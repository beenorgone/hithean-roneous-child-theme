/* [consult_flows] — danh sách / xem / copy / sửa kịch bản tư vấn. Dữ liệu tải 1 lần (AJAX hithean_cflow_list), lọc ở trình duyệt. */
(function () {
    'use strict';
    var root = document.getElementById('hcf-root');
    var cfgEl = document.getElementById('hcf-cfg');
    if (!root || !cfgEl) return;
    var cfg = JSON.parse(cfgEl.textContent || '{}');
    var $ = function (sel) { return root.querySelector(sel); };
    var listEl = $('[data-hcf-list]'), detailEl = $('[data-hcf-detail]'), statusEl = $('[data-hcf-status]');
    var qEl = $('[data-hcf-q]'), groupEl = $('[data-hcf-group]'), nameEl = $('[data-hcf-name]');
    var typeEl = $('[data-hcl-type]');
    var flows = [], currentId = 0, editing = false, editor = null, previewSeq = 0, libraryPending = false;

    try { nameEl.value = sessionStorage.getItem('hcf-name') || ''; } catch (e) { /* bỏ qua */ }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') node.textContent = attrs[k];
            else if (k === 'class') node.className = attrs[k];
            else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] !== false && attrs[k] != null) node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }
    function status(msg, err) { statusEl.textContent = msg || ''; statusEl.classList.toggle('is-error', !!err); statusEl.dispatchEvent(new Event('hcf:status')); }
    function norm(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase();
    }
    function fill(text) {
        var name = nameEl.value.trim();
        return String(text || '').replace(/\{ten\}/g, name || 'anh/chị').replace(/ {2,}/g, ' ');
    }
    /** Câu mẫu nhiều phương án tách bằng dòng "---". */
    function variants(say) {
        return String(say || '').split(/\n\s*---+\s*\n/).map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function copy(text, btn) {
        var done = function () {
            if (!btn) return;
            var old = btn.textContent;
            btn.classList.add('is-copied'); btn.textContent = '✓ Đã copy'; status('Đã copy câu mẫu.');
            setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else fallback();
        function fallback() {
            if (legacyCopy(text)) done();
            else status('Trình duyệt không cho copy tự động. Hãy chọn và sao chép câu mẫu đang hiển thị.', true);
        }
    }
    function legacyCopy(text) {
        var ta = el('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text;
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { /* show manual-copy guidance */ }
        ta.remove(); return ok;
    }
    window.hcfCopy = copy;

    function post(action, data) {
        var body = new FormData();
        if (cfg.library) {
            var actions = { hithean_cflow_list: 'hithean_library_list', hithean_cflow_save: 'hithean_library_save', hithean_cflow_delete: 'hithean_library_delete' };
            action = actions[action] || action;
            if (data && data.flow) { data = Object.assign({}, data, { entry: data.flow }); delete data.flow; }
        }
        body.append('action', action); body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
            .then(function (res) { if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi không xác định'); return res.data; });
    }

    function resolveTexts(texts) {
        if (!texts.some(function (t) { return /\{(?!ten\})[^{}]+\}/.test(t); })) return Promise.resolve({ texts: texts, errors: [] });
        return post('hithean_consult_resolve', { texts: JSON.stringify(texts) });
    }
    function copyResolved(texts, btn) {
        btn.disabled = true;
        (cfg.library ? post('hithean_library_copy', { key: currentId, texts: JSON.stringify(texts.map(fill)) }) : resolveTexts(texts.map(fill))).then(function (d) {
            if (d.errors && d.errors.length) { status(d.errors.join(' '), true); return; }
            var paragraph = btn.parentNode.querySelector('[data-hcf-say]');
            if (paragraph && d.texts.length === 1) paragraph.textContent = d.texts[0];
            copy(d.texts.join('\n\n'), btn);
        }).catch(function (err) { status(err.message, true); }).finally(function () { btn.disabled = false; });
    }
    function previewVariables() {
        var seq = ++previewSeq;
        var nodes = Array.prototype.slice.call(detailEl.querySelectorAll('[data-hcf-say]'), 0, 30);
        if (!nodes.length) return;
        resolveTexts(nodes.map(function (node) { return fill(node._raw); })).then(function (d) {
            if (seq !== previewSeq || editing) return;
            nodes.forEach(function (node, i) { node.textContent = d.texts[i]; });
            if (d.errors && d.errors.length) status('Cần sửa biến trước khi copy: ' + d.errors.join(' '), true);
        }).catch(function (err) { if (seq === previewSeq) status(err.message, true); });
    }
    function variablePicker(textarea) {
        var type = el('select', { class: 'hcf-in', 'aria-label': 'Loại biến' }, [
            el('option', { value: 'sp', text: 'Sản phẩm theo SKU' }), el('option', { value: 'nhom', text: 'Nhóm sản phẩm theo slug' }),
            el('option', { value: 'danh_muc', text: 'Danh mục theo slug' }), el('option', { value: 'thuong_hieu', text: 'Thương hiệu theo slug' })]);
        var ref = el('input', { class: 'hcf-in', placeholder: 'SKU hoặc slug trên site', 'aria-label': 'SKU hoặc slug' });
        var prop = el('select', { class: 'hcf-in', 'aria-label': 'Dữ liệu cần chèn' }, [el('option', { value: 'title', text: 'Tên' }), el('option', { value: 'link', text: 'Link' }), el('option', { value: 'gia', text: 'Giá hiện tại (SP)' })]);
        type.addEventListener('change', function () { prop.querySelector('[value=gia]').disabled = type.value !== 'sp'; if (type.value !== 'sp' && prop.value === 'gia') prop.value = 'title'; });
        var insert = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '＋ Chèn biến' });
        insert.addEventListener('click', function () {
            var key = ref.value.trim();
            if (!key || /[{}\r\n]/.test(key)) { ref.focus(); status('Nhập SKU hoặc slug hợp lệ.', true); return; }
            var token = '{' + type.value + ':' + key + ':' + prop.value + '}';
            var start = textarea.selectionStart, end = textarea.selectionEnd;
            textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
            textarea.focus(); textarea.setSelectionRange(start + token.length, start + token.length);
        });
        var preview = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '👁 Xem thử câu mẫu' });
        var result = el('p', { class: 'hcf-variable-preview', role: 'status' });
        preview.addEventListener('click', function () {
            preview.disabled = true;
            resolveTexts([fill(textarea.value)]).then(function (d) { result.textContent = d.errors && d.errors.length ? d.errors.join(' ') : d.texts[0]; result.classList.toggle('is-error', !!(d.errors && d.errors.length)); })
                .catch(function (err) { result.textContent = err.message; }).finally(function () { preview.disabled = false; });
        });
        return el('details', { class: 'hcf-variable-tools' }, [el('summary', { text: '＋ Chèn biến sản phẩm / nhóm / danh mục' }),
            el('p', { class: 'hpa-meta', text: 'Tên khách: {ten}. Ví dụ {sp:SKU:title}, {sp:SKU:link}, {sp:SKU:gia}. Nhóm = product_tag; danh mục = product_cat. Nhập đúng SKU/slug trên từng site; giá được tra lại khi copy.' }),
            el('div', { class: 'hcf-variable-picker' }, [type, ref, prop, insert, preview]), result]);
    }

    function notifyLibrary() {
        if (!cfg.library) return;
        try { localStorage.setItem('hcf-library-updated', Date.now() + ':' + Math.random()); } catch (e) { /* storage may be unavailable */ }
        // storage chỉ báo sang tài liệu khác; AI tư vấn cùng trang Thư viện nghe sự kiện này.
        window.dispatchEvent(new CustomEvent('hcf:library-updated', { detail: { source: 'library' } }));
    }
    function refreshLibrary() {
        if (editing) { libraryPending = true; return; }
        post('hithean_cflow_list').then(function (d) { libraryPending = false; load(d.flows); }).catch(function (err) { status(err.message, true); });
    }
    if (cfg.library) {
        window.addEventListener('storage', function (event) { if (event.key === 'hcf-library-updated') refreshLibrary(); });
        window.addEventListener('hcf:library-updated', function (event) { if (!event.detail || event.detail.source !== 'library') refreshLibrary(); });
    }

    function haystack(f) {
        return norm([f.title, f.group, (f.keywords || []).join(' '), (f.skus || []).join(' ')].concat((f.steps || []).map(function (s) { return s.title + ' ' + s.say; })).join(' '));
    }
    function filtered() {
        var q = norm(qEl.value).trim().split(/\s+/).filter(Boolean), g = groupEl.value;
        return flows.filter(function (f) {
            if (g && f.group !== g) return false;
            if (typeEl && typeEl.value && f.type !== typeEl.value) return false;
            var h = f._h || (f._h = haystack(f));
            return q.every(function (w) { return h.indexOf(w) !== -1; });
        });
    }

    function renderGroups() {
        var keep = groupEl.value, groups = [];
        flows.forEach(function (f) { if (f.group && groups.indexOf(f.group) === -1) groups.push(f.group); });
        groupEl.innerHTML = '';
        groupEl.appendChild(el('option', { value: '', text: 'Mọi nhóm (' + flows.length + ')' }));
        groups.forEach(function (g) { groupEl.appendChild(el('option', { value: g, text: g })); });
        groupEl.value = groups.indexOf(keep) !== -1 ? keep : '';
    }

    function renderList() {
        var items = filtered(), lastGroup = null;
        listEl.innerHTML = '';
        if (!items.length) { listEl.appendChild(el('p', { class: 'hcf-empty', text: cfg.library ? (flows.length ? 'Không có nội dung khớp.' : 'Chưa có nội dung.') : (flows.length ? 'Không có kịch bản khớp.' : 'Chưa có kịch bản.') })); return; }
        items.forEach(function (f) {
            var g = f.group || 'Khác';
            if (g !== lastGroup) { listEl.appendChild(el('h4', { text: g })); lastGroup = g; }
            listEl.appendChild(el('button', { type: 'button', 'aria-current': f.id === currentId ? 'true' : 'false', onclick: function () { select(f.id, true); } }, [
                (cfg.library ? (f.type === 'faq' ? '💬 ' : '🗂️ ') : '') + f.title, f.status === 'draft' ? el('span', { class: 'hcf-draft', text: 'nháp' }) : null,
                el('small', { text: (f.type === 'faq' ? 'Hỏi đáp · dùng ' + (f.uses || 0) + ' lần' : (f.steps || []).length + ' bước') + ((f.skus || []).length ? ' · ' + f.skus.join(', ') : '') })
            ]));
        });
    }

    function select(id, scroll) {
        currentId = id; editing = false; status('');
        try { history.replaceState(null, '', '#kb-' + id); } catch (e) { /* bỏ qua */ }
        renderList(); renderDetail();
        if (scroll && window.matchMedia('(max-width:760px)').matches) detailEl.scrollIntoView({ behavior: 'smooth' });
    }

    function sayBlock(text) {
        var btn = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '📋 Copy' });
        var paragraph = el('p', { text: fill(text), 'data-hcf-say': '' }); paragraph._raw = text;
        var box = el('div', { class: 'hcf-say', title: 'Bấm để tra biến và copy' }, [paragraph, btn]);
        box.addEventListener('click', function () { if (!btn.disabled) copyResolved([text], btn); });
        return box;
    }

    function renderDetail() {
        previewSeq++;
        if (editor) { editor.destroy(); editor = null; }
        detailEl.innerHTML = '';
        if (editing) return renderForm();
        var f = flows.find(function (x) { return x.id === currentId; });
        if (!f) {
            var kids = [el('p', { text: flows.length ? (cfg.library ? 'Chọn hỏi đáp hoặc kịch bản trong danh sách.' : 'Chọn một kịch bản ở danh sách bên trái.') : (cfg.library ? 'Chưa có nội dung. Bấm Thêm vào thư viện để bắt đầu.' : 'Chưa có kịch bản tư vấn nào.') })];
            if (!flows.length && cfg.manage && !cfg.library) kids.push(el('button', { type: 'button', class: 'hcf-btn', text: '✨ Tạo 2 kịch bản mẫu', onclick: starter }));
            detailEl.appendChild(el('div', { class: 'hcf-empty' }, kids));
            return;
        }
        var all = (f.steps || []).map(function (s) { return variants(s.say)[0]; }).filter(Boolean);
        var allBtn = el('button', { type: 'button', class: 'hcf-btn', text: '📋 Copy toàn bộ câu mẫu' });
        allBtn.addEventListener('click', function () { copyResolved(all, allBtn); });
        var linkBtn = el('button', { type: 'button', class: 'hcf-btn', text: '🔗', title: 'Copy link kịch bản' });
        linkBtn.addEventListener('click', function () { copy(location.href.split('#')[0] + '#kb-' + f.id, linkBtn); });
        detailEl.appendChild(el('div', { class: 'hcf-detail__head' }, [
            el('div', {}, [
                el('h2', { text: f.title }),
                el('div', { class: 'hcf-tags' }, [f.group ? el('span', { class: 'hcf-tag', text: '📁 ' + f.group }) : null, f.status === 'draft' ? el('span', { class: 'hcf-draft', text: 'nháp — AI chưa dùng' }) : null]
                    .concat((f.keywords || []).map(function (k) { return el('span', { class: 'hcf-tag', text: '“' + k + '”' }); }))
                    .concat((f.skus || []).map(function (k) { return el('span', { class: 'hcf-tag hcf-tag--sku', text: k }); })))
            ]),
            el('div', { class: 'hcf-tags' }, [allBtn, linkBtn, cfg.manage ? el('button', { type: 'button', class: 'hcf-btn', text: '✏️ Sửa', onclick: function () { editing = true; renderDetail(); } }) : null])
        ]));
        var ol = el('ol', { class: 'hcf-steps' });
        (f.steps || []).forEach(function (s) {
            var li = el('li', { class: 'hcf-step' }, [el('h3', { text: s.title || 'Bước' })]);
            variants(s.say).forEach(function (v) { li.appendChild(sayBlock(v)); });
            if (s.note) li.appendChild(el('p', { class: 'hcf-note', text: '💡 ' + s.note }));
            ol.appendChild(li);
        });
        detailEl.appendChild(ol);
        previewVariables();
        if (f.updated) detailEl.appendChild(el('p', { class: 'hpa-meta', text: 'Cập nhật: ' + f.updated }));
    }

    function renderForm() {
        var f = flows.find(function (x) { return x.id === currentId; }) || { id: 0, type: cfg.library ? (cfg.manage ? (typeEl && typeEl.value || 'faq') : 'faq') : 'flow', title: '', group: '', keywords: [], skus: [], steps: [{ title: '', say: '', note: '' }], status: 'publish' };
        var form = el('form', { class: 'hcf-form' });
        var kind = el('select', { class: 'hcf-in', 'aria-label': 'Dạng nội dung mới', disabled: !!f.id }, [el('option', { value: 'faq', text: 'Hỏi đáp nhanh — một câu trả lời' })].concat(cfg.manage ? [el('option', { value: 'flow', text: 'Kịch bản — nhiều bước' })] : []));
        kind.value = f.type || 'flow';
        var groups = flows.map(function (x) { return x.group; }).filter(function (g, i, a) { return g && a.indexOf(g) === i; });
        var dl = el('datalist', { id: 'hcf-groups' }, groups.map(function (g) { return el('option', { value: g }); }));
        var field = function (label, input, hint) { return el('label', {}, [label, hint ? el('small', { text: hint }) : null, input]); };
        var title = el('input', { class: 'hcf-in', name: 'title', required: true, value: f.title, placeholder: cfg.library ? 'VD: Phí giao hàng bao nhiêu?' : 'VD: Khách hỏi sữa bột cho mẹ sau sinh' });
        var group = el('input', { class: 'hcf-in', name: 'group', list: 'hcf-groups', value: f.group, placeholder: cfg.library ? 'VD: Giao hàng' : 'VD: Mẹ & bé' });
        var kw = el('textarea', { class: 'hcf-in', name: 'keywords', rows: 2, placeholder: cfg.library ? 'phí ship, giao hàng, thời gian giao' : 'sau sinh, cho con bú, lợi sữa' }); kw.value = (f.keywords || []).join(', ');
        var skus = el('input', { class: 'hcf-in', name: 'skus', value: (f.skus || []).join(', '), placeholder: 'SKU1, SKU2' });
        var st = el('select', { class: 'hcf-in', name: 'status' }, [el('option', { value: 'publish', text: 'Đang dùng' }), el('option', { value: 'draft', text: 'Nháp (AI không dùng)' })]); st.value = f.status;
        var stepsBox = el('div', { class: 'hcf-form', 'data-steps': '' });
        function addStep(s) {
            var box = el('div', { class: 'hcf-fstep' });
            var t = el('input', { class: 'hcf-in', placeholder: 'Tên bước (VD: Hỏi nhu cầu)', value: s.title || '' });
            var say = el('textarea', { class: 'hcf-in', rows: 4, placeholder: 'Câu trả lời mẫu gửi khách. Dùng {ten} cho tên khách. Nhiều phương án: tách bằng 1 dòng ---' }); say.value = s.say || '';
            var note = el('textarea', { class: 'hcf-in', rows: 2, placeholder: 'Ghi chú nội bộ (không gửi khách)' }); note.value = s.note || '';
            var move = function (d) { return function () { var sib = d < 0 ? box.previousElementSibling : box.nextElementSibling; if (sib) d < 0 ? stepsBox.insertBefore(box, sib) : stepsBox.insertBefore(sib, box); }; };
            box.appendChild(el('div', { class: 'hcf-fstep__bar' }, [el('b', { text: 'Bước' }),
                el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '↑', onclick: move(-1) }),
                el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '↓', onclick: move(1) }),
                el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm hcf-btn--danger', text: '✕', onclick: function () { box.remove(); } })]));
            box.appendChild(t); box.appendChild(say); box.appendChild(variablePicker(say)); box.appendChild(note);
            box._get = function () { return { title: t.value, say: say.value, note: note.value }; };
            stepsBox.appendChild(box);
        }
        (f.steps && f.steps.length ? f.steps : [{}]).forEach(addStep);
        var moreStep;
        function updateKind() {
            if (!cfg.library) return;
            var faq = kind.value === 'faq';
            if (moreStep) moreStep.hidden = faq;
            Array.prototype.forEach.call(stepsBox.children, function (box, i) { box.hidden = faq && i > 0; var bar = box.querySelector('.hcf-fstep__bar'); if (bar) bar.hidden = faq; var stepTitle = box.querySelector('input'); if (stepTitle) { stepTitle.hidden = faq; if (faq && !stepTitle.value) stepTitle.value = 'Trả lời'; } });
        }
        kind.addEventListener('change', updateKind);
        var saveBtn = el('button', { type: 'submit', class: 'hcf-btn hcf-btn--pri', text: '💾 Lưu' });
        form.appendChild(el('h2', { text: cfg.library ? (f.id ? 'Sửa nội dung thư viện' : 'Thêm vào thư viện') : (f.id ? 'Sửa kịch bản' : 'Kịch bản mới') }));
        if (cfg.library) form.appendChild(field('Dạng nội dung', kind));
        form.appendChild(field(cfg.library ? 'Câu hỏi / tình huống của khách' : 'Nhu cầu / tình huống của khách', title));
        form.appendChild(el('div', { class: 'hcf-form__grid' }, [field('Nhóm', group), field('Trạng thái', st)]));
        form.appendChild(dl);
        form.appendChild(field('Từ khóa khách hay nói', kw, ' — phân cách dấu phẩy; AI tư vấn dùng để nhận ra kịch bản (không phân biệt dấu)'));
        form.appendChild(field('SKU liên quan', skus, ' — không bắt buộc'));
        form.appendChild(stepsBox);
        moreStep = el('button', { type: 'button', class: 'hcf-btn', text: '＋ Thêm bước', onclick: function () { addStep({}); updateKind(); } });
        form.appendChild(el('div', { class: 'hcf-form__actions' }, [
            moreStep,
            saveBtn,
            el('button', { type: 'button', class: 'hcf-btn', text: 'Hủy', onclick: function () { editing = false; renderDetail(); if (libraryPending) refreshLibrary(); } }),
            f.id ? el('button', { type: 'button', class: 'hcf-btn hcf-btn--danger', text: '🗑 Xóa', onclick: function () { remove(f.id); } }) : null
        ]));
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = { id: f.id, type: cfg.library ? kind.value : 'flow', title: title.value, group: group.value, keywords: kw.value, skus: skus.value, status: st.value,
                steps: Array.prototype.map.call(stepsBox.children, function (b) { return b._get(); }) };
            if (cfg.library && kind.value === 'faq') data.steps = data.steps.slice(0, 1);
            saveBtn.disabled = true; status('Đang lưu…');
            post('hithean_cflow_save', { flow: JSON.stringify(data) }).then(function (d) {
                editing = false; libraryPending = false; load(d.flows); status('Đã lưu.'); select(d.id); notifyLibrary();
            }).catch(function (err) { status(err.message, true); }).finally(function () { saveBtn.disabled = false; });
        });
        if (cfg.library && !cfg.manage) { st.disabled = true; st.value = 'publish'; }
        updateKind();
        detailEl.appendChild(form);
        if (window.hcfMobileForm) editor = window.hcfMobileForm(form, { title: cfg.library ? (f.id ? 'Sửa nội dung thư viện' : 'Thêm vào thư viện') : (f.id ? 'Sửa kịch bản' : 'Kịch bản mới'), cta: cfg.library ? '✏️ Tiếp tục soạn nội dung' : '✏️ Tiếp tục soạn kịch bản', autoOpen: true, status: statusEl });
        title.focus();
    }

    function remove(id) {
        if (!window.confirm((cfg.library ? 'Xóa nội dung này?' : 'Xóa kịch bản này?') + ' (bản cũ vẫn giữ trong lịch sử để khôi phục khi cần)')) return;
        post('hithean_cflow_delete', { id: id }).then(function (d) { currentId = 0; load(d.flows); status('Đã xóa.'); notifyLibrary(); }).catch(function (err) { status(err.message, true); });
    }
    function starter() {
        post('hithean_cflow_import', { starter: 1 }).then(function (d) { load(d.flows); status(d.message); }).catch(function (err) { status(err.message, true); });
    }

    function load(data) {
        flows = (data || []).map(function (f) { delete f._h; return f; });
        renderGroups(); renderList(); renderDetail();
    }

    qEl.addEventListener('input', renderList);
    groupEl.addEventListener('change', renderList);
    if (typeEl) typeEl.addEventListener('change', renderList);
    nameEl.addEventListener('input', function () { try { sessionStorage.setItem('hcf-name', nameEl.value); } catch (e) { /* bỏ qua */ } if (!editing) renderDetail(); });
    var newBtn = $('[data-hcf-new]');
    if (newBtn) newBtn.addEventListener('click', function () { if (editor && editing && !currentId) { editor.open(); return; } currentId = 0; editing = true; renderList(); renderDetail(); });
    if (window.hcfBundleTools) window.hcfBundleTools(root, cfg, 'flows', function (d) { if (cfg.library) { notifyLibrary(); refreshLibrary(); } else load(d.flows); }, status);

    post('hithean_cflow_list').then(function (d) {
        var m = /#kb-(flow-\d+|faq-\d+|\d+)/.exec(location.hash);
        if (m) currentId = cfg.library ? (/^\d+$/.test(m[1]) ? 'flow-' + m[1] : m[1]) : +m[1];
        load(d.flows);
        status(flows.length ? 'Bấm vào câu mẫu để copy. Gõ tên khách để thay {ten}.' : '');
    }).catch(function (err) { status(err.message, true); });
})();

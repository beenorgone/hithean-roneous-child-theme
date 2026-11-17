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
    var flows = [], currentId = 0, editing = false;

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
    function status(msg, err) { statusEl.textContent = msg || ''; statusEl.classList.toggle('is-error', !!err); }
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
            btn.classList.add('is-copied'); btn.textContent = '✓ Đã copy';
            setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text); done(); });
        } else { legacyCopy(text); done(); }
    }
    function legacyCopy(text) {
        var ta = el('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text;
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) { /* bỏ qua */ }
        ta.remove();
    }
    window.hcfCopy = copy;

    function post(action, data) {
        var body = new FormData();
        body.append('action', action); body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
            .then(function (res) { if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi không xác định'); return res.data; });
    }

    function haystack(f) {
        return norm([f.title, f.group, (f.keywords || []).join(' '), (f.skus || []).join(' ')].concat((f.steps || []).map(function (s) { return s.title + ' ' + s.say; })).join(' '));
    }
    function filtered() {
        var q = norm(qEl.value).trim().split(/\s+/).filter(Boolean), g = groupEl.value;
        return flows.filter(function (f) {
            if (g && f.group !== g) return false;
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
        if (!items.length) { listEl.appendChild(el('p', { class: 'hcf-empty', text: flows.length ? 'Không có kịch bản khớp.' : 'Chưa có kịch bản.' })); return; }
        items.forEach(function (f) {
            var g = f.group || 'Khác';
            if (g !== lastGroup) { listEl.appendChild(el('h4', { text: g })); lastGroup = g; }
            listEl.appendChild(el('button', { type: 'button', 'aria-current': f.id === currentId ? 'true' : 'false', onclick: function () { select(f.id, true); } }, [
                f.title, f.status === 'draft' ? el('span', { class: 'hcf-draft', text: 'nháp' }) : null,
                el('small', { text: (f.steps || []).length + ' bước' + ((f.skus || []).length ? ' · ' + f.skus.join(', ') : '') })
            ]));
        });
    }

    function select(id, scroll) {
        currentId = id; editing = false;
        try { history.replaceState(null, '', '#kb-' + id); } catch (e) { /* bỏ qua */ }
        renderList(); renderDetail();
        if (scroll && window.matchMedia('(max-width:760px)').matches) detailEl.scrollIntoView({ behavior: 'smooth' });
    }

    function sayBlock(text) {
        var btn = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '📋 Copy' });
        var box = el('div', { class: 'hcf-say', title: 'Bấm để copy' }, [el('p', { text: fill(text) }), btn]);
        box.addEventListener('click', function () { copy(fill(text), btn); });
        return box;
    }

    function renderDetail() {
        detailEl.innerHTML = '';
        if (editing) return renderForm();
        var f = flows.find(function (x) { return x.id === currentId; });
        if (!f) {
            var kids = [el('p', { text: flows.length ? 'Chọn một kịch bản ở danh sách bên trái.' : 'Chưa có kịch bản tư vấn nào.' })];
            if (!flows.length && cfg.manage) kids.push(el('button', { type: 'button', class: 'hcf-btn', text: '✨ Tạo 2 kịch bản mẫu', onclick: starter }));
            detailEl.appendChild(el('div', { class: 'hcf-empty' }, kids));
            return;
        }
        var all = (f.steps || []).map(function (s) { return variants(s.say)[0]; }).filter(Boolean).map(fill).join('\n\n');
        var allBtn = el('button', { type: 'button', class: 'hcf-btn', text: '📋 Copy toàn bộ câu mẫu' });
        allBtn.addEventListener('click', function () { copy(all, allBtn); });
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
        if (f.updated) detailEl.appendChild(el('p', { class: 'hpa-meta', text: 'Cập nhật: ' + f.updated }));
    }

    function renderForm() {
        var f = flows.find(function (x) { return x.id === currentId; }) || { id: 0, title: '', group: '', keywords: [], skus: [], steps: [{ title: '', say: '', note: '' }], status: 'publish' };
        var form = el('form', { class: 'hcf-form' });
        var groups = flows.map(function (x) { return x.group; }).filter(function (g, i, a) { return g && a.indexOf(g) === i; });
        var dl = el('datalist', { id: 'hcf-groups' }, groups.map(function (g) { return el('option', { value: g }); }));
        var field = function (label, input, hint) { return el('label', {}, [label, hint ? el('small', { text: hint }) : null, input]); };
        var title = el('input', { class: 'hcf-in', name: 'title', required: true, value: f.title, placeholder: 'VD: Khách hỏi sữa bột cho mẹ sau sinh' });
        var group = el('input', { class: 'hcf-in', name: 'group', list: 'hcf-groups', value: f.group, placeholder: 'VD: Mẹ & bé' });
        var kw = el('textarea', { class: 'hcf-in', name: 'keywords', rows: 2, placeholder: 'sau sinh, cho con bú, lợi sữa' }); kw.value = (f.keywords || []).join(', ');
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
            box.appendChild(t); box.appendChild(say); box.appendChild(note);
            box._get = function () { return { title: t.value, say: say.value, note: note.value }; };
            stepsBox.appendChild(box);
        }
        (f.steps && f.steps.length ? f.steps : [{}]).forEach(addStep);
        var saveBtn = el('button', { type: 'submit', class: 'hcf-btn hcf-btn--pri', text: '💾 Lưu' });
        form.appendChild(el('h2', { text: f.id ? 'Sửa kịch bản' : 'Kịch bản mới' }));
        form.appendChild(field('Nhu cầu / tình huống của khách', title));
        form.appendChild(el('div', { class: 'hcf-form__grid' }, [field('Nhóm', group), field('Trạng thái', st)]));
        form.appendChild(dl);
        form.appendChild(field('Từ khóa khách hay nói', kw, ' — phân cách dấu phẩy; AI tư vấn dùng để nhận ra kịch bản (không phân biệt dấu)'));
        form.appendChild(field('SKU liên quan', skus, ' — không bắt buộc'));
        form.appendChild(stepsBox);
        form.appendChild(el('div', { class: 'hcf-form__actions' }, [
            el('button', { type: 'button', class: 'hcf-btn', text: '＋ Thêm bước', onclick: function () { addStep({}); } }),
            saveBtn,
            el('button', { type: 'button', class: 'hcf-btn', text: 'Hủy', onclick: function () { editing = false; renderDetail(); } }),
            f.id ? el('button', { type: 'button', class: 'hcf-btn hcf-btn--danger', text: '🗑 Xóa', onclick: function () { remove(f.id); } }) : null
        ]));
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = { id: f.id, title: title.value, group: group.value, keywords: kw.value, skus: skus.value, status: st.value,
                steps: Array.prototype.map.call(stepsBox.children, function (b) { return b._get(); }) };
            saveBtn.disabled = true; status('Đang lưu…');
            post('hithean_cflow_save', { flow: JSON.stringify(data) }).then(function (d) {
                load(d.flows); status('Đã lưu.'); select(d.id);
            }).catch(function (err) { status(err.message, true); }).finally(function () { saveBtn.disabled = false; });
        });
        detailEl.appendChild(form);
        title.focus();
    }

    function remove(id) {
        if (!window.confirm('Xóa kịch bản này? (bản cũ vẫn giữ trong lịch sử để khôi phục khi cần)')) return;
        post('hithean_cflow_delete', { id: id }).then(function (d) { currentId = 0; load(d.flows); status('Đã xóa.'); }).catch(function (err) { status(err.message, true); });
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
    nameEl.addEventListener('input', function () { try { sessionStorage.setItem('hcf-name', nameEl.value); } catch (e) { /* bỏ qua */ } if (!editing) renderDetail(); });
    var newBtn = $('[data-hcf-new]');
    if (newBtn) newBtn.addEventListener('click', function () { currentId = 0; editing = true; renderList(); renderDetail(); });
    if (window.hcfBundleTools) window.hcfBundleTools(root, cfg, 'flows', function (d) { load(d.flows); }, status);

    post('hithean_cflow_list').then(function (d) {
        var m = /#kb-(\d+)/.exec(location.hash);
        if (m) currentId = +m[1];
        load(d.flows);
        status(flows.length ? 'Bấm vào câu mẫu để copy. Gõ tên khách để thay {ten}.' : '');
    }).catch(function (err) { status(err.message, true); });
})();

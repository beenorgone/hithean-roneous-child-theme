/* [consult_faqs] — FAQ tư vấn nội bộ: tìm, copy (đếm lượt dùng), thêm / sửa / xóa, xuất / nhập gói sang site khác. */
(function () {
    'use strict';
    var root = document.getElementById('hfq-root');
    var cfgEl = document.getElementById('hfq-cfg');
    if (!root || !cfgEl) return;
    var cfg = JSON.parse(cfgEl.textContent || '{}');
    var $ = function (sel) { return root.querySelector(sel); };
    var listEl = $('[data-hfq-list]'), statusEl = $('[data-hfq-status]'), qEl = $('[data-hfq-q]'), nameEl = $('[data-hfq-name]'), slot = $('[data-hfq-form-slot]');
    var items = [];

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
    function norm(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase(); }
    function fill(text) { return String(text || '').replace(/\{ten\}/g, nameEl.value.trim() || 'anh/chị'); }
    function copy(text, btn) {
        var done = function () {
            if (!btn) return;
            var old = btn.textContent;
            btn.classList.add('is-copied'); btn.textContent = '✓ Đã copy';
            setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { legacy(text); done(); });
        else { legacy(text); done(); }
    }
    function legacy(text) {
        var ta = el('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text;
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) { /* bỏ qua */ }
        ta.remove();
    }
    function post(action, data) {
        var body = new FormData();
        body.append('action', action); body.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
            .then(function (res) { if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi không xác định'); return res.data; });
    }

    function render() {
        var words = norm(qEl.value).trim().split(/\s+/).filter(Boolean);
        var shown = items.filter(function (f) {
            var h = f._h || (f._h = norm([f.q, f.a, (f.skus || []).join(' '), (f.keywords || []).join(' ')].join(' ')));
            return words.every(function (w) { return h.indexOf(w) !== -1; });
        });
        listEl.innerHTML = '';
        if (!shown.length) { listEl.appendChild(el('p', { class: 'hcf-empty', text: items.length ? 'Không có FAQ khớp.' : 'Chưa có FAQ. Lưu từ kết quả AI tư vấn hoặc bấm ＋ Thêm FAQ.' })); return; }
        shown.forEach(function (f) {
            var btn = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '📋 Copy' });
            var say = el('div', { class: 'hcf-say', title: 'Bấm để copy' }, [el('p', { text: fill(f.a) }), btn]);
            say.addEventListener('click', function () {
                copy(fill(f.a), btn);
                f.uses = (f.uses || 0) + 1;
                post('hithean_faq_used', { id: f.id }).catch(function () { /* không chặn */ });
            });
            listEl.appendChild(el('article', { class: 'hcf-step hfq-item' }, [
                el('h3', { text: '❓ ' + f.q }),
                say,
                el('div', { class: 'hcf-tags' }, (f.skus || []).map(function (s) { return el('span', { class: 'hcf-tag hcf-tag--sku', text: s }); })
                    .concat((f.keywords || []).map(function (k) { return el('span', { class: 'hcf-tag', text: '“' + k + '”' }); }))
                    .concat([el('span', { class: 'hpa-meta', text: (f.source === 'ai' ? '✨ từ AI · ' : '') + 'dùng ' + (f.uses || 0) + ' lần · ' + (f.by || '') + ' ' + (f.updated || '') })])
                    .concat(cfg.manage ? [
                        el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '✏️ Sửa', onclick: function () { form(f); } }),
                        el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm hcf-btn--danger', text: '🗑', title: 'Xóa', onclick: function () { remove(f.id); } })
                    ] : []))
            ]));
        });
    }

    function form(f) {
        f = f || { id: 0, q: '', a: '', skus: [], keywords: [] };
        slot.innerHTML = '';
        var q = el('textarea', { class: 'hcf-in', rows: 2, required: true, placeholder: 'Câu hỏi khách hay gặp' }); q.value = f.q;
        var a = el('textarea', { class: 'hcf-in', rows: 5, required: true, placeholder: 'Câu trả lời mẫu (dùng {ten} cho tên khách)' }); a.value = f.a;
        var skus = el('input', { class: 'hcf-in', value: (f.skus || []).join(', '), placeholder: 'SKU liên quan (không bắt buộc)' });
        var kw = el('input', { class: 'hcf-in', value: (f.keywords || []).join(', '), placeholder: 'Từ khóa khác khách hay dùng (không bắt buộc)' });
        var save = el('button', { type: 'submit', class: 'hcf-btn hcf-btn--pri', text: '💾 Lưu FAQ' });
        var box = el('form', { class: 'hcf-form hcf-step' }, [
            el('h3', { text: f.id ? 'Sửa FAQ #' + f.id : 'FAQ mới' }),
            el('label', {}, ['Câu hỏi', q]), el('label', {}, ['Câu trả lời', a]),
            el('div', { class: 'hcf-form__grid' }, [el('label', {}, ['SKU', skus]), el('label', {}, ['Từ khóa', kw])]),
            el('div', { class: 'hcf-form__actions' }, [save, el('button', { type: 'button', class: 'hcf-btn', text: 'Hủy', onclick: function () { slot.innerHTML = ''; } })])
        ]);
        box.addEventListener('submit', function (e) {
            e.preventDefault();
            save.disabled = true;
            post('hithean_faq_save', { faq: JSON.stringify({ id: f.id, q: q.value, a: a.value, skus: skus.value, keywords: kw.value }) }).then(function (d) {
                load(d.items); slot.innerHTML = ''; status(d.updated ? 'Đã cập nhật FAQ.' : 'Đã thêm FAQ.');
            }).catch(function (err) { status(err.message, true); }).finally(function () { save.disabled = false; });
        });
        slot.appendChild(box);
        q.focus();
    }

    function remove(id) {
        if (!window.confirm('Xóa FAQ này? (bản cũ vẫn giữ trong lịch sử)')) return;
        post('hithean_faq_delete', { id: id }).then(function (d) { load(d.items); status('Đã xóa.'); }).catch(function (err) { status(err.message, true); });
    }
    function load(data) { items = (data || []).map(function (f) { delete f._h; return f; }); render(); }

    qEl.addEventListener('input', render);
    nameEl.addEventListener('input', function () { try { sessionStorage.setItem('hcf-name', nameEl.value); } catch (e) { /* bỏ qua */ } render(); });
    $('[data-hfq-new]').addEventListener('click', function () { form(null); });
    if (window.hcfBundleTools) window.hcfBundleTools(root, cfg, 'faqs', function (d) { load(d.items); }, status);

    post('hithean_faq_list').then(function (d) { load(d.items); status(items.length ? 'Bấm vào câu trả lời để copy. Gõ tên khách để thay {ten}.' : ''); })
        .catch(function (err) { status(err.message, true); });
})();

/* Popup "Nhập thông tin xuất VAT" — order-vat-request.php. Dùng chung theanmarket + hithean.
 * Nút: `[data-thean-vat-request][data-order-id]` (ô tìm đơn, board, xem nhanh, bảng điều phối).
 * Bước 1 BẮT BUỘC up ảnh chụp tin nhắn thực tế của khách → mới mở ô nhập + "🤖 Bóc tách bằng AI" → Lưu.
 * Xong: phát `thean:order-updated` (board tự làm mới); trang sửa đơn (cfg.reload) thì tải lại.
 */
(function () {
    var cfg = window.theanVatCfg;
    if (!cfg) return;
    var modal = null, current = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text) n.textContent = text;
        return n;
    }

    function post(action, data, files) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        (files || []).forEach(function (f) { fd.append('images[]', f); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi.');
                return res.data;
            });
    }

    function build() {
        var style = el('style');
        style.textContent = '#thean-vat-modal[hidden]{display:none}'
            + '#thean-vat-modal{position:fixed;inset:0;z-index:1000003;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5)}'
            + '#thean-vat-modal .tv-box{position:relative;box-sizing:border-box;width:min(560px,100%);max-height:calc(100vh - 32px);overflow:auto;padding:20px;border-radius:12px;background:#fff;box-shadow:0 24px 60px rgba(15,23,42,.32);font-size:13px}'
            + '#thean-vat-modal h2{margin:0 32px 6px 0;font-size:18px}'
            + '#thean-vat-modal .tv-close{position:absolute;top:10px;right:10px;width:30px;height:30px;border:0;border-radius:50%;background:#eef2f7;font-size:20px;cursor:pointer}'
            + '#thean-vat-modal .tv-step{margin:12px 0;padding:12px;border:1px solid #e2e8f0;border-radius:10px}'
            + '#thean-vat-modal .tv-step h3{margin:0 0 8px;font-size:13px}'
            + '#thean-vat-modal .tv-step.is-off{opacity:.5}'
            + '#thean-vat-modal .tv-thumbs{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}'
            + '#thean-vat-modal .tv-thumbs img{width:64px;height:64px;object-fit:cover;border:1px solid #cbd5e1;border-radius:6px}'
            + '#thean-vat-modal label{display:grid;gap:4px;margin:0 0 8px;font-weight:600;color:#334155}'
            + '#thean-vat-modal input[type=text],#thean-vat-modal input[type=email]{box-sizing:border-box;width:100%;padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font-weight:400}'
            + '#thean-vat-modal .tv-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px}'
            + '#thean-vat-modal button.tv-btn{padding:7px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-weight:600;cursor:pointer}'
            + '#thean-vat-modal button.tv-primary{border-color:#003a99;background:#0047ba;color:#fff}'
            + '#thean-vat-modal button:disabled{opacity:.55;cursor:not-allowed}'
            + '#thean-vat-modal .tv-msg{min-height:18px;margin-top:8px;font-weight:600;color:#b42318}'
            + '#thean-vat-modal .tv-msg.is-ok{color:#087443}'
            + '#thean-vat-modal .tv-muted{color:#64748b}';
        document.head.appendChild(style);
        modal = el('div');
        modal.id = 'thean-vat-modal';
        modal.hidden = true;
        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('.tv-close, [data-tv-cancel]')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) { e.stopPropagation(); close(); }
        }, true);
        document.body.appendChild(modal);
    }

    function close() { if (modal) modal.hidden = true; current = null; }

    function msg(text, ok) {
        var m = modal.querySelector('.tv-msg');
        m.textContent = text || '';
        m.classList.toggle('is-ok', !!ok);
    }

    function render(data) {
        var box = el('div', 'tv-box');
        var closeBtn = el('button', 'tv-close', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Đóng');
        box.append(closeBtn, el('h2', '', '🧾 Thông tin xuất VAT — đơn #' + data.order_id));
        if (data.status) box.appendChild(el('p', 'tv-muted', 'Tình trạng HĐ: ' + data.status));
        if (data.locked) {
            box.appendChild(el('p', '', '🔒 Đơn đã vào lô / đã xuất hóa đơn — không sửa thông tin VAT được.'));
        }

        // Bước 1: ảnh tin nhắn (bắt buộc)
        var s1 = el('div', 'tv-step');
        s1.appendChild(el('h3', '', '1. Ảnh chụp tin nhắn thực tế của khách (bắt buộc, tối đa ' + cfg.max + ' ảnh)'));
        var thumbs = el('div', 'tv-thumbs');
        (data.proof || []).forEach(function (p) {
            var a = el('a'); a.href = p.url; a.target = '_blank'; a.rel = 'noopener';
            var img = el('img'); img.src = p.thumb; img.alt = 'Ảnh tin nhắn';
            a.appendChild(img); thumbs.appendChild(a);
        });
        var file = el('input');
        file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp'; file.multiple = true;
        file.disabled = data.locked || (data.proof || []).length >= cfg.max;
        var up = el('button', 'tv-btn', '⤒ Up ảnh');
        up.type = 'button'; up.disabled = file.disabled;
        var upRow = el('div', 'tv-row'); upRow.style.justifyContent = 'flex-start';
        upRow.append(file, up);
        s1.append(upRow, thumbs);
        box.appendChild(s1);

        // Bước 2: thông tin (mở khi đã có ảnh)
        var hasProof = (data.proof || []).length > 0;
        var s2 = el('div', 'tv-step' + (hasProof ? '' : ' is-off'));
        s2.appendChild(el('h3', '', '2. Thông tin xuất hóa đơn' + (hasProof ? '' : ' — up ảnh trước')));
        var fields = {};
        [['tax_code', 'Mã số thuế', 'text'], ['company', 'Tên công ty / người mua', 'text'], ['address', 'Địa chỉ', 'text'], ['email', 'Email nhận HĐ', 'email'], ['note', 'Ghi chú (không bắt buộc)', 'text']].forEach(function (f) {
            var lab = el('label', '', f[1]);
            var inp = el('input');
            inp.type = f[2]; inp.name = f[0];
            inp.value = f[0] === 'note' ? '' : ((data.buyer || {})[f[0]] || '');
            inp.disabled = !hasProof || data.locked;
            lab.appendChild(inp); s2.appendChild(lab); fields[f[0]] = inp;
        });
        var ai = el('button', 'tv-btn', '🤖 Bóc tách bằng AI');
        ai.type = 'button'; ai.disabled = !hasProof || data.locked;
        var save = el('button', 'tv-btn tv-primary', 'Lưu thông tin VAT');
        save.type = 'button'; save.disabled = !hasProof || data.locked;
        var cancel = el('button', 'tv-btn', 'Đóng');
        cancel.type = 'button'; cancel.setAttribute('data-tv-cancel', '');
        var row = el('div', 'tv-row');
        row.append(ai, cancel, save);
        s2.appendChild(row);
        if (data.request && data.request.at) s2.appendChild(el('p', 'tv-muted', 'Lần nhập trước: ' + data.request.at + (data.request.by ? ' — ' + data.request.by : '')));
        box.append(s2, el('div', 'tv-msg'));
        modal.replaceChildren(box);

        up.addEventListener('click', function () {
            if (!file.files.length) { msg('Chọn ảnh chụp tin nhắn của khách.'); return; }
            up.disabled = true; msg('Đang up ảnh…', true);
            post('thean_vat_request_upload', { order_id: data.order_id }, Array.prototype.slice.call(file.files))
                .then(function (d) { render(d); msg('Đã up ảnh — nhập hoặc bấm "Bóc tách bằng AI".', true); })
                .catch(function (e) { up.disabled = false; msg(e.message); });
        });
        ai.addEventListener('click', function () {
            ai.disabled = true; msg('AI đang đọc ảnh…', true);
            post('thean_vat_request_ai', { order_id: data.order_id })
                .then(function (d) {
                    ['tax_code', 'company', 'address', 'email'].forEach(function (k) { if (d[k]) fields[k].value = d[k]; });
                    if (d.note) fields.note.value = d.note;
                    msg('AI đã điền — kiểm tra lại trước khi lưu.', true);
                })
                .catch(function (e) { msg(e.message); })
                .then(function () { ai.disabled = false; });
        });
        save.addEventListener('click', function () {
            var payload = { order_id: data.order_id };
            Object.keys(fields).forEach(function (k) { payload[k] = fields[k].value.trim(); });
            if (!payload.tax_code && !payload.company) { msg('Cần ít nhất MST hoặc tên người mua.'); return; }
            save.disabled = true; msg('Đang lưu…', true);
            post('thean_vat_request_save', payload)
                .then(function (d) {
                    if (cfg.reload) { location.reload(); return; }
                    if (current && current.trigger) current.trigger.textContent = '🧾 Sửa VAT';
                    render(d);
                    msg(d.message || 'Đã lưu.', true);
                    document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: data.order_id, kind: 'vat' } }));
                })
                .catch(function (e) { save.disabled = false; msg(e.message); });
        });
    }

    function open(trigger) {
        if (!modal) build();
        var id = trigger.getAttribute('data-order-id');
        if (!id) return;
        current = { trigger: trigger };
        var box = el('div', 'tv-box');
        box.appendChild(el('p', 'tv-muted', 'Đang tải đơn #' + id + '…'));
        modal.replaceChildren(box);
        modal.hidden = false;
        post('thean_vat_request_get', { order_id: id })
            .then(render)
            .catch(function (e) { box.replaceChildren(el('p', '', e.message)); });
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-thean-vat-request]');
        if (!t) return;
        e.preventDefault();
        e.stopPropagation();
        open(t);
    }, true);
}());

/* Đơn đặt trước — popup dùng chung cho bảng điều phối (trang sửa đơn) và trang danh sách đơn (cột Thao tác,
 * ô tìm đơn, board "Tải đơn cần xử lý"). Dùng chung theanmarket + hithean.
 *
 * - Chuyển sang đặt trước (bắt buộc ghi chú): `.wc-action-button-thean-order-preorder` | `[data-thean-preorder]`
 *   → AJAX thean_order_workspace, task order_flow, flow preorder (order-workspace.php).
 * - Xác nhận giao đơn đặt trước (sau khi tiếp tục): `.wc-action-button-thean-order-preorder-confirm` |
 *   `[data-thean-preorder-confirm]` → task preorder_confirm, result confirmed | waiting.
 * Xong: phát sự kiện `thean:order-updated` (board tự làm mới); trang sửa đơn (cfg.reload) thì tải lại.
 */
(function () {
    var cfg = window.theanPreorderCfg;
    if (!cfg) return;
    var modal = null, form = null, current = null;

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text) node.textContent = text;
        return node;
    }

    function build() {
        var style = el('style');
        style.textContent = '#thean-preorder-modal[hidden]{display:none}'
            + '#thean-preorder-modal{position:fixed;inset:0;z-index:1000003;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5)}'
            + '#thean-preorder-modal form{position:relative;box-sizing:border-box;width:min(480px,100%);padding:20px;border-radius:12px;background:#fff;box-shadow:0 24px 60px rgba(15,23,42,.32);font-size:13px}'
            + '#thean-preorder-modal h2{margin:0 32px 8px 0;font-size:18px;line-height:1.3}'
            + '#thean-preorder-modal p{margin:0 0 10px;color:#475569}'
            + '#thean-preorder-modal textarea{box-sizing:border-box;width:100%;min-height:80px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}'
            + '#thean-preorder-modal .tp-close{position:absolute;top:10px;right:10px;width:30px;height:30px;border:0;border-radius:50%;background:#eef2f7;font-size:20px;cursor:pointer}'
            + '#thean-preorder-modal .tp-contact a{display:inline-block;margin:0 6px 6px 0;padding:4px 10px;border:1px solid #b9cdf0;border-radius:999px;color:#0047ba;text-decoration:none;font-weight:600}'
            + '#thean-preorder-modal .tp-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;margin-top:14px}'
            + '#thean-preorder-modal .tp-actions button{padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-weight:600;cursor:pointer}'
            + '#thean-preorder-modal .tp-actions .is-primary{border-color:#003a99;background:#0047ba;color:#fff}'
            + '#thean-preorder-modal .tp-notice{min-height:18px;margin-top:10px;color:#b42318;font-weight:600}';
        document.head.appendChild(style);

        modal = el('div');
        modal.id = 'thean-preorder-modal';
        modal.hidden = true;
        form = el('form');
        form.setAttribute('role', 'dialog');
        form.setAttribute('aria-modal', 'true');
        modal.appendChild(form);
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('.tp-close, [data-tp-cancel]')) close();
        });
        form.addEventListener('submit', function (e) { e.preventDefault(); });
        form.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-tp-submit]');
            if (btn) submit(btn.getAttribute('data-tp-submit'), btn);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) { e.stopPropagation(); close(); }
        }, true);
    }

    function close() {
        if (modal) modal.hidden = true;
        current = null;
    }

    function fromTrigger(trigger) {
        var id = trigger.getAttribute('data-order-id') || '';
        var phone = trigger.getAttribute('data-phone') || '';
        if (!id && trigger.href) {
            try {
                var url = new URL(trigger.href, location.origin);
                id = url.searchParams.get('order_id') || '';
                phone = phone || url.searchParams.get('phone') || '';
            } catch (err) {}
        }
        return { orderId: String(parseInt(id, 10) || ''), phone: phone };
    }

    function open(kind, trigger) {
        if (!modal) build();
        var info = fromTrigger(trigger);
        if (!info.orderId) return;
        current = { kind: kind, trigger: trigger, orderId: info.orderId };
        form.replaceChildren();

        var closeBtn = el('button', 'tp-close', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Đóng');
        var title = el('h2', '', kind === 'preorder' ? '📅 Chuyển sang đặt trước — đơn #' + info.orderId : '📞 Xác nhận giao đơn đặt trước — #' + info.orderId);
        form.append(closeBtn, title);

        if (kind === 'preorder') {
            form.appendChild(el('p', '', 'Ghi chú lý do / hẹn giao (bắt buộc). Khi tiếp tục xử lý phải nhắn/gọi khách xác nhận giao trước.'));
        } else {
            form.appendChild(el('p', '', 'Nhắn / gọi khách xác nhận giao đơn, rồi ghi nhận kết quả.'));
            if (info.phone) {
                var contact = el('div', 'tp-contact');
                var tel = el('a', '', '📞 ' + info.phone);
                tel.href = 'tel:' + info.phone;
                var zalo = el('a', '', '💬 Zalo');
                zalo.href = 'https://zalo.me/' + info.phone.replace(/\D/g, '');
                zalo.target = '_blank';
                zalo.rel = 'noopener';
                contact.append(tel, zalo);
                form.appendChild(contact);
            }
        }
        var note = el('textarea');
        note.name = 'note';
        note.placeholder = kind === 'preorder' ? 'VD: hàng về ngày 15/11, khách hẹn giao sau Tết…' : 'Ghi chú thêm (không bắt buộc)';
        form.appendChild(note);

        var actions = el('div', 'tp-actions');
        var cancel = el('button', '', 'Hủy');
        cancel.type = 'button';
        cancel.setAttribute('data-tp-cancel', '');
        actions.appendChild(cancel);
        (kind === 'preorder'
            ? [['preorder', 'Chuyển sang đặt trước', true]]
            : [['waiting', 'Chờ khách phản hồi', false], ['confirmed', '✅ Khách đã xác nhận giao', true]]
        ).forEach(function (a) {
            var b = el('button', a[2] ? 'is-primary' : '', a[1]);
            b.type = 'button';
            b.setAttribute('data-tp-submit', a[0]);
            actions.appendChild(b);
        });
        form.append(actions, el('div', 'tp-notice'));
        modal.hidden = false;
        note.focus();
    }

    function submit(result, btn) {
        if (!current) return;
        var note = form.elements.note.value.trim();
        var noticeEl = form.querySelector('.tp-notice');
        if (current.kind === 'preorder' && !note) { noticeEl.textContent = 'Nhập ghi chú trước.'; return; }
        var fd = new FormData();
        fd.append('action', 'thean_order_workspace');
        fd.append('nonce', cfg.nonce);
        fd.append('order_id', current.orderId);
        if (current.kind === 'preorder') {
            fd.append('task', 'order_flow');
            fd.append('flow', 'preorder');
            fd.append('reason', note);
        } else {
            fd.append('task', 'preorder_confirm');
            fd.append('result', result);
            fd.append('note', note);
        }
        var job = current;
        form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        noticeEl.textContent = '';
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Không lưu được.');
                close();
                done(job, res.data && res.data.message);
            })
            .catch(function (err) { noticeEl.textContent = err.message || 'Lỗi kết nối.'; })
            .then(function () { form.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
    }

    function toast(text) {
        var t = el('div', '', text);
        t.style.cssText = 'position:fixed;right:24px;bottom:24px;z-index:1000004;max-width:360px;padding:12px 16px;border-radius:8px;background:#087443;color:#fff;font-weight:600;box-shadow:0 8px 24px rgba(15,23,42,.22)';
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 4000);
    }

    function done(job, message) {
        if (cfg.reload) { location.reload(); return; }
        var t = job.trigger;
        t.classList.add('is-thean-complete');
        t.setAttribute('data-thean-complete-label', job.kind === 'preorder' ? 'Đã đặt trước' : 'Đã ghi nhận');
        t.removeAttribute('href');
        toast(message || 'Đã lưu.');
        document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: job.orderId, kind: job.kind } }));
    }

    // Capture: chạy trước các handler khác của link thao tác (mở tab mới…).
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.wc-action-button-thean-order-preorder, [data-thean-preorder]');
        var kind = 'preorder';
        if (!trigger) {
            trigger = e.target.closest('.wc-action-button-thean-order-preorder-confirm, [data-thean-preorder-confirm]');
            kind = 'confirm';
        }
        if (!trigger || trigger.classList.contains('is-thean-complete')) return;
        e.preventDefault();
        e.stopPropagation();
        open(kind, trigger);
    }, true);
}());

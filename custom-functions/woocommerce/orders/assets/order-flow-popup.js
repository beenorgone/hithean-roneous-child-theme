/* Popup thao tác luồng đơn — dùng chung cho bảng điều phối (trang sửa đơn) và trang danh sách đơn (cột Thao tác,
 * ô tìm đơn, board "Tải đơn cần xử lý"). Dùng chung theanmarket + hithean.
 *
 * - Tạm giữ / Tiếp tục / Hủy: `[data-thean-flow="hold|resume|cancel"]` → task order_flow (lý do bắt buộc trừ Tiếp tục).
 * - Bỏ qua thiếu chứng từ XK: `[data-thean-flow="skipproof"]` → task export_proof_skip.
 * - Chuyển sang đặt trước (bắt buộc ghi chú): `.wc-action-button-thean-order-preorder` | `[data-thean-preorder]`
 *   → AJAX thean_order_workspace, task order_flow, flow preorder (order-workspace.php).
 * - Xác nhận giao đơn đặt trước (sau khi tiếp tục): `.wc-action-button-thean-order-preorder-confirm` |
 *   `[data-thean-preorder-confirm]` → task preorder_confirm, result confirmed | waiting.
 * - Sự cố giao hàng: `[data-thean-flow="issue"]` (bắt buộc ghi chú) / `"issue_resolve"` → task delivery_issue (order-flow-notes.php).
 * - Tạo ghi chú đơn: `[data-thean-flow="note"]` (chọn Nội bộ / Gửi khách) → task add_note.
 * - Không phải nội bộ (tab Đơn nội bộ): `[data-thean-flow="notinternal"]` → đọc tình trạng (task not_internal_preview) rồi
 *   chọn kịch bản: bỏ email nội bộ khỏi billing / tạo-gắn tài khoản khách → task not_internal (order-internal-flow.php).
 * - Mẫu ghi chú gợi ý: cfg.templates[kind] (Cài đặt ERP → Xử lý đơn → Mẫu ghi chú thao tác) — chip bấm để chèn vào ô ghi chú.
 *   spec.key = kind lấy mẫu (KINDS tự có; popup tùy biến truyền spec.key hoặc spec.templates).
 * Xong: phát sự kiện `thean:order-updated` (board tự làm mới); trang sửa đơn (cfg.reload) thì tải lại.
 */
(function () {
    var cfg = window.theanFlowCfg;
    if (!cfg) return;
    var KINDS = {
        preorder: { title: '📅 Chuyển sang đặt trước', help: 'Ghi chú lý do / hẹn giao (bắt buộc). Khi tiếp tục xử lý phải nhắn/gọi khách xác nhận giao trước.', placeholder: 'VD: hàng về ngày 15/11, khách hẹn giao sau Tết…', required: true, buttons: [['preorder', 'Chuyển sang đặt trước', 'primary']] },
        hold:     { title: '⏸️ Tạm giữ đơn', help: 'Tạm giữ để điều chỉnh (sửa sản phẩm / phí / địa chỉ). Nhập lý do.', placeholder: 'VD: khách đổi sản phẩm, sai địa chỉ…', required: true, buttons: [['hold', 'Tạm giữ', 'primary']] },
        cancel:   { title: '✖️ Hủy đơn', help: 'Hủy đơn sẽ trả hàng về tồn kho. Nhập lý do.', placeholder: 'VD: khách hủy, trùng đơn…', required: true, buttons: [['cancel', 'Hủy đơn và trả hàng về kho', 'danger']] },
        resume:   { title: '▶️ Tiếp tục xử lý', help: 'Đơn đặt trước: tiếp tục xong phải nhắn/gọi khách xác nhận giao. Đơn CK chưa thanh toán sẽ về Chờ thanh toán.', placeholder: 'Ghi chú (không bắt buộc)', required: false, buttons: [['resume', 'Tiếp tục', 'primary']] },
        skipproof: { title: '⏭️ Bỏ qua thiếu / chưa xác nhận ảnh xuất kho', help: 'Đơn đã giao đi mà thiếu ảnh hoặc xác nhận xuất kho. Đánh dấu bỏ qua để đơn rời tab "Thiếu / chưa xác nhận ảnh xuất kho".', placeholder: 'Lý do (không bắt buộc): VD đơn giao thẳng từ NCC, ảnh lưu nơi khác…', required: false, buttons: [['skipproof', 'Đánh dấu bỏ qua', 'primary']] },
        issue:    { title: '⚠️ Báo sự cố giao hàng', help: 'Đơn chuyển sang tab "Sự cố giao hàng" tới khi xử lý xong. Ghi rõ sự cố (bắt buộc).', placeholder: 'VD: gọi 3 lần không nghe máy, ĐVVC báo sai địa chỉ…', required: true, buttons: [['flag', 'Báo sự cố', 'danger']] },
        issue_resolve: { title: '✅ Xử lý xong sự cố giao hàng', help: 'Gỡ cờ sự cố — đơn quay lại luồng giao hàng (Chờ giao VC / Đang giao).', placeholder: 'Kết quả xử lý (không bắt buộc)', required: false, buttons: [['resolve', 'Xử lý xong', 'primary']] },
        note:     { title: '📝 Thêm ghi chú đơn', help: 'Ghi chú nội bộ chỉ nhân viên thấy; "Gửi khách" sẽ email cho khách.', placeholder: 'Nội dung ghi chú', required: true, select: { label: 'Loại ghi chú', value: 'private', options: [['private', '🔒 Nội bộ'], ['customer', '✉️ Gửi khách (email)']] }, buttons: [['note', 'Lưu ghi chú', 'primary']] },
        confirm:  { title: '📞 Xác nhận giao đơn đặt trước', help: 'Nhắn / gọi khách xác nhận giao đơn, rồi ghi nhận kết quả.', placeholder: 'Ghi chú thêm (không bắt buộc)', required: false, buttons: [['waiting', 'Chờ khách phản hồi', ''], ['confirmed', '✅ Khách đã xác nhận giao', 'primary']] }
    };
    Object.keys(KINDS).forEach(function (k) { KINDS[k].key = k; });
    var modal = null, form = null, current = null;

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text) node.textContent = text;
        return node;
    }

    function build() {
        var style = el('style');
        style.textContent = '#thean-flow-modal[hidden]{display:none}'
            + '#thean-flow-modal{position:fixed;inset:0;z-index:1000003;display:grid;place-items:center;padding:16px;background:rgba(15,23,42,.5)}'
            + '#thean-flow-modal form{position:relative;box-sizing:border-box;width:min(480px,100%);padding:20px;border-radius:12px;background:#fff;box-shadow:0 24px 60px rgba(15,23,42,.32);font-size:13px}'
            + '#thean-flow-modal h2{margin:0 32px 8px 0;font-size:18px;line-height:1.3}'
            + '#thean-flow-modal p{margin:0 0 10px;color:#475569}'
            + '#thean-flow-modal textarea{box-sizing:border-box;width:100%;min-height:80px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}'
            + '#thean-flow-modal .tp-close{position:absolute;top:10px;right:10px;width:30px;height:30px;border:0;border-radius:50%;background:#eef2f7;font-size:20px;cursor:pointer}'
            + '#thean-flow-modal .tp-contact a{display:inline-block;margin:0 6px 6px 0;padding:4px 10px;border:1px solid #b9cdf0;border-radius:999px;color:#0047ba;text-decoration:none;font-weight:600}'
            + '#thean-flow-modal .tp-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;margin-top:14px}'
            + '#thean-flow-modal .tp-actions button{padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-weight:600;cursor:pointer}'
            + '#thean-flow-modal .tp-actions .is-primary{border-color:#003a99;background:#0047ba;color:#fff}'
            + '#thean-flow-modal .tp-actions .is-danger{border-color:#b42318;background:#d92d20;color:#fff}'
            + '#thean-flow-modal .tp-tpl{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 0}'
            + '#thean-flow-modal .tp-tpl button{padding:4px 10px;border:1px solid #b9cdf0;border-radius:999px;background:#f5f8ff;color:#0047ba;font:inherit;font-size:12px;cursor:pointer;text-align:left}'
            + '#thean-flow-modal .tp-tpl button:hover{background:#e8f0ff}'
            + '#thean-flow-modal .tp-opt{display:flex;gap:8px;align-items:flex-start;margin:0 0 10px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}'
            + '#thean-flow-modal .tp-opt input[type=checkbox]{margin-top:2px}'
            + '#thean-flow-modal .tp-opt small{display:block;margin-top:3px;color:#475569}'
            + '#thean-flow-modal .tp-opt input[type=email]{box-sizing:border-box;width:100%;margin-top:6px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px}'
            + '#thean-flow-modal .tp-opt.is-off{opacity:.6}'
            + '#thean-flow-modal .tp-notice{min-height:18px;margin-top:10px;color:#b42318;font-weight:600}';
        document.head.appendChild(style);

        modal = el('div');
        modal.id = 'thean-flow-modal';
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
            var tpl = e.target.closest('[data-tp-tpl]');
            if (tpl) {
                var area = form.elements.note, text = tpl.getAttribute('data-tp-tpl'), cur = area.value.trim();
                if (cur.indexOf(text) === -1) area.value = cur ? cur.replace(/[.\s]*$/, '') + '. ' + text : text;
                area.focus();
                return;
            }
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

    /**
     * Dựng popup. spec: {title, help, placeholder, required, buttons: [[key, label, tone]], phone,
     * select?: {label, options: [[value, label]]} — giá trị chọn truyền vào onSubmit(note, key, selectValue)}.
     * current = {kind, trigger, orderId, onSubmit?}.
     */
    function render(spec, job) {
        if (!modal) build();
        current = job;
        form.replaceChildren();
        var closeBtn = el('button', 'tp-close', '×');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Đóng');
        form.append(closeBtn, el('h2', '', spec.title), el('p', '', spec.help || ''));
        if (spec.phone) {
            var contact = el('div', 'tp-contact');
            var tel = el('a', '', '📞 ' + spec.phone);
            tel.href = 'tel:' + spec.phone;
            var zalo = el('a', '', '💬 Zalo');
            zalo.href = 'https://zalo.me/' + spec.phone.replace(/\D/g, '');
            zalo.target = '_blank';
            zalo.rel = 'noopener';
            contact.append(tel, zalo);
            form.appendChild(contact);
        }
        if (spec.select) {
            var sel = el('select');
            sel.name = 'select';
            sel.style.cssText = 'width:100%;margin:0 0 10px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px';
            if (!spec.select.value) {
                var first = el('option', '', spec.select.label || 'Chọn…');
                first.value = '';
                sel.appendChild(first);
            }
            spec.select.options.forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; sel.appendChild(opt); });
            if (spec.select.value) sel.value = spec.select.value;
            form.appendChild(sel);
        }
        var note = el('textarea');
        note.name = 'note';
        note.placeholder = spec.placeholder || '';
        form.appendChild(note);
        var tpls = spec.templates || ((cfg.templates || {})[spec.key] || []);
        if (tpls.length) {
            var box = el('div', 'tp-tpl');
            box.setAttribute('aria-label', 'Mẫu ghi chú gợi ý');
            tpls.forEach(function (t) {
                var b = el('button', '', t);
                b.type = 'button';
                b.setAttribute('data-tp-tpl', t);
                box.appendChild(b);
            });
            form.appendChild(box);
        }
        var actions = el('div', 'tp-actions');
        var cancel = el('button', '', 'Đóng');
        cancel.type = 'button';
        cancel.setAttribute('data-tp-cancel', '');
        actions.appendChild(cancel);
        spec.buttons.forEach(function (a) {
            var b = el('button', a[2] ? 'is-' + a[2] : '', a[1]);
            b.type = 'button';
            b.setAttribute('data-tp-submit', a[0]);
            actions.appendChild(b);
        });
        form.append(actions, el('div', 'tp-notice'));
        current.required = !!spec.required;
        current.needSelect = !!spec.select;
        modal.hidden = false;
        note.focus();
    }

    function post(fields) {
        var fd = new FormData();
        fd.append('action', 'thean_order_workspace');
        fd.append('nonce', cfg.nonce);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Không lưu được.');
                return res.data || {};
            });
    }

    /** Kịch bản "Không phải nội bộ": tick chọn từng việc, server làm theo thứ tự email → tài khoản → gỡ cờ. */
    function openNotInternal(trigger) {
        var info = fromTrigger(trigger);
        if (!info.orderId) return;
        render({
            title: '🙅 Không phải đơn nội bộ — đơn #' + info.orderId,
            help: 'Đơn sẽ rời tab "Đơn nội bộ" và đi luồng khách như đơn thường (gỡ cờ nội bộ, eim xuất VAT bình thường). Chọn việc cần làm:',
            placeholder: 'Ghi chú (không bắt buộc): VD khách mua hộ qua nhân viên…',
            required: false, templates: [],
            buttons: [['apply', 'Chuyển sang đơn khách', 'primary']]
        }, { kind: 'notinternal', trigger: trigger, orderId: info.orderId, onSubmit: function (note) {
            var box = form.querySelector('.tp-ni');
            if (!box || !box.getAttribute('data-ready')) return Promise.reject(new Error('Đang tải thông tin đơn…'));
            var rm = form.elements.ni_email && form.elements.ni_email.checked;
            var cu = form.elements.ni_customer && form.elements.ni_customer.checked;
            return post({ task: 'not_internal', order_id: info.orderId, remove_email: rm ? 1 : '', customer: cu ? 1 : '',
                new_email: rm && form.elements.ni_new_email ? form.elements.ni_new_email.value.trim() : '', note: note })
                .then(function (data) { setTimeout(function () { done({ kind: 'notinternal', trigger: trigger, orderId: info.orderId }, data.message); }, 0); });
        } });
        var box = el('div', 'tp-ni', 'Đang đọc thông tin đơn…');
        form.insertBefore(box, form.elements.note);
        post({ task: 'not_internal_preview', order_id: info.orderId }).then(function (d) {
            box.replaceChildren();
            var email = el('label', 'tp-opt' + (d.email_internal ? '' : ' is-off'));
            var cb1 = el('input'); cb1.type = 'checkbox'; cb1.name = 'ni_email'; cb1.checked = !!d.email_internal;
            var t1 = el('span', '', '✉️ Bỏ email nội bộ khỏi email billing');
            t1.appendChild(el('small', '', d.email_internal ? 'Email hiện tại: ' + d.email + ' (domain nội bộ). Để trống ô dưới = lấy email tài khoản khách (nếu là email thật) hoặc để trống.'
                : 'Email hiện tại ' + (d.email || '(trống)') + ' không thuộc domain nội bộ — chỉ đổi khi nhập email mới.'));
            var ne = el('input'); ne.type = 'email'; ne.name = 'ni_new_email'; ne.placeholder = 'Email thật của khách (không bắt buộc)';
            t1.appendChild(ne);
            email.append(cb1, t1);
            var cust = el('label', 'tp-opt' + (d.customer.mode === 'none' ? ' is-off' : ''));
            var cb2 = el('input'); cb2.type = 'checkbox'; cb2.name = 'ni_customer'; cb2.checked = d.customer.mode === 'attach' || d.customer.mode === 'create';
            cb2.disabled = d.customer.mode === 'none' || d.customer.mode === 'keep';
            var t2 = el('span', '', '👤 Tạo / gắn tài khoản khách theo thông tin đặt / giao');
            t2.appendChild(el('small', '', (d.name || d.phone ? (d.name + (d.phone ? ' · ' + d.phone : '')) + ' — ' : '') + d.customer.text));
            cust.append(cb2, t2);
            box.append(email, cust);
            if (d.flag) box.appendChild(el('p', '', '🏷️ Đơn đang có cờ _eim_internal — sẽ gỡ.'));
            box.setAttribute('data-ready', '1');
        }).catch(function (err) { box.textContent = err.message || 'Không đọc được thông tin đơn.'; });
    }

    function open(kind, trigger) {
        if (kind === 'notinternal') { openNotInternal(trigger); return; }
        var info = fromTrigger(trigger);
        var spec = KINDS[kind];
        if (!info.orderId || !spec) return;
        render(Object.assign({}, spec, {
            title: spec.title + ' — đơn #' + info.orderId,
            phone: kind === 'confirm' ? info.phone : ''
        }), { kind: kind, trigger: trigger, orderId: info.orderId });
    }

    function submit(result, btn) {
        if (!current) return;
        var note = form.elements.note.value.trim();
        var noticeEl = form.querySelector('.tp-notice');
        if (current.required && !note) { noticeEl.textContent = 'Nhập ghi chú / lý do trước.'; return; }
        var picked = form.elements.select ? form.elements.select.value : '';
        if (current.needSelect && !picked) { noticeEl.textContent = 'Chọn giá trị trước.'; return; }
        if (current.onSubmit) {
            // Popup tùy biến (VD thao tác hàng loạt): người gọi tự gửi; Promise reject → hiện lỗi, giữ popup.
            var custom = current;
            form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
            Promise.resolve().then(function () { return custom.onSubmit(note, result, picked); })
                .then(function () { close(); })
                .catch(function (err) { noticeEl.textContent = (err && err.message) || 'Lỗi.'; })
                .then(function () { form.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
            return;
        }
        var fd = new FormData();
        fd.append('action', 'thean_order_workspace');
        fd.append('nonce', cfg.nonce);
        fd.append('order_id', current.orderId);
        if (current.kind === 'issue' || current.kind === 'issue_resolve') {
            fd.append('task', 'delivery_issue');
            fd.append('op', result);
            fd.append('note', note);
        } else if (current.kind === 'note') {
            fd.append('task', 'add_note');
            fd.append('note_type', picked || 'private');
            fd.append('note', note);
        } else if (current.kind === 'skipproof') {
            fd.append('task', 'export_proof_skip');
            fd.append('note', note);
        } else if (current.kind !== 'confirm') {
            fd.append('task', 'order_flow');
            fd.append('flow', current.kind);
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
        if (job.kind === 'note') {
            // Ghi chú thêm được nhiều lần; trang sửa đơn (reload) đã tải lại ở trên.
            toast(message || 'Đã lưu.');
            document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: job.orderId, kind: job.kind } }));
            return;
        }
        t.classList.add('is-thean-complete');
        t.setAttribute('data-thean-complete-label', { preorder: 'Đã đặt trước', hold: 'Đã tạm giữ', cancel: 'Đã hủy', resume: 'Đã tiếp tục', skipproof: 'Đã bỏ qua', issue: 'Đã báo sự cố', issue_resolve: 'Đã xử lý sự cố', notinternal: 'Đã chuyển đơn khách' }[job.kind] || 'Đã ghi nhận');
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
        if (!trigger) {
            trigger = e.target.closest('[data-thean-flow]');
            kind = trigger ? trigger.getAttribute('data-thean-flow') : '';
        }
        if (!trigger || trigger.classList.contains('is-thean-complete')) return;
        e.preventDefault();
        e.stopPropagation();
        open(kind, trigger);
    }, true);

    // API cho ô tìm đơn / board (thao tác hàng loạt): window.theanFlow.prompt(spec, onSubmit(note, key) → Promise).
    window.theanFlow = {
        kinds: KINDS,
        prompt: function (spec, onSubmit) { render(spec, { kind: 'custom', onSubmit: onSubmit }); }
    };
}());

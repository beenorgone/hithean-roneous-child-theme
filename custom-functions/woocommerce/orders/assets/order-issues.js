/* Shared with theanmarket. Native dialog provides focus trapping and Escape. */
(function () {
    'use strict';
    var cfg = window.theanIssueCfg;
    if (!cfg) return;
    var dialog, content, status, current, opener, sequence = 0;
    var labels = { open: 'Cần xử lý', working: 'Đang xử lý', resolved: 'Đã xử lý' };
    function el(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    function button(text, run) {
        var node = el('button', text);
        node.type = 'button'; node.addEventListener('click', run);
        return node;
    }
    function ensureDialog() {
        if (dialog) return;
        dialog = el('dialog', undefined, 'thean-incidents');
        dialog.setAttribute('aria-labelledby', 'thean-incidents-title');
        var head = el('header');
        var title = el('h2', 'Sự cố đơn hàng'); title.id = 'thean-incidents-title';
        head.append(title, button('Đóng', function () { dialog.close(); }));
        status = el('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        content = el('div'); dialog.append(head, status, content); document.body.append(dialog);
        dialog.addEventListener('close', function () { sequence++; if (opener && opener.isConnected) opener.focus(); });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { var r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); } });
    }
    async function request(data) {
        var form = new FormData();
        form.set('action', 'thean_order_issues'); form.set('nonce', cfg.nonce);
        Object.keys(data).forEach(function (key) { form.set(key, data[key]); });
        var response = await fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: form });
        var result = await response.json();
        if (!result.success) throw new Error((result.data && result.data.message) || 'Không xử lý được sự cố.');
        return result.data;
    }
    function name(userId) { return userId ? ' · Nhân viên #' + userId : ''; }
    function time(value) { return value ? new Date(value * 1000).toLocaleString('vi-VN') : ''; }
    function render(data) {
        current = data;
        dialog.querySelector('h2').textContent = 'Sự cố đơn #' + data.id;
        content.replaceChildren();
        status.textContent = data.pending ? 'Đang kiểm tra dữ liệu cũ ở nền; bộ lọc có thể chưa đầy đủ. Đơn này đã được kiểm tra lại.' : '';
        if (!data.records.length) content.append(el('p', 'Đơn chưa có sự cố.'));
        data.records.forEach(function (record) {
            var card = el('section', undefined, 'thean-incident-card' + (record.blocked ? ' is-blocked' : ''));
            card.append(el('h3', record.label), el('strong', (labels[record.status] || record.status) + (record.blocked ? ' · Đang chặn thực hiện đơn' : '')));
            if (record.since_at) card.append(el('p', 'Mốc tính: ' + time(record.since_at) + ' · đã qua ' + Math.floor((Date.now() / 1000 - record.since_at) / 86400) + ' ngày'));
            card.append(el('p', record.description), el('p', record.help, 'thean-incidents-help'));
            card.append(el('small', 'Ghi nhận: ' + time(record.created_at) + name(record.created_by)));
            if (record.ack_at) card.append(el('p', (record.blocked ? 'Lần kiểm tra trước đã hết hiệu lực: ' : 'Đã kiểm tra: ') + time(record.ack_at) + name(record.ack_by)));
            if (record.updated_at) card.append(el('small', 'Cập nhật: ' + time(record.updated_at) + name(record.updated_by)));
            if (record.resolution) card.append(el('p', 'Kết quả: ' + record.resolution));
            (record.related || []).forEach(function (order) {
                var row = el('p', undefined, 'thean-incidents-related');
                var link = el('a', '#' + order.number); link.href = order.url; link.target = '_blank'; link.rel = 'noopener';
                row.append(link, document.createTextNode(' · ' + order.at + ' · ' + order.status + ' · ' + order.total + ' — ' + order.reasons.join(', ')));
                card.append(row);
            });
            if (data.can_manage !== false && record.actions && record.actions.length) {
                var note = el('textarea'); note.rows = 2; note.maxLength = 2000; note.setAttribute('aria-label', 'Ghi chú / kết quả xử lý'); note.placeholder = 'Ghi chú / kết quả xử lý';
                card.append(note);
                var actions = el('div', undefined, 'thean-incidents-actions');
                record.actions.forEach(function (op) {
                    if (op === 'start' && record.status === 'working') return;
                    actions.append(button({ start: 'Nhận xử lý', ack: 'Đã kiểm tra đơn này', resolve: 'Đã xử lý xong', edit: 'Lưu nội dung' }[op] || op, function () {
                        mutate({ op: op, instance: record.id, token: record.token, note: note.value });
                    }));
                });
                card.append(actions);
            }
            content.append(card);
        });
        var form = el('form', undefined, 'thean-incidents-create');
        form.append(el('h3', 'Báo sự cố'));
        var note = el('textarea'); note.required = true; note.maxLength = 2000; note.rows = 3; note.setAttribute('aria-label', 'Nội dung sự cố'); note.placeholder = 'Mô tả sự cố và việc cần làm';
        var mode = el('select'); mode.setAttribute('aria-label', 'Mức ảnh hưởng');
        [['warn', 'Chỉ cảnh báo'], ['resolve', 'Chặn thực hiện đơn đến khi xử lý xong']].forEach(function (pair) { var option = el('option', pair[1]); option.value = pair[0]; mode.append(option); });
        var submit = el('button', 'Ghi nhận sự cố'); submit.type = 'submit';
        form.append(note, mode, submit);
        form.addEventListener('submit', function (event) { event.preventDefault(); if (form.reportValidity()) mutate({ op: 'create', note: note.value, mode: mode.value }); });
        if (data.can_manage !== false) content.append(form);
        else content.append(el('p', 'Liên hệ người có quyền xử lý đơn để xác nhận hoặc xử lý sự cố.'));
        if (data.history && data.history.length) {
            var history = el('details'); history.append(el('summary', 'Lịch sử sự cố (trong 50 ghi chú gần nhất)'));
            data.history.forEach(function (entry) { history.append(el('p', entry.at + ' · ' + entry.by + '\n' + entry.text)); });
            content.append(history);
        }
    }
    async function mutate(data) {
        var epoch = sequence, id = current.id;
        var disabled = Array.from(content.querySelectorAll('button, textarea, select'));
        disabled.forEach(function (node) { node.disabled = true; });
        status.textContent = 'Đang lưu…';
        try {
            var result = await request(Object.assign({ order_id: id }, data));
            document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: id } }));
            if (epoch === sequence && dialog.open) { render(result); status.textContent = 'Đã lưu.'; }
        } catch (error) {
            if (epoch === sequence && dialog.open) status.textContent = error.message + ' Có thể đóng và mở lại để tải dữ liệu mới.';
        } finally { disabled.forEach(function (node) { node.disabled = false; }); }
    }
    document.addEventListener('click', async function (event) {
        var trigger = event.target.closest('[data-order-issues]');
        if (!trigger) return;
        event.preventDefault(); event.stopPropagation();
        ensureDialog(); opener = trigger; var epoch = ++sequence;
        current = null; content.replaceChildren(); status.textContent = 'Đang kiểm tra sự cố và các đơn liên quan…';
        if (!dialog.open) dialog.showModal();
        try { var data = await request({ order_id: trigger.dataset.orderIssues, op: 'read' }); if (epoch === sequence && dialog.open) render(data); }
        catch (error) { if (epoch === sequence && dialog.open) status.textContent = error.message; }
    }, true);
}());

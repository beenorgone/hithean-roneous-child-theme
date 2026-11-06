/* Review evidence before invoking the existing warehouse export confirmation action. */
(function () {
    'use strict';
    var cfg = window.theanExportReviewCfg || {};
    var modal = document.getElementById('thean-export-review-modal');
    if (!modal) return;
    var form = modal.querySelector('[data-export-review-form]');
    var content = modal.querySelector('[data-export-review-content]');
    var notice = modal.querySelector('[data-export-review-notice]');
    var submit = form.querySelector('[type="submit"]');
    var reload = form.querySelector('[data-export-review-reload]');
    var state = { id: 0, button: null, opener: null, data: null, ctl: null, version: 0, busy: false };
    function element(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }
    function message(response, fallback) {
        return response && response.data ? (typeof response.data === 'string' ? response.data : response.data.message || fallback) : fallback;
    }
    function post(action, fields, signal) {
        var data = new FormData(); data.set('action', action);
        Object.keys(fields).forEach(function (key) { data.set(key, fields[key]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data, signal: signal }).then(function (response) { return response.json(); });
    }
    function close() {
        if (state.busy) return;
        state.version++;
        if (state.ctl) state.ctl.abort();
        modal.hidden = true; document.body.classList.remove('thean-export-review-open'); state.data = null; submit.disabled = true;
        if (state.opener && state.opener.isConnected) state.opener.focus({ preventScroll: true });
    }
    function render(data) {
        content.replaceChildren();
        modal.querySelector('[data-export-review-summary]').textContent = 'Đơn #' + data.number + ' · ' + data.status;
        var grid = element('div', undefined, 'thean-export-review__grid');
        var photos = element('section'); photos.appendChild(element('h3', 'Ảnh lấy hàng / xuất kho (' + data.images.length + ')'));
        if (data.images.length) {
            var hero = element('img', undefined, 'thean-export-review__hero');
            var openImage = element('a', 'Mở ảnh lớn ↗'); openImage.target = '_blank'; openImage.rel = 'noopener';
            var thumbs = element('div', undefined, 'thean-export-review__thumbs');
            function select(index) {
                hero.src = data.images[index]; hero.alt = 'Ảnh xuất kho ' + (index + 1) + '/' + data.images.length;
                openImage.href = data.images[index];
                thumbs.querySelectorAll('button').forEach(function (button, i) { button.setAttribute('aria-pressed', String(i === index)); });
            }
            data.images.forEach(function (url, index) {
                var button = element('button'); button.type = 'button'; button.setAttribute('aria-label', 'Xem ảnh ' + (index + 1));
                var image = element('img'); image.src = url; image.alt = 'Ảnh ' + (index + 1); image.loading = 'lazy';
                button.appendChild(image); button.addEventListener('click', function () { select(index); }); thumbs.appendChild(button);
            });
            select(0); photos.appendChild(hero); photos.appendChild(thumbs); photos.appendChild(openImage);
        } else photos.appendChild(element('p', 'Chưa có ảnh xuất kho. Hãy tải ảnh trước khi xác nhận.'));
        var detail = element('section'); detail.appendChild(element('h3', 'Sản phẩm cần đối chiếu'));
        var items = element('ul'); data.items.forEach(function (item) { items.appendChild(element('li', item.name + ' × ' + item.quantity)); }); detail.appendChild(items);
        var ai = element('section', undefined, 'thean-export-review__ai'); ai.appendChild(element('h3', 'Kết quả & ghi chú AI'));
        if (data.ai.state === 'queued') ai.appendChild(element('p', 'AI đang xử lý. Nếu có kết quả bên dưới, đó là lần kiểm tra trước.'));
        else if (data.ai.state === 'none') ai.appendChild(element('p', 'Chưa có kết quả AI được lưu.'));
        data.ai.fields.forEach(function (field) { ai.appendChild(element('p', field.label + ': ' + field.value)); });
        if (data.ai.reason) ai.appendChild(element('p', data.ai.reason));
        var issues = element('ul'); data.ai.issues.forEach(function (issue) { issues.appendChild(element('li', issue)); }); ai.appendChild(issues);
        if (data.ai.checked_at) ai.appendChild(element('p', 'Lần kiểm tra: ' + data.ai.checked_at));
        if (data.ai.note) { ai.appendChild(element('h3', 'Ghi chú đã lưu trên đơn')); ai.appendChild(element('pre', data.ai.note)); }
        ai.appendChild(element('p', 'AI chỉ hỗ trợ đối chiếu. Hãy kiểm tra ảnh, mã đơn và số lượng trước khi bấm xác nhận.'));
        detail.appendChild(ai); grid.appendChild(photos); grid.appendChild(detail); content.appendChild(grid);
        notice.textContent = data.blocked || '';
        submit.disabled = !data.can_confirm;
    }
    function load() {
        if (state.busy) return;
        if (state.ctl) state.ctl.abort();
        var version = ++state.version, id = state.id, ctl = new AbortController(); state.ctl = ctl;
        var timeout = setTimeout(function () { ctl.abort(); }, 15000);
        state.data = null; submit.disabled = true; reload.disabled = true; notice.textContent = '';
        content.replaceChildren(element('p', 'Đang tải ảnh và ghi chú AI…'));
        post('thean_order_export_review', { nonce: cfg.readNonce, order_id: id }, ctl.signal).then(function (response) {
            if (modal.hidden || state.version !== version) return;
            if (!response.success) throw new Error(message(response, 'Không tải được ảnh và ghi chú AI.'));
            if (Number(response.data.order_id) !== id) throw new Error('Dữ liệu không khớp đơn đang mở.');
            state.data = response.data; render(response.data);
        }).catch(function (error) {
            if (modal.hidden || state.version !== version) return;
            notice.textContent = error.name === 'AbortError' ? 'Tải quá lâu. Bấm Tải lại ảnh & AI để thử lại.' : error.message || 'Lỗi kết nối.';
            content.replaceChildren();
        }).finally(function () { clearTimeout(timeout); if (state.version === version) reload.disabled = false; });
    }
    // Capture runs before legacy quick-action handlers, so opening the review can never submit a confirmation.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.wc-action-button-thean-order-export-confirm, .wc-action-button-hithean-order-export-confirm');
        if (!button) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (state.busy || !button.getAttribute('href')) return;
        var url = new URL(button.href, location.href), id = Number(url.searchParams.get('order_id'));
        if (url.origin !== location.origin || !Number.isSafeInteger(id) || id <= 0) return;
        state.id = id; state.button = button; state.opener = document.activeElement;
        modal.hidden = false; document.body.classList.add('thean-export-review-open'); modal.querySelector('.thean-export-review__dialog').scrollTop = 0;
        modal.querySelector('button[data-export-review-close]').focus({ preventScroll: true });
        modal.querySelector('[data-export-review-summary]').textContent = 'Đơn #' + id;
        load();
    }, true);
    modal.addEventListener('click', function (event) {
        if (event.target.closest('[data-export-review-close]')) close();
        else if (event.target.closest('[data-export-review-reload]')) load();
    });
    document.addEventListener('keydown', function (event) {
        if (modal.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(); }
        if (event.key === 'Tab') {
            var nodes = Array.from(modal.querySelectorAll('button:not(:disabled), a[href]'));
            var first = nodes[0], last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }, true);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (state.busy || !state.data || !state.data.can_confirm || submit.disabled) return;
        var id = state.id, button = state.button;
        state.busy = true; submit.disabled = true; reload.disabled = true; submit.textContent = 'Đang xác nhận…'; notice.textContent = '';
        post('ajax_confirm_export', { nonce: cfg.confirmNonce, uexe_order_id: id }).then(function (response) {
            if (!response.success) throw new Error(message(response, 'Không thể xác nhận xuất kho.'));
            state.busy = false; close();
            button.classList.add('is-thean-complete', 'is-hithean-complete'); button.removeAttribute('href');
            document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: id } }));
            var toast = element('div', message(response, 'Đã xác nhận xuất kho.'), 'thean-export-review__toast'); toast.setAttribute('role', 'status'); document.body.appendChild(toast);
            setTimeout(function () { toast.remove(); }, 4000);
        }).catch(function (error) { notice.textContent = error.message || 'Lỗi kết nối. Chưa xác nhận được xuất kho.'; })
        .finally(function () { state.busy = false; submit.disabled = !state.data || !state.data.can_confirm; reload.disabled = false; submit.textContent = 'Xác nhận xuất kho'; });
    });
}());

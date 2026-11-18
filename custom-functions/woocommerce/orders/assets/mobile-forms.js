/**
 * Form trên mobile → nút thao tác + popup (dùng chung hithean ↔ theanmarket, GIỐNG HỆT 2 site).
 *
 * Áp cho các tab công cụ nhúng ở /quan-ly-don (iframe ?thean_order_tool=… — trừ Tạo đơn) và khối Shopee của portal.
 * ≤782px: mỗi form "thật" (≥2 trường, hoặc có ô file / textarea) được gói TẠI CHỖ vào <dialog> (không chuyển form đi nơi
 * khác → id, closest(), handler jQuery uỷ quyền của shortcode vẫn chạy), chỗ cũ hiện 1 nút; bấm → showModal().
 * Form 1 ô (quét mã / tìm nhanh) giữ nguyên. Rộng hơn breakpoint → gỡ dialog, trả form về chỗ cũ.
 *
 * Tuỳ chỉnh bằng thuộc tính:
 *   data-mform="off"          bỏ qua form này
 *   [data-mform] (không phải form) gom khối này thành popup (VD khối nhập chứng từ đối soát)
 *   data-mform-label="…"      chữ trên nút + tiêu đề popup
 *   data-mform-close          (nút trong khối) bấm → đóng popup sau khi chạy
 *   data-mform-keep           không tự đóng popup khi submit
 * Mặc định submit → đóng popup (kết quả nằm ngoài form), trừ khi trong form có ô trạng thái (status) — để thấy tiến trình.
 * Khởi tạo: tự quét [data-mform-root] lúc tải; gọi thêm TheanMobileForms.init(el) cho vùng khác.
 */
(function () {
    'use strict';
    if (window.TheanMobileForms) return;

    var MQ = window.matchMedia ? window.matchMedia('(max-width: 782px)') : null;
    var SKIP_IN = 'dialog,[role="dialog"],[aria-modal="true"],.spx-modal,.orm-modal,.thean-quick-modal,[class*="-modal__"],[class*="modal-body"],template';
    var LABELS = [
        ['#proteinCalcForm', '🧮 Nhập thông số tính protein'],
        ['.hpa-form', '✨ Soạn câu hỏi cho AI tư vấn'],
        ['#upload-export-form', '📤 Upload ảnh xuất kho'],
        ['.attach-return-form', '↩️ Ghi nhận hoàn hàng'],
        ['#spx-upload-form', '📷 Tải ảnh 1 kiện'],
        ['#spx-bulk-form', '🗂️ Tải ảnh nhiều kiện'],
        ['#spx-search-form', '🔎 Tìm / lọc kiện']
    ];
    var roots = [];
    var items = [];
    var observers = [];
    var seq = 0;

    function isMobile() { return !!(MQ && MQ.matches); }

    function fieldsOf(el) {
        return Array.prototype.filter.call(el.querySelectorAll('input,select,textarea'), function (f) {
            var t = (f.type || '').toLowerCase();
            return ['hidden', 'submit', 'button', 'reset', 'image'].indexOf(t) < 0;
        });
    }

    function eligible(el) {
        if (el.dataset.mformDone || el.getAttribute('data-mform') === 'off') return false;
        if (el.parentElement && el.parentElement.closest(SKIP_IN)) return false;
        if (el.parentElement && el.parentElement.closest('[data-mform-done]')) return false; // form trong khối đã gom
        if (el.tagName !== 'FORM') return true; // khối opt-in
        var fields = fieldsOf(el);
        var rich = fields.some(function (f) { return f.tagName === 'TEXTAREA' || (f.type || '').toLowerCase() === 'file'; });
        return rich || fields.length >= 2;
    }

    function clean(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }

    function labelOf(el) {
        if (el.dataset.mformLabel) return el.dataset.mformLabel;
        for (var i = 0; i < LABELS.length; i++) if (el.matches(LABELS[i][0])) return LABELS[i][1];
        var head = el.querySelector('legend,h2,h3,h4,strong');
        if (head && clean(head.textContent).length <= 60) return clean(head.textContent);
        var prev = el.previousElementSibling;
        if (prev && /^H[2-4]$/.test(prev.tagName)) return clean(prev.textContent);
        var btn = el.querySelector('button[type="submit"],input[type="submit"],button:not([type])');
        var t = btn ? clean(btn.textContent || btn.value) : '';
        return t || 'Mở biểu mẫu';
    }

    function keepOpen(el) {
        if (el.hasAttribute('data-mform-keep')) return true;
        return !!el.querySelector('[role="status"],[aria-live],[id*="status"],[class*="status"]');
    }

    function syncHidden(it) {
        var hide = it.el.hidden || it.el.style.display === 'none';
        it.trigger.hidden = hide;
        if (hide && it.dialog.open) it.dialog.close();
    }

    function open(it) {
        if (it.dialog.open) return;
        if (typeof it.dialog.showModal === 'function') it.dialog.showModal();
        else it.dialog.setAttribute('open', '');
        document.documentElement.classList.add('tmf-locked');
        var first = fieldsOf(it.el).filter(function (f) { return !f.disabled && f.offsetParent !== null && f.type !== 'file'; })[0];
        // Không tự bật bàn phím ảo che nửa popup: chỉ focus khi popup có 1 ô.
        if (first && fieldsOf(it.el).length === 1) first.focus();
    }

    function close(it) {
        if (it.dialog.open) {
            if (typeof it.dialog.close === 'function') it.dialog.close();
            else it.dialog.removeAttribute('open');
        }
    }

    function wrap(el) {
        var id = 'tmf-' + (++seq);
        var label = labelOf(el);
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'tmf-trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-controls', id);
        trigger.innerHTML = '<span class="tmf-trigger__label"></span><span class="tmf-trigger__chev" aria-hidden="true">›</span>';
        trigger.firstChild.textContent = label;

        var dialog = document.createElement('dialog');
        dialog.className = 'tmf-dialog';
        dialog.id = id;
        dialog.setAttribute('aria-label', label);
        dialog.innerHTML = '<div class="tmf-dialog__head"><strong class="tmf-dialog__title"></strong>' +
            '<button type="button" class="tmf-dialog__x" aria-label="Đóng">✕</button></div><div class="tmf-dialog__body"></div>';
        dialog.querySelector('.tmf-dialog__title').textContent = label;

        el.parentNode.insertBefore(trigger, el);
        el.parentNode.insertBefore(dialog, el);
        dialog.querySelector('.tmf-dialog__body').appendChild(el);
        el.dataset.mformDone = '1';

        var it = { el: el, trigger: trigger, dialog: dialog, keep: keepOpen(el), mo: null };
        trigger.addEventListener('click', function () { open(it); });
        dialog.querySelector('.tmf-dialog__x').addEventListener('click', function () { close(it); });
        dialog.addEventListener('click', function (e) { if (e.target === dialog) close(it); }); // bấm nền
        dialog.addEventListener('close', function () {
            if (!items.some(function (x) { return x.dialog.open; })) document.documentElement.classList.remove('tmf-locked');
        });
        // Đóng sau khi handler của shortcode (uỷ quyền ở document) đã đọc dữ liệu form.
        dialog.addEventListener('submit', function (e) {
            if (it.keep || e.target !== el) return;
            setTimeout(function () { close(it); }, 0);
        });
        dialog.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-mform-close]');
            if (b && dialog.contains(b)) setTimeout(function () { close(it); }, 0);
        });
        if (window.MutationObserver) {
            it.mo = new MutationObserver(function () { syncHidden(it); });
            it.mo.observe(el, { attributes: true, attributeFilter: ['hidden', 'style'] });
        }
        syncHidden(it);
        items.push(it);
    }

    function unwrap(it) {
        close(it);
        if (it.mo) it.mo.disconnect();
        if (it.trigger.parentNode) it.trigger.parentNode.insertBefore(it.el, it.trigger);
        it.trigger.remove();
        it.dialog.remove();
        delete it.el.dataset.mformDone;
    }

    function scan(root) {
        if (!isMobile() || !root || !root.isConnected) return;
        var list = Array.prototype.slice.call(root.querySelectorAll('form,[data-mform]:not([data-mform="off"])'));
        if (root.matches && root.matches('form,[data-mform]')) list.unshift(root);
        list.forEach(function (el) { if (el.isConnected && eligible(el)) wrap(el); });
    }

    function scanAll() { roots.forEach(scan); }

    function prune() { items = items.filter(function (it) { return it.el.isConnected || (it.mo && it.mo.disconnect(), false); }); }

    function onMedia() {
        if (isMobile()) { scanAll(); return; }
        items.splice(0).forEach(unwrap);
        document.documentElement.classList.remove('tmf-locked');
    }

    function init(root) {
        if (!root || roots.indexOf(root) >= 0) return;
        roots.push(root);
        scan(root);
        if (!window.MutationObserver) return;
        var pending = false;
        var mo = new MutationObserver(function (muts) {
            if (!isMobile() || pending) return;
            var added = muts.some(function (m) { return m.addedNodes && m.addedNodes.length; });
            if (!added) return;
            pending = true;
            (window.requestAnimationFrame || setTimeout)(function () { pending = false; prune(); scan(root); });
        });
        mo.observe(root, { childList: true, subtree: true });
        observers.push(mo);
    }

    if (MQ) {
        if (MQ.addEventListener) MQ.addEventListener('change', onMedia);
        else if (MQ.addListener) MQ.addListener(onMedia);
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-mform-root]'), init);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    window.TheanMobileForms = { init: init, refresh: scanAll, items: function () { return items.slice(); } };
})();

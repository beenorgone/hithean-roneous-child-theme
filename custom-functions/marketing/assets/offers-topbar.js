/* Thanh ưu đãi đầu trang (offers/topbar.php): xoay vòng thông điệp, đóng trong phiên, popup "Xem chi tiết" tải AJAX. */
(function () {
    'use strict';
    var bar = document.getElementById('hithean-topbar');
    if (!bar) return;
    var cfg = window.hitheanTopbar || {};
    var root = document.documentElement;

    /* Thanh cao theo nội dung → đo lại --hpt-h khi đổi cỡ màn hình / font tải xong (body + menu Roneous chừa đúng chỗ). */
    function measure() { if (bar.isConnected) root.style.setProperty('--hpt-h', bar.offsetHeight + 'px'); }
    if (typeof ResizeObserver === 'function') new ResizeObserver(measure).observe(bar);
    else window.addEventListener('resize', measure);
    measure();

    /* Xoay vòng thông điệp (≥2 dòng): 5 giây / dòng, dừng khi rê chuột / focus vào thanh hoặc tab ẩn. */
    var msgs = bar.querySelectorAll('[data-hpt-msg]');
    var index = 0, paused = false;
    function show(next) {
        msgs[index].classList.remove('is-active'); msgs[index].setAttribute('aria-hidden', 'true');
        index = (next + msgs.length) % msgs.length;
        msgs[index].classList.add('is-active'); msgs[index].removeAttribute('aria-hidden');
    }
    if (msgs.length > 1) {
        bar.addEventListener('mouseenter', function () { paused = true; });
        bar.addEventListener('mouseleave', function () { paused = false; });
        bar.addEventListener('focusin', function () { paused = true; });
        bar.addEventListener('focusout', function () { paused = false; });
        setInterval(function () { if (!paused && !document.hidden) show(index + 1); }, 5000);
    }

    /* Đóng: ẩn trong phiên theo khóa nội dung (đổi nội dung → hiện lại). */
    bar.querySelector('[data-hpt-close]').addEventListener('click', function () {
        try { sessionStorage.setItem('hpt-off', bar.getAttribute('data-hpt-key') || '1'); } catch (e) { /* bỏ qua */ }
        root.classList.remove('hpt-on');
        root.style.removeProperty('--hpt-h');
        bar.parentNode.removeChild(bar);
    });

    /* Popup chương trình ưu đãi. */
    var dialog = document.getElementById('hpt-dialog');
    if (!dialog) return;
    var list = dialog.querySelector('[data-hpt-list]');
    var meta = dialog.querySelector('[data-hpt-meta]');
    var loaded = false, loading = false;

    function status(text) {
        list.innerHTML = '';
        var li = document.createElement('li');
        li.className = 'hpt-dialog__status';
        li.textContent = text;
        list.appendChild(li);
    }
    function load() {
        if (loaded || loading || !cfg.ajaxUrl) return;
        loading = true;
        status('Đang tải ưu đãi…');
        fetch(cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') < 0 ? '?' : '&') + 'action=hithean_offers_topbar', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var data = (res && res.data) || {};
                if (!res || !res.success) throw new Error(data.message || '');
                loaded = true;
                if (meta && data.meta) meta.textContent = data.meta;
                if (data.html) list.innerHTML = data.html; else status('Hiện chưa có chương trình ưu đãi nào.');
            })
            .catch(function (err) { status((err && err.message) || 'Chưa tải được ưu đãi. Vui lòng thử lại.'); })
            .then(function () { loading = false; });
    }
    function open() {
        load();
        if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); }
        else dialog.setAttribute('open', '');
        document.body.classList.add('hpt-modal-open');
    }
    function close() {
        if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }
    bar.querySelector('[data-hpt-open]').addEventListener('click', open);
    dialog.querySelector('[data-hpt-dialog-close]').addEventListener('click', close);
    dialog.addEventListener('close', function () { document.body.classList.remove('hpt-modal-open'); });
    dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) return;
        var r = dialog.getBoundingClientRect();
        if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close();
    });
    /* Link #uu-dai-chuong-trinh trên trang bất kỳ → mở sẵn popup. */
    if (location.hash === '#uu-dai-chuong-trinh') open();
})();

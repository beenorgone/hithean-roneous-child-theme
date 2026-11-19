/* [consult_library] — 2 tab con 📚 Thư viện / ✨ AI tư vấn trên cùng trang (nhớ tab ở sessionStorage, ?hcl_view=ai mở sẵn AI). */
(function () {
    'use strict';
    var bar = document.querySelector('[data-hcl-views]');
    if (!bar) return;
    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[data-hcl-view]'));
    var KEY = 'hcl-view';

    function panel(view) { return document.querySelector('[data-hcl-panel="' + view + '"]'); }
    function show(view, focus) {
        if (!panel(view)) return;
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-hcl-view') === view;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            panel(tab.getAttribute('data-hcl-view')).hidden = !on;
            if (on && focus) tab.focus();
        });
        try { sessionStorage.setItem(KEY, view); } catch (e) { /* bỏ qua */ }
    }
    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { show(tab.getAttribute('data-hcl-view')); });
        tab.addEventListener('keydown', function (e) {
            var step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!step) return;
            e.preventDefault();
            show(tabs[(i + step + tabs.length) % tabs.length].getAttribute('data-hcl-view'), true);
        });
    });
    // Link có ?hcl_view thì theo link; không thì giữ tab đã xem trong phiên.
    if (!/[?&]hcl_view=/.test(location.search)) {
        try { var saved = sessionStorage.getItem(KEY); if (saved) show(saved); } catch (e) { /* bỏ qua */ }
    }

    // ✨ Hỏi AI: chuyển tab, điền sẵn nội dung đang tìm ở thư viện nếu ô câu hỏi còn trống.
    var ask = document.querySelector('[data-hcl-ask]');
    if (ask) ask.addEventListener('click', function () {
        show('ai');
        var search = document.querySelector('[data-hcf-q]');
        var question = document.querySelector('#hpa-root textarea[name="question"]');
        if (!question) return;
        if (search && search.value.trim() && !question.value.trim()) {
            question.value = search.value.trim();
            question.dispatchEvent(new Event('input', { bubbles: true }));
        }
        var launcher = document.querySelector('#hpa-root .hcf-mobile-launcher:not([hidden]) button');
        if (launcher && !question.closest('dialog[open]')) launcher.click(); // mobile: form câu hỏi nằm trong popup
        else question.focus();
    });
})();

/* Main workspace navigation. Existing boards and tool frames stay mounted across tab switches. */
(function () {
    'use strict';
    var bar = document.getElementById('thean-ous-bar');
    var board = document.getElementById('thean-ous-board');
    var orders = document.getElementById('thean-ous-orders-panel');
    var products = document.getElementById('thean-ous-products-panel');
    var exports = document.getElementById('thean-ous-exports-panel');
    var foot = document.getElementById('thean-workspace-foot');
    if (!bar || !board || !orders || !products || !foot) return;
    bar.parentNode.insertBefore(orders, bar.nextSibling);
    orders.appendChild(board);
    orders.parentNode.insertBefore(products, orders.nextSibling);
    if (exports) products.parentNode.insertBefore(exports, products.nextSibling);
    foot.hidden = false;
    var current = 'orders';
    var tools = {
        products: { panel: products, key: 'info', param: 'thean_product_tool' },
        exports: { panel: exports, key: 'export_review', param: 'thean_export_tool' }
    };
    function updateUrl() {
        var url = new URL(location.href);
        if (current === 'orders') url.searchParams.delete('thean_workspace');
        else url.searchParams.set('thean_workspace', current);
        Object.keys(tools).forEach(function (key) {
            if (key === current) url.searchParams.set(tools[key].param, tools[key].key);
            else url.searchParams.delete(tools[key].param);
        });
        history.replaceState(history.state, '', url.toString());
    }
    function loadTool(panel, reload) {
        var frame = panel.querySelector('iframe');
        if (!reload && frame.getAttribute('src')) {
            // Existing export lists refresh through their own read-only endpoint.
            // Never reload a selected upload form or replay a mutation.
            try { if (frame.contentWindow.uexeRefreshLists) frame.contentWindow.uexeRefreshLists(true); } catch (e) {}
            return;
        }
        var status = panel.querySelector('[data-product-status]');
        status.textContent = 'Đang tải công cụ…';
        frame.setAttribute('aria-busy', 'true');
        frame.onload = function () {
            frame.removeAttribute('aria-busy');
            status.textContent = 'Công cụ đã tải. Nếu phiên đăng nhập hết hạn, mở riêng để đăng nhập lại.';
        };
        frame.onerror = function () {
            frame.removeAttribute('aria-busy');
            status.textContent = 'Không tải được công cụ. Bấm Tải lại công cụ hoặc Mở riêng.';
        };
        frame.src = frame.dataset.src;
    }
    function selectTool(key, workspace) {
        workspace = workspace || (current === 'exports' ? 'exports' : 'products');
        var config = tools[workspace];
        if (!config || !config.panel) return;
        var panel = Array.from(config.panel.querySelectorAll('[data-product-panel]')).find(function (el) { return el.dataset.productPanel === key; });
        if (!panel) return;
        config.key = key;
        config.panel.querySelectorAll('[data-product-tool]').forEach(function (button) {
            var on = button.dataset.productTool === key;
            button.setAttribute('aria-selected', String(on)); button.tabIndex = on ? 0 : -1;
        });
        config.panel.querySelectorAll('[data-product-panel]').forEach(function (el) { el.hidden = el !== panel; });
        if (current === workspace) loadTool(panel, false);
        updateUrl();
    }
    function show(key) {
        if (key !== 'orders' && (!tools[key] || !tools[key].panel)) return;
        current = key;
        orders.hidden = key !== 'orders';
        Object.keys(tools).forEach(function (name) { if (tools[name].panel) tools[name].panel.hidden = key !== name; });
        bar.classList.toggle('is-tools', key !== 'orders');
        document.body.classList.toggle('thean-tools-workspace', key !== 'orders');
        document.querySelectorAll('[data-order-workspace]').forEach(function (button) {
            var on = button.dataset.orderWorkspace === key;
            if (button.getAttribute('role') === 'tab') {
                button.setAttribute('aria-selected', String(on)); button.tabIndex = on ? 0 : -1;
            } else button.setAttribute('aria-pressed', String(on));
        });
        var suggest = document.getElementById('thean-ous-suggest');
        if (suggest && key !== 'orders') suggest.hidden = true;
        if (key !== 'orders') selectTool(tools[key].key, key);
        else updateUrl();
        document.dispatchEvent(new CustomEvent('thean:workspace-changed', { detail: { workspace: key } }));
    }
    // Xuất kho: loại đơn Website / Shopee (chỉ site có module Shopee render nút) → ?thean_export_kind= cho mọi iframe xuất kho.
    var KIND_KEY = 'theanExportKind', exportKind = 'all';
    try { exportKind = localStorage.getItem(KIND_KEY) || 'all'; } catch (e) {}
    var kindButtons = exports ? Array.from(exports.querySelectorAll('[data-export-kind]')) : [];
    function setExportKind(kind, reload) {
        if (!kindButtons.length) return;
        if (!kindButtons.some(function (b) { return b.dataset.exportKind === kind; })) kind = 'all';
        exportKind = kind;
        try { localStorage.setItem(KIND_KEY, kind); } catch (e) {}
        kindButtons.forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.exportKind === kind)); });
        exports.querySelectorAll('[data-product-panel]').forEach(function (panel) {
            var frame = panel.querySelector('iframe'), link = panel.querySelector('a[target="_blank"]');
            var u = new URL(frame.dataset.src, location.href);
            u.searchParams.set('thean_export_kind', kind);
            frame.dataset.src = u.toString();
            if (link) link.href = u.toString();
            if (reload && frame.getAttribute('src')) frame.removeAttribute('src'); // tải lại khi mở (đang xem → tải ngay)
        });
        if (reload && current === 'exports') selectTool(tools.exports.key, 'exports');
    }
    setExportKind(exportKind, false);
    function toolWorkspace(button) {
        return exports && exports.contains(button) ? 'exports' : 'products';
    }
    document.addEventListener('click', function (event) {
        var kindButton = event.target.closest('[data-export-kind]');
        if (kindButton) { if (kindButton.dataset.exportKind !== exportKind) setExportKind(kindButton.dataset.exportKind, true); return; }
        var button = event.target.closest('[data-order-workspace]');
        if (button) { show(button.dataset.orderWorkspace); return; }
        button = event.target.closest('[data-product-tool]');
        if (button) { selectTool(button.dataset.productTool, toolWorkspace(button)); return; }
        button = event.target.closest('[data-product-reload]');
        if (button) loadTool(button.closest('[data-product-panel]'), true);
    });
    document.addEventListener('keydown', function (event) {
        var button = event.target.closest('[data-order-workspace][role="tab"], [data-product-tool]');
        if (!button || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
        var buttons = Array.from(button.closest('[role="tablist"]').querySelectorAll('[role="tab"]'));
        var index = buttons.indexOf(button);
        index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
        event.preventDefault(); buttons[index].click(); buttons[index].focus();
    });
    // 📋 Website ở footer: tổng đơn board = cộng số các tab, trừ "Thiếu chứng từ XK" (đơn đó đã nằm ở tab bước chính).
    var footCount = foot.querySelector('[data-thean-foot-count]');
    var boardTabs = board.querySelector('.thean-ous-tabs');
    function syncFootCount() {
        if (!footCount) return;
        var sum = 0, any = false;
        board.querySelectorAll('.thean-ous-tabs [data-thean-ous-tab]').forEach(function (t) {
            if (t.dataset.theanOusTab === 'proof') return;
            var n = parseInt((t.querySelector('b') || {}).textContent, 10);
            if (!isNaN(n)) { sum += n; any = true; }
        });
        footCount.textContent = String(sum);
        footCount.hidden = !any || board.hidden;
    }
    if (boardTabs) new MutationObserver(syncFootCount).observe(boardTabs, { subtree: true, childList: true, characterData: true });
    new MutationObserver(syncFootCount).observe(board, { attributes: true, attributeFilter: ['hidden'] });
    syncFootCount();
    window.TheanOrderWorkspace = { show: show, product: function (key) { selectTool(key, 'products'); }, exports: function (key) { selectTool(key, 'exports'); } };
    var url = new URL(location.href);
    Object.keys(tools).forEach(function (workspace) {
        var config = tools[workspace], initial = url.searchParams.get(config.param);
        if (config.panel && Array.from(config.panel.querySelectorAll('[data-product-tool]')).some(function (button) { return button.dataset.productTool === initial; })) config.key = initial;
    });
    var initialWorkspace = url.searchParams.get('thean_workspace');
    show(tools[initialWorkspace] && tools[initialWorkspace].panel ? initialWorkspace : 'orders');
}());

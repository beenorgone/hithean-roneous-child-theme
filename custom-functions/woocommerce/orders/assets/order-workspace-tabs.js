/* Main workspace navigation. Existing boards and tool frames stay mounted across tab switches. */
(function () {
    'use strict';
    var bar = document.getElementById('thean-ous-bar');
    var board = document.getElementById('thean-ous-board');
    var orders = document.getElementById('thean-ous-orders-panel');
    var products = document.getElementById('thean-ous-products-panel');
    var foot = document.getElementById('thean-workspace-foot');
    if (!bar || !board || !orders || !products || !foot) return;
    bar.parentNode.insertBefore(orders, bar.nextSibling);
    orders.appendChild(board);
    orders.parentNode.insertBefore(products, orders.nextSibling);
    foot.hidden = false;
    var current = 'orders', tool = 'info';
    function updateUrl() {
        var url = new URL(location.href);
        if (current === 'products') {
            url.searchParams.set('thean_workspace', 'products');
            url.searchParams.set('thean_product_tool', tool);
        } else {
            url.searchParams.delete('thean_workspace');
            url.searchParams.delete('thean_product_tool');
        }
        history.replaceState(history.state, '', url.toString());
    }
    function loadTool(panel, reload) {
        var frame = panel.querySelector('iframe');
        if (!reload && frame.getAttribute('src')) return;
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
    function selectTool(key) {
        var panel = Array.from(products.querySelectorAll('[data-product-panel]')).find(function (el) { return el.dataset.productPanel === key; });
        if (!panel) return;
        tool = key;
        products.querySelectorAll('[data-product-tool]').forEach(function (button) {
            var on = button.dataset.productTool === key;
            button.setAttribute('aria-selected', String(on)); button.tabIndex = on ? 0 : -1;
        });
        products.querySelectorAll('[data-product-panel]').forEach(function (el) { el.hidden = el !== panel; });
        if (current === 'products') loadTool(panel, false);
        updateUrl();
    }
    function show(key) {
        if (key !== 'orders' && key !== 'products') return;
        current = key;
        orders.hidden = key !== 'orders'; products.hidden = key !== 'products';
        bar.classList.toggle('is-products', key === 'products');
        document.body.classList.toggle('thean-products-workspace', key === 'products');
        document.querySelectorAll('[data-order-workspace]').forEach(function (button) {
            var on = button.dataset.orderWorkspace === key;
            if (button.getAttribute('role') === 'tab') {
                button.setAttribute('aria-selected', String(on)); button.tabIndex = on ? 0 : -1;
            } else button.setAttribute('aria-pressed', String(on));
        });
        var suggest = document.getElementById('thean-ous-suggest');
        if (suggest && key === 'products') suggest.hidden = true;
        if (key === 'products') selectTool(tool);
        else updateUrl();
        document.dispatchEvent(new CustomEvent('thean:workspace-changed', { detail: { workspace: key } }));
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-order-workspace]');
        if (button) { show(button.dataset.orderWorkspace); return; }
        button = event.target.closest('[data-product-tool]');
        if (button) { selectTool(button.dataset.productTool); return; }
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
    window.TheanOrderWorkspace = { show: show, product: selectTool };
    var url = new URL(location.href);
    var initial = url.searchParams.get('thean_product_tool');
    if (['info', 'compare', 'protein'].indexOf(initial) !== -1) tool = initial;
    show(url.searchParams.get('thean_workspace') === 'products' ? 'products' : 'orders');
}());

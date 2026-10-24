(function () {
    'use strict';
    document.querySelectorAll('[data-ho-page]').forEach(function (root) {
        root.querySelectorAll('[data-ho-wheel-open]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                const trigger = document.querySelector('#thean-lw-root .thean-lw-trigger');
                if (trigger) { event.preventDefault(); trigger.click(); }
            });
        });
        // The link stays a category/shop fallback; with JS it opens the group's product list.
        root.querySelectorAll('[data-ho-open]').forEach(function (link) {
            const dialog = document.getElementById(link.dataset.hoOpen);
            if (!dialog || typeof dialog.showModal !== 'function') { return; }
            link.addEventListener('click', function (event) {
                event.preventDefault();
                dialog.showModal();
                document.documentElement.classList.add('ho-modal-open');
            });
            dialog.addEventListener('close', function () { document.documentElement.classList.remove('ho-modal-open'); link.focus(); });
            dialog.addEventListener('click', function (event) {
                if (event.target.closest('[data-ho-close]')) { dialog.close(); return; }
                if (event.target !== dialog) { return; }
                const rect = dialog.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) { dialog.close(); }
            });
        });
        const cards = Array.from(root.querySelectorAll('[data-ho-panel="programs"] [data-ho-card]'));
        const filters = root.querySelectorAll('[data-ho-filter]');
        const search = root.querySelector('[data-ho-search-input]');
        const result = root.querySelector('[data-ho-result]');
        const empty = root.querySelector('[data-ho-empty]');
        const productGrid = root.querySelector('.ho-product-grid');
        const productsEmpty = root.querySelector('[data-ho-products-empty]');
        const modebar = root.querySelector('[data-ho-modebar]');
        let active = 'all';
        let mode = 'programs';
        let expiryTimer;
        function normalize(text) { return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd').toLowerCase(); }
        function update() {
            const query = normalize(search ? search.value.trim() : '');
            const now = Date.now() / 1000;
            let count = 0;
            cards.forEach(function (card) {
                const rules = Array.from(card.querySelectorAll('[data-ho-rule]'));
                const nameMatches = normalize((card.querySelector('h3')?.textContent || '') + ' ' + (card.dataset.hoNames || '')).includes(query);
                rules.forEach(function (rule) {
                    rule.hidden = now >= Number(rule.dataset.hoUntil) || (active !== 'all' && active !== rule.dataset.hoKind) || (!nameMatches && !normalize(rule.textContent).includes(query));
                });
                const badge = card.querySelector('.ho-badge');
                if (badge && rules.length > 1) { badge.textContent = rules.filter(rule => now < Number(rule.dataset.hoUntil)).length + ' ưu đãi'; }
                const visible = rules.length ? rules.some(rule => !rule.hidden) : now < Number(card.dataset.hoUntil) && (active === 'all' || active === card.dataset.hoKind) && normalize(card.dataset.hoSearch).includes(query);
                if (card.hidden === visible) { card.hidden = !visible; }
                if (visible) { count++; }
            });
            let status = count + ' sản phẩm / chương trình phù hợp';
            if (mode === 'products' && productGrid) {
                let shown = 0;
                Array.from(productGrid.children).forEach(function (item) {
                    // Expired rules drop out individually; a card with none left is hidden.
                    let live = 0;
                    item.querySelectorAll('[data-ho-rule]').forEach(function (rule) {
                        rule.hidden = now >= Number(rule.dataset.hoUntil);
                        if (!rule.hidden) { live++; }
                    });
                    const badge = item.querySelector('.ho-badge');
                    if (badge && live > 1) { badge.textContent = live + ' ưu đãi'; }
                    const match = live > 0 && normalize(item.querySelector('h3')?.textContent || '').includes(query);
                    item.hidden = !match;
                    if (match) { shown++; }
                });
                if (productsEmpty) { productsEmpty.hidden = shown > 0; }
                status = shown + ' sản phẩm có ưu đãi';
            }
            if (result && result.textContent !== status) { result.textContent = status; }
            if (empty) { empty.hidden = count > 0; }
            const orderGroup = root.querySelector('.ho-order-group');
            if (orderGroup) { orderGroup.hidden = !orderGroup.querySelector('[data-ho-card]:not([hidden])'); }
            window.clearTimeout(expiryTimer);
            const upcoming = Array.from(root.querySelectorAll('[data-ho-until]')).map(item => Number(item.dataset.hoUntil)).filter(until => until > now);
            if (upcoming.length) { expiryTimer = window.setTimeout(update, Math.min(2147483647, (Math.min(...upcoming) - now) * 1000 + 50)); }
        }
        filters.forEach(function (button) {
            button.addEventListener('click', function () {
                active = button.dataset.hoFilter;
                root.dataset.hoActive = active;
                filters.forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
                update();
            });
        });
        if (search) { search.addEventListener('input', update); }
        if (modebar) {
            const buttons = modebar.querySelectorAll('[data-ho-mode]');
            const section = root.querySelector('#ho-offers');
            modebar.hidden = false;
            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    mode = button.dataset.hoMode;
                    root.dataset.hoMode = mode;
                    buttons.forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
                    root.querySelectorAll('[data-ho-panel]').forEach(function (panel) { panel.hidden = panel.dataset.hoPanel !== mode; });
                    update();
                    if (section) { section.scrollIntoView({block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'}); }
                });
            });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { update(); } });
        if (cards.length) { update(); }
    });
})();

(function () {
    'use strict';
    document.querySelectorAll('[data-ho-page]').forEach(function (root) {
        // This page has no theme header, so the language switcher would float over the cart link; dock it in the nav.
        const lang = document.querySelector('.hithean-lang-switch--mobile-fixed');
        const navLinks = root.querySelector('.ho-nav > div');
        if (lang && navLinks) { navLinks.appendChild(lang); }
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
        const section = root.querySelector('#ho-offers');
        // Nút đổi chế độ nằm trên thanh menu đáy toàn site (ngoài root, đã được portal ra <body>).
        const modeToggles = document.querySelectorAll('[data-ho-mode-toggle]');
        function syncToggles() {
            const toProducts = mode !== 'products';
            modeToggles.forEach(function (button) {
                const label = button.querySelector('[data-ho-mode-label]');
                if (label) { label.textContent = toProducts ? 'Theo SP' : 'Theo CT'; }
                button.setAttribute('aria-label', toProducts ? 'Xem ưu đãi theo sản phẩm' : 'Xem ưu đãi theo chương trình');
            });
        }
        function setMode(next, scroll) {
            mode = next === 'products' ? 'products' : 'programs';
            root.dataset.hoMode = mode;
            syncToggles();
            root.querySelectorAll('[data-ho-panel]').forEach(function (panel) { panel.hidden = panel.dataset.hoPanel !== mode; });
            update();
            try { window.sessionStorage.setItem('ho-mode', mode); } catch (e) {}
            // Link chia sẻ giữ đúng chế độ đang xem (?xem=san-pham | chuong-trinh).
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('xem', mode === 'products' ? 'san-pham' : 'chuong-trinh');
                window.history.replaceState(null, '', url);
            } catch (e) {}
            if (scroll && section) { section.scrollIntoView({block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'}); }
        }
        modeToggles.forEach(function (button) {
            button.addEventListener('click', function () { setMode(mode === 'products' ? 'programs' : 'products', true); });
        });
        syncToggles();
        // Hỏi nhanh khi vừa vào trang: xem theo sản phẩm hay theo chương trình. Không hỏi lại khi URL đã chọn
        // (?xem=…) hoặc khách đã chọn trong phiên này.
        const ask = root.querySelector('[data-ho-ask]');
        let chosen = '';
        try {
            const param = new URL(window.location.href).searchParams.get('xem');
            chosen = param === 'san-pham' ? 'products' : (param === 'chuong-trinh' ? 'programs' : '');
            if (!chosen) { chosen = window.sessionStorage.getItem('ho-mode') || ''; }
        } catch (e) {}
        if (chosen) {
            if (chosen === 'products') { setMode('products', false); }
        } else if (ask && typeof ask.showModal === 'function' && cards.length) {
            ask.showModal();
            document.documentElement.classList.add('ho-modal-open');
            ask.addEventListener('close', function () { document.documentElement.classList.remove('ho-modal-open'); });
            ask.addEventListener('cancel', function () { try { window.sessionStorage.setItem('ho-mode', 'programs'); } catch (e) {} });
            ask.addEventListener('click', function (event) {
                const pick = event.target.closest('[data-ho-ask-pick]');
                if (!pick) { return; }
                ask.close();
                if (pick.dataset.hoAskPick) { setMode(pick.dataset.hoAskPick, true); }
                else { try { window.sessionStorage.setItem('ho-mode', 'programs'); } catch (e) {} }
            });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { update(); } });
        if (cards.length) { update(); }
    });
})();

(function () {
    'use strict';
    document.querySelectorAll('[data-ho-page]').forEach(function (root) {
        const cards = Array.from(root.querySelectorAll('[data-ho-card]'));
        const filters = root.querySelectorAll('[data-ho-filter]');
        const search = root.querySelector('[data-ho-search-input]');
        const result = root.querySelector('[data-ho-result]');
        const empty = root.querySelector('[data-ho-empty]');
        let active = 'all';
        let expiryTimer;
        function normalize(text) { return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/gi, 'd').toLowerCase(); }
        function update() {
            const query = normalize(search ? search.value.trim() : '');
            const now = Date.now() / 1000;
            let count = 0;
            cards.forEach(function (card) {
                const visible = now < Number(card.dataset.hoUntil) && (active === 'all' || active === card.dataset.hoKind) && normalize(card.dataset.hoSearch).includes(query);
                if (card.hidden === visible) { card.hidden = !visible; }
                if (visible) { count++; }
            });
            const status = count + ' ưu đãi phù hợp';
            if (result && result.textContent !== status) { result.textContent = status; }
            if (empty) { empty.hidden = count > 0; }
            const orderGroup = root.querySelector('.ho-order-group');
            if (orderGroup) { orderGroup.hidden = !orderGroup.querySelector('[data-ho-card]:not([hidden])'); }
            window.clearTimeout(expiryTimer);
            const upcoming = cards.map(card => Number(card.dataset.hoUntil)).filter(until => until > now);
            if (upcoming.length) { expiryTimer = window.setTimeout(update, Math.min(2147483647, (Math.min(...upcoming) - now) * 1000 + 50)); }
        }
        filters.forEach(function (button) {
            button.addEventListener('click', function () {
                active = button.dataset.hoFilter;
                filters.forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
                update();
            });
        });
        if (search) { search.addEventListener('input', update); }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { update(); } });
        if (cards.length) { update(); }
    });
})();

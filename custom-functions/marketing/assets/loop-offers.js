/** Category cards use the product-page offer sources and shared popup renderer. */
(function () {
    'use strict';
    if (typeof HTMLDialogElement === 'undefined') return;
    var cache = new Map(), current = 0, instance = null, loading = null;

    function status(message, opener, url) {
        if (loading) loading.remove();
        loading = document.createElement('dialog');
        loading.className = 'hithean-offers-dialog hlo-dialog';
        loading.innerHTML = '<div class="hithean-offers-dialog__head"><h3>Ưu đãi đang áp dụng</h3><button type="button" class="hithean-offers-dialog__close" aria-label="Đóng">×</button></div><p class="hlo-dialog__loading" aria-live="polite"></p><div class="hithean-offers-dialog__foot"><a class="hithean-offer-card__action">Xem ưu đãi trên trang sản phẩm ↗</a></div>';
        loading.querySelector('p').textContent = message;
        loading.querySelector('a').href = url + '#uu-dai';
        loading.querySelector('button').onclick = function () { loading.close(); };
        loading.addEventListener('click', function (event) { if (event.target === loading) loading.close(); });
        loading.addEventListener('close', function () {
            current++;
            document.documentElement.classList.remove('hithean-offers-open');
            opener.focus({ preventScroll: true });
        });
        document.body.appendChild(loading);
        loading.showModal();
        document.documentElement.classList.add('hithean-offers-open');
    }

    function source(url) {
        var cached = cache.get(url);
        if (cached && Date.now() - cached.at < 60000) return cached.promise;
        var endpoint = new URL(url);
        endpoint.searchParams.set('hithean_offer_fragment', '1');
        var promise = fetch(endpoint.href, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (response) {
            if (!response.ok || new URL(response.url || url).origin !== location.origin) throw new Error('product');
            return response.text();
        }).then(function (body) {
            var payload = null;
            try { payload = JSON.parse(body); } catch (error) { /* Older cached pages may still return HTML. */ }
            if (payload && (!payload.success || !payload.data)) throw new Error('sources');
            var doc = new DOMParser().parseFromString(payload ? payload.data.html : body, 'text/html');
            var summary = doc.querySelector('.product-single .summary, .summary');
            var node = doc.getElementById('hithean-product-offers-config');
            var config = payload ? payload.data.config : (node ? JSON.parse(node.textContent) : null);
            if (!config) {
                // Compatibility with pages cached before the inert JSON block was added. No eval.
                var match = body.match(/\bhitheanProductPurchase\s*=\s*(\{[^\n]*\})\s*;/);
                if (match) config = JSON.parse(match[1]);
            }
            if (!summary) throw new Error('sources');
            if (!config) {
                var image = doc.querySelector('.woocommerce-product-gallery img');
                config = { offerOrder: ['discount', 'tiers', 'gift', 'wheel', 'other', 'shipping'], offerMedia: { product: image ? image.getAttribute('src') : '' } };
            }
            return { doc: doc, summary: summary, config: config };
        }).catch(function (error) { cache.delete(url); throw error; });
        // Bound memory on long catalog browsing sessions; concurrent clicks share the request.
        if (cache.size >= 20) cache.delete(cache.keys().next().value);
        cache.set(url, { at: Date.now(), promise: promise });
        return promise;
    }

    function productUrl(button) {
        var raw = button.getAttribute('data-hlo-url');
        // Cached older category markup still contains the product's enclosing link.
        var link = button.closest('a') || button.closest('li.product, .product')?.querySelector('a.woocommerce-LoopProduct-link, a[href]');
        var url;
        try { url = new URL(raw || (link && link.href), location.href); } catch (error) { return; }
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
        url.hash = '';
        return url;
    }

    function open(button) {
        var url = productUrl(button);
        if (!url) return;
        if (instance) { instance.destroy(); instance = null; }
        status('Đang tải ưu đãi…', button, url.href);
        var request = ++current;
        source(url.href).then(function (data) {
            if (request !== current || !loading.open) return;
            if (data.config.offersAvailable === false) {
                var id = button.getAttribute('data-hlo');
                document.querySelectorAll('[data-hlo]').forEach(function (item) { if (item.getAttribute('data-hlo') === id) item.remove(); });
                loading.close(); loading.remove(); loading = null;
                return;
            }
            var config = Object.assign({}, data.config, { productUrl: url.href, sourceDocument: data.doc, opener: button });
            instance = window.hitheanSetupProductOffers(data.summary.cloneNode(true), config);
            if (!instance) {
                loading.querySelector('p').textContent = 'Hiện chưa có chương trình ưu đãi nào cho sản phẩm này.';
                return;
            }
            loading.remove(); loading = null;
            instance.open();
        }).catch(function () {
            if (request === current && loading && loading.open) loading.querySelector('p').textContent = 'Không tải được ưu đãi. Vui lòng thử lại hoặc mở trang sản phẩm.';
        });
    }

    function trigger(event) {
        var button = event.target.closest && event.target.closest('[data-hlo]');
        if (!button) return;
        if (event.type === 'keydown' && (button.tagName === 'BUTTON' || (event.key !== 'Enter' && event.key !== ' '))) return;
        event.preventDefault(); event.stopPropagation();
        open(button);
    }
    // Fetch only on purchase intent; never request every product when a catalog opens.
    var warming = 0, hoverTimer;
    function warm(button) {
        if (!button || warming >= 2 || (navigator.connection && navigator.connection.saveData)) return;
        var url = productUrl(button);
        if (!url) return;
        warming++;
        source(url.href).catch(function () {}).finally(function () { warming--; });
    }
    document.addEventListener('pointerover', function (event) {
        if (event.pointerType === 'touch') return;
        var button = event.target.closest && event.target.closest('[data-hlo]');
        if (!button || (event.relatedTarget && button.contains(event.relatedTarget))) return;
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () { warm(button); }, 120);
    });
    document.addEventListener('pointerout', function (event) {
        var button = event.target.closest && event.target.closest('[data-hlo]');
        if (button && (!event.relatedTarget || !button.contains(event.relatedTarget))) clearTimeout(hoverTimer);
    });
    document.addEventListener('focusin', function (event) { warm(event.target.closest && event.target.closest('[data-hlo]')); });
    document.addEventListener('pointerdown', function (event) { warm(event.target.closest && event.target.closest('[data-hlo]')); });
    document.addEventListener('click', trigger, true);
    document.addEventListener('keydown', trigger, true);
})();

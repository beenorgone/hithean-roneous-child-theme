(function ($) {
    'use strict';
    $(function () {
        var config = window.hitheanProductPurchase;
        var product = document.querySelector('.product-single > .product');
        if (!config || !product) return;
        var summary = product.querySelector('.summary');
        var form = summary && summary.querySelector('form.cart');
        var price = summary && summary.querySelector('p.price');
        if (!summary) return;

        // Keep product help and marketplace actions together in the flow.
        // Nutrition dialog events are delegated; moving its launcher is safe.
        var nutritionAction = summary.querySelector('.product-nutrition-label');
        var chatActions = summary.querySelector('.b-single-product-ctas');
        var chatAction = chatActions && chatActions.querySelector('a[href*="m.me/"]');
        var shopeeAction = summary.querySelector('.ecom-buy-trigger');
        var shopeeModal = shopeeAction && summary.querySelector('.ecom-buy-modal');
        if (shopeeModal && shopeeModal.parentNode !== document.body) document.body.appendChild(shopeeModal);
        var contactActionItems = [nutritionAction, chatAction, shopeeAction].filter(Boolean);
        if (contactActionItems.length) {
            var contactActions = document.createElement('div');
            contactActions.className = 'hithean-purchase-contact-actions';
            contactActionItems.forEach(function (action) { contactActions.appendChild(action); });
            var trustWidgets = summary.querySelector('.product-trust-ctas');
            if (trustWidgets) trustWidgets.insertAdjacentElement('afterend', contactActions);
            else if (chatActions) chatActions.insertAdjacentElement('beforebegin', contactActions);
            else summary.appendChild(contactActions);
            if (chatActions && !chatActions.children.length && !chatActions.textContent.trim()) chatActions.hidden = true;
        }

        var impact = summary.querySelector('.b-greenspark-revenue-percentage-widget');
        var impactText = impact && impact.querySelector('.b-greenspark-revenue-percentage-widget__subtitle');
        if (impactText && impactText.textContent.trim()) {
            var disclosure = document.createElement('details');
            disclosure.className = 'hithean-purchase-impact';
            var caption = document.createElement('summary');
            caption.textContent = impactText.textContent.trim();
            impact.insertAdjacentElement('beforebegin', disclosure);
            disclosure.appendChild(caption);
            disclosure.appendChild(impact);
        }

        var navBoundary = form || product.querySelector('.woocommerce-product-gallery');
        if (navBoundary && 'IntersectionObserver' in window) {
            var navObserver = new IntersectionObserver(function () {
                document.body.classList.toggle('hithean-purchase-past-form', navBoundary.getBoundingClientRect().bottom < 0);
            });
            navObserver.observe(navBoundary);
        } else {
            document.body.classList.add('hithean-purchase-past-form');
        }

        var quantity = form && form.querySelector('input.qty[name="quantity"]');
        var originalButton = form && form.querySelector('.single_add_to_cart_button');
        var quote = form && form.querySelector('.hithean-purchase-quote');
        // Grouped/external/addon-only forms keep their own purchase flow.
        if (!quantity || !originalButton || !quote) return;
        var $form = $(form);
        var subtotal = quote.querySelector('[data-purchase-subtotal]');
        var status = quote.querySelector('[data-purchase-status]');
        var bar = document.querySelector('.sticky-atc-bar');
        var stickyQuantity = bar && bar.querySelector('.sticky-atc-bar__qty-input');
        var stickyButton = bar && bar.querySelector('.sticky-atc-bar__btn');
        var stickySubtotal = bar && bar.querySelector('[data-purchase-sticky-subtotal]');
        var stickyLabel = stickyButton && stickyButton.querySelector('span');
        var stickyText = stickyLabel ? stickyLabel.textContent : '';
        var initialPriceHtml = price ? price.innerHTML : '';
        var nativePriceHtml = initialPriceHtml;
        var request = null;
        var timer = null;
        var generation = 0;
        var currentQuote = null;
        var selectedVariation = null;
        var lastKey = '';

        quote.hidden = false;
        document.body.classList.add('hithean-purchase-ready');
        if (price) document.body.classList.add('hithean-purchase-with-price');
        if (bar && stickyQuantity && stickyButton) {
            if (bar.parentNode !== document.body) document.body.appendChild(bar);
            document.body.classList.add('hithean-purchase-has-sticky');
            function syncStickyMenuOffset() {
                var barHeight = Math.ceil(bar.getBoundingClientRect().height);
                if (barHeight > 0) document.body.style.setProperty('--purchase-bottom-space', (barHeight + 16) + 'px');
            }
            syncStickyMenuOffset();
            if ('ResizeObserver' in window) new ResizeObserver(syncStickyMenuOffset).observe(bar);
            window.addEventListener('resize', syncStickyMenuOffset, { passive: true });
        }

        function syncQuantity() {
            if (!stickyQuantity) return;
            ['min', 'max', 'step'].forEach(function (name) {
                if (quantity.hasAttribute(name)) stickyQuantity.setAttribute(name, quantity.getAttribute(name));
                else stickyQuantity.removeAttribute(name);
            });
            stickyQuantity.value = quantity.value;
            stickyQuantity.disabled = quantity.disabled;
            stickyQuantity.readOnly = quantity.readOnly;
            stickyQuantity.closest('.quantity').hidden = quantity.type === 'hidden';
        }
        function syncDisabled() {
            if (!stickyButton) return;
            var disabled = originalButton.disabled || originalButton.classList.contains('disabled') || !quantity.checkValidity();
            stickyButton.disabled = disabled;
            stickyButton.classList.toggle('disabled', disabled);
            var adding = originalButton.classList.contains('loading');
            stickyButton.setAttribute('aria-busy', String(adding));
            if (stickyLabel) stickyLabel.textContent = adding ? (config.adding || 'Đang thêm…') : stickyText;
        }
        function setStatus(message, state) {
            quote.dataset.state = state;
            quote.setAttribute('aria-busy', state === 'pending' ? 'true' : 'false');
            status.textContent = message;
        }
        function clearQuote(message, state) {
            currentQuote = null;
            lastKey = '';
            subtotal.textContent = '—';
            if (stickySubtotal) stickySubtotal.textContent = '';
            if (price) price.innerHTML = nativePriceHtml;
            setStatus(message, state);
        }
        function renderQuote(result) {
            currentQuote = result;
            if (typeof result.baseUnitHtml === 'string') nativePriceHtml = result.baseUnitHtml;
            // All HTML is returned by our nonce-protected, same-origin action,
            // sanitized server-side with wp_kses_post. No localized money parsing.
            if (price) price.innerHTML = result.unitHtml;
            subtotal.innerHTML = result.subtotalHtml;
            if (!result.subtotalHtml) subtotal.textContent = '—';
            if (stickySubtotal) stickySubtotal.innerHTML = result.subtotalHtml;
            setStatus(result.message, result.state);
        }
        function addonSelectionPresent() {
            return !!form.querySelector('.wc-pao-addon, .product-addon, .product_addon-title, .thwepo-extra-options, .tm-extra-product-options, .wapf-field-container, input[name^="addon"], [data-product-addon-hidden-inputs] input, input[type="file"]');
        }
        function refreshQuote(force) {
            generation++;
            var version = generation;
            window.clearTimeout(timer);
            if (request) { request.abort(); request = null; }
            syncQuantity();
            syncDisabled();
            var variationInput = form.querySelector('[name="variation_id"]');
            var variationId = variationInput ? Number(variationInput.value) : 0;
            if (variationInput && (!variationId || !selectedVariation)) {
                clearQuote(config.selectOptions, 'options');
                return;
            }
            if (!quantity.checkValidity()) {
                clearQuote(config.invalidQuantity, 'invalid');
                return;
            }
            if (!/^[1-9][0-9]{0,3}$/.test(quantity.value)) {
                clearQuote(config.unavailable, 'deferred');
                return;
            }
            if (addonSelectionPresent()) {
                clearQuote(config.addons, 'deferred');
                return;
            }
            var key = variationId + ':' + quantity.value;
            if (!force && key === lastKey && currentQuote) return;
            clearQuote(config.pending, 'pending');
            timer = window.setTimeout(function () {
                request = $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    timeout: 12000,
                    cache: false,
                    data: { action: config.action, nonce: config.nonce, product_id: config.productId, variation_id: variationId, quantity: quantity.value }
                }).done(function (response) {
                    if (version !== generation) return;
                    var result = response && response.success && response.data;
                    if (!result || result.quantity !== Number(quantity.value) || result.productId !== (variationId || Number(config.productId)) || typeof result.unitHtml !== 'string' || typeof result.subtotalHtml !== 'string' || ['base', 'discount', 'deferred'].indexOf(result.state) < 0) {
                        clearQuote(config.unavailable, 'unavailable');
                        return;
                    }
                    lastKey = key;
                    renderQuote(result);
                }).fail(function (_, reason) {
                    if (version === generation && reason !== 'abort') clearQuote(config.unavailable, 'unavailable');
                }).always(function () { if (version === generation) request = null; });
            }, 250);
        }

        // Read only explicit quantity ranges from the plugin's own table, never
        // a title, percentage, price or an assumed set of 1/2/3 purchase options.
        function addTierActions() {
            var tierTables = summary.querySelectorAll('.awdr-bulk-customizable-table');
            tierTables.forEach(function (table) {
                var rows = table.querySelectorAll('tr.bulk_table_row');
                var shortDescriptions = rows.length >= 2 && Array.prototype.every.call(rows, function (row) {
                    var title = row.querySelector('.wdr_bulk_title');
                    var words = title ? title.textContent.replace(/\s+/g, ' ').trim().split(' ').filter(Boolean) : [];
                    return words.length > 0 && words.length <= 5;
                });
                table.classList.toggle('hithean-purchase-tiers--short-copy', shortDescriptions);
            });
            // Không chèn nút "Chọn số lượng" — cả thẻ bậc giá là vùng bấm để chọn số lượng.
            summary.querySelectorAll('.awdr-bulk-customizable-table tr.bulk_table_row').forEach(function (row) {
                if (row.hasAttribute('data-purchase-quantity')) return;
                var range = row.querySelector('.wdr_bulk_range');
                var text = range ? range.textContent.replace(/\s+/g, ' ').trim() : '';
                var match = text.match(/^([1-9]\d{0,3})(?:\s*(\+)|\s*[-–]\s*([1-9]\d{0,3}))?$/);
                if (!match || (match[3] && Number(match[3]) < Number(match[1]))) return;
                row.dataset.purchaseQuantity = match[1];
                row.dataset.purchaseMaximum = match[3] || (match[2] ? '' : match[1]);
                row.setAttribute('role', 'button');
                row.setAttribute('tabindex', '0');
                row.setAttribute('aria-label', config.chooseQuantity + ': ' + text);
                row.setAttribute('aria-pressed', 'false');
                row.closest('.awdr-bulk-customizable-table').classList.add('hithean-purchase-tiers');
            });
            updateTierActions();
        }
        function updateTierActions() {
            summary.querySelectorAll('tr[data-purchase-quantity]').forEach(function (row) {
                var minimum = Number(row.dataset.purchaseQuantity);
                var maximum = Number(row.dataset.purchaseMaximum) || Infinity;
                var probe = quantity.cloneNode(false);
                probe.value = String(minimum);
                var allowed = !quantity.readOnly && probe.checkValidity()
                    && (!quantity.max || minimum <= Number(quantity.max))
                    && (!quantity.min || minimum >= Number(quantity.min))
                    && (quantity.type !== 'hidden' || minimum === Number(quantity.value));
                var disabled = !allowed || quantity.disabled || (!!form.querySelector('[name="variation_id"]') && !selectedVariation);
                row.setAttribute('aria-disabled', String(disabled));
                row.setAttribute('tabindex', disabled ? '-1' : '0');
                row.setAttribute('aria-pressed', String(Number(quantity.value) >= minimum && Number(quantity.value) <= maximum));
            });
        }
        function chooseTier(row) {
            if (!row || row.getAttribute('aria-disabled') === 'true') return;
            quantity.value = row.dataset.purchaseQuantity;
            $(quantity).trigger('input').trigger('change');
        }
        summary.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, select, textarea')) return;
            chooseTier(event.target.closest('tr[data-purchase-quantity]'));
        });
        summary.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            var row = event.target.matches && event.target.matches('tr[data-purchase-quantity]') ? event.target : null;
            if (!row) return;
            event.preventDefault();
            chooseTier(row);
        });
        $(quantity).on('input.hitheanPurchase change.hitheanPurchase', function () { updateTierActions(); refreshQuote(false); });
        $form.on('change.hitheanPurchase', 'input:not([name="quantity"]):not([name="variation_id"]), select, textarea', function () {
            if (addonSelectionPresent()) refreshQuote(true);
        });
        if (stickyQuantity) $(stickyQuantity).on('input.hitheanPurchase change.hitheanPurchase', function (event) {
            quantity.value = stickyQuantity.value;
            $(quantity).trigger(event.type);
        });
        if (stickyButton) stickyButton.addEventListener('click', function () {
            if (stickyButton.disabled || originalButton.disabled || originalButton.classList.contains('disabled')) return;
            if (!form.reportValidity()) return;
            // Request a native submit: all addon/variation validation and the
            // existing popup handler run once. Works as a POST without the popup.
            if (typeof form.requestSubmit === 'function') form.requestSubmit(originalButton);
            else originalButton.click();
        });
        $form.on('found_variation.hitheanPurchase', function (_, variation) {
            selectedVariation = variation;
            nativePriceHtml = variation.price_html || initialPriceHtml;
            updateTierActions();
            refreshQuote(true);
        }).on('reset_data.hitheanPurchase hide_variation.hitheanPurchase', function () {
            selectedVariation = null;
            nativePriceHtml = initialPriceHtml;
            updateTierActions();
            refreshQuote(true);
        }).on('show_variation.hitheanPurchase', function () { syncQuantity(); syncDisabled(); });
        // A preselected variation can have emitted found_variation before this
        // deferred script; ask WooCommerce to replay its own variation selection.
        if (form.querySelector('[name="variation_id"]')) $form.trigger('check_variations');
        $(document.body).on('added_to_cart.hitheanPurchase removed_from_cart.hitheanPurchase wc_fragments_refreshed.hitheanPurchase', function () { refreshQuote(true); });
        $(document).on('wdr_dynamic_price_update.hitheanPurchase', function () {
            if (currentQuote) renderQuote(currentQuote);
            else if (price) price.innerHTML = nativePriceHtml;
        });
        window.addEventListener('pageshow', function (event) { if (event.persisted) refreshQuote(true); });
        if (window.MutationObserver) {
            new MutationObserver(function () { syncQuantity(); syncDisabled(); updateTierActions(); }).observe(originalButton, { attributes: true, attributeFilter: ['disabled', 'class'] });
            new MutationObserver(function () { syncQuantity(); syncDisabled(); updateTierActions(); }).observe(quantity, { attributes: true, attributeFilter: ['min', 'max', 'step', 'disabled', 'readonly', 'type'] });
            var table = summary.querySelector('.awdr-bulk-customizable-table');
            if (table) new MutationObserver(addTierActions).observe(table, { childList: true, subtree: true });
        }
        addTierActions();
        refreshQuote(true);
    });
})(jQuery);

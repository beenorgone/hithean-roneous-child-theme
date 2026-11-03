(function ($) {
    'use strict';

    /*
     * "Ưu đãi đang áp dụng": gom thanh ưu đãi của Discount Rules (freeship…) và khối "Ưu đãi" của plugin
     * product-addon (chỉ khi mọi dòng là "tự động áp dụng") vào 1 nút → popup mỗi chương trình 1 thẻ, cùng kiểu
     * popup trang /uu-dai/. Nguồn gốc vẫn nằm trong DOM (ẩn bằng CSS) để plugin tiếp tục đồng bộ trạng thái /
     * input ẩn của form; popup đọc lại nguồn mỗi lần mở nên luôn khớp nội dung plugin đang hiển thị.
     * Khối add-on có lựa chọn (khách phải chọn) giữ nguyên tại chỗ.
     */
    function setupOffers(summary, config) {
        var text = function (node) { return node ? node.textContent.replace(/\s+/g, ' ').trim() : ''; };
        var isAutoOffer = function (box) {
            var heading = box.querySelector(':scope > h4');
            var rows = box.querySelectorAll('[data-choice-row]');
            return /^ưu đãi/i.test(text(heading)) && rows.length > 0
                && Array.prototype.every.call(rows, function (row) { return row.getAttribute('data-mode') === 'auto'; });
        };
        summary.querySelectorAll('[data-product-addon-container]').forEach(function (box) {
            if (isAutoOffer(box)) box.setAttribute('data-hithean-offer-source', '');
        });

        function collect() {
            var cards = [];
            summary.querySelectorAll('[data-hithean-offer-source]').forEach(function (box) {
                var message = box.querySelector('.product_addon-sale-message');
                box.querySelectorAll('[data-choice-row]').forEach(function (row) {
                    var title = row.querySelector('.product_addon-title');
                    var name = title ? text(title.firstChild) || text(title) : '';
                    if (!name) return;
                    var minimum = Number(row.getAttribute('data-min-qty')) || 1;
                    var notes = [row.classList.contains('is-active')
                        ? '✓ Đang áp dụng với số lượng bạn chọn'
                        : 'Tự động áp dụng khi mua từ ' + minimum + ' sản phẩm'];
                    var price = row.querySelector('.product_addon-price');
                    cards.push({ badge: 'Ưu đãi kèm', title: name, notes: notes, price: price, extra: message, active: row.classList.contains('is-active') });
                });
            });
            summary.querySelectorAll('.awdr_discount_bar').forEach(function (bar) {
                var label = text(bar);
                if (!label) return;
                var content = bar.querySelector('.awdr_discount_bar_content') || bar;
                cards.push({
                    badge: /free ?ship|miễn phí (vận chuyển|giao)|phí (ship|vận chuyển)/i.test(label) ? 'Ưu đãi vận chuyển' : 'Ưu đãi',
                    html: content, label: label, notes: [], active: true
                });
            });
            return cards;
        }

        var initial = collect();
        if (!initial.length) return;
        document.body.classList.add('hithean-purchase-offers');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'hithean-offers-trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.innerHTML = '<span class="hithean-offers-trigger__icon" aria-hidden="true">✳</span>'
            + '<span class="hithean-offers-trigger__text"><strong>Ưu đãi đang áp dụng</strong><small data-offers-summary></small></span>'
            + '<span class="hithean-offers-trigger__count" data-offers-count></span>'
            + '<span class="hithean-offers-trigger__more" aria-hidden="true">Xem ›</span>';
        var anchor = summary.querySelector('[data-hithean-offer-source], .awdr_discount_bar');
        if (anchor && anchor.parentNode === summary) summary.insertBefore(trigger, anchor);
        else summary.appendChild(trigger);

        var dialog = document.createElement('dialog');
        dialog.className = 'hithean-offers-dialog';
        dialog.setAttribute('aria-labelledby', 'hithean-offers-dialog-title');
        dialog.innerHTML = '<div class="hithean-offers-dialog__head"><div><h3 id="hithean-offers-dialog-title">Ưu đãi đang áp dụng</h3><p data-offers-meta></p></div>'
            + '<button type="button" class="hithean-offers-dialog__close" data-offers-close aria-label="Đóng">×</button></div>'
            + '<ul class="hithean-offers-dialog__list" data-offers-list></ul>'
            + '<div class="hithean-offers-dialog__foot"><p>Mỗi chương trình có điều kiện riêng. Giá trị cuối cùng được xác nhận trong giỏ hàng.</p></div>';
        if (config.offersUrl) {
            var all = document.createElement('a');
            all.className = 'hithean-offers-dialog__all';
            all.href = config.offersUrl;
            all.textContent = 'Xem mọi ưu đãi trên website ↗';
            dialog.querySelector('.hithean-offers-dialog__foot').appendChild(all);
        }
        document.body.appendChild(dialog);
        var list = dialog.querySelector('[data-offers-list]');

        function el(tag, className, content) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (content) node.textContent = content;
            return node;
        }
        function renderTrigger(cards) {
            trigger.hidden = !cards.length;
            trigger.querySelector('[data-offers-summary]').textContent = cards.map(function (card) { return card.title || card.label; }).join(' · ');
            trigger.querySelector('[data-offers-count]').textContent = String(cards.length);
            trigger.setAttribute('aria-label', 'Ưu đãi đang áp dụng: ' + cards.length + ' chương trình. Xem chi tiết');
        }
        function renderDialog(cards) {
            dialog.querySelector('[data-offers-meta]').textContent = cards.length + ' chương trình cho sản phẩm này';
            list.textContent = '';
            cards.forEach(function (card) {
                var item = el('li', 'hithean-offer-card' + (card.active ? ' is-active' : ''));
                var top = el('div', 'hithean-offer-card__top');
                top.appendChild(el('span', 'hithean-offer-card__badge', card.badge));
                if (card.price) {
                    var price = el('span', 'hithean-offer-card__price');
                    price.innerHTML = card.price.innerHTML;
                    top.appendChild(price);
                }
                item.appendChild(top);
                var body = el('div', 'hithean-offer-card__title');
                // Nội dung đã hiển thị trên trang (do plugin render) — sao chép nguyên node, không qua chuỗi.
                if (card.html) Array.prototype.forEach.call(card.html.childNodes, function (node) { body.appendChild(node.cloneNode(true)); });
                else body.textContent = card.title;
                item.appendChild(body);
                if (card.extra && text(card.extra)) {
                    var extra = el('div', 'hithean-offer-card__extra');
                    Array.prototype.forEach.call(card.extra.childNodes, function (node) { extra.appendChild(node.cloneNode(true)); });
                    item.appendChild(extra);
                }
                card.notes.forEach(function (note) { item.appendChild(el('p', 'hithean-offer-card__note', note)); });
                list.appendChild(item);
            });
        }
        function refresh() { renderTrigger(collect()); }
        renderTrigger(initial);

        trigger.addEventListener('click', function () {
            var cards = collect();
            renderDialog(cards);
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
            document.documentElement.classList.add('hithean-offers-open');
        });
        dialog.addEventListener('close', function () { document.documentElement.classList.remove('hithean-offers-open'); trigger.focus(); });
        dialog.addEventListener('click', function (event) {
            if (event.target.closest('[data-offers-close]')) { dialog.close(); return; }
            if (event.target !== dialog) return;
            var rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
        });
        // Discount Rules / addon cập nhật thanh ưu đãi theo phân loại & số lượng.
        $(document).on('wdr_dynamic_price_update.hitheanOffers', refresh);
        $(summary).on('found_variation.hitheanOffers reset_data.hitheanOffers change.hitheanOffers input.hitheanOffers', function () { window.setTimeout(refresh, 0); });
    }

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

        setupOffers(summary, config);

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

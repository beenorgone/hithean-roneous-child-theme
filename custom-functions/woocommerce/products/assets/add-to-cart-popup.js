/**
 * Add to cart popup — gửi form.cart (trang sản phẩm) hoặc nút "Thêm vào giỏ" của
 * product loop qua AJAX rồi hiện modal xem trước giỏ hàng.
 * API cho code khác (sticky bar mobile): window.hitheanAtcPopup.submit(form, { quantity, button }).
 * Nút có [data-atc-cart-open] (nút giỏ hàng cụm sticky mobile) → window.hitheanAtcPopup.openCart():
 * mở popup chế độ "view", nội dung giỏ tải lại qua AJAX. Badge [data-atc-cart-count] cập nhật theo số trả về.
 */
jQuery(function ($) {
    var config = window.hitheanAtcPopupConfig;
    var $modal = $('#atc-popup');
    if (!config || !$modal.length) return;

    var $panel = $modal.find('.atc-popup__panel');
    var lastFocus = null;
    var busy = false;

    function open(state) {
        if ($modal.is('[hidden]')) lastFocus = document.activeElement;
        $modal.attr('data-state', state).removeAttr('hidden');
        $('html').addClass('atc-popup-open');
        $panel.trigger('focus');
    }

    function close() {
        if ($modal.is('[hidden]')) return;
        $modal.attr('hidden', 'hidden');
        $('html').removeClass('atc-popup-open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function showError(message, productUrl) {
        $modal.find('.atc-popup__error-msg').text(message || config.errorText);
        var $link = $modal.find('.atc-popup__product-link');
        if (productUrl) {
            $link.attr('href', productUrl).removeAttr('hidden');
        } else {
            $link.attr('hidden', 'hidden');
        }
        open('error');
    }

    function setCount(count) {
        count = parseInt(count, 10) || 0;
        $('[data-atc-cart-count]').each(function () {
            this.setAttribute('data-atc-cart-count', count);
            this.textContent = count > 99 ? '99+' : String(count);
            this.hidden = count <= 0;
        });
    }

    /*
     * Xem giỏ: admin-ajax mất ~1,5s nên lưu bản giỏ gần nhất ở sessionStorage, khóa theo cookie
     * woocommerce_cart_hash của WC (đổi mỗi khi giỏ đổi). Hash khớp → hiện ngay, không gọi server;
     * lệch → hiện bản cũ (mờ) rồi thay khi tải xong. Tải sẵn lúc rảnh sau khi trang tải + khi chạm nút.
     */
    var CACHE_KEY = 'hitheanAtcCart';
    var viewXhr = null;

    function cartHashCookie() {
        var m = document.cookie.match(/(?:^|;\s*)woocommerce_cart_hash=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    function readCache() {
        try {
            var c = JSON.parse(window.sessionStorage.getItem(CACHE_KEY) || 'null');
            return c && typeof c.html === 'string' ? c : null;
        } catch (e) { return null; }
    }

    function writeCache(payload) {
        try {
            window.sessionStorage.setItem(CACHE_KEY, JSON.stringify({
                html: payload.html, count: payload.count, cart_hash: payload.cart_hash || ''
            }));
        } catch (e) { /* private mode */ }
    }

    function emptyPayload() {
        return { html: '<p class="atc-popup__empty">' + $('<div>').text(config.emptyText).html() + '</p>', count: 0, cart_hash: '' };
    }

    function render(payload) {
        $modal.find('.atc-popup__cart').html(payload.html);
        if (payload.count > 0) $modal.removeAttr('data-empty'); else $modal.attr('data-empty', '1');
        setCount(payload.count);
    }

    function fetchCart() {
        if (viewXhr) return viewXhr;
        viewXhr = $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: { action: config.viewAction },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success) writeCache(res.data);
        }).always(function () {
            viewXhr = null;
        });
        return viewXhr;
    }

    function prefetch() {
        var hash = cartHashCookie();
        var cache = readCache();
        if (hash && (!cache || cache.cart_hash !== hash)) fetchCart();
    }

    function openCart() {
        if (busy) return;
        var $cart = $modal.find('.atc-popup__cart');
        var hash = cartHashCookie();
        var cache = readCache();
        var fresh = cache && cache.cart_hash === hash;

        if (fresh) {
            render(cache);
        } else if (cache) {
            render(cache);
        } else if (!hash) {
            render(emptyPayload()); // không có cookie hash = giỏ trống (vẫn kiểm lại bên dưới)
        } else {
            $cart.html('<p class="atc-popup__empty">' + $('<div>').text(config.loadingText).html() + '</p>');
        }
        open('view');
        if (fresh) return;

        if (hash) $cart.addClass('is-loading');
        fetchCart().done(function (res) {
            if ($modal.attr('data-state') !== 'view') return;
            if (!res || !res.success) {
                showError(res && res.data && res.data.message);
                return;
            }
            render(res.data);
        }).fail(function (xhr, status) {
            if (status !== 'abort' && hash) showError();
        }).always(function () {
            $cart.removeClass('is-loading');
        });
    }

    function removeItem($btn) {
        var $item = $btn.closest('.atc-popup__item');
        var nonce = $btn.closest('[data-atc-remove-nonce]').attr('data-atc-remove-nonce');
        $modal.find('.atc-popup__notice').remove();
        $item.addClass('is-removing');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: { action: config.removeAction, nonce: nonce, cart_item_key: $btn.attr('data-atc-remove') },
            dataType: 'json'
        }).done(function (res) {
            if (!res || !res.success) {
                $item.removeClass('is-removing');
                notice((res && res.data && res.data.message) || config.removeError);
                return;
            }
            render(res.data);
            writeCache(res.data);
            applyFragments(res.data.fragments);
            $(document.body).trigger('removed_from_cart', [res.data.fragments, res.data.cart_hash, $btn]);
        }).fail(function () {
            $item.removeClass('is-removing');
            notice(config.removeError);
        });
    }

    /*
     * − / +: đổi số trên giao diện ngay, bấm liên tiếp gộp thành 1 request (chờ 400ms sau lần bấm cuối);
     * đang gửi thì khóa stepper của dòng đó, xong render lại cả giỏ (tổng tiền, badge).
     */
    var qtyTimers = {};

    function stepQty($btn) {
        var $stepper = $btn.closest('[data-atc-qty-key]');
        var key = $stepper.attr('data-atc-qty-key');
        var max = parseInt($stepper.attr('data-max'), 10) || 0;
        var $val = $stepper.find('.atc-popup__qty-val');
        var qty = (parseInt($val.text(), 10) || 1) + parseInt($btn.attr('data-atc-qty-step'), 10);
        qty = Math.max(1, max > 0 ? Math.min(qty, max) : qty);

        $val.text(qty);
        $stepper.find('[data-atc-qty-step="-1"]').prop('disabled', qty <= 1);
        $stepper.find('[data-atc-qty-step="1"]').prop('disabled', max > 0 && qty >= max);

        clearTimeout(qtyTimers[key]);
        qtyTimers[key] = setTimeout(function () {
            delete qtyTimers[key];
            sendQty($stepper, key, qty);
        }, 400);
    }

    function sendQty($stepper, key, qty) {
        var $item = $stepper.closest('.atc-popup__item');
        var nonce = $stepper.closest('[data-atc-remove-nonce]').attr('data-atc-remove-nonce');
        $modal.find('.atc-popup__notice').remove();
        $item.addClass('is-updating');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: { action: config.qtyAction, nonce: nonce, cart_item_key: key, quantity: qty },
            dataType: 'json'
        }).done(function (res) {
            var data = (res && res.data) || {};
            if (typeof data.html === 'string') {
                render(data);
                writeCache(data);
                applyFragments(data.fragments);
                $(document.body).trigger('updated_cart_totals');
            } else {
                $item.removeClass('is-updating');
            }
            if (!res || !res.success || data.message) notice(data.message || config.qtyError);
        }).fail(function () {
            $item.removeClass('is-updating');
            notice(config.qtyError);
        });
    }

    function notice(message) {
        $('<p class="atc-popup__notice" role="alert"></p>').text(message).prependTo($modal.find('.atc-popup__cart'));
    }

    function applyFragments(fragments) {
        if (!fragments) return;
        $.each(fragments, function (selector, html) {
            $(selector).replaceWith(html);
        });
    }

    /**
     * Gửi request thêm vào giỏ. opts.showProductLink: khi lỗi, hiện link sang trang
     * sản phẩm (nút loop — sản phẩm có thể cần chọn thêm tuỳ chọn).
     */
    function send(data, $btn, opts) {
        if (busy) return;
        opts = opts || {};

        var btnHtml = $btn.html();
        busy = true;
        $btn.addClass('loading').removeClass('added').prop('disabled', true);
        if (opts.loadingText) $btn.text(config.addingText);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            if (!res || !res.success) {
                var err = (res && res.data) || {};
                showError(err.message, opts.showProductLink ? err.product_url : '');
                return;
            }
            applyFragments(res.data.fragments);
            $(document.body).trigger('added_to_cart', [res.data.fragments, res.data.cart_hash, $btn]);
            $modal.find('.atc-popup__cart').html(res.data.html);
            $modal.removeAttr('data-empty');
            if (res.data.count !== undefined) setCount(res.data.count);
            // Lưu cho lần bấm nút giỏ sau (bỏ tô "vừa thêm").
            var $copy = $('<div>').html(res.data.html);
            $copy.find('.is-added').removeClass('is-added');
            writeCache({ html: $copy.html(), count: res.data.count, cart_hash: res.data.cart_hash });
            open('success');
        }).fail(function () {
            showError();
        }).always(function () {
            busy = false;
            $btn.removeClass('loading').prop('disabled', false);
            if (opts.loadingText) $btn.html(btnHtml);
        });
    }

    function submit(form, opts) {
        opts = opts || {};
        if (busy) return;

        var $form = $(form);
        var $btn = $(opts.button || $form.find('.single_add_to_cart_button').first());
        var data = new FormData(form);

        // Simple/grouped: id nằm trên nút submit name="add-to-cart" (FormData không chứa nút),
        // nên đọc từ form — nút gửi có thể là sticky bar (không có value).
        var productId = data.get('add-to-cart') || $form.find('[name="add-to-cart"]').val() || $form.data('product_id');
        data.delete('add-to-cart');
        data.set('hithean_atc_product', productId);
        data.set('action', config.action);
        if (opts.quantity) data.set('quantity', opts.quantity);

        send(data, $btn, { loadingText: true });
    }

    function submitLoop($btn) {
        var data = new FormData();
        data.set('hithean_atc_product', $btn.attr('data-product_id'));
        data.set('quantity', $btn.attr('data-quantity') || 1);
        data.set('action', config.action);

        send(data, $btn, { showProductLink: true });
    }

    window.hitheanAtcPopup = { submit: submit, close: close, openCart: openCart };

    $(document).on('click', '[data-atc-cart-open]', function (e) {
        e.preventDefault();
        openCart();
    });
    $(document).on('pointerenter touchstart', '[data-atc-cart-open]', prefetch);
    $modal.on('click', '[data-atc-qty-step]', function (e) {
        e.preventDefault();
        stepQty($(this));
    });
    $modal.on('click', '[data-atc-remove]', function (e) {
        e.preventDefault();
        removeItem($(this));
    });
    if ($('[data-atc-cart-open]').length) {
        (window.requestIdleCallback || function (cb) { return setTimeout(cb, 1500); })(prefetch);
    }

    // Delegate ở document → chạy sau handler gắn trực tiếp vào form (validate của addon/variation).
    $(document).on('submit', 'form.cart', function (e) {
        if (e.isDefaultPrevented()) return;
        if ((this.getAttribute('method') || '').toLowerCase() !== 'post') return; // external product

        e.preventDefault();
        var submitter = e.originalEvent && e.originalEvent.submitter;
        submit(this, { button: submitter && $(submitter).is('.single_add_to_cart_button') ? submitter : null });
    });

    // Nút "Thêm vào giỏ" của product loop (simple, còn hàng).
    var LOOP_BTN = '.hithean-atc-loop[data-product_id], .ajax_add_to_cart[data-product_id]';

    // wc-add-to-cart.js (handler ở body, chạy trước) — chặn request AJAX của WC để không thêm 2 lần.
    $(document.body).on('should_send_ajax_request.adding_to_cart', function (e, $button) {
        if ($button && $($button).is(LOOP_BTN)) return false;
    });

    $(document).on('click', LOOP_BTN, function (e) {
        e.preventDefault();
        submitLoop($(this));
    });

    $modal.on('click', '[data-atc-popup-close]', close);
    $(document).on('keyup', function (e) {
        if (e.key === 'Escape') close();
    });
});

/**
 * Add to cart popup — gửi form.cart (trang sản phẩm) hoặc nút "Thêm vào giỏ" của
 * product loop qua AJAX rồi hiện modal xem trước giỏ hàng.
 * API cho code khác (sticky bar mobile): window.hitheanAtcPopup.submit(form, { quantity, button }).
 */
jQuery(function ($) {
    var config = window.hitheanAtcPopupConfig;
    var $modal = $('#atc-popup');
    if (!config || !$modal.length) return;

    var $panel = $modal.find('.atc-popup__panel');
    var lastFocus = null;
    var busy = false;

    function open(state) {
        lastFocus = document.activeElement;
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

    window.hitheanAtcPopup = { submit: submit, close: close };

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

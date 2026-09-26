/**
 * Add to cart popup — gửi form.cart qua AJAX rồi hiện modal xem trước giỏ hàng.
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

    function showError(message) {
        $modal.find('.atc-popup__error-msg').text(message || config.errorText);
        open('error');
    }

    function applyFragments(fragments) {
        if (!fragments) return;
        $.each(fragments, function (selector, html) {
            $(selector).replaceWith(html);
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

        var btnText = $btn.text();
        busy = true;
        $btn.addClass('loading').prop('disabled', true).text(config.addingText);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            if (!res || !res.success) {
                showError(res && res.data && res.data.message);
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
            $btn.removeClass('loading').prop('disabled', false).text(btnText);
        });
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

    $modal.on('click', '[data-atc-popup-close]', close);
    $(document).on('keyup', function (e) {
        if (e.key === 'Escape') close();
    });
});

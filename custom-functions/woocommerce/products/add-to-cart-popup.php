<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  ADD TO CART POPUP — trang sản phẩm + mọi product loop.
  Form "Thêm vào giỏ" (và sticky bar mobile) ở trang sản phẩm, cùng nút
  "Thêm vào giỏ" trong product loop (danh mục, trang chủ, shortcode, sản phẩm
  liên quan...) được gửi qua admin-ajax thay vì reload trang / AJAX mặc định
  của WC; sau khi thêm thành công hiện modal xem trước giỏ hàng với 2 nút
  "Tiếp tục mua sắm" / "Đặt hàng". Trang giỏ hàng / thanh toán giữ hành vi WC.

  Việc thêm vào giỏ vẫn do WC_Form_Handler::add_to_cart_action() xử lý, nên
  simple / variable / grouped + field của plugin addon hoạt động như submit
  thường. JS gửi product id qua 'hithean_atc_product' (không phải
  'add-to-cart') để handler wp_loaded của WC không tự chạy thêm lần nữa.
\*---------------------------------------*/

const HITHEAN_ATC_POPUP_ACTION = 'hithean_atc_popup';
const HITHEAN_ATC_POPUP_VIEW_ACTION = 'hithean_atc_popup_view';
const HITHEAN_ATC_POPUP_REMOVE_ACTION = 'hithean_atc_popup_remove';
const HITHEAN_ATC_POPUP_QTY_ACTION = 'hithean_atc_popup_qty';

/*
  Xem giỏ hàng (nút giỏ hàng ở cụm sticky mobile trang sản phẩm — product-navigation.php):
  mở cùng popup ở chế độ "view", nội dung giỏ lấy lại qua AJAX mỗi lần mở (trang có thể bị cache).
*/
add_action('wp_ajax_' . HITHEAN_ATC_POPUP_VIEW_ACTION, 'hithean_atc_popup_view_ajax');
add_action('wp_ajax_nopriv_' . HITHEAN_ATC_POPUP_VIEW_ACTION, 'hithean_atc_popup_view_ajax');

function hithean_atc_popup_view_ajax()
{
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => __('Không tải được giỏ hàng.', 'hithean.com')]);
    }

    wp_send_json_success(hithean_atc_popup_cart_payload());
}

/*
  Xóa 1 dòng khỏi giỏ ngay trong popup (nút × mỗi sản phẩm). Nonce nằm trong HTML giỏ
  (render qua AJAX nên không dính cache trang).
*/
add_action('wp_ajax_' . HITHEAN_ATC_POPUP_REMOVE_ACTION, 'hithean_atc_popup_remove_ajax');
add_action('wp_ajax_nopriv_' . HITHEAN_ATC_POPUP_REMOVE_ACTION, 'hithean_atc_popup_remove_ajax');

function hithean_atc_popup_remove_ajax()
{
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => __('Không tải được giỏ hàng.', 'hithean.com')]);
    }
    if (!check_ajax_referer(HITHEAN_ATC_POPUP_REMOVE_ACTION, 'nonce', false)) {
        wp_send_json_error(['message' => __('Phiên làm việc đã hết hạn, vui lòng tải lại trang.', 'hithean.com')]);
    }

    $key = isset($_POST['cart_item_key']) ? wc_clean(wp_unslash($_POST['cart_item_key'])) : '';
    if ($key !== '' && WC()->cart->get_cart_item($key)) {
        WC()->cart->remove_cart_item($key);
    }

    $payload = hithean_atc_popup_cart_payload();
    $payload['fragments'] = hithean_atc_popup_fragments();
    wp_send_json_success($payload);
}

/*
  Đổi số lượng 1 dòng (nút − / + trong popup). Dùng chung nonce với nút xóa. Kiểm như trang giỏ
  của WC: tối đa theo tồn kho / bán lẻ từng cái + filter woocommerce_update_cart_validation (plugin addon…).
*/
add_action('wp_ajax_' . HITHEAN_ATC_POPUP_QTY_ACTION, 'hithean_atc_popup_qty_ajax');
add_action('wp_ajax_nopriv_' . HITHEAN_ATC_POPUP_QTY_ACTION, 'hithean_atc_popup_qty_ajax');

function hithean_atc_popup_qty_ajax()
{
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => __('Không tải được giỏ hàng.', 'hithean.com')]);
    }
    if (!check_ajax_referer(HITHEAN_ATC_POPUP_REMOVE_ACTION, 'nonce', false)) {
        wp_send_json_error(['message' => __('Phiên làm việc đã hết hạn, vui lòng tải lại trang.', 'hithean.com')]);
    }

    $key  = isset($_POST['cart_item_key']) ? wc_clean(wp_unslash($_POST['cart_item_key'])) : '';
    $qty  = isset($_POST['quantity']) ? (int) wp_unslash($_POST['quantity']) : 0;
    $item = $key !== '' ? WC()->cart->get_cart_item($key) : null;
    $product = $item['data'] ?? null;

    if (!$item || !$product instanceof WC_Product) {
        wp_send_json_error(['message' => __('Sản phẩm không còn trong giỏ hàng.', 'hithean.com')] + hithean_atc_popup_cart_payload());
    }

    $max = hithean_atc_popup_item_max_qty($product);
    $qty = max(1, $max > 0 ? min($qty, $max) : $qty);

    if ($qty !== (int) $item['quantity']) {
        if (apply_filters('woocommerce_update_cart_validation', true, $key, $item, $qty)) {
            WC()->cart->set_quantity($key, $qty, true);
        }
    }

    $errors = wc_get_notices('error');
    wc_clear_notices();

    $payload = hithean_atc_popup_cart_payload();
    $payload['fragments'] = hithean_atc_popup_fragments();
    if ($errors) {
        $payload['message'] = implode(' ', array_filter(array_map(function ($notice) {
            return wp_strip_all_tags(is_array($notice) ? ($notice['notice'] ?? '') : (string) $notice);
        }, $errors)));
    }
    wp_send_json_success($payload);
}

/** Số lượng tối đa của 1 dòng giỏ: 0 = không giới hạn. */
function hithean_atc_popup_item_max_qty(WC_Product $product): int
{
    if ($product->is_sold_individually()) {
        return 1;
    }
    $max = (int) $product->get_max_purchase_quantity();
    return $max > 0 ? $max : 0;
}

/** Nội dung popup chế độ xem + số lượng + cart hash (JS dùng hash để biết bản lưu tạm còn đúng không). */
function hithean_atc_popup_cart_payload(): array
{
    WC()->cart->calculate_totals();
    $count = (int) WC()->cart->get_cart_contents_count();

    return [
        'html'      => $count > 0 ? hithean_atc_popup_render_cart('') : '<p class="atc-popup__empty">' . esc_html__('Giỏ hàng của bạn đang trống.', 'hithean.com') . '</p>',
        'count'     => $count,
        'cart_hash' => $count > 0 ? WC()->cart->get_cart_hash() : '',
    ];
}

/** Icon giỏ hàng dùng chung cho nút mở popup (sticky trang SP + sticky footer menu archive). */
function hithean_atc_cart_icon_svg(): string
{
    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2.5 3h2.6l2.4 11.6a1.5 1.5 0 0 0 1.5 1.2h8.7a1.5 1.5 0 0 0 1.5-1.1L21 7H6"/></svg>';
}

/** Số lượng SP trong giỏ cho badge nút giỏ hàng; khóa fragment theo class → WC / popup thay khi giỏ đổi. */
function hithean_atc_cart_count_html(): string
{
    $count = (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;

    return '<span class="hithean-cart-count" data-atc-cart-count="' . $count . '"' . ($count > 0 ? '' : ' hidden') . '>'
        . ($count > 99 ? '99+' : $count) . '</span>';
}

add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $fragments['span.hithean-cart-count'] = hithean_atc_cart_count_html();
    return $fragments;
});

add_action('wp_ajax_' . HITHEAN_ATC_POPUP_ACTION, 'hithean_atc_popup_ajax');
add_action('wp_ajax_nopriv_' . HITHEAN_ATC_POPUP_ACTION, 'hithean_atc_popup_ajax');

function hithean_atc_popup_ajax()
{
    $product_id = isset($_POST['hithean_atc_product']) ? absint(wp_unslash($_POST['hithean_atc_product'])) : 0;
    if (!$product_id || !function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => __('Không thể thêm sản phẩm vào giỏ hàng.', 'hithean.com')]);
    }

    // Không cho form handler redirect (redirect sau khi thêm / filter của plugin khác).
    add_filter('woocommerce_add_to_cart_redirect', '__return_false', PHP_INT_MAX);
    add_filter('pre_option_woocommerce_cart_redirect_after_add', function () {
        return 'no';
    });

    $added_key = '';
    add_action('woocommerce_add_to_cart', function ($cart_item_key) use (&$added_key) {
        $added_key = (string) $cart_item_key;
    });

    $_REQUEST['add-to-cart'] = $product_id;
    WC_Form_Handler::add_to_cart_action();

    $errors = wc_get_notices('error');
    wc_clear_notices();

    if ($errors || $added_key === '') {
        $messages = array_map(function ($notice) {
            return wp_strip_all_tags(is_array($notice) ? ($notice['notice'] ?? '') : (string) $notice);
        }, $errors);
        $messages = array_values(array_filter($messages));

        wp_send_json_error([
            'message'     => $messages ? implode(' ', $messages) : __('Không thể thêm sản phẩm vào giỏ hàng.', 'hithean.com'),
            'product_url' => get_permalink($product_id) ?: '',
        ]);
    }

    WC()->cart->calculate_totals();

    wp_send_json_success([
        'html'      => hithean_atc_popup_render_cart($added_key),
        'fragments' => hithean_atc_popup_fragments(),
        'cart_hash' => WC()->cart->get_cart_hash(),
        'count'     => (int) WC()->cart->get_cart_contents_count(),
    ]);
}

function hithean_atc_popup_fragments(): array
{
    $mini_cart = '';
    if (function_exists('woocommerce_mini_cart')) {
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
    }

    return apply_filters('woocommerce_add_to_cart_fragments', [
        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
    ]);
}

function hithean_atc_popup_render_cart(string $added_key): string
{
    $cart  = WC()->cart;
    $items = $cart->get_cart();

    // Sản phẩm vừa thêm lên đầu.
    if (isset($items[$added_key])) {
        $items = [$added_key => $items[$added_key]] + $items;
    }

    ob_start();
    ?>
    <ul class="atc-popup__items" data-atc-remove-nonce="<?php echo esc_attr(wp_create_nonce(HITHEAN_ATC_POPUP_REMOVE_ACTION)); ?>">
        <?php foreach ($items as $key => $item) :
            $product = $item['data'] ?? null;
            if (!$product instanceof WC_Product || empty($item['quantity'])) continue;

            $name = apply_filters('woocommerce_cart_item_name', $product->get_name(), $item, $key);
            $link = $product->is_visible() ? $product->get_permalink($item) : '';
            ?>
            <li class="atc-popup__item<?php echo $key === $added_key ? ' is-added' : ''; ?>">
                <div class="atc-popup__thumb"><?php echo $product->get_image('woocommerce_gallery_thumbnail'); ?></div>
                <div class="atc-popup__info">
                    <p class="atc-popup__name">
                        <?php echo $link ? '<a href="' . esc_url($link) . '">' . wp_kses_post($name) . '</a>' : wp_kses_post($name); ?>
                    </p>
                    <?php echo wc_get_formatted_cart_item_data($item); ?>
                    <?php $max_qty = hithean_atc_popup_item_max_qty($product); ?>
                    <div class="atc-popup__qty">
                        <?php if ($max_qty !== 1) : ?>
                            <span class="atc-popup__stepper" data-atc-qty-key="<?php echo esc_attr($key); ?>" data-max="<?php echo esc_attr($max_qty); ?>">
                                <button type="button" data-atc-qty-step="-1" aria-label="<?php esc_attr_e('Giảm số lượng', 'hithean.com'); ?>"<?php disabled((int) $item['quantity'] <= 1); ?>>&minus;</button>
                                <span class="atc-popup__qty-val" aria-live="polite"><?php echo esc_html($item['quantity']); ?></span>
                                <button type="button" data-atc-qty-step="1" aria-label="<?php esc_attr_e('Tăng số lượng', 'hithean.com'); ?>"<?php disabled($max_qty > 0 && (int) $item['quantity'] >= $max_qty); ?>>+</button>
                            </span>
                            <span class="atc-popup__unit">&times; <?php echo WC()->cart->get_product_price($product); ?></span>
                        <?php else : ?>
                            <span class="atc-popup__unit"><?php echo esc_html($item['quantity']); ?> &times; <?php echo WC()->cart->get_product_price($product); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="atc-popup__line-total"><?php echo $cart->get_product_subtotal($product, $item['quantity']); ?></div>
                <button type="button" class="atc-popup__remove" data-atc-remove="<?php echo esc_attr($key); ?>" aria-label="<?php echo esc_attr(sprintf(__('Xóa %s khỏi giỏ hàng', 'hithean.com'), wp_strip_all_tags($name))); ?>" title="<?php esc_attr_e('Xóa khỏi giỏ', 'hithean.com'); ?>">&times;</button>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="atc-popup__subtotal">
        <span><?php printf(esc_html__('Tạm tính (%d sản phẩm)', 'hithean.com'), (int) $cart->get_cart_contents_count()); ?></span>
        <strong><?php echo $cart->get_cart_subtotal(); ?></strong>
    </div>
    <?php
    return ob_get_clean();
}

/*---------------------------------------*\
  PRODUCT LOOP
  Nút loop chỉ có class 'ajax_add_to_cart' khi bật "Enable AJAX add to cart"
  trong WC; gắn thêm class riêng để popup chạy bất kể setting đó.
\*---------------------------------------*/

add_filter('woocommerce_loop_add_to_cart_args', 'hithean_atc_popup_loop_button_args', 10, 2);

function hithean_atc_popup_loop_button_args($args, $product)
{
    if (
        $product instanceof WC_Product
        && $product->supports('ajax_add_to_cart')
        && $product->is_purchasable()
        && $product->is_in_stock()
        && hithean_atc_popup_is_enabled()
    ) {
        $args['class'] = trim(($args['class'] ?? '') . ' hithean-atc-loop');
    }

    return $args;
}

/*---------------------------------------*\
  ASSETS + MARKUP MODAL
\*---------------------------------------*/

function hithean_atc_popup_is_enabled(): bool
{
    if (is_admin() || !function_exists('WC')) {
        return false;
    }

    return !(function_exists('is_cart') && is_cart()) && !(function_exists('is_checkout') && is_checkout());
}

add_action('wp_enqueue_scripts', 'hithean_atc_popup_enqueue_assets', 20);

function hithean_atc_popup_enqueue_assets()
{
    if (!hithean_atc_popup_is_enabled()) {
        return;
    }

    $css_rel  = '/custom-functions/woocommerce/products/assets/add-to-cart-popup.css';
    $js_rel   = '/custom-functions/woocommerce/products/assets/add-to-cart-popup.js';
    $css_path = get_stylesheet_directory() . $css_rel;
    $js_path  = get_stylesheet_directory() . $js_rel;

    wp_enqueue_style(
        'hithean-atc-popup',
        get_stylesheet_directory_uri() . $css_rel,
        ['hithean-custom-style'],
        is_file($css_path) ? (string) filemtime($css_path) : thean_theme_code_version()
    );

    wp_enqueue_script(
        'hithean-atc-popup',
        get_stylesheet_directory_uri() . $js_rel,
        ['jquery'],
        is_file($js_path) ? (string) filemtime($js_path) : thean_theme_code_version(),
        true
    );

    wp_localize_script('hithean-atc-popup', 'hitheanAtcPopupConfig', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'action'       => HITHEAN_ATC_POPUP_ACTION,
        'viewAction'   => HITHEAN_ATC_POPUP_VIEW_ACTION,
        'removeAction' => HITHEAN_ATC_POPUP_REMOVE_ACTION,
        'qtyAction'    => HITHEAN_ATC_POPUP_QTY_ACTION,
        'qtyError'     => __('Không cập nhật được số lượng, vui lòng thử lại.', 'hithean.com'),
        'emptyText'    => __('Giỏ hàng của bạn đang trống.', 'hithean.com'),
        'loadingText'  => __('Đang tải giỏ hàng...', 'hithean.com'),
        'removeError'  => __('Không xóa được sản phẩm, vui lòng thử lại.', 'hithean.com'),
        'addingText'   => __('Đang thêm...', 'hithean.com'),
        'errorText'    => __('Có lỗi xảy ra, vui lòng thử lại.', 'hithean.com'),
    ]);
}

add_action('wp_footer', 'hithean_atc_popup_render_modal', 30);

function hithean_atc_popup_render_modal()
{
    if (!hithean_atc_popup_is_enabled()) {
        return;
    }
    ?>
    <div class="atc-popup" id="atc-popup" hidden>
        <div class="atc-popup__backdrop" data-atc-popup-close></div>
        <div class="atc-popup__panel" role="dialog" aria-modal="true" aria-labelledby="atc-popup-title" tabindex="-1">
            <button type="button" class="atc-popup__close" data-atc-popup-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>

            <div class="atc-popup__state atc-popup__state--success">
                <h3 class="atc-popup__title" id="atc-popup-title">
                    <span class="atc-popup__title-added">
                        <span class="atc-popup__check" aria-hidden="true">&#10003;</span>
                        <?php esc_html_e('Đã thêm vào giỏ hàng', 'hithean.com'); ?>
                    </span>
                    <span class="atc-popup__title-view"><?php esc_html_e('Giỏ hàng của bạn', 'hithean.com'); ?></span>
                </h3>
                <div class="atc-popup__cart"></div>
                <div class="atc-popup__actions">
                    <button type="button" class="button atc-popup__continue" data-atc-popup-close><?php esc_html_e('Tiếp tục mua sắm', 'hithean.com'); ?></button>
                    <a class="button alt atc-popup__checkout" href="<?php echo esc_url(wc_get_checkout_url()); ?>"><?php esc_html_e('Đặt hàng', 'hithean.com'); ?></a>
                </div>
                <a class="atc-popup__view-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Xem giỏ hàng', 'hithean.com'); ?></a>
            </div>

            <div class="atc-popup__state atc-popup__state--error">
                <h3 class="atc-popup__title"><?php esc_html_e('Chưa thêm được vào giỏ hàng', 'hithean.com'); ?></h3>
                <p class="atc-popup__error-msg"></p>
                <div class="atc-popup__actions">
                    <button type="button" class="button alt" data-atc-popup-close><?php esc_html_e('Đóng', 'hithean.com'); ?></button>
                </div>
                <a class="atc-popup__view-cart atc-popup__product-link" href="#" hidden><?php esc_html_e('Xem chi tiết sản phẩm', 'hithean.com'); ?></a>
            </div>
        </div>
    </div>
    <?php
}

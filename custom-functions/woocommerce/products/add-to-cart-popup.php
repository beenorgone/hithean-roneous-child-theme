<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  ADD TO CART POPUP — trang sản phẩm.
  Form "Thêm vào giỏ" (và sticky bar mobile) được gửi qua admin-ajax thay vì
  reload trang; sau khi thêm thành công hiện modal xem trước giỏ hàng với
  2 nút "Tiếp tục mua sắm" / "Thanh toán".

  Việc thêm vào giỏ vẫn do WC_Form_Handler::add_to_cart_action() xử lý, nên
  simple / variable / grouped + field của plugin addon hoạt động như submit
  thường. JS gửi product id qua 'hithean_atc_product' (không phải
  'add-to-cart') để handler wp_loaded của WC không tự chạy thêm lần nữa.
\*---------------------------------------*/

const HITHEAN_ATC_POPUP_ACTION = 'hithean_atc_popup';

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
            'message' => $messages ? implode(' ', $messages) : __('Không thể thêm sản phẩm vào giỏ hàng.', 'hithean.com'),
        ]);
    }

    WC()->cart->calculate_totals();

    wp_send_json_success([
        'html'      => hithean_atc_popup_render_cart($added_key),
        'fragments' => hithean_atc_popup_fragments(),
        'cart_hash' => WC()->cart->get_cart_hash(),
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
    <ul class="atc-popup__items">
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
                    <p class="atc-popup__qty">
                        <?php echo esc_html($item['quantity']); ?> &times; <?php echo WC()->cart->get_product_price($product); ?>
                    </p>
                </div>
                <div class="atc-popup__line-total"><?php echo $cart->get_product_subtotal($product, $item['quantity']); ?></div>
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
  ASSETS + MARKUP MODAL
\*---------------------------------------*/

add_action('wp_enqueue_scripts', 'hithean_atc_popup_enqueue_assets', 20);

function hithean_atc_popup_enqueue_assets()
{
    if (!function_exists('is_product') || !is_product()) {
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
        'addingText'   => __('Đang thêm...', 'hithean.com'),
        'errorText'    => __('Có lỗi xảy ra, vui lòng thử lại.', 'hithean.com'),
    ]);
}

add_action('wp_footer', 'hithean_atc_popup_render_modal', 30);

function hithean_atc_popup_render_modal()
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }
    ?>
    <div class="atc-popup" id="atc-popup" hidden>
        <div class="atc-popup__backdrop" data-atc-popup-close></div>
        <div class="atc-popup__panel" role="dialog" aria-modal="true" aria-labelledby="atc-popup-title" tabindex="-1">
            <button type="button" class="atc-popup__close" data-atc-popup-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>

            <div class="atc-popup__state atc-popup__state--success">
                <h3 class="atc-popup__title" id="atc-popup-title">
                    <span class="atc-popup__check" aria-hidden="true">&#10003;</span>
                    <?php esc_html_e('Đã thêm vào giỏ hàng', 'hithean.com'); ?>
                </h3>
                <div class="atc-popup__cart"></div>
                <div class="atc-popup__actions">
                    <button type="button" class="button atc-popup__continue" data-atc-popup-close><?php esc_html_e('Tiếp tục mua sắm', 'hithean.com'); ?></button>
                    <a class="button alt atc-popup__checkout" href="<?php echo esc_url(wc_get_checkout_url()); ?>"><?php esc_html_e('Thanh toán', 'hithean.com'); ?></a>
                </div>
                <a class="atc-popup__view-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Xem giỏ hàng', 'hithean.com'); ?></a>
            </div>

            <div class="atc-popup__state atc-popup__state--error">
                <h3 class="atc-popup__title"><?php esc_html_e('Chưa thêm được vào giỏ hàng', 'hithean.com'); ?></h3>
                <p class="atc-popup__error-msg"></p>
                <div class="atc-popup__actions">
                    <button type="button" class="button alt" data-atc-popup-close><?php esc_html_e('Đóng', 'hithean.com'); ?></button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

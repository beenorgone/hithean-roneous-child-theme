<?php
if (!defined('ABSPATH')) exit;

/** Product-page presentation; WooCommerce and Discount Rules own all pricing. */
const HITHEAN_PRODUCT_QUOTE_ACTION = 'hithean_product_purchase_quote';

add_action('wp_ajax_' . HITHEAN_PRODUCT_QUOTE_ACTION, 'hithean_product_purchase_ajax_quote');
add_action('wp_ajax_nopriv_' . HITHEAN_PRODUCT_QUOTE_ACTION, 'hithean_product_purchase_ajax_quote');
add_action('wp_enqueue_scripts', 'hithean_product_purchase_enqueue', 30);
add_action('woocommerce_before_add_to_cart_button', 'hithean_product_purchase_render_quote', 25);
add_filter('body_class', 'hithean_product_purchase_body_class');

function hithean_product_purchase_body_class(array $classes): array
{
    if (function_exists('is_product') && is_product()) {
        $classes[] = 'hithean-product-purchase';
    }
    return $classes;
}

function hithean_product_purchase_enqueue(): void
{
    if (!function_exists('is_product') || !is_product()) return;

    $base = '/custom-functions/woocommerce/products/assets/product-purchase';
    foreach (['css', 'js'] as $extension) {
        $relative = $base . '.' . $extension;
        $version = (string) filemtime(get_stylesheet_directory() . $relative);
        if ($extension === 'css') {
            wp_enqueue_style('hithean-product-purchase', get_stylesheet_directory_uri() . $relative, ['hithean-custom-style', 'hithean-product-navigation', 'hithean-offer-card'], $version);
        } else {
            wp_enqueue_script('hithean-product-purchase', get_stylesheet_directory_uri() . $relative, ['jquery', 'hithean-product-navigation'], $version, true);
            wp_script_add_data('hithean-product-purchase', 'strategy', 'defer');
        }
    }
    wp_localize_script('hithean-product-purchase', 'hitheanProductPurchase', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'action' => HITHEAN_PRODUCT_QUOTE_ACTION,
        'nonce' => wp_create_nonce(HITHEAN_PRODUCT_QUOTE_ACTION),
        'productId' => (int) get_queried_object_id(),
        'pending' => __('Đang kiểm tra giá áp dụng…', 'hithean.com'),
        'unavailable' => __('Chưa xác nhận được giá theo lựa chọn. Giá được tính lại trong giỏ hàng.', 'hithean.com'),
        'selectOptions' => __('Chọn đầy đủ tùy chọn để xem giá.', 'hithean.com'),
        'invalidQuantity' => __('Vui lòng chọn số lượng hợp lệ.', 'hithean.com'),
        'addons' => __('Giá gồm lựa chọn bổ sung được xác nhận trong giỏ hàng.', 'hithean.com'),
        'chooseQuantity' => __('Chọn số lượng', 'hithean.com'),
        'temporary' => __('Tạm tính sản phẩm', 'hithean.com'),
        'adding' => __('Đang thêm…', 'hithean.com'),
        'offersUrl' => hithean_product_purchase_offers_url(),
        'offerMedia' => hithean_product_purchase_offer_media((int) get_queried_object_id()),
        // Thứ tự nhóm thẻ trong popup ưu đãi (marketing/offers/order.php — cùng thứ tự /uu-dai/).
        'offerOrder' => function_exists('hithean_offers_order') ? hithean_offers_order() : [],
    ]);
}

/**
 * Ảnh nhỏ góc phải thẻ trong popup ưu đãi: ảnh sản phẩm đang xem + ảnh sản phẩm add-on (dòng add-on loại
 * "product" — tặng/mua kèm) theo tên dòng. Chỉ đọc dữ liệu qua hàm của plugin product-addon, không sửa plugin.
 */
function hithean_product_purchase_offer_media(int $product_id): array
{
    $thumb = static function (int $id): string {
        $image_id = $id ? (int) get_post_thumbnail_id($id) : 0;
        return $image_id ? (string) wp_get_attachment_image_url($image_id, 'woocommerce_gallery_thumbnail') : '';
    };
    $addons = [];
    if ($product_id && function_exists('simple_addon_get_product_addons') && function_exists('simple_addon_resolve_product_id')) {
        foreach ((array) simple_addon_get_product_addons($product_id) as $addon) {
            if (($addon['type'] ?? '') !== 'product' || ($addon['status'] ?? '') !== 'ON' || isset($addons[$addon['name']])) continue;
            $url = $thumb((int) simple_addon_resolve_product_id($addon['target'] ?? ''));
            if ($url !== '') $addons[(string) $addon['name']] = $url;
        }
    }
    return ['product' => $thumb($product_id), 'addons' => (object) $addons];
}

/** Link "Xem mọi ưu đãi" trong popup ưu đãi: chỉ khi trang /uu-dai/ đã công khai (không ở chế độ xem trước). */
function hithean_product_purchase_offers_url(): string
{
    if (!function_exists('hithean_offers_settings') || hithean_offers_settings()['preview']) return '';
    $page = get_page_by_path('uu-dai');
    return $page && $page->post_status === 'publish' ? (string) get_permalink($page) : '';
}

function hithean_product_purchase_render_quote(): void
{
    global $product;
    if (!$product instanceof WC_Product || !$product->is_type(['simple', 'variable'])) return;
    ?>
    <div class="hithean-purchase-quote" hidden aria-live="polite" aria-atomic="true">
        <div class="hithean-purchase-quote__total"><span><?php esc_html_e('Tạm tính sản phẩm', 'hithean.com'); ?></span><strong data-purchase-subtotal></strong></div>
        <small class="hithean-purchase-quote__breakdown" data-purchase-breakdown></small>
        <p class="hithean-purchase-quote__status" data-purchase-status></p>
    </div>
    <?php
}

/**
 * Only published products belonging to this page can be quoted. The action
 * reads a single product and the current plugin context; it never adds items,
 * applies coupons, writes rule settings or exposes unpublished product data.
 */
function hithean_product_purchase_ajax_quote(): void
{
    nocache_headers();
    if (!check_ajax_referer(HITHEAN_PRODUCT_QUOTE_ACTION, 'nonce', false)) {
        wp_send_json_error(['message' => __('Phiên xem giá đã hết hạn. Vui lòng tải lại trang.', 'hithean.com')], 403);
        return;
    }

    $parent_id = isset($_POST['product_id']) && is_scalar($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
    $variation_id = isset($_POST['variation_id']) && is_scalar($_POST['variation_id']) ? absint(wp_unslash($_POST['variation_id'])) : 0;
    $quantity = isset($_POST['quantity']) && is_scalar($_POST['quantity']) ? (string) wp_unslash($_POST['quantity']) : '';
    $parent = $parent_id && function_exists('wc_get_product') ? wc_get_product($parent_id) : false;
    if (!$parent instanceof WC_Product || $parent->get_status() !== 'publish' || post_password_required($parent_id) || !$parent->is_type(['simple', 'variable'])) {
        wp_send_json_error([], 404);
        return;
    }

    $product = $parent;
    if ($parent->is_type('variable')) {
        $product = $variation_id ? wc_get_product($variation_id) : false;
        if (!$product instanceof WC_Product || !$product->is_type('variation') || (int) $product->get_parent_id() !== $parent_id || $product->get_status() !== 'publish' || !$product->variation_is_visible()) {
            wp_send_json_error([], 404);
            return;
        }
    } elseif ($variation_id) {
        wp_send_json_error([], 400);
        return;
    }

    // Discount Rules' own quantity endpoint accepts whole units, not decimals.
    if (!preg_match('/^[1-9][0-9]{0,3}$/D', $quantity) || !$product->is_purchasable() || !$product->is_in_stock()) {
        wp_send_json_error([], 400);
        return;
    }
    $quantity = (int) $quantity;
    $minimum = (float) $product->get_min_purchase_quantity();
    $maximum = (float) $product->get_max_purchase_quantity();
    if ($quantity < $minimum || ($maximum > 0 && $quantity > $maximum) || ($product->is_sold_individually() && $quantity !== 1)) {
        wp_send_json_error([], 400);
        return;
    }

    try {
        $quote = hithean_product_purchase_build_quote($product, $quantity);
    } catch (Throwable $error) {
        wp_send_json_error([], 503);
        return;
    }
    wp_send_json_success($quote);
}

/**
 * Accept only a fully matched, uniform product-price discount. Cart adjustments,
 * BOGO/set/mixed lines must be calculated by the native cart, not flattened.
 */
function hithean_product_purchase_rule_price($details, int $quantity): ?float
{
    if (!is_array($details) || empty($details['total_discount_details']) || !is_array($details['total_discount_details'])) return null;
    foreach ((array) ($details['apply_as_cart_rule'] ?? []) as $as_cart) {
        if ($as_cart === 'yes' || $as_cart === true || $as_cart === 1) return null;
    }
    $matched = false;
    foreach ($details['total_discount_details'] as $rule) {
        if (!is_array($rule)) return null;
        foreach ($rule as $kind => $discount) {
            if (empty($discount)) continue;
            if (!in_array($kind, ['simple_discount', 'bulk_discount'], true) || !is_array($discount)) return null;
            $matched = true;
        }
    }
    $initial = $details['initial_price'] ?? null;
    $discounted = $details['discounted_price'] ?? null;
    if (!$matched || !is_numeric($initial) || !is_numeric($discounted)) return null;
    $initial = (float) $initial;
    $discounted = (float) $discounted;
    if (!is_finite($initial) || !is_finite($discounted) || $discounted < 0 || $discounted >= $initial) return null;

    if (isset($details['discount_lines'])) {
        if (!is_array($details['discount_lines'])) return null;
        $covered = 0.0;
        foreach ($details['discount_lines'] as $line) {
            if (!is_array($line) || !is_numeric($line['quantity'] ?? null) || (float) $line['quantity'] < 0) return null;
            $units = (float) $line['quantity'];
            if ($units === 0.0) continue;
            if (!is_numeric($line['discounted_price'] ?? null) || abs((float) $line['discounted_price'] - $discounted) > 0.0001) return null;
            $covered += $units;
        }
        if (abs($covered - $quantity) > 0.0001) return null;
    }
    return $discounted;
}

function hithean_product_purchase_build_quote(WC_Product $product, int $quantity): array
{
    $price = $product->get_price();
    // get_price_html() also runs Discount Rules' catalogue/quantity-one
    // filters. It cannot establish eligibility for the selected quantity.
    // Use WooCommerce getters/formatters for the no-matching-rule baseline.
    $html = '';
    if (is_numeric($price) && is_finite((float) $price) && (float) $price >= 0) {
        $display_price = wc_get_price_to_display($product, ['price' => (float) $price]);
        $regular = $product->get_regular_price();
        $html = $product->is_on_sale('edit') && is_numeric($regular) && (float) $regular > (float) $price
            ? wc_format_sale_price(wc_get_price_to_display($product, ['price' => (float) $regular]), $display_price)
            : wc_price($display_price);
        $html .= $product->get_price_suffix((float) $price);
    }
    $sale_notice = '';
    // Bypass the plugin's optional "on sale" badge filter: this notice is
    // specifically about a WooCommerce sale, not merely an available rule.
    if ($product->is_on_sale('edit')) {
        $from = $product->get_date_on_sale_from();
        $to = $product->get_date_on_sale_to();
        $date_format = get_option('date_format') ?: 'd/m/Y';
        $notice = $to ? sprintf(__('Áp dụng đến hết %s', 'hithean.com'), wp_date($date_format, $to->getTimestamp()))
            : ($from ? sprintf(__('Áp dụng từ %s', 'hithean.com'), wp_date($date_format, $from->getTimestamp())) : __('Đang áp dụng giá sale', 'hithean.com'));
        $sale_notice = '<span class="hithean-sale-price-date-notice">' . esc_html($notice) . '</span>';
    }
    if (strpos($html, 'hithean-sale-price-date-notice') === false) $html .= $sale_notice;
    $result = [
        'productId' => (int) $product->get_id(),
        'quantity' => $quantity,
        'state' => 'base',
        'unitHtml' => wp_kses_post($html),
        'baseUnitHtml' => wp_kses_post($html),
        'subtotalHtml' => '',
        'message' => __('Giá sản phẩm hiện tại. Phí giao hàng và ưu đãi của đơn được xác nhận trong giỏ hàng.', 'hithean.com'),
    ];
    if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
        $result['state'] = 'deferred';
        return $result;
    }

    // This is the plugin's own matched-details path, also used by its frontend
    // awdr_get_product_discount endpoint. No table/percentage inference.
    $details = has_filter('advanced_woo_discount_rules_get_product_discount_price_from_custom_price') !== false
        ? apply_filters('advanced_woo_discount_rules_get_product_discount_price_from_custom_price', false, $product, $quantity, 0, 'all', true)
        : false;
    if ($details !== false && $details !== null && $details !== []) {
        $rule_price = hithean_product_purchase_rule_price($details, $quantity);
        if ($rule_price === null) {
            $result['state'] = 'deferred';
            $result['message'] = __('Ưu đãi này được tính theo điều kiện giỏ hàng. Xem giá cuối trong giỏ hàng.', 'hithean.com');
            return $result;
        }
        $initial_display = wc_get_price_to_display($product, ['price' => (float) $details['initial_price']]);
        $discount_display = wc_get_price_to_display($product, ['price' => $rule_price]);
        $result['state'] = 'discount';
        $result['unitHtml'] = wp_kses_post(wc_format_sale_price($initial_display, $discount_display) . $product->get_price_suffix($rule_price) . $sale_notice);
        $result['message'] = __('Giá theo ưu đãi đang áp dụng cho lựa chọn này. Đơn hàng được tính lại trong giỏ hàng.', 'hithean.com');
        $price = $rule_price;
    }
    $subtotal = wc_get_price_to_display($product, ['qty' => $quantity, 'price' => (float) $price]);
    $result['subtotalHtml'] = wp_kses_post(wc_price($subtotal) . $product->get_price_suffix((float) $price, $quantity));
    // "đơn giá × N SP" cạnh nhãn tạm tính (giá đơn vị đã áp ưu đãi số lượng nếu có).
    $result['breakdownHtml'] = wp_kses_post(wc_price(wc_get_price_to_display($product, ['price' => (float) $price])) . ' × ' . $quantity . ' SP');
    return $result;
}

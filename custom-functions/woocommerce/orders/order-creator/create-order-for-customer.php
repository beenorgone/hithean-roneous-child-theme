<?php
if (!defined('ABSPATH')) exit;

/**
 * Tạo đơn hộ khách hàng — chạy qua cart pipeline.
 *
 * Khác với plugin Phone Orders / màn tạo đơn native (dựng WC_Order trực tiếp,
 * bỏ qua mọi hook giá), module này mô phỏng một phiên giỏ hàng server-side
 * DƯỚI ĐÚNG DANH TÍNH KHÁCH HÀNG để coupon, BOGO/fees và phí/ship động
 * áp dụng đúng y như khách tự checkout, gồm cả giá theo role và rule ưu đãi.
 *
 * Route:  /tao-don/
 * Prefix: order_creator_*   (đặt tên tổng quát để tái dùng cho site khác)
 *
 * Tích hợp (tái dùng backend có sẵn của theme):
 *  - Xác nhận TT:     AJAX `confirm_order_payment` (shortcode-bank-transfer-confirmation.php)
 *  - Xử lý đơn:       meta `order_handling_status` (order-metabox.php)
 *  - Xem hóa đơn:     WC_Email_Customer_Invoice (preview HTML)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ORDER_CREATOR_ROUTE')) {
    define('ORDER_CREATOR_ROUTE', 'tao-don');
}
if (!defined('ORDER_CREATOR_NONCE')) {
    define('ORDER_CREATOR_NONCE', 'order_creator_nonce');
}
if (!defined('ORDER_CREATOR_CAP')) {
    define('ORDER_CREATOR_CAP', 'edit_shop_orders');
}

// ================================================================
// STATE — cờ + danh sách phí dùng trong pipeline (chỉ tác động khi active)
// ================================================================

function &order_creator_state(): array
{
    static $state = ['active' => false, 'fees' => [], 'is_admin' => false, 'manual_shipping' => false, 'shipping_cost' => null, 'source_prices' => [], 'coupon_results' => [], 'editing_order_coupons' => [], 'editing_order_customer_id' => 0];
    return $state;
}

// ================================================================
// QUYỀN & TIỆN ÍCH CHUNG
// ================================================================

function order_creator_can(): bool
{
    return current_user_can(ORDER_CREATOR_CAP) || current_user_can('manage_woocommerce');
}

function order_creator_verify_ajax(): void
{
    if (!check_ajax_referer(ORDER_CREATOR_NONCE, 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên làm việc hết hạn, vui lòng tải lại trang.'], 403);
    }
    if (!order_creator_can()) {
        wp_send_json_error(['message' => 'Bạn không có quyền tạo đơn.'], 403);
    }
}

function order_creator_get_payload(): array
{
    $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Đảm bảo WC cart/session/customer được khởi tạo (admin-ajax không tự load). */
function order_creator_ensure_wc_cart(): void
{
    if (function_exists('WC') && WC()->cart === null && function_exists('wc_load_cart')) {
        wc_load_cart();
    }
}

// ================================================================
// CÀI ĐẶT (tab Tùy chỉnh — chỉ Admin)
// ================================================================

function order_creator_default_settings(): array
{
    return [
        'default_products'  => [],     // SP mặc định hiện khi focus ô tìm
        'show_image_stock'  => true,   // hiện ảnh + tồn trong kết quả
        'group_hidden_last' => true,   // SP ẩn/riêng tư xuống cuối
        'hide_manual_price' => false,
        'hide_line_discount' => false,
        'customer_fields'   => ['first_name', 'last_name', 'email', 'username', 'role', 'phone', 'address_1', 'address_2', 'city', 'state'],
        'customer_info_fields' => ['chat_link', 'facebook'],
        'order_fields'      => ['order_handling_status'],
    ];
}

function order_creator_get_settings(): array
{
    $saved = get_option('order_creator_settings', []);
    $settings = wp_parse_args(is_array($saved) ? $saved : [], order_creator_default_settings());
    $settings['default_products'] = array_values(array_filter(array_map('absint', (array) $settings['default_products'])));
    $settings['customer_fields']  = array_values(array_map('sanitize_key', (array) $settings['customer_fields']));
    $settings['customer_info_fields'] = array_values(array_map('sanitize_key', (array) $settings['customer_info_fields']));
    $settings['order_fields']     = array_values(array_map('sanitize_key', (array) $settings['order_fields']));
    return $settings;
}

/**
 * Field "Thông tin khách hàng" (chỉ đọc) hiển thị từ user-meta.
 * hithean chưa có hệ thống điểm/CRM riêng → để trống; thêm field qua filter khi cần:
 *   add_filter('order_creator_customer_info_field_defs', fn($d) => $d + [
 *       'note' => ['label' => 'Ghi chú', 'meta_key' => 'private_about_note'],
 *   ]);
 */
function order_creator_customer_info_field_defs(): array
{
    return (array) apply_filters('order_creator_customer_info_field_defs', [
        'chat_link' => ['label' => 'Chat link', 'meta_key' => 'chat_link'],
        'facebook' => ['label' => 'Facebook', 'meta_key' => 'facebook'],
    ]);
}

/**
 * Field meta-đơn cấu hình hiển thị phía trên bảng tổng.
 * Key trùng meta_key của order-metabox.php để dữ liệu khớp với metabox WP Admin + cột ACP.
 */
function order_creator_order_field_defs(): array
{
    return [
        // order_handling_status: select_advanced multiple trong order-metabox.php (lưu dạng mảng).
        'order_handling_status' => [
            'label' => 'Trạng thái xử lý',
            'type' => 'checkboxes',
            'options' => [
                'Đã in phiếu xuất hàng'    => 'Đã in phiếu xuất hàng',
                'Chờ in vận đơn'           => 'Chờ in vận đơn',
                'Đã in vận đơn, chờ xử lý' => 'Đã in vận đơn, chờ xử lý',
                'Đã nhập kho vận chuyển'   => 'Đã nhập kho vận chuyển',
                'Cần chỉnh đơn'            => 'Cần chỉnh đơn',
                'Giao nhanh 1 giờ'         => 'Giao nhanh 1 giờ',
                'Giao nhanh 2 giờ'         => 'Giao nhanh 2 giờ',
                'Giao nhanh 4 giờ'         => 'Giao nhanh 4 giờ',
                'Thiếu SP'                 => 'Thiếu SP',
            ],
        ],
    ];
}

function order_creator_apply_order_meta(WC_Order $order, array $payload): void
{
    $values = isset($payload['order_meta']) && is_array($payload['order_meta']) ? $payload['order_meta'] : [];
    $enabled = array_flip(order_creator_get_settings()['order_fields']);
    foreach (order_creator_order_field_defs() as $key => $field) {
        if (!isset($enabled[$key])) {
            continue;
        }
        $raw = $values[$key] ?? (in_array($field['type'], ['multi_select', 'checkboxes'], true) ? [] : '');
        $allowed = $field['type'] === 'checkboxes' ? array_keys($field['options']) : $field['options'];
        if (in_array($field['type'], ['multi_select', 'checkboxes'], true)) {
            $value = array_values(array_intersect($allowed, array_map('sanitize_text_field', (array) $raw)));
        } else {
            $value = sanitize_text_field((string) $raw);
            $value = in_array($value, $allowed, true) ? $value : '';
        }
        $order->update_meta_data($key, $value);
    }
}

/**
 * Field user-meta tùy biến hiển thị/sửa trong popup khách hàng.
 * hithean chưa có metabox user riêng → để trống; mở rộng qua filter khi cần:
 *   add_filter('order_creator_customer_custom_field_defs', fn($f) => $f + [
 *       'cccd' => ['label' => 'CCCD', 'type' => 'text'],
 *   ]);
 * Mỗi field: ['label' => string, 'type' => 'text'|'email'|'textarea'].
 */
function order_creator_customer_custom_field_defs(): array
{
    $fields = [];
    foreach ((array) apply_filters('order_creator_customer_custom_field_defs', []) as $id => $def) {
        $id = sanitize_key((string) $id);
        $type = $def['type'] ?? 'text';
        if ($id === '' || !in_array($type, ['text', 'email', 'textarea'], true)) {
            continue;
        }
        $fields[$id] = [
            'label' => sanitize_text_field((string) ($def['label'] ?? $id)),
            'type'  => $type,
        ];
    }

    return $fields;
}

/** Danh sách field có thể bật/tắt cho popup khách hàng. */
function order_creator_customer_field_defs(): array
{
    return array_merge([
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'email'      => 'E-mail',
        'username'   => 'Username',
        'role'       => 'Role',
        'phone'      => 'Phone',
        'address_1'  => 'Address 1',
        'address_2'  => 'Address 2',
        'city'       => 'City',
        'state'      => 'State/County',
    ], wp_list_pluck(order_creator_customer_custom_field_defs(), 'label'));
}

// ================================================================
// HOOK CHÍNH SÁCH GIÁ THỦ CÔNG (chỉ chạy trong pipeline của module)
// ================================================================

/** Ghi đè giá / giảm giá theo dòng — chạy sau các plugin chính sách giá. */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    $state = &order_creator_state();
    if (!$state['active'] || !($cart instanceof WC_Cart)) {
        return;
    }
    foreach ($cart->get_cart() as $item) {
        if (empty($item['data']) || !($item['data'] instanceof WC_Product)) {
            continue;
        }

        // Một số rule giá trả về giá rỗng cho SP hidden/hết hàng ở context "view".
        // Giữ giá hiệu lực đã snapshot lúc nhân viên thêm sản phẩm để cart vẫn tính được.
        if (isset($item['_oc_source_price']) && is_numeric($item['_oc_source_price']) && !is_numeric($item['data']->get_price())) {
            $item['data']->set_price(max(0, (float) $item['_oc_source_price']));
        }

        if (empty($state['is_admin'])) {
            continue; // giá sửa / giảm theo dòng chỉ dành cho admin
        }
        if (isset($item['_oc_manual_price']) && $item['_oc_manual_price'] !== '') {
            $item['data']->set_price(max(0, (float) $item['_oc_manual_price']));
        } elseif (!empty($item['_oc_line_discount'])) {
            $price = (float) $item['data']->get_price();
            $item['data']->set_price(max(0, $price - (float) $item['_oc_line_discount']));
        }
    }
}, 1000);

/** Phí thủ công + giảm giá đơn (giảm = phí âm). */
add_action('woocommerce_cart_calculate_fees', function ($cart) {
    $state = &order_creator_state();
    if (!$state['active'] || !($cart instanceof WC_Cart)) {
        return;
    }
    foreach ($state['fees'] as $fee) {
        $name = $fee['name'] !== '' ? $fee['name'] : 'Điều chỉnh';
        $cart->add_fee($name, (float) $fee['amount'], false);
    }
}, 1000);

/**
 * Khi chỉnh sửa đơn đã dùng mã ưu đãi: lượt dùng của mã đã bị chính đơn này
 * ghi nhận (WooCommerce tăng usage khi đơn sang trạng thái tính phí), nên áp
 * lại mã trong lúc sửa sẽ bị chặn "đã hết lượt". Trừ ảo 1 lượt của đơn đang
 * sửa lúc validate — không ghi DB nên usage thật không bị lệch.
 */
add_filter('woocommerce_coupon_get_usage_count', function ($count, $coupon) {
    $state = &order_creator_state();
    if (!$state['active'] || empty($state['editing_order_coupons']) || !($coupon instanceof WC_Coupon)) {
        return $count;
    }
    $code = wc_format_coupon_code($coupon->get_code());
    if (in_array($code, $state['editing_order_coupons'], true)) {
        return max(0, (int) $count - 1);
    }
    return $count;
}, 1000, 2);

/** Tương tự cho giới hạn lượt dùng / khách: bỏ qua check khi lượt đó là của chính đơn đang sửa. */
add_filter('woocommerce_coupon_validate_user_usage_limit', function ($validate, $user_id, $coupon) {
    $state = &order_creator_state();
    if (!$validate || !$state['active'] || empty($state['editing_order_coupons']) || !($coupon instanceof WC_Coupon)) {
        return $validate;
    }
    $code = wc_format_coupon_code($coupon->get_code());
    if (in_array($code, $state['editing_order_coupons'], true) && (int) $user_id === (int) $state['editing_order_customer_id']) {
        return false;
    }
    return $validate;
}, 1000, 3);

/** Woo Discount Rules Pro có rule freeship riêng nhưng đôi khi chưa register rate trong AJAX. */
function order_creator_wdr_free_shipping_applies(): bool
{
    if (class_exists('\WDRPro\App\Rules\FreeShipping') && is_callable(['\WDRPro\App\Rules\FreeShipping', 'cartHasFreeShipping'])) {
        return (bool) \WDRPro\App\Rules\FreeShipping::cartHasFreeShipping();
    }
    if (class_exists('\Wdr\App\Controllers\DiscountCalculator') && is_callable(['\Wdr\App\Controllers\DiscountCalculator', 'getFreeshippingMethod'])) {
        $discount = \Wdr\App\Controllers\DiscountCalculator::getFreeshippingMethod();
        return !empty($discount['free_shipping']);
    }
    return false;
}

/**
 * Ship trong order creator:
 * - Không nhập phí thủ công: giữ pipeline checkout, bổ sung rate WDR freeship nếu rule đang apply.
 * - Có nhập phí thủ công: ghi đè cost của mọi rate để đơn lưu đúng phí nhân viên đặt.
 */
add_filter('woocommerce_package_rates', function ($rates) {
    $state = &order_creator_state();
    if (!$state['active'] || !is_array($rates)) {
        return $rates;
    }

    if (empty($state['manual_shipping'])) {
        if (order_creator_wdr_free_shipping_applies() && !isset($rates['wdr_free_shipping'])) {
            $rates['wdr_free_shipping'] = new WC_Shipping_Rate(
                'wdr_free_shipping',
                __('Free shipping', 'woo-discount-rules-pro'),
                0,
                [],
                'wdr_free_shipping'
            );
        }
        return $rates;
    }

    if ($state['shipping_cost'] === null) {
        return $rates;
    }

    $cost  = max(0, (float) $state['shipping_cost']);
    $taxes = wc_tax_enabled() ? WC_Tax::calc_shipping_tax($cost, WC_Tax::get_shipping_tax_rates()) : [];
    foreach ($rates as $rate) {
        $rate->set_cost($cost);
        if (wc_tax_enabled()) {
            $rate->set_taxes($taxes);
        }
    }
    return $rates;
}, 1000);

/** Cho phép thêm SP hết hàng/backorder khi tạo đơn hộ khách. */
add_filter('woocommerce_add_to_cart_validation', function ($passed) {
    $state = &order_creator_state();
    return $state['active'] ? true : $passed;
}, 1000);

/** Chỉ trong phiên tạo đơn: cho phép nhân viên thêm SP ẩn hoặc hết hàng. */
add_filter('woocommerce_is_purchasable', function ($purchasable) {
    $state = &order_creator_state();
    return $state['active'] ? true : $purchasable;
}, 1000);

add_filter('woocommerce_product_is_in_stock', function ($in_stock) {
    $state = &order_creator_state();
    return $state['active'] ? true : $in_stock;
}, 1000);

add_filter('woocommerce_product_has_enough_stock', function ($has_enough_stock) {
    $state = &order_creator_state();
    return $state['active'] ? true : $has_enough_stock;
}, 1000);

/** Fallback giá lưu trong DB khi một filter front-end ẩn giá SP không thể mua. */
add_filter('woocommerce_product_get_price', function ($price, $product) {
    $state = &order_creator_state();
    if (!$state['active'] || is_numeric($price) || !($product instanceof WC_Product)) {
        return $price;
    }

    $source_price = $state['source_prices'][$product->get_id()] ?? null;
    return is_numeric($source_price) ? (string) $source_price : $price;
}, PHP_INT_MAX, 2);

// ================================================================
// CART PIPELINE
// ================================================================

/**
 * Chạy callback dưới danh tính khách hàng đích, snapshot + khôi phục giỏ của
 * nhân viên. Mạo danh current_user để plugin đọc đúng role/khách.
 */
function order_creator_with_customer_context(int $user_id, callable $fn)
{
    order_creator_ensure_wc_cart();

    $state = &order_creator_state();
    $orig_user           = get_current_user_id();
    $orig_session_cart   = WC()->session ? WC()->session->get('cart') : null;
    $orig_chosen         = WC()->session ? WC()->session->get('chosen_shipping_methods') : null;

    $state['active']   = true;
    $state['is_admin'] = current_user_can('manage_options'); // operator hiện tại (trước khi mạo danh)

    try {
        if ($user_id > 0) {
            wp_set_current_user($user_id);
        }
        if (WC()->session) {
            WC()->session->set('order_awaiting_payment', false); // tránh tái dùng đơn nháp của NV
        }
        WC()->cart->empty_cart(false);
        WC()->customer = new WC_Customer($user_id > 0 ? $user_id : 0);

        return $fn();
    } finally {
        $state['active'] = false;
        $state['fees']   = [];
        $state['manual_shipping'] = false;
        $state['shipping_cost'] = null;
        $state['source_prices'] = [];
        $state['coupon_results'] = [];
        $state['editing_order_coupons'] = [];
        $state['editing_order_customer_id'] = 0;

        WC()->cart->empty_cart(false);
        if (WC()->session) {
            if (is_array($orig_session_cart)) {
                WC()->session->set('cart', $orig_session_cart);
            }
            WC()->session->set('chosen_shipping_methods', $orig_chosen);
        }
        wp_set_current_user($orig_user);
        WC()->customer = new WC_Customer($orig_user > 0 ? $orig_user : 0);
        WC()->cart->get_cart_from_session();
    }
}

/**
 * Link sửa coupon cho operator. Không dùng get_edit_post_link() trong lúc
 * mạo danh khách vì hàm đó kiểm tra quyền theo current user hiện tại.
 */
function order_creator_coupon_edit_url(int $coupon_id): string
{
    if ($coupon_id <= 0 || empty(order_creator_state()['is_admin'])) {
        return '';
    }

    return (string) add_query_arg([
        'post'   => $coupon_id,
        'action' => 'edit',
    ], admin_url('post.php'));
}

/** Xoá cache rate để các rule ship/free-ship chạy lại theo cart hiện tại. */
function order_creator_reset_shipping_cache(): void
{
    if (!WC()->session || !WC()->cart) {
        return;
    }
    foreach (array_keys(WC()->cart->get_shipping_packages()) as $package_key) {
        WC()->session->set('shipping_for_package_' . $package_key, false);
    }
}

/** Chọn shipping rate giống checkout: ưu tiên freeship, rồi rate đang chọn, rồi rate đầu tiên. */
function order_creator_choose_checkout_shipping_rate(): void
{
    if (!function_exists('WC') || !WC()->session || !WC()->shipping()) {
        return;
    }

    $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
    $changed = false;
    foreach (WC()->shipping()->get_packages() as $package_key => $package) {
        $rates = (array) ($package['rates'] ?? []);
        $current_id = (string) ($chosen[$package_key] ?? '');
        $current_rate = $current_id !== '' && isset($rates[$current_id]) ? $rates[$current_id] : null;
        $fallback_id = '';
        $package_changed = false;
        foreach ($rates as $rate_id => $rate) {
            if ($fallback_id === '') {
                $fallback_id = (string) $rate_id;
            }
            if (!is_callable([$rate, 'get_method_id'])) {
                continue;
            }
            $method_id = (string) $rate->get_method_id();
            if (in_array($method_id, ['wdr_free_shipping', 'free_shipping'], true) && (float) $rate->get_cost() <= 0) {
                $chosen[$package_key] = $rate_id;
                $changed = true;
                $package_changed = true;
                break;
            }
        }
        if (!$package_changed && !$current_rate && $fallback_id !== '') {
            $chosen[$package_key] = $fallback_id;
            $changed = true;
        }
    }

    if ($changed) {
        WC()->session->set('chosen_shipping_methods', $chosen);
    }
}

/**
 * Tính tổng theo checkout pipeline và refresh shipping sau khi discount rules
 * đã chạy, vì một số rule freeship chỉ quyết định trong pha calculate_totals.
 */
function order_creator_calculate_cart_totals(): void
{
    order_creator_reset_shipping_cache();
    WC()->cart->calculate_shipping();
    WC()->cart->calculate_totals();
    // Nạp lại product object sạch từ session trước khi tính tổng lần 2, vì
    // lần 1 có thể đã set_price() giá đã giảm lên object hiện tại (WDR, hook giá
    // thủ công...). Không reload thì lần 2 tính discount tiếp trên giá ĐÃ giảm
    // của lần 1 thay vì giá gốc → sai tier luỹ tiến (VD: 3 SP cùng mã giảm
    // 5%/5%/10% ở checkout nhưng lệch bậc khi tạo đơn).
    WC()->cart->get_cart_from_session();
    order_creator_reset_shipping_cache();
    WC()->cart->calculate_shipping();
    order_creator_choose_checkout_shipping_rate();
    WC()->cart->calculate_totals();
}

/** Nạp SP, coupon, phí, ship vào giỏ theo payload (chưa tính tổng). */
function order_creator_populate_cart(array $payload): void
{
    $state = &order_creator_state();
    $state['fees'] = [];
    $state['manual_shipping'] = isset($payload['shipping_cost']) && $payload['shipping_cost'] !== '' && is_numeric($payload['shipping_cost']);
    $state['shipping_cost'] = $state['manual_shipping']
        ? max(0, (float) $payload['shipping_cost'])
        : null;

    // Đang sửa đơn: gom các mã ưu đãi mà đơn này ĐÃ ghi nhận lượt dùng, để filter
    // validate coupon trừ ảo lượt của chính đơn này (xem hook phía trên).
    $state['editing_order_coupons'] = [];
    $state['editing_order_customer_id'] = 0;
    $edit_order_id = absint($payload['order_id'] ?? 0);
    if ($edit_order_id > 0) {
        $edit_order = wc_get_order($edit_order_id);
        if ($edit_order instanceof WC_Order) {
            $data_store = $edit_order->get_data_store();
            $usage_recorded = is_callable([$data_store, 'get_recorded_coupon_usage_counts'])
                ? (bool) $data_store->get_recorded_coupon_usage_counts($edit_order)
                : wc_string_to_bool((string) $edit_order->get_meta('_recorded_coupon_usage_counts'));
            if ($usage_recorded) {
                $codes = method_exists($edit_order, 'get_coupon_codes') ? $edit_order->get_coupon_codes() : $edit_order->get_used_coupons();
                foreach ((array) $codes as $code) {
                    $state['editing_order_coupons'][] = wc_format_coupon_code((string) $code);
                }
                $state['editing_order_customer_id'] = (int) $edit_order->get_customer_id();
            }
        }
    }

    $billing  = isset($payload['billing']) && is_array($payload['billing']) ? $payload['billing'] : [];
    $shipping = isset($payload['shipping']) && is_array($payload['shipping']) ? $payload['shipping'] : [];
    $ship_for_calc = !empty($shipping) ? $shipping : $billing;

    if (WC()->customer) {
        WC()->customer->set_billing_country($billing['country'] ?? 'VN');
        WC()->customer->set_shipping_country($ship_for_calc['country'] ?? 'VN');
        WC()->customer->set_shipping_state($ship_for_calc['state'] ?? '');
        WC()->customer->set_shipping_city($ship_for_calc['city'] ?? '');
        WC()->customer->set_shipping_postcode($ship_for_calc['postcode'] ?? '');
        WC()->customer->set_shipping_address_1($ship_for_calc['address_1'] ?? '');
    }

    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    // WooCommerce core hook calculate_totals chạy sau MỖI add_to_cart. Vì trang
    // tạo đơn nạp cả giỏ trong 1 request (khác web: mỗi lần thêm giỏ là 1 request
    // riêng), rule số lượng của WDR sẽ tính khi giỏ chưa đủ hàng và cache kết quả
    // theo từng variation trong suốt request → 2 dòng đầu kẹt ở bậc thấp (5%) dù
    // tổng qty đạt bậc cao (10%). Tắt auto-calc khi nạp, nạp đủ rồi tính 1 lần.
    remove_action('woocommerce_add_to_cart', [WC()->cart, 'calculate_totals'], 20);
    remove_action('woocommerce_applied_coupon', [WC()->cart, 'calculate_totals'], 20);
    foreach ($items as $item) {
        $product_id   = absint($item['product_id'] ?? 0);
        $variation_id = absint($item['variation_id'] ?? 0);
        $qty          = max(1, (float) ($item['qty'] ?? 1));
        if ($product_id <= 0) {
            continue;
        }

        $price_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($product_id);
        if (!$price_product instanceof WC_Product) {
            continue;
        }

        $cart_item_data = [];
        // Context "edit" đọc _price đã lưu (sale price nếu có), không bị các filter
        // front-end ẩn giá đối với SP hidden/hết hàng tác động.
        $source_price = $price_product->get_price('edit');
        if (is_numeric($source_price)) {
            $cart_item_data['_oc_source_price'] = (float) $source_price;
            $state['source_prices'][$price_product->get_id()] = (float) $source_price;
        }
        if (isset($item['manual_price']) && $item['manual_price'] !== '' && $item['manual_price'] !== null) {
            $cart_item_data['_oc_manual_price'] = (float) $item['manual_price'];
        }
        if (!empty($item['line_discount'])) {
            $cart_item_data['_oc_line_discount'] = (float) $item['line_discount'];
        }

        $variation_attrs = [];
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation instanceof WC_Product_Variation) {
                $variation_attrs = $variation->get_variation_attributes();
            }
        }

        WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation_attrs, $cart_item_data);
    }

    // Giỏ đã đủ hàng: bật lại auto-calc rồi tính tổng 1 lần để (1) WDR chạy đúng
    // bậc số lượng ngay từ lần tính đầu tiên (cache trong request giữ giá trị đúng)
    // và (2) coupon validation phía dưới đọc đúng subtotal.
    add_action('woocommerce_add_to_cart', [WC()->cart, 'calculate_totals'], 20, 0);
    add_action('woocommerce_applied_coupon', [WC()->cart, 'calculate_totals'], 20, 0);
    WC()->cart->calculate_totals();

    $fees = isset($payload['fees']) && is_array($payload['fees']) ? $payload['fees'] : [];
    foreach ($fees as $fee) {
        $state['fees'][] = [
            'name'   => sanitize_text_field($fee['name'] ?? ''),
            'amount' => (float) ($fee['amount'] ?? 0),
        ];
    }

    $state['coupon_results'] = [];
    $coupons = isset($payload['coupons']) && is_array($payload['coupons']) ? $payload['coupons'] : [];
    foreach ($coupons as $raw_code) {
        $code = wc_format_coupon_code(wc_clean((string) $raw_code));
        if ($code === '') {
            continue;
        }
        if (WC()->cart->has_discount($code)) {
            $state['coupon_results'][$code] = ['applied' => true, 'message' => ''];
            continue;
        }
        // Bắt notice lỗi của WooCommerce để báo lý do thất bại thân thiện cho nhân viên.
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        $ok      = WC()->cart->apply_coupon($code);
        $applied = $ok && WC()->cart->has_discount($code);
        $message = '';
        if (!$applied && function_exists('wc_get_notices')) {
            foreach (wc_get_notices('error') as $notice) {
                $text    = is_array($notice) ? ($notice['notice'] ?? '') : (string) $notice;
                $message = trim($message . ' ' . wp_strip_all_tags((string) $text));
            }
        }
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        $state['coupon_results'][$code] = [
            'applied' => $applied,
            'message' => $applied ? '' : ($message !== '' ? $message : 'Mã ưu đãi không áp dụng được cho đơn này.'),
        ];
    }

    if ($state['manual_shipping'] && !empty($payload['shipping_method']) && WC()->session) {
        WC()->session->set('chosen_shipping_methods', [wc_clean((string) $payload['shipping_method'])]);
    } elseif (!$state['manual_shipping'] && WC()->session) {
        WC()->session->set('chosen_shipping_methods', []);
    }
    // Xoá cache rate (WC cache theo hash gói hàng, không gồm phí ship thủ công) để
    // filter woocommerce_package_rates chạy lại với shipping_cost mới mỗi lần tính.
    order_creator_reset_shipping_cache();
    WC()->cart->calculate_shipping();
}

/**
 * Thông tin tóm tắt 1 mã ưu đãi để hiển thị popup "Xem chi tiết".
 * Trả về null khi mã không tồn tại trong hệ thống.
 */
function order_creator_coupon_detail(string $code): ?array
{
    $code = wc_format_coupon_code($code);
    $id   = function_exists('wc_get_coupon_id_by_code') ? wc_get_coupon_id_by_code($code) : 0;
    if (!$id) {
        return null;
    }
    $coupon = new WC_Coupon($id);

    $type_labels = function_exists('wc_get_coupon_types') ? wc_get_coupon_types() : [];
    $type        = $coupon->get_discount_type();
    $amount      = (float) $coupon->get_amount();
    $amount_text = $type === 'percent'
        ? wc_format_decimal($amount, false, true) . '%'
        : wp_strip_all_tags(wc_price($amount));

    $rows   = [];
    $rows[] = ['label' => 'Loại giảm', 'value' => $type_labels[$type] ?? $type];
    $rows[] = ['label' => 'Giá trị', 'value' => $amount_text];
    if ($coupon->get_free_shipping()) {
        $rows[] = ['label' => 'Miễn phí ship', 'value' => 'Có'];
    }
    if ((float) $coupon->get_minimum_amount() > 0) {
        $rows[] = ['label' => 'Đơn tối thiểu', 'value' => wp_strip_all_tags(wc_price($coupon->get_minimum_amount()))];
    }
    if ((float) $coupon->get_maximum_amount() > 0) {
        $rows[] = ['label' => 'Đơn tối đa', 'value' => wp_strip_all_tags(wc_price($coupon->get_maximum_amount()))];
    }
    $expiry = $coupon->get_date_expires();
    if ($expiry) {
        $rows[] = ['label' => 'Hết hạn', 'value' => $expiry->date_i18n('d/m/Y')];
    }
    if ((int) $coupon->get_usage_limit() > 0) {
        $rows[] = ['label' => 'Lượt dùng', 'value' => (int) $coupon->get_usage_count() . ' / ' . (int) $coupon->get_usage_limit()];
    }
    if ((int) $coupon->get_usage_limit_per_user() > 0) {
        $rows[] = ['label' => 'Giới hạn / khách', 'value' => (string) (int) $coupon->get_usage_limit_per_user()];
    }
    if ($coupon->get_individual_use()) {
        $rows[] = ['label' => 'Dùng riêng', 'value' => 'Không cộng dồn mã khác'];
    }
    if ($coupon->get_exclude_sale_items()) {
        $rows[] = ['label' => 'SP đang sale', 'value' => 'Không áp dụng'];
    }

    $product_names = function (array $ids): string {
        $names = [];
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $names[] = $p->get_name();
            }
        }
        return implode(', ', $names);
    };
    $category_names = function (array $ids): string {
        $names = [];
        foreach ($ids as $cid) {
            $term = get_term($cid, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            }
        }
        return implode(', ', $names);
    };
    if ($coupon->get_product_ids()) {
        $rows[] = ['label' => 'Chỉ áp dụng SP', 'value' => $product_names($coupon->get_product_ids())];
    }
    if ($coupon->get_excluded_product_ids()) {
        $rows[] = ['label' => 'Loại trừ SP', 'value' => $product_names($coupon->get_excluded_product_ids())];
    }
    if ($coupon->get_product_categories()) {
        $rows[] = ['label' => 'Chỉ áp dụng danh mục', 'value' => $category_names($coupon->get_product_categories())];
    }
    if ($coupon->get_excluded_product_categories()) {
        $rows[] = ['label' => 'Loại trừ danh mục', 'value' => $category_names($coupon->get_excluded_product_categories())];
    }
    if ($coupon->get_email_restrictions()) {
        $rows[] = ['label' => 'Giới hạn email', 'value' => implode(', ', $coupon->get_email_restrictions())];
    }

    // Quyền sửa coupon kiểm tra theo operator (đã snapshot trước khi mạo danh khách).
    $is_admin = !empty(order_creator_state()['is_admin']);

    return [
        'code'        => $coupon->get_code(),
        'description' => wp_strip_all_tags($coupon->get_description()),
        'rows'        => $rows,
        'edit_url'    => $is_admin ? order_creator_coupon_edit_url($id) : '',
    ];
}

/** Đọc giỏ đã tính → mảng cho UI (preview, không lưu). */
function order_creator_snapshot_cart(): array
{
    $cart  = WC()->cart;
    $lines = [];
    foreach ($cart->get_cart() as $key => $item) {
        $product = $item['data'];
        $lines[] = [
            'key'           => $key,
            'product_id'    => (int) $item['product_id'],
            'variation_id'  => (int) $item['variation_id'],
            'name'          => $product ? $product->get_name() : '',
            'qty'           => (float) $item['quantity'],
            'unit_price'    => $product ? (float) wc_get_price_to_display($product, ['price' => $product->get_price()]) : 0,
            'line_subtotal' => (float) $item['line_subtotal'],
            'line_total'    => (float) $item['line_total'],
        ];
    }

    $fees = [];
    foreach ($cart->get_fees() as $fee) {
        $fees[] = ['name' => $fee->name, 'amount' => (float) $fee->amount];
    }

    $rates = [];
    foreach (WC()->shipping()->get_packages() as $package) {
        foreach ((array) ($package['rates'] ?? []) as $rate_id => $rate) {
            $rates[] = [
                'id'    => $rate_id,
                'label' => $rate->get_label(),
                'cost'  => (float) $rate->get_cost(),
            ];
        }
    }

    $coupons = [];
    foreach ($cart->get_applied_coupons() as $code) {
        $coupons[] = ['code' => $code, 'amount' => (float) $cart->get_coupon_discount_amount($code)];
    }

    // Trạng thái từng mã đã yêu cầu áp (thành công / thất bại + lý do + chi tiết) cho UI.
    $coupon_status = [];
    foreach (order_creator_state()['coupon_results'] as $code => $result) {
        $coupon_status[] = [
            'code'     => $code,
            'applied'  => !empty($result['applied']),
            'message'  => (string) ($result['message'] ?? ''),
            'discount' => !empty($result['applied']) ? (float) $cart->get_coupon_discount_amount($code) : 0,
            'detail'   => order_creator_coupon_detail($code),
        ];
    }

    $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];

    return [
        'lines'          => $lines,
        'fees'           => $fees,
        'shipping_rates' => $rates,
        'chosen_shipping' => $chosen[0] ?? '',
        'coupons'        => $coupons,
        'coupon_status'  => $coupon_status,
        'subtotal'       => (float) $cart->get_subtotal(),
        'discount_total' => (float) $cart->get_discount_total(),
        'shipping_total' => (float) $cart->get_shipping_total(),
        'fee_total'      => (float) $cart->get_fee_total(),
        'tax_total'      => (float) $cart->get_total_tax(),
        'total'          => (float) $cart->get_total('edit'),
    ];
}

/** Tắt toàn bộ email giao dịch WooCommerce cho request hiện tại. */
function order_creator_disable_emails(): void
{
    $ids = [
        'new_order', 'cancelled_order', 'failed_order',
        'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order',
        'customer_refunded_order', 'customer_invoice', 'customer_note',
        'customer_reset_password', 'customer_new_account',
    ];
    foreach ($ids as $id) {
        add_filter("woocommerce_email_enabled_{$id}", '__return_false', 99);
    }
}

/** Status cho đơn nháp: ưu tiên 'checkout-draft' của WC, fallback 'pending'. */
function order_creator_draft_status(): string
{
    return get_post_status_object('wc-checkout-draft') ? 'checkout-draft' : 'pending';
}

/** Email mặc định TẮT khi tạo/sửa đơn; chỉ gửi khi suppress_email === false. */
function order_creator_should_suppress_email(array $payload): bool
{
    if (!empty($payload['draft'])) {
        return true;
    }
    if (!array_key_exists('suppress_email', $payload)) {
        return true; // mặc định tắt
    }
    return (bool) $payload['suppress_email'];
}

/** Gán địa chỉ billing/shipping lên đơn từ payload. */
function order_creator_apply_addresses(WC_Order $order, array $payload): void
{
    $billing  = isset($payload['billing']) && is_array($payload['billing']) ? $payload['billing'] : [];
    $shipping = isset($payload['shipping']) && is_array($payload['shipping']) ? $payload['shipping'] : [];
    if (empty($shipping)) {
        $shipping = $billing;
    }
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $f) {
        $bsetter = 'set_billing_' . $f;
        $ssetter = 'set_shipping_' . $f;
        if (is_callable([$order, $bsetter])) {
            $order->{$bsetter}(sanitize_text_field($billing[$f] ?? ''));
        }
        if (is_callable([$order, $ssetter])) {
            $order->{$ssetter}(sanitize_text_field($shipping[$f] ?? ''));
        }
    }
    if ($order->get_billing_country() === '') {
        $order->set_billing_country('VN');
    }
    if ($order->get_shipping_country() === '') {
        $order->set_shipping_country('VN');
    }
}

/** Đồng bộ duy nhất thông tin billing/shipping chuẩn khi nhân viên chủ động chọn lưu. */
function order_creator_save_customer_addresses(array $payload): void
{
    if (empty($payload['save_customer_addresses'])) {
        return;
    }
    $user_id = absint($payload['customer_id'] ?? 0);
    if ($user_id <= 0 || !get_user_by('id', $user_id)) {
        return;
    }
    $billing = isset($payload['billing']) && is_array($payload['billing']) ? $payload['billing'] : [];
    $shipping = isset($payload['shipping']) && is_array($payload['shipping']) ? $payload['shipping'] : $billing;
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
        update_user_meta($user_id, 'billing_' . $field, sanitize_text_field($billing[$field] ?? ''));
        update_user_meta($user_id, 'shipping_' . $field, sanitize_text_field($shipping[$field] ?? ''));
    }
}

/** Lưu địa chỉ mặc định theo thao tác xác nhận riêng, không cần tạo đơn. */
add_action('wp_ajax_order_creator_save_customer_addresses', function () {
    order_creator_verify_ajax();
    $payload = order_creator_get_payload();
    $user_id = absint($payload['customer_id'] ?? 0);
    if ($user_id <= 0 || !get_user_by('id', $user_id)) {
        wp_send_json_error(['message' => 'Hãy chọn khách hàng trước khi lưu địa chỉ.'], 400);
    }

    $payload['save_customer_addresses'] = true;
    order_creator_save_customer_addresses($payload);
    wp_send_json_success(['customer' => order_creator_format_customer(get_user_by('id', $user_id))]);
});

/**
 * Đồng bộ line item / fee / shipping / coupon từ giỏ đã tính sang đơn có sẵn.
 * Dùng cho luồng CHỈNH SỬA (xoá item cũ, dựng lại từ giỏ).
 */
function order_creator_custom_shipping_title(array $payload): string
{
    if (!isset($payload['shipping_cost']) || $payload['shipping_cost'] === '' || !is_numeric($payload['shipping_cost'])) {
        return '';
    }
    return sanitize_text_field((string) ($payload['shipping_title'] ?? ''));
}

function order_creator_shipping_method_parts(string $rate_id): array
{
    $parts = explode(':', wc_clean($rate_id), 2);
    return [
        'method_id'   => $parts[0] ?? '',
        'instance_id' => isset($parts[1]) ? absint($parts[1]) : 0,
    ];
}

function order_creator_add_custom_shipping_item(WC_Order $order, array $payload): void
{
    if (!isset($payload['shipping_cost']) || $payload['shipping_cost'] === '' || !is_numeric($payload['shipping_cost'])) {
        return;
    }
    $method = order_creator_shipping_method_parts((string) ($payload['shipping_method'] ?? ''));
    if ($method['method_id'] === '') {
        return;
    }
    $cost = max(0, (float) $payload['shipping_cost']);
    $taxes = wc_tax_enabled() ? WC_Tax::calc_shipping_tax($cost, WC_Tax::get_shipping_tax_rates()) : [];
    $item = new WC_Order_Item_Shipping();
    $item->set_props([
        'method_title' => order_creator_custom_shipping_title($payload) ?: $method['method_id'],
        'method_id'    => $method['method_id'],
        'instance_id'  => $method['instance_id'],
        'total'        => wc_format_decimal($cost),
        'taxes'        => $taxes,
    ]);
    $order->add_item($item);
}

function order_creator_apply_custom_shipping_title(WC_Order $order, array $payload): void
{
    $title = order_creator_custom_shipping_title($payload);
    if ($title === '') {
        return;
    }
    $has_shipping_item = false;
    foreach ($order->get_items('shipping') as $item) {
        if ($item instanceof WC_Order_Item_Shipping) {
            $has_shipping_item = true;
            $item->set_method_title($title);
            $item->save();
        }
    }
    if (!$has_shipping_item) {
        order_creator_add_custom_shipping_item($order, $payload);
    }
}

function order_creator_sync_order_from_cart(WC_Order $order, array $payload): void
{
    foreach ($order->get_items(['line_item', 'fee', 'shipping', 'coupon', 'tax']) as $item_id => $item) {
        $order->remove_item($item_id);
    }

    foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
        $product = $values['data'];
        $item = new WC_Order_Item_Product();
        $item->set_props([
            'quantity'     => $values['quantity'],
            'variation'    => $values['variation'],
            'subtotal'     => $values['line_subtotal'],
            'total'        => $values['line_total'],
            'subtotal_tax' => $values['line_subtotal_tax'],
            'total_tax'    => $values['line_tax'],
            'taxes'        => $values['line_tax_data'],
        ]);
        if ($product instanceof WC_Product) {
            $item->set_product($product);
            $item->set_name($product->get_name());
        }
        // Cho plugin (lucky-wheel, B2B...) lưu meta dòng như checkout thật.
        do_action('woocommerce_checkout_create_order_line_item', $item, $cart_item_key, $values, $order);
        $order->add_item($item);
    }

    foreach (WC()->cart->get_fees() as $fee) {
        $item = new WC_Order_Item_Fee();
        $item->set_props([
            'name'      => $fee->name,
            'tax_class' => $fee->taxable ? $fee->tax_class : 0,
            'amount'    => $fee->amount,
            'total'     => $fee->total,
            'total_tax' => $fee->tax,
            'taxes'     => ['total' => $fee->tax_data],
        ]);
        $order->add_item($item);
    }

    $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];
    $custom_shipping_title = order_creator_custom_shipping_title($payload);
    foreach (WC()->shipping()->get_packages() as $package_key => $package) {
        $rate_id = $chosen[$package_key] ?? '';
        if ($rate_id !== '' && (isset($package['rates'][$rate_id]) || $custom_shipping_title !== '')) {
            $rate = $package['rates'][$rate_id] ?? null;
            $method = $rate ? ['method_id' => $rate->get_method_id(), 'instance_id' => $rate->get_instance_id()] : order_creator_shipping_method_parts($rate_id);
            $item = new WC_Order_Item_Shipping();
            $item->set_props([
                'method_title' => $custom_shipping_title !== '' ? $custom_shipping_title : $rate->get_label(),
                'method_id'    => $method['method_id'],
                'instance_id'  => $method['instance_id'],
                'total'        => wc_format_decimal($rate ? $rate->get_cost() : max(0, (float) ($payload['shipping_cost'] ?? 0))),
                'taxes'        => $rate ? $rate->get_taxes() : (wc_tax_enabled() ? WC_Tax::calc_shipping_tax(max(0, (float) ($payload['shipping_cost'] ?? 0)), WC_Tax::get_shipping_tax_rates()) : []),
            ]);
            if ($rate) {
                foreach ($rate->get_meta_data() as $key => $value) {
                    $item->add_meta_data($key, $value, true);
                }
            }
            $order->add_item($item);
        }
    }

    foreach (WC()->cart->get_coupons() as $code => $coupon) {
        $item = new WC_Order_Item_Coupon();
        $item->set_props([
            'code'         => $code,
            'discount'     => WC()->cart->get_coupon_discount_amount($code),
            'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
        ]);
        $item->add_meta_data('coupon_data', $coupon->get_data());
        $order->add_item($item);
    }
}

function order_creator_order_stock_was_reduced(WC_Order $order): bool
{
    $data_store = WC_Data_Store::load('order');
    if (is_callable([$data_store, 'get_stock_reduced'])) {
        return (bool) $data_store->get_stock_reduced($order->get_id());
    }

    return wc_string_to_bool((string) $order->get_meta('_order_stock_reduced', true));
}

function order_creator_set_order_stock_reduced(WC_Order $order, bool $reduced): void
{
    $data_store = WC_Data_Store::load('order');
    if (is_callable([$data_store, 'set_stock_reduced'])) {
        $data_store->set_stock_reduced($order->get_id(), $reduced);
        return;
    }

    $order->update_meta_data('_order_stock_reduced', $reduced ? 'yes' : 'no');
    $order->save();
}

function order_creator_order_status_should_reduce_stock(WC_Order $order, bool $is_draft): bool
{
    if ($is_draft) {
        return false;
    }

    $status = $order->get_status();
    $restore_statuses = (array) apply_filters('woocommerce_order_statuses_that_restore_stock', ['cancelled', 'pending', 'failed']);
    $restore_statuses = array_map(static function ($restore_status) {
        return preg_replace('/^wc-/', '', (string) $restore_status);
    }, $restore_statuses);

    return !in_array($status, $restore_statuses, true);
}

function order_creator_restore_reduced_stock_before_edit(WC_Order $order): bool
{
    if (!function_exists('wc_increase_stock_levels') || !order_creator_order_stock_was_reduced($order)) {
        return false;
    }

    wc_increase_stock_levels($order);
    order_creator_set_order_stock_reduced($order, false);
    $order->add_order_note('Hoàn tồn kho dòng cũ trước khi cập nhật đơn từ trang Tạo đơn.', false);
    return true;
}

function order_creator_reduce_stock_after_edit(WC_Order $order, bool $is_draft): void
{
    if (!order_creator_order_status_should_reduce_stock($order, $is_draft) || order_creator_order_stock_was_reduced($order)) {
        return;
    }

    if (function_exists('wc_reduce_stock_levels')) {
        wc_reduce_stock_levels($order);
        order_creator_set_order_stock_reduced($order, true);
    }
}

/** Gán trạng thái / ngày / ghi chú nội bộ cho đơn. */
/**
 * Gắn nguồn đơn = "Quản trị web" (WooCommerce Order Attribution).
 * source_type 'admin' → cột "Nguồn" hiển thị "Web admin" / "Quản trị web".
 */
function order_creator_apply_source(WC_Order $order): void
{
    $order->set_created_via('order_creator');
    $order->update_meta_data('_wc_order_attribution_source_type', 'admin');
    $order->update_meta_data('_wc_order_attribution_utm_source', 'Web admin');
}

function order_creator_apply_status_and_notes(WC_Order $order, array $payload, bool $is_draft, string $action_label): void
{
    order_creator_apply_source($order);
    order_creator_apply_order_meta($order, $payload);
    if (!empty($payload['order_date'])) {
        $ts = strtotime((string) $payload['order_date']);
        if ($ts) {
            $order->set_date_created($ts);
        }
    }
    $status = $is_draft ? order_creator_draft_status() : sanitize_key($payload['status'] ?? 'on-hold');
    if ($status !== '') {
        $order->set_status($status);
    }
    if (!empty($payload['internal_note'])) {
        $order->add_order_note(sanitize_textarea_field($payload['internal_note']), false);
    }
    if ($is_draft) {
        $order->add_order_note('Đơn được lưu dạng nháp.', false);
    }
    $creator = wp_get_current_user();
    $order->add_order_note(sprintf('%s qua trang Tạo đơn (/%s) bởi %s.', $action_label, ORDER_CREATOR_ROUTE, $creator ? $creator->display_name : '—'), false);
}

/** CHỈNH SỬA đơn có sẵn: chạy lại pipeline rồi đồng bộ vào đơn. */
function order_creator_update_order(int $order_id, array $payload): WC_Order
{
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        throw new Exception('Không tìm thấy đơn để chỉnh sửa.');
    }

    $is_draft = !empty($payload['draft']);
    if (order_creator_should_suppress_email($payload)) {
        order_creator_disable_emails();
    }

    order_creator_populate_cart($payload);
    order_creator_calculate_cart_totals();
    if (WC()->cart->is_empty()) {
        throw new Exception('Đơn không có sản phẩm.');
    }

    $order->set_customer_id(absint($payload['customer_id'] ?? 0));
    order_creator_apply_addresses($order, $payload);

    $payment_method = wc_clean($payload['payment_method'] ?? '');
    if ($payment_method !== '') {
        $order->set_payment_method($payment_method);
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        if (isset($gateways[$payment_method])) {
            $order->set_payment_method_title($gateways[$payment_method]->get_title());
        }
    }
    $order->set_customer_note(sanitize_textarea_field($payload['customer_note'] ?? ''));

    order_creator_restore_reduced_stock_before_edit($order);
    order_creator_sync_order_from_cart($order, $payload);
    $order->calculate_totals(true);

    order_creator_apply_status_and_notes($order, $payload, $is_draft, 'Đơn được cập nhật');
    order_creator_save_customer_addresses($payload);
    $order->save();
    order_creator_reduce_stock_after_edit($order, $is_draft);

    return $order;
}

/** Dựng WC_Order từ giỏ đã tính (giữ nguyên giá chính sách). */
function order_creator_build_order(array $payload): WC_Order
{
    $is_draft = !empty($payload['draft']);
    if (order_creator_should_suppress_email($payload)) {
        order_creator_disable_emails();
    }

    order_creator_populate_cart($payload);
    order_creator_calculate_cart_totals();

    if (WC()->cart->is_empty()) {
        throw new Exception('Chưa có sản phẩm nào trong đơn.');
    }

    $billing  = isset($payload['billing']) && is_array($payload['billing']) ? $payload['billing'] : [];
    $shipping = isset($payload['shipping']) && is_array($payload['shipping']) ? $payload['shipping'] : [];
    if (empty($shipping)) {
        $shipping = $billing;
    }

    $data = [
        'payment_method' => wc_clean($payload['payment_method'] ?? 'cod'),
        'order_comments' => sanitize_textarea_field($payload['customer_note'] ?? ''),
    ];
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $f) {
        $data['billing_' . $f]  = sanitize_text_field($billing[$f] ?? '');
        $data['shipping_' . $f] = sanitize_text_field($shipping[$f] ?? '');
    }
    if ($data['billing_country'] === '') {
        $data['billing_country'] = 'VN';
    }
    if ($data['shipping_country'] === '') {
        $data['shipping_country'] = 'VN';
    }

    $order_id = WC()->checkout()->create_order($data);
    if (is_wp_error($order_id)) {
        throw new Exception($order_id->get_error_message());
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        throw new Exception('Không tạo được đơn hàng.');
    }

    // Tiêu đề phương thức thanh toán
    $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
    if (isset($gateways[$data['payment_method']])) {
        $order->set_payment_method_title($gateways[$data['payment_method']]->get_title());
    }

    order_creator_apply_custom_shipping_title($order, $payload);
    order_creator_apply_status_and_notes($order, $payload, $is_draft, 'Đơn được tạo');
    order_creator_save_customer_addresses($payload);
    $order->save();

    return $order;
}

function order_creator_order_meta_text(WC_Order $order, array $keys): string
{
    $values = [];
    foreach ($keys as $key) {
        foreach ($order->get_meta($key, false) as $value) {
            foreach ((array) $value as $entry) {
                if ($entry !== '') {
                    $values[] = (string) $entry;
                }
            }
        }
        $single = $order->get_meta($key, true);
        foreach ((array) $single as $entry) {
            if ($entry !== '') {
                $values[] = (string) $entry;
            }
        }
    }

    return implode(' ', array_unique($values));
}

function order_creator_order_has_quick_shipping(WC_Order $order): bool
{
    $text = order_creator_order_meta_text($order, ['order_handling_status', 'handling_status']);
    $normalized = function_exists('remove_accents') ? remove_accents($text) : $text;
    return preg_match('/giao\s*nhanh/i', $normalized) === 1;
}

function order_creator_order_is_local_delivery(WC_Order $order): bool
{
    $address = implode(' ', array_filter([
        $order->get_shipping_state(),
        $order->get_shipping_city(),
        $order->get_shipping_address_1(),
        $order->get_billing_state(),
        $order->get_billing_city(),
        $order->get_billing_address_1(),
    ]));
    $normalized = strtolower(function_exists('remove_accents') ? remove_accents($address) : $address);

    return strpos($normalized, 'ha noi') !== false
        || strpos($normalized, 'hanoi') !== false
        || preg_match('/(^|\s)hn($|\s)/', $normalized) === 1;
}

function order_creator_customer_confirmed_cod_order(WC_Order $order): array
{
    if ($order->get_payment_method() !== 'cod') {
        throw new Exception('Chỉ áp dụng cho đơn COD.');
    }
    if (!in_array($order->get_status(), ['on-hold', 'processing'], true)) {
        throw new Exception('Chỉ áp dụng khi đơn đang ở trạng thái Tạm giữ hoặc Đang xử lý.');
    }

    $is_quick = order_creator_order_has_quick_shipping($order);
    $is_local = order_creator_order_is_local_delivery($order);
    $target_status = $is_quick ? 'local-shipping' : 'packaging';
    $reason = $is_quick
        ? 'Khách đã xác nhận COD, đơn có trạng thái xử lý Giao nhanh nên chuyển sang Giao nhanh.'
        : ($is_local
            ? 'Khách đã xác nhận COD, chuyển sang Chuẩn bị bàn giao vận chuyển.'
            : 'Khách đã xác nhận COD ngoại tỉnh, chuyển sang Chuẩn bị bàn giao vận chuyển.');

    $user = wp_get_current_user();
    $order->update_meta_data('_order_creator_customer_confirmed_at', current_time('mysql'));
    $order->update_meta_data('_order_creator_customer_confirmed_by', $user ? (int) $user->ID : 0);
    if ($order->get_status() !== $target_status) {
        $order->update_status($target_status, $reason);
    } else {
        $order->add_order_note($reason, false);
    }
    $order->save();

    $statuses = wc_get_order_statuses();
    return [
        'status'       => $order->get_status(),
        'status_label' => $statuses['wc-' . $order->get_status()] ?? $order->get_status(),
        'is_quick'     => $is_quick,
        'is_local'     => $is_local,
    ];
}

// ================================================================
// AJAX — TÌM SẢN PHẨM
// ================================================================

/** Nhãn tồn kho ngắn gọn cho 1 product/variation. */
function order_creator_stock_label(WC_Product $product): string
{
    if ($product->managing_stock()) {
        $qty = $product->get_stock_quantity();
        return 'Tồn: ' . ($qty === null ? '—' : wc_stock_amount($qty));
    }
    switch ($product->get_stock_status()) {
        case 'outofstock':   return 'Hết hàng';
        case 'onbackorder':  return 'Đặt trước';
        default:             return 'Còn hàng';
    }
}

/** Ảnh thumbnail của product (fallback placeholder). */
function order_creator_product_image(WC_Product $product): string
{
    $id = $product->get_image_id();
    $url = $id ? wp_get_attachment_image_url($id, 'thumbnail') : '';
    if (!$url && $product instanceof WC_Product_Variation) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            $url = wp_get_attachment_image_url($parent->get_image_id(), 'thumbnail');
        }
    }
    return $url ?: wc_placeholder_img_src('thumbnail');
}

/** SP ẩn (hidden visibility) hoặc riêng tư (private status). */
function order_creator_product_is_hidden(WC_Product $product): bool
{
    return in_array($product->get_catalog_visibility(), ['hidden', 'search'], true)
        || $product->get_status() === 'private';
}

/** Giá hiệu lực, fallback về _price khi rule front-end đang ẩn giá sản phẩm. */
function order_creator_product_price(WC_Product $product): float
{
    $price = $product->get_price();
    if (!is_numeric($price)) {
        $price = $product->get_price('edit');
    }
    return is_numeric($price) ? (float) $price : 0.0;
}

/** Chuẩn hoá 1 product → entry cho kết quả tìm/SP mặc định. */
function order_creator_format_product(WC_Product $product): array
{
    $entry = [
        'id'         => $product->get_id(),
        'name'       => $product->get_name(),
        'sku'        => $product->get_sku(),
        'price'      => order_creator_product_price($product),
        'price_html' => wp_strip_all_tags($product->get_price_html()),
        'type'       => $product->get_type(),
        'image'      => order_creator_product_image($product),
        'stock'      => order_creator_stock_label($product),
        'hidden'     => order_creator_product_is_hidden($product),
        'variations' => [],
    ];
    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation instanceof WC_Product_Variation) {
                continue;
            }
            $entry['variations'][] = [
                'id'    => $variation->get_id(),
                'label' => wc_get_formatted_variation($variation, true, false),
                'sku'   => $variation->get_sku(),
                'price' => order_creator_product_price($variation),
                'image' => order_creator_product_image($variation),
                'stock' => order_creator_stock_label($variation),
            ];
        }
    }
    return $entry;
}

/** Sắp SP ẩn/riêng tư xuống cuối (giữ thứ tự gốc trong mỗi nhóm). */
function order_creator_sort_hidden_last(array $entries): array
{
    $visible = [];
    $hidden  = [];
    foreach ($entries as $e) {
        if (!empty($e['hidden'])) {
            $hidden[] = $e;
        } else {
            $visible[] = $e;
        }
    }
    return array_merge($visible, $hidden);
}

add_action('wp_ajax_order_creator_search_products', function () {
    order_creator_verify_ajax();

    $term = isset($_POST['term']) ? wc_clean(wp_unslash($_POST['term'])) : '';
    if ($term === '') {
        wp_send_json_success(['products' => []]);
    }

    // Gồm cả sản phẩm private; hidden lọc sau bằng cờ.
    $ids = wc_get_products([
        'status' => ['publish', 'private'],
        'limit'  => 30,
        's'      => $term,
        'return' => 'ids',
    ]);

    $by_sku = wc_get_product_id_by_sku($term);
    if ($by_sku) {
        $ids[] = $by_sku;
    }
    $ids = array_values(array_unique(array_map('absint', $ids)));

    $products = [];
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if ($product instanceof WC_Product) {
            $products[] = order_creator_format_product($product);
        }
    }

    $settings = order_creator_get_settings();
    if (!empty($settings['group_hidden_last'])) {
        $products = order_creator_sort_hidden_last($products);
    }

    wp_send_json_success(['products' => $products]);
});

add_action('wp_ajax_order_creator_save_settings', function () {
    order_creator_verify_ajax();
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Chỉ Admin được sửa cài đặt.'], 403);
    }
    $payload = order_creator_get_payload();

    $allowed_fields = array_keys(order_creator_customer_field_defs());
    $allowed_customer_info_fields = array_keys(order_creator_customer_info_field_defs());
    $allowed_order_fields = array_keys(order_creator_order_field_defs());
    $settings = [
        'default_products'  => array_values(array_filter(array_map('absint', (array) ($payload['default_products'] ?? [])))),
        'show_image_stock'  => !empty($payload['show_image_stock']),
        'group_hidden_last' => !empty($payload['group_hidden_last']),
        'hide_manual_price' => !empty($payload['hide_manual_price']),
        'hide_line_discount' => !empty($payload['hide_line_discount']),
        'customer_fields'   => array_values(array_intersect($allowed_fields, array_map('sanitize_key', (array) ($payload['customer_fields'] ?? [])))),
        'customer_info_fields' => array_values(array_intersect($allowed_customer_info_fields, array_map('sanitize_key', (array) ($payload['customer_info_fields'] ?? [])))),
        'order_fields'      => array_values(array_intersect($allowed_order_fields, array_map('sanitize_key', (array) ($payload['order_fields'] ?? [])))),
    ];
    update_option('order_creator_settings', $settings);
    wp_send_json_success(['settings' => $settings]);
});

// ================================================================
// AJAX — TÌM / TẠO KHÁCH HÀNG
// ================================================================

function order_creator_phone_variants(string $raw): array
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return [];
    }
    $variants = [$digits];
    if (strpos($digits, '84') === 0) {
        $variants[] = '0' . substr($digits, 2);
        $variants[] = '+84' . substr($digits, 2);
    } elseif (strpos($digits, '0') === 0) {
        $variants[] = '84' . substr($digits, 1);
        $variants[] = '+84' . substr($digits, 1);
    }
    return array_values(array_unique(array_filter($variants)));
}

function order_creator_user_phone_meta_query(string $term): array
{
    $variants = order_creator_phone_variants($term);
    if ($variants === []) {
        return [];
    }

    $queries = ['relation' => 'OR'];
    foreach ($variants as $variant) {
        foreach (['billing_phone', 'shipping_phone'] as $phone_meta_key) {
            $queries[] = [
                'key'     => $phone_meta_key,
                'value'   => $variant,
                'compare' => '=',
            ];
        }
    }

    $digits = preg_replace('/\D+/', '', $term) ?: '';
    if (strlen($digits) >= 7) {
        $tail = substr($digits, -7);
        foreach (['billing_phone', 'shipping_phone'] as $phone_meta_key) {
            $queries[] = [
                'key'     => $phone_meta_key,
                'value'   => $tail,
                'compare' => 'LIKE',
            ];
        }
    }

    return count($queries) > 1 ? $queries : [];
}

function order_creator_is_phone_search(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value) ?: '';
    return strlen($digits) >= 6;
}

function order_creator_customer_has_role_fragment(WP_User $user, array $needles): bool
{
    $role_names = wp_roles()->get_names();
    foreach ((array) $user->roles as $role) {
        $haystacks = [strtolower((string) $role)];
        if (isset($role_names[$role])) {
            $haystacks[] = strtolower((string) $role_names[$role]);
        }
        foreach ($needles as $needle) {
            $needle = strtolower((string) $needle);
            if ($needle === '') {
                continue;
            }
            foreach ($haystacks as $haystack) {
                if (strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
        }
    }
    return false;
}

function order_creator_latest_customer_payment_method(int $user_id): string
{
    static $cache = [];
    if ($user_id <= 0 || !function_exists('wc_get_orders')) {
        return '';
    }
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }
    $orders = wc_get_orders([
        'customer_id' => $user_id,
        'limit'       => 1,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'return'      => 'objects',
    ]);
    $order = $orders[0] ?? null;
    $cache[$user_id] = $order instanceof WC_Order ? (string) $order->get_payment_method() : '';
    return $cache[$user_id];
}

function order_creator_default_customer_payment_method(WP_User $user): string
{
    if (order_creator_customer_has_role_fragment($user, ['dai-ly', 'wholesale'])) {
        return 'bacs';
    }
    return order_creator_latest_customer_payment_method((int) $user->ID);
}

function order_creator_format_customer(WP_User $user): array
{
    $addr = function (string $type) use ($user): array {
        $out = [];
        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email'] as $f) {
            $out[$f] = (string) get_user_meta($user->ID, $type . '_' . $f, true);
        }
        return $out;
    };

    $custom_fields = [];
    foreach (order_creator_customer_custom_field_defs() as $key => $def) {
        $custom_fields[$key] = (string) get_user_meta($user->ID, $key, true);
    }
    $customer_info = [];
    foreach (order_creator_customer_info_field_defs() as $key => $field) {
        $customer_info[$key] = (string) get_user_meta($user->ID, $field['meta_key'], true);
    }

    return [
        'id'          => $user->ID,
        'name'        => $user->display_name,
        'username'    => $user->user_login,
        'email'       => $user->user_email,
        'phone'       => (string) get_user_meta($user->ID, 'billing_phone', true),
        'roles'       => array_values($user->roles),
        'default_payment_method' => order_creator_default_customer_payment_method($user),
        'billing'     => $addr('billing'),
        'shipping'    => $addr('shipping'),
        'custom_fields' => $custom_fields,
        'customer_info' => $customer_info,
    ];
}

add_action('wp_ajax_order_creator_search_customers', function () {
    order_creator_verify_ajax();

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    if ($term === '') {
        wp_send_json_success(['customers' => []]);
    }

    $found = [];

    if (is_email($term)) {
        $user = get_user_by('email', $term);
        if ($user) {
            $found[$user->ID] = $user;
        }
    } elseif (preg_match('/^#?(\d+)$/', $term, $m) && !preg_match('/^\+?\d{6,}$/', preg_replace('/^#/', '', $term))) {
        $user = get_user_by('id', absint($m[1]));
        if ($user) {
            $found[$user->ID] = $user;
        }
    }

    // Số điện thoại: tìm trong cả billing_phone và shipping_phone của tài khoản.
    if (order_creator_is_phone_search($term)) {
        $meta_query = order_creator_user_phone_meta_query($term);
        if ($meta_query !== []) {
            $q = new WP_User_Query([
                'meta_query' => $meta_query,
                'number'     => 20,
                'fields'     => 'all',
            ]);
            foreach ($q->get_results() as $user) {
                $found[$user->ID] = $user;
            }
        }
    }

    // Tên / login / email (mờ)
    if (!order_creator_is_phone_search($term) && count($found) < 10) {
        $q = new WP_User_Query([
            'search'         => '*' . esc_attr($term) . '*',
            'search_columns' => ['user_login', 'user_email', 'user_nicename', 'display_name'],
            'number'         => 10,
        ]);
        foreach ($q->get_results() as $user) {
            $found[$user->ID] = $user;
        }
    }

    $customers = [];
    foreach ($found as $user) {
        $customers[] = order_creator_format_customer($user);
    }

    wp_send_json_success(['customers' => $customers]);
});

// ================================================================
// AJAX — TÌM ĐƠN ĐỂ CHỈNH SỬA / COPY
// ================================================================

add_action('wp_ajax_order_creator_customer_history', function () {
    order_creator_verify_ajax();
    $customer_id = absint($_POST['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        wp_send_json_error(['message' => 'Chưa chọn khách hàng.']);
    }
    $orders = wc_get_orders(['customer_id' => $customer_id, 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC']);
    $rows = [];
    $products = [];
    foreach ($orders as $order) {
        if (!$order instanceof WC_Order) { continue; }
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $entry = ['product_id' => $product_id, 'variation_id' => $variation_id, 'name' => $item->get_name(), 'qty' => (float) $item->get_quantity()];
            $items[] = $entry;
            $key = $product_id . ':' . $variation_id;
            if (!isset($products[$key])) { $products[$key] = $entry; }
            else { $products[$key]['qty'] += $entry['qty']; }
        }
        $address_html = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
        $address_lines = array_values(array_filter(array_map('trim', preg_split('/\s*<br\s*\/?\s*>\s*/i', $address_html))));
        $rows[] = [
            'id' => $order->get_id(),
            'number' => (string) (function_exists('change_order_number') ? change_order_number($order->get_id()) : $order->get_order_number()),
            'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : '',
            'address' => implode("\n", array_map('wp_strip_all_tags', $address_lines)),
            'address_lines' => array_map('wp_strip_all_tags', $address_lines),
            'items' => $items,
            'total' => (float) $order->get_total(),
            'edit_url' => $order->get_edit_order_url(),
        ];
    }
    wp_send_json_success(['orders' => $rows, 'products' => array_values($products)]);
});

function order_creator_normalize_order_search_term(string $term): string
{
    $term = trim($term);
    return preg_replace('/^(?:#|P0|P1)+/i', '', $term) ?? $term;
}

function order_creator_add_order_search_candidate(array &$orders, string $term): bool
{
    if (!ctype_digit($term)) {
        return false;
    }

    $order = wc_get_order(absint($term));
    if (!$order instanceof WC_Order) {
        return false;
    }

    $orders[$order->get_id()] = $order;
    return true;
}

add_action('wp_ajax_order_creator_search_orders', function () {
    order_creator_verify_ajax();

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    if (strlen($term) < 2) {
        wp_send_json_success(['orders' => []]);
    }

    $orders = [];
    $normalized_term = order_creator_normalize_order_search_term($term);
    if ($normalized_term !== '') {
        $found_by_id = order_creator_add_order_search_candidate($orders, $normalized_term);
        if (!$found_by_id && ctype_digit($normalized_term) && strlen($normalized_term) > 2) {
            order_creator_add_order_search_candidate($orders, substr($normalized_term, 0, -2));
        }
    }

    $matches = wc_get_orders([
        'limit'   => 20,
        'orderby' => 'date',
        'order'   => 'DESC',
        'search'  => '*' . ($normalized_term !== '' ? $normalized_term : $term) . '*',
    ]);
    foreach ($matches as $order) {
        if ($order instanceof WC_Order) {
            $orders[$order->get_id()] = $order;
        }
    }

    $result = [];
    foreach ($orders as $order) {
        $result[] = [
            'id'       => $order->get_id(),
            'number'   => (string) (function_exists('change_order_number') ? change_order_number($order->get_id()) : $order->get_order_number()),
            'customer' => trim($order->get_formatted_billing_full_name()) ?: __('Guest customer', 'woocommerce'),
            'phone'    => $order->get_billing_phone(),
            'total'    => (float) $order->get_total(),
            'status'   => wc_get_order_status_name($order->get_status()),
            'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y H:i') : '',
            'edit_url' => $order->get_edit_order_url(),
            'invoice_url' => add_query_arg([
                'action'   => 'order_creator_invoice_preview',
                'order_id' => $order->get_id(),
                'nonce'    => wp_create_nonce(ORDER_CREATOR_NONCE),
            ], admin_url('admin-ajax.php')),
        ];
    }

    wp_send_json_success(['orders' => $result]);
});

/** Tạo HOẶC cập nhật khách hàng từ form popup. */
add_action('wp_ajax_order_creator_create_customer', function () {
    order_creator_verify_ajax();
    $payload = order_creator_get_payload();

    $user_id_existing = absint($payload['user_id'] ?? 0); // >0 = cập nhật (quản lý địa chỉ)
    $first = sanitize_text_field($payload['first_name'] ?? '');
    $last  = sanitize_text_field($payload['last_name'] ?? '');
    $email = sanitize_email($payload['email'] ?? '');
    $phone = sanitize_text_field($payload['phone'] ?? '');
    $role  = sanitize_key($payload['role'] ?? 'customer');
    $username = sanitize_user($payload['username'] ?? '', true);

    // Whitelist role để tránh nâng quyền.
    $editable_roles = function_exists('get_editable_roles') ? array_keys(get_editable_roles()) : array_keys(wp_roles()->get_names());
    if (!in_array($role, $editable_roles, true)) {
        $role = 'customer';
    }

    $address = [
        'first_name' => $first,
        'last_name'  => $last,
        'phone'      => $phone,
        'address_1'  => sanitize_text_field($payload['address_1'] ?? ''),
        'address_2'  => sanitize_text_field($payload['address_2'] ?? ''),
        'city'       => sanitize_text_field($payload['city'] ?? ''),
        'state'      => sanitize_text_field($payload['state'] ?? ''),
        'postcode'   => sanitize_text_field($payload['postcode'] ?? ''),
        'country'    => sanitize_text_field($payload['country'] ?? 'VN'),
    ];

    if ($user_id_existing > 0) {
        // ----- Cập nhật khách hiện có (quản lý địa chỉ) -----
        $user_id = $user_id_existing;
        $update = ['ID' => $user_id];
        if ($first !== '' || $last !== '') {
            $update['display_name'] = trim($first . ' ' . $last);
        }
        if ($email !== '' && $email !== (get_user_by('id', $user_id)->user_email ?? '')) {
            if (email_exists($email) && email_exists($email) !== $user_id) {
                wp_send_json_error(['message' => 'Email đã thuộc tài khoản khác.']);
            }
            $update['user_email'] = $email;
        }
        if (isset($payload['role']) && $role !== '') {
            $update['role'] = $role;
        }
        $res = wp_update_user($update);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
    } else {
        // ----- Tạo khách mới -----
        if ($email === '') {
            $phone_digits = preg_replace('/\D+/', '', $phone);
            if (substr($phone_digits, 0, 2) === '00') {
                $phone_digits = substr($phone_digits, 2);
            }
            if (substr($phone_digits, 0, 1) === '0') {
                $phone_digits = '84' . substr($phone_digits, 1);
            } elseif (substr($phone_digits, 0, 2) !== '84') {
                $phone_digits = '84' . $phone_digits;
            }
            if (!preg_match('/^84\d{8,10}$/', $phone_digits)) {
                wp_send_json_error(['message' => 'Cần email hoặc SĐT hợp lệ để tạo tài khoản khách hàng.']);
            }
            $email = $phone_digits . '@sms.hithean.com';
        }
        if (email_exists($email)) {
            wp_send_json_error(['message' => 'Email đã tồn tại trong hệ thống.']);
        }
        if ($username === '') {
            $username = $email;
        }
        if (username_exists($username)) {
            $username .= '_' . wp_generate_password(4, false, false);
        }
        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(16),
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last) ?: $phone,
            'role'         => $role,
        ]);
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }
    }

    // Lưu billing address
    foreach ($address as $key => $value) {
        update_user_meta($user_id, 'billing_' . $key, $value);
    }
    if ($email !== '') {
        update_user_meta($user_id, 'billing_email', $email);
    }
    foreach (order_creator_customer_custom_field_defs() as $key => $def) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = wp_unslash($payload[$key]);
        update_user_meta($user_id, $key, $def['type'] === 'email' ? sanitize_email($value) : ($def['type'] === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value)));
    }
    // Đồng bộ shipping nếu được yêu cầu
    if (!empty($payload['copy_to_shipping'])) {
        foreach ($address as $key => $value) {
            update_user_meta($user_id, 'shipping_' . $key, $value);
        }
    }

    wp_send_json_success(['customer' => order_creator_format_customer(get_user_by('id', $user_id))]);
});

// ================================================================
// AJAX — AI BÓC TÁCH THÔNG TIN KHÁCH (popup Khách hàng mới)
// ================================================================

/**
 * Cấu hình AI riêng cho feature bóc tách khách hàng.
 * Mặc định lấy từ trang Settings → Cài đặt ERP (mục AI); override qua wp-config.php:
 *   define('ORDER_CREATOR_AI_PROVIDER', 'claude'); // claude | gemini | openai | auto
 *   define('ORDER_CREATOR_AI_MODEL', 'claude-haiku-4-5');
 * hoặc qua filter 'order_creator_ai_config'. API key: CLAUDE_API_KEY /
 * GEMINI_API_KEY / OPENAI_API_KEY trong wp-config.php.
 */
function order_creator_ai_config(): array
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';

    return apply_filters('order_creator_ai_config', [
        'provider' => defined('ORDER_CREATOR_AI_PROVIDER') ? ORDER_CREATOR_AI_PROVIDER : theme_ai_default_provider(),
        'model'    => defined('ORDER_CREATOR_AI_MODEL') ? ORDER_CREATOR_AI_MODEL : theme_ai_default_model(),
    ]);
}

add_action('wp_ajax_order_creator_ai_extract_customer', function () {
    order_creator_verify_ajax();

    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('order_creator_ai_extract_customer')) {
        wp_send_json_error(['message' => 'Tính năng AI đang tắt trong Cài đặt ERP.'], 403);
    }

    $text = sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''));

    $image = null;
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        if (!empty($_FILES['image']['error'])) {
            wp_send_json_error(['message' => 'Tải ảnh lên thất bại.']);
        }
        if ((int) $_FILES['image']['size'] > 5 * MB_IN_BYTES) {
            wp_send_json_error(['message' => 'Ảnh vượt quá 5MB.']);
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($_FILES['image']['tmp_name']) : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            wp_send_json_error(['message' => 'Chỉ hỗ trợ ảnh JPG/PNG/WebP/GIF.']);
        }
        $image = ['path' => (string) $_FILES['image']['tmp_name'], 'mime_type' => $mime];
    }

    if ($text === '' && !$image) {
        wp_send_json_error(['message' => 'Dán dữ liệu hoặc chọn ảnh trước khi bóc tách.']);
    }

    require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
    require_once get_stylesheet_directory() . '/custom-functions/core/vn-address.php';

    $system = 'Bạn là công cụ bóc tách thông tin khách hàng cho cửa hàng online Việt Nam. '
        . 'Từ dữ liệu được cung cấp (tin nhắn, comment, ảnh chụp màn hình chat, phiếu ship...), trích xuất thông tin khách và trả về DUY NHẤT một JSON object với đúng các khóa: '
        . 'first_name, last_name, email, phone, address_1, address_2, city, state. '
        . 'Địa chỉ dùng cấu trúc hành chính VN MỚI (sau sáp nhập 07/2025, 2 cấp, không còn quận/huyện): '
        . 'state = tên tỉnh/thành, chỉ chọn trong danh sách: ' . implode('; ', theme_vn_address_provinces()) . '. '
        . 'city = tên phường/xã theo cấu trúc mới kèm tiền tố, VD "Phường Ba Đình", "Xã Tiên Lữ" — nếu địa chỉ gốc ghi theo cấu trúc cũ (có quận/huyện), quy đổi sang phường/xã mới khi biết chắc, không chắc thì giữ nguyên tên phường/xã gốc. '
        . 'Quy tắc khác: first_name = tên gọi (VD "Hằng" trong "Nguyễn Thị Hằng"); last_name = họ + tên đệm (VD "Nguyễn Thị"); '
        . 'phone = SĐT Việt Nam, giữ số 0 đầu, chỉ gồm chữ số; '
        . 'address_1 = số nhà + tên đường/thôn/xóm; address_2 = thông tin bổ sung (tòa nhà, ngõ ngách, quận/huyện cũ...). '
        . 'Trường không tìm thấy → chuỗi rỗng. Không markdown, không giải thích, chỉ trả về JSON.';

    $prompt = $text !== '' ? "Dữ liệu khách hàng:\n" . $text : 'Bóc tách thông tin khách hàng từ ảnh đính kèm.';
    if ($image && $text !== '') {
        $prompt .= "\n(Kết hợp thêm thông tin trong ảnh đính kèm nếu có.)";
    }

    $cfg = order_creator_ai_config();
    $raw = $image
        ? theme_ai_call_provider_with_documents($cfg['provider'], $system, $prompt, [$image], 1024, 90, $cfg['model'])
        : theme_ai_call_provider($cfg['provider'], $system, [['role' => 'user', 'content' => $prompt]], 1024, $cfg['model']);

    if (is_wp_error($raw)) {
        wp_send_json_error(['message' => $raw->get_error_message()]);
    }

    $data = theme_ai_parse_json_object($raw);
    if (is_wp_error($data)) {
        wp_send_json_error(['message' => $data->get_error_message()]);
    }

    $fields = [];
    foreach (['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state'] as $key) {
        $value = isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : '';
        $fields[$key] = $key === 'email' ? sanitize_email($value) : sanitize_text_field($value);
    }

    // Chuẩn hóa về danh mục hành chính: state → mã tỉnh, city → tên phường/xã đúng danh mục.
    $unmatched = [];
    $province  = theme_vn_address_match_province($fields['state']);
    if ($fields['state'] !== '' && !$province) {
        $unmatched['state'] = $fields['state'];
    }
    $fields['state'] = $province['code'] ?? '';

    $ward = $province ? theme_vn_address_match_ward($province['code'], $fields['city']) : [];
    if ($fields['city'] !== '' && !$ward) {
        $unmatched['city'] = $fields['city'];
    }
    $fields['city'] = $ward['name'] ?? '';

    wp_send_json_success(['fields' => $fields, 'unmatched' => $unmatched]);
});

/** Danh sách phường/xã theo tỉnh cho dropdown cascade trong popup khách hàng. */
add_action('wp_ajax_order_creator_vn_wards', function () {
    order_creator_verify_ajax();

    require_once get_stylesheet_directory() . '/custom-functions/core/vn-address.php';

    $matp  = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) wp_unslash($_POST['matp'] ?? '')));
    $wards = [];
    foreach (theme_vn_address_wards($matp) as $code => $name) {
        $wards[] = ['code' => (string) $code, 'name' => $name];
    }

    wp_send_json_success(['wards' => $wards]);
});

// ================================================================
// AJAX — TÍNH LẠI (PREVIEW) & TẠO ĐƠN
// ================================================================

add_action('wp_ajax_order_creator_recalculate', function () {
    order_creator_verify_ajax();
    $payload = order_creator_get_payload();

    try {
        $result = order_creator_with_customer_context(absint($payload['customer_id'] ?? 0), function () use ($payload) {
            order_creator_populate_cart($payload);
            order_creator_calculate_cart_totals();
            return order_creator_snapshot_cart();
        });
        wp_send_json_success($result);
    } catch (Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

add_action('wp_ajax_order_creator_create_order', function () {
    order_creator_verify_ajax();
    $payload = order_creator_get_payload();

    try {
        $order = order_creator_with_customer_context(absint($payload['customer_id'] ?? 0), function () use ($payload) {
            $edit_id = absint($payload['order_id'] ?? 0);
            return $edit_id > 0
                ? order_creator_update_order($edit_id, $payload)
                : order_creator_build_order($payload);
        });

        $order_number = function_exists('change_order_number') ? change_order_number($order->get_id()) : $order->get_order_number();

        wp_send_json_success([
            'order_id'      => $order->get_id(),
            'order_number'  => (string) $order_number,
            'total'         => (float) $order->get_total(),
            'edit_url'      => $order->get_edit_order_url(),
            'invoice_url'   => add_query_arg([
                'action'   => 'order_creator_invoice_preview',
                'order_id' => $order->get_id(),
                'nonce'    => wp_create_nonce(ORDER_CREATOR_NONCE),
            ], admin_url('admin-ajax.php')),
            'payment_total' => wc_format_decimal($order->get_total(), 0),
            'payment_method'=> $order->get_payment_method(),
            'status'        => $order->get_status(),
            'phone'         => $order->get_billing_phone(),
        ]);
    } catch (Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

add_action('wp_ajax_order_creator_customer_confirmed', function () {
    order_creator_verify_ajax();

    $order_id = absint($_POST['order_id'] ?? 0);
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order instanceof WC_Order) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.']);
    }

    try {
        $result = order_creator_customer_confirmed_cod_order($order);
        wp_send_json_success(array_merge($result, [
            'order_id'      => $order->get_id(),
            'order_number'  => function_exists('change_order_number') ? (string) change_order_number($order->get_id()) : (string) $order->get_order_number(),
            'total'         => (float) $order->get_total(),
            'edit_url'      => $order->get_edit_order_url(),
            'payment_method'=> $order->get_payment_method(),
        ]));
    } catch (Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});

// ================================================================
// AJAX — NẠP ĐƠN CÓ SẴN ĐỂ CHỈNH SỬA
// ================================================================

add_action('wp_ajax_order_creator_load_order', function () {
    order_creator_verify_ajax();

    $order_id = absint($_POST['order_id'] ?? 0);
    $order    = $order_id ? wc_get_order($order_id) : false;
    if (!$order instanceof WC_Order) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.']);
    }

    $items = [];
    foreach ($order->get_items() as $item) {
        $items[] = [
            'product_id'    => (int) $item->get_product_id(),
            'variation_id'  => (int) $item->get_variation_id(),
            'name'          => $item->get_name(),
            'qty'           => (float) $item->get_quantity(),
            'manual_price'  => '',
            'line_discount' => 0,
        ];
    }

    $fees = [];
    foreach ($order->get_items('fee') as $fee) {
        $fees[] = ['name' => $fee->get_name(), 'amount' => (float) $fee->get_total()];
    }

    $shipping_method = '';
    $shipping_title = '';
    $shipping_cost = '';
    foreach ($order->get_items('shipping') as $ship) {
        $instance = $ship->get_instance_id();
        $shipping_method = $ship->get_method_id() . ($instance !== '' ? ':' . $instance : '');
        $shipping_title = $ship->get_method_title();
        $shipping_cost = (string) $ship->get_total();
        break;
    }

    $addr = function (string $type) use ($order): array {
        $out = [];
        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email'] as $f) {
            $getter = 'get_' . $type . '_' . $f;
            $out[$f] = is_callable([$order, $getter]) ? (string) $order->{$getter}() : '';
        }
        return $out;
    };

    $customer = null;
    if ($order->get_customer_id()) {
        $user = get_user_by('id', $order->get_customer_id());
        if ($user) {
            $customer = order_creator_format_customer($user);
        }
    }

    wp_send_json_success([
        'order_id'        => $order->get_id(),
        'order_number'    => function_exists('change_order_number') ? (string) change_order_number($order->get_id()) : (string) $order->get_order_number(),
        'customer'        => $customer,
        'customer_id'     => (int) $order->get_customer_id(),
        'items'           => $items,
        'coupons'         => array_values($order->get_coupon_codes()),
        'fees'            => $fees,
        'shipping_method' => $shipping_method,
        'shipping_title'  => $shipping_title,
        'shipping_cost'   => $shipping_cost,
        'billing'         => $addr('billing'),
        'shipping'        => $addr('shipping'),
        'status'          => $order->get_status(),
        'payment_method'  => $order->get_payment_method(),
        'customer_note'   => $order->get_customer_note(),
        'order_date'      => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d\TH:i') : '',
        'order_meta'      => array_reduce(array_keys(order_creator_order_field_defs()), function ($out, $key) use ($order) { $out[$key] = $order->get_meta($key, true); return $out; }, []),
        'total'           => (float) $order->get_total(),
        'edit_url'        => $order->get_edit_order_url(),
        'invoice_url'     => add_query_arg([
            'action'   => 'order_creator_invoice_preview',
            'order_id' => $order->get_id(),
            'nonce'    => wp_create_nonce(ORDER_CREATOR_NONCE),
        ], admin_url('admin-ajax.php')),
        'payment_total'   => wc_format_decimal($order->get_total(), 0),
        'phone'           => $order->get_billing_phone(),
    ]);
});

// ================================================================
// HÓA ĐƠN ĐỘC LẬP — không dùng template/style email WooCommerce
// ================================================================

function order_creator_order_customer_has_trade_role(WC_Order $order): bool
{
    $user_id = (int) $order->get_customer_id();
    if ($user_id <= 0) {
        return false;
    }

    $user = get_user_by('id', $user_id);
    if (!$user instanceof WP_User) {
        return false;
    }

    $role_names = wp_roles()->roles;
    foreach ((array) $user->roles as $role) {
        $label = isset($role_names[$role]['name']) ? (string) $role_names[$role]['name'] : '';
        $haystack = strtolower($role . ' ' . $label);
        $normalized = function_exists('remove_accents') ? strtolower(remove_accents($haystack)) : $haystack;
        if (strpos($haystack, 'đại lý') !== false || strpos($normalized, 'dai_ly') !== false || strpos($normalized, 'dai ly') !== false || strpos($normalized, 'daily') !== false || strpos($normalized, 'wholesale') !== false) {
            return true;
        }
    }

    return false;
}

function order_creator_order_meta_values(WC_Order $order, string $key): array
{
    $values = [];
    foreach ($order->get_meta($key, false) as $value) {
        foreach ((array) $value as $entry) {
            if ($entry !== '') {
                $values[] = (string) $entry;
            }
        }
    }

    if ($values === []) {
        $single = $order->get_meta($key, true);
        foreach ((array) $single as $entry) {
            if ($entry !== '') {
                $values[] = (string) $entry;
            }
        }
    }

    return array_values(array_unique($values));
}

function order_creator_order_needs_compact_invoice(WC_Order $order): bool
{
    if (order_creator_order_customer_has_trade_role($order)) {
        return true;
    }

    $tokens = [];
    foreach (['handling_notes', 'order_handling_status', 'vat_status'] as $key) {
        foreach (order_creator_order_meta_values($order, $key) as $value) {
            $tokens[] = strtolower(function_exists('remove_accents') ? remove_accents($value) : $value);
        }
    }

    foreach ($tokens as $token) {
        if (strpos($token, 'dropship') !== false || strpos($token, 'vat') !== false || strpos($token, 'xuat vat') !== false) {
            return true;
        }
    }

    return false;
}

function order_creator_invoice_html(WC_Order $order): string
{
    $number = function_exists('change_order_number') ? change_order_number($order->get_id()) : $order->get_order_number();
    $emails = WC()->mailer()->get_emails();
    $invoice = $emails['WC_Email_Customer_Invoice'] ?? null;
    if (!$invoice instanceof WC_Email_Customer_Invoice) {
        return '';
    }
    $invoice->object = $order;
    $invoice->recipient = $order->get_billing_email();
    $additional_content = $invoice->get_additional_content();
    $email_footer_text = (string) get_option('woocommerce_email_footer_text', '');
    if (method_exists($invoice, 'format_string')) {
        $email_footer_text = $invoice->format_string($email_footer_text);
    }
    $logo_url = (string) get_option('woocommerce_email_header_image');
    if ($logo_url === '') {
        $logo_id = (int) get_theme_mod('custom_logo');
        $logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url($logo_id, 'full') : '';
    }
    $logo_url = esc_url($logo_url);
    $brand_html = $logo_url !== '' ? '<div class="oc-invoice-brand"><img src="' . $logo_url . '" alt="' . esc_attr(get_bloginfo('name')) . '"></div>' : '';
    $footer_parts = array_values(array_unique(array_filter([$additional_content, $email_footer_text])));
    $additional_html = $footer_parts === [] ? '' : '<footer class="oc-invoice-additional">' . wp_kses_post(wpautop(wptexturize(implode("\n\n", $footer_parts)))) . '</footer>';

    // Keep the custom email-order-details.php logic, but render additional content in the invoice footer.
    $suppress_email_additional_content = static function (): string {
        return '';
    };
    add_filter('woocommerce_email_additional_content_customer_invoice', $suppress_email_additional_content, PHP_INT_MAX);
    $bacs_gateway = null;
    if ($order->get_payment_method() === 'bacs' && WC()->payment_gateways()) {
        $bacs_gateway = WC()->payment_gateways()->payment_gateways()['bacs'] ?? null;
        if ($bacs_gateway instanceof WC_Gateway_BACS) {
            remove_action('woocommerce_email_before_order_table', [$bacs_gateway, 'email_instructions'], 10);
        }
    }
    $has_custom_bacs_notice = function_exists('devvn_email_instructions');
    if ($has_custom_bacs_notice) {
        remove_action('woocommerce_email_before_order_table', 'devvn_email_instructions', 10);
    }
    $content = $invoice->get_content_html();
    if ($has_custom_bacs_notice) {
        add_action('woocommerce_email_before_order_table', 'devvn_email_instructions', 10, 3);
    }
    if ($bacs_gateway instanceof WC_Gateway_BACS) {
        add_action('woocommerce_email_before_order_table', [$bacs_gateway, 'email_instructions'], 10, 3);
    }
    remove_filter('woocommerce_email_additional_content_customer_invoice', $suppress_email_additional_content, PHP_INT_MAX);
    $content = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $content);
    $content = preg_replace('#<!--\[if.*?\]>.*?<!\[endif\]-->#is', '', $content);
    $content = preg_replace('/\sstyle=(["\']).*?\1/is', '', $content);
    // Bỏ lời chào và lời mời thanh toán mặc định; hóa đơn chỉ hiển thị thông tin đơn.
    $content = preg_replace('#<p\b[^>]*>\s*(?:Xin\s+chào\b|Một\s+đơn\s+hàng\s+đã\s+được\s+tạo\b|Đây\s+là\s+thông\s+tin\s+đơn\s+hàng\b).*?</p>#isu', '', $content);
    $content = preg_replace_callback('#<tfoot\b[^>]*>.*?</tfoot>#is', static function (array $matches): string {
        return preg_replace_callback('#<tr\b([^>]*)>(.*?)</tr>#is', static function (array $row_matches): string {
            $row_text = html_entity_decode(trim(wp_strip_all_tags($row_matches[2])), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
            if (!preg_match('/(?:\btotal\b|tổng)/iu', $row_text) || preg_match('/subtotal/i', $row_text)) {
                return $row_matches[0];
            }

            if (preg_match('/\sclass=(["\'])(.*?)\1/i', $row_matches[1])) {
                $attrs = preg_replace('/\sclass=(["\'])(.*?)\1/i', ' class=$1$2 oc-invoice-total-row$1', $row_matches[1], 1);
            } else {
                $attrs = $row_matches[1] . ' class="oc-invoice-total-row"';
            }

            return '<tr' . $attrs . '>' . $row_matches[2] . '</tr>';
        }, $matches[0]);
    }, $content);
    $customer_note = trim((string) $order->get_customer_note());
    $note_html = $customer_note === '' ? '' : '<section class="oc-invoice-note"><strong>Ghi chú đơn hàng</strong><div>' . nl2br(esc_html($customer_note)) . '</div></section>';
    $table_css = '<style>.oc-invoice .awdr-you-saved-text{display:none!important}.oc-invoice #body_content table.td,.oc-invoice #body_content table.td td,.oc-invoice #body_content table.td th{border:0!important}.oc-invoice #body_content table.td td,.oc-invoice #body_content table.td th{padding:10px 9px!important;vertical-align:top!important}.oc-invoice #body_content table.td th{background:#eee!important;color:#17212b!important}.oc-invoice tr:last-child td{font-weight:normal!important}.oc-invoice table.td tfoot .woocommerce-Price-amount.amount{font-weight:700!important}.oc-invoice table.td tfoot tr.oc-invoice-total-row .woocommerce-Price-amount.amount{font-size:150%!important;font-weight:700!important}.oc-invoice .wc-payment-qr img,.oc-invoice img[src*="qrcode.io.vn"],.oc-invoice img[src*="qr.sepay.vn"]{width:166.667px!important;max-width:100%!important}</style>';
    $main_class = 'oc-invoice';
    if (order_creator_order_needs_compact_invoice($order)) {
        $main_class .= ' oc-invoice--trade';
        $brand_html = '';
        $note_html = '';
        $additional_html = '';
    }

    return '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hóa đơn #' . esc_html($number) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;background:#fff;color:#1f2933;font:14px/1.5 Arial,sans-serif}.oc-invoice{width:min(760px,100%);margin:0 auto;padding:26px 28px;background:#fff}.oc-invoice .awdr-you-saved-text{display:none!important}.oc-invoice-brand{padding:0 0 16px;margin-bottom:18px;border-bottom:2px solid #17212b}.oc-invoice-brand img{display:block;max-width:180px!important;max-height:72px!important;width:auto!important;height:auto!important;object-fit:contain}.oc-invoice #wrapper,.oc-invoice #template_container,.oc-invoice #template_body,.oc-invoice #body_content,.oc-invoice #body_content_inner{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important}.oc-invoice #template_header,.oc-invoice #template_header_image,.oc-invoice #template_footer,.oc-invoice #footer{display:none!important}.oc-invoice table{width:100%!important;border-collapse:collapse!important;margin:16px 0!important;background:#fff!important}.oc-invoice #wrapper td,.oc-invoice #template_container td,.oc-invoice #template_body td,.oc-invoice #body_content td{padding:0!important;border:0!important}.oc-invoice table.td td,.oc-invoice table.td th{padding:10px 9px!important;border:1px solid #d9e0e6!important;vertical-align:top!important}.oc-invoice table.td th{background:#eee!important;color:#17212b!important;border-color:#17212b!important;font-size:12px!important;text-align:left!important}.oc-invoice table.td tr:last-child td{font-weight:700}.oc-invoice p{margin:0 0 12px}.oc-invoice h1,.oc-invoice h2{margin:0 0 12px;color:#17212b}.oc-invoice img{max-width:100%!important;height:auto!important}.oc-invoice .wc-payment-qr{margin:22px auto!important;text-align:center!important}.oc-invoice-note{margin-top:20px;padding:13px 15px;border-left:4px solid #17212b;background:#f3f5f7}.oc-invoice-note strong{display:block;margin-bottom:4px;color:#17212b}.oc-invoice-additional{margin-top:24px;padding-top:14px;border-top:1px solid #d9e0e6;color:#52606d;font-size:12px}.oc-invoice-additional p:last-child{margin-bottom:0}@media print{.oc-invoice{width:100%;padding:0}}</style></head><body><main class="' . esc_attr($main_class) . '">' . $brand_html . $table_css . $content . $note_html . $additional_html . '</main></body></html>';
}

add_action('wp_ajax_order_creator_invoice_preview', function () {
    if (!check_ajax_referer(ORDER_CREATOR_NONCE, 'nonce', false) || !order_creator_can()) {
        wp_die('Không có quyền.', '', ['response' => 403]);
    }
    $order_id = absint($_GET['order_id'] ?? 0);
    $order    = $order_id ? wc_get_order($order_id) : false;
    if (!$order instanceof WC_Order) {
        wp_die('Không tìm thấy đơn hàng.', '', ['response' => 404]);
    }

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo order_creator_invoice_html($order);
    exit;
});

// ================================================================
// ROUTE /tao-don/ — RENDER FULL PAGE
// ================================================================

/** Segment đầu tiên của URL (vd: /tao-don/abc → 'tao-don'). */
function order_creator_first_segment(): string
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $segments = explode('/', $path);
    return $segments[0];
}

function order_creator_is_route(): bool
{
    if (is_admin()) {
        return false;
    }
    return order_creator_first_segment() === ORDER_CREATOR_ROUTE;
}

add_action('template_redirect', function () {
    if (!order_creator_is_route()) {
        return;
    }
    if (!is_user_logged_in()) {
        auth_redirect();
        exit;
    }
    if (!order_creator_can()) {
        wp_die('Bạn không có quyền truy cập trang này.', 'Không có quyền', ['response' => 403]);
    }

    add_filter('show_admin_bar', '__return_false'); // ẩn thanh admin trên trang tạo đơn
    status_header(200);
    nocache_headers();
    order_creator_render_page();
    exit;
}, 5);

/** Chuyển hướng trang plugin Phone Orders (admin) sang trang Tạo đơn của theme. */
add_action('admin_init', function () {
    if (wp_doing_ajax() || ($_GET['page'] ?? '') !== 'phone-orders-for-woocommerce') {
        return;
    }
    if (!order_creator_can()) {
        return;
    }
    $args = isset($_GET['order_id']) && absint($_GET['order_id']) > 0 ? ['order_id' => absint($_GET['order_id'])] : [];
    wp_safe_redirect(add_query_arg($args, home_url('/' . ORDER_CREATOR_ROUTE . '/')));
    exit;
});

function order_creator_assets_uri(string $file): string
{
    // Suy URL từ vị trí thật của file để không phụ thuộc đường dẫn cứng.
    $relative = ltrim(str_replace(get_stylesheet_directory(), '', __DIR__), '/');
    return get_stylesheet_directory_uri() . '/' . $relative . '/assets/' . $file;
}

function order_creator_assets_path(string $file): string
{
    return __DIR__ . '/assets/' . $file;
}

/** Shipping methods enabled in WooCommerce Shipping Zones, for the selector UI. */
function order_creator_configured_shipping_methods(): array
{
    if (!class_exists('WC_Shipping_Zones')) {
        return [];
    }

    $zones = WC_Shipping_Zones::get_zones();
    $zones[] = ['zone_name' => __('Locations not covered by your other zones', 'woocommerce'), 'shipping_methods' => WC_Shipping_Zones::get_zone(0)->get_shipping_methods(true)];
    $methods = [];
    foreach ($zones as $zone) {
        foreach ((array) ($zone['shipping_methods'] ?? []) as $method) {
            if (!$method instanceof WC_Shipping_Method || $method->enabled !== 'yes') {
                continue;
            }
            $id = $method->get_rate_id();
            if (isset($methods[$id])) {
                continue;
            }
            $methods[$id] = [
                'id'    => $id,
                'label' => $method->get_title(),
                'zone'  => (string) ($zone['zone_name'] ?? ''),
            ];
        }
    }

    return array_values($methods);
}

/** Cấu hình truyền sang JS. */
function order_creator_js_config(): array
{
    $gateways = [];
    if (WC()->payment_gateways()) {
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if ($gateway->enabled === 'yes') {
                $gateways[] = ['id' => $id, 'title' => $gateway->get_title()];
            }
        }
    }

    $statuses = [];
    foreach (wc_get_order_statuses() as $key => $label) {
        $statuses[] = ['key' => str_replace('wc-', '', $key), 'label' => $label];
    }

    $roles = [];
    foreach (wp_roles()->get_names() as $role_key => $role_name) {
        $roles[] = ['value' => $role_key, 'label' => translate_user_role($role_name)];
    }

    $settings = order_creator_get_settings();
    $default_products = [];
    foreach ($settings['default_products'] as $pid) {
        $product = wc_get_product($pid);
        if ($product instanceof WC_Product) {
            $default_products[] = order_creator_format_product($product);
        }
    }
    $settings_for_js = [
        'show_image_stock'  => (bool) $settings['show_image_stock'],
        'group_hidden_last' => (bool) $settings['group_hidden_last'],
        'hide_manual_price' => (bool) $settings['hide_manual_price'],
        'hide_line_discount' => (bool) $settings['hide_line_discount'],
        'customer_fields'   => $settings['customer_fields'],
        'customer_info_fields' => $settings['customer_info_fields'],
        'order_fields'      => $settings['order_fields'],
        'default_products'  => $default_products,
        'default_product_ids' => $settings['default_products'],
    ];

    return [
        'isAdmin'          => current_user_can('manage_options'),
        'roles'            => $roles,
        'customerFieldDefs' => order_creator_customer_field_defs(),
        'customerCustomFieldDefs' => order_creator_customer_custom_field_defs(),
        'customerInfoFieldDefs' => order_creator_customer_info_field_defs(),
        'orderFieldDefs'    => order_creator_order_field_defs(),
        'settings'         => $settings_for_js,
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce(ORDER_CREATOR_NONCE),
        'confirmNonce' => wp_create_nonce('confirm_order_payment_nonce'),
        'gateways'     => $gateways,
        'statuses'     => $statuses,
        'shippingMethods' => order_creator_configured_shipping_methods(),
        'bankAccounts' => apply_filters('order_creator_bank_accounts', [
            'Vietinbank 113600098383',
            'ACB 11090087',
            'Momo 0766333454',
            'ACB 212658699',
            'tiền mặt',
        ]),
        'currencySymbol' => html_entity_decode(get_woocommerce_currency_symbol()),
        'editOrderId'    => isset($_GET['order_id']) ? absint($_GET['order_id']) : 0,
    ];
}

function order_creator_render_page(): void
{
    wp_enqueue_style('dashicons');
    $css_ver = file_exists(order_creator_assets_path('create-order.css')) ? filemtime(order_creator_assets_path('create-order.css')) : '1';
    $js_ver  = file_exists(order_creator_assets_path('create-order.js')) ? filemtime(order_creator_assets_path('create-order.js')) : '1';
    $config  = wp_json_encode(order_creator_js_config(), JSON_UNESCAPED_UNICODE);
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tạo đơn hàng</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url(order_creator_assets_uri('create-order.css')); ?>?ver=<?php echo esc_attr($css_ver); ?>">
</head>
<body class="order-creator-body">
<?php wp_body_open(); ?>
<div class="oc-app" id="oc-app">
    <?php
    $oc_logo_id  = get_theme_mod('custom_logo');
    $oc_logo_url = $oc_logo_id ? wp_get_attachment_image_url($oc_logo_id, 'full') : (string) get_option('woocommerce_email_header_image');
    ?>
    <header class="oc-topbar">
        <div class="oc-topbar__left">
            <div class="oc-topbar__title">
                <?php if ($oc_logo_url) : ?><img class="oc-logo" src="<?php echo esc_url($oc_logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>"><?php endif; ?>
                <h1>Tạo đơn hàng</h1>
            </div>
            <nav class="oc-tabs">
                <button type="button" class="oc-tab is-active" data-tab="create">Tạo đơn</button>
                <?php if (current_user_can('manage_options')) : ?>
                    <button type="button" class="oc-tab" data-tab="settings">Tùy chỉnh</button>
                <?php endif; ?>
                <button type="button" class="oc-tab" data-tab="hdsd">HDSD</button>
            </nav>
        </div>
    </header>

    <div class="oc-pane" id="oc-pane-create">
    <nav class="oc-section-nav" id="oc-section-nav">
        <?php if ($oc_logo_url) : ?><img class="oc-section-nav__logo" src="<?php echo esc_url($oc_logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>"><?php endif; ?>
        <a href="#oc-section-cart" data-jump="oc-section-cart" class="is-active">Chỉnh giỏ hàng</a>
        <a href="#oc-section-promo" data-jump="oc-section-promo">Ưu đãi / Giảm giá</a>
        <a href="#oc-section-notes" data-jump="oc-section-notes">Ghi chú</a>
        <a href="#oc-section-orders" data-jump="oc-section-orders">Tìm đơn</a>
        <a href="#oc-section-customer" data-jump="oc-section-customer">Khách hàng</a>
    </nav>
    <div class="oc-grid">
        <main class="oc-main">
            <section class="oc-card" id="oc-section-cart">
                <div class="oc-card-head">
                    <h2>Chỉnh giỏ hàng</h2>
                    <button type="button" class="oc-btn oc-btn--ghost oc-btn--sm" id="oc-clear-cart">Xóa giỏ hàng</button>
                </div>
                <div class="oc-field">
                    <label>Tìm sản phẩm</label>
                    <input type="text" id="oc-product-search" placeholder="Tên sản phẩm hoặc SKU...">
                    <div class="oc-search-results" id="oc-product-results" hidden></div>
                </div>

                <table class="oc-lines" id="oc-lines">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th class="oc-col-num">Đơn giá (CS)</th>
                            <th class="oc-col-num">Giá sửa</th>
                            <th class="oc-col-num">Giảm/SP</th>
                            <th class="oc-col-num">SL</th>
                            <th class="oc-col-num">Thành tiền</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="oc-lines-body">
                        <tr class="oc-empty"><td colspan="7">Chưa có sản phẩm.</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="oc-card oc-adjustments" id="oc-section-promo">
                <div class="oc-field">
                    <label>Mã ưu đãi (coupon)</label>
                    <div class="oc-inline">
                        <input type="text" id="oc-coupon-input" placeholder="Nhập mã...">
                        <button type="button" class="oc-btn oc-btn--ghost" id="oc-coupon-add">Áp dụng</button>
                        <a class="oc-btn oc-btn--ghost" id="oc-coupon-create" href="<?php echo esc_url(admin_url('post-new.php?post_type=shop_coupon')); ?>" target="_blank" rel="noopener">Tạo mã ưu đãi</a>
                    </div>
                    <ul class="oc-chips" id="oc-coupon-list"></ul>
                </div>

                <div class="oc-field">
                    <label>Phí / giảm giá thủ công</label>
                    <div class="oc-inline">
                        <input type="text" id="oc-fee-name" placeholder="Tên (vd: Phí ship, Giảm giá)">
                        <input type="number" id="oc-fee-amount" step="1000" placeholder="Số tiền (âm = giảm)">
                        <button type="button" class="oc-btn oc-btn--ghost" id="oc-fee-add">Thêm</button>
                    </div>
                    <ul class="oc-chips" id="oc-fee-list"></ul>
                </div>

                <div class="oc-field">
                    <label>Phương thức vận chuyển</label>
                    <select id="oc-shipping-method"><option value="">— Tính lại để xem —</option></select>
                </div>
                <div class="oc-field">
                    <label>Phí ship điều chỉnh</label>
                    <input type="number" id="oc-shipping-cost" min="0" step="1000" placeholder="Để trống để dùng phí WooCommerce">
                </div>
                <div class="oc-field">
                    <label>Tên phương thức vận chuyển</label>
                    <input type="text" id="oc-shipping-title" placeholder="Bật khi nhập phí ship điều chỉnh" disabled>
                </div>
            </section>

            <?php $order_fields = order_creator_order_field_defs(); $enabled_order_fields = array_flip(order_creator_get_settings()['order_fields']); ?>
            <section class="oc-card oc-order-handling" id="oc-order-handling"<?php echo $enabled_order_fields ? '' : ' hidden'; ?>>
                <h2>Xử lý đơn</h2>
                <?php foreach ($order_fields as $key => $field) : if (!isset($enabled_order_fields[$key])) { continue; } ?>
                    <div class="oc-field" data-order-field="<?php echo esc_attr($key); ?>">
                        <label><?php echo esc_html($field['label']); ?></label>
                        <?php if ($field['type'] === 'checkboxes') : ?>
                            <div class="oc-check-grid" id="oc-order-<?php echo esc_attr($key); ?>">
                                <?php foreach ($field['options'] as $value => $label) : ?><label><input type="checkbox" value="<?php echo esc_attr($value); ?>"> <?php echo esc_html($label); ?></label><?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <select id="oc-order-<?php echo esc_attr($key); ?>"<?php echo $field['type'] === 'multi_select' ? ' multiple' : ''; ?>>
                                <?php if ($field['type'] !== 'multi_select') : ?><option value="">— Chưa chọn —</option><?php endif; ?>
                                <?php foreach ($field['options'] as $option) : ?><option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option><?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="oc-card oc-totals" id="oc-totals">
                <div class="oc-total-row"><span>Tạm tính</span><b data-total="subtotal">0</b></div>
                <div class="oc-total-row"><span>Giảm giá</span><b data-total="discount_total">0</b></div>
                <div class="oc-total-row"><span>Phí</span><b data-total="fee_total">0</b></div>
                <div class="oc-total-row"><span>Vận chuyển</span><b data-total="shipping_total">0</b></div>
                <div class="oc-total-row"><span>Thuế</span><b data-total="tax_total">0</b></div>
                <div class="oc-total-row oc-total-row--grand"><span>Tổng đơn</span><b data-total="total">0</b></div>
            </section>
        </main>

        <aside class="oc-side">
            <section class="oc-card" id="oc-section-orders">
                <h2>Tìm đơn</h2>
                <input type="text" id="oc-order-search" placeholder="Mã đơn, tên, SĐT hoặc email...">
                <div class="oc-search-results" id="oc-order-results" hidden></div>
            </section>

            <section class="oc-card" id="oc-section-customer">
                <h2>Khách hàng</h2>
                <input type="text" id="oc-customer-search" placeholder="SĐT / Email / #ID / Tên...">
                <div class="oc-search-results" id="oc-customer-results" hidden></div>
                <div class="oc-customer-card" id="oc-customer-card" hidden></div>
                <button type="button" class="oc-btn oc-btn--ghost oc-btn--block" id="oc-customer-edit" hidden>Chỉnh thông tin khách</button>
                <button type="button" class="oc-btn oc-btn--ghost oc-btn--block" id="oc-customer-history" hidden>Lịch sử đặt hàng</button>
                <button type="button" class="oc-btn oc-btn--ghost oc-btn--block" id="oc-customer-products" hidden>Sản phẩm đã đặt</button>
                <button type="button" class="oc-btn oc-btn--ghost oc-btn--block" id="oc-customer-new-toggle">Khách hàng mới</button>
            </section>

            <section class="oc-card">
                <h2>Địa chỉ đặt hàng</h2>
                <input type="text" id="oc-bill-name" placeholder="Họ tên">
                <input type="text" id="oc-bill-phone" placeholder="SĐT">
                <input type="text" id="oc-bill-email" placeholder="Email">
                <input type="text" id="oc-bill-address" placeholder="Địa chỉ (số nhà, đường/thôn xóm)">
                <?php require_once get_stylesheet_directory() . '/custom-functions/core/vn-address.php'; ?>
                <select id="oc-bill-state">
                    <option value="">— Tỉnh/Thành —</option>
                    <?php foreach (theme_vn_address_provinces() as $vn_matp => $vn_province) : ?>
                        <option value="<?php echo esc_attr($vn_matp); ?>"><?php echo esc_html($vn_province); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="oc-bill-city">
                    <option value="">— Chọn Tỉnh/Thành trước —</option>
                </select>
                <div class="oc-customer-info" id="oc-customer-info" hidden></div>
                <div class="oc-field oc-check">
                    <label><input type="checkbox" id="oc-save-customer-addresses"> Lưu địa chỉ này vào thông tin mặc định của khách</label>
                    <button type="button" class="oc-btn oc-btn--primary" id="oc-save-customer-addresses-confirm" hidden>Xác nhận lưu địa chỉ</button>
                    <small class="oc-muted" id="oc-save-customer-addresses-status" aria-live="polite"></small>
                </div>

                <div class="oc-field oc-check" style="margin-top:10px;">
                    <label><input type="checkbox" id="oc-ship-diff"> Giao đến địa chỉ khác?</label>
                </div>
                <div id="oc-ship-fields" hidden>
                    <h3 class="oc-subhead">Địa chỉ giao hàng</h3>
                    <div class="oc-inline">
                        <input type="text" id="oc-ship-load-order" placeholder="Mã đơn để tải địa chỉ giao">
                        <button type="button" class="oc-btn oc-btn--ghost" id="oc-ship-load-btn">Tải địa chỉ từ đơn</button>
                    </div>
                    <small class="oc-muted" id="oc-ship-load-status" aria-live="polite"></small>
                    <input type="text" id="oc-ship-name" placeholder="Họ tên người nhận">
                    <input type="text" id="oc-ship-phone" placeholder="SĐT">
                    <input type="text" id="oc-ship-address" placeholder="Địa chỉ (số nhà, đường/thôn xóm)">
                    <select id="oc-ship-state">
                        <option value="">— Tỉnh/Thành —</option>
                        <?php foreach (theme_vn_address_provinces() as $vn_matp => $vn_province) : ?>
                            <option value="<?php echo esc_attr($vn_matp); ?>"><?php echo esc_html($vn_province); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="oc-ship-city">
                        <option value="">— Chọn Tỉnh/Thành trước —</option>
                    </select>
                </div>
            </section>

            <section class="oc-card" id="oc-section-notes">
                <h2>Thông tin đơn</h2>
                <div class="oc-field">
                    <label>Ngày đặt</label>
                    <input type="datetime-local" id="oc-order-date" value="<?php echo esc_attr(current_time('Y-m-d\TH:i')); ?>">
                </div>
                <div class="oc-field">
                    <label>Trạng thái</label>
                    <select id="oc-status"></select>
                </div>
                <div class="oc-field">
                    <label>Thanh toán</label>
                    <select id="oc-payment"></select>
                </div>
                <div class="oc-field">
                    <label>Ghi chú khách (hiện trên đơn)</label>
                    <textarea id="oc-customer-note" rows="2"></textarea>
                </div>
                <div class="oc-field">
                    <label>Ghi chú nội bộ</label>
                    <textarea id="oc-internal-note" rows="2"></textarea>
                </div>
                <div class="oc-field oc-check">
                    <label><input type="checkbox" id="oc-suppress-email" checked> Không gửi email khi tạo đơn</label>
                </div>
            </section>
        </aside>
    </div>

    <!-- Kết quả sau khi tạo đơn -->
    <div class="oc-result" id="oc-result" hidden></div>

    <!-- Thanh nút thao tác (sticky) — hiển thị theo chế độ: create / edit / result -->
    <div class="oc-actionbar mode-create" id="oc-create-actions">
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-cancel-edit" data-act="cancel">Hủy chỉnh sửa</button>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-recalc" data-act="recalc">Tính lại</button>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-copy-order" data-act="copy">Copy</button>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-draft" data-act="draft">Lưu nháp</button>
        <button type="button" class="oc-btn oc-btn--primary" id="oc-create" data-act="create">Tạo đơn</button>
        <button type="button" class="oc-btn oc-btn--primary" id="oc-customer-confirmed" data-act="customer-confirm" hidden>Khách đã xác nhận</button>
        <button type="button" class="oc-btn oc-btn--primary" id="oc-open-pay" data-act="pay">Xác nhận thanh toán</button>
        <a class="oc-btn oc-btn--ghost" id="oc-view-order" data-act="view" target="_blank" rel="noopener" href="#">Xem đơn</a>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-view-invoice" data-act="invoice">Xem hóa đơn</button>
        <a class="oc-btn oc-btn--ghost" id="oc-print-pxk" data-act="print" target="_blank" rel="noopener" href="#">In phiếu xuất kho</a>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-edit-inline" data-act="edit">Chỉnh sửa</button>
        <button type="button" class="oc-btn oc-btn--ghost" id="oc-new-order" data-act="new">Tạo đơn mới</button>
    </div>
    </div><!-- /oc-pane-create -->

    <?php if (current_user_can('manage_options')) : ?>
    <div class="oc-pane" id="oc-pane-settings" hidden>
        <div class="oc-card">
            <h2>Sản phẩm mặc định (hiện khi bấm vào ô Tìm sản phẩm)</h2>
            <input type="text" id="oc-set-product-search" placeholder="Tìm sản phẩm để thêm...">
            <div class="oc-search-results" id="oc-set-product-results" hidden></div>
            <ul class="oc-chips" id="oc-set-default-products"></ul>
        </div>

        <div class="oc-card">
            <h2>Kết quả tìm kiếm</h2>
            <div class="oc-field oc-check"><label><input type="checkbox" id="oc-set-image-stock"> Hiện ảnh sản phẩm + tồn kho trong kết quả</label></div>
            <div class="oc-field oc-check"><label><input type="checkbox" id="oc-set-hidden-last"> Đưa sản phẩm ẩn/riêng tư xuống cuối (nhóm riêng)</label></div>
            <div class="oc-field oc-check"><label><input type="checkbox" id="oc-set-hide-manual-price"> Ẩn Giá sửa</label></div>
            <div class="oc-field oc-check"><label><input type="checkbox" id="oc-set-hide-line-discount"> Ẩn Giảm/SP</label></div>
        </div>

        <div class="oc-card">
            <h2>Trường hiển thị trong popup Khách hàng</h2>
            <div class="oc-check-grid" id="oc-set-customer-fields"></div>
        </div>
        <div class="oc-card">
            <h2>Trường hiển thị trong Thông tin khách hàng</h2>
            <div class="oc-check-grid" id="oc-set-customer-info-fields"></div>
        </div>
        <div class="oc-card">
            <h2>Trường xử lý đơn</h2>
            <p class="oc-muted">Chọn các field hiển thị phía trên bảng tổng. Danh sách field được khai báo tập trung để có thể mở rộng an toàn.</p>
            <div class="oc-check-grid" id="oc-set-order-fields"></div>
        </div>

        <div class="oc-settings-actions">
            <button type="button" class="oc-btn oc-btn--primary" id="oc-set-save">Lưu cài đặt</button>
            <span class="oc-muted" id="oc-set-status"></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="oc-pane oc-hdsd" id="oc-pane-hdsd" hidden>
        <div class="oc-card">
            <h2>📋 Quy trình đăng đơn chuẩn</h2>
            <ol>
                <li><strong>Chọn khách hàng</strong> — tìm theo SĐT / email / tên ở mục <em>Khách hàng</em>. Khách chưa có trong hệ thống → bấm <em>Khách hàng mới</em>.</li>
                <li><strong>Kiểm tra địa chỉ</strong> — mục <em>Địa chỉ đặt hàng</em> tự điền theo hồ sơ khách. Giao nơi khác → tick <em>Giao đến địa chỉ khác?</em> và điền địa chỉ giao.</li>
                <li><strong>Thêm sản phẩm</strong> — gõ tên hoặc SKU vào ô <em>Tìm sản phẩm</em>, chỉnh số lượng / giá nếu cần.</li>
                <li><strong>Ưu đãi &amp; phí</strong> — nhập mã ưu đãi hoặc thêm phí phát sinh (nếu có).</li>
                <li><strong>Kiểm tra tổng</strong> — bấm <em>Tính lại</em>, đối chiếu tạm tính, giảm giá, phí vận chuyển, tổng đơn.</li>
                <li><strong>Tạo đơn</strong> — chọn Trạng thái + Thanh toán ở mục <em>Thông tin đơn</em>, rồi bấm <em>Tạo đơn</em> (hoặc <em>Lưu nháp</em> nếu khách chưa chốt).</li>
            </ol>
        </div>

        <div class="oc-card">
            <h2>👤 Khách hàng</h2>
            <ul>
                <li><strong>Tìm khách:</strong> nhập SĐT / email / #ID / tên. Chọn đúng khách để đơn được gắn vào tài khoản và tích lũy lịch sử mua.</li>
                <li><strong>Lịch sử đặt hàng / Sản phẩm đã đặt:</strong> xem nhanh các đơn cũ và món khách hay mua — dùng để tư vấn và đối chiếu.</li>
                <li><strong>Khách hàng mới:</strong> tối thiểu cần <strong>Tên + SĐT hợp lệ</strong> (hoặc email). Không có email → hệ thống tự tạo email từ SĐT.</li>
                <li><strong>Nạp thông tin khách từ đơn:</strong> trong popup, nhập số đơn cũ rồi bấm <em>Nạp</em> — dùng khi khách từng mua nhưng chưa có tài khoản.</li>
                <li><strong>Chỉnh thông tin khách:</strong> chọn khách xong sẽ hiện nút chỉnh sửa — cập nhật hồ sơ trước khi tạo đơn nếu khách đổi SĐT/địa chỉ.</li>
            </ul>
        </div>

        <div class="oc-card">
            <h2>✨ Nhập khách hàng bằng AI</h2>
            <ol>
                <li>Trong popup <em>Khách hàng mới</em>, bấm <em>✨ Nhập khách hàng bằng AI</em>.</li>
                <li>Dán tin nhắn / comment của khách vào ô nhập, <strong>hoặc</strong> dán ảnh chụp màn hình bằng <kbd>Ctrl+V</kbd> / chọn file ảnh (JPG/PNG/WebP/GIF, tối đa 5MB). Có thể kết hợp cả text lẫn ảnh.</li>
                <li>Bấm <em>Bóc tách &amp; điền</em> — AI tự điền Tên, Họ, SĐT, email và địa chỉ vào form.</li>
                <li><strong>Luôn kiểm tra lại từng trường trước khi bấm Lưu khách hàng</strong> — AI chỉ hỗ trợ điền, không tự lưu.</li>
            </ol>
            <ul>
                <li>⚠️ Báo <em>"Không khớp danh mục"</em>: tên tỉnh / phường AI đọc được không trùng danh mục mới → tự chọn lại ở 2 ô Tỉnh/Thành và Phường/Xã.</li>
                <li>Không thấy nút AI = tính năng đang tắt trong <em>Cài đặt ERP</em>, hoặc AI báo thiếu API key → báo quản trị viên.</li>
            </ul>
        </div>

        <div class="oc-card">
            <h2>🗺️ Địa chỉ theo cấu trúc hành chính mới (từ 07/2025)</h2>
            <ul>
                <li>Địa chỉ chỉ còn <strong>2 cấp</strong>: <strong>Tỉnh/Thành</strong> (34 đơn vị) → <strong>Phường/Xã</strong>. <strong>Không còn quận/huyện.</strong></li>
                <li>Chọn Tỉnh/Thành trước — danh sách Phường/Xã tự tải theo tỉnh đã chọn.</li>
                <li>Ô <em>Địa chỉ</em> chỉ ghi <strong>số nhà + tên đường / thôn xóm</strong>. Thông tin phụ (tòa nhà, ngõ ngách, tên quận cũ...) ghi vào <em>Địa chỉ 2</em> trong hồ sơ khách.</li>
                <li>Khách / đơn cũ có thể hiện giá trị <em>"... (cũ)"</em> trong dropdown — <strong>chọn lại Phường/Xã theo danh mục mới</strong> trước khi tạo đơn.</li>
                <li>Địa chỉ đúng danh mục thì đơn đẩy sang đơn vị vận chuyển (GHTK) mới không lỗi.</li>
            </ul>
        </div>

        <div class="oc-card">
            <h2>🛒 Giỏ hàng, ưu đãi &amp; vận chuyển</h2>
            <ul>
                <li><strong>Giá sửa:</strong> ghi đè giá bán của dòng sản phẩm (để trống = giá gốc). <strong>Giảm/SP:</strong> giảm tiền trực tiếp trên từng sản phẩm.</li>
                <li><strong>Mã ưu đãi:</strong> nhập mã rồi thêm — nếu mã không áp được, hệ thống hiện popup giải thích lý do.</li>
                <li><strong>Phí:</strong> thêm các khoản thu ngoài (phụ phí, đóng gói...) với tên + số tiền.</li>
                <li><strong>Vận chuyển:</strong> phí ship tự tính lại khi đổi địa chỉ; có thể chọn phương thức khác trong ô chọn hoặc sửa phí ship thủ công.</li>
                <li>Sau mọi thay đổi lớn, bấm <em>Tính lại</em> để cập nhật bảng tổng trước khi tạo đơn.</li>
            </ul>
        </div>

        <div class="oc-card">
            <h2>💾 Tạo đơn, sửa đơn &amp; thanh toán</h2>
            <ul>
                <li><strong>Tạo đơn</strong> = đơn chính thức theo trạng thái đã chọn. <strong>Lưu nháp</strong> = giữ đơn ở trạng thái nháp để chốt sau.</li>
                <li><strong>Sửa đơn:</strong> tìm đơn ở mục <em>Tìm đơn</em> (mã đơn / tên / SĐT / email) rồi mở để chỉnh — nhớ bấm lưu thay đổi; <em>Hủy chỉnh sửa</em> để thoát không lưu.</li>
                <li><strong>Copy:</strong> nhân bản đơn hiện tại thành đơn mới (giữ khách + giỏ hàng) — dùng cho khách đặt lại y hệt.</li>
                <li><strong>Khách đã xác nhận:</strong> bấm khi khách chốt COD — trạng thái đơn tự chuyển bước tiếp theo.</li>
                <li><strong>Xác nhận thanh toán:</strong> mở popup nhập tài khoản nhận, ngày nhận, số tiền, người chuyển — dùng khi khách đã chuyển khoản.</li>
                <li><strong>Hóa đơn:</strong> xem hóa đơn của đơn vừa tạo, <em>Xuất JPG</em> để tải ảnh hoặc <em>Copy ảnh</em> để dán thẳng vào chat gửi khách.</li>
            </ul>
        </div>

        <div class="oc-card">
            <h2>❗ Lỗi thường gặp</h2>
            <ul>
                <li><em>"Phiên làm việc hết hạn"</em> → tải lại trang (<kbd>F5</kbd>) rồi thao tác lại.</li>
                <li><em>"Bạn không có quyền tạo đơn"</em> → tài khoản chưa được cấp quyền, báo quản trị viên.</li>
                <li>Phí ship không đổi sau khi sửa địa chỉ → bấm <em>Tính lại</em>.</li>
                <li>Dropdown Phường/Xã trống → chưa chọn Tỉnh/Thành, hoặc mạng chậm — chọn lại Tỉnh/Thành.</li>
                <li>Nút AI không bóc tách được ảnh → thử ảnh rõ nét hơn hoặc dán nội dung dạng text.</li>
            </ul>
        </div>
    </div>
</div>

<div class="oc-modal" id="oc-history-modal" hidden>
    <div class="oc-modal__backdrop" data-oc-close="1"></div>
    <div class="oc-modal__dialog oc-modal__dialog--wide" role="dialog" aria-modal="true">
        <button type="button" class="oc-modal__close" data-oc-close="1" aria-label="Đóng">&times;</button>
        <h3 id="oc-history-title">Lịch sử đặt hàng</h3><div id="oc-history-content"></div>
    </div>
</div>

<!-- Modal tạo / chỉnh sửa khách hàng (field bật/tắt theo cài đặt) -->
<div class="oc-modal" id="oc-customer-modal" hidden>
    <div class="oc-modal__backdrop" data-oc-close="1"></div>
    <div class="oc-modal__dialog oc-modal__dialog--wide" role="dialog" aria-modal="true">
        <button type="button" class="oc-modal__close" data-oc-close="1" aria-label="Đóng">&times;</button>
        <h3 id="oc-cust-modal-title">Khách hàng mới</h3>

        <div class="oc-field oc-cust-field" data-cust="load_order">
            <label>Nạp thông tin khách từ đơn (nhập số đơn)</label>
            <div class="oc-inline">
                <input type="text" id="oc-cust-load-order" placeholder="VD: 12345">
                <button type="button" class="oc-btn oc-btn--ghost" id="oc-cust-load-btn">Nạp</button>
            </div>
        </div>

        <?php
        require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
        if (theme_ai_feature_enabled('order_creator_ai_extract_customer')) :
        ?>
        <div class="oc-field" id="oc-cust-ai-wrap">
            <button type="button" class="oc-btn oc-btn--ghost" id="oc-cust-ai-toggle">✨ Nhập khách hàng bằng AI</button>
            <div id="oc-cust-ai-panel" class="oc-ai-panel" hidden>
                <label for="oc-cust-ai-text">Dán tin nhắn / comment / địa chỉ khách gửi — hoặc dán (Ctrl+V) / chọn ảnh chụp màn hình</label>
                <textarea id="oc-cust-ai-text" rows="4" placeholder="VD: Nguyễn Thị Hằng, 0912345678, 25 Lê Lợi, P. Bến Nghé, Q.1, TP.HCM&#10;(Có thể dán ảnh trực tiếp vào ô này)"></textarea>
                <div class="oc-ai-preview" id="oc-cust-ai-preview" hidden>
                    <img id="oc-cust-ai-preview-img" alt="Ảnh đã chọn">
                    <button type="button" class="oc-btn oc-btn--ghost" id="oc-cust-ai-remove-img">Bỏ ảnh</button>
                </div>
                <div class="oc-inline">
                    <input type="file" id="oc-cust-ai-image" accept="image/jpeg,image/png,image/webp,image/gif">
                    <button type="button" class="oc-btn oc-btn--primary" id="oc-cust-ai-run">Bóc tách &amp; điền</button>
                </div>
                <div class="oc-muted" id="oc-cust-ai-status"></div>
            </div>
        </div>
        <?php endif; ?>

        <h4 class="oc-subhead">Thông tin chung</h4>
        <div class="oc-form-grid">
            <div class="oc-cust-field" data-cust="first_name"><label>Tên *</label><input type="text" id="oc-cust-first_name"></div>
            <div class="oc-cust-field" data-cust="last_name"><label>Họ</label><input type="text" id="oc-cust-last_name"></div>
            <div class="oc-cust-field" data-cust="email"><label>E-mail</label><input type="email" id="oc-cust-email"></div>
            <div class="oc-cust-field" data-cust="username"><label>Tên đăng nhập</label><input type="text" id="oc-cust-username"></div>
            <div class="oc-cust-field" data-cust="role"><label>Vai trò</label><select id="oc-cust-role"></select></div>
        </div>

        <h4 class="oc-subhead">Địa chỉ</h4>
        <?php require_once get_stylesheet_directory() . '/custom-functions/core/vn-address.php'; ?>
        <div class="oc-form-grid">
            <div class="oc-cust-field" data-cust="phone"><label>SĐT *</label><input type="text" id="oc-cust-phone"></div>
            <div class="oc-cust-field" data-cust="address_1"><label>Địa chỉ 1 * (số nhà, đường/thôn xóm)</label><input type="text" id="oc-cust-address_1"></div>
            <div class="oc-cust-field" data-cust="address_2"><label>Địa chỉ 2</label><input type="text" id="oc-cust-address_2"></div>
            <div class="oc-cust-field" data-cust="state"><label>Tỉnh/Thành *</label>
                <select id="oc-cust-state">
                    <option value="">— Chọn Tỉnh/Thành —</option>
                    <?php foreach (theme_vn_address_provinces() as $vn_matp => $vn_province) : ?>
                        <option value="<?php echo esc_attr($vn_matp); ?>"><?php echo esc_html($vn_province); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="oc-cust-field" data-cust="city"><label>Phường/Xã</label>
                <select id="oc-cust-city">
                    <option value="">— Chọn Tỉnh/Thành trước —</option>
                </select>
            </div>
        </div>

        <?php $oc_custom_defs = order_creator_customer_custom_field_defs(); ?>
        <?php if ($oc_custom_defs) : ?>
        <h4 class="oc-subhead">Thông tin thêm</h4>
        <div class="oc-form-grid">
            <?php foreach ($oc_custom_defs as $key => $field) : ?>
                <div class="oc-cust-field<?php echo $field['type'] === 'textarea' ? ' oc-form-span' : ''; ?>" data-cust="<?php echo esc_attr($key); ?>">
                    <label for="oc-cust-<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label>
                    <?php if ($field['type'] === 'textarea') : ?>
                        <textarea id="oc-cust-<?php echo esc_attr($key); ?>" rows="3"></textarea>
                    <?php else : ?>
                        <input type="<?php echo esc_attr($field['type']); ?>" id="oc-cust-<?php echo esc_attr($key); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="oc-field oc-check"><label><input type="checkbox" id="oc-cust-copy-shipping" checked> Dùng địa chỉ này cho cả giao hàng</label></div>

        <div class="oc-modal__actions">
            <button type="button" class="oc-btn oc-btn--ghost" data-oc-close="1">Hủy</button>
            <button type="button" class="oc-btn oc-btn--primary" id="oc-new-save">Lưu khách hàng</button>
        </div>
        <div class="oc-modal__result" id="oc-new-result"></div>
    </div>
</div>

<!-- Modal xác nhận thanh toán (gọi backend confirm_order_payment có sẵn) -->
<div class="oc-modal" id="oc-pay-modal" hidden>
    <div class="oc-modal__backdrop" data-oc-close="1"></div>
    <div class="oc-modal__dialog" role="dialog" aria-modal="true">
        <button type="button" class="oc-modal__close" data-oc-close="1" aria-label="Đóng">&times;</button>
        <h3>Xác nhận thanh toán</h3>
        <div class="oc-modal__summary"><textarea id="oc-pay-info" rows="2" readonly></textarea></div>
        <div class="oc-form-grid">
            <div><label>Tài khoản nhận</label><select id="oc-pay-bank"></select></div>
            <div><label>Ngày nhận</label><input type="date" id="oc-pay-date"></div>
            <div><label>Số tiền nhận</label><input type="number" id="oc-pay-amount" step="1000"></div>
            <div><label>Người chuyển khoản</label>
                <select id="oc-pay-payer">
                    <option value="customer">Khách hàng</option>
                    <option value="shipper">Shipper TT COD</option>
                    <option value="self">Nhân viên TT COD</option>
                </select>
            </div>
            <div class="oc-form-span" id="oc-pay-codnote-wrap" style="display:none;"><label>Ghi chú</label><textarea id="oc-pay-codnote"></textarea></div>
        </div>
        <div class="oc-modal__actions">
            <button type="button" class="oc-btn oc-btn--ghost" data-oc-close="1">Hủy</button>
            <button type="button" class="oc-btn oc-btn--primary" id="oc-pay-confirm">Xác nhận</button>
        </div>
        <div class="oc-modal__result" id="oc-pay-result"></div>
    </div>
</div>

<!-- Modal mã ưu đãi (thông báo lỗi thân thiện + xem chi tiết) -->
<div class="oc-modal" id="oc-coupon-modal" hidden>
    <div class="oc-modal__backdrop" data-oc-close="1"></div>
    <div class="oc-modal__dialog" role="dialog" aria-modal="true">
        <button type="button" class="oc-modal__close" data-oc-close="1" aria-label="Đóng">&times;</button>
        <h3 id="oc-coupon-modal-title">Mã ưu đãi</h3>
        <div id="oc-coupon-modal-body"></div>
        <div class="oc-modal__actions">
            <a href="#" class="oc-btn oc-btn--ghost" id="oc-coupon-modal-edit" target="_blank" rel="noopener" hidden>Mở trang quản trị mã</a>
            <button type="button" class="oc-btn oc-btn--primary" data-oc-close="1">Đóng</button>
        </div>
    </div>
</div>

<!-- Modal xem hóa đơn -->
<div class="oc-modal" id="oc-invoice-modal" hidden>
    <div class="oc-modal__backdrop" data-oc-close="1"></div>
    <div class="oc-modal__dialog oc-modal__dialog--wide" role="dialog" aria-modal="true">
        <button type="button" class="oc-modal__close" data-oc-close="1" aria-label="Đóng">&times;</button>
        <div class="oc-invoice-bar">
            <h3>Hóa đơn</h3>
            <div class="oc-invoice-actions">
                <button type="button" class="oc-btn oc-btn--ghost" id="oc-invoice-jpg">Xuất JPG</button>
                <button type="button" class="oc-btn oc-btn--ghost" id="oc-invoice-copy">Copy ảnh (gửi chat)</button>
            </div>
        </div>
        <div id="oc-invoice-render">
            <iframe id="oc-invoice-frame" style="width:800px;max-width:none;height:70vh;border:0;display:block;"></iframe>
        </div>
        <div class="oc-muted" id="oc-invoice-msg"></div>
    </div>
</div>

<script>window.OrderCreator = <?php echo $config; // phpcs:ignore ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="<?php echo esc_url(order_creator_assets_uri('create-order.js')); ?>?ver=<?php echo esc_attr($js_ver); ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
    <?php
}

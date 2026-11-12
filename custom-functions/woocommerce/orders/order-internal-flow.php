<?php
if (!defined('ABSPATH')) exit;

/**
 * Luồng ngoại lệ "Đơn nội bộ" → nút "🙅 Không phải nội bộ" (tab internal của board, order-ops-step.php).
 * Nạp từ order-ops-step.php (mọi request — thean_order_is_internal() + filter eim cần cờ override). GIỐNG HỆT hithean ↔ theanmarket.
 *
 * Popup (assets/order-flow-popup.js, kind `notinternal`) đọc trước tình trạng đơn (task `not_internal_preview`) rồi áp các kịch bản
 * đã chọn (task `not_internal` của thean_order_workspace — order-workspace.php):
 *  1. Bỏ email nội bộ khỏi email billing (thay bằng email nhập tay / email tài khoản khách gắn ở bước 2 / để trống).
 *  2. Tạo / gắn tài khoản khách hàng theo thông tin đặt / giao: tìm theo SĐT (billing rồi shipping) → email billing (không phải
 *     email nội bộ); bỏ qua tài khoản nhân viên (edit_shop_orders) và tài khoản email nội bộ; không có → tạo khách mới (role customer,
 *     email = email billing thật hoặc <84SĐT>@sms.<domain site>, không gửi email đăng ký).
 *  3. Luôn: gỡ cờ `_eim_internal` + ghi cờ `THEAN_NOT_INTERNAL_META` → thean_order_is_internal() / SQL board / eim (filter
 *     eim_is_internal_order) coi đơn là đơn khách, kể cả khi email còn khớp domain nội bộ. Ghi chú đơn tóm tắt.
 */

const THEAN_NOT_INTERNAL_META = '_thean_not_internal';

/** Đơn đã được xác nhận "Không phải nội bộ"? */
function thean_order_not_internal_override(WC_Order $order): bool
{
    return (string) $order->get_meta(THEAN_NOT_INTERNAL_META) === '1';
}

// eim (hóa đơn): đơn đã xác nhận không phải nội bộ → không bỏ qua xuất VAT.
add_filter('eim_is_internal_order', function ($internal, $order) {
    return $order instanceof WC_Order && thean_order_not_internal_override($order) ? false : $internal;
}, 20, 2);

/** Các cách viết SĐT để so meta billing_phone: 0xxx, 84xxx, +84xxx. Hàm thuần; [] khi không phải SĐT VN hợp lệ. */
function thean_phone_variants(string $phone): array
{
    $d = preg_replace('/\D+/', '', $phone);
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    if (strpos($d, '84') === 0 && strlen($d) >= 11) $d = substr($d, 2);
    if (strpos($d, '0') === 0) $d = substr($d, 1);
    if (!preg_match('/^\d{8,10}$/', $d)) return [];
    return ['0' . $d, '84' . $d, '+84' . $d];
}

/** Email tự sinh cho khách không có email: 84<SĐT>@sms.<domain site>. '' khi SĐT không hợp lệ. */
function thean_customer_sms_email(string $phone): string
{
    $v = thean_phone_variants($phone);
    if (!$v) return '';
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    return $v[1] . '@sms.' . ($host !== '' ? $host : 'localhost');
}

/** Tài khoản đem gắn cho đơn khách được không: không phải nhân viên, email không thuộc domain nội bộ. */
function thean_customer_user_eligible(WP_User $user): bool
{
    return !user_can($user, 'edit_shop_orders') && !user_can($user, 'manage_woocommerce')
        && !(function_exists('thean_email_is_internal') && thean_email_is_internal((string) $user->user_email));
}

/** Tìm tài khoản khách khớp đơn: ['user' => WP_User, 'by' => phone|email] | null. */
function thean_order_find_customer(WC_Order $order): ?array
{
    foreach (array_unique(array_filter([$order->get_billing_phone(), $order->get_shipping_phone()])) as $phone) {
        $variants = thean_phone_variants((string) $phone);
        if (!$variants) continue;
        $users = get_users([
            'meta_query' => ['relation' => 'OR',
                ['key' => 'billing_phone', 'value' => $variants, 'compare' => 'IN'],
                ['key' => 'shipping_phone', 'value' => $variants, 'compare' => 'IN'],
            ],
            'number' => 5, 'orderby' => 'ID', 'order' => 'ASC',
        ]);
        foreach ($users as $user) {
            if ($user instanceof WP_User && thean_customer_user_eligible($user)) return ['user' => $user, 'by' => 'phone'];
        }
        $sms = get_user_by('email', thean_customer_sms_email((string) $phone));
        if ($sms instanceof WP_User && thean_customer_user_eligible($sms)) return ['user' => $sms, 'by' => 'phone'];
    }
    $email = sanitize_email($order->get_billing_email());
    if ($email !== '' && !(function_exists('thean_email_is_internal') && thean_email_is_internal($email))) {
        $user = get_user_by('email', $email);
        if ($user instanceof WP_User && thean_customer_user_eligible($user)) return ['user' => $user, 'by' => 'email'];
    }
    return null;
}

/** Email thật (không nội bộ) đang ở billing, '' nếu không có. */
function thean_order_real_billing_email(WC_Order $order): string
{
    $email = sanitize_email($order->get_billing_email());
    return $email !== '' && !(function_exists('thean_email_is_internal') && thean_email_is_internal($email)) ? $email : '';
}

/** Tình trạng đơn cho popup. */
function thean_not_internal_preview(WC_Order $order): array
{
    $email    = (string) $order->get_billing_email();
    $current  = $order->get_customer_id() ? get_userdata($order->get_customer_id()) : null;
    $keep     = $current instanceof WP_User && thean_customer_user_eligible($current);
    $match    = $keep ? null : thean_order_find_customer($order);
    $name     = trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()) ?: trim($order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name());
    $phone    = $order->get_billing_phone() ?: $order->get_shipping_phone();
    $user_txt = static fn(WP_User $u) => sprintf('#%d %s (%s)', $u->ID, $u->display_name, $u->user_email);
    if ($keep) {
        $customer = ['mode' => 'keep', 'text' => 'Đơn đã gắn khách ' . $user_txt($current) . ' — giữ nguyên.'];
    } elseif ($match) {
        $customer = ['mode' => 'attach', 'text' => 'Gắn tài khoản có sẵn ' . $user_txt($match['user']) . ' — khớp theo ' . ($match['by'] === 'phone' ? 'SĐT' : 'email') . '.'];
    } else {
        $new_email = thean_order_real_billing_email($order) ?: thean_customer_sms_email((string) $phone);
        $customer = $new_email !== ''
            ? ['mode' => 'create', 'text' => 'Chưa có tài khoản khớp → tạo khách mới "' . ($name ?: $phone) . '" · ' . $new_email . '.']
            : ['mode' => 'none', 'text' => 'Không đủ thông tin (cần SĐT hợp lệ hoặc email thật) để tạo tài khoản.'];
    }
    if ($current instanceof WP_User && !$keep) {
        $customer['text'] .= ' Bỏ tài khoản đang gắn ' . $user_txt($current) . ' (nhân viên / email nội bộ).';
    }
    return [
        'email'          => $email,
        'email_internal' => function_exists('thean_email_is_internal') && thean_email_is_internal($email),
        'flag'           => (string) $order->get_meta('_eim_internal') === '1',
        'customer'       => $customer,
        'name'           => $name,
        'phone'          => (string) $phone,
    ];
}

/** Tạo tài khoản khách theo thông tin đặt / giao của đơn. WP_User | WP_Error. */
function thean_order_create_customer(WC_Order $order)
{
    $phone = $order->get_billing_phone() ?: $order->get_shipping_phone();
    $email = thean_order_real_billing_email($order) ?: thean_customer_sms_email((string) $phone);
    if ($email === '') return new WP_Error('thean_no_contact', 'Cần SĐT hợp lệ hoặc email thật để tạo tài khoản khách.');
    if (email_exists($email)) return new WP_Error('thean_email_taken', 'Email ' . $email . ' đã thuộc tài khoản khác (nhân viên / nội bộ) — gắn tay ở trang sửa đơn.');
    $first = $order->get_billing_first_name() ?: $order->get_shipping_first_name();
    $last  = $order->get_billing_last_name() ?: $order->get_shipping_last_name();
    $login = sanitize_user($email, true);
    if (username_exists($login)) $login .= '_' . wp_generate_password(4, false, false);
    $user_id = wp_insert_user([
        'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(20),
        'first_name' => $first, 'last_name' => $last, 'display_name' => trim($last . ' ' . $first) ?: (string) $phone, 'role' => 'customer',
    ]);
    if (is_wp_error($user_id)) return $user_id;
    $has_shipping = $order->get_shipping_address_1() !== '' || $order->get_shipping_first_name() !== '';
    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'] as $f) {
        $b = (string) $order->{'get_billing_' . $f}();
        $s = is_callable([$order, 'get_shipping_' . $f]) ? (string) $order->{'get_shipping_' . $f}() : '';
        update_user_meta($user_id, 'billing_' . $f, $b !== '' ? $b : $s);
        update_user_meta($user_id, 'shipping_' . $f, $has_shipping && $s !== '' ? $s : $b);
    }
    update_user_meta($user_id, 'billing_email', $email);
    return get_userdata($user_id);
}

/**
 * Áp "Không phải nội bộ". $opts: remove_email (bool), new_email (string), customer (bool), note (string).
 * Trả [ok, message].
 */
function thean_not_internal_apply(WC_Order $order, array $opts): array
{
    $log = [];
    $new_email = sanitize_email((string) ($opts['new_email'] ?? ''));
    if ($new_email !== '' && function_exists('thean_email_is_internal') && thean_email_is_internal($new_email)) {
        return [false, 'Email thay thế vẫn thuộc domain nội bộ.'];
    }
    $old_email = (string) $order->get_billing_email();
    $email_internal = function_exists('thean_email_is_internal') && thean_email_is_internal($old_email);
    // 1. Bỏ email nội bộ trước để bước tìm / tạo khách không dùng email đó.
    if (!empty($opts['remove_email']) && ($email_internal || $new_email !== '')) {
        $order->set_billing_email($new_email);
        $log[] = 'email billing ' . ($old_email !== '' ? $old_email : '(trống)') . ' → ' . ($new_email !== '' ? $new_email : '(trống)');
    }
    // 2. Gắn / tạo tài khoản khách.
    if (!empty($opts['customer'])) {
        $current = $order->get_customer_id() ? get_userdata($order->get_customer_id()) : null;
        if ($current instanceof WP_User && thean_customer_user_eligible($current)) {
            $user = $current;
            $log[] = 'giữ khách đang gắn #' . $user->ID;
        } else {
            $match = thean_order_find_customer($order);
            $user = $match ? $match['user'] : thean_order_create_customer($order);
            if (is_wp_error($user)) return [false, $user->get_error_message()];
            $order->set_customer_id($user->ID);
            $log[] = ($match ? 'gắn khách có sẵn #' : 'tạo khách mới #') . $user->ID . ' ' . $user->display_name . ($match ? ' (khớp ' . ($match['by'] === 'phone' ? 'SĐT' : 'email') . ')' : '')
                . ($current instanceof WP_User ? ', bỏ tài khoản #' . $current->ID : '');
        }
        // Email đã bỏ (để trống) → lấy email thật của khách vừa gắn (không lấy email @sms tự sinh).
        if (!empty($opts['remove_email']) && $new_email === '' && $order->get_billing_email() === ''
            && strpos((string) $user->user_email, '@sms.') === false && thean_customer_user_eligible($user)) {
            $order->set_billing_email((string) $user->user_email);
            $log[] = 'email billing = email khách ' . $user->user_email;
        }
    }
    // 3. Gỡ cờ nội bộ + ghi đè: đơn khách kể cả khi email còn khớp domain nội bộ.
    if ((string) $order->get_meta('_eim_internal') === '1') {
        $order->delete_meta_data('_eim_internal');
        $log[] = 'gỡ cờ _eim_internal';
    }
    $order->update_meta_data(THEAN_NOT_INTERNAL_META, '1');
    $note = trim((string) ($opts['note'] ?? ''));
    $order->add_order_note('🙅 Không phải đơn nội bộ' . ($log ? ': ' . implode('; ', $log) : '') . '.' . ($note !== '' ? ' Ghi chú: ' . $note : ''), false, true);
    $order->save();
    return [true, 'Đã chuyển sang đơn khách' . ($log ? ' — ' . implode('; ', $log) : '') . '.'];
}

/** Nút "🙅 Không phải nội bộ" (popup kind notinternal). */
function thean_not_internal_button(WC_Order $order): string
{
    if (!current_user_can('manage_woocommerce')) return '';
    return '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-flow="notinternal" data-order-id="' . esc_attr($order->get_id()) . '">🙅 Không phải nội bộ</button>';
}

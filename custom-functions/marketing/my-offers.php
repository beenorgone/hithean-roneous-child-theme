<?php
/**
 * "Ưu đãi của tôi" — mã ưu đãi riêng của khách (panel của thanh menu đáy, site-footer-menu.php).
 *
 * Nguồn: mã Vòng quay may mắn (coupon có `_thean_lw_reward_id`, khớp hash định danh / user / liên hệ cũ — cùng
 * cách tra của lucky-wheel/ui.php) + coupon WooCommerce giới hạn theo email (`customer_email`).
 * Chỉ coupon còn hạn, còn lượt dùng.
 *
 * Định danh:
 *  - Đã đăng nhập: user ID, email tài khoản, billing email/phone, SĐT đăng nhập SMS (user_login +84…) → coi là ĐÃ XÁC MINH.
 *  - Mã vòng quay vừa nhận trong phiên này (state lucky wheel) → đã xác minh.
 *  - Khách tự nhập email/SĐT (kiểm tra nhanh) → kèm tài khoản gắn với email/SĐT đó, nhưng CHƯA xác minh:
 *    mã hiện dạng che (LW-AB•••••). Email → nút "Gửi mã về email này"; SĐT → đăng nhập SMS để xem mã.
 *
 * Lưu: liên hệ đã tra nhớ theo phiên (cookie `hithean_mo` → transient 30 ngày) và theo tài khoản (user meta
 * `hithean_my_offers_contacts`, gộp từ phiên khi đăng nhập).
 *
 * AJAX (khai ở module `site_footer_menu` trong module-loader): hithean_my_offers_load (không nonce — trang có thể
 * bị cache; chỉ đọc), hithean_my_offers_lookup (không nonce, giới hạn lượt), hithean_my_offers_forget /
 * hithean_my_offers_send / hithean_my_offers_apply (nonce lấy từ phản hồi load).
 */
defined('ABSPATH') || exit;

const HITHEAN_MO_COOKIE = 'hithean_mo';
const HITHEAN_MO_NONCE = 'hithean_my_offers';
const HITHEAN_MO_USER_META = 'hithean_my_offers_contacts';
const HITHEAN_MO_MAX_CONTACTS = 3;

foreach (['load', 'lookup', 'forget', 'send', 'apply'] as $hithean_mo_action) {
    add_action('wp_ajax_hithean_my_offers_' . $hithean_mo_action, 'hithean_mo_ajax_' . $hithean_mo_action);
    add_action('wp_ajax_nopriv_hithean_my_offers_' . $hithean_mo_action, 'hithean_mo_ajax_' . $hithean_mo_action);
}
unset($hithean_mo_action);

/* ---------- Định danh ---------- */

function hithean_mo_normalize_contact(string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') { return null; }
    if (is_email($raw)) { return ['type' => 'email', 'value' => strtolower(sanitize_email($raw))]; }
    $digits = (string) preg_replace('/\D+/', '', $raw);
    if (strpos($digits, '84') === 0 && strlen($digits) >= 11) { $digits = '0' . substr($digits, 2); }
    return preg_match('/^0\d{9,10}$/', $digits) ? ['type' => 'phone', 'value' => $digits] : null;
}

/** Cùng công thức thean_lw_claim_identity_hash() (lucky-wheel/ui.php) — ui.php chỉ nạp khi vòng quay bật. */
function hithean_mo_identity(string $type, string $value): array {
    return ['type' => $type, 'value' => $value, 'hash' => hash('sha256', sanitize_key($type) . ':' . strtolower(trim($value)))];
}

function hithean_mo_phone_variants(string $phone): array {
    $local = substr($phone, 1);
    return [$phone, '+84' . $local, '84' . $local];
}

/** Tài khoản gắn với email / SĐT (email tài khoản, billing email/phone, user_login SĐT của đăng nhập SMS). */
function hithean_mo_find_users(array $contact): array {
    $ids = [];
    if ($contact['type'] === 'email') {
        if ($user = get_user_by('email', $contact['value'])) { $ids[] = $user->ID; }
        $ids = array_merge($ids, get_users(['meta_key' => 'billing_email', 'meta_value' => $contact['value'], 'fields' => 'ID', 'number' => 3]));
    } else {
        foreach (hithean_mo_phone_variants($contact['value']) as $variant) {
            if ($user = get_user_by('login', $variant)) { $ids[] = $user->ID; }
        }
        $ids = array_merge($ids, get_users(['meta_key' => 'billing_phone', 'meta_value' => hithean_mo_phone_variants($contact['value']), 'meta_compare' => 'IN', 'fields' => 'ID', 'number' => 3]));
    }
    return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 3);
}

/** Định danh của 1 tài khoản: user + email tài khoản / billing + SĐT billing / SĐT đăng nhập SMS. */
function hithean_mo_user_identities(int $user_id): array {
    $user = get_userdata($user_id);
    if (!$user) { return []; }
    $out = [hithean_mo_identity('user', (string) $user_id)];
    foreach ([$user->user_email, (string) get_user_meta($user_id, 'billing_email', true), (string) get_user_meta($user_id, 'billing_phone', true), $user->user_login] as $raw) {
        $contact = hithean_mo_normalize_contact((string) $raw);
        if ($contact && !($contact['type'] === 'email' && substr($contact['value'], -strlen('@sms.hithean.com')) === '@sms.hithean.com')) {
            $out[] = hithean_mo_identity($contact['type'], $contact['value']);
        }
    }
    return $out;
}

/** Định danh mở rộng của 1 liên hệ khách nhập: chính nó + tài khoản gắn với nó. */
function hithean_mo_contact_identities(array $contact): array {
    $out = [hithean_mo_identity($contact['type'], $contact['value'])];
    foreach (hithean_mo_find_users($contact) as $user_id) { $out = array_merge($out, hithean_mo_user_identities($user_id)); }
    return $out;
}

function hithean_mo_mask(array $contact): string {
    if ($contact['type'] === 'email') {
        [$name, $domain] = array_pad(explode('@', $contact['value'], 2), 2, '');
        return mb_substr($name, 0, 2) . str_repeat('•', max(2, min(6, mb_strlen($name) - 2))) . '@' . $domain;
    }
    return substr($contact['value'], 0, 3) . '•••••' . substr($contact['value'], -2);
}

/* ---------- Lưu liên hệ: phiên + tài khoản ---------- */

function hithean_mo_session_key(bool $create): string {
    $sid = isset($_COOKIE[HITHEAN_MO_COOKIE]) ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_COOKIE[HITHEAN_MO_COOKIE])) : '';
    if ($sid === '' && $create) {
        $sid = wp_generate_password(32, false, false);
        if (!headers_sent()) { setcookie(HITHEAN_MO_COOKIE, $sid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true); }
        $_COOKIE[HITHEAN_MO_COOKIE] = $sid;
    }
    return $sid === '' ? '' : 'hithean_mo_' . substr(hash('sha256', $sid), 0, 40);
}

function hithean_mo_clean_contacts($list): array {
    $out = [];
    foreach ((array) $list as $item) {
        $contact = is_array($item) ? hithean_mo_normalize_contact((string) ($item['value'] ?? '')) : null;
        if ($contact) { $key = $contact['type'] . ':' . $contact['value']; unset($out[$key]); $out[$key] = $contact; }
    }
    return array_slice(array_values($out), -HITHEAN_MO_MAX_CONTACTS);
}

/** Liên hệ đã nhớ. Đăng nhập: gộp phần của phiên vào user meta (rồi xóa ở phiên). */
function hithean_mo_get_contacts(): array {
    $key = hithean_mo_session_key(false);
    $session = $key ? hithean_mo_clean_contacts(get_transient($key)) : [];
    $user_id = get_current_user_id();
    if (!$user_id) { return $session; }
    $saved = hithean_mo_clean_contacts(get_user_meta($user_id, HITHEAN_MO_USER_META, true));
    if ($session) {
        $saved = hithean_mo_clean_contacts(array_merge($saved, $session));
        update_user_meta($user_id, HITHEAN_MO_USER_META, $saved);
        delete_transient($key);
    }
    return $saved;
}

function hithean_mo_save_contacts(array $contacts): void {
    $contacts = hithean_mo_clean_contacts($contacts);
    if ($user_id = get_current_user_id()) { update_user_meta($user_id, HITHEAN_MO_USER_META, $contacts); return; }
    $key = hithean_mo_session_key(true);
    if ($contacts) { set_transient($key, $contacts, 30 * DAY_IN_SECONDS); } else { delete_transient($key); }
}

/* ---------- Tra coupon ---------- */

/** ID coupon khớp định danh: mã vòng quay (hash / user / liên hệ cũ) + coupon giới hạn email. Mới nhất trước. */
function hithean_mo_coupon_ids(array $identities, int $limit = 30): array {
    global $wpdb;
    $lw = []; $emails = [];
    foreach ($identities as $identity) {
        $lw[] = $wpdb->prepare("(m.meta_key = '_thean_lw_identity_hash' AND m.meta_value = %s)", $identity['hash']);
        if ($identity['type'] === 'user') {
            $lw[] = $wpdb->prepare("(m.meta_key = '_thean_lw_user_id' AND m.meta_value = %s)", $identity['value']);
        } else {
            $lw[] = $wpdb->prepare("(m.meta_key = '_thean_lw_contact_value' AND m.meta_value = %s)", $identity['value']);
        }
        if ($identity['type'] === 'email') {
            $emails[] = $wpdb->prepare("(m.meta_key = 'customer_email' AND m.meta_value LIKE %s)", '%' . $wpdb->esc_like('"' . $identity['value'] . '"') . '%');
        }
    }
    if (!$lw) { return []; }
    $where = implode(' OR ', array_merge($lw, $emails));
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND ($where)
         ORDER BY p.ID DESC LIMIT %d",
        $limit
    ));
    return array_map('intval', (array) $ids);
}

/** Coupon còn dùng được (còn hạn, còn lượt). */
function hithean_mo_coupon_usable(WC_Coupon $coupon): bool {
    $expires = $coupon->get_date_expires();
    if ($expires && $expires->getTimestamp() <= time()) { return false; }
    $limit = (int) $coupon->get_usage_limit();
    return !$limit || (int) $coupon->get_usage_count() < $limit;
}

function hithean_mo_coupon_title(WC_Coupon $coupon): string {
    $config = json_decode((string) $coupon->get_meta('_thean_lw_reward_config', true), true);
    if (is_array($config) && !empty($config['label'])) { return sanitize_text_field((string) $config['label']); }
    $amount = (float) $coupon->get_amount();
    if ($coupon->get_free_shipping() && $amount <= 0) { return 'Miễn phí vận chuyển'; }
    switch ($coupon->get_discount_type()) {
        case 'percent': return 'Giảm ' . rtrim(rtrim(number_format($amount, 2, ',', ''), '0'), ',') . '% giá trị đơn';
        case 'fixed_cart': return 'Giảm ' . number_format($amount, 0, ',', '.') . ' ₫ cho đơn hàng';
        case 'fixed_product': return 'Giảm ' . number_format($amount, 0, ',', '.') . ' ₫ mỗi sản phẩm áp dụng';
    }
    return 'Mã ưu đãi dành riêng cho bạn';
}

function hithean_mo_mask_code(string $code): string {
    $code = strtoupper($code);
    $keep = strpos($code, 'LW-') === 0 ? 5 : 2;
    return substr($code, 0, $keep) . str_repeat('•', max(3, strlen($code) - $keep));
}

/**
 * Thẻ ưu đãi (cùng kiểu thẻ trang SP / /uu-dai/ — offer-card.php). $verified: hiện mã đầy đủ + Sao chép / Áp dụng.
 * $via: liên hệ (đã che) mà mã được tìm thấy qua — chỉ hiện với mã chưa xác minh.
 */
function hithean_mo_coupon_card(WC_Coupon $coupon, bool $verified, string $via, bool $can_email): array {
    $is_lw = $coupon->get_meta('_thean_lw_reward_id', true) !== '';
    $code = strtoupper($coupon->get_code());
    $notes = [];
    if ((float) $coupon->get_minimum_amount() > 0) { $notes[] = 'Áp dụng cho đơn từ ' . number_format((float) $coupon->get_minimum_amount(), 0, ',', '.') . ' ₫'; }
    if ($is_lw) { $notes[] = 'Mỗi đơn dùng 1 mã vòng quay'; }
    if ($expires = $coupon->get_date_expires()) {
        $notes[] = '⏳ Hết hạn ' . wp_date('H:i d/m/Y', $expires->getTimestamp(), new DateTimeZone('Asia/Ho_Chi_Minh'));
    }
    if (!$verified && $via !== '') { $notes[] = 'Tìm thấy qua ' . $via; }
    $actions = '<div class="hithean-offer-card__code"><span>' . esc_html($verified ? $code : hithean_mo_mask_code($code)) . '</span>'
        . ($verified ? '' : '<small>Đã ẩn mã</small>') . '</div>';
    if ($verified) {
        $actions .= '<div class="hithean-offer-card__actions"><button type="button" class="hithean-offer-card__action is-ghost" data-hmo-copy="' . esc_attr($code) . '">Sao chép mã</button>'
            . '<button type="button" class="hithean-offer-card__action" data-hmo-apply="' . esc_attr($code) . '">Dùng cho giỏ hàng</button></div>';
    } elseif ($can_email) {
        $actions .= '<button type="button" class="hithean-offer-card__action is-ghost" data-hmo-send>Gửi mã đầy đủ về email</button>';
    } else {
        $actions .= '<button type="button" class="hithean-offer-card__action is-ghost" data-hmo-login>Đăng nhập bằng SĐT để xem mã</button>';
    }
    return [
        'kind' => $is_lw ? 'wheel' : 'coupon', 'badge' => $is_lw ? 'Mã từ vòng quay' : 'Mã ưu đãi riêng',
        'title' => hithean_mo_coupon_title($coupon), 'media' => ['icon' => $is_lw ? 'wheel' : 'coupon'],
        'notes' => $notes, 'actions_html' => $actions, 'active' => $verified,
    ];
}

/** Mã vòng quay vừa nhận trong phiên này (state của lucky-wheel) — chỉ khi module vòng quay đã nạp. */
function hithean_mo_session_wheel_code(): string {
    if (!function_exists('thean_lw_get_state') || !defined('THEAN_LW_SESSION_COOKIE') || empty($_COOKIE[THEAN_LW_SESSION_COOKIE])) { return ''; }
    return strtoupper((string) (thean_lw_get_state()['coupon_code'] ?? ''));
}

/**
 * Toàn bộ dữ liệu panel. Trả mã ĐÃ XÁC MINH (codes) để AJAX apply kiểm lại.
 * @return array{cards: array, codes: string[], contacts: array, email_contacts: string[]}
 */
function hithean_mo_collect(): array {
    $user_id = get_current_user_id();
    $verified_ids = $user_id ? hithean_mo_user_identities($user_id) : [];
    $contacts = hithean_mo_get_contacts();
    $seen = []; $cards = []; $codes = [];
    $push = static function (int $coupon_id, bool $verified, string $via, bool $can_email) use (&$seen, &$cards, &$codes): void {
        if (isset($seen[$coupon_id]) || count($cards) >= 20) { return; }
        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id() || !hithean_mo_coupon_usable($coupon)) { return; }
        $seen[$coupon_id] = true;
        $cards[] = hithean_mo_coupon_card($coupon, $verified, $via, $can_email);
        if ($verified) { $codes[] = strtoupper($coupon->get_code()); }
    };
    $wheel = hithean_mo_session_wheel_code();
    if ($wheel !== '' && ($id = wc_get_coupon_id_by_code($wheel))) { $push((int) $id, true, '', false); }
    if ($verified_ids) { foreach (hithean_mo_coupon_ids($verified_ids) as $id) { $push($id, true, '', false); } }
    foreach ($contacts as $contact) {
        foreach (hithean_mo_coupon_ids(hithean_mo_contact_identities($contact)) as $id) {
            $push($id, false, hithean_mo_mask($contact), $contact['type'] === 'email');
        }
    }
    return ['cards' => $cards, 'codes' => $codes, 'contacts' => $contacts];
}

/* ---------- AJAX ---------- */

function hithean_mo_rate_ok(string $bucket, int $limit, int $ttl): bool {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $key = 'hithean_mo_rl_' . md5($bucket . '|' . $ip);
    $count = (int) get_transient($key);
    if ($count >= $limit) { return false; }
    set_transient($key, $count + 1, $ttl);
    return true;
}

function hithean_mo_check_nonce(): void {
    if (!check_ajax_referer(HITHEAN_MO_NONCE, 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên đã hết hạn, vui lòng mở lại "Ưu đãi của tôi".'], 403);
    }
}

function hithean_mo_response(string $message = ''): void {
    nocache_headers();
    if (!class_exists('WC_Coupon')) { wp_send_json_error(['message' => 'Cửa hàng chưa sẵn sàng.'], 503); }
    $data = hithean_mo_collect();
    $user = wp_get_current_user();
    $html = '';
    foreach ($data['cards'] as $card) { $html .= hithean_offer_card_html($card); }
    wp_send_json_success([
        'loggedIn' => $user->exists(),
        'name' => $user->exists() ? ($user->first_name ?: $user->display_name) : '',
        'contacts' => array_map(static fn($c, $i) => ['index' => $i, 'type' => $c['type'], 'label' => hithean_mo_mask($c)], $data['contacts'], array_keys($data['contacts'])),
        'count' => count($data['cards']),
        'html' => $html,
        'nonce' => wp_create_nonce(HITHEAN_MO_NONCE),
        'message' => $message,
    ]);
}

function hithean_mo_ajax_load(): void {
    hithean_mo_response();
}

function hithean_mo_ajax_lookup(): void {
    $contact = hithean_mo_normalize_contact(isset($_POST['contact']) ? (string) wp_unslash($_POST['contact']) : '');
    if (!$contact) { wp_send_json_error(['message' => 'Vui lòng nhập email hoặc số điện thoại hợp lệ.'], 400); }
    if (!hithean_mo_rate_ok('lookup', 10, HOUR_IN_SECONDS)) { wp_send_json_error(['message' => 'Bạn đã kiểm tra quá nhiều lần. Vui lòng thử lại sau ít phút.'], 429); }
    $contacts = hithean_mo_get_contacts();
    $contacts[] = $contact;
    hithean_mo_save_contacts($contacts);
    $found = count(hithean_mo_coupon_ids(hithean_mo_contact_identities($contact), 5));
    hithean_mo_response($found ? '' : 'Chưa tìm thấy mã ưu đãi còn hạn cho ' . hithean_mo_mask($contact) . '.');
}

function hithean_mo_ajax_forget(): void {
    hithean_mo_check_nonce();
    $index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
    $contacts = hithean_mo_get_contacts();
    if (isset($contacts[$index])) { unset($contacts[$index]); hithean_mo_save_contacts(array_values($contacts)); }
    hithean_mo_response();
}

/** Gửi mã đầy đủ về chính các email khách đã tra (chỉ mã khớp email đó) — người giữ hộp thư mới đọc được. */
function hithean_mo_ajax_send(): void {
    hithean_mo_check_nonce();
    if (!hithean_mo_rate_ok('send', 3, HOUR_IN_SECONDS)) { wp_send_json_error(['message' => 'Đã gửi gần đây, vui lòng kiểm tra hộp thư (kể cả mục Spam).'], 429); }
    $sent = 0;
    foreach (hithean_mo_get_contacts() as $contact) {
        if ($contact['type'] !== 'email') { continue; }
        $lines = [];
        foreach (hithean_mo_coupon_ids(hithean_mo_contact_identities($contact), 20) as $id) {
            $coupon = new WC_Coupon($id);
            if (!$coupon->get_id() || !hithean_mo_coupon_usable($coupon)) { continue; }
            $expires = $coupon->get_date_expires();
            $lines[] = '• ' . strtoupper($coupon->get_code()) . ' — ' . hithean_mo_coupon_title($coupon)
                . ($expires ? ' (hết hạn ' . wp_date('H:i d/m/Y', $expires->getTimestamp(), new DateTimeZone('Asia/Ho_Chi_Minh')) . ')' : '');
        }
        if (!$lines) { continue; }
        $body = "Chào bạn,\n\nĐây là các mã ưu đãi đang còn hạn của bạn tại " . get_bloginfo('name') . ":\n\n" . implode("\n", $lines)
            . "\n\nNhập mã ở bước Giỏ hàng / Thanh toán: " . wc_get_cart_url() . "\n\nNếu bạn không yêu cầu email này, hãy bỏ qua.";
        if (wp_mail($contact['value'], 'Mã ưu đãi của bạn tại ' . get_bloginfo('name'), $body)) { $sent++; }
    }
    if (!$sent) { wp_send_json_error(['message' => 'Không có mã nào để gửi qua email.'], 400); }
    wp_send_json_success(['message' => 'Đã gửi mã về email. Vui lòng kiểm tra hộp thư (kể cả mục Spam).']);
}

/** Áp mã vào giỏ — chỉ mã đã xác minh của chính người gọi. (lucky-wheel.php nạp ui.php cho action này để chạy luật 1 mã vòng quay / đơn.) */
function hithean_mo_ajax_apply(): void {
    hithean_mo_check_nonce();
    $code = strtoupper(wc_format_coupon_code(isset($_POST['code']) ? (string) wp_unslash($_POST['code']) : ''));
    if ($code === '' || !in_array($code, hithean_mo_collect()['codes'], true)) { wp_send_json_error(['message' => 'Mã không hợp lệ.'], 403); }
    if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'Giỏ hàng chưa sẵn sàng.'], 400); }
    if (WC()->cart->is_empty()) {
        wp_send_json_error(['message' => 'Giỏ hàng đang trống. Đã sao chép mã ' . $code . ' — hãy chọn sản phẩm rồi dán mã ở bước thanh toán.', 'copy' => $code], 400);
    }
    if (function_exists('thean_lw_cart_has_other_lucky_wheel_coupon') && thean_lw_cart_has_other_lucky_wheel_coupon($code)) {
        wp_send_json_error(['message' => 'Mỗi đơn hàng chỉ dùng được 1 mã vòng quay.'], 409);
    }
    if (!WC()->cart->has_discount($code)) {
        WC()->cart->apply_coupon($code);
        WC()->cart->calculate_totals();
    }
    $errors = wc_get_notices('error');
    wc_clear_notices();
    if ($errors && !WC()->cart->has_discount($code)) {
        wp_send_json_error(['message' => wp_strip_all_tags((string) ($errors[0]['notice'] ?? 'Chưa áp dụng được mã.')) . ' Mã vẫn được giữ — bạn có thể dùng khi giỏ hàng đủ điều kiện.'], 400);
    }
    wp_send_json_success(['message' => 'Đã áp dụng mã ' . $code . ' vào giỏ hàng.', 'cartUrl' => wc_get_cart_url()]);
}

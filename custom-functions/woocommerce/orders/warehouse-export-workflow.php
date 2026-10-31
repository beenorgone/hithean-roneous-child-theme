<?php
defined('ABSPATH') || exit;

/**
 * Background work for warehouse-export evidence.
 *
 * Upload and confirmation requests only enqueue the network-heavy work. This
 * keeps warehouse staff out of the AI/Lark request path; WP-Cron performs it
 * in a separate request shortly afterwards.
 */
function hithean_export_ai_schedule_check(int $order_id, int $requested_by = 0)
{
    if (!$order_id || !function_exists('wc_get_order') || !wc_get_order($order_id)) {
        return new WP_Error('hithean_export_ai_invalid_order', 'Không tìm thấy đơn hàng để AI kiểm tra.');
    }

    if (wp_next_scheduled('hithean_export_ai_check_async', [$order_id])) {
        return true;
    }

    thean_order_meta_set((int) $order_id, 'warehouse_export_ai_review_status', [
        'state'        => 'queued',
        'queued_at'    => current_time('mysql'),
        'requested_by' => absint($requested_by),
    ]);

    if (!wp_schedule_single_event(time() + 5, 'hithean_export_ai_check_async', [$order_id])) {
        return new WP_Error('hithean_export_ai_queue_failed', 'Không thể đưa AI kiểm tra vào hàng đợi.');
    }

    return true;
}

add_action('hithean_export_ai_check_async', function ($order_id): void {
    $order_id = absint($order_id);
    $order    = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
    if (!$order) {
        return;
    }

    $state        = get_post_meta($order_id, 'warehouse_export_ai_review_status', true);
    $requested_by = is_array($state) ? absint($state['requested_by'] ?? 0) : 0;
    $review_file  = get_stylesheet_directory() . '/custom-functions/shortcodes/shortcode-order-export-confirm.php';

    if (!function_exists('hithean_export_ai_check_order') && is_readable($review_file)) {
        require_once $review_file;
    }
    if (!function_exists('hithean_export_ai_check_order')) {
        thean_order_meta_set((int) $order_id, 'warehouse_export_ai_review_status', ['state' => 'failed', 'updated_at' => current_time('mysql')]);
        return;
    }

    $result = hithean_export_ai_check_order($order, $requested_by);
    if (is_wp_error($result)) {
        thean_order_meta_set((int) $order_id, 'warehouse_export_ai_review_status', [
            'state'      => 'failed',
            'updated_at' => current_time('mysql'),
            'error'      => sanitize_text_field($result->get_error_message()),
        ]);
        $order->add_order_note('🤖 AI review ảnh xuất kho chưa hoàn tất: ' . sanitize_text_field($result->get_error_message()));
        return;
    }

    thean_order_meta_set((int) $order_id, 'warehouse_export_ai_review_status', ['state' => 'completed', 'updated_at' => current_time('mysql')]);
}, 10, 1);

/**
 * Mã vận đơn AI bóc từ ảnh xuất kho → tự append vào "Mã giao vận"
 * (order_ship_code) thay cho nhập tay. Nhiều mã cách nhau ", " — cùng bộ
 * tách token [\s,;|] mà eim reconcile dùng để khớp đối soát.
 */
function hithean_export_ship_codes_split($value): array
{
    $tokens = preg_split('/[\s,;|]+/u', trim((string) $value)) ?: [];
    return array_values(array_filter($tokens, 'strlen'));
}

function hithean_export_ship_codes_sanitize($codes, WC_Order $order): array
{
    $clean = [];
    foreach (is_array($codes) ? $codes : [] as $code) {
        foreach (hithean_export_ship_codes_split(is_scalar($code) ? $code : '') as $token) {
            $token = strtoupper(trim($token, " \t\n\r\0\x0B.-_#:"));
            $digits = preg_replace('/\D/', '', $token);
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{5,39}$/', $token) || $digits === '') continue; // GHN có mã chỉ 1 chữ số (vd GA4RQKLQ)
            if (preg_match('/^(0|84)\d{9,10}$/', $token)) continue; // số điện thoại
            if ($digits === $token && strpos($token, (string) $order->get_id()) === 0) continue; // mã đơn HiThean (kể cả suffix)
            if (preg_match('/^' . preg_quote((string) $order->get_id(), '/') . '\.\d{1,3}$/', $token)) continue; // mã kiện ID.N của chính đơn
            // Chỉ lưu mã dạng ngắn: segment sau dấu chấm cuối (S22788.SG01-K25.1811382021 → 1811382021).
            $short = hithean_export_ship_code_key($token);
            if (strlen($short) >= 6 && preg_match('/\d/', $short)) $clean[] = $short;
        }
    }
    return array_slice(array_values(array_unique($clean)), 0, 10);
}

/** Phần định danh để so trùng: segment sau dấu chấm cuối (staff hay nhập mã ngắn). */
function hithean_export_ship_code_key(string $code): string
{
    $parts = explode('.', strtoupper($code));
    return trim((string) end($parts));
}

/**
 * Append mã mới (bỏ trùng) vào order_ship_code, ghi note + log để card hiển thị.
 * Trả về danh sách mã thực sự được thêm.
 */
function hithean_export_append_ship_codes(int $order_id, array $codes, string $trigger): array
{
    $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
    if (!$order || !$codes) return [];

    $existing = hithean_export_ship_codes_split(get_post_meta($order_id, 'order_ship_code', true));
    $keys     = array_map('hithean_export_ship_code_key', $existing);
    $added    = [];
    foreach (hithean_export_ship_codes_sanitize($codes, $order) as $code) {
        $key = hithean_export_ship_code_key($code);
        if ($key === '' || in_array($key, $keys, true)) continue;
        $keys[]  = $key;
        $added[] = $code;
    }
    if (!$added) return [];

    thean_order_meta_set((int) $order_id, 'order_ship_code', implode(', ', array_merge($existing, $added)));
    $labels = ['ai_pass' => 'AI xác nhận ảnh khớp đơn', 'export_confirmed' => 'xác nhận xuất kho'];
    $log    = get_post_meta($order_id, 'warehouse_export_ship_codes_log', true);
    $log    = is_array($log) ? $log : [];
    $log[]  = ['codes' => $added, 'trigger' => $trigger, 'at' => current_time('mysql'), 'by' => get_current_user_id()];
    thean_order_meta_set((int) $order_id, 'warehouse_export_ship_codes_log', array_slice($log, -10));
    $order->add_order_note('🚚 Tự động lưu mã vận đơn từ ảnh xuất kho (' . ($labels[$trigger] ?? $trigger) . '): ' . implode(', ', $added));
    $GLOBALS['hithean_export_ship_codes_added'][$order_id] = $added;
    return $added;
}

/** Hậu tố toast cho request vừa append mã (vd. AJAX xác nhận xuất kho). */
function hithean_export_ship_codes_toast_suffix(int $order_id): string
{
    $added = $GLOBALS['hithean_export_ship_codes_added'][$order_id] ?? [];
    return $added ? ' 🚚 Đã lưu mã vận đơn: ' . implode(', ', $added) : '';
}

/** Mã AI đã đọc được ở lần kiểm tra gần nhất → append (idempotent nhờ so trùng). */
function hithean_export_save_ai_ship_codes(int $order_id, string $trigger): array
{
    $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    $codes  = is_array($review) ? (array) ($review['shipping_codes'] ?? []) : [];
    return $codes ? hithean_export_append_ship_codes($order_id, $codes, $trigger) : [];
}

/** Ghi nhận tài khoản đã upload ảnh xuất kho (nhiều người → giữ đủ, bỏ trùng). */
function hithean_export_record_uploader(int $order_id, int $user_id): void
{
    if (!$order_id || !$user_id) return;
    $ids = array_map('absint', (array) get_post_meta($order_id, 'warehouse_export_uploaded_by', true));
    if (in_array($user_id, $ids, true)) return;
    $ids[] = $user_id;
    thean_order_meta_set((int) $order_id, 'warehouse_export_uploaded_by', array_values(array_filter($ids)));
}

/**
 * Người upload ảnh → "Xuất kho bởi" (order_export_by). Meta Box select
 * multiple lưu mỗi user_login một meta row → add_post_meta từng người còn thiếu.
 */
function hithean_export_save_uploaders_as_exporters(int $order_id, string $trigger): array
{
    $order = $order_id && function_exists('wc_get_order') ? wc_get_order($order_id) : false;
    if (!$order) return [];

    $existing = [];
    foreach ((array) get_post_meta($order_id, 'order_export_by', false) as $row) {
        $existing = array_merge($existing, array_map('strval', (array) $row));
    }
    $added = [];
    foreach ((array) get_post_meta($order_id, 'warehouse_export_uploaded_by', true) as $user_id) {
        $user = get_userdata(absint($user_id));
        if (!$user || in_array($user->user_login, $existing, true)) continue;
        thean_order_meta_add((int) $order_id, 'order_export_by', $user->user_login);
        $existing[] = $user->user_login;
        $added[]    = $user->user_login;
    }
    if ($added) {
        $labels = ['ai_pass' => 'AI xác nhận ảnh khớp đơn', 'export_confirmed' => 'xác nhận xuất kho'];
        $order->add_order_note('👷 Tự động điền "Xuất kho bởi" theo người upload ảnh (' . ($labels[$trigger] ?? $trigger) . '): ' . implode(', ', $added));
    }
    return $added;
}

function hithean_export_lark_settings(): array
{
    $saved = get_option('hithean_export_lark_settings', []);
    $saved = is_array($saved) ? $saved : [];
    return wp_parse_args($saved, ['enabled' => '0', 'only_alerts' => '0', 'webhook_url' => '']);
}

function hithean_export_lark_sanitize_webhook($value): string
{
    $url   = esc_url_raw(trim((string) $value));
    $parts = wp_parse_url($url);
    $host  = strtolower((string) ($parts['host'] ?? ''));
    $valid = ($parts['scheme'] ?? '') === 'https'
        && preg_match('/(^|\\.)(larksuite\\.com|larkoffice\\.com|feishu\\.cn)$/', $host);
    return $valid ? $url : '';
}

function hithean_export_lark_queue_notification(int $order_id): void
{
    if (!$order_id || get_post_meta($order_id, 'warehouse_export_lark_notified_at', true)) {
        return;
    }
    $settings = hithean_export_lark_settings();
    if (empty($settings['enabled']) || hithean_export_lark_sanitize_webhook($settings['webhook_url']) === '') {
        return;
    }
    if (wp_next_scheduled('hithean_export_lark_notify_async', [$order_id, 0])) {
        return;
    }

    thean_order_meta_set((int) $order_id, 'warehouse_export_lark_status', ['state' => 'queued', 'updated_at' => current_time('mysql')]);
    wp_schedule_single_event(time() + 5, 'hithean_export_lark_notify_async', [$order_id, 0]);
}

add_action('hithean_export_lark_notify_async', function ($order_id, $attempt = 0): void {
    $order_id = absint($order_id);
    $attempt  = absint($attempt);
    if (!$order_id || get_post_meta($order_id, 'warehouse_export_lark_notified_at', true)) {
        return;
    }
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
    if (!$order) {
        return;
    }

    $settings = hithean_export_lark_settings();
    $webhook  = hithean_export_lark_sanitize_webhook($settings['webhook_url'] ?? '');
    if (empty($settings['enabled']) || $webhook === '') {
        thean_order_meta_set((int) $order_id, 'warehouse_export_lark_status', ['state' => 'disabled', 'updated_at' => current_time('mysql')]);
        return;
    }

    $review       = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    $review_state = get_post_meta($order_id, 'warehouse_export_ai_review_status', true);
    if (!is_array($review) && is_array($review_state) && ($review_state['state'] ?? '') === 'queued' && $attempt < 6) {
        wp_schedule_single_event(time() + 30, 'hithean_export_lark_notify_async', [$order_id, $attempt + 1]);
        return;
    }

    $passed  = is_array($review) && ($review['overall'] ?? '') === 'pass';
    if ($passed && !empty($settings['only_alerts'])) {
        thean_order_meta_set((int) $order_id, 'warehouse_export_lark_status', ['state' => 'skipped_passed', 'updated_at' => current_time('mysql')]);
        return;
    }
    $content = ($passed ? '✅' : '⚠️') . ' Xuất kho #' . $order->get_order_number() . ' — AI: ' . ($passed ? 'Đạt' : 'Không đạt');
    $response = wp_remote_post($webhook, [
        'timeout' => 8,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body'    => wp_json_encode(['msg_type' => 'text', 'content' => ['text' => $content]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    if (is_wp_error($response) || $code < 200 || $code >= 300) {
        $error = is_wp_error($response) ? $response->get_error_message() : 'Webhook HTTP ' . $code;
        thean_order_meta_set((int) $order_id, 'warehouse_export_lark_status', ['state' => 'failed', 'updated_at' => current_time('mysql'), 'error' => sanitize_text_field($error)]);
        if ($attempt < 2) {
            wp_schedule_single_event(time() + 60, 'hithean_export_lark_notify_async', [$order_id, $attempt + 1]);
        }
        return;
    }

    thean_order_meta_set((int) $order_id, 'warehouse_export_lark_notified_at', current_time('mysql'));
    thean_order_meta_set((int) $order_id, 'warehouse_export_lark_status', ['state' => 'sent', 'updated_at' => current_time('mysql')]);
}, 10, 2);

add_action('hithean_warehouse_export_confirmed', function ($order): void {
    if ($order instanceof WC_Order) {
        hithean_export_save_ai_ship_codes($order->get_id(), 'export_confirmed');
        hithean_export_save_uploaders_as_exporters($order->get_id(), 'export_confirmed');
        hithean_export_lark_queue_notification($order->get_id());
    }
}, 5);

add_action('hithean_export_ai_check_completed', function ($order, $result = []): void {
    if (!($order instanceof WC_Order)) return;
    $confirmed = (bool) get_post_meta($order->get_id(), 'export_confirmed_by', true);
    // AI khớp → lưu ngay; AI chạy xong sau khi đã xác nhận xuất kho → lưu theo xác nhận.
    if (is_array($result) && ($result['overall'] ?? '') === 'pass') {
        hithean_export_save_ai_ship_codes($order->get_id(), 'ai_pass');
        hithean_export_save_uploaders_as_exporters($order->get_id(), 'ai_pass');
    } elseif ($confirmed) {
        hithean_export_save_ai_ship_codes($order->get_id(), 'export_confirmed');
        hithean_export_save_uploaders_as_exporters($order->get_id(), 'export_confirmed');
    }
    if ($confirmed) {
        hithean_export_lark_queue_notification($order->get_id());
    }
}, 10, 2);

/* ------------------------------------------------------------------ */
/* Version danh sách xuất kho — live refresh chỉ render lại khi có đổi */
/* ------------------------------------------------------------------ */

function hithean_export_lists_version(): int
{
    return (int) get_option('hithean_export_lists_ver', 0);
}

/** Đánh dấu dữ liệu 2 danh sách xuất kho đã đổi (option không autoload, ghi tối đa 1 lần/giây). */
function hithean_export_lists_bump(): void
{
    $now = time();
    if (hithean_export_lists_version() !== $now) {
        update_option('hithean_export_lists_ver', $now, false);
    }
}

add_action('woocommerce_update_order', 'hithean_export_lists_bump');
add_action('woocommerce_order_status_changed', 'hithean_export_lists_bump');

// Code xuất kho ghi meta trực tiếp bằng update_post_meta (không qua $order->save()).
function hithean_export_lists_bump_on_meta($meta_id, $object_id, $meta_key): void
{
    static $keys = ['warehouse_export_images', 'export_confirmed_by', 'export_date', 'order_ship_date', 'warehouse_export_ai_check', 'warehouse_export_ai_review_status'];
    if (in_array($meta_key, $keys, true)) {
        hithean_export_lists_bump();
    }
}
add_action('added_post_meta', 'hithean_export_lists_bump_on_meta', 10, 3);
add_action('updated_post_meta', 'hithean_export_lists_bump_on_meta', 10, 3);
add_action('deleted_post_meta', 'hithean_export_lists_bump_on_meta', 10, 3);

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

    update_post_meta($order_id, 'warehouse_export_ai_review_status', [
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
        update_post_meta($order_id, 'warehouse_export_ai_review_status', ['state' => 'failed', 'updated_at' => current_time('mysql')]);
        return;
    }

    $result = hithean_export_ai_check_order($order, $requested_by);
    if (is_wp_error($result)) {
        update_post_meta($order_id, 'warehouse_export_ai_review_status', [
            'state'      => 'failed',
            'updated_at' => current_time('mysql'),
            'error'      => sanitize_text_field($result->get_error_message()),
        ]);
        $order->add_order_note('🤖 AI review ảnh xuất kho chưa hoàn tất: ' . sanitize_text_field($result->get_error_message()));
        return;
    }

    update_post_meta($order_id, 'warehouse_export_ai_review_status', ['state' => 'completed', 'updated_at' => current_time('mysql')]);
}, 10, 1);

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

    update_post_meta($order_id, 'warehouse_export_lark_status', ['state' => 'queued', 'updated_at' => current_time('mysql')]);
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
        update_post_meta($order_id, 'warehouse_export_lark_status', ['state' => 'disabled', 'updated_at' => current_time('mysql')]);
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
        update_post_meta($order_id, 'warehouse_export_lark_status', ['state' => 'skipped_passed', 'updated_at' => current_time('mysql')]);
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
        update_post_meta($order_id, 'warehouse_export_lark_status', ['state' => 'failed', 'updated_at' => current_time('mysql'), 'error' => sanitize_text_field($error)]);
        if ($attempt < 2) {
            wp_schedule_single_event(time() + 60, 'hithean_export_lark_notify_async', [$order_id, $attempt + 1]);
        }
        return;
    }

    update_post_meta($order_id, 'warehouse_export_lark_notified_at', current_time('mysql'));
    update_post_meta($order_id, 'warehouse_export_lark_status', ['state' => 'sent', 'updated_at' => current_time('mysql')]);
}, 10, 2);

add_action('hithean_warehouse_export_confirmed', function ($order): void {
    if ($order instanceof WC_Order) {
        hithean_export_lark_queue_notification($order->get_id());
    }
});

add_action('hithean_export_ai_check_completed', function ($order): void {
    if ($order instanceof WC_Order && get_post_meta($order->get_id(), 'export_confirmed_by', true)) {
        hithean_export_lark_queue_notification($order->get_id());
    }
});

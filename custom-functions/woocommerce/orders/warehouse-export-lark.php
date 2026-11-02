<?php
defined('ABSPATH') || exit;

function hithean_export_lark_settings(): array
{
    $saved = get_option('hithean_export_lark_settings', []);
    return wp_parse_args(is_array($saved) ? $saved : [], ['enabled' => '0', 'only_alerts' => '0', 'webhook_url' => '']);
}

function hithean_export_lark_sanitize_webhook($value): string
{
    $url = esc_url_raw(trim((string) $value));
    $parts = wp_parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    return ($parts['scheme'] ?? '') === 'https'
        && preg_match('/(^|\.)(larksuite\.com|larkoffice\.com|feishu\.cn)$/', $host)
        && empty($parts['user']) && empty($parts['pass'])
        && (!isset($parts['port']) || (int) $parts['port'] === 443)
        && preg_match('~^/open-apis/bot/v2/hook/[a-zA-Z0-9_-]+$~', $parts['path'] ?? '')
        && empty($parts['query']) && empty($parts['fragment']) ? $url : '';
}

/** Lark may return HTTP 200 with a nonzero application error code. */
function hithean_export_lark_response_error($response): string
{
    if (is_wp_error($response)) {
        // Transport errors can contain the secret webhook URL. Never persist it.
        return 'Không kết nối được Lark; kiểm tra mạng máy chủ và thử lại.';
    }
    $http = (int) wp_remote_retrieve_response_code($response);
    if ($http < 200 || $http >= 300) return 'Webhook HTTP ' . $http;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || (!isset($body['code']) && !isset($body['StatusCode']))) {
        return 'Lark trả phản hồi không hợp lệ.';
    }
    foreach (['code', 'StatusCode'] as $key) {
        if (isset($body[$key]) && (!is_numeric($body[$key]) || (float) $body[$key] !== 0.0)) {
            $code = is_numeric($body[$key]) ? (int) $body[$key] : 'invalid';
            return 'Lark từ chối tin nhắn (mã ' . $code . '); kiểm tra webhook, từ khóa, chữ ký và IP được phép của bot.';
        }
    }
    return '';
}

function hithean_export_lark_send(string $text): string
{
    $settings = hithean_export_lark_settings();
    $url = hithean_export_lark_sanitize_webhook($settings['webhook_url']);
    if (empty($settings['enabled']) || $url === '') return 'Lark chưa bật hoặc webhook không hợp lệ.';
    $error = hithean_export_lark_response_error(wp_remote_post($url, [
        'timeout' => 8, 'redirection' => 0,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body' => wp_json_encode(['msg_type' => 'text', 'content' => ['text' => $text]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]));
    update_option('hithean_export_lark_last_delivery', ['state' => $error === '' ? 'sent' : 'failed', 'error' => $error, 'updated_at' => current_time('mysql')], false);
    return $error;
}

/** Short option locks serialize enrollment and prevent overlapping send workers. */
function hithean_export_lark_lock(string $key): bool
{
    $expires = (int) get_option($key, 0);
    if ($expires && $expires < time()) delete_option($key);
    return add_option($key, time() + 60, '', false);
}

function hithean_export_lark_status(int $order_id, string $key, string $state, string $error = '', array $extra = []): void
{
    thean_order_meta_set($order_id, $key, array_merge(['state' => $state, 'updated_at' => current_time('mysql'), 'error' => $error], $extra));
}

/** A mismatch for the same evidence and verdict alerts once; new photos may alert again. */
function hithean_export_lark_alert_hash(int $order_id, array $review): string
{
    return hash('sha256', wp_json_encode([
        get_post_meta($order_id, 'warehouse_export_images', true),
        $review['overall'] ?? '', $review['order_match'] ?? '', $review['items_match'] ?? '',
    ]));
}

function hithean_export_lark_queue_alert(int $order_id, array $review): void
{
    $settings = hithean_export_lark_settings();
    if (($review['overall'] ?? '') !== 'review' || empty($settings['enabled']) || hithean_export_lark_sanitize_webhook($settings['webhook_url']) === '') return;
    $hash = hithean_export_lark_alert_hash($order_id, $review);
    if (get_post_meta($order_id, 'warehouse_export_lark_alert_hash', true) === $hash) return;
    for ($attempt = 0; $attempt <= 2; $attempt++) {
        if (wp_next_scheduled('hithean_export_lark_alert_async', [$order_id, $hash, $attempt])) return;
    }
    $queued = wp_schedule_single_event(time(), 'hithean_export_lark_alert_async', [$order_id, $hash, 0]);
    hithean_export_lark_status($order_id, 'warehouse_export_lark_alert_status', $queued ? 'queued' : 'failed', $queued ? '' : 'Không tạo được lịch gửi cảnh báo.');
}

add_action('hithean_export_lark_alert_async', function ($order_id, $hash, $attempt = 0): void {
    $order_id = absint($order_id);
    $attempt = absint($attempt);
    $order = wc_get_order($order_id);
    $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    if (!$order || !is_array($review) || ($review['overall'] ?? '') !== 'review'
        || hithean_export_lark_alert_hash($order_id, $review) !== $hash
        || get_post_meta($order_id, 'warehouse_export_lark_alert_hash', true) === $hash) return;
    $lock = 'hithean_lark_alert_lock_' . $order_id;
    if (!hithean_export_lark_lock($lock)) {
        if ($attempt < 2) wp_schedule_single_event(time() + 60, 'hithean_export_lark_alert_async', [$order_id, $hash, $attempt + 1]);
        return;
    }
    try {
        // Recheck after acquiring the lock in case another worker just delivered it.
        if (get_post_meta($order_id, 'warehouse_export_lark_alert_hash', true) === $hash) return;
        $text = '⚠️ Xuất kho #' . $order->get_order_number() . ' — AI: Không khớp. Cần kiểm tra thủ công.';
        $error = hithean_export_lark_send($text);
        hithean_export_lark_status($order_id, 'warehouse_export_lark_alert_status', $error === '' ? 'sent' : 'failed', $error);
        if ($error === '') {
            thean_order_meta_set($order_id, 'warehouse_export_lark_alert_hash', $hash);
        } elseif ($attempt < 2) {
            wp_schedule_single_event(time() + 60, 'hithean_export_lark_alert_async', [$order_id, $hash, $attempt + 1]);
        }
    } finally {
        delete_option($lock);
    }
}, 10, 3);

/** Confirmation batches have a fixed deadline, never extended by later orders. */
function hithean_export_lark_queue_notification(int $order_id, int $attempt = 0): void
{
    $settings = hithean_export_lark_settings();
    if (!$order_id || get_post_meta($order_id, 'warehouse_export_lark_notified_at', true)
        || empty($settings['enabled']) || hithean_export_lark_sanitize_webhook($settings['webhook_url']) === '') return;
    $lock = 'hithean_lark_batch_queue_lock';
    if (!hithean_export_lark_lock($lock)) {
        if ($attempt < 12) wp_schedule_single_event(time() + 10, 'hithean_export_lark_notify_async', [$order_id, $attempt + 1]);
        return;
    }
    try {
        $status = get_post_meta($order_id, 'warehouse_export_lark_status', true);
        if (is_array($status) && !empty($status['batch_id']) && get_option('hithean_export_lark_batch_' . $status['batch_id'], [])) return;
        $batch_id = (string) get_option('hithean_export_lark_active_batch', '');
        $batch = $batch_id !== '' ? get_option('hithean_export_lark_batch_' . $batch_id, []) : [];
        if (!$batch || (int) $batch['due_at'] <= time() || ($batch['state'] ?? '') !== 'queued') {
            $batch_id = wp_generate_uuid4();
            $batch = ['state' => 'queued', 'started_at' => time(), 'due_at' => time() + 30 * MINUTE_IN_SECONDS, 'orders' => []];
            if (!wp_schedule_single_event($batch['due_at'], 'hithean_export_lark_batch_async', [$batch_id, 0])) {
                hithean_export_lark_status($order_id, 'warehouse_export_lark_status', 'failed', 'Không tạo được lịch gửi lô.');
                return;
            }
            update_option('hithean_export_lark_active_batch', $batch_id, false);
        }
        $batch['orders'][$order_id] = $order_id;
        update_option('hithean_export_lark_batch_' . $batch_id, $batch, false);
        hithean_export_lark_status($order_id, 'warehouse_export_lark_status', 'queued', '', ['due_at' => $batch['due_at'], 'batch_id' => $batch_id]);
    } finally {
        delete_option($lock);
    }
}

// Old single-order cron events are migrated into the batch flow, not sent individually.
add_action('hithean_export_lark_notify_async', 'hithean_export_lark_queue_notification', 10, 2);

add_action('hithean_export_lark_batch_async', function ($batch_id, $attempt = 0): void {
    $attempt = absint($attempt);
    if (!is_string($batch_id) || !preg_match('/^[a-zA-Z0-9-]+$/', $batch_id)) return;
    $key = 'hithean_export_lark_batch_' . $batch_id;
    $batch = get_option($key, []);
    if (!$batch || in_array($batch['state'] ?? '', ['sent', 'skipped'], true)) return;
    if (time() < (int) $batch['due_at']) return;
    $lock = $key . '_send_lock';
    if (!hithean_export_lark_lock($lock)) {
        wp_schedule_single_event(time() + 10, 'hithean_export_lark_batch_async', [$batch_id, $attempt]);
        return;
    }
    try {
        // Only hold the enrollment lock while closing the batch, never during HTTP.
        $queue_lock = 'hithean_lark_batch_queue_lock';
        if (!hithean_export_lark_lock($queue_lock)) {
            wp_schedule_single_event(time() + 10, 'hithean_export_lark_batch_async', [$batch_id, $attempt]);
            return;
        }
        try {
            $batch = get_option($key, []);
            if (!$batch || in_array($batch['state'] ?? '', ['sent', 'skipped'], true)) return;
            $batch['state'] = 'sending';
            update_option($key, $batch, false);
        } finally {
            delete_option($queue_lock);
        }
        // Save a stable payload for bounded delivery retries.
        if (!isset($batch['text'])) {
            $settings = hithean_export_lark_settings();
            $lines = [];
            $included = [];
            foreach ($batch['orders'] as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;
                $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
                $passed = is_array($review) && ($review['overall'] ?? '') === 'pass';
                if ($passed && !empty($settings['only_alerts'])) {
                    hithean_export_lark_status($order_id, 'warehouse_export_lark_status', 'skipped_passed');
                    continue;
                }
                $included[] = $order_id;
                $verdict = !is_array($review) ? 'Chưa có kết quả' : ($passed ? 'Đạt' : 'Không khớp');
                if (count($lines) < 150) $lines[] = '#' . $order->get_order_number() . ' — AI: ' . $verdict;
            }
            $batch['included'] = $included;
            $batch['text'] = $included ? '📦 Xuất kho — lô xác nhận 30 phút: ' . count($included) . " đơn\n" . implode("\n", $lines) : '';
            if (count($included) > count($lines)) $batch['text'] .= "\nVà " . (count($included) - count($lines)) . ' đơn khác (xem danh sách xuất kho).';
        }
        $error = $batch['text'] !== '' ? hithean_export_lark_send($batch['text']) : '';
        $state = $error !== '' ? 'failed' : ($batch['text'] === '' ? 'skipped' : 'sent');
        $batch['state'] = $state;
        $batch['error'] = $error;
        update_option($key, $batch, false);
        foreach ($batch['included'] as $order_id) {
            hithean_export_lark_status($order_id, 'warehouse_export_lark_status', $state, $error, ['batch_id' => $batch_id]);
            if ($state === 'sent') thean_order_meta_set($order_id, 'warehouse_export_lark_notified_at', current_time('mysql'));
        }
        if ($error !== '' && $attempt < 2) {
            wp_schedule_single_event(time() + 60, 'hithean_export_lark_batch_async', [$batch_id, $attempt + 1]);
        } else {
            if (get_option('hithean_export_lark_active_batch') === $batch_id) delete_option('hithean_export_lark_active_batch');
            delete_option($key); // Per-order status retains the outcome; do not accumulate queue options.
        }
    } finally {
        delete_option($lock);
    }
}, 10, 2);

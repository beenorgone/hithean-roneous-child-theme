<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/warehouse-export-lark-content.php';
require_once __DIR__ . '/warehouse-export-lark-follow-up.php';

child_theme_lark_register_channel('kho', [
    'label' => 'Xuất kho', 'default_active' => false,
    'description' => 'AI ảnh xuất kho không khớp (báo ngay + nhắc 30 phút), tổng hợp xác nhận xuất kho',
]);

/**
 * Cài đặt Lark của xuất kho. Webhook + bật/tắt nằm ở kênh "kho" (core/lark.php, Cài đặt ERP → Lark);
 * option hithean_export_lark_settings chỉ còn only_alerts + stop_reminder_user_ids.
 */
function hithean_export_lark_settings(): array
{
    $saved = get_option('hithean_export_lark_settings', []);
    $settings = wp_parse_args(is_array($saved) ? $saved : [], ['only_alerts' => '0', 'stop_reminder_user_ids' => []]);
    // enabled = kênh "kho" đang bật VÀ có webhook hợp lệ.
    $settings['enabled'] = child_theme_lark_ready('kho') ? '1' : '0';
    return $settings;
}

/** Tên cũ — kiểm webhook ở core/lark.php. */
function hithean_export_lark_sanitize_webhook($value): string
{
    return child_theme_lark_sanitize_webhook($value);
}

function hithean_export_lark_response_error($response): string
{
    return child_theme_lark_response_error($response);
}

function hithean_export_lark_send($message): string
{
    $payload = is_array($message) ? $message : ['msg_type' => 'text', 'content' => ['text' => (string) $message]];
    $error = child_theme_lark_send('kho', $payload);
    update_option('hithean_export_lark_last_delivery', ['state' => $error === '' ? 'sent' : 'failed', 'error' => $error, 'updated_at' => current_time('mysql')], false);
    return $error;
}

/** Ô riêng của xuất kho trong tab Cài đặt ERP → Lark. */
add_action('child_theme_lark_settings_fields', static function (): void {
    $settings = hithean_export_lark_settings();
    $stop = array_map('absint', (array) ($settings['stop_reminder_user_ids'] ?? []));
    echo '<h3>Xuất kho</h3><p><label><input type="checkbox" name="hithean_export_lark_only_alerts" value="1" ' . checked(!empty($settings['only_alerts']), true, false) . '> Chỉ gửi cảnh báo khi AI không đạt (bỏ đơn AI đạt khỏi tin tổng hợp)</label></p>';
    echo '<fieldset><legend><strong>User được dừng nhắc Lark</strong></legend>';
    foreach (get_users(['capability' => 'manage_woocommerce', 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
        echo '<label style="display:block;margin:5px 0"><input type="checkbox" name="hithean_export_lark_stop_users[]" value="' . (int) $user->ID . '" ' . checked(in_array((int) $user->ID, $stop, true), true, false) . '> ' . esc_html($user->display_name . ' (' . $user->user_login . ')') . '</label>';
    }
    echo '<p class="description">AI không khớp: báo ngay kèm nhận định, người xử lý, link đơn và ảnh; nhắc mỗi 30 phút tới khi ảnh hiện tại được AI xác nhận khớp. Chỉ user được chọn mới kết thúc nhắc khi xác nhận đã xử lý. Xác nhận xuất kho gom lô 30 phút. Cần WP-Cron. Bot có từ khóa phải chấp nhận “Xuất kho” / “AI”.</p></fieldset>';
});
add_action('child_theme_lark_settings_save', static function (array $posted): void {
    $saved = get_option('hithean_export_lark_settings', []);
    $saved = is_array($saved) ? $saved : [];
    $saved['only_alerts'] = !empty($posted['hithean_export_lark_only_alerts']) ? '1' : '0';
    $saved['stop_reminder_user_ids'] = array_values(array_intersect(
        array_map('absint', (array) ($posted['hithean_export_lark_stop_users'] ?? [])),
        array_map(static fn($user) => (int) $user->ID, get_users(['capability' => 'manage_woocommerce', 'fields' => ['ID']]))
    ));
    unset($saved['enabled'], $saved['webhook_url']); // đã chuyển sang kênh "kho"
    update_option('hithean_export_lark_settings', $saved, false);
});

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

/** Send once per AI review, including after upgrading the alert format. */
function hithean_export_lark_alert_hash(int $order_id, array $review): string
{
    return hash('sha256', wp_json_encode([
        'lark-review-card-v2',
        get_post_meta($order_id, 'warehouse_export_images', true),
        $review['overall'] ?? '', $review['order_match'] ?? '', $review['items_match'] ?? '',
        $review['checked_at'] ?? '',
    ]));
}

function hithean_export_lark_queue_alert(int $order_id, array $review): void
{
    $settings = hithean_export_lark_settings();
    if (($review['overall'] ?? '') !== 'review' || empty($settings['enabled'])) return;
    hithean_export_lark_schedule_reminder($order_id);
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
        $elements = hithean_export_lark_order_elements($order, $review);
        $elements[] = ['tag' => 'div', 'text' => ['tag' => 'plain_text', 'content' => hithean_export_lark_reminder_notice()]];
        $error = hithean_export_lark_send(hithean_export_lark_card('⚠️ Xuất kho — AI không khớp, cần kiểm tra', $elements));
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
        || empty($settings['enabled'])) return;
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
        if (!isset($batch['text']) && !isset($batch['message'])) {
            $settings = hithean_export_lark_settings();
            $elements = [];
            $shown = 0;
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
                $candidate = array_merge($elements, hithean_export_lark_order_elements($order, is_array($review) ? $review : []), [['tag' => 'hr']]);
                if (strlen(wp_json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) < 17000) {
                    $elements = $candidate;
                    $shown++;
                }
            }
            $batch['included'] = $included;
            if (count($included) > $shown) {
                $elements[] = ['tag' => 'div', 'text' => ['tag' => 'plain_text', 'content' => 'Còn ' . (count($included) - $shown) . ' đơn khác; xem đầy đủ kết quả AI và ảnh trong danh sách đơn hàng.']];
                $elements[] = ['tag' => 'action', 'actions' => [hithean_export_lark_button('Mở danh sách đơn', thean_order_list_url())]];
            }
            $batch['message'] = $included ? hithean_export_lark_card('📦 Xuất kho — lô xác nhận 30 phút: ' . count($included) . ' đơn', $elements, 'blue') : '';
        }
        $message = $batch['message'] ?? $batch['text'];
        // Persist the exact payload before HTTP so a crashed/retried worker uses the same snapshot.
        update_option($key, $batch, false);
        $error = $message !== '' ? hithean_export_lark_send($message) : '';
        $state = $error !== '' ? 'failed' : ($message === '' ? 'skipped' : 'sent');
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

<?php
defined('ABSPATH') || exit;

function hithean_export_lark_reminder_notice(): string
{
    return '⏰ Tự động nhắc lại sau 30 phút nếu chưa có ảnh xuất kho được AI xác nhận khớp sản phẩm và số lượng. Chỉ user được ủy quyền trong Cài đặt ERP mới có thể dừng nhắc bằng nút xác nhận; user khác xác nhận đã xử lý nhưng nhắc vẫn tiếp tục.';
}

function hithean_export_lark_schedule_reminder(int $order_id): void
{
    for ($attempt = 0; $attempt <= 2; $attempt++) {
        if (wp_next_scheduled('hithean_export_lark_remind_async', [$order_id, $attempt])) return;
    }
    $due = time() + 30 * MINUTE_IN_SECONDS;
    $queued = wp_schedule_single_event($due, 'hithean_export_lark_remind_async', [$order_id, 0]);
    hithean_export_lark_status($order_id, 'warehouse_export_lark_reminder_status', $queued ? 'queued' : 'failed', $queued ? '' : 'Không tạo được lịch nhắc.', ['due_at' => $due]);
}

function hithean_export_lark_stop_reminders(int $order_id, string $state = 'completed'): void
{
    wp_clear_scheduled_hook('hithean_export_lark_remind_async', [$order_id, 0]);
    wp_clear_scheduled_hook('hithean_export_lark_remind_async', [$order_id, 1]);
    wp_clear_scheduled_hook('hithean_export_lark_remind_async', [$order_id, 2]);
    hithean_export_lark_status($order_id, 'warehouse_export_lark_reminder_status', $state);
}

function hithean_export_lark_user_can_stop_reminders(int $user_id): bool
{
    $settings = hithean_export_lark_settings();
    return $user_id > 0 && in_array($user_id, array_map('absint', (array) ($settings['stop_reminder_user_ids'] ?? [])), true);
}

add_action('hithean_export_lark_remind_async', function ($order_id, $attempt = 0): void {
    $order_id = absint($order_id);
    $attempt = absint($attempt);
    if (!$order_id || !($order = wc_get_order($order_id))) return;
    $resolution = get_post_meta($order_id, 'warehouse_export_ai_resolution', true);
    $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    if (is_array($resolution) && !empty($resolution['stop_reminders']) && is_array($review) && ($resolution['hash'] ?? '') === hithean_export_lark_alert_hash($order_id, $review)) {
        hithean_export_lark_stop_reminders($order_id, 'acknowledged');
        return;
    }
    if (hithean_export_lark_current_pass($order_id)) {
        hithean_export_lark_stop_reminders($order_id);
        return;
    }
    $settings = hithean_export_lark_settings();
    if (empty($settings['enabled']) || hithean_export_lark_sanitize_webhook($settings['webhook_url']) === '') {
        hithean_export_lark_stop_reminders($order_id, 'disabled');
        return;
    }
    $lock = 'hithean_lark_reminder_lock_' . $order_id;
    if (!hithean_export_lark_lock($lock)) return;
    try {
        $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
        $elements = hithean_export_lark_order_elements($order, is_array($review) ? $review : []);
        $elements[] = ['tag' => 'div', 'text' => ['tag' => 'plain_text', 'content' => hithean_export_lark_reminder_notice()]];
        $error = hithean_export_lark_send(hithean_export_lark_card('⚠️ Xuất kho — nhắc lại: chưa có ảnh AI khớp', $elements));
        hithean_export_lark_status($order_id, 'warehouse_export_lark_reminder_status', $error === '' ? 'sent' : 'failed', $error);
        if ($error !== '' && $attempt < 2) {
            wp_schedule_single_event(time() + 60, 'hithean_export_lark_remind_async', [$order_id, $attempt + 1]);
        } else {
            hithean_export_lark_schedule_reminder($order_id);
        }
    } finally {
        delete_option($lock);
    }
}, 10, 2);

/** Acknowledgment records staff work; it never changes AI/shipping state. */
function hithean_export_lark_acknowledge(int $order_id, string $hash, string $nonce)
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) return new WP_Error('forbidden', 'Bạn không có quyền xử lý đơn hàng.');
    if (!wp_verify_nonce($nonce, 'hithean_export_lark_resolve_' . $order_id . '_' . $hash)) return new WP_Error('invalid_nonce', 'Phiên xác nhận đã hết hạn. Hãy tải lại trang.');
    $order = wc_get_order($order_id);
    $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    if (!$order || !is_array($review) || ($review['overall'] ?? '') !== 'review' || hithean_export_lark_alert_hash($order_id, $review) !== $hash) {
        return new WP_Error('stale_review', 'Ảnh hoặc kết quả AI đã thay đổi. Hãy mở đơn để kiểm tra kết quả hiện tại.');
    }
    $lock = 'hithean_lark_resolve_lock_' . $order_id;
    if (!hithean_export_lark_lock($lock)) return new WP_Error('busy', 'Đơn đang được xác nhận. Vui lòng thử lại.');
    try {
        $saved = get_post_meta($order_id, 'warehouse_export_ai_resolution', true);
        $user_id = get_current_user_id();
        $can_stop = hithean_export_lark_user_can_stop_reminders($user_id);
        if (is_array($saved) && ($saved['hash'] ?? '') === $hash) {
            if ($can_stop && empty($saved['stop_reminders'])) {
                $saved['stop_reminders'] = true;
                $saved['stopped_by'] = $user_id;
                $saved['stopped_at'] = current_time('mysql');
                thean_order_meta_set($order_id, 'warehouse_export_ai_resolution', $saved);
                hithean_export_lark_stop_reminders($order_id, 'acknowledged');
            }
            return $saved;
        }
        $saved = ['hash' => $hash, 'resolved_by' => $user_id, 'resolved_at' => current_time('mysql'), 'stop_reminders' => $can_stop];
        if ($can_stop) { $saved['stopped_by'] = $user_id; $saved['stopped_at'] = $saved['resolved_at']; }
        thean_order_meta_set($order_id, 'warehouse_export_ai_resolution', $saved);
        if ($can_stop) hithean_export_lark_stop_reminders($order_id, 'acknowledged');
        $user = wp_get_current_user();
        $order->add_order_note('✓ ' . hithean_export_lark_clean_text($user->display_name, 120) . ' xác nhận đã xử lý cảnh báo AI qua Lark.' . ($can_stop ? ' User được cấp quyền dừng nhắc tự động.' : ' Lark vẫn nhắc sau mỗi 30 phút cho đến khi user được cấp quyền dừng hoặc ảnh hiện tại được AI kiểm tra khớp.'));
        return $saved;
    } finally {
        delete_option($lock);
    }
}

add_action('admin_post_nopriv_hithean_export_lark_review', 'auth_redirect');
add_action('admin_post_hithean_export_lark_review', function (): void {
    if (!current_user_can('manage_woocommerce')) wp_die('Bạn không có quyền xử lý đơn hàng.', '', ['response' => 403]);
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    $id = absint($_REQUEST['order_id'] ?? 0);
    $hash = sanitize_text_field(wp_unslash($_REQUEST['review'] ?? ''));
    $order = wc_get_order($id);
    $review = get_post_meta($id, 'warehouse_export_ai_check', true);
    if (!$order || !is_array($review) || ($review['overall'] ?? '') !== 'review' || hithean_export_lark_alert_hash($id, $review) !== $hash) {
        $link = $order ? '<p><a href="' . esc_url(thean_order_edit_url($id)) . '">Mở đơn hàng và kết quả AI hiện tại →</a></p>' : '';
        wp_die('Ảnh hoặc kết quả AI đã thay đổi.' . $link, 'Cảnh báo đã thay đổi', ['response' => 409]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = hithean_export_lark_acknowledge($id, $hash, sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')));
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), 'Chưa xác nhận', ['response' => 400]);
        wp_safe_redirect(hithean_export_lark_review_url($id, $hash));
        exit;
    }
    $saved = get_post_meta($id, 'warehouse_export_ai_resolution', true);
    $resolved = is_array($saved) && ($saved['hash'] ?? '') === $hash;
    $html = '<h1>Cảnh báo AI — đơn #' . esc_html($order->get_order_number()) . '</h1>';
    $html .= '<p>Người xử lý / xuất kho: ' . esc_html(hithean_export_lark_staff($id)) . '</p>';
    $html .= '<p style="white-space:pre-line">' . esc_html(hithean_export_lark_ai_details($review)) . '</p>';
    $html .= '<p>' . esc_html(hithean_export_lark_reminder_notice()) . '</p>';
    if ($resolved) {
        $user = get_userdata(absint($saved['resolved_by']));
        $html .= '<p role="status"><strong>✓ Đã xử lý: ' . esc_html($user ? $user->display_name : 'Nhân viên') . '</strong> — ' . esc_html($saved['resolved_at']) . ($saved['stop_reminders'] ?? false ? ' — đã dừng nhắc' : ' — nhắc vẫn tiếp tục') . '</p>';
        if (empty($saved['stop_reminders'])) {
            $html .= '<p>User được ủy quyền dừng nhắc có thể xác nhận lại tại đây.</p>';
            $html .= '<form method="post" action="' . esc_url(hithean_export_lark_review_url($id, $hash)) . '">'
                . wp_nonce_field('hithean_export_lark_resolve_' . $id . '_' . $hash, '_wpnonce', true, false)
                . '<button type="submit">✓ Xác nhận và dừng nhắc (user được ủy quyền)</button></form>';
        }
    } else {
        $html .= '<form method="post" action="' . esc_url(hithean_export_lark_review_url($id, $hash)) . '">'
            . wp_nonce_field('hithean_export_lark_resolve_' . $id . '_' . $hash, '_wpnonce', true, false)
            . '<button type="submit" style="background:#0047ba;color:#fff;border:0;border-radius:6px;padding:12px 18px;cursor:pointer;font-size:15px">✓ ' . (hithean_export_lark_user_can_stop_reminders(get_current_user_id()) ? 'Xác nhận đã xử lý và dừng nhắc' : 'Xác nhận đã xử lý') . '</button></form>';
    }
    $html .= '<p><a href="' . esc_url(thean_order_edit_url($id)) . '">Mở đơn hàng và ảnh xuất kho →</a></p>';
    wp_die($html, 'Xác nhận xử lý cảnh báo AI', ['response' => 200]);
});

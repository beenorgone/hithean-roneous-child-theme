<?php
defined('ABSPATH') || exit;

/** Evidence identity is independent of metadata whitespace and array ordering. */
function hithean_export_lark_images_hash(array $urls): string
{
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
    sort($urls, SORT_STRING);
    return hash('sha256', wp_json_encode($urls));
}

function hithean_export_lark_current_images(int $order_id): array
{
    return array_values(array_filter(array_map('trim', explode("\n", (string) get_post_meta($order_id, 'warehouse_export_images', true)))));
}

function hithean_export_lark_current_pass(int $order_id): bool
{
    $review = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    $state = get_post_meta($order_id, 'warehouse_export_ai_review_status', true);
    $urls = hithean_export_lark_current_images($order_id);
    return $urls && is_array($review) && ($review['overall'] ?? '') === 'pass'
        && ($review['order_match'] ?? '') === 'match' && ($review['items_match'] ?? '') === 'match'
        && (!is_array($state) || ($state['state'] ?? '') !== 'queued')
        && ($review['images_hash'] ?? '') === hithean_export_lark_images_hash($urls);
}

function hithean_export_lark_clean_text($value, int $limit = 600): string
{
    if (!is_scalar($value)) return '';
    $text = trim(wp_strip_all_tags((string) $value));
    return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
}

/** This workflow assigns warehouse staff through export logins and uploader IDs. */
function hithean_export_lark_staff(int $order_id): string
{
    $names = [];
    foreach ((array) get_post_meta($order_id, 'order_export_by', false) as $row) {
        foreach ((array) $row as $login) {
            if (!is_scalar($login) || (string) $login === '') continue;
            $user = get_user_by('login', (string) $login);
            $names[] = $user ? $user->display_name : (string) $login;
        }
    }
    foreach ((array) get_post_meta($order_id, 'warehouse_export_uploaded_by', true) as $id) {
        $user = get_userdata(absint($id));
        if ($user) $names[] = $user->display_name;
    }
    $names = array_values(array_unique(array_filter(array_map('hithean_export_lark_clean_text', $names))));
    return $names ? implode(', ', array_slice($names, 0, 12)) : 'Chưa ghi nhận';
}

function hithean_export_lark_ai_details(array $review): string
{
    $labels = ['match' => 'Khớp', 'mismatch' => 'Không khớp', 'unclear' => 'Chưa rõ'];
    $confidence = ['high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'];
    if (!$review) return 'AI: Chưa có kết quả cho ảnh hiện tại.';
    $lines = [
        'Mã đơn/phiếu: ' . ($labels[$review['order_match'] ?? ''] ?? 'Chưa rõ'),
        'Sản phẩm / số lượng: ' . ($labels[$review['items_match'] ?? ''] ?? 'Chưa rõ'),
        'Độ tin cậy: ' . ($confidence[$review['confidence'] ?? ''] ?? 'Chưa rõ'),
    ];
    foreach (['visible_order_reference' => 'AI đọc được', 'reason' => 'Nhận định', 'checked_at' => 'Kiểm tra lúc'] as $key => $label) {
        $value = hithean_export_lark_clean_text($review[$key] ?? '');
        if ($value !== '') $lines[] = $label . ': ' . $value;
    }
    foreach (['missing_or_suspected_missing' => 'Thiếu / nghi thiếu', 'unexpected_or_suspected_extra' => 'Thừa / nghi thừa', 'shipping_codes' => 'Mã vận đơn'] as $key => $label) {
        $items = [];
        foreach (array_slice((array) ($review[$key] ?? []), 0, 8) as $item) {
            $text = hithean_export_lark_clean_text($item, 250);
            if ($text !== '') $items[] = $text;
        }
        if ($items) $lines[] = $label . ': ' . implode('; ', $items);
    }
    return implode("\n", $lines);
}

function hithean_export_lark_review_url(int $order_id, string $hash): string
{
    return add_query_arg(['action' => 'hithean_export_lark_review', 'order_id' => $order_id, 'review' => $hash], admin_url('admin-post.php'));
}

function hithean_export_lark_button(string $label, string $url, string $type = 'default'): array
{
    return ['tag' => 'button', 'type' => $type, 'text' => ['tag' => 'plain_text', 'content' => $label], 'url' => $url];
}

/** AI text is plain_text: model output cannot inject mentions, links or card markup. */
function hithean_export_lark_order_elements(WC_Order $order, array $review): array
{
    $id = $order->get_id();
    $verdict = !$review ? 'Chưa có kết quả' : (($review['overall'] ?? '') === 'pass'
        ? (hithean_export_lark_current_pass($id) ? 'Đạt' : 'Chưa có kết quả khớp ảnh hiện tại') : 'Không khớp');
    $text = '#' . hithean_export_lark_clean_text($order->get_order_number(), 80) . ' — AI: ' . $verdict
        . "\nNgười xử lý / xuất kho: " . hithean_export_lark_clean_text(hithean_export_lark_staff($id), 500);
    $confirmed = get_userdata(absint(get_post_meta($id, 'export_confirmed_by', true)));
    if ($confirmed) $text .= "\nXác nhận xuất kho: " . hithean_export_lark_clean_text($confirmed->display_name, 120);
    $resolution = get_post_meta($id, 'warehouse_export_ai_resolution', true);
    $hash = hithean_export_lark_alert_hash($id, $review);
    $resolved = is_array($resolution) && ($resolution['hash'] ?? '') === $hash;
    if ($resolved) {
        $user = get_userdata(absint($resolution['resolved_by'] ?? 0));
        $text .= "\n✓ Đã xử lý cảnh báo: " . ($user ? hithean_export_lark_clean_text($user->display_name, 120) : 'Nhân viên') . ' — ' . ($resolution['resolved_at'] ?? '');
    }
    $text .= "\n" . hithean_export_lark_ai_details($review);
    $short = hithean_export_lark_clean_text($text, 3000);
    if ($short !== $text) $short .= "\n… Xem đầy đủ kết quả trong đơn hàng.";
    $elements = [['tag' => 'div', 'text' => ['tag' => 'plain_text', 'content' => $short]]];
    $buttons = [hithean_export_lark_button('Mở đơn #' . $order->get_order_number(), thean_order_edit_url($id), 'primary')];
    if (($review['overall'] ?? '') === 'review') {
        $buttons[] = hithean_export_lark_button($resolved ? '✓ Đã xử lý — xem chi tiết' : '✓ Xác nhận đã xử lý', hithean_export_lark_review_url($id, $hash));
    }
    $urls = array_values(array_unique(hithean_export_lark_current_images($id)));
    $shown = 0;
    foreach ($urls as $url) {
        $parts = wp_parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) continue;
        $url = esc_url_raw($url);
        if ($url === '' || strlen($url) > 2048) continue;
        $button = hithean_export_lark_button('Ảnh ' . ($shown + 1), $url);
        if (strlen(wp_json_encode([$elements, $buttons, $button], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 16000) break;
        $buttons[] = $button;
        $shown++;
        if ($shown >= 10) break;
    }
    foreach (array_chunk($buttons, 5) as $row) $elements[] = ['tag' => 'action', 'actions' => $row];
    if (count($urls) > $shown) $elements[] = ['tag' => 'note', 'elements' => [['tag' => 'plain_text', 'content' => 'Xem đầy đủ ảnh trong đơn hàng.']]];
    return $elements;
}

function hithean_export_lark_card(string $title, array $elements, string $tone = 'orange'): array
{
    return ['msg_type' => 'interactive', 'card' => ['config' => ['wide_screen_mode' => true], 'header' => ['template' => $tone, 'title' => ['tag' => 'plain_text', 'content' => $title]], 'elements' => $elements]];
}

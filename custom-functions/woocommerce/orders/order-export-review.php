<?php
if (!defined('ABSPATH')) exit;

/** Read primary order metadata, with the same legacy-post fallback as the export tools. */
function thean_export_review_meta(WC_Order $order, string $key)
{
    $value = $order->get_meta($key);
    return empty($value) ? get_post_meta($order->get_id(), $key, true) : $value;
}

/** Review only: no recovery writes, AI calls, status changes or stock operations. */
function thean_export_review_data(WC_Order $order): array
{
    $raw = thean_export_review_meta($order, 'warehouse_export_images');
    $images = [];
    foreach (is_array($raw) ? $raw : (is_scalar($raw) ? explode("\n", (string) $raw) : []) as $url) {
        if (is_string($url) && ($url = esc_url_raw(trim($url), ['http', 'https'])) !== '') $images[] = $url;
    }
    $images = array_values(array_unique($images));
    $result = thean_export_review_meta($order, 'warehouse_export_ai_check');
    $result = is_array($result) ? $result : [];
    $queue = thean_export_review_meta($order, 'warehouse_export_ai_review_status');
    $queue = is_array($queue) ? $queue : [];
    $text = static fn($v): string => is_scalar($v) ? wp_strip_all_tags((string) $v) : '';
    $fields = [];
    if ($result) {
        $labels = ['match' => 'Khớp', 'mismatch' => 'Không khớp', 'unclear' => 'Chưa đủ bằng chứng'];
        $pass = ($result['overall'] ?? '') === 'pass' && ($result['order_match'] ?? '') === 'match' && ($result['items_match'] ?? '') === 'match';
        $fields = [
            ['label' => 'Kết quả', 'value' => $pass ? 'Khớp ảnh với đơn' : 'Cần kiểm tra thủ công'],
            ['label' => 'Mã đơn', 'value' => $labels[$text($result['order_match'] ?? '')] ?? 'Chưa đủ bằng chứng'],
            ['label' => 'Sản phẩm / số lượng', 'value' => $labels[$text($result['items_match'] ?? '')] ?? 'Chưa đủ bằng chứng'],
            ['label' => 'Độ tin cậy', 'value' => ['high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'][$text($result['confidence'] ?? '')] ?? 'Chưa rõ'],
        ];
    }
    $issues = [];
    foreach (['missing_or_suspected_missing', 'unexpected_or_suspected_extra'] as $key) {
        foreach (is_array($result[$key] ?? null) ? $result[$key] : [] as $issue) {
            if (($issue = $text($issue)) !== '') $issues[] = $issue;
        }
    }
    $note = '';
    if (function_exists('wc_get_order_notes')) {
        foreach (wc_get_order_notes(['order_id' => $order->get_id(), 'limit' => 15, 'type' => 'internal', 'orderby' => 'date_created', 'order' => 'DESC']) as $entry) {
            $content = $text($entry->content ?? '');
            if (preg_match('/AI (?:review|kiểm tra) ảnh xuất kho/iu', $content)) { $note = $content; break; }
        }
    }
    $items = [];
    foreach ($order->get_items() as $item) $items[] = ['name' => $item->get_name(), 'quantity' => $item->get_quantity()];
    $confirmed = (bool) thean_export_review_meta($order, 'export_confirmed_by');
    $locked = function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order);
    return [
        'order_id' => $order->get_id(), 'number' => $order->get_order_number(),
        'status' => wc_get_order_status_name($order->get_status()), 'items' => $items, 'images' => $images,
        'confirmed' => $confirmed, 'can_confirm' => (bool) $images && !$confirmed && !$locked,
        'blocked' => $confirmed ? 'Đơn đã được xác nhận xuất kho.' : (!$images ? 'Đơn chưa có ảnh xuất kho.' : ($locked ? 'Đơn đang bị khóa thao tác.' : '')),
        'ai' => ['state' => ($queue['state'] ?? '') === 'queued' ? 'queued' : ($result ? 'done' : ($note !== '' ? 'note' : 'none')),
            'fields' => $fields, 'reason' => $text($result['reason'] ?? ''), 'issues' => $issues,
            'checked_at' => $text($result['checked_at'] ?? ''), 'note' => $note],
    ];
}

add_action('wp_ajax_thean_order_export_review', 'thean_export_review_ajax');
function thean_export_review_ajax(): void
{
    check_ajax_referer('thean_order_export_review', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'Không có quyền duyệt ảnh xuất kho.'], 403);
    $raw = $_POST['order_id'] ?? '';
    $id = is_scalar($raw) ? absint($raw) : 0;
    if (!$id) wp_send_json_error(['message' => 'Mã đơn không hợp lệ.'], 400);
    if (!current_user_can('edit_shop_order', $id)) wp_send_json_error(['message' => 'Không có quyền sửa đơn này.'], 403);
    if (function_exists('thean_ops_order_cache_bust')) thean_ops_order_cache_bust([$id]);
    $order = wc_get_order($id);
    if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') wp_send_json_error(['message' => 'Không tìm thấy đơn.'], 404);
    wp_send_json_success(thean_export_review_data($order));
}

function thean_export_review_is_list(): bool
{
    if (!current_user_can('manage_woocommerce') || in_array($_GET['action'] ?? '', ['edit', 'new'], true)) return false;
    $screen = get_current_screen();
    return $screen && in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true);
}

add_action('admin_enqueue_scripts', function () {
    if (!thean_export_review_is_list()) return;
    $path = '/custom-functions/woocommerce/orders/assets/';
    $dir = get_stylesheet_directory() . $path;
    $uri = get_stylesheet_directory_uri() . $path;
    wp_enqueue_style('thean-export-review', $uri . 'order-export-review.css', [], (string) filemtime($dir . 'order-export-review.css'));
    wp_enqueue_script('thean-export-review', $uri . 'order-export-review.js', [], (string) filemtime($dir . 'order-export-review.js'), true);
    wp_add_inline_script('thean-export-review', 'window.theanExportReviewCfg = ' . wp_json_encode([
        'ajaxUrl' => admin_url('admin-ajax.php'), 'readNonce' => wp_create_nonce('thean_order_export_review'),
        'confirmNonce' => wp_create_nonce('ajax_confirm_export_nonce'),
    ]) . ';', 'before');
}, 30);

add_action('admin_footer', function () {
    if (!thean_export_review_is_list()) return;
    ?>
    <div class="thean-export-review" id="thean-export-review-modal" hidden>
        <div class="thean-export-review__backdrop" data-export-review-close></div>
        <section class="thean-export-review__dialog" role="dialog" aria-modal="true" aria-labelledby="thean-export-review-title" tabindex="-1">
            <button type="button" class="thean-export-review__close" data-export-review-close aria-label="Đóng duyệt ảnh">×</button>
            <h2 id="thean-export-review-title">Kiểm tra ảnh &amp; xác nhận xuất kho</h2>
            <p data-export-review-summary></p>
            <div data-export-review-content></div>
            <form data-export-review-form>
                <p class="thean-export-review__notice" role="status" aria-live="polite" data-export-review-notice></p>
                <div class="thean-export-review__actions">
                    <button type="button" data-export-review-reload>Tải lại ảnh &amp; AI</button>
                    <button type="button" data-export-review-close>Đóng</button>
                    <button type="submit" class="thean-export-review__confirm" disabled>Xác nhận xuất kho</button>
                </div>
            </form>
        </section>
    </div>
    <?php
}, 30);

<?php
if (!defined('ABSPATH')) exit;

/**
 * Bước vận hành của đơn, lưu sẵn thành meta để board "Cần xử lý" (order-universal-search.php) chỉ cần
 * đếm / phân trang bằng SQL — không phải đọc hàng trăm đơn mỗi lần mở.
 *
 * - `_thean_ops_step`    = tab board (confirm, payment, picking, export, delivery, shipping, cod, return) hoặc 'none'.
 * - `_thean_ops_step_v`  = THEAN_OPS_STEP_VERSION lúc tính (đánh dấu đơn đã được tính theo logic hiện tại).
 * Bước tính bằng thean_ow_flow() (order-workspace.php) — cùng logic bảng điều phối ở trang sửa đơn.
 *
 * ĐỔI LUỒNG VẬN HÀNH (thean_ow_flow, thean_ops_step_from_flow, trạng thái loại trừ…) → TĂNG THEAN_OPS_STEP_VERSION:
 * mọi đơn mở thành "chưa tính", cron + board tự tính lại (board bù 20 đơn mỗi lần mở, cron 50 đơn / 10 phút).
 * Thêm / bớt tab → sửa thean_ops_step_tabs() + thean_ops_step_from_flow() rồi tăng version.
 *
 * Cập nhật:
 * 1. Đơn được lưu (woocommerce_after_order_object_save) hoặc meta liên quan bị ghi thẳng bằng *_post_meta
 *    → gom ID, tính lại 1 lần ở shutdown.
 * 2. Board tự sửa: 10 đơn đang hiển thị luôn được tính lại; lệch thì ghi lại.
 * 3. Cron 10 phút quét 50 đơn mở (ưu tiên đơn chưa từng tính) — bắt các thay đổi không qua hook
 *    (VD save_meta_data trên HPOS).
 *
 * File nạp mọi request (đơn tạo ở checkout cũng cần tính). Dùng chung theanmarket + hithean.
 */

const THEAN_OPS_STEP_META = '_thean_ops_step';
const THEAN_OPS_STEP_VER  = '_thean_ops_step_v';
const THEAN_OPS_STEP_VERSION = '1'; // tăng khi đổi logic bước vận hành
const THEAN_OPS_CRON      = 'thean_ops_step_sweep';
const THEAN_OPS_SWEEP     = 50;

/** Tab board "Cần xử lý": khóa => nhãn, theo thứ tự luồng. */
function thean_ops_step_tabs(): array
{
    return [
        'confirm'  => 'Xác nhận COD',
        'payment'  => 'Chờ thanh toán',
        'picking'  => 'Lấy hàng',
        'export'   => 'Xuất kho',
        'delivery' => 'Chờ giao',
        'shipping' => 'Đang giao',
        'cod'      => 'Thu COD',
        'return'   => 'Hoàn hàng',
    ];
}

/**
 * Trạng thái không thuộc luồng vận hành: đã đóng / xong, và "Chưa ghi nhận" / "Đã ghi nhận" (đơn kế toán /
 * nội bộ). Đơn đóng còn hoàn hàng dở vẫn vào tab Hoàn hàng.
 */
function thean_ops_excluded_statuses(): array
{
    return (array) apply_filters('thean_ops_excluded_statuses', ['cancelled', 'failed', 'refunded', 'completed', 'chua-ghi-nhan', 'da-ghi-nhan']);
}

/** Bước tiếp theo (thean_ow_flow) → tab board; '' = không cần xử lý. Hàm thuần. */
function thean_ops_step_from_flow(array $flow, string $status = ''): string
{
    $next = (string) ($flow['next_goto'] ?? '');
    if ($next === '') {
        return '';
    }
    $off = !empty($flow['is_closed']) || ($status !== '' && in_array($status, thean_ops_excluded_statuses(), true));
    if ($off && $next !== 'return') {
        return '';
    }
    if ($next === 'payment' && !empty($flow['is_cod'])) {
        return 'cod'; // COD đã giao, chờ đối soát thu tiền
    }
    if ($next === 'delivery' && !empty($flow['is_shipping'])) {
        return 'shipping';
    }
    return array_key_exists($next, thean_ops_step_tabs()) ? $next : '';
}

/** Tính bước + gợi ý cho 1 đơn (không ghi). ['step' => tab|'', 'hint' => string]. */
function thean_ops_step_compute(WC_Order $order): array
{
    if (!function_exists('thean_ow_flow')) {
        require_once __DIR__ . '/order-workspace.php';
    }
    if ($order->get_type() !== 'shop_order' || (string) $order->get_meta('_spx_date') !== '') {
        return ['step' => '', 'hint' => ''];
    }
    $flow = thean_ow_flow($order);
    $step = thean_ops_step_from_flow($flow, $order->get_status());
    $hint = $step === '' ? '' : (string) ($flow['next_hint'] ?: ($step === 'return' ? 'Hoàn hàng: ' . $flow['return_status'] : ''));
    return ['step' => $step, 'hint' => $hint];
}

/** Tính và lưu bước của đơn. Trả kết quả thean_ops_step_compute(). */
function thean_ops_step_sync(WC_Order $order): array
{
    $res   = thean_ops_step_compute($order);
    $value = $res['step'] !== '' ? $res['step'] : 'none';
    // save_meta_data (không phải save) → không kích lại woocommerce_after_order_object_save.
    if ((string) $order->get_meta(THEAN_OPS_STEP_META) !== $value || (string) $order->get_meta(THEAN_OPS_STEP_VER) !== THEAN_OPS_STEP_VERSION) {
        $order->update_meta_data(THEAN_OPS_STEP_META, $value);
        $order->update_meta_data(THEAN_OPS_STEP_VER, THEAN_OPS_STEP_VERSION);
        $order->save_meta_data();
    }
    return $res;
}

/** Tính + lưu bước cho nhiều đơn, đọc gộp 1 lần wc_get_orders. Trả [id => kết quả]. */
function thean_ops_step_sync_ids(array $ids): array
{
    $out = [];
    if (!$ids || !function_exists('wc_get_orders')) {
        return $out;
    }
    foreach (wc_get_orders(['post__in' => $ids, 'type' => 'shop_order', 'limit' => count($ids), 'return' => 'objects']) as $order) {
        if ($order instanceof WC_Order && in_array($order->get_id(), $ids, true)) {
            $out[$order->get_id()] = thean_ops_step_sync($order);
        }
    }
    return $out;
}

/** Gom ID đơn cần tính lại, xử lý 1 lần ở shutdown. */
function thean_ops_step_queue(int $order_id): void
{
    static $hooked = false;
    if ($order_id <= 0) {
        return;
    }
    $GLOBALS['thean_ops_step_queue'][$order_id] = true;
    if (!$hooked) {
        $hooked = true;
        add_action('shutdown', 'thean_ops_step_flush', 5);
    }
}

function thean_ops_step_flush(): void
{
    $ids = array_keys($GLOBALS['thean_ops_step_queue'] ?? []);
    unset($GLOBALS['thean_ops_step_queue']);
    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if ($order instanceof WC_Order) {
            thean_ops_step_sync($order);
        }
    }
}

add_action('woocommerce_after_order_object_save', function ($order) {
    if ($order instanceof WC_Order && $order->get_type() === 'shop_order') {
        thean_ops_step_queue($order->get_id());
    }
});

/** Meta ảnh hưởng bước vận hành (cả tên theanmarket lẫn tên "order_*" của hithean). */
function thean_ops_step_watched_meta(): array
{
    return [
        '_order_payment_confirmation_audit', 'paid_date', 'order_paid_date', 'bank_account_received', 'order_bank_account_received',
        'handling_status', 'order_handling_status', 'warehouse_export_images', 'export_confirmed_by', 'return_status',
        '_payment_method', '_spx_date',
    ];
}

// Code cũ ghi thẳng post meta (kể cả khi bật HPOS) — không qua order->save().
foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $thean_ops_hook) {
    add_action($thean_ops_hook, function ($meta_id, $object_id, $meta_key) {
        if (in_array($meta_key, thean_ops_step_watched_meta(), true)
            && in_array(get_post_type((int) $object_id), ['shop_order', 'shop_order_placehold'], true)) {
            thean_ops_step_queue((int) $object_id);
        }
    }, 10, 3);
}
unset($thean_ops_hook);

/** Bảng / cột đơn + meta theo kiểu lưu trữ (HPOS hoặc legacy). */
function thean_ops_step_tables(): array
{
    global $wpdb;
    $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    return $hpos
        ? ['orders' => "{$wpdb->prefix}wc_orders", 'meta' => "{$wpdb->prefix}wc_orders_meta", 'id' => 'id', 'status' => 'status', 'type' => 'type', 'fk' => 'order_id']
        : ['orders' => $wpdb->posts, 'meta' => $wpdb->postmeta, 'id' => 'ID', 'status' => 'post_status', 'type' => 'post_type', 'fk' => 'post_id'];
}

/** FROM … WHERE chung: đơn mở thuộc luồng vận hành. */
function thean_ops_step_open_sql(array $t): string
{
    $excluded = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_excluded_statuses())) . "'";
    return "FROM {$t['orders']} o WHERE o.{$t['type']} = 'shop_order'
             AND o.{$t['status']} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft', {$excluded})";
}

/** Đơn mở chưa được tính bước theo logic hiện tại (THEAN_OPS_STEP_VERSION), mới nhất trước. @return int[] */
function thean_ops_step_unsynced_ids(int $limit): array
{
    global $wpdb;
    $t = thean_ops_step_tables();
    return array_map('intval', $wpdb->get_col(
        "SELECT o.{$t['id']} " . thean_ops_step_open_sql($t) . "
           AND NOT EXISTS (SELECT 1 FROM {$t['meta']} a WHERE a.{$t['fk']} = o.{$t['id']} AND a.meta_key = '" . THEAN_OPS_STEP_VER . "' AND a.meta_value = '" . esc_sql(THEAN_OPS_STEP_VERSION) . "')
         ORDER BY o.{$t['id']} DESC LIMIT " . (int) $limit
    ));
}

/**
 * Đơn mở cần (tính lại) bước — cho cron. Đơn chưa tính trước, rồi xoay vòng theo con trỏ ID giảm dần.
 *
 * @return int[]
 */
function thean_ops_step_sweep_ids(int $limit = THEAN_OPS_SWEEP): array
{
    global $wpdb;
    $t   = thean_ops_step_tables();
    $ids = thean_ops_step_unsynced_ids($limit);
    if (count($ids) >= $limit) {
        return $ids;
    }
    $base = thean_ops_step_open_sql($t);

    $cursor = (int) get_option('thean_ops_step_cursor', 0);
    $more = array_map('intval', $wpdb->get_col(
        "SELECT o.{$t['id']} {$base}" . ($cursor > 0 ? " AND o.{$t['id']} < {$cursor}" : '') . "
         ORDER BY o.{$t['id']} DESC LIMIT " . (int) ($limit - count($ids))
    ));
    update_option('thean_ops_step_cursor', $more ? min($more) : 0, false); // hết vòng → quay lại đơn mới nhất
    return array_values(array_unique(array_merge($ids, $more)));
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['thean_10min'] = $schedules['thean_10min'] ?? ['interval' => 600, 'display' => 'Mỗi 10 phút'];
    return $schedules;
});
add_action('init', function () {
    if (!wp_next_scheduled(THEAN_OPS_CRON)) {
        wp_schedule_event(time() + 60, 'thean_10min', THEAN_OPS_CRON);
    }
});
add_action(THEAN_OPS_CRON, function () {
    thean_ops_step_sync_ids(thean_ops_step_sweep_ids());
});

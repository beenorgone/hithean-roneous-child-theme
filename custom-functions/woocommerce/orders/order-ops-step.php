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
 * Đơn nội bộ (email thuộc domain nội bộ ở Cài đặt ERP → Xử lý đơn, hoặc cờ `_eim_internal`) không thuộc luồng:
 * bước = 'none' và board lọc thẳng bằng SQL (đổi danh sách domain có hiệu lực ngay, không cần tính lại).
 *
 * File nạp mọi request (đơn tạo ở checkout cũng cần tính). Dùng chung theanmarket + hithean.
 */

const THEAN_OPS_STEP_META = '_thean_ops_step';
const THEAN_OPS_STEP_VER  = '_thean_ops_step_v';
const THEAN_OPS_STEP_VERSION = '3'; // tăng khi đổi logic bước vận hành (2: Đặt trước; 3: Thiếu chứng từ XK)
const THEAN_OPS_MAX_AGE_DAYS = 365; // luồng vận hành / board bỏ qua đơn tạo quá 365 ngày
const THEAN_OPS_PROOF_DAYS = 60;    // tab Thiếu chứng từ XK: chỉ xét đơn tạo trong 60 ngày (đơn cũ có trước quy trình ảnh xuất kho)
const THEAN_OPS_CRON      = 'thean_ops_step_sweep';
const THEAN_OPS_SWEEP     = 50;

/** Tab board "Cần xử lý": khóa => nhãn, theo thứ tự luồng. */
function thean_ops_step_tabs(): array
{
    return [
        'confirm'  => 'Xác nhận khách', // COD chờ khách xác nhận + đơn đặt trước vừa tiếp tục cần xác nhận giao
        'payment'  => 'Chờ thanh toán',
        'picking'  => 'Lấy hàng',
        'export'   => 'Xuất kho',
        'delivery' => 'Chờ giao',
        'shipping' => 'Đang giao',
        'cod'      => 'Thu COD',
        'proof'    => 'Thiếu chứng từ XK', // đã giao / hoàn thành mà thiếu ảnh hoặc xác nhận xuất kho
        'return'   => 'Hoàn hàng',
        'preorder' => 'Đặt trước',      // trạng thái pre-order, chờ đến hẹn
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

/**
 * Domain email nội bộ (Cài đặt ERP → Xử lý đơn, option `thean_internal_email_domains`). Trống thì dùng danh sách
 * `internal_email_domains` của plugin eim nếu có. Chuẩn hóa: chữ thường, bỏ "@" đầu.
 *
 * @return string[]
 */
function thean_internal_email_domains(): array
{
    $list = thean_parse_email_domains((string) get_option('thean_internal_email_domains', ''));
    if (!$list && function_exists('eim_internal_email_domains')) {
        $list = thean_parse_email_domains(implode(' ', (array) eim_internal_email_domains()));
    }
    return $list;
}

/** Tách danh sách domain (phẩy / chấm phẩy / khoảng trắng / xuống dòng). Hàm thuần. */
function thean_parse_email_domains(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $domain) {
        $domain = ltrim(trim($domain), '@');
        if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            $out[] = $domain;
        }
    }
    return array_values(array_unique($out));
}

/** Email thuộc domain nội bộ? Khớp "@domain" hoặc subdomain ".domain" (không khớp nhầm thean.com với an.com). */
function thean_email_is_internal(string $email, ?array $domains = null): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    foreach ($domains ?? thean_internal_email_domains() as $domain) {
        $at = '@' . $domain;
        $sub = '.' . $domain;
        if (substr($email, -strlen($at)) === $at || (strpos($email, '@') !== false && substr($email, -strlen($sub)) === $sub)) {
            return true;
        }
    }
    return false;
}

/** Đơn nội bộ: cờ `_eim_internal` hoặc email người mua thuộc domain nội bộ. */
function thean_order_is_internal(WC_Order $order): bool
{
    return (string) $order->get_meta('_eim_internal') === '1' || thean_email_is_internal($order->get_billing_email());
}

/** Điều kiện SQL loại đơn nội bộ (alias đơn = o). */
function thean_ops_internal_sql(array $t): string
{
    global $wpdb;
    $parts = ["NOT EXISTS (SELECT 1 FROM {$t['meta']} i WHERE i.{$t['fk']} = o.{$t['id']} AND i.meta_key = '_eim_internal' AND i.meta_value = '1')"];
    $likes = [];
    foreach (thean_internal_email_domains() as $domain) {
        foreach (['%@', '%.'] as $prefix) {
            $likes[] = $wpdb->prepare('%s', $prefix . $wpdb->esc_like($domain));
        }
    }
    if ($likes) {
        $match = static fn(string $col) => '(' . implode(' OR ', array_map(static fn($l) => "{$col} LIKE {$l}", $likes)) . ')';
        $parts[] = $t['email_col'] !== ''
            ? 'NOT ' . $match("COALESCE(o.{$t['email_col']}, '')")
            : "NOT EXISTS (SELECT 1 FROM {$t['meta']} e WHERE e.{$t['fk']} = o.{$t['id']} AND e.meta_key = '_billing_email' AND " . $match('e.meta_value') . ')';
    }
    return implode(' AND ', $parts);
}

/** Chỉ admin (manage_options) được bỏ qua lỗi thiếu chứng từ xuất kho. */
function thean_can_skip_export_proof(): bool
{
    return current_user_can('manage_options');
}

/**
 * Được đổi trạng thái tùy ý (từng đơn + hàng loạt) ở ô tìm đơn / board: người được chỉ định ở Cài đặt ERP → Xử lý đơn
 * (option `thean_status_change_users`, ID user). Danh sách trống = mọi admin (manage_options).
 */
function thean_can_change_any_status(): bool
{
    if (!current_user_can('manage_woocommerce')) {
        return false;
    }
    $ids = array_map('intval', (array) get_option('thean_status_change_users', []));
    return $ids ? in_array(get_current_user_id(), $ids, true) : current_user_can('manage_options');
}

/** Trạng thái đã giao / xong — thiếu chứng từ xuất kho thì vào tab "Thiếu chứng từ XK". */
function thean_ops_proof_statuses(): array
{
    return ['delivered', 'partial-shipped', 'completed'];
}

/**
 * Chứng từ xuất kho còn thiếu của đơn đã giao / xong (rỗng = đủ, hoặc đã đánh dấu bỏ qua, hoặc đơn ngoài diện xét).
 *
 * @return string[]
 */
function thean_ops_missing_proof(WC_Order $order): array
{
    if (!in_array($order->get_status(), thean_ops_proof_statuses(), true) || (string) $order->get_meta('_thean_export_proof_skip') === '1') {
        return [];
    }
    $created = $order->get_date_created();
    if (!$created || $created->getTimestamp() < time() - THEAN_OPS_PROOF_DAYS * DAY_IN_SECONDS) {
        return [];
    }
    $missing = [];
    if (trim((string) $order->get_meta('warehouse_export_images')) === '') {
        $missing[] = 'ảnh xuất kho';
    }
    // Workflow xuất kho cũ vẫn ghi post meta kể cả khi bật HPOS.
    if (!$order->get_meta('export_confirmed_by') && !get_post_meta($order->get_id(), 'export_confirmed_by', true)) {
        $missing[] = 'xác nhận xuất kho';
    }
    return $missing;
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
    if ($next === 'preorder') {
        // thean_ow_flow: đang Đặt trước → tab Đặt trước; vừa tiếp tục, chưa xác nhận giao → Xác nhận khách.
        return !empty($flow['preorder_confirm']) ? 'confirm' : 'preorder';
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
    $created = $order->get_date_created();
    $too_old = !$created || $created->getTimestamp() < time() - THEAN_OPS_MAX_AGE_DAYS * DAY_IN_SECONDS;
    if ($too_old || $order->get_type() !== 'shop_order' || (string) $order->get_meta('_spx_date') !== '' || thean_order_is_internal($order)) {
        return ['step' => '', 'hint' => ''];
    }
    $missing = thean_ops_missing_proof($order);
    if ($missing) {
        return ['step' => 'proof', 'hint' => 'Đã ' . mb_strtolower(wc_get_order_status_name($order->get_status())) . ' nhưng thiếu ' . implode(' + ', $missing)];
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

/**
 * Nút đơn đặt trước ở danh sách đơn / ô tìm / board (popup: assets/order-flow-popup.js, xử lý: order-workspace.php):
 * "Chuyển sang đặt trước" và "Xác nhận giao (đặt trước)" khi đơn vừa tiếp tục từ Đặt trước.
 */
add_filter('woocommerce_admin_order_actions', function ($actions, $order) {
    if (!$order instanceof WC_Order || !current_user_can('manage_woocommerce') || !wc_is_order_status('wc-pre-order')) {
        return $actions;
    }
    $status = $order->get_status();
    $args   = ['order_id' => $order->get_id(), 'phone' => $order->get_billing_phone() ?: $order->get_shipping_phone()];
    $closed = ['pre-order', 'cancelled', 'failed', 'refunded', 'completed', 'shipping', 'local-shipping', 'partial-shipping', 'delivered', 'partial-shipped'];
    if (!in_array($status, $closed, true)) {
        $actions['thean-order-preorder'] = [
            'url'    => add_query_arg(['thean_order_action' => 'preorder'] + $args, admin_url('admin.php')),
            'name'   => 'Chuyển sang đặt trước',
            'action' => 'thean-order-preorder',
        ];
    }
    if ($status !== 'pre-order' && (string) $order->get_meta('_thean_preorder_confirm') !== '') {
        $actions['thean-order-preorder-confirm'] = [
            'url'    => add_query_arg(['thean_order_action' => 'preorder-confirm'] + $args, admin_url('admin.php')),
            'name'   => 'Xác nhận giao (đặt trước)',
            'action' => 'thean-order-preorder-confirm',
        ];
    }
    return $actions;
}, 120, 2);

/** Nạp popup thao tác luồng đơn (assets/order-flow-popup.js: Tạm giữ / Tiếp tục / Hủy / Đặt trước…). Trang sửa đơn: reload = true. */
function thean_flow_popup_enqueue(bool $reload): void
{
    $dir = get_stylesheet_directory() . '/custom-functions/woocommerce/orders/assets/';
    $uri = get_stylesheet_directory_uri() . '/custom-functions/woocommerce/orders/assets/';
    wp_enqueue_script('thean-order-flow-popup', $uri . 'order-flow-popup.js', [], (string) @filemtime($dir . 'order-flow-popup.js'), true);
    wp_add_inline_script('thean-order-flow-popup', 'window.theanFlowCfg = ' . wp_json_encode([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('thean_order_workspace'), // THEAN_OW_NONCE
        'reload'  => $reload,
    ]) . ';', 'before');
}

/** Meta ảnh hưởng bước vận hành (cả tên theanmarket lẫn tên "order_*" của hithean). */
function thean_ops_step_watched_meta(): array
{
    return [
        '_order_payment_confirmation_audit', 'paid_date', 'order_paid_date', 'bank_account_received', 'order_bank_account_received',
        'handling_status', 'order_handling_status', 'warehouse_export_images', 'export_confirmed_by', 'return_status',
        '_payment_method', '_spx_date', '_eim_internal', '_billing_email', '_thean_preorder_confirm', '_thean_export_proof_skip',
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

/** Điều kiện SQL: đơn tạo trong THEAN_OPS_MAX_AGE_DAYS ngày (alias đơn = o). */
function thean_ops_age_sql(array $t): string
{
    return "o.{$t['date']} >= '" . gmdate('Y-m-d H:i:s', time() - THEAN_OPS_MAX_AGE_DAYS * DAY_IN_SECONDS) . "'";
}

/** Bảng / cột đơn + meta theo kiểu lưu trữ (HPOS hoặc legacy). */
function thean_ops_step_tables(): array
{
    global $wpdb;
    $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    return $hpos
        ? ['orders' => "{$wpdb->prefix}wc_orders", 'meta' => "{$wpdb->prefix}wc_orders_meta", 'id' => 'id', 'status' => 'status', 'type' => 'type', 'fk' => 'order_id', 'email_col' => 'billing_email', 'date' => 'date_created_gmt']
        : ['orders' => $wpdb->posts, 'meta' => $wpdb->postmeta, 'id' => 'ID', 'status' => 'post_status', 'type' => 'post_type', 'fk' => 'post_id', 'email_col' => '', 'date' => 'post_date_gmt'];
}

/**
 * FROM … WHERE chung: đơn mở thuộc luồng vận hành + đơn đã giao / xong trong THEAN_OPS_PROOF_DAYS ngày (soát chứng từ XK);
 * bỏ đơn tạo quá THEAN_OPS_MAX_AGE_DAYS ngày.
 */
function thean_ops_step_open_sql(array $t): string
{
    $excluded = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_excluded_statuses())) . "'";
    $proof    = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_proof_statuses())) . "'";
    $since    = gmdate('Y-m-d H:i:s', time() - THEAN_OPS_PROOF_DAYS * DAY_IN_SECONDS);
    return "FROM {$t['orders']} o WHERE o.{$t['type']} = 'shop_order'
             AND o.{$t['status']} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft')
             AND (o.{$t['status']} NOT IN ({$excluded}) OR (o.{$t['status']} IN ({$proof}) AND o.{$t['date']} >= '{$since}'))
             AND " . thean_ops_age_sql($t);
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

/**
 * Các mục của luồng vận hành trong Cài đặt ERP → tab Xử lý đơn (gọi từ core/ai-settings.php của mỗi theme):
 * "Đơn nội bộ" + "Quyền đổi trạng thái tùy ý" + "Khóa đơn cũ" (tạm nới).
 */
function thean_order_ops_settings_section(): void
{
    thean_internal_domains_settings_section();
    thean_status_change_settings_section();
    if (function_exists('thean_order_age_lock_settings_section')) {
        thean_order_age_lock_settings_section(); // order-age-lock.php
    }
}

/** Mục "Quyền đổi trạng thái tùy ý": danh sách user (email / tên đăng nhập / ID) → option thean_status_change_users. */
function thean_status_change_settings_section(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $notice = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['thean_status_users_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['thean_status_users_nonce'])), 'thean_status_users_save')) {
        $ids = [];
        $bad = [];
        foreach (preg_split('/[\s,;]+/', trim(wp_unslash((string) ($_POST['thean_status_change_users'] ?? '')))) ?: [] as $who) {
            if ($who === '') {
                continue;
            }
            $user = ctype_digit($who) ? get_userdata((int) $who) : (get_user_by('email', $who) ?: get_user_by('login', $who));
            if ($user) {
                $ids[] = (int) $user->ID;
            } else {
                $bad[] = $who;
            }
        }
        if ($bad) {
            $notice = ['error', 'Không tìm thấy: ' . implode(', ', $bad) . ' — chưa lưu.'];
        } else {
            update_option('thean_status_change_users', array_values(array_unique($ids)), false);
            $notice = ['success', '✅ Đã lưu người được đổi trạng thái tùy ý.'];
        }
    }
    $users = array_filter(array_map('get_userdata', array_map('intval', (array) get_option('thean_status_change_users', []))));
    ?>
    <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div><?php endif; ?>
    <form method="post">
        <?php wp_nonce_field('thean_status_users_save', 'thean_status_users_nonce'); ?>
        <h2>Quyền đổi trạng thái tùy ý</h2>
        <table class="form-table" role="presentation"><tbody><tr>
            <th scope="row"><label for="thean-status-users">Người được đổi trạng thái tùy ý</label></th>
            <td>
                <input type="text" class="large-text" style="max-width:600px" id="thean-status-users" name="thean_status_change_users" value="<?php echo esc_attr(implode(', ', array_map(static fn($u) => $u->user_email, $users))); ?>" placeholder="Email / tên đăng nhập / ID, cách nhau bằng dấu phẩy">
                <p class="description">Chỉ những người này thấy nút <strong>🔁 Đổi trạng thái</strong> (từng đơn) và <strong>Đổi trạng thái hàng loạt</strong> ở ô tìm đơn / board
                    "Tải đơn cần xử lý". Để trống = mọi tài khoản Administrator. Bỏ qua lỗi thiếu chứng từ xuất kho luôn chỉ dành cho Administrator.</p>
                <?php if ($users) : ?><p class="description">Đang chỉ định: <?php echo esc_html(implode(', ', array_map(static fn($u) => $u->display_name . ' (#' . $u->ID . ')', $users))); ?>.</p><?php endif; ?>
            </td>
        </tr></tbody></table>
        <?php submit_button('Lưu quyền'); ?>
    </form>
    <?php
}

/** Mục "Đơn nội bộ" (domain email nội bộ). */
function thean_internal_domains_settings_section(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $saved = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['thean_internal_domains_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['thean_internal_domains_nonce'])), 'thean_internal_domains_save')) {
        update_option('thean_internal_email_domains', implode(', ', thean_parse_email_domains(wp_unslash((string) ($_POST['thean_internal_email_domains'] ?? '')))), false);
        $saved = true;
    }
    $value = (string) get_option('thean_internal_email_domains', '');
    $eim   = function_exists('eim_internal_email_domains') ? (array) eim_internal_email_domains() : [];
    ?>
    <?php if ($saved) : ?><div class="notice notice-success is-dismissible"><p>✅ Đã lưu domain email nội bộ.</p></div><?php endif; ?>
    <form method="post">
        <?php wp_nonce_field('thean_internal_domains_save', 'thean_internal_domains_nonce'); ?>
        <h2>Đơn nội bộ</h2>
        <table class="form-table" role="presentation"><tbody><tr>
            <th scope="row"><label for="thean-internal-domains">Domain email nội bộ</label></th>
            <td>
                <input type="text" class="large-text" style="max-width:600px" id="thean-internal-domains" name="thean_internal_email_domains" value="<?php echo esc_attr($value); ?>" placeholder="vd: theanmarket.com, ivarvietnam.com">
                <p class="description">Đơn có email người mua thuộc các domain này (kể cả subdomain), hoặc có cờ <code>_eim_internal</code>, được coi là đơn nội bộ và
                    <strong>không vào luồng vận hành</strong> (board "Tải đơn cần xử lý"). Ô tìm đơn vẫn tìm được. Cách nhau bằng dấu phẩy.</p>
                <?php if ($value === '' && $eim) : ?>
                    <p class="description">Đang để trống → dùng danh sách của plugin hóa đơn (eim): <code><?php echo esc_html(implode(', ', $eim)); ?></code>.</p>
                <?php endif; ?>
            </td>
        </tr></tbody></table>
        <?php submit_button('Lưu domain nội bộ'); ?>
    </form>
    <?php
}

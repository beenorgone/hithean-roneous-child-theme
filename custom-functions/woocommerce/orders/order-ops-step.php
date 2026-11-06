<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/order-export-review.php';

/**
 * Bước vận hành của đơn, lưu sẵn thành meta để board "Cần xử lý" (order-universal-search.php) chỉ cần
 * đếm / phân trang bằng SQL — không phải đọc hàng trăm đơn mỗi lần mở.
 *
 * - `_thean_ops_step`    = tab board (confirm, payment, picking, export, delivery, shipping, cod, return) hoặc 'none'.
 * - `_thean_ops_step_v`  = THEAN_OPS_STEP_VERSION lúc tính (đánh dấu đơn đã được tính theo logic hiện tại).
 * - `_thean_ops_proof`   = '1' khi thiếu chứng từ xuất kho: tab "Thiếu / chưa xác nhận ảnh xuất kho" lọc theo cờ này (không theo bước), nên đơn
 *   đang giao thiếu chứng từ hiện ở CẢ "Đang giao" (bước) lẫn "Thiếu / chưa xác nhận ảnh xuất kho" (cờ).
 * Bước tính bằng thean_ow_flow() (order-workspace.php) — cùng logic bảng điều phối ở trang sửa đơn.
 *
 * ĐỔI LUỒNG VẬN HÀNH (thean_ow_flow, thean_ops_step_from_flow, trạng thái loại trừ…) → TĂNG THEAN_OPS_STEP_VERSION:
 * mọi đơn mở thành "chưa tính", cron tự tính lại (backfill 20 đơn / lượt nền, cron đối soát 50 đơn / 10 phút).
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

// Frontend context preserves WooCommerce/shortcode dependencies; document remains staff-only.
add_action('template_redirect', function () {
    if (!isset($_GET['thean_order_tool'])) return;
    require_once __DIR__ . '/order-product-tools.php';
    thean_order_product_tool_response();
}, 1);

const THEAN_OPS_STEP_META = '_thean_ops_step';
const THEAN_OPS_STEP_VER  = '_thean_ops_step_v';
const THEAN_OPS_STEP_VERSION = '8'; // tăng khi đổi logic bước vận hành (2: Đặt trước; 3: Thiếu / chưa xác nhận ảnh xuất kho; 4: cờ chứng từ cho đơn đang giao;
                                    // 5: bắt save_meta_data trên HPOS; 6: lưu bước thật cả đơn nội bộ / đơn cũ + đọc đơn bỏ qua cache;
                                    // 7: tính lại cả đơn đóng còn hoàn hàng; 8: nhóm khách/COD, giao nhanh chưa xuất không tính đã giao đi)
const THEAN_OPS_PROOF_META = '_thean_ops_proof'; // '1' = thiếu chứng từ XK → tab "Thiếu / chưa xác nhận ảnh xuất kho" (đơn đang giao: kèm tab Đang giao)
const THEAN_OPS_PROOF_DAYS = 60;    // tab Thiếu / chưa xác nhận ảnh xuất kho: chỉ xét đơn tạo trong 60 ngày (đơn cũ có trước quy trình ảnh xuất kho)
const THEAN_OPS_CRON      = 'thean_ops_step_sweep';
const THEAN_OPS_SWEEP     = 50;

/** Tab board "Cần xử lý": khóa => nhãn, theo thứ tự luồng. */
function thean_ops_step_tabs(): array
{
    return [
        'confirm'  => 'Chờ khách xác nhận / thanh toán',
        'picking'  => 'Cần xuất kho, đóng gói',
        'export'   => 'Chưa kiểm tra ảnh xuất kho',
        'delivery' => 'Đã đóng gói, chờ giao VC',
        'shipping' => 'Đang giao',
        'cod'      => 'Chờ thu COD',
        'proof'    => 'Thiếu / chưa xác nhận ảnh xuất kho', // chỉ đơn đã giao đi; có thể chồng lên bước chính
        'return'   => 'Hoàn hàng',
        'preorder' => 'Đặt trước',      // trạng thái pre-order, chờ đến hẹn
    ];
}

/** Bước lưu sẵn vẫn riêng biệt để giữ đúng thao tác; chỉ gộp ở lớp hiển thị board. */
function thean_ops_step_tab(string $step): string
{
    return ['payment' => 'confirm', 'cod_fast' => 'cod'][$step] ?? $step;
}

/** Hai nhóm trong cùng một tab; khóa là bước gốc, dùng chung cho SQL, badge và UI. */
function thean_ops_step_groups(string $tab): array
{
    return [
        'confirm' => ['confirm' => 'Đơn chờ xác nhận', 'payment' => 'Đơn chờ thanh toán trước'],
        'cod' => ['cod_fast' => 'Chờ thu COD giao nhanh', 'cod' => 'Chờ đối soát đơn vị VC'],
    ][$tab] ?? [];
}

function thean_ops_step_labels(): array
{
    return array_merge(thean_ops_step_tabs(), thean_ops_step_groups('confirm'), thean_ops_step_groups('cod'));
}

/**
 * Link mở thẳng board "Đơn cần xử lý" ở 1 tab (danh sách đơn admin + ?thean_board=<tab>, xử lý ở
 * assets/order-universal-search.js). Tab không hợp lệ / trống → board mở tab có đơn đầu tiên.
 * Dùng cho thông báo, email nội bộ, bookmark…
 */
function thean_ops_board_url(string $tab = ''): string
{
    $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $url  = $hpos ? admin_url('admin.php?page=wc-orders') : admin_url('edit.php?post_type=shop_order');
    $tab  = sanitize_key($tab);
    $tab  = thean_ops_step_tab($tab); // giữ link cũ ?thean_board=payment
    return isset(thean_ops_step_tabs()[$tab]) ? add_query_arg('thean_board', $tab, $url) : $url;
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
    if (!thean_internal_domains_enabled()) {
        return []; // tắt lọc theo domain (Cài đặt ERP → Xử lý đơn → Đơn nội bộ); cờ _eim_internal vẫn áp dụng
    }
    $list = thean_parse_email_domains((string) get_option('thean_internal_email_domains', ''));
    if (!$list && function_exists('eim_internal_email_domains')) {
        $list = thean_parse_email_domains(implode(' ', (array) eim_internal_email_domains()));
    }
    return $list;
}

/**
 * Lọc đơn nội bộ theo domain email đang bật? Mặc định BẬT. Option `thean_internal_domains_off` = '1' để tắt
 * (key cũ `thean_internal_domains_enabled` — mặc định tắt — bỏ từ 31/10/2026 khi chuyển sang khớp đúng domain).
 */
function thean_internal_domains_enabled(): bool
{
    return (string) get_option('thean_internal_domains_off', '0') !== '1';
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

/**
 * Email thuộc domain nội bộ? Khớp ĐÚNG domain sau "@": khai "thean.com" thì a@thean.com là nội bộ, còn
 * a@hn.thean.com (subdomain) vẫn là đơn thường — muốn loại subdomain thì khai riêng "hn.thean.com".
 */
function thean_email_is_internal(string $email, ?array $domains = null): bool
{
    $email = strtolower(trim($email));
    $at    = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    return in_array(substr($email, $at + 1), $domains ?? thean_internal_email_domains(), true);
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
        $likes[] = $wpdb->prepare('%s', '%@' . $wpdb->esc_like($domain)); // đúng domain, không gồm subdomain
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

/** Trạng thái đã giao / xong — thiếu chứng từ xuất kho thì chỉ ở tab "Thiếu / chưa xác nhận ảnh xuất kho". */
function thean_ops_proof_statuses(): array
{
    return ['delivered', 'partial-shipped', 'completed'];
}

/** Trạng thái đang giao — thiếu chứng từ thì ở cả tab "Đang giao" (theo dõi) lẫn "Thiếu / chưa xác nhận ảnh xuất kho", không về "Lấy hàng". */
function thean_ops_shipping_statuses(): array
{
    return ['shipping', 'local-shipping', 'partial-shipping'];
}

/**
 * Chứng từ xuất kho còn thiếu của đơn đang giao / đã giao / xong (rỗng = đủ, hoặc đã đánh dấu bỏ qua, hoặc đơn ngoài diện xét).
 *
 * @return string[]
 */
function thean_ops_missing_proof(WC_Order $order): array
{
    $statuses = array_merge(thean_ops_proof_statuses(), thean_ops_shipping_statuses());
    if (!in_array($order->get_status(), $statuses, true) || (string) $order->get_meta('_thean_export_proof_skip') === '1') {
        return [];
    }
    $created = $order->get_date_created();
    if (!$created || $created->getTimestamp() < time() - THEAN_OPS_PROOF_DAYS * DAY_IN_SECONDS) {
        return [];
    }
    // Code cũ ghi post meta → HPOS có thể trống / đã bị Meta Box xóa: kéo sang + khôi phục (order-legacy-meta-sync.php).
    if (function_exists('thean_legacy_meta_reconcile')) {
        thean_legacy_meta_reconcile($order);
        thean_export_proof_recover($order);
    }
    // "Giao nhanh" có thể được gắn tự động trước khi đóng gói, không phải bằng chứng đã bàn giao.
    if (!function_exists('thean_ow_is_dispatched')) {
        require_once __DIR__ . '/order-workspace.php';
    }
    if (!thean_ow_is_dispatched($order)) {
        return [];
    }
    $missing = [];
    if (trim((string) $order->get_meta('warehouse_export_images')) === '' && trim((string) get_post_meta($order->get_id(), 'warehouse_export_images', true)) === '') {
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
        return !empty($flow['is_fast']) ? 'cod_fast' : 'cod';
    }
    if ($next === 'delivery' && !empty($flow['is_shipping'])) {
        return 'shipping';
    }
    return array_key_exists($next, thean_ops_step_labels()) ? $next : '';
}

/** Kiểm tra phạm vi riêng để tái dùng flow đã tính mà vẫn giữ bộ lọc hiển thị. */
function thean_ops_step_excluded(WC_Order $order, bool $gated = true): bool
{
    $created = $order->get_date_created();
    return ($gated && (!$created || $created->getTimestamp() < thean_ops_age_cutoff_ts()))
        || $order->get_type() !== 'shop_order'
        || (string) $order->get_meta('_spx_date') !== ''
        || ($gated && thean_order_is_internal($order));
}

/**
 * Tính bước + gợi ý cho 1 đơn (không ghi). ['step' => tab|'', 'hint' => string, 'proof' => thiếu chứng từ XK?].
 * $gated = true (hiển thị ở ô tìm): đơn cũ (trước mốc khóa) / đơn nội bộ → không có bước.
 * $gated = false (lưu meta): vẫn tính bước thật — board đã loại 2 loại này bằng SQL theo cài đặt HIỆN TẠI, nên đổi
 * domain nội bộ / ngày mốc khóa đơn cũ có hiệu lực ngay cả 2 chiều (trước đây đơn bị lưu 'none' và kẹt lại).
 */
function thean_ops_step_compute(WC_Order $order, bool $gated = true): array
{
    if (thean_ops_step_excluded($order, $gated)) {
        return ['step' => '', 'hint' => '', 'proof' => false];
    }
    if (!function_exists('thean_ow_flow')) {
        require_once __DIR__ . '/order-workspace.php';
    }
    $missing = thean_ops_missing_proof($order);
    $flow = thean_ow_flow($order);
    $step = thean_ops_step_from_flow($flow, $order->get_status());
    // Đã giao đi: không kéo về soạn hàng/duyệt ảnh. Ảnh thiếu là công việc ngoại lệ song song.
    // Hoàn hàng còn dở và bước xác nhận giao đặt trước vẫn được ưu tiên.
    if (thean_ow_is_dispatched($order) && !in_array($flow['next_goto'], ['return', 'preorder'], true)) {
        if ($order->get_status() !== 'completed' && $flow['is_cod'] && !$flow['payment']['done']
            && ($flow['is_fast'] ? (bool) $flow['confirmed_by'] : in_array($order->get_status(), ['delivered', 'partial-shipped'], true))) {
            $step = $flow['is_fast'] ? 'cod_fast' : 'cod';
        } elseif (in_array($order->get_status(), thean_ops_shipping_statuses(), true)) {
            $step = 'shipping';
        } elseif ($missing) {
            $step = 'proof';
        }
    }
    $hint = $step === '' ? '' : (string) ($flow['next_hint'] ?: ($step === 'return' ? 'Hoàn hàng: ' . $flow['return_status'] : ''));
    if ($missing) {
        $hint = ($hint !== '' ? $hint . ' · ' : '') . wc_get_order_status_name($order->get_status()) . ' nhưng thiếu ' . implode(' + ', $missing);
    }
    return ['step' => $step, 'hint' => $hint, 'proof' => (bool) $missing];
}

/** Tính và lưu bước của đơn. Trả kết quả thean_ops_step_compute(). */
function thean_ops_step_sync(WC_Order $order): array
{
    $res   = thean_ops_step_compute($order, false);
    $value = $res['step'] !== '' ? $res['step'] : 'none';
    // save_meta_data (không phải save) → không kích lại woocommerce_after_order_object_save.
    $proof = !empty($res['proof']) ? '1' : '';
    if ((string) $order->get_meta(THEAN_OPS_STEP_META) !== $value || (string) $order->get_meta(THEAN_OPS_STEP_VER) !== THEAN_OPS_STEP_VERSION
        || (string) $order->get_meta(THEAN_OPS_PROOF_META) !== $proof) {
        $order->update_meta_data(THEAN_OPS_STEP_META, $value);
        $order->update_meta_data(THEAN_OPS_STEP_VER, THEAN_OPS_STEP_VERSION);
        if ($proof !== '') {
            $order->update_meta_data(THEAN_OPS_PROOF_META, $proof);
        } else {
            $order->delete_meta_data(THEAN_OPS_PROOF_META);
        }
        $order->save_meta_data();
        thean_ops_board_invalidate();
    }
    return $res;
}

/** Tính + lưu bước cho nhiều đơn, đọc gộp 1 lần wc_get_orders. Trả [id => kết quả]. */
function thean_ops_step_sync_ids(array $ids, ?array &$loaded_orders = null): array
{
    $out = [];
    $loaded_orders = [];
    if (!$ids || !function_exists('wc_get_orders')) {
        return $out;
    }
    thean_ops_order_cache_bust($ids);
    foreach (wc_get_orders(['post__in' => $ids, 'type' => 'shop_order', 'limit' => count($ids), 'return' => 'objects']) as $order) {
        if ($order instanceof WC_Order && in_array($order->get_id(), $ids, true)) {
            $out[$order->get_id()] = thean_ops_step_sync($order);
            $loaded_orders[$order->get_id()] = $order;
        }
    }
    return $out;
}

/** Token riêng cho cache ID/số đếm board; không cache HTML hoặc quyền thao tác. */
function thean_ops_board_invalidate(): void
{
    update_option('thean_ops_board_revision', uniqid('', true), false);
}

/** Chỉ xếp lịch; không quét hay đọc đơn trên request mở board. */
function thean_ops_step_request_backfill(): bool
{
    $hook = 'thean_ops_step_backfill';
    if (!get_transient('thean_ops_backfill_checked') && !wp_next_scheduled($hook)) {
        if (wp_schedule_single_event(time() + 1, $hook)) {
            set_transient('thean_ops_backfill_checked', 1, 60);
        }
    }
    return (bool) wp_next_scheduled($hook);
}

add_action('thean_ops_step_backfill', function () {
    $ids = thean_ops_step_unsynced_ids(21);
    thean_ops_step_sync_ids(array_slice($ids, 0, 20));
    if (count($ids) > 20 && !wp_next_scheduled('thean_ops_step_backfill')) {
        wp_schedule_single_event(time() + 10, 'thean_ops_step_backfill');
    }
});

/** Gom ID đơn cần tính lại, xử lý 1 lần ở shutdown. */
function thean_ops_step_queue(int $order_id): void
{
    static $hooked = false;
    if ($order_id <= 0) {
        return;
    }
    if (empty($GLOBALS['thean_ops_step_queue'][$order_id])) {
        thean_ops_board_invalidate();
    }
    $GLOBALS['thean_ops_step_queue'][$order_id] = true;
    if (!$hooked) {
        $hooked = true;
        add_action('shutdown', 'thean_ops_step_flush', 5);
    }
}

/**
 * Bỏ đơn khỏi cache đối tượng của WC (OrderCache — nhóm `orders`, lưu bền ở Redis, gồm cả meta) để lần đọc kế tiếp
 * đọc lại DB. Cần vì nhiều luồng cũ ghi update_post_meta (xác nhận TT, ảnh / xác nhận xuất kho…): trên HPOS bản ghi
 * post chỉ được kéo sang bảng HPOS khi đơn được ĐỌC LẠI (sync on read) — đơn đang nằm trong cache thì không, nên
 * bước tính ra theo dữ liệu cũ (đơn lọt / kẹt sai tab) cho tới khi có ai lưu đơn.
 */
function thean_ops_order_cache_bust(array $ids): void
{
    $cache = null;
    if (class_exists('\Automattic\WooCommerce\Caches\OrderCache') && function_exists('wc_get_container')) {
        try {
            $cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);
        } catch (\Throwable $e) {
            $cache = null;
        }
    }
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        if ($cache) {
            $cache->remove($id);
        }
        if (method_exists('WC_Order', 'generate_meta_cache_key')) {
            wp_cache_delete(WC_Order::generate_meta_cache_key($id, 'orders'), 'orders');
        }
    }
}

function thean_ops_step_flush(): void
{
    $ids = array_keys($GLOBALS['thean_ops_step_queue'] ?? []);
    unset($GLOBALS['thean_ops_step_queue']);
    thean_ops_order_cache_bust($ids);
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
        'handling_status', 'order_handling_status', 'shipper', 'order_shipper', 'warehouse_export_images', 'export_confirmed_by', 'return_status',
        '_payment_method', '_spx_date', '_eim_internal', '_billing_email', '_thean_preorder_confirm', '_thean_export_proof_skip',
    ];
}

// Code cũ ghi thẳng post meta (kể cả khi bật HPOS) — không qua order->save().
foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $thean_ops_hook) {
    add_action($thean_ops_hook, function ($meta_id, $object_id, $meta_key) {
        if (in_array($meta_key, thean_ops_step_watched_meta(), true)
            && in_array(get_post_type((int) $object_id), ['shop_order', 'shop_order_placehold'], true)) {
            thean_ops_order_cache_bust([(int) $object_id]); // đọc lại sau đó mới thấy giá trị mới (xem thean_ops_order_cache_bust)
            thean_ops_step_queue((int) $object_id);
        }
    }, 10, 3);
}
// $order->update_meta_data() + save_meta_data() (không gọi save()): WC_Data phát {added,updated,deleted}_order_meta, KHÔNG phát
// woocommerce_after_order_object_save và (HPOS) cũng không phát *_post_meta → trước đây bước lưu sẵn bị cũ: tìm đơn (tính trực tiếp)
// thấy "Chờ thanh toán" nhưng board (đọc meta) không có.
foreach (['added_order_meta', 'updated_order_meta', 'deleted_order_meta'] as $thean_ops_hook) {
    add_action($thean_ops_hook, function ($meta_id, $object_id, $meta_key) {
        if (in_array($meta_key, thean_ops_step_watched_meta(), true)) {
            thean_ops_step_queue((int) $object_id);
        }
    }, 10, 3);
}
unset($thean_ops_hook);

/** Mốc bỏ đơn cũ của luồng vận hành = mốc khóa đơn cũ (order-age-lock.php: ngày mốc ở Cài đặt ERP, mặc định 365 ngày). */
function thean_ops_age_cutoff_ts(): int
{
    return function_exists('thean_order_lock_cutoff_ts') ? thean_order_lock_cutoff_ts() : time() - 365 * DAY_IN_SECONDS;
}

/** Điều kiện SQL: đơn tạo từ mốc khóa đơn cũ trở đi (alias đơn = o). */
function thean_ops_age_sql(array $t): string
{
    return "o.{$t['date']} >= '" . gmdate('Y-m-d H:i:s', thean_ops_age_cutoff_ts()) . "'";
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
 * FROM … WHERE chung: đơn mở + đơn đóng còn hoàn hàng + đơn đã giao / xong trong THEAN_OPS_PROOF_DAYS ngày (soát chứng từ XK);
 * bỏ đơn cũ (trước mốc khóa đơn cũ).
 */
function thean_ops_step_open_sql(array $t): string
{
    $excluded = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_excluded_statuses())) . "'";
    $proof    = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_proof_statuses())) . "'";
    $since    = gmdate('Y-m-d H:i:s', time() - THEAN_OPS_PROOF_DAYS * DAY_IN_SECONDS);
    // Đọc yêu cầu hoàn gốc: bước lưu sẵn có thể thiếu / sai nên không đủ để tìm đơn cần backfill.
    // Giữ cả bước return đã lưu để cron dọn bước cũ khi yêu cầu hoàn vừa kết thúc / bị xóa ngoài hook.
    $returns  = "EXISTS (SELECT 1 FROM {$t['meta']} r WHERE r.{$t['fk']} = o.{$t['id']}
                    AND ((r.meta_key = 'return_status' AND TRIM(r.meta_value) <> ''
                          AND TRIM(r.meta_value) <> 'Hủy yêu cầu' AND TRIM(r.meta_value) NOT LIKE 'Đã nhận%')
                         OR (r.meta_key = '" . THEAN_OPS_STEP_META . "' AND r.meta_value = 'return')))";
    return "FROM {$t['orders']} o WHERE o.{$t['type']} = 'shop_order'
             AND o.{$t['status']} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft')
             AND (o.{$t['status']} NOT IN ({$excluded}) OR {$returns} OR (o.{$t['status']} IN ({$proof}) AND o.{$t['date']} >= '{$since}'))
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
        update_option('thean_internal_domains_off', empty($_POST['thean_internal_domains_enabled']) ? '1' : '0', false);
        delete_option('thean_internal_domains_enabled');
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
            <th scope="row">Lọc theo domain</th>
            <td>
                <label><input type="checkbox" name="thean_internal_domains_enabled" value="1" <?php checked(thean_internal_domains_enabled()); ?>>
                    Bật — loại đơn có email thuộc các domain dưới khỏi board</label>
                <p class="description">Đang <strong><?php echo thean_internal_domains_enabled() ? 'BẬT' : 'TẮT'; ?></strong>. Khi tắt, đơn có email nội bộ vẫn vào board như đơn thường;
                    đơn có cờ <code>_eim_internal</code> (đơn gộp Shopee, đơn liên site…) vẫn luôn bị loại. Đổi có hiệu lực ngay.</p>
            </td>
        </tr><tr>
            <th scope="row"><label for="thean-internal-domains">Domain email nội bộ</label></th>
            <td>
                <input type="text" class="large-text" style="max-width:600px" id="thean-internal-domains" name="thean_internal_email_domains" value="<?php echo esc_attr($value); ?>" placeholder="vd: theanmarket.com, ivarvietnam.com">
                <p class="description">Đơn có email người mua thuộc <strong>đúng</strong> các domain này, hoặc có cờ <code>_eim_internal</code>, được coi là đơn nội bộ và
                    <strong>không vào luồng vận hành</strong> (board "Tải đơn cần xử lý"). Ô tìm đơn vẫn tìm được. Cách nhau bằng dấu phẩy.<br>
                    Subdomain <strong>không</strong> tự tính: khai <code>thean.com</code> thì <code>a@thean.com</code> bị loại nhưng <code>a@hn.thean.com</code> vẫn vào board;
                    muốn loại cả subdomain thì khai thêm <code>hn.thean.com</code>.</p>
                <?php if ($value === '' && $eim) : ?>
                    <p class="description">Đang để trống → dùng danh sách của plugin hóa đơn (eim): <code><?php echo esc_html(implode(', ', $eim)); ?></code>.</p>
                <?php endif; ?>
            </td>
        </tr></tbody></table>
        <?php submit_button('Lưu domain nội bộ'); ?>
    </form>
    <?php
}

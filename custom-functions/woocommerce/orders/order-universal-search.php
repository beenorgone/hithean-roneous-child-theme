<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/order-product-tools.php';

/**
 * Tìm kiếm đơn nhanh (sticky) ở trang danh sách đơn WooCommerce — thay ô tìm mặc định.
 *
 * Gõ tự do: ID / mã đơn (ID + hậu tố), tên khách, SĐT, email, địa chỉ, tên SP, mã vận đơn.
 * Lệnh lọc (kết hợp được, AND): /tt <trạng thái> (lặp lại = OR), /sdt, /id, /sp, /vd, /email, /kh.
 * Chạy cả HPOS (wc_orders / wc_order_addresses / wc_orders_meta) lẫn lưu trữ cũ (posts / postmeta).
 *
 * Tải VPS: danh sách ID khớp (≤ THEAN_OUS_CAP) cache transient (Redis) theo truy vấn + version cache
 * "orders" của WC, nên "Xem thêm" chỉ render 10 đơn kế, không chạy lại LIKE; 10 đơn đọc gộp 1 lần
 * wc_get_orders; HTML xem nhanh cache 2 phút; mỗi user tối đa 4 lượt tìm thật / 30s, quá thì khóa 2 phút.
 * CSS/JS: assets/order-universal-search.{css,js} (file tĩnh, dùng chung 2 site).
 * Kết quả trong modal dạng bảng (mobile: dạng thẻ); bấm dòng để xem nhanh đơn kèm các nút
 * thao tác (dùng chung modal thao tác nhanh ở orders-admin.php qua class .wc-action-button-*).
 *
 * "Ẩn thông báo": ô tích trên thanh tìm ẩn mọi .notice / .update-nag ở danh sách đơn — MẶC ĐỊNH BẬT
 * cho mọi user; bỏ tích thì lưu '0' vào user meta THEAN_OUS_ALERTS_META, phiên sau vẫn giữ.
 *
 * Board "Cần xử lý": nút "Tải đơn cần xử lý" trên thanh tìm hiện board NGAY TRÊN TRANG (dưới thanh tìm), chia tab theo bước vận hành của đơn. Bước được lưu sẵn ở meta
 * `_thean_ops_step` (order-ops-step.php, tính bằng thean_ow_flow() — cùng logic bảng điều phối) nên board chỉ
 * đếm + phân trang bằng SQL (cache ID/số đếm 10s), chỉ đọc tab đang xem; đơn hiện được tính lại tại chỗ.
 * Backfill đơn chưa phân bước chạy qua WP-Cron, không nằm trên request mở board.
 *
 * Chọn nhiều đơn (kết quả tìm + board) → thao tác hàng loạt: Tạm giữ / Tiếp tục / Đặt trước / Hủy (thean_ow_apply_flow của
 * order-workspace.php), Đổi trạng thái, Bỏ qua thiếu chứng từ XK (tab board); tối đa THEAN_OUS_BULK_MAX đơn / lần.
 *
 * AJAX: thean_order_usearch, thean_order_usearch_preview, thean_order_usearch_alerts, thean_order_usearch_board,
 * thean_order_usearch_bulk.
 */

const THEAN_OUS_PER_PAGE  = 10;
const THEAN_OUS_CAP       = 500;  // tối đa số đơn khớp giữ trong cache cho 1 truy vấn
const THEAN_OUS_SOURCE    = 1000; // tối đa ứng viên lấy từ mỗi nguồn
const THEAN_OUS_CACHE_TTL = 180;
const THEAN_OUS_ALERTS_META = 'thean_ous_alerts_off';
// "Ẩn thanh admin + menu trái" (menu ⋯): mặc định ẩn; bỏ tích lưu '0'.
const THEAN_OUS_CHROME_META = 'thean_ous_chrome_off';
const THEAN_OUS_PREVIEW_TTL = 120; // HTML xem nhanh trong object cache (Redis)
const THEAN_OUS_RL_MAX      = 4;   // tối đa lượt tìm thật (không trúng cache) …
const THEAN_OUS_RL_WINDOW   = 30;  // … trong 30 giây; lượt thứ 5 bị chặn
const THEAN_OUS_RL_BLOCK    = 120; // khóa 2 phút
const THEAN_OUS_BULK_MAX = 50; // thao tác hàng loạt: tối đa 50 đơn / lần
const THEAN_OUS_BOARD_FIRST = 10; // lần đầu chỉ tải tab đang xem
const THEAN_OUS_BOARD_MORE  = 20; // "Xem thêm" tải 20 đơn / lần (client gửi limit ≤ 50)
const THEAN_OUS_FLUSH_BATCH = 50;   // nút 🧹 Xoá cache: tính lại bước 50 đơn mở / request …
const THEAN_OUS_FLUSH_MAX   = 2000; // … tối đa 2000 đơn mới nhất (còn lại cron quét dần)
const THEAN_OUS_BOARD_CACHE_TTL = 10; // ID/số đếm; không cache HTML/quyền thao tác

/**
 * Meta vận đơn / hoàn hàng được tìm theo chuỗi con. Hithean: field Meta Box có tiền tố
 * "order_" (order-metabox.php, order_shipper là select nhiều giá trị); giữ key cũ để tìm đơn cũ.
 */
function thean_ous_tracking_meta_keys(): array
{
    return ['order_ship_code', 'ship_code', '_ghtk_ordercode', 'return_code', 'compensate_code', 'order_shipper', 'shipper'];
}

/** Đọc meta vận hành: key "order_*" (hiện tại) trước, rồi key cũ. Mảng → nối bằng dấu phẩy. */
function thean_ous_meta(WC_Order $order, array $keys): string
{
    foreach ($keys as $key) {
        $value = $order->get_meta($key);
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }
    return '';
}

/** Shipper (mã self/ghtk/…) → nhãn ngắn. Nhãn: hithean_shipper_labels() (order-shipper.php); map dưới là dự phòng khi port. */
function thean_ous_shipper(WC_Order $order): string
{
    $map = function_exists('hithean_shipper_labels')
        ? hithean_shipper_labels(true)
        : ['self' => 'THEAN', 'ghtk' => 'GHTK', 'ahamove' => 'Ahamove', 'viettel' => 'Viettel Post'];
    $raw = $order->get_meta('order_shipper');
    $raw = is_array($raw) ? $raw : ($raw === '' || $raw === null ? [] : [$raw]);
    $names = [];
    foreach ($raw as $code) {
        $code = strtolower(trim((string) $code));
        if ($code !== '') {
            $names[] = $map[$code] ?? $code;
        }
    }
    return $names ? implode(', ', array_unique($names)) : thean_ous_meta($order, ['shipper']);
}

function thean_ous_ship_code(WC_Order $order): string
{
    return thean_ous_meta($order, ['order_ship_code', 'ship_code', '_ghtk_ordercode']);
}

/** Lệnh lọc: khóa chuẩn => [nhãn, ví dụ, mô tả]. */
function thean_ous_commands(): array
{
    return [
        'tt'    => ['Trạng thái', '/tt dang-xu-ly', 'Lọc theo trạng thái — lặp lại để lấy nhiều trạng thái'],
        'sdt'   => ['SĐT', '/sdt 0901234567', 'SĐT người đặt / người nhận (từ 4 số)'],
        'id'    => ['ID / mã đơn', '/id 90433', 'ID đơn hoặc mã đơn hiển thị'],
        'sp'    => ['Sản phẩm', '/sp whey gold', 'Tên sản phẩm trong đơn'],
        'kh'    => ['Khách / địa chỉ', '/kh nguyễn văn a', 'Tên, công ty, địa chỉ khách'],
        'vd'    => ['Vận đơn', '/vd S2278', 'Mã vận đơn, shipper, mã hoàn / bồi hoàn'],
        'email' => ['Email', '/email gmail.com', 'Email người đặt'],
    ];
}

function thean_ous_command_aliases(): array
{
    return [
        'status' => 'tt', 'trangthai' => 'tt', 'phone' => 'sdt', 'dt' => 'sdt',
        'ma' => 'id', 'product' => 'sp', 'ship' => 'vd', 'vandon' => 'vd', 'khach' => 'kh', 'mail' => 'email',
    ];
}

function thean_ous_user_can(): bool
{
    return current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce');
}

function thean_ous_hpos_enabled(): bool
{
    return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/** Tách từ khóa thành các token, tối đa 6 token. */
function thean_ous_tokens(string $term): array
{
    $tokens = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_unique($tokens)), 0, 6);
}

/** Chuẩn hóa không dấu để so trạng thái. */
function thean_ous_norm(string $text): string
{
    return sanitize_title(remove_accents($text));
}

/** [['slug' => 'processing', 'name' => 'Đang xử lý', 'norm' => 'dang-xu-ly'], ...] */
function thean_ous_statuses(): array
{
    $out = [];
    foreach (wc_get_order_statuses() as $key => $name) {
        $out[] = ['slug' => substr($key, 3), 'name' => $name, 'norm' => thean_ous_norm($name)];
    }
    return $out;
}

/** Tham số /tt → danh sách slug khớp (khớp đúng slug/tên ưu tiên, sau đó chứa chuỗi). */
function thean_ous_resolve_status(string $arg): array
{
    $needle = preg_replace('/^wc-/', '', thean_ous_norm($arg));
    if ($needle === '') {
        return [];
    }
    $exact = $partial = [];
    foreach (thean_ous_statuses() as $status) {
        if ($status['slug'] === $needle || $status['norm'] === $needle) {
            $exact[] = $status['slug'];
        } elseif (strpos($status['slug'], $needle) !== false || strpos($status['norm'], $needle) !== false) {
            $partial[] = $status['slug'];
        }
    }
    return $exact ?: $partial;
}

/**
 * Tách truy vấn thành bộ lọc. Lệnh không biết → giữ nguyên trong text tự do.
 * /tt và /id chỉ lấy 1 từ làm tham số, phần sau trả về text tự do.
 *
 * @return array{text:string,phone:string,id:string,product:string,customer:string,ship:string,email:string,statuses:string[],errors:string[]}
 */
function thean_ous_parse_query(string $query): array
{
    $filters = ['text' => '', 'phone' => '', 'id' => '', 'product' => '', 'customer' => '', 'ship' => '', 'email' => '', 'statuses' => [], 'errors' => []];
    $map     = ['sdt' => 'phone', 'id' => 'id', 'sp' => 'product', 'kh' => 'customer', 'vd' => 'ship', 'email' => 'email'];
    $aliases = thean_ous_command_aliases();
    $commands = thean_ous_commands();

    $parts = preg_split('/(?:^|\s)\/([a-z]+)\b/iu', ' ' . trim($query), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $text  = [trim((string) array_shift($parts))];

    for ($i = 0; $i < count($parts); $i += 2) {
        $cmd = strtolower($parts[$i]);
        $cmd = $aliases[$cmd] ?? $cmd;
        $arg = trim((string) ($parts[$i + 1] ?? ''));

        if (!isset($commands[$cmd])) {
            $text[] = '/' . $parts[$i] . ' ' . $arg;
            continue;
        }
        if ($cmd === 'tt' || $cmd === 'id') {
            $words = preg_split('/\s+/u', $arg, 2);
            $arg   = (string) ($words[0] ?? '');
            $text[] = (string) ($words[1] ?? '');
        }
        if ($arg === '') {
            continue;
        }
        if ($cmd === 'tt') {
            $found = thean_ous_resolve_status($arg);
            if (!$found) {
                $filters['errors'][] = sprintf('Không có trạng thái “%s”.', $arg);
            }
            $filters['statuses'] = array_values(array_unique(array_merge($filters['statuses'], $found)));
            continue;
        }
        $key = $map[$cmd];
        $filters[$key] = trim($filters[$key] . ' ' . $arg);
    }

    $filters['text'] = trim(preg_replace('/\s+/u', ' ', implode(' ', $text)));
    return $filters;
}

/** Bộ lọc có đủ điều kiện để chạy không (tránh LIKE 1–2 ký tự quét cả bảng). */
function thean_ous_validate(array $f): string
{
    if ($f['errors']) {
        return implode(' ', $f['errors']);
    }
    if ($f['phone'] !== '' && strlen(thean_ous_phone_needle($f['phone'], 4)) < 4) {
        return 'SĐT cần ít nhất 4 chữ số.';
    }
    if ($f['id'] !== '' && !thean_ous_candidate_ids($f['id'])) {
        return 'ID / mã đơn phải là số.';
    }
    foreach (['text' => 'Từ khóa', 'product' => 'Tên SP', 'customer' => 'Tên khách', 'ship' => 'Mã vận đơn', 'email' => 'Email'] as $key => $label) {
        if ($f[$key] !== '' && mb_strlen($f[$key]) < 3) {
            return $label . ' cần ít nhất 3 ký tự.';
        }
    }
    $has = $f['statuses'];
    foreach (['text', 'phone', 'id', 'product', 'customer', 'ship', 'email'] as $key) {
        $has = $has || $f[$key] !== '';
    }
    return $has ? '' : 'Nhập ít nhất 3 ký tự hoặc dùng lệnh / để lọc.';
}

/**
 * Các ID có thể ứng với chuỗi số người dùng gõ: ID trực tiếp, hoặc mã đơn hiển thị
 * (ID + hậu tố 1–2 chữ số, xem order-number.php). Chỉ nhận khi hậu tố khớp.
 */
function thean_ous_candidate_ids(string $term): array
{
    $digits = preg_replace('/[#*\s]/', '', $term);
    if ($digits === '' || !ctype_digit($digits) || strlen($digits) > 12) {
        return [];
    }

    $ids = [(int) $digits];
    foreach ([1, 2] as $cut) {
        if (strlen($digits) - $cut < 3) {
            continue;
        }
        $id = (int) substr($digits, 0, -$cut);
        if (function_exists('thean_change_woocommerce_order_number') && (string) thean_change_woocommerce_order_number((string) $id) === $digits) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/** SĐT dạng chuẩn hóa để LIKE: bỏ ký tự không phải số, bỏ 84/0 đầu. */
function thean_ous_phone_needle(string $term, int $min = 6): string
{
    if (preg_match('/[^\d\s.+()\-]/', $term)) {
        return '';
    }
    $digits = preg_replace('/\D/', '', $term);
    if (strpos($digits, '84') === 0 && strlen($digits) >= 11) {
        $digits = substr($digits, 2);
    }
    $digits = ltrim($digits, '0');
    return strlen($digits) >= $min ? $digits : '';
}

/** SQL biểu thức chuẩn hóa SĐT (bỏ khoảng trắng, chấm, gạch, ngoặc, +). */
function thean_ous_phone_sql(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '.', ''), '-', ''), '(', ''), ')', ''), '+', '')";
}

/** "col LIKE %t1% AND col LIKE %t2% ..." (đã prepare — không bọc thêm prepare()). */
function thean_ous_tokens_like(string $column, array $tokens): string
{
    global $wpdb;
    $parts = [];
    foreach ($tokens as $token) {
        $parts[] = $wpdb->prepare("{$column} LIKE %s", '%' . $wpdb->esc_like($token) . '%');
    }
    return $parts ? '(' . implode(' AND ', $parts) . ')' : '1=0';
}

function thean_ous_like(string $term): string
{
    global $wpdb;
    return '%' . $wpdb->esc_like($term) . '%';
}

/** Khoảng theo ngày tạo đơn (UTC), dùng chung UI, SQL và cache. */
function thean_ous_range(string $range): string
{
    return in_array($range, ['auto', '7', '30', '90', 'all'], true) ? $range : 'auto';
}

function thean_ous_range_label(string $range, string $tab = ''): string
{
    $range = thean_ous_range($range);
    if ($range === 'all') {
        return $tab === '' ? 'Toàn bộ thời gian' : 'Toàn bộ trong phạm vi xử lý';
    }
    if ($range === 'auto' && $tab === '') {
        return '30 ngày; Hoàn hàng / Đặt trước: 90 ngày';
    }
    $days = $range === 'auto' ? (in_array($tab, ['return', 'preorder'], true) ? 90 : 30) : (int) $range;
    if ($tab === 'proof') {
        $days = min($days, THEAN_OPS_PROOF_DAYS);
    }
    return $days . ' ngày gần nhất';
}

/** $alias / $step_column chỉ do code nội bộ truyền, không nhận tên cột từ request. */
function thean_ous_range_sql(string $range, string $alias = 'o', ?string $step_column = null, ?int $now = null): string
{
    $range = thean_ous_range($range);
    if ($range === 'all') {
        return '1=1';
    }
    $t = thean_ops_step_tables();
    $date = $alias . '.' . $t['date'];
    $now = $now ?? time();
    $since = static fn(int $days) => "'" . gmdate('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS) . "'";
    if ($range !== 'auto') {
        return $date . ' >= ' . $since((int) $range);
    }
    $special = $step_column !== null ? $step_column . " IN ('return','preorder')"
        : "({$alias}.{$t['status']} = 'wc-pre-order' OR EXISTS (SELECT 1 FROM {$t['meta']} drs WHERE drs.{$t['fk']} = {$alias}.{$t['id']} AND drs.meta_key = '" . THEAN_OPS_STEP_META . "' AND drs.meta_value IN ('return','preorder')))";
    return "({$date} >= " . $since(30) . " OR ({$date} >= " . $since(90) . " AND {$special}))";
}

/** Áp dụng giới hạn ngay ở từng nguồn, trước LIMIT ứng viên. */
function thean_ous_source_range_sql(string $column, string $range, array $only_ids = []): string
{
    $restricted = $only_ids ? " AND {$column} IN (" . implode(',', thean_ous_int_ids($only_ids)) . ')' : '';
    if (thean_ous_range($range) === 'all') {
        return $restricted;
    }
    $t = thean_ops_step_tables();
    return $restricted . " AND {$column} IN (SELECT dr.{$t['id']} FROM {$t['orders']} dr WHERE dr.{$t['type']} = 'shop_order' AND " . thean_ous_range_sql($range, 'dr') . ')';
}

function thean_ous_ids_product(array $tokens, string $range = 'all', array $only_ids = []): array
{
    global $wpdb;
    return $wpdb->get_col(
        "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items
         WHERE order_item_type = 'line_item' AND " . thean_ous_tokens_like('order_item_name', $tokens) . thean_ous_source_range_sql('order_id', $range, $only_ids) . "
         ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE
    );
}

function thean_ous_ids_customer(array $tokens, string $like, string $range = 'all', array $only_ids = []): array
{
    global $wpdb;
    if (thean_ous_hpos_enabled()) {
        $concat = "CONCAT_WS(' ', first_name, last_name, company, address_1, address_2, city, state, postcode, email, phone)";
        return $wpdb->get_col(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_addresses
             WHERE " . thean_ous_tokens_like($concat, $tokens) . thean_ous_source_range_sql('order_id', $range, $only_ids) . " ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE
        );
    }

    // _billing/_shipping_address_index chứa sẵn tên + công ty + địa chỉ + email + SĐT.
    $ids = $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_address_index', '_shipping_address_index')
           AND " . thean_ous_tokens_like('meta_value', $tokens) . thean_ous_source_range_sql('post_id', $range, $only_ids) . "
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE
    );
    // Đơn cũ thiếu address_index: tìm thêm theo từng trường tên / địa chỉ.
    return array_merge($ids, $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name', '_billing_address_1', '_shipping_address_1')
           AND meta_value LIKE %s" . thean_ous_source_range_sql('post_id', $range, $only_ids) . "
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    )));
}

function thean_ous_ids_phone(string $needle, string $range = 'all', array $only_ids = []): array
{
    global $wpdb;
    $like = thean_ous_like($needle);
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_addresses
             WHERE " . thean_ous_phone_sql('phone') . " LIKE %s" . thean_ous_source_range_sql('order_id', $range, $only_ids) . " ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE,
            $like
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_phone', '_shipping_phone') AND " . thean_ous_phone_sql('meta_value') . " LIKE %s" . thean_ous_source_range_sql('post_id', $range, $only_ids) . "
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    ));
}

function thean_ous_ids_email(string $like, string $range = 'all', array $only_ids = []): array
{
    global $wpdb;
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE %s" . thean_ous_source_range_sql('id', $range, $only_ids) . " ORDER BY id DESC LIMIT " . THEAN_OUS_SOURCE,
            $like
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_email' AND meta_value LIKE %s" . thean_ous_source_range_sql('post_id', $range, $only_ids) . " ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    ));
}

function thean_ous_ids_ship(string $like, string $range = 'all', array $only_ids = []): array
{
    global $wpdb;
    $keys  = thean_ous_tracking_meta_keys();
    $in    = implode(',', array_fill(0, count($keys), '%s'));
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ({$in}) AND meta_value LIKE %s" . thean_ous_source_range_sql('order_id', $range, $only_ids) . " ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE,
            array_merge($keys, [$like])
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s" . thean_ous_source_range_sql('post_id', $range, $only_ids) . " ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        array_merge($keys, [$like])
    ));
}

function thean_ous_int_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

/**
 * Tìm ID đơn khớp bộ lọc: mỗi bộ lọc cho 1 tập ứng viên, giao các tập lại (AND), rồi
 * lọc lần cuối theo loại đơn / trạng thái, mới nhất trước. Text tự do = hợp mọi nguồn.
 *
 * @return int[]
 */
function thean_ous_find_order_ids(array $f, int $cap = THEAN_OUS_CAP, string $range = 'auto', array $only_ids = []): array
{
    global $wpdb;

    $range = thean_ous_range($range);
    $source_range = $range === 'auto' && $f['id'] !== '' ? 'all' : $range;
    $sets = [];
    if ($f['text'] !== '') {
        $tokens = thean_ous_tokens($f['text']);
        $like   = thean_ous_like($f['text']);
        $set    = array_merge(
            thean_ous_candidate_ids($f['text']),
            thean_ous_ids_product($tokens, $source_range, $only_ids),
            thean_ous_ids_customer($tokens, $like, $source_range, $only_ids),
            thean_ous_ids_email($like, $source_range, $only_ids),
            thean_ous_ids_ship($like, $source_range, $only_ids)
        );
        $phone = thean_ous_phone_needle($f['text']);
        if ($phone !== '') {
            $set = array_merge($set, thean_ous_ids_phone($phone, $source_range, $only_ids));
        }
        $sets[] = $set;
    }
    if ($f['id'] !== '') {
        $sets[] = thean_ous_candidate_ids($f['id']);
    }
    if ($f['phone'] !== '') {
        $sets[] = thean_ous_ids_phone(thean_ous_phone_needle($f['phone'], 4), $source_range, $only_ids);
    }
    if ($f['product'] !== '') {
        $sets[] = thean_ous_ids_product(thean_ous_tokens($f['product']), $source_range, $only_ids);
    }
    if ($f['customer'] !== '') {
        $sets[] = thean_ous_ids_customer(thean_ous_tokens($f['customer']), thean_ous_like($f['customer']), $source_range, $only_ids);
    }
    if ($f['ship'] !== '') {
        $sets[] = thean_ous_ids_ship(thean_ous_like($f['ship']), $source_range, $only_ids);
    }
    if ($f['email'] !== '') {
        $sets[] = thean_ous_ids_email(thean_ous_like($f['email']), $source_range, $only_ids);
    }

    $ids = null;
    foreach ($sets as $set) {
        $set = thean_ous_int_ids($set);
        $ids = $ids === null ? $set : array_values(array_intersect($ids, $set));
        if (!$ids) {
            return [];
        }
    }

    $hpos  = thean_ous_hpos_enabled();
    $id_col = $hpos ? 'id' : 'ID';
    $st_col = $hpos ? 'status' : 'post_status';
    $where = [
        $hpos ? "type = 'shop_order'" : "post_type = 'shop_order'",
        "{$st_col} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft')",
    ];
    if ($only_ids) {
        $where[] = "{$id_col} IN (" . implode(',', thean_ous_int_ids($only_ids)) . ')';
    }
    if ($ids !== null) {
        $where[] = "{$id_col} IN (" . implode(',', $ids) . ')';
    }
    if ($f['statuses']) {
        $where[] = "{$st_col} IN ('" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), $f['statuses'])) . "')";
    }

    $window = thean_ous_range_sql($range);
    $exact = $range === 'auto' ? thean_ous_candidate_ids($f['id'] !== '' ? $f['id'] : $f['text']) : [];
    $where[] = $exact ? '(' . $window . " OR o.{$id_col} IN (" . implode(',', $exact) . '))' : $window;
    $table = $hpos ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
    return array_map('intval', $wpdb->get_col(
        "SELECT {$id_col} FROM {$table} o WHERE " . implode(' AND ', $where) . " ORDER BY {$id_col} DESC LIMIT " . (int) $cap
    ));
}

/** Version cache "orders" của WC — đổi mỗi khi đơn được lưu / đổi trạng thái. */
function thean_ous_orders_version(): string
{
    return class_exists('WC_Cache_Helper') ? (string) WC_Cache_Helper::get_transient_version('orders') : '';
}

/** Khóa transient cho danh sách ID khớp (Redis nếu có object cache). Phân trang chỉ cắt mảng này. */
function thean_ous_ids_cache_key(array $f, string $range = 'auto'): string
{
    unset($f['errors']);
    return 'thean_ous_' . md5(wp_json_encode([$f, thean_ous_range($range), 'date-window-v1', gmdate('Y-m-d-H-i'), thean_ous_hpos_enabled(), thean_ous_orders_version()]));
}

/** Còn bị khóa tìm kiếm bao nhiêu giây (0 = không). */
function thean_ous_rate_blocked(int $user_id, ?int $now = null): int
{
    $now   = $now ?? time();
    $until = (int) get_transient('thean_ous_rl_block_' . $user_id);
    return $until > $now ? $until - $now : 0;
}

/**
 * Ghi 1 lượt tìm thật (truy vấn DB). Quá THEAN_OUS_RL_MAX lượt trong THEAN_OUS_RL_WINDOW giây thì
 * khóa THEAN_OUS_RL_BLOCK giây. Trả số giây bị khóa (0 = được tìm).
 */
function thean_ous_rate_hit(int $user_id, ?int $now = null): int
{
    $now  = $now ?? time();
    $wait = thean_ous_rate_blocked($user_id, $now);
    if ($wait) {
        return $wait;
    }
    $key  = 'thean_ous_rl_hits_' . $user_id;
    $hits = array_values(array_filter((array) get_transient($key), static fn($t) => (int) $t > $now - THEAN_OUS_RL_WINDOW));
    if (count($hits) >= THEAN_OUS_RL_MAX) {
        set_transient('thean_ous_rl_block_' . $user_id, $now + THEAN_OUS_RL_BLOCK, THEAN_OUS_RL_BLOCK);
        delete_transient($key);
        return THEAN_OUS_RL_BLOCK;
    }
    $hits[] = $now;
    set_transient($key, $hits, THEAN_OUS_RL_WINDOW);
    return 0;
}

/** Token để tô vàng trong kết quả. */
function thean_ous_highlight_tokens(array $f): array
{
    $tokens = [];
    foreach (['text', 'product', 'customer', 'ship', 'email', 'id'] as $key) {
        $tokens = array_merge($tokens, thean_ous_tokens($f[$key]));
    }
    if ($f['phone'] !== '') {
        $tokens[] = $f['phone'];
    }
    return array_values(array_unique($tokens));
}

/** Mô tả ngắn các bộ lọc đang áp dụng. */
function thean_ous_filter_summary(array $f): array
{
    $out = [];
    if ($f['statuses']) {
        $out[] = 'Trạng thái: ' . implode(', ', array_map('wc_get_order_status_name', $f['statuses']));
    }
    $labels = ['id' => 'ID', 'phone' => 'SĐT', 'product' => 'SP', 'customer' => 'Khách', 'ship' => 'Vận đơn', 'email' => 'Email', 'text' => 'Từ khóa'];
    foreach ($labels as $key => $label) {
        if ($f[$key] !== '') {
            $out[] = $label . ': ' . $f[$key];
        }
    }
    return $out;
}

/** Escape + bọc <mark> các token khớp. */
function thean_ous_highlight(string $text, array $tokens): string
{
    $html = esc_html($text);
    $patterns = [];
    foreach ($tokens as $token) {
        $token = esc_html($token);
        if (mb_strlen($token) >= 2) {
            $patterns[] = preg_quote($token, '/');
        }
    }
    if (!$patterns || $html === '') {
        return $html;
    }
    usort($patterns, static fn($a, $b) => strlen($b) - strlen($a));
    $out = preg_replace('/(' . implode('|', $patterns) . ')/iu', '<mark>$1</mark>', $html);
    return is_string($out) ? $out : $html;
}

function thean_ous_customer_name(WC_Order $order): string
{
    $name = trim($order->get_formatted_billing_full_name());
    if ($name === '') {
        $name = trim($order->get_formatted_shipping_full_name());
    }
    return $name !== '' ? $name : __('Khách vãng lai', 'woocommerce');
}

function thean_ous_address_line(WC_Order $order): string
{
    $type  = $order->has_shipping_address() ? 'shipping' : 'billing';
    $parts = [];
    foreach (['address_1', 'address_2', 'city', 'state'] as $field) {
        $getter = "get_{$type}_{$field}";
        $value  = trim((string) $order->$getter());
        if ($field === 'state' && $value !== '') {
            $states = WC()->countries->get_states($order->{"get_{$type}_country"}() ?: 'VN');
            $value  = $states[$value] ?? $value;
        }
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    return implode(', ', $parts);
}

function thean_ous_status_badge(WC_Order $order): string
{
    $status = $order->get_status();
    return '<mark class="order-status status-' . esc_attr(sanitize_html_class($status)) . '"><span>'
        . esc_html(wc_get_order_status_name($status)) . '</span></mark>';
}

function thean_ous_short_date(WC_Order $order): string
{
    $date = $order->get_date_created();
    if (!$date) {
        return '';
    }
    $same_year = $date->date_i18n('Y') === current_time('Y');
    return $date->date_i18n($same_year ? 'd/m H:i' : 'd/m/Y H:i');
}

function thean_ous_creator_url(int $order_id): string
{
    $route = defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don';
    return home_url('/' . $route . '/?order_id=' . $order_id);
}

/**
 * Các action của đơn giống cột "Thao tác" ở danh sách: filter
 * woocommerce_admin_order_actions (add_custom_actions ở orders-admin.php, print_pxk...).
 */
function thean_ous_order_actions(WC_Order $order): array
{
    if (!function_exists('add_custom_actions')) {
        require_once __DIR__ . '/orders-admin.php';
    }

    // Không có nút core "Đang xử lý" / "Hoàn thành" (đã bỏ ở add_custom_actions).
    return (array) apply_filters('woocommerce_admin_order_actions', [], $order);
}

/** Nhóm + nhãn ngắn cho từng action (dùng chung với cột Thao tác). Nhóm quyết định màu nút. */
function thean_ous_action_meta(string $slug): array
{
    $meta = hithean_order_action_meta($slug);
    return [$meta['group'], $meta['short'], $meta['icon']];
}

function thean_ous_action_groups(): array
{
    return ['view' => 'Xem', 'flow' => 'Tạm giữ / Hủy', 'pay' => 'Thanh toán', 'kho' => 'Kho', 'hoan' => 'Hoàn', 'other' => 'Khác'];
}

function thean_ous_button(string $group, string $label, string $url, string $class = '', bool $blank = false, string $icon = ''): string
{
    $icon_html = $icon !== '' ? '<span class="thean-ous-btn__icon" aria-hidden="true">' . esc_html($icon) . '</span> ' : '';
    return '<a class="thean-ous-btn thean-ous-btn--' . esc_attr($group) . ($class !== '' ? ' ' . esc_attr($class) : '') . '" href="' . esc_url($url) . '"'
        . ($blank ? ' target="_blank" rel="noopener"' : '') . ' title="' . esc_attr($label) . '">' . $icon_html . esc_html($label) . '</a>';
}

/**
 * Nút thao tác theo nhóm. $compact = dòng kết quả: chỉ nhóm Xem + thao tác nhanh (modal);
 * xem nhanh: mọi action, có tiêu đề nhóm.
 */
/**
 * Nút Tạm giữ / Tiếp tục / Hủy (popup lý do — assets/order-flow-popup.js → task order_flow của order-workspace.php).
 *
 * @return string[]
 */
function thean_ous_flow_buttons(WC_Order $order): array
{
    $status = $order->get_status();
    if (!current_user_can('manage_woocommerce') || in_array($status, ['cancelled', 'failed', 'refunded', 'trash'], true)
        || (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order))) {
        return [];
    }
    $attrs = ' data-order-id="' . esc_attr($order->get_id()) . '" data-phone="' . esc_attr($order->get_billing_phone() ?: $order->get_shipping_phone()) . '"';
    $btn   = static fn(string $kind, string $label, string $tone) => '<button type="button" class="thean-ous-btn thean-ous-btn--' . $tone . '" data-thean-flow="' . $kind . '"' . $attrs . '>' . esc_html($label) . '</button>';
    $out   = [in_array($status, ['on-hold', 'pre-order'], true) ? $btn('resume', '▶️ Tiếp tục', 'flow') : $btn('hold', '⏸️ Tạm giữ', 'flow')];
    $out[] = $btn('cancel', '✖️ Hủy', 'danger');
    if (function_exists('thean_can_change_any_status') && thean_can_change_any_status()) {
        $out[] = '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-ous-status="' . esc_attr($status) . '"' . $attrs . '>🔁 Đổi trạng thái</button>';
    }
    return $out;
}

/**
 * Cột "Thao tác tiếp theo": nút đúng bước tiếp theo của đơn (bước tính bằng thean_ops_step_compute → thean_ow_flow,
 * cùng logic bảng điều phối). Lấy từ các action sẵn có (woocommerce_admin_order_actions, so theo đuôi slug để chạy
 * cả thean-order-* lẫn hithean-order-*); bước chưa có thao tác nhanh → mở trang sửa đơn đúng bước (#step-<bước>,
 * bảng điều phối mở tab / box tương ứng).
 */
function thean_ous_next_actions(WC_Order $order, string $step, bool $proof = false): string
{
    if ($step === '') {
        return '';
    }
    $map = [
        'confirm' => ['order-customer-confirmed', 'order-preorder-confirm'],
        'payment' => ['order-payment'],
        'cod'     => ['order-payment'],
        'cod_fast' => ['order-payment'],
        'picking' => ['order-export-images', 'in-van-don-ghtk', 'print_pxk'],
        'export'  => ['order-export-confirm'],
        'return'  => ['order-return-record', 'order-return-images'],
        'proof'   => ['order-export-images', 'order-export-confirm'],
    ];
    $buttons = [];
    // Đơn đang giao thiếu chứng từ XK: nút theo dõi giao + nút bổ sung chứng từ (như tab Thiếu / chưa xác nhận ảnh xuất kho).
    $suffixes = array_merge($map[$step] ?? [], $proof && $step !== 'proof' ? $map['proof'] : []);
    foreach ($suffixes as $suffix) {
        foreach (thean_ous_order_actions($order) as $key => $action) {
            $slug = sanitize_html_class($action['action'] ?? $key);
            if (empty($action['url']) || ($slug !== $suffix && substr($slug, -strlen('-' . $suffix)) !== '-' . $suffix)) {
                continue;
            }
            [$group, $label, $icon] = thean_ous_action_meta($slug);
            $label = $label !== '' ? $label : wp_strip_all_tags((string) ($action['name'] ?? $key));
            $buttons[] = thean_ous_button($group, $label, (string) $action['url'], 'wc-action-button wc-action-button-' . $slug, !empty($action['target']), $icon);
        }
    }
    if ($step === 'preorder') {
        $buttons = array_slice(thean_ous_flow_buttons($order), 0, 1); // ▶️ Tiếp tục
    }
    if (($step === 'proof' || $proof) && function_exists('thean_can_skip_export_proof') && thean_can_skip_export_proof()) {
        $buttons[] = '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-flow="skipproof" data-order-id="' . esc_attr($order->get_id()) . '">⏭️ Bỏ qua</button>';
    }
    if ($step === 'delivery' && (string) $order->get_meta('_ghtk_ordercode') === '') {
        // Phương án A: mở trang sửa đơn, bảng điều phối mở + cuộn tới khung GHTK của plugin (#step-ghtk).
        $buttons[] = thean_ous_button('kho', '🚚 Đăng GHTK ↗', $order->get_edit_order_url() . '#step-ghtk', '', true);
    }
    if (!$buttons || $step === 'delivery' || $step === 'shipping') {
        $tab = ['cod' => 'payment', 'cod_fast' => 'payment', 'shipping' => 'delivery'][$step] ?? $step;
        $labels = function_exists('thean_ops_step_labels') ? thean_ops_step_labels() : [];
        $buttons[] = thean_ous_button('view', 'Mở bước ' . ($labels[$step] ?? $step) . ' ↗', $order->get_edit_order_url() . '#step-' . $tab, '', true, '➡');
    }
    return '<div class="thean-ous-acts__g">' . implode('', $buttons) . '</div>';
}

/**
 * Link xem hóa đơn của đơn — dùng lại bản xem trước hóa đơn của trang Tạo đơn (AJAX order_creator_invoice_preview,
 * WC_Email_Customer_Invoice). '' khi theme không có module tạo đơn / người xem không có quyền (thean_order_creator_url).
 */
function thean_ous_invoice_url(WC_Order $order): string
{
    if (!function_exists('thean_order_creator_url') || thean_order_creator_url() === '') {
        return '';
    }
    return add_query_arg([
        'action'   => 'order_creator_invoice_preview',
        'order_id' => $order->get_id(),
        'nonce'    => wp_create_nonce(defined('ORDER_CREATOR_NONCE') ? ORDER_CREATOR_NONCE : 'order_creator_nonce'),
    ], admin_url('admin-ajax.php'));
}

function thean_ous_render_actions(WC_Order $order, bool $compact): string
{
    $id      = $order->get_id();
    $buttons = array_fill_keys(array_keys(thean_ous_action_groups()), []);

    if ($compact) {
        $buttons['view'][] = '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-ous-preview="' . esc_attr($id) . '"><span class="thean-ous-btn__icon" aria-hidden="true">👁</span> Xem nhanh</button>';
    }
    $buttons['view'][] = thean_ous_button('view', 'Chi tiết', $order->get_edit_order_url(), '', true, '📄');
    $invoice = thean_ous_invoice_url($order);
    if ($invoice !== '') {
        $buttons['view'][] = '<button type="button" class="thean-ous-btn thean-ous-btn--view" data-thean-ous-invoice="' . esc_url($invoice) . '" data-order-id="' . esc_attr($order->get_id()) . '">🧾 Xem hóa đơn</button>';
    }
    if (function_exists('thean_vat_request_button') && ($vat = thean_vat_request_button($order)) !== '') {
        $buttons['view'][] = $vat; // Nhập / sửa thông tin xuất VAT (order-vat-request.php)
    }

    foreach (thean_ous_order_actions($order) as $key => $action) {
        if (empty($action['url'])) {
            continue;
        }
        $slug = sanitize_html_class($action['action'] ?? $key);
        if ($slug === 'chinh-don-tao-don') {
            continue; // nút "Chỉnh" riêng bên dưới
        }
        $is_flow = $slug === 'thean-order-preorder';
        if ($compact && !$is_flow) {
            continue; // dòng kết quả: thao tác theo bước nằm ở cột "Thao tác tiếp theo" (thean_ous_next_actions)
        }
        [$group, $label, $icon] = thean_ous_action_meta($slug);
        $group = $is_flow ? 'flow' : $group;
        $label = $is_flow ? 'Đặt trước' : ($label !== '' ? $label : wp_strip_all_tags((string) ($action['name'] ?? $key)));
        $buttons[$group][] = thean_ous_button($group, $label, (string) $action['url'], 'wc-action-button wc-action-button-' . $slug, !empty($action['target']), $icon);
    }

    $buttons['flow'] = array_merge(thean_ous_flow_buttons($order), $buttons['flow']);

    if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
        array_splice($buttons['view'], $compact ? 1 : 0, 0, [thean_ous_button('edit', 'Chỉnh', thean_ous_creator_url($id), '', true, '✏')]);
    }

    $html = '';
    foreach (thean_ous_action_groups() as $group => $title) {
        if (!$buttons[$group]) {
            continue;
        }
        $html .= '<div class="thean-ous-acts__g thean-ous-acts__g--' . esc_attr($group) . '">'
            . ($compact ? '' : '<span class="thean-ous-acts__label">' . esc_html($title) . '</span>')
            . implode('', $buttons[$group]) . '</div>';
    }
    return '<div class="thean-ous-acts">' . $html . '</div>';
}

/**
 * Đọc gộp các đơn của 1 trang bằng 1 lần wc_get_orders (HPOS: read_multiple; legacy: WP_Query + prime
 * cache post/meta/items). Giữ đúng thứ tự $ids; đơn nào không trả về thì wc_get_order lẻ.
 *
 * @return WC_Order[]
 */
function thean_ous_load_orders(array $ids): array
{
    $loaded = [];
    if (count($ids) > 1 && function_exists('wc_get_orders')) {
        $found = wc_get_orders(['post__in' => $ids, 'type' => 'shop_order', 'limit' => count($ids), 'return' => 'objects']);
        foreach ((array) $found as $order) {
            if ($order instanceof WC_Order) {
                $loaded[$order->get_id()] = $order;
            }
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $order = $loaded[$id] ?? wc_get_order($id);
        if ($order instanceof WC_Order) {
            $out[] = $order;
        }
    }
    return $out;
}

/**
 * Nhãn "📍 bước của flow" ở dòng kết quả: bước tính trực tiếp (cùng logic board), lý do khi đơn không vào board,
 * và cảnh báo khi meta bước board đang lưu khác (dữ liệu cũ / cache) — bấm 🧹 Xoá cache để tính lại.
 */
function thean_ous_step_badge(WC_Order $order, array $flow): string
{
    $tabs   = function_exists('thean_ops_step_labels') ? thean_ops_step_labels() : [];
    $step   = (string) ($flow['step'] ?? '');
    $stored = defined('THEAN_OPS_STEP_META') ? (string) $order->get_meta(THEAN_OPS_STEP_META) : '';
    $class  = 'is-' . ($step !== '' ? $step : 'none');
    $off    = false;
    if ($step !== '') {
        $label = $tabs[$step] ?? $step;
        if (!empty($flow['proof']) && $step !== 'proof') {
            $label .= ' + ' . ($tabs['proof'] ?? 'Thiếu / chưa xác nhận ảnh xuất kho');
        }
    } else {
        $created = $order->get_date_created();
        $off     = true;
        if ((string) $order->get_meta('_spx_date') !== '') {
            $label = 'Đơn gộp Shopee — ngoài board';
        } elseif (function_exists('thean_order_is_internal') && thean_order_is_internal($order)) {
            $label = 'Đơn nội bộ — ngoài board';
        } elseif (function_exists('thean_ops_age_cutoff_ts') && (!$created || $created->getTimestamp() < thean_ops_age_cutoff_ts())) {
            $label = 'Đơn cũ (trước mốc khóa) — ngoài board';
        } elseif (function_exists('thean_ops_excluded_statuses') && in_array($order->get_status(), thean_ops_excluded_statuses(), true)) {
            $label = 'Đã đóng — ngoài board';
        } else {
            $label = 'Không cần xử lý';
            $off   = false;
        }
    }
    $html = '<span class="thean-ous__step-label">📍 ' . esc_html($label) . '</span>';
    // Lệch với meta board (chỉ xét đơn board có thể hiện): đơn nội bộ / cũ / gộp Shopee bị SQL loại nên bỏ qua.
    $board = $step !== '' ? $step : 'none';
    if (!$off && $stored !== $board) {
        $class .= ' is-stale';
        $html  .= '<small class="thean-ous__step-warn" title="Board đọc bước lưu sẵn; bấm 🧹 Xoá cache trên thanh tìm để tính lại">⚠ Board đang xếp: '
            . esc_html($stored === '' ? 'chưa tính' : ($stored === 'none' ? 'Không cần xử lý' : ($tabs[$stored] ?? $stored))) . '</small>';
    }
    return '<div class="thean-ous__step ' . esc_attr($class) . '">' . $html . '</div>';
}

/**
 * Mã vận đơn GHTK dạng "S12734516.BO.SGP120-V44.1363613511" → chỉ lấy phần cuối "1363613511"
 * (mã tra cứu ngắn). Mã khác định dạng giữ nguyên.
 */
function thean_ous_ship_code_short(string $code): string
{
    $code = trim($code);
    return preg_match('/^S\d+\..*\.(\d+)$/i', $code, $m) ? $m[1] : $code;
}

function thean_ous_render_rows(array $ids, array $tokens, array $hints = [], ?array $orders = null, array $flows = []): string
{
    ob_start();
    foreach ($orders ?? thean_ous_load_orders($ids) as $order) {
        $id = $order->get_id();
        // Bước tiếp theo (board đã tính sẵn thì dùng gợi ý của board).
        $flow = $flows[$id] ?? (function_exists('thean_ops_step_compute') ? thean_ops_step_compute($order) : ['step' => '', 'hint' => '', 'proof' => false]);
        // sync lưu bước không gated; render vẫn loại đơn vừa trở thành nội bộ/cũ.
        if (isset($flows[$id]) && thean_ops_step_excluded($order)) {
            $flow = ['step' => '', 'hint' => '', 'proof' => false];
        }
        if (!empty($hints[$id]) && $flow['step'] !== '') {
            $flow['hint'] = $hints[$id];
        }
        $shipper = thean_ous_shipper($order);
        $ship_full = thean_ous_ship_code($order);
        $ship      = thean_ous_ship_code_short($ship_full);
        $phone   = $order->get_billing_phone() ?: $order->get_shipping_phone();
        $address = thean_ous_address_line($order);
        ?>
        <tr data-order-id="<?php echo esc_attr($id); ?>" data-ops-step="<?php echo esc_attr($flow['step']); ?>" tabindex="0">
            <td class="thean-ous__c-check"><label><input type="checkbox" data-thean-ous-check value="<?php echo esc_attr($id); ?>" aria-label="Chọn đơn #<?php echo esc_attr($id); ?>"></label></td>
            <td class="thean-ous__c-order">
                <div class="thean-ous__order-head">
                    <strong><?php echo thean_ous_highlight('#' . $id, $tokens); ?></strong>
                    <span class="column-order_status"><?php echo thean_ous_status_badge($order); ?></span>
                </div>
                <small><?php echo thean_ous_highlight($order->get_order_number(), $tokens); ?> · <?php echo esc_html(thean_ous_short_date($order)); ?></small>
                <?php echo thean_ous_step_badge($order, $flow); ?>
                <div class="thean-ous__total">
                    <?php echo wp_kses_post(wc_price($order->get_total(), ['currency' => $order->get_currency()])); ?>
                    <small><?php echo esc_html($order->get_payment_method_title()); ?></small>
                </div>
            </td>
            <td class="thean-ous__c-cus">
                <strong><?php echo thean_ous_highlight(thean_ous_customer_name($order), $tokens); ?></strong>
                <small>
                    <?php echo thean_ous_highlight((string) $phone, $tokens); ?>
                    <?php if ($order->get_billing_email()) : ?> · <?php echo thean_ous_highlight($order->get_billing_email(), $tokens); ?><?php endif; ?>
                </small>
                <?php if ($address !== '') : ?><small>📍 <?php echo thean_ous_highlight($address, $tokens); ?></small><?php endif; ?>
                <?php if ($ship !== '' || $shipper !== '') : ?>
                    <small title="<?php echo esc_attr($ship_full); ?>">🚚 <?php echo thean_ous_highlight(trim($shipper . ' ' . $ship), $tokens); ?></small>
                <?php endif; ?>
            </td>
            <td class="thean-ous__c-items">
                <?php foreach ($order->get_items() as $item) : ?>
                    <div><?php echo thean_ous_highlight($item->get_name(), $tokens); ?> <b>×<?php echo esc_html($item->get_quantity()); ?></b></div>
                <?php endforeach; ?>
            </td>
            <td class="thean-ous__c-next">
                <?php if ($flow['hint'] !== '') : ?><div class="thean-ous__hint">👉 <?php echo esc_html($flow['hint']); ?></div><?php endif; ?>
                <?php echo thean_ous_next_actions($order, $flow['step'], !empty($flow['proof'])); ?>
            </td>
            <td class="thean-ous__c-acts"><?php echo thean_ous_render_actions($order, true); ?></td>
            <td class="thean-ous__c-mact">
                <?php // Mobile: mọi thao tác gom vào 1 nút → popup (Tiếp theo = bản sao cột "Thao tác tiếp theo", Tổng quan = template dưới). ?>
                <button type="button" class="thean-ous-btn thean-ous-btn--primary thean-ous__mact-btn" data-thean-ous-acts="<?php echo esc_attr($id); ?>">⚡ Thao tác<?php echo $flow['step'] !== '' ? ' <span class="thean-ous__mact-dot" aria-hidden="true"></span>' : ''; ?></button>
                <template data-thean-ous-acts-all><?php echo thean_ous_render_actions($order, false); ?></template>
            </td>
        </tr>
        <?php
    }
    return (string) ob_get_clean();
}

function thean_ous_render_address_block(WC_Order $order, string $type): string
{
    $address = $type === 'shipping' ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
    $phone   = $type === 'shipping' ? $order->get_shipping_phone() : $order->get_billing_phone();
    $html    = $address ? wp_kses_post($address) : '<em>—</em>';
    if ($phone) {
        $html .= '<br><a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
    }
    if ($type === 'billing' && $order->get_billing_email()) {
        $html .= '<br><a href="mailto:' . esc_attr($order->get_billing_email()) . '">' . esc_html($order->get_billing_email()) . '</a>';
    }
    return $html;
}

/**
 * URL ảnh xuất kho của đơn (meta warehouse_export_images, mỗi dòng 1 URL). Đọc meta đơn (HPOS) trước, trống thì
 * đọc postmeta — đơn cũ / luồng ghi thẳng update_post_meta chưa sync sang HPOS.
 */
function thean_ous_export_images(WC_Order $order): array
{
    $raw = (string) $order->get_meta('warehouse_export_images');
    if (trim($raw) === '') {
        $raw = (string) get_post_meta($order->get_id(), 'warehouse_export_images', true);
    }
    return array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $raw))))));
}

function thean_ous_render_preview(WC_Order $order): string
{
    $id   = $order->get_id();
    $date = $order->get_date_created();
    $ops  = array_filter([
        'Giao bởi'         => thean_ous_shipper($order),
        'Mã vận đơn'       => thean_ous_ship_code_short(thean_ous_ship_code($order)),
        'Ngày xuất kho'    => thean_ous_meta($order, ['order_ship_date', 'order_export_date', 'export_date']),
        'Ngày CK'          => thean_ous_meta($order, ['order_paid_date', 'paid_date']),
        'TK nhận'          => thean_ous_meta($order, ['order_bank_account_received', 'bank_account_received']),
        'Trạng thái xử lý' => thean_ous_meta($order, ['order_handling_status', 'handling_status']),
        'Hoàn hàng'        => thean_ous_meta($order, ['return_status']),
    ], static fn($v) => $v !== '');
    $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes(['order_id' => $id, 'limit' => 5]) : [];
    $export_images = thean_ous_export_images($order);

    ob_start();
    ?>
    <div class="thean-ous-preview__head">
        <h2>Đơn #<?php echo esc_html($id); ?> <small><?php echo esc_html($order->get_order_number()); ?></small></h2>
        <span class="column-order_status"><?php echo thean_ous_status_badge($order); ?></span>
        <span class="thean-ous-preview__meta">
            <?php echo $date ? esc_html($date->date_i18n('d/m/Y H:i')) : ''; ?>
            · <?php echo esc_html($order->get_payment_method_title() ?: '—'); ?>
            <?php if ($order->get_customer_id()) : ?>
                · <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $order->get_customer_id())); ?>" target="_blank" rel="noopener">Hồ sơ KH #<?php echo esc_html($order->get_customer_id()); ?></a>
            <?php endif; ?>
        </span>
    </div>

    <div class="thean-ous-preview__actions"><?php echo thean_ous_render_actions($order, false); ?></div>

    <div class="thean-ous-preview__grid">
        <div><h3>Thanh toán</h3><p><?php echo thean_ous_render_address_block($order, 'billing'); ?></p></div>
        <div><h3>Giao hàng</h3><p><?php echo thean_ous_render_address_block($order, 'shipping'); ?></p></div>
        <?php if ($ops) : ?>
            <div><h3>Vận hành</h3><dl>
                <?php foreach ($ops as $label => $value) : ?>
                    <dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html((string) $value); ?></dd>
                <?php endforeach; ?>
            </dl></div>
        <?php endif; ?>
    </div>

    <div class="thean-ous-preview__items-wrap">
    <table class="thean-ous-preview__items">
        <thead><tr><th>Sản phẩm</th><th>SKU</th><th>SL</th><th>Thành tiền</th></tr></thead>
        <tbody>
        <?php foreach ($order->get_items() as $item) :
            $product = $item->get_product();
            ?>
            <tr>
                <td><?php echo $product ? '<a href="' . esc_url(get_edit_post_link($product->get_parent_id() ?: $product->get_id())) . '" target="_blank" rel="noopener">' . esc_html($item->get_name()) . '</a>' : esc_html($item->get_name()); ?></td>
                <td><?php echo esc_html($product ? $product->get_sku() : ''); ?></td>
                <td><?php echo esc_html($item->get_quantity()); ?></td>
                <td><?php echo wp_kses_post(wc_price($item->get_total(), ['currency' => $order->get_currency()])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><th colspan="3">Phí ship</th><td><?php echo wp_kses_post(wc_price($order->get_shipping_total(), ['currency' => $order->get_currency()])); ?></td></tr>
            <?php if ((float) $order->get_discount_total() > 0) : ?>
                <tr><th colspan="3">Giảm giá</th><td>−<?php echo wp_kses_post(wc_price($order->get_discount_total(), ['currency' => $order->get_currency()])); ?></td></tr>
            <?php endif; ?>
            <tr><th colspan="3">Tổng</th><td><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></td></tr>
        </tfoot>
    </table>
    </div>

    <?php if ($export_images) : ?>
        <h3>📦 Ảnh xuất kho <small>(<?php echo count($export_images); ?>)</small></h3>
        <div class="thean-ous-preview__thumbs">
            <?php foreach ($export_images as $url) : ?>
                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($url); ?>" alt="Ảnh xuất kho" loading="lazy"></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($order->get_customer_note()) : ?>
        <div class="thean-ous-preview__note"><strong>Ghi chú của khách:</strong> <?php echo esc_html($order->get_customer_note()); ?></div>
    <?php endif; ?>

    <?php if ($notes) : ?>
        <h3>Ghi chú đơn gần nhất</h3>
        <ul class="thean-ous-preview__notes">
            <?php foreach ($notes as $note) : ?>
                <li class="<?php echo $note->customer_note ? 'is-customer' : ''; ?>">
                    <?php echo wp_kses_post(wpautop(wptexturize($note->content))); ?>
                    <small><?php echo esc_html($note->date_created ? $note->date_created->date_i18n('d/m/Y H:i') : ''); ?><?php echo $note->added_by ? ' · ' . esc_html($note->added_by) : ''; ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/**
 * HTML xem nhanh, cache trong object cache (Redis) THEAN_OUS_PREVIEW_TTL giây. Khóa gồm thời điểm sửa đơn +
 * version cache orders (đổi khi lưu đơn) + quyền / thiết bị (nút thao tác phụ thuộc 2 thứ này).
 * Ghi chú đơn thêm mà không lưu đơn có thể trễ tối đa TTL.
 */
function thean_ous_preview_html(WC_Order $order): string
{
    $modified = $order->get_date_modified();
    $key = 'pv_' . $order->get_id() . '_' . md5(implode('|', [
        'tpl2', // đổi khi sửa mẫu HTML xem nhanh (vd thêm khối ảnh xuất kho)
        thean_ous_orders_version(),
        $modified ? $modified->getTimestamp() : 0,
        current_user_can('manage_woocommerce') ? 1 : 0,
        current_user_can('edit_shop_orders') ? 1 : 0,
        wp_is_mobile() ? 1 : 0,
    ]));
    $html = wp_cache_get($key, 'thean_ous');
    if (is_string($html)) {
        return $html;
    }
    $html = thean_ous_render_preview($order);
    wp_cache_set($key, $html, 'thean_ous', THEAN_OUS_PREVIEW_TTL);
    return $html;
}

/** FROM … WHERE board: đơn có bước (≠ none) thuộc luồng vận hành; đơn đóng chỉ còn tab Hoàn hàng; bỏ đơn gộp Shopee + đơn nội bộ. */
function thean_ous_board_sql(string $range = 'auto'): string
{
    $t = thean_ops_step_tables();
    $excluded = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_excluded_statuses())) . "'";
    // Điều kiện cuối: đơn nội bộ (domain email / _eim_internal — đổi cài đặt có hiệu lực ngay), mốc khóa đơn cũ và khoảng ngày tạo đang chọn.
    return "FROM {$t['orders']} o
            JOIN {$t['meta']} s ON s.{$t['fk']} = o.{$t['id']} AND s.meta_key = '" . THEAN_OPS_STEP_META . "' AND s.meta_value <> 'none'
            WHERE o.{$t['type']} = 'shop_order'
              AND o.{$t['status']} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft')
              AND (o.{$t['status']} NOT IN ({$excluded}) OR s.meta_value IN ('return', 'proof'))
              AND (s.meta_value <> 'proof' OR " . thean_ous_proof_since_sql($t) . ")
              AND NOT EXISTS (SELECT 1 FROM {$t['meta']} x WHERE x.{$t['fk']} = o.{$t['id']} AND x.meta_key = '_spx_date' AND x.meta_value <> '')
              AND " . thean_ops_internal_sql($t) . "
              AND " . thean_ops_age_sql($t) . "
              AND " . thean_ous_range_sql($range, 'o', 's.meta_value');
}

/**
 * Điều kiện SQL chọn đơn của 1 tab: tab "Thiếu / chưa xác nhận ảnh xuất kho" theo cờ THEAN_OPS_PROOF_META (gồm cả đơn đang giao — bước
 * chính vẫn là Đang giao), các tab khác theo bước `_thean_ops_step` (alias s).
 */
function thean_ous_board_tab_sql(string $tab, string $range = 'auto'): string
{
    global $wpdb;
    if ($tab === 'proof') {
        $t = thean_ops_step_tables();
        return "EXISTS (SELECT 1 FROM {$t['meta']} pf WHERE pf.{$t['fk']} = o.{$t['id']} AND pf.meta_key = '" . THEAN_OPS_PROOF_META . "' AND pf.meta_value = '1')"
            . ' AND ' . thean_ous_proof_since_sql($t)
            . ' AND ' . thean_ous_range_sql($range === 'auto' ? '30' : $range);
    }
    $groups = thean_ops_step_groups(thean_ops_step_tab($tab));
    if ($groups) {
        return 's.meta_value IN (' . implode(',', array_map(static fn($step) => $wpdb->prepare('%s', $step), array_keys($groups))) . ')';
    }
    return $wpdb->prepare('s.meta_value = %s', $tab);
}

/**
 * Tab "Thiếu / chưa xác nhận ảnh xuất kho" chỉ xét đơn tạo trong THEAN_OPS_PROOF_DAYS ngày (thean_ops_missing_proof). Cờ lưu sẵn không tự
 * hết hạn khi đơn quá 60 ngày → lọc thẳng bằng SQL để số đếm khớp danh sách.
 */
function thean_ous_proof_since_sql(array $t): string
{
    return "o.{$t['date']} >= '" . gmdate('Y-m-d H:i:s', time() - THEAN_OPS_PROOF_DAYS * DAY_IN_SECONDS) . "'";
}

/** Đơn (kết quả thean_ops_step_compute) có thuộc tab không — cùng quy tắc thean_ous_board_tab_sql. */
function thean_ous_board_in_tab(array $res, string $tab): bool
{
    return $tab === 'proof' ? !empty($res['proof']) : thean_ops_step_tab((string) ($res['step'] ?? '')) === thean_ops_step_tab($tab);
}

/** Cache dữ liệu dùng chung, khóa gồm cấu hình lọc và revision; HTML luôn render theo user hiện tại.
 * Làm tròn giây trong mốc tuổi chỉ cho khóa cache: SQL vẫn dùng mốc chính xác, TTL tối đa 10s.
 */
function thean_ous_board_cache_key(string $part, string $range = 'auto'): string
{
    $scope = preg_replace("/(:[0-9]{2})'/", ":00'", thean_ous_board_sql($range));
    return 'thean_ous_board_' . md5(wp_json_encode([
        $part, thean_ous_range($range), $scope, THEAN_OPS_STEP_VERSION, thean_ous_orders_version(),
        get_option('thean_ops_board_revision', '0'),
    ]));
}

/** Server-Timing hiển thị trong DevTools, không chứa dữ liệu khách hàng. */
function thean_ous_timing_header(array $timings): void
{
    if (!headers_sent()) {
        $parts = [];
        foreach ($timings as $name => $ms) {
            $parts[] = $name . ';dur=' . number_format(max(0, $ms), 1, '.', '');
        }
        header('Server-Timing: ' . implode(', ', $parts));
    }
}

/** Số đơn mỗi tab (GROUP BY theo bước + đếm riêng tab "Thiếu / chưa xác nhận ảnh xuất kho" theo cờ). */
function thean_ous_board_stats(string $range = 'auto'): array
{
    global $wpdb;
    $key = thean_ous_board_cache_key('counts', $range);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $rows   = $wpdb->get_results('SELECT s.meta_value AS tab, COUNT(DISTINCT o.' . thean_ops_step_tables()['id'] . ') AS n ' . thean_ous_board_sql($range) . ' GROUP BY s.meta_value', ARRAY_A);
    $counts = array_fill_keys(array_keys(thean_ops_step_tabs()), 0);
    $steps = [];
    foreach ((array) $rows as $row) {
        $steps[$row['tab']] = (int) $row['n'];
        $tab = thean_ops_step_tab($row['tab']);
        if (isset($counts[$tab])) {
            $counts[$tab] += (int) $row['n'];
        }
    }
    if (isset($counts['proof'])) {
        $counts['proof'] = (int) $wpdb->get_var('SELECT COUNT(DISTINCT o.' . thean_ops_step_tables()['id'] . ') ' . thean_ous_board_sql($range) . ' AND ' . thean_ous_board_tab_sql('proof', $range));
    }
    $stats = ['counts' => $counts, 'steps' => $steps];
    set_transient($key, $stats, THEAN_OUS_BOARD_CACHE_TTL);
    return $stats;
}

function thean_ous_board_counts(string $range = 'auto'): array
{
    return thean_ous_board_stats($range)['counts'];
}

/** Labels + group totals share the same aggregate query/cache as tab counts. */
function thean_ous_board_tabs(string $range = 'auto'): array
{
    $stats = thean_ous_board_stats($range);
    $tabs = [];
    foreach (thean_ops_step_tabs() as $key => $label) {
        $groups = [];
        foreach (thean_ops_step_groups($key) as $step => $group_label) {
            $groups[] = ['key' => $step, 'label' => $group_label, 'count' => $stats['steps'][$step] ?? 0];
        }
        $tabs[] = ['key' => $key, 'label' => $label, 'count' => $stats['counts'][$key] ?? 0, 'groups' => $groups] + thean_ops_step_position($key);
    }
    // Hiển thị theo thứ tự vận hành: luồng chính trước, ngoại lệ sau (thean_ops_step_flow()).
    $order = array_flip(array_merge(...array_values(thean_ops_step_flow())));
    usort($tabs, static fn($a, $b) => ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99));
    return $tabs;
}

/** ID đơn trong tab từ vị trí $offset, tối đa $limit đơn, mới nhất trước. */
function thean_ous_board_page_ids(string $tab, int $offset, int $limit, string $range = 'auto', int $before_id = 0): array
{
    global $wpdb;
    $key = $offset === 0 && $before_id === 0 ? thean_ous_board_cache_key('ids:' . $tab . ':' . $limit, $range) : '';
    $cached = $key ? get_transient($key) : false;
    if (is_array($cached)) {
        return $cached;
    }
    $id = thean_ops_step_tables()['id'];
    $cursor = $before_id > 0 ? " AND o.{$id} < " . (int) $before_id : '';
    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT o.{$id} " . thean_ous_board_sql($range) . ' AND ' . thean_ous_board_tab_sql($tab, $range) . $cursor . " ORDER BY o.{$id} DESC LIMIT %d OFFSET %d",
        $limit, $before_id > 0 ? 0 : $offset
    )));
    if ($key) {
        set_transient($key, $ids, THEAN_OUS_BOARD_CACHE_TTL);
    }
    return $ids;
}

add_action('wp_ajax_thean_order_usearch_board', 'thean_ous_ajax_board');
function thean_ous_ajax_board(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $out = thean_ous_board_payload(wp_unslash($_POST));
    thean_ous_timing_header($out['timings']);
    wp_send_json_success($out['data']);
}

/**
 * Dữ liệu board (dùng chung AJAX thean_order_usearch_board + trang /quan-ly-don in sẵn trang đầu).
 * $req = tham số đã unslash: range, tab, refresh, all, before_id, offset, limit. Trả ['data' => …, 'timings' => ms].
 */
function thean_ous_board_payload(array $req): array
{
    $range = thean_ous_range((string) ($req['range'] ?? 'auto'));
    $started = microtime(true);
    $pending = thean_ops_step_request_backfill();
    // Làm mới chủ động phải bỏ cache server, kể cả khi không có đơn đổi bước.
    if (!empty($req['refresh'])) {
        thean_ops_board_invalidate();
    }

    $labels = thean_ops_step_tabs();
    $before_id = absint($req['before_id'] ?? 0);
    $offset = absint($req['offset'] ?? 0); // "Xem thêm": số đơn đang hiện của tab
    $limit  = min(50, max(1, absint($req['limit'] ?? ($offset === 0 ? THEAN_OUS_BOARD_FIRST : THEAN_OUS_BOARD_MORE))));
    $tab    = thean_ops_step_tab(sanitize_key((string) ($req['tab'] ?? '')));
    $counts = thean_ous_board_counts($range);
    if (!isset($labels[$tab])) {
        $tab = (string) (array_key_first(array_filter($counts)) ?? array_key_first($labels));
    }

    // Giữ all=1 cho client cũ; client mới chỉ yêu cầu tab đang xem.
    $all  = !empty($req['all']);
    $load = $all ? array_keys(array_filter($counts)) : [];
    if (!in_array($tab, $load, true)) {
        $load[] = $tab;
    }
    $ids_by = [];
    foreach ($load as $t) {
        $ids_by[$t] = $all
            ? thean_ous_board_page_ids($t, 0, THEAN_OUS_BOARD_FIRST, $range)
            : thean_ous_board_page_ids($t, $offset, $limit, $range, $before_id);
    }

    // Tính lại tại chỗ các đơn sẽ hiện (đọc gộp 1 lần): lệch thì ghi lại, đơn đã sang bước khác thì không hiện ở tab đó.
    $queried = microtime(true);
    $orders = [];
    $fresh = thean_ops_step_sync_ids(array_merge([], ...array_values($ids_by)), $orders);
    // Có đơn vừa đổi tab (meta cũ) → lấy lại danh sách: đơn chuyển SANG tab khác không bị kẹt ngoài trang đầu của tab đó
    // ("Xem thêm" tính tiếp theo offset nên sẽ nhảy qua nó).
    $moved = false;
    foreach ($ids_by as $t => $ids) {
        foreach ($ids as $id) {
            $moved = $moved || !isset($fresh[$id]) || !thean_ous_board_in_tab($fresh[$id], $t);
        }
    }
    if ($moved) {
        thean_ops_board_invalidate();
        $counts = thean_ous_board_counts($range);
        $load   = $all ? array_keys(array_filter($counts)) : [];
        if (!in_array($tab, $load, true)) {
            $load[] = $tab;
        }
        $ids_by = [];
        foreach ($load as $t) {
            $ids_by[$t] = $all ? thean_ous_board_page_ids($t, 0, THEAN_OUS_BOARD_FIRST, $range) : thean_ous_board_page_ids($t, $offset, $limit, $range, $before_id);
        }
        $new = array_diff(array_merge([], ...array_values($ids_by)), array_keys($fresh));
        $new_orders = [];
        $fresh += thean_ops_step_sync_ids(array_values($new), $new_orders);
        $orders += $new_orders;
    }
    $show_by = [];
    $hints   = [];
    $recount = false;
    foreach ($ids_by as $t => $ids) {
        $show_by[$t] = [];
        foreach ($ids as $id) {
            if (isset($fresh[$id]) && thean_ous_board_in_tab($fresh[$id], $t)) {
                $show_by[$t][] = $id;
                $hints[$id]    = $fresh[$id]['hint'];
            } else {
                $recount = true;
            }
        }
    }
    if ($recount) {
        $counts = thean_ous_board_counts($range);
    }

    $synced = microtime(true);
    $panes = [];
    foreach ($show_by as $t => $show) {
        $total = $counts[$t] ?? 0;
        // Đơn vừa rời tab (tính lại lệch) không còn trong truy vấn → vị trí kế tiếp = số đơn đã hiện.
        $shown = min($total, ($all ? 0 : $offset) + count($show));
        $panes[$t] = [
            'tab'      => $t,
            'range_label' => thean_ous_range_label($range, $t),
            'rows'     => thean_ous_render_rows($show, [], array_intersect_key($hints, array_flip($show)), array_map(static fn($id) => $orders[$id], $show), $fresh),
            'total'    => $total,
            'shown'    => $shown,
            'has_more' => $shown < $total && !empty($ids_by[$t]),
            'next_cursor' => $ids_by[$t] ? (int) end($ids_by[$t]) : 0,
            'exhausted' => count($ids_by[$t]) < ($all ? THEAN_OUS_BOARD_FIRST : $limit),
        ];
    }
    $tabs = thean_ous_board_tabs($range);
    return [
        'data' => $panes[$tab] + [
            'tabs'    => $tabs,
            'panes'   => $all ? $panes : null,
            'pending' => $pending,
            'at'      => wp_date('H:i:s'),
            'ver'     => thean_ous_build_ver(),
        ],
        'timings' => [
            'board_query' => ($queried - $started) * 1000,
            'board_sync' => ($synced - $queried) * 1000,
            'board_render' => (microtime(true) - $synced) * 1000,
            'board_total' => (microtime(true) - $started) * 1000,
        ],
    ];
}

/** Membership của đúng các đơn vừa đổi, kể cả tab chứng từ chồng lên bước chính. */
function thean_ous_board_memberships(array $ids, string $range): array
{
    global $wpdb;
    $ids = thean_ous_int_ids($ids);
    if (!$ids) {
        return [];
    }
    $t = thean_ops_step_tables();
    $rows = $wpdb->get_results("SELECT DISTINCT o.{$t['id']} AS order_id, s.meta_value AS step, ("
        . thean_ous_board_tab_sql('proof', $range) . ') AS proof ' . thean_ous_board_sql($range)
        . " AND o.{$t['id']} IN (" . implode(',', $ids) . ')', ARRAY_A);
    $out = array_fill_keys($ids, []);
    $labels = thean_ops_step_tabs();
    foreach ((array) $rows as $row) {
        $id = (int) $row['order_id'];
        if (!isset($out[$id])) {
            continue;
        }
        $tab = thean_ops_step_tab($row['step']);
        if (isset($labels[$tab]) && $tab !== 'proof') {
            $out[$id][] = $tab;
        }
        if (!empty($row['proof'])) {
            $out[$id][] = 'proof';
        }
    }
    return $out;
}

add_action('wp_ajax_thean_order_usearch_rows', 'thean_ous_ajax_rows');
function thean_ous_ajax_rows(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $ids = array_values(array_filter(thean_ous_int_ids(explode(',', (string) wp_unslash($_POST['ids'] ?? ''))), static fn($id) => $id > 0));
    if (!$ids || count($ids) > THEAN_OUS_BULK_MAX) {
        wp_send_json_error(['message' => 'Chọn từ 1 đến 50 đơn để cập nhật.'], 400);
    }
    $range = thean_ous_range((string) wp_unslash($_POST['range'] ?? 'auto'));
    $query = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
    $filters = thean_ous_parse_query($query);
    // Bộ lọc tìm sai không được chặn cập nhật board của một đơn đã lưu thành công.
    $valid_query = $query !== '' && thean_ous_validate($filters) === '';
    $started = microtime(true);
    $orders = [];
    $flows = thean_ops_step_sync_ids($ids, $orders);
    // Mutation request đã hoàn tất cả shutdown trước khi UI gọi endpoint này.
    thean_ops_board_invalidate();
    $memberships = thean_ous_board_memberships($ids, $range);
    $matches = $valid_query ? thean_ous_find_order_ids($filters, count($ids), $range, $ids) : [];
    $patches = [];
    foreach ($ids as $id) {
        $order = $orders[$id] ?? null;
        $match = $order && in_array($id, $matches, true);
        $patches[] = [
            'id' => $id,
            'tabs' => $memberships[$id] ?? [],
            'rows' => $order ? thean_ous_render_rows([$id], [], [], [$order], $flows) : '',
            'search_match' => (bool) $match,
            'search_rows' => $match ? thean_ous_render_rows([$id], thean_ous_highlight_tokens($filters), [], [$order], $flows) : '',
        ];
    }
    $tabs = thean_ous_board_tabs($range);
    thean_ous_timing_header(['row_update' => (microtime(true) - $started) * 1000]);
    wp_send_json_success(['orders' => $patches, 'tabs' => $tabs, 'range' => $range, 'q' => $query, 'at' => wp_date('H:i:s'), 'ver' => thean_ous_build_ver()]);
}

/** Đổi trạng thái 1 đơn (thao tác hàng loạt). Trả [thành công?, thông báo]. */
function thean_ous_bulk_status(WC_Order $order, string $status, string $note): array
{
    if ($status === '' || !wc_is_order_status('wc-' . $status)) {
        return [false, 'Trạng thái không hợp lệ.'];
    }
    if (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order)) {
        return [false, 'Đơn đã bị khóa đổi trạng thái (chỉ admin).'];
    }
    if ($order->get_status() === $status) {
        return [false, 'Đơn đã ở trạng thái ' . wc_get_order_status_name($status) . '.'];
    }
    $order->update_status($status, '🔁 Đổi trạng thái hàng loạt (ô tìm đơn)' . ($note !== '' ? ': ' . $note : '') . '.', true);
    return [true, 'Đã chuyển sang: ' . wc_get_order_status_name($status)];
}

add_action('wp_ajax_thean_order_usearch_bulk', 'thean_ous_ajax_bulk');
function thean_ous_ajax_bulk(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $op     = sanitize_key(wp_unslash($_POST['op'] ?? ''));
    $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
    $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
    $ids    = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) wp_unslash($_POST['ids'] ?? ''))))));
    if (!$ids) {
        wp_send_json_error(['message' => 'Chưa chọn đơn nào.']);
    }
    if (count($ids) > THEAN_OUS_BULK_MAX) {
        wp_send_json_error(['message' => sprintf('Mỗi lần tối đa %d đơn.', THEAN_OUS_BULK_MAX)]);
    }
    $flows = ['hold', 'resume', 'cancel', 'preorder'];
    if (!in_array($op, array_merge($flows, ['status', 'skipproof']), true)) {
        wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
    }
    if ($op === 'status' && !(function_exists('thean_can_change_any_status') && thean_can_change_any_status())) {
        wp_send_json_error(['message' => 'Chỉ người được chỉ định (Cài đặt ERP → Xử lý đơn) mới đổi trạng thái tùy ý.'], 403);
    }
    if ($op === 'skipproof' && !(function_exists('thean_can_skip_export_proof') && thean_can_skip_export_proof())) {
        wp_send_json_error(['message' => 'Chỉ admin được bỏ qua lỗi thiếu chứng từ xuất kho.'], 403);
    }
    if ($op !== 'status' && !function_exists('thean_ow_apply_flow')) {
        require_once __DIR__ . '/order-workspace.php';
    }

    $done   = [];
    $failed = [];
    $orders = thean_ous_load_orders($ids);
    foreach ($orders as $order) {
        if ($op === 'status') {
            [$ok, $message] = thean_ous_bulk_status($order, $status, $reason);
        } elseif ($op === 'skipproof') {
            [$ok, $message] = thean_ow_skip_export_proof($order, $reason);
        } else {
            [$ok, $message] = thean_ow_apply_flow($order, $op, $reason);
        }
        if ($ok) {
            $done[] = $order->get_id();
        } else {
            $failed[] = ['id' => $order->get_id(), 'message' => $message];
        }
    }
    foreach (array_diff($ids, array_map(static fn($o) => $o->get_id(), $orders)) as $missing) {
        $failed[] = ['id' => $missing, 'message' => 'Không tìm thấy đơn.'];
    }
    wp_send_json_success([
        'done'    => $done,
        'failed'  => $failed,
        'message' => sprintf('Xong %d/%d đơn.', count($done), count($ids)),
    ]);
}

add_action('wp_ajax_thean_order_usearch', 'thean_ous_ajax_search');
function thean_ous_ajax_search(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }

    $range = thean_ous_range((string) wp_unslash($_POST['range'] ?? 'auto'));
    $started = microtime(true);
    $query   = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
    $page    = max(1, absint($_POST['page'] ?? 1));
    $filters = thean_ous_parse_query($query);
    $error   = thean_ous_validate($filters);
    if ($error !== '') {
        wp_send_json_error(['message' => $error]);
    }

    // Đang bị khóa thì chặn mọi lượt tìm mới (trang 1); "Xem thêm" vẫn đọc từ cache.
    $user_id = get_current_user_id();
    $wait    = $page === 1 ? thean_ous_rate_blocked($user_id) : 0;
    $key     = thean_ous_ids_cache_key($filters, $range);
    $ids     = $wait ? false : get_transient($key);
    if (!is_array($ids)) {
        // Chỉ lượt tìm thật (không trúng cache) mới tính vào giới hạn.
        $wait = $wait ?: thean_ous_rate_hit($user_id);
        if ($wait) {
            wp_send_json_error([
                'message'     => sprintf('Tìm quá nhanh (quá %d lượt trong %d giây). Tìm kiếm tạm khóa, thử lại sau %d giây.', THEAN_OUS_RL_MAX, THEAN_OUS_RL_WINDOW, $wait),
                'retry_after' => $wait,
            ], 429);
        }
        $ids = thean_ous_find_order_ids($filters, THEAN_OUS_CAP, $range);
        set_transient($key, $ids, THEAN_OUS_CACHE_TTL);
    }
    $queried = microtime(true);
    $total = count($ids);
    $before_id = absint($_POST['before_id'] ?? 0);
    $available = $before_id > 0 ? array_values(array_filter($ids, static fn($id) => $id < $before_id)) : $ids;
    $slice = array_slice($available, $before_id > 0 ? 0 : ($page - 1) * THEAN_OUS_PER_PAGE, THEAN_OUS_PER_PAGE);

    $rows = thean_ous_render_rows($slice, thean_ous_highlight_tokens($filters));
    thean_ous_timing_header([
        'search_query' => ($queried - $started) * 1000,
        'search_render' => (microtime(true) - $queried) * 1000,
        'search_total' => (microtime(true) - $started) * 1000,
    ]);
    wp_send_json_success([
        'ver'      => thean_ous_build_ver(),
        'rows'     => $rows,
        'total'    => $total,
        'capped'   => $total >= THEAN_OUS_CAP,
        'shown'    => min($total, ($before_id > 0 ? absint($_POST['shown'] ?? 0) : ($page - 1) * THEAN_OUS_PER_PAGE) + count($slice)),
        'next_cursor' => $slice ? (int) end($slice) : 0,
        'exhausted' => count($slice) < THEAN_OUS_PER_PAGE,
        'page'     => $page,
        'has_more' => $page * THEAN_OUS_PER_PAGE < $total,
        'summary'  => array_merge([thean_ous_range_label($range) . ($range === 'auto' ? ' · ID/mã đơn: toàn bộ' : '')], thean_ous_filter_summary($filters)),
    ]);
}

add_action('wp_ajax_thean_order_usearch_preview', 'thean_ous_ajax_preview');
function thean_ous_ajax_preview(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }

    $order = wc_get_order(absint($_POST['order_id'] ?? 0));
    if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') {
        wp_send_json_error(['message' => 'Không tìm thấy đơn.']);
    }

    wp_send_json_success(['html' => thean_ous_preview_html($order)]);
}

/**
 * Nút 🧹 Xoá cache (thanh tìm): lượt đầu (offset 0) đổi version cache 'orders' của WC → bỏ mọi danh sách ID khớp + HTML
 * xem nhanh đã cache; mỗi lượt tính lại bước THEAN_OUS_FLUSH_BATCH đơn mở mới nhất (thean_ops_step_sync_ids bỏ OrderCache
 * trước khi đọc). Client gọi lặp theo `done` tới khi `finished`.
 */
add_action('wp_ajax_thean_order_usearch_flush', 'thean_ous_ajax_flush');
function thean_ous_ajax_flush(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    global $wpdb;
    $offset = absint($_POST['offset'] ?? 0);
    if ($offset === 0) {
        thean_ops_board_invalidate();
    }
    if ($offset === 0 && class_exists('WC_Cache_Helper')) {
        WC_Cache_Helper::get_transient_version('orders', true);
    }
    $t     = thean_ops_step_tables();
    $base  = thean_ops_step_open_sql($t);
    $total = min(THEAN_OUS_FLUSH_MAX, (int) $wpdb->get_var("SELECT COUNT(*) {$base}"));
    $ids   = $offset < $total
        ? array_map('intval', $wpdb->get_col("SELECT o.{$t['id']} {$base} ORDER BY o.{$t['id']} DESC LIMIT " . THEAN_OUS_FLUSH_BATCH . ' OFFSET ' . $offset))
        : [];
    $ids   = array_slice($ids, 0, max(0, $total - $offset));
    thean_ops_step_sync_ids($ids);
    $done = $offset + count($ids);
    wp_send_json_success(['total' => $total, 'done' => $done, 'finished' => !$ids || $done >= $total]);
}

add_action('wp_ajax_thean_order_usearch_alerts', 'thean_ous_ajax_alerts');
function thean_ous_ajax_alerts(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $off = !empty($_POST['off']);
    // what=chrome: ô "Ẩn thanh admin + menu trái"; mặc định: ô "Ẩn thông báo".
    $meta = sanitize_key(wp_unslash($_POST['what'] ?? '')) === 'chrome' ? THEAN_OUS_CHROME_META : THEAN_OUS_ALERTS_META;
    update_user_meta(get_current_user_id(), $meta, $off ? '1' : '0');
    wp_send_json_success(['off' => $off]);
}

/** Đang ở màn danh sách đơn (không phải màn sửa/tạo đơn của HPOS) và có quyền. */
function thean_ous_is_list_ui(): bool
{
    if (!function_exists('hithean_order_admin_is_list_screen') || !hithean_order_admin_is_list_screen() || !thean_ous_user_can()) {
        return false;
    }
    // HPOS dùng chung screen id cho danh sách và màn sửa đơn.
    return !in_array($_GET['action'] ?? '', ['edit', 'new'], true);
}

function thean_ous_alerts_off(): bool
{
    // Mặc định ẩn cho mọi user; chỉ hiện lại khi user bỏ tích (lưu '0').
    return get_user_meta(get_current_user_id(), THEAN_OUS_ALERTS_META, true) !== '0';
}

function thean_ous_chrome_off(): bool
{
    // Mặc định ẩn thanh admin + menu trái ở danh sách đơn; chỉ hiện lại khi user bỏ tích (lưu '0').
    return get_user_meta(get_current_user_id(), THEAN_OUS_CHROME_META, true) !== '0';
}

// Class gắn sẵn ở <body>; CSS ẩn nằm trong assets/order-universal-search.css (nạp ở <head>) nên không chớp.
add_filter('admin_body_class', function ($classes) {
    if (!thean_ous_is_list_ui()) {
        return $classes;
    }
    return $classes . (thean_ous_alerts_off() ? ' thean-ous-alerts-off' : '') . (thean_ous_chrome_off() ? ' thean-ous-chrome-off' : '');
});

/**
 * Phiên bản giao diện bảng = mtime JS + PHP render dòng. Tab admin mở từ trước khi deploy vẫn chạy JS cũ
 * (tiêu đề/colgroup cũ) nhưng AJAX trả dòng theo PHP mới → lệch cột. JS so 'ver' trong phản hồi với
 * cfg.ver, khác thì nhắc tải lại trang.
 */
function thean_ous_build_ver(): string
{
    static $ver = null;
    if ($ver === null) {
        $ver = (string) @filemtime(__DIR__ . '/assets/order-universal-search.js') . '.' . (string) @filemtime(__FILE__);
    }
    return $ver;
}

/** Config cho assets/order-universal-search.js (window.theanOusCfg). */
function thean_ous_ui_config(): array
{
    $commands = [];
    foreach (thean_ous_commands() as $key => [$label, $example, $desc]) {
        $commands[] = ['cmd' => $key, 'label' => $label, 'example' => $example, 'desc' => $desc];
    }
    return [
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('thean_ous'),
        'ver'            => thean_ous_build_ver(),
        'perPage'        => THEAN_OUS_PER_PAGE,
        'cap'            => THEAN_OUS_CAP,
        'commands'       => $commands,
        'aliases'        => thean_ous_command_aliases(),
        'statuses'       => thean_ous_statuses(),
        'alertsOff'      => thean_ous_alerts_off(),
        'chromeOff'      => thean_ous_chrome_off(),
        // Modal thao tác nhanh của orders-admin.php — Esc nhường chúng đóng trước.
        'canChangeStatus' => function_exists('thean_can_change_any_status') && thean_can_change_any_status(), // order-ops-step.php
        'canSkipProof'   => function_exists('thean_can_skip_export_proof') && thean_can_skip_export_proof(),
        'standalone'     => function_exists('thean_order_portal_is_route') && thean_order_portal_is_route(),
        'wcList'         => (function_exists('thean_order_portal_is_route') && thean_order_portal_is_route()) ? null : (function_exists('thean_wcl_state') ? thean_wcl_state() : null), // order-list-lazy.php
        // Tự tải board "Tải đơn cần xử lý" ngay khi mở danh sách đơn (theanmarket chưa bật — JS mặc định tắt).
        'boardAutoload'  => true,
        'blockingModals' => '.thean-quick-modal:not([hidden]), .hithean-return-modal:not([hidden]), #thean-flow-modal:not([hidden]), #thean-vat-modal:not([hidden]), #thean-export-review-modal:not([hidden])',
    ];
}

// CSS/JS là file tĩnh (trình duyệt cache, version = filemtime). File này được nạp trong
// admin_enqueue_scripts priority 10 nên đăng ký priority 20 để chạy ngay trong lượt đó.
/** Hướng dẫn tại chỗ, chỉ đọc; nhãn lấy cùng nguồn với các tab board. */
function thean_ous_render_workflow_guide(): void
{
    $labels = thean_ops_step_tabs();
    $instructions = [
        'confirm' => 'Đơn chờ xác nhận: gọi hoặc nhắn khách, ghi nhận đã đồng ý / chờ phản hồi / cần hủy. Đơn chờ thanh toán trước: kiểm tra tiền thực nhận, sau đó xác nhận thanh toán. Hai nhóm có thao tác riêng; khách đồng ý không có nghĩa đã thanh toán.',
        'picking' => 'Soạn đúng sản phẩm và số lượng, in phiếu, chụp rồi tải ảnh xuất kho. Đơn giao nhanh chưa có ảnh và chưa bàn giao cũng ở đây. Tải ảnh xong chuyển sang bước kiểm tra ảnh.',
        'export' => 'Kiểm tra ảnh, mã đơn, sản phẩm và số lượng rồi xác nhận xuất kho. AI chỉ hỗ trợ đọc ảnh; AI báo khớp và việc tải ảnh không thay cho người xác nhận.',
        'delivery' => 'Đã có ảnh và xác nhận xuất kho: chọn đơn vị vận chuyển, nhập mã vận đơn và ghi nhận bàn giao. Khách tự lấy thì bàn giao khi khách đến.',
        'shipping' => 'Theo dõi giao hàng; chỉ chuyển Đã giao khi có căn cứ khách đã nhận. Theo dõi đơn giao một phần và xử lý yêu cầu hoàn nếu có.',
        'cod' => 'Chờ thu COD giao nhanh: đơn giao nhanh đã xác nhận xuất kho nhưng chưa xác nhận thu tiền. Chờ đối soát đơn vị VC: đơn COD đã giao / giao một phần, chờ xác nhận khoản tiền bên vận chuyển chuyển về. Chỉ xác nhận khi thực nhận tiền; xuất kho không đồng nghĩa đã giao thành công hoặc đã thu COD.',
        'proof' => 'Danh sách ngoại lệ: đơn đã giao đi (đang giao, đã giao, giao một phần, hoàn thành…) còn thiếu ảnh hoặc thiếu người xác nhận xuất kho. Bổ sung ảnh / kiểm tra và xác nhận. Một đơn có thể đồng thời ở tab theo dõi giao, COD hoặc hoàn hàng. Đơn giao nhanh chỉ mang nhãn Giao nhanh chưa được coi là đã bàn giao.',
        'return' => 'Theo dõi yêu cầu hoàn, tiếp nhận hàng thực tế, kiểm tra ảnh và số lượng rồi ghi nhận kết quả theo quyền được cấp.',
        'preorder' => 'Giữ đơn đến lịch hẹn; khi tiếp tục, liên hệ khách xác nhận giao lại trước khi xử lý các bước tiếp theo.',
    ];
    ?>
    <div class="thean-ous-modal thean-ous-modal--guide" id="thean-ous-workflow-guide" hidden>
        <div class="thean-ous-modal__backdrop" data-thean-ous-close></div>
        <div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="thean-ous-workflow-title" tabindex="-1">
            <button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng hướng dẫn">&times;</button>
            <h2 class="thean-ous-modal__title" id="thean-ous-workflow-title">Quy trình xử lý đơn</h2>
            <div data-workflow-guide-scope="orders">
            <p>Mở tab có việc cần làm, xử lý bằng nút trên từng đơn. Khi lưu thành công, dòng đơn và số đếm tự cập nhật sang bước phù hợp.</p>
            <?php
            $flow = thean_ops_step_flow();
            // Bước rẽ nhánh: ghi rõ thay vì "bước kế" theo vị trí.
            $next_text = [
                'export'   => '4. ' . $labels['delivery'] . ' — đơn giao nhanh COD: 6. ' . $labels['cod'],
                'shipping' => '6. ' . $labels['cod'] . ' (đơn COD) — đơn đã thanh toán trước: Hoàn thành',
            ];
            ?>
            <div class="thean-ous-flowmap" aria-label="Thứ tự vận hành">
                <p class="thean-ous-flowmap__lane">Luồng chính — đơn đi lần lượt (bấm để mở tab)</p>
                <ol class="thean-ous-flowmap__main">
                    <?php foreach ($flow['main'] as $i => $key) : ?>
                        <li><button type="button" class="thean-ous-flowmap__step" data-thean-ous-guide-tab="<?php echo esc_attr($key); ?>"><b><?php echo $i + 1; ?></b><?php echo esc_html($labels[$key]); ?></button></li>
                    <?php endforeach; ?>
                    <li class="thean-ous-flowmap__end">✅ Hoàn thành</li>
                </ol>
                <p class="thean-ous-flowmap__lane">Ngoại lệ / song song — không phải bước kế tiếp, kiểm tra thêm mỗi ngày</p>
                <ul class="thean-ous-flowmap__side">
                    <?php foreach ($flow['side'] as $key) : ?>
                        <li><button type="button" class="thean-ous-flowmap__step thean-ous-flowmap__step--side" data-thean-ous-guide-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($labels[$key]); ?></button></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php foreach ($flow as $lane => $keys) : ?>
                <h3 class="thean-ous-guide__lane"><?php echo $lane === 'main' ? 'Luồng chính' : 'Ngoại lệ / song song'; ?></h3>
                <ol class="thean-ous-guide<?php echo $lane === 'side' ? ' thean-ous-guide--side' : ''; ?>">
                    <?php foreach ($keys as $i => $key) : ?>
                        <li><h3><?php echo esc_html($labels[$key]); ?></h3><p><?php echo esc_html($instructions[$key] ?? ''); ?></p>
                            <?php if ($lane === 'main') : ?><p class="thean-ous-guide__next">→ Xong chuyển sang: <?php echo esc_html($next_text[$key] ?? (isset($keys[$i + 1]) ? ($i + 2) . '. ' . $labels[$keys[$i + 1]] : 'Hoàn thành')); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endforeach; ?>
            <p class="thean-ous-guide__note">Bộ lọc Ngày tạo áp dụng cho mọi tab. Mỗi lần chỉ tải một phần danh sách; số “đang hiện / tổng” của từng nhóm cho biết còn đơn cần bấm Xem thêm. Tab ngoại lệ ảnh giữ giới hạn <?php echo (int) THEAN_OPS_PROOF_DAYS; ?> ngày và bộ lọc ngày đang chọn. Nếu vừa cập nhật quy trình, hệ thống sẽ phân bước lại ở nền; bấm Làm mới sau khi xử lý xong.</p>
            </div>
            <div data-workflow-guide-scope="shopee" hidden>
                <p>Xử lý kiện Shopee theo thứ tự: tải ảnh → kiểm tra → xác nhận xuất kho → bàn giao → đối soát.</p>
                <ol class="thean-ous-guide">
                    <li><h3>1. Tải ảnh kiện</h3><p>Mở mục tải ảnh, chọn ảnh tem vận đơn đặt cạnh đủ sản phẩm. Nhập mã đơn nếu cần; AI hỗ trợ đọc tem và nhận diện sản phẩm.</p></li>
                    <li><h3>2. Kiểm tra kiện chờ xác nhận</h3><p>Đối chiếu mã đơn, mã vận đơn, sản phẩm và số lượng trên ảnh. Sửa thông tin sai trước khi xác nhận. AI báo khớp vẫn cần người kiểm tra.</p></li>
                    <li><h3>3. Xác nhận xuất kho</h3><p>Chỉ xác nhận khi ảnh và hàng thực tế đúng. Xác nhận xuất kho có tác động tồn kho; không bấm lặp lại khi kết nối chậm, hãy kiểm tra trạng thái kiện trước.</p></li>
                    <li><h3>4. Bàn giao và đối soát</h3><p>Dùng khu Bàn giao SPX để quét hoặc nhập mã vận đơn khi vận chuyển nhận kiện. Kiểm tra kết quả từng mã. Mở trang đầy đủ để xem lịch sử và các chức năng đối soát.</p></li>
                </ol>
                <p class="thean-ous-guide__note">Xác nhận xuất kho và bàn giao vận chuyển là hai việc riêng. Nếu tải nhầm ảnh, sai mã hoặc kiện bị hủy, kiểm tra chi tiết và dùng thao tác phù hợp trên kiện trước khi tiếp tục.</p>
            </div>
            <div data-workflow-guide-scope="exports" hidden>
                <p>Ưu tiên duyệt ảnh và xử lý đơn đã có ảnh trước khi tải ảnh mới. Mỗi tab dùng công cụ xuất kho hiện có, giữ nguyên quyền và bước xác nhận.</p>
                <ol class="thean-ous-guide">
                    <li><h3>Ảnh chờ duyệt</h3><p>Đối chiếu ảnh với mã đơn, sản phẩm và số lượng. Chỉ xác nhận xuất kho khi đã kiểm tra; AI báo khớp chỉ hỗ trợ quyết định của người duyệt.</p></li>
                    <li><h3>Đơn đã upload ảnh nhưng chưa giao đi</h3><p>Kiểm tra các đơn đã có ảnh để hoàn tất việc duyệt và chuẩn bị bàn giao. Có ảnh không đồng nghĩa đã được duyệt hoặc đã giao đi. Ghi nhận giao hàng bằng thao tác hiện có trên đơn.</p></li>
                    <li><h3>Up ảnh</h3><p>Nhập đúng mã đơn, chọn ảnh, kiểm tra checklist rồi xác nhận tải lên. Có thể dùng chế độ nhiều đơn và AI theo các lựa chọn của biểu mẫu. Tải ảnh xong chuyển sang Ảnh chờ duyệt.</p></li>
                </ol>
                <p class="thean-ous-guide__note">Chuyển tab giữ dữ liệu đang nhập và ảnh đang chọn. Hai danh sách được làm mới khi mở lại; biểu mẫu Up ảnh giữ nguyên cho tới khi bạn chủ động tải lại. Dùng nút Tải lại công cụ khi cần khởi động lại toàn bộ công cụ.</p>
            </div>
            <div data-workflow-guide-scope="products" hidden>
                <p>Chọn một trong ba công cụ. Chuyển tab giữ kết quả và dữ liệu đang nhập; Tải lại công cụ sẽ khởi động lại công cụ đó.</p>
                <ol class="thean-ous-guide">
                    <li><h3>Tra thông tin &amp; HDSD sản phẩm</h3><p>Nhập tên sản phẩm, chọn đúng sản phẩm hoặc biến thể, đọc thông tin và hướng dẫn sử dụng. Dùng nút Copy giới thiệu / Copy HDSD để gửi nội dung phù hợp cho khách.</p></li>
                    <li><h3>So sánh sản phẩm</h3><p>Tìm và thêm các sản phẩm cần so sánh, kiểm tra biến thể và đơn vị. Đọc bảng so sánh theo tiêu chí khách quan tâm; nội dung AI chỉ hỗ trợ, cần kiểm tra thông tin trước khi tư vấn.</p></li>
                    <li><h3>Tính protein</h3><p>Nhập thông tin và lựa chọn phù hợp trong biểu mẫu để xem kết quả ước tính. Dùng kết quả làm thông tin tham khảo khi tư vấn và đọc các giải thích ngay trong công cụ.</p></li>
                </ol>
                <p class="thean-ous-guide__note">Các công cụ dùng lại chức năng hiện có của website. Chỉ công cụ đang chọn được tải lần đầu. Nếu nội dung không tải, bấm Tải lại công cụ; dùng Mở riêng khi cần đăng nhập lại hoặc xem rộng hơn. Muốn tiếp tục xử lý đơn, bấm Đơn hàng ở thanh trên hoặc 📋 Website ở menu sticky phía dưới.</p>
            </div>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-close>Đã hiểu, quay lại làm việc</button>
        </div>
    </div>
    <?php
}

add_action('admin_enqueue_scripts', 'thean_ous_enqueue_assets', 20);
function thean_ous_enqueue_assets(): void
{
    if (!thean_ous_is_list_ui()) {
        return;
    }
    $dir = get_stylesheet_directory() . '/custom-functions/woocommerce/orders/assets/';
    $uri = get_stylesheet_directory_uri() . '/custom-functions/woocommerce/orders/assets/';
    wp_enqueue_style('thean-ous', $uri . 'order-universal-search.css', [], (string) @filemtime($dir . 'order-universal-search.css'));
    wp_enqueue_script('thean-ous', $uri . 'order-universal-search.js', [], (string) @filemtime($dir . 'order-universal-search.js'), true);
    wp_add_inline_script('thean-ous', 'window.theanOusCfg = ' . wp_json_encode(thean_ous_ui_config()) . ';', 'before');
    wp_enqueue_script('thean-workspace-tabs', $uri . 'order-workspace-tabs.js', ['thean-ous'], (string) @filemtime($dir . 'order-workspace-tabs.js'), true);
    if (function_exists('thean_flow_popup_enqueue')) {
        thean_flow_popup_enqueue(false); // popup Tạm giữ / Hủy / Đặt trước… (order-ops-step.php)
    }
    if (function_exists('thean_vat_request_enqueue')) {
        thean_vat_request_enqueue(false); // popup Nhập thông tin xuất VAT (order-vat-request.php)
    }
}

/**
 * Logo site ở đầu thanh tìm — như thanh ERP (/erp): logo theme → ảnh đầu email WooCommerce → biểu tượng site → tên site.
 * Theanmarket dùng luôn thean_erp_get_nav_logo_html() nếu module ERP đã nạp.
 */
function thean_ous_logo_html(): string
{
    if (function_exists('thean_erp_get_nav_logo_html')) {
        return thean_erp_get_nav_logo_html();
    }
    $name     = (string) get_bloginfo('name');
    $logo_id  = (int) get_theme_mod('custom_logo');
    $logo_url = $logo_id > 0 ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
    if ($logo_url === '') {
        $logo_url = (string) get_option('woocommerce_email_header_image');
    }
    if ($logo_url === '') {
        $logo_url = (string) get_site_icon_url(64);
    }
    if ($logo_url === '') {
        return '<span>' . esc_html($name) . '</span>';
    }
    return '<img class="skip-lazy" data-skip-lazy="1" src="' . esc_url($logo_url) . '" alt="' . esc_attr($name) . '">';
}

add_action('admin_footer', 'thean_ous_render_ui');
function thean_ous_render_ui(): void
{
    if (!thean_ous_is_list_ui()) {
        return;
    }
    ?>
    <form class="thean-ous-bar" id="thean-ous-bar" role="search" autocomplete="off" hidden>
        <div class="thean-workspace-tabs" role="tablist" aria-label="Khu làm việc">
            <button type="button" id="thean-workspace-orders" role="tab" aria-controls="thean-ous-orders-panel" aria-selected="true" tabindex="0" data-order-workspace="orders">📋 Đơn hàng</button>
            <?php if (function_exists('thean_order_portal_is_route') && thean_order_portal_is_route()) : ?>
                <button type="button" id="thean-workspace-create" role="tab" aria-controls="thean-ous-create-panel" aria-selected="false" tabindex="-1" data-order-workspace="create">＋ Tạo đơn</button>
            <?php endif; ?>
            <button type="button" id="thean-workspace-products" role="tab" aria-controls="thean-ous-products-panel" aria-selected="false" tabindex="-1" data-order-workspace="products">📦 Sản phẩm</button>
            <?php if (current_user_can('manage_woocommerce')) : ?>
                <button type="button" id="thean-workspace-exports" role="tab" aria-controls="thean-ous-exports-panel" aria-selected="false" tabindex="-1" data-order-workspace="exports">📤 Xuất kho</button>
            <?php endif; ?>
            <?php if (function_exists('thean_order_portal_legacy_url')) : // /quan-ly-don: link về danh sách Woo, căn phải cùng hàng tab ?>
                <a class="thean-workspace-legacy" href="<?php echo esc_url(thean_order_portal_legacy_url()); ?>" title="Mở danh sách đơn WooCommerce gốc">Phiên bản cũ ↗</a>
            <?php endif; ?>
        </div>
        <?php // Khối bọc hàng tìm + gợi ý: /quan-ly-don cho riêng khối này sticky (tab cuộn đi); ở admin là khối thường. ?>
        <div class="thean-ous-bar__sticky">
        <div class="thean-ous-bar__row">
            <a class="thean-ous-bar__brand" href="<?php echo esc_url(function_exists('thean_erp_get_home_url') ? thean_erp_get_home_url() : home_url('/')); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr(get_bloginfo('name')); ?>"><?php echo thean_ous_logo_html(); ?></a>
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input type="search" name="q" placeholder="Tìm đơn: ID, tên, SĐT, email, địa chỉ, SP, vận đơn… — gõ / để lọc (/tt, /sdt, /id, /sp)" aria-label="Tìm đơn hàng" aria-autocomplete="list" aria-controls="thean-ous-suggest">
            <label class="thean-ous-bar__range">Ngày tạo
                <select name="range" aria-label="Khoảng ngày tạo đơn" title="Áp dụng cho tìm kiếm và board. Toàn bộ vẫn giữ điều kiện nghiệp vụ và mốc khóa đơn của board.">
                    <option value="auto" title="Mặc định: 30 ngày; riêng Hoàn hàng và Đặt trước: 90 ngày">30/90 ngày</option>
                    <option value="7" title="7 ngày gần nhất">7 ngày</option>
                    <option value="30" title="30 ngày gần nhất">30 ngày</option>
                    <option value="90" title="90 ngày gần nhất">90 ngày</option>
                    <option value="all" title="Toàn bộ thời gian">Toàn bộ</option>
                </select>
            </label>
            <kbd>/</kbd>
            <button type="submit" class="thean-ous-bar__submit">Tìm</button>
            <button type="button" class="thean-ous-bar__board" data-thean-ous-board title="Tải đơn cần xử lý ngay trên trang, chia tab theo bước (giống bảng điều phối)">📋 <span>Tải đơn cần xử lý</span></button>
            <button type="button" class="thean-ous-bar__flush" data-thean-ous-flush title="Xoá cache tìm kiếm / xem nhanh / board và tính lại bước flow cho các đơn đang mở">🧹 <span>Xoá cache</span></button>
            <div class="thean-ous-menu" data-thean-ous-menu>
                <button type="button" class="thean-ous-menu__btn" aria-haspopup="menu" aria-expanded="false" title="Thêm" aria-label="Thêm">⋯<b data-thean-ous-alert-dot hidden></b></button>
                <div class="thean-ous-menu__list" role="menu" hidden>
                    <label class="thean-ous-menu__item thean-ous-menu__toggle" role="menuitemcheckbox" title="Ẩn mọi thông báo (notice) ở trang danh sách đơn — nhớ cho lần sau">
                        <input type="checkbox" name="alerts_off"> <span>Ẩn thông báo</span><b data-thean-ous-alert-count hidden></b>
                    </label>
                    <label class="thean-ous-menu__item thean-ous-menu__toggle" role="menuitemcheckbox" title="Ẩn thanh admin (trên cùng) và menu trái của WordPress ở trang danh sách đơn — nhớ cho lần sau">
                        <input type="checkbox" name="chrome_off"> <span>Ẩn thanh admin + menu trái</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="thean-ous-suggest" id="thean-ous-suggest" role="listbox" hidden></div>
        </div>
    </form>

    <div class="thean-ous-modal" id="thean-ous-results" hidden>
        <div class="thean-ous-modal__backdrop" data-thean-ous-close></div>
        <div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="thean-ous-results-title">
            <button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng">&times;</button>
            <h2 class="thean-ous-modal__title" id="thean-ous-results-title">Kết quả tìm đơn <span class="thean-ous-modal__status"></span></h2>
            <div class="thean-ous-chips"></div>
            <div class="thean-ous-modal__body"></div>
        </div>
    </div>

    <section class="thean-ous-board" id="thean-ous-board" aria-labelledby="thean-ous-board-title" hidden>
        <div class="thean-ous-board__head">
            <h2 class="thean-ous-modal__title" id="thean-ous-board-title">Đơn cần xử lý <span class="thean-ous-modal__status"></span></h2>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-workflow-guide aria-haspopup="dialog" aria-controls="thean-ous-workflow-guide">📖 Quy trình</button>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-board-refresh>↻ Làm mới</button>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-board-link title="Sao chép link mở thẳng tab đang xem (?thean_board=…)">🔗 Link tab</button>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-board-close>Ẩn</button>
        </div>
        <div class="thean-ous-tabs" role="tablist" aria-label="Bước xử lý"></div>
        <div class="thean-ous-modal__body"></div>
    </section>

    <section id="thean-ous-orders-panel" role="tabpanel" aria-labelledby="thean-workspace-orders"></section>
    <?php if (function_exists('thean_order_portal_render_create_tab')) thean_order_portal_render_create_tab(); ?>
    <?php thean_order_render_product_workspace(); ?>
    <?php thean_order_render_export_workspace(); ?>
    <nav class="thean-workspace-foot" id="thean-workspace-foot" aria-label="Khu làm việc" hidden>
        <?php // 1 dòng. Theanmarket: admin-embed.js (shopee-export) chèn nút 🛍️ Shopee ngay sau 📋 Website. ?>
        <button type="button" data-order-workspace="orders" aria-pressed="true">📋 Website <b data-thean-foot-count hidden></b></button>
        <?php if (function_exists('thean_order_portal_is_route') && thean_order_portal_is_route()) : ?>
            <button type="button" data-order-workspace="create" aria-pressed="false">＋ Tạo đơn</button>
        <?php endif; ?>
        <button type="button" data-order-workspace="products" aria-pressed="false">📦 Sản phẩm</button>
        <?php if (current_user_can('manage_woocommerce')) : ?>
            <button type="button" data-order-workspace="exports" aria-pressed="false">📤 Xuất kho</button>
        <?php endif; ?>
    </nav>
    <?php thean_ous_render_workflow_guide(); ?>

    <div class="thean-ous-modal thean-ous-modal--invoice" id="thean-ous-invoice" hidden>
        <div class="thean-ous-modal__backdrop" data-thean-ous-close></div>
        <div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="thean-ous-invoice-title">
            <button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng">&times;</button>
            <div class="thean-ous-invoice__head">
                <h2 class="thean-ous-modal__title" id="thean-ous-invoice-title">🧾 Hóa đơn</h2>
                <button type="button" class="thean-ous__more-btn" data-thean-ous-invoice-print>🖨 In</button>
                <a class="thean-ous__more-btn" data-thean-ous-invoice-open href="#" target="_blank" rel="noopener">Mở tab mới ↗</a>
            </div>
            <iframe class="thean-ous-invoice__frame" title="Hóa đơn" src="about:blank"></iframe>
        </div>
    </div>

    <div class="thean-ous-modal thean-ous-modal--preview" id="thean-ous-preview" hidden>
        <div class="thean-ous-modal__backdrop" data-thean-ous-close></div>
        <div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-label="Xem nhanh đơn">
            <button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng">&times;</button>
            <div class="thean-ous-modal__body"></div>
        </div>
    </div>

    <?php
}

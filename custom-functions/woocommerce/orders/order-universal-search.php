<?php
if (!defined('ABSPATH')) exit;

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
 * đếm + phân trang bằng SQL rồi đọc 10 đơn của trang; 10 đơn đó được tính lại tại chỗ (tự sửa nếu lệch).
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
const THEAN_OUS_PREVIEW_TTL = 120; // HTML xem nhanh trong object cache (Redis)
const THEAN_OUS_RL_MAX      = 4;   // tối đa lượt tìm thật (không trúng cache) …
const THEAN_OUS_RL_WINDOW   = 30;  // … trong 30 giây; lượt thứ 5 bị chặn
const THEAN_OUS_RL_BLOCK    = 120; // khóa 2 phút
const THEAN_OUS_BULK_MAX = 50; // thao tác hàng loạt: tối đa 50 đơn / lần
const THEAN_OUS_BOARD_FIRST = 5;  // board: lần tải đầu (mọi tab) lấy 5 đơn / tab …
const THEAN_OUS_BOARD_MORE  = 20; // … tab đang xem tự tải thêm cho đủ 20; "Xem thêm" tải 20 đơn / lần (client gửi limit ≤ 50)
const THEAN_OUS_BOARD_BACKFILL = 20; // board: mỗi lần mở tính bù tối đa 20 đơn chưa có bước

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

/** Shipper (mã self/ghtk/…) → nhãn, đồng bộ filter eim_order_shipper_label ở bootstrap.php. */
function thean_ous_shipper(WC_Order $order): string
{
    $map = ['self' => 'THEAN', 'ghtk' => 'GHTK', 'ahamove' => 'Ahamove', 'viettel' => 'Viettel Post'];
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

function thean_ous_ids_product(array $tokens): array
{
    global $wpdb;
    return $wpdb->get_col(
        "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items
         WHERE order_item_type = 'line_item' AND " . thean_ous_tokens_like('order_item_name', $tokens) . "
         ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE
    );
}

function thean_ous_ids_customer(array $tokens, string $like): array
{
    global $wpdb;
    if (thean_ous_hpos_enabled()) {
        $concat = "CONCAT_WS(' ', first_name, last_name, company, address_1, address_2, city, state, postcode, email, phone)";
        return $wpdb->get_col(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_addresses
             WHERE " . thean_ous_tokens_like($concat, $tokens) . " ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE
        );
    }

    // _billing/_shipping_address_index chứa sẵn tên + công ty + địa chỉ + email + SĐT.
    $ids = $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_address_index', '_shipping_address_index')
           AND " . thean_ous_tokens_like('meta_value', $tokens) . "
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE
    );
    // Đơn cũ thiếu address_index: tìm thêm theo từng trường tên / địa chỉ.
    return array_merge($ids, $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_shipping_first_name', '_shipping_last_name', '_billing_address_1', '_shipping_address_1')
           AND meta_value LIKE %s
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    )));
}

function thean_ous_ids_phone(string $needle): array
{
    global $wpdb;
    $like = thean_ous_like($needle);
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_order_addresses
             WHERE " . thean_ous_phone_sql('phone') . " LIKE %s ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE,
            $like
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_billing_phone', '_shipping_phone') AND " . thean_ous_phone_sql('meta_value') . " LIKE %s
         ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    ));
}

function thean_ous_ids_email(string $like): array
{
    global $wpdb;
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email LIKE %s ORDER BY id DESC LIMIT " . THEAN_OUS_SOURCE,
            $like
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_email' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
        $like
    ));
}

function thean_ous_ids_ship(string $like): array
{
    global $wpdb;
    $keys  = thean_ous_tracking_meta_keys();
    $in    = implode(',', array_fill(0, count($keys), '%s'));
    if (thean_ous_hpos_enabled()) {
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ({$in}) AND meta_value LIKE %s ORDER BY order_id DESC LIMIT " . THEAN_OUS_SOURCE,
            array_merge($keys, [$like])
        ));
    }
    return $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$in}) AND meta_value LIKE %s ORDER BY post_id DESC LIMIT " . THEAN_OUS_SOURCE,
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
function thean_ous_find_order_ids(array $f, int $cap = THEAN_OUS_CAP): array
{
    global $wpdb;

    $sets = [];
    if ($f['text'] !== '') {
        $tokens = thean_ous_tokens($f['text']);
        $like   = thean_ous_like($f['text']);
        $set    = array_merge(
            thean_ous_candidate_ids($f['text']),
            thean_ous_ids_product($tokens),
            thean_ous_ids_customer($tokens, $like),
            thean_ous_ids_email($like),
            thean_ous_ids_ship($like)
        );
        $phone = thean_ous_phone_needle($f['text']);
        if ($phone !== '') {
            $set = array_merge($set, thean_ous_ids_phone($phone));
        }
        $sets[] = $set;
    }
    if ($f['id'] !== '') {
        $sets[] = thean_ous_candidate_ids($f['id']);
    }
    if ($f['phone'] !== '') {
        $sets[] = thean_ous_ids_phone(thean_ous_phone_needle($f['phone'], 4));
    }
    if ($f['product'] !== '') {
        $sets[] = thean_ous_ids_product(thean_ous_tokens($f['product']));
    }
    if ($f['customer'] !== '') {
        $sets[] = thean_ous_ids_customer(thean_ous_tokens($f['customer']), thean_ous_like($f['customer']));
    }
    if ($f['ship'] !== '') {
        $sets[] = thean_ous_ids_ship(thean_ous_like($f['ship']));
    }
    if ($f['email'] !== '') {
        $sets[] = thean_ous_ids_email(thean_ous_like($f['email']));
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
    if ($ids !== null) {
        $where[] = "{$id_col} IN (" . implode(',', $ids) . ')';
    }
    if ($f['statuses']) {
        $where[] = "{$st_col} IN ('" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), $f['statuses'])) . "')";
    }

    $table = $hpos ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
    return array_map('intval', $wpdb->get_col(
        "SELECT {$id_col} FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$id_col} DESC LIMIT " . (int) $cap
    ));
}

/** Version cache "orders" của WC — đổi mỗi khi đơn được lưu / đổi trạng thái. */
function thean_ous_orders_version(): string
{
    return class_exists('WC_Cache_Helper') ? (string) WC_Cache_Helper::get_transient_version('orders') : '';
}

/** Khóa transient cho danh sách ID khớp (Redis nếu có object cache). Phân trang chỉ cắt mảng này. */
function thean_ous_ids_cache_key(array $f): string
{
    unset($f['errors']);
    return 'thean_ous_' . md5(wp_json_encode([$f, thean_ous_hpos_enabled(), thean_ous_orders_version()]));
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
    return [$meta['group'], $meta['short']];
}

function thean_ous_action_groups(): array
{
    return ['view' => 'Xem', 'flow' => 'Tạm giữ / Hủy', 'pay' => 'Thanh toán', 'kho' => 'Kho', 'hoan' => 'Hoàn', 'other' => 'Khác'];
}

function thean_ous_button(string $group, string $label, string $url, string $class = '', bool $blank = false): string
{
    return '<a class="thean-ous-btn thean-ous-btn--' . esc_attr($group) . ($class !== '' ? ' ' . esc_attr($class) : '') . '" href="' . esc_url($url) . '"'
        . ($blank ? ' target="_blank" rel="noopener"' : '') . ' title="' . esc_attr($label) . '">' . esc_html($label) . '</a>';
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
        'picking' => ['order-export-images', 'in-van-don-ghtk', 'print_pxk'],
        'export'  => ['order-export-confirm'],
        'return'  => ['order-return-record', 'order-return-images'],
        'proof'   => ['order-export-images', 'order-export-confirm'],
    ];
    $buttons = [];
    // Đơn đang giao thiếu chứng từ XK: nút theo dõi giao + nút bổ sung chứng từ (như tab Thiếu chứng từ XK).
    $suffixes = array_merge($map[$step] ?? [], $proof && $step !== 'proof' ? $map['proof'] : []);
    foreach ($suffixes as $suffix) {
        foreach (thean_ous_order_actions($order) as $key => $action) {
            $slug = sanitize_html_class($action['action'] ?? $key);
            if (empty($action['url']) || ($slug !== $suffix && substr($slug, -strlen('-' . $suffix)) !== '-' . $suffix)) {
                continue;
            }
            [$group, $label] = thean_ous_action_meta($slug);
            $label = $label !== '' ? $label : wp_strip_all_tags((string) ($action['name'] ?? $key));
            $buttons[] = thean_ous_button($group, $label, (string) $action['url'], 'wc-action-button wc-action-button-' . $slug, !empty($action['target']));
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
        $tab = ['cod' => 'payment', 'shipping' => 'delivery'][$step] ?? $step;
        $labels = function_exists('thean_ops_step_tabs') ? thean_ops_step_tabs() : [];
        $buttons[] = thean_ous_button('view', 'Mở bước ' . ($labels[$step] ?? $step) . ' ↗', $order->get_edit_order_url() . '#step-' . $tab, '', true);
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
        $buttons['view'][] = '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-ous-preview="' . esc_attr($id) . '">Xem nhanh</button>';
    }
    $buttons['view'][] = thean_ous_button('view', 'Chi tiết', $order->get_edit_order_url(), '', true);
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
        [$group, $label] = thean_ous_action_meta($slug);
        $group = $is_flow ? 'flow' : $group;
        $label = $is_flow ? '📅 Đặt trước' : ($label !== '' ? $label : wp_strip_all_tags((string) ($action['name'] ?? $key)));
        $buttons[$group][] = thean_ous_button($group, $label, (string) $action['url'], 'wc-action-button wc-action-button-' . $slug, !empty($action['target']));
    }

    $buttons['flow'] = array_merge(thean_ous_flow_buttons($order), $buttons['flow']);

    if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
        array_splice($buttons['view'], $compact ? 1 : 0, 0, [thean_ous_button('edit', 'Chỉnh', thean_ous_creator_url($id), '', true)]);
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

function thean_ous_render_rows(array $ids, array $tokens, array $hints = []): string
{
    ob_start();
    foreach (thean_ous_load_orders($ids) as $order) {
        $id = $order->get_id();
        // Bước tiếp theo (board đã tính sẵn thì dùng gợi ý của board).
        $flow = function_exists('thean_ops_step_compute') ? thean_ops_step_compute($order) : ['step' => '', 'hint' => '', 'proof' => false];
        if (!empty($hints[$id])) {
            $flow['hint'] = $hints[$id];
        }
        $shipper = thean_ous_shipper($order);
        $ship    = thean_ous_ship_code($order);
        $phone   = $order->get_billing_phone() ?: $order->get_shipping_phone();
        $address = thean_ous_address_line($order);
        ?>
        <tr data-order-id="<?php echo esc_attr($id); ?>" tabindex="0">
            <td class="thean-ous__c-check"><label><input type="checkbox" data-thean-ous-check value="<?php echo esc_attr($id); ?>" aria-label="Chọn đơn #<?php echo esc_attr($id); ?>"></label></td>
            <td class="thean-ous__c-order">
                <div class="thean-ous__order-head">
                    <strong><?php echo thean_ous_highlight('#' . $id, $tokens); ?></strong>
                    <span class="column-order_status"><?php echo thean_ous_status_badge($order); ?></span>
                </div>
                <small><?php echo thean_ous_highlight($order->get_order_number(), $tokens); ?> · <?php echo esc_html(thean_ous_short_date($order)); ?></small>
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
                    <small>🚚 <?php echo thean_ous_highlight(trim($shipper . ' ' . $ship), $tokens); ?></small>
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

function thean_ous_render_preview(WC_Order $order): string
{
    $id   = $order->get_id();
    $date = $order->get_date_created();
    $ops  = array_filter([
        'Giao bởi'         => thean_ous_shipper($order),
        'Mã vận đơn'       => thean_ous_ship_code($order),
        'Ngày xuất kho'    => thean_ous_meta($order, ['order_ship_date', 'order_export_date', 'export_date']),
        'Ngày CK'          => thean_ous_meta($order, ['order_paid_date', 'paid_date']),
        'TK nhận'          => thean_ous_meta($order, ['order_bank_account_received', 'bank_account_received']),
        'Trạng thái xử lý' => thean_ous_meta($order, ['order_handling_status', 'handling_status']),
        'Hoàn hàng'        => thean_ous_meta($order, ['return_status']),
    ], static fn($v) => $v !== '');
    $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes(['order_id' => $id, 'limit' => 5]) : [];

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
function thean_ous_board_sql(): string
{
    $t = thean_ops_step_tables();
    $excluded = "'" . implode("','", array_map(static fn($s) => esc_sql('wc-' . $s), thean_ops_excluded_statuses())) . "'";
    // Điều kiện cuối: đơn nội bộ (domain email / _eim_internal — đổi cài đặt có hiệu lực ngay), đơn tạo quá 365 ngày.
    return "FROM {$t['orders']} o
            JOIN {$t['meta']} s ON s.{$t['fk']} = o.{$t['id']} AND s.meta_key = '" . THEAN_OPS_STEP_META . "' AND s.meta_value <> 'none'
            WHERE o.{$t['type']} = 'shop_order'
              AND o.{$t['status']} NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'draft')
              AND (o.{$t['status']} NOT IN ({$excluded}) OR s.meta_value IN ('return', 'proof'))
              AND NOT EXISTS (SELECT 1 FROM {$t['meta']} x WHERE x.{$t['fk']} = o.{$t['id']} AND x.meta_key = '_spx_date' AND x.meta_value <> '')
              AND " . thean_ops_internal_sql($t) . "
              AND " . thean_ops_age_sql($t);
}

/**
 * Điều kiện SQL chọn đơn của 1 tab: tab "Thiếu chứng từ XK" theo cờ THEAN_OPS_PROOF_META (gồm cả đơn đang giao — bước
 * chính vẫn là Đang giao), các tab khác theo bước `_thean_ops_step` (alias s).
 */
function thean_ous_board_tab_sql(string $tab): string
{
    global $wpdb;
    if ($tab === 'proof') {
        $t = thean_ops_step_tables();
        return "EXISTS (SELECT 1 FROM {$t['meta']} pf WHERE pf.{$t['fk']} = o.{$t['id']} AND pf.meta_key = '" . THEAN_OPS_PROOF_META . "' AND pf.meta_value = '1')";
    }
    return $wpdb->prepare('s.meta_value = %s', $tab);
}

/** Đơn (kết quả thean_ops_step_compute) có thuộc tab không — cùng quy tắc thean_ous_board_tab_sql. */
function thean_ous_board_in_tab(array $res, string $tab): bool
{
    return $tab === 'proof' ? !empty($res['proof']) : ($res['step'] ?? '') === $tab;
}

/** Số đơn mỗi tab (GROUP BY theo bước + đếm riêng tab "Thiếu chứng từ XK" theo cờ). */
function thean_ous_board_counts(): array
{
    global $wpdb;
    $rows   = $wpdb->get_results('SELECT s.meta_value AS tab, COUNT(DISTINCT o.' . thean_ops_step_tables()['id'] . ') AS n ' . thean_ous_board_sql() . ' GROUP BY s.meta_value', ARRAY_A);
    $counts = array_fill_keys(array_keys(thean_ops_step_tabs()), 0);
    foreach ((array) $rows as $row) {
        if (isset($counts[$row['tab']])) {
            $counts[$row['tab']] = (int) $row['n'];
        }
    }
    if (isset($counts['proof'])) {
        $counts['proof'] = (int) $wpdb->get_var('SELECT COUNT(DISTINCT o.' . thean_ops_step_tables()['id'] . ') ' . thean_ous_board_sql() . ' AND ' . thean_ous_board_tab_sql('proof'));
    }
    return $counts;
}

/** ID đơn trong tab từ vị trí $offset, tối đa $limit đơn, mới nhất trước. */
function thean_ous_board_page_ids(string $tab, int $offset, int $limit): array
{
    global $wpdb;
    $id = thean_ops_step_tables()['id'];
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT o.{$id} " . thean_ous_board_sql() . ' AND ' . thean_ous_board_tab_sql($tab) . " ORDER BY o.{$id} DESC LIMIT %d OFFSET %d",
        $limit, $offset
    )));
}

add_action('wp_ajax_thean_order_usearch_board', 'thean_ous_ajax_board');
function thean_ous_ajax_board(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }

    // Đơn chưa có bước (mới bật tính năng / vừa tăng THEAN_OPS_STEP_VERSION): bù dần mỗi lần mở board.
    $pending = thean_ops_step_unsynced_ids(THEAN_OUS_BOARD_BACKFILL + 1);
    thean_ops_step_sync_ids(array_slice($pending, 0, THEAN_OUS_BOARD_BACKFILL));

    $labels = thean_ops_step_tabs();
    $offset = absint($_POST['offset'] ?? 0); // "Xem thêm": số đơn đang hiện của tab
    $limit  = min(50, max(1, absint($_POST['limit'] ?? THEAN_OUS_BOARD_MORE)));
    $tab    = sanitize_key(wp_unslash($_POST['tab'] ?? ''));
    $counts = thean_ous_board_counts();
    if (!isset($labels[$tab])) {
        $tab = (string) (array_key_first(array_filter($counts)) ?? array_key_first($labels));
    }

    // all = 1 (mở board / Làm mới): trả luôn THEAN_OUS_BOARD_FIRST đơn đầu của mọi tab có đơn → đổi tab không gọi lại server.
    $all  = !empty($_POST['all']);
    $load = $all ? array_keys(array_filter($counts)) : [];
    if (!in_array($tab, $load, true)) {
        $load[] = $tab;
    }
    $ids_by = [];
    foreach ($load as $t) {
        $ids_by[$t] = $all
            ? thean_ous_board_page_ids($t, 0, THEAN_OUS_BOARD_FIRST)
            : thean_ous_board_page_ids($t, $offset, $limit);
    }

    // Tính lại tại chỗ các đơn sẽ hiện (đọc gộp 1 lần): lệch thì ghi lại, đơn đã sang bước khác thì không hiện ở tab đó.
    $fresh   = thean_ops_step_sync_ids(array_merge([], ...array_values($ids_by)));
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
        $counts = thean_ous_board_counts();
    }

    $panes = [];
    foreach ($show_by as $t => $show) {
        $total = $counts[$t] ?? 0;
        // Đơn vừa rời tab (tính lại lệch) không còn trong truy vấn → vị trí kế tiếp = số đơn đã hiện.
        $shown = min($total, ($all ? 0 : $offset) + count($show));
        $panes[$t] = [
            'tab'      => $t,
            'rows'     => thean_ous_render_rows($show, [], array_intersect_key($hints, array_flip($show))),
            'total'    => $total,
            'shown'    => $shown,
            'has_more' => $shown < $total,
        ];
    }
    $tabs = [];
    foreach ($labels as $k => $label) {
        $tabs[] = ['key' => $k, 'label' => $label, 'count' => $counts[$k] ?? 0];
    }
    wp_send_json_success($panes[$tab] + [
        'tabs'    => $tabs,
        'panes'   => $all ? $panes : null,
        'pending' => max(0, count($pending) - THEAN_OUS_BOARD_BACKFILL) > 0,
        'at'      => wp_date('H:i:s'),
    ]);
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
    $key     = thean_ous_ids_cache_key($filters);
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
        $ids = thean_ous_find_order_ids($filters);
        set_transient($key, $ids, THEAN_OUS_CACHE_TTL);
    }
    $total = count($ids);
    $slice = array_slice($ids, ($page - 1) * THEAN_OUS_PER_PAGE, THEAN_OUS_PER_PAGE);

    wp_send_json_success([
        'rows'     => thean_ous_render_rows($slice, thean_ous_highlight_tokens($filters)),
        'total'    => $total,
        'capped'   => $total >= THEAN_OUS_CAP,
        'shown'    => min($total, $page * THEAN_OUS_PER_PAGE),
        'page'     => $page,
        'has_more' => $page * THEAN_OUS_PER_PAGE < $total,
        'summary'  => thean_ous_filter_summary($filters),
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

add_action('wp_ajax_thean_order_usearch_alerts', 'thean_ous_ajax_alerts');
function thean_ous_ajax_alerts(): void
{
    check_ajax_referer('thean_ous', 'nonce');
    if (!thean_ous_user_can()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $off = !empty($_POST['off']);
    update_user_meta(get_current_user_id(), THEAN_OUS_ALERTS_META, $off ? '1' : '0');
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

// Class gắn sẵn ở <body>; CSS ẩn nằm trong assets/order-universal-search.css (nạp ở <head>) nên không chớp.
add_filter('admin_body_class', function ($classes) {
    return thean_ous_is_list_ui() && thean_ous_alerts_off() ? $classes . ' thean-ous-alerts-off' : $classes;
});

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
        'perPage'        => THEAN_OUS_PER_PAGE,
        'cap'            => THEAN_OUS_CAP,
        'commands'       => $commands,
        'aliases'        => thean_ous_command_aliases(),
        'statuses'       => thean_ous_statuses(),
        'alertsOff'      => thean_ous_alerts_off(),
        // Modal thao tác nhanh của orders-admin.php — Esc nhường chúng đóng trước.
        'canChangeStatus' => function_exists('thean_can_change_any_status') && thean_can_change_any_status(), // order-ops-step.php
        'canSkipProof'   => function_exists('thean_can_skip_export_proof') && thean_can_skip_export_proof(),
        'wcList'         => function_exists('thean_wcl_state') ? thean_wcl_state() : null, // order-list-lazy.php
        'blockingModals' => '.hithean-order-modal:not([hidden]), .hithean-return-modal:not([hidden]), #thean-flow-modal:not([hidden]), #thean-vat-modal:not([hidden])',
    ];
}

// CSS/JS là file tĩnh (trình duyệt cache, version = filemtime). File này được nạp trong
// admin_enqueue_scripts priority 10 nên đăng ký priority 20 để chạy ngay trong lượt đó.
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
        <div class="thean-ous-bar__row">
            <a class="thean-ous-bar__brand" href="<?php echo esc_url(function_exists('thean_erp_get_home_url') ? thean_erp_get_home_url() : home_url('/')); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr(get_bloginfo('name')); ?>"><?php echo thean_ous_logo_html(); ?></a>
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input type="search" name="q" placeholder="Tìm đơn: ID, tên, SĐT, email, địa chỉ, SP, vận đơn… — gõ / để lọc (/tt, /sdt, /id, /sp)" aria-label="Tìm đơn hàng" aria-autocomplete="list" aria-controls="thean-ous-suggest">
            <kbd>/</kbd>
            <button type="submit" class="thean-ous-bar__submit">Tìm</button>
            <button type="button" class="thean-ous-bar__board" data-thean-ous-board title="Tải đơn cần xử lý ngay trên trang, chia tab theo bước (giống bảng điều phối)">📋 <span>Tải đơn cần xử lý</span></button>
            <div class="thean-ous-menu" data-thean-ous-menu>
                <button type="button" class="thean-ous-menu__btn" aria-haspopup="menu" aria-expanded="false" title="Thêm" aria-label="Thêm">⋯<b data-thean-ous-alert-dot hidden></b></button>
                <div class="thean-ous-menu__list" role="menu" hidden>
                    <label class="thean-ous-menu__item thean-ous-menu__toggle" role="menuitemcheckbox" title="Ẩn mọi thông báo (notice) ở trang danh sách đơn — nhớ cho lần sau">
                        <input type="checkbox" name="alerts_off"> <span>Ẩn thông báo</span><b data-thean-ous-alert-count hidden></b>
                    </label>
                </div>
            </div>
        </div>
        <div class="thean-ous-suggest" id="thean-ous-suggest" role="listbox" hidden></div>
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
            <button type="button" class="thean-ous__more-btn" data-thean-ous-board-refresh>↻ Làm mới</button>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-board-close>Ẩn</button>
        </div>
        <div class="thean-ous-tabs" role="tablist" aria-label="Bước xử lý"></div>
        <div class="thean-ous-modal__body"></div>
    </section>

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

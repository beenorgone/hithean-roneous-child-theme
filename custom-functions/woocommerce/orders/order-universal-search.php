<?php
if (!defined('ABSPATH')) exit;

/**
 * Tìm kiếm đơn nhanh (sticky) ở trang danh sách đơn WooCommerce — thay ô tìm mặc định.
 *
 * Gõ tự do: ID / mã đơn (ID + hậu tố), tên khách, SĐT, email, địa chỉ, tên SP, mã vận đơn.
 * Lệnh lọc (kết hợp được, AND): /tt <trạng thái> (lặp lại = OR), /sdt, /id, /sp, /vd, /email, /kh.
 * Chạy cả HPOS (wc_orders / wc_order_addresses / wc_orders_meta) lẫn lưu trữ cũ (posts / postmeta).
 *
 * Tải VPS: danh sách ID khớp (≤ THEAN_OUS_CAP) được cache transient theo truy vấn + version
 * cache "orders" của WC, nên bấm "Xem thêm" chỉ render 10 đơn kế, không chạy lại LIKE.
 * Kết quả trong modal dạng bảng (mobile: dạng thẻ); bấm dòng để xem nhanh đơn kèm các nút
 * thao tác (dùng chung modal thao tác nhanh ở orders-admin.php qua class .wc-action-button-*).
 *
 * "Ẩn thông báo": ô tích trên thanh tìm ẩn mọi .notice / .update-nag ở danh sách đơn — MẶC ĐỊNH BẬT
 * cho mọi user; bỏ tích thì lưu '0' vào user meta THEAN_OUS_ALERTS_META, phiên sau vẫn giữ.
 *
 * AJAX: thean_order_usearch, thean_order_usearch_preview, thean_order_usearch_alerts.
 */

const THEAN_OUS_PER_PAGE  = 10;
const THEAN_OUS_CAP       = 500;  // tối đa số đơn khớp giữ trong cache cho 1 truy vấn
const THEAN_OUS_SOURCE    = 1000; // tối đa ứng viên lấy từ mỗi nguồn
const THEAN_OUS_CACHE_TTL = 180;
const THEAN_OUS_ALERTS_META = 'thean_ous_alerts_off';

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

/**
 * Danh sách ID khớp, cache theo truy vấn + version cache "orders" của WC (đổi mỗi khi
 * đơn được lưu / đổi trạng thái) + TTL ngắn. Phân trang chỉ cắt mảng này.
 */
function thean_ous_cached_ids(array $f): array
{
    $version = class_exists('WC_Cache_Helper') ? WC_Cache_Helper::get_transient_version('orders') : '';
    unset($f['errors']);
    $key = 'thean_ous_' . md5(wp_json_encode([$f, thean_ous_hpos_enabled(), $version]));

    $ids = get_transient($key);
    if (is_array($ids)) {
        return $ids;
    }
    $ids = thean_ous_find_order_ids($f);
    set_transient($key, $ids, THEAN_OUS_CACHE_TTL);
    return $ids;
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
    return ['view' => 'Xem', 'pay' => 'Thanh toán', 'kho' => 'Kho', 'hoan' => 'Hoàn', 'other' => 'Khác'];
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
function thean_ous_render_actions(WC_Order $order, bool $compact): string
{
    $id      = $order->get_id();
    $buttons = array_fill_keys(array_keys(thean_ous_action_groups()), []);

    if ($compact) {
        $buttons['view'][] = '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-ous-preview="' . esc_attr($id) . '">Xem nhanh</button>';
    }
    $buttons['view'][] = thean_ous_button('view', 'Chi tiết', $order->get_edit_order_url(), '', true);

    foreach (thean_ous_order_actions($order) as $key => $action) {
        if (empty($action['url'])) {
            continue;
        }
        $slug = sanitize_html_class($action['action'] ?? $key);
        if ($slug === 'chinh-don-tao-don') {
            continue; // nút "Chỉnh" riêng bên dưới
        }
        if ($compact && strpos($slug, 'hithean-order-') !== 0) {
            continue;
        }
        [$group, $label] = thean_ous_action_meta($slug);
        $label = $label !== '' ? $label : wp_strip_all_tags((string) ($action['name'] ?? $key));
        $buttons[$group][] = thean_ous_button($group, $label, (string) $action['url'], 'wc-action-button wc-action-button-' . $slug, !empty($action['target']));
    }

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

/** Nạp sẵn cache post + meta cho cả trang (legacy) để tránh N truy vấn. */
function thean_ous_prime(array $ids): void
{
    if (!$ids || thean_ous_hpos_enabled()) {
        return;
    }
    if (function_exists('_prime_post_caches')) {
        _prime_post_caches($ids, false, true);
    }
}

function thean_ous_render_rows(array $ids, array $tokens): string
{
    thean_ous_prime($ids);
    ob_start();
    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) {
            continue;
        }
        $shipper = thean_ous_shipper($order);
        $ship    = thean_ous_ship_code($order);
        $phone   = $order->get_billing_phone() ?: $order->get_shipping_phone();
        $address = thean_ous_address_line($order);
        ?>
        <tr data-order-id="<?php echo esc_attr($id); ?>" tabindex="0">
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
            <td class="thean-ous__c-acts"><?php echo thean_ous_render_actions($order, true); ?></td>
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

    $ids   = thean_ous_cached_ids($filters);
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

    wp_send_json_success(['html' => thean_ous_render_preview($order)]);
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

// Class + CSS in sẵn ở <head> để thông báo không chớp hiện rồi mới ẩn.
add_filter('admin_body_class', function ($classes) {
    return thean_ous_is_list_ui() && thean_ous_alerts_off() ? $classes . ' thean-ous-alerts-off' : $classes;
});
add_action('admin_head', function (): void {
    if (thean_ous_is_list_ui()) {
        echo '<style>body.thean-ous-alerts-off #wpbody-content .notice, body.thean-ous-alerts-off #wpbody-content .update-nag, body.thean-ous-alerts-off .woocommerce-layout__notice-list { display:none !important; }</style>';
    }
});

add_action('admin_footer', 'thean_ous_render_ui');
function thean_ous_render_ui(): void
{
    if (!thean_ous_is_list_ui()) {
        return;
    }

    $commands = [];
    foreach (thean_ous_commands() as $key => [$label, $example, $desc]) {
        $commands[] = ['cmd' => $key, 'label' => $label, 'example' => $example, 'desc' => $desc];
    }
    $config = [
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('thean_ous'),
        'perPage'  => THEAN_OUS_PER_PAGE,
        'cap'      => THEAN_OUS_CAP,
        'commands' => $commands,
        'aliases'  => thean_ous_command_aliases(),
        'statuses' => thean_ous_statuses(),
        'alertsOff' => thean_ous_alerts_off(),
    ];
    ?>
    <style>
        #thean-ous-bar, .thean-ous-modal {
            /* Xanh dương đậm Hi The An (--default-color-dark-blue) — đổi màu ở đây. */
            --ous-primary:#0047ba; --ous-primary-dark:#003a99; --ous-primary-light:#e8f0ff; --ous-primary-lighter:#f3f7ff;
            --ous-border:#b9cdf0; --ous-border-strong:#8fb0e6;
        }
        /* Thay ô tìm mặc định của danh sách đơn (legacy + HPOS). */
        #posts-filter p.search-box, #wc-orders-filter p.search-box, .woocommerce_page_wc-orders p.search-box { display:none !important; }

        .thean-ous-bar { position:sticky; top:32px; z-index:90; margin:10px 0 12px; }
        body.thean-ous-open .thean-ous-bar { z-index:99200; } /* thanh tìm nổi trên modal kết quả */
        .thean-ous-bar__row { display:flex; gap:8px; align-items:center; padding:8px 10px; border:1px solid var(--ous-border); border-radius:10px; background:var(--ous-primary-lighter); box-shadow:0 4px 12px rgba(15,23,42,.08); }
        .thean-ous-bar input[type="search"] { flex:1; min-width:0; padding:7px 12px; border:1px solid var(--ous-border-strong); border-radius:8px; font-size:15px; line-height:1.4; }
        .thean-ous-bar kbd { font-size:11px; color:#64748b; }
        .thean-ous-bar__submit, .thean-ous__more-btn { flex:0 0 auto; margin:0; padding:7px 16px; border:1px solid var(--ous-primary-dark); border-radius:8px; background:var(--ous-primary); color:#fff; font-size:14px; font-weight:600; line-height:1.4; text-transform:none; letter-spacing:0; box-shadow:none; cursor:pointer; }
        .thean-ous-bar__submit:hover, .thean-ous__more-btn:hover { background:var(--ous-primary-dark); }
        .thean-ous__more-btn { background:#fff; color:var(--ous-primary); border-color:var(--ous-border-strong); }
        .thean-ous__more-btn:hover { background:var(--ous-primary-light); }
        .thean-ous__more-btn:disabled { opacity:.6; cursor:wait; }
        .thean-ous-bar .dashicons-search { color:var(--ous-primary); }
        .thean-ous-bar__toggle { display:inline-flex; flex:0 0 auto; align-items:center; gap:5px; margin:0; padding:5px 9px; border:1px solid var(--ous-border); border-radius:8px; background:#fff; color:#334155; font-size:13px; white-space:nowrap; cursor:pointer; user-select:none; }
        .thean-ous-bar__toggle input { margin:0 !important; }
        .thean-ous-bar__toggle b { min-width:18px; padding:0 5px; border-radius:999px; background:#fde68a; color:#78350f; font-size:11px; text-align:center; }
        .thean-ous-bar__toggle:has(input:checked) { border-color:var(--ous-primary); background:var(--ous-primary-light); color:var(--ous-primary); }
        .thean-ous-suggest[hidden] { display:none; }
        .thean-ous-suggest { position:absolute; left:0; right:0; top:100%; z-index:95; max-height:min(360px, 60vh); overflow:auto; margin-top:4px; padding:4px; border:1px solid var(--ous-border); border-radius:10px; background:#fff; box-shadow:0 12px 28px rgba(15,23,42,.18); }
        .thean-ous-suggest__item { display:flex; gap:10px; align-items:baseline; width:100%; padding:7px 10px; border:0; border-radius:7px; background:none; text-align:left; cursor:pointer; color:#1e293b; font-size:13px; }
        .thean-ous-suggest__item.is-active, .thean-ous-suggest__item:hover { background:var(--ous-primary-light); }
        .thean-ous-suggest__item code { flex:0 0 auto; padding:1px 6px; border-radius:5px; background:var(--ous-primary-light); color:var(--ous-primary); font-size:12px; }
        .thean-ous-suggest__item span { color:#64748b; }
        .thean-ous-suggest__head { padding:6px 10px 2px; color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }

        .thean-ous-modal[hidden] { display:none; }
        .thean-ous-modal { position:fixed; inset:0; z-index:99000; display:grid; grid-template-rows:minmax(0,1fr); place-items:center; padding:24px; }
        .thean-ous-modal--preview { z-index:99500; }
        .thean-ous-modal__backdrop { position:absolute; inset:0; background:rgba(15,23,42,.5); }
        .thean-ous-modal__dialog { position:relative; box-sizing:border-box; width:min(1280px,100%); max-height:100%; overflow-y:auto; overflow-x:hidden; padding:18px 22px; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(15,23,42,.32); }
        .thean-ous-modal--preview .thean-ous-modal__dialog { width:min(960px,100%); }
        .thean-ous-modal__close { position:absolute; top:10px; right:10px; z-index:2; width:32px; height:32px; border:0; border-radius:50%; background:#eef2f7; color:#334155; font-size:22px; line-height:1; cursor:pointer; }
        .thean-ous-modal__title { margin:0 40px 6px 0; font-size:17px; }
        .thean-ous-modal__status { color:#64748b; font-weight:400; font-size:13px; }
        .thean-ous-chips { display:flex; flex-wrap:wrap; gap:6px; margin:0 40px 10px 0; }
        .thean-ous-chips span { padding:2px 9px; border-radius:999px; background:var(--ous-primary-light); color:var(--ous-primary); font-size:12px; }

        .thean-ous__table { width:100%; table-layout:fixed; border-collapse:collapse; font-size:13px; }
        .thean-ous__table th, .thean-ous__table td { padding:9px 8px; border-bottom:1px solid #e2e8f0; text-align:left; vertical-align:top; overflow-wrap:anywhere; }
        .thean-ous__table thead th { position:sticky; top:-18px; z-index:1; background:#f8fafc; color:#334155; }
        .thean-ous__table col.c-order { width:190px; }
        .thean-ous__table col.c-cus { width:32%; }
        .thean-ous__table col.c-acts { width:300px; }
        .thean-ous__table tbody tr { cursor:pointer; }
        .thean-ous__table tbody tr:hover, .thean-ous__table tbody tr:focus { background:var(--ous-primary-lighter); outline:none; }
        .thean-ous__table small { display:block; margin-top:2px; color:#64748b; }
        .thean-ous__table mark:not(.order-status) { background:#fff3a3; color:inherit; padding:0 1px; }
        .thean-ous__order-head { display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
        .thean-ous__order-head strong { font-size:14px; }
        .thean-ous__table mark.order-status, .thean-ous-preview__head mark.order-status { margin:0; font-size:11px; line-height:1.9; }
        .thean-ous__total { margin-top:6px; font-weight:600; color:#0f172a; }
        .thean-ous__total small { font-weight:400; }
        .thean-ous__c-items div + div { margin-top:3px; }
        .thean-ous__more { display:flex; justify-content:center; padding:14px 0 4px; }
        .thean-ous__empty, .thean-ous__loading { padding:24px; text-align:center; color:#64748b; }

        .thean-ous-acts { display:flex; flex-direction:column; gap:5px; }
        .thean-ous-acts__g { display:flex; flex-wrap:wrap; align-items:center; gap:4px; }
        .thean-ous-acts__label { flex:0 0 74px; color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
        .thean-ous-btn, .thean-ous-btn.wc-action-button { --c:#334155; --bg:#f1f5f9; --bd:#cbd5e1; display:inline-flex !important; align-items:center; width:auto !important; height:auto !important; margin:0 !important; padding:3px 9px !important; border:1px solid var(--bd) !important; border-radius:6px !important; background:var(--bg) !important; color:var(--c) !important; font-size:12px; font-weight:600; line-height:1.6; text-indent:0 !important; text-transform:none !important; letter-spacing:0 !important; text-decoration:none; white-space:nowrap; overflow:visible; box-shadow:none !important; cursor:pointer; }
        .thean-ous-btn::after { display:none !important; content:none !important; }
        .thean-ous-btn:hover { filter:brightness(.95); }
        .thean-ous-btn:focus-visible { outline:2px solid var(--ous-primary); outline-offset:1px; }
        .thean-ous-btn--primary { --c:#fff; --bg:var(--ous-primary); --bd:var(--ous-primary-dark); }
        .thean-ous-btn--view { --c:var(--ous-primary); --bg:#fff; --bd:var(--ous-border-strong); }
        .thean-ous-btn--edit { --c:#002b1c; --bg:#08e097; --bd:#05b97c; }
        .thean-ous-btn--pay { --c:var(--ous-primary); --bg:var(--ous-primary-light); --bd:var(--ous-border); }
        .thean-ous-btn--kho { --c:#166534; --bg:#e7f6ee; --bd:#9ed8b6; }
        .thean-ous-btn--hoan { --c:#9a3412; --bg:#fff4e5; --bd:#f5c27a; }
        .thean-ous-btn--other { --c:#334155; --bg:#f1f5f9; --bd:#cbd5e1; }
        .thean-ous-btn.is-thean-complete { opacity:.5; pointer-events:none; }

        .thean-ous-preview__head { display:flex; flex-wrap:wrap; align-items:center; gap:8px 10px; margin-right:40px; }
        .thean-ous-preview__head h2 { margin:0; font-size:20px; }
        .thean-ous-preview__head h2 small { color:#64748b; font-weight:400; font-size:13px; }
        .thean-ous-preview__meta { color:#475569; }
        .thean-ous-preview__actions { margin:12px 0; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#fafcff; }
        .thean-ous-preview__grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin:14px 0; }
        .thean-ous-preview__grid h3, .thean-ous-modal--preview h3 { margin:0 0 6px; font-size:12px; text-transform:uppercase; color:#64748b; }
        .thean-ous-preview__grid p { margin:0; line-height:1.6; overflow-wrap:anywhere; }
        .thean-ous-preview__grid dl { display:grid; grid-template-columns:auto 1fr; gap:3px 10px; margin:0; }
        .thean-ous-preview__grid dt { color:#64748b; }
        .thean-ous-preview__grid dd { margin:0; overflow-wrap:anywhere; }
        .thean-ous-preview__items-wrap { overflow-x:auto; }
        .thean-ous-preview__items { width:100%; border-collapse:collapse; font-size:13px; }
        .thean-ous-preview__items th, .thean-ous-preview__items td { padding:7px 8px; border-bottom:1px solid #e2e8f0; text-align:left; }
        .thean-ous-preview__items thead th { background:#f8fafc; }
        .thean-ous-preview__items tfoot th { text-align:right; font-weight:400; color:#475569; }
        .thean-ous-preview__note { margin:12px 0; padding:10px 12px; border-radius:8px; background:#fff8e6; }
        .thean-ous-preview__notes { margin:0 0 4px; padding:0; list-style:none; }
        .thean-ous-preview__notes li { margin-bottom:6px; padding:8px 10px; border-radius:6px; background:#f1f5f9; overflow-wrap:anywhere; }
        .thean-ous-preview__notes li.is-customer { background:var(--ous-primary-light); }
        .thean-ous-preview__notes li p { margin:0; }
        .thean-ous-preview__notes small { color:#64748b; }
        body.thean-ous-open { overflow:hidden; }

        @media (max-width:1100px) { .thean-ous__table col.c-acts { width:230px; } .thean-ous__table col.c-order { width:160px; } }
        @media (max-width:782px) {
            .thean-ous-bar { top:46px; }
            .thean-ous-bar kbd { display:none; }
            .thean-ous-bar__row { flex-wrap:wrap; }
            .thean-ous-bar__row input[type="search"] { flex-basis:calc(100% - 110px); }
            .thean-ous-bar__toggle { order:5; }
            .thean-ous-bar input[type="search"] { font-size:16px; } /* iOS không zoom */
            .thean-ous-modal { padding:0; align-items:stretch; }
            .thean-ous-modal__dialog, .thean-ous-modal--preview .thean-ous-modal__dialog { width:100%; max-height:none; padding:14px 12px; border-radius:0; }
            .thean-ous__table, .thean-ous__table tbody, .thean-ous__table tr, .thean-ous__table td { display:block; width:auto; }
            .thean-ous__table thead, .thean-ous__table colgroup { display:none; }
            .thean-ous__table tr { margin-bottom:10px; padding:10px; border:1px solid #e2e8f0; border-radius:10px; }
            .thean-ous__table td { padding:4px 0; border:0; }
            .thean-ous__table td.thean-ous__c-acts { margin-top:6px; padding-top:8px; border-top:1px dashed #e2e8f0; }
            .thean-ous-btn, .thean-ous-btn.wc-action-button { padding:6px 10px !important; font-size:13px; }
            .thean-ous-acts__label { flex-basis:100%; }
            .thean-ous-preview__grid { grid-template-columns:1fr; }
        }
    </style>

    <form class="thean-ous-bar" id="thean-ous-bar" role="search" autocomplete="off" hidden>
        <div class="thean-ous-bar__row">
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <input type="search" name="q" placeholder="Tìm đơn: ID, tên, SĐT, email, địa chỉ, SP, vận đơn… — gõ / để lọc (/tt, /sdt, /id, /sp)" aria-label="Tìm đơn hàng" aria-autocomplete="list" aria-controls="thean-ous-suggest">
            <kbd>/</kbd>
            <button type="submit" class="thean-ous-bar__submit">Tìm</button>
            <label class="thean-ous-bar__toggle" title="Ẩn mọi thông báo (notice) ở trang danh sách đơn — nhớ cho lần sau">
                <input type="checkbox" name="alerts_off"> <span>Ẩn thông báo</span><b data-thean-ous-alert-count hidden></b>
            </label>
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

    <div class="thean-ous-modal thean-ous-modal--preview" id="thean-ous-preview" hidden>
        <div class="thean-ous-modal__backdrop" data-thean-ous-close></div>
        <div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-label="Xem nhanh đơn">
            <button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng">&times;</button>
            <div class="thean-ous-modal__body"></div>
        </div>
    </div>

    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        var bar = document.getElementById('thean-ous-bar');
        var input = bar.elements.q;
        var suggest = document.getElementById('thean-ous-suggest');
        var results = document.getElementById('thean-ous-results');
        var preview = document.getElementById('thean-ous-preview');
        var searchCtl = null, previewCtl = null, timer = 0;
        var state = { q: '', page: 0, loading: false };
        var cache = new Map(); // "q|page" → {at, data}; 60s, tránh gọi lại khi mở/đóng modal
        var items = [], active = -1;
        var statusCmds = ['tt'].concat(Object.keys(cfg.aliases).filter(function (k) { return cfg.aliases[k] === 'tt'; }));

        // Đặt thanh tìm ở đầu trang danh sách, ngay dưới tiêu đề.
        var anchor = document.querySelector('.wrap .wp-header-end') || document.querySelector('.wrap h1');
        if (anchor) anchor.parentNode.insertBefore(bar, anchor.nextSibling);
        bar.hidden = false;

        function stickyTop() {
            var top = 0;
            ['#wpadminbar', '.woocommerce-layout__header'].forEach(function (sel) {
                var el = document.querySelector(sel);
                if (!el || getComputedStyle(el).position !== 'fixed') return;
                var r = el.getBoundingClientRect();
                if (r.height && r.top < 1 + top) top = Math.max(top, Math.round(r.bottom));
            });
            bar.style.top = (top + 6) + 'px';
        }
        stickyTop();
        [300, 1000, 2500].forEach(function (ms) { setTimeout(stickyTop, ms); });
        window.addEventListener('load', stickyTop);
        window.addEventListener('resize', function () { clearTimeout(stickyTop.t); stickyTop.t = setTimeout(stickyTop, 150); });
        try { var s = new URLSearchParams(location.search).get('s'); if (s) input.value = s; } catch (e) {}

        /* ---------- Ẩn thông báo (lưu user meta) ---------- */
        var alertBox = bar.elements.alerts_off;
        var alertCount = bar.querySelector('[data-thean-ous-alert-count]');
        function countAlerts() {
            var n = document.querySelectorAll('#wpbody-content .notice, #wpbody-content .update-nag').length;
            alertCount.textContent = n;
            alertCount.hidden = !(alertBox.checked && n);
            alertCount.title = n + ' thông báo đang ẩn';
        }
        alertBox.checked = !!cfg.alertsOff;
        countAlerts();
        setTimeout(countAlerts, 1500); // notice do JS thêm muộn
        alertBox.addEventListener('change', function () {
            document.body.classList.toggle('thean-ous-alerts-off', alertBox.checked);
            countAlerts();
            var fd = new FormData();
            fd.append('action', 'thean_order_usearch_alerts');
            fd.append('nonce', cfg.nonce);
            if (alertBox.checked) fd.append('off', '1');
            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
        });

        function post(action, data, ctl) {
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce', cfg.nonce);
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
            return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, signal: ctl.signal })
                .then(function (r) { return r.json(); });
        }
        function message(res, fallback) { return (res && res.data && res.data.message) || fallback; }
        function setOpen(modal, open) {
            if (modal === results && open) {
                // Chừa chỗ cho thanh tìm (đang nổi trên modal) để vẫn thấy mình gõ gì.
                results.style.paddingTop = Math.round(bar.getBoundingClientRect().bottom + 8) + 'px';
            }
            modal.hidden = !open;
            document.body.classList.toggle('thean-ous-open', !results.hidden || !preview.hidden);
        }
        function body(modal) { return modal.querySelector('.thean-ous-modal__body'); }
        function notice(el, text) {
            var p = document.createElement('p');
            p.className = 'thean-ous__loading';
            p.textContent = text;
            el.replaceChildren(p);
        }
        function norm(str) {
            return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        }

        /* ---------- Gợi ý lệnh ---------- */
        function closeSuggest() { suggest.hidden = true; items = []; active = -1; input.setAttribute('aria-expanded', 'false'); }
        function renderSuggest(head, list) {
            items = list; active = list.length ? 0 : -1;
            if (!list.length) { closeSuggest(); return; }
            suggest.replaceChildren();
            var h = document.createElement('div'); h.className = 'thean-ous-suggest__head'; h.textContent = head; suggest.appendChild(h);
            list.forEach(function (it, i) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'thean-ous-suggest__item' + (i === 0 ? ' is-active' : ''); b.setAttribute('role', 'option');
                var c = document.createElement('code'); c.textContent = it.code;
                var t = document.createElement('span'); t.textContent = it.text;
                b.append(c, t);
                b.addEventListener('mousedown', function (e) { e.preventDefault(); apply(i); });
                suggest.appendChild(b);
            });
            suggest.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
        function moveActive(step) {
            if (!items.length) return;
            active = (active + step + items.length) % items.length;
            suggest.querySelectorAll('.thean-ous-suggest__item').forEach(function (el, i) {
                el.classList.toggle('is-active', i === active);
                if (i === active) el.scrollIntoView({ block: 'nearest' });
            });
        }
        function apply(i) {
            var it = items[i]; if (!it) return;
            var pos = input.selectionStart, before = input.value.slice(0, pos), after = input.value.slice(pos);
            before = before.slice(0, before.length - it.replace.length) + it.insert;
            input.value = before + after.replace(/^\S*/, '');
            input.setSelectionRange(before.length, before.length);
            input.focus();
            updateSuggest();
            if (it.run) schedule(0);
        }
        function updateSuggest() {
            var before = input.value.slice(0, input.selectionStart);
            var m = before.match(/(?:^|\s)\/([a-z]+)\s+(\S*)$/i);
            if (m && statusCmds.indexOf(m[1].toLowerCase()) !== -1) {
                var n = norm(m[2]);
                renderSuggest('Trạng thái', cfg.statuses.filter(function (s) {
                    return !n || s.slug.indexOf(n) !== -1 || s.norm.indexOf(n) !== -1;
                }).map(function (s) { return { code: s.slug, text: s.name, replace: m[2], insert: s.slug + ' ', run: true }; }));
                return;
            }
            var c = before.match(/(?:^|\s)(\/[a-z]*)$/i);
            if (c || input.value.trim() === '') {
                var typed = c ? c[1].slice(1).toLowerCase() : '';
                renderSuggest('Lệnh lọc — kết hợp được nhiều lệnh', cfg.commands.filter(function (cmd) {
                    return cmd.cmd.indexOf(typed) === 0;
                }).map(function (cmd) { return { code: '/' + cmd.cmd, text: cmd.label + ' — ' + cmd.desc + ' (vd: ' + cmd.example + ')', replace: c ? c[1] : '', insert: '/' + cmd.cmd + ' ' }; }));
                return;
            }
            closeSuggest();
        }

        /* ---------- Tìm + phân trang ---------- */
        function ready(q) {
            if (q.length < 3) return false;
            // Lệnh chưa có tham số ("/sp", "/tt ") → chờ gõ tiếp.
            return !/(?:^|\s)\/[a-z]*\s*$/i.test(q);
        }
        function schedule(delay) {
            clearTimeout(timer);
            var q = input.value.trim();
            if (!ready(q)) return;
            timer = setTimeout(function () { search(q, 1); }, delay);
        }
        function shell() {
            var wrap = document.createElement('div');
            wrap.innerHTML = '<table class="thean-ous__table"><colgroup><col class="c-order"><col class="c-cus"><col class="c-items"><col class="c-acts"></colgroup>'
                + '<thead><tr><th>Đơn · Trạng thái · Tổng</th><th>Khách hàng · Giao hàng</th><th>Sản phẩm</th><th>Thao tác</th></tr></thead><tbody></tbody></table>'
                + '<div class="thean-ous__more" hidden><button type="button" class="thean-ous__more-btn" data-thean-ous-more></button></div>';
            body(results).replaceChildren(wrap);
            return wrap;
        }
        function render(data, page) {
            var status = results.querySelector('.thean-ous-modal__status');
            var chips = results.querySelector('.thean-ous-chips');
            chips.replaceChildren();
            (data.summary || []).forEach(function (txt) { var s = document.createElement('span'); s.textContent = txt; chips.appendChild(s); });
            status.textContent = '— ' + (data.capped ? cfg.cap + '+' : data.total) + ' đơn' + (data.total ? ', đang hiện ' + data.shown : '');
            if (!data.total) { notice(body(results), 'Không tìm thấy đơn nào khớp.'); return; }
            var tbody = page === 1 ? shell().querySelector('tbody') : body(results).querySelector('tbody');
            tbody.insertAdjacentHTML('beforeend', data.rows);
            var more = body(results).querySelector('.thean-ous__more');
            more.hidden = !data.has_more;
            if (data.has_more) more.querySelector('button').textContent = 'Xem thêm ' + Math.min(cfg.perPage, data.total - data.shown) + ' đơn (còn ' + (data.total - data.shown) + ')';
        }
        function search(q, page) {
            var key = q + '|' + page, hit = cache.get(key);
            if (page === 1 && q === state.q && state.page >= 1 && !results.hidden) return;
            if (searchCtl) searchCtl.abort();
            state.q = q;
            setOpen(results, true);
            if (hit && Date.now() - hit.at < 60000) { state.page = page; render(hit.data, page); return; }
            searchCtl = new AbortController();
            state.loading = true;
            var moreBtn = body(results).querySelector('[data-thean-ous-more]');
            if (page === 1) { results.querySelector('.thean-ous-modal__status').textContent = '— đang tìm…'; notice(body(results), 'Đang tìm “' + q + '”…'); }
            else if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Đang tải…'; }
            post('thean_order_usearch', { q: q, page: page }, searchCtl).then(function (res) {
                if (!res.success) throw new Error(message(res, 'Không tìm được.'));
                cache.set(key, { at: Date.now(), data: res.data });
                state.page = page;
                render(res.data, page);
            }).catch(function (err) {
                if (err.name === 'AbortError') return;
                state.page = 0;
                results.querySelector('.thean-ous-modal__status').textContent = '';
                results.querySelector('.thean-ous-chips').replaceChildren();
                notice(body(results), err.message || 'Lỗi kết nối.');
            }).finally(function () { state.loading = false; if (moreBtn) moreBtn.disabled = false; });
        }

        function openPreview(orderId) {
            if (previewCtl) previewCtl.abort();
            previewCtl = new AbortController();
            notice(body(preview), 'Đang tải đơn #' + orderId + '…');
            setOpen(preview, true);
            post('thean_order_usearch_preview', { order_id: orderId }, previewCtl).then(function (res) {
                if (!res.success) throw new Error(message(res, 'Không tải được đơn.'));
                body(preview).innerHTML = res.data.html;
            }).catch(function (err) {
                if (err.name !== 'AbortError') notice(body(preview), err.message || 'Lỗi kết nối.');
            });
        }

        bar.addEventListener('submit', function (e) {
            e.preventDefault();
            clearTimeout(timer);
            closeSuggest();
            var q = input.value.trim();
            if (q.length < 2) return;
            cache.forEach(function (v, k) { if (k.indexOf(q + '|') === 0) cache.delete(k); }); // Enter = tải mới
            state.page = 0;
            search(q, 1);
        });
        input.addEventListener('input', function () { updateSuggest(); schedule(600); });
        input.addEventListener('click', updateSuggest);
        input.addEventListener('focus', function () {
            updateSuggest();
            if (input.value.trim() === state.q && state.page && results.hidden) setOpen(results, true);
        });
        input.addEventListener('blur', function () { setTimeout(closeSuggest, 120); });
        input.addEventListener('keydown', function (e) {
            if (suggest.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
            else if ((e.key === 'Enter' || e.key === 'Tab') && active >= 0) { e.preventDefault(); apply(active); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSuggest(); }
        });

        results.addEventListener('click', function (e) {
            if (e.target.closest('[data-thean-ous-more]')) { if (!state.loading) search(state.q, state.page + 1); return; }
            var btn = e.target.closest('[data-thean-ous-preview]');
            if (btn) { e.preventDefault(); openPreview(btn.getAttribute('data-thean-ous-preview')); return; }
            if (e.target.closest('a, button, input, select, textarea')) return;
            var row = e.target.closest('tr[data-order-id]');
            if (row) openPreview(row.getAttribute('data-order-id'));
        });
        results.addEventListener('keydown', function (e) {
            var row = e.target.closest('tr[data-order-id]');
            if (row && e.target === row && e.key === 'Enter') openPreview(row.getAttribute('data-order-id'));
        });

        document.addEventListener('click', function (e) {
            var close = e.target.closest('[data-thean-ous-close]');
            if (close) setOpen(close.closest('.thean-ous-modal'), false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (!suggest.hidden) return; // ô gợi ý tự đóng (handler của input)
                // Modal thao tác nhanh (orders-admin.php) nằm trên cùng — để nó tự đóng trước.
                if (document.querySelector('.hithean-order-modal:not([hidden]), .hithean-return-modal:not([hidden])')) return;
                if (!preview.hidden) setOpen(preview, false);
                else if (!results.hidden) setOpen(results, false);
                return;
            }
            var tag = (e.target.tagName || '').toLowerCase();
            if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(input|textarea|select)$/.test(tag) && !e.target.isContentEditable) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        }, true); // capture: chạy trước handler Escape của modal thao tác nhanh
    }());
    </script>
    <?php
}

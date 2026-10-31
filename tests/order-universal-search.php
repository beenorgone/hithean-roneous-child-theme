<?php
/**
 * Run with php tests/order-universal-search.php. No WordPress bootstrap needed —
 * tests helper tìm đơn nhanh + câu SQL ở cả HPOS lẫn lưu trữ cũ (wpdb giả).
 */
namespace Automattic\WooCommerce\Utilities {
    class OrderUtil
    {
        public static bool $hpos = false;
        public static function custom_orders_table_usage_is_enabled(): bool { return self::$hpos; }
    }
}

namespace {
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
function add_action(...$a) {}
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function get_user_meta() { return ''; }
function add_filter(...$a) {}
$GLOBALS['__transients'] = [];
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function apply_filters($tag, $value) { return $value; }
$GLOBALS['__options'] = [];
function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
$GLOBALS['__caps'] = []; $GLOBALS['__uid'] = 0;
function current_user_can($cap) { return in_array($cap, $GLOBALS['__caps'], true); }
function get_current_user_id() { return $GLOBALS['__uid']; }
function esc_sql($s) { return addslashes($s); }
function remove_accents($s)
{
    // Đủ cho tên trạng thái trong test (không cần ext-intl).
    $from = ['à','á','ả','ã','ạ','ă','ằ','ắ','ẳ','ẵ','ặ','â','ầ','ấ','ẩ','ẫ','ậ','è','é','ẻ','ẽ','ẹ','ê','ề','ế','ể','ễ','ệ','ì','í','ỉ','ĩ','ị','ò','ó','ỏ','õ','ọ','ô','ồ','ố','ổ','ỗ','ộ','ơ','ờ','ớ','ở','ỡ','ợ','ù','ú','ủ','ũ','ụ','ư','ừ','ứ','ử','ữ','ự','ỳ','ý','ỷ','ỹ','ỵ','đ','Đ'];
    $to   = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d','D'];
    return str_replace($from, $to, $s);
}
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function wc_get_order_statuses() { return ['wc-pending' => 'Chờ thanh toán', 'wc-processing' => 'Đang xử lý', 'wc-cancelled' => 'Đã hủy', 'wc-da-ghi-nhan' => 'Đã ghi nhận']; }
function thean_change_woocommerce_order_number($order_id)
{
    $nums1 = str_split($order_id, 2);
    $nums2 = str_split(strrev($order_id), 2);
    return $order_id . ceil(($nums1[0] + $nums2[0]) / 2);
}

class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public array $queries = [];
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($query, ...$args)
    {
        if (isset($args[0]) && is_array($args[0])) $args = $args[0];
        if (substr_count($query, '%s') !== count($args)) throw new RuntimeException('placeholder mismatch: ' . $query);
        if (preg_match('/%(?!s)/', str_replace('%s', '', $query))) throw new RuntimeException('stray % in prepare: ' . $query);
        return vsprintf(str_replace('%s', "'%s'", $query), array_map('addslashes', $args));
    }
    public function get_col($sql)
    {
        $this->queries[] = $sql;
        // Lọc cuối (IN (...)) trả lại đúng danh sách ứng viên, mới nhất trước.
        if (preg_match('/\b(?:id|ID) IN \(([\d,]+)\)/', $sql, $m)) {
            $ids = array_map('intval', explode(',', $m[1]));
            rsort($ids);
            return $ids;
        }
        if (strpos($sql, 'order_item_name') !== false) return ['300', '120'];
        return [];
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-legacy-meta-sync.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-ops-step.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-universal-search.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-vat-request.php';

$checks = 0;
function check($cond, string $msg): void
{
    global $checks;
    $checks++;
    if (!$cond) throw new RuntimeException('FAIL: ' . $msg);
}

// Mã đơn hiển thị = ID + hậu tố → nhận lại ID.
$display = thean_change_woocommerce_order_number('10276');
check(in_array(10276, thean_ous_candidate_ids($display), true), 'display number maps back to ID');
check(thean_ous_candidate_ids('#10276**') === [10276], 'masked ID');
check(thean_ous_candidate_ids('Nguyen') === [], 'text is not an ID');

// SĐT.
check(thean_ous_phone_needle('0901 234 567') === '901234567', 'phone strips 0 and spaces');
check(thean_ous_phone_needle('+84 901234567') === '901234567', 'phone strips +84');
check(thean_ous_phone_needle('12345') === '', 'too short for phone');
check(thean_ous_phone_needle('Q1 0901234567') === '', 'mixed text not phone');

// Highlight: escape trước, đánh dấu không phân biệt hoa thường (unicode).
check(thean_ous_highlight('Nguyễn <b>Văn</b>', ['văn']) === 'Nguyễn &lt;b&gt;<mark>Văn</mark>&lt;/b&gt;', 'highlight escapes + marks');
check(thean_ous_tokens('  Whey   gold whey ') === ['Whey', 'gold', 'whey'], 'tokens split');

// Lệnh lọc.
$f = thean_ous_parse_query('/tt dang-xu-ly nguyen /sp whey gold /sdt 0901 234');
check($f['statuses'] === ['processing'], 'status by accent-free name');
check($f['text'] === 'nguyen', 'words after /tt status go back to free text');
check($f['product'] === 'whey gold' && $f['phone'] === '0901 234', 'multi-word args');
check(thean_ous_validate($f) === '', 'valid combined query');
check(thean_ous_parse_query('/tt huy')['statuses'] === ['cancelled'], 'status partial match');
check(thean_ous_parse_query('/status wc-processing /tt pending')['statuses'] === ['processing', 'pending'], 'alias + wc- prefix + repeat = OR');
check(thean_ous_validate(thean_ous_parse_query('/tt xyz')) !== '', 'unknown status errors');
check(thean_ous_parse_query('/abc def')['text'] === '/abc def', 'unknown command stays free text');
check(thean_ous_parse_query('/id 90433 thêm')['id'] === '90433', '/id takes one word');
check(thean_ous_validate(thean_ous_parse_query('ab')) !== '', 'free text needs 3 chars');
check(thean_ous_validate(thean_ous_parse_query('/sdt 12')) !== '', 'phone needs 4 digits');
check(thean_ous_validate(thean_ous_parse_query('/sp ')) !== '', 'empty command rejected');

$empty = thean_ous_parse_query('');

// Legacy.
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
$wpdb->queries = [];
$ids = thean_ous_find_order_ids(array_merge($empty, ['text' => 'Whey Gold']));
check($ids === [300, 120], 'legacy returns filtered ids newest first');
$sql = implode("\n", $wpdb->queries);
check(strpos($sql, '_billing_address_index') !== false && strpos($sql, 'wp_posts') !== false, 'legacy uses postmeta/posts');
check(strpos($sql, 'wc_orders') === false, 'legacy does not touch HPOS tables');
check(strpos($sql, "meta_value LIKE '%Whey%' AND meta_value LIKE '%Gold%'") !== false, 'legacy token AND');

// HPOS.
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
$wpdb->queries = [];
$ids = thean_ous_find_order_ids(array_merge($empty, ['text' => '0901 234 567']));
$sql = implode("\n", $wpdb->queries);
check(strpos($sql, 'wp_wc_order_addresses') !== false && strpos($sql, 'wp_wc_orders_meta') !== false, 'HPOS tables used');
check(strpos($sql, 'wp_postmeta') === false, 'HPOS does not touch postmeta');
check(strpos($sql, "'order_ship_code'") !== false && strpos($sql, "'order_shipper'") !== false, 'hithean order_* tracking meta searched');
check(strpos($sql, "LIKE '%901234567%'") !== false, 'HPOS phone normalized');
check(strpos($sql, "type = 'shop_order'") !== false, 'HPOS filters order type');

// Chỉ trạng thái → 1 truy vấn trên bảng đơn, không IN (...).
$wpdb->queries = [];
thean_ous_find_order_ids(array_merge($empty, ['statuses' => ['processing']]));
check(count($wpdb->queries) === 1 && strpos($wpdb->queries[0], "status IN ('wc-processing')") !== false, 'status-only query');

// SP + trạng thái: giao tập SP với lọc trạng thái.
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
$wpdb->queries = [];
thean_ous_find_order_ids(array_merge($empty, ['product' => 'whey', 'statuses' => ['processing']]));
$last = end($wpdb->queries);
check(strpos($last, 'ID IN (300,120)') !== false && strpos($last, "post_status IN ('wc-processing')") !== false, 'product AND status (legacy)');

// Giới hạn: 4 lượt tìm thật / 30s, lượt thứ 5 khóa 2 phút.
$t = 1000;
check(thean_ous_rate_hit(7, $t) === 0 && thean_ous_rate_hit(7, $t + 5) === 0 && thean_ous_rate_hit(7, $t + 10) === 0 && thean_ous_rate_hit(7, $t + 15) === 0, '4 searches allowed');
check(thean_ous_rate_hit(7, $t + 20) === 120, '5th within 30s blocks 120s');
check(thean_ous_rate_blocked(7, $t + 80) === 60, 'still blocked after 60s');
check(thean_ous_rate_hit(7, $t + 80) === 60, 'hits while blocked do not extend block');
check(thean_ous_rate_blocked(7, $t + 141) === 0 && thean_ous_rate_hit(7, $t + 141) === 0, 'unblocked after 2 minutes, counter reset');
check(thean_ous_rate_hit(8, $t) === 0, 'per-user');
$t = 5000;
foreach ([0, 10, 20, 31] as $d) thean_ous_rate_hit(9, $t + $d);
check(thean_ous_rate_hit(9, $t + 35) === 0, 'old hits fall out of the 30s window');

// Bước vận hành (order-ops-step.php): bước tiếp theo của thean_ow_flow → tab board.
check(thean_ops_step_from_flow(['next_goto' => 'picking']) === 'picking', 'next step → tab');
check(thean_ops_step_from_flow(['next_goto' => '']) === '', 'nothing to do → no tab');
check(thean_ops_step_from_flow(['next_goto' => 'payment', 'is_cod' => true]) === 'cod', 'COD delivered unpaid → Thu COD');
check(thean_ops_step_from_flow(['next_goto' => 'payment', 'is_cod' => false]) === 'payment', 'bank transfer unpaid → Chờ thanh toán');
check(thean_ops_step_from_flow(['next_goto' => 'delivery', 'is_shipping' => true]) === 'shipping', 'shipping → Đang giao');
check(thean_ops_step_from_flow(['next_goto' => 'delivery']) === 'delivery', 'not shipped → Chờ giao');
check(thean_ops_step_from_flow(['next_goto' => 'picking', 'is_closed' => true]) === '', 'closed order skipped');
check(thean_ops_step_from_flow(['next_goto' => 'return', 'is_closed' => true]) === 'return', 'closed order with pending return kept');
check(thean_ops_step_from_flow(['next_goto' => 'picking'], 'chua-ghi-nhan') === '', 'Chưa ghi nhận not in ops flow');
check(thean_ops_step_from_flow(['next_goto' => 'preorder']) === 'preorder', 'pre-order status → tab Đặt trước');
check(thean_ops_step_from_flow(['next_goto' => 'preorder', 'preorder_confirm' => 'pending']) === 'confirm', 'resumed pre-order → Xác nhận khách');
check(thean_ops_step_from_flow(['next_goto' => 'preorder', 'preorder_confirm' => 'waiting']) === 'confirm', 'waiting customer reply stays in Xác nhận khách');
// Tab "Thiếu chứng từ XK" theo cờ (đơn đang giao thiếu chứng từ ở cả tab Đang giao lẫn Thiếu chứng từ XK).
check(thean_ous_board_in_tab(['step' => 'shipping', 'proof' => true], 'shipping') && thean_ous_board_in_tab(['step' => 'shipping', 'proof' => true], 'proof'), 'shipping + missing proof → both tabs');
check(!thean_ous_board_in_tab(['step' => 'shipping', 'proof' => true], 'picking') && !thean_ous_board_in_tab(['step' => 'shipping', 'proof' => false], 'proof'), 'not in picking; no proof flag → not in proof tab');
check(strpos(thean_ous_board_tab_sql('proof'), "meta_key = '_thean_ops_proof' AND pf.meta_value = '1'") !== false && thean_ous_board_tab_sql('shipping') === "s.meta_value = 'shipping'", 'tab SQL: proof by flag, others by step');
check(thean_ous_ship_code_short('S12734516.BO.SGP120-V44.1363613511') === '1363613511' && thean_ous_ship_code_short('S12734516.MB10-12-L17.1615456041') === '1615456041', 'GHTK code → last segment');
check(thean_ous_ship_code_short('ahamove 26OU3IQB') === 'ahamove 26OU3IQB' && thean_ous_ship_code_short('') === '', 'non-GHTK code unchanged');
// HPOS: khôi phục chứng từ xuất kho bị Meta Box xóa (order-legacy-meta-sync.php).
check(thean_export_proof_image_file('order-export-image-10786-20261031-091800-1.jpg', 10786) && thean_export_proof_image_file('xuat-kho-lo-ab12-3.jpg', 10786)
    && !thean_export_proof_image_file('order-export-image-10787-20261031-1.jpg', 10786) && !thean_export_proof_image_file('return-10786-1.jpg', 10786), 'export proof image filenames');
check(thean_export_confirm_name_from_note('✅ Đã xác nhận xuất kho bởi Tuan') === 'Tuan' && thean_export_confirm_name_from_note('Đã xác nhận ảnh xuất kho bởi Kho 1') === 'Kho 1'
    && thean_export_confirm_name_from_note('Đã xác nhận thanh toán.') === '', 'confirm name from note');
check(thean_legacy_meta_empty('  ') && thean_legacy_meta_empty([]) && thean_legacy_meta_empty(null) && !thean_legacy_meta_empty('0') && !thean_legacy_meta_empty([1]), 'legacy meta empty');
check(in_array('warehouse_export_images', thean_legacy_meta_keys(), true) && in_array('export_confirmed_by', thean_legacy_meta_keys(), true), 'legacy meta keys');
check(thean_ops_shipping_statuses() === ['shipping', 'local-shipping', 'partial-shipping'], 'shipping statuses');
check(preg_match("/pf\.meta_value = '1'\) AND o\.(date_created_gmt|post_date_gmt) >= '\d{4}-/", thean_ous_board_tab_sql('proof'))
    && strpos(thean_ous_board_sql(), "s.meta_value <> 'proof' OR o.") !== false, 'proof tab: only orders within THEAN_OPS_PROOF_DAYS (stale flags not counted)');
check(isset(thean_ops_step_tabs()['proof']) && thean_ops_proof_statuses() === ['delivered', 'partial-shipped', 'completed'], 'tab Thiếu chứng từ XK');
check(array_key_last(thean_ops_step_tabs()) === 'preorder' && thean_ops_step_tabs()['confirm'] === 'Xác nhận khách', 'tabs: Đặt trước + Xác nhận khách');
check(thean_ops_step_from_flow(['next_goto' => 'payment'], 'da-ghi-nhan') === '', 'Đã ghi nhận not in ops flow');
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $q = thean_ous_board_sql();
    check(strpos($q, $hpos ? 'wp_wc_orders_meta' : 'wp_postmeta') !== false && strpos($q, "'_thean_ops_step'") !== false
        && strpos($q, "'wc-chua-ghi-nhan'") !== false && strpos($q, '_spx_date') !== false
        && strpos($q, "s.meta_value IN ('return', 'proof')") !== false, 'board SQL ' . ($hpos ? 'HPOS' : 'legacy'));
    check(strpos($q, '//') === false && preg_match("/o\\.(date_created_gmt|post_date_gmt) >= '\\d{4}-/", $q), 'board SQL: no stray comment, skips orders older than 365 days');
    $wpdb->queries = [];
    thean_ops_step_unsynced_ids(20);
    check(strpos($wpdb->queries[0], "'_thean_ops_step_v' AND a.meta_value = '" . THEAN_OPS_STEP_VERSION . "'") !== false
        && strpos($wpdb->queries[0], "IN ('wc-delivered','wc-partial-shipped','wc-completed') AND o.") !== false && strpos($wpdb->queries[0], 'LIMIT 20') !== false, 'unsynced = no meta for current version ' . ($hpos ? 'HPOS' : 'legacy'));
}

// Đơn nội bộ: domain email (Cài đặt ERP) / cờ _eim_internal → không vào luồng vận hành.
check(thean_parse_email_domains("@TheAn.com, ivar.vn;  bad  x@y\nsub.ivar.vn") === ['thean.com', 'ivar.vn', 'sub.ivar.vn'], 'parse domains');
check(thean_email_is_internal('kho@thean.com', ['thean.com']) && thean_email_is_internal('Kho@TheAn.com ', ['thean.com']), 'internal email: exact domain');
check(!thean_email_is_internal('a@hn.thean.com', ['thean.com']) && thean_email_is_internal('a@hn.thean.com', ['thean.com', 'hn.thean.com']), 'subdomain not internal unless listed');
check(!thean_email_is_internal('khach@an.com', ['thean.com']) && !thean_email_is_internal('a@notthean.com', ['thean.com']) && !thean_email_is_internal('', ['thean.com']), 'no partial-domain match');
$GLOBALS['__options']['thean_internal_email_domains'] = 'thean.com';
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $q = thean_ous_board_sql();
    check(strpos($q, "'_eim_internal'") !== false && strpos($q, "LIKE '%@thean.com'") !== false && strpos($q, "LIKE '%.thean.com'") === false
        && strpos($q, $hpos ? 'COALESCE(o.billing_email' : "meta_key = '_billing_email'") !== false, 'board skips internal orders ' . ($hpos ? 'HPOS' : 'legacy'));
}
$GLOBALS['__options']['thean_internal_domains_off'] = '1';
check(thean_internal_email_domains() === [] && strpos(thean_ous_board_sql(), 'LIKE') === false && strpos(thean_ous_board_sql(), "'_eim_internal'") !== false,
    'internal domains switched off: no domain filter, _eim_internal flag still excluded');
unset($GLOBALS['__options']['thean_internal_domains_off']);
unset($GLOBALS['__options']['thean_internal_email_domains']);

// Quyền: đổi trạng thái tùy ý (người chỉ định / mặc định Administrator), bỏ qua thiếu chứng từ (chỉ Administrator).
$GLOBALS['__caps'] = ['manage_woocommerce']; $GLOBALS['__uid'] = 5;
check(!thean_can_change_any_status() && !thean_can_skip_export_proof(), 'shop manager: no arbitrary status / skip by default');
$GLOBALS['__caps'] = ['manage_woocommerce', 'manage_options'];
check(thean_can_change_any_status() && thean_can_skip_export_proof(), 'admin allowed when no one is designated');
$GLOBALS['__options']['thean_status_change_users'] = [7];
check(!thean_can_change_any_status(), 'admin not designated → no arbitrary status');
$GLOBALS['__caps'] = ['manage_woocommerce']; $GLOBALS['__uid'] = 7;
check(thean_can_change_any_status() && !thean_can_skip_export_proof(), 'designated shop manager can change status, still cannot skip proof');
unset($GLOBALS['__options']['thean_status_change_users']); $GLOBALS['__caps'] = []; $GLOBALS['__uid'] = 0;

// Nhập VAT: bóc JSON trả lời của AI.
$ai = thean_vat_request_parse_ai("Đây là kết quả:\n```json\n{\"tax_code\":\"MST: 0312 345 678\",\"company\":\" Công ty TNHH An \",\"address\":\"12 Lê Lợi, Q1\",\"email\":\"ketoan@an.vn\",\"note\":\"\"}\n```");
check($ai['tax_code'] === '0312345678' && $ai['company'] === 'Công ty TNHH An' && $ai['email'] === 'ketoan@an.vn', 'AI JSON in code fence, MST cleaned');
check(thean_vat_request_parse_ai('Không đọc được ảnh') === [], 'no JSON → empty');
check(thean_vat_request_parse_ai('{"tax_code":"0101234567-001"}')['tax_code'] === '0101234567-001', 'MST 13 số giữ dấu -');

echo "OK ({$checks} checks)\n";
}

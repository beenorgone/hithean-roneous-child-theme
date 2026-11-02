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
define('ARRAY_A', 'ARRAY_A');
function add_action($hook, $callback, ...$a) { $GLOBALS['__actions'][$hook][] = $callback; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function get_user_meta() { return ''; }
function add_filter(...$a) {}
$GLOBALS['__transients'] = [];
function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; $GLOBALS['__ttls'][$k] = $ttl; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }
function apply_filters($tag, $value) { return $value; }
$GLOBALS['__options'] = [];
function get_option($k, $d = false) { return $GLOBALS['__options'][$k] ?? $d; }
$GLOBALS['__caps'] = []; $GLOBALS['__uid'] = 0;
function current_user_can($cap) { return in_array($cap, $GLOBALS['__caps'], true); }
function get_current_user_id() { return $GLOBALS['__uid']; }
function esc_sql($s) { return addslashes($s); }
function update_option($k, $v, $autoload = null) { $GLOBALS['__options'][$k] = $v; return true; }
function wp_json_encode($v) { return json_encode($v); }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
function absint($v) { return abs((int) $v); }
function wp_date($format) { return gmdate($format); }
$GLOBALS['__scheduled'] = [];
function wp_next_scheduled($hook) { return $GLOBALS['__scheduled'][$hook] ?? false; }
function wp_schedule_single_event($at, $hook) {
    $GLOBALS['__schedule_calls'] = ($GLOBALS['__schedule_calls'] ?? 0) + 1;
    if (!empty($GLOBALS['__schedule_fail'])) return false;
    $GLOBALS['__scheduled'][$hook] = $at;
    return true;
}
function wc_get_orders($args) { $GLOBALS['__order_queries'][] = $args; return []; }
class JsonResponse extends RuntimeException {
    public array $data;
    public bool $success;
    public function __construct($data, $success) { $this->data = $data; $this->success = $success; }
}
function check_ajax_referer(...$a) {
    $GLOBALS['__nonce_checks'] = ($GLOBALS['__nonce_checks'] ?? 0) + 1;
    if (!empty($GLOBALS['__bad_nonce'])) throw new RuntimeException('Invalid nonce');
}
function wp_send_json_success($data) { throw new JsonResponse($data, true); }
function wp_send_json_error($data, $status = null) { throw new JsonResponse($data, false); }

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

class WC_Order {
    public array $meta = [];
    public string $email = '';
    public ?DateTimeImmutable $created = null;
    public function get_date_created() { return $this->created; }
    public function get_type() { return 'shop_order'; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function get_billing_email() { return $this->email; }
}

class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public array $queries = [];
    public array $board_counts = [];
    public array $board_ids = [];
    public array $unsynced_ids = [];
    public int $proof_count = 0;
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($query, ...$args)
    {
        if (isset($args[0]) && is_array($args[0])) $args = $args[0];
        $i = 0;
        $out = preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            if (!array_key_exists($i, $args)) throw new RuntimeException('missing placeholder argument');
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes($v) . "'";
        }, $query);
        if ($i !== count($args)) throw new RuntimeException('unused placeholder arguments');
        return $out;
    }
    public function get_results($sql, $format) {
        $this->queries[] = $sql;
        return $this->board_counts;
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        return $this->proof_count;
    }
    public function get_col($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'AND a.meta_value') !== false) return $this->unsynced_ids;
        if (strpos($sql, 'SELECT DISTINCT o.') !== false) return $this->board_ids;
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
$__ai = thean_export_ai_result_from_note("🤖 AI review ảnh xuất kho\nKết quả: Khớp — vẫn cần nhân viên xác nhận.\nMã đơn/phiếu: Khớp (khớp theo mã che chuẩn)\nSản phẩm và số lượng: Khớp\nĐộ tin cậy: high\nAI đọc được: #10786**\nNhận định: Phiếu rõ\nMã vận đơn đọc được: S1.A.123, 456");
check($__ai['overall'] === 'pass' && $__ai['order_match'] === 'match' && $__ai['items_match'] === 'match' && $__ai['confidence'] === 'high'
    && $__ai['masked_order_match'] && $__ai['visible_order_reference'] === '#10786**' && $__ai['shipping_codes'] === ['S1.A.123', '456'], 'AI result from note: pass');
$__ai = thean_export_ai_result_from_note("AI review ảnh xuất kho\nKết quả: Cần kiểm tra thủ công.\nMã đơn/phiếu: Không khớp\nSản phẩm và số lượng: Chưa đủ bằng chứng\nĐộ tin cậy: low\nCần xem lại: thiếu A; thừa B");
check($__ai['overall'] === 'review' && $__ai['order_match'] === 'mismatch' && $__ai['items_match'] === 'unclear' && $__ai['missing_or_suspected_missing'] === ['thiếu A', 'thừa B'], 'AI result from note: review');
check(thean_export_ai_result_from_note('AI review ảnh xuất kho đã được đưa vào hàng đợi.') === null && thean_export_ai_result_from_note('Đã xác nhận xuất kho bởi Tuan') === null, 'non-result notes ignored');
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

// Tái dùng flow phải giữ gating hiển thị, trong khi bước lưu sẵn vẫn không gated.
$order = new WC_Order();
$order->created = new DateTimeImmutable();
check(!thean_ops_step_excluded($order), 'recent customer order eligible for board');
$order->meta['_eim_internal'] = '1';
check(thean_ops_step_excluded($order) && !thean_ops_step_excluded($order, false), 'internal gating preserved when reusing stored flow');
$order->meta = []; $order->created = new DateTimeImmutable('-400 days');
check(thean_ops_step_excluded($order) && !thean_ops_step_excluded($order, false), 'old-order gating preserved when reusing stored flow');
$order->meta['_spx_date'] = '2026-01-01';
check(thean_ops_step_excluded($order, false), 'merged Shopee order excluded even without age/internal gating');

// Board: cache rỗng cũng phải là cache hit, tách kho lưu trữ / cấu hình / tab / giới hạn.
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    thean_ops_board_invalidate();
    $wpdb->queries = [];
    $wpdb->board_counts = [['tab' => 'picking', 'n' => '25']];
    $wpdb->proof_count = 3;
    $counts = thean_ous_board_counts();
    check($counts['picking'] === 25 && $counts['proof'] === 3, 'counts + proof count cached');
    thean_ous_board_counts();
    check(count($wpdb->queries) === 2, 'second count request skips SQL');
    $key = thean_ous_board_cache_key('counts');
    check($GLOBALS['__ttls'][$key] === 10, 'counts expire after 10 seconds');
    $wpdb->board_ids = [901, 900];
    $wpdb->queries = [];
    check(thean_ous_board_page_ids('picking', 0, 10) === [901, 900], 'first page IDs');
    thean_ous_board_page_ids('picking', 0, 10);
    check(count($wpdb->queries) === 1, 'first page ID cache hit');
    thean_ous_board_page_ids('shipping', 0, 10);
    thean_ous_board_page_ids('picking', 0, 20);
    check(count($wpdb->queries) === 3, 'ID cache separated by tab and limit');
    thean_ous_board_page_ids('picking', 10, 20);
    thean_ous_board_page_ids('picking', 10, 20);
    check(count($wpdb->queries) === 5, 'load more uses live offset query');
    thean_ops_board_invalidate();
    check(thean_ous_board_cache_key('counts') !== $key, 'step update invalidates cache generation');
    $wpdb->board_counts = []; $wpdb->board_ids = []; $wpdb->proof_count = 0;
    $wpdb->queries = [];
    thean_ous_board_counts(); thean_ous_board_counts();
    thean_ous_board_page_ids('picking', 0, 10); thean_ous_board_page_ids('picking', 0, 10);
    check(count($wpdb->queries) === 3, 'empty counts and IDs cached');
    $key = thean_ous_board_cache_key('counts');
    $GLOBALS['__options']['thean_internal_email_domains'] = 'private.example';
    check(thean_ous_board_cache_key('counts') !== $key, 'domain settings change cache scope');
    unset($GLOBALS['__options']['thean_internal_email_domains']);
}
$key = thean_ous_board_cache_key('counts');
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
check(thean_ous_board_cache_key('counts') !== $key, 'HPOS and legacy caches are separate');
$key = thean_ous_board_cache_key('counts');
thean_ops_step_queue(123);
check(thean_ous_board_cache_key('counts') !== $key, 'queued order save invalidates counts/IDs');

// Backfill: enqueue only; failures remain retryable and repeated opens share one event.
$wpdb->queries = [];
$GLOBALS['__schedule_fail'] = true;
check(!thean_ops_step_request_backfill() && !get_transient('thean_ops_backfill_checked'), 'failed scheduling remains retryable');
$GLOBALS['__schedule_fail'] = false;
check(thean_ops_step_request_backfill(), 'background backfill scheduled');
$calls = $GLOBALS['__schedule_calls'];
check(thean_ops_step_request_backfill() && $GLOBALS['__schedule_calls'] === $calls && !$wpdb->queries, 'opening board does not scan orders or duplicate job');
unset($GLOBALS['__scheduled']['thean_ops_step_backfill']);
foreach ($GLOBALS['__actions']['thean_ops_step_backfill'] as $worker) $worker();
check(count($wpdb->queries) === 1 && strpos($wpdb->queries[0], 'LIMIT 21') !== false, 'worker scans unsynced orders outside board request');
check(!thean_ops_step_request_backfill() && $GLOBALS['__schedule_calls'] === $calls, 'backfill checks throttled across visits');

// Một lượt nền xử lý tối đa 20 đơn và nối tiếp nếu còn backlog.
$wpdb->unsynced_ids = range(100, 120);
$GLOBALS['__order_queries'] = [];
foreach ($GLOBALS['__actions']['thean_ops_step_backfill'] as $worker) $worker();
check(count($GLOBALS['__order_queries']) === 1 && count($GLOBALS['__order_queries'][0]['post__in']) === 20, 'worker reads at most 20 unsynced orders');
check((bool) wp_next_scheduled('thean_ops_step_backfill'), 'worker schedules another batch when backlog remains');
$wpdb->unsynced_ids = [];
unset($GLOBALS['__scheduled']['thean_ops_step_backfill']);

// AJAX đầu tiên: chỉ lấy một tab, 10 đơn; kiểm tra quyền/nonce trước SQL hoặc xếp lịch.
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    thean_ops_board_invalidate();
    $GLOBALS['__caps'] = ['manage_woocommerce'];
    $_POST = ['tab' => 'picking'];
    $wpdb->queries = [];
    try { thean_ous_ajax_board(); } catch (JsonResponse $r) {
        check($r->success && $r->data['tab'] === 'picking' && $r->data['panes'] === null && $r->data['rows'] === '', 'active-tab response without all-tab panes');
    }
    $sql = implode("\n", $wpdb->queries);
    check(count($wpdb->queries) === 3 && strpos($sql, 'LIMIT 10 OFFSET 0') !== false && strpos($sql, "s.meta_value = 'picking'") !== false && strpos($sql, 'a.meta_value') === false, 'only counts and active-page SQL; no foreground backfill');
    $_POST['refresh'] = 1;
    $wpdb->queries = [];
    try { thean_ous_ajax_board(); } catch (JsonResponse $r) {}
    check(count($wpdb->queries) === 3, 'explicit refresh bypasses server counts and IDs cache');
    unset($_POST['refresh']);
    $wpdb->queries = []; $GLOBALS['__caps'] = [];
    try { thean_ous_ajax_board(); } catch (JsonResponse $r) { check(!$r->success, 'unauthorized board rejected'); }
    check(!$wpdb->queries, 'unauthorized board performs no SQL');
    $GLOBALS['__caps'] = ['manage_woocommerce']; $GLOBALS['__bad_nonce'] = true;
    try { thean_ous_ajax_board(); } catch (RuntimeException $r) { check($r->getMessage() === 'Invalid nonce', 'bad nonce rejected'); }
    check(!$wpdb->queries, 'bad nonce performs no SQL');
    $GLOBALS['__bad_nonce'] = false;
}
$GLOBALS['__caps'] = []; $_POST = [];

// Khoảng ngày tạo: chuẩn hóa, tách cache, lọc ngay trước LIMIT nguồn.
check(thean_ous_range('500') === 'auto' && thean_ous_range("30 OR 1=1") === 'auto', 'unknown ranges cannot alter SQL policy');
check(thean_ous_range_label('auto', 'return') === '90 ngày gần nhất' && thean_ous_range_label('auto', 'preorder') === '90 ngày gần nhất', 'return and preorder default to 90 days');
check(thean_ous_range_label('auto', 'picking') === '30 ngày gần nhất' && thean_ous_range_label('7', 'return') === '7 ngày gần nhất', 'ordinary default 30; explicit selection overrides exception');
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $wpdb->queries = [];
    thean_ous_find_order_ids(array_merge($empty, ['text' => 'Whey Gold']), THEAN_OUS_CAP, '7');
    $queries = $wpdb->queries;
    foreach (array_slice($queries, 0, -1) as $sql) {
        check(strpos($sql, 'SELECT dr.') !== false && strpos($sql, 'SELECT dr.') < strpos($sql, 'ORDER BY'), 'source query restricts dates before LIMIT');
    }
    $final = end($queries);
    check(strpos($final, $hpos ? 'o.date_created_gmt' : 'o.post_date_gmt') !== false && strpos($final, 'drs.') === false, 'explicit final date range uses correct storage column');
    $wpdb->queries = [];
    thean_ous_find_order_ids(array_merge($empty, ['text' => 'Whey Gold']), THEAN_OUS_CAP, 'all');
    check(strpos(implode("\n", $wpdb->queries), 'SELECT dr.') === false && strpos(end($wpdb->queries), 'AND 1=1') !== false, 'all-time removes source/final date restriction');
    $wpdb->queries = [];
    thean_ous_find_order_ids(array_merge($empty, ['id' => '10276']));
    check(strpos(end($wpdb->queries), 'OR o.') !== false, 'exact ID bypasses default dates');
    $wpdb->queries = [];
    thean_ous_find_order_ids(array_merge($empty, ['id' => '10276']), THEAN_OUS_CAP, '30');
    check(strpos(end($wpdb->queries), 'OR o.') === false, 'exact ID respects explicitly selected date range');
    check(thean_ous_ids_cache_key($empty, '30') !== thean_ous_ids_cache_key($empty, 'all'), 'search range scopes cache');
    check(thean_ous_board_cache_key('counts', '30') !== thean_ous_board_cache_key('counts', '90'), 'board range scopes counts cache');
    thean_ops_board_invalidate(); $wpdb->queries = [];
    thean_ous_board_counts('7'); thean_ous_board_counts('90');
    check(count($wpdb->queries) === 4, 'counts for different ranges do not collide');
    $wpdb->queries = [];
    thean_ous_board_page_ids('return', 0, 10, '7');
    thean_ous_board_page_ids('return', 0, 10, 'all');
    check(count($wpdb->queries) === 2, 'first-page ID cache separates ranges');
}

// Nhập VAT: bóc JSON trả lời của AI.
$ai = thean_vat_request_parse_ai("Đây là kết quả:\n```json\n{\"tax_code\":\"MST: 0312 345 678\",\"company\":\" Công ty TNHH An \",\"address\":\"12 Lê Lợi, Q1\",\"email\":\"ketoan@an.vn\",\"note\":\"\"}\n```");
check($ai['tax_code'] === '0312345678' && $ai['company'] === 'Công ty TNHH An' && $ai['email'] === 'ketoan@an.vn', 'AI JSON in code fence, MST cleaned');
check(thean_vat_request_parse_ai('Không đọc được ảnh') === [], 'no JSON → empty');
check(thean_vat_request_parse_ai('{"tax_code":"0101234567-001"}')['tax_code'] === '0101234567-001', 'MST 13 số giữ dấu -');

echo "OK ({$checks} checks)\n";
}

<?php
/** SP hết hàng ở danh sách (out-of-stock-loop.php): xếp cuối / ẩn theo Cài đặt ERP → WooCommerce. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
$GLOBALS['filters'] = []; $GLOBALS['option'] = null; $GLOBALS['admin'] = false; $GLOBALS['meta'] = [];
function add_filter($tag, $cb, $prio = 10, $n = 1) {
    $GLOBALS['cbs'][$tag][] = $cb;
    $GLOBALS['filters'][$tag] = static function ($value, ...$args) use ($tag) {
        foreach ($GLOBALS['cbs'][$tag] as $f) { $value = $f($value, ...$args); }
        return $value;
    };
}
function add_action($tag, $cb, $prio = 10, $n = 1) { $GLOBALS['filters'][$tag] = $cb; }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return false; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function get_option($k, $d = false) { return $k === 'hithean_oos_loop_rules' ? ($GLOBALS['rules'] ?? $d) : ($GLOBALS['option'] ?? $d); }
function wp_unslash($v) { return $v; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function get_post_meta($id, $k, $single) { return $GLOBALS['meta'][$id] ?? ''; }
class WP_Query {
    public $vars; public $main;
    public function __construct($vars = [], $main = false) { $this->vars = $vars; $this->main = $main; }
    public function get($k) { return $this->vars[$k] ?? ''; }
    public function is_main_query() { return $this->main; }
}
$GLOBALS['wpdb'] = (object) ['posts' => 'wp_posts', 'postmeta' => 'wp_postmeta'];
require dirname(__DIR__) . '/custom-functions/woocommerce/products/out-of-stock-loop.php';

$fails = 0;
function ok($cond, $msg) { global $fails; echo ($cond ? '✓ ' : '✗ ') . $msg . "\n"; if (!$cond) { $fails++; } }

ok(hithean_oos_loop_mode() === 'last', 'Chưa cấu hình → mặc định xếp cuối');
ok(hithean_oos_loop_sanitize_mode('hide') === 'hide' && hithean_oos_loop_sanitize_mode('x') === 'last', 'Sanitize chế độ');

$base = ['join' => '', 'where' => ' AND 1=1', 'orderby' => 'wp_posts.menu_order ASC'];
$last = hithean_oos_loop_clauses($base, 'last', 'wp_posts', 'wp_postmeta');
ok(strpos($last['orderby'], "CASE WHEN hithean_oos_pm.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC, wp_posts.menu_order ASC") === 0, 'last: xếp hết hàng xuống cuối, giữ thứ tự cũ');
ok($last['where'] === ' AND 1=1', 'last: không lọc');
ok(strpos($last['join'], "LEFT JOIN wp_postmeta AS hithean_oos_pm ON (wp_posts.ID = hithean_oos_pm.post_id AND hithean_oos_pm.meta_key = '_stock_status')") !== false, 'last: JOIN _stock_status');
$twice = hithean_oos_loop_clauses($last, 'last', 'wp_posts', 'wp_postmeta');
ok(substr_count($twice['join'], 'LEFT JOIN') === 1, 'Không JOIN lặp');
$empty = hithean_oos_loop_clauses(['join' => '', 'where' => '', 'orderby' => ''], 'last', 'wp_posts', 'wp_postmeta');
ok(substr($empty['orderby'], -3) === 'ASC', 'orderby trống vẫn hợp lệ');

$hide = hithean_oos_loop_clauses($base, 'hide', 'wp_posts', 'wp_postmeta');
ok(strpos($hide['where'], "(hithean_oos_pm.meta_value IS NULL OR hithean_oos_pm.meta_value <> 'outofstock')") !== false, 'hide: lọc bỏ outofstock (onbackorder vẫn hiện)');
ok($hide['orderby'] === 'wp_posts.menu_order ASC', 'hide: không đổi thứ tự');

ok(hithean_oos_loop_is_target_query(new WP_Query(['wc_query' => 'product_query', 'post_type' => 'product'], true)), 'Query catalog chính → áp');
ok(!hithean_oos_loop_is_target_query(new WP_Query(['post_type' => 'product'], false)), 'Query SP khác (wc_get_products…) → không áp');
ok(hithean_oos_loop_is_target_query(new WP_Query(['post_type' => 'product', 'hithean_oos_loop' => 'last'])), 'Shortcode [products] đã gắn cờ → áp');
ok(!hithean_oos_loop_is_target_query(new WP_Query(['post_type' => 'post', 'hithean_oos_loop' => 'last'])), 'Post type khác → không áp');
$GLOBALS['admin'] = true;
ok(!hithean_oos_loop_is_target_query(new WP_Query(['wc_query' => 'product_query'], true)), 'wp-admin → không áp');
$GLOBALS['admin'] = false;

$args = $GLOBALS['filters']['woocommerce_shortcode_products_query'](['post_type' => 'product'], []);
ok($args['hithean_oos_loop'] === 'last', 'Shortcode gắn cờ kèm chế độ (khóa cache đổi theo chế độ)');

$GLOBALS['meta'] = [2 => 'outofstock', 3 => 'onbackorder'];
ok($GLOBALS['filters']['woocommerce_related_products']([1, 2, 3]) === [1, 2, 3], 'SP liên quan: last → giữ nguyên');
$GLOBALS['option'] = 'hide';
ok($GLOBALS['filters']['woocommerce_related_products']([1, 2, 3]) === [1, 3], 'SP liên quan: hide → bỏ SP hết hàng, giữ đặt trước');
$cl = $GLOBALS['filters']['posts_clauses']($base, new WP_Query(['wc_query' => 'product_query'], true));
ok(strpos($cl['where'], 'outofstock') !== false, 'posts_clauses dùng chế độ đã lưu');

// Ngoại lệ theo trang
$n = 'hithean_oos_loop_normalize_path';
ok($n('https://hithean.com/danh-muc-san-pham/Protein/page/3/?orderby=price') === 'danh-muc-san-pham/protein', 'Chuẩn hóa: bỏ domain, query, /page/N/, chữ hoa');
ok($n('/') === '' && $n('https://hithean.com') === '' && $n('/page/2/') === '', 'Trang chủ = khóa rỗng');
ok($n('/danh-muc-san-pham/protein/*') === 'danh-muc-san-pham/protein/*' && $n('*') === '*', 'Giữ * cuối');
$rules = hithean_oos_loop_sanitize_rules([
    ['path' => '/', 'mode' => 'hide'],
    ['path' => 'https://hithean.com/danh-muc-san-pham/protein/*', 'mode' => 'hide'],
    ['path' => '/danh-muc-san-pham/protein/whey/', 'mode' => 'last'],
    ['path' => '/danh-muc-san-pham/protein/', 'mode' => 'xx'],
    ['path' => '  ', 'mode' => 'hide'],
]);
ok(count($rules) === 4 && $rules[3]['mode'] === 'last', 'Sanitize: bỏ dòng trống, chế độ lạ → last');
$m = static function ($uri) use ($rules) { return hithean_oos_loop_mode_for_path($uri, 'last', $rules); };
ok($m('/') === 'hide' && $m('/?utm=1') === 'hide', 'Trang chủ theo ngoại lệ');
ok($m('/danh-muc-san-pham/protein/whey/page/2/') === 'last', 'Khớp đúng thắng nhánh *');
ok($m('/danh-muc-san-pham/protein/vegan/') === 'hide', 'Nhánh * áp cho danh mục con');
ok($m('/danh-muc-san-pham/protein/') === 'last', 'Khớp đúng (chế độ đã sanitize) thắng nhánh *');
ok($m('/danh-muc-san-pham/protein-bar/') === 'last', 'Nhánh * không ăn lan sang slug cùng tiền tố');
ok($m('/shop/') === 'last', 'Trang không có ngoại lệ → chế độ chung');
ok(hithean_oos_loop_mode_for_path('/bat-ky/', 'hide', [['path' => '*', 'mode' => 'last']]) === 'last', '* = mọi trang');

// Thuộc tính shortcode
ok(hithean_oos_loop_attr_mode('an') === 'hide' && hithean_oos_loop_attr_mode('cuoi') === 'last' && hithean_oos_loop_attr_mode('x') === '', 'het_hang="an|cuoi"');
$atts = $GLOBALS['filters']['shortcode_atts_products'](['limit' => 4], [], ['het_hang' => 'an']);
ok($atts['het_hang'] === 'an', 'Woo giữ thuộc tính het_hang');
ok($atts['class'] === 'hithean-oos-hide', 'Thẻ bọc có class dấu hiệu theo chế độ');
$args = $GLOBALS['filters']['woocommerce_shortcode_products_query'](['post_type' => 'product'], ['het_hang' => 'cuoi']);
ok($args['hithean_oos_loop'] === 'last', 'Thuộc tính shortcode đè chế độ trang');
$cl = $GLOBALS['filters']['posts_clauses']($base, new WP_Query(['post_type' => 'product', 'hithean_oos_loop' => 'last']));
ok(strpos($cl['where'], 'outofstock') === false && strpos($cl['orderby'], 'outofstock') !== false, 'posts_clauses dùng chế độ gắn trên query shortcode');
ok(!hithean_oos_loop_is_target_query(new WP_Query(['post_type' => 'product', 'hithean_oos_loop' => '1'])), 'Cờ lạ → không áp');

echo $fails ? "\n$fails lỗi\n" : "\nOK\n";
exit($fails ? 1 : 0);

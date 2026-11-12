<?php
/** Giá ưu đãi trên thẻ SP ở danh sách (product-list-page.php): rule Discount Rules giảm từ 1 SP → giá gốc gạch + giá ưu đãi. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
define('HITHEAN_THEME_DIR', dirname(__DIR__));
$GLOBALS['rules'] = []; $GLOBALS['plugin'] = true; $GLOBALS['products'] = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function has_filter($tag) { return $GLOBALS['plugin']; }
function apply_filters($tag, $value, ...$args) {
    if ($tag !== 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price') { return $value; }
    ok($args[1] === 1, 'Hỏi plugin đúng số lượng 1');
    return $GLOBALS['rules'][$args[0]->get_id()] ?? false;
}
function wc_price($price) { return number_format((float) $price, 0, ',', '.') . 'đ'; }
function wc_format_price_range($from, $to) { return wc_price($from) . '–' . wc_price($to); }
function wc_format_sale_price($regular, $sale) { return '<del>' . (is_numeric($regular) ? wc_price($regular) : $regular) . '</del><ins>' . (is_numeric($sale) ? wc_price($sale) : $sale) . '</ins>'; }
function wc_get_price_to_display($product, $args = []) { return (float) $args['price']; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
function is_admin() { return false; }
class WC_Product {
    public $id; public $price; public $regular; public $children = [];
    public function __construct($id, $price, $regular = null, $children = []) { $this->id = $id; $this->price = $price; $this->regular = $regular ?? $price; $this->children = $children; $GLOBALS['products'][$id] = $this; }
    public function get_id() { return $this->id; }
    public function get_price() { return $this->price; }
    public function get_regular_price() { return $this->regular; }
    public function is_type($type) { return $type === 'variable' ? (bool) $this->children : false; }
    public function get_visible_children() { return $this->children; }
    public function get_price_suffix() { return ''; }
}
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function rule(float $initial, float $discounted, string $kind = 'simple_discount', $as_cart = 'no'): array {
    return ['initial_price' => $initial, 'discounted_price' => $discounted, 'apply_as_cart_rule' => [$as_cart], 'total_discount_details' => [[$kind => ['discount_value' => 1]]]];
}
require dirname(__DIR__) . '/custom-functions/woocommerce/products/product-list-page.php';

ok(hithean_loop_rule_price_ranges([[100, 100], [200, 200]]) === null, 'Không lựa chọn nào giảm → null');
ok(hithean_loop_rule_price_ranges([[100, 80], [200, 200], null]) === [100.0, 200.0, 80.0, 200.0], 'Gộp khoảng giá gốc / giá cuối');

$GLOBALS['hithean_loop_price_scope'] = true;
new WC_Product(1, 500000);
$GLOBALS['rules'][1] = rule(500000, 450000);
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][1]) === '<del>500.000đ</del><ins>450.000đ</ins>', 'Rule giảm từ 1 SP → gạch giá gốc, hiện giá ưu đãi');
new WC_Product(2, 500000);
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][2]) === 'X', 'Không có rule → giữ nguyên');
new WC_Product(3, 400000, 500000);
ok(hithean_loop_rule_price_html('SALE', $GLOBALS['products'][3]) === 'SALE', 'Sale thông thường, không rule → WooCommerce tự hiện');
new WC_Product(4, 400000, 500000);
$GLOBALS['rules'][4] = rule(400000, 360000, 'bulk_discount');
ok(hithean_loop_rule_price_html('SALE', $GLOBALS['products'][4]) === '<del>500.000đ</del><ins>360.000đ</ins>', 'Sale + rule → gạch giá gốc ban đầu, hiện giá sau rule');
new WC_Product(5, 500000);
$GLOBALS['rules'][5] = rule(500000, 450000, 'simple_discount', 'yes');
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][5]) === 'X', 'Rule áp dụng ở giỏ hàng → không đổi giá hiển thị');
new WC_Product(6, 500000);
$GLOBALS['rules'][6] = rule(500000, 450000, 'buy_x_get_y_discount');
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][6]) === 'X', 'Quà tặng / BOGO → không đổi giá hiển thị');
new WC_Product(11, 300000); new WC_Product(12, 500000);
new WC_Product(10, 300000, 300000, [11, 12]);
$GLOBALS['rules'][11] = rule(300000, 270000); $GLOBALS['rules'][12] = rule(500000, 450000);
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][10]) === '<del>300.000đ–500.000đ</del><ins>270.000đ–450.000đ</ins>', 'SP biến thể: khoảng giá gốc gạch + khoảng giá ưu đãi');
new WC_Product(21, 300000, 350000); new WC_Product(20, 300000, 350000, [21]);
ok(hithean_loop_rule_price_html('VSALE', $GLOBALS['products'][20]) === 'VSALE', 'SP biến thể chỉ Sale thông thường → giữ nguyên');
$GLOBALS['hithean_loop_price_scope'] = false;
new WC_Product(7, 500000); $GLOBALS['rules'][7] = rule(500000, 450000);
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][7]) === 'X', 'Ngoài thẻ sản phẩm (trang chi tiết, giỏ…) → không can thiệp');
$GLOBALS['hithean_loop_price_scope'] = true; $GLOBALS['plugin'] = false;
ok(hithean_loop_rule_price_html('X', $GLOBALS['products'][7]) === 'X', 'Không có Discount Rules → không can thiệp');

echo "PASS: $count loop-rule-price assertions\n";

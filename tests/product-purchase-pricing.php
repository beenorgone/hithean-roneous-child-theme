<?php
/** Standalone regression checks for eligibility, native sale prices and scope. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function __($text, $domain = '') { return $text; }
function wp_kses_post($html) { return $html; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function get_option($key) { return 'd/m/Y'; }
function wp_date($format, $timestamp) { return gmdate($format, $timestamp); }
function wc_price($price) { return '<span class="amount">' . number_format($price, 0, ',', '.') . ' ₫</span>'; }
function wc_format_sale_price($regular, $sale) { return '<del>' . wc_price($regular) . '</del><ins>' . wc_price($sale) . '</ins>'; }
function wc_get_price_to_display($product, $args = []) { return ($args['price'] ?? $product->price) * ($args['qty'] ?? 1); }
function has_filter($tag) { return $GLOBALS['plugin_enabled']; }
function apply_filters($tag, $value, ...$args) {
    if ($tag !== 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price') throw new Exception('Unexpected filter');
    [$product, $quantity, $custom_price, $mode, $manual] = $args;
    check($value === false && $custom_price === 0 && $mode === 'all' && $manual === true, 'Ask plugin for matched details, not a table or a custom price');
    $GLOBALS['requested'] = [$product->id, $quantity];
    return ($GLOBALS['rule_result'])($product, $quantity);
}
class WC_Product {
    public $id = 3977;
    public $price = 880000;
    public $regular = 880000;
    public $sale = false;
    public $type = 'simple';
    public $parent = 0;
    public $status = 'publish';
    public $stock = true;
    public $purchasable = true;
    public $visible = true;
    public $minimum = 1;
    public $maximum = -1;
    public $individual = false;
    public $sale_end = null;
    public function get_id() { return $this->id; }
    public function get_price() { return $this->price; }
    public function get_regular_price() { return $this->regular; }
    public function get_price_html() { throw new Exception('Catalogue price HTML does not prove selected-quantity eligibility'); }
    public function get_price_suffix($price = '', $qty = 1) { return ''; }
    public function is_on_sale() { return $this->sale; }
    public function get_date_on_sale_from() { return null; }
    public function get_date_on_sale_to() { return $this->sale_end; }
    public function get_status() { return $this->status; }
    public function is_type($types) { return in_array($this->type, (array) $types, true); }
    public function get_parent_id() { return $this->parent; }
    public function variation_is_visible() { return $this->visible; }
    public function is_purchasable() { return $this->purchasable; }
    public function is_in_stock() { return $this->stock; }
    public function get_min_purchase_quantity() { return $this->minimum; }
    public function get_max_purchase_quantity() { return $this->maximum; }
    public function is_sold_individually() { return $this->individual; }
}
class QuoteResponse extends Exception {
    public $status;
    public $data;
    public $success;
    public function __construct($success, $data, $status) { $this->success = $success; $this->data = $data; $this->status = $status; }
}
function wp_send_json_error($data, $status = 200) { throw new QuoteResponse(false, $data, $status); }
function wp_send_json_success($data) { throw new QuoteResponse(true, $data, 200); }
function nocache_headers() { $GLOBALS['no_cache']++; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid']; }
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
function post_password_required($id) { return $GLOBALS['password_required']; }
function check($condition, $message) {
    $GLOBALS['assertions']++;
    if (!$condition) throw new Exception($message);
}
function matched($initial, $discounted, $quantity) {
    return [
        'initial_price' => $initial, 'discounted_price' => $discounted,
        'total_discount_details' => [6 => ['set_discount' => 0, 'bulk_discount' => ['discount_type' => 'percentage', 'discount_price' => $initial - $discounted], 'simple_discount' => 0]],
        'apply_as_cart_rule' => ['no'],
        'discount_lines' => [['quantity' => $quantity, 'discounted_price' => $discounted]],
    ];
}
function call_quote(array $post): QuoteResponse {
    $_POST = $post;
    try { hithean_product_purchase_ajax_quote(); }
    catch (QuoteResponse $response) { return $response; }
    throw new Exception('Action did not send a response');
}
$GLOBALS['assertions'] = 0;
$GLOBALS['plugin_enabled'] = true;
$GLOBALS['nonce_valid'] = true;
$GLOBALS['password_required'] = false;
$GLOBALS['no_cache'] = 0;
$GLOBALS['rule_result'] = function () { return false; };
require __DIR__ . '/../custom-functions/woocommerce/products/product-purchase.php';

$product = new WC_Product();
$GLOBALS['products'] = [3977 => $product];
$base = hithean_product_purchase_build_quote($product, 2);
check($base['state'] === 'base' && strpos($base['subtotalHtml'], '1.760.000') !== false, 'No matching rule keeps native price');
$product->sale = true; $product->regular = 1000000; $product->price = 900000;
$sale = hithean_product_purchase_build_quote($product, 2);
check($sale['state'] === 'base' && strpos($sale['unitHtml'], '<del>') !== false && strpos($sale['unitHtml'], '900.000') !== false && strpos($sale['subtotalHtml'], '1.800.000') !== false, 'Sale product excluded from rules retains native sale and strikeout');
check($sale['baseUnitHtml'] === $sale['unitHtml'], 'No-match baseline is native Woo pricing, not a catalogue rule price');
$GLOBALS['rule_result'] = function ($p, $q) { return $q >= 2 ? matched(900000, 855000, $q) : false; };
$sale_rule = hithean_product_purchase_build_quote($product, 2);
check($sale_rule['state'] === 'discount' && strpos($sale_rule['subtotalHtml'], '1.710.000') !== false, 'Use eligible plugin quote based on sale price once');
check(strpos($sale_rule['unitHtml'], '855.000') !== false && strpos($sale_rule['unitHtml'], '812.250') === false, 'Do not apply percentage again to plugin price');
check(strpos($sale_rule['baseUnitHtml'], '900.000') !== false && strpos($sale_rule['baseUnitHtml'], '855.000') === false, 'Keep native sale baseline separate from matched rule price for loading/error fallback');
$below_threshold = hithean_product_purchase_build_quote($product, 1);
check($below_threshold['state'] === 'base', 'Existing rule below quantity threshold does not claim a discount');
$GLOBALS['rule_result'] = function ($p, $q) { return matched(1000000, 950000, $q); };
$regular_rule = hithean_product_purchase_build_quote($product, 2);
check($regular_rule['state'] === 'discount' && strpos($regular_rule['unitHtml'], '950.000') !== false && strpos($regular_rule['subtotalHtml'], '1.900.000') !== false, 'Rule based on regular price wins per plugin, not a theme-computed minimum with sale price');
$product->sale_end = new DateTimeImmutable('2026-11-20T16:59:59Z');
$scheduled = hithean_product_purchase_build_quote($product, 2);
check(strpos($scheduled['unitHtml'], '20/11/2026') !== false, 'Keep sale schedule notice when replacing price');
$product->sale = false; $product->sale_end = null; $product->price = 1000000;
$GLOBALS['rule_result'] = function () { return false; };
$expired = hithean_product_purchase_build_quote($product, 1);
check($expired['state'] === 'base' && strpos($expired['unitHtml'], '<del>') === false, 'Inactive/future/expired native sale does not fabricate a sale');
foreach (['cart', 'mixed', 'set', 'invalid', 'missing', 'negative', 'not_reduced'] as $case) {
    $details = matched(1000000, 900000, 2);
    if ($case === 'cart') $details['apply_as_cart_rule'] = ['yes'];
    if ($case === 'mixed') $details['discount_lines'] = [['quantity' => 1, 'discounted_price' => 900000], ['quantity' => 1, 'discounted_price' => 1000000]];
    if ($case === 'set') $details['total_discount_details'][6]['set_discount'] = ['discount_price' => 100000];
    if ($case === 'invalid') $details['discounted_price'] = INF;
    if ($case === 'missing') $details['total_discount_details'] = [];
    if ($case === 'negative') $details['discounted_price'] = -1;
    if ($case === 'not_reduced') $details['discounted_price'] = 1000000;
    $GLOBALS['rule_result'] = function () use ($details) { return $details; };
    $deferred = hithean_product_purchase_build_quote($product, 2);
    check($deferred['state'] === 'deferred' && $deferred['subtotalHtml'] === '', 'Do not flatten unsupported/malformed result: ' . $case);
}
$GLOBALS['plugin_enabled'] = false;
$without_plugin = hithean_product_purchase_build_quote($product, 2);
check($without_plugin['state'] === 'base', 'Plugin absence does not break native pricing');
$GLOBALS['plugin_enabled'] = true;
$GLOBALS['rule_result'] = function ($p, $q) { return $p->id === 4000 && $q === 2 ? matched(1000000, 900000, $q) : false; };
$variation_parent = clone $product; $variation_parent->id = 3999; $variation_parent->type = 'variable';
$variation = clone $product; $variation->id = 4000; $variation->type = 'variation'; $variation->parent = 3999;
$GLOBALS['products'][3999] = $variation_parent; $GLOBALS['products'][4000] = $variation;
$response = call_quote(['product_id' => 3999, 'variation_id' => 4000, 'quantity' => 2]);
check($response->success && $response->data['state'] === 'discount' && $GLOBALS['requested'] === [4000, 2], 'Check eligibility for selected variation, not its parent or first child');
check(!call_quote(['product_id' => 3977, 'variation_id' => 4000, 'quantity' => 2])->success, 'Reject unrelated variation');
check(!call_quote(['product_id' => 3999, 'quantity' => 2])->success, 'Reject unselected variable product');
foreach (['0', '-1', '1.5', '10000', '2abc', [], ''] as $quantity) {
    check(!call_quote(['product_id' => 3977, 'quantity' => $quantity])->success, 'Reject invalid/unbounded quantity');
}
$product->maximum = 2;
check(!call_quote(['product_id' => 3977, 'quantity' => 3])->success, 'Respect stock/purchase maximum');
$product->maximum = -1; $product->individual = true;
check(!call_quote(['product_id' => 3977, 'quantity' => 2])->success, 'Respect sold-individually limit');
$product->individual = false; $product->stock = false;
check(!call_quote(['product_id' => 3977, 'quantity' => 1])->success, 'Respect stock state');
$product->stock = true; $product->status = 'draft';
check(call_quote(['product_id' => 3977, 'quantity' => 1])->status === 404, 'No draft product price disclosure');
$product->status = 'publish'; $GLOBALS['password_required'] = true;
check(call_quote(['product_id' => 3977, 'quantity' => 1])->status === 404, 'No password-protected product price disclosure');
$GLOBALS['password_required'] = false; $GLOBALS['nonce_valid'] = false;
check(call_quote(['product_id' => 3977, 'quantity' => 1])->status === 403, 'Nonce required');
check($GLOBALS['no_cache'] >= 10, 'Every quote response disables shared cache');
echo 'PASS: ' . $GLOBALS['assertions'] . " product-purchase pricing assertions\n";

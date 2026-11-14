<?php
/** Product-context fragment contract; no WordPress installation required. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function is_product() { return true; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function get_queried_object_id() { return 7; }
function wc_get_product($id) { return $GLOBALS['item']; }
function post_password_required() { return $GLOBALS['password'] ?? false; }
function get_post($id) { return (object) ['ID' => $id]; }
function get_page_by_path($slug) { return null; }
function hithean_offers_settings() { return ['preview' => false]; }
function hithean_offers_order() { return ['tiers', 'gift', 'shipping']; }
function get_post_thumbnail_id($id) { return 0; }
function thean_lw_is_eligible_page() { return true; }
class JsonResponse extends RuntimeException { public function __construct(public $data, public $status = 200) {} }
function wp_send_json_success($data) { throw new JsonResponse($data); }
function wp_send_json_error($data, $status = 400) { throw new JsonResponse($data, $status); }
function do_action($name) {
    if ($name !== 'woocommerce_single_product_summary') { throw new RuntimeException('Unexpected rendering path'); }
    if ($GLOBALS['product'] !== $GLOBALS['item'] || $GLOBALS['post']->ID !== 7) { throw new RuntimeException('Missing native product context'); }
    $GLOBALS['renders']++;
    echo '<form class="cart"><input name="quantity" class="qty" value="1"></form><div class="awdr_discount_bar">Native plugin offer</div>';
}
require dirname(__DIR__) . '/custom-functions/marketing/offers/availability.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/products/product-purchase.php';
$GLOBALS['item'] = new class {
    public $stock = true, $backorders = false, $status = 'publish';
    function is_in_stock() { return $this->stock; }
    function backorders_allowed() { return $this->backorders; }
    function get_status() { return $this->status; }
    function get_id() { return 7; }
};
$_GET['hithean_offer_fragment'] = '1';
$GLOBALS['product'] = 'previous product'; $GLOBALS['post'] = (object) ['ID' => 99]; $GLOBALS['renders'] = 0;
$count = 0;
function check($condition, $label) { global $count; $count++; if (!$condition) { throw new RuntimeException($label); } }
function response() { try { hithean_product_purchase_offer_fragment(); } catch (JsonResponse $response) { return $response; } throw new RuntimeException('No response'); }
$result = response();
check($result->status === 200 && $result->data['config']['offersAvailable'] === true, 'In-stock offers enabled');
check(str_contains($result->data['html'], 'Native plugin offer') && str_contains($result->data['html'], 'thean-lw-root'), 'Fragment preserves plugin sources and wheel presence');
check(!str_contains($result->data['html'], '<html') && !str_contains($result->data['html'], '<header'), 'No full page layout');
check($GLOBALS['nocache'] && $GLOBALS['product'] === 'previous product' && $GLOBALS['post']->ID === 99, 'No shared price caching; globals restored');
$GLOBALS['item']->stock = false;
$result = response();
check($result->data['config']['offersAvailable'] === false && $GLOBALS['renders'] === 1 && !str_contains($result->data['html'], 'Native plugin offer'), 'Unavailable products skip plugin rendering entirely');
$GLOBALS['item']->backorders = true;
$result = response();
check($result->data['config']['offersAvailable'] === true && $GLOBALS['renders'] === 2, 'Backorders retain plugin offers');
$GLOBALS['item']->status = 'draft';
check(response()->status === 404, 'Unpublished products cannot expose offers');
$GLOBALS['item']->status = 'publish'; $GLOBALS['password'] = true;
check(response()->status === 404, 'Password-protected products cannot expose offers');
echo "PASS: $count product offer fragment assertions\n";

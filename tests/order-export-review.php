<?php
/** Read-only export review fixtures. No WordPress bootstrap or live mutations. */
define('ABSPATH', __DIR__);
$GLOBALS['hooks'] = []; $GLOBALS['caps'] = ['manage_woocommerce', 'edit_shop_order']; $GLOBALS['nonce_ok'] = true; $GLOBALS['locked'] = false;
$GLOBALS['post_meta'] = []; $GLOBALS['orders'] = []; $GLOBALS['notes'] = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function check_ajax_referer($action, $field) { if (!$GLOBALS['nonce_ok']) throw new RuntimeException('bad nonce'); }
function current_user_can($cap, ...$args) { return in_array($cap, $GLOBALS['caps'], true); }
function absint($value) { return abs((int) $value); }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['post_meta'][$id][$key] ?? ''; }
function wc_get_order($id) { $GLOBALS['reads'][] = $id; return $GLOBALS['orders'][$id] ?? false; }
function wc_get_order_status_name($status) { return 'Chuẩn bị giao VC'; }
function wc_get_order_notes($args) { $GLOBALS['note_args'] = $args; return $GLOBALS['notes'][$args['order_id']] ?? []; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function esc_url_raw($url, $protocols = []) { return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $protocols, true) ? $url : ''; }
function thean_order_status_is_locked($order) { return $GLOBALS['locked']; }
function get_current_screen() { return (object) ['id' => 'woocommerce_page_wc-orders']; }
class ReviewResponse extends RuntimeException {
    public function __construct(public $data, public bool $success, public int $status = 200) { parent::__construct(); }
}
function wp_send_json_success($data) { throw new ReviewResponse($data, true); }
function wp_send_json_error($data, $status = 400) { throw new ReviewResponse($data, false, $status); }
class WC_Order {
    public array $meta = []; public string $type = 'shop_order';
    public function __construct(public int $id) {}
    public function get_id() { return $this->id; }
    public function get_type() { return $this->type; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function get_order_number() { return $this->id . '**'; }
    public function get_status() { return 'packaging'; }
    public function get_items() { return [new class { function get_name() { return 'Protein sample'; } function get_quantity() { return 2; } }]; }
    public function save() { throw new RuntimeException('Review must never save an order'); }
    public function save_meta_data() { throw new RuntimeException('Review must never save metadata'); }
}
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-export-review.php';
$order = new WC_Order(101); $GLOBALS['orders'][101] = $order;
$order->meta = ['warehouse_export_images' => "https://example.test/one.jpg\nhttps://example.test/two.jpg\nhttps://example.test/one.jpg\njavascript:alert(1)",
    'warehouse_export_ai_check' => ['overall'=>'pass','order_match'=>'match','items_match'=>'match','confidence'=>'high','reason'=>'Khớp 2 sản phẩm','checked_at'=>'2026-11-06 10:00']];
$GLOBALS['notes'][101] = [(object) ['content'=>'Ghi chú nội bộ khác'], (object) ['content'=>"🤖 AI review ảnh xuất kho\nSản phẩm và số lượng: Khớp"]];
if (($argv[1] ?? '') === 'fixture') {
    ob_start(); foreach ($GLOBALS['hooks']['admin_footer'] as $callback) $callback(); $html = ob_get_clean();
    echo json_encode(['html'=>$html,'data'=>thean_export_review_data($order)]); exit;
}
$checks = 0;
function check($value, $message) { global $checks; $checks++; if (!$value) throw new RuntimeException($message); }
function endpoint($success, $status = 200) {
    try { thean_export_review_ajax(); } catch (ReviewResponse $response) { check($response->success === $success && $response->status === $status, 'response authorization/status'); return $response->data; }
    throw new RuntimeException('Expected JSON response');
}
$before = serialize([$order->meta, $GLOBALS['post_meta']]);
$_POST = ['order_id'=>101]; $data = endpoint(true);
check($data['images'] === ['https://example.test/one.jpg','https://example.test/two.jpg'], 'images deduplicated and unsafe URLs rejected');
check($data['can_confirm'] && !$data['confirmed'] && $data['order_id'] === 101, 'existing unconfirmed evidence is reviewable');
check($data['items'][0] === ['name'=>'Protein sample','quantity'=>2] && $data['ai']['reason'] === 'Khớp 2 sản phẩm', 'items and AI reasoning included');
check(strpos($data['ai']['note'], 'AI review') !== false && strpos($data['ai']['note'], 'khác') === false, 'only relevant AI note selected');
check($GLOBALS['note_args']['limit'] === 15 && $GLOBALS['note_args']['type'] === 'internal', 'note query is bounded and permission gated');
check(serialize([$order->meta,$GLOBALS['post_meta']]) === $before, 'read does not mutate order or legacy metadata');
$order->meta['warehouse_export_ai_review_status'] = ['state'=>'queued'];
check(thean_export_review_data($order)['ai']['state'] === 'queued', 'queued AI is identified separately from previous result');
$order->meta['export_confirmed_by'] = 9;
check(!thean_export_review_data($order)['can_confirm'], 'already confirmed order cannot confirm again in UI');
$order->meta = []; $GLOBALS['post_meta'][101] = ['warehouse_export_images'=>'https://example.test/legacy.jpg', 'warehouse_export_ai_check'=>['reason'=>'Legacy AI']];
$data = thean_export_review_data($order);
check($data['images'] === ['https://example.test/legacy.jpg'] && $data['ai']['reason'] === 'Legacy AI', 'legacy export images and AI metadata remain visible');
$GLOBALS['post_meta'] = [];
check(!thean_export_review_data($order)['can_confirm'] && thean_export_review_data($order)['blocked'] !== '', 'no photo blocks confirmation');
$order->meta['warehouse_export_images'] = 'https://example.test/current.jpg'; $GLOBALS['locked'] = true;
check(!thean_export_review_data($order)['can_confirm'], 'old-order lock respected'); $GLOBALS['locked'] = false;
$GLOBALS['caps'] = ['edit_shop_order']; $GLOBALS['reads'] = []; endpoint(false,403); check(!$GLOBALS['reads'], 'unauthorized review does not load any order');
$GLOBALS['caps'] = ['manage_woocommerce']; endpoint(false,403); check(!$GLOBALS['reads'], 'per-order edit permission checked before reading order');
$GLOBALS['caps'] = ['manage_woocommerce','edit_shop_order'];
$_POST = ['order_id'=>['101']]; endpoint(false,400);
$_POST = ['order_id'=>999]; endpoint(false,404);
$_POST = ['order_id'=>101]; $order->type = 'shop_order_refund'; endpoint(false,404); $order->type = 'shop_order';
$GLOBALS['nonce_ok'] = false;
try { thean_export_review_ajax(); } catch (RuntimeException $error) { check($error->getMessage() === 'bad nonce', 'bad nonce rejected'); }
echo "OK ($checks read-only review checks)\n";

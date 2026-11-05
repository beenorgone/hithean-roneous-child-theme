<?php
/** Isolated workflow routing; no WordPress bootstrap, HTTP, or production data. */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
function add_action(...$args) {}
function add_filter(...$args) {}
function apply_filters($hook, $value) { return $value; }
function get_option($key, $default = false) { return $default; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['post_meta'][$id][$key] ?? ''; }
function wc_get_order_status_name($status) { return $status; }
function thean_legacy_meta_reconcile($order) {}
function thean_export_proof_recover($order) {}
function update_option(...$args) {}
class WC_Meta_Data { public function __construct(public $value) {} }
class WC_Order {
    public array $meta = [];
    public string $status = 'packaging';
    public string $payment = 'cod';
    public string $via = 'admin';
    public string $email = 'buyer@example.test';
    public DateTimeImmutable $created;
    public function __construct() { $this->created = new DateTimeImmutable(); }
    public function get_meta($key, $single = true) {
        $value = $this->meta[$key] ?? '';
        if (!$single) return array_map(static fn($v) => new WC_Meta_Data($v), is_array($value) ? $value : ($value === '' ? [] : [$value]));
        return is_array($value) ? ($value[0] ?? '') : $value;
    }
    public function get_id() { return 900; }
    public function get_type() { return 'shop_order'; }
    public function get_status() { return $this->status; }
    public function get_payment_method() { return $this->payment; }
    public function get_created_via() { return $this->via; }
    public function get_billing_email() { return $this->email; }
    public function get_date_created() { return $this->created; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save_meta_data() {}
}
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-ops-step.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-workspace.php';
$checks = 0;
function check($condition, $message) { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function field($key) { return function_exists('thean_ow_key') ? thean_ow_key($key) : $key; }
function route(WC_Order $order, string $step, bool $proof, string $message): void {
    $result = thean_ops_step_compute($order);
    check($result['step'] === $step && $result['proof'] === $proof, $message . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
}
$order = new WC_Order();
$order->status = 'processing'; $order->via = 'checkout';
route($order, 'confirm', false, 'self-placed COD needs customer confirmation');
$order->via = 'admin'; $order->payment = 'bacs';
route($order, 'payment', false, 'unpaid advance transfer remains separate payment step');
$order->meta['_order_payment_confirmation_audit'] = '{"verified":true}';
route($order, 'picking', false, 'verified advance payment enters packing');
$order->meta = []; $order->payment = 'cod'; $order->status = 'local-shipping';
route($order, 'picking', false, 'fast status alone is not dispatch or proof exception');
$order->meta['warehouse_export_images'] = "https://example.test/export.jpg";
route($order, 'export', false, 'photo upload still requires human export review');
$order->meta['warehouse_export_ai_check'] = ['overall' => 'pass', 'order_match' => 'match', 'items_match' => 'match'];
route($order, 'export', false, 'AI pass does not replace human confirmation');
$order->meta['export_confirmed_by'] = 8;
route($order, 'cod_fast', false, 'confirmed fast export enters fast COD queue before delivered');
check($order->status === 'local-shipping', 'classification never marks delivered');
$order->meta['_order_payment_confirmation_audit'] = '{"verified":true}';
route($order, 'shipping', false, 'paid fast order leaves COD, still tracked for delivery');
$order->meta = [field('handling_status') => ['Giao nhanh 2 giờ'], 'warehouse_export_images' => 'https://example.test/export.jpg', 'export_confirmed_by' => 8];
$order->status = 'delivered';
route($order, 'cod_fast', false, 'fast tag survives status change to delivered');
$order->meta = [field('shipper') => ['ahamove'], 'warehouse_export_images' => 'https://example.test/export.jpg', 'export_confirmed_by' => 8];
route($order, 'cod_fast', false, 'site-specific shipper key identifies fast COD');
$order->meta = [field('shipper') => ['ghtk'], 'warehouse_export_images' => 'https://example.test/export.jpg', 'export_confirmed_by' => 8];
route($order, 'cod', false, 'delivered carrier COD awaits reconciliation');
$order->status = 'shipping';
route($order, 'shipping', false, 'carrier COD still in transit is not yet reconciliation');
$order->meta = [];
foreach (['shipping', 'partial-shipping'] as $status) {
    $order->status = $status;
    route($order, 'shipping', true, $status . ' missing photo and confirmation overlaps exception');
}
$order->status = 'delivered';
route($order, 'cod', true, 'delivered COD debt is visible alongside missing proof');
$order->status = 'partial-shipped';
route($order, 'cod', true, 'partially delivered COD remains collection plus proof');
$order->status = 'completed';
route($order, 'proof', true, 'completed orders missing proof stay in exception');
$order->meta['warehouse_export_images'] = 'https://example.test/export.jpg';
route($order, 'proof', true, 'completed with photo but no confirmation remains exception');
$order->meta['export_confirmed_by'] = 8;
route($order, '', false, 'completed with complete proof leaves board');
$order->meta = ['return_status' => 'Chờ hoàn'];
route($order, 'return', true, 'unfinished return takes priority while missing proof remains flagged');
$order->status = 'cancelled';
route($order, 'return', false, 'closed order still retains unfinished return');
$order->meta = [];
route($order, '', false, 'cancelled order does not reenter packing');
$order->status = 'pre-order';
route($order, 'preorder', false, 'pre-order stays parked');
$order->status = 'local-shipping'; $order->meta['_thean_preorder_confirm'] = 'pending';
route($order, 'confirm', false, 'resumed preorder still needs confirmation before packing');
$order->meta = [field('handling_status') => ['Đã nhập kho vận chuyển']];
route($order, 'shipping', true, 'explicit handover makes unconfirmed fast order a proof exception');
$order->meta = [field('shipper') => ['ghtk'], 'export_confirmed_by' => 8];$order->status = 'shipping';
route($order, 'shipping', true, 'confirmed but missing photo is still an exception');
$order->meta['_thean_export_proof_skip'] = '1';
route($order, 'shipping', false, 'authorized existing skip marker stays respected');
$order->meta = [];$order->created = new DateTimeImmutable('-61 days');
route($order, 'shipping', false, 'historical proof age limit remains unchanged');
$order->created = new DateTimeImmutable();$order->status = 'local-shipping';$order->meta = ['warehouse_export_images' => 'https://example.test/export.jpg', 'export_confirmed_by' => 8];
$result = thean_ops_step_sync($order);
check($result['step'] === 'cod_fast' && $order->meta[THEAN_OPS_STEP_META] === 'cod_fast' && $order->meta[THEAN_OPS_STEP_VER] === THEAN_OPS_STEP_VERSION, 'sync stores the new step and version for backfill');
check(in_array('shipper', thean_ops_step_watched_meta(), true) && in_array('order_shipper', thean_ops_step_watched_meta(), true), 'both sites invalidate classification when carrier changes');
echo "OK ($checks workflow routing checks)\n";

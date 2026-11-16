<?php
/** Isolated save-boundary and payment evidence regression tests. */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
function add_action(...$args) {}
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['admin']; }
function thean_order_change_source() { return $GLOBALS['source']; }
function wp_doing_ajax() { return false; }
function get_current_user_id() { return 7; }
function set_transient($key, $value, $ttl) { $GLOBALS['pending'] = $value; }
function order_creator_state() { return $GLOBALS['creator'] ?? []; }
class WC_Data_Exception extends Exception { public function __construct($code, $message) { parent::__construct($message); } }
class WC_Meta_Data { public function __construct(public $value) {} }
class WC_Order {
    public array $meta = [], $changes = [];
    public array $data = ['status' => 'shipping', 'date_paid' => null, 'date_completed' => null];
    protected $status_transition = ['from' => 'shipping', 'to' => 'completed'];
    public function get_meta($key, $single = true) { $v = $this->meta[$key] ?? ''; return $single ? $v : array_map(fn($v) => new WC_Meta_Data($v), (array) $v); }
    public function get_status() { return $this->changes['status'] ?? $this->data['status']; }
    public function get_data() { return $this->data; }
    public function get_changes() { return $this->changes; }
    public function set_status($s) { $this->changes['status'] = $s; }
    public function set_date_paid($v) { $this->changes['date_paid'] = $v; }
    public function set_date_completed($v) { $this->changes['date_completed'] = $v; }
    public function get_payment_method() { return 'cod'; }
    public function transition() { return $this->status_transition; }
}
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-workspace.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-payment-status-guard.php';
$GLOBALS['admin'] = false; $GLOBALS['source'] = ''; $checks = 0;
function check($value, $label) { global $checks; ++$checks; if (!$value) throw new Exception($label); }
foreach (['completed', 'delivered'] as $target) {
    $o = new WC_Order(); $o->set_status($target);
    check(hithean_order_payment_status_error($o, $target) !== '', 'pending target is not payment proof');
    $o->changes['date_paid'] = 123; $o->changes['date_completed'] = 456;
    try { hithean_order_guard_unpaid_status($o); throw new Exception('save must fail'); } catch (WC_Data_Exception $e) {
        check(strpos($e->getMessage(), 'Chưa xác nhận thanh toán.') === 0, 'actionable error');
    }
    check($o->get_status() === 'shipping' && $o->changes['date_paid'] === null && $o->changes['date_completed'] === null && $o->transition() === false, 'restore status, timestamps and transition');
    $o->meta['_order_payment_confirmation_audit'] = '{"verified":true}';
    check(hithean_order_payment_status_error($o, $target) === '', 'audit confirms payment');
    $o->meta = ['order_paid_date' => '2026-11-16'];
    check(hithean_order_payment_status_error($o, $target) !== '', 'date alone insufficient');
    $o->meta['order_bank_account_received'] = 'cash';
    check(hithean_order_payment_status_error($o, $target) === '', 'date and account confirm');
    $o->data['status'] = 'partial-paid';
    check(hithean_order_payment_status_error($o, $target) !== '', 'partial payment insufficient');
    $o->data['status'] = 'paid'; $o->meta = [];
    check(hithean_order_payment_status_error($o, $target) === '', 'original paid status confirms');
}
$o = new WC_Order(); $GLOBALS['admin'] = true;
check(hithean_order_payment_status_error($o, 'completed') === '', 'admin override');
$GLOBALS['admin'] = false;
foreach (['cron', 'REST API', 'Action Scheduler', 'webhook'] as $source) {
    $GLOBALS['source'] = $source;
    check(hithean_order_payment_status_error($o, 'completed') === '', 'automatic source unchanged');
}
$GLOBALS['creator'] = ['active' => true, 'operator_id' => 7, 'is_admin' => false];
check(hithean_order_payment_status_error($o, 'completed') !== '', 'creator impersonation cannot bypass guard');
$GLOBALS['creator']['is_admin'] = true;
check(hithean_order_payment_status_error($o, 'completed') === '', 'creator keeps admin override');
$GLOBALS['creator'] = []; $GLOBALS['source'] = '';
check(hithean_order_payment_status_error($o, 'shipping') === '', 'other targets unchanged');
$o->changes = [];
hithean_order_guard_unpaid_status($o);
$o->data['status'] = 'completed'; $o->set_status('completed');
hithean_order_guard_unpaid_status($o);
check(true, 'ordinary saves and unchanged completed status allowed');
echo "OK ($checks checks)\n";

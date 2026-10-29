<?php
/** php tests/test-erp-mesh.php — no WordPress or production writes. */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('ERP_MESH_SITE_ID', 'hithean');
define('ERP_MESH_PEERS', ['ivar' => ['endpoint' => 'https://ivar.example/wp-json/mcp/ivar-erp-records',
    'username' => 'fixture', 'password' => 'fixture-only']]);
$GLOBALS['allowed'] = true; $GLOBALS['http'] = []; $GLOBALS['options'] = [];
class WP_Error { public $code; public function __construct($c, ...$args) { $this->code = $c; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action(...$a) {}
function add_filter(...$a) {}
function apply_filters($name, $value, ...$args) { return $value; }
function is_user_logged_in() { return true; }
function current_user_can(...$args) { return $GLOBALS['allowed']; }
function wp_json_encode($v) { return json_encode($v); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function absint($v) { return abs((int) $v); }
function get_post_meta(...$a) { return ''; }
function wp_parse_url($url) { return parse_url($url); }
function wp_generate_uuid4() { static $n = 0; return 'fixture-' . ++$n; }
function wp_remote_retrieve_header($r, $key) { return $r['headers'][$key] ?? ''; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_safe_remote_post($url, $args) {
    $GLOBALS['http'][] = ['url' => $url, 'args' => $args];
    $request = json_decode($args['body'], true);
    if ($request['method'] === 'notifications/initialized') return ['status' => 202, 'body' => '', 'headers' => []];
    if ($request['method'] === 'initialize') $result = ['protocolVersion' => '2025-03-26'];
    else $result = ['structuredContent' => ['site' => 'ivar', 'id' => 7]];
    $data = ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result];
    return ['status' => 200, 'headers' => ['mcp-session-id' => 'test-session'],
        'body' => !empty($GLOBALS['sse']) ? 'data: ' . json_encode($data) . "\n\n" : json_encode($data)];
}
class WC_Product {
    function get_sku() { return 'SKU-1'; }
    function get_name() { return 'Catalogue name'; }
}
class WC_Order_Item_Product {
    function get_product() { return new WC_Product(); }
    function get_quantity() { return 4; }
    function get_meta($key, $single = true) { return $key === '_restock_refunded_items' ? 1 : ''; }
    function get_id() { return 22; }
    function get_product_id() { return 11; }
    function get_variation_id() { return 0; }
    function get_subtotal() { return '100'; }
    function get_total() { return '90'; }
    function get_total_tax() { return '9'; }
    function get_name() { throw new RuntimeException('Free-form item names must not be projected.'); }
}
class WC_Order {
    function get_type() { return 'shop_order'; }
    function get_status() { return 'completed'; }
    function get_id() { return 7; }
    function get_order_number() { return '7'; }
    function get_items() { return [new WC_Order_Item_Product()]; }
    function get_currency() { return 'VND'; }
    function get_date_created() { return null; }
    function get_date_modified() { return null; }
    function get_total() { return '99'; }
    function get_shipping_total() { return '0'; }
    function get_total_tax() { return '9'; }
    function get_total_refunded() { return '20'; }
    function get_meta($key, $single = true) { return $key === 'export_confirmed_by' ? 8 : ''; }
    function __call($name, $args) { throw new RuntimeException('Unexpected order data access: ' . $name); }
}
function wc_get_order($id) { return $id === 7 ? new WC_Order() : false; }
function wc_get_order_statuses() { return ['wc-completed' => 'Completed']; }
function wc_get_orders($args) { return (object) ['orders' => [new WC_Order()], 'max_num_pages' => 2]; }
require_once dirname(__DIR__) . '/custom-functions/integrations/erp-mesh/mesh.php';
$checks = 0;
function check($ok, $message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$projection = erp_mesh_orders_get(['id' => 7]);
check($projection['items'][0]['restocked_quantity'] === 1.0, 'Only actual restock quantity is used.');
check($projection['items'][0]['quantity'] === 4.0, 'Financial refund must not reduce ordered quantity.');
check($projection['items'][0]['name'] === 'Catalogue name', 'Free-form customer item names are excluded.');
check(!preg_match('/billing|shipping_address|email|phone|customer|note/', json_encode($projection)), 'Projection contains no PII fields.');
check(strlen($projection['fingerprint']) === 64, 'Projection is fingerprinted.');
$GLOBALS['allowed'] = false;
check(is_wp_error(erp_mesh_orders_get(['id' => 7])), 'Execute callback rechecks permissions.');
check(is_wp_error(erp_mesh_orders_list()), 'List denies unauthorized caller.');
check(is_wp_error(erp_mesh_peer_orders(['site' => 'ivar'])), 'Peer forwarding requires local manager permission.');
$GLOBALS['allowed'] = true;
check(erp_mesh_date('2026-02-30') === '', 'Invalid calendar date rejected.');
check(erp_mesh_date('2028-02-29') === '2028-02-29', 'Leap date accepted.');
check(is_wp_error(erp_mesh_orders_list(['date_from' => '2026-10-30', 'date_to' => '2026-10-29'])), 'Reversed window rejected.');
check(is_wp_error(erp_mesh_orders_list(['date_from' => '2026-01-01', 'date_to' => '2026-10-29'])), 'Unbounded scans rejected.');
$page = erp_mesh_orders_list();
check($page['has_more'] && $page['omitted'] === 0, 'Pagination exposes continuation and omissions.');
check(is_wp_error(erp_mesh_peer_call('unknown', 'orders-get', ['id' => 7])), 'Unknown host is not callable.');
check(is_wp_error(erp_mesh_peer_call('ivar', 'arbitrary-tool', [])), 'Unknown remote tool is not callable.');
$result = erp_mesh_peer_call('ivar', 'orders-get', ['id' => 7]);
check($result['id'] === 7 && count($GLOBALS['http']) === 3, 'MCP initialize, notification, and call handshake.');
check($GLOBALS['http'][2]['args']['redirection'] === 0, 'Credentials never follow redirects.');
check($GLOBALS['http'][2]['args']['headers']['Mcp-Session-Id'] === 'test-session', 'Session is propagated.');
$GLOBALS['sse'] = true;
check(erp_mesh_peer_call('ivar', 'orders-get', ['id' => 7])['id'] === 7, 'SSE JSON-RPC response decoded.');
check(count($GLOBALS['http']) === 4, 'Handshake reused within the same request.');
$session = ''; $protocol = '2025-03-26';
check(is_wp_error(erp_mesh_peer_rpc(['endpoint' => 'https://user:secret@ivar.example/wp-json/mcp/a'], 'initialize', [], $session, $protocol)), 'URL credentials rejected.');
check(is_wp_error(erp_mesh_peer_rpc(['endpoint' => 'http://ivar.example/wp-json/mcp/a'], 'initialize', [], $session, $protocol)), 'HTTP rejected.');
$definitions = erp_mesh_ability_definitions();
check(!isset($definitions['erp-mesh/commit']), 'No remote commit ability.');
check($definitions['erp-mesh/orders-get']['output_schema']['additionalProperties'] === false, 'Order output schema is closed.');
echo "PASS: $checks ERP mesh checks.\n";

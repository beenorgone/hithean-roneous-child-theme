<?php
// php tests/order-portal.php — navigation boundaries and route authorization, no live orders.
define('ABSPATH', __DIR__);
$hooks = []; $admin = true; $ajax = false; $allowed = true; $logged_in = true;
function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][] = $callback; }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function home_url($path = '') { return 'https://example.test/store' . $path; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function add_query_arg($args, $url) { return $url . ($args ? '?' . http_build_query($args) : ''); }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function absint($value) { return abs((int) $value); }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
function send_frame_options_header() { $GLOBALS['frame_header'] = true; }
function auth_redirect() { throw new RuntimeException('login'); }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException('status:' . ($args['response'] ?? 500)); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
$checks = 0;
require __DIR__ . '/../custom-functions/woocommerce/orders/order-navigation.php';
$pagenow = 'admin.php'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['page' => 'wc-orders'];
check(thean_order_portal_redirect_url() === home_url('/quan-ly-don/'), 'HPOS list redirect');
$pagenow = 'edit.php'; $_GET = ['post_type' => 'shop_order', 'thean_board' => 'cod', 'thean_range' => '90'];
$url = thean_order_portal_redirect_url();
check(str_contains($url, 'thean_board=cod') && str_contains($url, 'thean_range=90'), 'board filters preserved');
$_GET['post_status'] = 'wc-processing'; $_GET['s'] = 'sample';
check(str_contains(thean_order_portal_redirect_url(), 's=%2Ftt+processing+sample'), 'native status mapped to search');
foreach ([['action' => 'edit'], ['action' => 'mark_completed'], ['action2' => 'trash'], ['_wpnonce' => 'nonce'], ['thean_wc_list' => '1'], ['_customer_user' => 9], ['m' => '202611']] as $extra) {
    $_GET = array_merge(['post_type' => 'shop_order'], $extra);
    check(thean_order_portal_redirect_url() === '', 'native mutation/edit/filter/fallback stays in Woo');
}
$_GET = ['post_type' => 'shop_order']; $_SERVER['REQUEST_METHOD'] = 'POST';
check(thean_order_portal_redirect_url() === '', 'POST never redirected'); $_SERVER['REQUEST_METHOD'] = 'GET';
$ajax = true; check(thean_order_portal_redirect_url() === '', 'AJAX untouched'); $ajax = false;
$allowed = false; check(thean_order_portal_redirect_url() === '', 'unauthorized user not redirected'); $allowed = true;
$pagenow = 'admin.php'; $_GET = ['page' => 'wc-orders', 'action' => 'new'];
check(str_contains(thean_order_portal_redirect_url(), 'thean_workspace=create'), 'HPOS new opens create tab');
$_GET = ['page' => 'phone-orders-for-woocommerce', 'order_id' => 42];
check(str_contains(thean_order_portal_redirect_url(), 'order_id=42'), 'phone orders preserves edit ID in create tab');
$pagenow = 'post-new.php'; $_GET = ['post_type' => 'shop_order'];
check(str_contains(thean_order_portal_redirect_url(), 'thean_workspace=create'), 'legacy new opens create tab');
$pagenow = 'plugins.php'; $_GET = [];
check(thean_order_portal_redirect_url() === '', 'unrelated admin untouched');
$menu = [[0, 0, 'edit.php?post_type=shop_order']]; $submenu = ['woocommerce' => [[0, 0, 'wc-orders'], [0, 0, 'wc-settings']]];
check(empty($hooks['admin_menu']), 'menu rewrite is hooked by the theme loader, not on file load');
thean_order_portal_rewrite_menu();
check($menu[0][2] === home_url('/quan-ly-don/') && $submenu['woocommerce'][0][2] === home_url('/quan-ly-don/'), 'order menus link directly to portal');
check($submenu['woocommerce'][1][2] === 'wc-settings', 'other menu destinations unchanged');
$admin = false; $_GET = []; $_SERVER['REQUEST_URI'] = '/store/quan-ly-don/?thean_workspace=create';
check(thean_order_portal_is_route(), 'subdirectory route with query');
foreach (['/store/quan-ly-don/other', '/store/shop/', '/quan-ly-don/'] as $path) {
    $_SERVER['REQUEST_URI'] = $path; check(!thean_order_portal_is_route(), 'exact site-relative route only');
}
require __DIR__ . '/../custom-functions/woocommerce/orders/order-portal.php';
$render = end($hooks['template_redirect']);
$_SERVER['REQUEST_URI'] = '/store/quan-ly-don/'; $allowed = false;
try { $render(); throw new RuntimeException('missing denial'); } catch (RuntimeException $e) { check($e->getMessage() === 'status:403', 'route denies unauthorized users before loading UI'); }
$logged_in = false;
try { $render(); throw new RuntimeException('missing login'); } catch (RuntimeException $e) { check($e->getMessage() === 'login', 'route requires login before loading UI'); }
check(!function_exists('thean_ous_render_ui'), 'denied requests never load board modules');
check(!empty($no_cache) && !empty($frame_header), 'private route sets no-cache and framing headers');
// Pre-rendered first board page: ?thean_board beats the saved-tab cookie; create workspace / failures fall back to AJAX.
function thean_ous_range($r) { return in_array($r, ['auto', '7', '30', '90', 'all'], true) ? $r : 'auto'; }
function thean_ous_board_payload($req) { if (!empty($GLOBALS['board_fail'])) throw new RuntimeException('db'); $GLOBALS['board_req'] = $req; return ['data' => ['tab' => $req['tab'] ?: 'confirm'], 'timings' => ['board_total' => 1.0]]; }
$_COOKIE = ['thean_ous_tab' => 'cod']; $_GET = [];
$init = thean_order_portal_board_initial();
check($init['config']['requested'] === 'cod' && $board_req['tab'] === 'cod' && $init['config']['range'] === 'auto', 'saved-tab cookie selects the pre-rendered tab');
$_GET = ['thean_board' => 'Picking', 'thean_range' => '90'];
$init = thean_order_portal_board_initial();
check($init['config']['requested'] === 'picking' && $board_req['range'] === '90', 'URL tab and range override the cookie');
$_GET = ['thean_range' => 'bogus']; $_COOKIE = [];
check(thean_order_portal_board_initial()['config']['range'] === 'auto' && $board_req['tab'] === '', 'invalid range and no saved tab use server defaults');
$_GET = ['thean_workspace' => 'create'];
check(thean_order_portal_board_initial() === null, 'create-first visit defers the board read');
$_GET = []; $board_fail = true;
check(thean_order_portal_board_initial() === null, 'board errors fall back to the client AJAX read');
echo "OK ($checks order portal navigation and authorization checks)\n";

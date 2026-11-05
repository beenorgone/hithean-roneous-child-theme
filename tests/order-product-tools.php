<?php
/** Auth/allowlist/document tests, using isolated WP stubs; no live shortcode/data calls. */
define('ABSPATH', __DIR__);
$GLOBALS['allowed'] = true; $GLOBALS['nonce_ok'] = true;
function current_user_can($cap) { return $GLOBALS['allowed']; }
function wp_die($message, $title = '', $args = []) { throw new RuntimeException($message, $args['response'] ?? 500); }
function check_admin_referer($action) { $GLOBALS['nonce_checks'] = ($GLOBALS['nonce_checks'] ?? 0) + 1; if (!$GLOBALS['nonce_ok']) wp_die('Bad nonce', '', ['response' => 403]); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function wp_unslash($s) { return $s; }
function shortcode_exists($name) { return true; }
function wp_enqueue_script($name) { $GLOBALS['scripts'][] = $name; }
function en_product_info_search_scripts() { $GLOBALS['scripts'][] = 'product-info-search'; }
function do_shortcode($tag) { return '<div data-shortcode="' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '">Isolated tool fixture</div>'; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function send_frame_options_header() { $GLOBALS['sameorigin'] = true; }
function get_bloginfo($key) { return 'UTF-8'; }
function language_attributes() { echo 'lang="vi"'; }
function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return esc_attr($s); }
function esc_url($s) { return esc_attr($s); }
function wp_print_styles() { echo '<style data-test-styles></style>'; }
function wp_print_head_scripts() { echo '<script data-scripts="' . esc_attr(implode(',', $GLOBALS['scripts'] ?? [])) . '"></script>'; }
function wp_print_footer_scripts() { echo '<script data-test-footer></script>'; }
function home_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=test-nonce'; }
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-product-tools.php';
if (($argv[1] ?? '') === 'render') { $_GET = ['thean_order_tool' => $argv[2]]; thean_order_product_tool_response(); }
$checks = 0;
function check($test, $message) { global $checks; $checks++; if (!$test) throw new RuntimeException($message); }
function reject($status, $message) {
    try { thean_order_product_tool_response(); } catch (RuntimeException $error) { check($error->getCode() === $status, $message); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
check(array_keys(thean_order_product_tools()) === ['info', 'compare', 'protein'], 'only three fixed tool IDs');
check(thean_order_product_tool_url('not-a-tool') === '', 'unknown tool has no endpoint URL');
check(strpos(thean_order_product_tool_url('info'), 'thean_order_tool=info') !== false && strpos(thean_order_product_tool_url('info'), '_wpnonce=') !== false, 'iframe URL has allowlisted tool and nonce');
$GLOBALS['allowed'] = false; $_GET = ['thean_order_tool' => 'info']; reject(403, 'unauthorized reader blocked');
check(empty($GLOBALS['nonce_checks']), 'capability checked before nonce or shortcode loading');
$GLOBALS['allowed'] = true; $GLOBALS['nonce_ok'] = false; reject(403, 'bad nonce blocked');
$GLOBALS['nonce_ok'] = true; $_GET = ['thean_order_tool' => '../../wp-config']; reject(400, 'path-like tool rejected');
$_GET = ['thean_order_tool' => ['info']]; reject(400, 'array tool rejected without PHP warnings');
ob_start(); thean_order_render_product_workspace(); $html = ob_get_clean();
check(substr_count($html, '<iframe ') === 3 && substr_count($html, 'data-src=') === 3 && !preg_match('/<iframe[^>]*\ssrc=/', $html), 'tool iframes have no eager src');
check(strpos($html, 'data-thean-ous-workflow-guide="products"') !== false && strpos($html, 'aria-controls="thean-product-compare"') !== false, 'product guide and accessible subtabs rendered');
foreach (thean_order_product_tools() as $key => $tool) {
    $cmd = [PHP_BINARY, __FILE__, 'render', $key];
    $pipes = []; $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $document = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $errors === '', 'standalone document renders without warnings: ' . $key . ' ' . $errors);
    check(strpos($document, '[' . $tool['shortcode'] . ']') !== false && strpos($document, 'data-test-footer') !== false, 'renders exact registered shortcode and footer assets: ' . $key);
    if ($key === 'info') check(strpos($document, 'jquery,product-info-search') !== false, 'product info loads its AJAX script and jQuery');
}
echo "OK ($checks product tool checks)\n";

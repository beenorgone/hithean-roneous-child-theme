<?php
/** Render the real export footer callback with WP stubs; no upload/order/database operations. */
define('ABSPATH', __DIR__);
$GLOBALS['allowed'] = true; $GLOBALS['hooks'] = []; $GLOBALS['shortcodes'] = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter(...$args) {}
function add_shortcode($tag, $callback) { $GLOBALS['shortcodes'][$tag] = $callback; }
function ct_get_order_item_display_name($item) { return 'Fixture'; }
function is_user_logged_in() { return $GLOBALS['allowed']; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function get_option($key, $default = false) { return $default; }
function wp_create_nonce($action) { return 'test-' . $action; }
function wp_json_encode($value) { return json_encode($value); }
function esc_js($value) { return addslashes($value); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://example.test/theme'; }
$source = dirname(__DIR__) . '/custom-functions/woocommerce/shipping/shortcode-order-export-confirm.php';
if (!is_file($source)) $source = dirname(__DIR__) . '/custom-functions/shortcodes/shortcode-order-export-confirm.php';
require $source;
$checks = 0;
function check($condition, $message) { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
$callback = $GLOBALS['hooks']['wp_footer'][0] ?? null;
check(in_array($callback, ['thean_export_confirm_assets', 'hithean_export_confirm_assets'], true) && is_callable($callback), 'original frontend footer hook has a reusable named callback');
check(isset($GLOBALS['shortcodes']['list_unconfirmed_exports'], $GLOBALS['shortcodes']['list_uploaded_not_shipped_exports'], $GLOBALS['shortcodes']['upload_export_images_form']), 'all original shortcodes stay registered');
ob_start(); $callback(); $html = ob_get_clean();
check(strpos($html, 'id="ueif-checklist-modal"') !== false && strpos($html, 'uexe-gallery-modal') !== false, 'actual checklist and photo gallery rendered');
check(strpos($html, 'test-ajax_upload_images_nonce') !== false && strpos($html, 'test-ajax_confirm_export_nonce') !== false, 'native upload and confirmation nonces rendered');
check(strpos($html, 'window.uexeRefreshLists') !== false && strpos($html, "window.frameElement.closest('[hidden]')") !== false, 'live refresh remains available and skips hidden tool frames');
preg_match_all('/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $html, $scripts, PREG_SET_ORDER);
$compiled = 0;
foreach ($scripts as $script) {
    if (trim($script[2]) === '') continue;
    $pipes = [];
    $process = proc_open(['node', '--check'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $script[2]); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'real inline export script syntax: ' . $stderr); $compiled++;
}
check($compiled > 0, 'at least one real export script compiled');
$GLOBALS['allowed'] = false; ob_start(); $callback(); $denied = ob_get_clean();
check($denied === '', 'unauthorized users receive no export controls or script nonces');
echo "OK ($checks export asset checks)\n";

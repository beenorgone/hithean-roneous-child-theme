<?php
/** Run: php tests/create-order-ai-address.php. No network or WordPress needed. */
define('ABSPATH', __DIR__);
define('WP_PLUGIN_DIR', '/nonexistent');
function get_stylesheet_directory() { return dirname(__DIR__); }
function apply_filters($hook, $value) { return $value; }
function remove_accents($text) { return str_replace(['đ', 'Đ'], ['d', 'D'], iconv('UTF-8', 'ASCII//TRANSLIT', $text)); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function sanitize_email($text) { return filter_var($text, FILTER_SANITIZE_EMAIL); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }

require get_stylesheet_directory() . '/custom-functions/core/vn-address.php';
require get_stylesheet_directory() . '/custom-functions/woocommerce/orders/order-creator/ai-address.php';
$module = file_get_contents(get_stylesheet_directory() . '/custom-functions/woocommerce/orders/order-creator/create-order-for-customer.php');
foreach (['order_creator_ai_normalize_customer', 'order_creator_ai_address_rules'] as $name) {
    if (!preg_match('/function ' . $name . '\([^\n]*\n\{.*?\n\}/s', $module, $match)) {
        throw new RuntimeException('Cannot load production function: ' . $name);
    }
    eval($match[0]);
}
$calls = 0;
$response = [];
function order_creator_ai_run($system, $search, $tail, $prompt, $images, $tokens, $convert, &$used) {
    global $calls, $response;
    $calls++;
    check(strpos($prompt, '0901234567') === false && strpos($prompt, 'Private Customer') === false, 'conversion receives address only');
    check($convert && !$images && strpos($search, 'số điện thoại') !== false, 'grounded conversion excludes PII from searches');
    check(strpos($prompt, 'DANH MỤC PHƯỜNG/XÃ') !== false, 'current ward catalog supplied');
    $used = ['provider' => 'gemini'];
    return $response;
}
$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
$wards = theme_vn_address_wards('HANOI');
$ward_code = (string) array_key_first($wards);
$ward_name = $wards[$ward_code];
$source = ['first_name' => 'Private Customer', 'phone' => '0901234567', 'address_1' => '12 Example Street, Old District', 'state' => 'HANOI', 'city' => 'Old Ward'];
$response = ['verified' => true, 'address_1' => '12 Example Street', 'state' => 'Thành phố Hà Nội', 'city' => $ward_name];
$used = null;
$result = order_creator_ai_prepare_customer($source, true, $used);
check($result['fields']['state'] === 'HANOI' && $result['fields']['city'] === $ward_code, 'validated current codes selected');
check($result['fields']['address_1'] === '12 Example Street, ' . $ward_name . ', Thành phố Hà Nội', 'full address uses canonical names without old district');
check($result['fields']['phone'] === $source['phone'] && $result['fields']['first_name'] === $source['first_name'], 'identity retained');
check($used['provider'] === 'gemini', 'actual address provider reported');

foreach ([new WP_Error(), ['verified' => false], ['verified' => 'true'], ['verified' => true, 'address_1' => '12 Example Street', 'state' => 'HANOI', 'city' => 'Invented Ward'], ['verified' => true, 'address_1' => '', 'state' => 'HANOI', 'city' => $ward_name], ['verified' => true, 'address_1' => '12 Example Street', 'state' => 'HANOI', 'city' => 'Xã ' . preg_replace('/^(Phường|Xã) /u', '', $ward_name)]] as $response) {
    $result = order_creator_ai_prepare_customer($source, true, $used);
    check($result['fields']['state'] === '' && $result['fields']['city'] === '', 'uncertain/invalid conversion does not silently select units');
    check(strpos($result['fields']['address_1'], $source['address_1']) === 0 && $result['unmatched']['city'] === 'Old Ward', 'original address and warning retained');
}
$before = $calls;
$result = order_creator_ai_prepare_customer($source, false, $used);
check($calls === $before && $result['fields']['state'] === 'HANOI', 'opt-out skips conversion');
order_creator_ai_prepare_customer(['phone' => '0901234567'], true, $used);
check($calls === $before, 'product/contact-only edit skips conversion');
echo "OK ($checks checks)\n";

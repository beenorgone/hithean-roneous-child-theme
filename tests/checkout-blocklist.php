<?php
/** Regression: wc-ajax checkout must load and enforce the blocklist before creating an order. */
define('ABSPATH', __DIR__);
$hooks = [];
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook] = [$callback, $args]; }
function __($text, $domain) { return $text; }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
$GLOBALS['checks'] = 0;
$root = dirname(__DIR__);
$base = $root . '/custom-functions/woocommerce/checkout/';
require $base . 'checkout-blocklist-policy.php';
require $base . 'checkout-blocklist.php';
check(($hooks['woocommerce_after_checkout_validation'] ?? null) === ['child_theme_checkout_validate_blocklist', 2], 'validation is registered with parsed billing data and error object');
$loader = file_get_contents($root . '/custom-functions/core/module-loader.php');
$generalStart = strpos($loader, '$general_includes = [');
$generalEnd = strpos($loader, "\n];", $generalStart);
// Both loader styles declare these dependencies before any conditional checkout module.
$general = substr($loader, $generalStart, $generalEnd === false ? null : $generalEnd - $generalStart);
check(strpos($general, 'woocommerce/checkout/checkout-blocklist-policy.php') !== false, 'policy loads in general includes, including wc-ajax checkout');
check(strpos($general, 'woocommerce/checkout/checkout-blocklist.php') !== false, 'guard loads in general includes, including wc-ajax checkout');
check(strpos($general, 'checkout-blocklist-policy.php') < strpos($general, 'checkout-blocklist.php'), 'policy loads before guard');
foreach (['0797096899', '0797 096 899', '+84 797 096 899', '84797096899', '0084.797.096.899'] as $phone) {
    check(child_theme_checkout_blocked_fields(['billing_phone' => $phone, 'billing_email' => 'allowed@example.com']) === ['billing_phone'], 'phone independently blocks: ' . $phone);
}
foreach (['quynhnguyen298@gmail.com', 'QUYNHNGUYEN298@GMAIL.COM', ' QuynhNguyen298@gmail.com '] as $email) {
    check(child_theme_checkout_blocked_fields(['billing_phone' => '0901234567', 'billing_email' => $email]) === ['billing_email'], 'email independently blocks after normalization');
}
check(child_theme_checkout_blocked_fields(['billing_phone' => '0797096899', 'billing_email' => 'quynhnguyen298@gmail.com']) === ['billing_phone', 'billing_email'], 'both fields report errors');
foreach ([[], ['billing_phone' => [], 'billing_email' => []], ['billing_phone' => '0797096898', 'billing_email' => 'other@example.com'], ['billing_phone' => '10797096899', 'billing_email' => 'xquynhnguyen298@gmail.com']] as $data) {
    check(child_theme_checkout_blocked_fields($data) === [], 'empty, malformed or neighboring identities do not match');
}
$errors = new class {
    public array $errors = [];
    public function add($code, $message, $data) { $this->errors[$code] = [$message, $data]; }
};
call_user_func($hooks['woocommerce_after_checkout_validation'][0], ['billing_phone' => '+84797096899', 'billing_email' => 'QUYNHNGUYEN298@GMAIL.COM'], $errors);
check(count($errors->errors) === 2, 'checkout receives blocking validation errors');
check($errors->errors['checkout_blocked_billing_phone'][1]['id'] === 'billing_phone', 'phone error identifies checkout field');
check($errors->errors['checkout_blocked_billing_email'][1]['id'] === 'billing_email', 'email error identifies checkout field');
$policy = child_theme_checkout_blocklist_policy();
foreach ($policy['phones'] as $phone) check(child_theme_checkout_blocked_fields(['billing_phone' => $phone]) === ['billing_phone'], 'all policy phones remain blocked');
foreach ($policy['emails'] as $email) check(child_theme_checkout_blocked_fields(['billing_email' => $email]) === ['billing_email'], 'all policy emails remain blocked');
$isMarket = strpos(basename($root), 'theanmarket-') === 0;
if ($isMarket) {
    foreach (['0394788389', '0939588286'] as $phone) check(in_array($phone, $policy['phones'], true), 'legacy phone preserved');
    foreach (['buithithehy@gmail.com', 'trieuvinh@gmail.com', 'martinameikim@gmail.com'] as $email) check(in_array($email, $policy['emails'], true), 'legacy email preserved');
    foreach (['checkout-page.php', 'checkout-ajax.php'] as $file) check(strpos(file_get_contents($base . $file), 'TAO_BLOCKED_') === false, 'removed duplicate legacy policy');
}
$sibling = dirname($root) . '/' . ($isMarket ? 'hithean-roneous-child-theme' : 'theanmarket-wr-nitro-child-theme');
if (is_dir($sibling)) {
    foreach (['custom-functions/woocommerce/checkout/checkout-blocklist.php', 'tests/checkout-blocklist.php'] as $file) check(file_get_contents($root . '/' . $file) === file_get_contents($sibling . '/' . $file), 'shared guard and regression test stay identical');
}
echo 'OK (' . $GLOBALS['checks'] . " checks)\n";

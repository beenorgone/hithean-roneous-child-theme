<?php
/**
 * Reject blocked customer identities before Classic Checkout creates an order.
 * GIỐNG HỆT hithean ↔ theanmarket; edit in hithean and copy with tests/checkout-blocklist.php.
 * Site-owned policy lives in checkout-blocklist-policy.php; no DB queries or customer logging.
 */
if (!defined('ABSPATH')) exit;

/** Normalize a Vietnamese phone number for exact blocklist matching. */
function child_theme_checkout_phone_key($phone): string
{
    if (!is_scalar($phone)) return '';
    $digits = preg_replace('/[^0-9]+/', '', (string) $phone);
    if (substr($digits, 0, 4) === '0084') {
        $digits = '0' . substr($digits, 4);
    } elseif (substr($digits, 0, 2) === '84') {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

/** Return blocked billing field names; either identity independently prevents checkout. */
function child_theme_checkout_blocked_fields(array $data): array
{
    $policy = child_theme_checkout_blocklist_policy();
    $blocked = [];
    $phone = child_theme_checkout_phone_key($data['billing_phone'] ?? '');
    $raw_email = $data['billing_email'] ?? '';
    $email = is_scalar($raw_email) ? strtolower(trim((string) $raw_email)) : '';
    if ($phone !== '' && in_array($phone, $policy['phones'], true)) $blocked[] = 'billing_phone';
    if ($email !== '' && in_array($email, $policy['emails'], true)) $blocked[] = 'billing_email';
    return $blocked;
}

/** Add checkout validation errors before WooCommerce creates an order or attempts payment. */
function child_theme_checkout_validate_blocklist($data, $errors): void
{
    $messages = [
        'billing_phone' => __('Số điện thoại này không được phép đặt hàng.', 'woocommerce'),
        'billing_email' => __('Email này không được phép đặt hàng.', 'woocommerce'),
    ];
    foreach (child_theme_checkout_blocked_fields($data) as $field) {
        $errors->add('checkout_blocked_' . $field, $messages[$field], ['id' => $field]);
    }
}
add_action('woocommerce_after_checkout_validation', 'child_theme_checkout_validate_blocklist', 10, 2);

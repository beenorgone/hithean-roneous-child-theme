<?php
/** Run with wp eval-file. All SQL fixtures are rolled back; no coupons are issued. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    exit(1);
}

$lookup = 'thean_lw_claim_coupon_ids';
$source = $args[0] ?? '';
if ($source) {
    $code = file_get_contents($source);
    $start = strpos($code, 'function thean_lw_claim_coupon_ids(');
    $end = strpos($code, "\nfunction thean_lw_set_claim_locks(", $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('Lookup source not found.');
    }
    $lookup = 'thean_lw_test_claim_coupon_ids';
    eval(str_replace('function thean_lw_claim_coupon_ids(', 'function ' . $lookup . '(', substr($code, $start, $end - $start)));
}

global $wpdb;
$wpdb->query('START TRANSACTION');
$checks = 0;
$prefix = 'lw-perf-' . wp_generate_uuid4();
$identity = ['type' => 'email', 'value' => $prefix . '@example.invalid', 'hash' => hash('sha256', $prefix)];
$user = ['type' => 'user', 'value' => '987654321', 'hash' => hash('sha256', $prefix . '-user')];
$phone = ['type' => 'phone', 'value' => '0987654321', 'hash' => hash('sha256', $prefix . '-phone')];
$make = static function (array $meta, string $date, string $status = 'publish', string $type = 'shop_coupon') use ($wpdb, $prefix): int {
    if (!$wpdb->insert($wpdb->posts, ['post_title' => $prefix, 'post_type' => $type, 'post_status' => $status, 'post_date' => $date, 'post_date_gmt' => $date])) {
        throw new RuntimeException($wpdb->last_error);
    }
    $id = (int) $wpdb->insert_id;
    foreach ($meta as $key => $values) {
        foreach ((array) $values as $value) {
            if (!$wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value])) {
                throw new RuntimeException($wpdb->last_error);
            }
        }
    }
    return $id;
};
$assert = static function ($actual, $expected, string $label) use (&$checks): void {
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . wp_json_encode(['expected' => $expected, 'actual' => $actual]));
    }
    $checks++;
};
try {
    $reward = ['_thean_lw_reward_id' => 'test'];
    $hash = ['_thean_lw_identity_hash' => array_fill(0, 8, $identity['hash'])];
    $modern = $make($reward + $hash, '2026-01-02 00:00:00');
    $legacy = $make($reward + ['_thean_lw_contact_type' => 'email', '_thean_lw_contact_value' => $identity['value']], '2026-01-01 00:00:00');
    $make($reward + ['_thean_lw_contact_type' => 'phone', '_thean_lw_contact_value' => $identity['value']], '2026-01-03 00:00:00');
    $make($reward + $hash, '2026-01-04 00:00:00', 'trash');
    $make($reward + $hash, '2026-01-05 00:00:00', 'auto-draft');
    $make($hash, '2026-01-06 00:00:00');
    $make($reward + $hash, '2026-01-07 00:00:00', 'publish', 'post');
    $user_coupon = $make($reward + ['_thean_lw_user_id' => $user['value']], '2026-01-08 00:00:00');
    $phone_coupon = $make($reward + ['_thean_lw_contact_type' => 'phone', '_thean_lw_contact_value' => $phone['value']], '2026-01-09 00:00:00');
    $assert($lookup([], 20), [], 'Empty identities');
    $assert($lookup([['type' => 'email', 'value' => '', 'hash' => '']], 20), [], 'Blank identities');
    $assert($lookup([$identity], 20), [$modern, $legacy], 'Hash and typed legacy match, no duplicates or unrelated posts');
    $assert($lookup([$identity], 1), [$modern], 'Newest and bounded');
    $assert($lookup([$identity], 0), [$modern], 'Minimum limit');
    $assert($lookup([$identity, $user, $phone], 20), [$phone_coupon, $user_coupon, $modern, $legacy], 'Multiple account identities');
    $assert($lookup([$identity], 20, [['column' => 'post_date_gmt', 'after' => '2026-01-02 00:00:00', 'inclusive' => true]]), [$modern], 'Inclusive GMT date boundary');
    $assert($lookup([$identity], 20, [['column' => 'post_date_gmt', 'after' => '2026-01-03 00:00:00']]), [], 'Date exclusion');
    $assert($lookup([['type' => 'email', 'value' => "' OR 1=1 --", 'hash' => "' OR 1=1 --"]], 20), [], 'Prepared identity values');
    $assert($lookup([$identity], 20), [$modern, $legacy], 'Request cache');
    echo "PASS: {$checks} coupon lookup checks; fixtures rolled back.\n";
} finally {
    $wpdb->query('ROLLBACK');
}

// Read-only reproduction of a multi-identity account lookup, if requested.
$user_id = isset($args[1]) ? (int) $args[1] : 0;
if ($user_id > 0) {
    $account = get_userdata($user_id);
    $identities = [['type' => 'user', 'value' => (string) $user_id, 'hash' => hash('sha256', 'user:' . $user_id)]];
    if ($account) {
        $email = strtolower($account->user_email);
        $identities[] = ['type' => 'email', 'value' => $email, 'hash' => hash('sha256', 'email:' . $email)];
    }
    $started = microtime(true);
    $ids = $lookup($identities, 20);
    printf("Account lookup: %d matches, %.2f ms, no personal data printed.\n", count($ids), (microtime(true) - $started) * 1000);
}

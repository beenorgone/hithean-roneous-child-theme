<?php

if (!defined('ABSPATH')) {
    exit;
}

define('THEAN_LW_OPTION_KEY', 'thean_lw_settings');
define('THEAN_LW_STATE_PREFIX', 'thean_lw_state_');
define('THEAN_LW_SESSION_COOKIE', 'thean_lw_session');
define('THEAN_LW_NONCE_ACTION', 'thean_lw_nonce');
define('THEAN_LW_MAX_SPINS', 3);
define('THEAN_LW_CLAIM_COOLDOWN', 2 * DAY_IN_SECONDS);

function thean_lw_default_settings(): array
{
    return [
        'enabled' => 1,
        'offer_slugs' => "uu-dai\nkhuyen-mai\nsale",
        'trigger_rules' => wp_json_encode(thean_lw_default_trigger_rules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        'coupon_hold_hours' => '',
        'sheets_webhook_url' => '',
        'sheets_webhook_secret' => '',
        'rewards_json' => wp_json_encode(thean_lw_default_rewards(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ];
}

function thean_lw_default_rewards(): array
{
    return [
        [
            'id' => 'percent_5',
            'label' => 'Giảm 5% cho đơn hàng hôm nay',
            'type' => 'percent',
            'amount' => 5,
            'frequency' => 35,
            'min_cart' => 0,
            'active' => true,
        ],
        [
            'id' => 'percent_10_500',
            'label' => 'Giảm 10% cho đơn từ 500.000đ',
            'type' => 'percent',
            'amount' => 10,
            'frequency' => 20,
            'min_cart' => 500000,
            'active' => true,
        ],
        [
            'id' => 'fixed_20000_300',
            'label' => 'Giảm 20.000đ cho đơn từ 300.000đ',
            'type' => 'fixed_cart',
            'amount' => 20000,
            'frequency' => 20,
            'min_cart' => 300000,
            'active' => true,
        ],
        [
            'id' => 'freeship',
            'label' => 'Miễn phí vận chuyển',
            'type' => 'free_shipping',
            'amount' => 0,
            'frequency' => 15,
            'min_cart' => 0,
            'active' => true,
        ],
        [
            'id' => 'fixed_30000_600',
            'label' => 'Giảm 30.000đ cho đơn từ 600.000đ',
            'type' => 'fixed_cart',
            'amount' => 30000,
            'frequency' => 10,
            'min_cart' => 600000,
            'active' => true,
        ],
    ];
}

function thean_lw_default_trigger_rules(): array
{
    return [
        ['url_pattern' => '*', 'vertical' => 'bottom', 'horizontal' => 'right', 'display' => 'icon_text', 'custom_class' => ''],
    ];
}

function thean_lw_sanitize_css_value(string $value): string
{
    $value = trim(sanitize_text_field($value));
    if ($value !== '' && !preg_match('/^[0-9a-zA-Z%.\- ]+$/', $value)) {
        return '';
    }
    return $value;
}

function thean_lw_normalize_trigger_rule(array $rule): ?array
{
    $pattern = trim((string) ($rule['url_pattern'] ?? ''));
    if ($pattern === '') {
        return null;
    }

    $vertical = in_array($rule['vertical'] ?? '', ['top', 'bottom'], true) ? $rule['vertical'] : 'bottom';
    $horizontal = in_array($rule['horizontal'] ?? '', ['left', 'right'], true) ? $rule['horizontal'] : 'right';
    $display = in_array($rule['display'] ?? '', ['icon_text', 'icon_only', 'text_only'], true) ? $rule['display'] : 'icon_text';
    $custom_class = sanitize_html_class((string) ($rule['custom_class'] ?? ''));

    return [
        'url_pattern' => $pattern,
        'vertical' => $vertical,
        'horizontal' => $horizontal,
        'display' => $display,
        'custom_class' => $custom_class,
        'top' => thean_lw_sanitize_css_value((string) ($rule['top'] ?? '')),
        'bottom' => thean_lw_sanitize_css_value((string) ($rule['bottom'] ?? '')),
        'left' => thean_lw_sanitize_css_value((string) ($rule['left'] ?? '')),
        'right' => thean_lw_sanitize_css_value((string) ($rule['right'] ?? '')),
        'mobile_top' => thean_lw_sanitize_css_value((string) ($rule['mobile_top'] ?? '')),
        'mobile_bottom' => thean_lw_sanitize_css_value((string) ($rule['mobile_bottom'] ?? '')),
        'mobile_left' => thean_lw_sanitize_css_value((string) ($rule['mobile_left'] ?? '')),
        'mobile_right' => thean_lw_sanitize_css_value((string) ($rule['mobile_right'] ?? '')),
    ];
}

function thean_lw_get_trigger_rules(): array
{
    $decoded = json_decode((string) thean_lw_get_settings()['trigger_rules'], true);
    if (!is_array($decoded) || empty($decoded)) {
        return thean_lw_default_trigger_rules();
    }

    $rules = [];
    foreach ($decoded as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $normalized = thean_lw_normalize_trigger_rule($rule);
        if ($normalized !== null) {
            $rules[] = $normalized;
        }
    }

    return empty($rules) ? thean_lw_default_trigger_rules() : $rules;
}

function thean_lw_url_pattern_matches(string $pattern, string $path): bool
{
    if ($pattern === '*') {
        return true;
    }

    $path = rtrim($path, '/') ?: '/';
    $pattern = rtrim($pattern, '/') ?: '/';

    return fnmatch($pattern, $path, FNM_CASEFOLD);
}

function thean_lw_feature_dir(): string
{
    return get_stylesheet_directory() . '/custom-functions/lucky-wheel';
}

function thean_lw_feature_url(): string
{
    return get_stylesheet_directory_uri() . '/custom-functions/lucky-wheel';
}

function thean_lw_asset(string $basename): array
{
    $extension = pathinfo($basename, PATHINFO_EXTENSION);
    $filename = basename($basename, '.' . $extension);
    $min_file = $filename . '.min.' . $extension;
    $min_path = thean_lw_feature_dir() . '/' . $min_file;
    $source_path = thean_lw_feature_dir() . '/' . $basename;
    $resolved_file = is_file($min_path) ? $min_file : $basename;
    $resolved_path = is_file($min_path) ? $min_path : $source_path;

    return [
        'file' => $resolved_file,
        'path' => $resolved_path,
        'url' => thean_lw_feature_url() . '/' . $resolved_file,
        'version' => is_file($resolved_path) ? (string) filemtime($resolved_path) : thean_theme_code_version(),
    ];
}

function thean_lw_can_manage(): bool
{
    return current_user_can('manage_woocommerce') || current_user_can('manage_options');
}

function thean_lw_get_settings(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $saved = get_option(THEAN_LW_OPTION_KEY, []);
    $saved = is_array($saved) ? $saved : [];
    $cache = array_merge(thean_lw_default_settings(), $saved);

    return $cache;
}

function thean_lw_is_enabled(): bool
{
    return !empty(thean_lw_get_settings()['enabled']);
}

function thean_lw_offer_slugs(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $raw = (string) thean_lw_get_settings()['offer_slugs'];
    $cache = array_values(array_unique(array_filter(array_map('sanitize_title', preg_split('/\R+/', $raw)))));

    return $cache;
}

function thean_lw_coupon_hold_hours(): int
{
    $hours = trim((string) thean_lw_get_settings()['coupon_hold_hours']);
    if ($hours === '') {
        return 24;
    }

    return max(1, min(168, (int) $hours));
}

function thean_lw_coupon_ttl(): int
{
    return thean_lw_coupon_hold_hours() * HOUR_IN_SECONDS;
}

function thean_lw_sheets_webhook_url(): string
{
    return esc_url_raw((string) thean_lw_get_settings()['sheets_webhook_url']);
}

function thean_lw_sheets_webhook_secret(): string
{
    return (string) thean_lw_get_settings()['sheets_webhook_secret'];
}

function thean_lw_trigger_config(): array
{
    $rules = thean_lw_get_trigger_rules();
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = rtrim('/' . ltrim((string) parse_url($uri, PHP_URL_PATH), '/'), '/') ?: '/';

    // Last-match wins: đặt catch-all (*) đầu, rule cụ thể hơn ở sau sẽ override
    $matched = null;
    foreach ($rules as $rule) {
        if (thean_lw_url_pattern_matches((string) $rule['url_pattern'], $path)) {
            $matched = $rule;
        }
    }

    if ($matched !== null) {
        return $matched;
    }

    return thean_lw_default_trigger_rules()[0];
}

function thean_lw_reward_wheel_label(array $reward): string
{
    if (!empty($reward['wheel_label'])) {
        return sanitize_text_field((string) $reward['wheel_label']);
    }

    switch ($reward['type']) {
        case 'percent':
            return (string) round((float) $reward['amount']) . '%';
        case 'fixed_cart':
            return round(((float) $reward['amount']) / 1000) . 'K';
        case 'free_shipping':
            return 'Freeship';
    }

    return sanitize_text_field((string) $reward['label']);
}

function thean_lw_normalize_reward(array $reward): ?array
{
    $id = sanitize_key((string) ($reward['id'] ?? ''));
    $label = sanitize_text_field((string) ($reward['label'] ?? ''));
    $type = sanitize_key((string) ($reward['type'] ?? ''));

    if ($id === '' || $label === '' || !in_array($type, ['percent', 'fixed_cart', 'free_shipping'], true)) {
        return null;
    }

    $amount = isset($reward['amount']) ? (float) $reward['amount'] : 0.0;
    $frequency = max(0, (int) ($reward['frequency'] ?? 0));
    $min_cart = max(0, (float) ($reward['min_cart'] ?? 0));
    $active = !empty($reward['active']);

    if ($type === 'percent') {
        $amount = min(max($amount, 0), 100);
    } elseif ($type === 'fixed_cart') {
        $amount = max($amount, 0);
    } else {
        $amount = 0.0;
    }

    return [
        'id' => $id,
        'label' => $label,
        'type' => $type,
        'amount' => $amount,
        'frequency' => $frequency,
        'min_cart' => $min_cart,
        'active' => $active,
    ];
}

function thean_lw_rewards(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $decoded = json_decode((string) thean_lw_get_settings()['rewards_json'], true);
    $source = is_array($decoded) ? $decoded : thean_lw_default_rewards();
    $cache = [];

    foreach ($source as $reward) {
        if (!is_array($reward)) {
            continue;
        }

        $normalized = thean_lw_normalize_reward($reward);
        if ($normalized !== null) {
            $cache[] = $normalized;
        }
    }

    if (empty($cache)) {
        $cache = thean_lw_default_rewards();
    }

    return $cache;
}

function thean_lw_active_rewards(): array
{
    $segments = [];

    foreach (thean_lw_rewards() as $reward) {
        if (empty($reward['active']) || (int) $reward['frequency'] <= 0) {
            continue;
        }

        $segments[] = $reward;
    }

    if (empty($segments)) {
        foreach (thean_lw_default_rewards() as $reward) {
            $segments[] = $reward;
        }
    }

    return array_values($segments);
}

function thean_lw_is_feature_ajax_request(): bool
{
    if (!wp_doing_ajax()) {
        return false;
    }

    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';

    return in_array($action, ['thean_lw_status', 'thean_lw_spin', 'thean_lw_claim', 'thean_lw_apply_coupon'], true);
}

if (is_admin()) {
    require_once thean_lw_feature_dir() . '/admin.php';
}

if (thean_lw_is_enabled() && (!is_admin() || thean_lw_is_feature_ajax_request())) {
    require_once thean_lw_feature_dir() . '/ui.php';
}

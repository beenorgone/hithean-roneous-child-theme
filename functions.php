<?php

add_action('pre_get_posts', function ($query) {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'shop_order') {
        error_log('✅ Admin search query is active. Query vars: ' . print_r($query->query_vars, true));
    }
});


if (!function_exists('roneous_child_enqueue_styles')) {
    function roneous_child_enqueue_styles()
    {
        $parent_style = 'roneous-style';
        wp_enqueue_style(
            $parent_style,
            get_template_directory_uri() . '/style.css',
            array('roneous-libs', 'roneous-theme-styles')
        );
        wp_enqueue_style(
            'roneous-child-style',
            get_stylesheet_directory_uri() . '/style.css',
            array($parent_style)
        );
    }
    add_action('wp_enqueue_scripts', 'roneous_child_enqueue_styles');
}

if (!function_exists('roneous_child_language_setup')) {
    function roneous_child_language_setup()
    {
        load_child_theme_textdomain('roneous', get_stylesheet_directory() . '/languages');
    }
    add_action('after_setup_theme', 'roneous_child_language_setup');
}

/* Add to functions.php file of in-use theme */

function add_pragma_no_cache_header($headers)
{
    $headers['Pragma'] = 'no-cache';
    return $headers;
}
add_filter('nocache_headers', 'add_pragma_no_cache_header');

/* Add custom menus */

function custom_theme_menus()
{
    register_nav_menus(
        array(
            'secondary-menu'    => __('Secondary Menu'),
        )
    );
}
add_action('after_setup_theme', 'custom_theme_menus');

// General includes with conditionally loaded files commented out
$general_includes = [
    'custom-functions/test.php',
    'custom-functions/hpos-order-check.php',
    'custom-functions/woocommerce-settings.php',
    'custom-functions/swiper-slider.php',
    'custom-functions/popup-widget.php',
    'custom-functions/shortcode-field-content.php',
    'custom-functions/shortcode-greenspark-banner.php',
    'custom-functions/product-page.php',
    'custom-functions/product-list-page.php',
    'custom-functions/pos-post-type.php',
    // 'custom-functions/recipe-post-type.php',
    'custom-functions/shortcode-recipes.php',
    'custom-functions/shortcode-posts.php',
    'custom-functions/shortcode-pos.php',
    'custom-functions/shortcode-bank-transfer-confirmation.php',
    'custom-functions/shortcode-google-docs.php',
    'custom-functions/shortcode-order-shipped.php',
    'custom-functions/shortcode-order-export-confirm.php',
    'custom-functions/product-tab-post-type.php',
    'custom-functions/blogpost-metabox.php',
    'custom-functions/product-metabox.php',

    /* ORDER */
    'custom-functions/order-metabox.php',
    'custom-functions/order-number.php',
    'custom-functions/order-status.php',
    'custom-functions/order-tracking.php',

    'custom-functions/checkout-page.php',
    'custom-functions/order-auto-tasks.php',
    'custom-functions/thank-you-page.php', // To ensure order admin page, thank you page, and emails can use this
    'custom-functions/firebase-sms-login.php',
    'custom-functions/email-issue.php',
    'custom-functions/email-login.php',
    'custom-functions/email-tasks.php',
    'custom-functions/product-taxonomies.php',

    'custom-functions/affiliate-manager.php',

    /* Plugins */
    'custom-functions/plugin-devvn-ghtk-tweaks.php',
];

foreach ($general_includes as $include) {
    if (file_exists(__DIR__ . '/' . $include)) {
        require_once(__DIR__ . '/' . $include);
    }
}

// Conditionally load admin-specific files
function load_custom_admin_files()
{
    if (!is_admin()) {
        return;
    }

    // Get current screen (safe fallback)
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen ? $screen->id : '';
    $screen_base = $screen ? $screen->base : '';

    // === WooCommerce Orders Admin ===
    if (
        (isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') || // edit.php?post_type=shop_order
        (isset($_GET['page']) && $_GET['page'] === 'wc-orders') ||            // wc-orders SPA
        in_array($screen_id, ['edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders'], true)
    ) {
        require_once __DIR__ . '/custom-functions/orders-admin.php';
        require_once __DIR__ . '/custom-functions/order-search.php';
    }

    // === Plugins page ===
    if ($screen_id === 'plugins') {
        require_once __DIR__ . '/custom-functions/plugins-page.php';
    }

    // === Phone Orders plugin ===
    if (
        $screen_base === 'admin_page_phone-orders-for-woocommerce' ||
        (isset($_GET['page']) && $_GET['page'] === 'phone-orders-for-woocommerce')
    ) {
    }

    // === Admin styles ===
    wp_enqueue_style(
        'admin-styles',
        get_stylesheet_directory_uri() . '/admin.css',
        [],
        wp_get_theme()->get('Version')
    );
}
add_action('admin_enqueue_scripts', 'load_custom_admin_files');


function tpc_loader_require_relative($relative_path)
{
    $path = __DIR__ . '/' . ltrim((string) $relative_path, '/');
    if (is_file($path)) {
        require_once $path;
    }
}

function tpc_loader_normalize_path($path)
{
    $path = '/' . ltrim((string) $path, '/');
    return untrailingslashit($path);
}

function tpc_loader_current_path()
{
    static $current_path = null;

    if ($current_path !== null) {
        return $current_path;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $parsed_path = $request_uri ? wp_parse_url(home_url($request_uri), PHP_URL_PATH) : '';
    $current_path = tpc_loader_normalize_path((string) $parsed_path);

    return $current_path;
}

function tpc_loader_path_groups()
{
    return apply_filters('tpc_loader_path_groups', [
        'tools' => [
            '/tien-ich',
            '/tien-ich-admin',
        ],
        'compare' => [
            '/tra-cuu',
            '/so-sanh',
            '/tra-cuu/so-sanh',
        ],
        'protein' => [
            '/protein-calculator',
            '/tien-ich/protein-calculator',
        ],
    ]);
}

function tpc_loader_expand_module_paths(array $module)
{
    $paths = isset($module['paths']) ? (array) $module['paths'] : [];
    $groups = isset($module['path_groups']) ? (array) $module['path_groups'] : [];
    $group_paths = [];
    $group_map = tpc_loader_path_groups();

    foreach ($groups as $group) {
        if (isset($group_map[$group])) {
            $group_paths = array_merge($group_paths, (array) $group_map[$group]);
        }
    }

    $all_paths = array_merge($paths, $group_paths);

    if (!empty($module['paths_filter']) && is_string($module['paths_filter'])) {
        $all_paths = (array) apply_filters($module['paths_filter'], $all_paths, $module);
    }

    return array_values(array_unique(array_filter(array_map('tpc_loader_normalize_path', $all_paths))));
}

function tpc_loader_front_path_matches(array $module)
{
    if (is_admin()) {
        return false;
    }

    $allowed_paths = tpc_loader_expand_module_paths($module);
    if (empty($allowed_paths)) {
        return false;
    }

    return in_array(tpc_loader_current_path(), $allowed_paths, true);
}

function tpc_loader_current_ajax_action()
{
    if (!wp_doing_ajax()) {
        return '';
    }

    return isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
}

function tpc_loader_modules()
{
    static $modules = null;

    if ($modules !== null) {
        return $modules;
    }

    $modules = [
        [
            'id' => 'product_compare',
            'file' => 'custom-functions/shortcode-product-compare.php',
            'path_groups' => ['tools', 'compare'],
            'paths_filter' => 'tpc_compare_allowed_paths',
            'ajax_actions' => [
                'tpc_product_compare_search',
                'tpc_product_compare_get_product',
            ],
        ],
        [
            'id' => 'protein_calculator',
            'file' => 'custom-functions/shortcode-protein-calculator.php',
            'path_groups' => ['tools', 'protein'],
            'ajax_actions' => [
                'protein_calculator_get_products',
            ],
        ],
    ];

    return apply_filters('tpc_loader_modules', $modules);
}

function tpc_loader_load_front_modules()
{
    foreach (tpc_loader_modules() as $module) {
        if (!empty($module['file']) && tpc_loader_front_path_matches($module)) {
            tpc_loader_require_relative($module['file']);
        }
    }
}
add_action('wp', 'tpc_loader_load_front_modules', PHP_INT_MAX - 1);

function tpc_loader_load_ajax_modules()
{
    $action = tpc_loader_current_ajax_action();
    if ($action === '') {
        return;
    }

    foreach (tpc_loader_modules() as $module) {
        $ajax_actions = isset($module['ajax_actions']) ? (array) $module['ajax_actions'] : [];
        if (!empty($module['file']) && in_array($action, $ajax_actions, true)) {
            tpc_loader_require_relative($module['file']);
        }
    }
}
add_action('init', 'tpc_loader_load_ajax_modules', 1);

/**
 * Force WooCommerce invoice/order-details emails to use child-theme override.
 * This avoids fallback to plugin/default template when other hooks interfere.
 */
function hroneous_force_email_order_details_template($emails)
{
    if (! is_object($emails) || ! method_exists($emails, 'order_details')) {
        return;
    }

    remove_action('woocommerce_email_order_details', array($emails, 'order_details'), 10);
    add_action('woocommerce_email_order_details', 'hroneous_render_custom_email_order_details', 10, 4);
}
add_action('woocommerce_email', 'hroneous_force_email_order_details_template', 999);

function hroneous_render_custom_email_order_details($order, $sent_to_admin, $plain_text, $email)
{
    if (! $order instanceof WC_Order) {
        return;
    }

    wc_get_template(
        'emails/email-order-details.php',
        array(
            'order'         => $order,
            'sent_to_admin' => $sent_to_admin,
            'plain_text'    => $plain_text,
            'email'         => $email,
        ),
        '',
        trailingslashit(get_stylesheet_directory()) . 'woocommerce/'
    );
}

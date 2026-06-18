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
        wp_enqueue_style(
            'hithean-custom-style',
            get_stylesheet_directory_uri() . '/css/custom.css',
            array('roneous-child-style'),
            filemtime(get_stylesheet_directory() . '/css/custom.css')
        );

        if (is_page(['an-new-chapter', 'anc-huu-co', 'anc-phan-phoi'])) {
            wp_enqueue_style(
                'hithean-an-new-chapter-style',
                get_stylesheet_directory_uri() . '/pages/an-new-chapter/an-new-chapter.css',
                array('hithean-custom-style'),
                filemtime(get_stylesheet_directory() . '/pages/an-new-chapter/an-new-chapter.css')
            );
            wp_enqueue_script(
                'hithean-an-new-chapter-script',
                get_stylesheet_directory_uri() . '/pages/an-new-chapter/an-new-chapter.js',
                array(),
                filemtime(get_stylesheet_directory() . '/pages/an-new-chapter/an-new-chapter.js'),
                true
            );
        }

        if (function_exists('is_cart') && is_cart()) {
            wp_enqueue_style(
                'roneous-child-cart-style',
                get_stylesheet_directory_uri() . '/css/page-cart.css',
                array('hithean-custom-style'),
                filemtime(get_stylesheet_directory() . '/css/page-cart.css')
            );

            wp_add_inline_script('jquery', <<<'JS'
jQuery(function($) {
    var updateTimer = null;

    function queueCartUpdate() {
        var $form = $('form.woocommerce-cart-form');
        var $button = $form.find('button[name="update_cart"]');

        if (!$form.length || !$button.length || $button.prop('disabled')) {
            return;
        }

        window.clearTimeout(updateTimer);
        $('body').addClass('cart-is-updating');

        updateTimer = window.setTimeout(function() {
            $button.prop('disabled', false).trigger('click');
        }, 350);
    }

    $(document.body).on('input change', '.woocommerce-cart-form input.qty', function() {
        queueCartUpdate();
    });

    $(document.body).on('updated_wc_div updated_cart_totals wc_fragments_refreshed', function() {
        $('body').removeClass('cart-is-updating');
    });
});
JS);
        }

        if (function_exists('is_checkout') && is_checkout()) {
            wp_enqueue_style(
                'roneous-child-checkout-style',
                get_stylesheet_directory_uri() . '/css/page-checkout.css',
                array('hithean-custom-style'),
                filemtime(get_stylesheet_directory() . '/css/page-checkout.css')
            );
        }
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

function hithean_locate_grouped_template($template_name): string
{
    $template_name = str_replace('\\', '/', (string) $template_name);
    $template_name = basename($template_name);

    if ($template_name === '' || substr($template_name, -4) !== '.php') {
        return '';
    }

    $candidates = [];
    if (strpos($template_name, 'archive-') === 0) {
        $candidates[] = 'archives/' . $template_name;
    } elseif (strpos($template_name, 'single-') === 0) {
        $candidates[] = 'singles/' . $template_name;
    } elseif (strpos($template_name, 'template-') === 0) {
        $candidates[] = 'templates/' . $template_name;
    }

    $candidates[] = $template_name;

    return (string) locate_template(array_values(array_unique($candidates)), false, false);
}

function hithean_load_grouped_template($template): string
{
    if (is_admin()) {
        return (string) $template;
    }

    if (is_post_type_archive()) {
        $post_type = get_query_var('post_type');
        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }

        $post_type = sanitize_key((string) $post_type);
        if ($post_type !== '') {
            $archive_template = hithean_locate_grouped_template('archive-' . $post_type . '.php');
            if ($archive_template !== '') {
                return $archive_template;
            }
        }
    }

    if (is_singular()) {
        $post_id       = get_queried_object_id();
        $template_slug = $post_id > 0 ? (string) get_page_template_slug($post_id) : '';

        if ($template_slug !== '' && $template_slug !== 'default') {
            $page_template = hithean_locate_grouped_template($template_slug);
            if ($page_template !== '') {
                return $page_template;
            }
        }

        $post_type = sanitize_key((string) get_post_type());
        if ($post_type !== '') {
            $single_template = hithean_locate_grouped_template('single-' . $post_type . '.php');
            if ($single_template !== '') {
                return $single_template;
            }
        }
    }

    return (string) $template;
}
add_filter('template_include', 'hithean_load_grouped_template', 20);

// General includes with conditionally loaded files commented out
$general_includes = [
    //'custom-functions/test.php',
    //'custom-functions/hpos-order-check.php',
    'custom-functions/backend-optimize.php',
    'custom-functions/functional.php',
    'custom-functions/theme-code-cache.php',
    'custom-functions/woocommerce-settings.php',
    'custom-functions/swiper-slider.php',
    'custom-functions/popup-widget.php',
    'custom-functions/shortcode-field-content.php',
    'custom-functions/shortcode-greenspark-banner.php',
    'custom-functions/product-page.php',
    'custom-functions/product-linking.php',
    'custom-functions/product-list-page.php',
    'custom-functions/pos-post-type.php',
    // 'custom-functions/recipe-post-type.php',
    'custom-functions/shortcode-recipes.php',
    'custom-functions/shortcode-posts.php',
    'custom-functions/shortcode-pos.php',
    'custom-functions/shortcode-bank-transfer-confirmation.php',
    'custom-functions/shortcode-payment-pending-confirm.php',
    'custom-functions/shortcode-google-docs.php',
    'custom-functions/shortcode-embed.php',
    'custom-functions/shortcode-order-shipped.php',
    'custom-functions/shortcode-order-export-confirm.php',
    'custom-functions/admin-settings-tools.php',
    'custom-functions/product-tab-post-type.php',
    'custom-functions/blogpost-metabox.php',
    'custom-functions/product-metabox.php',

    /* WOO UI */
    'custom-functions/lucky-wheel.php',

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

if (is_admin() && file_exists(__DIR__ . '/custom-functions/meta-key-rename-tool.php')) {
    require_once(__DIR__ . '/custom-functions/meta-key-rename-tool.php');
}

function child_theme_should_load_post_editor_tools(): bool
{
    if (!is_admin()) {
        return false;
    }

    $admin_page = isset($_SERVER['PHP_SELF']) ? basename((string) wp_unslash($_SERVER['PHP_SELF'])) : '';
    if (!in_array($admin_page, ['post.php', 'post-new.php'], true)) {
        return false;
    }

    if ($admin_page === 'post-new.php') {
        return true;
    }

    $post_id = 0;
    if (isset($_GET['post'])) {
        $post_id = absint($_GET['post']);
    } elseif (isset($_POST['post_ID'])) {
        $post_id = absint($_POST['post_ID']);
    }

    return $post_id > 0;
}

/*
if (child_theme_should_load_post_editor_tools()) {
    require_once(__DIR__ . '/custom-functions/editor-tools/editor-tools.php');
}
*/

add_action('wp', function (): void {
    if (!is_singular()) {
        return;
    }

    $post = get_post();
    if (!$post instanceof WP_Post) {
        return;
    }

    $post_content = (string) $post->post_content;
    if (stripos($post_content, '<table') === false && strpos($post_content, 'ivar-content-button') === false) {
        return;
    }

    require_once(__DIR__ . '/custom-functions/editor-tools/editor-tools.php');
});

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

        if (in_array($screen_id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            require_once __DIR__ . '/custom-functions/edit-order-page.php';
        }
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

function child_theme_get_default_admin_color_scheme(): string
{
    return 'modern';
}

function child_theme_set_user_admin_color_scheme(int $user_id): void
{
    if ($user_id <= 0) {
        return;
    }

    update_user_option($user_id, 'admin_color', child_theme_get_default_admin_color_scheme(), true);
}

function child_theme_force_default_admin_color_scheme($color = '')
{
    return child_theme_get_default_admin_color_scheme();
}
add_filter('get_user_option_admin_color', 'child_theme_force_default_admin_color_scheme', 20);

function child_theme_apply_default_admin_color_to_current_user(): void
{
    if (!is_user_logged_in()) {
        return;
    }

    child_theme_set_user_admin_color_scheme(get_current_user_id());
}
add_action('admin_init', 'child_theme_apply_default_admin_color_to_current_user', 1);

function child_theme_apply_default_admin_color_on_login($user_login, $user): void
{
    if ($user instanceof WP_User) {
        child_theme_set_user_admin_color_scheme((int) $user->ID);
    }
}
add_action('wp_login', 'child_theme_apply_default_admin_color_on_login', 10, 2);

function child_theme_apply_default_admin_color_to_new_user($user_id): void
{
    child_theme_set_user_admin_color_scheme((int) $user_id);
}
add_action('user_register', 'child_theme_apply_default_admin_color_to_new_user', 10, 1);
add_action('personal_options_update', 'child_theme_apply_default_admin_color_to_new_user', 99, 1);
add_action('edit_user_profile_update', 'child_theme_apply_default_admin_color_to_new_user', 99, 1);

function child_theme_migrate_default_admin_color_scheme(): void
{
    $migration_key = 'child_theme_modern_admin_color_migrated';
    if (get_option($migration_key) === '1') {
        return;
    }

    $user_ids = get_users([
        'fields' => 'ids',
    ]);

    foreach ($user_ids as $user_id) {
        child_theme_set_user_admin_color_scheme((int) $user_id);
    }

    update_option($migration_key, '1', false);
}
add_action('admin_init', 'child_theme_migrate_default_admin_color_scheme', 2);


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
            '/an-new-chapter',
        ],
        'anc_landing' => [
            '/an-new-chapter',
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
        [
            'id' => 'wc_product_field',
            'file' => 'custom-functions/shortcode-wc-product-field.php',
            'path_groups' => ['anc_landing'],
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

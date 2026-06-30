<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin: nạp file admin theo screen + admin styles, và ép admin color scheme mặc định.
 */

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
        require_once HITHEAN_THEME_DIR . '/custom-functions/orders-admin.php';
        require_once HITHEAN_THEME_DIR . '/custom-functions/order-search.php';

        if (in_array($screen_id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            require_once HITHEAN_THEME_DIR . '/custom-functions/edit-order-page.php';
        }
    }

    // === Plugins page ===
    if ($screen_id === 'plugins') {
        require_once HITHEAN_THEME_DIR . '/custom-functions/plugins-page.php';
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

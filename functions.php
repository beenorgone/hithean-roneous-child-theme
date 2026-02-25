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


/**
 * Vá lỗi i18n.min.js "Cannot read properties of undefined (reading 'replace')"
 * Chèn Script vá lỗi trực tiếp vào thẻ <head> admin.
 */
add_action('admin_print_scripts', 'hithean_patch_i18n_error_direct', 1);
function hithean_patch_i18n_error_direct() {
    // Chỉ áp dụng cho các trang WooCommerce
    if ( ! isset($_GET['page']) || strpos($_GET['page'], 'wc-') === false ) {
        return;
    }
    ?>
    <script type="text/javascript">
        /* POLYFILL: Vá lỗi sprintf trong thư viện wp.i18n */
        (function() {
            window.wp = window.wp || {};
            window.wp.i18n = window.wp.i18n || {};

            // Lưu lại hàm sprintf gốc nếu có (đề phòng)
            var originalSprintf = window.wp.i18n.sprintf;

            // Định nghĩa lại sprintf an toàn
            window.wp.i18n.sprintf = function( format ) {
                // Nếu format bị undefined (nguyên nhân gây lỗi), trả về chuỗi rỗng
                if ( typeof format === 'undefined' ) {
                    console.warn('Fixed i18n crash: format string was undefined');
                    return '';
                }

                // Nếu thư viện gốc đã tải, thử dùng nó
                if ( typeof originalSprintf === 'function' ) {
                    try {
                        // Gọi hàm gốc với các tham số
                        return originalSprintf.apply( this, arguments );
                    } catch (e) {
                        // Nếu hàm gốc lỗi, trả về format ban đầu thay vì làm sập trang
                        return format;
                    }
                }

                // Fallback đơn giản nếu chưa tải thư viện
                return format;
            };
        })();
    </script>
    <?php
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end enqueue (styles/scripts), language setup, nocache header, menus.
 */

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

        if (is_front_page() || is_page(['an-new-chapter', 'anc-huu-co', 'anc-phan-phoi', 'an-new-chapter-b2b', 'an-new-chapter-b2b-organic', 'an-new-chapter-affiliate'])) {
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

            if (is_page(['an-new-chapter-b2b', 'an-new-chapter-b2b-organic'])) {
                wp_enqueue_style(
                    'hithean-an-new-chapter-b2b-style',
                    get_stylesheet_directory_uri() . '/pages/an-new-chapter/an-new-chapter-b2b.css',
                    array('hithean-an-new-chapter-style'),
                    filemtime(get_stylesheet_directory() . '/pages/an-new-chapter/an-new-chapter-b2b.css')
                );
            }

            if (is_page('an-new-chapter-affiliate')) {
                wp_enqueue_style(
                    'hithean-an-new-chapter-affiliate-style',
                    get_stylesheet_directory_uri() . '/pages/an-new-chapter-affiliate/an-new-chapter-affiliate.css',
                    array('hithean-an-new-chapter-style'),
                    filemtime(get_stylesheet_directory() . '/pages/an-new-chapter-affiliate/an-new-chapter-affiliate.css')
                );
            }
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

/**
 * Use one stylesheet for all fonts used by the parent and child theme.
 *
 * The old setup printed the child fonts directly in header.php and enqueued a
 * second Google Fonts stylesheet from the parent. Both contained Oswald, which
 * created duplicate CSS work and an extra third-party request on every page.
 */
if (!function_exists('hithean_enqueue_consolidated_fonts')) {
    function hithean_enqueue_consolidated_fonts(): void
    {
        if (is_admin()) {
            return;
        }

        wp_dequeue_style('roneous-google-fonts');
        wp_deregister_style('roneous-google-fonts');

        wp_enqueue_style(
            'hithean-google-fonts',
            'https://fonts.googleapis.com/css2?family=Be+Vietnam:wght@400;500&family=Hind:wght@100;300;400;600;700&family=IBM+Plex+Sans:wght@400;500&family=Open+Sans:wght@300;400&family=Oswald:wght@100;300;400;500;600;700&display=swap',
            [],
            null
        );
    }
    // The parent theme enqueues its fonts at priority 110.
    add_action('wp_enqueue_scripts', 'hithean_enqueue_consolidated_fonts', 120);
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

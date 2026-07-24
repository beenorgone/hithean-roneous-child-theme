<?php
if (!defined('ABSPATH')) exit;

function hithean_should_enqueue_popup_widget(): bool
{
    if (is_admin()) {
        return false;
    }

    if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        return (bool) apply_filters('hithean_enable_popup_widget_on_product_taxonomy', false);
    }

    if (function_exists('is_shop') && is_shop()) {
        return (bool) apply_filters('hithean_enable_popup_widget_on_shop', false);
    }

    return (bool) apply_filters('hithean_enqueue_popup_widget', true);
}

function popup_widget_enqueue_scripts() {
    if (!hithean_should_enqueue_popup_widget()) {
        return;
    }

    wp_enqueue_style(
        'popup-widget-style',
        get_stylesheet_directory_uri() . '/css/popup-widget.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'popup-widget-script',
        get_stylesheet_directory_uri() . '/js/popup-widget.js',
        array('jquery'),
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'popup_widget_enqueue_scripts');

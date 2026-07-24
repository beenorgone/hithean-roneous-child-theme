<?php
if (!defined('ABSPATH')) exit;

function hithean_should_enqueue_swiper_slider(): bool
{
    if (is_admin()) {
        return false;
    }

    if (function_exists('is_product_taxonomy') && is_product_taxonomy()) {
        return false;
    }

    if (function_exists('is_shop') && is_shop()) {
        return false;
    }

    if (function_exists('is_cart') && is_cart()) {
        return false;
    }

    if (function_exists('is_checkout') && is_checkout()) {
        return false;
    }

    return (bool) apply_filters('hithean_enqueue_swiper_slider', is_front_page() || is_singular());
}

function enqueue_swiper_slider() {
    if (!hithean_should_enqueue_swiper_slider()) {
        return;
    }

    wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), null);
    wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array('jquery'), null, true);
    wp_enqueue_script('custom-swiper-init', get_stylesheet_directory_uri() . '/js/swiper-init.js', array('swiper-js'), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_swiper_slider');

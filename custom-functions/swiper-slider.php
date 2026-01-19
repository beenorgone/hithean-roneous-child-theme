<?php
function enqueue_swiper_slider() {
    // CSS của Swiper
    wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), null);
    // JavaScript của Swiper
    wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array('jquery'), null, true);
    // JavaScript khởi tạo slider
    wp_enqueue_script('custom-swiper-init', get_stylesheet_directory_uri() . '/js/swiper-init.js', array('swiper-js'), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_swiper_slider');


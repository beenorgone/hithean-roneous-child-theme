<?php
// Add CSS and JS for the popup widget in the child theme
function popup_widget_enqueue_scripts() {
    // Register and enqueue CSS
    wp_enqueue_style(
        'popup-widget-style', // Unique identifier
        get_stylesheet_directory_uri() . '/css/popup-widget.css', // Path to the CSS file
        array(), // No dependencies
        '1.0.0' // Version
    );

    // Register and enqueue JS
    wp_enqueue_script(
        'popup-widget-script', // Unique identifier
        get_stylesheet_directory_uri() . '/js/popup-widget.js', // Path to the JS file
        array('jquery'), // Dependency on jQuery
        '1.0.0', // Version
        true // Load in the footer
    );
}
add_action('wp_enqueue_scripts', 'popup_widget_enqueue_scripts');

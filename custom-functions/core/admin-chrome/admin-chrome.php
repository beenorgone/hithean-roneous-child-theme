<?php
/** Browser-local navigation toggle for standard WordPress backend screens. */
if (!defined('ABSPATH')) {
    exit;
}

function hithean_admin_chrome_enqueue(): void
{
    if (!is_admin()) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->is_block_editor() || (defined('IFRAME_REQUEST') && IFRAME_REQUEST)) {
        return;
    }

    $relative_path = '/custom-functions/core/admin-chrome/assets/';
    $directory = get_stylesheet_directory() . $relative_path;
    $uri = get_stylesheet_directory_uri() . $relative_path;
    wp_enqueue_style('hithean-admin-chrome', $uri . 'admin-chrome.css', [], (string) filemtime($directory . 'admin-chrome.css'));
    // Apply the saved preference in the head, before the admin UI paints.
    wp_enqueue_script('hithean-admin-chrome', $uri . 'admin-chrome.js', [], (string) filemtime($directory . 'admin-chrome.js'), false);
}
add_action('admin_enqueue_scripts', 'hithean_admin_chrome_enqueue');

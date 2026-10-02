<?php
if (!defined('ABSPATH')) exit;

/**
 * Grouped template router: cho phép đặt single-*.php trong singles/,
 * archive-*.php trong archives/, template-*.php trong templates/.
 */

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

/**
 * File HTML chứa nội dung của page dùng template "Landing Page"
 * (pages/{slug}/{slug}.html). Trả '' nếu page không dùng template đó.
 */
function hithean_landing_page_html_path($post = null): string
{
    $post = get_post($post);
    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return '';
    }

    if (basename((string) get_page_template_slug($post)) !== 'template-landing-page.php') {
        return '';
    }

    $slug = (string) $post->post_name;
    return $slug !== '' ? get_stylesheet_directory() . '/pages/' . $slug . '/' . $slug . '.html' : '';
}

/**
 * Nội dung thô của trang đơn, dùng để dò shortcode/markup khi nạp module hoặc
 * enqueue asset có điều kiện. Page landing render từ file HTML trong theme
 * (không phải post_content) nên phải gộp cả file đó, nếu không sẽ dò trượt.
 */
function hithean_singular_raw_content($post = null): string
{
    static $cache = [];

    $post = get_post($post);
    if (!$post instanceof WP_Post) {
        return '';
    }

    if (isset($cache[$post->ID])) {
        return $cache[$post->ID];
    }

    $content = (string) $post->post_content;
    $path    = hithean_landing_page_html_path($post);
    if ($path !== '' && is_readable($path)) {
        $content .= "\n" . (string) file_get_contents($path);
    }

    return $cache[$post->ID] = $content;
}

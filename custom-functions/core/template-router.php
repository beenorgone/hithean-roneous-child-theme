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

<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('THEAN_THEME_CODE_VERSION_OPTION')) {
    define('THEAN_THEME_CODE_VERSION_OPTION', 'thean_theme_code_version');
}

if (!function_exists('thean_theme_git_version')) {
    function thean_theme_git_version(): string
    {
        $theme_root = trailingslashit(get_stylesheet_directory());
        $git_head = $theme_root . '.git/HEAD';

        if (!is_file($git_head) || !is_readable($git_head)) {
            return '';
        }

        $head = trim((string) file_get_contents($git_head));
        if ($head === '') {
            return '';
        }

        if (strpos($head, 'ref: ') !== 0) {
            return sanitize_text_field($head);
        }

        $ref = trim(substr($head, 5));
        if ($ref === '') {
            return '';
        }

        $ref_path = $theme_root . '.git/' . ltrim($ref, '/');
        if (is_file($ref_path) && is_readable($ref_path)) {
            return sanitize_text_field(trim((string) file_get_contents($ref_path)));
        }

        $packed_refs = $theme_root . '.git/packed-refs';
        if (!is_file($packed_refs) || !is_readable($packed_refs)) {
            return '';
        }

        foreach (file($packed_refs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#') {
                continue;
            }

            [$hash, $name] = array_pad(preg_split('/\s+/', trim($line), 2), 2, '');
            if ($name === $ref) {
                return sanitize_text_field($hash);
            }
        }

        return '';
    }
}

if (!function_exists('thean_theme_code_version')) {
    function thean_theme_code_version(): string
    {
        static $version = null;

        if ($version !== null) {
            return $version;
        }

        $git_version = thean_theme_git_version();
        if ($git_version !== '') {
            $version = $git_version;
            return $version;
        }

        $parts = [];
        $watch_files = [
            get_stylesheet_directory() . '/functions.php',
            get_stylesheet_directory() . '/style.css',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel.php',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/admin.php',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/ui.php',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/lucky-wheel.css',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/lucky-wheel.min.css',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/lucky-wheel.js',
            get_stylesheet_directory() . '/custom-functions/lucky-wheel/lucky-wheel.min.js',
        ];

        foreach ($watch_files as $path) {
            if (is_file($path)) {
                $parts[] = basename($path) . ':' . (string) filemtime($path);
            }
        }

        $version = hash('sha256', implode('|', $parts));

        return $version;
    }
}

if (!function_exists('thean_theme_purge_cache')) {
    function thean_theme_purge_cache(): void
    {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        if (function_exists('rocket_clean_minify')) {
            rocket_clean_minify();
        }

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
        } else {
            do_action('litespeed_purge_all');
        }

        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
}

if (!function_exists('thean_theme_maybe_purge_code_cache')) {
    function thean_theme_maybe_purge_code_cache(): void
    {
        $current_version = thean_theme_code_version();
        if ($current_version === '') {
            return;
        }

        $stored_version = (string) get_option(THEAN_THEME_CODE_VERSION_OPTION, '');
        if ($stored_version === $current_version) {
            return;
        }

        thean_theme_purge_cache();
        update_option(THEAN_THEME_CODE_VERSION_OPTION, $current_version, false);
    }
}
add_action('init', 'thean_theme_maybe_purge_code_cache', 1);

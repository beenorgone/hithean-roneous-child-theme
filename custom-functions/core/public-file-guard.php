<?php
if (!defined('ABSPATH')) exit;

function hithean_is_sensitive_wp_content_log_request(): bool
{
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $request_path = parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($request_path) || $request_path === '') {
        return false;
    }

    $request_path = rawurldecode($request_path);
    return (bool) preg_match('#(?:^|/)wp-content/(?:debug|error_log|php_errors)\.log$#i', $request_path);
}

function hithean_block_sensitive_wp_content_logs(): void
{
    if (!hithean_is_sensitive_wp_content_log_request()) {
        return;
    }

    status_header(403);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    exit;
}
add_action('init', 'hithean_block_sensitive_wp_content_logs', 0);

function hithean_wp_content_log_htaccess_rules(): string
{
    return implode("\n", [
        '<IfModule mod_authz_core.c>',
        '    <FilesMatch "^(debug|error_log|php_errors)\.log$">',
        '        Require all denied',
        '    </FilesMatch>',
        '</IfModule>',
        '<IfModule !mod_authz_core.c>',
        '    <FilesMatch "^(debug|error_log|php_errors)\.log$">',
        '        Order allow,deny',
        '        Deny from all',
        '    </FilesMatch>',
        '</IfModule>',
    ]);
}

function hithean_ensure_wp_content_log_htaccess(): void
{
    if (!defined('WP_CONTENT_DIR')) {
        return;
    }

    $marker = 'HITHEAN block public logs';
    $rules = hithean_wp_content_log_htaccess_rules();
    $hash = md5($rules);
    $option_key = 'hithean_wp_content_log_htaccess_hash';
    $transient_key = 'hithean_wp_content_log_htaccess_checked';

    if (get_transient($transient_key) === $hash && get_option($option_key) === $hash) {
        return;
    }

    $htaccess = trailingslashit(WP_CONTENT_DIR) . '.htaccess';
    $content = is_readable($htaccess) ? (string) file_get_contents($htaccess) : '';
    $block = '# BEGIN ' . $marker . "\n" . $rules . "\n" . '# END ' . $marker;

    if (strpos($content, '# BEGIN ' . $marker) !== false) {
        $pattern = '/# BEGIN ' . preg_quote($marker, '/') . '\R.*?# END ' . preg_quote($marker, '/') . '/s';
        $new_content = preg_replace($pattern, $block, $content, 1);
        if (!is_string($new_content)) {
            return;
        }
    } else {
        $new_content = rtrim($content) . ($content !== '' ? "\n\n" : '') . $block . "\n";
    }

    $rules_present = strpos($content, '# BEGIN ' . $marker) !== false && strpos($content, $rules) !== false;
    if (!$rules_present && $new_content !== $content) {
        $can_write = (file_exists($htaccess) && is_writable($htaccess))
            || (!file_exists($htaccess) && is_dir(WP_CONTENT_DIR) && is_writable(WP_CONTENT_DIR));

        if ($can_write) {
            $rules_present = file_put_contents($htaccess, $new_content, LOCK_EX) !== false;
        }
    }

    if ($rules_present) {
        set_transient($transient_key, $hash, 12 * HOUR_IN_SECONDS);
        update_option($option_key, $hash, false);
    } else {
        delete_transient($transient_key);
        delete_option($option_key);
    }
}
add_action('init', 'hithean_ensure_wp_content_log_htaccess', 1);
add_action('after_switch_theme', 'hithean_ensure_wp_content_log_htaccess');

function hithean_admin_notice_wp_content_log_htaccess(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $rules = hithean_wp_content_log_htaccess_rules();
    $hash = md5($rules);
    if (get_option('hithean_wp_content_log_htaccess_hash') === $hash) {
        return;
    }

    $message = sprintf(
        /* translators: %s: wp-content/.htaccess */
        __('Security notice: public access to wp-content debug logs may still be open. Add the Hithean log-block rules to %s, or add an equivalent Nginx/server rule denying /wp-content/debug.log, /wp-content/error_log.log, and /wp-content/php_errors.log.', 'hithean-roneous-child'),
        '<code>wp-content/.htaccess</code>'
    );

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        wp_kses($message, ['code' => []])
    );
}
add_action('admin_notices', 'hithean_admin_notice_wp_content_log_htaccess');

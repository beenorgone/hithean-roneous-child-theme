<?php

if (!defined('ABSPATH')) {
    exit;
}

define('THEAN_LW_CLEANUP_GRACE_DAYS', 7);
define('THEAN_LW_LOG_TRUNCATE_DAYS', 30);
define('THEAN_LW_CLEANUP_LOG_HANDLE', 'thean-lucky-wheel-cleanup');
define('THEAN_LW_CLEANUP_CRON_HOOK', 'thean_lw_daily_cleanup');
define('THEAN_LW_CLEANUP_BATCH_SIZE', 500);
define('THEAN_LW_LOG_LAST_TRUNCATE_OPTION', 'thean_lw_log_last_truncate');

/**
 * Matches the leading ISO-8601 timestamp WC_Log_Handler_File prefixes every
 * log line with (see WC_Log_Handler::format_time(), date('c', $timestamp)).
 */
define('THEAN_LW_LOG_LINE_TIMESTAMP_REGEX', '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})/');

function thean_lw_schedule_cleanup_cron(): void
{
    if (!wp_next_scheduled(THEAN_LW_CLEANUP_CRON_HOOK)) {
        wp_schedule_event(time(), 'daily', THEAN_LW_CLEANUP_CRON_HOOK);
    }
}
add_action('init', 'thean_lw_schedule_cleanup_cron');

function thean_lw_unschedule_cleanup_cron(): void
{
    $timestamp = wp_next_scheduled(THEAN_LW_CLEANUP_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, THEAN_LW_CLEANUP_CRON_HOOK);
    }
}
add_action('switch_theme', 'thean_lw_unschedule_cleanup_cron');

add_action(THEAN_LW_CLEANUP_CRON_HOOK, 'thean_lw_run_daily_cleanup');

function thean_lw_run_daily_cleanup(): void
{
    thean_lw_run_expired_coupon_cleanup();
    thean_lw_prune_cleanup_log();
}

function thean_lw_cleanup_logger(): ?WC_Logger
{
    return function_exists('wc_get_logger') ? wc_get_logger() : null;
}

function thean_lw_format_log_timestamp(int $timestamp): string
{
    return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC' : 'n/a';
}

function thean_lw_log_expired_coupon(WC_Coupon $coupon, int $expired_at): void
{
    $logger = thean_lw_cleanup_logger();
    if (!$logger) {
        return;
    }

    $message = sprintf(
        'Đã xóa coupon Lucky Wheel hết hạn | code=%s | reward_id=%s | reward_type=%s | contact_type=%s | contact_value=%s | claimed_at=%s | expired_at=%s | deleted_at=%s',
        $coupon->get_code(),
        (string) $coupon->get_meta('_thean_lw_reward_id', true),
        (string) $coupon->get_meta('_thean_lw_reward_type', true),
        (string) $coupon->get_meta('_thean_lw_contact_type', true),
        (string) $coupon->get_meta('_thean_lw_contact_value', true),
        thean_lw_format_log_timestamp((int) $coupon->get_meta('_thean_lw_claimed_at', true)),
        thean_lw_format_log_timestamp($expired_at),
        thean_lw_format_log_timestamp(time())
    );

    $logger->info($message, ['source' => THEAN_LW_CLEANUP_LOG_HANDLE]);
}

/**
 * Deletes Lucky Wheel coupons whose expiry date is more than
 * THEAN_LW_CLEANUP_GRACE_DAYS days in the past, logging each one first.
 */
function thean_lw_run_expired_coupon_cleanup(): int
{
    if (!class_exists('WC_Coupon')) {
        return 0;
    }

    $cutoff = time() - (THEAN_LW_CLEANUP_GRACE_DAYS * DAY_IN_SECONDS);
    $skip_used = thean_lw_cleanup_skip_used_coupons();
    $deleted = 0;

    $coupon_ids = get_posts([
        'post_type' => 'shop_coupon',
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => THEAN_LW_CLEANUP_BATCH_SIZE,
        'orderby' => 'date',
        'order' => 'ASC',
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'meta_query' => [
            [
                'key' => '_thean_lw_claimed_at',
                'compare' => 'EXISTS',
            ],
        ],
    ]);

    foreach ($coupon_ids as $coupon_id) {
        $coupon = new WC_Coupon((int) $coupon_id);
        $expires = $coupon->get_date_expires();

        if (!$expires instanceof WC_DateTime) {
            continue;
        }

        $expires_ts = $expires->getTimestamp();
        if ($expires_ts > $cutoff) {
            continue;
        }

        if ($skip_used && $coupon->get_usage_count() > 0) {
            continue;
        }

        thean_lw_log_expired_coupon($coupon, $expires_ts);
        wp_delete_post($coupon_id, true);
        $deleted++;
    }

    return $deleted;
}

function thean_lw_parse_log_line_timestamp(string $line): ?int
{
    if (!preg_match(THEAN_LW_LOG_LINE_TIMESTAMP_REGEX, $line, $matches)) {
        return null;
    }

    $timestamp = strtotime($matches[1]);

    return $timestamp !== false ? $timestamp : null;
}

/**
 * "Truncate" here means keeping a rolling THEAN_LW_LOG_TRUNCATE_DAYS window:
 * drop only the log lines older than that, keep everything newer.
 */
function thean_lw_prune_cleanup_log(): int
{
    $path = thean_lw_cleanup_log_file_path();
    if ($path === '' || !is_file($path) || !is_readable($path) || !is_writable($path)) {
        return 0;
    }

    $cutoff = time() - (THEAN_LW_LOG_TRUNCATE_DAYS * DAY_IN_SECONDS);
    $handle = fopen($path, 'r');
    if (!$handle) {
        return 0;
    }

    $kept = [];
    $removed = 0;
    $last_timestamp = null;

    while (($line = fgets($handle)) !== false) {
        $timestamp = thean_lw_parse_log_line_timestamp($line);

        if ($timestamp !== null) {
            $last_timestamp = $timestamp;
        }

        // A wrapped continuation line (no leading timestamp) inherits the
        // previous entry's timestamp so multi-line log entries stay intact.
        $effective_timestamp = $timestamp ?? $last_timestamp;

        if ($effective_timestamp !== null && $effective_timestamp < $cutoff) {
            $removed++;
            continue;
        }

        $kept[] = $line;
    }
    fclose($handle);

    update_option(THEAN_LW_LOG_LAST_TRUNCATE_OPTION, time(), false);

    if ($removed === 0) {
        return 0;
    }

    $tmp_path = $path . '.tmp';
    if (file_put_contents($tmp_path, implode('', $kept), LOCK_EX) === false) {
        return 0;
    }
    rename($tmp_path, $path);

    return $removed;
}

function thean_lw_cleanup_log_file_path(): string
{
    return class_exists('WC_Log_Handler_File')
        ? (string) WC_Log_Handler_File::get_log_file_path(THEAN_LW_CLEANUP_LOG_HANDLE)
        : '';
}

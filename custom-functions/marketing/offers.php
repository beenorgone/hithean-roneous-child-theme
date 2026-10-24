<?php
/** Structured offers index. Expensive work only runs in background/CLI. */
defined('ABSPATH') || exit;

const HITHEAN_OFFERS_RUN = 'hithean_offers_run';
const HITHEAN_OFFERS_SNAPSHOT = 'hithean_offers_snapshot';
const HITHEAN_OFFERS_FRESHNESS = 8 * DAY_IN_SECONDS;

require_once __DIR__ . '/offers/store.php';
require_once __DIR__ . '/offers/extract.php';
require_once __DIR__ . '/offers/structured.php';
require_once __DIR__ . '/offers/worker.php';
require_once __DIR__ . '/offers/public.php';
if (is_admin()) { require_once __DIR__ . '/offers/admin.php'; }
if (defined('WP_CLI') && WP_CLI) { require_once __DIR__ . '/offers/cli.php'; }

function hithean_offers_settings(): array {
    return array_merge(['enabled' => false, 'ai_enabled' => true, 'provider' => 'auto', 'model' => '', 'preview' => true, 'source' => 'database'], (array) get_option('hithean_offers_settings', []));
}

/** Migrate the former daily event without changing saved publication/automation switches. */
function hithean_offers_sync_schedule(): void {
    if (wp_next_scheduled('hithean_offers_daily')) { wp_clear_scheduled_hook('hithean_offers_daily'); }
    if (!hithean_offers_settings()['enabled']) {
        if (wp_next_scheduled('hithean_offers_weekly')) { wp_clear_scheduled_hook('hithean_offers_weekly'); }
        return;
    }
    if (!wp_next_scheduled('hithean_offers_weekly')) {
        wp_schedule_event(time() + 7 * DAY_IN_SECONDS, 'weekly', 'hithean_offers_weekly');
    }
}

add_action('init', static function (): void {
    register_post_type('hithean_offer', [
        'label' => 'Ưu đãi đã thu thập', 'public' => false, 'show_ui' => false,
        'rewrite' => false, 'supports' => ['title'], 'can_export' => false,
    ]);
    hithean_offers_sync_schedule();
});
add_action('hithean_offers_weekly', static function (): void {
    if (hithean_offers_settings()['enabled']) {
        try { hithean_offers_start(); update_option('hithean_offers_scheduler_error', '', false); }
        catch (Throwable $error) { update_option('hithean_offers_scheduler_error', sanitize_text_field($error->getMessage()), false); }
    }
    hithean_offers_cleanup();
});
add_action('hithean_offers_work', 'hithean_offers_work');
add_action('hithean_offers_boundary', 'hithean_offers_publish');
add_action('switch_theme', static function (): void {
    foreach (['hithean_offers_daily', 'hithean_offers_weekly', 'hithean_offers_work', 'hithean_offers_boundary'] as $hook) { wp_clear_scheduled_hook($hook); }
});
add_shortcode('hithean_offers', 'hithean_offers_shortcode');
add_action('wp_enqueue_scripts', 'hithean_offers_enqueue');

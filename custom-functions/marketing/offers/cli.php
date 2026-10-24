<?php
defined('ABSPATH') || exit;

/** Collect and inspect product offers using the same background queue as admin. */
class Hithean_Offers_CLI {
    /**
     * Queue a full scan or one published parent product.
     *
     * ## OPTIONS
     *
     * [--product-id=<id>]
     * : Refresh this product and recompute shared-rule catalog memberships.
     */
    public function scan($args, $assoc): void {
        try {
            if (isset($assoc['product-id']) && (!ctype_digit((string) $assoc['product-id']) || (int) $assoc['product-id'] < 1)) { throw new RuntimeException('product-id must be a positive product ID.'); }
            $run = hithean_offers_start(absint($assoc['product-id'] ?? 0));
            WP_CLI::success('Queued/existing run ' . $run['id'] . ' (' . $run['total'] . ' steps). Run wp cron event run --due-now with the server scheduler.');
        } catch (Throwable $error) { WP_CLI::error($error->getMessage()); }
    }
    /** Show current run, last successful scan and next scheduled run. */
    public function status(): void {
        $run = (array) get_option(HITHEAN_OFFERS_RUN, []); unset($run['ids'], $run['catalog_ids'], $run['product_success']);
        WP_CLI::line(wp_json_encode(['run' => $run, 'last_success' => get_option('hithean_offers_last_success', 0), 'next_run' => wp_next_scheduled('hithean_offers_daily'), 'scheduler_error' => get_option('hithean_offers_scheduler_error', ''), 'settings' => hithean_offers_settings()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
WP_CLI::add_command('hithean offers', 'Hithean_Offers_CLI');

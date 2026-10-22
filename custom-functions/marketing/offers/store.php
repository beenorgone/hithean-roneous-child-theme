<?php
defined('ABSPATH') || exit;

/** Atomic option locks, including compare-and-delete for an expired owner. */
function hithean_offers_lock(string $name, int $ttl = 600): string {
    global $wpdb;
    $key = 'hithean_offers_lock_' . $name;
    $token = wp_generate_uuid4();
    $value = ['token' => $token, 'until' => time() + $ttl];
    if (add_option($key, $value, '', false)) { return $token; }
    $old = get_option($key);
    if (is_array($old) && ($old['until'] ?? PHP_INT_MAX) < time()) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, maybe_serialize($old)));
        wp_cache_delete($key, 'options');
        if (add_option($key, $value, '', false)) { return $token; }
    }
    return '';
}
function hithean_offers_unlock(string $name, string $token): void {
    global $wpdb;
    if ($token === '') { return; }
    $key = 'hithean_offers_lock_' . $name;
    $old = get_option($key);
    if (is_array($old) && hash_equals((string) $old['token'], $token)) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, maybe_serialize($old)));
        wp_cache_delete($key, 'options');
    }
}
function hithean_offers_records(int $product_id = 0): array {
    $args = ['post_type' => 'hithean_offer', 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC'];
    if ($product_id) { $args['meta_key'] = '_ho_product'; $args['meta_value'] = $product_id; }
    $rows = [];
    foreach (get_posts($args) as $post) {
        $data = get_post_meta($post->ID, '_ho_data', true);
        if (is_array($data)) { $data['id'] = $post->ID; $rows[] = $data; }
    }
    return $rows;
}
function hithean_offers_save(array $data): int {
    $id = (int) ($data['id'] ?? 0);
    unset($data['id']);
    if (!$id) {
        $id = wp_insert_post(['post_type' => 'hithean_offer', 'post_status' => 'private', 'post_title' => $data['title']], true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        update_post_meta($id, '_ho_product', $data['product_id']);
    }
    update_post_meta($id, '_ho_data', $data);
    return (int) $id;
}
function hithean_offers_audit(int $id, string $action): void {
    $log = (array) get_post_meta($id, '_ho_audit', true);
    $log[] = ['at' => time(), 'user' => get_current_user_id(), 'action' => $action];
    update_post_meta($id, '_ho_audit', array_slice($log, -30));
}
function hithean_offers_status(array $row, ?int $now = null): string {
    $now = $now ?? time();
    if (in_array($row['status'], ['removed', 'rejected', 'review'], true)) { return $row['status']; }
    if (!empty($row['end']) && $row['end'] < $now) { return 'expired'; }
    if (($row['verified_at'] ?? 0) + HITHEAN_OFFERS_FRESHNESS <= $now) { return 'stale'; }
    if (!empty($row['start']) && $row['start'] > $now) { return 'scheduled'; }
    return 'active';
}
/** Per-product reconciliation only after a valid, complete page read. */
function hithean_offers_reconcile(int $product_id, array $candidates, string $source): void {
    $token = hithean_offers_lock('product_' . $product_id, 30);
    if (!$token) { throw new RuntimeException('Sản phẩm đang được duyệt hoặc đồng bộ.'); }
    try {
        $existing = [];
        foreach (hithean_offers_records($product_id) as $row) { $existing[$row['key']] = $row; }
        $seen = [];
        foreach ($candidates as $candidate) {
            $key = hash('sha256', $product_id . '|' . $candidate['kind'] . '|' . hithean_offers_text($candidate['evidence']) . '|' . ($candidate['conditions_hash'] ?? ''));
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $previous = $existing[$key] ?? [];
            // A rejection/approval applies only to the exact original source terms.
            $status = $candidate['safe'] ? 'active' : 'review';
            if (!empty($previous['decision']) && ($previous['status'] ?? '') !== 'removed') {
                $status = $previous['decision'] === 'approve' ? 'active' : 'rejected';
                foreach (['start', 'end', 'scope', 'kind'] as $field) { $candidate[$field] = $previous[$field] ?? $candidate[$field]; }
            }
            $data = array_merge($previous, $candidate, [
                'key' => $key, 'product_id' => $product_id, 'source' => $source, 'status' => $status,
                'verified_at' => time(), 'first_seen' => $previous['first_seen'] ?? time(),
            ]);
            if (($previous['status'] ?? '') === 'removed') { unset($data['decision']); }
            $id = hithean_offers_save($data);
            if (!$previous) { hithean_offers_audit($id, 'collected'); }
        }
        foreach ($existing as $key => $row) {
            if (!isset($seen[$key]) && $row['status'] !== 'removed') {
                $row['status'] = 'removed';
                unset($row['decision']);
                hithean_offers_save($row);
                hithean_offers_audit($row['id'], 'source_removed');
            }
        }
    } finally { hithean_offers_unlock('product_' . $product_id, $token); }
}
/** A single option replacement is the public snapshot commit. */
function hithean_offers_publish(): void {
    $token = hithean_offers_lock('publish', 120);
    if (!$token) { wp_schedule_single_event(time() + 130, 'hithean_offers_boundary'); return; }
    try {
        $items = []; $boundary = time() + HOUR_IN_SECONDS;
        $old = (array) get_option(HITHEAN_OFFERS_SNAPSHOT, []);
        $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
        // A boundary tick must not expose a partially collected batch.
        $collecting = in_array($run['status'] ?? '', ['queued', 'running'], true);
        $records = $collecting ? ($old['items'] ?? []) : hithean_offers_records();
        foreach ($records as $row) {
            $status = hithean_offers_status($row);
            if (!in_array($status, ['active', 'scheduled'], true)) { continue; }
            $product = wc_get_product($row['product_id']);
            if (!$product || $product->get_status() !== 'publish' || $product->get_catalog_visibility() === 'hidden') { continue; }
            $row['product_name'] = $product->get_name();
            $row['url'] = get_permalink($product->get_id());
            $row['image'] = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src();
            $row['in_stock'] = $product->is_in_stock();
            $row['variable'] = $product->is_type('variable');
            $row['pinned'] = !empty($row['pinned']);
            foreach ([$row['start'] ?? 0, !empty($row['end']) ? $row['end'] + 1 : 0, $row['verified_at'] + HITHEAN_OFFERS_FRESHNESS] as $date) {
                if ($date > time()) { $boundary = min($boundary, $date); }
            }
            if ($status === 'active') { $items[] = $row; }
        }
        usort($items, static function ($a, $b): int {
            return [$b['in_stock'], $b['pinned'], -($b['end'] ?: PHP_INT_MAX), -$b['id']] <=> [$a['in_stock'], $a['pinned'], -($a['end'] ?: PHP_INT_MAX), -$a['id']];
        });
        $next = ['items' => $items, 'updated_at' => time(), 'valid_until' => $boundary];
        if (($old['items'] ?? []) !== $items && $old) { update_option('hithean_offers_previous_snapshot', $old, false); }
        update_option(HITHEAN_OFFERS_SNAPSHOT, $next, false);
        wp_clear_scheduled_hook('hithean_offers_boundary');
        wp_schedule_single_event($boundary, 'hithean_offers_boundary');
        if (($old['items'] ?? []) !== $items || ($old['valid_until'] ?? 0) !== $boundary) { hithean_offers_purge(); }
    } finally { hithean_offers_unlock('publish', $token); }
}
function hithean_offers_purge(): void {
    $page = get_page_by_path('uu-dai');
    if ($page && function_exists('rocket_clean_post')) { rocket_clean_post($page->ID); }
    if ($page) { clean_post_cache($page->ID); }
    do_action('hithean_offers_cache_purged', home_url('/uu-dai/'));
}
function hithean_offers_cleanup(): void {
    $history = (array) get_option('hithean_offers_history', []);
    $history = array_values(array_filter($history, static fn($run) => ($run['started_at'] ?? 0) > time() - 30 * DAY_IN_SECONDS));
    update_option('hithean_offers_history', array_slice($history, -100), false);
}

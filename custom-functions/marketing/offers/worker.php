<?php
defined('ABSPATH') || exit;

function hithean_offers_start(int $product_id = 0): array {
    if (!function_exists('wc_get_product')) { throw new RuntimeException('Cần kích hoạt WooCommerce.'); }
    $mode = hithean_offers_settings()['source'];
    if ($mode === 'frontend' && (!class_exists('DOMDocument') || !function_exists('mb_strtolower'))) { throw new RuntimeException('Cần PHP DOM và mbstring để đọc trang sản phẩm an toàn.'); }
    $token = hithean_offers_lock('queue', 30);
    if (!$token) { throw new RuntimeException('Hàng đợi đang cập nhật. Vui lòng thử lại.'); }
    try {
        $current = (array) get_option(HITHEAN_OFFERS_RUN, []);
        if (in_array($current['status'] ?? '', ['queued', 'running'], true)) {
            if (!wp_next_scheduled('hithean_offers_work')) { wp_schedule_single_event(time() + 5, 'hithean_offers_work'); }
            return $current;
        }
        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_status() !== 'publish' || $product->get_parent_id()) { throw new RuntimeException('Chỉ quét sản phẩm cha đã công khai.'); }
            $ids = [$product_id];
        } else {
            $ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
        }
        $catalog_ids = $mode === 'database' ? get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']) : [];
        $ids = $mode === 'database' ? array_merge(['wdr', 'wheel'], array_map('intval', $ids)) : array_map('intval', $ids);
        $run = ['id' => wp_generate_uuid4(), 'mode' => $mode, 'catalog_ids' => $catalog_ids, 'source_success' => [], 'product_success' => [],
            'status' => 'queued', 'started_at' => time(), 'updated_at' => time(), 'ids' => $ids,
            'cursor' => 0, 'total' => count($ids), 'ok' => 0, 'failed' => 0, 'attempts' => 0, 'stop' => false, 'errors' => []];
        update_option(HITHEAN_OFFERS_RUN, $run, false);
        update_option('hithean_offers_stop_requested', '', false);
        wp_clear_scheduled_hook('hithean_offers_work');
        wp_schedule_single_event(time() + 1, 'hithean_offers_work');
        return $run;
    } finally { hithean_offers_unlock('queue', $token); }
}
function hithean_offers_allowed_url(string $url): bool {
    $parts = wp_parse_url($url); $home = wp_parse_url(home_url('/'));
    return is_array($parts) && empty($parts['user']) && empty($parts['pass'])
        && ($parts['scheme'] ?? '') === ($home['scheme'] ?? 'https')
        && strtolower($parts['host'] ?? '') === strtolower($home['host'] ?? '')
        && ($parts['port'] ?? null) === ($home['port'] ?? null);
}
function hithean_offers_fetch(string $url): string {
    // Redirects are followed manually, validating every hop before issuing the request.
    for ($hop = 0; $hop < 4; $hop++) {
        if (!hithean_offers_allowed_url($url)) { throw new RuntimeException('URL/redirect ngoài website bị từ chối.'); }
        $response = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES,
            'cookies' => [], 'headers' => ['Cache-Control' => 'no-cache', 'Accept' => 'text/html'], 'user-agent' => 'Hithean-Offers/1.0; ' . home_url('/')]);
        if (is_wp_error($response)) { throw new RuntimeException('Không kết nối được trang sản phẩm.'); }
        $status = wp_remote_retrieve_response_code($response);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) { throw new RuntimeException('Redirect không có URL đích.'); }
            $url = WP_Http::make_absolute_url($location, $url); continue;
        }
        if ($status !== 200) { throw new RuntimeException('Trang sản phẩm trả HTTP ' . $status . '.'); }
        if (stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'text/html') === false) { throw new RuntimeException('Nguồn không phải HTML.'); }
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) >= 2 * MB_IN_BYTES) { throw new RuntimeException('HTML vượt giới hạn an toàn; cần kiểm tra.'); }
        return $body;
    }
    throw new RuntimeException('Quá nhiều redirect.');
}
function hithean_offers_scan_product(int $id): array {
    $product = wc_get_product($id);
    if (!$product || $product->get_status() !== 'publish' || $product->get_catalog_visibility() === 'hidden') {
        hithean_offers_reconcile($id, [], (string) get_permalink($id));
        return ['notice' => ''];
    }
    $url = get_permalink($id);
    $page = hithean_offers_parse(hithean_offers_fetch($url));
    if (mb_strtolower(hithean_offers_text($page['title'])) !== mb_strtolower(hithean_offers_text($product->get_name()))) { throw new RuntimeException('Tên sản phẩm nguồn không khớp; giữ dữ liệu cũ.'); }
    $previous = (array) get_post_meta($id, '_ho_extraction', true);
    $settings_hash = hash('sha256', wp_json_encode(array_intersect_key(hithean_offers_settings(), array_flip(['ai_enabled', 'provider', 'model']))));
    $notice = '';
    if (($previous['hash'] ?? '') === $page['hash'] && ($previous['settings_hash'] ?? '') === $settings_hash && empty($previous['ai_error'])) {
        $candidates = $previous['candidates'];
    } else {
        $ai = hithean_offers_ai($page['unresolved']);
        $notice = $ai['error'];
        $candidates = array_merge($page['candidates'], $ai['candidates']);
        update_post_meta($id, '_ho_extraction', ['hash' => $page['hash'], 'settings_hash' => $settings_hash, 'ai_error' => $notice, 'candidates' => $candidates]);
    }
    hithean_offers_reconcile($id, $candidates, $url);
    update_post_meta($id, '_ho_checked_at', time());
    return ['notice' => $notice];
}
function hithean_offers_work(): void {
    if (!function_exists('wc_get_product')) { return; }
    $token = hithean_offers_lock('worker', 600);
    if (!$token) { return; }
    try {
        $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
        if (!in_array($run['status'] ?? '', ['queued', 'running'], true)) { return; }
        // Watchdog survives a fatal error/process kill; the cursor is committed per product.
        wp_clear_scheduled_hook('hithean_offers_work');
        wp_schedule_single_event(time() + 610, 'hithean_offers_work');
        $run['status'] = 'running';
        update_option(HITHEAN_OFFERS_RUN, $run, false);
        $started = microtime(true); $processed = 0; $retry = false;
        while ($run['cursor'] < $run['total'] && $processed < 10) {
            if (hithean_offers_stop_requested($run['id'])) { $run['stop'] = true; break; }
            $task = $run['ids'][$run['cursor']]; $id = (int) $task;
            try {
                $result = ($run['mode'] ?? 'frontend') === 'database' ? hithean_offers_sync_task($task, $run) : hithean_offers_scan_product($id);
                if ($result['notice']) { $run['errors'][] = ['product_id' => $id, 'message' => $result['notice']]; }
                if (!empty($result['family'])) { $run['source_success'][] = $result['family']; }
                if (!empty($result['product_id'])) { $run['product_success'][] = $result['product_id']; }
                $run['ok']++; $run['cursor']++; $run['attempts'] = 0;
            } catch (Throwable $error) {
                $run['attempts']++;
                if ($run['attempts'] <= 2) { $retry = true; }
                else {
                    $run['failed']++; $run['cursor']++; $run['attempts'] = 0;
                    $run['errors'][] = ['product_id' => $id, 'message' => (is_string($task) ? $task . ': ' : '') . sanitize_text_field($error->getMessage())];
                }
            }
            $run['errors'] = array_slice($run['errors'], -100);
            $run['updated_at'] = time();
            // Preserve a stop request received during the network/AI operation.
            $run['stop'] = hithean_offers_stop_requested($run['id']);
            update_option(HITHEAN_OFFERS_RUN, $run, false);
            $processed++;
            if ($retry || $run['stop'] || microtime(true) - $started >= 20) { break; }
        }
        if ($run['stop'] || $run['cursor'] >= $run['total']) {
            if (($run['mode'] ?? '') === 'database') { hithean_offers_finish_structured($run); }
            $run['status'] = $run['stop'] ? 'stopped' : ($run['failed'] ? 'partial' : 'complete');
            $run['finished_at'] = time();
            update_option(HITHEAN_OFFERS_RUN, $run, false);
            $history = (array) get_option('hithean_offers_history', []);
            $entry = $run; unset($entry['ids'], $entry['catalog_ids'], $entry['product_success']); $history[] = $entry;
            update_option('hithean_offers_history', array_slice($history, -100), false);
            if (!$run['failed'] && !$run['stop']) { update_option('hithean_offers_last_success', time(), false); }
            hithean_offers_cleanup();
            wp_clear_scheduled_hook('hithean_offers_work');
            hithean_offers_publish();
        } else {
            wp_clear_scheduled_hook('hithean_offers_work');
            wp_schedule_single_event(time() + ($retry ? 60 * $run['attempts'] : 5), 'hithean_offers_work');
        }
    } finally { hithean_offers_unlock('worker', $token); }
}
function hithean_offers_stop_requested(string $run_id): bool {
    // Another admin request can set this while the worker is waiting on HTTP/AI.
    wp_cache_delete('hithean_offers_stop_requested', 'options');
    return get_option('hithean_offers_stop_requested', '') === $run_id;
}

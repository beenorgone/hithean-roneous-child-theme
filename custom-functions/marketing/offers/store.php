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
    if (isset($data['source_id'])) {
        update_post_meta($id, '_ho_source', $data['source_id']);
        $linked = array_map('intval', (array) get_post_meta($id, '_ho_product', false));
        if ($linked !== $data['product_ids']) {
            delete_post_meta($id, '_ho_product');
            foreach ($data['product_ids'] as $product_id) { add_post_meta($id, '_ho_product', $product_id); }
        }
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
    if (!empty($row['blocked']) && !in_array($row['status'], ['removed', 'rejected'], true)) { return 'blocked'; }
    if (!empty($row['source_id']) && !in_array($row['status'], ['removed', 'rejected'], true) && !empty($row['end']) && $row['end'] < $now) { return 'expired'; }
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
        $expanded = [];
        foreach ($records as $row) {
            if (!empty($row['blocked'])) { continue; }
            if (!$collecting && isset($row['product_ids'])) {
                foreach ($row['product_ids'] as $product_id) {
                    $item = $row; $item['product_id'] = $product_id; $item['snapshot_item'] = true;
                    $item['condition_note'] = !empty($row['matches'][$product_id]['partial']) ? 'Chỉ áp dụng cho một số phân loại đủ điều kiện; xem chi tiết tại sản phẩm.' : '';
                    $expanded[] = $item;
                }
            } else { $expanded[] = $row; }
        }
        foreach ($expanded as $row) {
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

function hithean_offers_record_lock_name(array $row): string {
    return !empty($row['source_id']) ? 'source_' . md5($row['source_id']) : 'product_' . (int) $row['product_id'];
}
/** A source ID is stable across catalog membership changes; its terms hash gates approval. */
function hithean_offers_upsert_source(array $source, string $run_id): int {
    $lock_name = 'source_' . md5($source['source_id']);
    $token = hithean_offers_lock($lock_name, 60);
    if (!$token) { throw new RuntimeException('Rule đang được duyệt hoặc cập nhật.'); }
    try {
        $posts = get_posts(['post_type' => 'hithean_offer', 'post_status' => 'private', 'numberposts' => 1,
            'meta_key' => '_ho_source', 'meta_value' => $source['source_id']]);
        $previous = $posts ? (array) get_post_meta($posts[0]->ID, '_ho_data', true) : [];
        $key = hash('sha256', $source['source_id'] . '|' . $source['config_hash'] . '|' . (int) $source['blocked']);
        $unchanged = ($previous['key'] ?? '') === $key && ($previous['status'] ?? '') !== 'removed';
        $data = array_merge($source, ['key' => $key, 'product_id' => (int) ($source['product_ids'][0] ?? 0),
            'verified_at' => time(), 'first_seen' => $previous['first_seen'] ?? time(), 'last_seen_run' => $run_id,
            'status' => $source['safe'] && !$source['blocked'] ? 'active' : 'review']);
        if ($posts) { $data['id'] = $posts[0]->ID; }
        if ($unchanged) {
            $data['pinned'] = !empty($previous['pinned']);
            if (!empty($previous['decision'])) {
                $data['decision'] = $previous['decision'];
                $data['status'] = $previous['decision'] === 'approve' ? 'active' : 'rejected';
                if ($previous['decision'] === 'approve') {
                    foreach (['title', 'kind', 'scope'] as $field) { $data[$field] = $previous[$field]; }
                    if (!empty($data['editable_dates'])) { foreach (['start', 'end'] as $field) { $data[$field] = $previous[$field]; } }
                    $data['reason'] = '';
                }
            }
        }
        $id = hithean_offers_save($data);
        if (!$previous || !$unchanged) { hithean_offers_audit($id, $previous ? 'source_terms_changed' : 'collected'); }
        return $id;
    } finally { hithean_offers_unlock($lock_name, $token); }
}
function hithean_offers_finish_structured(array $run): void {
    $families = $run['source_success'] ?? []; $products = $run['product_success'] ?? [];
    foreach (hithean_offers_records() as $row) {
        $owner = (int) ($row['owner_product'] ?? $row['product_id']);
        $complete = !empty($row['source_id'])
            ? (in_array($row['family'], ['wdr', 'wheel'], true) ? in_array($row['family'], $families, true) : in_array($owner, $products, true))
            : in_array($owner, $products, true);
        if (!$complete || ($row['last_seen_run'] ?? '') === $run['id'] || $row['status'] === 'removed') { continue; }
        $name = hithean_offers_record_lock_name($row); $token = hithean_offers_lock($name, 60);
        if (!$token) { throw new RuntimeException('Rule đang được duyệt; sẽ hoàn tất đồng bộ ở lượt tiếp theo.'); }
        try {
            $row = (array) get_post_meta($row['id'], '_ho_data', true) + ['id' => $row['id']];
            // Re-read after taking the lock; never overwrite a newer source version.
            if (($row['last_seen_run'] ?? '') === $run['id']) { continue; }
            $row['status'] = 'removed'; unset($row['decision']);
            hithean_offers_save($row); hithean_offers_audit($row['id'], 'source_removed');
        } finally { hithean_offers_unlock($name, $token); }
    }
}

function hithean_offers_review_date(string $value, bool $end): int {
    if ($value === '') { return 0; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Ho_Chi_Minh'));
    if (!$date || $date->format('Y-m-d') !== $value) { throw new RuntimeException('Ngày xác nhận không hợp lệ.'); }
    return ($end ? $date->setTime(23, 59, 59) : $date)->getTimestamp();
}
/** One decision on the canonical rule controls every associated product. */
function hithean_offers_review(int $id, string $key, string $action, array $fields): void {
    if (!current_user_can('manage_woocommerce')) { throw new RuntimeException('Không có quyền duyệt ưu đãi.'); }
    if (get_post_type($id) !== 'hithean_offer') { throw new RuntimeException('Không tìm thấy ưu đãi.'); }
    $row = get_post_meta($id, '_ho_data', true);
    if (!is_array($row)) { throw new RuntimeException('Dữ liệu ưu đãi không hợp lệ.'); }
    $name = hithean_offers_record_lock_name($row); $token = hithean_offers_lock($name, 60);
    if (!$token) { throw new RuntimeException('Rule đang đồng bộ. Vui lòng tải lại trước khi duyệt.'); }
    try {
        wp_cache_delete($id, 'post_meta');
        $row = get_post_meta($id, '_ho_data', true);
        if (!is_array($row)) { throw new RuntimeException('Rule đã bị gỡ. Vui lòng tải lại danh sách.'); }
        $row['id'] = $id;
        if (!hash_equals((string) $row['key'], $key)) { throw new RuntimeException('Điều kiện nguồn đã đổi. Vui lòng tải lại và duyệt phiên bản mới.'); }
        if ($action === 'approve') {
            if ($row['status'] === 'removed' || $row['verified_at'] + HITHEAN_OFFERS_FRESHNESS <= time()) { throw new RuntimeException('Cần đồng bộ lại nguồn trước khi duyệt.'); }
            if (!empty($row['blocked']) || (isset($row['product_ids']) && !$row['product_ids'])) { throw new RuntimeException('Rule chưa xác định được sản phẩm/đối tượng phù hợp để đăng công khai.'); }
            if (empty($fields['verified'])) { throw new RuntimeException('Cần xác nhận đủ điều kiện, nội dung và đối tượng khách lẻ.'); }
            if (empty($row['source_id']) || !empty($row['editable_dates'])) {
                $row['start'] = hithean_offers_review_date((string) ($fields['start'] ?? ''), false);
                $row['end'] = hithean_offers_review_date((string) ($fields['end'] ?? ''), true);
            }
            if (!empty($row['requires_date']) && !$row['start'] && !$row['end']) { throw new RuntimeException('Nguồn có mốc thời gian chưa rõ. Cần xác nhận ngày áp dụng trước khi đăng.'); }
            if ($row['start'] && $row['end'] && $row['start'] > $row['end']) { throw new RuntimeException('Khoảng thời gian không hợp lệ.'); }
            if (!$row['end'] && empty($fields['no_end'])) { throw new RuntimeException('Cần xác nhận nguồn không công bố ngày kết thúc.'); }
            if ($row['end'] && $row['end'] < time()) { throw new RuntimeException('Ưu đãi đã hết hạn.'); }
            $row['scope'] = ($fields['scope'] ?? '') === 'order' ? 'order' : 'product';
            $kind = (string) ($fields['kind'] ?? 'other');
            if (!in_array($kind, ['gift', 'discount', 'shipping', 'quantity', 'other'], true)) { throw new RuntimeException('Loại ưu đãi không hợp lệ.'); }
            $row['kind'] = $kind;
            $title = trim(sanitize_textarea_field((string) ($fields['title'] ?? $row['title'])));
            if ($title === '' || mb_strlen($title) > 3000) { throw new RuntimeException('Nội dung công khai cần từ 1 đến 3000 ký tự.'); }
            $row['title'] = $title; $row['status'] = 'active'; $row['decision'] = 'approve'; $row['reason'] = '';
        } elseif ($action === 'reject') { $row['status'] = 'rejected'; $row['decision'] = 'reject'; }
        elseif (in_array($action, ['pin', 'unpin'], true)) { $row['pinned'] = $action === 'pin'; }
        else { throw new RuntimeException('Thao tác không hợp lệ.'); }
        hithean_offers_save($row); hithean_offers_audit($id, $action);
    } finally { hithean_offers_unlock($name, $token); }
}

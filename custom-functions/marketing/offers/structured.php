<?php
/** Read-only source adapters. One stored offer per source rule, many product links. */
defined('ABSPATH') || exit;

function hithean_offers_json($value): array {
    if (is_object($value)) { return (array) $value; }
    if (is_array($value)) { return $value; }
    if ($value === null || $value === '') { return []; }
    $decoded = json_decode((string) $value, true);
    if (!is_array($decoded)) { throw new RuntimeException('Cấu hình ưu đãi không phải JSON hợp lệ.'); }
    return $decoded;
}
function hithean_offers_fingerprint(array $data): string {
    $sort = static function ($value) use (&$sort) {
        if (is_object($value)) { $value = (array) $value; }
        if (!is_array($value)) { return $value; }
        ksort($value);
        foreach ($value as $key => $item) { $value[$key] = $sort($item); }
        return $value;
    };
    return hash('sha256', wp_json_encode($sort($data)));
}
function hithean_offers_money($amount): string {
    return number_format((float) $amount, 0, ',', '.') . ' ₫';
}
function hithean_offers_plugin_active(string $directory): bool {
    $plugins = array_merge((array) get_option('active_plugins', []), array_keys((array) get_site_option('active_sitewide_plugins', [])));
    foreach ($plugins as $plugin) { if (strpos($plugin, $directory . '/') === 0) { return true; } }
    return false;
}
function hithean_offers_catalog(array $ids): array {
    $products = [];
    foreach ($ids as $id) {
        $p = wc_get_product((int) $id);
        if ($p && !$p->get_parent_id() && $p->get_status() === 'publish' && $p->get_catalog_visibility() !== 'hidden') { $products[$p->get_id()] = $p; }
    }
    return $products;
}
/** WDR stores wall-clock timestamps, formatted with gmdate in its own Rule helper. */
function hithean_offers_wdr_date($timestamp): int {
    return $timestamp ? (new DateTimeImmutable(gmdate('Y-m-d H:i:s', (int) $timestamp), wp_timezone()))->getTimestamp() : 0;
}
function hithean_offers_source(string $source_id, string $family, array $config, array $ids, string $title, string $kind, string $scope = 'product'): array {
    return ['source_id' => $source_id, 'family' => $family, 'config_hash' => hithean_offers_fingerprint($config),
        'source_config' => $config, 'product_ids' => array_values(array_unique(array_map('intval', $ids))),
        'title' => $title, 'evidence' => $title, 'kind' => $kind, 'scope' => $scope, 'start' => 0, 'end' => 0,
        'safe' => false, 'reason' => 'Xác nhận điều kiện một lần cho toàn bộ sản phẩm của rule.', 'blocked' => false,
        'source' => '', 'origin' => 'database', 'owner_product' => 0, 'matches' => []];
}
function hithean_offers_wdr_rows(): array {
    global $wpdb;
    if (!class_exists('Wdr\\App\\Models\\DBTable')) {
        if (hithean_offers_plugin_active('woo-discount-rules')) { throw new RuntimeException('Discount Rules đang bật nhưng API không khả dụng.'); }
        return [];
    }
    $table = $wpdb->prefix . \Wdr\App\Models\DBTable::RULES_TABLE_NAME;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) { throw new RuntimeException('Tên bảng rule không hợp lệ.'); }
    // The frontend model omits future rules; this read also preserves scheduled programs.
    $rows = $wpdb->get_results("SELECT * FROM `$table` WHERE enabled=1 AND deleted=0 ORDER BY priority ASC, id ASC", ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { throw new RuntimeException('Không đọc được cấu hình Discount Rules.'); }
    return $rows;
}
/** Match product filters only; cart/customer eligibility is recorded separately, never simulated. */
function hithean_offers_wdr_match(array $rule, $product): ?array {
    if (!class_exists('Wdr\\App\\Helpers\\Filter')) { throw new RuntimeException('Bộ lọc sản phẩm Discount Rules không khả dụng.'); }
    $filters = hithean_offers_json($rule['filters'] ?? '[]');
    $known = ['all_products', 'products', 'product_category', 'product_tags', 'product_attributes', 'product_sku', 'product_on_sale'];
    foreach ($filters as $filter) { if (!is_array($filter) || !in_array($filter['type'] ?? '', $known, true)) { return null; } }
    $helper = new \Wdr\App\Helpers\Filter();
    $objects = json_decode(wp_json_encode($filters));
    if (!$filters) { return ['variation_ids' => [], 'partial' => false]; }
    $test = static fn($p) => $helper->matchFilters($p, $objects, false, false, []);
    if (!$product->is_type('variable')) { return $test($product) ? ['variation_ids' => [], 'partial' => false] : []; }
    $children = []; $matched = [];
    foreach ($product->get_children() as $id) {
        $child = wc_get_product($id);
        if (!$child || $child->get_status() !== 'publish') { continue; }
        $children[] = $id;
        if ($test($child)) { $matched[] = (int) $id; }
    }
    if (!$matched) { return []; }
    return ['variation_ids' => $matched, 'partial' => count($matched) < count($children)];
}
/** Public text is generated from values, not internal rule titles or private coupon names. */
function hithean_offers_wdr_text(array $raw): array {
    $type = $raw['discount_type'] ?? ''; $parts = []; $blocked = false;
    $kind = 'other'; $scope = 'product';
    if ($type === 'wdr_bulk_discount') {
        $bulk = hithean_offers_json($raw['bulk_adjustments'] ?? []); $kind = 'quantity';
        foreach ($bulk['ranges'] ?? [] as $range) {
            $from = (int) ($range['from'] ?? 0); $to = (int) ($range['to'] ?? 0); $value = (float) ($range['value'] ?? 0);
            if ($from < 1 || $value <= 0 || !in_array($range['type'] ?? '', ['percentage', 'flat', 'fixed_price'], true)) { continue; }
            $quantity = $to === $from ? (string) $from : ($to ? "$from–$to" : 'từ ' . $from);
            $benefit = $range['type'] === 'percentage' ? 'giảm ' . $value . '%' : ($range['type'] === 'fixed_price' ? 'giá ' : 'giảm ') . hithean_offers_money($value) . '/sản phẩm';
            $parts[] = 'Mua ' . $quantity . ' sản phẩm: ' . $benefit;
        }
        $operator = $bulk['operator'] ?? '';
        if (in_array($operator, ['product_cumulative', 'cart', 'category_cumulative'], true)) { $parts[] = 'Tính cộng dồn số lượng các sản phẩm đủ điều kiện'; }
        elseif ($operator === 'product') { $parts[] = 'Số lượng được tính theo từng sản phẩm'; }
        else { $parts[] = 'Cách tính số lượng cần được xác nhận'; }
    } elseif ($type === 'wdr_simple_discount') {
        $adjustment = hithean_offers_json($raw['product_adjustments'] ?? []); $kind = 'discount';
        if (!empty($adjustment['value'])) {
            $parts[] = ($adjustment['type'] ?? '') === 'percentage' ? 'Giảm ' . (float) $adjustment['value'] . '%' : 'Giảm ' . hithean_offers_money($adjustment['value']);
        }
        if (!empty($adjustment['apply_as_cart_rule'])) { $scope = 'order'; $parts[] = 'Khoản giảm được áp dụng trong giỏ hàng'; }
    } elseif ($type === 'wdr_free_shipping') { $kind = 'shipping'; $scope = 'order'; $parts[] = 'Miễn phí vận chuyển'; }
    elseif (in_array($type, ['wdr_buy_x_get_y_discount', 'wdr_buy_x_get_x_discount'], true)) { $kind = 'gift'; $parts[] = 'Chương trình mua kèm quà tặng — cần xác nhận số lượng và sản phẩm quà'; }
    if (!$parts) { $parts[] = 'Chương trình ưu đãi cần xác nhận nội dung'; }
    foreach (hithean_offers_json($raw['conditions'] ?? []) as $condition) {
        $options = $condition['options'] ?? []; $condition_type = $condition['type'] ?? '';
        if ($condition_type === 'cart_subtotal' && in_array($options['operator'] ?? '', ['greater_than_or_equal', 'greater_than'], true)) {
            $parts[] = 'Giá trị giỏ hàng ' . ($options['operator'] === 'greater_than' ? 'trên ' : 'từ ') . hithean_offers_money($options['value'] ?? 0);
            $parts[] = 'Cơ sở tính ngưỡng: ' . (($options['calculate_from'] ?? '') === 'from_cart' ? 'theo giá trị giỏ hàng do plugin xác định' : 'cần xác nhận');
        } elseif ($condition_type === 'cart_coupon') { $parts[] = 'Cần sử dụng mã ưu đãi hợp lệ'; }
        elseif (in_array($condition_type, ['user_list', 'user_role'], true)) { $blocked = true; $parts[] = 'Chương trình giới hạn đối tượng, không xuất bản vào trang khách lẻ chung'; }
        else { $parts[] = 'Có điều kiện bổ sung (' . sanitize_key($condition_type) . '), cần xác nhận trước khi đăng'; }
    }
    if (!empty($raw['exclusive'])) { $parts[] = 'Không kết hợp các rule khác theo cấu hình plugin'; }
    return ['title' => implode('. ', $parts) . '.', 'kind' => $kind, 'scope' => $scope, 'blocked' => $blocked];
}
function hithean_offers_wdr_sources(array $rows, array $products): array {
    $sources = [];
    foreach ($rows as $raw) {
        if (empty($raw['enabled']) || !empty($raw['deleted'])) { continue; }
        if (!empty($raw['usage_limits']) && (int) ($raw['used_limits'] ?? 0) >= (int) $raw['usage_limits']) { continue; }
        $config = $raw;
        foreach (['created_by', 'created_on', 'modified_by', 'modified_on', 'used_limits', 'used_coupons'] as $field) { unset($config[$field]); }
        $text = hithean_offers_wdr_text($raw);
        $row = hithean_offers_source('wdr:' . (int) $raw['id'], 'wdr', $config, [], $text['title'], $text['kind'], $text['scope']);
        $row['blocked'] = $text['blocked']; $row['source_title'] = hithean_offers_text((string) ($raw['title'] ?? ''));
        $row['source'] = admin_url('admin.php?page=woo_discount_rules');
        $row['start'] = hithean_offers_wdr_date($raw['date_from'] ?? 0); $row['end'] = hithean_offers_wdr_date($raw['date_to'] ?? 0);
        foreach ($products as $id => $product) {
            $match = hithean_offers_wdr_match($raw, $product);
            if ($match === null) { $row['blocked'] = true; $row['reason'] = 'Loại bộ lọc sản phẩm chưa được hỗ trợ; không suy đoán danh sách sản phẩm.'; break; }
            if ($match) { $row['product_ids'][] = (int) $id; $row['matches'][$id] = $match; }
        }
        if ($text['blocked']) { $row['reason'] = 'Rule giới hạn khách hàng/nhóm khách; không đăng công khai.'; }
        $sources[] = $row;
    }
    return $sources;
}
function hithean_offers_product_sources($product): array {
    $id = $product->get_id(); $sources = [];
    if (!function_exists('simple_addon_get_product_addons') && hithean_offers_plugin_active('product-addon')) { throw new RuntimeException('Product Addon đang bật nhưng API không khả dụng.'); }
    $addon_enabled = !function_exists('simple_addon_is_product_addon_feature_enabled') || simple_addon_is_product_addon_feature_enabled($id);
    if (function_exists('simple_addon_get_product_addons') && $addon_enabled) {
        foreach (simple_addon_build_display_choices(simple_addon_get_product_addons($id)) as $choice) {
            $addons = array_values(array_filter($choice['addons'], static fn($a) => in_array($a['type'], ['product', 'discount', 'coupon'], true)));
            if (!$addons) { continue; }
            $parts = [hithean_offers_text($choice['name']), $choice['mode'] === 'auto' ? 'Tự động áp dụng khi đủ điều kiện' : 'Cần chọn ưu đãi tại trang sản phẩm'];
            foreach ($choice['ranges'] as $range) {
                $parts[] = 'Số lượng mua từ ' . max(1, (int) $range['min']) . (!empty($range['max']) ? ' đến ' . (int) $range['max'] : ' trở lên');
            }
            $config = $choice; $config['sale_message'] = get_post_meta($id, 'product_addon_sale_message', true);
            unset($config['preview_price']);
            $context = hithean_offers_text((string) $config['sale_message']);
            if ($context !== '') { $parts[] = $context; }
            $row = hithean_offers_source('addon:' . $id . ':' . $choice['value'], 'addon', $config, [$id], implode('. ', $parts), 'gift');
            $dates = hithean_offers_dates($row['title']); $row['start'] = $dates['start']; $row['end'] = $dates['end'];
            $row['editable_dates'] = $dates['reason'] !== ''; $row['requires_date'] = $row['editable_dates'];
            if ($dates['reason']) { $row['reason'] = $dates['reason']; }
            $row['owner_product'] = $id; $row['source'] = admin_url('post.php?post=' . $id . '&action=edit');
            $sources[] = $row;
        }
        // Free text supplements structured conditions; it never silently overrides them.
        $message = hithean_offers_text((string) get_post_meta($id, 'product_addon_sale_message', true));
        if ($message !== '' && !$sources) {
            $row = hithean_offers_source('addon:' . $id . ':message', 'addon', ['text' => $message], [$id], $message, 'other');
            $dates = hithean_offers_dates($message); $row['start'] = $dates['start']; $row['end'] = $dates['end'];
            $row['editable_dates'] = true; $row['requires_date'] = $dates['reason'] !== '';
            if ($dates['reason']) { $row['reason'] = $dates['reason']; }
            $row['owner_product'] = $id; $row['source'] = admin_url('post.php?post=' . $id . '&action=edit');
            $sources[] = $row;
        }
    }
    $sale_products = $product->is_type('variable') ? array_filter(array_map('wc_get_product', $product->get_children())) : [$product];
    foreach ($sale_products as $sale) {
        $raw_price = $sale->get_sale_price('edit'); $price = (float) $raw_price; $regular = (float) $sale->get_regular_price('edit');
        if ($sale->get_status() !== 'publish' || $raw_price === '' || $price < 0 || $regular <= $price) { continue; }
        $from = $sale->get_date_on_sale_from('edit'); $to = $sale->get_date_on_sale_to('edit');
        $config = ['price' => $price, 'regular' => $regular, 'start' => $from ? $from->getTimestamp() : 0, 'end' => $to ? $to->getTimestamp() : 0];
        $title = ($sale->get_parent_id() ? $sale->get_name() . ': ' : '') . 'Giá ưu đãi ' . hithean_offers_money($price) . ' (giá thường ' . hithean_offers_money($regular) . ')';
        $row = hithean_offers_source('sale:' . $sale->get_id(), 'sale', $config, [$id], $title, 'discount');
        $row['start'] = $config['start']; $row['end'] = $config['end']; $row['safe'] = true;
        $row['owner_product'] = $id; $row['source'] = admin_url('post.php?post=' . $id . '&action=edit');
        $sources[] = $row;
    }
    return $sources;
}
function hithean_offers_wheel_summary(): string {
    return 'Tham gia vòng quay may mắn để có cơ hội nhận mã ưu đãi. Cần quay và nhận mã trước khi dùng; phần thưởng và điều kiện áp dụng được xác nhận trong vòng quay.';
}
function hithean_offers_wheel_sources(array $products): array {
    if (!function_exists('thean_lw_active_rewards') || empty(thean_lw_get_settings()['enabled'])) { return []; }
    $rewards = []; $ids = [];
    foreach (thean_lw_active_rewards() as $reward) {
        $rewards[(string) $reward['id']] = $reward;
        foreach ($products as $id => $product) {
            if ($reward['type'] === 'buy_x_get_y' && !in_array($id, $reward['buy_product_ids'] ?? [], true)) { continue; }
            if ($reward['type'] === 'taxonomy_quantity_discount' && !has_term($reward['term_slugs'], $reward['taxonomy'], $id)) { continue; }
            $ids[$id] = $id;
        }
    }
    if (!$rewards || !$ids) { return []; }
    ksort($rewards);
    $config = ['rewards' => $rewards, 'hold_hours' => thean_lw_coupon_hold_hours()];
    $row = hithean_offers_source('wheel:program', 'wheel', $config, array_values($ids), hithean_offers_wheel_summary(), 'other', 'order');
    $row['source_title'] = 'Vòng quay may mắn';
    $row['source'] = admin_url('admin.php?page=thean-lucky-wheel');
    return [$row];
}

function hithean_offers_sync_task($task, array $run): array {
    if ($task === 'wdr' || $task === 'wheel') {
        $products = hithean_offers_catalog($run['catalog_ids']);
        $sources = $task === 'wdr' ? hithean_offers_wdr_sources(hithean_offers_wdr_rows(), $products) : hithean_offers_wheel_sources($products);
        foreach ($sources as $source) { hithean_offers_upsert_source($source, $run['id']); }
        return ['notice' => '', 'family' => $task];
    }
    $id = (int) $task; $products = hithean_offers_catalog([$id]);
    if (isset($products[$id])) {
        foreach (hithean_offers_product_sources($products[$id]) as $source) { hithean_offers_upsert_source($source, $run['id']); }
        update_post_meta($id, '_ho_checked_at', time());
    }
    return ['notice' => '', 'product_id' => $id];
}

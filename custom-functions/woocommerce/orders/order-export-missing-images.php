<?php
if (!defined('ABSPATH')) exit;

/** Website orders only: Shopee aggregate orders keep evidence on their packages. */
function thean_export_missing_statuses(): array
{
    return array_diff_key(wc_get_order_statuses(), array_flip(['wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-checkout-draft']));
}

function thean_export_missing_filters(array $input): array
{
    $days = absint($input['days'] ?? 30);
    $status = is_scalar($input['status'] ?? '') ? sanitize_key((string) ($input['status'] ?? '')) : '';
    return [
        'days' => in_array($days, [30, 60, 90], true) ? $days : 30,
        'status' => isset(thean_export_missing_statuses()[$status]) ? $status : '',
        'page' => max(1, min(10000, absint($input['page'] ?? 1))),
    ];
}

/** Bounded SQL before pagination; WC legacy order queries do not support meta_query. */
function thean_export_missing_ids(array $filters): array
{
    global $wpdb;
    $hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
    $id = $hpos ? 'id' : 'ID';
    $date = $hpos ? 'date_created_gmt' : 'post_date_gmt';
    $type = $hpos ? 'type' : 'post_type';
    $status = $hpos ? 'status' : 'post_status';
    $statuses = $filters['status'] !== '' ? [$filters['status']] : array_keys(thean_export_missing_statuses());
    if (!$statuses) return ['ids' => [], 'has_more' => false];
    $now = time();
    $where = [
        "o.{$type} = 'shop_order'",
        $wpdb->prepare("o.{$date} >= %s AND o.{$date} <= %s", gmdate('Y-m-d H:i:s', $now - $filters['days'] * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', $now)),
        $wpdb->prepare("o.{$status} IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')', ...$statuses),
    ];
    // A nonempty image value in either store counts as existing evidence, matching legacy fallback reads.
    $stores = [[$wpdb->postmeta, 'post_id']];
    if ($hpos) $stores[] = [$wpdb->prefix . 'wc_orders_meta', 'order_id'];
    foreach ($stores as [$meta_table, $order_key]) {
        $where[] = "NOT EXISTS (SELECT 1 FROM {$meta_table} m WHERE m.{$order_key} = o.{$id}"
            . " AND m.meta_key IN ('warehouse_export_images', '_spx_date')"
            . " AND TRIM(REPLACE(REPLACE(REPLACE(COALESCE(m.meta_value, ''), CHAR(13), ''), CHAR(10), ''), CHAR(9), '')) <> '')";
    }
    $offset = ($filters['page'] - 1) * 20;
    $ids = $wpdb->get_col("SELECT o.{$id} FROM {$table} o WHERE " . implode(' AND ', $where)
        . " ORDER BY o.{$date} DESC, o.{$id} DESC LIMIT 21 OFFSET " . (int) $offset);
    if ($wpdb->last_error) return ['error' => true];
    return ['ids' => array_map('intval', array_slice((array) $ids, 0, 20)), 'has_more' => count((array) $ids) > 20];
}

function thean_export_missing_shell(): string
{
    if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files')) return '';
    $asset = '/custom-functions/woocommerce/orders/assets/export-missing-images.css';
    ob_start(); ?>
    <link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . $asset); ?>?ver=<?php echo esc_attr((string) filemtime(get_stylesheet_directory() . $asset)); ?>">
    <section class="ueif-missing" data-ueif-missing data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('thean_export_missing_images')); ?>" aria-label="Đơn chưa upload ảnh">
        <h2>Đơn chưa upload ảnh</h2>
        <p>Đơn website theo ngày tạo, chưa có ảnh xuất kho. Không gồm đơn Hủy, Thất bại, Hoàn tiền, đơn nháp và đơn gộp Shopee.</p>
        <div class="ueif-missing__filters">
            <label>Ngày tạo<select data-missing-days><option value="30" selected>30 ngày qua</option><option value="60">60 ngày qua</option><option value="90">90 ngày qua</option></select></label>
            <label>Trạng thái<select data-missing-status><option value="">Tất cả trạng thái</option><?php foreach (thean_export_missing_statuses() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <button type="button" class="ueif-missing__button" data-missing-refresh>Làm mới</button>
        </div>
        <p data-missing-message role="status" aria-live="polite">Đang tải danh sách…</p>
        <div data-missing-results></div>
        <div class="ueif-missing__pages"><button type="button" class="ueif-missing__button" data-missing-prev disabled>‹ Trước</button><span data-missing-page>Trang 1</span><button type="button" class="ueif-missing__button" data-missing-next disabled>Tiếp ›</button></div>
    </section>
    <?php return ob_get_clean();
}

function thean_export_missing_cards(array $ids): string
{
    if (!$ids) return '<p>Không có đơn chưa upload ảnh trong bộ lọc này.</p>';
    $orders = wc_get_orders(['include' => $ids, 'type' => 'shop_order', 'limit' => count($ids)]);
    $map = [];
    foreach ($orders as $order) $map[$order->get_id()] = $order;
    ob_start(); ?>
    <div class="ueif-missing__grid">
    <?php foreach ($ids as $id):
        if (!isset($map[$id])) continue;
        $order = $map[$id];
        $created = $order->get_date_created();
        $edit_url = function_exists('thean_order_edit_url') ? thean_order_edit_url($id) : $order->get_edit_order_url(); ?>
        <article class="ueif-missing__card">
            <div class="ueif-missing__heading"><strong>#<?php echo esc_html($order->get_order_number()); ?></strong><span><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></div>
            <small><?php echo esc_html($created ? $created->date_i18n('d/m/Y H:i') : ''); ?></small>
            <p><?php echo esc_html($order->get_formatted_billing_full_name()); ?></p>
            <ul><?php foreach ($order->get_items() as $item): ?><li><?php echo esc_html($item->get_name()); ?> × <?php echo esc_html($item->get_quantity()); ?></li><?php endforeach; ?></ul>
            <div class="ueif-missing__actions"><button type="button" class="ueif-missing__button ueif-missing__button--primary" data-missing-upload="<?php echo esc_attr((string) $id); ?>">Upload ảnh</button><a class="ueif-missing__button" href="<?php echo esc_url($edit_url); ?>" target="_blank" rel="noopener">Chi tiết ↗</a></div>
        </article>
    <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function thean_export_missing_ajax(): void
{
    if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files') || !check_ajax_referer('thean_export_missing_images', 'nonce', false)) {
        wp_send_json_error(['message' => 'Không có quyền hoặc phiên đã hết hạn. Hãy tải lại trang.'], 403);
        return;
    }
    $filters = thean_export_missing_filters($_POST);
    $result = thean_export_missing_ids($filters);
    if (!empty($result['error'])) {
        wp_send_json_error(['message' => 'Không tải được danh sách. Vui lòng thử lại.'], 500);
        return;
    }
    wp_send_json_success(array_merge($filters, [
        'html' => thean_export_missing_cards($result['ids']),
        'count' => count($result['ids']),
        'has_more' => $result['has_more'],
    ]));
}
add_action('wp_ajax_thean_export_missing_images', 'thean_export_missing_ajax');

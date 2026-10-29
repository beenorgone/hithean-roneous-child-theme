<?php
if (!defined('ABSPATH')) exit;

/** Record policy remains local; IVAR additionally uses its owning ERP ACL. */
function erp_mesh_can_read_order($order): bool {
    if (!erp_mesh_can_read() || !$order instanceof WC_Order || $order->get_type() !== 'shop_order'
        || in_array($order->get_status(), ['trash', 'auto-draft'], true)) return false;
    if (erp_mesh_site() === 'ivar') {
        // These policy files are explicit MCP dependencies in IVAR bootstrap.
        if (!function_exists('ivar_ai_records_can_get')) return false;
        return ivar_ai_records_can_get(['type' => ivar_mo_is_enabled($order) ? 'mo' : 'so', 'id' => $order->get_id()]);
    }
    return (bool) apply_filters('erp_mesh_can_read_order', true, $order);
}

/** Only numeric IDs, catalogue data and operational states; no customer fields. */
function erp_mesh_project_order(WC_Order $order): array {
    $lines = [];
    foreach ($order->get_items() as $item) {
        if (!$item instanceof WC_Order_Item_Product) continue;
        $product = $item->get_product();
        $qty = (float) $item->get_quantity();
        // Financial refunds alone do not prove goods were returned to stock.
        $restocked = max(0, (float) $item->get_meta('_restock_refunded_items', true));
        $lines[] = [
            'line_id' => $item->get_id(), 'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'sku' => $product ? (string) $product->get_sku() : '',
            'name' => $product ? wp_strip_all_tags($product->get_name()) : 'Sản phẩm đã xóa',
            'quantity' => $qty, 'restocked_quantity' => min($qty, $restocked),
            'subtotal' => (string) $item->get_subtotal(), 'total' => (string) $item->get_total(),
            'tax' => (string) $item->get_total_tax(),
        ];
    }
    $data = [
        'site' => erp_mesh_site(), 'id' => $order->get_id(), 'number' => (string) $order->get_order_number(),
        'kind' => erp_mesh_site() === 'ivar' && ivar_mo_is_enabled($order) ? 'mo' : 'sale',
        'status' => $order->get_status(), 'currency' => $order->get_currency(),
        'created_at' => $order->get_date_created() ? $order->get_date_created()->date(DATE_ATOM) : '',
        'modified_at' => $order->get_date_modified() ? $order->get_date_modified()->date(DATE_ATOM) : '',
        'total' => (string) $order->get_total(), 'shipping_total' => (string) $order->get_shipping_total(),
        'tax_total' => (string) $order->get_total_tax(), 'refunded_total' => (string) $order->get_total_refunded(),
        'export_confirmed' => child_theme_order_export_is_confirmed($order),
        'return_status' => sanitize_key((string) $order->get_meta('return_status')),
        'internal' => (bool) $order->get_meta('_eim_internal'), 'items' => $lines,
    ];
    $data['fingerprint'] = hash('sha256', wp_json_encode($data));
    return $data;
}

function erp_mesh_orders_get($input = []) {
    $order = wc_get_order(absint($input['id'] ?? 0));
    if (!erp_mesh_can_read_order($order)) return new WP_Error('mesh_denied', 'Không có quyền đọc đơn này.', ['status' => 403]);
    if (count($order->get_items()) > 200) return new WP_Error('mesh_order_large', 'Đơn vượt giới hạn 200 dòng hàng.');
    return erp_mesh_project_order($order);
}

function erp_mesh_orders_list($input = []) {
    if (!erp_mesh_can_read()) return new WP_Error('mesh_denied', 'Không có quyền đọc đơn.', ['status' => 403]);
    $page = max(1, min(10000, absint($input['page'] ?? 1)));
    $limit = max(1, min(50, absint($input['per_page'] ?? 20)));
    if (($input['basis'] ?? 'created') === 'activity') return erp_mesh_activity_orders($input, $page, $limit);
    $args = ['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()),
        'limit' => $limit, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'paginate' => true];
    if (isset($input['ids'])) {
        if (!is_array($input['ids']) || !$input['ids'] || count($input['ids']) > 50
            || !empty($input['date_from']) || !empty($input['date_to'])) return new WP_Error('mesh_ids', 'Chọn tối đa 50 ID, không kèm khoảng ngày.');
        $args['include'] = array_values(array_unique(array_filter(array_map('absint', $input['ids']))));
        if (!$args['include']) return new WP_Error('mesh_ids', 'ID đơn không hợp lệ.');
        $args['limit'] = 50; $args['page'] = 1;
    }
    if (!empty($input['date_from']) || !empty($input['date_to'])) {
        $from = erp_mesh_date((string) ($input['date_from'] ?? ''));
        $to = erp_mesh_date((string) ($input['date_to'] ?? ''));
        if (!$from || !$to || $from > $to || strtotime($to) - strtotime($from) > 31 * DAY_IN_SECONDS) {
            return new WP_Error('mesh_dates', 'Chọn khoảng ngày hợp lệ, tối đa 31 ngày.');
        }
        // Site timezone; creation-date windows remain stable across repeated polls.
        $args['date_created'] = $from . '...' . $to;
    }
    $result = wc_get_orders($args);
    $orders = []; $omitted = 0;
    foreach ($result->orders as $order) {
        if (erp_mesh_can_read_order($order) && count($order->get_items()) <= 200) $orders[] = erp_mesh_project_order($order);
        else $omitted++;
    }
    // Candidate pagination: denied records can produce a sparse page.
    return ['site' => erp_mesh_site(), 'orders' => $orders, 'page' => $page,
        'has_more' => $page < (int) $result->max_num_pages, 'omitted' => $omitted];
}

function erp_mesh_date(string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

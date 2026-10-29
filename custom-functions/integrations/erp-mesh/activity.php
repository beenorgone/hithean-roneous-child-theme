<?php
/** Own event index: catches late warehouse confirmations even when WC date_modified is unchanged. */
if (!defined('ABSPATH')) exit;

function erp_mesh_activity_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'erp_mesh_activity';
}

function erp_mesh_activity_install(): bool {
    if (get_option('erp_mesh_activity_version') === '1') return true;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = erp_mesh_activity_table(); $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        order_id bigint(20) unsigned NOT NULL,
        changed_at datetime NOT NULL,
        PRIMARY KEY  (order_id),
        KEY changed_at (changed_at)
    ) ENGINE=InnoDB $charset;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
    update_option('erp_mesh_activity_version', '1', false);
    return true;
}

function erp_mesh_activity_record($order): void {
    // No DDL in order/stock transactions. Provisioned separately on admin_init.
    if (get_option('erp_mesh_activity_version') !== '1') return;
    if (is_numeric($order)) $order = wc_get_order((int) $order);
    if (!$order instanceof WC_Order || !$order->get_id() || $order->get_type() !== 'shop_order') return;
    global $wpdb;
    $wpdb->replace(erp_mesh_activity_table(), ['order_id' => $order->get_id(), 'changed_at' => current_time('mysql')]);
}

function erp_mesh_activity_orders(array $input, int $page, int $limit) {
    if (get_option('erp_mesh_activity_version') !== '1') return new WP_Error('mesh_activity_setup', 'Mở wp-admin bằng tài khoản quản lý để khởi tạo nhật ký MCP trước khi bóc phát sinh.');
    $from = erp_mesh_date((string) ($input['date_from'] ?? '')); $to = erp_mesh_date((string) ($input['date_to'] ?? ''));
    if (!$from || !$to || $from > $to || strtotime($to) - strtotime($from) > 31 * DAY_IN_SECONDS) return new WP_Error('mesh_dates', 'Khoảng ngày phát sinh không hợp lệ.');
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare('SELECT order_id FROM ' . erp_mesh_activity_table()
        . ' WHERE changed_at >= %s AND changed_at <= %s ORDER BY order_id ASC LIMIT %d OFFSET %d',
        $from . ' 00:00:00', $to . ' 23:59:59', $limit + 1, ($page - 1) * $limit));
    $more = count($ids) > $limit; $ids = array_slice($ids, 0, $limit);
    $orders = []; $omitted = 0;
    foreach ($ids as $id) {
        $order = wc_get_order((int) $id);
        if (erp_mesh_can_read_order($order) && count($order->get_items()) <= 200) $orders[] = erp_mesh_project_order($order);
        else $omitted++;
    }
    return ['site' => erp_mesh_site(), 'orders' => $orders, 'page' => $page, 'has_more' => $more, 'omitted' => $omitted];
}

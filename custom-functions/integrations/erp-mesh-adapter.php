<?php
/** Hithean policy for the mcp-site-link plugin. Group, peers and transport are configured in wp-config. */
if (!defined('ABSPATH') || !defined('ERP_MESH_PLUGIN_VERSION')) return;

add_filter('erp_mesh_order_export_confirmed', static function ($confirmed, $order) {
    require_once dirname(__DIR__) . '/woocommerce/orders/order-operations-domain.php';
    return child_theme_order_export_is_confirmed($order);
}, 10, 2);

// A warehouse confirmation may not touch the order object; the event index must still see it.
add_filter('erp_mesh_activity_hooks', static function (array $hooks): array {
    $hooks[] = 'hithean_warehouse_export_confirmed';
    return $hooks;
});

<?php
/** Cross-site ERP transport. Business writes stay in the destination adapter. */
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/peers.php';
require_once __DIR__ . '/abilities.php';

function erp_mesh_site(): string { return ERP_MESH_SITE_ID; }

function erp_mesh_can_read(): bool {
    return is_user_logged_in() && (current_user_can('manage_woocommerce') || current_user_can('erp_mesh_read_orders'));
}

function erp_mesh_can_call_peers(): bool {
    return is_user_logged_in() && current_user_can('manage_woocommerce');
}

// No WooCommerce edit capabilities: an integration account cannot change orders.
add_action('init', static function (): void {
    if (get_option('erp_mesh_role_version') === '1') return;
    add_role('erp_mesh_reader', 'MCP · Đọc đơn liên site', ['read' => true, 'erp_mesh_read_orders' => true]);
    update_option('erp_mesh_role_version', '1', false);
});

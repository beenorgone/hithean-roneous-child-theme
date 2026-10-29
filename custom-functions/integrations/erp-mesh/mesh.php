<?php
/** Cross-site ERP transport. Business writes stay in the destination adapter. */
if (!defined('ABSPATH')) exit;
require_once dirname(__DIR__, 2) . '/woocommerce/orders/order-operations-domain.php';

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/peers.php';
require_once __DIR__ . '/abilities.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';

function erp_mesh_site(): string { return ERP_MESH_SITE_ID; }

function erp_mesh_can_read(): bool {
    return is_user_logged_in() && (current_user_can('manage_woocommerce') || current_user_can('erp_mesh_read_orders'));
}

function erp_mesh_can_call_peers(): bool {
    return is_user_logged_in() && (current_user_can('manage_woocommerce') || current_user_can('erp_mesh_read_peers'));
}

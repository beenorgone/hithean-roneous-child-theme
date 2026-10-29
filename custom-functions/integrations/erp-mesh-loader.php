<?php
/** Management-only dispatcher. The theme bootstrap excludes this file from shopper requests. */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/erp-mesh/auth.php';

function erp_mesh_install_infrastructure(): bool {
    require_once __DIR__ . '/erp-mesh/activity.php';
    if (get_option('erp_mesh_role_version') !== '1') {
        add_role('erp_mesh_reader', 'MCP · Đọc đơn liên site', ['read' => true, 'erp_mesh_read_orders' => true]);
        update_option('erp_mesh_role_version', '1', false);
    }
    return erp_mesh_activity_install();
}

add_action('admin_init', static function (): void {
    if (current_user_can('manage_woocommerce')
        && (get_option('erp_mesh_role_version') !== '1' || get_option('erp_mesh_activity_version') !== '1')) erp_mesh_install_infrastructure();
});

// These callbacks load only the event index, only when an actual management mutation occurs.
function erp_mesh_record_management_activity($order): void {
    if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')
        && !(defined('DOING_CRON') && DOING_CRON) && !(defined('WP_CLI') && WP_CLI)) return;
    require_once __DIR__ . '/erp-mesh/activity.php';
    erp_mesh_activity_record($order);
}
foreach (['woocommerce_after_order_object_save', 'hithean_warehouse_export_confirmed', 'woocommerce_order_refunded'] as $hook) {
    add_action($hook, 'erp_mesh_record_management_activity', 100);
}
add_action('woocommerce_after_order_item_object_save', static function ($item): void {
    if ($item->get_order_id()) erp_mesh_record_management_activity($item->get_order_id());
}, 100);
add_action('woocommerce_before_delete_order_item', static function ($id): void {
    $item = WC_Order_Factory::get_order_item($id);
    if ($item) erp_mesh_record_management_activity($item->get_order_id());
}, 100);
add_action('woocommerce_restock_refunded_item', static function ($pid, $before, $after, $order): void {
    erp_mesh_record_management_activity($order);
}, 100, 4);

add_action('application_password_did_authenticate', static function ($user): void {
    if ($user instanceof WP_User && in_array('erp_mesh_reader', (array) $user->roles, true)) {
        require_once __DIR__ . '/erp-mesh/auth.php';
        $GLOBALS['erp_mesh_integration_auth'] = true;
    }
}, 5);

if (ERP_MESH_SITE_ID === 'theanmarket') {
    // A small stock-ledger guard is needed on management mutation paths, not the workflow UI.
    require_once dirname(__DIR__) . '/erp/modules/cross-site-operations/guard.php';
    add_action('admin_menu', static function (): void {
        add_submenu_page('woocommerce', 'Đối soát liên site', 'Đối soát liên site', 'manage_woocommerce', 'thean-mesh', 'thean_mesh_render');
    });
    add_action('thean_mesh_collect_daily', static function (): void {
        require_once __DIR__ . '/erp-mesh/mesh.php';
        require_once dirname(__DIR__) . '/erp/modules/cross-site-operations/operations.php';
        thean_mesh_collect_daily_run();
    });
}

$mesh_full_request = erp_mesh_is_mcp_request()
    || (ERP_MESH_SITE_ID === 'theanmarket' && (($_GET['page'] ?? '') === 'thean-mesh'
        || ($_REQUEST['action'] ?? '') === 'thean_mesh_action'));
if ($mesh_full_request) {
    require_once __DIR__ . '/erp-mesh/mesh.php';
    if (ERP_MESH_SITE_ID === 'theanmarket') require_once dirname(__DIR__) . '/erp/modules/cross-site-operations/operations.php';
}
unset($mesh_full_request);

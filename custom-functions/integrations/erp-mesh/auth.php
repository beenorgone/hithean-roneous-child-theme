<?php
/** Scope dedicated integration users to this site's read/preview MCP endpoint. */
if (!defined('ABSPATH')) exit;

function erp_mesh_endpoint_route(): string {
    return ERP_MESH_SITE_ID === 'ivar' ? '/mcp/ivar-erp-records' : '/mcp/' . ERP_MESH_SITE_ID . '-erp';
}

add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    $user = wp_get_current_user();
    $integration = !empty($GLOBALS['erp_mesh_integration_auth']) || ($user instanceof WP_User && in_array('erp_mesh_reader', (array) $user->roles, true));
    if ($integration && rtrim($request->get_route(), '/') !== erp_mesh_endpoint_route()) {
        return new WP_Error('mesh_auth_scope', 'Credential này chỉ dành cho MCP ERP đã duyệt.', ['status' => 403]);
    }
    return $result;
}, 5, 3);
add_action('wp_authenticate_application_password_errors', static function ($error, $user): void {
    if ($user instanceof WP_User && in_array('erp_mesh_reader', (array) $user->roles, true)
        && defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) $error->add('mesh_auth_scope', 'Không cho phép XML-RPC với tài khoản MCP.');
}, 10, 2);

<?php
if (!defined('ABSPATH')) exit;

/** Order navigation only; loaded for order admin requests, the standalone workspace and (lazily, at admin_menu) admin pages. */
function thean_order_portal_is_route(): bool
{
    if (is_admin()) return false;
    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    return is_string($path) && rtrim($path, '/') === rtrim((string) wp_parse_url(home_url('/quan-ly-don/'), PHP_URL_PATH), '/');
}

function thean_order_portal_can(): bool
{
    return current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce');
}

function thean_order_portal_url(array $args = []): string
{
    return add_query_arg($args, home_url('/quan-ly-don/'));
}

/** Only redirect navigation, never a mutation, edit screen, AJAX or explicit Woo fallback. */
function thean_order_portal_redirect_url(): string
{
    global $pagenow;
    if (!is_admin() || wp_doing_ajax() || !thean_order_portal_can()
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || !empty($_GET['thean_wc_list']) || !empty($_GET['_wpnonce'])) return '';
    $action = $_GET['action'] ?? '';
    $secondary = $_GET['action2'] ?? '';
    if (!in_array($action, ['', '-1', 'new'], true) || !in_array($secondary, ['', '-1'], true)) return '';
    $list = ($pagenow === 'edit.php' && ($_GET['post_type'] ?? '') === 'shop_order')
        || ($pagenow === 'admin.php' && ($_GET['page'] ?? '') === 'wc-orders');
    $phone = $pagenow === 'admin.php' && ($_GET['page'] ?? '') === 'phone-orders-for-woocommerce';
    $new = $pagenow === 'post-new.php' && ($_GET['post_type'] ?? '') === 'shop_order';
    if (!$list && !$phone && !$new) return '';
    // Preserve native filters not supported by the board by leaving their Woo list available.
    foreach (['_customer_user', 'customer', 'm', 'orderby', 'paged', 'filter_action'] as $key) {
        if (!empty($_GET[$key])) return '';
    }
    $args = [];
    foreach (['s', 'thean_board', 'thean_range', 'thean_workspace', 'thean_product_tool', 'thean_export_tool'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
    }
    $status = $_GET['status'] ?? ($_GET['post_status'] ?? '');
    if (in_array($status, ['trash', 'draft', 'auto-draft', 'checkout-draft'], true)) return '';
    if (is_string($status) && $status !== '' && $status !== 'all') {
        $status = preg_replace('/^wc-/', '', sanitize_key($status));
        $args['s'] = trim('/tt ' . $status . ' ' . ($args['s'] ?? ''));
    }
    if ($action === 'new' || $phone || $new) $args['thean_workspace'] = 'create';
    if ($phone && !empty($_GET['order_id']) && is_scalar($_GET['order_id'])) $args['order_id'] = absint($_GET['order_id']);
    return thean_order_portal_url($args);
}

/**
 * Menu destinations → portal, so routine navigation skips the wp-admin redirect hop.
 * Hooked from the theme loader on every non-AJAX admin page (admin_menu, PHP_INT_MAX), not here.
 */
function thean_order_portal_rewrite_menu(): void
{
    if (!thean_order_portal_can()) return;
    global $menu, $submenu;
    $replace = static function (&$items): void {
        foreach ($items as &$item) {
            $url = $item[2] ?? '';
            if (in_array($url, ['edit.php?post_type=shop_order', 'admin.php?page=wc-orders', 'wc-orders'], true)) $item[2] = thean_order_portal_url();
            elseif (in_array($url, ['phone-orders-for-woocommerce', 'admin.php?page=phone-orders-for-woocommerce', 'post-new.php?post_type=shop_order'], true)) $item[2] = thean_order_portal_url(['thean_workspace' => 'create']);
        }
    };
    if (is_array($menu)) $replace($menu);
    if (is_array($submenu)) foreach ($submenu as &$items) $replace($items);
}

add_action('admin_init', function (): void {
    $url = thean_order_portal_redirect_url();
    if ($url !== '') { wp_safe_redirect($url); exit; }
}, 0);


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
    // Every list link lands on the portal; Woo-only filters (customer, month, sort, Admin Columns rules…) are
    // dropped — the native list stays reachable with ?thean_wc_list=1 ("Phiên bản cũ").
    $args = [];
    foreach (['s', 'thean_board', 'thean_range', 'thean_workspace', 'thean_product_tool', 'thean_export_tool'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key]) && $_GET[$key] !== '') $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
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

/** Woo order screens (list, edit, new, Phone Orders) — their own menu slugs must stay registered. */
function thean_order_portal_is_order_screen(): bool
{
    return ($_GET['post_type'] ?? '') === 'shop_order'
        || in_array($_GET['page'] ?? '', ['wc-orders', 'phone-orders-for-woocommerce'], true);
}

/**
 * Menu destinations → portal, so routine navigation skips the wp-admin redirect hop.
 * Hooked from the theme loader at admin_init (PHP_INT_MAX), NOT admin_menu: wp-admin/menu.php checks
 * user_can_access_admin_page() against $menu/$submenu right after admin_menu, so a rewritten slug
 * makes WordPress deny the Woo order pages ("không được phép truy cập"). admin_init runs after that
 * check and before the menu is printed. Order screens keep native slugs (page title / fallback list).
 */
function thean_order_portal_rewrite_menu(): void
{
    if (!thean_order_portal_can() || thean_order_portal_is_order_screen()) return;
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


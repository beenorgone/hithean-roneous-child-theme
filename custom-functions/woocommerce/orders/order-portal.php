<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/order-navigation.php';

/** Danh sách đơn WooCommerce gốc (đường lui, không bị chuyển hướng) — nút "Phiên bản cũ" ở hàng tab. */
function thean_order_portal_legacy_url(): string
{
    $hpos = function_exists('thean_ous_hpos_enabled') && thean_ous_hpos_enabled();
    return admin_url($hpos ? 'admin.php?page=wc-orders&thean_wc_list=1' : 'edit.php?post_type=shop_order&thean_wc_list=1');
}

function thean_order_portal_render_create_tab(): void
{
    if (!function_exists('thean_order_portal_is_route') || !thean_order_portal_is_route()) return;
    $args = ['thean_order_embed' => '1'];
    if (!empty($_GET['order_id']) && is_scalar($_GET['order_id'])) $args['order_id'] = absint($_GET['order_id']);
    $url = add_query_arg($args, home_url('/' . (defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don') . '/'));
    ?>
    <section id="thean-ous-create-panel" class="thean-products-workspace" role="tabpanel" aria-labelledby="thean-workspace-create" hidden>
        <section data-product-panel="create">
            <div class="thean-portal-tool-head"><h2>Tạo đơn hàng</h2><button type="button" class="thean-ous__more-btn" data-product-reload="create" title="Tải lại biểu mẫu; nội dung chưa lưu sẽ mất">↻ Tải lại</button><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Mở riêng ↗</a></div>
            <p data-product-status role="status" aria-live="polite">Mở tab để bắt đầu tạo đơn. Dữ liệu đang nhập được giữ khi chuyển tab.</p>
            <iframe title="Tạo đơn hàng" data-src="<?php echo esc_url($url); ?>" referrerpolicy="same-origin" allow="clipboard-write"></iframe>
        </section>
    </section>
    <?php
}

/** Tab 🏦 Sepay: chỉ ở /quan-ly-don, cần plugin eim (widget hub) + quyền xác nhận thanh toán. */
function thean_order_portal_sepay_enabled(): bool
{
    return function_exists('thean_order_portal_is_route') && thean_order_portal_is_route()
        && function_exists('child_theme_hub_pay_enabled') && child_theme_hub_pay_enabled()
        && current_user_can('manage_woocommerce');
}

/**
 * Hàng đợi Sepay của hub (widget eim, tab sepay_all = đủ mọi giao dịch, không lọc theo site). URL iframe mang token
 * dùng 1 lần nên không in sẵn: order-workspace-tabs.js gọi AJAX child_theme_hub_sepay_url khi mở tab / bấm Tải lại.
 */
function thean_order_portal_render_sepay_tab(): void
{
    if (!thean_order_portal_sepay_enabled()) return;
    ?>
    <section id="thean-ous-sepay-panel" class="thean-products-workspace" role="tabpanel" aria-labelledby="thean-workspace-sepay" hidden>
        <section data-product-panel="queue" data-src-action="child_theme_hub_sepay_url" data-src-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-src-nonce="<?php echo esc_attr(wp_create_nonce('child_theme_hub_pay')); ?>">
            <div class="thean-portal-tool-head"><h2>🏦 Hàng đợi Sepay</h2><button type="button" class="thean-ous__more-btn" data-product-reload="queue" title="Mở phiên mới ở hub (dùng khi khung báo hết phiên)">↻ Tải lại</button></div>
            <p data-product-status role="status" aria-live="polite">Mở tab để tải hàng đợi Sepay.</p>
            <iframe title="Hàng đợi Sepay (hub)" referrerpolicy="no-referrer" allow="clipboard-write"></iframe>
        </section>
    </section>
    <?php
}

add_action('template_redirect', function (): void {
    if (!thean_order_portal_is_route()) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    send_frame_options_header();
    if (!is_user_logged_in()) { auth_redirect(); exit; }
    if (!thean_order_portal_can()) wp_die('Bạn không có quyền quản lý đơn hàng.', 'Không có quyền', ['response' => 403]);
    if (!function_exists('wc_get_orders')) wp_die('WooCommerce chưa sẵn sàng.', '', ['response' => 503]);
    require_once __DIR__ . '/order-list-lazy.php';
    require_once __DIR__ . '/orders-admin.php';
    require_once __DIR__ . '/order-universal-search.php';
    require_once __DIR__ . '/order-export-review.php'; // order-ops-step chỉ nạp nó ở wp-admin
    // Row actions come from woocommerce_admin_order_actions; keep pre-rendered rows identical to the admin-ajax ones.
    $pxk = dirname(__DIR__) . '/shipping/print-pxk.php';
    if (is_file($pxk)) require_once $pxk;
    $spx = dirname(__DIR__) . '/shipping/shopee-export/admin-embed.php';
    if (current_user_can('manage_woocommerce') && is_file($spx)) require_once $spx;
    wp_enqueue_style('dashicons');
    // Font chữ của website (Be Vietnam + Oswald) — đúng URL storefront nên dùng lại cache trình duyệt.
    foreach (['theanmarket_enqueue_brand_fonts', 'hithean_enqueue_consolidated_fonts'] as $font_fn) {
        if (function_exists($font_fn)) { $font_fn(); break; }
    }
    thean_ous_enqueue_assets();
    thean_export_review_enqueue();
    if (function_exists('thean_spx_embed_enqueue')) thean_spx_embed_enqueue();
    $asset = '/custom-functions/woocommerce/orders/assets/order-portal.css';
    wp_enqueue_style('thean-order-portal', get_stylesheet_directory_uri() . $asset, ['thean-ous'], (string) filemtime(get_stylesheet_directory() . $asset));
    $timings = ['wp_boot' => (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000];
    $initial = thean_order_portal_board_initial();
    if ($initial) {
        $timings += $initial['timings'];
        wp_add_inline_script('thean-ous', 'window.theanOusCfg.boardInitial = ' . wp_json_encode($initial['config']) . ';', 'before');
    }
    status_header(200);
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    $render_started = microtime(true);
    ob_start(); // render cả trang trước để Server-Timing có thời gian render
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Quản lý đơn hàng</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><?php wp_print_styles(); if (function_exists('custom_order_status_styles')) custom_order_status_styles(); /* màu trạng thái tuỳ chỉnh — ở wp-admin in qua admin_head */ wp_print_head_scripts(); ?></head>
    <body class="thean-order-portal<?php echo function_exists('thean_spx_embed_enabled') && thean_spx_embed_enabled() ? ' thean-spx-has-foot' : ''; // Shopee toast clears the footer menu ?>"><main class="wrap" id="thean-order-portal">
        <h1 class="screen-reader-text">Quản lý đơn hàng</h1><div class="wp-header-end"></div>
        <?php thean_ous_render_ui(); ?>
        <?php if (function_exists('thean_spx_embed_render')) thean_spx_embed_render(); ?>
    </main>
    <?php
    thean_qm_render();
    $returns = function_exists('hithean_order_admin_render_return_action_modals') ? 'hithean_order_admin_render_return_action_modals' : 'thean_order_admin_render_return_action_modals';
    if (function_exists($returns)) $returns();
    thean_export_review_render();
    if (function_exists('child_theme_payment_hub_link_script')) child_theme_payment_hub_link_script();
    wp_print_footer_scripts();
    ?>
    </body></html>
    <?php
    $html = (string) ob_get_clean();
    $timings['portal_render'] = (microtime(true) - $render_started) * 1000;
    $timings['portal_total'] = (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $render_started)) * 1000;
    thean_ous_timing_header($timings);
    echo $html; // phpcs:ignore
    exit;
}, 3);

/**
 * Trang đầu board in sẵn vào trang (bỏ 1 lượt admin-ajax). Tab = ?thean_board, không có thì tab đã xem lần trước
 * (cookie thean_ous_tab do JS ghi cùng localStorage). Mở thẳng tab Tạo đơn → board hoãn tới khi về tab Đơn hàng: bỏ qua.
 * Lỗi bất kỳ → null, client tự gọi AJAX như cũ.
 */
function thean_order_portal_board_initial(): ?array
{
    if (($_GET['thean_workspace'] ?? '') === 'create' || !function_exists('thean_ous_board_payload')) return null;
    $requested = (string) (isset($_GET['thean_board']) && is_scalar($_GET['thean_board'])
        ? wp_unslash($_GET['thean_board'])
        : (isset($_COOKIE['thean_ous_tab']) && is_scalar($_COOKIE['thean_ous_tab']) ? wp_unslash($_COOKIE['thean_ous_tab']) : ''));
    $requested = sanitize_key($requested);
    $range = thean_ous_range(isset($_GET['thean_range']) && is_scalar($_GET['thean_range']) ? (string) wp_unslash($_GET['thean_range']) : 'auto');
    try {
        $out = thean_ous_board_payload(['tab' => $requested, 'range' => $range]);
    } catch (Throwable $e) {
        return null;
    }
    return ['config' => ['requested' => $requested, 'range' => $range, 'data' => $out['data']], 'timings' => $out['timings']];
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Trang danh sách đơn admin: MẶC ĐỊNH KHÔNG TẢI bảng danh sách của WooCommerce (đã có ô tìm + board
 * "Cần xử lý" ở order-universal-search.php). Nút "Tải danh sách Woo" mở lại trang kèm ?thean_wc_list=1;
 * không nhớ — lần vào sau lại tắt.
 *
 * Phải nạp SỚM (admin, trước load-{screen}): WC chạy truy vấn danh sách trong prepare_items(), trước khi
 * các file nạp ở admin_enqueue_scripts kịp chạy.
 * - Legacy (edit.php?post_type=shop_order): posts_pre_query trả rỗng cho main query → không chạy SQL đơn;
 *   tắt luôn dropdown tháng.
 * - HPOS (admin.php?page=wc-orders): truy vấn của list table bị bỏ qua qua woocommerce_hpos_pre_query
 *   (phòng khi bản WC không có filter đó: chỉ lấy 1 dòng).
 * Bảng vẫn hiện như cũ khi: có ?thean_wc_list=1 (JS gắn tiếp vào link phân trang / tab trạng thái / sắp xếp),
 * tìm kiếm cũ ?s=, lọc khách / tháng, hoặc vừa chạy thao tác hàng loạt.
 * Nút "Thêm đơn hàng" của trang danh sách trỏ sang trang Tạo đơn (/tao-don/) khi theme có module tạo đơn hộ khách.
 * Dùng chung theanmarket + hithean.
 */

/** Đang ở màn danh sách đơn (không phải màn sửa / tạo đơn)? Không dùng get_current_screen (chưa có lúc này). */
function thean_wcl_is_list_request(): bool
{
    $pagenow = $GLOBALS['pagenow'] ?? '';
    if ($pagenow === 'edit.php') {
        return sanitize_key(wp_unslash($_GET['post_type'] ?? '')) === 'shop_order';
    }
    return $pagenow === 'admin.php'
        && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'wc-orders'
        && !in_array(sanitize_key(wp_unslash($_GET['action'] ?? '')), ['edit', 'new'], true);
}

/** Người xem đang chủ động dùng bảng Woo (bấm nút, tìm cũ, lọc, phân trang, kết quả thao tác hàng loạt)? */
function thean_wcl_requested(): bool
{
    foreach (['thean_wc_list', 's', '_customer_user', 'm', 'filter_action', 'changed', 'bulk_action', 'trashed', 'untrashed', 'deleted'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return true;
        }
    }
    foreach (array_keys($_GET) as $key) {
        if (strpos((string) $key, 'marked_') === 0) {
            return true;
        }
    }
    return false;
}

/** Bảng Woo đang tắt cho request này? (chỉ với người dùng có ô tìm đơn thay thế) */
function thean_wcl_off(): bool
{
    static $off = null;
    if ($off === null) {
        $off = is_admin() && !wp_doing_ajax() && thean_wcl_is_list_request() && !thean_wcl_requested()
            && (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce'));
    }
    return $off;
}

/** Trạng thái cho JS (window.theanOusCfg.wcList): JS tự gắn ?thean_wc_list=1 vào URL hiện tại để bật. */
function thean_wcl_state(): array
{
    $base = ($GLOBALS['pagenow'] ?? '') === 'edit.php'
        ? admin_url('edit.php?post_type=shop_order')
        : admin_url('admin.php?page=wc-orders');
    return ['off' => thean_wcl_off(), 'hideUrl' => $base];
}

// Legacy: main query của edit.php → rỗng, không chạy SQL.
add_filter('posts_pre_query', function ($posts, $query) {
    if ($posts !== null || !thean_wcl_off() || !$query instanceof WP_Query || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') {
        return $posts;
    }
    $query->found_posts   = 0;
    $query->max_num_pages = 0;
    return [];
}, 10, 2);

add_filter('disable_months_dropdown', function ($disable, $post_type) {
    return $post_type === 'shop_order' && thean_wcl_off() ? true : $disable;
}, 10, 2);

// HPOS: đánh dấu truy vấn kế tiếp (của list table) để bỏ qua.
add_filter('woocommerce_order_list_table_prepare_items_query_args', function ($args) {
    if (thean_wcl_off()) {
        $GLOBALS['thean_wcl_skip_next'] = true;
        $args['limit'] = 1; // dự phòng nếu WC không có woocommerce_hpos_pre_query
    }
    return $args;
});
add_filter('woocommerce_hpos_pre_query', function ($pre) {
    if (empty($GLOBALS['thean_wcl_skip_next'])) {
        return $pre;
    }
    unset($GLOBALS['thean_wcl_skip_next']);
    return [[], 0, 0]; // [order ids, tổng, số trang]
});

// Class ở <body> để CSS (assets/order-universal-search.css, nạp ở <head>) ẩn vùng bảng — không chớp.
add_filter('admin_body_class', function ($classes) {
    return thean_wcl_off() ? $classes . ' thean-wc-list-off' : $classes;
});

/**
 * URL trang Tạo đơn (/tao-don/) nếu module tạo đơn hộ khách có trong theme: đã nạp (hithean nạp mọi request →
 * có ORDER_CREATOR_ROUTE) hoặc được khai trong tpc_loader_modules() (theanmarket chỉ nạp ở /tao-don).
 * '' = không có module hoặc người xem không có quyền tạo đơn.
 */
function thean_order_creator_url(): string
{
    $has = defined('ORDER_CREATOR_ROUTE');
    if (!$has && function_exists('tpc_loader_modules')) {
        foreach ((array) tpc_loader_modules() as $module) {
            if (($module['id'] ?? '') === 'order_creator') {
                $has = true;
                break;
            }
        }
    }
    if (!$has || (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce'))) {
        return '';
    }
    $route = defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don';
    return home_url('/' . trim($route, '/') . '/');
}

// Nút "Thêm đơn hàng" (page-title-action) ở danh sách đơn → /tao-don/ (tab mới, giữ trang danh sách).
add_action('admin_footer', function (): void {
    if (!thean_wcl_is_list_request()) {
        return;
    }
    $url = thean_order_creator_url();
    if ($url === '') {
        return;
    }
    ?>
    <script>
    document.querySelectorAll('.wrap a.page-title-action').forEach(function (a) {
        if (!/post-new\.php|action=new/.test(a.getAttribute('href') || '')) return;
        a.href = <?php echo wp_json_encode($url); ?>;
        a.target = '_blank';
        a.rel = 'noopener';
        a.title = 'Tạo đơn trên trang Tạo đơn';
    });
    </script>
    <?php
});

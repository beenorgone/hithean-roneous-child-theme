<?php
if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('hithean_order_item_has_the_an_organics_brand')) {
    function hithean_order_item_has_the_an_organics_brand($product)
    {
        if (!$product instanceof WC_Product) {
            return false;
        }

        $product_ids = array_filter(array_unique([
            (int) $product->get_id(),
            (int) $product->get_parent_id(),
        ]));

        foreach ($product_ids as $product_id) {
            $terms = wp_get_post_terms($product_id, 'thuong-hieu');
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (
                    strtolower((string) $term->slug) === 'the-an-organics'
                    || strtolower((string) $term->name) === 'the an organics'
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}

add_action('woocommerce_before_order_itemmeta', 'hithean_display_order_item_seal_checkbox', 20, 3);
function hithean_display_order_item_seal_checkbox($item_id, $item, $product)
{
    if (!hithean_order_item_has_the_an_organics_brand($product)) {
        return;
    }

    echo '<label style="display:inline-flex;align-items:center;gap:4px;margin:4px 0 0;font-weight:700;"><input type="checkbox">TEM NIÊM PHONG</label>';
}

if (!function_exists('wr_order_item_edit_lock_user_is_restricted')) {
    function wr_order_item_edit_lock_user_is_restricted()
    {
        return is_admin() && current_user_can('manage_woocommerce');
    }
}

if (!function_exists('wr_order_item_edit_lock_render_ui')) {
    function wr_order_item_edit_lock_render_ui()
    {
        if (!wr_order_item_edit_lock_user_is_restricted()) {
            return;
        }
        ?>
        <style>
            #woocommerce-order-items #order_line_items .wc-order-edit-line-item,
            #woocommerce-order-items #order_line_items .wc-order-edit-product {
                display: none !important;
            }
        </style>
        <script>
            jQuery(function ($) {
                const lockControls = function () {
                    $('#woocommerce-order-items #order_line_items .wc-order-edit-line-item, #woocommerce-order-items #order_line_items .wc-order-edit-product').remove();
                    $('#woocommerce-order-items #order_line_items .quantity input, #woocommerce-order-items #order_line_items .line_cost input, #woocommerce-order-items #order_line_items .line_tax input').prop('readonly', true).prop('disabled', true);
                };

                lockControls();
                $(document.body).on('wc_order_items_reloaded updated', lockControls);
            });
        </script>
        <?php
    }
}
add_action('admin_print_footer_scripts', 'wr_order_item_edit_lock_render_ui');

// ================= Bảng điều phối đơn =================
// Thay nút "Chỉnh đơn" cũ (đã có trong box Chứng từ & tiện ích) + ẩn metabox "Thông tin bổ sung".
require_once __DIR__ . '/order-workspace.php';

// Legacy (post.php) và HPOS (wc-orders) — render 1 lần ở đầu form sửa đơn.
// Legacy dùng edit_form_top (trước #poststuff): edit_form_after_title nằm trong #post-body-content mà WC ẩn với shop_order.
add_action('edit_form_top', function ($post) {
    if ($post instanceof WP_Post && $post->post_type === 'shop_order') {
        thean_render_order_workspace($post->ID);
    }
});
add_action('order_edit_form_top', 'thean_render_order_workspace');

// File này được nạp trong admin_enqueue_scripts (core/admin.php) → enqueue luôn.
(function () {
    $dir = HITHEAN_THEME_DIR . '/custom-functions/woocommerce/orders/assets/';
    $uri = get_stylesheet_directory_uri() . '/custom-functions/woocommerce/orders/assets/';
    wp_enqueue_style('thean-order-workspace', $uri . 'order-workspace.css', [], (string) @filemtime($dir . 'order-workspace.css'));
    wp_enqueue_script('thean-order-workspace', $uri . 'order-workspace.js', [], (string) @filemtime($dir . 'order-workspace.js'), true);
    if (function_exists('thean_flow_popup_enqueue')) {
        thean_flow_popup_enqueue(true); // popup Đặt trước / xác nhận giao… — xong thì tải lại trang
    }
    if (function_exists('thean_vat_request_enqueue')) {
        thean_vat_request_enqueue(true); // popup Nhập thông tin xuất VAT — xong thì tải lại trang
    }
})();

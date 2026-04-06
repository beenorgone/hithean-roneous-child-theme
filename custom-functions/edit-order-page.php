<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wr_order_item_edit_lock_user_is_restricted')) {
    function wr_order_item_edit_lock_user_is_restricted()
    {
        return is_admin() && current_user_can('manage_woocommerce');
    }
}

if (!function_exists('wr_order_item_edit_lock_get_order_id')) {
    function wr_order_item_edit_lock_get_order_id()
    {
        return absint(wp_unslash($_REQUEST['order_id'] ?? $_REQUEST['post'] ?? $_REQUEST['id'] ?? 0));
    }
}

if (!function_exists('wr_order_item_edit_lock_is_blocked_action')) {
    function wr_order_item_edit_lock_is_blocked_action()
    {
        $blocked_actions = [
            'woocommerce_add_order_item',
            'woocommerce_remove_order_item',
            'woocommerce_save_order_items',
        ];

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        return in_array($action, $blocked_actions, true);
    }
}

if (!function_exists('wr_order_item_edit_lock_block_actions')) {
    function wr_order_item_edit_lock_block_actions()
    {
        $order_id = wr_order_item_edit_lock_get_order_id();

        if (
            !wr_order_item_edit_lock_user_is_restricted() ||
            !wp_doing_ajax() ||
            !wr_order_item_edit_lock_is_blocked_action() ||
            $order_id < 1 ||
            get_post_type($order_id) !== 'shop_order'
        ) {
            return;
        }

        wp_send_json_error([
            'error' => __('Bạn không được phép chỉnh sản phẩm trong đơn hàng này.', 'roneous'),
        ]);
    }
}
add_action('admin_init', 'wr_order_item_edit_lock_block_actions', 1);

if (!function_exists('wr_order_item_edit_lock_render_ui')) {
    function wr_order_item_edit_lock_render_ui()
    {
        if (!wr_order_item_edit_lock_user_is_restricted()) {
            return;
        }
        ?>
        <style>
            #woocommerce-order-items .add-items,
            #woocommerce-order-items .wc-order-edit-line-item,
            #woocommerce-order-items .wc-order-edit-product,
            #woocommerce-order-items .delete-order-item {
                display: none !important;
            }
        </style>
        <script>
            jQuery(function ($) {
                const lockControls = function () {
                    $('#woocommerce-order-items .add-items, #woocommerce-order-items .wc-order-edit-line-item, #woocommerce-order-items .wc-order-edit-product, #woocommerce-order-items .delete-order-item').remove();
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

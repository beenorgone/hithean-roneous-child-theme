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

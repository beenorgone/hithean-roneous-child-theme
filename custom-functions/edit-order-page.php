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

    echo '<label style="display:inline-flex;align-items:center;gap:4px;margin:4px 0 0;font-weight:700;"><input type="checkbox"> Tem niêm phong</label>';
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

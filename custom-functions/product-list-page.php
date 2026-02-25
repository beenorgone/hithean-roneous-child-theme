<?php

/**
 * Show product subheading under product title on shop/archive loop cards.
 */
function display_loop_product_subheading()
{
    global $product;

    if (!$product instanceof WC_Product) {
        return;
    }

    $subheading = get_post_meta($product->get_id(), 'product_info_subheading', true);
    if (empty($subheading)) {
        return;
    }

    echo '<div class="product-info-subheading">' . wp_kses_post(do_shortcode($subheading)) . '</div>';
}
add_action('woocommerce_after_shop_loop_item_title', 'display_loop_product_subheading', 9);

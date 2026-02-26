<?php

/**
 * Move out-of-stock products to the end on WooCommerce product list queries.
 */
function hithean_move_out_of_stock_to_end($clauses, $query)
{
    if (!($query instanceof WP_Query) || is_admin() || !$query->is_main_query()) {
        return $clauses;
    }

    // Restrict to WooCommerce catalog main query only.
    if ('product_query' !== $query->get('wc_query')) {
        return $clauses;
    }

    global $wpdb;

    $stock_alias = 'hithean_stock_status_pm';

    if (strpos($clauses['join'], " {$stock_alias} ") === false) {
        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS {$stock_alias} ON ({$wpdb->posts}.ID = {$stock_alias}.post_id AND {$stock_alias}.meta_key = '_stock_status')";
    }

    $stock_order = "CASE {$stock_alias}.meta_value WHEN 'outofstock' THEN 1 ELSE 0 END ASC";
    $clauses['orderby'] = !empty($clauses['orderby'])
        ? $stock_order . ', ' . $clauses['orderby']
        : $stock_order;

    return $clauses;
}
add_filter('posts_clauses', 'hithean_move_out_of_stock_to_end', 20, 2);

/**
 * Show out-of-stock text under price on shop/archive loop cards.
 */
function hithean_display_loop_out_of_stock_text()
{
    global $product;

    if (!$product instanceof WC_Product || $product->is_in_stock()) {
        return;
    }

    echo '<div class="product-stock-status out-of-stock">' . esc_html__('Hết hàng', 'roneous') . '</div>';
}
add_action('woocommerce_after_shop_loop_item_title', 'hithean_display_loop_out_of_stock_text', 11);

/**
 * Show product subheading under product title on shop/archive loop cards.
 */
function hithean_display_loop_product_subheading()
{
    global $product;

    if (!$product instanceof WC_Product) {
        return;
    }

    $subheading = get_post_meta($product->get_id(), 'product_info_subheading', true);
    if (empty($subheading)) {
        return;
    }

    // Keep rendering lightweight and safe in archive loops.
    echo '<div class="product-info-subheading">' . wp_kses_post($subheading) . '</div>';
}
add_action('woocommerce_after_shop_loop_item_title', 'hithean_display_loop_product_subheading', 12);

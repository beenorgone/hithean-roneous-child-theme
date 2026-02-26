<?php

defined('ABSPATH') || exit;

if (!function_exists('product_list_move_out_of_stock_to_end')) {
    /**
     * Move out-of-stock products to the end on WooCommerce catalog main query.
     */
    function product_list_move_out_of_stock_to_end($clauses, $query)
    {
        if (!($query instanceof WP_Query) || is_admin() || !$query->is_main_query()) {
            return $clauses;
        }

        // Restrict to WooCommerce catalog query only (shop, category, tag, attributes).
        if ('product_query' !== (string) $query->get('wc_query')) {
            return $clauses;
        }

        $post_type = $query->get('post_type');
        if (is_string($post_type) && '' !== $post_type && 'product' !== $post_type) {
            return $clauses;
        }
        if (is_array($post_type) && !in_array('product', $post_type, true)) {
            return $clauses;
        }

        global $wpdb;

        $stock_alias = 'product_stock_status_pm';
        $join = isset($clauses['join']) ? (string) $clauses['join'] : '';
        $orderby = isset($clauses['orderby']) ? (string) $clauses['orderby'] : '';

        if (false === strpos($join, " {$stock_alias} ")) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS {$stock_alias} ON ({$wpdb->posts}.ID = {$stock_alias}.post_id AND {$stock_alias}.meta_key = '_stock_status')";
        }

        // Out-of-stock last, keep existing ordering as secondary sort.
        $stock_order = "CASE WHEN {$stock_alias}.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC";

        $clauses['join'] = $join;
        $clauses['orderby'] = '' !== $orderby ? $stock_order . ', ' . $orderby : $stock_order;

        return $clauses;
    }
    add_filter('posts_clauses', 'product_list_move_out_of_stock_to_end', 20, 2);
}

if (!function_exists('product_list_display_loop_out_of_stock_text')) {
    /**
     * Show out-of-stock text under price on shop/archive loop cards.
     */
    function product_list_display_loop_out_of_stock_text()
    {
        global $product;

        if (!$product instanceof WC_Product || $product->is_in_stock()) {
            return;
        }

        echo '<div class="product-stock-status out-of-stock" style="margin-bottom: 20px;">' . esc_html__('Hết hàng', 'roneous') . '</div>';
    }
    add_action('woocommerce_after_shop_loop_item_title', 'product_list_display_loop_out_of_stock_text', 11);
}

if (!function_exists('display_loop_product_subheading')) {
    /**
     * Show product subheading under product title on shop/archive loop cards.
     */
    function display_loop_product_subheading()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $subheading = trim((string) get_post_meta($product->get_id(), 'product_info_subheading', true));
        if ('' === $subheading) {
            return;
        }

        echo '<div class="product-info-subheading">' . wp_kses_post($subheading) . '</div>';
    }
    add_action('woocommerce_after_shop_loop_item_title', 'display_loop_product_subheading', 12);
}

<?php

defined('ABSPATH') || exit;

if (!function_exists('hithean_is_product_listing_context')) {
    function hithean_is_product_listing_context(): bool
    {
        return !is_admin()
            && (
                (function_exists('is_shop') && is_shop())
                || (function_exists('is_product_taxonomy') && is_product_taxonomy())
            );
    }
}

if (!function_exists('hithean_dequeue_cart_fragments_off_conversion_pages')) {
    function hithean_dequeue_cart_fragments_off_conversion_pages(): void
    {
        if (is_admin()) {
            return;
        }

        $needs_cart_fragments = (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page())
            || (function_exists('is_product') && is_product());

        if ($needs_cart_fragments) {
            return;
        }

        wp_dequeue_script('wc-cart-fragments');
        wp_deregister_script('wc-cart-fragments');
    }
    add_action('wp_enqueue_scripts', 'hithean_dequeue_cart_fragments_off_conversion_pages', 100);
}

if (!function_exists('hithean_prioritize_first_catalog_product_image')) {
    function hithean_prioritize_first_catalog_product_image(array $attr, WP_Post $attachment, $size): array
    {
        static $prioritized = false;

        if ($prioritized || !hithean_is_product_listing_context() || 'woocommerce_thumbnail' !== $size) {
            return $attr;
        }

        $prioritized = true;
        $attr['loading'] = 'eager';
        $attr['fetchpriority'] = 'high';
        $attr['decoding'] = 'async';

        return $attr;
    }
    add_filter('wp_get_attachment_image_attributes', 'hithean_prioritize_first_catalog_product_image', 20, 3);
}

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

        $stock_order = "CASE WHEN {$stock_alias}.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC";

        $clauses['join'] = $join;
        $clauses['orderby'] = '' !== $orderby ? $stock_order . ', ' . $orderby : $stock_order;

        return $clauses;
    }
    add_filter('posts_clauses', 'product_list_move_out_of_stock_to_end', 20, 2);
}

if (!function_exists('product_list_display_loop_coming_soon_text')) {
    /**
     * Show a coming soon label below title when a product has no price.
     */
    function product_list_display_loop_coming_soon_text()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        if ('' !== (string) $product->get_price()) {
            return;
        }

        echo '<div class="product-coming-soon">' . esc_html__('SẮP RA MẮT', 'roneous') . '</div>';
    }
    add_action('woocommerce_after_shop_loop_item_title', 'product_list_display_loop_coming_soon_text', 9);
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

if (!function_exists('hithean_hide_loop_add_to_cart_when_out_of_stock')) {
    /**
     * Out-of-stock loop cards: no add-to-cart / "Read more" button — only "Xem chi tiết" remains.
     */
    function hithean_hide_loop_add_to_cart_when_out_of_stock($html, $product)
    {
        if ($product instanceof WC_Product && !$product->is_in_stock()) {
            return '';
        }

        return $html;
    }
    add_filter('woocommerce_loop_add_to_cart_link', 'hithean_hide_loop_add_to_cart_when_out_of_stock', 20, 2);
}

if (!function_exists('hithean_get_loop_addon_promo_lines')) {
    /**
     * Active promo lines of a product from the product-addon plugin (sale message or ON discount/coupon/product rules).
     */
    function hithean_get_loop_addon_promo_lines(int $product_id): array
    {
        if (!function_exists('simple_addon_get_product_addons') || !function_exists('simple_addon_get_product_sale_message')) {
            return [];
        }

        $sale_message = trim(wp_strip_all_tags((string) simple_addon_get_product_sale_message($product_id)));
        if ('' !== $sale_message) {
            return [wp_trim_words($sale_message, 24, '…')];
        }

        $lines = [];
        foreach (simple_addon_get_product_addons($product_id) as $addon) {
            if ('ON' !== ($addon['status'] ?? '') || !in_array($addon['type'] ?? '', ['discount', 'coupon', 'product'], true)) {
                continue;
            }

            $line = trim((string) ('' !== trim((string) ($addon['description'] ?? '')) ? $addon['description'] : ($addon['name'] ?? '')));

            if ('' !== $line) {
                $lines[$line] = $line;
            }
        }

        return array_values($lines);
    }
}

if (!function_exists('hithean_display_loop_addon_promo')) {
    /**
     * Show active product-addon program under price, above loop action buttons.
     */
    function hithean_display_loop_addon_promo()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $lines = hithean_get_loop_addon_promo_lines($product->get_id());
        if (empty($lines)) {
            return;
        }

        static $styles_printed = false;
        if (!$styles_printed) {
            $styles_printed = true;
            echo '<style id="hithean-product-addon-promo">' . ".product-addon-promo{margin:0 15px 10px;padding:8px 10px;border:1px dashed #e0a100;border-radius:6px;background:#fff8e6;color:#7a4b00;font-size:13px;line-height:1.45;text-align:left}.product-addon-promo__label{display:inline-block;margin-bottom:4px;padding:1px 8px;border-radius:10px;background:#e0a100;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase}.product-addon-promo ul{margin:0;padding:0 0 0 16px}.product-addon-promo li{margin:0}.product-addon-promo .product-addon-promo__more{list-style:none;margin-left:-16px;font-style:italic}@media (max-width:767px){.product-addon-promo{margin:10px 15px}}" . '</style>';
        }

        $max_lines = 2;
        $extra = count($lines) - $max_lines;

        echo '<div class="product-addon-promo"><span class="product-addon-promo__label">' . esc_html__('Ưu đãi', 'roneous') . '</span><ul>';
        foreach (array_slice($lines, 0, $max_lines) as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        if ($extra > 0) {
            echo '<li class="product-addon-promo__more">' . esc_html(sprintf('+%d ưu đãi khác', $extra)) . '</li>';
        }
        echo '</ul></div>';
    }
    // After product link close (5), before add-to-cart (10) and "Xem chi tiết" (15).
    add_action('woocommerce_after_shop_loop_item', 'hithean_display_loop_addon_promo', 7);
}

if (!function_exists('hithean_product_taxonomy_inline_styles')) {
    function hithean_product_taxonomy_inline_styles(): void
    {
        if (!hithean_is_product_listing_context() && !is_search()) {
            return;
        }
        ?>
        <style id="hithean-product-taxonomy-cro">
            .row.hithean-product-grid{display:flex;flex-wrap:wrap;width:100%}
            .row.hithean-product-grid:before,.row.hithean-product-grid:after{display:none}
            .row.hithean-product-grid>.product{float:none;display:flex;margin-bottom:30px}
            .hithean-product-grid .product .image-box{height:100%;width:100%;display:flex;flex-direction:row;flex-wrap:wrap;justify-content:center;align-content:flex-start}
            .hithean-product-grid .product .woocommerce-LoopProduct-link{flex:0 0 100%}
            .hithean-product-grid .product .product-addon-promo{flex:0 0 calc(100% - 30px);max-width:calc(100% - 30px)}
            .hithean-product-grid .product .woocommerce-LoopProduct-link img{width:100%;aspect-ratio:1/1;object-fit:cover}
            .hithean-product-grid .product .woocommerce-loop-product__title{min-height:2.6em;margin-top:14px}
            .hithean-product-grid .product .price{display:block;margin:8px 0 14px}
            .product-info-subheading{min-height:2.4em;margin-bottom:16px}
        </style>
        <?php
    }
    add_action('wp_head', 'hithean_product_taxonomy_inline_styles', 40);
}

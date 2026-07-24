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

        // Hide on desktop for archive pages (Shop, Category, Tag, etc.)
        if (!wp_is_mobile() && (is_shop() || is_product_taxonomy())) {
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

if (!function_exists('hithean_product_taxonomy_cro_header')) {
    function hithean_product_taxonomy_cro_header(): void
    {
        if (!hithean_is_product_listing_context()) {
            return;
        }

        $term = get_queried_object();
        $title = $term instanceof WP_Term ? (string) $term->name : (string) get_option('roneous_shop_title', __('Sản phẩm', 'roneous'));
        $count = 0;

        if ($term instanceof WP_Term && isset($term->count)) {
            $count = max(0, (int) $term->count);
        } elseif (isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof WP_Query) {
            $count = max(0, (int) $GLOBALS['wp_query']->found_posts);
        }

        $count_text = sprintf(
            _n('%s sản phẩm đang có', '%s sản phẩm đang có', $count, 'roneous'),
            number_format_i18n($count)
        );

        echo '<section class="hithean-taxonomy-flow" aria-label="' . esc_attr($title) . '">';
        echo '<div class="hithean-taxonomy-flow__copy">';
        echo '<span class="hithean-taxonomy-flow__eyebrow">' . esc_html($count_text) . '</span>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '</div>';
        echo '<a class="hithean-taxonomy-flow__cta" href="#main-content">' . esc_html__('Xem sản phẩm', 'roneous') . '</a>';
        echo '</section>';
    }
    add_action('woocommerce_before_shop_loop', 'hithean_product_taxonomy_cro_header', 1);
}

if (!function_exists('hithean_product_taxonomy_inline_styles')) {
    function hithean_product_taxonomy_inline_styles(): void
    {
        if (!hithean_is_product_listing_context()) {
            return;
        }
        ?>
        <style id="hithean-product-taxonomy-cro">
            .hithean-taxonomy-flow{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 24px;padding:16px 18px;border:1px solid #e6e0d8;background:#fff;border-radius:6px}
            .hithean-taxonomy-flow__copy{min-width:0}
            .hithean-taxonomy-flow__eyebrow{display:block;margin-bottom:4px;color:#6b5b4d;font-size:13px;font-weight:600}
            .hithean-taxonomy-flow h1{margin:0;color:#2f211b;font-size:clamp(22px,3vw,34px);line-height:1.15}
            .hithean-taxonomy-flow__cta{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border-radius:4px;background:#3b2222;color:#fff;font-weight:700;text-decoration:none;white-space:nowrap}
            .woocommerce .masonry .product .image-box,.woocommerce-page .masonry .product .image-box{height:100%;display:flex;flex-direction:column}
            .woocommerce .masonry .product .woocommerce-LoopProduct-link img,.woocommerce-page .masonry .product .woocommerce-LoopProduct-link img{width:100%;aspect-ratio:1/1;object-fit:cover}
            .woocommerce .masonry .product .woocommerce-loop-product__title,.woocommerce-page .masonry .product .woocommerce-loop-product__title{min-height:2.6em;margin-top:14px}
            .woocommerce .masonry .product .price,.woocommerce-page .masonry .product .price{display:block;margin:8px 0 14px}
            .woocommerce .masonry .product .add_to_cart_button,.woocommerce-page .masonry .product .add_to_cart_button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;margin:0 6px 8px;padding:0 16px;border-radius:4px}
            .woocommerce .masonry .product .button--light-blue,.woocommerce-page .masonry .product .button--light-blue{display:inline-flex;align-items:center;justify-content:center;min-height:38px;margin:0 6px 8px;padding:0 14px;border-radius:4px}
            .product-info-subheading{min-height:2.4em;margin-bottom:16px}
            @media(max-width:767px){.hithean-taxonomy-flow{align-items:flex-start;flex-direction:column;margin-bottom:18px;padding:14px}.hithean-taxonomy-flow__cta{width:100%}.woocommerce .masonry .product .add_to_cart_button,.woocommerce-page .masonry .product .add_to_cart_button,.woocommerce .masonry .product .button--light-blue,.woocommerce-page .masonry .product .button--light-blue{width:100%;margin-left:0;margin-right:0}}
        </style>
        <?php
    }
    add_action('wp_head', 'hithean_product_taxonomy_inline_styles', 40);
}

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

// Xếp / ẩn SP hết hàng ở danh sách: out-of-stock-loop.php (Cài đặt ERP → WooCommerce).

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

/*
 * Giá ưu đãi trên thẻ sản phẩm ở danh sách: sản phẩm thuộc rule Discount Rules giảm giá NGAY TỪ 1 SẢN PHẨM
 * (simple discount / bậc số lượng bắt đầu từ 1) hiện "giá gốc gạch — giá ưu đãi" như khi đặt giá Sale thông thường.
 * Giá do chính plugin tính (cùng đường product-purchase.php dùng ở trang chi tiết), theme chỉ trình bày;
 * rule tính theo giỏ hàng / mã / quà tặng không quy được về 1 đơn giá thì giữ nguyên giá đang hiện.
 */
if (!function_exists('hithean_loop_rule_price_ranges')) {
    /**
     * Gộp [giá gốc, giá cuối] của từng lựa chọn mua (SP đơn: 1 cặp; SP biến thể: mỗi biến thể 1 cặp) thành
     * [gốc thấp nhất, gốc cao nhất, cuối thấp nhất, cuối cao nhất]; null khi không lựa chọn nào được giảm (hàm thuần).
     */
    function hithean_loop_rule_price_ranges(array $pairs): ?array
    {
        $regular = [];
        $final = [];
        $discounted = false;
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_numeric($pair[0] ?? null) || !is_numeric($pair[1] ?? null)) {
                continue;
            }
            $regular[] = (float) $pair[0];
            $final[] = min((float) $pair[0], (float) $pair[1]);
            $discounted = $discounted || (float) $pair[1] < (float) $pair[0];
        }

        return $discounted ? [min($regular), max($regular), min($final), max($final)] : null;
    }
}

if (!function_exists('hithean_loop_rule_price_pair')) {
    /**
     * [giá gốc, giá khi mua 1, có rule không] của một sản phẩm / biến thể; giá cuối = giá rule nếu rule áp dụng từ 1 sản phẩm, không thì giá đang bán.
     */
    function hithean_loop_rule_price_pair(WC_Product $product): ?array
    {
        $price = $product->get_price();
        if (!is_numeric($price)) {
            return null;
        }
        $regular = is_numeric($product->get_regular_price()) ? max((float) $product->get_regular_price(), (float) $price) : (float) $price;
        $final = (float) $price;
        $ruled = false;

        if (!function_exists('hithean_product_purchase_rule_price')) {
            require_once HITHEAN_THEME_DIR . '/custom-functions/woocommerce/products/product-purchase.php';
        }
        $details = apply_filters('advanced_woo_discount_rules_get_product_discount_price_from_custom_price', false, $product, 1, 0, 'all', true);
        if ($details !== false && $details !== null && $details !== []) {
            $rule_price = hithean_product_purchase_rule_price($details, 1);
            if ($rule_price !== null && $rule_price < $final) {
                $ruled = true;
                $regular = max($regular, (float) $details['initial_price']);
                $final = min($final, $rule_price);
            }
        }

        return [$regular, $final, $ruled];
    }
}

if (!function_exists('hithean_loop_rule_price_data')) {
    /**
     * Khoảng giá [gốc thấp, gốc cao, ưu đãi thấp, ưu đãi cao] khi sản phẩm có rule giảm ngay từ 1 sản phẩm; null khi không có (nhớ theo request).
     */
    function hithean_loop_rule_price_data(WC_Product $product): ?array
    {
        static $cache = [];
        $id = $product->get_id();
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $cache[$id] = null;
        if (has_filter('advanced_woo_discount_rules_get_product_discount_price_from_custom_price') === false) {
            return null;
        }

        try {
            $pairs = [];
            $units = [$product];
            if ($product->is_type('variable')) {
                $units = array_filter(array_map('wc_get_product', array_slice($product->get_visible_children(), 0, 40)));
            }
            foreach ($units as $unit) {
                $pairs[] = hithean_loop_rule_price_pair($unit);
            }
            // Không rule nào giảm từ 1 sản phẩm (kể cả SP đang Sale thông thường): WooCommerce đã tự hiện đúng.
            if (array_filter($pairs, static fn($pair) => !empty($pair[2]))) {
                $cache[$id] = hithean_loop_rule_price_ranges($pairs);
            }
        } catch (Throwable $error) {
            $cache[$id] = null;
        }

        return $cache[$id];
    }
}

if (!function_exists('hithean_loop_rule_price_html')) {
    /**
     * Thay HTML giá trên thẻ sản phẩm ở danh sách khi rule giảm giá áp dụng ngay từ 1 sản phẩm.
     */
    function hithean_loop_rule_price_html($html, $product)
    {
        if (empty($GLOBALS['hithean_loop_price_scope']) || !$product instanceof WC_Product) {
            return $html;
        }
        $ranges = hithean_loop_rule_price_data($product);
        if ($ranges === null) {
            return $html;
        }
        $show = static function (float $low, float $high) use ($product) {
            $low = wc_get_price_to_display($product, ['price' => $low]);
            $high = wc_get_price_to_display($product, ['price' => $high]);

            return abs($high - $low) < 0.0001 ? $low : wc_format_price_range($low, $high);
        };

        return wc_format_sale_price($show($ranges[0], $ranges[1]), $show($ranges[2], $ranges[3])) . $product->get_price_suffix();
    }
    add_filter('woocommerce_get_price_html', 'hithean_loop_rule_price_html', 9999, 2);
    // Chỉ trong lúc in giá của thẻ sản phẩm (giá = woocommerce_after_shop_loop_item_title @10): không đụng trang chi tiết, giỏ, email.
    add_action('woocommerce_after_shop_loop_item_title', static function (): void { $GLOBALS['hithean_loop_price_scope'] = true; }, -9999);
    add_action('woocommerce_after_shop_loop_item_title', static function (): void { $GLOBALS['hithean_loop_price_scope'] = false; }, 9999);
}

if (!function_exists('hithean_loop_rule_sale_flash')) {
    /**
     * Nhãn Sale trên ảnh cho sản phẩm có rule giảm từ 1 sản phẩm — cùng span.onsale (kiểu nhãn NEW, css/custom.css) và cùng chữ
     * với nhãn Sale thông thường. SP đang Sale thông thường đã có nhãn của WooCommerce nên bỏ qua.
     */
    function hithean_loop_rule_sale_flash(): void
    {
        global $post, $product;
        if (!$product instanceof WC_Product || $product->is_on_sale() || hithean_loop_rule_price_data($product) === null) {
            return;
        }
        echo apply_filters('woocommerce_sale_flash', '<span class="onsale">' . esc_html__('Sale!', 'woocommerce') . '</span>', $post, $product); // phpcs:ignore WordPress.Security.EscapeOutput
    }
    add_action('woocommerce_before_shop_loop_item_title', 'hithean_loop_rule_sale_flash', 10);

    /**
     * Thẻ sản phẩm đó nhận lớp "sale" như Sale thông thường (nhãn NEW tự xuống dưới nhãn Sale). Không áp cho sản phẩm chính của trang chi tiết.
     */
    function hithean_loop_rule_sale_class($classes, $product)
    {
        if (is_admin() || !$product instanceof WC_Product || in_array('sale', (array) $classes, true)
            || (function_exists('is_product') && is_product() && get_queried_object_id() === $product->get_id())) {
            return $classes;
        }
        if (hithean_loop_rule_price_data($product) !== null) {
            $classes[] = 'sale';
        }

        return $classes;
    }
    add_filter('woocommerce_post_class', 'hithean_loop_rule_sale_class', 20, 2);
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

        if (!$product->is_in_stock() && !$product->backorders_allowed()) { return; }

        // Đã có nút ưu đãi trên thẻ (marketing/offers/loop-button.php) → không in thêm khung này.
        if (function_exists('hithean_loop_offers_enabled') && hithean_loop_offers_enabled()) {
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
            .product-info-subheading{min-height:2.4em;margin-bottom:20px}
        </style>
        <?php
    }
    add_action('wp_head', 'hithean_product_taxonomy_inline_styles', 40);
}

<?php
/*
 * Shortcode: [wc_product_field]
 * Lấy tên / giá / link / CTA của 1 sản phẩm WooCommerce theo id|sku|slug,
 * để nhúng vào landing page tĩnh — tự cập nhật khi sửa sản phẩm.
 *
 * Dùng: [wc_product_field slug="ten-san-pham" field="name"]
 * field: name | permalink | regular_price | sale_price | current_price |
 *        discount_percent | cta_text | image
 */

add_shortcode('wc_product_field', 'child_theme_wc_product_field_shortcode');

function child_theme_wc_product_field_resolve_product(array $atts): ?WC_Product
{
    if (!function_exists('wc_get_product')) {
        return null;
    }

    $product = null;

    if (!empty($atts['id'])) {
        $product = wc_get_product((int) $atts['id']);
    } elseif (!empty($atts['sku'])) {
        $id = wc_get_product_id_by_sku((string) $atts['sku']);
        $product = $id ? wc_get_product($id) : null;
    } elseif (!empty($atts['slug'])) {
        $post = get_page_by_path((string) $atts['slug'], OBJECT, 'product');
        $product = $post ? wc_get_product($post->ID) : null;
    }

    return $product instanceof WC_Product ? $product : null;
}

function child_theme_wc_product_field_shortcode($atts): string
{
    $atts = shortcode_atts([
        'id'    => '',
        'sku'   => '',
        'slug'  => '',
        'field' => 'name',
    ], $atts, 'wc_product_field');

    $product = child_theme_wc_product_field_resolve_product($atts);
    if (!$product instanceof WC_Product) {
        return '';
    }

    switch ($atts['field']) {
        case 'name':
            return esc_html($product->get_name());

        case 'permalink':
            return esc_url((string) get_permalink($product->get_id()));

        case 'regular_price':
            $regular = (float) $product->get_regular_price();
            return $regular > 0 ? wc_price($regular) : '';

        case 'sale_price':
            return wc_price((float) $product->get_price());

        case 'current_price':
            return wc_price((float) $product->get_price());

        case 'discount_percent':
            if (!$product->is_on_sale()) {
                return '';
            }
            $regular = (float) $product->get_regular_price();
            $sale    = (float) $product->get_sale_price();
            if ($regular <= 0) {
                return '';
            }
            return '-' . round((($regular - $sale) / $regular) * 100) . '%';

        case 'cta_text':
            return $product->is_in_stock() ? 'Xem & Mua ngay' : 'Hết hàng — Xem chi tiết';

        case 'image':
            $image_id = $product->get_image_id();
            return $image_id ? esc_url((string) wp_get_attachment_url($image_id)) : '';

        default:
            return '';
    }
}

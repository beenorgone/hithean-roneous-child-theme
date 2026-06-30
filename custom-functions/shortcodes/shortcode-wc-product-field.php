<?php
if (!defined('ABSPATH')) exit;
/*
 * Shortcode: [wc_product_field]
 * Lấy tên / giá / link / CTA của 1 sản phẩm WooCommerce theo id|sku|slug,
 * để nhúng vào landing page tĩnh — tự cập nhật khi sửa sản phẩm.
 *
 * Dùng: [wc_product_field slug="ten-san-pham" field="name"]
 * field: name | permalink | regular_price | sale_price | current_price |
 *        discount_percent | cta_text | image | gallery
 *
 * field="gallery" dựng nguyên khối .anc-gallery (ảnh main + thumbnails) từ
 * ảnh đại diện + album sản phẩm — khớp markup mà initGalleries() trong JS landing
 * page đang dùng, nên thumbnail switching chạy luôn không cần sửa JS.
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
            if ($product->is_in_stock()) {
                return 'Xem & Mua ngay';
            }
            // Sản phẩm gắn tag NEW: hiện "Sắp ra mắt" thay vì "Hết hàng"
            if (has_term(['new', 'NEW'], 'product_tag', $product->get_id())) {
                return 'Sắp ra mắt';
            }
            return 'Hết hàng — Xem chi tiết';

        case 'image':
            $image_id = $product->get_image_id();
            return $image_id ? esc_url((string) wp_get_attachment_url($image_id)) : '';

        case 'gallery':
            return child_theme_wc_product_field_gallery_html($product);

        case 'price_block':
            return child_theme_wc_product_field_price_block_html($product);

        default:
            return '';
    }
}

/**
 * Dựng khối giá: nếu đang sale → giá sale + giá gốc gạch ngang + tag %;
 * nếu không sale → chỉ hiện giá bán (không gạch ngang, không tag).
 */
function child_theme_wc_product_field_price_block_html(WC_Product $product): string
{
    $current = wc_price((float) $product->get_price());

    if (!$product->is_on_sale()) {
        return '<span class="anc-price-sale">' . $current . '</span>';
    }

    $regular = (float) $product->get_regular_price();
    $sale    = (float) $product->get_sale_price();

    $html = '<span class="anc-price-sale">' . $current . '</span>';
    if ($regular > 0) {
        $html .= '<span class="anc-price-original">' . wc_price($regular) . '</span>';
        $percent = round((($regular - $sale) / $regular) * 100);
        if ($percent > 0) {
            $html .= '<span class="anc-price-tag">-' . $percent . '%</span>';
        }
    }

    return $html;
}

/**
 * Dựng markup gallery (.anc-gallery) từ ảnh đại diện + album sản phẩm.
 * Trùng cấu trúc với gallery tĩnh trên landing page để initGalleries() hoạt động.
 */
function child_theme_wc_product_field_gallery_html(WC_Product $product): string
{
    $ids = [];

    $featured = (int) $product->get_image_id();
    if ($featured > 0) {
        $ids[] = $featured;
    }

    foreach ($product->get_gallery_image_ids() as $gid) {
        $gid = (int) $gid;
        if ($gid > 0 && !in_array($gid, $ids, true)) {
            $ids[] = $gid;
        }
    }

    if (!$ids) {
        return '';
    }

    $name     = $product->get_name();
    $main_src = wp_get_attachment_image_url($ids[0], 'woocommerce_single')
        ?: wp_get_attachment_image_url($ids[0], 'full');

    if (!$main_src) {
        return '';
    }

    ob_start();
    ?>
    <div class="anc-gallery">
        <img class="anc-gallery-main" src="<?php echo esc_url((string) $main_src); ?>" alt="<?php echo esc_attr($name); ?>" />
        <?php if (count($ids) > 1) : ?>
        <div class="anc-gallery-thumbs">
            <?php foreach ($ids as $index => $gid) :
                $full = wp_get_attachment_image_url($gid, 'woocommerce_single')
                    ?: wp_get_attachment_image_url($gid, 'full');
                if (!$full) {
                    continue;
                }
                $thumb = wp_get_attachment_image_url($gid, 'woocommerce_thumbnail') ?: $full;
            ?>
            <button class="anc-gallery-thumb<?php echo $index === 0 ? ' is-active' : ''; ?>" type="button" data-src="<?php echo esc_url((string) $full); ?>" aria-label="<?php echo esc_attr($name . ' — ảnh ' . ($index + 1)); ?>">
                <img src="<?php echo esc_url((string) $thumb); ?>" alt="" loading="lazy" decoding="async" />
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

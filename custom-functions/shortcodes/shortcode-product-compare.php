<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/product-compare-ai.php';

function tpc_compare_ajax_nonce()
{
    return 'tpc_compare_ajax';
}

/**
 * Icon SVG nét (stroke) dùng cho các nút của shortcode, màu theo currentColor.
 */
function tpc_compare_icon_svg($key)
{
    static $shapes = [
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'link'    => '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/>',
        'table'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16M15 4v16"/>',
        'sparkle' => '<path d="M12 3l1.8 4.7 4.7 1.8-4.7 1.8L12 16l-1.8-4.7-4.7-1.8 4.7-1.8z"/><path d="M19 15l.8 2.2 2.2.8-2.2.8L19 21l-.8-2.2-2.2-.8 2.2-.8z"/>',
        'reset'   => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'close'   => '<path d="M18 6 6 18M6 6l12 12"/>',
        'check'   => '<path d="m5 12 5 5L20 7"/>',
        'compare' => '<path d="m8 3-4 4 4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
        'cart'    => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.6a2 2 0 0 0 2-1.6L22 7H6"/>',
        'eye'     => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'save'    => '<path d="M5 3h11l5 5v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M7 3v5h8M7 21v-7h10v7"/>',
        'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'prev'    => '<path d="m15 18-6-6 6-6"/>',
        'next'    => '<path d="m9 18 6-6-6-6"/>',
    ];

    if (!isset($shapes[$key])) {
        return '';
    }

    return '<svg class="tpc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' . $shapes[$key] . '</svg>';
}

/**
 * Nội dung nút: icon + nhãn (nhãn bọc span để JS đổi chữ mà không mất icon).
 */
function tpc_compare_button_inner($icon, $label)
{
    return tpc_compare_icon_svg($icon) . '<span class="tpc-btn__label">' . esc_html($label) . '</span>';
}

function tpc_compare_format_price_text($amount)
{
    if ($amount === '' || $amount === null) {
        return '';
    }

    $amount = (float) $amount;
    if ($amount <= 0) {
        return '';
    }

    $text = wp_strip_all_tags(wc_price($amount));
    $charset = get_bloginfo('charset') ?: 'UTF-8';

    return html_entity_decode($text, ENT_QUOTES, $charset);
}

function tpc_compare_variation_label_short($variation, $parent_name)
{
    if (!$variation) {
        return 'Mặc định';
    }

    $variation_name = $variation->get_name();
    $short_name = str_replace($parent_name . ' - ', '', $variation_name);

    if ($short_name === $variation_name) {
        $short_name = wc_get_formatted_variation($variation, true);
    }

    return $short_name ?: 'Mặc định';
}

function tpc_compare_stock_display($wc_product)
{
    if (!$wc_product) {
        return '-';
    }

    if (method_exists($wc_product, 'is_in_stock') && !$wc_product->is_in_stock()) {
        return 'Hết hàng';
    }

    if (method_exists($wc_product, 'managing_stock') && $wc_product->managing_stock()) {
        $qty = $wc_product->get_stock_quantity();

        if ($qty === null || $qty === '') {
            return 'Còn hàng';
        }

        return (string) (int) $qty;
    }

    return 'Còn hàng';
}

function tpc_compare_get_price_groups($product, $product_title)
{
    if (!$product) {
        return [];
    }

    if ($product->is_type('variable')) {
        $groups = [];

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                continue;
            }

            if (method_exists($variation, 'get_status') && $variation->get_status() !== 'publish') {
                continue;
            }

            if (method_exists($variation, 'variation_is_visible') && !$variation->variation_is_visible()) {
                continue;
            }

            $regular_amount = $variation->get_regular_price();
            $current_amount = $variation->get_price();

            $regular_amount = ($regular_amount !== '' && $regular_amount !== null) ? (float) $regular_amount : (float) $current_amount;
            $current_amount = (float) $current_amount;

            $regular_key = wc_format_decimal($regular_amount, wc_get_price_decimals());
            $current_key = wc_format_decimal($current_amount, wc_get_price_decimals());
            $group_key = $regular_key . '|' . $current_key . '|' . ($variation->is_on_sale() ? '1' : '0');

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'regular_amount' => $regular_amount,
                    'current_amount' => $current_amount,
                    'on_sale'        => (bool) $variation->is_on_sale(),
                    'labels'         => [],
                ];
            }

            $groups[$group_key]['labels'][] = tpc_compare_variation_label_short($variation, $product_title);
        }

        if (empty($groups)) {
            return [];
        }

        uasort($groups, static function ($left, $right) {
            if ($left['regular_amount'] === $right['regular_amount']) {
                return $left['current_amount'] <=> $right['current_amount'];
            }

            return $left['regular_amount'] <=> $right['regular_amount'];
        });

        $rows = [];

        foreach ($groups as $group) {
            $labels = array_values(array_filter(array_map('trim', $group['labels'])));
            $labels = array_values(array_unique($labels));
            sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

            $regular_text = tpc_compare_format_price_text($group['regular_amount']);
            $current_text = tpc_compare_format_price_text($group['current_amount']);

            if ($group['on_sale'] && $regular_text && $current_text && $regular_text !== $current_text) {
                $price_text = $regular_text . ' sale còn ' . $current_text;
            } else {
                $price_text = $current_text ?: $regular_text;
            }

            if (!$price_text) {
                continue;
            }

            $rows[] = [
                'price'  => $price_text,
                'labels' => $labels,
            ];
        }

        return $rows;
    }

    $current_amount = (float) $product->get_price();
    $current_text = tpc_compare_format_price_text($current_amount);

    if ($product->is_on_sale()) {
        $regular_amount = $product->get_regular_price();
        $regular_amount = ($regular_amount !== '' && $regular_amount !== null) ? (float) $regular_amount : $current_amount;
        $regular_text = tpc_compare_format_price_text($regular_amount);

        if ($regular_text && $current_text && $regular_text !== $current_text) {
            return [
                [
                    'price'  => $regular_text . ' sale còn ' . $current_text,
                    'labels' => [],
                ],
            ];
        }
    }

    if (!$current_text) {
        return [];
    }

    return [
        [
            'price'  => $current_text,
            'labels' => [],
        ],
    ];
}

function tpc_compare_get_variants($product, $product_id, $product_title)
{
    if (!$product) {
        return [];
    }

    $variants = [];

    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);

            if (!$variation) {
                continue;
            }

            if (method_exists($variation, 'get_status') && $variation->get_status() !== 'publish') {
                continue;
            }

            if (method_exists($variation, 'variation_is_visible') && !$variation->variation_is_visible()) {
                continue;
            }

            $expiry = get_post_meta($variation_id, 'product_expiry_date_variation', true);

            $variants[] = [
                'name'   => tpc_compare_variation_label_short($variation, $product_title),
                'stock'  => tpc_compare_stock_display($variation),
                'expiry' => $expiry ?: '',
            ];
        }
    } else {
        $expiry = get_post_meta($product_id, 'product_expiry_date', true);

        $variants[] = [
            'name'   => 'Đơn thể',
            'stock'  => tpc_compare_stock_display($product),
            'expiry' => $expiry ?: '',
        ];
    }

    return $variants;
}

function tpc_compare_default_labels()
{
    return [
        'hdsd'                => 'Hướng dẫn sử dụng',
        'sku'                 => 'Mã sản phẩm',
        'categories'          => 'Danh mục',
        'tags'                => 'Thẻ sản phẩm',
        'weight'              => 'Khối lượng',
        'dimensions'          => 'Kích thước',
        'product_origin'      => 'Xuất xứ',
        'ingredients'         => 'Thành phần',
        'product_ingredients' => 'Thành phần',
        'uses'                => 'Công dụng',
        'usage'               => 'Cách dùng',
        'brand'               => 'Thương hiệu',
    ];
}

function tpc_compare_humanize_field_label($field_key)
{
    $default_labels = tpc_compare_default_labels();
    if (isset($default_labels[$field_key])) {
        return $default_labels[$field_key];
    }

    $label = str_replace(['_', '-'], ' ', $field_key);
    $label = preg_replace('/\s+/', ' ', (string) $label);
    $label = trim((string) $label);

    return $label ? ucwords($label) : 'Thông tin';
}

function tpc_compare_parse_fields($raw_fields)
{
    if (!is_string($raw_fields) || trim($raw_fields) === '') {
        return [];
    }

    $items = preg_split('/\s*,\s*/', $raw_fields);
    $fields = [];
    $skip_keys = ['short_desc', 'short_description'];

    foreach ($items as $item) {
        if ($item === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $item, 2));
        $field_key = sanitize_key($parts[0]);

        if ($field_key === '' || in_array($field_key, $skip_keys, true)) {
            continue;
        }

        $fields[] = [
            'key'   => $field_key,
            'label' => !empty($parts[1]) ? html_entity_decode(wp_strip_all_tags($parts[1]), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8') : tpc_compare_humanize_field_label($field_key),
        ];
    }

    return $fields;
}

function tpc_compare_parse_fields_from_json($raw_fields)
{
    if (!is_string($raw_fields) || trim($raw_fields) === '') {
        return [];
    }

    $decoded = json_decode(wp_unslash($raw_fields), true);
    if (!is_array($decoded)) {
        return [];
    }

    $fields = [];

    foreach ($decoded as $field) {
        if (!is_array($field)) {
            continue;
        }

        $field_key = isset($field['key']) ? sanitize_key($field['key']) : '';
        if ($field_key === '' || in_array($field_key, ['short_desc', 'short_description'], true)) {
            continue;
        }

        $field_label = isset($field['label']) ? html_entity_decode(wp_strip_all_tags($field['label']), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8') : tpc_compare_humanize_field_label($field_key);

        $fields[] = [
            'key'   => $field_key,
            'label' => $field_label ?: tpc_compare_humanize_field_label($field_key),
        ];
    }

    return $fields;
}

function tpc_compare_sanitize_utf8($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = tpc_compare_sanitize_utf8($item);
        }

        return $value;
    }

    if (is_string($value)) {
        return wp_check_invalid_utf8($value, true);
    }

    return $value;
}

function tpc_compare_json_encode_for_script($value, $fallback = '[]')
{
    $encoded = wp_json_encode(
        tpc_compare_sanitize_utf8($value),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return false === $encoded ? $fallback : $encoded;
}

function tpc_compare_format_field_value($value)
{
    if (is_array($value)) {
        $value = implode(', ', array_map('wp_strip_all_tags', $value));
    }

    if (is_object($value)) {
        $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $value = (string) $value;
    $value = trim($value);
    $value = do_shortcode($value);

    if ($value === '') {
        return '<span class="tpc-empty-value">-</span>';
    }

    if (strpos($value, '<') !== false) {
        return wpautop(wp_kses_post($value));
    }

    return wpautop(esc_html($value));
}

function tpc_compare_get_field_value($product, $product_id, $field_key)
{
    switch ($field_key) {
        case 'sku':
            return tpc_compare_format_field_value($product->get_sku());

        case 'categories':
            return tpc_compare_format_field_value(strip_tags(wc_get_product_category_list($product_id, ', ')));

        case 'tags':
            return tpc_compare_format_field_value(strip_tags(wc_get_product_tag_list($product_id, ', ')));

        case 'weight':
            return tpc_compare_format_field_value($product->get_weight());

        case 'dimensions':
            return tpc_compare_format_field_value(wc_format_dimensions($product->get_dimensions(false)));
    }

    $meta_value = get_post_meta($product_id, $field_key, true);

    // Metabox lưu với prefix "product_info_" (hdsd → product_info_hdsd, faq → product_info_faq...).
    if (($meta_value === '' || $meta_value === null) && strpos($field_key, 'product_info_') !== 0) {
        $meta_value = get_post_meta($product_id, 'product_info_' . $field_key, true);
    }

    return tpc_compare_format_field_value($meta_value);
}

function tpc_compare_build_product_payload($product_id, array $fields)
{
    $product_id = absint($product_id);
    if (!$product_id) {
        return null;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return null;
    }

    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'product') {
        return null;
    }

    $status = get_post_status($product_id);
    if ($status !== 'publish' && !current_user_can('edit_post', $product_id)) {
        return null;
    }

    $field_values = [];

    foreach ($fields as $field) {
        $field_values[$field['key']] = tpc_compare_get_field_value($product, $product_id, $field['key']);
    }

    $short_description = $product->get_short_description();
    $short_description = $short_description !== ''
        ? apply_filters('the_content', do_shortcode($short_description))
        : '<span class="tpc-empty-value">-</span>';

    $product_permalink = get_permalink($product_id);
    $cart_config = [
        'url'        => esc_url_raw($product_permalink),
        'label'      => 'Xem sản phẩm',
        'icon'       => 'eye',
        'classes'    => 'button button-primary',
        'target'     => '_blank',
        'rel'        => 'noopener',
        'product_id' => 0,
        'sku'        => '',
        'ajax'       => false,
    ];

    if ($product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock()) {
        $cart_config = [
            'url'        => esc_url_raw($product->add_to_cart_url()),
            'label'      => 'Xem thêm / Mua',
            'icon'       => 'cart',
            'classes'    => 'button button-primary add_to_cart_button ajax_add_to_cart product_type_simple',
            'target'     => '',
            'rel'        => '',
            'product_id' => $product_id,
            'sku'        => (string) $product->get_sku(),
            'ajax'       => true,
        ];
    } elseif ($product->is_type('variable')) {
        $cart_config['label'] = 'Xem thêm / Mua';
        $cart_config['icon'] = 'cart';
    }

    return tpc_compare_sanitize_utf8([
        'id'                => $product_id,
        'title'             => html_entity_decode(get_the_title($product_id), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'),
        'image'             => get_the_post_thumbnail_url($product_id, 'medium') ?: '',
        'permalink'         => esc_url_raw($product_permalink),
        'price_groups'      => tpc_compare_get_price_groups($product, get_the_title($product_id)),
        'variants'          => tpc_compare_get_variants($product, $product_id, get_the_title($product_id)),
        'short_description' => wp_kses_post($short_description),
        'fields'            => $field_values,
        // Ảnh của nút "Xem bảng dinh dưỡng" — hiện trong dòng Thành phần & dinh dưỡng.
        'nutrition_labels'  => function_exists('hithean_product_nutrition_label_items') ? hithean_product_nutrition_label_items($product_id) : [],
        'cart'              => $cart_config,
    ]);
}

/**
 * Đổi 1 token (ID hoặc SKU) thành ID sản phẩm cha. Trả về 0 nếu không khớp.
 * Token toàn số được ưu tiên hiểu là ID; nếu không phải ID sản phẩm thì thử như SKU.
 * SKU/ID của biến thể được quy về sản phẩm cha.
 */
function tpc_compare_resolve_product_token($token)
{
    $token = trim((string) $token);
    if ($token === '') {
        return 0;
    }

    $product_id = 0;

    if (ctype_digit($token)) {
        $post_type = get_post_type((int) $token);
        if ($post_type === 'product' || $post_type === 'product_variation') {
            $product_id = (int) $token;
        }
    }

    if (!$product_id && function_exists('wc_get_product_id_by_sku')) {
        $product_id = (int) wc_get_product_id_by_sku($token);
    }

    if ($product_id && get_post_type($product_id) === 'product_variation') {
        $product_id = (int) wp_get_post_parent_id($product_id);
    }

    return $product_id;
}

/**
 * Nhận danh sách ID và/hoặc SKU (ngăn cách bởi dấu phẩy, khoảng trắng hoặc "-")
 * và trả về mảng ID sản phẩm. Vì SKU có thể chứa "-", mỗi đoạn được thử khớp
 * nguyên khối trước, sau đó ghép tham lam các phần dài nhất có thể thành SKU.
 * Ví dụ: "123-WPI-1KG-456" → [123, <ID của SKU WPI-1KG>, 456].
 */
function tpc_compare_normalize_product_ids($raw_ids)
{
    if (is_array($raw_ids)) {
        $raw_ids = implode(',', $raw_ids);
    }

    if (!is_string($raw_ids) || trim($raw_ids) === '') {
        return [];
    }

    $ids = [];
    $chunks = array_filter(preg_split('/[\s,]+/', sanitize_text_field($raw_ids)), 'strlen');

    foreach ($chunks as $chunk) {
        $chunk_id = tpc_compare_resolve_product_token($chunk);
        if ($chunk_id) {
            $ids[] = $chunk_id;
            continue;
        }

        $parts = array_slice(array_values(array_filter(explode('-', $chunk), 'strlen')), 0, 30);
        $count = count($parts);
        $index = 0;

        while ($index < $count) {
            $matched = false;

            for ($end = $count; $end > $index; $end--) {
                $part_id = tpc_compare_resolve_product_token(implode('-', array_slice($parts, $index, $end - $index)));
                if ($part_id) {
                    $ids[] = $part_id;
                    $index = $end;
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $index++;
            }
        }
    }

    return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

function tpc_compare_get_product_ids_from_query()
{
    $query_keys = [
        'compare_products',
        'product_compare',
        'products',
    ];

    foreach ($query_keys as $query_key) {
        if (!isset($_GET[$query_key])) {
            continue;
        }

        $raw_value = wp_unslash($_GET[$query_key]);
        $ids = tpc_compare_normalize_product_ids($raw_value);

        if (!empty($ids)) {
            return $ids;
        }
    }

    return [];
}

function tpc_compare_verify_ajax_request()
{
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';

    if (!wp_verify_nonce($nonce, tpc_compare_ajax_nonce())) {
        wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
    }
}

function tpc_compare_cache_group()
{
    return 'tpc_product_compare';
}

/**
 * ID danh mục kèm toàn bộ danh mục con (có cache).
 */
function tpc_compare_get_category_term_ids($category_id)
{
    $category_id = absint($category_id);
    if (!$category_id) {
        return [];
    }

    $cache_key = 'product_cat_descendants:' . $category_id;
    $term_ids = wp_cache_get($cache_key, tpc_compare_cache_group());
    if ($term_ids === false || !is_array($term_ids)) {
        $children = get_term_children($category_id, 'product_cat');
        $term_ids = array_merge([$category_id], is_wp_error($children) ? [] : $children);
        $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));
        wp_cache_set($cache_key, $term_ids, tpc_compare_cache_group(), HOUR_IN_SECONDS);
    }

    return $term_ids;
}

/**
 * Danh sách slug danh mục mặc định cho dropdown khi bấm vào ô tìm kiếm.
 */
function tpc_compare_default_browse_categories()
{
    return 'protein,sieu-thuc-pham';
}

function tpc_compare_parse_category_slugs($raw_slugs)
{
    if (is_array($raw_slugs)) {
        $raw_slugs = implode(',', $raw_slugs);
    }

    if (!is_string($raw_slugs) || trim($raw_slugs) === '') {
        return [];
    }

    $slugs = array_map('sanitize_title', preg_split('/\s*,\s*/', trim($raw_slugs)));

    return array_slice(array_values(array_unique(array_filter($slugs))), 0, 10);
}

/**
 * Sản phẩm (đã publish, có ảnh, có giá) theo từng nhóm danh mục, giữ thứ tự slug truyền vào.
 * Trong mỗi nhóm: bán chạy nhất lên trước (total_sales), cùng lượt bán thì theo tên A→Z.
 */
function tpc_compare_get_category_groups(array $slugs)
{
    global $wpdb;

    $cache_key = 'browse:v2:' . md5(wp_json_encode($slugs));
    $cached_groups = wp_cache_get($cache_key, tpc_compare_cache_group());
    if ($cached_groups !== false && is_array($cached_groups)) {
        return $cached_groups;
    }

    $per_category = max(1, (int) apply_filters('tpc_compare_browse_per_category', 60));
    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
    $charset = get_bloginfo('charset') ?: 'UTF-8';
    $groups = [];

    foreach ($slugs as $slug) {
        $category = get_term_by('slug', $slug, 'product_cat');
        if (!$category || is_wp_error($category)) {
            continue;
        }

        $term_ids = tpc_compare_get_category_term_ids($category->term_id);
        if (empty($term_ids)) {
            continue;
        }

        $category_placeholders = implode(', ', array_fill(0, count($term_ids), '%d'));
        $sql = "
            SELECT DISTINCT p.ID, p.post_title, thumb_meta.meta_value AS thumbnail_id, product_lookup.total_sales
            FROM {$wpdb->posts} AS p
            INNER JOIN {$wpdb->postmeta} AS thumb_meta
                ON thumb_meta.post_id = p.ID
               AND thumb_meta.meta_key = '_thumbnail_id'
               AND thumb_meta.meta_value <> ''
            INNER JOIN {$lookup_table} AS product_lookup
                ON product_lookup.product_id = p.ID
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND product_lookup.min_price IS NOT NULL
              AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->term_relationships} AS category_relationships
                    INNER JOIN {$wpdb->term_taxonomy} AS category_taxonomy
                        ON category_taxonomy.term_taxonomy_id = category_relationships.term_taxonomy_id
                    WHERE category_relationships.object_id = p.ID
                      AND category_taxonomy.taxonomy = 'product_cat'
                      AND category_taxonomy.term_id IN ({$category_placeholders})
              )
            ORDER BY product_lookup.total_sales DESC, p.post_title ASC
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($term_ids, [$per_category])));
        $items = [];

        foreach ($rows as $row) {
            $thumb = !empty($row->thumbnail_id) ? wp_get_attachment_image_url((int) $row->thumbnail_id, 'thumbnail') : '';
            if (!$thumb) {
                continue;
            }

            $items[] = [
                'id'    => (int) $row->ID,
                'label' => html_entity_decode($row->post_title, ENT_QUOTES, $charset),
                'thumb' => $thumb,
            ];
        }

        if (empty($items)) {
            continue;
        }

        $groups[] = [
            'slug'  => $category->slug,
            'name'  => html_entity_decode($category->name, ENT_QUOTES, $charset),
            'items' => $items,
        ];
    }

    $groups = tpc_compare_sanitize_utf8($groups);
    wp_cache_set($cache_key, $groups, tpc_compare_cache_group(), 10 * MINUTE_IN_SECONDS);

    return $groups;
}

function tpc_compare_browse_products_ajax()
{
    tpc_compare_verify_ajax_request();

    $slugs = tpc_compare_parse_category_slugs(isset($_REQUEST['cats']) ? wp_unslash($_REQUEST['cats']) : '');
    if (empty($slugs)) {
        $slugs = tpc_compare_parse_category_slugs(tpc_compare_default_browse_categories());
    }

    wp_send_json(tpc_compare_get_category_groups($slugs));
}
add_action('wp_ajax_tpc_product_compare_browse', 'tpc_compare_browse_products_ajax');
add_action('wp_ajax_nopriv_tpc_product_compare_browse', 'tpc_compare_browse_products_ajax');

function tpc_compare_search_products_ajax()
{
    global $wpdb;

    tpc_compare_verify_ajax_request();

    $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
    if ($term === '' || mb_strlen($term) < 2) {
        wp_send_json([]);
    }

    $exclude_ids = isset($_REQUEST['exclude_ids']) ? wp_unslash($_REQUEST['exclude_ids']) : '';
    if (is_array($exclude_ids)) {
        $exclude_ids = implode(',', $exclude_ids);
    }
    $exclude_ids = is_string($exclude_ids) ? $exclude_ids : '';
    $exclude_ids = array_values(array_unique(array_filter(array_map('absint', preg_split('/\s*,\s*/', $exclude_ids)))));
    $category_id = isset($_REQUEST['category_id']) ? absint($_REQUEST['category_id']) : 0;
    $cache_key = 'search:' . md5(wp_json_encode([
        'term'        => $term,
        'exclude_ids' => $exclude_ids,
        'category_id' => $category_id,
    ]));
    $cached_results = wp_cache_get($cache_key, tpc_compare_cache_group());

    if ($cached_results !== false && is_array($cached_results)) {
        wp_send_json($cached_results);
    }

    $like = '%' . $wpdb->esc_like($term) . '%';
    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
    $status_sql = "AND p.post_status = 'publish'";
    $exclude_sql = '';
    $category_sql = '';
    $prepare_args = [$like];

    if (!empty($exclude_ids)) {
        $exclude_placeholders = implode(', ', array_fill(0, count($exclude_ids), '%d'));
        $exclude_sql = "AND p.ID NOT IN ({$exclude_placeholders})";
        $prepare_args = array_merge($prepare_args, $exclude_ids);
    }

    if ($category_id > 0) {
        $category_term = get_term($category_id, 'product_cat');
        if (!$category_term || is_wp_error($category_term)) {
            wp_send_json([]);
        }

        $term_ids = tpc_compare_get_category_term_ids($category_id);

        if (empty($term_ids)) {
            wp_send_json([]);
        }

        $category_placeholders = implode(', ', array_fill(0, count($term_ids), '%d'));
        $prepare_args = array_merge($prepare_args, $term_ids);

        $category_sql = "
          AND EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} AS category_relationships
                INNER JOIN {$wpdb->term_taxonomy} AS category_taxonomy
                    ON category_taxonomy.term_taxonomy_id = category_relationships.term_taxonomy_id
                WHERE category_relationships.object_id = p.ID
                  AND category_taxonomy.taxonomy = 'product_cat'
                  AND category_taxonomy.term_id IN ({$category_placeholders})
          )
        ";
    }

    $sql = "
        SELECT DISTINCT p.ID, p.post_title, thumb_meta.meta_value AS thumbnail_id
        FROM {$wpdb->posts} AS p
        INNER JOIN {$wpdb->postmeta} AS thumb_meta
            ON thumb_meta.post_id = p.ID
           AND thumb_meta.meta_key = '_thumbnail_id'
           AND thumb_meta.meta_value <> ''
        INNER JOIN {$lookup_table} AS product_lookup
            ON product_lookup.product_id = p.ID
        WHERE p.post_type = 'product'
          AND p.post_title LIKE %s
          {$status_sql}
          AND product_lookup.min_price IS NOT NULL
          {$category_sql}
          {$exclude_sql}
        ORDER BY p.post_title ASC
        LIMIT 15
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_args));
    $results = [];

    foreach ($rows as $row) {
        $thumb = !empty($row->thumbnail_id) ? wp_get_attachment_image_url((int) $row->thumbnail_id, 'thumbnail') : '';
        if (!$thumb) {
            continue;
        }

        $results[] = [
            'id'    => (int) $row->ID,
            'label' => html_entity_decode($row->post_title, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'),
            'thumb' => $thumb,
        ];
    }

    wp_cache_set($cache_key, $results, tpc_compare_cache_group(), 5 * MINUTE_IN_SECONDS);
    wp_send_json($results);
}
add_action('wp_ajax_tpc_product_compare_search', 'tpc_compare_search_products_ajax');
add_action('wp_ajax_nopriv_tpc_product_compare_search', 'tpc_compare_search_products_ajax');

function tpc_compare_get_product_ajax()
{
    tpc_compare_verify_ajax_request();

    $product_id = isset($_REQUEST['product_id']) ? absint($_REQUEST['product_id']) : 0;
    $fields = isset($_REQUEST['fields']) ? tpc_compare_parse_fields_from_json((string) $_REQUEST['fields']) : [];
    $payload = tpc_compare_build_product_payload($product_id, $fields);

    if (!$payload) {
        wp_send_json_error(['message' => 'Không tìm thấy sản phẩm.'], 404);
    }

    wp_send_json_success($payload);
}
add_action('wp_ajax_tpc_product_compare_get_product', 'tpc_compare_get_product_ajax');
add_action('wp_ajax_nopriv_tpc_product_compare_get_product', 'tpc_compare_get_product_ajax');

function tpc_product_compare_shortcode($atts)
{
    $atts = shortcode_atts([
        'products' => '',
        'number'   => 3,
        'fields'   => '',
        'cat'      => tpc_compare_default_browse_categories(),
    ], $atts, 'product_compare');

    $browse_categories = tpc_compare_parse_category_slugs($atts['cat']);
    if (empty($browse_categories)) {
        $browse_categories = tpc_compare_parse_category_slugs(tpc_compare_default_browse_categories());
    }

    $number = absint($atts['number']);
    if ($number < 2) {
        $number = 2;
    }
    if ($number > 5) {
        $number = 5;
    }

    $field_definitions = tpc_compare_parse_fields($atts['fields']);
    $product_ids = tpc_compare_normalize_product_ids($atts['products']);

    $query_product_ids = tpc_compare_get_product_ids_from_query();
    if (!empty($query_product_ids)) {
        $product_ids = $query_product_ids;
    }

    $product_ids = array_slice($product_ids, 0, $number);

    $products = [];
    foreach ($product_ids as $product_id) {
        $payload = tpc_compare_build_product_payload($product_id, $field_definitions);
        if ($payload) {
            $products[] = $payload;
        }
    }

    $instance_id = 'tpc-compare-' . wp_unique_id();
    $has_initial_products = !empty($products);
    $compare_page_url = home_url('/so-sanh/');
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    $can_manage_ai = tpc_compare_ai_can_manage() && theme_ai_feature_enabled('product_compare_ai');

    ob_start();
?>
    <div id="<?php echo esc_attr($instance_id); ?>" class="tpc-compare-root">
        <div class="tpc-header">
            <h2 class="tpc-title">So sánh sản phẩm</h2>
            <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-copy-link-button" hidden><?php echo tpc_compare_button_inner('link', 'Copy link xem bảng'); ?></button>
            <span class="tpc-copy-feedback" aria-live="polite" hidden>Đã copy link</span>
        </div>

        <div class="tpc-picker-panel">
            <div class="tpc-picker-wrap">
                <div class="tpc-picker-label">Chọn sản phẩm so sánh</div>
                <div class="tpc-selected-products"></div>
                <button type="button" class="tpc-picker-trigger" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($instance_id); ?>-picker">
                    <?php echo tpc_compare_icon_svg('search'); ?>
                    <span class="tpc-picker-trigger__text"><?php echo esc_html(sprintf('Tìm và chọn sản phẩm (tối đa %d)...', $number)); ?></span>
                </button>
                <div class="tpc-picker-help"><?php echo esc_html(sprintf('Chọn tối đa %d sản phẩm.', $number)); ?></div>
            </div>
        </div>

        <div id="<?php echo esc_attr($instance_id); ?>-nutrition" class="tpc-nl" role="dialog" aria-modal="true" aria-label="Bảng dinh dưỡng" hidden>
            <div class="tpc-nl__backdrop" data-tpc-nl-close></div>
            <div class="tpc-nl__dialog" tabindex="-1">
                <button type="button" class="tpc-nl__close" aria-label="Đóng" data-tpc-nl-close><?php echo tpc_compare_icon_svg('close'); ?></button>
                <div class="tpc-nl__stage">
                    <button type="button" class="tpc-nl__nav tpc-nl__nav--prev" aria-label="Sản phẩm trước" data-tpc-nl-step="-1"><?php echo tpc_compare_icon_svg('prev'); ?></button>
                    <img class="tpc-nl__image" src="" alt="" decoding="async" draggable="false">
                    <button type="button" class="tpc-nl__nav tpc-nl__nav--next" aria-label="Sản phẩm kế" data-tpc-nl-step="1"><?php echo tpc_compare_icon_svg('next'); ?></button>
                </div>
                <div class="tpc-nl__caption">
                    <a class="tpc-nl__product" href="#" target="_blank" rel="noopener"></a>
                    <span class="tpc-nl__title"></span>
                    <span class="tpc-nl__counter" aria-live="polite"></span>
                </div>
            </div>
        </div>

        <div id="<?php echo esc_attr($instance_id); ?>-picker" class="tpc-picker-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($instance_id); ?>-picker-title" hidden>
            <div class="tpc-pm__backdrop" data-tpc-picker-close></div>
            <div class="tpc-pm__dialog">
                <div class="tpc-pm__head">
                    <h3 id="<?php echo esc_attr($instance_id); ?>-picker-title" class="tpc-pm__title">Chọn sản phẩm so sánh</h3>
                    <button type="button" class="tpc-pm__close" aria-label="Đóng" data-tpc-picker-close><?php echo tpc_compare_icon_svg('close'); ?></button>
                </div>
                <div class="tpc-pm__search">
                    <input type="search" class="tpc-pm__input" autocomplete="off" placeholder="Tìm theo tên sản phẩm..." aria-label="Tìm sản phẩm">
                </div>
                <div class="tpc-pm__chips" aria-live="polite"></div>
                <div class="tpc-pm__body"></div>
                <div class="tpc-pm__foot">
                    <span class="tpc-pm__count" aria-live="polite"></span>
                    <div class="tpc-pm__foot-actions">
                        <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-pm__done" data-tpc-picker-close><?php echo tpc_compare_button_inner('check', 'Xong'); ?></button>
                        <button type="button" class="button button--light-blue tpc-btn tpc-pm__build"><?php echo tpc_compare_button_inner('compare', 'So sánh ngay'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tpc-actions">
            <button type="button" class="button button--light-blue tpc-btn tpc-build-button"><?php echo tpc_compare_button_inner('table', 'Tạo bảng so sánh'); ?></button>
            <?php if ($can_manage_ai) : ?>
                <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-ai-generate-button" hidden><?php echo tpc_compare_button_inner('sparkle', 'So sánh bằng AI'); ?></button>
            <?php endif; ?>
            <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-reset-button" hidden><?php echo tpc_compare_button_inner('reset', 'Tìm lại'); ?></button>
        </div>

        <?php if ($can_manage_ai) : ?>
            <section class="tpc-ai-panel" hidden aria-live="polite"></section>
        <?php endif; ?>

        <div class="tpc-results">
            <section class="tpc-ai-public-wrap" aria-label="So sánh bằng AI" hidden></section>

            <div class="tpc-table-shell<?php echo $has_initial_products ? '' : ' tpc-table-shell--hidden'; ?>" <?php echo $has_initial_products ? '' : ' hidden'; ?>>
                <div class="tpc-table-scroll">
                    <table class="tpc-compare-table">
                        <tbody class="tpc-compare-body">
                            <tr>
                                <td colspan="<?php echo esc_attr($number); ?>" class="tpc-placeholder-cell">
                                    <?php echo $has_initial_products ? 'Đang tải dữ liệu so sánh...' : 'Chọn sản phẩm rồi bấm "Tạo bảng so sánh".'; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="tpc-table-actions" hidden>
            <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-copy-link-button" hidden><?php echo tpc_compare_button_inner('link', 'Copy link xem bảng'); ?></button>
            <button type="button" class="button button--dark-blue-reverse tpc-btn tpc-reset-button"><?php echo tpc_compare_button_inner('reset', 'Tìm lại'); ?></button>
        </div>
    </div>

    <style>
        #<?php echo esc_html($instance_id); ?> {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
            min-width: 0;
            max-width: 100%;
        }

        #<?php echo esc_html($instance_id); ?> [hidden] {
            display: none !important;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-header {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-title {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-copy-link-button {
            width: auto;
            max-width: max-content;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-copy-feedback {
            font-size: 13px;
            color: #0f5132;
            font-weight: 600;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-build-button {
            width: auto;
            max-width: max-content;
            align-self: flex-start;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-table-actions {
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-actions .tpc-build-button,
        #<?php echo esc_html($instance_id); ?> .tpc-actions .tpc-ai-generate-button,
        #<?php echo esc_html($instance_id); ?> .tpc-actions .tpc-reset-button,
        #<?php echo esc_html($instance_id); ?> .tpc-table-actions .tpc-copy-link-button,
        #<?php echo esc_html($instance_id); ?> .tpc-table-actions .tpc-reset-button {
            width: auto;
            max-width: max-content;
            flex: 0 0 auto;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel {
            border: 1px solid #cbd8ee;
            border-radius: 12px;
            background: #f7faff;
            padding: 16px;
            box-shadow: 0 1px 2px rgba(19, 50, 91, .06);
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel__title {
            margin: 0 0 6px;
            color: #13325b;
            font-size: 18px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel__help,
        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel__status,
        #<?php echo esc_html($instance_id); ?> .tpc-ai-timestamp {
            color: #56697f;
            font-size: 13px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel__grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 12px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel label {
            display: grid;
            gap: 6px;
            color: #13325b;
            font-weight: 600;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel select,
        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #b9cae5;
            border-radius: 8px;
            background: #fff;
            padding: 10px;
            font: inherit;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel textarea {
            min-height: 126px;
            resize: vertical;
            font-weight: 400;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-panel__actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-results {
            display: grid;
            gap: 14px;
            min-width: 0;
            /* Khối AI tải sau và chèn phía trên bảng: không neo cuộn vào bảng để AI hiện ngay đầu khung nhìn. */
            overflow-anchor: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public-wrap {
            min-width: 0;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 10px 24px rgba(29, 78, 216, 0.08);
            overflow: hidden;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 0;
            padding: 14px 18px;
            background: linear-gradient(135deg, #e8f0ff 0%, #dce8ff 100%);
            color: #13325b;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.25;
            text-align: center;
            text-transform: uppercase;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__title .tpc-icon {
            width: 22px;
            height: 22px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            padding: 18px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__block {
            min-width: 0;
            padding: 14px 16px;
            border-radius: 10px;
            background: #f5f8ff;
            overflow-wrap: anywhere;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__block h3 {
            margin: 0 0 8px;
            color: #13325b;
            font-size: 17px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__block p {
            margin: 0 0 8px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public__block > :last-child {
            margin-bottom: 0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-ai-public .tpc-ai-timestamp {
            grid-column: 1 / -1;
        }

        @media (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?> .tpc-ai-public {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 12px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-ai-public__title {
                font-size: 18px;
                padding: 12px 14px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-ai-public__block {
                padding: 12px;
            }
        }

        #<?php echo esc_html($instance_id); ?> .tpc-table-shell {
            border: 0;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-table-shell--hidden {
            display: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-panel {
            display: block;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-table {
            width: 100%;
            min-width: max(100%, calc(var(--tpc-active-cols, <?php echo (int) $number; ?>) * 260px));
            border-collapse: separate;
            border-spacing: 0 10px;
            table-layout: fixed;
            border: 0 !important;
            box-shadow: none !important;
        }

        #<?php echo esc_html($instance_id); ?>[data-tpc-active-cols="2"] .tpc-compare-table {
            min-width: 100%;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-table th,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table td {
            width: calc(100% / var(--tpc-active-cols, <?php echo (int) $number; ?>));
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-table,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table thead,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table tbody,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table tr,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table th,
        #<?php echo esc_html($instance_id); ?> .tpc-compare-table td {
            padding: 14px;
            border: 0 !important;
            outline: 0 !important;
            box-shadow: none !important;
            vertical-align: top;
            background: #fff;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-table thead th {
            background: #f6faf8;
            border-radius: 0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-row td {
            background: #fff;
            border-radius: 0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-summary-row td {
            position: sticky;
            top: 0;
            z-index: 8;
            background: #ffffff !important;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-body tr.tpc-section-row:nth-of-type(3n + 1) td {
            background: #ffffff;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-body tr.tpc-section-row:nth-of-type(3n + 2) td {
            background: #f5f8ff;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-compare-body tr.tpc-section-row:nth-of-type(3n) td {
            background: #eef3ff;
        }

        /* Tiêu đề mục là ô colspan phủ hết bảng; phần chữ (.tpc-section-inner) dính mép trái
           vùng cuộn và rộng đúng bằng vùng đang nhìn thấy (--tpc-scroll-w do JS đo) → luôn
           nằm giữa, lề hai bên đều nhau kể cả khi bảng cuộn ngang. */
        #<?php echo esc_html($instance_id); ?> .tpc-section-head th.tpc-section-title {
            display: table-cell;
            padding: 0;
            border-radius: 0;
            text-align: center;
            font-size: 30px;
            font-weight: 800;
            color: #13325b;
            background: linear-gradient(135deg, #e8f0ff 0%, #dce8ff 100%);
            box-shadow: 0 10px 24px rgba(29, 78, 216, 0.12);
        }

        /* left/width trừ 14px padding ngang của .tpc-compare-table để lề hai bên luôn 14px. */
        #<?php echo esc_html($instance_id); ?> .tpc-section-inner {
            position: sticky;
            left: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            width: calc(var(--tpc-scroll-w, 100%) - 28px);
            max-width: 100%;
            padding: 14px 18px;
            box-sizing: border-box;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-label,
        #<?php echo esc_html($instance_id); ?> .tpc-section-note {
            display: block;
            width: 100%;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-label {
            text-transform: uppercase;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-overview {
            display: grid;
            gap: 14px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-overview__part + .tpc-overview__part {
            padding-top: 12px;
            border-top: 1px dashed #cbd8ee;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-overview__label {
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
            color: #56697f;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-nutrition-labels {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-nutrition-label {
            margin: 0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-nutrition-label a {
            display: block;
            cursor: zoom-in;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-nutrition-label img {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 8px;
            border: 1px solid #dbe4f3;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-nutrition-label figcaption {
            margin-top: 4px;
            font-size: 12px;
            color: #56697f;
            text-align: center;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-faq-toggle {
            width: auto;
            max-width: max-content;
            margin: 6px 0 0;
            font-size: 14px;
            text-transform: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-faq-toggle .tpc-icon {
            transition: transform .2s ease;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-faq-toggle[aria-expanded="true"] .tpc-icon {
            transform: rotate(180deg);
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-note {
            display: none;
            font-size: 12px;
            font-style: italic;
            font-weight: 600;
            color: #687684;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-summary {
            display: grid;
            gap: 10px;
            align-content: start;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-summary-title {
            font-size: 17px;
            font-weight: 800;
            line-height: 1.35;
            color: #17324a;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-wrap {
            min-width: 220px;
            position: relative;
            display: grid;
            gap: 10px;
            max-width: 720px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-selected-products {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-selected-product {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 34px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef4ff;
            color: #17324a;
            font-size: 14px;
            line-height: 1.2;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-selected-product-remove {
            border: 0;
            background: transparent;
            color: #5f6b76;
            padding: 0;
            width: 18px;
            height: 18px;
            line-height: 18px;
            cursor: pointer;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-help {
            font-size: 13px;
            color: #687684;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-label {
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #5f6b76;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-btn,
        #<?php echo esc_html($instance_id); ?>-picker .tpc-btn,
        #<?php echo esc_html($instance_id); ?> .tpc-add-to-cart {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-icon,
        #<?php echo esc_html($instance_id); ?>-picker .tpc-icon {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-trigger .tpc-icon {
            color: #5f6b76;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-width: 180px;
            border: 1px solid #c8d1d6;
            border-radius: 8px;
            background: #fff;
            padding: 10px 12px;
            color: #687684;
            font: inherit;
            text-align: left;
            text-transform: none;
            letter-spacing: normal;
            cursor: pointer;
            box-shadow: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-picker-trigger:hover,
        #<?php echo esc_html($instance_id); ?> .tpc-picker-trigger:focus-visible {
            border-color: #1d4ed8;
            background: #fff;
            color: #17324a;
        }

        /* Popup xem ảnh bảng dinh dưỡng: vuốt / mũi tên để sang sản phẩm kế hoặc trước. */
        #<?php echo esc_html($instance_id); ?>-nutrition {
            position: fixed;
            inset: 0;
            z-index: 10060;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            color: #17324a;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition[hidden] {
            display: none !important;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, .75);
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__dialog {
            position: relative;
            display: flex;
            flex-direction: column;
            width: min(760px, 100%);
            max-height: calc(100vh - 32px);
            max-height: calc(100dvh - 32px);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .35);
            overflow: hidden;
            outline: 0;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__close {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: rgba(241, 245, 249, .92);
            color: #334155;
            cursor: pointer;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__stage {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1 1 auto;
            min-height: 0;
            padding: 16px 56px;
            background: #f5f8ff;
            touch-action: pan-y pinch-zoom;
            user-select: none;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__image {
            display: block;
            max-width: 100%;
            max-height: calc(100vh - 190px);
            max-height: calc(100dvh - 190px);
            height: auto;
            object-fit: contain;
            -webkit-user-drag: none;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav {
            position: absolute;
            top: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: #fff;
            color: #13325b;
            box-shadow: 0 4px 12px rgba(15, 23, 42, .18);
            transform: translateY(-50%);
            cursor: pointer;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav--prev {
            left: 8px;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav--next {
            right: 8px;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav:disabled {
            opacity: .3;
            cursor: default;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav,
        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__close {
            min-width: 0;
            line-height: 1;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav .tpc-icon,
        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__close .tpc-icon {
            width: 20px;
            height: 20px;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__caption {
            display: grid;
            gap: 2px;
            padding: 12px 16px 14px;
            text-align: center;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__product {
            font-size: 16px;
            font-weight: 800;
            color: #13325b;
            text-decoration: none;
        }

        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__title,
        #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__counter {
            font-size: 13px;
            color: #56697f;
        }

        @media (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?>-nutrition {
                padding: 0;
            }

            #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__dialog {
                width: 100%;
                height: 100%;
                max-height: none;
                border-radius: 0;
            }

            #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__stage {
                padding: 56px 8px 64px;
            }

            #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__image {
                max-height: calc(100dvh - 200px);
            }

            #<?php echo esc_html($instance_id); ?>-nutrition .tpc-nl__nav {
                top: auto;
                bottom: 12px;
                transform: none;
            }
        }

        #<?php echo esc_html($instance_id); ?>-picker {
            position: fixed;
            inset: 0;
            z-index: 10050;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            font-size: 15px;
            color: #17324a;
        }

        #<?php echo esc_html($instance_id); ?>-picker[hidden] {
            display: none !important;
        }

        #<?php echo esc_html($instance_id); ?>-picker *,
        #<?php echo esc_html($instance_id); ?>-picker *::before,
        #<?php echo esc_html($instance_id); ?>-picker *::after {
            box-sizing: border-box;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, .55);
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__dialog {
            position: relative;
            display: flex;
            flex-direction: column;
            width: min(980px, 100%);
            height: min(820px, calc(100vh - 32px));
            height: min(820px, calc(100dvh - 32px));
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
            overflow: hidden;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 20px 8px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__title {
            margin: 0;
            font-size: 20px;
            line-height: 1.3;
            color: #13325b;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__close {
            flex: 0 0 auto;
            width: 36px;
            height: 36px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: #f1f5f9;
            color: #334155;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__close .tpc-icon {
            width: 20px;
            height: 20px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__search {
            padding: 8px 20px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__input {
            width: 100%;
            border: 1px solid #c8d1d6;
            border-radius: 10px;
            background: #fff;
            padding: 12px 14px;
            font: inherit;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__input:focus {
            outline: 2px solid #bfd3fb;
            border-color: #1d4ed8;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 0 20px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chips:not(:empty) {
            padding-bottom: 8px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            max-width: 100%;
            padding: 5px 6px 5px 12px;
            border: 0;
            border-radius: 999px;
            background: #1d4ed8;
            color: #fff;
            font: inherit;
            font-size: 13px;
            line-height: 1.3;
            cursor: pointer;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chip-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chip-x {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .25);
            font-size: 14px;
            line-height: 1;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            padding: 4px 20px 16px;
            border-top: 1px solid #eef1f5;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__group + .tpc-pm__group {
            margin-top: 8px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__group-label {
            position: sticky;
            top: -4px;
            z-index: 1;
            margin: 0 -20px 10px;
            padding: 10px 20px;
            background: #eef4ff;
            color: #13325b;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__item {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            width: 100%;
            margin: 0;
            padding: 10px;
            border: 2px solid #e5e9f0;
            border-radius: 12px;
            background: #fff;
            color: #17324a;
            font: inherit;
            text-align: left;
            text-transform: none;
            letter-spacing: normal;
            cursor: pointer;
            box-shadow: none;
            transition: border-color .15s, box-shadow .15s;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__item:hover {
            border-color: #9db8f2;
            background: #fff;
            color: #17324a;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__item[aria-pressed="true"] {
            border-color: #1d4ed8;
            background: #f5f8ff;
            box-shadow: 0 0 0 3px rgba(29, 78, 216, .12);
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__item.is-disabled {
            opacity: .45;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__thumb {
            display: block;
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 8px;
            object-fit: cover;
            background: #f3f4f6;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__name {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.35;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__check {
            position: absolute;
            top: 16px;
            right: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            border: 2px solid #fff;
            border-radius: 50%;
            background: rgba(15, 23, 42, .25);
            color: transparent;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .2);
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__item[aria-pressed="true"] .tpc-pm__check {
            background: #1d4ed8;
            color: #fff;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__state {
            padding: 28px 0;
            color: #5f6b76;
            text-align: center;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 20px;
            border-top: 1px solid #eef1f5;
            background: #fafbfd;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__count {
            font-weight: 600;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__count.is-error {
            color: #b42318;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__foot-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__foot-actions .button {
            width: auto;
            margin: 0;
        }

        #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__build:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        @media (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?>-picker {
                padding: 0;
            }

            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__dialog {
                width: 100%;
                height: 100%;
                border-radius: 0;
            }

            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__head,
            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__search,
            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__chips,
            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__body,
            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__foot {
                padding-left: 14px;
                padding-right: 14px;
            }

            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__group-label {
                margin-left: -14px;
                margin-right: -14px;
                padding-left: 14px;
                padding-right: 14px;
            }

            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            #<?php echo esc_html($instance_id); ?>-picker .tpc-pm__foot {
                padding-bottom: max(12px, env(safe-area-inset-bottom));
            }
        }

        #<?php echo esc_html($instance_id); ?> .tpc-placeholder-cell {
            color: #5f6b76;
            font-style: italic;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-cell-list {
            margin: 0;
            padding-left: 18px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-cell-list li + li {
            margin-top: 8px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-price {
            font-weight: 700;
            color: #0f5132;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-image-link,
        #<?php echo esc_html($instance_id); ?> .tpc-product-image-link:hover {
            display: inline-block;
            text-decoration: none;
            box-shadow: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-title-link,
        #<?php echo esc_html($instance_id); ?> .tpc-product-title-link:hover {
            font-weight: 700;
            text-decoration: none;
            box-shadow: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-image {
            display: block;
            width: 100%;
            max-width: 160px;
            height: auto;
            border-radius: 8px;
            object-fit: contain;
            margin: 0 auto;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-variation-notes {
            display: block;
            margin-top: 4px;
            color: #52606d;
            font-size: 13px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-add-to-cart {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            text-decoration: none;
            white-space: nowrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-empty-value,
        #<?php echo esc_html($instance_id); ?> .tpc-empty-cell {
            color: #8a94a0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-cell-content > :last-child {
            margin-bottom: 0;
        }

        @media (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?> .tpc-title {
                font-size: 22px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-actions,
            #<?php echo esc_html($instance_id); ?> .tpc-table-actions {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-wrap: wrap;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-compare-table th,
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table td {
                padding: 10px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-picker-wrap {
                min-width: 160px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-product-image {
                max-width: 120px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-section-head th.tpc-section-title {
                font-size: 18px;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-section-inner {
                padding: 12px 14px;
            }

            /* Nút theme cha có min-width 150px — phá cột hẹp, lệch lề phải. */
            #<?php echo esc_html($instance_id); ?> .tpc-add-to-cart {
                width: 100%;
                min-width: 0 !important;
                max-width: 100%;
                height: auto !important;
                white-space: normal;
                text-align: center;
            }

            #<?php echo esc_html($instance_id); ?> .tpc-compare-table th,
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table td {
                overflow-wrap: anywhere;
            }
        }

        @media (min-width: 400px) and (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table {
                min-width: calc(var(--tpc-active-cols, <?php echo (int) $number; ?>) * 50%);
            }

            #<?php echo esc_html($instance_id); ?> .tpc-section-note {
                display: inline;
            }
        }

        @media (max-width: 399px) {
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table {
                min-width: calc(var(--tpc-active-cols, <?php echo (int) $number; ?>) * 72%);
            }

            #<?php echo esc_html($instance_id); ?> .tpc-section-note {
                display: inline;
            }
        }
    </style>

    <script>
        (function() {
            const root = document.getElementById(<?php echo wp_json_encode($instance_id); ?>);
            if (!root) {
                return;
            }

            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode(wp_create_nonce(tpc_compare_ajax_nonce())); ?>;
            const body = root.querySelector('.tpc-compare-body');
            const tableShell = root.querySelector('.tpc-table-shell');
            const aiPublicWrap = root.querySelector('.tpc-ai-public-wrap');
            const tableScroll = root.querySelector('.tpc-table-scroll');

            // Bề rộng vùng nhìn thấy của bảng → tiêu đề mục dính và căn giữa đúng vùng này.
            function syncScrollWidth() {
                if (tableScroll && tableScroll.clientWidth > 0) {
                    root.style.setProperty('--tpc-scroll-w', tableScroll.clientWidth + 'px');
                }
            }

            if (tableScroll && 'ResizeObserver' in window) {
                new ResizeObserver(syncScrollWidth).observe(tableScroll);
            } else {
                window.addEventListener('resize', syncScrollWidth);
            }
            const fieldDefinitions = <?php echo tpc_compare_json_encode_for_script($field_definitions); ?>;
            const comparePageUrl = <?php echo wp_json_encode($compare_page_url); ?>;
            const productMap = new Map();
            const initialProducts = <?php echo tpc_compare_json_encode_for_script($products); ?>;
            const buttons = Array.from(root.querySelectorAll('.tpc-build-button'));
            const aiGenerateButton = root.querySelector('.tpc-ai-generate-button');
            const aiPanel = root.querySelector('.tpc-ai-panel');
            const topResetButton = root.querySelector('.tpc-actions .tpc-reset-button');
            const copyButtons = Array.from(root.querySelectorAll('.tpc-copy-link-button'));
            const copyFeedback = root.querySelector('.tpc-copy-feedback');
            const tableActions = root.querySelector('.tpc-table-actions');
            const selectedProductsWrap = root.querySelector('.tpc-selected-products');
            const pickerTrigger = root.querySelector('.tpc-picker-trigger');
            const picker = root.querySelector('.tpc-picker-modal');
            const pickerInput = picker.querySelector('.tpc-pm__input');
            const pickerBody = picker.querySelector('.tpc-pm__body');
            const pickerChips = picker.querySelector('.tpc-pm__chips');
            const pickerCount = picker.querySelector('.tpc-pm__count');
            const pickerBuild = picker.querySelector('.tpc-pm__build');
            const maxProducts = <?php echo (int) $number; ?>;
            const icons = <?php echo tpc_compare_json_encode_for_script(array_combine(
                ['cart', 'eye', 'save', 'globe', 'sparkle', 'chevron'],
                array_map('tpc_compare_icon_svg', ['cart', 'eye', 'save', 'globe', 'sparkle', 'chevron'])
            ), '{}'); ?>;
            const browseCategories = <?php echo tpc_compare_json_encode_for_script($browse_categories); ?>;
            let browseGroups = null;
            let browseRequest = null;
            let searchRevision = 0;
            let selectedProducts = initialProducts.slice(0, maxProducts);
            let hasGeneratedTable = <?php echo $has_initial_products ? 'true' : 'false'; ?>;
            let tableRevision = 0;
            let aiVersions = [];
            let activeAiVersionId = 0;
            let aiBusy = false;
            // Dòng FAQ dài nên ẩn mặc định, khách bấm "Xem thêm" ở tiêu đề mục để mở.
            let faqOpen = false;

            initialProducts.forEach(function(product) {
                productMap.set(String(product.id), product);
            });

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function decodeHtmlEntities(value) {
                const text = document.createElement('textarea');
                text.innerHTML = value == null ? '' : String(value);
                return text.value;
            }

            function serialize(params) {
                const formData = new URLSearchParams();
                Object.keys(params).forEach(function(key) {
                    formData.append(key, params[key]);
                });
                return formData.toString();
            }

            function postAjax(params) {
                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: serialize(params)
                }).then(function(response) {
                    return response.json();
                });
            }

            function selectedIds() {
                return selectedProducts.map(function(product) {
                    return product && product.id ? String(product.id) : '';
                }).filter(function(id) {
                    return !!id;
                });
            }

            function renderSelectedProducts() {
                if (!selectedProductsWrap) {
                    return;
                }

                syncPicker();

                if (!selectedProducts.length) {
                    selectedProductsWrap.innerHTML = '';
                    return;
                }

                selectedProductsWrap.innerHTML = selectedProducts.map(function(product) {
                    return '<span class="tpc-selected-product" data-product-id="' + escapeHtml(product.id) + '">' +
                        '<span class="tpc-selected-product-label">' + escapeHtml(product.title || product.label || ('#' + product.id)) + '</span>' +
                        '<button type="button" class="tpc-selected-product-remove" aria-label="Bỏ sản phẩm" data-product-id="' + escapeHtml(product.id) + '">&times;</button>' +
                        '</span>';
                }).join('');
            }

            // Cho phép UI bên ngoài (vd: modal so sánh ở trang danh mục) biết danh sách đang chọn.
            function notifySelectionChange() {
                root.dispatchEvent(new CustomEvent('tpc:selection-change', {
                    detail: { ids: selectedIds(), max: maxProducts }
                }));
            }

            function addSelectedProduct(productId, productLabel) {
                productId = String(productId || '').trim();
                if (!productId || selectedIds().includes(productId)) {
                    return false;
                }

                if (selectedProducts.length >= maxProducts) {
                    flashCopyFeedback('Đã đạt số sản phẩm tối đa', true);
                    flashPickerCount('Đã đủ ' + maxProducts + ' sản phẩm — bỏ bớt để chọn sản phẩm khác.');
                    return false;
                }

                selectedProducts.push({
                    id: productId,
                    title: productLabel,
                    label: productLabel,
                });
                renderSelectedProducts();
                notifySelectionChange();
                return true;
            }

            function removeSelectedProduct(productId) {
                productId = String(productId || '').trim();
                selectedProducts = selectedProducts.filter(function(product) {
                    return String(product.id) !== productId;
                });
                renderSelectedProducts();
                notifySelectionChange();
            }

            root.addEventListener('tpc:add-product', function(event) {
                const detail = event.detail || {};
                addSelectedProduct(detail.id, detail.label || '');
            });

            root.addEventListener('tpc:remove-product', function(event) {
                removeSelectedProduct((event.detail || {}).id);
            });

            root.addEventListener('tpc:build', function() {
                buildTable();
            });

            function getCompareShareUrl() {
                const ids = selectedIds().filter(function(id) {
                    return !!id;
                });

                if (!ids.length) {
                    return comparePageUrl;
                }

                const url = new URL(comparePageUrl, window.location.origin);
                url.searchParams.set('compare_products', ids.join('-'));
                return url.toString();
            }

            function flashCopyFeedback(text, isError) {
                if (!copyFeedback) {
                    return;
                }

                copyFeedback.textContent = text;
                copyFeedback.hidden = false;
                copyFeedback.style.color = isError ? '#b42318' : '#0f5132';

                window.clearTimeout(flashCopyFeedback._timer);
                flashCopyFeedback._timer = window.setTimeout(function() {
                    copyFeedback.hidden = true;
                }, 1800);
            }

            function setCopyButtonsVisible(isVisible) {
                copyButtons.forEach(function(button) {
                    button.hidden = !isVisible;
                });
            }

            function setTopResetVisible(isVisible) {
                if (topResetButton) {
                    topResetButton.hidden = !isVisible;
                }
            }

            function setTableActionsVisible(isVisible) {
                if (tableActions) {
                    tableActions.hidden = !isVisible;
                }
            }

            function iconButtonInner(icon, label) {
                return (icons[icon] || '') + '<span class="tpc-btn__label">' + escapeHtml(label) + '</span>';
            }

            function setButtonLabel(button, label) {
                const labelEl = button.querySelector('.tpc-btn__label');
                if (labelEl) {
                    labelEl.textContent = label;
                } else {
                    button.textContent = label;
                }
            }

            function syncBuildButtonLabel() {
                const label = hasGeneratedTable ? 'Tạo lại bảng so sánh' : 'Tạo bảng so sánh';
                buttons.forEach(function(button) {
                    setButtonLabel(button, label);
                });
            }

            function copyText(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }

                return new Promise(function(resolve, reject) {
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    textArea.setAttribute('readonly', 'readonly');
                    textArea.style.position = 'fixed';
                    textArea.style.opacity = '0';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();

                    try {
                        const success = document.execCommand('copy');
                        document.body.removeChild(textArea);
                        if (success) {
                            resolve();
                            return;
                        }
                    } catch (error) {}

                    document.body.removeChild(textArea);
                    reject(new Error('copy_failed'));
                });
            }

            function showPlaceholder(message) {
                renderAiPublicSection(null);
                setCopyButtonsVisible(false);
                setTopResetVisible(false);
                setTableActionsVisible(false);
                tableShell.hidden = false;
                tableShell.classList.remove('tpc-table-shell--hidden');
                const activeCols = Math.max(selectedIds().length, 1);
                root.style.setProperty('--tpc-active-cols', String(activeCols));
                root.setAttribute('data-tpc-active-cols', String(activeCols));
                body.innerHTML = '<tr><td colspan="' + activeCols + '" class="tpc-placeholder-cell">' + escapeHtml(message) + '</td></tr>';
            }

            function priceListHtml(product) {
                if (!product || !Array.isArray(product.price_groups) || product.price_groups.length === 0) {
                    return '';
                }

                const items = product.price_groups.map(function(group) {
                    let html = '<li><span class="tpc-price">' + escapeHtml(group.price || '-') + '</span>';

                    if (Array.isArray(group.labels) && group.labels.length) {
                        html += '<span class="tpc-variation-notes">(' + escapeHtml(group.labels.join(', ')) + ')</span>';
                    }

                    html += '</li>';
                    return html;
                }).join('');

                return '<ul class="tpc-cell-list">' + items + '</ul>';
            }

            function isHighlightField(field) {
                return field.key === 'subheading' || field.key === 'product_info_subheading';
            }

            // Ô "Tổng quan": giá bán, mô tả ngắn và điểm nhấn gộp chung một dòng; phần nào trống thì bỏ.
            function renderOverviewCell(product, highlightField) {
                if (!product) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                function contentHtml(html) {
                    return html ? '<div class="tpc-cell-content">' + html + '</div>' : '';
                }

                const highlightHtml = highlightField && product.fields ? product.fields[highlightField.key] : '';
                const parts = [
                    ['Giá bán', priceListHtml(product)],
                    ['Mô tả ngắn', contentHtml(product.short_description)],
                    [highlightField ? highlightField.label || 'Điểm nhấn' : '', contentHtml(highlightHtml)]
                ].filter(function(part) {
                    return part[0] && part[1];
                });

                if (!parts.length) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                return '<td><div class="tpc-overview">' + parts.map(function(part) {
                    return '<div class="tpc-overview__part"><div class="tpc-overview__label">' + escapeHtml(decodeHtmlEntities(part[0])) + '</div>' + part[1] + '</div>';
                }).join('') + '</div></td>';
            }

            function renderVariantCell(product) {
                if (!product || !Array.isArray(product.variants) || product.variants.length === 0) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                const items = product.variants.map(function(variant) {
                    let text = (variant.name || 'Mặc định') + ': ' + (variant.stock || '-');

                    if (variant.expiry) {
                        text += ' | HSD: ' + variant.expiry;
                    }

                    return '<li>' + escapeHtml(text) + '</li>';
                }).join('');

                return '<td><ul class="tpc-cell-list">' + items + '</ul></td>';
            }

            function renderHtmlCell(html) {
                if (!html) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                return '<td><div class="tpc-cell-content">' + html + '</div></td>';
            }

            function renderProductSummaryCell(product) {
                if (!product) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                const href = product.permalink || '#';
                const imageHtml = product.image
                    ? '<a class="tpc-product-image-link" href="' + escapeHtml(href) + '" target="_blank" rel="noopener"><img class="tpc-product-image" src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.title || '') + '"></a>'
                    : '';

                return '<td><div class="tpc-product-summary">' + imageHtml + '<a class="tpc-product-title-link tpc-product-summary-title" href="' + escapeHtml(href) + '" target="_blank" rel="noopener">' + escapeHtml(product.title || '-') + '</a></div></td>';
            }

            // Các trường này chỉ so sánh phần chữ: bỏ ảnh/media trong nội dung.
            const textOnlyFieldKeys = ['hdsd', 'product_info_hdsd', 'thanh_phan', 'product_info_thanh_phan'];
            const nutritionFieldKeys = ['thanh_phan', 'product_info_thanh_phan'];

            // Ảnh bảng dinh dưỡng của mọi cột, theo thứ tự cột — popup duyệt qua danh sách này.
            let nutritionSlides = [];

            function nutritionLabelsHtml(product) {
                if (!product || !Array.isArray(product.nutrition_labels) || !product.nutrition_labels.length) {
                    return '';
                }

                return '<div class="tpc-nutrition-labels">' + product.nutrition_labels.map(function(item) {
                    const title = escapeHtml(item.title || 'Bảng dinh dưỡng');
                    const index = nutritionSlides.push({
                        url: item.url,
                        title: item.title || 'Bảng dinh dưỡng',
                        productTitle: product.title || '',
                        productUrl: product.permalink || ''
                    }) - 1;
                    return '<figure class="tpc-nutrition-label"><a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener" data-tpc-nl-index="' + index + '"><img src="' + escapeHtml(item.url) + '" alt="' + title + '" loading="lazy" decoding="async"></a><figcaption>' + title + '</figcaption></figure>';
                }).join('') + '</div>';
            }

            function stripMedia(html) {
                const template = document.createElement('template');
                template.innerHTML = html;
                template.content.querySelectorAll('img, picture, figure, video, audio, iframe, svg, source').forEach(function(node) {
                    node.remove();
                });
                // Thẻ chỉ còn bọc ảnh (link, đoạn văn...) thì bỏ luôn để không để lại khoảng trống.
                Array.from(template.content.querySelectorAll('a, p, span, div, li')).reverse().forEach(function(node) {
                    if (!node.textContent.trim() && !node.querySelector('br, hr, input')) {
                        node.remove();
                    }
                });
                return template.innerHTML.trim();
            }

            function renderFieldCell(product, key) {
                const rawHtml = product && product.fields ? product.fields[key] || '' : '';
                const textHtml = textOnlyFieldKeys.indexOf(key) !== -1 ? stripMedia(rawHtml) : rawHtml;
                const labelsHtml = nutritionFieldKeys.indexOf(key) !== -1 ? nutritionLabelsHtml(product) : '';

                if (!labelsHtml) {
                    return renderHtmlCell(textHtml);
                }

                return '<td>' + (textHtml ? '<div class="tpc-cell-content">' + textHtml + '</div>' : '') + labelsHtml + '</td>';
            }

            function renderCartCell(product) {
                if (!product || !product.cart || !product.cart.url) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                const classes = product.cart.classes || 'button button-primary';
                const target = product.cart.target ? ' target="' + escapeHtml(product.cart.target) + '"' : '';
                const rel = product.cart.rel ? ' rel="' + escapeHtml(product.cart.rel) + '"' : '';
                const dataProductId = product.cart.product_id ? ' data-product_id="' + escapeHtml(product.cart.product_id) + '"' : '';
                const dataSku = product.cart.sku ? ' data-product_sku="' + escapeHtml(product.cart.sku) + '"' : '';
                const dataQuantity = product.cart.ajax ? ' data-quantity="1"' : '';

                return '<td><a class="tpc-add-to-cart ' + escapeHtml(classes) + '" href="' + escapeHtml(product.cart.url) + '"' + target + rel + dataProductId + dataSku + dataQuantity + '>' + iconButtonInner(product.cart.icon || 'cart', product.cart.label || 'Thêm vào giỏ hàng') + '</a></td>';
            }

            function isFaqField(field) {
                return field.key === 'faq' || field.key === 'product_info_faq';
            }

            function faqToggleLabel() {
                return faqOpen ? 'Thu gọn' : 'Xem thêm';
            }

            function renderSection(title, cellsHtml, collapsible) {
                const activeCols = Math.max(cellsHtml.length, 1);
                const noteHtml = activeCols > 2 ? '<span class="tpc-section-note">Kéo sang phải để xem từng sản phẩm.</span>' : '';
                const toggleHtml = collapsible ? '<button type="button" class="button button--dark-blue-reverse tpc-btn tpc-faq-toggle" aria-expanded="' + (faqOpen ? 'true' : 'false') + '">' + iconButtonInner('chevron', faqToggleLabel()) + '</button>' : '';
                const rowAttrs = collapsible ? ' tpc-faq-row"' + (faqOpen ? '' : ' hidden') : '"';
                return '<tr class="tpc-section-head"><th colspan="' + activeCols + '" class="tpc-section-title"><div class="tpc-section-inner"><span class="tpc-section-label">' + escapeHtml(decodeHtmlEntities(title)) + '</span>' + noteHtml + toggleHtml + '</div></th></tr>' +
                    '<tr class="tpc-section-row' + rowAttrs + '>' + cellsHtml.join('') + '</tr>';
            }

            function toggleFaq(button) {
                faqOpen = !faqOpen;
                body.querySelectorAll('.tpc-faq-row').forEach(function(row) {
                    row.hidden = !faqOpen;
                });
                button.setAttribute('aria-expanded', faqOpen ? 'true' : 'false');
                setButtonLabel(button, faqToggleLabel());
            }

            // Khối AI nằm ngoài bảng (bảng có min-width theo số cột → nội dung dài bị kéo ngang trên mobile).
            function renderAiPublicSection(version) {
                if (!aiPublicWrap) {
                    return false;
                }

                if (!version || !version.conclusion_html || !version.neutral_analysis_html) {
                    aiPublicWrap.hidden = true;
                    aiPublicWrap.innerHTML = '';
                    return false;
                }

                const timestamp = version.compared_label ? '<div class="tpc-ai-timestamp">So sánh lúc: ' + escapeHtml(version.compared_label) + '</div>' : '';
                aiPublicWrap.innerHTML = '<h2 class="tpc-ai-public__title">' + (icons.sparkle || '') + '<span>So sánh bằng AI</span></h2>' +
                    '<div class="tpc-ai-public">' +
                    '<div class="tpc-ai-public__block"><h3>Kết luận mua hàng</h3>' + version.conclusion_html + '</div>' +
                    '<div class="tpc-ai-public__block"><h3>Phân tích trung lập</h3>' + version.neutral_analysis_html + '</div>' + timestamp +
                    '</div>';
                aiPublicWrap.hidden = false;
                return true;
            }

            function renderRows(products, renderedColumnCount, publicAiVersion) {
                tableShell.hidden = false;
                tableShell.classList.remove('tpc-table-shell--hidden');
                setCopyButtonsVisible(true);
                setTopResetVisible(true);
                setTableActionsVisible(true);
                const activeCols = Math.max(Number(renderedColumnCount) || products.length || 1, 1);
                root.style.setProperty('--tpc-active-cols', String(activeCols));
                root.setAttribute('data-tpc-active-cols', String(activeCols));
                const rows = [];
                nutritionSlides = [];

                const hasAi = renderAiPublicSection(publicAiVersion);
                syncScrollWidth();
                rows.push('<tr class="tpc-section-row tpc-product-summary-row">' + products.map(renderProductSummaryCell).join('') + '</tr>');
                const highlightField = fieldDefinitions.find(isHighlightField) || null;
                rows.push(renderSection('Tổng quan', products.map(function(product) {
                    return renderOverviewCell(product, highlightField);
                })));

                fieldDefinitions.forEach(function(field) {
                    if (field === highlightField) {
                        return;
                    }

                    rows.push(renderSection(field.label || field.key, products.map(function(product) {
                        return renderFieldCell(product, field.key);
                    }), isFaqField(field)));
                });

                rows.push(renderSection('Mua sản phẩm', products.map(renderCartCell)));
                body.innerHTML = rows.join('');
                hasGeneratedTable = true;
                syncBuildButtonLabel();
                root.dispatchEvent(new CustomEvent('tpc:table-rendered', {
                    detail: { cols: activeCols, withAi: hasAi }
                }));
            }

            function fetchProductPayload(productId) {
                const key = String(productId);
                if (productMap.has(key)) {
                    return Promise.resolve(productMap.get(key));
                }

                return postAjax({
                    action: 'tpc_product_compare_get_product',
                    nonce: nonce,
                    product_id: key,
                    fields: JSON.stringify(fieldDefinitions)
                }).then(function(response) {
                    if (!response || !response.success || !response.data) {
                        return null;
                    }

                    productMap.set(key, response.data);
                    return response.data;
                }).catch(function() {
                    return null;
                });
            }

            function loadPublicAiVersion(ids) {
                return postAjax({
                    action: 'tpc_product_compare_ai_public',
                    nonce: nonce,
                    product_ids: ids.join(',')
                }).then(function(response) {
                    return response && response.success && response.data ? response.data.version : null;
                }).catch(function() {
                    return null;
                });
            }

            function setAiBusy(isBusy) {
                aiBusy = isBusy;
                if (aiGenerateButton) {
                    aiGenerateButton.disabled = isBusy;
                    setButtonLabel(aiGenerateButton, isBusy ? 'Đang xử lý AI...' : 'So sánh bằng AI');
                }
                if (aiPanel) {
                    aiPanel.querySelectorAll('button, select, textarea').forEach(function(control) {
                        control.disabled = isBusy;
                    });
                }
            }

            function getActiveAiVersion() {
                return aiVersions.find(function(version) {
                    return Number(version.id) === Number(activeAiVersionId);
                }) || aiVersions[0] || null;
            }

            function aiStatusLabel(status) {
                return status === 'published' ? 'Đang hiển thị' : (status === 'archived' ? 'Đã lưu lịch sử' : 'Nháp');
            }

            function renderAiPanel(message, isError) {
                if (!aiPanel) {
                    return;
                }

                aiPanel.hidden = false;
                const version = getActiveAiVersion();
                const status = message ? '<p class="tpc-ai-panel__status" style="color:' + (isError ? '#b42318' : '#0f5132') + '">' + escapeHtml(message) + '</p>' : '';
                if (!version) {
                    aiPanel.innerHTML = '<h3 class="tpc-ai-panel__title">So sánh bằng AI</h3><p class="tpc-ai-panel__help">Tạo bản nháp gồm Kết luận mua hàng và Phân tích trung lập. Chỉ bản bạn chọn công khai mới hiển thị cho khách.</p>' + status;
                    return;
                }

                const options = aiVersions.map(function(item) {
                    const label = '#' + item.id + ' — ' + aiStatusLabel(item.status) + (item.compared_label ? ' (' + item.compared_label + ')' : '');
                    return '<option value="' + escapeHtml(item.id) + '"' + (Number(item.id) === Number(activeAiVersionId) ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
                }).join('');
                aiPanel.innerHTML = '<h3 class="tpc-ai-panel__title">So sánh bằng AI</h3>' +
                    '<p class="tpc-ai-panel__help">Chọn phiên bản, hiệu đính nội dung rồi công khai khi sẵn sàng.</p>' + status +
                    '<div class="tpc-ai-panel__grid">' +
                    '<label>Phiên bản <select class="tpc-ai-version-select">' + options + '</select></label>' +
                    '<label>Kết luận mua hàng <textarea class="tpc-ai-conclusion" maxlength="6000">' + escapeHtml(version.conclusion || '') + '</textarea></label>' +
                    '<label>Phân tích trung lập <textarea class="tpc-ai-analysis" maxlength="6000">' + escapeHtml(version.neutral_analysis || '') + '</textarea></label>' +
                    '<div class="tpc-ai-panel__actions"><button type="button" class="button button--dark-blue-reverse tpc-btn" data-tpc-ai-action="save">' + iconButtonInner('save', 'Lưu bản nháp') + '</button><button type="button" class="button button--light-blue tpc-btn" data-tpc-ai-action="publish">' + iconButtonInner('globe', 'Công khai phiên bản này') + '</button></div>' +
                    '</div>';
                setAiBusy(aiBusy);
            }

            function loadAiVersions(preferredVersionId, message) {
                if (!aiPanel) {
                    return Promise.resolve();
                }
                const ids = selectedIds();
                if (ids.length < 2) {
                    aiVersions = [];
                    activeAiVersionId = 0;
                    renderAiPanel('Chọn ít nhất 2 sản phẩm trước khi dùng AI.', true);
                    return Promise.resolve();
                }
                return postAjax({
                    action: 'tpc_product_compare_ai_list',
                    nonce: nonce,
                    product_ids: ids.join(',')
                }).then(function(response) {
                    if (!response || !response.success) {
                        throw new Error((response && response.data && response.data.message) || 'Không tải được lịch sử AI.');
                    }
                    aiVersions = Array.isArray(response.data.versions) ? response.data.versions : [];
                    activeAiVersionId = Number(preferredVersionId) || Number((aiVersions[0] || {}).id || 0);
                    renderAiPanel(message || '', false);
                }).catch(function(error) {
                    renderAiPanel(error.message || 'Không tải được lịch sử AI.', true);
                });
            }

            function saveActiveAiVersion() {
                const version = getActiveAiVersion();
                if (!version || !aiPanel) {
                    return Promise.reject(new Error('Chưa chọn phiên bản AI.'));
                }
                const conclusion = aiPanel.querySelector('.tpc-ai-conclusion');
                const analysis = aiPanel.querySelector('.tpc-ai-analysis');
                setAiBusy(true);
                return postAjax({
                    action: 'tpc_product_compare_ai_save',
                    nonce: nonce,
                    version_id: version.id,
                    conclusion: conclusion ? conclusion.value : '',
                    neutral_analysis: analysis ? analysis.value : ''
                }).then(function(response) {
                    if (!response || !response.success || !response.data.version) {
                        throw new Error((response && response.data && response.data.message) || 'Không lưu được nội dung AI.');
                    }
                    return response.data.version;
                }).finally(function() {
                    setAiBusy(false);
                });
            }

            function refreshBuiltTable() {
                if (selectedIds().length >= 2) {
                    buildTable();
                }
            }

            function setButtonsDisabled(isDisabled) {
                buttons.forEach(function(button) {
                    button.disabled = isDisabled;
                });
            }

            function buildTable() {
                const ids = selectedIds();
                const activeIds = ids.filter(function(id) { return !!id; });
                if (activeIds.length < 2) {
                    flashCopyFeedback('Cần chọn ít nhất 2 sản phẩm để tạo bảng so sánh', true);
                    return;
                }

                setButtonsDisabled(true);
                showPlaceholder('Đang tạo bảng so sánh...');
                const revision = ++tableRevision;

                const sourceIds = activeIds;

                Promise.all(sourceIds.map(function(id) {
                    if (!id) return Promise.resolve(null);
                    return fetchProductPayload(id);
                })).then(function(products) {
                    products = products.filter(function(product) { return !!product; });
                    renderRows(products, sourceIds.length);
                    if (aiGenerateButton) {
                        aiGenerateButton.hidden = false;
                    }
                    if (aiPanel) {
                        loadAiVersions(0);
                    }
                    loadPublicAiVersion(sourceIds).then(function(version) {
                        if (revision === tableRevision) {
                            renderRows(products, sourceIds.length, version);
                        }
                    });
                }).catch(function() {
                    showPlaceholder('Không tải được dữ liệu sản phẩm.');
                }).finally(function() {
                    setButtonsDisabled(false);
                });
            }

            function resetCompareState() {
                tableRevision += 1;
                selectedProducts = [];
                renderSelectedProducts();
                notifySelectionChange();
                setCopyButtonsVisible(false);
                setTopResetVisible(false);
                setTableActionsVisible(false);
                hasGeneratedTable = false;
                syncBuildButtonLabel();
                tableShell.hidden = true;
                tableShell.classList.add('tpc-table-shell--hidden');
                renderAiPublicSection(null);
                root.setAttribute('data-tpc-active-cols', '0');
                body.innerHTML = '';
                if (aiGenerateButton) {
                    aiGenerateButton.hidden = true;
                }
                if (aiPanel) {
                    aiPanel.hidden = true;
                    aiPanel.innerHTML = '';
                }
                closePicker();
                pickerInput.value = '';
                if (pickerTrigger) {
                    pickerTrigger.focus();
                }
            }

            // ---------- Modal chọn sản phẩm (chọn nhiều + tìm kiếm ngay trong modal) ----------
            let pickerOpen = false;
            let pickerSearchTimer = 0;
            let pickerLastFocus = null;
            let pickerPrevOverflow = '';
            let pickerView = { type: 'browse', items: null };

            // Portal ra thẳng document.body (không ghi tên thẻ dạng HTML trong script inline: plugin tối ưu/cache có thể hiểu nhầm là thẻ thật): tổ tiên có transform/overflow (vd: modal footer trang danh mục) làm lệch position:fixed.
            document.body.appendChild(picker);

            function pickerItemHtml(item) {
                const id = String(item.id);
                const isSelected = selectedIds().includes(id);
                const isFull = !isSelected && selectedProducts.length >= maxProducts;
                const thumb = item.thumb ?
                    '<img class="tpc-pm__thumb" src="' + escapeHtml(item.thumb) + '" alt="" loading="lazy">' :
                    '<span class="tpc-pm__thumb"></span>';

                return '<button type="button" class="tpc-pm__item' + (isFull ? ' is-disabled' : '') + '" data-id="' + escapeHtml(id) + '" data-label="' + escapeHtml(item.label) + '" aria-pressed="' + (isSelected ? 'true' : 'false') + '">' +
                    thumb +
                    '<span class="tpc-pm__check" aria-hidden="true">&#10003;</span>' +
                    '<span class="tpc-pm__name">' + escapeHtml(item.label) + '</span>' +
                    '</button>';
            }

            function renderPickerBody() {
                if (pickerView.type === 'state') {
                    pickerBody.innerHTML = '<div class="tpc-pm__state">' + escapeHtml(pickerView.message) + '</div>';
                    return;
                }

                if (pickerView.type === 'search') {
                    const items = pickerView.items || [];
                    pickerBody.innerHTML = items.length ?
                        '<div class="tpc-pm__group"><div class="tpc-pm__group-label">Kết quả tìm kiếm (' + items.length + ')</div><div class="tpc-pm__grid">' + items.map(pickerItemHtml).join('') + '</div></div>' :
                        '<div class="tpc-pm__state">Không tìm thấy sản phẩm nào.</div>';
                } else {
                    const html = (pickerView.items || []).map(function(group) {
                        const items = Array.isArray(group.items) ? group.items : [];
                        if (!items.length) {
                            return '';
                        }
                        return '<div class="tpc-pm__group"><div class="tpc-pm__group-label">' + escapeHtml(group.name || group.slug) + '</div>' +
                            '<div class="tpc-pm__grid">' + items.map(pickerItemHtml).join('') + '</div></div>';
                    }).join('');
                    pickerBody.innerHTML = html || '<div class="tpc-pm__state">Không có sản phẩm gợi ý. Hãy gõ tên để tìm.</div>';
                }
            }

            function syncPicker() {
                if (!picker) {
                    return;
                }

                const count = selectedProducts.length;
                pickerCount.classList.remove('is-error');
                pickerCount.textContent = 'Đã chọn ' + count + '/' + maxProducts + (count < 2 ? ' — cần ít nhất 2' : '');
                pickerBuild.disabled = count < 2;
                pickerChips.innerHTML = selectedProducts.map(function(product) {
                    return '<button type="button" class="tpc-pm__chip" data-id="' + escapeHtml(product.id) + '" aria-label="Bỏ ' + escapeHtml(product.title || product.label || '') + '">' +
                        '<span class="tpc-pm__chip-label">' + escapeHtml(product.title || product.label || ('#' + product.id)) + '</span>' +
                        '<span class="tpc-pm__chip-x" aria-hidden="true">&times;</span></button>';
                }).join('');

                if (pickerOpen) {
                    updatePickerItems();
                }
            }

            // Cập nhật trạng thái chọn tại chỗ (không render lại) để giữ focus và vị trí cuộn.
            function updatePickerItems() {
                const ids = selectedIds();
                const isFull = ids.length >= maxProducts;
                pickerBody.querySelectorAll('.tpc-pm__item').forEach(function(item) {
                    const isSelected = ids.includes(String(item.getAttribute('data-id')));
                    item.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                    item.classList.toggle('is-disabled', isFull && !isSelected);
                });
            }

            function flashPickerCount(message) {
                if (!pickerOpen) {
                    return;
                }
                pickerCount.textContent = message;
                pickerCount.classList.add('is-error');
                window.clearTimeout(flashPickerCount._timer);
                flashPickerCount._timer = window.setTimeout(syncPicker, 2200);
            }

            function setPickerState(message) {
                pickerView = { type: 'state', message: message };
                renderPickerBody();
            }

            function loadBrowseGroups() {
                if (browseGroups) {
                    return Promise.resolve(browseGroups);
                }

                if (!browseRequest) {
                    browseRequest = postAjax({
                        action: 'tpc_product_compare_browse',
                        nonce: nonce,
                        cats: browseCategories.join(',')
                    }).then(function(response) {
                        browseGroups = Array.isArray(response) ? response : [];
                        return browseGroups;
                    }).finally(function() {
                        browseRequest = null;
                    });
                }

                return browseRequest;
            }

            // Ô trống → sản phẩm theo nhóm danh mục (thuộc tính "cat"); từ 2 ký tự → tìm theo tên.
            function runPickerSearch(term) {
                const revision = ++searchRevision;

                if (term.length < 2) {
                    if (!browseGroups) {
                        setPickerState('Đang tải...');
                    }
                    loadBrowseGroups().then(function(groups) {
                        if (revision === searchRevision) {
                            pickerView = { type: 'browse', items: groups };
                            renderPickerBody();
                        }
                    }).catch(function() {
                        if (revision === searchRevision) {
                            setPickerState('Không tải được danh sách sản phẩm.');
                        }
                    });
                    return;
                }

                setPickerState('Đang tìm...');
                postAjax({
                    action: 'tpc_product_compare_search',
                    nonce: nonce,
                    term: term,
                    exclude_ids: ''
                }).then(function(response) {
                    if (revision === searchRevision) {
                        pickerView = { type: 'search', items: Array.isArray(response) ? response : [] };
                        pickerBody.scrollTop = 0;
                        renderPickerBody();
                    }
                }).catch(function() {
                    if (revision === searchRevision) {
                        setPickerState('Không tải được kết quả.');
                    }
                });
            }

            const nutritionModal = root.querySelector('.tpc-nl');
            const nutritionDialog = nutritionModal.querySelector('.tpc-nl__dialog');
            const nutritionStage = nutritionModal.querySelector('.tpc-nl__stage');
            const nutritionImage = nutritionModal.querySelector('.tpc-nl__image');
            const nutritionProduct = nutritionModal.querySelector('.tpc-nl__product');
            const nutritionTitle = nutritionModal.querySelector('.tpc-nl__title');
            const nutritionCounter = nutritionModal.querySelector('.tpc-nl__counter');
            const nutritionPrev = nutritionModal.querySelector('.tpc-nl__nav--prev');
            const nutritionNext = nutritionModal.querySelector('.tpc-nl__nav--next');
            let nutritionIndex = -1;
            let nutritionLastFocus = null;
            let nutritionPrevOverflow = '';
            let nutritionSwipeStart = null;

            function showNutritionSlide(index) {
                const slide = nutritionSlides[index];
                if (!slide) {
                    return;
                }
                nutritionIndex = index;
                nutritionImage.src = slide.url;
                nutritionImage.alt = slide.title + (slide.productTitle ? ' - ' + slide.productTitle : '');
                nutritionProduct.textContent = slide.productTitle;
                nutritionProduct.href = slide.productUrl || '#';
                nutritionProduct.hidden = !slide.productTitle;
                nutritionTitle.textContent = slide.title;
                nutritionCounter.textContent = nutritionSlides.length > 1 ? (index + 1) + ' / ' + nutritionSlides.length : '';
                nutritionPrev.disabled = index <= 0;
                nutritionNext.disabled = index >= nutritionSlides.length - 1;
                nutritionPrev.hidden = nutritionNext.hidden = nutritionSlides.length < 2;
            }

            function stepNutrition(step) {
                showNutritionSlide(Math.min(Math.max(nutritionIndex + step, 0), nutritionSlides.length - 1));
            }

            function openNutrition(index) {
                if (!nutritionSlides[index]) {
                    return;
                }
                if (nutritionModal.hidden) {
                    nutritionLastFocus = document.activeElement;
                    nutritionPrevOverflow = document.documentElement.style.overflow;
                    document.documentElement.style.overflow = 'hidden';
                    nutritionModal.hidden = false;
                }
                showNutritionSlide(index);
                nutritionDialog.focus();
            }

            function closeNutrition() {
                if (nutritionModal.hidden) {
                    return;
                }
                nutritionModal.hidden = true;
                nutritionImage.removeAttribute('src');
                document.documentElement.style.overflow = nutritionPrevOverflow;
                if (nutritionLastFocus && typeof nutritionLastFocus.focus === 'function' && document.contains(nutritionLastFocus)) {
                    nutritionLastFocus.focus();
                }
            }

            nutritionModal.addEventListener('click', function(event) {
                if (event.target.closest('[data-tpc-nl-close]')) {
                    event.preventDefault();
                    closeNutrition();
                    return;
                }
                const stepButton = event.target.closest('[data-tpc-nl-step]');
                if (stepButton) {
                    event.preventDefault();
                    stepNutrition(Number(stepButton.getAttribute('data-tpc-nl-step')));
                }
            });

            // Vuốt ngang trên ảnh (cảm ứng hoặc kéo chuột) để sang sản phẩm kế / trước.
            nutritionStage.addEventListener('pointerdown', function(event) {
                if (event.target.closest('button')) {
                    return;
                }
                nutritionSwipeStart = { x: event.clientX, y: event.clientY };
            });
            nutritionStage.addEventListener('pointerup', function(event) {
                if (!nutritionSwipeStart) {
                    return;
                }
                const dx = event.clientX - nutritionSwipeStart.x;
                const dy = event.clientY - nutritionSwipeStart.y;
                nutritionSwipeStart = null;
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
                    stepNutrition(dx < 0 ? 1 : -1);
                }
            });
            nutritionStage.addEventListener('pointercancel', function() {
                nutritionSwipeStart = null;
            });

            // Pha capture như modal chọn SP: Esc chỉ đóng popup ảnh, không đóng modal so sánh bên ngoài.
            window.addEventListener('keydown', function(event) {
                if (nutritionModal.hidden) {
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    closeNutrition();
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    event.stopPropagation();
                    stepNutrition(event.key === 'ArrowRight' ? 1 : -1);
                } else if (event.key === 'Tab') {
                    const focusables = Array.from(nutritionModal.querySelectorAll('button:not([disabled]), a[href]')).filter(function(element) {
                        return element.offsetParent !== null;
                    });
                    if (!focusables.length) {
                        return;
                    }
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];
                    const active = document.activeElement;
                    if (!nutritionModal.contains(active) || active === nutritionDialog) {
                        event.preventDefault();
                        (event.shiftKey ? last : first).focus();
                    } else if (event.shiftKey && active === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && active === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            }, true);

            function openPicker() {
                if (pickerOpen) {
                    return;
                }
                pickerOpen = true;
                pickerLastFocus = document.activeElement;
                pickerPrevOverflow = document.documentElement.style.overflow;
                document.documentElement.style.overflow = 'hidden';
                picker.hidden = false;
                syncPicker();
                runPickerSearch(pickerInput.value.trim());
                window.setTimeout(function() {
                    pickerInput.focus();
                }, 30);
            }

            function closePicker() {
                if (!pickerOpen) {
                    return;
                }
                pickerOpen = false;
                picker.hidden = true;
                document.documentElement.style.overflow = pickerPrevOverflow;
                if (pickerLastFocus && typeof pickerLastFocus.focus === 'function' && document.contains(pickerLastFocus)) {
                    pickerLastFocus.focus();
                }
            }

            if (pickerTrigger) {
                pickerTrigger.addEventListener('click', function(event) {
                    event.preventDefault();
                    openPicker();
                });
            }

            pickerInput.addEventListener('input', function() {
                window.clearTimeout(pickerSearchTimer);
                pickerSearchTimer = window.setTimeout(function() {
                    runPickerSearch(pickerInput.value.trim());
                }, 250);
            });

            picker.addEventListener('click', function(event) {
                const item = event.target.closest('.tpc-pm__item');
                if (item) {
                    event.preventDefault();
                    const id = item.getAttribute('data-id');
                    if (item.getAttribute('aria-pressed') === 'true') {
                        removeSelectedProduct(id);
                    } else {
                        addSelectedProduct(id, item.getAttribute('data-label') || '');
                    }
                    return;
                }

                const chip = event.target.closest('.tpc-pm__chip');
                if (chip) {
                    event.preventDefault();
                    removeSelectedProduct(chip.getAttribute('data-id'));
                    return;
                }

                if (event.target.closest('.tpc-pm__build')) {
                    event.preventDefault();
                    closePicker();
                    buildTable();
                    return;
                }

                if (event.target.closest('[data-tpc-picker-close]')) {
                    event.preventDefault();
                    closePicker();
                }
            });

            // Bắt ở pha capture của window: Esc chỉ đóng modal chọn SP (dù focus đang ở đâu)
            // và không lan tới handler Esc của modal so sánh bên ngoài (trang danh mục).
            window.addEventListener('keydown', function(event) {
                if (!pickerOpen) {
                    return;
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    closePicker();
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                const focusables = Array.from(picker.querySelectorAll('button:not([disabled]), input')).filter(function(element) {
                    return element.offsetParent !== null;
                });
                if (!focusables.length) {
                    return;
                }
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                const active = document.activeElement;
                if (!picker.contains(active)) {
                    event.preventDefault();
                    (event.shiftKey ? last : first).focus();
                } else if (event.shiftKey && active === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && active === last) {
                    event.preventDefault();
                    first.focus();
                }
            }, true);

            root.addEventListener('click', function(event) {
                const nutritionLink = event.target.closest('[data-tpc-nl-index]');
                if (nutritionLink && root.contains(nutritionLink) && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
                    event.preventDefault();
                    openNutrition(Number(nutritionLink.getAttribute('data-tpc-nl-index')));
                    return;
                }

                const faqToggle = event.target.closest('.tpc-faq-toggle');
                if (faqToggle && root.contains(faqToggle)) {
                    event.preventDefault();
                    toggleFaq(faqToggle);
                    return;
                }

                const removeButton = event.target.closest('.tpc-selected-product-remove');
                if (removeButton && root.contains(removeButton)) {
                    event.preventDefault();
                    removeSelectedProduct(removeButton.getAttribute('data-product-id'));
                    return;
                }
            });

            buttons.forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    buildTable();
                });
            });

            if (aiGenerateButton) {
                aiGenerateButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (aiBusy) {
                        return;
                    }
                    const ids = selectedIds();
                    if (ids.length < 2) {
                        flashCopyFeedback('Cần chọn ít nhất 2 sản phẩm để so sánh bằng AI', true);
                        return;
                    }
                    setAiBusy(true);
                    if (aiPanel) {
                        aiPanel.hidden = false;
                        aiPanel.innerHTML = '<h3 class="tpc-ai-panel__title">So sánh bằng AI</h3><p class="tpc-ai-panel__status">Đang tạo bản nháp AI từ dữ liệu catalogue...</p>';
                    }
                    postAjax({
                        action: 'tpc_product_compare_ai_generate',
                        nonce: nonce,
                        product_ids: ids.join(','),
                        fields: JSON.stringify(fieldDefinitions)
                    }).then(function(response) {
                        if (!response || !response.success || !response.data.version) {
                            throw new Error((response && response.data && response.data.message) || 'Không tạo được bản nháp AI.');
                        }
                        return loadAiVersions(response.data.version.id, 'Đã tạo bản nháp AI. Hãy kiểm tra và chọn Công khai khi sẵn sàng.');
                    }).catch(function(error) {
                        renderAiPanel(error.message || 'Không tạo được bản nháp AI.', true);
                    }).finally(function() {
                        setAiBusy(false);
                    });
                });
            }

            if (aiPanel) {
                aiPanel.addEventListener('change', function(event) {
                    const select = event.target.closest('.tpc-ai-version-select');
                    if (!select || aiBusy) {
                        return;
                    }
                    activeAiVersionId = Number(select.value) || 0;
                    renderAiPanel('', false);
                });

                aiPanel.addEventListener('click', function(event) {
                    const actionButton = event.target.closest('[data-tpc-ai-action]');
                    if (!actionButton || aiBusy) {
                        return;
                    }
                    event.preventDefault();
                    if (actionButton.getAttribute('data-tpc-ai-action') === 'save') {
                        saveActiveAiVersion().then(function(version) {
                            return loadAiVersions(version.id, 'Đã lưu nội dung AI.');
                        }).then(refreshBuiltTable).catch(function(error) {
                            renderAiPanel(error.message || 'Không lưu được nội dung AI.', true);
                        });
                        return;
                    }

                    saveActiveAiVersion().then(function(version) {
                        setAiBusy(true);
                        return postAjax({
                            action: 'tpc_product_compare_ai_publish',
                            nonce: nonce,
                            version_id: version.id
                        });
                    }).then(function(response) {
                        if (!response || !response.success || !response.data.version) {
                            throw new Error((response && response.data && response.data.message) || 'Không thể công khai phiên bản AI.');
                        }
                        return loadAiVersions(response.data.version.id, 'Đã công khai phiên bản AI cho mọi người xem.');
                    }).then(refreshBuiltTable).catch(function(error) {
                        renderAiPanel(error.message || 'Không thể công khai phiên bản AI.', true);
                    }).finally(function() {
                        setAiBusy(false);
                    });
                });
            }

            root.querySelectorAll('.tpc-reset-button').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    resetCompareState();
                });
            });

            renderSelectedProducts();
            root.tpcGetSelectedIds = selectedIds;
            syncBuildButtonLabel();
            hasGeneratedTable = selectedIds().length >= 2 && hasGeneratedTable;
            setTopResetVisible(hasGeneratedTable);
            setTableActionsVisible(hasGeneratedTable);

            copyButtons.forEach(function(copyButton) {
                copyButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    const url = getCompareShareUrl();

                    copyText(url).then(function() {
                        flashCopyFeedback('Đã copy link');
                    }).catch(function() {
                        flashCopyFeedback('Không copy được link', true);
                    });
                });
            });

            if (selectedIds().length >= 2) {
                buildTable();
            } else {
                setCopyButtonsVisible(false);
                setTopResetVisible(false);
                setTableActionsVisible(false);
                hasGeneratedTable = false;
                syncBuildButtonLabel();
                root.setAttribute('data-tpc-active-cols', '0');
                tableShell.hidden = true;
                tableShell.classList.add('tpc-table-shell--hidden');
                body.innerHTML = '';
            }
        })();
    </script>
<?php

    return ob_get_clean();
}
add_shortcode('product_compare', 'tpc_product_compare_shortcode');

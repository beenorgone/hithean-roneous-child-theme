<?php

if (!defined('ABSPATH')) {
    exit;
}

function tpc_compare_ajax_nonce()
{
    return 'tpc_compare_ajax';
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
            'classes'    => 'button button-primary add_to_cart_button ajax_add_to_cart product_type_simple',
            'target'     => '',
            'rel'        => '',
            'product_id' => $product_id,
            'sku'        => (string) $product->get_sku(),
            'ajax'       => true,
        ];
    } elseif ($product->is_type('variable')) {
        $cart_config['label'] = 'Xem thêm / Mua';
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
        'cart'              => $cart_config,
    ]);
}

function tpc_compare_normalize_product_ids($raw_ids)
{
    if (is_array($raw_ids)) {
        $raw_ids = implode('-', $raw_ids);
    }

    if (!is_string($raw_ids) || trim($raw_ids) === '') {
        return [];
    }

    $ids = array_map('absint', preg_split('/[\s,-]+/', $raw_ids));

    return array_values(array_unique(array_filter($ids)));
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

        $term_ids_cache_key = 'product_cat_descendants:' . $category_id;
        $cached_term_ids = wp_cache_get($term_ids_cache_key, tpc_compare_cache_group());
        if ($cached_term_ids === false || !is_array($cached_term_ids)) {
            $cached_term_ids = array_merge([$category_id], get_term_children($category_id, 'product_cat'));
            $cached_term_ids = array_values(array_unique(array_filter(array_map('absint', $cached_term_ids))));
            wp_cache_set($term_ids_cache_key, $cached_term_ids, tpc_compare_cache_group(), HOUR_IN_SECONDS);
        }

        $term_ids = $cached_term_ids;
        $term_ids = array_values(array_unique(array_filter(array_map('absint', $term_ids))));

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
    ], $atts, 'product_compare');

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
    $category_terms = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    $category_terms = is_wp_error($category_terms) ? [] : $category_terms;

    ob_start();
?>
    <div id="<?php echo esc_attr($instance_id); ?>" class="tpc-compare-root">
        <div class="tpc-header">
            <h2 class="tpc-title">So sánh sản phẩm</h2>
            <button type="button" class="button button--dark-blue-reverse tpc-copy-link-button" hidden>Copy link xem bảng</button>
            <span class="tpc-copy-feedback" aria-live="polite" hidden>Đã copy link</span>
        </div>

        <div class="tpc-toolbar">
            <label class="tpc-category-filter-wrap">
                <span class="tpc-category-filter-label">So sánh trong nhóm sản phẩm</span>
                <select class="tpc-category-filter">
                    <option value="">Tất cả danh mục</option>
                    <?php foreach ($category_terms as $category_term) : ?>
                        <option value="<?php echo esc_attr($category_term->term_id); ?>">
                            <?php echo esc_html(html_entity_decode($category_term->name, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="tpc-picker-panel">
            <div class="tpc-picker-wrap">
                <div class="tpc-picker-label">Chọn sản phẩm so sánh</div>
                <div class="tpc-selected-products"></div>
                <div class="tpc-product-picker">
                    <input
                        type="text"
                        class="tpc-product-search"
                        value=""
                        data-selected-label=""
                        placeholder="<?php echo esc_attr(sprintf('Tìm và thêm sản phẩm (tối đa %d)...', $number)); ?>">
                    <div class="tpc-product-dropdown" hidden></div>
                </div>
                <div class="tpc-picker-help"><?php echo esc_html(sprintf('Chọn tối đa %d sản phẩm.', $number)); ?></div>
            </div>
        </div>

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

        <div class="tpc-actions">
            <button type="button" class="button button--light-blue tpc-build-button">Tạo bảng so sánh</button>
            <button type="button" class="button button--dark-blue-reverse tpc-copy-link-button" hidden>Copy link xem bảng</button>
        </div>
    </div>

    <style>
        #<?php echo esc_html($instance_id); ?> {
            display: grid;
            gap: 14px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-header {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-toolbar {
            display: flex;
            align-items: end;
            gap: 14px;
            flex-wrap: wrap;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-category-filter-wrap {
            display: grid;
            gap: 6px;
            min-width: min(340px, 100%);
        }

        #<?php echo esc_html($instance_id); ?> .tpc-category-filter-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #5f6b76;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-category-filter {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #c8d1d6;
            border-radius: 10px;
            background: #fff;
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
            gap: 12px;
            flex-wrap: wrap;
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
            border-radius: 14px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-row td {
            background: #fff;
            border-radius: 14px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-head th {
            padding: 8px 2px 4px;
            background: transparent;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-section-title {
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
            padding: 14px 18px;
            border-radius: 16px;
            text-align: center;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            color: #13325b;
            background: linear-gradient(135deg, #edf3ff 0%, #e2ecff 100%);
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

        #<?php echo esc_html($instance_id); ?> .tpc-product-picker {
            position: relative;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-search {
            width: 100%;
            min-width: 180px;
            border: 1px solid #c8d1d6;
            border-radius: 8px;
            background: #fff;
            padding: 10px 12px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-product-dropdown {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            z-index: 10;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            max-height: 280px;
            overflow: auto;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-state,
        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-empty {
            padding: 10px 12px;
            color: #5f6b76;
            font-size: 13px;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-list {
            margin: 0;
            padding: 6px 0;
            list-style: none;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-item:last-child {
            border-bottom: 0;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-item:hover {
            background: #f9fafb;
        }

        #<?php echo esc_html($instance_id); ?> .tpc-dropdown-thumb {
            width: 36px;
            height: 36px;
            border-radius: 4px;
            object-fit: cover;
            background: #f3f4f6;
            flex-shrink: 0;
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

            #<?php echo esc_html($instance_id); ?> .tpc-toolbar,
            #<?php echo esc_html($instance_id); ?> .tpc-actions {
                display: grid;
                grid-template-columns: 1fr;
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

            #<?php echo esc_html($instance_id); ?> .tpc-section-title {
                font-size: 18px;
                padding: 12px 14px;
            }
        }

        @media (min-width: 400px) and (max-width: 767px) {
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table {
                min-width: calc(var(--tpc-active-cols, <?php echo (int) $number; ?>) * 50vw);
            }

            #<?php echo esc_html($instance_id); ?> .tpc-section-note {
                display: inline;
            }
        }

        @media (max-width: 399px) {
            #<?php echo esc_html($instance_id); ?> .tpc-compare-table {
                min-width: calc(var(--tpc-active-cols, <?php echo (int) $number; ?>) * 72vw);
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
            const fieldDefinitions = <?php echo tpc_compare_json_encode_for_script($field_definitions); ?>;
            const comparePageUrl = <?php echo wp_json_encode($compare_page_url); ?>;
            const productMap = new Map();
            const initialProducts = <?php echo tpc_compare_json_encode_for_script($products); ?>;
            const buttons = Array.from(root.querySelectorAll('.tpc-build-button'));
            const copyButtons = Array.from(root.querySelectorAll('.tpc-copy-link-button'));
            const copyFeedback = root.querySelector('.tpc-copy-feedback');
            const selectedProductsWrap = root.querySelector('.tpc-selected-products');
            const searchInput = root.querySelector('.tpc-product-search');
            const searchDropdown = root.querySelector('.tpc-product-dropdown');
            const categoryFilter = root.querySelector('.tpc-category-filter');
            const maxProducts = <?php echo (int) $number; ?>;
            let selectedProducts = initialProducts.slice(0, maxProducts);

            initialProducts.forEach(function(product) {
                productMap.set(String(product.id), product);
            });

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
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

            function setDropdownState(dropdown, html) {
                dropdown.hidden = false;
                dropdown.innerHTML = html;
            }

            function closeDropdown(dropdown) {
                dropdown.hidden = true;
                dropdown.innerHTML = '';
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
                setCopyButtonsVisible(false);
                tableShell.hidden = false;
                tableShell.classList.remove('tpc-table-shell--hidden');
                root.style.setProperty('--tpc-active-cols', String(Math.max(selectedIds().length, 1)));
                body.innerHTML = '<tr><td colspan="' + Math.max(selectedIds().length, 1) + '" class="tpc-placeholder-cell">' + escapeHtml(message) + '</td></tr>';
            }

            function renderPriceCell(product) {
                if (!product || !Array.isArray(product.price_groups) || product.price_groups.length === 0) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                const items = product.price_groups.map(function(group) {
                    let html = '<li><span class="tpc-price">' + escapeHtml(group.price || '-') + '</span>';

                    if (Array.isArray(group.labels) && group.labels.length) {
                        html += '<span class="tpc-variation-notes">(' + escapeHtml(group.labels.join(', ')) + ')</span>';
                    }

                    html += '</li>';
                    return html;
                }).join('');

                return '<td><ul class="tpc-cell-list">' + items + '</ul></td>';
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

            function renderFieldCell(product, key) {
                if (!product || !product.fields || !product.fields[key]) {
                    return '<td><span class="tpc-empty-cell">-</span></td>';
                }

                return renderHtmlCell(product.fields[key]);
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

                return '<td><a class="tpc-add-to-cart ' + escapeHtml(classes) + '" href="' + escapeHtml(product.cart.url) + '"' + target + rel + dataProductId + dataSku + dataQuantity + '>' + escapeHtml(product.cart.label || 'Thêm vào giỏ hàng') + '</a></td>';
            }

            function renderSection(title, cellsHtml) {
                const activeCols = Math.max(cellsHtml.length, 1);
                return '<tr class="tpc-section-head"><th colspan="' + activeCols + '" class="tpc-section-title">' + escapeHtml(title) + '<span class="tpc-section-note">Kéo sang phải để xem từng sản phẩm.</span></th></tr>' +
                    '<tr class="tpc-section-row">' + cellsHtml.join('') + '</tr>';
            }

            function renderRows(products, renderedColumnCount) {
                tableShell.hidden = false;
                tableShell.classList.remove('tpc-table-shell--hidden');
                setCopyButtonsVisible(true);
                const activeCols = Math.max(Number(renderedColumnCount) || products.length || 1, 1);
                root.style.setProperty('--tpc-active-cols', String(activeCols));
                const rows = [];

                rows.push('<tr class="tpc-section-row">' + products.map(renderProductSummaryCell).join('') + '</tr>');
                rows.push(renderSection('Giá bán', products.map(renderPriceCell)));
                rows.push(renderSection('Mô tả ngắn', products.map(function(product) {
                    return renderHtmlCell(product ? product.short_description : '');
                })));

                fieldDefinitions.forEach(function(field) {
                    rows.push(renderSection(field.label || field.key, products.map(function(product) {
                        return renderFieldCell(product, field.key);
                    })));
                });

                rows.push(renderSection('Mua sản phẩm', products.map(renderCartCell)));
                body.innerHTML = rows.join('');
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

            function setButtonsDisabled(isDisabled) {
                buttons.forEach(function(button) {
                    button.disabled = isDisabled;
                });
            }

            function buildTable() {
                const ids = selectedIds();
                const activeIds = ids.filter(function(id) { return !!id; });
                const hasSelected = activeIds.length > 0;

                if (!hasSelected) {
                    showPlaceholder('Chọn ít nhất 1 sản phẩm rồi bấm "Tạo bảng so sánh".');
                    return;
                }

                setButtonsDisabled(true);
                showPlaceholder('Đang tạo bảng so sánh...');

                const sourceIds = activeIds;

                Promise.all(sourceIds.map(function(id) {
                    if (!id) return Promise.resolve(null);
                    return fetchProductPayload(id);
                })).then(function(products) {
                    products = products.filter(function(product) { return !!product; });
                    renderRows(products, sourceIds.length);
                }).catch(function() {
                    showPlaceholder('Không tải được dữ liệu sản phẩm.');
                }).finally(function() {
                    setButtonsDisabled(false);
                });
            }

            function renderSearchResults(items) {
                if (!items.length) {
                    setDropdownState(searchDropdown, '<div class="tpc-dropdown-empty">Không tìm thấy sản phẩm nào.</div>');
                    return;
                }

                const html = items.map(function(item) {
                    const thumb = item.thumb ?
                        '<img class="tpc-dropdown-thumb" src="' + escapeHtml(item.thumb) + '" alt="">' :
                        '<span class="tpc-dropdown-thumb"></span>';

                    return '<li class="tpc-dropdown-item" data-id="' + escapeHtml(item.id) + '" data-label="' + escapeHtml(item.label) + '">' +
                        thumb +
                        '<div class="tpc-dropdown-title">' + escapeHtml(item.label) + '</div>' +
                        '</li>';
                }).join('');

                setDropdownState(searchDropdown, '<ul class="tpc-dropdown-list">' + html + '</ul>');
            }

            function searchProducts(term) {
                if (term.length < 2) {
                    closeDropdown(searchDropdown);
                    return;
                }

                setDropdownState(searchDropdown, '<div class="tpc-dropdown-state">Đang tải...</div>');

                postAjax({
                    action: 'tpc_product_compare_search',
                    nonce: nonce,
                    term: term,
                    category_id: categoryFilter ? categoryFilter.value : '',
                    exclude_ids: selectedIds().join(',')
                }).then(function(response) {
                    const items = Array.isArray(response) ? response : [];
                    renderSearchResults(items);
                }).catch(function() {
                    setDropdownState(searchDropdown, '<div class="tpc-dropdown-empty">Không tải được kết quả.</div>');
                });
            }

            const searchTimers = new WeakMap();

            root.addEventListener('input', function(event) {
                const input = event.target.closest('.tpc-product-search');
                if (!input || !root.contains(input)) {
                    return;
                }

                if (searchTimers.has(input)) {
                    clearTimeout(searchTimers.get(input));
                }

                const timerId = window.setTimeout(function() {
                    searchProducts(input.value.trim());
                }, 250);

                searchTimers.set(input, timerId);
            });

            root.addEventListener('focusin', function(event) {
                const input = event.target.closest('.tpc-product-search');
                if (!input || !root.contains(input)) {
                    return;
                }

                const value = input.value.trim();
                if (value.length >= 2) {
                    searchProducts(value);
                }
            });

            root.addEventListener('click', function(event) {
                const item = event.target.closest('.tpc-dropdown-item');
                if (item && root.contains(item)) {
                    if (!searchInput || !searchDropdown) {
                        return;
                    }

                    const productId = String(item.getAttribute('data-id') || '').trim();
                    const productLabel = item.getAttribute('data-label') || '';

                    if (!productId || selectedIds().includes(productId)) {
                        closeDropdown(searchDropdown);
                        searchInput.value = '';
                        return;
                    }

                    if (selectedProducts.length >= maxProducts) {
                        flashCopyFeedback('Đã đạt số sản phẩm tối đa', true);
                        closeDropdown(searchDropdown);
                        return;
                    }

                    event.preventDefault();
                    selectedProducts.push({
                        id: productId,
                        title: productLabel,
                        label: productLabel,
                    });
                    renderSelectedProducts();
                    searchInput.value = '';
                    searchInput.dataset.selectedLabel = '';
                    closeDropdown(searchDropdown);
                    return;
                }

                const removeButton = event.target.closest('.tpc-selected-product-remove');
                if (removeButton && root.contains(removeButton)) {
                    event.preventDefault();
                    const removeId = String(removeButton.getAttribute('data-product-id') || '').trim();
                    selectedProducts = selectedProducts.filter(function(product) {
                        return String(product.id) !== removeId;
                    });
                    renderSelectedProducts();
                    return;
                }
            });

            document.addEventListener('mousedown', function(event) {
                if (!event.target.closest('#' + <?php echo wp_json_encode($instance_id); ?>)) {
                    closeDropdown(searchDropdown);
                    return;
                }

                if (!event.target.closest('.tpc-product-picker')) {
                    closeDropdown(searchDropdown);
                }
            });

            buttons.forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    buildTable();
                });
            });

            renderSelectedProducts();

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

            if (selectedIds().some(function(id) {
                    return !!id;
                })) {
                buildTable();
            } else {
                setCopyButtonsVisible(false);
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

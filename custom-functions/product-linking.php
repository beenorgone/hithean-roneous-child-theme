<?php

defined('ABSPATH') || exit;

const HITHEAN_PRODUCT_LINKING_CACHE_GROUP = 'hithean_product_linking';

/**
 * Add lightweight product-to-product option fields.
 *
 * Each product can represent one purchasable option. The linked product list is
 * shared by products in the same group, so editors only need to maintain one
 * complete list in the group.
 */
function hithean_product_linking_meta_boxes($meta_boxes)
{
    $meta_boxes[] = [
        'title'      => esc_html__('Nhóm lựa chọn sản phẩm', 'roneous'),
        'id'         => 'hithean_product_linking_metabox',
        'post_types' => ['product'],
        'context'    => 'side',
        'priority'   => 'default',
        'autosave'   => true,
        'fields'     => [
            [
                'id'          => 'hithean_linked_products',
                'name'        => esc_html__('Sản phẩm cùng nhóm', 'roneous'),
                'type'        => 'post',
                'post_type'   => 'product',
                'field_type'  => 'select_advanced',
                'multiple'    => true,
                'placeholder' => esc_html__('Chọn các sản phẩm đại diện biến thể', 'roneous'),
                'desc'        => esc_html__('Chọn tất cả sản phẩm trong cùng nhóm flavor/serving. Có thể nhập ở một sản phẩm bất kỳ trong nhóm.', 'roneous'),
                'query_args'  => [
                    'post_status'    => ['publish', 'private', 'draft'],
                    'posts_per_page' => 20,
                ],
            ],
            [
                'id'   => 'hithean_linking_flavor',
                'name' => esc_html__('Hương vị', 'roneous'),
                'type' => 'text',
                'desc' => esc_html__('Ví dụ: Vanilla, Chocolate, Không vị.', 'roneous'),
            ],
            [
                'id'   => 'hithean_linking_serving',
                'name' => esc_html__('Quy cách', 'roneous'),
                'type' => 'text',
                'desc' => esc_html__('Ví dụ: 20 servings, 500g, 1kg.', 'roneous'),
            ],
        ],
    ];

    return $meta_boxes;
}
add_filter('rwmb_meta_boxes', 'hithean_product_linking_meta_boxes');

function hithean_product_linking_clear_cache($post_id)
{
    if ('product' !== get_post_type($post_id)) {
        return;
    }

    wp_cache_flush_group(HITHEAN_PRODUCT_LINKING_CACHE_GROUP);
}
add_action('save_post_product', 'hithean_product_linking_clear_cache');

function hithean_product_linking_get_meta_array($product_id, $key)
{
    $value = get_post_meta($product_id, $key, true);

    if (empty($value)) {
        return [];
    }

    if (!is_array($value)) {
        $value = [$value];
    }

    return array_values(array_filter(array_map('absint', $value)));
}

function hithean_product_linking_find_group_ids($product_id)
{
    $cache_key = 'group_ids_' . absint($product_id);
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $direct_ids = hithean_product_linking_get_meta_array($product_id, 'hithean_linked_products');

    if (!empty($direct_ids)) {
        $group_ids = $direct_ids;
    } else {
        $query = new WP_Query([
            'post_type'              => 'product',
            'post_status'            => ['publish', 'private', 'draft'],
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => 'hithean_linked_products',
                    'value'   => '"' . absint($product_id) . '"',
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $group_ids = [];

        if (!empty($query->posts)) {
            $group_ids = hithean_product_linking_get_meta_array((int) $query->posts[0], 'hithean_linked_products');
        }
    }

    $group_ids[] = absint($product_id);
    $group_ids = array_values(array_unique(array_filter($group_ids)));

    wp_cache_set($cache_key, $group_ids, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $group_ids;
}

function hithean_product_linking_get_options($product_id)
{
    $cache_key = 'options_' . absint($product_id);
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $group_ids = hithean_product_linking_find_group_ids($product_id);

    if (count($group_ids) < 2) {
        wp_cache_set($cache_key, [], HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);
        return [];
    }

    $options = [];

    foreach ($group_ids as $group_id) {
        $linked_product = wc_get_product($group_id);

        if (!$linked_product instanceof WC_Product || 'publish' !== get_post_status($group_id)) {
            continue;
        }

        $options[] = [
            'id'       => $group_id,
            'title'    => get_the_title($group_id),
            'url'      => get_permalink($group_id),
            'flavor'   => trim((string) get_post_meta($group_id, 'hithean_linking_flavor', true)),
            'serving'  => trim((string) get_post_meta($group_id, 'hithean_linking_serving', true)),
            'in_stock' => $linked_product->is_in_stock(),
        ];
    }

    wp_cache_set($cache_key, $options, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $options;
}

function hithean_product_linking_get_current_label($options, $product_id, $field)
{
    foreach ($options as $option) {
        if ((int) $option['id'] === (int) $product_id) {
            return $option[$field];
        }
    }

    return '';
}

function hithean_product_linking_render_group($options, $product_id, $field, $label)
{
    $values = array_values(array_filter(array_unique(wp_list_pluck($options, $field))));

    if (empty($values)) {
        return;
    }

    $current_label = hithean_product_linking_get_current_label($options, $product_id, $field);

    echo '<div class="hithean-product-linking__group">';
    echo '<div class="hithean-product-linking__label">' . esc_html($label) . ': <strong>' . esc_html($current_label) . '</strong></div>';
    echo '<div class="hithean-product-linking__options" role="list">';

    foreach ($options as $option) {
        if ('' === $option[$field]) {
            continue;
        }

        $classes = ['hithean-product-linking__option'];

        if ((int) $option['id'] === (int) $product_id) {
            $classes[] = 'is-active';
        }

        if (!$option['in_stock']) {
            $classes[] = 'is-out-of-stock';
        }

        printf(
            '<a class="%1$s" href="%2$s" role="listitem" aria-label="%3$s"%4$s>%5$s</a>',
            esc_attr(implode(' ', $classes)),
            esc_url($option['url']),
            esc_attr(sprintf(__('Xem sản phẩm %1$s: %2$s', 'roneous'), $label, $option[$field])),
            (int) $option['id'] === (int) $product_id ? ' aria-current="page"' : '',
            esc_html($option[$field])
        );
    }

    echo '</div>';
    echo '</div>';
}

function hithean_product_linking_render()
{
    global $product;

    if (!$product instanceof WC_Product) {
        return;
    }

    $product_id = $product->get_id();
    $options = hithean_product_linking_get_options($product_id);

    if (empty($options)) {
        return;
    }

    echo '<section class="hithean-product-linking" aria-label="' . esc_attr__('Lựa chọn sản phẩm', 'roneous') . '">';
    hithean_product_linking_render_group($options, $product_id, 'flavor', __('Hương vị', 'roneous'));
    hithean_product_linking_render_group($options, $product_id, 'serving', __('Quy cách', 'roneous'));
    echo '</section>';
}
add_action('woocommerce_before_add_to_cart_form', 'hithean_product_linking_render', 5);

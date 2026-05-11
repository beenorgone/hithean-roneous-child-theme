<?php

defined('ABSPATH') || exit;

const HITHEAN_PRODUCT_LINKING_CACHE_GROUP = 'hithean_product_linking';
const HITHEAN_PRODUCT_LINKING_DROPDOWN_THRESHOLD = 4;
const HITHEAN_PRODUCT_LINKING_CSV_META_KEY = 'hithean_product_links_csv';

/**
 * Product linking is configured by CSV custom field, not metabox.
 * Meta key: hithean_product_links_csv
 * CSV rows: product_id,flavor,serving
 * Header row is optional.
 */
function hithean_product_linking_parse_csv($csv_data)
{
    $csv_data = trim((string) $csv_data);

    if ('' === $csv_data) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $csv_data);
    $rows = [];

    foreach ($lines as $line) {
        $line = trim((string) $line);

        if ('' === $line) {
            continue;
        }

        $row = array_map('trim', str_getcsv($line));

        if (empty($row)) {
            continue;
        }

        $first_cell = isset($row[0]) ? strtolower((string) $row[0]) : '';

        if (in_array($first_cell, ['id', 'product_id', 'product id', 'san_pham_id', 'sản phẩm id'], true)) {
            continue;
        }

        $product_id = isset($row[0]) ? absint($row[0]) : 0;

        if (!$product_id) {
            continue;
        }

        $rows[] = [
            'id'      => $product_id,
            'flavor'  => isset($row[1]) ? trim((string) $row[1]) : '',
            'serving' => isset($row[2]) ? trim((string) $row[2]) : '',
        ];
    }

    return $rows;
}

function hithean_product_linking_clear_cache($post_id)
{
    if ('product' !== get_post_type($post_id)) {
        return;
    }

    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group(HITHEAN_PRODUCT_LINKING_CACHE_GROUP);
    }
}
add_action('save_post_product', 'hithean_product_linking_clear_cache');

function hithean_product_linking_get_csv_source_product_id($product_id)
{
    $product_id = absint($product_id);
    $csv_data = get_post_meta($product_id, HITHEAN_PRODUCT_LINKING_CSV_META_KEY, true);

    if (trim((string) $csv_data) !== '') {
        return $product_id;
    }

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
                'key'     => HITHEAN_PRODUCT_LINKING_CSV_META_KEY,
                'value'   => (string) $product_id,
                'compare' => 'LIKE',
            ],
        ],
    ]);

    if (empty($query->posts)) {
        return 0;
    }

    return (int) $query->posts[0];
}

function hithean_product_linking_find_group_ids($product_id)
{
    $cache_key = 'group_ids_' . absint($product_id);
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $source_product_id = hithean_product_linking_get_csv_source_product_id($product_id);

    if (!$source_product_id) {
        wp_cache_set($cache_key, [], HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);
        return [];
    }

    $csv_rows = hithean_product_linking_parse_csv(get_post_meta($source_product_id, HITHEAN_PRODUCT_LINKING_CSV_META_KEY, true));
    $group_ids = wp_list_pluck($csv_rows, 'id');
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

    $source_product_id = hithean_product_linking_get_csv_source_product_id($product_id);
    $csv_rows = $source_product_id
        ? hithean_product_linking_parse_csv(get_post_meta($source_product_id, HITHEAN_PRODUCT_LINKING_CSV_META_KEY, true))
        : [];
    $row_by_product_id = [];

    foreach ($csv_rows as $row) {
        $row_by_product_id[(int) $row['id']] = $row;
    }

    foreach ($group_ids as $group_id) {
        $linked_product = wc_get_product($group_id);

        if (!$linked_product instanceof WC_Product || 'publish' !== get_post_status($group_id)) {
            continue;
        }

        $row = isset($row_by_product_id[$group_id]) ? $row_by_product_id[$group_id] : [];

        $options[] = [
            'id'       => $group_id,
            'title'    => get_the_title($group_id),
            'url'      => get_permalink($group_id),
            'flavor'   => isset($row['flavor']) ? trim((string) $row['flavor']) : '',
            'serving'  => isset($row['serving']) ? trim((string) $row['serving']) : '',
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

    if (count($options) >= HITHEAN_PRODUCT_LINKING_DROPDOWN_THRESHOLD) {
        echo '<select class="hithean-product-linking__select" aria-label="' . esc_attr($label) . '">';

        foreach ($options as $option) {
            if ('' === $option[$field]) {
                continue;
            }

            printf(
                '<option value="%1$s"%2$s>%3$s%4$s</option>',
                esc_url($option['url']),
                selected((int) $option['id'], (int) $product_id, false),
                esc_html($option[$field]),
                $option['in_stock'] ? '' : esc_html__(' - Hết hàng', 'roneous')
            );
        }

        echo '</select>';
        echo '</div>';
        return;
    }

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

function hithean_product_linking_enqueue_assets()
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }

    wp_add_inline_style('roneous-child-style', <<<'CSS'
.hithean-product-linking {
    display: grid;
    gap: 14px;
    margin: 18px 0 22px;
}

.hithean-product-linking__group {
    display: grid;
    gap: 8px;
}

.hithean-product-linking__label {
    color: var(--default-color-black, #323232);
    font-family: "Be Vietnam", sans-serif;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.35;
}

.hithean-product-linking__label strong {
    font-weight: 700;
}

.hithean-product-linking__options {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hithean-product-linking .hithean-product-linking__option {
    align-items: center;
    background: #fff;
    border: 1px solid rgba(50, 50, 50, 0.18);
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
    color: var(--default-color-black, #323232);
    display: inline-flex;
    font-family: "Be Vietnam", sans-serif;
    font-size: 14px;
    font-weight: 600;
    justify-content: center;
    line-height: 1.2;
    min-height: 40px;
    min-width: 72px;
    padding: 10px 14px;
    text-align: center;
    text-decoration: none;
    transition: border-color 160ms ease, box-shadow 160ms ease, color 160ms ease, transform 160ms ease;
}

.hithean-product-linking .hithean-product-linking__option:hover,
.hithean-product-linking .hithean-product-linking__option:focus {
    border-color: var(--default-color-dark-brown, #2e1203);
    box-shadow: 0 3px 8px rgba(46, 18, 3, 0.14);
    color: var(--default-color-dark-brown, #2e1203);
    text-decoration: none;
    transform: translateY(-1px);
}

.hithean-product-linking .hithean-product-linking__option.is-active {
    background: var(--default-color-dark-brown, #2e1203);
    border-color: var(--default-color-dark-brown, #2e1203);
    color: #fff;
}

.hithean-product-linking .hithean-product-linking__option.is-out-of-stock:not(.is-active) {
    color: rgba(50, 50, 50, 0.5);
    position: relative;
}

.hithean-product-linking .hithean-product-linking__option.is-out-of-stock:not(.is-active)::after {
    background: currentColor;
    content: "";
    height: 1px;
    left: 10px;
    opacity: 0.55;
    position: absolute;
    right: 10px;
    top: 50%;
}

.hithean-product-linking__select {
    appearance: none;
    background-color: #fff;
    background-image: linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%);
    background-position: calc(100% - 18px) 50%, calc(100% - 13px) 50%;
    background-repeat: no-repeat;
    background-size: 5px 5px, 5px 5px;
    border: 1px solid rgba(50, 50, 50, 0.2);
    border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
    color: var(--default-color-black, #323232);
    font-family: "Be Vietnam", sans-serif;
    font-size: 14px;
    font-weight: 600;
    min-height: 44px;
    padding: 10px 40px 10px 14px;
    width: 100%;
}
CSS);

    wp_add_inline_script('jquery', <<<'JS'
jQuery(function($) {
    $(document).on('change', '.hithean-product-linking__select', function() {
        var url = $(this).val();

        if (url && url !== window.location.href) {
            window.location.assign(url);
        }
    });
});
JS);
}
add_action('wp_enqueue_scripts', 'hithean_product_linking_enqueue_assets', 20);

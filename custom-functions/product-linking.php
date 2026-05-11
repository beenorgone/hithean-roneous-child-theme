<?php

defined('ABSPATH') || exit;

const HITHEAN_PRODUCT_LINKING_CACHE_GROUP = 'hithean_product_linking';
const HITHEAN_PRODUCT_LINKING_DROPDOWN_THRESHOLD = 4;
const HITHEAN_PRODUCT_LINKING_CSV_META_KEY = 'product_linking_list';
const HITHEAN_PRODUCT_LINKING_LEGACY_CSV_META_KEY = 'hithean_product_links_csv';

/**
 * Product linking is configured by CSV custom field, not metabox.
 * Meta key: product_linking_list
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

add_action('wp_ajax_hithean_product_linking_search', 'hithean_product_linking_ajax_product_search');
function hithean_product_linking_ajax_product_search()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';

    if (strlen($term) < 2) {
        wp_send_json_success([]);
    }

    $query = new WP_Query([
        'post_type'      => ['product', 'product_variation'],
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        's'              => $term,
        'fields'         => 'ids',
    ]);

    $results = [];

    foreach ($query->posts as $post_id) {
        $product = wc_get_product($post_id);

        if (!$product instanceof WC_Product) {
            continue;
        }

        $parent_id = $product->get_parent_id();
        $product_id = $parent_id ? $parent_id : $post_id;
        $display_id = $parent_id ? $post_id : $product_id;

        $results[] = [
            'id'    => $product_id,
            'name'  => $product->get_name(),
            'label' => sprintf('#%d - %s', $display_id, $product->get_formatted_name()),
        ];
    }

    wp_send_json_success($results);
}

add_filter('rwmb_meta_boxes', 'hithean_product_linking_register_csv_field');
function hithean_product_linking_register_csv_field($meta_boxes)
{
    if (!class_exists('RW_Meta_Box')) {
        return $meta_boxes;
    }

    $meta_boxes[] = [
        'id'         => 'hithean_product_linking_csv_box',
        'title'      => 'Nhóm lựa chọn sản phẩm',
        'post_types' => ['product'],
        'context'    => 'normal',
        'priority'   => 'high',
        'fields'     => [
            [
                'name'        => 'CSV sản phẩm liên kết',
                'id'          => HITHEAN_PRODUCT_LINKING_CSV_META_KEY,
                'type'        => 'textarea',
                'placeholder' => "Mỗi dòng: Product ID, Hương vị, Quy cách\n123, Bơ Matcha, 400g\n124, Overnight, 400g",
                'desc'        => implode('<br>', [
                    '<strong>Định dạng mỗi dòng</strong>: <code>Product ID, Hương vị, Quy cách</code>',
                    '<strong>Ví dụ</strong>: <code>123, Bơ Matcha, 400g</code>',
                    'Nếu có dưới 4 sản phẩm: hiển thị clickable pills. Từ 4 sản phẩm trở lên: hiển thị dropdown.',
                    'Nhập cùng một danh sách ở ít nhất một sản phẩm trong nhóm. Nên có cả ID của sản phẩm hiện tại để nhãn đang chọn hiển thị đúng.',
                ]),
            ],
            [
                'type' => 'custom_html',
                'std'  => hithean_product_linking_render_builder_tool(),
            ],
        ],
    ];

    return $meta_boxes;
}

function hithean_product_linking_render_builder_tool()
{
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <style>
        .hithean-product-linking-builder {
            margin-top: 12px;
            padding: 14px;
            border: 1px solid #dcdcde;
            background: #f6f7f7;
            border-radius: 4px;
        }

        .hithean-product-linking-builder h4 {
            margin: 0 0 10px;
        }

        .hithean-product-linking-builder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .hithean-product-linking-field {
            position: relative;
        }

        .hithean-product-linking-field label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 12px;
        }

        .hithean-product-linking-field input {
            width: 100%;
            min-height: 34px;
            padding: 0 8px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .hithean-product-linking-search-results {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 20;
            max-height: 220px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #8c8f94;
            border-top: 0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        }

        .hithean-product-linking-search-item {
            padding: 8px 10px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f1;
        }

        .hithean-product-linking-search-item:hover {
            background: #f0f6fc;
        }

        .hithean-product-linking-builder-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .hithean-product-linking-builder-note {
            color: #50575e;
            font-size: 12px;
        }
    </style>

    <div class="hithean-product-linking-builder" data-ajax-url="<?php echo esc_url($ajax_url); ?>">
        <h4>Tạo nhanh dòng sản phẩm liên kết</h4>
        <div class="hithean-product-linking-builder-grid">
            <div class="hithean-product-linking-field">
                <label>Tìm sản phẩm</label>
                <input type="text" data-product-linking-search placeholder="Gõ tên sản phẩm...">
                <input type="hidden" data-field="product_id">
                <div class="hithean-product-linking-search-results"></div>
            </div>
            <div class="hithean-product-linking-field">
                <label>Hương vị</label>
                <input type="text" data-field="flavor" placeholder="Bơ Matcha">
            </div>
            <div class="hithean-product-linking-field">
                <label>Quy cách</label>
                <input type="text" data-field="serving" placeholder="400g">
            </div>
        </div>
        <div class="hithean-product-linking-builder-actions">
            <button type="button" class="button button-primary" data-add-product-linking-row>Thêm vào CSV</button>
            <span class="hithean-product-linking-builder-note">Tool này sẽ tự chèn đúng format vào ô CSV bên trên.</span>
        </div>
    </div>

    <script>
        (function() {
            if (window.hitheanProductLinkingBuilderInit) {
                return;
            }
            window.hitheanProductLinkingBuilderInit = true;

            const csvEscape = function(value) {
                value = String(value || '');
                return /[",\n\r]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
            };

            const bindSearch = function(builder) {
                const ajaxUrl = builder.getAttribute('data-ajax-url');
                const input = builder.querySelector('[data-product-linking-search]');
                const hidden = builder.querySelector('[data-field="product_id"]');
                const results = builder.querySelector('.hithean-product-linking-search-results');
                let timer = null;

                if (!input || !hidden || !results) {
                    return;
                }

                input.addEventListener('input', function() {
                    const term = input.value.trim();
                    hidden.value = '';

                    if (term.length < 2) {
                        results.style.display = 'none';
                        results.innerHTML = '';
                        return;
                    }

                    clearTimeout(timer);
                    timer = setTimeout(function() {
                        fetch(ajaxUrl + '?action=hithean_product_linking_search&term=' + encodeURIComponent(term))
                            .then(function(response) {
                                return response.json();
                            })
                            .then(function(response) {
                                results.innerHTML = '';

                                if (!response.success || !response.data.length) {
                                    results.style.display = 'none';
                                    return;
                                }

                                response.data.forEach(function(item) {
                                    const row = document.createElement('div');
                                    row.className = 'hithean-product-linking-search-item';
                                    row.textContent = item.label;
                                    row.addEventListener('click', function() {
                                        input.value = item.name;
                                        hidden.value = item.id;
                                        results.style.display = 'none';
                                    });
                                    results.appendChild(row);
                                });

                                results.style.display = 'block';
                            });
                    }, 300);
                });

                document.addEventListener('click', function(event) {
                    if (!builder.contains(event.target)) {
                        results.style.display = 'none';
                    }
                });
            };

            const appendLine = function(builder) {
                const textarea = document.getElementById('<?php echo esc_js(HITHEAN_PRODUCT_LINKING_CSV_META_KEY); ?>');

                if (!textarea) {
                    alert('Không tìm thấy ô CSV sản phẩm liên kết.');
                    return;
                }

                const productId = builder.querySelector('[data-field="product_id"]').value.trim();
                const flavor = builder.querySelector('[data-field="flavor"]').value.trim();
                const serving = builder.querySelector('[data-field="serving"]').value.trim();

                if (!productId) {
                    alert('Cần chọn sản phẩm trước khi thêm.');
                    return;
                }

                const line = [productId, flavor, serving].map(csvEscape).join(',');
                textarea.value = textarea.value.trim() ? textarea.value + "\n" + line : line;
                textarea.dispatchEvent(new Event('change'));

                builder.querySelectorAll('input').forEach(function(input) {
                    input.value = '';
                });
            };

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.hithean-product-linking-builder').forEach(function(builder) {
                    bindSearch(builder);

                    const addButton = builder.querySelector('[data-add-product-linking-row]');
                    if (addButton) {
                        addButton.addEventListener('click', function() {
                            appendLine(builder);
                        });
                    }
                });
            });
        })();
    </script>
    <?php

    return ob_get_clean();
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
    $csv_data = hithean_product_linking_get_csv_data($product_id);

    if (trim((string) $csv_data) !== '') {
        return $product_id;
    }

    $query = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => ['publish', 'private', 'draft'],
        'posts_per_page'         => 20,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'OR',
            [
                'key'     => HITHEAN_PRODUCT_LINKING_CSV_META_KEY,
                'value'   => (string) $product_id,
                'compare' => 'LIKE',
            ],
            [
                'key'     => HITHEAN_PRODUCT_LINKING_LEGACY_CSV_META_KEY,
                'value'   => (string) $product_id,
                'compare' => 'LIKE',
            ],
        ],
    ]);

    foreach ($query->posts as $candidate_id) {
        $rows = hithean_product_linking_parse_csv(hithean_product_linking_get_csv_data((int) $candidate_id));
        $ids = array_map('intval', wp_list_pluck($rows, 'id'));

        if (in_array($product_id, $ids, true)) {
            return (int) $candidate_id;
        }
    }

    return 0;
}

function hithean_product_linking_get_csv_data($product_id)
{
    if (function_exists('rwmb_meta')) {
        $csv_data = rwmb_meta(HITHEAN_PRODUCT_LINKING_CSV_META_KEY, [], $product_id);
    } else {
        $csv_data = get_post_meta($product_id, HITHEAN_PRODUCT_LINKING_CSV_META_KEY, true);
    }

    if (trim((string) $csv_data) !== '') {
        return (string) $csv_data;
    }

    return (string) get_post_meta($product_id, HITHEAN_PRODUCT_LINKING_LEGACY_CSV_META_KEY, true);
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

    $csv_rows = hithean_product_linking_parse_csv(hithean_product_linking_get_csv_data($source_product_id));
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
        ? hithean_product_linking_parse_csv(hithean_product_linking_get_csv_data($source_product_id))
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

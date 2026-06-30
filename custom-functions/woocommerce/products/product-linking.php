<?php

defined('ABSPATH') || exit;

const HITHEAN_PRODUCT_LINKING_CACHE_GROUP = 'hithean_product_linking';
const HITHEAN_PRODUCT_LINKING_DROPDOWN_THRESHOLD = 4;
const HITHEAN_PRODUCT_LINKING_OPTION_KEY = 'hithean_product_linking_groups_csv';
const HITHEAN_PRODUCT_LINKING_NONCE_ACTION = 'hithean_product_linking_search';

/**
 * Product linking is configured globally by CSV.
 * CSV rows: group,product_id,label_1,value_1,label_2,value_2
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

        if (in_array($first_cell, ['group', 'group_key', 'nhom', 'nhóm'], true)) {
            continue;
        }

        $product_id = isset($row[1]) ? absint($row[1]) : 0;

        if (!$product_id) {
            continue;
        }

        $rows[] = [
            'group'   => sanitize_key((string) ($row[0] ?? '')),
            'label_1' => trim((string) ($row[2] ?? 'Hương vị')),
            'label_2' => trim((string) ($row[4] ?? 'Quy cách')),
            'id'      => $product_id,
            'flavor'  => isset($row[3]) ? trim((string) $row[3]) : '',
            'serving' => isset($row[5]) ? trim((string) $row[5]) : '',
        ];
    }

    return $rows;
}

function hithean_product_linking_encode_csv_row(array $row)
{
    $stream = fopen('php://temp', 'r+');

    if (false === $stream) {
        return implode(',', array_map('strval', $row));
    }

    fputcsv($stream, $row);
    rewind($stream);
    $line = stream_get_contents($stream);
    fclose($stream);

    return rtrim((string) $line, "\r\n");
}

function hithean_product_linking_get_global_csv_data()
{
    return (string) get_option(HITHEAN_PRODUCT_LINKING_OPTION_KEY, '');
}

function hithean_product_linking_cache_version()
{
    return substr(md5(hithean_product_linking_get_global_csv_data()), 0, 12);
}

function hithean_product_linking_get_all_global_rows()
{
    $cache_key = 'global_rows_' . hithean_product_linking_cache_version();
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $rows = hithean_product_linking_parse_csv(hithean_product_linking_get_global_csv_data());
    wp_cache_set($cache_key, $rows, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $rows;
}

function hithean_product_linking_get_global_index()
{
    $cache_key = 'global_index_' . hithean_product_linking_cache_version();
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $index = [
        'by_product' => [],
        'by_group'   => [],
    ];

    foreach (hithean_product_linking_get_all_global_rows() as $row) {
        $group = (string) $row['group'];
        $product_id = (int) $row['id'];

        if ('' === $group || $product_id <= 0) {
            continue;
        }

        $index['by_product'][$product_id] = $group;

        if (!isset($index['by_group'][$group])) {
            $index['by_group'][$group] = [];
        }

        $index['by_group'][$group][] = $row;
    }

    wp_cache_set($cache_key, $index, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $index;
}

function hithean_product_linking_clear_all_cache()
{
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group(HITHEAN_PRODUCT_LINKING_CACHE_GROUP);
        return;
    }

    wp_cache_delete('global_rows_' . hithean_product_linking_cache_version(), HITHEAN_PRODUCT_LINKING_CACHE_GROUP);
    wp_cache_delete('global_index_' . hithean_product_linking_cache_version(), HITHEAN_PRODUCT_LINKING_CACHE_GROUP);
}

function hithean_product_linking_sanitize_global_csv($input)
{
    hithean_product_linking_clear_all_cache();

    $rows = hithean_product_linking_parse_csv(wp_check_invalid_utf8((string) $input));

    if (empty($rows)) {
        return '';
    }

    $rows = array_slice($rows, 0, 500);
    $lines = [];

    foreach ($rows as $row) {
        $lines[] = hithean_product_linking_encode_csv_row([
            sanitize_key((string) $row['group']),
            absint($row['id']),
            sanitize_text_field((string) $row['label_1']),
            sanitize_text_field((string) $row['flavor']),
            sanitize_text_field((string) $row['label_2']),
            sanitize_text_field((string) $row['serving']),
        ]);
    }

    return implode("\n", $lines);
}

add_action('admin_init', 'hithean_product_linking_register_settings');
function hithean_product_linking_register_settings()
{
    register_setting('hithean_product_linking_settings', HITHEAN_PRODUCT_LINKING_OPTION_KEY, 'hithean_product_linking_sanitize_global_csv');
}

add_action('admin_menu', 'hithean_product_linking_register_settings_page', 30);
function hithean_product_linking_register_settings_page()
{
    add_submenu_page(
        'edit.php?post_type=product',
        'Liên kết sản phẩm',
        'Liên kết sản phẩm',
        'manage_woocommerce',
        'hithean-product-linking',
        'hithean_product_linking_render_settings_page'
    );
}

function hithean_product_linking_render_settings_page()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'roneous'));
    }

    $csv_data = hithean_product_linking_get_global_csv_data();
    ?>
    <div class="wrap">
        <h1>Liên kết sản phẩm</h1>
        <p>Cấu hình tập trung các nhóm sản phẩm thay thế nhau theo kiểu flavor/serving. Tên option có thể tùy chỉnh theo từng nhóm.</p>

        <form method="post" action="options.php">
            <?php settings_fields('hithean_product_linking_settings'); ?>
            <?php settings_errors(HITHEAN_PRODUCT_LINKING_OPTION_KEY); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">CSV nhóm sản phẩm</th>
                    <td>
                        <textarea id="<?php echo esc_attr(HITHEAN_PRODUCT_LINKING_OPTION_KEY); ?>" class="large-text code" rows="14" name="<?php echo esc_attr(HITHEAN_PRODUCT_LINKING_OPTION_KEY); ?>" placeholder="group,sản phẩm,label_1,value_1,label_2,value_2&#10;yeast-hero,123,Hương vị,Bơ Matcha,Quy cách,400g&#10;yeast-hero,124,Hương vị,Overnight,Quy cách,400g"><?php echo esc_textarea($csv_data); ?></textarea>
                        <p class="description">
                            Format: <code>group,sản phẩm,label_1,value_1,label_2,value_2</code>.
                            <code>sản phẩm</code> là Product ID. <code>value_1</code> và <code>value_2</code> là giá trị option hiển thị.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Tạo nhanh dòng CSV</th>
                    <td><?php echo hithean_product_linking_render_global_builder_tool(); ?></td>
                </tr>
            </table>

            <?php submit_button('Lưu liên kết sản phẩm'); ?>
        </form>

        <hr>
        <h2>Dọn custom fields phiên bản cũ</h2>
        <p>Công cụ này xóa các post meta cũ không còn được dùng sau khi chuyển sang cấu hình tập trung.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Xóa các custom fields liên kết sản phẩm cũ khỏi database?');">
            <?php wp_nonce_field('hithean_product_linking_cleanup_legacy_meta'); ?>
            <input type="hidden" name="action" value="hithean_product_linking_cleanup_legacy_meta">
            <?php submit_button('Xóa custom fields cũ', 'delete', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function hithean_product_linking_legacy_meta_keys()
{
    return [
        'hithean_linked_products',
        'hithean_linking_flavor',
        'hithean_linking_serving',
        'hithean_product_links_csv',
        'product_linking_list',
    ];
}

add_action('admin_post_hithean_product_linking_cleanup_legacy_meta', 'hithean_product_linking_cleanup_legacy_meta');
function hithean_product_linking_cleanup_legacy_meta()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'roneous'));
    }

    check_admin_referer('hithean_product_linking_cleanup_legacy_meta');

    global $wpdb;

    $keys = hithean_product_linking_legacy_meta_keys();
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
        $keys
    ));

    foreach ($keys as $key) {
        delete_metadata('post', 0, $key, '', true);
    }

    hithean_product_linking_clear_all_cache();
    add_settings_error(
        HITHEAN_PRODUCT_LINKING_OPTION_KEY,
        'legacy_meta_deleted',
        sprintf('Đã xóa %d custom field cũ của Liên kết sản phẩm.', $count),
        'updated'
    );
    set_transient('settings_errors', get_settings_errors(), 30);

    wp_safe_redirect(add_query_arg([
        'post_type' => 'product',
        'page'      => 'hithean-product-linking',
        'settings-updated' => 'true',
    ], admin_url('edit.php')));
    exit;
}

add_action('wp_ajax_hithean_product_linking_search', 'hithean_product_linking_ajax_product_search');
function hithean_product_linking_ajax_product_search()
{
    if (
        !current_user_can('manage_woocommerce')
        || !check_ajax_referer(HITHEAN_PRODUCT_LINKING_NONCE_ACTION, 'nonce', false)
    ) {
        wp_send_json_error([], 403);
    }

    $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
    $term = function_exists('mb_substr') ? mb_substr($term, 0, 100) : substr($term, 0, 100);

    if (strlen($term) < 2) {
        wp_send_json_success([]);
    }

    $query = new WP_Query([
        'post_type'              => ['product', 'product_variation'],
        'post_status'            => 'publish',
        'posts_per_page'         => 20,
        's'                      => $term,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);

    $results = [];
    $seen_product_ids = [];

    foreach ($query->posts as $post_id) {
        $product = wc_get_product($post_id);

        if (!$product instanceof WC_Product) {
            continue;
        }

        $parent_id = $product->get_parent_id();
        $product_id = $parent_id ? $parent_id : $post_id;
        $display_id = $parent_id ? $post_id : $product_id;

        if (isset($seen_product_ids[$product_id])) {
            continue;
        }

        $seen_product_ids[$product_id] = true;

        $results[] = [
            'id'    => $product_id,
            'name'  => $product->get_name(),
            'label' => sprintf('#%d - %s', $display_id, $product->get_formatted_name()),
        ];
    }

    wp_send_json_success($results);
}

function hithean_product_linking_render_global_builder_tool()
{
    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce(HITHEAN_PRODUCT_LINKING_NONCE_ACTION);

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

    <div class="hithean-product-linking-builder" data-ajax-url="<?php echo esc_url($ajax_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
        <h4>Tạo nhanh dòng sản phẩm liên kết</h4>
        <div class="hithean-product-linking-builder-grid">
            <div class="hithean-product-linking-field">
                <label>Group key</label>
                <input type="text" data-field="group" placeholder="yeast-hero">
            </div>
            <div class="hithean-product-linking-field">
                <label>Sản phẩm</label>
                <input type="text" data-product-linking-search placeholder="Gõ tên sản phẩm...">
                <input type="hidden" data-field="product_id">
                <div class="hithean-product-linking-search-results"></div>
            </div>
            <div class="hithean-product-linking-field">
                <label>Tên option 1</label>
                <input type="text" data-field="label_1" value="Hương vị">
            </div>
            <div class="hithean-product-linking-field">
                <label>value_1</label>
                <input type="text" data-field="flavor" placeholder="Giá trị option 1">
            </div>
            <div class="hithean-product-linking-field">
                <label>Tên option 2</label>
                <input type="text" data-field="label_2" value="Quy cách">
            </div>
            <div class="hithean-product-linking-field">
                <label>value_2</label>
                <input type="text" data-field="serving" placeholder="Giá trị option 2">
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
                const nonce = builder.getAttribute('data-nonce') || '';
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
                        fetch(ajaxUrl + '?action=hithean_product_linking_search&nonce=' + encodeURIComponent(nonce) + '&term=' + encodeURIComponent(term), {
                                credentials: 'same-origin'
                            })
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
                const textarea = document.getElementById('<?php echo esc_js(HITHEAN_PRODUCT_LINKING_OPTION_KEY); ?>');

                if (!textarea) {
                    alert('Không tìm thấy ô CSV sản phẩm liên kết.');
                    return;
                }

                const group = builder.querySelector('[data-field="group"]').value.trim();
                const label1 = builder.querySelector('[data-field="label_1"]').value.trim() || 'Hương vị';
                const label2 = builder.querySelector('[data-field="label_2"]').value.trim() || 'Quy cách';
                const productId = builder.querySelector('[data-field="product_id"]').value.trim();
                const flavor = builder.querySelector('[data-field="flavor"]').value.trim();
                const serving = builder.querySelector('[data-field="serving"]').value.trim();

                if (!group) {
                    alert('Cần nhập Group key trước khi thêm.');
                    return;
                }

                if (!productId) {
                    alert('Cần chọn sản phẩm trước khi thêm.');
                    return;
                }

                const line = [group, productId, label1, flavor, label2, serving].map(csvEscape).join(',');
                textarea.value = textarea.value.trim() ? textarea.value + "\n" + line : line;
                textarea.dispatchEvent(new Event('change'));

                builder.querySelector('[data-product-linking-search]').value = '';
                builder.querySelector('[data-field="product_id"]').value = '';
                builder.querySelector('[data-field="flavor"]').value = '';
                builder.querySelector('[data-field="serving"]').value = '';
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

    hithean_product_linking_clear_all_cache();
}
add_action('save_post_product', 'hithean_product_linking_clear_cache');

function hithean_product_linking_get_group_rows_by_product($product_id)
{
    $product_id = absint($product_id);
    $index = hithean_product_linking_get_global_index();
    $group_key = isset($index['by_product'][$product_id]) ? (string) $index['by_product'][$product_id] : '';

    if ('' !== $group_key && isset($index['by_group'][$group_key])) {
        return $index['by_group'][$group_key];
    }

    return [];
}

function hithean_product_linking_find_group_ids($product_id)
{
    $cache_key = 'group_ids_' . hithean_product_linking_cache_version() . '_' . absint($product_id);
    $cached = wp_cache_get($cache_key, HITHEAN_PRODUCT_LINKING_CACHE_GROUP);

    if (false !== $cached) {
        return $cached;
    }

    $csv_rows = hithean_product_linking_get_group_rows_by_product($product_id);

    if (empty($csv_rows)) {
        wp_cache_set($cache_key, [], HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);
        return [];
    }

    $group_ids = array_map('absint', wp_list_pluck($csv_rows, 'id'));
    $group_ids = array_values(array_unique(array_filter($group_ids)));

    wp_cache_set($cache_key, $group_ids, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $group_ids;
}

function hithean_product_linking_get_options($product_id)
{
    $cache_key = 'options_' . hithean_product_linking_cache_version() . '_' . absint($product_id);
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

    $csv_rows = hithean_product_linking_get_group_rows_by_product($product_id);
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
            'label_1'  => isset($row['label_1']) ? trim((string) $row['label_1']) : 'Hương vị',
            'label_2'  => isset($row['label_2']) ? trim((string) $row['label_2']) : 'Quy cách',
            'flavor'   => isset($row['flavor']) ? trim((string) $row['flavor']) : '',
            'serving'  => isset($row['serving']) ? trim((string) $row['serving']) : '',
            'in_stock' => $linked_product->is_in_stock(),
        ];
    }

    wp_cache_set($cache_key, $options, HITHEAN_PRODUCT_LINKING_CACHE_GROUP, HOUR_IN_SECONDS);

    return $options;
}

function hithean_product_linking_get_option_labels($options)
{
    foreach ($options as $option) {
        return [
            'flavor'  => $option['label_1'] !== '' ? $option['label_1'] : __('Hương vị', 'roneous'),
            'serving' => $option['label_2'] !== '' ? $option['label_2'] : __('Quy cách', 'roneous'),
        ];
    }

    return [
        'flavor'  => __('Hương vị', 'roneous'),
        'serving' => __('Quy cách', 'roneous'),
    ];
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
    static $rendered_product_ids = [];

    global $product;

    if (!$product instanceof WC_Product) {
        return;
    }

    $product_id = $product->get_id();

    if (isset($rendered_product_ids[$product_id])) {
        return;
    }

    $options = hithean_product_linking_get_options($product_id);

    if (empty($options)) {
        return;
    }

    $rendered_product_ids[$product_id] = true;

    echo '<section class="hithean-product-linking" aria-label="' . esc_attr__('Lựa chọn sản phẩm', 'roneous') . '">';
    $labels = hithean_product_linking_get_option_labels($options);
    hithean_product_linking_render_group($options, $product_id, 'flavor', $labels['flavor']);
    hithean_product_linking_render_group($options, $product_id, 'serving', $labels['serving']);
    echo '</section>';
}
add_action('woocommerce_before_add_to_cart_button', 'hithean_product_linking_render', 1);
add_action('woocommerce_single_product_summary', 'hithean_product_linking_render', 29);

function hithean_product_linking_enqueue_assets()
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }

    $product_id = get_queried_object_id();

    if (!$product_id || empty(hithean_product_linking_get_options($product_id))) {
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
    margin-bottom: 20px;
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
    font-weight: 600;
}

.hithean-product-linking .hithean-product-linking__option.is-active {
    background: var(--default-color-dark-blue);
    border-color: var(--default-color-dark-blue);
    color: var(--default-color-white);
    font-weight: 600;
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

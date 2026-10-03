<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode [product_info_search] — Tra thông tin & HDSD sản phẩm.
 * Port từ theanmarket theme. Tìm SP theo tên → card gồm tồn kho/HSD từng biến thể,
 * giá, mô tả ngắn, HDSD (meta product_info_hdsd) + nút copy để gửi khách.
 *
 * Danh sách gợi ý mặc định: attr default_ids="1,2,3" hoặc filter pis_default_product_ids;
 * để trống → 12 SP bán chạy nhất.
 */

// ==========================================================================
// 1. FRONTEND SCRIPTS & AJAX
// ==========================================================================

function pis_format_price_text($amount)
{
    if ($amount === '' || $amount === null) return '';

    $amount = (float) $amount;
    if ($amount <= 0) return '';

    $text = wp_strip_all_tags(wc_price($amount));
    $charset = get_bloginfo('charset') ?: 'UTF-8';
    return html_entity_decode($text, ENT_QUOTES, $charset);
}

function pis_variation_label_short($variation, $parent_name)
{
    if (!$variation) return 'Mặc định';

    $var_name_full  = $variation->get_name();
    $var_name_short = str_replace($parent_name . ' - ', '', $var_name_full);
    if ($var_name_short === $var_name_full) {
        $var_name_short = wc_get_formatted_variation($variation, true);
    }
    return $var_name_short ?: 'Mặc định';
}

function pis_stock_display($wc_product)
{
    if (!$wc_product) return '-';

    if (method_exists($wc_product, 'is_in_stock') && !$wc_product->is_in_stock()) {
        return 'Hết hàng';
    }

    if (method_exists($wc_product, 'managing_stock') && $wc_product->managing_stock()) {
        $qty = $wc_product->get_stock_quantity();
        if ($qty === null || $qty === '') return 'Còn hàng';
        return (string) (int) $qty;
    }

    return 'Còn hàng';
}

function pis_get_price_lines_for_product($product, $product_title)
{
    if (!$product) return [];

    // Variable products: group by (regular, current) and show which variations match.
    if ($product->is_type('variable')) {
        $groups = [];

        foreach ($product->get_children() as $vid) {
            $var = wc_get_product($vid);
            if (!$var) continue;
            if (method_exists($var, 'get_status') && $var->get_status() !== 'publish') continue;
            if (method_exists($var, 'variation_is_visible') && !$var->variation_is_visible()) continue;

            $regular_amount = $var->get_regular_price();
            $current_amount = $var->get_price();

            $regular_amount = ($regular_amount !== '' && $regular_amount !== null) ? (float) $regular_amount : (float) $current_amount;
            $current_amount = (float) $current_amount;

            $regular_key = wc_format_decimal($regular_amount, wc_get_price_decimals());
            $current_key = wc_format_decimal($current_amount, wc_get_price_decimals());
            $key = $regular_key . '|' . $current_key . '|' . ($var->is_on_sale() ? '1' : '0');

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'regular_amount' => $regular_amount,
                    'current_amount' => $current_amount,
                    'on_sale'        => (bool) $var->is_on_sale(),
                    'names'          => [],
                ];
            }

            $groups[$key]['names'][] = pis_variation_label_short($var, $product_title);
        }

        if (empty($groups)) return [];

        // Sort by regular amount (asc), then current amount (asc).
        uasort($groups, function ($a, $b) {
            if ($a['regular_amount'] === $b['regular_amount']) {
                return $a['current_amount'] <=> $b['current_amount'];
            }
            return $a['regular_amount'] <=> $b['regular_amount'];
        });

        $lines = [];
        foreach ($groups as $g) {
            $names = array_values(array_filter(array_map('trim', $g['names'])));
            $names = array_values(array_unique($names));
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);

            $variants_str = !empty($names) ? '(' . implode(', ', $names) . ')' : '';

            $regular_text = pis_format_price_text($g['regular_amount']);
            $current_text = pis_format_price_text($g['current_amount']);

            if ($g['on_sale'] && $regular_text && $current_text && $regular_text !== $current_text) {
                $line = $regular_text . ' sale còn ' . $current_text;
                if ($variants_str) $line .= "\n" . $variants_str;
                $lines[] = $line;
            } elseif ($current_text) {
                $line = $current_text;
                if ($variants_str) $line .= "\n" . $variants_str;
                $lines[] = $line;
            } elseif ($regular_text) {
                $line = $regular_text;
                if ($variants_str) $line .= "\n" . $variants_str;
                $lines[] = $line;
            }
        }

        return array_values(array_filter($lines));
    }

    // Simple / other products.
    $current_amount = (float) $product->get_price();
    $current_text   = pis_format_price_text($current_amount);

    if ($product->is_on_sale()) {
        $regular_amount = $product->get_regular_price();
        $regular_amount = ($regular_amount !== '' && $regular_amount !== null) ? (float) $regular_amount : $current_amount;
        $regular_text   = pis_format_price_text($regular_amount);

        if ($regular_text && $current_text && $regular_text !== $current_text) {
            return [$regular_text . ' sale còn ' . $current_text];
        }
    }

    return $current_text ? [$current_text] : [];
}

function pis_register_scripts()
{
    $js_path = get_stylesheet_directory() . '/js/product-info-search.js';
    $js_ver  = file_exists($js_path) ? filemtime($js_path) : null;

    wp_register_script(
        'product-info-search',
        get_stylesheet_directory_uri() . '/js/product-info-search.js',
        ['jquery'],
        $js_ver,
        true
    );

    wp_localize_script('product-info-search', 'product_info_params', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'i18n' => [
            'copied'     => 'Đã sao chép!',
            'copyFailed' => 'Không thể sao chép. Hãy thử thủ công.',
            'copyBtn'    => 'Copy giới thiệu',
            'copyManualBtn' => 'Copy HDSD', // Thêm nhãn cho nút mới
            'loading'    => 'Đang tải...',
            'noResult'   => 'Không tìm thấy sản phẩm nào.',
            'defaultTitle' => 'Sản phẩm gợi ý:',
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'pis_register_scripts');


function ajax_product_info_search()
{
    global $wpdb;

    $term = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
    // SP riêng tư chỉ trả về cho user có quyền xem (endpoint là nopriv).
    $exclude_private = (!empty($_REQUEST['exclude_private']) && $_REQUEST['exclude_private'] === 'true')
        || !current_user_can('read_private_products');
    $is_default_load = isset($_REQUEST['is_default']) && ($_REQUEST['is_default'] === 'true' || $_REQUEST['is_default'] === true);

    $default_ids = isset($_REQUEST['default_ids']) ? wp_unslash($_REQUEST['default_ids']) : '';
    $default_ids = array_values(array_filter(array_map('intval', explode(',', (string) $default_ids))));
    $default_ids = (array) apply_filters('pis_default_product_ids', $default_ids);
    if ($is_default_load && empty($default_ids)) {
        $default_ids = wc_get_products([
            'status'  => 'publish',
            'limit'   => 12,
            'orderby' => 'popularity',
            'return'  => 'ids',
        ]);
    }

    $rows = [];

    if ($term !== '') {
        $like = '%' . $wpdb->esc_like($term) . '%';
        $status_sql = $exclude_private ? "AND p.post_status = 'publish'" : "AND p.post_status IN ('publish','private')";

        $sql = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} AS p
            WHERE p.post_type = 'product'
              AND p.post_title LIKE %s
              $status_sql
            ORDER BY p.post_title ASC
            LIMIT 20
        ";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $like));
    } elseif ($is_default_load && !empty($default_ids)) {
        $default_ids = array_filter(array_map('intval', $default_ids));
        $ids_string = implode(',', $default_ids);
        $status_sql = $exclude_private ? "AND p.post_status = 'publish'" : "AND p.post_status IN ('publish','private')";

        // $ids_string is safe: all values cast to int above
        $sql = "
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} AS p
            WHERE p.ID IN ($ids_string)
              AND p.post_type = 'product'
            $status_sql
            ORDER BY FIELD(p.ID, $ids_string)
        ";
        $rows = $wpdb->get_results($sql);
    } else {
        wp_send_json([]);
    }

    $out = [];
    foreach ($rows as $p) {
        $product = wc_get_product($p->ID);
        if (!$product) continue;

        $thumb = get_the_post_thumbnail_url($p->ID, 'thumbnail') ?: '';
        $full_thumb = get_the_post_thumbnail_url($p->ID, 'medium') ?: '';
        $price = $product->get_price_html(); // Back-compat for older frontend.
        $price_lines = pis_get_price_lines_for_product($product, $p->post_title);
        $short = apply_filters('the_content', $product->get_short_description());
        $edit  = get_edit_post_link($p->ID);
        $url   = get_permalink($p->ID);

        $manual_raw  = get_post_meta($p->ID, 'product_info_hdsd', true);
        $manual_html = $manual_raw ? wp_kses_post($manual_raw) : '';

        $variants = [];
        if ($product->is_type('variable')) {
            $parent_name = $p->post_title;
            foreach ($product->get_children() as $vid) {
                $var = wc_get_product($vid);
                if (!$var) continue;
                if (method_exists($var, 'get_status') && $var->get_status() !== 'publish') continue;
                if (method_exists($var, 'variation_is_visible') && !$var->variation_is_visible()) continue;

                $var_name_short = pis_variation_label_short($var, $parent_name);

                $expiry = get_post_meta($vid, 'product_expiry_date_variation', true);
                $variants[] = [
                    'name'   => $var_name_short ?: 'Mặc định',
                    'stock'  => pis_stock_display($var),
                    'expiry' => $expiry ?: '-',
                ];
            }
        } else {
            $expiry = get_post_meta($p->ID, 'product_expiry_date', true);
            $variants[] = [
                'name'   => 'Đơn thể',
                'stock'  => pis_stock_display($product),
                'expiry' => $expiry ?: '-',
            ];
        }

        $out[] = [
            'id'        => (int) $p->ID,
            'label'     => $p->post_title,
            'url'       => $url,
            'thumb'     => $thumb,
            'image'     => $full_thumb,
            'price'     => $price,
            'price_lines' => $price_lines,
            'short'     => $short,
            'manual'    => $manual_html,
            'edit'      => $edit,
            'variants'  => $variants,
        ];
    }
    wp_send_json($out);
}

add_action('wp_ajax_product_info_search', 'ajax_product_info_search');
add_action('wp_ajax_nopriv_product_info_search', 'ajax_product_info_search');


// ==========================================================================
// 2. SHORTCODE UI
// ==========================================================================
function product_info_search_shortcode($atts)
{
    $atts = shortcode_atts([
        'exclude_private' => 'false',
        'default_ids'     => '',
        'title'           => 'Tra thông tin & HDSD sản phẩm',
    ], $atts, 'product_info_search');
    $exclude_private = ($atts['exclude_private'] === 'true') ? 'true' : 'false';
    $default_ids = implode(',', array_filter(array_map('intval', explode(',', $atts['default_ids']))));

    wp_enqueue_script('product-info-search');

    ob_start(); ?>
    <div class="shortcode-product-search-widget">
        <?php if ($atts['title'] !== '') : ?><h2><?php echo esc_html($atts['title']); ?></h2><?php endif; ?>

        <div class="product-info-search-bar" style="display:flex; flex-direction: column; gap:8px; justify-content: flex-start;">
            <input
                id="input-product-info-search"
                type="text"
                placeholder="Nhập tên sản phẩm cần tra cứu..."
                style="width: 100%; max-width: 500px; border-radius: 5px; border: 2px solid var(--default-color-green-dark, #00843d) !important;"
                data-exclude-private="<?php echo esc_attr($exclude_private); ?>"
                data-default-ids="<?php echo esc_attr($default_ids); ?>" />
            <button id="btn-product-info-search" type="button" class="button" style="width: fit-content;">
                Tìm sản phẩm
            </button>
        </div>

        <div id="product-info-dropdown" class="product-info-dropdown" style="display:none;"></div>

        <div id="product-info-default-area" style="margin-top: 20px;"></div>

        <div id="product-info-cards-area"></div>
    </div>

    <style>
        .shortcode-product-search-widget {
            position: relative;
        }

        .product-info-search-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
        }

        .product-info-search-bar input {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        /* Dropdown */
        .product-info-dropdown {
            position: absolute;
            left: 0;
            right: 0;
            z-index: 999;
            margin-top: 2px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .08);
            max-height: 340px;
            overflow: auto;
        }

        .product-info-dd-empty,
        .product-info-dd-loading {
            padding: 10px 12px;
            color: #555;
        }

        .product-info-dd-list {
            list-style: none;
            margin: 0;
            padding: 6px 0;
        }

        .product-info-dd-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }

        .product-info-dd-item:last-child {
            border-bottom: none;
        }

        .product-info-dd-item:hover {
            background: #f9fafb;
        }

        .product-info-dd-thumb {
            width: 36px;
            height: 36px;
            border-radius: 4px;
            object-fit: cover;
            background: #f3f4f6;
        }

        .product-info-dd-title {
            font-size: 14px;
            font-weight: 500;
        }

        /* Default List */
        .product-info-default-title {
            font-weight: bold;
            margin: 0 0 10px;
            color: #333;
            font-size: 15px;
            border-bottom: 2px solid #eee;
            display: inline-block;
            padding-bottom: 5px;
        }

        .product-info-default-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
        }

        .product-info-default-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .product-info-default-item:before {
            content: none !important;
        }

        .product-info-default-item:hover {
            border-color: var(--default-color-green-dark, #00843d);
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .product-info-default-thumb {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .product-info-default-label {
            font-size: 13px;
            font-weight: 600;
            color: #444;
            line-height: 1.3;
        }

        /* Cards */
        #product-info-cards-area {
            padding-top: 70px;
        }

        /* Card */
        .product-info-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .product-info-meta {
            display: flex;
            gap: 15px;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .product-info-meta img {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #eee;
        }

        .product-info-meta h3 {
            margin: 0 0 5px 0;
            font-size: 18px;
            line-height: 1.4;
        }

        .product-info-variants table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }

        .product-info-variants th {
            background: #f3f4f6;
            text-align: left;
            padding: 6px;
            font-weight: 600;
        }

        .product-info-variants td {
            border-bottom: 1px solid #eee;
            padding: 6px;
        }

        .product-info-section-title {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 5px;
            color: var(--default-color-green-dark, #00843d);
            text-transform: uppercase;
            font-size: 13px;
            padding-bottom: 3px;
        }

        .pis-price-lines {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .product-info-manual-box {
            background: #f9f9f9;
            padding: 10px;
            border-radius: 6px;
            border: 1px dashed #ccc;
            margin-top: 10px;
        }

        .product-info-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .btn--pink,
        .btn--green,
        .btn--black,
        .btn--blue {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            background: #f3f4f6;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .btn--green {
            background: #e6f9ef;
            border-color: #a7e3c0;
            color: #00843d;
        }

        .btn--green:hover {
            background: #d1f2e0;
        }

        .btn--black {
            background: #333;
            color: #fff;
            border-color: #111;
        }

        .btn--black:hover {
            background: #000;
        }

        .btn--pink {
            background: #fff0f5;
            border-color: #ffc0cb;
            color: #c71585;
        }

        .btn--pink:hover {
            background: #ffe4e1;
        }

        /* Style cho nút Copy HDSD màu xanh */
        .btn--blue {
            background: #e0f2fe;
            border-color: #7dd3fc;
            color: #0284c7;
        }

        .btn--blue:hover {
            background: #bae6fd;
        }

        .product-info-copied {
            color: #16a34a;
            font-size: 12px;
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
        }
    </style>

<?php
    return ob_get_clean();
}
add_shortcode('product_info_search', 'product_info_search_shortcode');

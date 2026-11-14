<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  SẢN PHẨM HẾT HÀNG Ở DANH SÁCH (LOOP)
  SP hết hàng và KHÔNG cho đặt trước = `_stock_status` = 'outofstock' (cho đặt trước thì Woo để 'onbackorder',
  cùng điều kiện hithean_offers_product_available()). Vị trí chọn ở Cài đặt ERP → WooCommerce
  (option `hithean_oos_loop_mode`):
    - 'last' (mặc định): xếp xuống cuối danh sách;
    - 'hide': ẩn khỏi danh sách (trang SP vẫn vào được bằng link).
  Áp cho: query chính của catalog (shop, danh mục, tag, thương hiệu, tìm SP — `wc_query` = product_query),
  shortcode WooCommerce [products …] (trang chủ, landing), SP liên quan (chế độ ẩn).
  Luôn nạp (module-loader $general_includes): hook query + form cài đặt.
\*---------------------------------------*/

const HITHEAN_OOS_LOOP_OPTION = 'hithean_oos_loop_mode';

function hithean_oos_loop_modes(): array
{
    return [
        'last' => 'Cho xuống cuối danh sách',
        'hide' => 'Ẩn khỏi danh sách',
    ];
}

function hithean_oos_loop_sanitize_mode($value): string
{
    $value = is_string($value) ? sanitize_key($value) : '';
    return array_key_exists($value, hithean_oos_loop_modes()) ? $value : 'last';
}

function hithean_oos_loop_mode(): string
{
    return hithean_oos_loop_sanitize_mode(get_option(HITHEAN_OOS_LOOP_OPTION, 'last'));
}

/**
 * Hàm thuần: thêm JOIN `_stock_status` + xếp cuối (last) hoặc lọc bỏ (hide) SP 'outofstock'.
 * SP không có meta `_stock_status` coi như còn hàng.
 */
function hithean_oos_loop_clauses(array $clauses, string $mode, string $posts_table, string $postmeta_table): array
{
    $alias = 'hithean_oos_pm';
    $join  = (string) ($clauses['join'] ?? '');
    if (strpos($join, " {$alias} ") === false) {
        $join .= " LEFT JOIN {$postmeta_table} AS {$alias} ON ({$posts_table}.ID = {$alias}.post_id AND {$alias}.meta_key = '_stock_status')";
    }
    $clauses['join'] = $join;

    if ($mode === 'hide') {
        $clauses['where'] = (string) ($clauses['where'] ?? '') . " AND ({$alias}.meta_value IS NULL OR {$alias}.meta_value <> 'outofstock')";
        return $clauses;
    }

    $order              = "CASE WHEN {$alias}.meta_value = 'outofstock' THEN 1 ELSE 0 END ASC";
    $orderby            = trim((string) ($clauses['orderby'] ?? ''));
    $clauses['orderby'] = $orderby !== '' ? $order . ', ' . $orderby : $order;
    return $clauses;
}

/** Query này có phải danh sách SP ở front (catalog chính hoặc shortcode Woo đã gắn cờ) không. */
function hithean_oos_loop_is_target_query($query): bool
{
    if (!($query instanceof WP_Query) || (is_admin() && !wp_doing_ajax())) {
        return false;
    }

    $post_type = $query->get('post_type');
    if (is_string($post_type) && $post_type !== '' && $post_type !== 'product') {
        return false;
    }
    if (is_array($post_type) && !in_array('product', $post_type, true)) {
        return false;
    }

    if ($query->get('hithean_oos_loop')) {
        return true;
    }

    return $query->is_main_query() && (string) $query->get('wc_query') === 'product_query';
}

add_filter('posts_clauses', static function ($clauses, $query) {
    if (!is_array($clauses) || !hithean_oos_loop_is_target_query($query)) {
        return $clauses;
    }
    global $wpdb;
    return hithean_oos_loop_clauses($clauses, hithean_oos_loop_mode(), $wpdb->posts, $wpdb->postmeta);
}, 20, 2);

// Shortcode [products …]: gắn cờ (kèm chế độ → khóa transient wc_product_loop_* đổi theo chế độ).
add_filter('woocommerce_shortcode_products_query', static function ($args) {
    if (is_array($args)) {
        $args['hithean_oos_loop'] = hithean_oos_loop_mode();
    }
    return $args;
}, 20);

// SP liên quan: Woo xáo ngẫu nhiên nên chỉ áp được chế độ ẩn.
add_filter('woocommerce_related_products', static function ($ids) {
    if (!is_array($ids) || hithean_oos_loop_mode() !== 'hide') {
        return $ids;
    }
    return array_values(array_filter($ids, static function ($id): bool {
        return get_post_meta((int) $id, '_stock_status', true) !== 'outofstock';
    }));
}, 20);

if (is_admin()) {
    add_action('admin_init', static function (): void {
        register_setting('hithean_oos_loop_group', HITHEAN_OOS_LOOP_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'hithean_oos_loop_sanitize_mode',
            'default'           => 'last',
        ]);
    });
}

// Trang danh sách được cache (WP Rocket) → đổi chế độ thì xóa cache để có hiệu lực ngay.
add_action('update_option_' . HITHEAN_OOS_LOOP_OPTION, static function ($old, $new): void {
    if ($old !== $new && function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
}, 10, 2);

/** Form ở Cài đặt ERP → WooCommerce. */
function hithean_oos_loop_render_settings(): void
{
    $mode = hithean_oos_loop_mode();
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('hithean_oos_loop_group'); ?>
        <h2>Sản phẩm hết hàng ở danh sách</h2>
        <p class="description">Áp cho sản phẩm <strong>hết hàng và không cho đặt trước</strong> ở trang cửa hàng, danh mục, tag, thương hiệu, tìm kiếm và shortcode <code>[products]</code> (trang chủ, landing). Trang chi tiết sản phẩm vẫn mở được bằng link.</p>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">Vị trí</th>
                    <td>
                        <?php foreach (hithean_oos_loop_modes() as $value => $label) : ?>
                            <label style="display:block;margin-bottom:6px">
                                <input type="radio" name="<?php echo esc_attr(HITHEAN_OOS_LOOP_OPTION); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($mode, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">“Ẩn” cũng bỏ sản phẩm đó khỏi mục Sản phẩm liên quan. Lưu sẽ xóa cache trang (WP Rocket) để có hiệu lực ngay.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php submit_button('Lưu vị trí sản phẩm hết hàng'); ?>
    </form>
    <hr>
    <?php
}

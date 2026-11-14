<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  SẢN PHẨM HẾT HÀNG Ở DANH SÁCH (LOOP)
  SP hết hàng và KHÔNG cho đặt trước = `_stock_status` = 'outofstock' (cho đặt trước thì Woo để 'onbackorder',
  cùng điều kiện hithean_offers_product_available()). Vị trí chọn ở Cài đặt ERP → WooCommerce
  (option `hithean_oos_loop_mode`):
    - 'last' (mặc định): xếp xuống cuối danh sách;
    - 'hide': ẩn khỏi danh sách (trang SP vẫn vào được bằng link).
  Ngoại lệ theo trang (option `hithean_oos_loop_rules` = [{path, mode}]): khớp đường dẫn trang đang xem (bỏ /page/N/,
  `*` cuối = cả nhánh) → đè chế độ chung. Shortcode `[products … het_hang="an|cuoi"]` đè cả hai cho riêng khối đó.
  Thứ tự: thuộc tính shortcode > ngoại lệ trang > chế độ chung.
  Áp cho: query chính của catalog (shop, danh mục, tag, thương hiệu, tìm SP — `wc_query` = product_query),
  shortcode WooCommerce [products …] (trang chủ, landing), SP liên quan (chế độ ẩn).
  Luôn nạp (module-loader $general_includes): hook query + form cài đặt.
\*---------------------------------------*/

const HITHEAN_OOS_LOOP_OPTION = 'hithean_oos_loop_mode';
const HITHEAN_OOS_LOOP_RULES_OPTION = 'hithean_oos_loop_rules';
const HITHEAN_OOS_LOOP_RULES_MAX = 100;

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

/** Hàm thuần: URL / đường dẫn → khóa so khớp ('' = trang chủ), bỏ query, /page/N/, giữ `*` cuối. */
function hithean_oos_loop_normalize_path(string $value): string
{
    $value = trim($value);
    if (preg_match('~^https?://~i', $value)) {
        $value = (string) parse_url($value, PHP_URL_PATH);
    }
    $value    = (string) preg_replace('~[?#].*$~', '', $value);
    $wildcard = substr(rtrim($value), -1) === '*';
    $value    = rawurldecode(rtrim($value, "* \t"));
    $value    = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value    = trim((string) preg_replace('~/+~', '/', $value), '/');
    $value    = trim((string) preg_replace('~(^|/)page/\d+$~', '', $value), '/');
    return $wildcard ? ($value === '' ? '*' : $value . '/*') : $value;
}

/** Hàm thuần: chế độ cho đường dẫn — khớp đúng trước, rồi nhánh `*` dài nhất, không khớp → mặc định. */
function hithean_oos_loop_mode_for_path(string $path, string $default, array $rules): string
{
    $path = hithean_oos_loop_normalize_path($path);
    $best = null; $best_len = -1;
    foreach ($rules as $rule) {
        $key  = (string) ($rule['path'] ?? '');
        $mode = (string) ($rule['mode'] ?? '');
        if (!isset(hithean_oos_loop_modes()[$mode])) {
            continue;
        }
        if ($key === $path) {
            return $mode;
        }
        if (substr($key, -1) === '*') {
            $prefix = rtrim(substr($key, 0, -1), '/');
            $hit    = $prefix === '' || $path === $prefix || strpos($path . '/', $prefix . '/') === 0;
            if ($hit && strlen($prefix) > $best_len) {
                $best = $mode; $best_len = strlen($prefix);
            }
        }
    }
    return $best ?? $default;
}

function hithean_oos_loop_sanitize_rules($input): array
{
    $out  = [];
    $seen = [];
    foreach (array_slice(is_array($input) ? $input : [], 0, HITHEAN_OOS_LOOP_RULES_MAX) as $row) {
        if (!is_array($row) || trim((string) ($row['path'] ?? '')) === '') {
            continue;
        }
        $path = hithean_oos_loop_normalize_path(sanitize_text_field((string) $row['path']));
        if (isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $out[] = ['path' => $path, 'mode' => hithean_oos_loop_sanitize_mode($row['mode'] ?? '')];
    }
    return $out;
}

function hithean_oos_loop_rules(): array
{
    $rules = get_option(HITHEAN_OOS_LOOP_RULES_OPTION, []);
    return is_array($rules) ? $rules : [];
}

/** Chế độ của trang đang xem (chế độ chung + ngoại lệ theo đường dẫn). */
function hithean_oos_loop_current_mode(): string
{
    $rules = hithean_oos_loop_rules();
    if (!$rules || wp_doing_ajax()) {
        return hithean_oos_loop_mode();
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    return hithean_oos_loop_mode_for_path($uri, hithean_oos_loop_mode(), $rules);
}

/** Thuộc tính het_hang của shortcode: an / hide → 'hide', cuoi / last → 'last', khác → ''. */
function hithean_oos_loop_attr_mode($value): string
{
    $value = sanitize_key((string) $value);
    return ['an' => 'hide', 'hide' => 'hide', 'cuoi' => 'last', 'last' => 'last'][$value] ?? '';
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

    if (isset(hithean_oos_loop_modes()[(string) $query->get('hithean_oos_loop')])) {
        return true;
    }

    return $query->is_main_query() && (string) $query->get('wc_query') === 'product_query';
}

add_filter('posts_clauses', static function ($clauses, $query) {
    if (!is_array($clauses) || !hithean_oos_loop_is_target_query($query)) {
        return $clauses;
    }
    global $wpdb;
    $mode = (string) $query->get('hithean_oos_loop');
    $mode = isset(hithean_oos_loop_modes()[$mode]) ? $mode : hithean_oos_loop_current_mode();
    return hithean_oos_loop_clauses($clauses, $mode, $wpdb->posts, $wpdb->postmeta);
}, 20, 2);

// [products het_hang="an|cuoi"]: Woo bỏ thuộc tính lạ → khai thêm vào danh sách thuộc tính.
add_filter('shortcode_atts_products', static function ($out, $pairs, $atts) {
    if (is_array($out) && is_array($atts) && isset($atts['het_hang'])) {
        $out['het_hang'] = (string) $atts['het_hang'];
    }
    return $out;
}, 10, 3);

// Shortcode [products …]: gắn cờ kèm chế độ (→ khóa transient wc_product_loop_* đổi theo chế độ).
add_filter('woocommerce_shortcode_products_query', static function ($args, $attributes = []) {
    if (is_array($args)) {
        $attr = hithean_oos_loop_attr_mode(is_array($attributes) ? ($attributes['het_hang'] ?? '') : '');
        $args['hithean_oos_loop'] = $attr !== '' ? $attr : hithean_oos_loop_current_mode();
    }
    return $args;
}, 20, 2);

// Dấu hiệu trên thẻ bọc khối [products] (class hithean-oos-last|hide) — kiểm tra nhanh trên site là filter đã chạy.
add_filter('shortcode_atts_products', static function ($out, $pairs, $atts) {
    if (is_array($out)) {
        $attr = hithean_oos_loop_attr_mode(is_array($atts) ? ($atts['het_hang'] ?? '') : '');
        $mode = $attr !== '' ? $attr : hithean_oos_loop_current_mode();
        $out['class'] = trim((string) ($out['class'] ?? '') . ' hithean-oos-' . $mode);
    }
    return $out;
}, 11, 3);

// SP liên quan: Woo xáo ngẫu nhiên nên chỉ áp được chế độ ẩn.
add_filter('woocommerce_related_products', static function ($ids) {
    if (!is_array($ids) || hithean_oos_loop_current_mode() !== 'hide') {
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
        register_setting('hithean_oos_loop_group', HITHEAN_OOS_LOOP_RULES_OPTION, [
            'type'              => 'array',
            'sanitize_callback' => 'hithean_oos_loop_sanitize_rules',
            'default'           => [],
        ]);
    });
}

// Trang danh sách được cache (WP Rocket) → đổi chế độ thì xóa cache để có hiệu lực ngay.
$hithean_oos_purge = static function ($old, $new): void {
    if ($old !== $new && function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
};
add_action('update_option_' . HITHEAN_OOS_LOOP_OPTION, $hithean_oos_purge, 10, 2);
add_action('update_option_' . HITHEAN_OOS_LOOP_RULES_OPTION, $hithean_oos_purge, 10, 2);
add_action('add_option_' . HITHEAN_OOS_LOOP_RULES_OPTION, static function ($option, $value) use ($hithean_oos_purge): void {
    $hithean_oos_purge([], $value);
}, 10, 2);

/** Form ở Cài đặt ERP → WooCommerce. */
function hithean_oos_loop_render_settings(): void
{
    $mode  = hithean_oos_loop_mode();
    $rules = hithean_oos_loop_rules();
    $name  = HITHEAN_OOS_LOOP_RULES_OPTION;
    $row   = static function ($index, array $rule) use ($name): void {
        $path = (string) ($rule['path'] ?? '');
        $shown = $path === '' && $index !== '__i__' ? '/' : ($path === '' ? '' : ($path === '*' ? '/*' : '/' . $path . (substr($path, -1) === '*' ? '' : '/')));
        ?>
        <tr data-hithean-oos-row>
            <td><input type="text" class="regular-text" placeholder="https://hithean.com/danh-muc-san-pham/protein/" name="<?php echo esc_attr($name . '[' . $index . '][path]'); ?>" value="<?php echo esc_attr($shown); ?>"></td>
            <td><select name="<?php echo esc_attr($name . '[' . $index . '][mode]'); ?>">
                <?php foreach (hithean_oos_loop_modes() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected((string) ($rule['mode'] ?? 'hide'), $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select></td>
            <td><button type="button" class="button-link button-link-delete" data-hithean-oos-del>Xóa</button></td>
        </tr>
        <?php
    };
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
                        <p class="description">Chế độ chung cho mọi trang. “Ẩn” cũng bỏ sản phẩm đó khỏi mục Sản phẩm liên quan. Lưu sẽ xóa cache trang (WP Rocket) để có hiệu lực ngay.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ngoại lệ theo trang</th>
                    <td>
                        <table class="widefat striped" style="max-width:760px">
                            <thead><tr><th>Link trang (hoặc đường dẫn)</th><th>Sản phẩm hết hàng</th><th></th></tr></thead>
                            <tbody data-hithean-oos-rows>
                                <?php foreach (array_values($rules) as $i => $rule) { $row($i, $rule); } ?>
                            </tbody>
                        </table>
                        <template data-hithean-oos-tpl><?php $row('__i__', ['path' => '', 'mode' => 'hide']); ?></template>
                        <p><button type="button" class="button" data-hithean-oos-add>+ Thêm trang</button></p>
                        <p class="description">Dán link trang chủ (<code>/</code>), landing, danh mục, tag, thương hiệu, trang tìm kiếm… — đè chế độ chung trên đúng trang đó (cả các trang phân trang /page/2/…). Thêm <code>*</code> ở cuối để áp cho cả nhánh, vd <code>/danh-muc-san-pham/protein/*</code> gồm cả danh mục con.<br>
                        Chỉnh riêng một khối sản phẩm: <code>[products … het_hang="an"]</code> (ẩn) hoặc <code>het_hang="cuoi"</code> (xuống cuối) — ưu tiên hơn cả ngoại lệ trang.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php submit_button('Lưu vị trí sản phẩm hết hàng'); ?>
    </form>
    <script>
    (function () {
        var body = document.querySelector('[data-hithean-oos-rows]');
        var tpl = document.querySelector('[data-hithean-oos-tpl]');
        var add = document.querySelector('[data-hithean-oos-add]');
        if (!body || !tpl || !add) return;
        var next = body.children.length;
        add.addEventListener('click', function () {
            body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(next++)));
            var input = body.lastElementChild && body.lastElementChild.querySelector('input');
            if (input) input.focus();
        });
        body.addEventListener('click', function (e) {
            var del = e.target.closest('[data-hithean-oos-del]');
            if (del) del.closest('[data-hithean-oos-row]').remove();
        });
    })();
    </script>
    <hr>
    <?php
}

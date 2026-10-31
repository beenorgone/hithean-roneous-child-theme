<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  LƯỢT BÁN THEO NHÃN HIỆU (Shopee + TikTok Shop + website)
  Quản lý tập trung ở Cài đặt ERP → tab "Lượt bán" (sales-count-admin.php):
  mỗi nhóm = 1 nhãn hiệu (VD Yeast Hero Protein) gồm nhiều sản phẩm
  (chọn tay và/hoặc theo taxonomy thương hiệu). Lượt bán của nhóm =
  tổng các nguồn Shopee + TikTok Shop (bóc tách từ link / ảnh / text hoặc nhập tay;
  mỗi nguồn gắn sàn 'shopee' | 'tiktok')
  + total_sales WooCommerce của các sản phẩm trong nhóm (tùy chọn).

  Hiển thị ở 4 vị trí, bật/tắt từng vị trí trong settings:
    - button : trong nút "Mua tại Shopee"
    - modal  : modal "Chọn kênh mua hàng" (tổng nhóm; từng store không hiện số riêng)
    - price  : dưới giá trên trang sản phẩm
    - loop   : thẻ sản phẩm ở trang danh mục / shop
  Shortcode [hithean_sales_count] in ở bất kỳ đâu, không phụ thuộc bật/tắt.

  File luôn nạp (nhẹ: chỉ đăng ký hook). Phần admin + AJAX bóc tách nằm ở
  sales-count-admin.php, chỉ require khi cần.
\*---------------------------------------*/

const HITHEAN_SC_OPTION        = 'hithean_sales_count';
const HITHEAN_SC_TOTALS_CACHE  = 'hithean_sc_totals_v2';
const HITHEAN_SC_BRAND_TAXONOMY = 'thuong-hieu';

function hithean_sc_positions(): array
{
    return [
        'button' => 'Trên nút "Mua tại Shopee"',
        'modal'  => 'Trong modal kênh bán (Shopee/TikTok)',
        'price'  => 'Dưới tiêu đề/giá trang sản phẩm',
        'loop'   => 'Thẻ sản phẩm (danh mục, shop)',
    ];
}

function hithean_sc_default_settings(): array
{
    return [
        'display'   => ['button' => '1', 'modal' => '1', 'price' => '1', 'loop' => '1'],
        'templates' => [
            // Nút + thẻ danh mục hẹp → câu ngắn; {count} = Shopee + TikTok + website.
            // Số sàn là cộng dồn nên ghi tháng cập nhật, không ghi "trong tháng".
            'button' => 'Đã bán {count} trên Shopee/TikTok',
            'modal'  => '{brand} đã bán {count} (cập nhật của tháng {thang})',
            'price'  => '🔥 {brand} đã bán {count} trên Shopee/TikTok (cập nhật của tháng {thang})',
            'loop'   => 'Đã bán {count} trên Shopee/TikTok',
        ],
        'min_count'  => 0,
        'stale_days' => 30,
        'groups'     => [],
    ];
}

function hithean_sc_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $defaults = hithean_sc_default_settings();
    $saved    = get_option(HITHEAN_SC_OPTION, []);
    $saved    = is_array($saved) ? $saved : [];

    $settings = array_replace($defaults, $saved);
    $settings['display']   = array_replace($defaults['display'], (array) ($saved['display'] ?? []));
    $settings['templates'] = array_replace($defaults['templates'], (array) ($saved['templates'] ?? []));
    $settings['groups']    = array_values((array) ($saved['groups'] ?? []));

    return $settings;
}

/**
 * Câu hiển thị cho 1 vị trí: câu riêng của nhãn hiệu (nếu có) > câu chung trong settings.
 * VD câu riêng: "{brand} đã bán {count} trên Shopee 3 tháng gần nhất (đại lý)".
 */
function hithean_sc_template(array $group, string $position): string
{
    $own = trim((string) ($group['templates'][$position] ?? ''));

    return $own !== '' ? $own : (string) (hithean_sc_settings()['templates'][$position] ?? '');
}

function hithean_sc_position_enabled(string $position): bool
{
    return !empty(hithean_sc_settings()['display'][$position]);
}

/*---------------------------------------*\
  SỐ LIỆU
\*---------------------------------------*/

/**
 * "2,3k" / "2.3k" / "10k+" / "1,2tr" / "1.2M" / "2.345" / "2,345" → int.
 * Có hậu tố (k, tr, m, nghìn...) thì dấu phẩy/chấm là phần thập phân;
 * không có hậu tố thì dấu phẩy/chấm là phân cách hàng nghìn.
 */
function hithean_sc_parse_number(string $raw): ?int
{
    $raw = mb_strtolower(trim($raw));
    if (!preg_match('/(\d[\d.,\s]*)\s*(k|nghìn|ngàn|tr|triệu|m)?/u', $raw, $m)) {
        return null;
    }

    $digits = preg_replace('/\s+/', '', $m[1]);
    $suffix = $m[2] ?? '';
    $multi  = ['k' => 1000, 'nghìn' => 1000, 'ngàn' => 1000, 'tr' => 1000000, 'triệu' => 1000000, 'm' => 1000000][$suffix] ?? 1;

    if ($multi > 1) {
        $number = (float) str_replace(',', '.', rtrim($digits, '.,'));
        return (int) round($number * $multi);
    }

    return (int) preg_replace('/\D/', '', $digits);
}

/** Kiểu Shopee: 999 → "999", 2345 → "2,3k", 1234567 → "1,2tr" (làm tròn xuống, không nói quá). */
function hithean_sc_format_count(int $count, string $format = 'short'): string
{
    if ($format === 'full' || $count < 1000) {
        return number_format_i18n($count);
    }

    [$base, $suffix] = $count >= 1000000 ? [1000000, 'tr'] : [1000, 'k'];
    $value = floor($count / $base * 10) / 10;

    return str_replace('.', ',', rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.')) . $suffix;
}

/** [shop_id, item_id] từ link sản phẩm Shopee đầy đủ (không resolve link rút gọn). */
function hithean_sc_shopee_ids(string $url): ?array
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if ($host === '' || strpos($host, 'shopee') === false) {
        return null;
    }

    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if (preg_match('/-i\.(\d+)\.(\d+)/', $path, $m) || preg_match('#/(\d{3,})/(\d{3,})(?:/|$)#', $path, $m)) {
        return [$m[1], $m[2]];
    }

    return null;
}

/** Sàn của 1 link: 'tiktok' nếu host TikTok, còn lại 'shopee'. */
function hithean_sc_link_platform(string $url): string
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

    return strpos($host, 'tiktok') !== false ? 'tiktok' : 'shopee';
}

function hithean_sc_source_platform(array $source): string
{
    return ($source['platform'] ?? '') === 'tiktok' ? 'tiktok' : 'shopee';
}

/** Khóa so khớp 1 link (nguồn trùng link trong nhóm — bookmarklet thay số thay vì thêm mới). */
function hithean_sc_link_key(string $url): string
{
    $ids = hithean_sc_shopee_ids($url);
    if ($ids) {
        return 'shopee:' . implode('.', $ids);
    }

    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $path = rtrim((string) wp_parse_url($url, PHP_URL_PATH), '/');

    return $host !== '' ? $host . $path : '';
}

/** ID sản phẩm thuộc nhóm: chọn tay + theo thương hiệu. */
function hithean_sc_group_product_ids(array $group): array
{
    $ids  = array_map('absint', (array) ($group['product_ids'] ?? []));
    $term = absint($group['brand_term'] ?? 0);

    if ($term && taxonomy_exists(HITHEAN_SC_BRAND_TAXONOMY)) {
        $ids = array_merge($ids, get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => [['taxonomy' => HITHEAN_SC_BRAND_TAXONOMY, 'terms' => $term]],
        ]));
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * Tổng từng nhóm: ['group_id' => ['shopee' => int, 'web' => int, 'total' => int, 'updated' => 'Y-m-d']].
 * 'updated' = ngày cập nhật mới nhất trong các nguồn — tháng của số liệu cho {thang}/{nam}.
 * Cache transient vài giờ (total_sales đổi theo đơn); xóa khi lưu settings.
 */
function hithean_sc_group_totals(): array
{
    static $totals = null;
    if ($totals !== null) {
        return $totals;
    }

    $cached = get_transient(HITHEAN_SC_TOTALS_CACHE);
    if (is_array($cached)) {
        return $totals = $cached;
    }

    $totals = [];
    foreach (hithean_sc_settings()['groups'] as $group) {
        $ecom    = ['shopee' => 0, 'tiktok' => 0];
        $updated = '';
        foreach ((array) ($group['sources'] ?? []) as $source) {
            $ecom[hithean_sc_source_platform((array) $source)] += absint($source['count'] ?? 0);
            $updated = max($updated, (string) ($source['updated'] ?? ''));
        }

        $web = 0;
        if (!empty($group['include_web'])) {
            foreach (hithean_sc_group_product_ids($group) as $product_id) {
                $web += absint(get_post_meta($product_id, 'total_sales', true));
            }
        }

        $totals[(string) $group['id']] = [
            'shopee' => $ecom['shopee'],
            'tiktok' => $ecom['tiktok'],
            'ecom'   => $ecom['shopee'] + $ecom['tiktok'],
            'web'    => $web,
            'total'   => $ecom['shopee'] + $ecom['tiktok'] + $web,
            'updated' => $updated,
        ];
    }

    set_transient(HITHEAN_SC_TOTALS_CACHE, $totals, 3 * HOUR_IN_SECONDS);

    return $totals;
}

function hithean_sc_get_group(string $group_id): ?array
{
    foreach (hithean_sc_settings()['groups'] as $group) {
        if ((string) ($group['id'] ?? '') === $group_id) {
            return $group;
        }
    }

    return null;
}

/** Nhóm đầu tiên chứa sản phẩm (chọn tay hoặc cùng thương hiệu). */
function hithean_sc_group_for_product(int $product_id): ?array
{
    static $cache = [];
    if (array_key_exists($product_id, $cache)) {
        return $cache[$product_id];
    }

    foreach (hithean_sc_settings()['groups'] as $group) {
        $ids  = array_map('absint', (array) ($group['product_ids'] ?? []));
        $term = absint($group['brand_term'] ?? 0);
        if (in_array($product_id, $ids, true) || ($term && has_term($term, HITHEAN_SC_BRAND_TAXONOMY, $product_id))) {
            return $cache[$product_id] = $group;
        }
    }

    return $cache[$product_id] = null;
}

/**
 * HTML 1 dòng lượt bán; '' nếu không có nhóm / dưới ngưỡng.
 * Template: {count} {brand} {shopee} {tiktok} {san} (Shopee + TikTok) {web} {thang} {nam} — các biến số được in đậm.
 * {thang}/{nam}: tháng/năm của số liệu = $date (ngày cập nhật 1 nguồn) hoặc ngày cập nhật mới nhất
 * của nhóm; chưa có ngày thì lấy tháng hiện tại.
 */
function hithean_sc_render_line(array $group, string $template, string $context, string $format = 'short', ?int $count = null, string $date = ''): string
{
    $totals = array_replace(
        ['shopee' => 0, 'tiktok' => 0, 'ecom' => 0, 'web' => 0, 'total' => 0, 'updated' => ''],
        hithean_sc_group_totals()[(string) $group['id']] ?? []
    );
    $count  = $count ?? (int) $totals['total'];
    $time   = strtotime($date ?: (string) $totals['updated']) ?: (int) current_time('timestamp');

    if ($count <= 0 || $count < absint(hithean_sc_settings()['min_count'])) {
        return '';
    }

    $html = strtr(esc_html($template), [
        '{count}'  => '<strong>' . esc_html(hithean_sc_format_count($count, $format)) . '</strong>',
        '{brand}'  => esc_html((string) $group['name']),
        '{shopee}' => '<strong>' . esc_html(hithean_sc_format_count((int) $totals['shopee'], $format)) . '</strong>',
        '{tiktok}' => '<strong>' . esc_html(hithean_sc_format_count((int) $totals['tiktok'], $format)) . '</strong>',
        '{san}'    => '<strong>' . esc_html(hithean_sc_format_count((int) $totals['ecom'], $format)) . '</strong>',
        '{web}'    => '<strong>' . esc_html(hithean_sc_format_count((int) $totals['web'], $format)) . '</strong>',
        '{thang}'  => gmdate('n', $time),
        '{nam}'    => gmdate('Y', $time),
    ]);

    hithean_sc_print_styles();

    return '<span class="hithean-sales-count hithean-sales-count--' . esc_attr($context) . '">' . $html . '</span>';
}

function hithean_sc_print_styles(): void
{
    static $printed = false;
    if ($printed) {
        return;
    }
    $printed = true;
    ?>
    <style>
    .hithean-sales-count { display: block; font-size: 13px; line-height: 1.4; color: #555; }
    .hithean-sales-count strong { color: #ee4d2d; }
    .hithean-sales-count--button { margin-top: 2px; font-size: 12px; font-weight: 400; color: rgba(255, 255, 255, .92); }
    .hithean-sales-count--button strong { color: inherit; }
    .hithean-sales-count--price { margin: 4px 0 12px; }
    .hithean-sales-count--modal { margin: -8px 0 12px; }
    .hithean-sales-count--loop { margin: 2px 0 6px; font-size: 12px; }
    </style>
    <?php
}

/*---------------------------------------*\
  HIỂN THỊ
\*---------------------------------------*/

function hithean_sc_current_product_id(): int
{
    global $product;

    return $product instanceof WC_Product ? $product->get_id() : 0;
}

function hithean_sc_echo_position(string $position, int $product_id = 0): void
{
    if (!hithean_sc_position_enabled($position)) {
        return;
    }

    $group = hithean_sc_group_for_product($product_id ?: hithean_sc_current_product_id());
    if ($group) {
        echo hithean_sc_render_line($group, hithean_sc_template($group, $position), $position); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in hithean_sc_render_line().
    }
}

// Hook trong nút / modal nằm ở product-page.php (hithean_render_ecom_buy_button).
add_action('hithean_ecom_buy_button_inner', static function (int $product_id): void {
    hithean_sc_echo_position('button', $product_id);
});

add_action('hithean_ecom_modal_after_desc', static function (int $product_id): void {
    hithean_sc_echo_position('modal', $product_id);
});

// Sau giá (woocommerce_template_single_price = 10), trước mô tả ngắn (20).
add_action('woocommerce_single_product_summary', static function (): void {
    hithean_sc_echo_position('price');
}, 11);

// Sau giá (10), "Hết hàng" (11), subheading (12) trên thẻ sản phẩm.
add_action('woocommerce_after_shop_loop_item_title', static function (): void {
    hithean_sc_echo_position('loop');
}, 13);

/**
 * [hithean_sales_count group="yeast-hero-protein" product_id="123" template="Đã bán {count}" format="short|full" source="total|san|shopee|tiktok|web" class=""]
 * Không truyền group/product_id → nhóm của sản phẩm hiện tại.
 * Không truyền template → câu "Dưới tiêu đề/giá" của nhãn hiệu (riêng hoặc chung).
 */
add_shortcode('hithean_sales_count', static function ($atts): string {
    $atts = shortcode_atts([
        'group'      => '',
        'product_id' => 0,
        'template'   => '',
        'format'     => 'short',
        'source'     => 'total',
        'class'      => '',
    ], $atts, 'hithean_sales_count');

    $group = $atts['group'] !== ''
        ? hithean_sc_get_group(sanitize_title($atts['group']))
        : hithean_sc_group_for_product(absint($atts['product_id']) ?: (hithean_sc_current_product_id() ?: (int) get_queried_object_id()));
    if (!$group) {
        return '';
    }

    $totals = hithean_sc_group_totals()[(string) $group['id']] ?? [];
    $source = ['san' => 'ecom'][$atts['source']] ?? $atts['source'];
    $source = in_array($source, ['shopee', 'tiktok', 'ecom', 'web'], true) ? $source : 'total';
    $tpl    = trim((string) $atts['template']) !== '' ? (string) $atts['template'] : hithean_sc_template($group, 'price');
    $html   = hithean_sc_render_line($group, $tpl, 'shortcode', $atts['format'] === 'full' ? 'full' : 'short', (int) ($totals[$source] ?? 0));

    if ($html !== '' && $atts['class'] !== '') {
        $html = '<span class="' . esc_attr($atts['class']) . '">' . $html . '</span>';
    }

    return $html;
});

/*---------------------------------------*\
  ADMIN — chỉ đăng ký hook, code nằm ở sales-count-admin.php
\*---------------------------------------*/

function hithean_sc_require_admin(): void
{
    require_once __DIR__ . '/sales-count-admin.php';
}

add_filter('theme_ai_feature_registry', static function (array $features): array {
    $features['sales_count_ai_extract'] = [
        'label'       => 'Lượt bán — AI bóc tách từ ảnh/text',
        'description' => 'Tab "Lượt bán" (Cài đặt ERP): đọc số "Đã bán" từ ảnh chụp hoặc text copy từ Shopee / TikTok Shop để cộng vào nhãn hiệu.',
    ];
    return $features;
});

add_action('wp_ajax_hithean_sc_extract', static function (): void {
    hithean_sc_require_admin();
    hithean_sc_ajax_extract();
});

add_action('wp_ajax_hithean_sc_preview_shortcode', static function (): void {
    hithean_sc_require_admin();
    hithean_sc_ajax_preview_shortcode();
});

// Bookmarklet: popup chọn nhãn hiệu (GET) + lưu (POST) qua admin-post.php.
add_action('admin_post_hithean_sc_capture', static function (): void {
    hithean_sc_require_admin();
    hithean_sc_capture_page();
});
add_action('admin_post_nopriv_hithean_sc_capture', 'auth_redirect');
add_action('admin_post_hithean_sc_capture_save', static function (): void {
    hithean_sc_require_admin();
    hithean_sc_capture_save();
});

add_filter('ivar_shortcodes_guide', static function (array $guides): array {
    $guides['hithean_sales_count'] = [
        'name'    => '[hithean_sales_count]',
        'summary' => 'In lượt bán của 1 nhãn hiệu (nhóm sản phẩm) khai báo ở <strong>Cài đặt ERP &gt; Lượt bán</strong>: tổng các nguồn Shopee + TikTok Shop + lượt bán website (nếu nhóm bật). Dưới ngưỡng tối thiểu thì không in gì.',
        'args'    => [
            ['group', 'Slug nhóm (cột "Slug" trong tab Lượt bán). Bỏ trống = nhóm chứa sản phẩm hiện tại / <code>product_id</code>.', 'rỗng'],
            ['product_id', 'Lấy nhóm chứa sản phẩm này.', 'sản phẩm hiện tại'],
            ['template', 'Câu hiển thị. Biến: <code>{count}</code>, <code>{brand}</code>, <code>{shopee}</code>, <code>{tiktok}</code>, <code>{san}</code> (Shopee + TikTok), <code>{web}</code>, <code>{thang}</code> / <code>{nam}</code> (tháng / năm cập nhật số liệu gần nhất).', 'câu "Dưới tiêu đề/giá" của nhãn hiệu'],
            ['format', '<code>short</code> kiểu Shopee (2,3k · 1,2tr) hoặc <code>full</code> (2.345).', 'short'],
            ['source', 'Số dùng cho <code>{count}</code>: <code>total</code> (tất cả), <code>san</code> (Shopee + TikTok), <code>shopee</code>, <code>tiktok</code> hoặc <code>web</code>.', 'total'],
            ['class', 'Class thêm vào thẻ bọc ngoài.', '—'],
        ],
        'examples' => [
            ['Trên trang sản phẩm', '[hithean_sales_count]'],
            ['Landing page theo nhóm', '[hithean_sales_count group="yeast-hero-protein" template="Hơn {count} hũ {brand} đã đến tay khách hàng"]'],
            ['Chỉ số Shopee, đủ số', '[hithean_sales_count group="yeast-hero-protein" source="shopee" format="full"]'],
        ],
        'notes' => [
            'Shortcode luôn in được, không phụ thuộc 4 công tắc hiển thị trong tab Lượt bán.',
        ],
    ];
    return $guides;
});

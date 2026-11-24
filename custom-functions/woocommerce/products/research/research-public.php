<?php
/**
 * Hồ sơ khoa học — trang khách: nút "Hồ sơ khoa học liên quan" + popup + slider; shortcode cho landing.
 *
 * Nạp bởi module `research_public` (module-loader): trang SP, danh mục SP, landing anc_landing, trang có [hithean_research].
 * - Trang SP: nút ở hithean_before_product_chat_ctas (12, sau Hồ sơ pháp lý), slider ở woocommerce_after_single_product_summary (15).
 * - Danh mục: nút dưới mô tả danh mục, slider sau lưới SP.
 * - Shortcode: [hithean_research layout="both|button|slider" product="ID" cat="ID|slug" page="ID"] — mặc định trang hiện tại.
 * Toàn bộ HTML in sẵn (WP Rocket cache được); JS chỉ mở popup / gạt ngôn ngữ / slider / tải video khi bấm.
 * Không có tài liệu đã đăng → không in gì, không nạp CSS/JS.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/research-core.php';

/**
 * Ngữ cảnh tự hiện của trang đang xem (SP / danh mục) hoặc null.
 * Trang / landing chỉ hiện qua shortcode [hithean_research] (HTML landing tự chọn chỗ đặt) — shortcode tự nạp CSS/JS.
 */
function hithean_research_current_context(): ?array
{
    if (function_exists('is_product') && is_product()) {
        return hithean_research_context_for_product((int) get_queried_object_id());
    }
    if (function_exists('is_product_category') && is_product_category()) {
        return hithean_research_context_for_term((int) get_queried_object_id());
    }
    return null;
}

/** Tài liệu của trang hiện tại (nhớ trong request). */
function hithean_research_current_rows(): array
{
    static $rows = null;
    if ($rows === null) {
        $context = hithean_research_current_context();
        $rows = $context ? hithean_research_for_context($context) : [];
    }
    return $rows;
}

/** Popup cần in ở footer: dialog_id => [rows, subtitle]. */
function hithean_research_queue_dialog(string $dialog_id, array $rows, string $subtitle): void
{
    $GLOBALS['hithean_research_dialogs'][$dialog_id] = [$rows, $subtitle];
    hithean_research_enqueue();
}

function hithean_research_enqueue(): void
{
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    $css = '/custom-functions/woocommerce/products/research/assets/research.css';
    $js  = '/custom-functions/woocommerce/products/research/assets/research.js';
    wp_enqueue_style('hithean-research', $uri . $css, [], (string) @filemtime($dir . $css));
    wp_enqueue_script('hithean-research', $uri . $js, [], (string) @filemtime($dir . $js), ['in_footer' => true, 'strategy' => 'defer']);
}

add_action('wp_enqueue_scripts', static function (): void {
    if (hithean_research_current_rows()) {
        hithean_research_enqueue();
    }
}, 30);

add_action('wp_footer', static function (): void {
    foreach ((array) ($GLOBALS['hithean_research_dialogs'] ?? []) as $dialog_id => [$rows, $subtitle]) {
        echo hithean_research_dialog_html($rows, (string) $dialog_id, (string) $subtitle); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
    }
}, 20);

/* ---------- Trang SP ---------- */

/** In nút ở trang SP một lần (CSS order trong research.css đặt nút ngay sau Hồ sơ pháp lý). */
function hithean_research_print_product_trigger(): void
{
    static $printed = false;
    if ($printed || !function_exists('is_product') || !is_product()) {
        return;
    }
    $rows = hithean_research_current_rows();
    if (!$rows) {
        return;
    }
    $printed = true;
    hithean_research_queue_dialog('hithean-research-dialog', $rows, (string) get_the_title(get_queried_object_id()));
    echo hithean_research_trigger_html($rows, 'hithean-research-dialog'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
}

// SP đơn giản: cạnh nút Hồ sơ pháp lý. SP biến thể / không mua được không có hook này (template simple.php mới include
// product-ctas.php) → in bù ở cuối summary.
add_action('hithean_before_product_chat_ctas', 'hithean_research_print_product_trigger', 12, 0);
add_action('woocommerce_single_product_summary', 'hithean_research_print_product_trigger', 99, 0);

add_action('woocommerce_after_single_product_summary', static function (): void {
    $rows = hithean_research_current_rows();
    if (!$rows || !is_product()) {
        return;
    }
    hithean_research_queue_dialog('hithean-research-dialog', $rows, (string) get_the_title(get_queried_object_id()));
    echo hithean_research_slider_html($rows, 'hithean-research-dialog'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
}, 15);

/* ---------- Danh mục ---------- */

add_action('woocommerce_archive_description', static function (): void {
    if (!function_exists('is_product_category') || !is_product_category()) {
        return;
    }
    $rows = hithean_research_current_rows();
    if (!$rows) {
        return;
    }
    hithean_research_queue_dialog('hithean-research-dialog', $rows, single_term_title('', false));
    echo '<div class="hithean-research-archive">' . hithean_research_trigger_html($rows, 'hithean-research-dialog') . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
}, 20);

add_action('woocommerce_after_shop_loop', static function (): void {
    if (!function_exists('is_product_category') || !is_product_category()) {
        return;
    }
    $rows = hithean_research_current_rows();
    if ($rows) {
        hithean_research_queue_dialog('hithean-research-dialog', $rows, single_term_title('', false));
        echo hithean_research_slider_html($rows, 'hithean-research-dialog'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
    }
}, 20);

/* ---------- Shortcode (landing) ---------- */

add_shortcode('hithean_research', static function ($atts): string {
    $atts = shortcode_atts(['layout' => 'both', 'product' => '', 'cat' => '', 'page' => ''], (array) $atts, 'hithean_research');
    if ($atts['product'] !== '') {
        $context = hithean_research_context_for_product((int) $atts['product']);
        $label   = get_the_title((int) $atts['product']);
    } elseif ($atts['cat'] !== '') {
        $term = is_numeric($atts['cat']) ? get_term((int) $atts['cat'], 'product_cat') : get_term_by('slug', sanitize_title($atts['cat']), 'product_cat');
        if (!$term instanceof WP_Term) {
            return '';
        }
        $context = hithean_research_context_for_term((int) $term->term_id);
        $label   = $term->name;
    } else {
        $page_id = $atts['page'] !== '' ? (int) $atts['page'] : (int) get_queried_object_id();
        $context = hithean_research_context_for_page($page_id);
        $label   = get_the_title($page_id);
    }
    $rows = hithean_research_for_context($context);
    if (!$rows) {
        return '';
    }
    $dialog_id = 'hithean-research-dialog-' . substr(md5(wp_json_encode($context)), 0, 8);
    hithean_research_queue_dialog($dialog_id, $rows, (string) $label);
    $html = '';
    if ($atts['layout'] !== 'slider') {
        $html .= '<div class="hithean-research-inline">' . hithean_research_trigger_html($rows, $dialog_id) . '</div>';
    }
    if ($atts['layout'] !== 'button') {
        $html .= hithean_research_slider_html($rows, $dialog_id);
    }
    return $html;
});

<?php
/**
 * Nút "Ưu đãi đang áp dụng" trên thẻ sản phẩm ở danh sách (product loop) — bản gọn của nút trang chi tiết.
 * MỖI sản phẩm 1 nút; bấm tải nguồn plugin từ trang sản phẩm rồi dựng popup bằng product-offers.js
 * dùng chung với trang chi tiết (cache bộ nhớ 1 phút). Snapshot /uu-dai/ chỉ dùng làm nhãn gọn trên thẻ. Vị trí chọn ở Ưu đãi sản phẩm → Cấu hình & xuất bản (settings 'loop_button'):
 *   a = nhãn nổi góc ảnh · b = dải 1 dòng dưới giá (thay khung vàng product-addon-promo) · c = nhãn nhỏ cạnh giá.
 * Máy tính ('loop_button', mặc định c) và điện thoại ≤767px ('loop_button_mobile', mặc định b) chọn riêng: trang danh sách có thể cache
 * nên khi 2 vị trí khác nhau thì in CẢ HAI nút, CSS ẩn theo bề rộng màn hình (.hlo-d = chỉ máy tính, .hlo-m = chỉ điện thoại).
 * Chỉ hiện khi snapshot đã công khai (hoặc người quản lý đang xem trước) — hithean_loop_offers_enabled().
 */
defined('ABSPATH') || exit;

/** Vị trí nút đã chọn: a | b | c — máy tính mặc định c, điện thoại ($mobile) mặc định b. */
function hithean_loop_offers_position(bool $mobile = false): string {
    $default = $mobile ? 'b' : 'c';
    $position = (string) (hithean_offers_settings()[$mobile ? 'loop_button_mobile' : 'loop_button'] ?? $default);
    return in_array($position, ['a', 'b', 'c'], true) ? $position : $default;
}

/** Lớp ẩn / hiện theo thiết bị của nút ở vị trí $position: null = không in ở vị trí này, '' = in cho mọi thiết bị (hàm thuần). */
function hithean_loop_offers_device_class(string $position, string $desktop, string $mobile): ?string {
    if ($position !== $desktop && $position !== $mobile) { return null; }
    return $desktop === $mobile ? '' : ($position === $desktop ? 'hlo-d' : 'hlo-m');
}

/** Nút chỉ hiện khi khách xem được snapshot ưu đãi (cùng điều kiện trang /uu-dai/). */
function hithean_loop_offers_enabled(): bool {
    static $enabled = null;
    if ($enabled === null) { $enabled = !hithean_offers_settings()['preview'] || current_user_can('manage_woocommerce'); }
    return $enabled;
}

/** Dòng snapshot đang hiệu lực của 1 sản phẩm (cùng cách gom của /uu-dai/ chế độ "Theo sản phẩm"); chỉ đọc snapshot 1 lần / request. */
function hithean_loop_offers_rows(int $product_id): array {
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (hithean_offers_visible_items() as $row) {
            if (empty($row['product_id']) || empty($row['product_name'])) { continue; }
            if (($row['family'] ?? '') === 'wheel') {
                if (($row['source_id'] ?? '') !== 'wheel:program') { continue; }
                $row['title'] = 'Vòng quay may mắn'; $row['condition_note'] = '';
            }
            $index[(int) $row['product_id']][] = $row;
        }
    }
    return $index[$product_id] ?? [];
}

/** Nhãn nút theo số ưu đãi riêng của sản phẩm (hàm thuần): [label, aria, summary]. */
function hithean_loop_offers_labels(int $count, string $first_title = ''): array {
    if ($count < 1) { return ['Xem ưu đãi', 'Xem ưu đãi của cửa hàng', 'Xem chương trình của cửa hàng']; }
    $first_title = trim(preg_replace('/\s+/u', ' ', $first_title));
    return [$count . ' ưu đãi', 'Xem ' . $count . ' ưu đãi đang áp dụng', $first_title !== '' ? $first_title : 'Đang áp dụng'];
}

/** HTML nút của 1 sản phẩm theo vị trí. a / c nằm TRONG link sản phẩm nên dùng span role=button, b là button thật. */
function hithean_loop_offers_button_html(int $product_id, string $position, string $device = ''): string {
    $device = $device !== '' ? ' ' . $device : '';
    $cards = hithean_offer_cards_from_rows(hithean_loop_offers_rows($product_id));
    $count = count($cards);
    [$label, $aria, $summary] = hithean_loop_offers_labels($count, (string) ($cards[0]['title'] ?? ''));
    $attrs = ' data-hlo="' . $product_id . '" data-hlo-url="' . esc_url(get_permalink($product_id)) . '" aria-haspopup="dialog" aria-label="' . esc_attr($aria) . '"';
    $star = '<span class="hlo__star" aria-hidden="true">✳</span>';
    if ($position === 'b') {
        return '<button type="button" class="hlo hlo--b' . $device . '"' . $attrs . '>' . $star . '<span class="hlo__label">Ưu đãi</span><span class="hlo__text">' . esc_html($summary) . '</span>'
            . ($count > 1 ? '<span class="hlo__count">' . $count . '</span>' : '') . '<span class="hlo__more" aria-hidden="true">›</span></button>';
    }
    $chip = '<span class="hlo hlo--' . $position . ($position === 'a' ? '' : $device) . '" role="button" tabindex="0"' . $attrs . '>' . $star . esc_html($label) . ($position === 'c' ? '<span class="hlo__more" aria-hidden="true"> ›</span>' : '') . '</span>';
    return $position === 'a' ? '<span class="hlo-anchor' . $device . '">' . $chip . '</span>' : $chip;
}

/** In nút ở hook của vị trí đang chọn; lần đầu in CSS (nhỏ, tránh nháy) + xếp JS / CSS thẻ ưu đãi cho footer. */
function hithean_loop_offers_render(string $position): void {
    global $product;
    if (!$product instanceof WC_Product || !hithean_loop_offers_enabled()) { return; }
    $device = hithean_loop_offers_device_class($position, hithean_loop_offers_position(), hithean_loop_offers_position(true));
    if ($device === null) { return; }
    static $assets = false;
    if (!$assets) {
        $assets = true;
        $css = HITHEAN_THEME_DIR . '/custom-functions/marketing/assets/loop-offers.css';
        echo '<style id="hithean-loop-offers">' . trim((string) @file_get_contents($css)) . '</style>';
        $js = '/custom-functions/marketing/assets/loop-offers.js';
        wp_enqueue_style('hithean-offer-card');
        $shared = '/custom-functions/marketing/assets/product-offers.js';
        wp_enqueue_script('hithean-product-offers', get_stylesheet_directory_uri() . $shared, ['jquery'], (string) filemtime(HITHEAN_THEME_DIR . $shared), true);
        wp_enqueue_script('hithean-loop-offers', get_stylesheet_directory_uri() . $js, ['hithean-product-offers'], (string) @filemtime(HITHEAN_THEME_DIR . $js), true);
    }
    echo hithean_loop_offers_button_html($product->get_id(), $position, $device); // phpcs:ignore WordPress.Security.EscapeOutput
}
// a: ngay sau ảnh (thumbnail = 10) · c: ngay sau giá (price = 10, "Hết hàng" = 11) · b: sau link SP (5), trước nút mua (10).
add_action('woocommerce_before_shop_loop_item_title', static function (): void { hithean_loop_offers_render('a'); }, 11);
add_action('woocommerce_after_shop_loop_item_title', static function (): void { hithean_loop_offers_render('c'); }, 11);
add_action('woocommerce_after_shop_loop_item', static function (): void { hithean_loop_offers_render('b'); }, 7);

/**
 * AJAX: nội dung popup của 1 sản phẩm. Chỉ đọc, không nonce (trang danh sách cache). SP chưa có ưu đãi riêng →
 * "Ưu đãi tiêu biểu" của cửa hàng (hithean_sfm_featured_cards — action này khai ở module site_footer_menu để file đó được nạp).
 */
function hithean_loop_offers_ajax(): void {
    $product = function_exists('wc_get_product') ? wc_get_product(absint($_GET['product_id'] ?? 0)) : null;
    if (!$product || $product->get_status() !== 'publish' || !hithean_loop_offers_enabled()) { wp_send_json_error(['message' => 'Không tìm thấy sản phẩm.'], 404); }
    $cards = hithean_offer_cards_from_rows(hithean_loop_offers_rows($product->get_id()));
    $own = count($cards);
    if (!$own && function_exists('hithean_sfm_featured_cards')) { $cards = hithean_sfm_featured_cards(); }
    $page = get_page_by_path('uu-dai');
    wp_send_json_success([
        'own' => $own,
        'meta' => $own ? $own . ' chương trình cho ' . $product->get_name() : ($cards ? 'Sản phẩm này chưa có ưu đãi riêng — ưu đãi tiêu biểu của cửa hàng' : 'Hiện chưa có chương trình ưu đãi nào.'),
        'html' => implode('', array_map('hithean_offer_card_html', $cards)),
        'url' => (string) get_permalink($product->get_id()),
        'buy' => $product->is_type('variable') ? 'Chọn phân loại & mua hàng' : 'Xem sản phẩm & mua hàng',
        'all' => $page && $page->post_status === 'publish' ? (string) get_permalink($page) : '',
    ]);
}
add_action('wp_ajax_hithean_loop_offers', 'hithean_loop_offers_ajax');
add_action('wp_ajax_nopriv_hithean_loop_offers', 'hithean_loop_offers_ajax');

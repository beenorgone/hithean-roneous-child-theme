<?php
/**
 * Thanh ưu đãi dính đầu trang (sticky topbar) — WooCommerce → Ưu đãi sản phẩm → Cấu hình & xuất bản → "Thanh ưu đãi đầu trang".
 *   - Nội dung admin tự soạn (settings 'topbar_text'): mỗi dòng 1 thông điệp, tùy chọn "Nội dung | đường dẫn"; nhiều dòng thì tự xoay vòng.
 *   - Nút "Xem chi tiết" (nhãn sửa được — 'topbar_button') mở popup "Chương trình ưu đãi đang áp dụng": 1 thẻ / chương trình
 *     (hithean_offers_programs() — cùng nguồn "Ưu đãi tiêu biểu"), thẻ .hithean-offer-card; mỗi chương trình có CTA "Xem sản phẩm"
 *     sang trang SP (chương trình nhiều SP: danh sách SP, mỗi SP 1 link).
 *   - Popup tải AJAX khi mở (hithean_offers_topbar, chỉ đọc, không nonce) — trang có thể cache, snapshot đổi theo lịch.
 * In ở hook `hithean_site_topbar` (header.php, ngay sau <body>) → landing dùng template riêng (/uu-dai/, ANC, /tao-don) tự không có.
 * Ẩn ở trang thanh toán / cảm ơn. Chỉ hiện khi snapshot công khai (hoặc quản lý xem trước) — hithean_loop_offers_enabled().
 * Khách bấm × → ẩn trong phiên (sessionStorage `hpt-off` = khóa nội dung; đổi nội dung là hiện lại).
 * Thanh cao cố định (CSS --hpt-h) để không giật trang; menu Roneous dạng fixed được đẩy xuống dưới thanh.
 */
defined('ABSPATH') || exit;

const HITHEAN_TOPBAR_MAX_LINES = 6;
const HITHEAN_TOPBAR_COLORS = ['blue' => 'Xanh dương thương hiệu', 'navy' => 'Xanh navy', 'green' => 'Xanh lá', 'yellow' => 'Vàng'];

/** "Nội dung | đường dẫn" mỗi dòng → [['text', 'url']] (hàm thuần; url chưa escape). Bỏ dòng trống, tối đa 6 dòng, 200 ký tự. */
function hithean_offers_topbar_lines(string $raw): array {
    $lines = [];
    foreach (preg_split('/\R/u', $raw) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        $text = trim(preg_replace('/\s+/u', ' ', $parts[0]));
        if ($text === '') { continue; }
        if (mb_strlen($text) > 200) { $text = rtrim(mb_substr($text, 0, 200)) . '…'; }
        $url = $parts[1] ?? '';
        $lines[] = ['text' => $text, 'url' => preg_match('~^(https?://|/|#)~i', $url) ? $url : ''];
        if (count($lines) >= HITHEAN_TOPBAR_MAX_LINES) { break; }
    }
    return $lines;
}

/** Cấu hình đã chuẩn hóa: enabled, lines, button, color. */
function hithean_offers_topbar_config(): array {
    $s = hithean_offers_settings();
    $button = trim((string) ($s['topbar_button'] ?? ''));
    return [
        'enabled' => !empty($s['topbar']),
        'lines' => hithean_offers_topbar_lines((string) ($s['topbar_text'] ?? '')),
        'button' => $button !== '' ? mb_substr($button, 0, 30) : 'Xem chi tiết',
        'color' => isset(HITHEAN_TOPBAR_COLORS[$s['topbar_color'] ?? '']) ? (string) $s['topbar_color'] : 'blue',
    ];
}

/** Khóa nội dung — khách đã đóng thanh, admin đổi nội dung thì thanh hiện lại. */
function hithean_offers_topbar_key(array $config): string {
    return substr(md5(wp_json_encode([$config['lines'], $config['button']])), 0, 10);
}

/** Thanh có in ở request này không (front). */
function hithean_offers_topbar_visible(): bool {
    static $visible = null;
    if ($visible !== null) { return $visible; }
    $config = hithean_offers_topbar_config();
    $visible = $config['enabled'] && $config['lines'] && !is_admin() && !is_feed() && !is_embed()
        && function_exists('hithean_loop_offers_enabled') && hithean_loop_offers_enabled()
        && !(function_exists('is_checkout') && is_checkout())
        && !(function_exists('hithean_offers_is_page') && hithean_offers_is_page())
        && empty($_GET['thean_order_embed']);
    $visible = (bool) apply_filters('hithean_offers_topbar_visible', $visible);
    return $visible;
}

add_action('wp_enqueue_scripts', static function (): void {
    if (!hithean_offers_topbar_visible()) { return; }
    $base = '/custom-functions/marketing/assets/offers-topbar';
    $ver = static fn(string $ext): string => (string) @filemtime(HITHEAN_THEME_DIR . $base . '.' . $ext);
    wp_enqueue_style('hithean-offers-topbar', get_stylesheet_directory_uri() . $base . '.css', ['hithean-offer-card'], $ver('css'));
    wp_enqueue_script('hithean-offers-topbar', get_stylesheet_directory_uri() . $base . '.js', [], $ver('js'), true);
    wp_script_add_data('hithean-offers-topbar', 'strategy', 'defer');
    wp_localize_script('hithean-offers-topbar', 'hitheanTopbar', ['ajaxUrl' => admin_url('admin-ajax.php')]);
}, 20);

/** HTML thanh (hàm in) — gọi từ header.php qua do_action('hithean_site_topbar'). */
function hithean_offers_topbar_render(): void {
    if (!hithean_offers_topbar_visible()) { return; }
    $config = hithean_offers_topbar_config();
    $key = hithean_offers_topbar_key($config);
    $messages = '';
    foreach ($config['lines'] as $i => $line) {
        $text = esc_html($line['text']);
        $inner = $line['url'] !== '' ? '<a class="hpt__text" href="' . esc_url($line['url']) . '">' . $text . '</a>' : '<span class="hpt__text">' . $text . '</span>';
        $messages .= '<p class="hpt__msg' . ($i === 0 ? ' is-active' : '') . '" data-hpt-msg title="' . esc_attr($line['text']) . '"' . ($i === 0 ? '' : ' aria-hidden="true"') . '>' . $inner . '</p>';
    }
    echo '<div class="hpt hpt--' . esc_attr($config['color']) . '" id="hithean-topbar" data-hpt data-hpt-key="' . esc_attr($key) . '" role="region" aria-label="Chương trình ưu đãi">'
        . '<div class="hpt__inner"><span class="hpt__star" aria-hidden="true">✳</span>'
        . '<div class="hpt__msgs" data-hpt-msgs>' . $messages . '</div>'
        . '<button type="button" class="hpt__more" data-hpt-open aria-haspopup="dialog" aria-controls="hpt-dialog">' . esc_html($config['button']) . '<span aria-hidden="true"> ›</span></button>'
        . '<button type="button" class="hpt__close" data-hpt-close aria-label="Ẩn thanh ưu đãi">×</button></div></div>';
    // Chạy ngay (trước khi vẽ phần còn lại): khách đã đóng trong phiên → bỏ thanh; ngược lại bật chừa chỗ cho thanh.
    echo '<script>(function(){var b=document.getElementById("hithean-topbar");try{if(sessionStorage.getItem("hpt-off")===b.getAttribute("data-hpt-key")){b.parentNode.removeChild(b);return;}}catch(e){}document.documentElement.classList.add("hpt-on");})();</script>';
}
add_action('hithean_site_topbar', 'hithean_offers_topbar_render');

/** Khung popup (nội dung tải AJAX khi mở). */
add_action('wp_footer', static function (): void {
    if (!hithean_offers_topbar_visible()) { return; }
    $offers_url = hithean_offers_page_url();
    ?>
    <dialog class="hithean-offers-dialog hpt-dialog" id="hpt-dialog" aria-labelledby="hpt-dialog-title">
        <div class="hithean-offers-dialog__head">
            <div><h3 id="hpt-dialog-title">Chương trình ưu đãi đang áp dụng</h3><p data-hpt-meta>Quà tặng, giảm giá, mua nhiều, vận chuyển…</p></div>
            <button type="button" class="hithean-offers-dialog__close" data-hpt-dialog-close aria-label="Đóng">×</button>
        </div>
        <ul class="hithean-offers-dialog__list" data-hpt-list><li class="hpt-dialog__status" data-hpt-status>Đang tải ưu đãi…</li></ul>
        <div class="hithean-offers-dialog__foot">
            <p>Mỗi chương trình có điều kiện riêng. Giá trị cuối cùng được xác nhận trong giỏ hàng.</p>
            <?php if ($offers_url !== '') : ?><a class="hithean-offers-dialog__all" href="<?php echo esc_url($offers_url); ?>">Xem toàn bộ ưu đãi ↗</a><?php endif; ?>
        </div>
    </dialog>
    <?php
}, 30);

/** Danh sách SP của 1 chương trình nhiều SP (mỗi SP 1 CTA "Xem sản phẩm"). */
function hithean_offers_topbar_products_html(array $rows, int $max = 5): string {
    $html = '<ul class="hpt-products">';
    foreach (array_slice($rows, 0, $max) as $row) {
        $html .= '<li><a href="' . esc_url((string) $row['url']) . '">'
            . (!empty($row['image']) ? '<img src="' . esc_url((string) $row['image']) . '" alt="" width="44" height="44" loading="lazy" decoding="async">' : '')
            . '<span class="hpt-products__name">' . esc_html((string) $row['product_name'])
            . (empty($row['in_stock']) ? '<small>Đặt trước</small>' : '') . '</span>'
            . '<span class="hpt-products__cta">Xem sản phẩm ›</span></a></li>';
    }
    return $html . '</ul>';
}

/**
 * Thẻ popup: thẻ ưu đãi chung + phạm vi + CTA tới trang sản phẩm.
 *   1 SP → nút "Xem sản phẩm" · toàn shop → "Xem sản phẩm" (cửa hàng / danh mục) · nhiều SP → danh sách ≤5 SP + "Xem tất cả N sản phẩm".
 */
function hithean_offers_topbar_cards(int $limit = 20): array {
    if (!function_exists('hithean_offers_programs') || !function_exists('hithean_offer_cards_from_rows')) { return []; }
    $cards = [];
    foreach (hithean_offers_programs($limit) as $rows) {
        $row = reset($rows);
        $card = hithean_offer_cards_from_rows([$row])[0] ?? null;
        if (!$card) { continue; }
        $count = count($rows);
        $button = static fn(string $url, string $label, string $extra = ''): string => '<a class="hithean-offer-card__action' . $extra . '" href="' . esc_url($url) . '">' . esc_html($label) . ' ↗</a>';
        if ($count === 1) {
            array_unshift($card['notes'], 'Áp dụng cho ' . $row['product_name']);
            $card['actions_html'] = $button((string) $row['url'], 'Xem sản phẩm');
        } else {
            $scope = hithean_offers_program_scope($rows);
            if ($scope['site_wide']) {
                array_unshift($card['notes'], 'Áp dụng cho mọi sản phẩm');
                $card['actions_html'] = $button($scope['url'], 'Xem sản phẩm');
            } else {
                array_unshift($card['notes'], 'Áp dụng cho ' . $count . ' sản phẩm');
                $card['actions_html'] = hithean_offers_topbar_products_html(array_values($rows))
                    . ($count > 5 ? $button($scope['url'], 'Xem tất cả ' . $count . ' sản phẩm', ' is-ghost') : '');
            }
        }
        $cards[] = $card;
    }
    return $cards;
}

/** AJAX: nội dung popup "Xem chi tiết". Chỉ đọc, không nonce (trang cache). */
function hithean_offers_topbar_ajax(): void {
    $config = hithean_offers_topbar_config();
    if (!$config['enabled'] || !function_exists('hithean_loop_offers_enabled') || !hithean_loop_offers_enabled()) {
        wp_send_json_error(['message' => 'Hiện chưa có chương trình ưu đãi.'], 404);
    }
    $cards = hithean_offers_topbar_cards();
    wp_send_json_success([
        'count' => count($cards),
        'meta' => $cards ? count($cards) . ' chương trình đang áp dụng' : 'Hiện chưa có chương trình ưu đãi nào.',
        'html' => implode('', array_map('hithean_offer_card_html', $cards)),
    ]);
}
add_action('wp_ajax_hithean_offers_topbar', 'hithean_offers_topbar_ajax');
add_action('wp_ajax_nopriv_hithean_offers_topbar', 'hithean_offers_topbar_ajax');

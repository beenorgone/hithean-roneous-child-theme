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
 * Tùy chọn ẩn nút CTA ('topbar_hide_button': mobile | desktop | both) → ở thiết bị đó bấm vào thông điệp (dòng không gắn link) mở popup.
 * Khách bấm × → ẩn trong phiên (sessionStorage `hpt-off` = khóa nội dung; đổi nội dung là hiện lại).
 * Chiều cao theo nội dung + padding (5px máy tính / 10px mobile); --hpt-h đo bằng JS ngay khi in thanh để body + menu Roneous dạng fixed chừa chỗ.
 */
defined('ABSPATH') || exit;

const HITHEAN_TOPBAR_MAX_LINES = 6;
/** Ẩn nút CTA: '' = luôn hiện · mobile (≤767px) · desktop · both. */
const HITHEAN_TOPBAR_HIDE_CTA = ['' => 'Không ẩn', 'mobile' => 'Ẩn trên điện thoại', 'desktop' => 'Ẩn trên máy tính', 'both' => 'Ẩn cả hai'];

/** Chuẩn hóa tùy chọn ẩn nút CTA (hàm thuần); bản cũ lưu true → 'both'. */
function hithean_offers_topbar_hide_value($value): string {
    if ($value === true || $value === 1 || $value === '1') { return 'both'; }
    return is_string($value) && isset(HITHEAN_TOPBAR_HIDE_CTA[$value]) ? $value : '';
}
/** Màu nền có sẵn => [nhãn, mã màu]; 'custom' = mã màu admin nhập (settings 'topbar_bg'). */
const HITHEAN_TOPBAR_COLORS = ['blue' => ['Xanh dương thương hiệu', '#0047ba'], 'red' => ['Hồng đỏ nổi bật', '#e0245e'], 'custom' => ['Mã màu tự chọn', '']];

/** Chuẩn hóa mã màu "#abc" / "abc" / "#aabbcc" → "#aabbcc" thường; sai định dạng → '' (hàm thuần). */
function hithean_offers_topbar_hex(string $value): string {
    $value = strtolower(ltrim(trim($value), '#'));
    if (preg_match('/^[0-9a-f]{3}$/', $value)) { $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2]; }
    return preg_match('/^[0-9a-f]{6}$/', $value) ? '#' . $value : '';
}

/** Màu chữ đọc rõ trên nền $hex: nền sáng → navy, nền tối → trắng (độ sáng tương đối WCAG). */
function hithean_offers_topbar_fg(string $hex): string {
    $channel = static function (string $pair): float { $c = hexdec($pair) / 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
    $l = 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    return (1.05 / ($l + 0.05)) >= (($l + 0.05) / (0.0265 + 0.05)) ? '#ffffff' : '#001f3f';
}

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

/** Cấu hình đã chuẩn hóa: enabled, lines, button, color (blue | red | custom), bg + fg (mã màu nền / chữ). */
function hithean_offers_topbar_config(): array {
    $s = hithean_offers_settings();
    $button = trim((string) ($s['topbar_button'] ?? ''));
    $config = [
        'enabled' => !empty($s['topbar']),
        'lines' => hithean_offers_topbar_lines((string) ($s['topbar_text'] ?? '')),
        'button' => $button !== '' ? mb_substr($button, 0, 30) : 'Xem chi tiết',
        'hide_button' => hithean_offers_topbar_hide_value($s['topbar_hide_button'] ?? ''),
    ];
    $color = isset(HITHEAN_TOPBAR_COLORS[$s['topbar_color'] ?? '']) ? (string) $s['topbar_color'] : 'blue';
    $bg = $color === 'custom' ? hithean_offers_topbar_hex((string) ($s['topbar_bg'] ?? '')) : HITHEAN_TOPBAR_COLORS[$color][1];
    if ($bg === '') { $color = 'blue'; $bg = HITHEAN_TOPBAR_COLORS['blue'][1]; }
    return $config + ['color' => $color, 'bg' => $bg, 'fg' => hithean_offers_topbar_fg($bg)];
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

/**
 * Nội dung 1 thông điệp: có link → <a>; không link + đã ẩn nút CTA ($opens_popup) → <button> bấm mở popup chương trình; còn lại → chữ thường.
 */
function hithean_offers_topbar_message_html(array $line, bool $opens_popup): string {
    $text = esc_html($line['text']);
    if ($line['url'] !== '') { return '<a class="hpt__text" href="' . esc_url($line['url']) . '">' . $text . '</a>'; }
    if ($opens_popup) { return '<button type="button" class="hpt__text hpt__text--open" data-hpt-open aria-haspopup="dialog" aria-controls="hpt-dialog">' . $text . '</button>'; }
    return '<span class="hpt__text">' . $text . '</span>';
}

/** HTML thanh (hàm in) — gọi từ header.php qua do_action('hithean_site_topbar'). */
function hithean_offers_topbar_render(): void {
    if (!hithean_offers_topbar_visible()) { return; }
    $config = hithean_offers_topbar_config();
    $key = hithean_offers_topbar_key($config);
    $messages = '';
    foreach ($config['lines'] as $i => $line) {
        $messages .= '<p class="hpt__msg' . ($i === 0 ? ' is-active' : '') . '" data-hpt-msg title="' . esc_attr($line['text']) . '"' . ($i === 0 ? '' : ' aria-hidden="true"') . '>'
            . hithean_offers_topbar_message_html($line, $config['hide_button'] !== '') . '</p>';
    }
    // Trang có thể cache → ẩn nút theo thiết bị bằng CSS (.hpt--cta-mobile / -desktop); thông điệp chỉ bấm được ở thiết bị đã ẩn nút.
    $cta_class = $config['hide_button'] !== '' ? ' hpt--cta-' . $config['hide_button'] : '';
    echo '<div class="hpt hpt--' . esc_attr($config['color']) . $cta_class . '" style="--hpt-bg:' . esc_attr($config['bg']) . ';--hpt-fg:' . esc_attr($config['fg']) . '" id="hithean-topbar" data-hpt data-hpt-key="' . esc_attr($key) . '" role="region" aria-label="Chương trình ưu đãi">'
        . '<div class="hpt__inner"><span class="hpt__star" aria-hidden="true">✳</span>'
        . '<div class="hpt__msgs" data-hpt-msgs>' . $messages . '</div>'
        . ($config['hide_button'] === 'both' ? '' : '<button type="button" class="hpt__more" data-hpt-open aria-haspopup="dialog" aria-controls="hpt-dialog">' . esc_html($config['button']) . '<span aria-hidden="true"> ›</span></button>')
        . '<button type="button" class="hpt__close" data-hpt-close aria-label="Ẩn thanh ưu đãi">×</button></div></div>';
    // Chạy ngay (trước khi vẽ phần còn lại): khách đã đóng trong phiên → bỏ thanh; ngược lại bật chừa chỗ cho thanh.
    echo '<script>(function(){var b=document.getElementById("hithean-topbar");try{if(sessionStorage.getItem("hpt-off")===b.getAttribute("data-hpt-key")){b.parentNode.removeChild(b);return;}}catch(e){}var r=document.documentElement;r.style.setProperty("--hpt-h",b.offsetHeight+"px");r.classList.add("hpt-on");})();</script>';
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

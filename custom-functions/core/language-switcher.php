<?php
if (!defined('ABSPATH')) exit;

/**
 * Nút "Eng" / "Vi" toàn site, dịch bằng Google Website Translator (client-side).
 * Xem plans/multi-language-google-translate-switch.md cho quyết định kiến trúc
 * và giới hạn đã biết (không SEO đa ngôn ngữ, không dịch nội dung nạp bằng AJAX).
 */

function hithean_language_switcher_is_english(): bool
{
    if (!isset($_COOKIE['googtrans'])) {
        return false;
    }

    $value = (string) wp_unslash($_COOKIE['googtrans']);

    return strpos($value, '/en') !== false;
}

function hithean_language_switcher_enqueue(): void
{
    if (is_admin()) {
        return;
    }

    wp_enqueue_style(
        'hithean-language-switcher',
        get_stylesheet_directory_uri() . '/css/language-switcher.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'hithean-language-switcher',
        get_stylesheet_directory_uri() . '/js/language-switcher.js',
        array(),
        '1.0.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'hithean_language_switcher_enqueue');

/**
 * Toggle 2 nhánh "VI | EN", khớp kiểu segmented control của theme (xem
 * css/language-switcher.css). Dùng chung cho cả bản render trong nav (desktop)
 * và bản fixed cho mobile — xem 2 điểm gọi bên dưới.
 */
function hithean_language_switcher_markup(string $extra_class = ''): string
{
    $is_english = hithean_language_switcher_is_english();
    $class      = trim('hithean-lang-switch ' . $extra_class);

    return sprintf(
        '<div class="%s" role="group" aria-label="Language">' .
            '<button type="button" class="hithean-lang-switch__option" data-lang="vi" aria-pressed="%s">VI</button>' .
            '<button type="button" class="hithean-lang-switch__option" data-lang="en" aria-pressed="%s">EN</button>' .
        '</div>',
        esc_attr($class),
        $is_english ? 'false' : 'true',
        $is_english ? 'true' : 'false'
    );
}

/**
 * Render qua hook thay vì sửa cứng template, để tắt/đổi vị trí được bằng
 * remove_action() từ nơi khác nếu cần. Bản này nằm trong `.row` của nav —
 * cả 2 layout header (center-standard, custom) gộp `.row` đó vào hamburger
 * menu trên mobile, nên bản này bị ẩn theo khi thu gọn màn hình
 * (xem `.hithean-lang-switch--inline` trong css/language-switcher.css).
 */
add_action('hithean_top_bar_after', function (): void {
    echo hithean_language_switcher_markup('hithean-lang-switch--inline');
});

/**
 * Bản độc lập render thẳng vào wp_footer — nằm ngoài toàn bộ cấu trúc
 * nav/hamburger nên không bị cuốn theo khi menu mobile thu gọn. CSS chỉ hiện
 * bản này ở mobile (position: fixed, góc trên-phải màn hình), ẩn ở desktop
 * vì bản trong nav đã đủ.
 */
add_action('wp_footer', function (): void {
    if (is_admin()) {
        return;
    }

    echo '<div id="google_translate_element" class="hithean-google-translate-element" aria-hidden="true"></div>';
    echo hithean_language_switcher_markup('hithean-lang-switch--mobile-fixed');
});

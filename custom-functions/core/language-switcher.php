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
 * Render qua hook thay vì sửa cứng template, để tắt/đổi vị trí được bằng
 * remove_action() từ nơi khác nếu cần.
 *
 * Toggle 2 nhánh "VI | EN" thay vì 1 nút đổi label, khớp kiểu segmented
 * control của theme (xem css/language-switcher.css).
 */
function hithean_language_switcher_render_button(): void
{
    $is_english = hithean_language_switcher_is_english();

    printf(
        '<div class="hithean-lang-switch" role="group" aria-label="Language">' .
            '<button type="button" class="hithean-lang-switch__option" data-lang="vi" aria-pressed="%s">VI</button>' .
            '<button type="button" class="hithean-lang-switch__option" data-lang="en" aria-pressed="%s">EN</button>' .
        '</div>',
        $is_english ? 'false' : 'true',
        $is_english ? 'true' : 'false'
    );
}
add_action('hithean_top_bar_after', 'hithean_language_switcher_render_button');

/**
 * Container ẩn Google Translate cần trong DOM trước khi script element.js
 * chạy init. Luôn in ra (rẻ) nhưng script Google chỉ được JS nạp khi cookie
 * googtrans đã tồn tại — xem js/language-switcher.js.
 */
add_action('wp_footer', function (): void {
    if (is_admin()) {
        return;
    }

    echo '<div id="google_translate_element" class="hithean-google-translate-element" aria-hidden="true"></div>';
});

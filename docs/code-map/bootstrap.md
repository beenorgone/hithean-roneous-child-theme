<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `bootstrap`

> Điểm vào: bootstrap.php chỉ require core/* rồi core/module-loader.php; shortcode-social-display.php (social fan/marquee/collage, nạp theo trang).

2 file · ~700 dòng · 12 hàm (8% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| shortcode | `[social_collage]` | shortcode-social-display.php |
| shortcode | `[social_coverflow]` | shortcode-social-display.php |
| shortcode | `[social_display]` | shortcode-social-display.php |
| shortcode | `[social_fan]` | shortcode-social-display.php |
| shortcode | `[social_marquee]` | shortcode-social-display.php |

## Hook khu này lắng nghe

`init` · `wp_footer`

## File

### `bootstrap.php`

### `shortcode-social-display.php`

Social Display Shortcodes

| Hàm | Mô tả |
|---|---|
| `social_display_get_items(array $atts)` | — |
| `social_display_render_card(array $it, string $extra_class = '')` | — |
| `social_display_render_header(array $atts)` | — |
| `social_display_print_assets()` | — |
| `social_display_print_footer_runner()` | — |
| `social_display_prepare(array $atts, string $tag)` | — |
| `social_display_sanitize_css_spacing(string $value)` | — |
| `social_display_style_vars(array $atts)` | — |
| `social_display_build_section(string $variant, array $atts, array $items)` | — |
| `social_display_variant_shortcode(string $variant, $atts, string $tag)` | Factory cho 4 shortcode đơn — mỗi cái cố định một kiểu. |
| `social_display_responsive_shortcode($atts = [])` | — |
| `social_display_register_media_meta()` | — |


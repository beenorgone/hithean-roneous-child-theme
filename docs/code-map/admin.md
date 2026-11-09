<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `admin`

> wp-admin: hướng dẫn shortcode, metabox bài viết, trang plugins, editor tools.

4 file · ~800 dòng · 19 hàm (5% có docblock)

[← Mục lục bản đồ code](README.md)

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `ivar_shortcodes_guide` | shortcodes-guide.php |
| `mce_external_plugins` | editor-tools.php |

## Hook khu này lắng nghe

`admin_footer-post-new.php` · `admin_footer-post.php` · `admin_menu` · `content_save_pre` · `manage_plugins_columns` · `manage_plugins_custom_column` · `mce_buttons` · `mce_external_plugins` · `rwmb_meta_boxes` · `the_content` ×2 · `tiny_mce_before_init` · `use_block_editor_for_post_type` · `wp_default_editor` · `wp_head` ×2 · `wp_kses_allowed_html`

## File

### `admin/blogpost-metabox.php`

### `admin/editor-tools/editor-tools.php`

| Hàm | Mô tả |
|---|---|
| `ivar_editor_tools_asset_url(string $asset)` | — |
| `ivar_editor_tools_add_tinymce_plugins(array $plugins)` | — |
| `ivar_editor_tools_use_classic_editor_for_posts(bool $use_block_editor, string $post_type)` | — |
| `ivar_editor_tools_default_editor(string $editor)` | — |
| `ivar_editor_tools_recover_classic_editor()` | — |
| `ivar_editor_tools_button_styles()` | — |
| `ivar_editor_tools_add_tinymce_buttons(array $buttons)` | — |
| `ivar_editor_tools_configure_tinymce(array $init)` | — |
| `ivar_editor_tools_allowed_post_html(array $tags, string $context)` | — |
| `ivar_editor_tools_remove_tinymce_bookmarks(string $content)` | — |
| `ivar_editor_tools_dom_inner_html($node)` | — |
| `ivar_editor_tools_add_table_card_labels(string $content)` | — |
| `ivar_editor_tools_print_table_card_styles()` | — |
| `ivar_editor_tools_print_button_styles()` | — |

### `admin/plugins-page.php`

| Hàm | Mô tả |
|---|---|
| `add_plugin_slug_column($columns)` | — |
| `show_plugin_slug_column($column_name, $plugin_file, $plugin_data)` | — |

### `admin/shortcodes-guide.php`

Trang admin: Shortcodes Guide Hiển thị tài liệu + hướng dẫn dùng các shortcode của theme.

| Hàm | Mô tả |
|---|---|
| `ivar_shortcodes_guide_data()` | Cấu trúc mỗi guide: name        string   tên hiển thị, vd "[certifications]" summary     string   mô tả ngắn (HTML cho phép) args        array    [ [name, desc, default], ... ]… |
| `ivar_shortcodes_guide_menu()` | — |
| `ivar_shortcodes_guide_render()` | — |


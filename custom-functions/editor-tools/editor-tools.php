<?php
if (!defined('ABSPATH')) exit;

/**
 * Reusable WordPress editor utilities.
 */

if (!function_exists('ivar_editor_tools_asset_url')) {
    function ivar_editor_tools_asset_url(string $asset): string
    {
        $relative_path = 'custom-functions/editor-tools/assets/' . ltrim($asset, '/');
        $asset_path    = get_stylesheet_directory() . '/' . $relative_path;
        $asset_url     = get_stylesheet_directory_uri() . '/' . $relative_path;

        if (file_exists($asset_path)) {
            $asset_url = add_query_arg('ver', (string) filemtime($asset_path), $asset_url);
        }

        return $asset_url;
    }
}

if (!function_exists('ivar_editor_tools_add_tinymce_plugins')) {
    function ivar_editor_tools_add_tinymce_plugins(array $plugins): array
    {
        if (!is_admin() || !current_user_can('edit_posts') || !user_can_richedit()) {
            return $plugins;
        }

        $asset_path = get_stylesheet_directory() . '/custom-functions/editor-tools/assets/tinymce-table-tools.js';
        if (is_readable($asset_path)) {
            $plugins['ivar_table_tools'] = ivar_editor_tools_asset_url('tinymce-table-tools.js');
        }

        return $plugins;
    }
}
add_filter('mce_external_plugins', 'ivar_editor_tools_add_tinymce_plugins');

if (!function_exists('ivar_editor_tools_use_classic_editor_for_posts')) {
    function ivar_editor_tools_use_classic_editor_for_posts(bool $use_block_editor, string $post_type): bool
    {
        if (!post_type_supports($post_type, 'editor')) {
            return $use_block_editor;
        }

        return false;
    }
}
add_filter('use_block_editor_for_post_type', 'ivar_editor_tools_use_classic_editor_for_posts', 20, 2);

if (!function_exists('ivar_editor_tools_default_editor')) {
    function ivar_editor_tools_default_editor(string $editor): string
    {
        if (!is_admin() || !current_user_can('edit_posts') || !user_can_richedit()) {
            return $editor;
        }

        return 'tinymce';
    }
}
add_filter('wp_default_editor', 'ivar_editor_tools_default_editor', 20);

if (!function_exists('ivar_editor_tools_recover_classic_editor')) {
    function ivar_editor_tools_recover_classic_editor(): void
    {
        if (!is_admin() || !current_user_can('edit_posts') || !user_can_richedit()) {
            return;
        }
        ?>
        <script>
            window.addEventListener('load', function () {
                var textarea = document.getElementById('content');
                if (!textarea || !window.switchEditors || typeof window.switchEditors.go !== 'function') {
                    return;
                }

                window.setTimeout(function () {
                    if (window.tinymce && window.tinymce.get('content')) {
                        return;
                    }

                    var iframe = document.getElementById('content_ifr');
                    if (!iframe) {
                        window.switchEditors.go('content', 'tmce');
                    }
                }, 250);
            });
        </script>
        <?php
    }
}
add_action('admin_footer-post.php', 'ivar_editor_tools_recover_classic_editor', 99);
add_action('admin_footer-post-new.php', 'ivar_editor_tools_recover_classic_editor', 99);

if (!function_exists('ivar_editor_tools_button_styles')) {
    function ivar_editor_tools_button_styles(): string
    {
        return 'a.ivar-content-button{display:inline-flex;align-items:center;justify-content:center;text-align:center;text-decoration:none;cursor:pointer;}'
            . 'a.ivar-content-button.btn,a.ivar-content-button.btn-primary,a.ivar-content-button.button-primary,a.ivar-content-button.button-secondary{height:auto;line-height:20px;padding:6px 16px;margin:0 0 10px;border:0;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.24);font-size:13px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;}'
            . 'a.ivar-content-button.btn-primary,a.ivar-content-button.button-primary{background:#6200ee;color:#fff;}'
            . 'a.ivar-content-button.btn,a.ivar-content-button.button-secondary{background:#fff;color:#6200ee;}'
            . 'a.ivar-content-button[class*="button--"]{display:inline-block;min-height:45px;line-height:45px;padding:0 20px;font-family:Oswald,Arial,sans-serif;font-size:16px;font-weight:500;letter-spacing:1px;text-transform:uppercase;word-spacing:2px;white-space:nowrap;}'
            . 'a.ivar-content-button.button--dark-blue-reverse{background:#fff;color:#0d2f4f;border:2px solid #0d2f4f;}'
            . 'a.ivar-content-button.button--light-blue{background:#5aa7d8;color:#0d2f4f;border:2px solid #5aa7d8;}'
            . 'a.ivar-content-button.button--nuocepkytu-light-green{background:#00843d;border:2px solid #00843d;color:#fff;}'
            . 'a.ivar-content-button.button--protein-yeast-yellow{background:#d0b41b;border-color:#d0b41b;color:#fff;}'
            . 'a.ivar-content-button.button--protein-yeast-brown{background:#71541b;border:2px solid #71541b;color:#fff;}'
            . 'a.ivar-content-button.button--short-text{min-width:150px;}'
            . 'a.ivar-content-button.button--small{min-height:40px;line-height:40px;min-width:0;letter-spacing:0;padding:0 10px;}'
            . 'a.ivar-content-button.button--none{border:0;}';
    }
}

if (!function_exists('ivar_editor_tools_add_tinymce_buttons')) {
    function ivar_editor_tools_add_tinymce_buttons(array $buttons): array
    {
        if (!is_admin() || !current_user_can('edit_posts') || !user_can_richedit()) {
            return $buttons;
        }

        $plugins = apply_filters('mce_external_plugins', []);
        if (empty($plugins['ivar_table_tools'])) {
            return $buttons;
        }

        $editor_buttons = [
            'ivarptextcolor',
            'ivarpbackground',
            'ivarpfontfamily',
            'ivarpfontsize',
            'ivarpfontweight',
            'ivarpfontstyle',
            'ivarbutton',
            'ivartable',
            'ivartableaddrow',
            'ivartableaddcol',
            'ivartabledeleterow',
            'ivartabledeletecol',
            'ivartabledelete',
        ];

        foreach ($editor_buttons as $button) {
            if (!in_array($button, $buttons, true)) {
                $buttons[] = $button;
            }
        }

        return $buttons;
    }
}
add_filter('mce_buttons', 'ivar_editor_tools_add_tinymce_buttons');

if (!function_exists('ivar_editor_tools_configure_tinymce')) {
    function ivar_editor_tools_configure_tinymce(array $init): array
    {
        if (!is_admin() || !current_user_can('edit_posts') || !user_can_richedit()) {
            return $init;
        }

        $init['table_advtab']             = true;
        $init['table_appearance_options'] = true;
        $init['table_default_attributes'] = wp_json_encode([
            'class' => 'ivar-content-table',
        ]);
        $init['table_default_styles'] = wp_json_encode([
            'width'           => '100%',
            'border-collapse' => 'collapse',
        ]);
        $init['table_class_list'] = wp_json_encode([
            [
                'title' => 'Bảng nội dung',
                'value' => 'ivar-content-table',
            ],
            [
                'title' => 'Bảng gọn',
                'value' => 'ivar-content-table ivar-content-table--compact',
            ],
        ]);

        $editor_table_css = 'table.ivar-content-table{width:100%;border-collapse:collapse;margin:16px 0;background:#fff;}'
            . 'table.ivar-content-table th,table.ivar-content-table td{border:1px solid #d0d7de;padding:8px 10px;vertical-align:top;}'
            . 'table.ivar-content-table th{background:#f6f8fa;font-weight:600;}'
            . 'table.ivar-content-table--compact th,table.ivar-content-table--compact td{padding:5px 8px;}'
            . 'a.ivar-content-button{display:inline-flex;align-items:center;justify-content:center;height:auto;line-height:20px;padding:6px 16px;margin:0 0 10px 0;border-radius:4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.24);}';

        $init['content_style'] = trim(($init['content_style'] ?? '') . ' ' . $editor_table_css . ' ' . ivar_editor_tools_button_styles());

        return $init;
    }
}
add_filter('tiny_mce_before_init', 'ivar_editor_tools_configure_tinymce');

if (!function_exists('ivar_editor_tools_allowed_post_html')) {
    function ivar_editor_tools_allowed_post_html(array $tags, string $context): array
    {
        if ($context !== 'post') {
            return $tags;
        }

        $global_attrs = [
            'class'      => true,
            'style'      => true,
            'id'         => true,
            'data-label' => true,
        ];

        $tags['table'] = array_merge($global_attrs, [
            'border'      => true,
            'cellpadding' => true,
            'cellspacing' => true,
            'summary'     => true,
            'width'       => true,
        ]);
        $tags['thead']    = $global_attrs;
        $tags['tbody']    = $global_attrs;
        $tags['tfoot']    = $global_attrs;
        $tags['tr']       = $global_attrs;
        $tags['th']       = array_merge($global_attrs, [
            'abbr'    => true,
            'colspan' => true,
            'rowspan' => true,
            'scope'   => true,
            'width'   => true,
        ]);
        $tags['td'] = array_merge($global_attrs, [
            'colspan' => true,
            'rowspan' => true,
            'width'   => true,
        ]);
        $tags['caption']  = $global_attrs;
        $tags['colgroup'] = $global_attrs;
        $tags['col']      = array_merge($global_attrs, [
            'span'  => true,
            'width' => true,
        ]);
        $tags['p']        = array_merge($tags['p'] ?? [], $global_attrs);
        $tags['a']        = array_merge($tags['a'] ?? [], $global_attrs, [
            'href'   => true,
            'target' => true,
            'rel'    => true,
            'title'  => true,
        ]);
        $tags['span']     = array_merge($tags['span'] ?? [], $global_attrs);
        $tags['li']       = array_merge($tags['li'] ?? [], $global_attrs);
        $tags['blockquote'] = array_merge($tags['blockquote'] ?? [], $global_attrs);

        return $tags;
    }
}
add_filter('wp_kses_allowed_html', 'ivar_editor_tools_allowed_post_html', 10, 2);

if (!function_exists('ivar_editor_tools_remove_tinymce_bookmarks')) {
    function ivar_editor_tools_remove_tinymce_bookmarks(string $content): string
    {
        if (strpos($content, 'mce_SELRES_') === false && strpos($content, 'data-mce-type="bookmark"') === false) {
            return $content;
        }

        $content = preg_replace('/<span\b(?=[^>]*\bdata-mce-type=(["\'])bookmark\1)(?=[^>]*\bmce_SELRES_(?:start|end)\b)[^>]*>.*?<\/span>/is', '', $content);
        $content = str_replace("\xEF\xBB\xBF", '', (string) $content);

        return (string) $content;
    }
}
add_filter('content_save_pre', 'ivar_editor_tools_remove_tinymce_bookmarks', 1);
add_filter('the_content', 'ivar_editor_tools_remove_tinymce_bookmarks', 1);

if (!function_exists('ivar_editor_tools_dom_inner_html')) {
    function ivar_editor_tools_dom_inner_html($node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }
}

if (!function_exists('ivar_editor_tools_add_table_card_labels')) {
    function ivar_editor_tools_add_table_card_labels(string $content): string
    {
        if (is_admin() || stripos($content, '<table') === false || !class_exists('DOMDocument')) {
            return $content;
        }

        $previous_errors = libxml_use_internal_errors(true);
        $dom             = new DOMDocument('1.0', 'UTF-8');
        $wrapped_content = '<div id="ivar-editor-tools-content">' . $content . '</div>';

        if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
            return $content;
        }

        $xpath  = new DOMXPath($dom);
        $tables = $xpath->query('//table[not(contains(concat(" ", normalize-space(@class), " "), " no-mobile-card "))]');

        if (!$tables instanceof DOMNodeList || $tables->length === 0) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
            return $content;
        }

        foreach ($tables as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $labels                = [];
            $uses_first_row_header = false;
            $header_cells          = $xpath->query('.//thead/tr[1]/th|.//thead/tr[1]/td', $table);

            if (!$header_cells instanceof DOMNodeList || $header_cells->length === 0) {
                $header_cells = $xpath->query('.//tr[1]/th|.//tr[1]/td', $table);
                $uses_first_row_header = $header_cells instanceof DOMNodeList && $header_cells->length > 0;
            }

            if ($header_cells instanceof DOMNodeList) {
                foreach ($header_cells as $cell) {
                    $labels[] = trim(preg_replace('/\s+/', ' ', (string) $cell->textContent));
                }
            }

            if (empty($labels)) {
                continue;
            }

            $body_rows = $uses_first_row_header
                ? $xpath->query('.//tr[position() > 1]', $table)
                : $xpath->query('.//tbody/tr', $table);

            if ($uses_first_row_header) {
                $header_rows = $xpath->query('.//tr[1]', $table);
                if ($header_rows instanceof DOMNodeList && $header_rows->length > 0 && $header_rows->item(0) instanceof DOMElement) {
                    $header_row = $header_rows->item(0);
                    $header_row->setAttribute('class', trim($header_row->getAttribute('class') . ' ivar-mobile-card-header-row'));
                }
            }

            if (!$body_rows instanceof DOMNodeList || $body_rows->length === 0) {
                $body_rows = $xpath->query('.//tr[not(ancestor::thead)]', $table);
            }

            if (!$body_rows instanceof DOMNodeList) {
                continue;
            }

            foreach ($body_rows as $row) {
                if (!$row instanceof DOMElement) {
                    continue;
                }

                $cells = $xpath->query('./td|./th', $row);
                if (!$cells instanceof DOMNodeList) {
                    continue;
                }

                $index = 0;
                foreach ($cells as $cell) {
                    if ($cell instanceof DOMElement && !$cell->hasAttribute('data-label') && isset($labels[$index])) {
                        $cell->setAttribute('data-label', $labels[$index]);
                    }
                    $index++;
                }
            }
        }

        $root = $dom->getElementById('ivar-editor-tools-content');
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        return $root ? ivar_editor_tools_dom_inner_html($root) : $content;
    }
}
add_filter('the_content', 'ivar_editor_tools_add_table_card_labels', 20);

if (!function_exists('ivar_editor_tools_print_table_card_styles')) {
    function ivar_editor_tools_print_table_card_styles(): void
    {
        if (is_admin() || !is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post instanceof WP_Post || stripos((string) $post->post_content, '<table') === false) {
            return;
        }
?>
        <style id="ivar-editor-tools-table-card-styles">
            @media (max-width: 640px) {
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) {
                    display: block;
                    width: 100%;
                    border: 0;
                    background: transparent;
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) thead,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) colgroup,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) tr.ivar-mobile-card-header-row {
                    display: none;
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) tbody,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) tr,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) td,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) th {
                    display: block;
                    width: 100%;
                    box-sizing: border-box;
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) tr {
                    margin: 0 0 12px;
                    padding: 10px 12px;
                    border: 1px solid #e0e3e7;
                    border-radius: 8px;
                    background: #fff;
                    box-shadow: 0 1px 3px rgba(60, 64, 67, 0.12);
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) td,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) th {
                    display: grid;
                    grid-template-columns: minmax(104px, 38%) 1fr;
                    gap: 10px;
                    align-items: start;
                    padding: 8px 0;
                    border: 0;
                    border-bottom: 1px solid #edf1f7;
                    background: transparent;
                    text-align: left;
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) td:last-child,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) th:last-child {
                    border-bottom: 0;
                }

                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) td::before,
                :where(.entry-content, .post-content, .page-content, .single-post) table:not(.no-mobile-card) th::before {
                    content: attr(data-label);
                    color: #5f6368;
                    font-size: 12px;
                    font-weight: 700;
                    line-height: 1.35;
                    text-transform: uppercase;
                }
            }
        </style>
<?php
    }
}
add_action('wp_head', 'ivar_editor_tools_print_table_card_styles', 80);

if (!function_exists('ivar_editor_tools_print_button_styles')) {
    function ivar_editor_tools_print_button_styles(): void
    {
        if (is_admin() || !is_singular()) {
            return;
        }

        $post = get_post();
        if (!$post instanceof WP_Post || strpos((string) $post->post_content, 'ivar-content-button') === false) {
            return;
        }
?>
        <style id="ivar-editor-tools-button-styles">
            <?php echo ivar_editor_tools_button_styles(); ?>
        </style>
<?php
    }
}
add_action('wp_head', 'ivar_editor_tools_print_button_styles', 81);

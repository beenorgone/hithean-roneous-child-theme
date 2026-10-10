<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  PRODUCT CONTENT NAVIGATOR — SETTINGS
  Option nhỏ, không autoload theo mặc định của WP (add_option ... false ở dưới)
  — trang sản phẩm dùng object cache, request khác không phải trả giá cho nó.
  File luôn nạp (không điều kiện) vì cả trang admin (Cài đặt ERP) lẫn
  product-navigation.php (chỉ nạp trên trang sản phẩm) đều cần các hàm này.
\*---------------------------------------*/

const HITHEAN_PCN_SETTINGS_OPTION = 'hithean_product_navigator_settings';

function hithean_pcn_default_settings(): array
{
    return [
        // Mobile không có setting — luôn là cụm nút nổi (đã build sẵn).
        'desktop_mode' => 'sticky_bar', // 'sticky_bar' | 'floating_toc'
        'menus'        => [],
    ];
}

function hithean_pcn_get_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $saved    = get_option(HITHEAN_PCN_SETTINGS_OPTION, []);
    $settings = is_array($saved)
        ? array_replace(hithean_pcn_default_settings(), $saved)
        : hithean_pcn_default_settings();

    return $settings;
}

function hithean_pcn_menu_scope_taxonomy_map(): array
{
    return [
        'global'   => '',
        'category' => 'product_cat',
        'tag'      => 'product_tag',
        'brand'    => 'thuong-hieu',
    ];
}

function hithean_pcn_is_safe_fragment(string $value): bool
{
    return (bool) preg_match('/^#[A-Za-z][A-Za-z0-9_:\-\.]*$/', $value);
}

function hithean_pcn_sanitize_menu_url(string $value): string
{
    $value = trim($value);
    if ($value === '' || strpos($value, '//') === 0) {
        return '';
    }

    if (strpos($value, '/') === 0 || strpos($value, '?') === 0) {
        return esc_url_raw($value, ['http', 'https']);
    }

    $url   = esc_url_raw($value, ['http', 'https']);
    $parts = $url !== '' ? wp_parse_url($url) : false;
    if (!is_array($parts) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
        return '';
    }

    return $url;
}

function hithean_pcn_sanitize_settings($input): array
{
    $input    = is_array($input) ? $input : [];
    $defaults = hithean_pcn_default_settings();
    $modes    = ['sticky_bar', 'floating_toc'];
    $scopes   = hithean_pcn_menu_scope_taxonomy_map();

    $out = [
        'desktop_mode' => in_array($input['desktop_mode'] ?? '', $modes, true) ? $input['desktop_mode'] : $defaults['desktop_mode'],
        'menus'        => [],
    ];

    foreach (array_slice((array) ($input['menus'] ?? []), 0, 30) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label            = sanitize_text_field((string) ($item['label'] ?? ''));
        $destination_type = ($item['destination_type'] ?? '') === 'internal' ? 'internal' : 'external';
        $destination      = trim((string) ($item['destination'] ?? ''));
        $scope            = sanitize_key((string) ($item['scope'] ?? 'global'));

        if ($label === '' || !array_key_exists($scope, $scopes)) {
            continue;
        }

        if ($destination_type === 'internal') {
            if (!hithean_pcn_is_safe_fragment($destination)) {
                continue;
            }
        } else {
            $destination = hithean_pcn_sanitize_menu_url($destination);
            if ($destination === '') {
                continue;
            }
        }

        $term_id = 0;
        if ($scope !== 'global') {
            $term_id = absint($item['term_id'] ?? 0);
            if ($term_id < 1 || !term_exists($term_id, $scopes[$scope])) {
                continue;
            }
        }

        $out['menus'][] = [
            'label'            => $label,
            'destination_type' => $destination_type,
            'destination'      => $destination,
            'scope'            => $scope,
            'term_id'          => $term_id,
        ];
    }

    return $out;
}

function hithean_pcn_external_menu_icon_svg(): string
{
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" focusable="false"><path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3ZM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg>';
}

function hithean_pcn_menu_matches_product(array $item, int $product_id): bool
{
    $scope = $item['scope'] ?? 'global';
    if ($scope === 'global') {
        return true;
    }

    $taxonomy = hithean_pcn_menu_scope_taxonomy_map()[$scope] ?? '';
    $term_id  = absint($item['term_id'] ?? 0);

    return $taxonomy !== '' && $term_id > 0 && has_term($term_id, $taxonomy, $product_id);
}

function hithean_pcn_get_product_menus(int $product_id): array
{
    static $cache = [];
    if (isset($cache[$product_id])) {
        return $cache[$product_id];
    }

    $settings = hithean_pcn_get_settings();
    $resolved = [];
    foreach ((array) ($settings['menus'] ?? []) as $item) {
        if (is_array($item) && hithean_pcn_menu_matches_product($item, $product_id)) {
            $resolved[] = $item;
        }
    }

    return $cache[$product_id] = $resolved;
}

if (is_admin()) {
    add_action('admin_init', function (): void {
        if (get_option(HITHEAN_PCN_SETTINGS_OPTION, null) === null) {
            add_option(HITHEAN_PCN_SETTINGS_OPTION, hithean_pcn_default_settings(), '', false);
        }

        register_setting('hithean_pcn_settings_group', HITHEAN_PCN_SETTINGS_OPTION, [
            'type'              => 'array',
            'sanitize_callback' => 'hithean_pcn_sanitize_settings',
        ]);
    });
}

/**
 * Only run the two script snippets generated by IVAR. Never echo arbitrary HTML
 * from a product meta field into the sticky menu.
 */
function hithean_pcn_proof_embed_script($raw, string $kind): string
{
    if (!is_string($raw) || $raw === '' || strlen($raw) > 12000 || !in_array($kind, ['qc', 'legal'], true)) {
        return '';
    }

    // The WYSIWYG field may wrap a pasted snippet in one paragraph or encode &.
    $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $attribute = $kind === 'qc' ? 'data-qc-widget' : 'data-sku';
    $pattern = '~^\s*(?:<p>\s*)?<script\s+src="([^"]+)"\s+' . $attribute
        . '="([^"]+)"\s+async\s*>\s*</script>\s*(?:</p>)?\s*$~i';
    if (!preg_match($pattern, $raw, $match)) {
        return '';
    }

    $src = $match[1];
    $path = $kind === 'qc'
        ? 'qc/assets/qc-embed.js'
        : 'product-legal/assets/legal-embed.js';
    if (!preg_match('~^https://ivarvietnam\.com/wp-content/themes/[^/]+/custom-functions/erp/modules/' . preg_quote($path, '~') . '$~i', $src)) {
        return '';
    }

    if ($kind === 'legal') {
        $sku = $match[2];
        if ($sku === '' || $sku !== trim($sku) || strlen($sku) > 100 || preg_match('/[\x00-\x1F\x7F<>]/', $sku)) {
            return '';
        }
        return '<script src="' . esc_url($src) . '" data-sku="' . esc_attr($sku) . '" async></script>';
    }

    $url = $match[2];
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || strcasecmp((string) ($parts['host'] ?? ''), 'ivarvietnam.com') !== 0
        || ($parts['path'] ?? '/') !== '/' || empty($parts['query'])
        || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return '';
    }
    parse_str($parts['query'], $query);
    $expected = ['ivar_qc_widget_embed', 'ivar_qc_widget_embed_config', 'ivar_qc_widget_expires', 'ivar_qc_widget_signature', 'ivar_qc_widget_format'];
    if (count($query) !== count($expected) || array_diff(array_keys($query), $expected)) {
        return '';
    }
    foreach ($expected as $key) {
        if (!isset($query[$key]) || !is_string($query[$key])) {
            return '';
        }
    }
    if (strlen($url) > 10000
        || !ctype_digit((string) ($query['ivar_qc_widget_embed'] ?? ''))
        || (int) $query['ivar_qc_widget_embed'] < 1
        || !preg_match('/^[A-Za-z0-9_-]{10,8192}$/', (string) ($query['ivar_qc_widget_embed_config'] ?? ''))
        || !ctype_digit((string) ($query['ivar_qc_widget_expires'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/i', (string) ($query['ivar_qc_widget_signature'] ?? ''))
        || ($query['ivar_qc_widget_format'] ?? '') !== 'fragment') {
        return '';
    }

    return '<script src="' . esc_url($src) . '" data-qc-widget="' . esc_attr($url) . '" async></script>';
}

function hithean_pcn_proof_embed_scripts(int $product_id): array
{
    return array_filter([
        'qc' => hithean_pcn_proof_embed_script(get_post_meta($product_id, 'product_info_kiem_nghiem', true), 'qc'),
        'legal' => hithean_pcn_proof_embed_script(get_post_meta($product_id, 'product_info_ho_so_phap_ly', true), 'legal'),
    ]);
}

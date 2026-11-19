<?php
defined('ABSPATH') || exit;

/**
 * Variables shared by consultation preview/copy and AI. Stored scripts/bundles retain tokens, never source-site URLs.
 * {sp:SKU:title|link|gia}, {nhom:slug:title|link}, {danh_muc:slug:title|link}, {thuong_hieu:slug:title|link}.
 * Only published products/variations and fixed public WooCommerce taxonomies are read. No arbitrary shortcode execution.
 */
function hcf_variable_tokens(array $texts): array
{
    $tokens = [];
    foreach ($texts as $text) {
        preg_match_all('/\{[^{}\r\n]{1,160}\}/u', (string) $text, $matches);
        foreach ($matches[0] as $token) {
            if ($token !== '{ten}') $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

/** Parse a token without querying WordPress. */
function hcf_variable_parse(string $token): ?array
{
    if (!preg_match('/^\{(sp|nhom|danh_muc|thuong_hieu):([^{}\r\n]{1,80}):(title|link|gia)\}$/u', $token, $m)) return null;
    if ($m[1] !== 'sp' && $m[3] === 'gia') return null;
    $ref = trim($m[2]);
    if ($ref === '') return null;
    if ($m[1] !== 'sp' && !preg_match('/^[a-z0-9%_-]+$/i', $ref)) return null;
    return ['kind' => $m[1], 'ref' => $ref, 'field' => $m[3]];
}

/** Convert rendered catalogue data to plain text (including WooCommerce currency entities). */
function hcf_variable_plain(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8')));
}

/** Read one public entity, reused for all fields of that entity in this request. */
function hcf_variable_entity(array $ref): array
{
    if ($ref['kind'] === 'sp') {
        $id = wc_get_product_id_by_sku($ref['ref']);
        $product = $id ? wc_get_product($id) : false;
        if (!$product || $product->get_status() !== 'publish') return [];
        if ($product->get_parent_id() && get_post_status($product->get_parent_id()) !== 'publish') return [];
        $price = '';
        if ($product->get_price() !== '') {
            if ($product->is_type('variable')) {
                $min = $product->get_variation_price('min', true);
                $max = $product->get_variation_price('max', true);
                if ($min !== '' && $max !== '') $price = wc_price((float) $min) . ((float) $max !== (float) $min ? ' – ' . wc_price((float) $max) : '');
            } else {
                $price = wc_price(wc_get_price_to_display($product));
            }
        }
        return ['title' => hcf_variable_plain($product->get_name()), 'link' => esc_url_raw($product->get_permalink(), ['http', 'https']), 'gia' => hcf_variable_plain($price)];
    }
    $taxonomies = ['nhom' => 'product_tag', 'danh_muc' => 'product_cat', 'thuong_hieu' => 'thuong-hieu'];
    $taxonomy = $taxonomies[$ref['kind']] ?? '';
    $object = $taxonomy ? get_taxonomy($taxonomy) : null;
    if (!$object || empty($object->public) || !in_array('product', (array) $object->object_type, true)) return [];
    $term = get_term_by('slug', $ref['ref'], $taxonomy);
    if (!$term || is_wp_error($term)) return [];
    $url = get_term_link($term);
    return ['title' => hcf_variable_plain($term->name), 'link' => is_wp_error($url) ? '' : esc_url_raw($url, ['http', 'https'])];
}

/** Resolve a bounded batch; missing/invalid variables stay visible and are returned as errors to block copying. */
function hcf_variables_resolve(array $texts): array
{
    $texts = array_values(array_map(static function ($text): string { return hcf_clean_text($text, 4000); }, array_slice($texts, 0, 30)));
    $tokens = hcf_variable_tokens($texts);
    if (count($tokens) > 60 || mb_strlen(implode('', $texts), 'UTF-8') > 40000) {
        return ['texts' => $texts, 'errors' => ['Quá nhiều biến hoặc nội dung quá dài (tối đa 60 biến / 40.000 ký tự).']];
    }
    $entities = $values = $errors = [];
    foreach ($tokens as $token) {
        $ref = hcf_variable_parse($token);
        if (!$ref) { $errors[] = 'Biến không hợp lệ: ' . $token; continue; }
        $key = $ref['kind'] . ':' . $ref['ref'];
        if (!array_key_exists($key, $entities)) $entities[$key] = hcf_variable_entity($ref);
        $value = $entities[$key][$ref['field']] ?? '';
        if ($value === '') { $errors[] = 'Không tìm thấy dữ liệu cho ' . $token . ' trên site này.'; continue; }
        $values[$token] = $value;
    }
    return ['texts' => array_map(static function (string $text) use ($values): string { return strtr($text, $values); }, $texts), 'errors' => $errors];
}

/** Resolve before giving scripts to AI; unavailable fields are explicitly marked, never guessed. */
function hcf_variable_ai_flow(array $flow): array
{
    $steps = [];
    foreach ((array) ($flow['steps'] ?? []) as $step) {
        $resolved = hcf_variables_resolve([$step['title'] ?? '', $step['say'] ?? '', $step['note'] ?? '']);
        $texts = array_map(static function (string $text): string {
            return preg_replace_callback('/\{[^{}\r\n]{1,160}\}/u', static function (array $m): string { return $m[0] === '{ten}' ? 'anh/chị' : '[DỮ LIỆU CHƯA CÓ]'; }, $text);
        }, $resolved['texts']);
        $steps[] = ['title' => $texts[0], 'say' => $texts[1], 'note' => $texts[2]];
        if ($resolved['errors']) $steps[count($steps) - 1]['missing_variables'] = $resolved['errors'];
    }
    $flow['steps'] = $steps;
    return $flow;
}

add_action('wp_ajax_hithean_consult_resolve', 'hcf_variables_ajax_resolve');
function hcf_variables_ajax_resolve(): void
{
    hcf_ajax_guard(false);
    $raw = $_POST['texts'] ?? '';
    if (!is_string($raw) || strlen($raw) > 200000) wp_send_json_error(['message' => 'Nội dung không hợp lệ hoặc quá dài.'], 400);
    $texts = json_decode(wp_unslash($raw), true);
    if (!is_array($texts) || count($texts) > 30 || array_filter($texts, static function ($v): bool { return !is_string($v) || mb_strlen($v, 'UTF-8') > 4000; })) {
        wp_send_json_error(['message' => 'Tối đa 30 đoạn văn bản, mỗi đoạn 4.000 ký tự.'], 400);
    }
    wp_send_json_success(hcf_variables_resolve($texts));
}

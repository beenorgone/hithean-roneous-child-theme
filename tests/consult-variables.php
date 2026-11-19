<?php
/** Variable contracts, public-data boundaries, fresh price reads and bundle portability without WordPress/network. */
define('ABSPATH', __DIR__);
function add_shortcode(...$args) {}
function add_action(...$args) {}
function wp_strip_all_tags($text) { return strip_tags($text); }
function esc_url_raw($url, $protocols = []) { return preg_match('~^https?://~', $url) ? $url : ''; }
function is_wp_error($value) { return $value instanceof RuntimeException; }
function wc_price($value) { return '<span>' . number_format($value, 0, ',', '.') . '&nbsp;₫</span>'; }
function wc_get_price_to_display($p) { return (float) $p->price; }
function wc_get_product_id_by_sku($sku) { $GLOBALS['lookups']++; return $GLOBALS['skus'][$sku] ?? 0; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
function get_post_status($id) { return $GLOBALS['statuses'][$id] ?? 'publish'; }
function get_taxonomy($tax) { return in_array($tax, ['product_cat', 'product_tag', 'thuong-hieu'], true) ? (object) ['public' => true, 'object_type' => ['product']] : null; }
function get_term_by($field, $slug, $tax) { return $slug === 'exists' ? (object) ['name' => 'Nhóm &amp; danh mục', 'slug' => $slug, 'taxonomy' => $tax] : false; }
function get_term_link($term) { return 'https://current.test/' . $term->taxonomy . '/' . $term->slug; }
class FixtureProduct {
    public $status = 'publish', $parent = 0, $price = '120000', $type = 'simple';
    public function get_status() { return $this->status; }
    public function get_parent_id() { return $this->parent; }
    public function get_name() { return 'Product &amp; title'; }
    public function get_permalink() { return 'https://current.test/product'; }
    public function get_price() { return $this->price; }
    public function is_type($type) { return $this->type === $type; }
    public function get_variation_price($mode, $display) { return $mode === 'min' ? '100000' : '200000'; }
}
require dirname(__DIR__) . '/custom-functions/shortcodes/shortcode-consult-faqs.php';
$checks = 0;
function check($ok, $msg) { global $checks; $checks++; if (!$ok) throw new RuntimeException($msg); }
$GLOBALS['skus'] = ['ABC-01' => 1, 'SKU:COLON' => 1];
$p = $GLOBALS['products'][1] = new FixtureProduct();
$GLOBALS['lookups'] = 0;
$r = hcf_variables_resolve(['Dạ {ten}: {sp:ABC-01:title} — {sp:ABC-01:gia}\n{sp:ABC-01:link}', '{sp:ABC-01:gia}']);
check(!$r['errors'] && strpos($r['texts'][0], 'Product & title') !== false && strpos($r['texts'][0], '120.000 ₫') !== false, 'title decoded and current price formatted as plain text');
check(strpos($r['texts'][0], '{ten}') !== false && strpos($r['texts'][0], 'https://current.test/product') !== false, 'customer token retained and destination-site permalink used');
check($GLOBALS['lookups'] === 1, 'all fields of one SKU share one lookup in a batch');
$p->price = '99000'; $r = hcf_variables_resolve(['{sp:ABC-01:gia}']);
check($r['texts'][0] === '99.000 ₫', 'next copy request reads changed price without stale cross-request cache');
$p->price = '0'; check(hcf_variables_resolve(['{sp:ABC-01:gia}'])['texts'][0] === '0 ₫', 'zero price is valid');
$p->price = ''; check(count(hcf_variables_resolve(['{sp:ABC-01:gia}'])['errors']) === 1, 'missing price is not invented as zero');
$p->price = '100000'; $p->type = 'variable';
check(hcf_variables_resolve(['{sp:ABC-01:gia}'])['texts'][0] === '100.000 ₫ – 200.000 ₫', 'variable product returns tax-display price range');
$p->type = 'simple'; $p->status = 'draft';
check(count(hcf_variables_resolve(['{sp:ABC-01:title}','{sp:ABC-01:link}'])['errors']) === 2, 'unpublished product data and links withheld');
$p->status = 'publish'; $p->parent = 10; $GLOBALS['statuses'][10] = 'draft';
check(count(hcf_variables_resolve(['{sp:ABC-01:title}'])['errors']) === 1, 'variation with unpublished parent withheld');
$p->parent = 0;
check(!hcf_variables_resolve(['{sp:SKU:COLON:title}'])['errors'], 'SKU may contain colon');
$r = hcf_variables_resolve(['{nhom:exists:title}\n{nhom:exists:link}\n{danh_muc:exists:link}\n{thuong_hieu:exists:link}']);
check(!$r['errors'] && strpos($r['texts'][0], '/product_tag/exists') !== false && strpos($r['texts'][0], '/product_cat/exists') !== false, 'groups and categories resolve distinct fixed taxonomies');
check(strpos($r['texts'][0], '/thuong-hieu/exists') !== false, 'brand slug supported');
foreach (['{nhom:missing:link}', '{sp:MISSING:title}', '{nhom:exists:gia}', '{option:api_key:title}', '{sp:ABC-01:password}', '{sp::title}'] as $token) {
 $r = hcf_variables_resolve([$token]); check(count($r['errors']) === 1 && $r['texts'][0] === $token, 'missing or forbidden variable remains visible for review: ' . $token);
}
$tokens = []; for ($i = 0; $i < 61; $i++) $tokens[] = '{sp:X' . $i . ':title}';
$before = $GLOBALS['lookups']; $r = hcf_variables_resolve([implode(' ', $tokens)]);
check(count($r['errors']) === 1 && $GLOBALS['lookups'] === $before, 'too many variables rejected before DB lookup');
$ai = hcf_variable_ai_flow(['steps' => [['title' => 'Info', 'say' => '{sp:ABC-01:title} {sp:MISSING:gia}', 'note' => '{ten}']]]);
check(strpos($ai['steps'][0]['say'], 'Product & title') !== false && strpos($ai['steps'][0]['say'], '[DỮ LIỆU CHƯA CÓ]') !== false && $ai['steps'][0]['missing_variables'], 'AI receives resolved data and explicit missing-data markers');
$bundle = json_decode(file_get_contents(dirname(__DIR__) . '/custom-functions/shortcodes/assets/consult-starter.json'), true);
check($bundle['format'] === HCF_BUNDLE_FORMAT && count($bundle['flows']) === 10, 'editable starter has ten portable scripts');
[$flows] = hcf_bundle_parse($bundle);
check(count(array_filter($flows, static function ($f) { return $f['status'] === 'draft'; })) === 10, 'all starters stay drafts until reviewed');
[$store,$created] = hcf_store_import(hcf_store_normalize(null), $flows, 't', 'u');
[$store,$created2,$updated2] = hcf_store_import($store, $flows, 't', 'u');
check($created === 10 && $created2 === 0 && $updated2 === 10, 'reimport does not duplicate starter titles');
$export = hcf_bundle_build($store['flows'], [], '', 'site-a', 't', 'flows');
check(strpos(json_encode($export), '{sp:SKU_CAN_SUA:title}') !== false && strpos(json_encode($export), 'current.test') === false, 'export keeps tokens rather than freezing source-site data');
echo "OK ($checks consultation variable checks)\n";

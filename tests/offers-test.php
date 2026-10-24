<?php
/** Standalone behavioral regression harness. No production DB, HTTP or AI calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__); define('HITHEAN_THEME_DIR', dirname(__DIR__));
define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400); define('MB_IN_BYTES', 1048576);
$GLOBALS['options'] = ['hithean_offers_settings' => ['source' => 'frontend']]; $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['events'] = []; $GLOBALS['http'] = []; $GLOBALS['requests'] = [];
function add_action(...$args) {} function add_shortcode(...$args) {} function is_admin() { return false; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, ...$args) { if (array_key_exists($key, $GLOBALS['options'])) { return false; } return update_option($key, $value); }
function wp_generate_uuid4() { static $id = 0; return 'token-' . ++$id; }
function maybe_serialize($value) { return serialize($value); }
function wp_cache_delete(...$args) {}
$GLOBALS['wpdb'] = new class {
    public $options = 'options';
    function prepare($sql, ...$args) { return $args; }
    function query($args) {
        [$key, $value] = $args;
        if (isset($GLOBALS['options'][$key]) && serialize($GLOBALS['options'][$key]) === $value) { unset($GLOBALS['options'][$key]); return 1; }
        return 0;
    }
};
function get_posts($args) {
    $result = [];
    foreach ($GLOBALS['posts'] as $id => $post) {
        if ($post->post_type !== $args['post_type'] || $post->post_status !== $args['post_status']) { continue; }
        if (isset($args['meta_key']) && !in_array($args['meta_value'], get_post_meta($id, $args['meta_key'], false))) { continue; }
        $result[] = ($args['fields'] ?? '') === 'ids' ? $id : $post;
    }
    return $result;
}
function wp_insert_post($data, ...$args) { $id = count($GLOBALS['posts']) + 1000; $GLOBALS['posts'][$id] = (object) ($data + ['ID' => $id]); return $id; }
function get_post_meta($id, $key, $single = true) {
    $values = $GLOBALS['multi_meta'][$id][$key] ?? (isset($GLOBALS['meta'][$id][$key]) ? [$GLOBALS['meta'][$id][$key]] : []);
    return $single ? ($values[0] ?? '') : $values;
}
function add_post_meta($id, $key, $value) { $GLOBALS['multi_meta'][$id][$key][] = $value; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key], $GLOBALS['multi_meta'][$id][$key]); }
function get_post_type($id) { return $GLOBALS['posts'][$id]->post_type ?? ''; }
function get_site_option($key, $default = []) { return $default; }
function wp_timezone() { return new DateTimeZone('Asia/Ho_Chi_Minh'); }
function admin_url($path) { return home_url('/wp-admin/' . $path); }
function get_edit_post_link($id, ...$args) { return admin_url('post.php?post=' . $id . '&action=edit'); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_textarea_field($value) { return strip_tags($value); }

function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function get_current_user_id() { return 5; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function sanitize_text_field($text) { return strip_tags($text); }
function home_url($path = '/') { return 'https://hithean.test' . $path; }
function wp_parse_url($url) { return parse_url($url); }
function get_permalink($id) { return home_url('/product/' . $id); }
function get_page_by_path($path) { return null; }
function do_action(...$args) {} function clean_post_cache(...$args) {}
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public function __construct(public $code, public $message) {} public function get_error_message() { return $this->message; } }
class WP_Http { public static function make_absolute_url($location, $url) { return str_starts_with($location, '/') ? home_url($location) : $location; } }
function wp_safe_remote_get($url, $args) { $GLOBALS['requests'][] = $url; return array_shift($GLOBALS['http']) ?? new WP_Error('offline', 'offline'); }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_header($response, $key) { return $response['headers'][$key] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook] ?? false; }
function wp_schedule_event($at, $recurrence, $hook) { $GLOBALS['recurrences'][$hook] = $recurrence; $GLOBALS['events'][$hook] = $at; }
function wp_schedule_single_event($at, $hook) { $GLOBALS['events'][$hook] = $at; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
function wp_get_attachment_image_url(...$args) { return 'https://hithean.test/image.png'; }
function wc_placeholder_img_src() { return 'https://hithean.test/placeholder.png'; }
function current_user_can(...$args) { return !empty($GLOBALS['can_manage']); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); } function esc_url($text) { return esc_html($text); }
function wp_date($format, $date, $tz = null) { return (new DateTimeImmutable('@' . $date))->setTimezone($tz ?: new DateTimeZone('Asia/Ho_Chi_Minh'))->format($format); }
function wc_get_cart_url() { return home_url('/cart'); } function wc_get_page_permalink($page) { return home_url('/shop'); }
function get_theme_mod($name, $default = false) { return $GLOBALS["theme_mods"][$name] ?? $default; } function get_bloginfo($show = "") { return "The An"; } function wp_kses_post($html) { return $html; } function get_term($id, $taxonomy = '') { return $id === 160 ? (object) ['term_id' => 160, 'name' => 'Trà thảo mộc'] : null; } function get_term_link($term) { return home_url('/danh-muc/' . $term->term_id); } function wp_list_pluck($list, $field) { return array_map(static fn($item) => is_object($item) ? $item->$field : $item[$field], $list); }
$GLOBALS['products'] = [];
class OfferTestProduct {
    public function __construct(public $id, public $name, public $stock = true, public $status = 'publish', public $visibility = 'visible') {}
    function get_id() { return $this->id; } function get_name() { return $this->name; } function get_status() { return $this->status; }
    public $type = 'simple', $children = [], $categories = [], $on_sale = false;
    function get_children() { return $this->children; }
    function get_sale_price(...$args) { return ''; } function get_regular_price(...$args) { return ''; }
    function get_parent_id() { return 0; } function get_catalog_visibility() { return $this->visibility; }
    function get_image_id() { return 1; } function get_price_html() { return '<span class="amount">100.000₫</span>'; } function is_in_stock() { return $this->stock; } function is_type($type) { return $this->type === $type; }
}
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
require HITHEAN_THEME_DIR . '/custom-functions/marketing/offers.php';
if (defined('HITHEAN_OFFERS_TEST_LIBRARY')) { return; }
$count = 0;
function check($condition, $message) { global $count; $count++; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } }
function throws(callable $call, string $message) { try { $call(); } catch (RuntimeException $error) { check(true, $message); return; } check(false, $message); }
if (!class_exists('DOMDocument')) { fwrite(STDERR, "PHP DOM extension required for parser tests.\n"); exit(2); }
$fixture = file_get_contents(__DIR__ . '/fixtures/offers-product.html');
$page = hithean_offers_parse($fixture);
check(count($page['candidates']) === 2, 'Only the product gift and order shipping are collected');
check($page['candidates'][0]['safe'] === true, 'Clear automatic gift accepted');
check(str_contains($page['candidates'][0]['title'], 'Hữu Cơ Tự động'), 'Inline text keeps word boundaries');
check($page['candidates'][1]['safe'] === false && $page['candidates'][1]['scope'] === 'order', 'Yearless order shipping requires review');
check($page['unresolved'] === [], 'Related, script and footer offers are excluded');
throws(fn() => hithean_offers_parse('<h1>Service unavailable</h1>'), 'Soft 200 error rejected');
$manual = hithean_offers_parse(str_replace('data-mode="auto"', 'data-mode="choice"', $fixture));
check(!$manual['candidates'][0]['safe'], 'Choice promotion never auto-publishes');
$range = hithean_offers_parse(str_replace('"max":0', '"max":2', $fixture));
check(!$range['candidates'][0]['safe'], 'Hidden upper quantity condition requires review');
$single = hithean_offers_candidate('MUA 1 TẶNG 1 tự động áp dụng hoặc quà khác', 'gift', true);
check(!$single['safe'], 'Alternative reward is not silently treated as unconditional');
$dates = hithean_offers_dates('Áp dụng từ 01/01/2030 đến hết 31/01/2030');
check($dates['start'] && $dates['end'] > $dates['start'] && $dates['reason'] === '', 'Explicit Vietnamese date range');
check(hithean_offers_dates('đến hết 31/02/2030')['reason'] !== '', 'Invalid calendar date rejected');
check(hithean_offers_dates('đến hết 31/10')['reason'] !== '', 'No current-year inference');
check(hithean_offers_dates('đến hết 2030-01-31')['reason'] !== '', 'Unsupported date format held for review');
$price_html = str_replace('<p class="price"><span>450.000 ₫</span></p>', '<p class="price"><del>500.000 ₫</del><ins>450.000 ₫</ins></p>', $fixture);
$price = hithean_offers_parse($price_html);
check(count($price['candidates']) === 3 && $price['candidates'][2]['safe'], 'Exact single sale price accepted');
$variable = hithean_offers_parse(str_replace('<p class="stock">', '<form class="variations_form"></form><p class="stock">', $price_html));
check(!$variable['candidates'][2]['safe'], 'Variable price requires review');
check(hithean_offers_allowed_url(home_url('/product/1')), 'Own-origin URL accepted');
foreach (['https://outside.test/p', 'https://user:pass@hithean.test/p', 'http://hithean.test/p', 'https://hithean.test:444/p'] as $url) { check(!hithean_offers_allowed_url($url), 'Unsafe origin denied'); }
$GLOBALS['http'] = [['status' => 302, 'headers' => ['location' => 'https://outside.test/p']]];
throws(fn() => hithean_offers_fetch(home_url('/p')), 'External redirect blocked');
check(count($GLOBALS['requests']) === 1, 'No request to redirect destination');
$GLOBALS['http'] = [['status' => 429, 'headers' => []]];
throws(fn() => hithean_offers_fetch(home_url('/p')), 'Rate-limited page is not a successful empty parse');
$lock = hithean_offers_lock('test');
check($lock !== '' && hithean_offers_lock('test') === '', 'Atomic duplicate lock');
hithean_offers_unlock('test', 'wrong'); check(hithean_offers_lock('test') === '', 'Wrong owner cannot unlock');
hithean_offers_unlock('test', $lock); check(hithean_offers_lock('test') !== '', 'Owner release succeeds');
$GLOBALS['options']['hithean_offers_lock_test']['until'] = time() - 1;
check(hithean_offers_lock('test') !== '', 'Expired lock recovers');
$GLOBALS['products'][123] = new OfferTestProduct(123, $page['title']);
$GLOBALS['posts'][123] = (object) ['ID' => 123, 'post_type' => 'product', 'post_status' => 'publish'];
hithean_offers_reconcile(123, $page['candidates'], home_url('/product/123'));
$rows = hithean_offers_records(123); check(count($rows) === 2, 'Records persisted');
hithean_offers_reconcile(123, $page['candidates'], home_url('/product/123')); check(count(hithean_offers_records(123)) === 2, 'Idempotent collection');
$row = $rows[0]; $row['status'] = 'rejected'; $row['decision'] = 'reject'; hithean_offers_save($row);
hithean_offers_reconcile(123, $page['candidates'], home_url('/product/123')); check(hithean_offers_records(123)[0]['status'] === 'rejected', 'Exact-source rejection survives scan');
$row['status'] = 'active'; $row['decision'] = 'approve'; hithean_offers_save($row);
hithean_offers_publish(); check(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 1, 'Only approved/clear active offers in public snapshot');
$html = hithean_offers_shortcode(); check(!str_contains($html, 'MUA 1 Matcha'), 'Preview hidden from public visitor');
update_option('hithean_offers_settings', ['preview' => false, 'ai_enabled' => false, 'source' => 'frontend']);
$html = hithean_offers_shortcode(); check(str_contains($html, 'MUA 1 Matcha') && !str_contains($html, '600.000'), 'Only eligible public gift appears');
$stale = $row; $stale['verified_at'] = time() - HITHEAN_OFFERS_FRESHNESS;
check(hithean_offers_status($stale) === 'stale', 'Freshness boundary');
$expired = $row; $expired['end'] = time() - 1; check(hithean_offers_status($expired) === 'expired', 'Expiry overrides freshness');
$scheduled = $row; $scheduled['start'] = time() + 600; check(hithean_offers_status($scheduled) === 'scheduled', 'Future start not public');
$run = hithean_offers_start(123); $duplicate = hithean_offers_start(); check($run['id'] === $duplicate['id'], 'Manual/cron starts share one queue');
$changed = $page['candidates']; $changed[0]['title'] = $changed[0]['evidence'] = 'MUA 2 TẶNG 1 Tự động áp dụng';
hithean_offers_reconcile(123, $changed, home_url('/product/123'));
hithean_offers_publish(); check(get_option(HITHEAN_OFFERS_SNAPSHOT)['items'][0]['title'] === $page['candidates'][0]['title'], 'Boundary keeps committed snapshot during scan');
$GLOBALS['http'] = [['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => $fixture]];
hithean_offers_work(); check(get_option(HITHEAN_OFFERS_RUN)['status'] === 'complete', 'Worker completes queue');
check(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 1, 'Final snapshot published');
$old_snapshot = get_option(HITHEAN_OFFERS_SNAPSHOT);
hithean_offers_start(123);
for ($i = 0; $i < 3; $i++) { $GLOBALS['http'] = [new WP_Error('offline', 'offline')]; hithean_offers_work(); }
$failed = get_option(HITHEAN_OFFERS_RUN);
check($failed['status'] === 'partial' && $failed['failed'] === 1 && $failed['cursor'] === 1, 'Two retries then one recorded product failure');
check(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === count($old_snapshot['items']), 'Fetch failure preserves fresh offers');
$GLOBALS['options'][HITHEAN_OFFERS_RUN] = [];
hithean_offers_reconcile(123, [], home_url('/product/123')); hithean_offers_publish();
check(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 0, 'Successful confirmed removal removes public offer');
hithean_offers_reconcile(123, $page['candidates'], home_url('/product/123'));
check(empty(hithean_offers_records(123)[0]['decision']), 'Removed then returned source requires a fresh decision');
$GLOBALS['products'][123]->status = 'draft'; hithean_offers_publish();
check(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 0, 'Unpublished product excluded');
$GLOBALS['products'][123]->status = 'publish';
hithean_offers_reconcile(123, $page['candidates'], home_url('/product/123'));
$approved = array_values(array_filter(hithean_offers_records(123), static fn($r) => $r['kind'] === 'gift' && $r['status'] !== 'removed'))[0];
$approved['decision'] = 'approve'; $approved['status'] = 'active'; hithean_offers_save($approved);
hithean_offers_reconcile(123, $range['candidates'], home_url('/product/123'));
$changed_condition = array_values(array_filter(hithean_offers_records(123), static fn($r) => $r['kind'] === 'gift' && $r['status'] !== 'removed'))[0];
check($changed_condition['status'] === 'review' && empty($changed_condition['decision']), 'A changed structural quantity condition invalidates approval');
$context = hithean_offers_parse(str_replace('<h4>Ưu đãi:</h4>', '<h4>Ưu đãi: đến hết 31/10</h4>', $fixture));
check(!$context['candidates'][0]['safe'] && str_contains($context['candidates'][0]['evidence'], '31/10'), 'Common addon deadline stays in evidence and requires review');
$new_markup = hithean_offers_parse(str_replace('product_addon-title', 'new-promotion-title', $fixture));
check(count($new_markup['unresolved']) > 0, 'Unknown promotion markup is routed to text review');
$ai_off = hithean_offers_ai(['Mua 1 tặng 1 cho khách mới']);
check(count($ai_off['candidates']) === 1 && !$ai_off['candidates'][0]['safe'], 'No AI provider still retains a review proposal');
$run = hithean_offers_start(123);
update_option('hithean_offers_stop_requested', $run['id']);
$requests_before = count($GLOBALS['requests']);
hithean_offers_work();
check(get_option(HITHEAN_OFFERS_RUN)['status'] === 'stopped' && count($GLOBALS['requests']) === $requests_before, 'Stop request does not issue another HTTP request');
$next_run = hithean_offers_start(123);
check($next_run['id'] !== $run['id'], 'A new run is not stopped by an old stop request');
$GLOBALS['http'] = [['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => str_replace($page['title'], 'Wrong product', $fixture)]];
hithean_offers_work();
check(get_option(HITHEAN_OFFERS_RUN)['attempts'] === 1, 'Wrong product response is retried instead of removing offers');
$GLOBALS['options'][HITHEAN_OFFERS_RUN] = ['status' => 'stopped'];
$public_row = array_merge($old_snapshot['items'][0], ['title' => '<script>alert(1)</script>', 'status' => 'active', 'verified_at' => time(), 'end' => 0]);
update_option(HITHEAN_OFFERS_SNAPSHOT, ['items' => [$public_row]]);
$public_html = hithean_offers_shortcode();
check(!str_contains($public_html, '<script>alert(1)</script>') && str_contains($public_html, '&lt;script&gt;'), 'Offer titles are escaped in HTML');
$public_row['verified_at'] = time() - HITHEAN_OFFERS_FRESHNESS;
update_option(HITHEAN_OFFERS_SNAPSHOT, ['items' => [$public_row]]);
check(!str_contains(hithean_offers_shortcode(), 'alert(1)'), 'Stale snapshot cannot leak an expired offer on server render');
$hidden = hithean_offers_parse(str_replace('<label data-mode=', '<label style="display: none" data-mode=', $fixture));
check(count(array_filter($hidden['candidates'], static fn($r) => $r['kind'] === 'gift')) === 0, 'Inline hidden gifts are excluded');
$product_lock = hithean_offers_lock('product_123', 30);
throws(fn() => hithean_offers_reconcile(123, [], home_url('/p')), 'Concurrent review/reconciliation cannot overwrite the same product');
hithean_offers_unlock('product_123', $product_lock);
$proposal = $ai_off['candidates'][0];
hithean_offers_reconcile(789, [$proposal], home_url('/product/789'));
$reviewed = hithean_offers_records(789)[0];
$reviewed['decision'] = 'approve'; $reviewed['status'] = 'active'; $reviewed['kind'] = 'shipping'; $reviewed['scope'] = 'order'; $reviewed['end'] = time() + 86400;
hithean_offers_save($reviewed);
hithean_offers_reconcile(789, [$proposal], home_url('/product/789'));
$retained = hithean_offers_records(789)[0];
check($retained['kind'] === 'shipping' && $retained['scope'] === 'order' && $retained['end'] === $reviewed['end'], 'Verified category, scope and dates survive unchanged-source collection');
echo "PASS: $count behavioral assertions\n";

<?php
/** Integration-contract tests for source identity, shared review and product projection. */
namespace Wdr\App\Helpers {
    // Models only the product-filter contract; production matching is smoke-tested with the actual plugin.
    class Filter {
        public function matchFilters($product, $filters, ...$args) {
            $status = false;
            foreach ($filters as $filter) {
                if ($filter->type === 'all_products') { $status = true; continue; }
                $in = $filter->type === 'products' ? in_array($product->get_id(), $filter->value) : count(array_intersect($product->categories, $filter->value)) > 0;
                if (($filter->method ?? 'in_list') === 'not_in_list') { if ($in) { return false; } }
                elseif ($in) { $status = true; }
            }
            return $status;
        }
    }
}
namespace {
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('HITHEAN_OFFERS_TEST_LIBRARY', true);
require __DIR__ . '/offers-test.php';
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { throw new \RuntimeException('FAIL: ' . $message); } }
function denied($call, $message) { try { $call(); } catch (\RuntimeException $e) { ok(true, $message); return; } ok(false, $message); }
function simple_addon_is_product_addon_feature_enabled($id) { return $GLOBALS['addon_enabled'][$id] ?? true; }
function simple_addon_get_product_addons($id) { return $GLOBALS['addons'][$id] ?? []; }
function simple_addon_build_display_choices($addons) { return $addons; }
function thean_lw_get_settings() { return ['enabled' => $GLOBALS['wheel_enabled'] ?? false]; }
function thean_lw_active_rewards() { return $GLOBALS['rewards'] ?? []; }
function thean_lw_coupon_hold_hours() { return 48; }
function has_term(...$args) { return true; }
$GLOBALS['options'] = ['hithean_offers_settings' => ['source' => 'database', 'preview' => false]];
$GLOBALS['products'] = []; $GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['multi_meta'] = [];
foreach ([11, 12, 13] as $id) {
    $p = new \OfferTestProduct($id, 'Test product ' . $id); $p->categories = [160]; $GLOBALS['products'][$id] = $p;
    $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'publish'];
}
$raw = ['id' => 1, 'enabled' => 1, 'deleted' => 0, 'title' => 'Internal rule name', 'discount_type' => 'wdr_bulk_discount',
    'filters' => json_encode([['type' => 'product_category', 'method' => 'in_list', 'value' => [160]], ['type' => 'products', 'method' => 'not_in_list', 'value' => [13]]]),
    'conditions' => '[]', 'bulk_adjustments' => json_encode(['operator' => 'product_cumulative', 'ranges' => [['from' => 2, 'to' => 2, 'type' => 'percentage', 'value' => 5], ['from' => 3, 'to' => '', 'type' => 'percentage', 'value' => 10]]]),
    'usage_limits' => 0, 'used_limits' => 1, 'date_from' => null, 'date_to' => null];
$sources = hithean_offers_wdr_sources([$raw], $GLOBALS['products']); $source = $sources[0];
ok($source['product_ids'] === [11, 12], 'Category match and product exclusion use the plugin filter contract');
ok(str_contains($source['title'], '5%') && str_contains($source['title'], '10%') && str_contains($source['title'], 'cộng dồn'), 'Bulk tiers and cumulative semantics are retained');
ok(!str_contains($source['title'], 'Internal'), 'Internal rule names are not automatically published');
$id = hithean_offers_upsert_source($source, 'r1');
ok(count(hithean_offers_records()) === 1 && count(hithean_offers_records(11)) === 1 && count(hithean_offers_records(12)) === 1, 'One canonical rule has indexed links to multiple products');
$row = hithean_offers_records()[0];
$fields = ['verified' => 1, 'no_end' => 1, 'kind' => 'quantity', 'scope' => 'product', 'title' => $source['title']];
denied(fn() => hithean_offers_review($id, $row['key'], 'approve', $fields), 'Unauthenticated caller cannot approve a shared rule');
$GLOBALS['can_manage'] = true;
hithean_offers_review($id, $row['key'], 'approve', $fields); hithean_offers_publish();
ok(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 2, 'One approval publishes the same rule on two products');
$html = hithean_offers_shortcode();
ok(substr_count($html, 'class="ho-card"') === 2, 'Landing renders both associated products');
ok(!str_contains($html, 'ho-logo'), 'Landing falls back to the text wordmark without a site logo');
$GLOBALS['theme_mods']['custom_logo'] = 7;
ok(substr_count(hithean_offers_shortcode(), '<img src="https://hithean.test/image.png" alt="The An">') === 2, 'Landing nav and footer use the site logo');
unset($GLOBALS['theme_mods']);
$raw['used_limits'] = 2;
$next = hithean_offers_wdr_sources([$raw], $GLOBALS['products'])[0];
ok($next['config_hash'] === $source['config_hash'], 'Ordinary usage-counter increments do not invalidate approval');
$p = new \OfferTestProduct(14, 'New matching product'); $p->categories = [160]; $GLOBALS['products'][14] = $p;
$next = hithean_offers_wdr_sources([$raw], $GLOBALS['products'])[0];
hithean_offers_upsert_source($next, 'r2'); hithean_offers_publish();
ok(hithean_offers_records()[0]['decision'] === 'approve' && count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 3, 'Approved rule automatically covers newly matching products');
ok(count(hithean_offers_records(14)) === 1, 'New product association is indexed');
$raw2 = $raw; $raw2['id'] = 2;
hithean_offers_upsert_source(hithean_offers_wdr_sources([$raw2], $GLOBALS['products'])[0], 'r2');
ok(count(hithean_offers_records()) === 2 && hithean_offers_records()[1]['status'] === 'review', 'Different rule IDs never inherit approval merely because their text matches');
hithean_offers_review($id, $row['key'], 'reject', []); hithean_offers_publish();
ok(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 0, 'One rejection removes the rule from every product');
hithean_offers_upsert_source($next, 'r3'); ok(hithean_offers_records()[0]['status'] === 'rejected', 'Rejection survives unchanged configuration');
$rejected = get_post_meta($id, '_ho_data', true);
$GLOBALS['can_manage'] = false;
denied(fn() => hithean_offers_review($id, $row['key'], 'restore', []), 'Unauthenticated caller cannot restore a rejected rule');
$GLOBALS['can_manage'] = true;
denied(fn() => hithean_offers_review($id, 'outdated-key', 'restore', []), 'Stale restore form cannot modify source terms');
hithean_offers_review($id, $row['key'], 'restore', []); hithean_offers_publish();
$restored = get_post_meta($id, '_ho_data', true);
ok($restored['status'] === 'review' && $restored['decision'] === 'review' && !get_option(HITHEAN_OFFERS_SNAPSHOT)['items'], 'Restore returns the shared rule to review without publishing');
ok($restored['verified_at'] === $rejected['verified_at'] && $restored['product_ids'] === $rejected['product_ids'], 'Restore preserves verification time and all product associations');
$audit = get_post_meta($id, '_ho_audit', true);
ok(end($audit)['action'] === 'restore', 'Restore records the manager action in the audit history');
hithean_offers_upsert_source($next, 'restored-sync');
ok(get_post_meta($id, '_ho_data', true)['status'] === 'review', 'Pending restoration survives an unchanged source sync');
denied(fn() => hithean_offers_review($id, $row['key'], 'restore', []), 'Only rejected cards can be restored');
hithean_offers_review($id, $row['key'], 'approve', $fields);
$changed = $raw; $changed['priority'] = 99;
hithean_offers_upsert_source(hithean_offers_wdr_sources([$changed], $GLOBALS['products'])[0], 'r4');
$new_row = hithean_offers_records()[0];
ok($new_row['status'] === 'review' && empty($new_row['decision']), 'Changed source priority/conditions require one fresh rule approval');
denied(fn() => hithean_offers_review($id, $row['key'], 'approve', $fields), 'Stale approval form cannot approve changed source terms');
$blocked = $raw; $blocked['id'] = 3; $blocked['conditions'] = json_encode([['type' => 'user_list', 'options' => ['value' => [42]]]]);
$blocked_source = hithean_offers_wdr_sources([$blocked], $GLOBALS['products'])[0];
$blocked_id = hithean_offers_upsert_source($blocked_source, 'r4'); $blocked_row = get_post_meta($blocked_id, '_ho_data', true);
ok($blocked_row['blocked'], 'Private customer rule is blocked from general retail publication');
denied(fn() => hithean_offers_review($blocked_id, $blocked_row['key'], 'approve', $fields), 'A private rule cannot be published through the generic approval action');
$unsupported = $raw; $unsupported['id'] = 4; $unsupported['filters'] = json_encode([['type' => 'unknown_custom_filter']]);
$unknown = hithean_offers_wdr_sources([$unsupported], $GLOBALS['products'])[0];
ok($unknown['blocked'] && !$unknown['product_ids'], 'Unknown product filters fail closed instead of applying to the catalog');
$exhausted = $raw; $exhausted['usage_limits'] = 2; $exhausted['used_limits'] = 2;
ok(hithean_offers_wdr_sources([$exhausted], $GLOBALS['products']) === [], 'Exhausted usage limit is excluded');
$wall = gmmktime(23, 59, 0, 12, 1, 2030);
ok(wp_date('Y-m-d H:i', hithean_offers_wdr_date($wall)) === '2030-12-01 23:59', 'WDR wall-clock timestamps are converted with the site timezone');
$past = $raw; $past['id'] = 5; $past['date_to'] = time() - 86400;
$past_id = hithean_offers_upsert_source(hithean_offers_wdr_sources([$past], $GLOBALS['products'])[0], 'r4');
$past_row = get_post_meta($past_id, '_ho_data', true);
ok(hithean_offers_status($past_row) === 'expired', 'Expired structured rules are shown as expired even before review');
denied(fn() => hithean_offers_review($past_id, $past_row['key'], 'approve', $fields + ['end' => '2099-01-01']), 'Approval cannot extend an expired plugin rule date');
// Add a second independently approved rule to the same products.
hithean_offers_review($id, $new_row['key'], 'approve', $fields);
$second = hithean_offers_records()[1]; hithean_offers_review($second['id'], $second['key'], 'approve', $fields);
hithean_offers_publish(); $html = hithean_offers_shortcode();
ok(substr_count($html, 'class="ho-card"') === 3 && substr_count($html, 'data-ho-rule ') === 6, 'A product card combines multiple approved rules without duplicate product cards');
$before = count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']);
hithean_offers_finish_structured(['id' => 'failed', 'source_success' => [], 'product_success' => []]); hithean_offers_publish();
ok(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === $before, 'Failed source reads do not withdraw old rules');
hithean_offers_finish_structured(['id' => 'empty-success', 'source_success' => ['wdr'], 'product_success' => []]); hithean_offers_publish();
ok(count(get_option(HITHEAN_OFFERS_SNAPSHOT)['items']) === 0, 'Successful absence/disablement removes a shared rule for all products');
denied(fn() => hithean_offers_review($id, $new_row['key'], 'restore', []), 'Removed or disabled source cannot be restored');
hithean_offers_upsert_source($next, 'returned');
ok(empty(hithean_offers_records()[0]['decision']), 'A removed then returned source requires a new decision');
// Wheel eligibility is an opportunity to claim, never an assigned or automatic coupon.
$GLOBALS['wheel_enabled'] = true;
$GLOBALS['rewards'] = [['id' => 'reward5', 'label' => 'Internal label', 'type' => 'percent', 'amount' => 5, 'frequency' => 1, 'min_cart' => 500000, 'active' => true]];
$wheel = hithean_offers_wheel_sources($GLOBALS['products']);
ok(count($wheel) === 1 && count($wheel[0]['product_ids']) === 4 && str_contains($wheel[0]['title'], 'Cần quay') && !$wheel[0]['safe'], 'Wheel reward is a shared review-first claim opportunity');
$GLOBALS['rewards'][] = ['id' => 'reward20k', 'label' => 'Private prize label', 'type' => 'fixed_cart', 'amount' => 20000, 'frequency' => 2, 'min_cart' => 300000, 'active' => true];
$wheel = hithean_offers_wheel_sources($GLOBALS['products']);
ok(count($wheel) === 1 && $wheel[0]['source_id'] === 'wheel:program' && count($wheel[0]['source_config']['rewards']) === 2, 'Multiple prizes form one canonical program while retaining private source evidence');
ok(!str_contains($wheel[0]['title'], '5%') && !str_contains($wheel[0]['title'], '20.000') && !str_contains($wheel[0]['title'], 'Private prize label'), 'Wheel summary does not enumerate reward values or internal labels');
$GLOBALS['rewards'] = array_reverse($GLOBALS['rewards']);
ok(hithean_offers_wheel_sources($GLOBALS['products'])[0]['config_hash'] === $wheel[0]['config_hash'], 'Reordering the same prizes does not invalidate a program approval');
$legacy_wheel = hithean_offers_source('wheel:reward5', 'wheel', ['amount' => 5], [11, 12], 'Legacy prize', 'other', 'order');
$legacy_id = hithean_offers_upsert_source($legacy_wheel, 'legacy-wheel');
$legacy_row = get_post_meta($legacy_id, '_ho_data', true);
hithean_offers_review($legacy_id, $legacy_row['key'], 'approve', $fields);
$program_id = hithean_offers_upsert_source($wheel[0], 'grouped-wheel');
$program_row = get_post_meta($program_id, '_ho_data', true);
ok($program_row['status'] === 'review' && empty($program_row['decision']), 'Program approval is not inherited from an individual prize');
hithean_offers_finish_structured(['id' => 'grouped-wheel', 'source_success' => ['wheel'], 'product_success' => []]);
ok(get_post_meta($legacy_id, '_ho_data', true)['status'] === 'removed', 'Successful wheel sync retires old per-prize cards');
$program_fields = $fields; $program_fields['title'] = $wheel[0]['title'];
hithean_offers_review($program_id, $program_row['key'], 'approve', $program_fields); hithean_offers_publish();
$program_html = hithean_offers_shortcode();
if (getenv('HITHEAN_OFFERS_PUBLIC_PREVIEW')) { file_put_contents(getenv('HITHEAN_OFFERS_PUBLIC_PREVIEW'), $program_html); }
ok(substr_count($program_html, 'class="ho-card"') === 1 && substr_count($program_html, 'data-ho-rule ') === 1 && str_contains($program_html, 'data-ho-wheel-open'), 'Landing renders one wheel program across all eligible products with a wheel CTA');
ok(str_contains($program_html, 'Vòng quay may mắn') && !str_contains($program_html, 'Legacy prize') && !str_contains($program_html, 'Private prize label'), 'Public wheel card shows only a program summary');
$GLOBALS['rewards'][0]['amount'] = 30000;
$changed_wheel = hithean_offers_wheel_sources($GLOBALS['products'])[0];
hithean_offers_upsert_source($changed_wheel, 'changed-wheel');
ok(get_post_meta($program_id, '_ho_data', true)['status'] === 'review', 'Any changed prize terms require one new program review');
// New database worker never issues HTTP requests, including per-product invocation.
$GLOBALS['wheel_enabled'] = false; $GLOBALS['requests'] = []; $GLOBALS['options'][HITHEAN_OFFERS_RUN] = [];
$run = hithean_offers_start(11); hithean_offers_work();
ok($run['mode'] === 'database' && $GLOBALS['requests'] === [], 'Manual database sync does not fetch any frontend URL');
ok(get_option(HITHEAN_OFFERS_RUN)['status'] === 'complete', 'Database queue finishes through the same cron/manual worker');
// A variation-specific rule attaches only with an explicit partial-variation qualifier.
class VariantOfferProduct extends \OfferTestProduct {
    public function get_parent_id() { return 21; }
}
$parent = new \OfferTestProduct(21, 'Variable test product'); $parent->type = 'variable'; $parent->children = [211, 212];
$GLOBALS['products'][21] = $parent;
$GLOBALS['products'][211] = new VariantOfferProduct(211, 'Variation one');
$GLOBALS['products'][212] = new VariantOfferProduct(212, 'Variation two');
$variant_raw = $raw; $variant_raw['id'] = 9;
$variant_raw['filters'] = json_encode([['type' => 'products', 'method' => 'in_list', 'value' => [211]]]);
$variant_source = hithean_offers_wdr_sources([$variant_raw], [21 => $parent])[0];
ok($variant_source['product_ids'] === [21] && $variant_source['matches'][21]['variation_ids'] === [211] && $variant_source['matches'][21]['partial'], 'Variation-only eligibility is projected to the parent without claiming every variation');
$variant_id = hithean_offers_upsert_source($variant_source, 'variants');
$variant_row = get_post_meta($variant_id, '_ho_data', true);
hithean_offers_review($variant_id, $variant_row['key'], 'approve', $fields); hithean_offers_publish();
$variant_items = array_values(array_filter(get_option(HITHEAN_OFFERS_SNAPSHOT)['items'], static fn($r) => $r['product_id'] === 21));
ok(count($variant_items) === 1 && !empty($variant_items[0]['condition_note']), 'Public snapshot carries the partial-variation qualifier');
$GLOBALS['addons'][11] = [['value' => 'gift', 'name' => 'Test gift', 'mode' => 'auto', 'ranges' => [['min' => 2, 'max' => 5]], 'addons' => [['type' => 'product', 'status' => 'ON', 'target' => 13]]]];
$addon_sources = hithean_offers_product_sources($GLOBALS['products'][11]);
ok(count($addon_sources) === 1 && str_contains($addon_sources[0]['title'], 'từ 2 đến 5') && !$addon_sources[0]['safe'], 'Addon quantity ranges stay explicit and review-first');
$GLOBALS['addon_enabled'][11] = false; update_post_meta(11, 'product_addon_sale_message', 'Test stale message');
ok(hithean_offers_product_sources($GLOBALS['products'][11]) === [], 'Disabled addon suppresses both choices and leftover sale messages');
class SaleOfferProduct extends \OfferTestProduct {
    public function get_sale_price(...$args) { return '0'; }
    public function get_regular_price(...$args) { return '50000'; }
    public function get_date_on_sale_from(...$args) { return null; }
    public function get_date_on_sale_to(...$args) { return null; }
}
$free_sale = hithean_offers_product_sources(new SaleOfferProduct(31, 'Free sale test'));
ok(count($free_sale) === 1 && $free_sale[0]['safe'] && str_contains($free_sale[0]['title'], '0 ₫'), 'A stored zero sale price is distinct from an unset sale field');
$midnight = (new \DateTimeImmutable('2030-12-01 00:00:00', wp_timezone()))->getTimestamp();
ok(hithean_offers_date_label(['end' => $midnight]) === 'Đến 00:00 01/12/2030', 'Midnight cutoff is not advertised as valid through the whole date');
$GLOBALS['addon_enabled'][11] = true; $GLOBALS['addons'][11] = [];
update_post_meta(11, 'product_addon_sale_message', 'Ưu đãi kết thúc đến hết 31/10');
$message_source = hithean_offers_product_sources($GLOBALS['products'][11])[0];
$message_id = hithean_offers_upsert_source($message_source, 'message');
$message_row = get_post_meta($message_id, '_ho_data', true);
denied(fn() => hithean_offers_review($message_id, $message_row['key'], 'approve', $fields), 'Missing-year prose requires explicit date confirmation');
$confirmed = $fields; $confirmed['end'] = '2030-10-31';
hithean_offers_review($message_id, $message_row['key'], 'approve', $confirmed);
$confirmed_end = get_post_meta($message_id, '_ho_data', true)['end'];
hithean_offers_upsert_source($message_source, 'message-again');
ok(get_post_meta($message_id, '_ho_data', true)['end'] === $confirmed_end, 'Confirmed prose dates survive an unchanged-source refresh');
// Even otherwise automatic sale sources must wait for approval after restoration.
$automatic = hithean_offers_source('sale:restore-test', 'sale', [], [11], 'Sale test', 'discount');
$automatic['safe'] = true;
$automatic_id = hithean_offers_upsert_source($automatic, 'auto');
$automatic_row = get_post_meta($automatic_id, '_ho_data', true);
hithean_offers_review($automatic_id, $automatic_row['key'], 'reject', []);
hithean_offers_review($automatic_id, $automatic_row['key'], 'restore', []);
hithean_offers_upsert_source($automatic, 'auto-again');
ok(get_post_meta($automatic_id, '_ho_data', true)['status'] === 'review', 'An automatic sale cannot bypass restored pending review');
// Weekly scheduling migrates existing daily events, preserves switches and never duplicates jobs.
$GLOBALS['events']['hithean_offers_daily'] = time() + 60;
$GLOBALS['options']['hithean_offers_settings']['enabled'] = true;
hithean_offers_sync_schedule();
$weekly_at = wp_next_scheduled('hithean_offers_weekly');
ok(!wp_next_scheduled('hithean_offers_daily') && $GLOBALS['recurrences']['hithean_offers_weekly'] === 'weekly' && abs($weekly_at - time() - 7 * DAY_IN_SECONDS) < 3, 'Existing daily event migrates to one weekly event');
hithean_offers_sync_schedule();
ok(wp_next_scheduled('hithean_offers_weekly') === $weekly_at, 'Repeated initialization does not move the weekly schedule');
$GLOBALS['options']['hithean_offers_settings']['enabled'] = false;
hithean_offers_sync_schedule();
ok(!wp_next_scheduled('hithean_offers_weekly'), 'Manual-only mode clears automatic events');
$fresh = get_post_meta($automatic_id, '_ho_data', true); $fresh['status'] = 'active';
ok(hithean_offers_status($fresh, $fresh['verified_at'] + 7 * DAY_IN_SECONDS) === 'active', 'Sources remain fresh throughout the weekly interval');
ok(hithean_offers_status($fresh, $fresh['verified_at'] + 8 * DAY_IN_SECONDS) === 'stale', 'Unverified sources hide at the eight-day freshness boundary');
$fresh['end'] = time() + 60;
ok(hithean_offers_status($fresh, time() + 61) === 'expired', 'Explicit source expiry still takes priority over weekly freshness');
$css = file_get_contents(dirname(__DIR__) . '/pages/uu-dai/uu-dai.css');
preg_match('/\.ho-button-dark\s*\{([^}]+)\}/', $css, $match);
ok(str_contains($match[1], 'var(--default-color-dark-blue'), 'Landing CTA uses the Add to cart button colour');
foreach (['.ho-button', '.ho-nav-cart', '.ho-filters button'] as $selector) {
    preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]+)\}/', $css, $match);
    ok(str_contains($match[1], 'border-radius: 0;'), 'Hithean square-corner button rule for ' . $selector);
}
// Render the actual admin board: rejected cards expose restoration, not direct approval.
function get_transient($key) { return false; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture-only">'; }
function checked($a, $b, $echo = true) { return $a == $b ? 'checked' : ''; }
function selected($a, $b, $echo = true) { return $a == $b ? 'selected' : ''; }
function esc_textarea($value) { return esc_html($value); }
function absint($value) { return abs((int) $value); }
require HITHEAN_THEME_DIR . '/custom-functions/marketing/offers/admin.php';
hithean_offers_review($automatic_id, $automatic_row['key'], 'reject', []);
ob_start(); hithean_offers_admin(); $admin_html = ob_get_clean();
// Match the canonical card to avoid depending on how many unrelated fixture cards exist.
preg_match('/<article class="ho-offer">(?:(?!<article).)*Sale test(?:(?!<article).)*<\/article>/sU', $admin_html, $rejected_card);
ok(!empty($rejected_card[0]) && str_contains($rejected_card[0], 'value="restore"') && !str_contains($rejected_card[0], 'value="approve"'), 'Rejected admin card provides restore before approval');
ok(!str_contains($admin_html, 'Legacy prize') && !str_contains($admin_html, 'Private prize label') && substr_count($admin_html, '>Vòng quay may mắn</button>') === 1, 'Admin shows one program card and hides superseded prize cards and raw reward detail');
ok(str_contains($admin_html, 'Tự đồng bộ mỗi tuần một lần') && !str_contains($admin_html, 'mỗi 24 giờ'), 'Admin configuration describes the weekly schedule');
preg_match_all('/<article class="ho-offer">.*?<\/article>/s', $admin_html, $cards);
$compact_cards = preg_replace('/<dialog\b.*?<\/dialog>/s', '', $cards[0]);
ok(count($compact_cards) > 0 && !preg_grep('/<(form|details|p)\b/', $compact_cards), 'Board cards expose only a title; all details and actions live inside dialogs');
if (getenv('HITHEAN_OFFERS_ADMIN_PREVIEW')) { file_put_contents(getenv('HITHEAN_OFFERS_ADMIN_PREVIEW'), $admin_html); }
echo "PASS: $count structured-offer assertions\n";
}

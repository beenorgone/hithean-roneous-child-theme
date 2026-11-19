<?php
/** Unified model and real AJAX permission/history tests; legacy data remains intact and no live writes occur. */
define('ABSPATH', __DIR__);
$GLOBALS['options'] = []; $GLOBALS['writes'] = []; $GLOBALS['caps'] = ['manage_woocommerce']; $GLOBALS['nonce'] = true;
class WP_Error {
    private $code, $message;
    function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
class LibraryResponse extends RuntimeException {
    public $data;
    function __construct($data, $status) { parent::__construct('', $status); $this->data = $data; }
}
function add_action(...$args) {}
function add_shortcode(...$args) {}
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; $GLOBALS['writes'][] = $k; return true; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['caps'], true); }
function current_time($format) { return '2026-11-19 15:00:00'; }
function wp_get_current_user() { return new class { public $display_name = 'Manager'; function exists() { return true; } }; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce']; }
function wp_unslash($v) { return $v; }
function wp_send_json_error($data, $status = 400) { throw new LibraryResponse($data, $status); }
function wp_send_json_success($data = null) { throw new LibraryResponse($data, 200); }
require dirname(__DIR__) . '/custom-functions/shortcodes/consult-library.php';
$checks = 0;
function check($v, $m) { global $checks; $checks++; if (!$v) throw new RuntimeException($m); }
function response($fn, $code) {
    try { $fn(); } catch (LibraryResponse $r) { check($r->getCode() === $code, $fn . ' HTTP ' . $code); return $r->data; }
    throw new RuntimeException('Missing response: ' . $fn);
}
$flow = ['id' => 1, 'title' => 'Tư vấn giao hàng', 'group' => 'Giao hàng', 'keywords' => ['giao hàng'], 'skus' => ['X'], 'steps' => [['title' => 'Hỏi', 'say' => 'Mình nhận ở đâu?\n---\nMình ở tỉnh nào?', 'note' => 'Nội bộ']], 'status' => 'publish', 'updated' => 'old', 'by' => 'Old author'];
$GLOBALS['options'][HCF_OPTION] = ['next_id' => 3, 'flows' => [1 => $flow, 2 => array_replace($flow, ['id' => 2, 'status' => 'draft'])]];
$GLOBALS['options'][HCF_FAQ_OPTION] = ['next_id' => 3, 'items' => [1 => ['id' => 1, 'q' => 'Phí giao hàng?', 'a' => 'Kiểm tra theo địa chỉ', 'group' => 'Giao hàng', 'status' => 'publish', 'note' => 'Chưa hứa phí', 'uses' => 7, 'source' => 'ai', 'updated' => 'old', 'by' => 'Old author'], 2 => ['id' => 2, 'q' => 'Nháp riêng', 'a' => 'Draft only', 'status' => 'draft']]];
$GLOBALS['options'][HCF_HISTORY_OPTION] = [['action' => 'old history']];
$GLOBALS['options'][HCF_FAQ_HISTORY_OPTION] = [['action' => 'old FAQ history']];
$before = $GLOBALS['options'];
$all = array_column(hcl_items(true), null, 'id');
check(count($all) === 4 && isset($all['flow-1'], $all['faq-1']), 'colliding legacy IDs are independent typed keys');
check($all['faq-1']['uses'] === 7 && $all['faq-1']['source'] === 'ai' && $all['flow-1']['by'] === 'Old author', 'common model preserves usage/source/authorship');
check($all['faq-1']['steps'][0]['note'] === 'Chưa hứa phí' && $all['faq-1']['group'] === 'Giao hàng', 'FAQ shares grouping and step shape');
check(count(hcl_items(false)) === 2, 'drafts of both kinds excluded from normal/AI reads');
check($GLOBALS['options'] === $before && !$GLOBALS['writes'], 'reading library never migrates, duplicates or rewrites data/history');
[$aiFlows, $aiFaqs] = hcl_ai_sources();
check(count($aiFlows) === 1 && count($aiFaqs) === 1 && $aiFlows[0]['id'] === 1 && $aiFaqs[0]['id'] === 1, 'AI gets published legacy-compatible sources from one common model');
$matches = hcl_suggest(hcl_items(true), 'phí giao hàng', ['X']);
check(in_array('flow-1', array_column($matches, 'key'), true) && in_array('faq-1', array_column($matches, 'key'), true), 'suggestions search both kinds');
check(!in_array('faq-2', array_column($matches, 'key'), true), 'suggestions never return drafts');
foreach (['1', 'flow-0', 'faq--1', '../flow-1', 'faq-1-extra'] as $bad) check(hcl_key($bad) === null, 'reject malformed typed key: ' . $bad);
$GLOBALS['caps'] = ['edit_shop_orders'];
$raw = ['id' => 0, 'type' => 'flow', 'title' => 'New', 'steps' => [['say' => 'Answer']]];
check(hcl_save_entry($raw, false)->get_error_code() === 'hcl_forbidden', 'staff cannot create scripts');
$raw['id'] = 'faq-1'; $raw['type'] = 'faq';
check(hcl_save_entry($raw, false)->get_error_code() === 'hcl_forbidden', 'staff cannot update existing FAQ');
check($GLOBALS['options'] === $before, 'denied edits preserve all data');
$raw['id'] = 0; $raw['title'] = 'Hỏi đáp mới'; $raw['status'] = 'draft';
check(hcl_save_entry($raw, false) === 'faq-3', 'staff may create a quick answer');
check(hcf_faq_read()['items'][3]['status'] === 'publish', 'staff creation follows existing published-FAQ permission contract');
$raw['id'] = 'faq-1'; $raw['type'] = 'flow';
check(hcl_save_entry($raw, true)->get_error_code() === 'hcl_invalid', 'existing entries cannot silently change kind');
$raw['type'] = 'faq'; $raw['title'] = 'Phí giao hàng?'; $raw['group'] = 'Nhóm chung'; $raw['steps'][0]['note'] = 'Ghi chú mới';
check(hcl_save_entry($raw, true) === 'faq-1', 'manager updates legacy FAQ through common editor');
$faq = hcf_faq_read()['items'][1];
check($faq['uses'] === 7 && $faq['source'] === 'ai' && $faq['status'] === 'draft', 'FAQ update retains counters/source and supports draft');
$history = $GLOBALS['options'][HCF_FAQ_HISTORY_OPTION];
check(count($history) === 2 && $history[0]['faq']['a'] === 'Kiểm tra theo địa chỉ' && $history[1] === $before[HCF_FAQ_HISTORY_OPTION][0], 'new history appended while existing history survives');
check(!in_array('faq-1', array_column(hcl_items(false), 'id'), true), 'draft FAQ hidden from staff and AI after update');
check(!in_array(1, array_column(hcf_faq_visible_list(hcf_faq_read()), 'id'), true), 'legacy staff FAQ endpoint also hides drafts');
[$updatedStore, $id] = hcf_faq_store_upsert(hcf_faq_read(), ['id' => 1, 'q' => 'Phí giao hàng?', 'a' => 'Legacy update'], 't', 'u', true);
check($updatedStore['items'][1]['status'] === 'draft' && $updatedStore['items'][1]['group'] === 'Nhóm chung' && $updatedStore['items'][1]['note'] === 'Ghi chú mới', 'legacy editor preserves common fields it does not send');
$bundle = hcf_bundle_build(hcf_get_flows(true), hcf_faq_list(hcf_faq_read()), 'Reference', 'site', 't');
[$importFlows, $importFaqs] = hcf_bundle_parse($bundle);
$exportFaq = array_values(array_filter($importFaqs, static function ($f) { return $f['q'] === 'Phí giao hàng?'; }))[0];
check(count($importFlows) === 2 && count($importFaqs) === 3 && $exportFaq['status'] === 'draft' && $exportFaq['group'] === 'Nhóm chung', 'one portable bundle retains both kinds, groups and drafts');
$endpoints = ['hcl_ajax_list', 'hcl_ajax_save', 'hcl_ajax_delete', 'hcl_ajax_suggest', 'hcl_ajax_copy'];
$GLOBALS['caps'] = [];
foreach ($endpoints as $fn) response($fn, 403);
$GLOBALS['caps'] = ['manage_woocommerce']; $GLOBALS['nonce'] = false;
foreach ($endpoints as $fn) response($fn, 403);
$GLOBALS['nonce'] = true; $GLOBALS['caps'] = ['edit_shop_orders'];
$_POST = ['id' => 'flow-1']; response('hcl_ajax_delete', 403);
$_POST = ['key' => 'faq-1']; response('hcl_ajax_copy', 404);
$_POST = ['key' => 'faq-3']; $copy = response('hcl_ajax_copy', 200);
check($copy['texts'] === ['Answer'] && hcf_faq_read()['items'][3]['uses'] === 1, 'copy resolves answer and keeps FAQ use counter');
$_POST = ['key' => 'faq-3', 'texts' => ['bad']]; response('hcl_ajax_copy', 400);
$GLOBALS['caps'] = ['manage_woocommerce'];
$_POST = ['id' => 'flow-1']; response('hcl_ajax_delete', 200);
check(!isset(hcf_store_read()['flows'][1]) && isset(hcf_faq_read()['items'][1]), 'delete one kind cannot remove the other with same legacy ID');
check(count($GLOBALS['options'][HCF_HISTORY_OPTION]) === 2, 'script delete preserves old history and records deleted content');
echo "OK ($checks unified library checks)\n";

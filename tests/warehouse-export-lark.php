<?php
/** Run: php tests/warehouse-export-lark.php. No network or WordPress required. */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['hooks'] = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_post_meta($id, $key, ...$args) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function thean_order_meta_set($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function wp_parse_args($value, $default) { return array_merge($default, $value); }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($url) { return trim($url); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function absint($value) { return abs((int) $value); }
function current_time($format) { return '2026-11-02 14:00:00'; }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_user_by($field, $value) { foreach ($GLOBALS['users'] ?? [] as $user) if (($user->user_login ?? '') === $value) return $user; return false; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function thean_order_edit_url($id) { return admin_url('admin.php?page=wc-orders&action=edit&id=' . $id); }
function thean_order_list_url() { return admin_url('admin.php?page=wc-orders'); }
function is_user_logged_in() { return $GLOBALS['logged_in'] ?? true; }
function current_user_can($capability) { return $GLOBALS['can_manage'] ?? true; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid:' . $action; }
function get_current_user_id() { return 7; }
function wp_get_current_user() { return get_userdata(7); }
function wp_generate_uuid4() { static $id = 0; return 'batch-' . ++$id; }
function wp_next_scheduled($hook, $args) { foreach ($GLOBALS['events'] as $event) if ($event['hook'] === $hook && $event['args'] === $args) return $event['at']; return false; }
function wp_schedule_single_event($at, $hook, $args) { if ($GLOBALS['queue_fail']) return false; $GLOBALS['events'][] = compact('at', 'hook', 'args'); return true; }
function wp_clear_scheduled_hook($hook, $args) { $GLOBALS['events'] = array_values(array_filter($GLOBALS['events'], fn($e) => $e['hook'] !== $hook || $e['args'] !== $args)); }
class WP_Error {
    public function __construct(public string $code = '', private string $message = '') {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error($response) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['http']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_post($url, $args) {
    $GLOBALS['requests'][] = compact('url', 'args');
    if (isset($GLOBALS['on_send'])) ($GLOBALS['on_send'])();
    return $GLOBALS['response'];
}
class WC_Order {
    public function __construct(private int $id) {}
    public function get_id() { return $this->id; }
    public function get_order_number() { return $this->id . '.1'; }
    public function add_order_note($text) { $GLOBALS['notes'][] = $text; }
}
function wc_get_order($id) { return $id > 0 ? new WC_Order($id) : false; }
// Như core/module-loader.php: khai kênh không cần core; core Lark chỉ nạp khi tính năng thật sự dùng.
$GLOBALS['lark_loads'] = 0;
function child_theme_lark_register_channel(string $key, array $def): void { $GLOBALS['child_theme_lark_channel_defs'][$key] = $def + ['label' => $key, 'description' => '', 'fallback' => '', 'default_active' => false]; }
function child_theme_lark_load(): bool { if (!function_exists('child_theme_lark_send')) { $GLOBALS['lark_loads']++; require_once dirname(__DIR__) . '/custom-functions/core/lark.php'; } return true; }
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/warehouse-export-workflow.php';
$GLOBALS['lark_preloaded'] = function_exists('child_theme_lark_send'); // include file tính năng không được kéo core Lark theo

$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
check(!$GLOBALS['lark_preloaded'], 'include xuất kho không nạp core Lark');
function reset_state($only_alerts = '0') {
    $GLOBALS['options'] = ['hithean_export_lark_settings' => ['enabled' => '1', 'only_alerts' => $only_alerts, 'webhook_url' => 'https://open.larksuite.com/open-apis/bot/v2/hook/test-token']];
    $GLOBALS['meta'] = $GLOBALS['events'] = $GLOBALS['requests'] = [];
    $GLOBALS['users'] = [7 => (object) ['ID' => 7, 'user_login' => 'staff', 'display_name' => 'Nhân viên xử lý']];
    $GLOBALS['notes'] = [];
    $GLOBALS['logged_in'] = $GLOBALS['can_manage'] = true;
    $GLOBALS['queue_fail'] = false;
    unset($GLOBALS['on_send']);
    $GLOBALS['response'] = ['http' => 200, 'body' => '{"code":0,"StatusCode":0}'];
}
function run_hook($hook, ...$args) {
    wp_clear_scheduled_hook($hook, $args);
    foreach ($GLOBALS['hooks'][$hook] as $callback) $callback(...$args);
}
function review($id, $verdict = 'review') {
    if (!isset($GLOBALS['meta'][$id]['warehouse_export_images'])) $GLOBALS['meta'][$id]['warehouse_export_images'] = 'https://example.test/order-' . $id . '.jpg';
    $r = ['overall' => $verdict, 'order_match' => $verdict === 'pass' ? 'match' : 'mismatch', 'items_match' => 'match', 'images_hash' => hithean_export_lark_images_hash(hithean_export_lark_current_images($id))];
    $GLOBALS['meta'][$id]['warehouse_export_ai_check'] = $r;
    return $r;
}
function close_batch($id) { $key = 'hithean_export_lark_batch_' . $id; $GLOBALS['options'][$key]['due_at'] = time() - 1; }
function last_text() { $r = end($GLOBALS['requests']); return $r['args']['body']; }

reset_state();
foreach (['open.larksuite.com', 'open.feishu.cn', 'open.larkoffice.com'] as $host) {
    $url = 'https://' . $host . '/open-apis/bot/v2/hook/test-token';
    check(hithean_export_lark_sanitize_webhook($url) === $url, 'accept official bot URL');
}
foreach (['http://open.larksuite.com/open-apis/bot/v2/hook/x', 'https://larksuite.com.evil.test/open-apis/bot/v2/hook/x', 'https://evil-larksuite.com/open-apis/bot/v2/hook/x', 'https://user:pass@open.larksuite.com/open-apis/bot/v2/hook/x', 'https://open.larksuite.com/open-apis/bot/v2/hook/.....', 'https://open.larksuite.com/wrong', 'https://open.larksuite.com:444/open-apis/bot/v2/hook/x'] as $url) check(hithean_export_lark_sanitize_webhook($url) === '', 'reject invalid URL');
foreach (['{"code":19024,"msg":"secret-url"}', '{"StatusCode":1}', 'not json', '{}', '{"code":"bad"}', '{"code":0,"StatusCode":1}'] as $body) {
    check(hithean_export_lark_response_error(['http' => 200, 'body' => $body]) !== '', 'reject application/malformed error');
}
check(hithean_export_lark_response_error(['http' => 200, 'body' => '{"StatusCode":0}']) === '', 'legacy success');
check(hithean_export_lark_response_error(['http' => 503, 'body' => '{"code":0}']) !== '', 'reject HTTP error');
check(hithean_export_lark_response_error(new WP_Error) !== '', 'transport error');

// Mismatch before confirmation sends immediately, without marking confirmation sent.
$r = review(1);
run_hook('hithean_export_ai_check_completed', wc_get_order(1), $r);
check(wp_next_scheduled('hithean_export_lark_alert_async', [1, hithean_export_lark_alert_hash(1, $r), 0]) <= time(), 'mismatch queues immediately before confirmation');
check(wp_next_scheduled('hithean_export_lark_remind_async', [1, 0]) >= time() + 1799, 'mismatch also schedules reminder');
$hash = hithean_export_lark_alert_hash(1, $r);
run_hook('hithean_export_lark_alert_async', 1, $hash, 0);
check(count($GLOBALS['requests']) === 1 && strpos(last_text(), 'Không khớp') !== false, 'immediate mismatch delivered');
check(!get_post_meta(1, 'warehouse_export_lark_notified_at', true), 'alert does not suppress confirmation');
run_hook('hithean_export_lark_alert_async', 1, $hash, 0);
check(count($GLOBALS['requests']) === 1, 'duplicate alert suppressed');
$GLOBALS['meta'][1]['warehouse_export_images'] = 'new-evidence.jpg';
hithean_export_lark_queue_alert(1, $r);
$new_hash = hithean_export_lark_alert_hash(1, $r);
run_hook('hithean_export_lark_alert_async', 1, $new_hash, 0);
check(count($GLOBALS['requests']) === 2, 'new evidence may alert again');
$r = review(2); $hash = hithean_export_lark_alert_hash(2, $r); review(2, 'pass');
run_hook('hithean_export_lark_alert_async', 2, $hash, 0);
check(count($GLOBALS['requests']) === 2, 'obsolete mismatch ignored after AI pass');

reset_state();
review(1, 'pass'); review(2);
run_hook('hithean_warehouse_export_confirmed', wc_get_order(1));
$id = get_option('hithean_export_lark_active_batch');
$batch = get_option('hithean_export_lark_batch_' . $id);
check(abs($batch['due_at'] - time() - 1800) <= 1, 'first confirmation opens a 30 minute window');
hithean_export_lark_queue_notification(2);
hithean_export_lark_queue_notification(2);
$updated = get_option('hithean_export_lark_batch_' . $id);
check($updated['due_at'] === $batch['due_at'] && count($updated['orders']) === 2, 'later confirmations retain deadline and deduplicate');
run_hook('hithean_export_lark_batch_async', $id, 0);
check(count($GLOBALS['requests']) === 0, 'batch cannot send early');
close_batch($id);
// A confirmation at/after the deadline opens a new window even if cron is late.
hithean_export_lark_queue_notification(3);
$new_id = get_option('hithean_export_lark_active_batch');
check($new_id !== $id, 'closed window gets a separate batch');
run_hook('hithean_export_lark_batch_async', $id, 0);
check(count($GLOBALS['requests']) === 1 && strpos(last_text(), '#1.1') !== false && strpos(last_text(), '#2.1') !== false && strpos(last_text(), '#3.1') === false, 'one summary contains exactly the closed batch');
check(get_option('hithean_export_lark_active_batch') === $new_id, 'old batch does not remove the next batch');
run_hook('hithean_export_lark_batch_async', $id, 0);
check(count($GLOBALS['requests']) === 1, 'repeated batch callback does not resend');
check($GLOBALS['requests'][0]['args']['redirection'] === 0, 'never redirect secret webhook');

reset_state('1'); review(1, 'pass'); review(2);
hithean_export_lark_queue_notification(1); hithean_export_lark_queue_notification(2);
$id = get_option('hithean_export_lark_active_batch'); close_batch($id);
run_hook('hithean_export_lark_batch_async', $id, 0);
check(count($GLOBALS['requests']) === 1 && strpos(last_text(), '#1.1') === false && strpos(last_text(), '#2.1') !== false, 'alerts-only filters successful orders');
check(get_post_meta(1, 'warehouse_export_lark_status', true)['state'] === 'skipped_passed', 'filtered order status retained');
reset_state('1'); review(1, 'pass'); hithean_export_lark_queue_notification(1);
$id = get_option('hithean_export_lark_active_batch'); close_batch($id); run_hook('hithean_export_lark_batch_async', $id, 0);
check(!$GLOBALS['requests'], 'all-pass alerts-only batch sends nothing');

reset_state(); review(1);
hithean_export_lark_queue_notification(1); $id = get_option('hithean_export_lark_active_batch'); close_batch($id);
$GLOBALS['response'] = ['http' => 200, 'body' => '{"code":19024,"msg":"secret-url"}'];
run_hook('hithean_export_lark_batch_async', $id, 0);
check(!get_post_meta(1, 'warehouse_export_lark_notified_at', true), 'rejected message is not marked sent');
check(get_post_meta(1, 'warehouse_export_lark_status', true)['state'] === 'failed', 'application rejection recorded');
check(strpos(get_option('hithean_export_lark_last_delivery')['error'], '19024') !== false, 'show actionable code without secret');
$text = last_text(); review(1, 'pass');
run_hook('hithean_export_lark_batch_async', $id, 1);
check(last_text() === $text, 'retries preserve batch payload');
run_hook('hithean_export_lark_batch_async', $id, 2);
check(!get_option('hithean_export_lark_batch_' . $id), 'terminal failure cleans batch option');
check(count($GLOBALS['requests']) === 3 && !array_filter($GLOBALS['events'], fn($e) => $e['hook'] === 'hithean_export_lark_batch_async'), 'initial delivery plus two retries only');

reset_state(); $GLOBALS['options']['hithean_lark_batch_queue_lock'] = time() + 60;
hithean_export_lark_queue_notification(1);
check(count($GLOBALS['events']) === 1 && $GLOBALS['events'][0]['hook'] === 'hithean_export_lark_notify_async', 'busy enrollment defers without losing the order');
unset($GLOBALS['options']['hithean_lark_batch_queue_lock']);
run_hook('hithean_export_lark_notify_async', 1, 1);
check(count(get_option('hithean_export_lark_batch_' . get_option('hithean_export_lark_active_batch'))['orders']) === 1, 'deferred enrollment succeeds');
reset_state(); $GLOBALS['queue_fail'] = true; hithean_export_lark_queue_notification(1);
check(get_post_meta(1, 'warehouse_export_lark_status', true)['state'] === 'failed', 'cron failure is visible');
check(!get_option('hithean_lark_batch_queue_lock'), 'queue failure releases lock');

// Sending an old batch must never block confirmation enrollment.
reset_state(); review(1); hithean_export_lark_queue_notification(1);
$id = get_option('hithean_export_lark_active_batch'); close_batch($id);
$GLOBALS['on_send'] = function () { hithean_export_lark_queue_notification(2); };
run_hook('hithean_export_lark_batch_async', $id, 0);
$next_id = get_option('hithean_export_lark_active_batch');
check($next_id !== $id && get_option('hithean_export_lark_batch_' . $next_id)['orders'] === [2 => 2], 'enrollment continues during HTTP delivery');

reset_state(); $r = review(1); $hash = hithean_export_lark_alert_hash(1, $r);
$GLOBALS['response'] = ['http' => 200, 'body' => '{"code":19024}'];
run_hook('hithean_export_lark_alert_async', 1, $hash, 0);
check(!get_post_meta(1, 'warehouse_export_lark_alert_hash', true), 'failed immediate alert can retry');
check(get_post_meta(1, 'warehouse_export_lark_alert_status', true)['state'] === 'failed', 'immediate alert failure retained');
hithean_export_lark_queue_alert(1, $r);
check(count(array_filter($GLOBALS['events'], fn($e) => $e['hook'] === 'hithean_export_lark_alert_async')) === 1, 'repeated AI completion does not bypass pending alert retry');
$GLOBALS['response'] = ['http' => 200, 'body' => '{"code":0}'];
run_hook('hithean_export_lark_alert_async', 1, $hash, 1);
check(get_post_meta(1, 'warehouse_export_lark_alert_hash', true) === $hash, 'successful alert retry marks only alert sent');
reset_state(); $GLOBALS['options']['hithean_export_lark_settings']['enabled'] = '0';
hithean_export_lark_queue_alert(1, review(1)); hithean_export_lark_queue_notification(1);
check(!$GLOBALS['events'] && !$GLOBALS['requests'], 'master toggle disables both flows');

// Structured cards include findings, staff, safe photo links and a protected URL action.
reset_state();
$r = review(4);
$r += ['confidence' => 'high', 'reason' => '<script>alert(1)</script>Thiếu một sản phẩm', 'visible_order_reference' => '4.1',
    'missing_or_suspected_missing' => ['Bột mì 1kg'], 'unexpected_or_suspected_extra' => ['Men'], 'shipping_codes' => ['SHIP123']];
$GLOBALS['meta'][4]['warehouse_export_ai_check'] = $r;
$GLOBALS['meta'][4]['order_export_by'] = [['staff', 'staff']];
$GLOBALS['meta'][4]['warehouse_export_uploaded_by'] = [7];
$GLOBALS['meta'][4]['export_confirmed_by'] = 7;
$GLOBALS['meta'][4]['warehouse_export_images'] = "https://example.test/a.jpg\njavascript:alert(1)\nhttps://example.test/a.jpg\nhttps://example.test/b.jpg";
$hash = hithean_export_lark_alert_hash(4, $r);
run_hook('hithean_export_lark_alert_async', 4, $hash, 0);
$payload = json_decode(last_text(), true);
check($payload['msg_type'] === 'interactive', 'alerts use structured Lark cards');
check(strpos(last_text(), 'Thiếu một sản phẩm') !== false && strpos(last_text(), 'Bột mì 1kg') !== false && strpos(last_text(), 'SHIP123') !== false, 'card includes detailed AI findings');
check(strpos(last_text(), '<script>') === false, 'AI markup is stripped');
$elements = $payload['card']['elements'];
$buttons = [];
foreach ($elements as $el) foreach ($el['actions'] ?? [] as $button) $buttons[] = $button;
check(count($buttons) === 4, 'order, resolve and two unique safe photo links');
check($buttons[0]['url'] === thean_order_edit_url(4), 'order link uses HPOS helper');
check(strpos($buttons[1]['url'], 'hithean_export_lark_review') !== false && strpos($buttons[1]['url'], $hash) !== false, 'resolve URL is bound to evidence');
check(strpos($elements[0]['text']['content'], 'Người xử lý / xuất kho: Nhân viên xử lý') !== false, 'staff display name included');
check(strpos(last_text(), 'Tự động nhắc lại sau 30 phút') !== false, 'initial alert explicitly promises automatic reminder');
check($elements[0]['text']['tag'] === 'plain_text', 'AI cannot inject card markup or mentions');

// Acknowledgment requires login, capability, nonce and current evidence; double clicks are safe.
$nonce = 'valid:hithean_export_lark_resolve_4_' . $hash;
$GLOBALS['logged_in'] = false;
check(is_wp_error(hithean_export_lark_acknowledge(4, $hash, $nonce)), 'anonymous acknowledgment denied');
$GLOBALS['logged_in'] = true; $GLOBALS['can_manage'] = false;
check(is_wp_error(hithean_export_lark_acknowledge(4, $hash, $nonce)), 'nonstaff acknowledgment denied');
$GLOBALS['can_manage'] = true;
check(is_wp_error(hithean_export_lark_acknowledge(4, $hash, 'invalid')), 'CSRF acknowledgment denied');
check(!get_post_meta(4, 'warehouse_export_ai_resolution', true), 'unauthorized requests never save a resolution');
hithean_export_lark_schedule_reminder(4);
$resolved = hithean_export_lark_acknowledge(4, $hash, $nonce);
check(!is_wp_error($resolved) && $resolved['resolved_by'] === 7, 'acknowledgment records actor and timestamp');
check(get_post_meta(4, 'warehouse_export_ai_check', true)['overall'] === 'review' && get_post_meta(4, 'export_confirmed_by', true) === 7, 'acknowledgment preserves AI and shipping state');
check(wp_next_scheduled('hithean_export_lark_remind_async', [4, 0]) !== false, 'acknowledgment does not cancel reminders');
$note_count = count($GLOBALS['notes']);
hithean_export_lark_acknowledge(4, $hash, $nonce);
check(count($GLOBALS['notes']) === $note_count, 'double acknowledgment does not duplicate audit notes');
$GLOBALS['meta'][4]['warehouse_export_images'] .= "\nhttps://example.test/new.jpg";
check(is_wp_error(hithean_export_lark_acknowledge(4, $hash, $nonce)), 'old action cannot acknowledge new evidence');
check(get_post_meta(4, 'warehouse_export_ai_resolution', true)['hash'] === $hash, 'stale action preserves existing resolution');

// Reminder cadence is fixed; it stops only for a passing review of the current evidence.
reset_state(); $r = review(1);
hithean_export_lark_schedule_reminder(1);
$due = wp_next_scheduled('hithean_export_lark_remind_async', [1, 0]);
hithean_export_lark_schedule_reminder(1);
check(count($GLOBALS['events']) === 1 && abs($due - time() - 1800) <= 1, 'reminder schedules once at 30 minutes');
run_hook('hithean_export_lark_remind_async', 1, 0);
check(count($GLOBALS['requests']) === 1 && strpos(last_text(), 'Tự động nhắc lại sau 30 phút') !== false, 'unresolved mismatch triggers a reminder with the cadence');
check(wp_next_scheduled('hithean_export_lark_remind_async', [1, 0]) >= time() + 1799, 'each reminder schedules the next 30 minute reminder');
$GLOBALS['meta'][1]['warehouse_export_ai_resolution'] = ['hash' => hithean_export_lark_alert_hash(1, $r), 'resolved_by' => 7, 'resolved_at' => current_time('mysql')];
run_hook('hithean_export_lark_remind_async', 1, 0);
check(count($GLOBALS['requests']) === 2 && strpos(last_text(), 'Đã xử lý cảnh báo') !== false, 'acknowledged mismatch still reminds and shows actor');
review(1, 'pass');
check(hithean_export_lark_current_pass(1), 'passing review binds to current images');
run_hook('hithean_export_lark_remind_async', 1, 0);
check(count($GLOBALS['requests']) === 2 && !$GLOBALS['events'], 'current matching images stop reminder without sending');
check(get_post_meta(1, 'warehouse_export_lark_reminder_status', true)['state'] === 'completed', 'completed reminder state is saved');
$GLOBALS['meta'][1]['warehouse_export_images'] = 'https://example.test/changed.jpg';
check(!hithean_export_lark_current_pass(1), 'old pass never proves newly uploaded images match');
hithean_export_lark_schedule_reminder(1); run_hook('hithean_export_lark_remind_async', 1, 0);
check(count($GLOBALS['requests']) === 3 && strpos(last_text(), 'Chưa có kết quả khớp ảnh hiện tại') !== false, 'stale pass still reminds with truthful status');
review(1, 'pass'); $GLOBALS['meta'][1]['warehouse_export_ai_review_status'] = ['state' => 'queued'];
check(!hithean_export_lark_current_pass(1), 'queued review cannot stop reminders');
unset($GLOBALS['meta'][1]['warehouse_export_ai_review_status']);
$r = get_post_meta(1, 'warehouse_export_ai_check', true);
run_hook('hithean_export_ai_check_completed', wc_get_order(1), $r);
check(!wp_next_scheduled('hithean_export_lark_remind_async', [1, 0]), 'matching completion cancels future reminders immediately');
check(hithean_export_lark_images_hash(['b', 'a', 'a']) === hithean_export_lark_images_hash(['a', 'b']), 'image identity normalizes duplicate URLs and ordering');

reset_state(); review(1);
$GLOBALS['response'] = ['http' => 503, 'body' => '{}'];
run_hook('hithean_export_lark_remind_async', 1, 0);
check(wp_next_scheduled('hithean_export_lark_remind_async', [1, 1]) !== false, 'failed reminder retries in background');
run_hook('hithean_export_lark_remind_async', 1, 1);
run_hook('hithean_export_lark_remind_async', 1, 2);
check(count($GLOBALS['requests']) === 3 && wp_next_scheduled('hithean_export_lark_remind_async', [1, 0]) >= time() + 1799, 'reminder retries stop at two then resume the 30 minute cadence');
child_theme_lark_save_channel(['key' => 'kho', 'active' => false]); // tắt kênh "kho" ở Cài đặt ERP → Lark
run_hook('hithean_export_lark_remind_async', 1, 0);
check(count($GLOBALS['requests']) === 3 && !$GLOBALS['events'], 'master toggle stops reminders');

// Large batches remain one payload within Lark limits and offer access to remaining orders.
reset_state();
for ($id = 1; $id <= 30; $id++) {
    $r = review($id); $r['reason'] = str_repeat('Kiểm tra sản phẩm. ', 60);
    $GLOBALS['meta'][$id]['warehouse_export_ai_check'] = $r;
    hithean_export_lark_queue_notification($id);
}
$id = get_option('hithean_export_lark_active_batch'); close_batch($id);
run_hook('hithean_export_lark_batch_async', $id, 0);
check(count($GLOBALS['requests']) === 1 && strlen(last_text()) <= 19000, 'large batch remains one bounded message');
check(strpos(last_text(), 'Mở danh sách đơn') !== false, 'oversize details link to full order list');

// Rechecking the same photos is a new review, not a duplicate delivery.
reset_state(); $r = review(1); $r['checked_at'] = '2026-11-02 11:46:50';
$GLOBALS['meta'][1]['warehouse_export_ai_check'] = $r;
$old_hash = hithean_export_lark_alert_hash(1, $r);
run_hook('hithean_export_lark_alert_async', 1, $old_hash, 0);
check(count($GLOBALS['requests']) === 1, 'first review delivered');
$r['checked_at'] = '2026-11-02 11:58:29';
$GLOBALS['meta'][1]['warehouse_export_ai_check'] = $r;
$new_hash = hithean_export_lark_alert_hash(1, $r);
check($new_hash !== $old_hash, 'new AI run gets its own alert identity on identical photos');
hithean_export_lark_queue_alert(1, $r);
check(wp_next_scheduled('hithean_export_lark_alert_async', [1, $new_hash, 0]) !== false, 'new review is not suppressed by previous delivery');
run_hook('hithean_export_lark_alert_async', 1, $new_hash, 0);
check(count($GLOBALS['requests']) === 2, 'new mismatch sends immediately on the same photos');
run_hook('hithean_export_lark_alert_async', 1, $new_hash, 0);
check(count($GLOBALS['requests']) === 2, 'duplicate execution of the same review still sends once');
check(count(array_filter($GLOBALS['events'], fn($e) => $e['hook'] === 'hithean_export_lark_remind_async')) === 1, 'rechecking does not extend or duplicate the reminder');

check($GLOBALS['lark_loads'] === 1, 'core Lark nạp đúng 1 lần, khi gửi');
check($GLOBALS['lark_loads'] === 1, 'core Lark nạp đúng 1 lần, khi tính năng dùng tới');
echo "OK: $checks checks\n";

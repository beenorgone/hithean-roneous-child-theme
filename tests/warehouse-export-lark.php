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
function get_userdata($id) { return false; }
function wp_generate_uuid4() { static $id = 0; return 'batch-' . ++$id; }
function wp_next_scheduled($hook, $args) { foreach ($GLOBALS['events'] as $event) if ($event['hook'] === $hook && $event['args'] === $args) return $event['at']; return false; }
function wp_schedule_single_event($at, $hook, $args) { if ($GLOBALS['queue_fail']) return false; $GLOBALS['events'][] = compact('at', 'hook', 'args'); return true; }
class WP_Error {}
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
}
function wc_get_order($id) { return $id > 0 ? new WC_Order($id) : false; }
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/warehouse-export-workflow.php';

$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) throw new RuntimeException($message); }
function reset_state($only_alerts = '0') {
    $GLOBALS['options'] = ['hithean_export_lark_settings' => ['enabled' => '1', 'only_alerts' => $only_alerts, 'webhook_url' => 'https://open.larksuite.com/open-apis/bot/v2/hook/test-token']];
    $GLOBALS['meta'] = $GLOBALS['events'] = $GLOBALS['requests'] = [];
    $GLOBALS['queue_fail'] = false;
    unset($GLOBALS['on_send']);
    $GLOBALS['response'] = ['http' => 200, 'body' => '{"code":0,"StatusCode":0}'];
}
function run_hook($hook, ...$args) { foreach ($GLOBALS['hooks'][$hook] as $callback) $callback(...$args); }
function review($id, $verdict = 'review') {
    $r = ['overall' => $verdict, 'order_match' => $verdict === 'pass' ? 'match' : 'mismatch', 'items_match' => 'match'];
    $GLOBALS['meta'][$id]['warehouse_export_ai_check'] = $r;
    return $r;
}
function close_batch($id) { $key = 'hithean_export_lark_batch_' . $id; $GLOBALS['options'][$key]['due_at'] = time() - 1; }
function last_text() { $r = end($GLOBALS['requests']); return json_decode($r['args']['body'], true)['content']['text']; }

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
check(count($GLOBALS['events']) === 1 && $GLOBALS['events'][0]['at'] <= time(), 'mismatch queues immediately before confirmation');
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
check(count(array_filter($GLOBALS['events'], fn($e) => $e['hook'] === 'hithean_export_lark_batch_async')) === 3, 'initial delivery plus two retry events only');

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
check(count($GLOBALS['events']) === 1, 'repeated AI completion does not bypass pending alert retry');
$GLOBALS['response'] = ['http' => 200, 'body' => '{"code":0}'];
run_hook('hithean_export_lark_alert_async', 1, $hash, 1);
check(get_post_meta(1, 'warehouse_export_lark_alert_hash', true) === $hash, 'successful alert retry marks only alert sent');
reset_state(); $GLOBALS['options']['hithean_export_lark_settings']['enabled'] = '0';
hithean_export_lark_queue_alert(1, review(1)); hithean_export_lark_queue_notification(1);
check(!$GLOBALS['events'] && !$GLOBALS['requests'], 'master toggle disables both flows');

echo "OK: $checks checks\n";

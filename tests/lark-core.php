<?php
/** Test core/lark.php (kênh Lark dùng chung — giống hệt hithean ↔ theanmarket). Không cần WordPress / mạng. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
$GLOBALS['options'] = []; $GLOBALS['sent'] = []; $GLOBALS['body'] = '{"code":0}'; $GLOBALS['http'] = 200;
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, ...$a) { $GLOBALS['options'][$k] = $v; return true; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'cURL error 28 https://open.larksuite.com/open-apis/bot/v2/hook/SECRET'; } }
function wp_remote_post($url, $args) { $GLOBALS['sent'][] = ['url' => $url, 'args' => $args, 'body' => json_decode($args['body'], true)]; return $GLOBALS['http'] === 0 ? new WP_Error() : ['http' => $GLOBALS['http']]; }
function wp_remote_retrieve_response_code($r) { return $r['http']; }
function wp_remote_retrieve_body($r) { return $GLOBALS['body']; }
require dirname(__DIR__) . '/custom-functions/core/lark.php';

$count = 0;
function check($c, $m) { global $count; $count++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }
$hook = static fn(string $t) => 'https://open.larksuite.com/open-apis/bot/v2/hook/' . $t;

// Kiểm webhook chặt.
check(child_theme_lark_sanitize_webhook($hook('abc-1_2')) === $hook('abc-1_2'), 'Nhận webhook bot chuẩn');
check(child_theme_lark_sanitize_webhook('https://open.feishu.cn/open-apis/bot/v2/hook/x') !== '', 'Nhận Feishu');
foreach (['http://open.larksuite.com/open-apis/bot/v2/hook/x', 'https://larksuite.com.evil.test/open-apis/bot/v2/hook/x', 'https://evil-larksuite.com/open-apis/bot/v2/hook/x',
    'https://u:p@open.larksuite.com/open-apis/bot/v2/hook/x', 'https://open.larksuite.com/share/base/view/x', 'https://open.larksuite.com:444/open-apis/bot/v2/hook/x',
    'https://open.larksuite.com/open-apis/bot/v2/hook/x?a=1', ''] as $bad) {
    check(child_theme_lark_sanitize_webhook($bad) === '', 'Từ chối ' . $bad);
}
check(child_theme_lark_sanitize_chat_id('oc_ab12') === 'oc_ab12' && child_theme_lark_sanitize_chat_id('ou_x') === '', 'chat_id dạng oc_');

// Chuyển cấu hình cũ sang kênh (1 lần, không đè kênh đã có).
$GLOBALS['options'] = [
    'thean_export_lark_settings' => ['enabled' => '1', 'webhook_url' => $hook('kho'), 'only_alerts' => '1', 'spx_ai_alerts' => '1'],
    'thean_offers_lark_settings' => ['enabled' => true, 'webhook_url' => ''], // từng dùng chung nhóm kho → nay là nhóm riêng, chưa có webhook
    'hithean_cro_landing_settings' => ['lark_webhook' => $hook('cro')],
    'erp_lark_settings' => ['app_id' => 'cli_x', 'channels' => [['key' => 'ops', 'label' => 'ERP', 'type' => 'chat_id', 'value' => 'oc_1', 'active' => true]]],
];
child_theme_lark_register_channel('kho', ['label' => 'Xuất kho']);
child_theme_lark_register_channel('shopee_ai', ['label' => 'Lỗi AI Shopee', 'fallback' => 'kho']);
child_theme_lark_register_channel('uu_dai', ['label' => 'Ưu đãi']);
child_theme_lark_register_channel('cro', ['label' => 'CRO']);
child_theme_lark_migrate();
$s = get_option('erp_lark_settings');
$by = array_column($s['channels'], null, 'key');
check($s['app_id'] === 'cli_x' && isset($by['ops']) && $by['ops']['type'] === 'chat_id', 'Giữ cấu hình bộ Lark ERP sẵn có');
check($by['kho']['value'] === $hook('kho') && $by['kho']['active'] === true && $by['kho']['type'] === 'webhook', 'Chuyển webhook xuất kho → kênh kho');
check($by['shopee_ai']['active'] && $by['shopee_ai']['value'] === '', 'Lỗi AI Shopee bật, dùng nhóm kho');
check($by['uu_dai']['active'] === false, 'Ưu đãi chưa có webhook riêng → tắt (không rơi vào nhóm kho)');
check($by['cro']['active'] && $by['cro']['value'] === $hook('cro'), 'Chuyển webhook CRO');
$GLOBALS['options']['thean_export_lark_settings']['webhook_url'] = $hook('khac');
child_theme_lark_migrate();
check(array_column(get_option('erp_lark_settings')['channels'], null, 'key')['kho']['value'] === $hook('kho'), 'Chuyển 1 lần, không đè');

// Đích + dự phòng.
check(child_theme_lark_target('shopee_ai')['value'] === $hook('kho') && child_theme_lark_target('shopee_ai')['via'] === 'kho', 'Kênh trống dùng nhóm dự phòng');
child_theme_lark_save_channel(['key' => 'kho', 'active' => false]);
check(child_theme_lark_ready('shopee_ai') && !child_theme_lark_ready('kho'), 'Tắt kênh kho không tắt lỗi AI Shopee (vẫn dùng nhóm kho)');
check(!child_theme_lark_ready('uu_dai') && str_contains(child_theme_lark_target('uu_dai')['error'], 'đang tắt'), 'Kênh tắt → không gửi');
child_theme_lark_save_channel(['key' => 'uu_dai', 'active' => true]);
check(str_contains(child_theme_lark_target('uu_dai')['error'], 'chưa có webhook'), 'Bật mà trống, không có dự phòng → báo thiếu webhook');
child_theme_lark_save_channel(['key' => 'uu_dai', 'value' => $hook('uudai')]);

// Gửi: lỗi Lark trong body, không redirect, nhật ký không có URL.
check(child_theme_lark_text('uu_dai', 'Xin chào') === '' && $GLOBALS['sent'][0]['url'] === $hook('uudai') && $GLOBALS['sent'][0]['args']['redirection'] === 0, 'Gửi text');
$GLOBALS['body'] = '{"code":19021,"msg":"sign match fail or timestamp is not within one hour from current time"}';
$err = child_theme_lark_text('uu_dai', 'x');
check(str_contains($err, '19021'), 'HTTP 200 + mã lỗi Lark = lỗi');
$GLOBALS['body'] = '{"StatusCode":0,"StatusMessage":"success"}';
check(child_theme_lark_text('uu_dai', 'x') === '', 'Dạng phản hồi StatusCode cũ');
$GLOBALS['http'] = 0;
$err = child_theme_lark_text('uu_dai', 'x');
check($err !== '' && !str_contains($err, 'SECRET'), 'Lỗi mạng không lộ URL webhook');
$GLOBALS['http'] = 200; $GLOBALS['body'] = '{"code":0}';
check(!str_contains(json_encode(get_option('child_theme_lark_log')), 'hook/'), 'Nhật ký không chứa URL webhook');
check(child_theme_lark_last('uu_dai')['state'] === 'failed' && count(get_option('child_theme_lark_log')['recent']) === 4, 'Nhật ký lần gần nhất + lượt gần đây');
check(child_theme_lark_send('uu_dai', ['content' => []]) !== '', 'Thiếu msg_type → lỗi');
check(str_contains(child_theme_lark_send('uu_dai', ['msg_type' => 'text', 'content' => ['text' => str_repeat('a', 20000)]]), 'giới hạn'), 'Tin quá dài → lỗi, không gửi');

// Thẻ cùng dạng erp_lark_notify().
child_theme_lark_notify('uu_dai', ['title' => 'T', 'body' => '**B**', 'status' => 'warning', 'url' => 'https://x.test', 'fields' => [['label' => 'Đơn', 'value' => '#1']]]);
$card = end($GLOBALS['sent'])['body'];
check($card['msg_type'] === 'interactive' && $card['card']['header']['template'] === 'orange' && $card['card']['elements'][0]['text']['content'] === '**B**', 'Thẻ interactive');
check(end($card['card']['elements'])['actions'][0]['url'] === 'https://x.test', 'Nút "Xem chi tiết" từ url');

// Lên C: kênh chat_id đi qua Lark App khi có bộ ERP.
child_theme_lark_save_channel(['key' => 'cro', 'type' => 'chat_id', 'value' => 'oc_group1']);
check(str_contains(child_theme_lark_text('cro', 'x'), 'Lark App'), 'chat_id chưa có App → lỗi rõ');
if (!function_exists('erp_lark_api_post')) { function erp_lark_api_post($path, $payload) { $GLOBALS['app'][] = [$path, $payload]; return ['ok' => true, 'code' => 0]; } } // khai sau: mô phỏng cài bộ ERP
check(child_theme_lark_text('cro', 'qua app') === '' && $GLOBALS['app'][0][1]['receive_id'] === 'oc_group1'
    && json_decode($GLOBALS['app'][0][1]['content'], true)['text'] === 'qua app' && str_contains($GLOBALS['app'][0][0], 'receive_id_type=chat_id'), 'chat_id gửi qua erp_lark_api_post');

echo "PASS: $count assertions\n";

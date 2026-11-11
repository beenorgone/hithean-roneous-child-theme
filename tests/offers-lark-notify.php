<?php
/** Test thông báo Lark khi trang /uu-dai/ đổi chương trình (marketing/offers/lark-notify.php). Không cần WordPress / mạng. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__); define('MINUTE_IN_SECONDS', 60);
const HITHEAN_OFFERS_SNAPSHOT = 'hithean_offers_snapshot';
$GLOBALS['options'] = ['child_theme_lark_migrated' => 1]; $GLOBALS['events'] = []; $GLOBALS['posts_sent'] = []; $GLOBALS['actions'] = []; $GLOBALS['lark_fail'] = '';
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['actions'][$hook][] = $cb; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, ...$a) {
    $old = $GLOBALS['options'][$k] ?? null; $GLOBALS['options'][$k] = $v;
    foreach ($GLOBALS['actions']['update_option_' . $k] ?? [] as $cb) { $cb($old, $v); }
    return true;
}
function wp_next_scheduled($h) { return $GLOBALS['events'][$h] ?? false; }
function wp_schedule_single_event($at, $h) { $GLOBALS['events'][$h] = $at; }
function wp_date($f, $ts) { return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format($f); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function home_url($p = '/') { return 'https://hithean.test' . $p; }
function admin_url($p) { return home_url('/wp-admin/' . $p); }
function is_wp_error($v) { return false; }
function hithean_offers_settings() { return ['preview' => !empty($GLOBALS['preview'])]; }
function hithean_offers_lock($n, $t = 60) { return 'tok'; } function hithean_offers_unlock($n, $t) {}
function wp_remote_post($url, $args) { $GLOBALS['posts_sent'][] = ['url' => $url, 'body' => json_decode($args['body'], true)]; return ['http' => 200]; }
function wp_remote_retrieve_response_code($r) { return $r['http']; }
function wp_remote_retrieve_body($r) { return $GLOBALS['lark_fail'] !== '' ? '{"code":19021}' : '{"code":0}'; }
require_once dirname(__DIR__) . '/custom-functions/core/lark.php';
require dirname(__DIR__) . '/custom-functions/marketing/offers/lark-notify.php';

$count = 0;
function check($c, $m) { global $count; $count++; if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }
function text_of(array $msg): string {
    $out = [];
    foreach ($msg['content']['post']['vi_vn']['content'] as $line) { foreach ($line as $el) { $out[] = $el['text'] ?? ('@' . ($el['user_id'] ?? '')); } }
    return implode("\n", $out);
}
$now = 1_800_000_000;
$item = static fn($id, $pid, $name, $title, $end = 0, $extra = []) => array_merge(['id' => $id, 'product_id' => $pid, 'product_name' => $name,
    'title' => $title, 'source_title' => '', 'kind' => 'discount', 'start' => 0, 'end' => $end, 'verified_at' => $now], $extra);

// Tóm tắt: dòng bung theo SP gộp về 1 chương trình.
$old_items = [$item(10, 1, 'Yeast Hero', 'Giảm 10%', $now + 86400), $item(10, 2, 'ANC Protein', 'Giảm 10%', $now + 86400),
    $item(11, 1, 'Yeast Hero', 'Freeship đơn 500k', 0, ['source_title' => 'Freeship toàn quốc']), $item(12, 3, 'Bột đậu', 'Tặng bình lắc', $now - 10)];
$p = hithean_offers_lark_programs($old_items);
check(count($p) === 3 && count($p['10']['products']) === 2, 'Gộp dòng snapshot theo chương trình');
check($p['11']['name'] === 'Freeship toàn quốc', 'Tên chương trình ưu tiên source_title');

// Không đổi gì (chỉ verified_at đổi khi quét lại) → diff rỗng.
$rescan = array_map(static fn($r) => ['verified_at' => $now + 999] + $r, $old_items);
check(hithean_offers_lark_is_empty(hithean_offers_lark_diff($p, hithean_offers_lark_programs($rescan))), 'Quét lại không đổi nội dung → không báo');

// Thêm / sửa / ngừng.
$new_items = [$item(10, 1, 'Yeast Hero', 'Giảm 15%', $now + 7 * 86400), $item(10, 4, 'Granola', 'Giảm 15%', $now + 7 * 86400),
    $item(11, 1, 'Yeast Hero', 'Freeship đơn 500k', 0, ['source_title' => 'Freeship toàn quốc']), $item(13, 5, 'Trà', 'Mua 2 tặng 1')];
$diff = hithean_offers_lark_diff($p, hithean_offers_lark_programs($new_items));
check(array_keys($diff['added']) === [13] && array_keys($diff['ended']) === [12] && array_keys($diff['changed']) === [10], 'Phân loại mới / đổi / ngừng');
$c = $diff['changed'][10];
check($c['fields'] === ['nội dung', 'thời gian'] && array_values($c['plus']) === ['Granola'] && array_values($c['minus']) === ['ANC Protein'], 'Chi tiết thay đổi: nội dung, thời gian, SP thêm/bỏ');

$msg = hithean_offers_lark_message($diff, ['url' => home_url('/uu-dai/'), 'admin_url' => admin_url('x'), 'total_programs' => 3, 'total_products' => 4, 'now' => $now]);
$t = text_of($msg);
check($msg['msg_type'] === 'post' && str_contains($msg['content']['post']['vi_vn']['title'], 'Cập nhật chương trình ưu đãi'), 'Tin dạng post có tiêu đề');
check(str_contains($t, '🆕 Chương trình mới (1)') && str_contains($t, 'Mua 2 tặng 1') && str_contains($t, 'không thời hạn'), 'Nhóm chương trình mới');
check(str_contains($t, 'nội dung mới: Giảm 15%') && str_contains($t, 'thêm 1 SP: Granola') && str_contains($t, 'bỏ 1 SP: ANC Protein'), 'Nhóm thay đổi');
check(str_contains($t, 'hết hạn ') && str_contains($t, '⛔ Ngừng hiện trên trang (1)'), 'Chương trình quá ngày kết thúc ghi "hết hạn"');
check(str_contains($t, 'Đang áp dụng: 3 chương trình trên 4 sản phẩm') && str_contains($t, '👉 Xem trang ưu đãi'), 'Dòng tổng + link');
check(!str_contains($t, 'xem trước'), 'Không cảnh báo xem trước khi trang công khai');
check(str_contains(text_of(hithean_offers_lark_message($diff, ['preview' => true, 'now' => $now])), 'chế độ xem trước'), 'Cảnh báo khi trang ở chế độ xem trước');
check(str_starts_with(text_of(hithean_offers_lark_message($diff, ['mention_all' => true, 'now' => $now])), '@all'), 'Nhắc @Tất cả');

// Nhiều chương trình: cắt mỗi nhóm, tin vẫn dưới giới hạn.
$many = [];
for ($i = 1; $i <= 200; $i++) { $many[] = $item(1000 + $i, $i, str_repeat('Sản phẩm dài ', 8) . $i, str_repeat('Ưu đãi rất dài ', 20) . $i); }
$big = hithean_offers_lark_message(hithean_offers_lark_diff([], hithean_offers_lark_programs($many)), ['now' => $now, 'total_programs' => 200]);
check(strlen(json_encode($big, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) < 19000 && str_contains(text_of($big), 'và 185 chương trình khác'), 'Tin dài bị cắt gọn dưới giới hạn Lark');

// Luồng: tắt → không hẹn; bật → snapshot đổi mới hẹn gửi.
update_option(HITHEAN_OFFERS_SNAPSHOT, ['items' => $old_items]);
check(!wp_next_scheduled('hithean_offers_lark_flush'), 'Tắt thông báo → không hẹn gửi');
// Ưu đãi là NHÓM RIÊNG: có webhook nhóm kho mà kênh "uu_dai" trống thì vẫn không gửi.
child_theme_lark_save_channel(['key' => 'kho', 'type' => 'webhook', 'value' => 'https://open.larksuite.com/open-apis/bot/v2/hook/kho', 'active' => true]);
child_theme_lark_save_channel(['key' => 'uu_dai', 'type' => 'webhook', 'value' => '', 'active' => true]);
check(!hithean_offers_lark_settings()['enabled'], 'Kênh ưu đãi trống webhook → không dùng nhóm kho');
$GLOBALS['shared_hook'] = 'https://open.larksuite.com/open-apis/bot/v2/hook/uudai';
child_theme_lark_save_channel(['key' => 'uu_dai', 'value' => $GLOBALS['shared_hook']]);
check(hithean_offers_lark_settings()['enabled'], 'Kênh ưu đãi có webhook riêng → bật');
update_option(HITHEAN_OFFERS_SNAPSHOT, ['items' => $old_items, 'updated_at' => 2]);
check(!wp_next_scheduled('hithean_offers_lark_flush'), 'Snapshot đổi mà items giữ nguyên → không hẹn');

// Lần đầu (chưa có mốc): chỉ ghi mốc, không gửi.
hithean_offers_lark_flush();
check(!$GLOBALS['posts_sent'] && isset(get_option('hithean_offers_lark_state')['programs']), 'Lần đầu chỉ ghi mốc');

update_option(HITHEAN_OFFERS_SNAPSHOT, ['items' => $new_items]);
check(wp_next_scheduled('hithean_offers_lark_flush') !== false, 'Items đổi → hẹn gửi (gom 5 phút)');
unset($GLOBALS['events']['hithean_offers_lark_flush']);

// Gửi lỗi: giữ mốc, hẹn thử lại; lần 3 bỏ lượt.
$GLOBALS['lark_fail'] = 'Lark từ chối tin nhắn (mã 19021)';
hithean_offers_lark_flush();
$st = get_option('hithean_offers_lark_state');
check(count($GLOBALS['posts_sent']) === 1 && $st['attempts'] === 1 && isset($st['programs'][12]) && str_contains($st['error'], '19021') && wp_next_scheduled('hithean_offers_lark_flush'), 'Lỗi gửi → giữ mốc + hẹn thử lại');
check($GLOBALS['posts_sent'][0]['url'] === $GLOBALS['shared_hook'], 'Gửi vào webhook nhóm ưu đãi, không phải nhóm kho');
$GLOBALS['lark_fail'] = '';
hithean_offers_lark_flush();
$st = get_option('hithean_offers_lark_state');
check(count($GLOBALS['posts_sent']) === 2 && $st['attempts'] === 0 && !isset($st['programs'][12]) && $st['error'] === '', 'Gửi lại thành công → dời mốc');
hithean_offers_lark_flush();
check(count($GLOBALS['posts_sent']) === 2, 'Không đổi thêm → không gửi trùng');

check(hithean_offers_lark_test() === '' && str_contains(text_of(end($GLOBALS['posts_sent'])['body']), 'Tin gửi thử'), 'Gửi thử');

echo "PASS: $count assertions\n";

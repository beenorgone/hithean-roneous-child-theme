<?php
/** Test giữ dữ liệu ưu đãi mới (marketing/offers/freshness.php). Không cần WordPress. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__); define('HOUR_IN_SECONDS', 3600); define('DAY_IN_SECONDS', 86400);
const HITHEAN_OFFERS_FRESHNESS = 8 * DAY_IN_SECONDS; const HITHEAN_OFFERS_RUN = 'hithean_offers_run';
$GLOBALS['actions'] = [];
function add_action($h, $cb) { $GLOBALS['actions'][$h][] = $cb; }
require dirname(__DIR__) . '/custom-functions/marketing/offers/freshness.php';

$fail = 0;
function check(string $name, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $name: " . var_export($got, true) . "\n"; } else { echo "ok   $name\n"; } }
$now = 1_800_000_000; $d = DAY_IN_SECONDS;
$s = fn(int $age, bool $public = true, bool $running = false) => hithean_offers_freshness_state($age < 0 ? 0 : $now - $age, $now, $public, $running);

check('mới quét: không làm gì', [$s(3 * $d)['scan'], $s(3 * $d)['alert'], $s(3 * $d)['level']], [false, false, 'ok']);
check('6 ngày: tự quét lại, chưa báo', [$s(6 * $d)['scan'], $s(6 * $d)['alert'], $s(6 * $d)['level']], [true, false, 'warn']);
check('7 ngày vẫn chưa mới: quét + báo', [$s(7 * $d)['scan'], $s(7 * $d)['alert']], [true, true]);
check('quá 8 ngày: stale, quét + báo', [$s(9 * $d)['scan'], $s(9 * $d)['alert'], $s(9 * $d)['level']], [true, true, 'stale']);
check('chưa từng quét thành công', [$s(-1)['scan'], $s(-1)['level'], $s(-1)['expires']], [true, 'stale', 0]);
check('đang chạy lượt quét: không xếp lượt mới', $s(7 * $d, true, true)['scan'], false);
check('chế độ xem trước: không quét, không báo', [$s(9 * $d, false)['scan'], $s(9 * $d, false)['alert']], [false, false]);
check('mốc hết hạn', $s(6 * $d)['expires'], $now + 2 * $d);
check('cron đã đăng ký', isset($GLOBALS['actions']['hithean_offers_freshness']), true);
// Sự cố 19/11/2026: quét 11/11 09:26, tắt lịch tuần → 20/11 phải là stale + được quét lại.
$last = strtotime('2026-11-11T02:26:44Z');
$r = hithean_offers_freshness_state($last, strtotime('2026-11-20T05:00:00Z'), true, false);
check('tái hiện sự cố 20/11', [$r['scan'], $r['level']], [true, 'stale']);
exit($fail ? 1 : 0);

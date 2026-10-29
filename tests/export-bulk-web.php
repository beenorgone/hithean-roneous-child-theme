<?php
/**
 * Run with php tests/export-bulk-web.php (port từ theanmarket). No WordPress bootstrap needed —
 * tests mã đơn → ID và gom ảnh theo đơn của up nhiều đơn website.
 */
define('ABSPATH', __DIR__);
function add_action(...$a) {}

require dirname(__DIR__) . '/custom-functions/shortcodes/export-bulk-web.php';

$checks = 0;
function check($cond, string $msg): void
{
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
}

// Mã trên phiếu / nhãn → ID đơn.
check(hithean_export_bulk_resolve_ref('#10276**') === 10276, 'masked slip ref');
check(hithean_export_bulk_resolve_ref(' 10276 ** ') === 10276, 'masked ref with spaces');
check(hithean_export_bulk_resolve_ref('10276.2') === 10276, 'parcel ref ID.N');
check(hithean_export_bulk_resolve_ref('#10276') === 0, 'bare number is not trusted (could be anything)');
check(hithean_export_bulk_resolve_ref('0901234567') === 0, 'phone number ignored');
check(hithean_export_bulk_resolve_ref('S22788.SG01-K25.1811382021') === 0, 'carrier code needs ship_code lookup (wc_get_orders absent here)');

// Gom ảnh: không thấy mã → theo ảnh liền trước; đầu lô → theo ảnh liền sau.
$g = hithean_export_bulk_group_by_key([
    ['id' => 3, 'seq' => 2, 'key' => ''],
    ['id' => 1, 'seq' => 0, 'key' => ''],
    ['id' => 2, 'seq' => 1, 'key' => '10276'],
    ['id' => 4, 'seq' => 3, 'key' => '10280'],
    ['id' => 5, 'seq' => 4, 'key' => ''],
]);
check(array_keys($g['groups']) === [10276, 10280], 'groups by order id');
check($g['groups'][10276]['images'] === [1, 2, 3], 'leading + trailing unlabeled photos follow nearest order');
check($g['groups'][10276]['by_sequence'] === [1, 3], 'by_sequence marks guessed photos');
check($g['groups'][10280]['images'] === [4, 5] && $g['groups'][10280]['by_sequence'] === [5], 'second order');
$none = hithean_export_bulk_group_by_key([['id' => 1, 'seq' => 0, 'key' => '']]);
check(!$none['groups'] && $none['unassigned'] === [1], 'nothing readable → unassigned');

echo 'PASS: ' . $checks . " export-bulk-web checks.\n";

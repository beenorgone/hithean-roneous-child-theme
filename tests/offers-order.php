<?php
/** Thứ tự hiển thị chương trình ưu đãi (marketing/offers/order.php): mặc định giảm giá → quà tặng → vận chuyển, đổi được ở cài đặt. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
$GLOBALS['settings'] = [];
function hithean_offers_settings(): array { return $GLOBALS['settings']; }
require dirname(__DIR__) . '/custom-functions/marketing/offers/order.php';
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

$default = ['discount', 'tiers', 'gift', 'wheel', 'other', 'shipping'];
ok(hithean_offers_order_sanitize([]) === $default, 'Chưa sắp: giảm giá → mua nhiều → quà tặng → vòng quay → khác → vận chuyển');
ok(hithean_offers_order_sanitize(['shipping', 'gift', 'gift', 'x', 7]) === ['shipping', 'gift', 'discount', 'tiers', 'wheel', 'other'], 'Giữ thứ tự đã chọn, bỏ trùng / lạ, nhóm thiếu nối theo mặc định');
ok(hithean_offers_order_sanitize('bad') === $default, 'Giá trị không phải mảng → mặc định');
ok(hithean_offers_order_rank('addon', $default) === 2 && hithean_offers_order_rank('quantity', $default) === 1 && hithean_offers_order_rank('nope', $default) === 99, 'Tên nhóm của thẻ được quy về nhóm sắp xếp');

ok(hithean_offers_row_group(['family' => 'wheel', 'kind' => 'other']) === 'wheel', 'Vòng quay');
ok(hithean_offers_row_group(['family' => 'addon', 'kind' => 'other']) === 'gift' && hithean_offers_row_group(['family' => 'wdr', 'kind' => 'gift']) === 'gift', 'Add-on / quà tặng');
ok(hithean_offers_row_group(['family' => 'wdr', 'kind' => 'shipping']) === 'shipping', 'Vận chuyển');
ok(hithean_offers_row_group(['family' => 'wdr', 'kind' => 'quantity']) === 'tiers', 'Mua nhiều giảm giá');
ok(hithean_offers_row_group(['family' => 'sale', 'kind' => 'other']) === 'discount' && hithean_offers_row_group(['family' => 'wdr', 'kind' => 'discount']) === 'discount', 'Giá sale / rule giảm giá');
ok(hithean_offers_row_group(['family' => 'x', 'kind' => 'other']) === 'other', 'Còn lại');

$rows = [
    ['id' => 1, 'family' => 'wdr', 'kind' => 'shipping'],
    ['id' => 2, 'family' => 'addon', 'kind' => 'gift'],
    ['id' => 3, 'family' => 'sale', 'kind' => 'discount'],
    ['id' => 4, 'family' => 'wdr', 'kind' => 'shipping'],
    ['id' => 5, 'family' => 'wdr', 'kind' => 'discount'],
];
ok(array_column(hithean_offers_sort_rows($rows), 'id') === [3, 5, 2, 1, 4], 'Mặc định: giảm giá → tặng → vận chuyển, cùng nhóm giữ thứ tự cũ');
ok(hithean_offers_rows_rank($rows) === 0 && hithean_offers_rows_rank([]) === 99, 'Thẻ gộp xếp theo ưu đãi đứng đầu');

echo "PASS: $count offers-order assertions\n";

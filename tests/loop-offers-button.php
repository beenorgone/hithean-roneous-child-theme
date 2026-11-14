<?php
/** Nút ưu đãi trên thẻ sản phẩm ở danh sách (marketing/offers/loop-button.php): vị trí, nhãn, HTML từng vị trí. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
const HITHEAN_OFFERS_FRESHNESS = 8 * DAY_IN_SECONDS;
$GLOBALS['settings'] = ['preview' => false];
$GLOBALS['items'] = [];
function add_action(...$args) {}
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return (string) $value; }
function get_permalink($id) { return 'https://example.test/product/' . $id . '/'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function current_user_can($cap) { return false; }
function hithean_offers_settings(): array { return $GLOBALS['settings'] + ['preview' => true]; }
function hithean_offers_visible_items(): array { return $GLOBALS['items']; }
function hithean_offers_date_label(array $row): string { return ''; }
require dirname(__DIR__) . '/custom-functions/marketing/offers/order.php';
require dirname(__DIR__) . '/custom-functions/marketing/offers/offer-card.php';
require dirname(__DIR__) . '/custom-functions/marketing/offers/loop-button.php';

$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

ok(hithean_loop_offers_position() === 'c', 'Mặc định là vị trí c');
$GLOBALS['settings']['loop_button'] = 'x';
ok(hithean_loop_offers_position() === 'c', 'Giá trị lạ quay về c');
$GLOBALS['settings']['loop_button'] = 'b';
ok(hithean_loop_offers_position() === 'b', 'Đọc vị trí đã lưu');
ok(hithean_loop_offers_position(true) === 'b', 'Điện thoại mặc định b');
$GLOBALS['settings']['loop_button_mobile'] = 'a';
ok(hithean_loop_offers_position(true) === 'a' && hithean_loop_offers_position() === 'b', 'Điện thoại đọc cài đặt riêng');
ok(hithean_loop_offers_device_class('c', 'c', 'b') === 'hlo-d' && hithean_loop_offers_device_class('b', 'c', 'b') === 'hlo-m', 'Khác vị trí → mỗi nút 1 lớp thiết bị');
ok(hithean_loop_offers_device_class('a', 'c', 'b') === null, 'Vị trí không được chọn → không in');
ok(hithean_loop_offers_device_class('b', 'b', 'b') === '', 'Cùng vị trí → 1 nút cho mọi thiết bị');
ok(hithean_loop_offers_enabled() === true, 'Snapshot công khai → hiện nút');

ok(hithean_loop_offers_labels(0) === ['Xem ưu đãi', 'Xem ưu đãi của cửa hàng', 'Xem chương trình của cửa hàng'], 'SP chưa có ưu đãi riêng vẫn có nhãn');
ok(hithean_loop_offers_labels(3, "  Mua 2\n giảm 5% ")[0] === '3 ưu đãi' && hithean_loop_offers_labels(3, "  Mua 2\n giảm 5% ")[2] === 'Mua 2 giảm 5%', 'Nhãn đếm + tóm tắt gọn 1 dòng');

$base = ['product_name' => 'ANC Cacao', 'image' => '', 'end' => 0, 'verified_at' => time(), 'source_config' => []];
$GLOBALS['items'] = [
    ['product_id' => 7, 'family' => 'sale', 'kind' => 'discount', 'title' => 'Giảm 10% <b>']+ $base,
    ['product_id' => 7, 'family' => 'wdr', 'kind' => 'shipping', 'title' => 'Miễn phí vận chuyển'] + $base,
    ['product_id' => 7, 'family' => 'wheel', 'kind' => 'other', 'source_id' => 'wheel:prize', 'title' => 'Giải 1'] + $base,
    ['product_id' => 7, 'family' => 'wheel', 'kind' => 'other', 'source_id' => 'wheel:program', 'title' => 'x'] + $base,
    ['product_id' => 0, 'family' => 'sale', 'kind' => 'discount', 'title' => 'Không SP'] + $base,
];
ok(count(hithean_loop_offers_rows(7)) === 3, 'Gom dòng theo SP, giải vòng quay lẻ bị bỏ, chương trình vòng quay giữ');
ok(hithean_loop_offers_rows(8) === [], 'SP khác không có dòng');

$a = hithean_loop_offers_button_html(7, 'a');
ok(strpos($a, '<span class="hlo-anchor"><span class="hlo hlo--a" role="button" tabindex="0" data-hlo="7" data-hlo-url="https://example.test/product/7/"') === 0 && strpos($a, '3 ưu đãi') !== false, 'a: span trong neo, có số ưu đãi');
$c = hithean_loop_offers_button_html(7, 'c');
ok(strpos($c, '<span class="hlo hlo--c" role="button"') === 0 && strpos($c, '<button') === false, 'c: span (nằm trong link SP), không phải button');
$b = hithean_loop_offers_button_html(7, 'b');
ok(strpos($b, '<button type="button" class="hlo hlo--b"') === 0 && strpos($b, '<span class="hlo__label">Ưu đãi</span><span class="hlo__text">Giảm 10% &lt;b&gt;') !== false && strpos($b, '<span class="hlo__count">3</span>') !== false, 'b: button, tóm tắt thẻ đầu (giảm giá xếp trước vận chuyển) + số đếm');
$none = hithean_loop_offers_button_html(8, 'b');
ok(strpos($none, 'data-hlo="8"') !== false && strpos($none, '<span class="hlo__label">Ưu đãi</span>') !== false && strpos($none, 'Xem chương trình của cửa hàng') !== false && strpos($none, 'hlo__count') === false, 'SP chưa có ưu đãi riêng vẫn có nút, không số đếm');
ok(strpos(hithean_loop_offers_button_html(8, 'c'), 'Xem ưu đãi') !== false, 'c: SP chưa có ưu đãi riêng');
ok(strpos(hithean_loop_offers_button_html(1, 'a', 'hlo-m'), '<span class="hlo-anchor hlo-m"><span class="hlo hlo--a" role') === 0, 'a: lớp thiết bị nằm ở neo');
ok(strpos(hithean_loop_offers_button_html(1, 'b', 'hlo-m'), 'class="hlo hlo--b hlo-m"') !== false && strpos(hithean_loop_offers_button_html(1, 'c', 'hlo-d'), 'class="hlo hlo--c hlo-d"') !== false, 'b / c: lớp thiết bị trên nút');

echo "PASS: $count loop-offers-button assertions\n";

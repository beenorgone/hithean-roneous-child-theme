<?php
/** SP hết hàng ở danh sách (product-list-page.php): đưa xuống cuối (mặc định) / ẩn / để bình thường theo Cài đặt ERP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
$GLOBALS['opt'] = null;
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['opt'] ?? $default; }
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
require dirname(__DIR__) . '/custom-functions/woocommerce/products/product-list-page.php';

ok(hithean_loop_out_of_stock_mode() === 'end', 'Chưa lưu → mặc định đưa xuống cuối');
$GLOBALS['opt'] = 'hide';
ok(hithean_loop_out_of_stock_mode() === 'hide', 'Đọc chế độ đã lưu');
$GLOBALS['opt'] = 'lung-tung';
ok(hithean_loop_out_of_stock_mode() === 'end', 'Giá trị lạ → mặc định');

$base = ['join' => '', 'where' => ' AND wp_posts.post_type = \'product\'', 'orderby' => 'wp_posts.menu_order ASC'];
ok(hithean_loop_out_of_stock_apply_clauses($base, 'normal', 'wp_posts', 'wp_postmeta') === $base, 'normal → không đổi truy vấn');

$end = hithean_loop_out_of_stock_apply_clauses($base, 'end', 'wp_posts', 'wp_postmeta');
ok(strpos($end['join'], "meta_key = '_stock_status'") !== false, 'end → JOIN _stock_status');
ok(strpos($end['orderby'], "= 'outofstock' THEN 1 ELSE 0 END ASC, wp_posts.menu_order ASC") !== false, 'end → hết hàng xếp sau, giữ thứ tự gốc');
ok($end['where'] === $base['where'], 'end → không lọc');
$again = hithean_loop_out_of_stock_apply_clauses($end, 'end', 'wp_posts', 'wp_postmeta');
ok(substr_count($again['join'], 'product_stock_status_pm') === substr_count($end['join'], 'product_stock_status_pm'), 'Không JOIN trùng');

$hide = hithean_loop_out_of_stock_apply_clauses($base, 'hide', 'wp_posts', 'wp_postmeta');
ok(strpos($hide['where'], "meta_value <> 'outofstock'") !== false, 'hide → loại SP hết hàng');
ok(strpos($hide['where'], 'onbackorder') === false, 'hide → SP cho đặt trước vẫn hiện');
ok($hide['orderby'] === $base['orderby'], 'hide → giữ thứ tự sắp xếp');

echo "OK ($count kiểm tra)\n";

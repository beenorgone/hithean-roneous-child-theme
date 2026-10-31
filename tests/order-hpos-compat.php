<?php
// php tests/order-hpos-compat.php — filter get_post_metadata đọc meta đơn từ HPOS (dùng chung theanmarket + hithean).
namespace Automattic\WooCommerce\Utilities {
    class OrderUtil
    {
        public static function custom_orders_table_usage_is_enabled(): bool { return true; }
        public static function get_order_type($id) { return isset($GLOBALS['orders'][$id]) && $id < 20 ? 'shop_order' : null; } // loại đơn thật, không phải post type giữ chỗ
        public static function get_order_admin_edit_url($id) { return 'admin.php?page=wc-orders&action=edit&id=' . $id; }
    }
}

namespace {
    define('ABSPATH', __DIR__);
    $GLOBALS['filters'] = [];
    function add_filter($hook, $cb) { $GLOBALS['filters'][$hook] = $cb; }
    function apply_filters($hook, $value) { return $value; }
    function did_action($hook) { return 1; }
    function get_post_type($id) { return $GLOBALS['types'][$id] ?? false; }
    function thean_legacy_meta_empty($v): bool { return is_array($v) ? $v === [] : trim((string) $v) === ''; }
    class WC_Order
    {
        public $meta;
        public function __construct(array $meta) { $this->meta = $meta; }
        public function get_meta($key, $single = true)
        {
            return array_map(static function ($v) { return (object) ['value' => $v]; }, $this->meta[$key] ?? []);
        }
    }
    function wc_get_order($id) { return isset($GLOBALS['orders'][$id]) ? new WC_Order($GLOBALS['orders'][$id]) : false; }

    require __DIR__ . '/../custom-functions/woocommerce/orders/order-hpos-compat.php';

    $fail = 0;
    function check($ok, $msg) { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $msg . "\n"; if (!$ok) $fail++; }

    $GLOBALS['types']  = [10 => 'shop_order_placehold', 11 => 'shop_order', 20 => 'product'];
    $GLOBALS['orders'] = [10 => ['export_date' => ['2026-10-31'], 'handling_status' => [''], '_spx_date' => ['2026-10-30'], 'order_export_by' => ['a', 'b']],
                          11 => ['shipper' => ['GHTK']], 20 => ['hdsd' => ['x']]];
    $f = $GLOBALS['filters']['get_post_metadata'];

    check($f(null, 10, 'export_date', true) === ['2026-10-31'], 'đơn (placeholder) → giá trị HPOS');
    check($f(null, 11, 'shipper', true) === ['GHTK'], 'đơn shop_order (sync bật) → giá trị HPOS');
    check($f(null, 10, 'handling_status', true) === null, 'HPOS trống → đọc posts như cũ');
    check($f(null, 10, 'ship_code', true) === null, 'HPOS không có key → đọc posts');
    check($f(null, 10, '_spx_date', true) === ['2026-10-30'], 'key "_" trong danh sách → HPOS');
    check($f(null, 10, '_thean_export_ai_check_lock', true) === null, 'khóa _thean_*_lock → posts');
    check($f(null, 10, 'order_export_by', false) === ['a', 'b'], 'nhiều giá trị');
    check($f(null, 20, 'hdsd', true) === null, 'không phải đơn → không đụng');
    check($f(['x'], 10, 'export_date', true) === ['x'], 'filter khác đã trả giá trị → giữ');
    check($f(null, 10, '', false) === null, 'lấy toàn bộ meta → không đụng');

    check(thean_is_shop_order_id(10) && !thean_is_shop_order_id(20), 'thean_is_shop_order_id');
    check(thean_order_edit_url(10) === 'admin.php?page=wc-orders&action=edit&id=10', 'thean_order_edit_url HPOS');

    echo $fail ? "\n$fail lỗi\n" : "\nTất cả ok\n";
    exit($fail ? 1 : 0);
}

<?php
// php tests/order-flow-notes.php — mẫu ghi chú thao tác + cờ sự cố giao hàng (order-flow-notes.php).
define('ABSPATH', __DIR__);
$GLOBALS['opt'] = [];
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opt']) ? $GLOBALS['opt'][$k] : $d; }
function add_action(...$a) {}
function get_userdata($id) { return null; }
class WC_Order {
    public array $meta = [];
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
}
require __DIR__ . '/../custom-functions/woocommerce/orders/order-flow-notes.php';
$n = 0;
function ok($c, $m) { global $n; if (!$c) { fwrite(STDERR, "FAIL $m\n"); exit(1); } $n++; }

ok(thean_flow_note_parse_lines("  a  b \n\n a  b\r\nc") === ['a b', 'c'], 'tách dòng, gộp khoảng trắng, bỏ trùng');
ok(count(thean_flow_note_parse_lines(implode("\n", range(1, 30)))) === 20, 'tối đa 20 mẫu');
$t = thean_flow_note_templates();
ok(array_keys($t) === array_keys(thean_flow_note_kinds()), 'mọi kind đều có khóa');
ok($t['issue'] === thean_flow_note_templates_defaults()['issue'], 'chưa lưu → mặc định');
$GLOBALS['opt']['thean_flow_note_templates'] = ['issue' => [], 'note' => ['X']];
$t = thean_flow_note_templates();
ok($t['issue'] === [] && $t['note'] === ['X'], 'đã lưu (kể cả trống) → đúng giá trị lưu');
ok($t['hold'] === thean_flow_note_templates_defaults()['hold'], 'kind chưa có trong option → mặc định');

$o = new WC_Order();
ok(!thean_delivery_issue($o)['on'], 'chưa có cờ');
[$okf, $msg] = thean_delivery_issue_apply($o, 'flag', '  ');
ok(!$okf && strpos($msg, 'ghi chú') !== false, 'gắn cờ bắt buộc ghi chú');
ok(!thean_delivery_issue_apply($o, 'resolve', '')[0], 'không có cờ → không gỡ được');
$o->meta = [THEAN_DELIVERY_ISSUE_META => '1', THEAN_DELIVERY_ISSUE_META . '_note' => 'x', THEAN_DELIVERY_ISSUE_META . '_at' => '1'];
thean_delivery_issue_clear($o);
ok($o->meta === [], 'gỡ cờ xóa hết meta');
ok(in_array('shipping', thean_delivery_issue_steps(), true) && in_array('delivery', thean_delivery_issue_steps(), true), 'tách từ Chờ giao VC + Đang giao');
echo "OK ($n checks)\n";

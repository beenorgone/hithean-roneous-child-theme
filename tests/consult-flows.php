<?php
/** Kịch bản tư vấn + AI tư vấn: hàm thuần (chuẩn hóa, so khớp, nhận SP, đọc JSON AI) — không cần WordPress. */
define('ABSPATH', __DIR__);
define('HPA_MAX_DETAIL', 5);
function add_shortcode($tag, $cb) {}
function add_action(...$args) {}
function did_action($hook) { return 0; }

require dirname(__DIR__) . '/custom-functions/shortcodes/shortcode-consult-flows.php';
// Chỉ lấy các hàm thuần của advisor (file cần WooCommerce khi chạy thật nhưng include chỉ khai báo hàm).
require dirname(__DIR__) . '/custom-functions/shortcodes/shortcode-product-advisor.php';

$checks = 0;
function check($ok, string $msg): void { global $checks; $checks++; if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } }

// Chuẩn hóa bỏ dấu (cả dạng tổ hợp NFD khi copy từ Messenger trên Mac).
check(hcf_norm('Cho con BÚ, lợi sữa!') === 'cho con bu loi sua', 'norm bỏ dấu + ký tự');
check(hcf_norm('Đường huyết') === 'duong huyet', 'norm đ → d');
check(hcf_norm("cho con bu\u{0301}") === 'cho con bu', 'norm bỏ dấu tổ hợp');

// Làm sạch kịch bản.
$flow = hcf_sanitize_flow([
    'title' => ' <b>Mẹ sau sinh</b> ', 'group' => 'Mẹ & bé', 'keywords' => "sau sinh, cho con bú\nSau sinh", 'skus' => 'anc-01, ANC-01',
    'steps' => [['title' => 'Hỏi', 'say' => "Dạ {ten}\r\n---\r\nPhương án 2", 'note' => ''], ['title' => '', 'say' => '', 'note' => 'bỏ'], 'rác'],
    'status' => 'trash',
]);
check($flow['title'] === 'Mẹ sau sinh', 'title bỏ thẻ HTML + trim');
check($flow['keywords'] === ['sau sinh', 'cho con bú'], 'keywords tách dấu phẩy / xuống dòng, bỏ trùng không phân biệt hoa thường');
check($flow['skus'] === ['ANC-01'], 'SKU in hoa, bỏ trùng');
check(count($flow['steps']) === 1 && $flow['steps'][0]['say'] === "Dạ {ten}\n---\nPhương án 2", 'bỏ bước rỗng, giữ xuống dòng');
check($flow['status'] === 'publish', 'trạng thái lạ → publish');
// Kho option (không CPT): thêm / sửa / nhập / danh sách.
$store = hcf_store_normalize(null);
[$store, $id1] = hcf_store_upsert($store, ['title' => 'Khách chê đắt', 'group' => 'Phản đối', 'steps' => [['say' => 'Dạ…']]], '2026-11-18 10:00:00', 'NV A');
[$store, $id2] = hcf_store_upsert($store, ['title' => 'Mẹ sau sinh', 'group' => 'Mẹ & bé', 'status' => 'draft', 'steps' => [['say' => 'Dạ…']]], 't', 'NV A');
check($id1 === 1 && $id2 === 2 && $store['next_id'] === 3, 'thêm mới cấp id tăng dần');
[$s2, $err] = hcf_store_upsert($store, ['title' => 'X', 'steps' => []], 't', 'b');
check(is_string($err) && $s2 === $store, 'thiếu bước → lỗi, kho không đổi');
[$s2, $err] = hcf_store_upsert($store, ['id' => 99, 'title' => 'X', 'steps' => [['say' => 'a']]], 't', 'b');
check(is_string($err), 'sửa id không còn → lỗi');
[$store, $id, $before] = hcf_store_upsert($store, ['id' => 1, 'title' => 'Khách chê giá cao', 'steps' => [['say' => 'Mới']]], 't2', 'NV B');
check($id === 1 && $before['title'] === 'Khách chê đắt' && $store['flows'][1]['by'] === 'NV B', 'sửa trả bản cũ cho lịch sử');
check(array_column(hcf_store_list($store, false), 'id') === [1], 'nháp ẩn khi không phải người quản lý');
check(array_column(hcf_store_list($store, true), 'id') === [2, 1], 'sắp theo nhóm bỏ dấu (Mẹ & bé trước Phản đối… → theo norm)' );
[$store, $c, $u, $errs] = hcf_store_import($store, [['title' => 'MẸ SAU SINH', 'steps' => [['say' => 'cập nhật']]], ['title' => 'Mới', 'steps' => [['say' => 'x']]], ['title' => '']], 't3', 'NV C');
check($c === 1 && $u === 1 && count($errs) === 1 && $store['flows'][2]['steps'][0]['say'] === 'cập nhật', 'nhập: trùng tiêu đề không dấu → cập nhật, lỗi đếm riêng');
$reloaded = hcf_store_normalize(json_decode(json_encode($store), true));
check($reloaded['next_id'] === 4 && count($reloaded['flows']) === 3, 'đọc lại kho từ option giữ id / next_id');

// So khớp kịch bản.
$flows = [
    ['id' => 1, 'title' => 'Mẹ sau sinh', 'keywords' => ['cho con bú', 'lợi sữa'], 'skus' => []],
    ['id' => 2, 'title' => 'Khách chê đắt', 'keywords' => ['đắt', 'giảm giá'], 'skus' => []],
    ['id' => 3, 'title' => 'Prenatal', 'keywords' => ['bầu'], 'skus' => ['ANC-PRE']],
    ['id' => 4, 'title' => 'Bú', 'keywords' => ['bu'], 'skus' => []],
];
$m = hcf_match($flows, 'Chị đang CHO CON BU, uống có lợi sữa ko em?', [], 3);
check($m && $m[0]['id'] === 1, 'khớp cụm từ khóa không dấu, cao điểm nhất');
check(!in_array(2, array_column($m, 'id'), true), 'không khớp kịch bản không liên quan');
check(hcf_match($flows, 'giá sao em', ['anc-pre'])[0]['id'] === 3, 'khớp theo SKU');
check(hcf_match($flows, 'đắtđỏ', []) === [], 'không khớp nửa chữ');
check(count(hcf_match($flows, 'cho con bú đắt bầu bu', [], 2)) === 2, 'giới hạn số kịch bản');

// Nhận SP trong câu hỏi.
$cat = [
    ['id' => 10, 'name' => 'An New Chapter Perfect Prenatal Multivitamin', 'sku' => 'ANC-PRE', 'skus' => ['ANC-PRE', 'ANC-PRE-96']],
    ['id' => 11, 'name' => 'Yeast Hero Men Probiotic', 'sku' => 'YH-MEN', 'skus' => ['YH-MEN']],
    ['id' => 12, 'name' => 'An New Chapter Zyflamend Whole Body', 'sku' => 'ANC-ZY', 'skus' => ['ANC-ZY']],
];
check(hpa_detect_products('cho chị hỏi anc-pre-96 uống sao', $cat) === [10], 'nhận SKU biến thể → SP cha');
check(hpa_detect_products('Zyflamend whole body dùng lâu được không', $cat) === [12], 'nhận theo tên (độ phủ chữ có nghĩa)');
check(hpa_detect_products('an new chapter có loại nào cho bầu', $cat) === [], 'chỉ tên hãng chung → không đoán SP');
check(hpa_detect_products('', $cat) === [], 'câu rỗng');
check(hpa_ids_from_skus(['yh-men', 'anc-pre-96', 'không có'], $cat) === [10, 11], 'SKU → ID theo danh mục');

// Đọc JSON AI.
$r = hpa_parse_reply(['reply' => " Dạ <b>em</b> chào chị ", 'follow_up_questions' => ['Chị bao nhiêu tuổi?', '', 'Chị bao nhiêu tuổi?', 'a', 'b', 'c'], 'staff_notes' => 'không phải mảng', 'flow_id' => '7', 'need_product_details' => ['ANC-PRE']]);
check($r['reply'] === 'Dạ em chào chị', 'reply bỏ thẻ + trim');
check($r['follow_up_questions'] === ['Chị bao nhiêu tuổi?', 'a', 'b'], 'câu hỏi thêm bỏ rỗng / trùng, tối đa 3');
check($r['need_flow_ids'] === [] , 'need_flow_ids mặc định rỗng');
check(hpa_parse_reply(['need_flow_ids' => ['3', 3, 0, 'x', 9, 12]])['need_flow_ids'] === [3, 9], 'need_flow_ids: số nguyên dương, bỏ trùng, tối đa 2');
check($r['staff_notes'] === [] && $r['suggested_skus'] === [] && $r['flow_id'] === 7 && $r['need_product_details'] === ['ANC-PRE'], 'ép kiểu các trường khác');

// Kịch bản mẫu hợp lệ.
foreach (hcf_starter_flows() as $starter) {
    $clean = hcf_sanitize_flow($starter);
    check($clean['title'] !== '' && $clean['steps'] && $clean['keywords'], 'kịch bản mẫu hợp lệ: ' . $starter['title']);
}

echo "OK ($checks consult flow / advisor checks)\n";

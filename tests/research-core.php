<?php
/**
 * Hồ sơ khoa học — lõi (research-core.php) + AI parse / prompt (research-ai.php), không cần WordPress.
 * Lỗi giữ chặt: tài liệu chưa đăng / bị ẩn ở SP không được lọt ra trang khách; disclaimer đúng nguyên văn đã chốt;
 * ký hiệu "p<0.05" không bị cắt khi lưu.
 * Chạy: php tests/research-core.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_get_attachment_image_url($id) { return $id ? 'https://hithean.test/thumb-' . $id . '.jpg' : false; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
require dirname(__DIR__) . '/custom-functions/woocommerce/products/research/research-core.php';
require dirname(__DIR__) . '/custom-functions/woocommerce/products/research/research-ai.php';
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

/* ---- resolve ---- */
$items = [
    1 => ['evidence' => 'expert_media', 'published_on' => '2025-01-01'],
    2 => ['evidence' => 'meta_analysis', 'published_on' => '2020-01-01'],
    3 => ['evidence' => 'rct', 'published_on' => '2024-01-01'],
    4 => ['evidence' => 'rct', 'published_on' => '2022-01-01'],
    5 => ['evidence' => '', 'published_on' => ''],
];
$link = static fn($id, $type, $tid, $mode = 'include', $sort = 0) => ['research_id' => $id, 'target_type' => $type, 'target_id' => $tid, 'mode' => $mode, 'sort' => $sort];
$ctx = ['own' => ['product', 10], 'inherit' => [['product_cat', 3], ['product_cat', 1]]];
$links = [
    $link(1, 'product_cat', 3), $link(2, 'product_cat', 1), $link(3, 'product', 10), $link(4, 'product_cat', 3),
    $link(5, 'product', 10, 'pin', 2), $link(1, 'product', 10, 'pin', 1),
    $link(9, 'product', 10),                          // chưa đăng (không có trong $items)
    $link(6, 'product_cat', 99),                     // danh mục khác
];
ok(hithean_research_resolve($links, $items, $ctx) === [1, 5, 2, 3, 4], 'Ghim theo sort đứng đầu, còn lại: bằng chứng mạnh → mới hơn; bỏ tài liệu chưa đăng / danh mục khác');
$links[] = $link(4, 'product', 10, 'exclude');
ok(!in_array(4, hithean_research_resolve($links, $items, $ctx), true), 'Ẩn ở SP thắng gắn từ danh mục');
ok(hithean_research_resolve([$link(4, 'product', 11, 'exclude'), $link(4, 'product_cat', 3)], $items, $ctx) === [4], 'Ẩn ở SP khác không ảnh hưởng');
ok(hithean_research_resolve([$link(2, 'product_cat', 3, 'pin')], $items, $ctx) === [], 'Ghim / ẩn ở danh mục cha không được kế thừa (chỉ include)');
ok(count(hithean_research_resolve(array_map(static fn($i) => $link($i, 'page', 7), range(1, 5)), $items, ['own' => ['page', 7]], 3)) === 3, 'Giới hạn số thẻ');
ok(hithean_research_resolve([], $items, []) === [], 'Không có ngữ cảnh → rỗng');

/* ---- tóm tắt ---- */
$sum = hithean_research_normalize_summary('{"vi":{"headline":" Tiêu  đề ","summary":"Tóm tắt","points":["a","",' . str_repeat('"x",', 8) . '"y"],"limits":"Ít"},"en":{"headline":"T","summary":"S"},"model":"gemini","evil":"x"}');
ok($sum['vi']['headline'] === 'Tiêu đề' && count($sum['vi']['points']) === 6 && $sum['en']['points'] === [] && $sum['model'] === 'gemini' && !isset($sum['evil']), 'Chuẩn hoá tóm tắt');
ok(hithean_research_summary_complete($sum) && !hithean_research_summary_complete(hithean_research_normalize_summary(['vi' => ['headline' => 'a', 'summary' => 'b']])), 'Đủ để đăng cần cả VI và EN');
ok(hithean_research_normalize_summary('rác') === hithean_research_normalize_summary([]), 'JSON hỏng → cấu trúc rỗng');

/* ---- disclaimer (nguyên văn) ---- */
ok(hithean_research_disclaimer('vi', 'beta-glucan') === 'Nghiên cứu được AI tổng hợp và chuyển ngữ tự động phần tóm tắt nhằm giúp người đọc có kênh tham chiếu có giá trị về beta-glucan. Chúng tôi không soạn thảo và không chịu trách nhiệm cho các thông tin được thể hiện trong nội dung do AI tạo ra.', 'Disclaimer VI nguyên văn');
ok(strpos(hithean_research_disclaimer('vi', ''), 'có giá trị về sức khỏe và dinh dưỡng.') !== false, 'Thiếu chủ đề → sức khỏe và dinh dưỡng');
ok(strpos(hithean_research_disclaimer('en', 'yeast beta-glucan'), 'valuable reference on yeast beta-glucan. We did not author') !== false, 'Disclaimer EN');

/* ---- văn bản thuần ---- */
ok(hithean_research_plain_text("p<0.05, n>100 <b>đậm</b><script>alert(1)</script>\r\nhết", 500) === "p<0.05, n>100 đậm\nhết", 'Giữ ký hiệu khoa học, bỏ thẻ / script');
ok(hithean_research_plain_text(str_repeat('ă', 10), 4) === 'ăăăă' && hithean_research_plain_text(['x'], 9) === '', 'Cắt theo ký tự UTF-8, mảng → rỗng');

/* ---- dựng thẻ ---- */
$row = ['id' => 7, 'source_type' => 'journal', 'url' => 'https://x.test/a', 'canonical_url' => '', 'doi' => '10.1234/ab', 'video_id' => '',
    'title' => 'Tiêu đề <script>', 'authors' => '', 'publisher' => 'Nutrients', 'published_on' => '2023-05-02', 'thumb_id' => 0,
    'evidence' => 'rct', 'topic' => 'beta-glucan', 'topic_en' => '', 'summary' => $sum];
$card = hithean_research_card_html($row, 'dialog');
ok(strpos($card, 'Tiêu đề &lt;script&gt;') !== false && strpos($card, '<script>') === false, 'Tiêu đề được escape');
ok(strpos($card, 'href="https://doi.org/10.1234/ab"') !== false && strpos($card, 'rel="nofollow noopener noreferrer"') !== false, 'Nguồn gốc ưu tiên doi.org, mở tab mới an toàn');
ok(strpos($card, 'translate="no"') !== false && substr_count($card, '<template data-hr-tpl=') === 2, 'Tóm tắt 2 ngữ trong template, không cho Google dịch chồng');
ok(strpos($card, 'Nutrients · 2023') !== false && strpos($card, 'Thử nghiệm ngẫu nhiên') !== false && strpos($card, 'Về thành phần: <b>beta-glucan</b>') !== false, 'Nguồn · năm, mức bằng chứng, chủ đề');
ok(strpos($card, 'valuable reference on beta-glucan') !== false, 'Thiếu chủ đề EN → dùng chủ đề VI trong disclaimer EN');
$video = hithean_research_card_html(['source_type' => 'video', 'video_id' => 'dQw4w9WgXcQ', 'doi' => '', 'evidence' => 'zzz', 'topic' => ''] + $row, 'dialog');
ok(strpos($video, 'data-hr-video="dQw4w9WgXcQ"') !== false && strpos($video, 'iframe') === false, 'Video: chỉ nút ▶, không tải iframe sẵn');
$slide = hithean_research_card_html($row, 'slide');
ok(strpos($slide, 'data-hr-open="7"') !== false && strpos($slide, '<template') === false, 'Thẻ slider gọn, mở popup tại thẻ');
ok(hithean_research_trigger_html([], 'd') === '' && hithean_research_dialog_html([], 'd', '') === '' && hithean_research_slider_html([], 'd') === '', 'Không có tài liệu → không in gì');
$trigger = hithean_research_trigger_html([$row, ['source_type' => 'video'] + $row], 'hithean-research-dialog');
ok(strpos($trigger, 'Hồ sơ khoa học liên quan') !== false && strpos($trigger, '2 tài liệu · Tạp chí · Video') !== false, 'Nút: tên + dòng phụ');
ok(substr_count(hithean_research_dialog_html([$row], 'd', 'SP'), 'data-hr-filter') === 0, 'Một loại → không hiện bộ lọc');

/* ---- AI ---- */
[$system, $prompt] = hithean_research_ai_prompt(['title' => 'T', 'authors' => '', 'publisher' => 'J', 'published_on' => '', 'doi' => '', 'source_type' => 'journal', 'source_text' => 'Nội dung gốc']);
ok(strpos($system, 'KHÔNG nói về sản phẩm') !== false && strpos($system, 'insufficient') !== false, 'Prompt: cấm nói về SP, cho phép báo thiếu');
ok(strpos($prompt, 'Nội dung gốc') !== false && strpos($prompt, '- Nguồn: J') !== false && strpos($prompt, 'Tác giả') === false, 'Prompt chỉ gửi trường có dữ liệu');
$parsed = hithean_research_ai_parse(['insufficient' => false, 'evidence' => 'rct', 'topic_vi' => 'beta-glucan', 'topic_en' => 'beta-glucan',
    'vi' => ['headline' => 'a', 'summary' => 'b'], 'en' => ['headline' => 'c', 'summary' => 'd']]);
ok(!$parsed['insufficient'] && $parsed['evidence'] === 'rct' && $parsed['topic'] === 'beta-glucan', 'Parse kết quả AI hợp lệ');
ok(hithean_research_ai_parse(['vi' => ['headline' => 'a', 'summary' => 'b']])['insufficient'], 'Thiếu bản EN → coi như chưa đủ');
ok(hithean_research_ai_parse(['insufficient' => true] + $parsed['summary'])['insufficient'] && hithean_research_ai_parse(['evidence' => 'bịa'])['evidence'] === '', 'AI báo thiếu / mức bằng chứng lạ');

echo "PASS: $count research-core assertions\n";

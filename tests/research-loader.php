<?php
/**
 * Hồ sơ khoa học — đối chiếu ngược: mọi wp_ajax_hithean_research_* trong code phải có trong ajax_actions của module
 * research_admin (thiếu → handler không được nạp, nút bấm im lặng không chạy). Rút sự thật bằng tokenizer.
 * Chạy: php tests/research-loader.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

/** Mọi chuỗi hằng trong file (đã bỏ dấu nháy). */
function strings_in(string $file): array
{
    $out = [];
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $out[] = substr($token[1], 1, -1);
        }
    }
    return $out;
}

$hooks = [];
foreach (glob($root . '/custom-functions/woocommerce/products/research/*.php') as $file) {
    foreach (strings_in($file) as $s) {
        if (preg_match('/^wp_ajax_(?:nopriv_)?(hithean_research_\w+)$/', $s, $m)) {
            $hooks[$m[1]] = true;
        }
    }
}
ok(count($hooks) >= 10, 'Tìm thấy các handler AJAX của hồ sơ khoa học');

$declared = [];
foreach (strings_in($root . '/custom-functions/core/module-loader.php') as $s) {
    if (strpos($s, 'hithean_research_') === 0) {
        $declared[$s] = true;
    }
}
$missing = array_diff_key($hooks, $declared);
$stale   = array_diff_key($declared, $hooks);
ok(!$missing, 'Handler chưa khai ở module-loader: ' . implode(', ', array_keys($missing)));
ok(!$stale, 'Khai ở module-loader nhưng không có handler: ' . implode(', ', array_keys($stale)));

// JS gọi đúng action đã có handler.
preg_match_all("/'(hithean_research_\\w+)'/", (string) file_get_contents($root . '/custom-functions/woocommerce/products/research/assets/research-admin.js'), $m);
$unknown = array_diff(array_unique($m[1]), array_keys($hooks));
ok(!$unknown, 'JS gọi action không có handler: ' . implode(', ', $unknown));

$loader = (string) file_get_contents($root . '/custom-functions/core/module-loader.php');
ok(strpos($loader, "'shortcodes' => ['hithean_research']") !== false, 'Shortcode [hithean_research] có đường nạp');
ok(strpos((string) file_get_contents($root . '/custom-functions/core/ai-settings.php'), "'research_summary_ai'") !== false, 'Feature AI có trong Cài đặt ERP → AI');

echo "PASS: $count research-loader assertions\n";

<?php
/** "Ưu đãi của tôi" (marketing/my-offers.php) — phần thuần: chuẩn hóa liên hệ, che mã / liên hệ, hash định danh. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
function add_action(...$args) {}
function is_email($value) { return (bool) filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_email($value) { return trim($value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
require dirname(__DIR__) . '/custom-functions/marketing/my-offers.php';

$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

ok(hithean_mo_normalize_contact(' An@Example.COM ') === ['type' => 'email', 'value' => 'an@example.com'], 'Email is lower-cased');
ok(hithean_mo_normalize_contact('+84 912 345 678') === ['type' => 'phone', 'value' => '0912345678'], '+84 phone becomes local 0…');
ok(hithean_mo_normalize_contact('0912.345.678') === ['type' => 'phone', 'value' => '0912345678'], 'Separators are ignored');
ok(hithean_mo_normalize_contact('12345') === null && hithean_mo_normalize_contact('') === null, 'Invalid contacts are rejected');
ok(hithean_mo_phone_variants('0912345678') === ['0912345678', '+84912345678', '84912345678'], 'Phone variants cover SMS-login usernames');
// Phải trùng công thức thean_lw_claim_identity_hash() để tìm được mã vòng quay đã phát.
ok(hithean_mo_identity('email', 'an@example.com')['hash'] === hash('sha256', 'email:an@example.com'), 'Identity hash matches the lucky wheel formula');
ok(hithean_mo_mask(['type' => 'phone', 'value' => '0912345678']) === '091•••••78', 'Phone is masked');
ok(hithean_mo_mask(['type' => 'email', 'value' => 'nguyenvan@gmail.com']) === 'ng••••••@gmail.com', 'Email is masked');
ok(hithean_mo_mask_code('lw-ab12cd34') === 'LW-AB••••••', 'Wheel code keeps only its prefix');
ok(hithean_mo_mask_code('SALE50') === 'SA••••', 'Other codes keep two characters');
$many = hithean_mo_clean_contacts([['value' => 'a@x.vn'], ['value' => 'bad'], ['value' => '0912345678'], ['value' => 'A@x.vn'], ['value' => 'b@x.vn'], ['value' => 'c@x.vn']]);
ok(count($many) === HITHEAN_MO_MAX_CONTACTS && end($many)['value'] === 'c@x.vn', 'Remembered contacts are de-duplicated and capped, newest kept');

echo "PASS: $count my-offers assertions\n";

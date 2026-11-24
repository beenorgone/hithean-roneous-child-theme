// Hồ sơ khoa học — research.js trên HTML thật do research-core.php dựng (cần jsdom + php).
// Lỗi giữ chặt: video không được tải iframe trước khi bấm; đóng popup phải dừng video; ngôn ngữ đổi đồng loạt mọi thẻ đang mở.
// Chạy: node tests/research-ui.cjs
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');

const php = `
define('ABSPATH', __DIR__);
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function wp_get_attachment_image_url($id) { return false; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
require 'custom-functions/woocommerce/products/research/research-core.php';
$sum = ['vi' => ['headline' => 'Tiêu đề VI', 'summary' => 'Tóm tắt VI', 'points' => ['Ý 1'], 'limits' => 'Giới hạn VI'],
        'en' => ['headline' => 'Headline EN', 'summary' => 'Summary EN', 'points' => [], 'limits' => '']];
$base = ['url' => 'https://x.test', 'canonical_url' => '', 'doi' => '', 'video_id' => '', 'authors' => '', 'publisher' => 'J',
         'published_on' => '2024-01-01', 'thumb_id' => 0, 'evidence' => 'rct', 'topic' => 'beta-glucan', 'topic_en' => 'yeast beta-glucan', 'summary' => $sum];
$rows = [
  ['id' => 1, 'source_type' => 'journal', 'title' => 'Bài A'] + $base,
  ['id' => 2, 'source_type' => 'video', 'title' => 'Video B', 'video_id' => 'dQw4w9WgXcQ'] + $base,
  ['id' => 3, 'source_type' => 'news', 'title' => 'Báo C'] + $base,
];
echo hithean_research_trigger_html($rows, 'hrd') . hithean_research_slider_html($rows, 'hrd') . hithean_research_dialog_html($rows, 'hrd', 'SP X');
`;
const html = execFileSync('php', ['-r', php], { encoding: 'utf8' });
const script = fs.readFileSync('custom-functions/woocommerce/products/research/assets/research.js', 'utf8');

function env(cookie) {
    const dom = new JSDOM(`<body>${html}</body>`, { url: 'https://hithean.test/san-pham/x/', runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    if (cookie) w.document.cookie = cookie;
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
    w.Element.prototype.scrollIntoView = function () {};
    w.Element.prototype.scrollBy = function (o) { this.scrolledBy = o.left; };
    w.eval(script);
    return w;
}

const w = env();
const d = w.document;
const dialog = d.getElementById('hrd');
assert.equal(d.querySelectorAll('iframe').length, 0, 'Chưa bấm thì không tải video');

d.querySelector('[data-hr-trigger]').click();
assert.ok(dialog.hasAttribute('open'), 'Nút mở popup');
assert.equal(d.querySelectorAll('[data-hr-filter]').length, 4, 'Bộ lọc: Tất cả + 3 loại');

const card = dialog.querySelector('.hr-card[data-hr-id="1"]');
card.querySelector('[data-hr-read]').click();
assert.equal(card.querySelector('[data-hr-ai]').hidden, false, 'Đọc tóm tắt mở khối AI');
assert.match(card.querySelector('[data-hr-summary]').textContent, /Tiêu đề VI.*Giới hạn VI.*có giá trị về beta-glucan/s, 'Mặc định VI + disclaimer');
assert.equal(card.querySelector('[data-hr-read]').getAttribute('aria-expanded'), 'true');

const card3 = dialog.querySelector('.hr-card[data-hr-id="3"]');
card3.querySelector('[data-hr-read]').click();
card.querySelector('[data-hr-lang="en"]').click();
assert.match(card.querySelector('[data-hr-summary]').textContent, /Headline EN.*valuable reference on yeast beta-glucan/s, 'Gạt EN');
assert.match(card3.querySelector('[data-hr-summary]').textContent, /Headline EN/, 'Đổi ngôn ngữ áp cho mọi thẻ đang mở');
assert.equal(card3.querySelector('[data-hr-lang="en"]').getAttribute('aria-pressed'), 'true');
assert.equal(w.sessionStorage.getItem('hr-lang'), 'en', 'Nhớ ngôn ngữ trong phiên');

dialog.querySelector('[data-hr-filter="video"]').click();
assert.deepEqual([...dialog.querySelectorAll('.hr-card')].filter(c => !c.hidden).map(c => c.dataset.hrId), ['2'], 'Lọc theo loại');

dialog.querySelector('[data-hr-video]').click();
const frame = dialog.querySelector('iframe[data-hr-player]');
assert.ok(frame && frame.src.startsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'Bấm ▶ mới tải youtube-nocookie');

dialog.querySelector('[data-hr-close]').click();
assert.ok(!dialog.hasAttribute('open'), 'Đóng popup');
assert.equal(dialog.querySelectorAll('iframe').length, 0, 'Đóng popup dừng video');
assert.equal(dialog.querySelector('[data-hr-video]').hidden, false, 'Nút ▶ hiện lại');

d.querySelector('.hr-card--slide[data-hr-open="3"]').click();
assert.ok(dialog.hasAttribute('open'), 'Thẻ slider mở popup');
assert.equal(dialog.querySelector('.hr-card[data-hr-id="3"]').hidden, false, 'Thẻ đích hiện dù đang lọc loại khác');
assert.equal(dialog.querySelector('.hr-card[data-hr-id="3"] [data-hr-ai]').hidden, false, 'Mở sẵn tóm tắt thẻ đích');

d.querySelector('[data-hr-step="1"]').click();
assert.ok(d.querySelector('[data-hr-track]').scrolledBy !== undefined, 'Nút › cuộn slider');

const en = env('googtrans=/vi/en');
en.document.querySelector('[data-hr-trigger]').click();
const c1 = en.document.querySelector('#hrd .hr-card[data-hr-id="1"]');
c1.querySelector('[data-hr-read]').click();
assert.match(c1.querySelector('[data-hr-summary]').textContent, /Headline EN/, 'Khách đang dịch sang EN → mặc định EN');

console.log('PASS research-ui');

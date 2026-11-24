<?php
/**
 * Hồ sơ khoa học — parser bóc tách (research-extract.php), không cần mạng / WordPress.
 * Lỗi giữ chặt: link Scholar tìm kiếm không được coi là bài báo đã có nội dung; DOI có đuôi /full, dấu chấm cuối vẫn trùng khoá.
 * Chạy: php tests/research-extract.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/custom-functions/woocommerce/products/research/research-extract.php';
$count = 0;
function ok($condition, $message) { global $count; $count++; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

// Chuẩn hoá URL.
ok(hithean_research_normalize_url('HTTPS://Example.COM/a?utm_source=x&b=2&fbclid=z#top') === 'https://example.com/a?b=2', 'Bỏ utm / fbclid / fragment, host chữ thường');
ok(hithean_research_normalize_url('ftp://x.com/a') === '' && hithean_research_normalize_url('không phải link') === '', 'Không phải http(s) → rỗng');

// YouTube.
foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'https://youtu.be/dQw4w9WgXcQ?si=abc', 'https://youtube.com/shorts/dQw4w9WgXcQ',
          'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'https://m.youtube.com/live/dQw4w9WgXcQ'] as $url) {
    ok(hithean_research_youtube_id($url) === 'dQw4w9WgXcQ', 'YouTube ID: ' . $url);
}
ok(hithean_research_youtube_id('https://example.com/watch?v=dQw4w9WgXcQ') === '', 'Host khác YouTube → không phải video');

// DOI.
ok(hithean_research_doi_from_string('https://doi.org/10.3390/NU12061234.') === '10.3390/nu12061234', 'DOI từ doi.org, bỏ dấu chấm cuối, chữ thường');
ok(hithean_research_doi_from_string('https://www.tandfonline.com/doi/full/10.1080/07315724.2020.1752332/full') === '10.1080/07315724.2020.1752332', 'Bỏ đuôi /full');
ok(hithean_research_doi_from_string('https://x.com/doi/10.1016%2Fj.nut.2021.111%2Fabc') === '10.1016/j.nut.2021.111/abc', 'DOI mã hoá %2F');
ok(hithean_research_doi_from_string('không có') === '', 'Không có DOI');

// Scholar.
$scholar = 'https://scholar.google.com/scholar_url?url=https%3A%2F%2Fdoi.org%2F10.3390%2Fnu1234&hl=vi&sa=X';
ok(hithean_research_scholar_target($scholar) === 'https://doi.org/10.3390/nu1234', 'Gỡ link gốc trong scholar_url');
$id = hithean_research_identity($scholar);
ok($id['key'] === 'doi:10.3390/nu1234' && $id['source_type'] === 'journal' && !$id['scholar_unresolved'], 'Link Scholar có đích → khoá DOI');
$search = hithean_research_identity('https://scholar.google.com.vn/scholar?cluster=123&hl=vi');
ok($search['scholar_unresolved'] === true, 'Link tìm kiếm Scholar → cần bổ sung, không scrape');

// Danh tính: cùng nội dung → cùng khoá.
ok(hithean_research_identity('https://youtu.be/dQw4w9WgXcQ')['key'] === hithean_research_identity('https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_source=fb')['key'], 'Video dán 2 kiểu link → trùng');
ok(hithean_research_identity('https://doi.org/10.1234/ABC')['key'] === hithean_research_identity('https://journal.org/doi/10.1234/abc')['key'], 'Cùng DOI ở 2 trang → trùng');
ok(hithean_research_identity('https://vnexpress.net/bai-viet.html?utm_medium=x')['source_type'] === 'news', 'Link thường → báo');
ok(hithean_research_identity('mailto:a@b.c') === null, 'Link sai → null');

// Ngày.
ok(hithean_research_parse_date('2023') === '2023-01-01' && hithean_research_parse_date('2023/5/2') === '2023-05-02'
    && hithean_research_parse_date('2024-03-01T10:00:00Z') === '2024-03-01' && hithean_research_parse_date('abc') === '', 'Parse ngày');

// HTML meta.
$html = '<html><head><title>Tab title</title>
<meta name="citation_title" content="Yeast beta-glucan &amp; colds">
<meta name="citation_author" content="Nguyễn A"><meta name="citation_author" content="Smith B">
<meta name="citation_journal_title" content="Nutrients"><meta name="citation_publication_date" content="2023/05/02">
<meta name="citation_doi" content="10.3390/nu15051234"><meta property="og:image" content="https://www.mdpi.com/img.png">
<meta name="citation_abstract" content="Background: p&lt;0.05 in 12 trials.">
</head><body><nav>Menu dài dài dài dài dài dài dài dài dài</nav><article><p>Đoạn một của bài viết đủ dài để được giữ lại trong văn bản.</p><script>x()</script><p>ngắn</p></article></body></html>';
$meta = hithean_research_parse_html_meta($html);
ok($meta['title'] === 'Yeast beta-glucan & colds' && $meta['authors'] === 'Nguyễn A, Smith B', 'citation_title + tác giả (UTF-8)');
ok($meta['publisher'] === 'Nutrients' && $meta['published_on'] === '2023-05-02' && $meta['doi'] === '10.3390/nu15051234' && $meta['kind'] === 'journal', 'Tạp chí, ngày, DOI');
ok($meta['abstract'] === 'Background: p<0.05 in 12 trials.' && $meta['image'] === 'https://www.mdpi.com/img.png', 'Abstract giữ "<", ảnh og');
ok(hithean_research_extract_article_text($html) === 'Đoạn một của bài viết đủ dài để được giữ lại trong văn bản.', 'Thân bài: chỉ <article>, bỏ script, bỏ đoạn quá ngắn');

$ld = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Báo"},{"@type":"NewsArticle","headline":"Nấm men và sức khoẻ","datePublished":"2025-01-09","author":[{"@type":"Person","name":"BS. Lan"}],"publisher":{"@type":"Organization","name":"Báo Sức Khỏe"},"image":{"url":"https://bao.vn/a.jpg"}}]}</script></head><body></body></html>';
$m2 = hithean_research_parse_html_meta($ld);
ok($m2['title'] === 'Nấm men và sức khoẻ' && $m2['authors'] === 'BS. Lan' && $m2['publisher'] === 'Báo Sức Khỏe' && $m2['published_on'] === '2025-01-09' && $m2['image'] === 'https://bao.vn/a.jpg', 'JSON-LD @graph NewsArticle');
ok(hithean_research_parse_html_meta('')['title'] === '', 'HTML rỗng không lỗi');

// Crossref / Europe PMC / oEmbed / YouTube API.
$cr = hithean_research_parse_crossref(['message' => ['title' => ['A trial'], 'author' => [['given' => 'Ann', 'family' => 'Lee'], ['name' => 'Group X']],
    'container-title' => ['Journal of Nutrition'], 'issued' => ['date-parts' => [[2022, 7]]], 'DOI' => '10.1/X', 'abstract' => '<jats:p>Result p&lt;0.01.</jats:p>', 'type' => 'journal-article']]);
ok($cr['title'] === 'A trial' && $cr['authors'] === 'Ann Lee, Group X' && $cr['publisher'] === 'Journal of Nutrition' && $cr['published_on'] === '2022-07-01'
    && $cr['doi'] === '10.1/x' && $cr['abstract'] === 'Result p<0.01.', 'Crossref');
$ep = hithean_research_parse_europepmc(['resultList' => ['result' => [['abstractText' => 'Abs <i>text</i>', 'pmid' => '123', 'pubTypeList' => ['pubType' => ['Meta-Analysis', 'Journal Article']]]]]]);
ok($ep['abstract'] === 'Abs text' && $ep['pmid'] === '123' && hithean_research_guess_evidence($ep['pub_type'], '') === 'meta_analysis', 'Europe PMC + đoán meta-analysis');
ok(hithean_research_guess_evidence('', 'A randomized controlled trial of X') === 'rct' && hithean_research_guess_evidence('', 'Yeast') === '', 'Đoán RCT / không đoán bừa');
$oe = hithean_research_parse_oembed(['title' => 'Video A', 'author_name' => 'Kênh B', 'thumbnail_url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg']);
ok($oe['title'] === 'Video A' && $oe['publisher'] === 'Kênh B', 'oEmbed');
$yt = hithean_research_parse_youtube_api(['items' => [['snippet' => ['description' => 'Mô tả', 'publishedAt' => '2024-02-03T00:00:00Z', 'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/h.jpg']]]]]]);
ok($yt['abstract'] === 'Mô tả' && $yt['published_on'] === '2024-02-03' && $yt['image'] === 'https://i.ytimg.com/h.jpg', 'YouTube Data API');

// Gộp + ảnh cho phép.
ok(hithean_research_merge(['title' => '', 'a' => '1'], ['title' => 'T', 'a' => '2']) == ['title' => 'T', 'a' => '1'], 'Gộp: giá trị không rỗng đầu tiên thắng');
ok(hithean_research_image_allowed('https://i.ytimg.com/vi/x.jpg', 'https://www.youtube.com/watch?v=x'), 'Ảnh YouTube');
ok(hithean_research_image_allowed('https://cdn.mdpi.com/a.png', 'https://www.mdpi.com/2072'), 'Ảnh cùng tên miền gốc');
ok(!hithean_research_image_allowed('https://evil.test/a.png', 'https://www.mdpi.com/2072') && !hithean_research_image_allowed('http://www.mdpi.com/a.png', 'https://www.mdpi.com/'), 'Chặn host lạ / http');

echo "PASS: $count research-extract assertions\n";

<?php
/**
 * Hồ sơ khoa học — bóc tách nguồn từ link.
 *
 * Mọi đường nhập (dán ở bảng, dán ở metabox SP, "Bóc lại") đi qua hithean_research_ingest().
 * Parser là hàm thuần (chuỗi / mảng vào → mảng ra), test ở tests/research-extract.php không cần mạng.
 *
 * Giới hạn nguồn (thiết kế quanh, không né):
 * - Google Scholar: KHÔNG scrape. Chỉ gỡ link gốc trong `scholar_url?url=`; link tìm kiếm / cluster → Cần bổ sung.
 * - YouTube: oEmbed (không key) + mô tả qua Data API khi có YOUTUBE_API_KEY; phụ đề không lấy được → nhân sự dán transcript.
 * - Thiếu nội dung gốc (< HITHEAN_RESEARCH_MIN_TEXT ký tự) → trạng thái needs_input; AI không chạy.
 */

defined('ABSPATH') || exit;

const HITHEAN_RESEARCH_MIN_TEXT   = 300;
const HITHEAN_RESEARCH_MAX_TEXT   = 60000;
const HITHEAN_RESEARCH_FETCH_SIZE = 2 * 1024 * 1024;
const HITHEAN_RESEARCH_UA         = 'HitheanResearchBot/1.0 (+https://hithean.com)';

/* ---------- Nhận dạng link (thuần) ---------- */

function hithean_research_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function hithean_research_substr(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** Bỏ tham số theo dõi, fragment; host chữ thường. '' khi không phải http(s). */
function hithean_research_normalize_url(string $url): string
{
    $url = trim($url);
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
        return '';
    }
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(utm_|fbclid$|gclid$|mc_|igshid$|si$|feature$)/i', (string) $key)) {
                unset($query[$key]);
            }
        }
    }
    $out = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . ($parts['path'] ?? '/');
    if ($query) {
        ksort($query);
        $out .= '?' . http_build_query($query);
    }
    return $out;
}

/** ID video YouTube (watch, youtu.be, shorts, embed, live) hoặc ''. */
function hithean_research_youtube_id(string $url): string
{
    if (preg_match('~^https?://(?:www\.|m\.|music\.)?(?:youtube\.com|youtube-nocookie\.com|youtu\.be)/~i', $url) !== 1) {
        return '';
    }
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~', $url, $m)
        || preg_match('~/(?:shorts|embed|live|v)/([A-Za-z0-9_-]{11})~', $url, $m)
        || preg_match('~[?&]v=([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** DOI đầu tiên trong chuỗi (chữ thường, bỏ dấu câu cuối) hoặc ''. */
function hithean_research_doi_from_string(string $text): string
{
    $text = rawurldecode($text);
    if (preg_match('~\b(10\.\d{4,9}/[^\s"\'<>#?&]+)~u', $text, $m) !== 1) {
        return '';
    }
    $doi = rtrim($m[1], '.,;:)]}');
    $doi = preg_replace('~/(full|abstract|pdf|epdf|html)$~i', '', $doi) ?? $doi;
    return strtolower($doi);
}

function hithean_research_is_scholar(string $url): bool
{
    return preg_match('~^https?://scholar\.google\.[a-z.]+/~i', $url) === 1;
}

/** Link gốc trong link Google Scholar (scholar_url?url=…) hoặc ''. */
function hithean_research_scholar_target(string $url): string
{
    if (!hithean_research_is_scholar($url)) {
        return '';
    }
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $target = (string) ($query['url'] ?? '');
    return preg_match('~^https?://~i', $target) === 1 && !hithean_research_is_scholar($target) ? $target : '';
}

/**
 * Danh tính của link: khoá chống trùng + loại + DOI / video.
 *
 * @return array{key:string,url:string,source_type:string,doi:string,video_id:string,scholar_unresolved:bool}|null
 */
function hithean_research_identity(string $url): ?array
{
    $raw = trim($url);
    if (hithean_research_is_scholar($raw)) {
        $target = hithean_research_scholar_target($raw);
        if ($target === '') {
            $norm = hithean_research_normalize_url($raw);
            return $norm === '' ? null : ['key' => 'url:' . $norm, 'url' => $norm, 'source_type' => 'journal', 'doi' => '', 'video_id' => '', 'scholar_unresolved' => true];
        }
        $raw = $target;
    }
    $norm = hithean_research_normalize_url($raw);
    if ($norm === '') {
        return null;
    }
    $video = hithean_research_youtube_id($raw);
    if ($video !== '') {
        return ['key' => 'youtube:' . $video, 'url' => 'https://www.youtube.com/watch?v=' . $video, 'source_type' => 'video', 'doi' => '', 'video_id' => $video, 'scholar_unresolved' => false];
    }
    $doi = hithean_research_doi_from_string($raw);
    if ($doi !== '') {
        return ['key' => 'doi:' . $doi, 'url' => $norm, 'source_type' => 'journal', 'doi' => $doi, 'video_id' => '', 'scholar_unresolved' => false];
    }
    return ['key' => 'url:' . $norm, 'url' => $norm, 'source_type' => 'news', 'doi' => '', 'video_id' => '', 'scholar_unresolved' => false];
}

/* ---------- Parser (thuần) ---------- */

/** Ngày bất kỳ ("2023", "2023/05/02", ISO) → Y-m-d hoặc ''. */
function hithean_research_parse_date(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(\d{4})(?:[-\/](\d{1,2}))?(?:[-\/](\d{1,2}))?/', $value, $m) !== 1) {
        return '';
    }
    $y = (int) $m[1];
    $mo = isset($m[2]) && $m[2] !== '' ? max(1, min(12, (int) $m[2])) : 1;
    $d = isset($m[3]) && $m[3] !== '' ? max(1, min(31, (int) $m[3])) : 1;
    return $y >= 1900 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}

/** Bỏ thẻ HTML thật (bắt đầu bằng chữ cái) rồi giải mã entity — giữ "p<0.05" (strip_tags cắt mất đoạn sau "<"). */
function hithean_research_clean_text(string $text): string
{
    $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~isu', ' ', $text) ?? '';
    $text = preg_replace('~</?[a-z][a-z0-9:-]*(?:\s[^<>]*)?/?>~iu', ' ', $text) ?? '';
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '');
}

function hithean_research_dom(string $html): ?DOMDocument
{
    if ($html === '' || !class_exists('DOMDocument')) {
        return null;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

/**
 * Metadata từ thẻ meta (citation_*, dc.*, og:*) + JSON-LD (ScholarlyArticle / Article / NewsArticle / VideoObject).
 *
 * @return array{title:string,authors:string,publisher:string,published_on:string,doi:string,abstract:string,image:string,kind:string}
 */
function hithean_research_parse_html_meta(string $html): array
{
    $out = ['title' => '', 'authors' => '', 'publisher' => '', 'published_on' => '', 'doi' => '', 'abstract' => '', 'image' => '', 'kind' => ''];
    $doc = hithean_research_dom($html);
    if (!$doc) {
        return $out;
    }
    $meta = [];
    foreach ($doc->getElementsByTagName('meta') as $node) {
        $name = strtolower(trim($node->getAttribute('name') ?: $node->getAttribute('property')));
        $content = hithean_research_clean_text($node->getAttribute('content'));
        if ($name !== '' && $content !== '') {
            $meta[$name][] = $content;
        }
    }
    $first = static function (array $keys) use ($meta): string {
        foreach ($keys as $key) {
            if (!empty($meta[$key][0])) {
                return $meta[$key][0];
            }
        }
        return '';
    };

    $out['title']        = $first(['citation_title', 'dc.title', 'og:title', 'twitter:title']);
    $out['authors']      = implode(', ', array_slice($meta['citation_author'] ?? $meta['dc.creator'] ?? $meta['author'] ?? [], 0, 12));
    $out['publisher']    = $first(['citation_journal_title', 'citation_publisher', 'dc.publisher', 'og:site_name']);
    $out['published_on'] = hithean_research_parse_date($first(['citation_publication_date', 'citation_date', 'citation_online_date', 'dc.date', 'article:published_time', 'date']));
    $out['doi']          = hithean_research_doi_from_string($first(['citation_doi', 'dc.identifier', 'prism.doi']));
    $out['abstract']     = $first(['citation_abstract', 'dc.description', 'og:description', 'description']);
    $out['image']        = $first(['og:image', 'og:image:url', 'twitter:image']);
    if ($first(['citation_title', 'citation_journal_title']) !== '') {
        $out['kind'] = 'journal';
    }

    foreach ($doc->getElementsByTagName('script') as $script) {
        if (stripos($script->getAttribute('type'), 'ld+json') === false) {
            continue;
        }
        $data = json_decode(trim($script->textContent), true);
        if (!is_array($data)) {
            continue;
        }
        $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_keys($data) === range(0, count($data) - 1) ? $data : [$data]);
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = implode(' ', (array) ($node['@type'] ?? ''));
            if (!preg_match('/Article|ScholarlyArticle|VideoObject|NewsArticle|BlogPosting|Report/i', $type)) {
                continue;
            }
            $out['title'] = $out['title'] ?: hithean_research_clean_text((string) ($node['headline'] ?? $node['name'] ?? ''));
            if ($out['authors'] === '' && !empty($node['author'])) {
                $names = [];
                foreach (isset($node['author']['name']) ? [$node['author']] : (array) $node['author'] as $author) {
                    $name = is_array($author) ? (string) ($author['name'] ?? '') : (string) $author;
                    if ($name !== '') {
                        $names[] = hithean_research_clean_text($name);
                    }
                }
                $out['authors'] = implode(', ', array_slice($names, 0, 12));
            }
            if ($out['publisher'] === '' && !empty($node['publisher'])) {
                $out['publisher'] = hithean_research_clean_text((string) (is_array($node['publisher']) ? ($node['publisher']['name'] ?? '') : $node['publisher']));
            }
            $out['published_on'] = $out['published_on'] ?: hithean_research_parse_date((string) ($node['datePublished'] ?? $node['uploadDate'] ?? ''));
            $out['abstract'] = $out['abstract'] ?: hithean_research_clean_text((string) ($node['abstract'] ?? $node['description'] ?? ''));
            if ($out['image'] === '' && !empty($node['image'])) {
                $image = $node['image'];
                $out['image'] = (string) (is_array($image) ? ($image['url'] ?? $image[0] ?? '') : $image);
            }
            if ($out['kind'] === '' && stripos($type, 'Scholarly') !== false) {
                $out['kind'] = 'journal';
            }
        }
    }
    if ($out['title'] === '') {
        $titles = $doc->getElementsByTagName('title');
        $out['title'] = $titles->length ? hithean_research_clean_text($titles->item(0)->textContent) : '';
    }
    return $out;
}

/** Thân bài: <article> → <main> → <body>, bỏ script/nav/…; ghép đoạn văn, cắt 20.000 ký tự. */
function hithean_research_extract_article_text(string $html): string
{
    $doc = hithean_research_dom($html);
    if (!$doc) {
        return '';
    }
    foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'figure', 'iframe', 'svg'] as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            $node->parentNode->removeChild($node);
        }
    }
    $root = null;
    foreach (['article', 'main', 'body'] as $tag) {
        $list = $doc->getElementsByTagName($tag);
        if ($list->length) {
            $root = $list->item(0);
            break;
        }
    }
    if (!$root) {
        return '';
    }
    $xpath = new DOMXPath($doc);
    $lines = [];
    foreach ($xpath->query('.//p|.//h2|.//h3|.//li', $root) as $node) {
        $text = hithean_research_clean_text($node->textContent);
        if (hithean_research_strlen($text) >= 30) {
            $lines[] = $text;
        }
    }
    return hithean_research_substr(implode("\n", array_unique($lines)), 20000);
}

/** Crossref /works/{doi} → metadata. */
function hithean_research_parse_crossref(array $json): array
{
    $msg = is_array($json['message'] ?? null) ? $json['message'] : $json;
    $authors = [];
    foreach (array_slice((array) ($msg['author'] ?? []), 0, 12) as $author) {
        $name = trim(($author['given'] ?? '') . ' ' . ($author['family'] ?? ''));
        if ($name === '' && !empty($author['name'])) {
            $name = (string) $author['name'];
        }
        if ($name !== '') {
            $authors[] = $name;
        }
    }
    $parts = $msg['published']['date-parts'][0] ?? $msg['issued']['date-parts'][0] ?? $msg['published-print']['date-parts'][0] ?? [];
    $date = $parts ? hithean_research_parse_date(implode('-', array_map('strval', (array) $parts))) : '';
    return [
        'title'        => hithean_research_clean_text((string) (($msg['title'][0] ?? '') ?: '')),
        'authors'      => implode(', ', $authors),
        'publisher'    => hithean_research_clean_text((string) (($msg['container-title'][0] ?? '') ?: ($msg['publisher'] ?? ''))),
        'published_on' => $date,
        'doi'          => strtolower((string) ($msg['DOI'] ?? '')),
        'abstract'     => hithean_research_clean_text(preg_replace('~</?jats:[^>]+>~', ' ', (string) ($msg['abstract'] ?? '')) ?? ''),
        'url'          => (string) ($msg['URL'] ?? ''),
        'kind'         => (string) ($msg['type'] ?? ''),
    ];
}

/** Europe PMC search (resultType=core) → abstract + PMID. */
function hithean_research_parse_europepmc(array $json): array
{
    $row = $json['resultList']['result'][0] ?? [];
    return [
        'abstract' => hithean_research_clean_text((string) ($row['abstractText'] ?? '')),
        'pmid'     => preg_match('/^\d+$/', (string) ($row['pmid'] ?? '')) ? (string) $row['pmid'] : '',
        'pub_type' => strtolower(implode(' ', (array) ($row['pubTypeList']['pubType'] ?? []))),
    ];
}

/** Đoán mức bằng chứng từ loại bài (Europe PMC / tiêu đề). '' khi không chắc. */
function hithean_research_guess_evidence(string $pub_type, string $title): string
{
    $hay = strtolower($pub_type . ' ' . $title);
    if (strpos($hay, 'meta-analysis') !== false || strpos($hay, 'meta analysis') !== false) {
        return 'meta_analysis';
    }
    if (strpos($hay, 'randomized') !== false || strpos($hay, 'randomised') !== false) {
        return 'rct';
    }
    if (strpos($hay, 'review') !== false) {
        return 'review';
    }
    return '';
}

/** YouTube oEmbed → tiêu đề, kênh, thumbnail. */
function hithean_research_parse_oembed(array $json): array
{
    return [
        'title'     => hithean_research_clean_text((string) ($json['title'] ?? '')),
        'publisher' => hithean_research_clean_text((string) ($json['author_name'] ?? '')),
        'image'     => (string) ($json['thumbnail_url'] ?? ''),
    ];
}

/** YouTube Data API videos.list (part=snippet) → mô tả, ngày đăng. */
function hithean_research_parse_youtube_api(array $json): array
{
    $snippet = $json['items'][0]['snippet'] ?? [];
    $thumbs  = (array) ($snippet['thumbnails'] ?? []);
    return [
        'abstract'     => trim((string) ($snippet['description'] ?? '')),
        'published_on' => hithean_research_parse_date((string) ($snippet['publishedAt'] ?? '')),
        'image'        => (string) ($thumbs['maxres']['url'] ?? $thumbs['high']['url'] ?? ''),
    ];
}

/** Gộp nhiều nguồn metadata: giá trị đầu tiên không rỗng thắng. */
function hithean_research_merge(array ...$parts): array
{
    $out = [];
    foreach ($parts as $part) {
        foreach ($part as $key => $value) {
            if ((!isset($out[$key]) || $out[$key] === '') && is_scalar($value) && (string) $value !== '') {
                $out[$key] = (string) $value;
            }
        }
    }
    return $out;
}

/** Host ảnh được phép tải về Media: i.ytimg.com hoặc cùng tên miền gốc với nguồn. */
function hithean_research_image_allowed(string $image, string $source_url): bool
{
    $ih = strtolower((string) parse_url($image, PHP_URL_HOST));
    $sh = strtolower((string) parse_url($source_url, PHP_URL_HOST));
    if ($ih === '' || strtolower((string) parse_url($image, PHP_URL_SCHEME)) !== 'https') {
        return false;
    }
    if ($ih === 'i.ytimg.com') {
        return true;
    }
    $base = static function (string $host): string {
        $labels = explode('.', $host);
        return implode('.', array_slice($labels, -2));
    };
    return $sh !== '' && $base($ih) === $base($sh);
}

/* ---------- Mạng ---------- */

/** GET an toàn (chặn IP nội bộ), ≤ 2 MB, ≤ 3 redirect. @return string|WP_Error thân phản hồi. */
function hithean_research_fetch(string $url, array $headers = [])
{
    $response = wp_safe_remote_get($url, [
        'timeout'             => 10,
        'redirection'         => 3,
        'limit_response_size' => HITHEAN_RESEARCH_FETCH_SIZE,
        'user-agent'          => HITHEAN_RESEARCH_UA,
        'headers'             => $headers + ['Accept-Language' => 'en,vi;q=0.8'],
    ]);
    if (is_wp_error($response)) {
        return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('hithean_research_http', sprintf('Trang nguồn trả mã %d.', $code));
    }
    return (string) wp_remote_retrieve_body($response);
}

/** @return array|WP_Error JSON đã giải mã. */
function hithean_research_fetch_json(string $url)
{
    $body = hithean_research_fetch($url, ['Accept' => 'application/json']);
    if (is_wp_error($body)) {
        return $body;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : new WP_Error('hithean_research_json', 'Phản hồi không phải JSON.');
}

/**
 * Nhập một link: tạo dòng (trùng → trả dòng cũ) rồi bóc tách khi dòng mới / đang chờ nội dung.
 *
 * @return array{id:int,created:bool,status:string}|WP_Error
 */
function hithean_research_ingest(string $url, int $user_id)
{
    $identity = hithean_research_identity($url);
    if (!$identity) {
        return new WP_Error('hithean_research_url', 'Link không hợp lệ (cần bắt đầu bằng http:// hoặc https://).');
    }
    $row = hithean_research_upsert_identity(hash('sha256', $identity['key']), $identity, $user_id);
    if (is_wp_error($row)) {
        return $row;
    }
    $current = hithean_research_get($row['id']);
    $status  = (string) ($current['status'] ?? 'inbox');
    if ($row['created'] || $status === 'inbox') {
        $result = hithean_research_extract($row['id'], $identity);
        $status = is_wp_error($result) ? 'needs_input' : $result;
    }
    return ['id' => $row['id'], 'created' => $row['created'], 'status' => $status];
}

/**
 * Bóc tách metadata + nội dung cho một dòng, ghi lại, chuyển trạng thái (draft khi đủ nội dung, ngược lại needs_input).
 *
 * @return string|WP_Error trạng thái mới
 */
function hithean_research_extract(int $id, ?array $identity = null)
{
    $row = hithean_research_get($id);
    if (!$row) {
        return new WP_Error('hithean_research_missing', 'Không tìm thấy tài liệu.');
    }
    $identity = $identity ?: hithean_research_identity((string) $row['url']);
    if (!$identity) {
        return new WP_Error('hithean_research_url', 'Link đã lưu không hợp lệ.');
    }

    $notes = [];
    $meta  = [];
    $text  = '';
    $evidence = '';
    $pmid  = '';

    if ($identity['scholar_unresolved']) {
        $notes[] = 'Link tìm kiếm Google Scholar — dán link DOI hoặc trang xuất bản của bài.';
    } elseif ($identity['source_type'] === 'video') {
        $oembed = hithean_research_fetch_json('https://www.youtube.com/oembed?format=json&url=' . rawurlencode($identity['url']));
        $parts  = [is_wp_error($oembed) ? [] : hithean_research_parse_oembed($oembed)];
        if (is_wp_error($oembed)) {
            $notes[] = 'Không đọc được thông tin video: ' . $oembed->get_error_message();
        }
        $key = defined('YOUTUBE_API_KEY') ? (string) YOUTUBE_API_KEY : '';
        if ($key !== '') {
            $api = hithean_research_fetch_json('https://www.googleapis.com/youtube/v3/videos?part=snippet&id=' . rawurlencode($identity['video_id']) . '&key=' . rawurlencode($key));
            if (!is_wp_error($api)) {
                $parts[] = hithean_research_parse_youtube_api($api);
            }
        }
        $meta = hithean_research_merge(...$parts);
        $meta['image'] = $meta['image'] ?? 'https://i.ytimg.com/vi/' . $identity['video_id'] . '/hqdefault.jpg';
        $notes[] = 'Video: dán transcript (phụ đề) để AI tóm tắt.';
        $evidence = 'expert_media';
    } else {
        $parts = [];
        $html  = hithean_research_fetch($identity['url']);
        $page  = [];
        if (is_wp_error($html)) {
            $notes[] = 'Không mở được trang nguồn: ' . $html->get_error_message();
        } else {
            $page = hithean_research_parse_html_meta($html);
            $text = hithean_research_extract_article_text($html);
        }
        $doi = $identity['doi'] ?: (string) ($page['doi'] ?? '');
        if ($doi !== '') {
            $crossref = hithean_research_fetch_json('https://api.crossref.org/works/' . rawurlencode($doi));
            if (!is_wp_error($crossref)) {
                $parts[] = hithean_research_parse_crossref($crossref);
            }
            $epmc = hithean_research_fetch_json('https://www.ebi.ac.uk/europepmc/webservices/rest/search?format=json&resultType=core&pageSize=1&query=' . rawurlencode('DOI:"' . $doi . '"'));
            if (!is_wp_error($epmc)) {
                $e = hithean_research_parse_europepmc($epmc);
                $parts[] = ['abstract' => $e['abstract']];
                $pmid = $e['pmid'];
                $evidence = hithean_research_guess_evidence($e['pub_type'], '');
            }
        }
        $parts[] = $page;
        $meta = hithean_research_merge(...$parts);
        $meta['doi'] = $doi;
        if ($evidence === '') {
            $evidence = hithean_research_guess_evidence('', (string) ($meta['title'] ?? ''));
        }
        // Bài tạp chí: abstract là phần đáng tin nhất (thân trang thường là menu / trích dẫn).
        if ($doi !== '' || ($page['kind'] ?? '') === 'journal') {
            $identity['source_type'] = 'journal';
            $text = (string) ($meta['abstract'] ?? '');
        } elseif (hithean_research_strlen($text) < HITHEAN_RESEARCH_MIN_TEXT) {
            $text = trim($text . "\n" . ($meta['abstract'] ?? ''));
        }
    }

    // Không ghi đè nội dung nhân sự đã dán.
    $keep_text = hithean_research_strlen((string) $row['source_text']) >= HITHEAN_RESEARCH_MIN_TEXT;
    if ($identity['source_type'] === 'video' && !$keep_text) {
        $text = (string) ($meta['abstract'] ?? '');
        if (hithean_research_strlen($text) < 1500) {
            $text = ''; // mô tả video ngắn ≠ nội dung; chờ transcript
        }
    }
    $source_text = $keep_text ? (string) $row['source_text'] : hithean_research_substr($text, HITHEAN_RESEARCH_MAX_TEXT);
    $enough = hithean_research_strlen($source_text) >= HITHEAN_RESEARCH_MIN_TEXT || (int) $row['source_file_id'] > 0;
    if (!$enough && !$identity['scholar_unresolved'] && $identity['source_type'] !== 'video') {
        $notes[] = 'Chưa lấy được nội dung (abstract / thân bài) — dán nội dung hoặc tải PDF.';
    }

    $fields = [
        'source_type'  => $identity['source_type'],
        'canonical_url' => (string) ($meta['url'] ?? ''),
        'doi'          => (string) ($meta['doi'] ?? $identity['doi']),
        'pmid'         => $pmid,
        'title'        => (string) ($row['title'] ?: ($meta['title'] ?? '')),
        'authors'      => (string) ($row['authors'] ?: ($meta['authors'] ?? '')),
        'publisher'    => (string) ($row['publisher'] ?: ($meta['publisher'] ?? '')),
        'published_on' => (string) ($row['published_on'] ?: ($meta['published_on'] ?? '')),
        'evidence'     => (string) ($row['evidence'] ?: $evidence),
        'source_text'  => $source_text,
        'source_note'  => $enough ? '' : implode(' ', array_unique($notes)),
        'status'       => in_array($row['status'], ['inbox', 'needs_input'], true) ? ($enough ? 'draft' : 'needs_input') : $row['status'],
    ];
    if ($fields['title'] === '') {
        $fields['title'] = $identity['url'];
    }
    if (empty($row['thumb_id']) && !empty($meta['image'])) {
        $fields['thumb_id'] = hithean_research_sideload_thumb((string) $meta['image'], $identity['url'], $id);
    }

    $saved = hithean_research_save($id, $fields);
    return is_wp_error($saved) ? $saved : $fields['status'];
}

/** Tải ảnh đại diện về Media (chỉ host cho phép). 0 khi không tải được. */
function hithean_research_sideload_thumb(string $image, string $source_url, int $research_id): int
{
    if (!hithean_research_image_allowed($image, $source_url)) {
        return 0;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment = media_sideload_image($image, 0, 'Hồ sơ khoa học #' . $research_id, 'id');
    if (is_wp_error($attachment)) {
        error_log('[hithean_research] Không tải được ảnh #' . $research_id . ': ' . $attachment->get_error_message());
        return 0;
    }
    return (int) $attachment;
}

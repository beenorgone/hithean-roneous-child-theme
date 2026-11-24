<?php
/**
 * Hồ sơ khoa học — lõi dữ liệu + dựng thẻ (dùng chung trang khách, bảng quản trị, metabox).
 *
 * Phương án: plans/science-research-library.md.
 * - Bảng {prefix}hithean_research: một dòng / một nguồn (bài tạp chí, video, bài báo). url_hash UNIQUE chống trùng.
 * - Bảng {prefix}hithean_research_links: gắn nguồn vào SP / danh mục / trang (include · exclude · pin).
 * - Trang khách chỉ đọc cột summary_public của dòng status = published; admin sửa summary_draft.
 * - hithean_research_resolve() (hàm thuần) là nơi DUY NHẤT quyết định trang nào hiện tài liệu nào.
 * - hithean_research_card_html() là nơi DUY NHẤT dựng thẻ (popup, slider, xem trước admin).
 * Múi giờ: mọi cột datetime lưu giờ local (current_time('mysql')).
 */

defined('ABSPATH') || exit;

/** 1: bảng nguồn + bảng gắn trang (24/11/2026). */
const HITHEAN_RESEARCH_DB_VERSION = '1';
const HITHEAN_RESEARCH_DB_OPTION  = 'hithean_research_db_version';
const HITHEAN_RESEARCH_MAX_ITEMS  = 12;
const HITHEAN_RESEARCH_CACHE      = 'hithean_research';

function hithean_research_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'hithean_research';
}

function hithean_research_links_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'hithean_research_links';
}

/** Tạo / nâng cấp bảng (gọi ở admin_init và trước mọi lần ghi). */
function hithean_research_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (get_option(HITHEAN_RESEARCH_DB_OPTION) === HITHEAN_RESEARCH_DB_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $main    = hithean_research_table();
    $links   = hithean_research_links_table();

    dbDelta("CREATE TABLE {$main} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url_hash char(64) NOT NULL,
        source_type varchar(16) NOT NULL DEFAULT 'other',
        url text NOT NULL,
        canonical_url text NULL,
        doi varchar(190) NOT NULL DEFAULT '',
        pmid varchar(20) NOT NULL DEFAULT '',
        video_id varchar(20) NOT NULL DEFAULT '',
        title text NULL,
        authors text NULL,
        publisher varchar(255) NOT NULL DEFAULT '',
        published_on date NULL DEFAULT NULL,
        thumb_id bigint(20) unsigned NOT NULL DEFAULT 0,
        evidence varchar(20) NOT NULL DEFAULT '',
        topic varchar(190) NOT NULL DEFAULT '',
        topic_en varchar(190) NOT NULL DEFAULT '',
        source_text longtext NULL,
        source_file_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_note varchar(255) NOT NULL DEFAULT '',
        summary_draft longtext NULL,
        summary_public longtext NULL,
        status varchar(16) NOT NULL DEFAULT 'inbox',
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        published_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY status_updated (status, updated_at),
        KEY doi (doi)
    ) {$charset};");

    dbDelta("CREATE TABLE {$links} (
        research_id bigint(20) unsigned NOT NULL,
        target_type varchar(16) NOT NULL,
        target_id bigint(20) unsigned NOT NULL,
        mode varchar(8) NOT NULL DEFAULT 'include',
        sort int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (research_id, target_type, target_id),
        KEY target (target_type, target_id, mode)
    ) {$charset};");

    if ($wpdb->last_error !== '') {
        error_log('[hithean_research] dbDelta lỗi: ' . $wpdb->last_error);
        return;
    }
    update_option(HITHEAN_RESEARCH_DB_OPTION, HITHEAN_RESEARCH_DB_VERSION, false);
}

/* ---------- Danh mục (một nguồn, admin JS nhận qua config) ---------- */

/** Cột Kanban: key => [nhãn, gợi ý việc tiếp theo]. */
function hithean_research_statuses(): array
{
    return [
        'inbox'       => ['label' => '📥 Mới dán', 'hint' => 'Đang bóc tách tự động'],
        'needs_input' => ['label' => '✋ Cần bổ sung', 'hint' => 'Thiếu nội dung gốc — AI chưa chạy'],
        'draft'       => ['label' => '✨ Bản nháp AI', 'hint' => 'Tạo / sửa tóm tắt VI-EN, gắn trang'],
        'review'      => ['label' => '👀 Chờ duyệt', 'hint' => 'Quản lý xem trước rồi đăng'],
        'published'   => ['label' => '✅ Đã đăng', 'hint' => 'Đang hiện cho khách'],
        'archived'    => ['label' => '🗄 Lưu trữ', 'hint' => 'Đã gỡ, có thể khôi phục'],
    ];
}

function hithean_research_types(): array
{
    return [
        'journal' => ['vi' => 'Tạp chí', 'en' => 'Journal'],
        'video'   => ['vi' => 'Video', 'en' => 'Video'],
        'news'    => ['vi' => 'Báo', 'en' => 'News'],
        'other'   => ['vi' => 'Tài liệu', 'en' => 'Document'],
    ];
}

/** Mức bằng chứng: rank nhỏ = mạnh hơn (dùng để sắp). */
function hithean_research_evidence_levels(): array
{
    return [
        'meta_analysis' => ['vi' => 'Tổng hợp nhiều nghiên cứu', 'en' => 'Meta-analysis', 'tone' => 'strong', 'rank' => 1],
        'rct'           => ['vi' => 'Thử nghiệm ngẫu nhiên', 'en' => 'Randomised trial', 'tone' => 'strong', 'rank' => 2],
        'review'        => ['vi' => 'Bài tổng quan', 'en' => 'Review', 'tone' => 'mid', 'rank' => 3],
        'observational' => ['vi' => 'Nghiên cứu quan sát', 'en' => 'Observational study', 'tone' => 'mid', 'rank' => 4],
        'animal_lab'    => ['vi' => 'Thí nghiệm động vật / phòng lab', 'en' => 'Animal / lab study', 'tone' => 'low', 'rank' => 5],
        'expert_media'  => ['vi' => 'Chuyên gia / báo chí', 'en' => 'Expert / media', 'tone' => 'low', 'rank' => 6],
    ];
}

/** Disclaimer duy nhất (nguyên văn chủ sản phẩm chốt 24/11/2026). */
function hithean_research_disclaimer(string $lang, string $topic): string
{
    $topic = trim($topic);
    if ($lang === 'en') {
        return 'This study summary was compiled and automatically translated by AI to give readers a valuable reference on '
            . ($topic !== '' ? $topic : 'health and nutrition')
            . '. We did not author and are not responsible for the information presented in this AI-generated content.';
    }
    return 'Nghiên cứu được AI tổng hợp và chuyển ngữ tự động phần tóm tắt nhằm giúp người đọc có kênh tham chiếu có giá trị về '
        . ($topic !== '' ? $topic : 'sức khỏe và dinh dưỡng')
        . '. Chúng tôi không soạn thảo và không chịu trách nhiệm cho các thông tin được thể hiện trong nội dung do AI tạo ra.';
}

/**
 * Văn bản thuần do người dùng nhập: bỏ thẻ HTML thật (bắt đầu bằng chữ cái), GIỮ ký hiệu khoa học như "p<0.05", "n>100"
 * (sanitize_textarea_field biến "<" thành "&lt;"). Hiển thị luôn qua esc_html.
 */
function hithean_research_plain_text($value, int $max): string
{
    $value = is_scalar($value) ? (string) $value : '';
    if (function_exists('wp_check_invalid_utf8')) {
        $value = wp_check_invalid_utf8($value, true);
    }
    $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~isu', '', $value) ?? '';
    $value = preg_replace('~</?[a-z][a-z0-9-]*(?:\s[^<>]*)?/?>~iu', '', $value) ?? '';
    $value = preg_replace('/\r\n|\r/', "\n", $value) ?? '';
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

/* ---------- Tóm tắt (hàm thuần) ---------- */

/**
 * Chuẩn hoá tóm tắt VI/EN về một cấu trúc: {vi:{headline,summary,points[],limits}, en:{…}, …meta}.
 * Nhận mảng hoặc chuỗi JSON; trường lạ bị bỏ, chuỗi bị cắt độ dài.
 */
function hithean_research_normalize_summary($raw): array
{
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : json_decode($raw, true);
    }
    $raw = is_array($raw) ? $raw : [];
    $clean = static function ($value, int $max): string {
        $value = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    };
    $out = [];
    foreach (['vi', 'en'] as $lang) {
        $part   = is_array($raw[$lang] ?? null) ? $raw[$lang] : [];
        $points = [];
        foreach ((array) ($part['points'] ?? []) as $point) {
            $point = $clean($point, 300);
            if ($point !== '' && count($points) < 6) {
                $points[] = $point;
            }
        }
        $out[$lang] = [
            'headline' => $clean($part['headline'] ?? '', 200),
            'summary'  => $clean($part['summary'] ?? '', 1500),
            'points'   => $points,
            'limits'   => $clean($part['limits'] ?? '', 500),
        ];
    }
    foreach (['model', 'source_hash', 'at'] as $key) {
        if (isset($raw[$key]) && is_scalar($raw[$key])) {
            $out[$key] = (string) $raw[$key];
        }
    }
    return $out;
}

/** Đủ để đăng: cả VI và EN có tiêu đề + đoạn tóm tắt. */
function hithean_research_summary_complete(array $summary): bool
{
    foreach (['vi', 'en'] as $lang) {
        if (($summary[$lang]['headline'] ?? '') === '' || ($summary[$lang]['summary'] ?? '') === '') {
            return false;
        }
    }
    return true;
}

/* ---------- Chọn tài liệu cho một trang (hàm thuần) ---------- */

/**
 * Quyết định danh sách tài liệu hiện ở một trang.
 *
 * @param array $links   dòng links: [research_id, target_type, target_id, mode, sort]
 * @param array $items   tài liệu ĐÃ ĐĂNG: id => ['evidence' => …, 'published_on' => 'Y-m-d'|'' ]
 * @param array $context ['own' => [type, id], 'inherit' => [[type, id], …]]
 *                       own: pin (đứng đầu) + include − exclude; inherit: chỉ include (vd danh mục của SP).
 * @return int[] id theo thứ tự hiển thị, tối đa $limit.
 */
function hithean_research_resolve(array $links, array $items, array $context, int $limit = HITHEAN_RESEARCH_MAX_ITEMS): array
{
    $own = isset($context['own'][0], $context['own'][1]) ? $context['own'][0] . ':' . (int) $context['own'][1] : '';
    $inherit = [];
    foreach ((array) ($context['inherit'] ?? []) as $target) {
        if (isset($target[0], $target[1])) {
            $inherit[$target[0] . ':' . (int) $target[1]] = true;
        }
    }

    $pins = $include = $exclude = [];
    foreach ($links as $link) {
        $id   = (int) ($link['research_id'] ?? 0);
        $key  = ($link['target_type'] ?? '') . ':' . (int) ($link['target_id'] ?? 0);
        $mode = (string) ($link['mode'] ?? 'include');
        if ($id <= 0 || !isset($items[$id])) {
            continue;
        }
        if ($key === $own) {
            if ($mode === 'pin') {
                $pins[$id] = (int) ($link['sort'] ?? 0);
            } elseif ($mode === 'exclude') {
                $exclude[$id] = true;
            } else {
                $include[$id] = true;
            }
        } elseif (isset($inherit[$key]) && $mode === 'include') {
            $include[$id] = true;
        }
    }

    asort($pins);
    $pinned = array_keys($pins);
    $rest   = array_values(array_diff(array_keys($include), $pinned, array_keys($exclude)));
    $levels = hithean_research_evidence_levels();
    usort($rest, static function (int $a, int $b) use ($items, $levels): int {
        $ra = $levels[$items[$a]['evidence'] ?? '']['rank'] ?? 9;
        $rb = $levels[$items[$b]['evidence'] ?? '']['rank'] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $da = (string) ($items[$a]['published_on'] ?? '');
        $db = (string) ($items[$b]['published_on'] ?? '');
        return $da !== $db ? strcmp($db, $da) : $b <=> $a;
    });

    return array_slice(array_merge($pinned, $rest), 0, max(0, $limit));
}

/* ---------- Đọc / ghi ---------- */

/** Bỏ cache đọc của mọi trang (gọi sau mọi lần ghi). */
function hithean_research_bump_cache(): void
{
    wp_cache_set_last_changed(HITHEAN_RESEARCH_CACHE);
}

function hithean_research_get(int $id): ?array
{
    global $wpdb;
    if ($id <= 0) {
        return null;
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . hithean_research_table() . ' WHERE id = %d', $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** @return array<int,array> id => dòng, chỉ cột cần để hiển thị. */
function hithean_research_get_many(array $ids, bool $published_only = true): array
{
    global $wpdb;
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '%d'));
    $sql  = 'SELECT id, source_type, url, canonical_url, doi, video_id, title, authors, publisher, published_on, thumb_id, evidence, topic, topic_en, summary_public, status FROM '
        . hithean_research_table() . " WHERE id IN ({$in})" . ($published_only ? " AND status = 'published'" : '') . ' LIMIT 200';
    $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A) ?: [];
    $out  = [];
    foreach ($rows as $row) {
        $out[(int) $row['id']] = $row;
    }
    return $out;
}

/** Cột được phép ghi qua hithean_research_save(). */
function hithean_research_writable_columns(): array
{
    return ['url', 'canonical_url', 'source_type', 'doi', 'pmid', 'video_id', 'title', 'authors', 'publisher', 'published_on',
        'thumb_id', 'evidence', 'topic', 'topic_en', 'source_text', 'source_file_id', 'source_note', 'summary_draft',
        'summary_public', 'status', 'reviewed_by', 'published_at'];
}

/**
 * Ghi các cột của một tài liệu — mọi đường ghi (bóc tách, sửa tay, AI, đổi trạng thái) đi qua đây.
 *
 * @return true|WP_Error
 */
function hithean_research_save(int $id, array $fields)
{
    global $wpdb;
    hithean_research_install();
    $data = array_intersect_key($fields, array_flip(hithean_research_writable_columns()));
    if (isset($data['status']) && !isset(hithean_research_statuses()[$data['status']])) {
        return new WP_Error('hithean_research_status', 'Trạng thái không hợp lệ.');
    }
    if (isset($data['source_type']) && !isset(hithean_research_types()[$data['source_type']])) {
        $data['source_type'] = 'other';
    }
    if (isset($data['evidence']) && $data['evidence'] !== '' && !isset(hithean_research_evidence_levels()[$data['evidence']])) {
        $data['evidence'] = '';
    }
    foreach (['summary_draft', 'summary_public'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $data[$key] = wp_json_encode($data[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    if (array_key_exists('published_on', $data) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['published_on'])) {
        $data['published_on'] = null;
    }
    if (isset($data['source_note'])) {
        $data['source_note'] = function_exists('mb_substr') ? mb_substr((string) $data['source_note'], 0, 250) : substr((string) $data['source_note'], 0, 250);
    }
    if (!$data) {
        return true;
    }
    $data['updated_at'] = current_time('mysql');
    $ok = $wpdb->update(hithean_research_table(), $data, ['id' => $id]);
    if ($ok === false) {
        error_log('[hithean_research] Ghi ' . hithean_research_table() . ' #' . $id . ' lỗi: ' . $wpdb->last_error);
        return new WP_Error('hithean_research_db', 'Không lưu được tài liệu (lỗi cơ sở dữ liệu).');
    }
    hithean_research_bump_cache();
    return true;
}

/**
 * Tạo dòng theo url_hash, trùng thì trả dòng cũ (INSERT … ON DUPLICATE KEY UPDATE, an toàn khi dán song song).
 *
 * @return array{id:int,created:bool}|WP_Error
 */
function hithean_research_upsert_identity(string $url_hash, array $fields, int $user_id)
{
    global $wpdb;
    hithean_research_install();
    $now   = current_time('mysql');
    $table = hithean_research_table();
    $sql   = $wpdb->prepare(
        "INSERT INTO {$table} (url_hash, source_type, url, doi, video_id, status, created_by, created_at, updated_at)
         VALUES (%s, %s, %s, %s, %s, 'inbox', %d, %s, %s)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
        $url_hash,
        (string) ($fields['source_type'] ?? 'other'),
        (string) ($fields['url'] ?? ''),
        (string) ($fields['doi'] ?? ''),
        (string) ($fields['video_id'] ?? ''),
        $user_id,
        $now,
        $now
    );
    if ($wpdb->query($sql) === false) {
        error_log('[hithean_research] Upsert ' . $table . ' lỗi: ' . $wpdb->last_error);
        return new WP_Error('hithean_research_db', 'Không tạo được tài liệu (lỗi cơ sở dữ liệu).');
    }
    hithean_research_bump_cache();
    // rows_affected: 1 = dòng mới, 0/2 = đã có.
    return ['id' => (int) $wpdb->insert_id, 'created' => (int) $wpdb->rows_affected === 1];
}

/** Xoá hẳn (chỉ tài liệu đã lưu trữ). */
function hithean_research_delete(int $id): bool
{
    global $wpdb;
    $links = hithean_research_links_of($id);
    $ok = $wpdb->delete(hithean_research_table(), ['id' => $id, 'status' => 'archived']);
    if ($ok) {
        $wpdb->delete(hithean_research_links_table(), ['research_id' => $id]);
        hithean_research_bump_cache();
        hithean_research_purge($links);
    }
    return (bool) $ok;
}

function hithean_research_link_types(): array
{
    return ['product', 'product_cat', 'page'];
}

/** @return array<int,array> các dòng gắn của một tài liệu. */
function hithean_research_links_of(int $research_id): array
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT research_id, target_type, target_id, mode, sort FROM ' . hithean_research_links_table() . ' WHERE research_id = %d LIMIT 1000',
        $research_id
    ), ARRAY_A) ?: [];
}

/**
 * Đặt danh sách trang "include" của một tài liệu (bảng quản trị). Giữ nguyên pin / exclude đã đặt ở từng SP.
 *
 * @param array $targets [[type, id], …]
 */
function hithean_research_replace_includes(int $research_id, array $targets): void
{
    global $wpdb;
    hithean_research_install();
    $table  = hithean_research_links_table();
    $before = hithean_research_links_of($research_id);
    $want   = [];
    foreach ($targets as $target) {
        $type = (string) ($target[0] ?? '');
        $tid  = (int) ($target[1] ?? 0);
        if ($tid > 0 && in_array($type, hithean_research_link_types(), true)) {
            $want[$type . ':' . $tid] = [$type, $tid];
        }
    }
    foreach ($before as $link) {
        $key = $link['target_type'] . ':' . $link['target_id'];
        if ($link['mode'] === 'include' && !isset($want[$key])) {
            $wpdb->delete($table, ['research_id' => $research_id, 'target_type' => $link['target_type'], 'target_id' => (int) $link['target_id']]);
        }
        unset($want[$key]); // đã có (include / pin / exclude) → không ghi đè chế độ của SP
    }
    foreach ($want as [$type, $tid]) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (research_id, target_type, target_id, mode, sort) VALUES (%d, %s, %d, 'include', 0)
             ON DUPLICATE KEY UPDATE mode = mode",
            $research_id, $type, $tid
        ));
    }
    if ($wpdb->last_error !== '') {
        error_log('[hithean_research] Ghi ' . $table . ' lỗi: ' . $wpdb->last_error);
    }
    hithean_research_bump_cache();
    hithean_research_purge(array_merge($before, hithean_research_links_of($research_id)));
}

/**
 * Đặt chế độ của một tài liệu tại một trang: include | pin | exclude | '' (gỡ).
 */
function hithean_research_set_link(int $research_id, string $type, int $target_id, string $mode): void
{
    global $wpdb;
    hithean_research_install();
    $table = hithean_research_links_table();
    if (!in_array($type, hithean_research_link_types(), true) || $target_id <= 0 || $research_id <= 0) {
        return;
    }
    if ($mode === '') {
        $wpdb->delete($table, ['research_id' => $research_id, 'target_type' => $type, 'target_id' => $target_id]);
    } elseif (in_array($mode, ['include', 'pin', 'exclude'], true)) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (research_id, target_type, target_id, mode, sort) VALUES (%d, %s, %d, %s, 0)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode)",
            $research_id, $type, $target_id, $mode
        ));
    }
    if ($wpdb->last_error !== '') {
        error_log('[hithean_research] Ghi ' . $table . ' lỗi: ' . $wpdb->last_error);
    }
    hithean_research_bump_cache();
    hithean_research_purge([['target_type' => $type, 'target_id' => $target_id]]);
}

/* ---------- Ngữ cảnh trang ---------- */

/** Ngữ cảnh của SP: chính SP + mọi danh mục (kể cả danh mục cha). */
function hithean_research_context_for_product(int $product_id): array
{
    $cats = [];
    $terms = get_the_terms($product_id, 'product_cat');
    foreach (is_array($terms) ? $terms : [] as $term) {
        $cats[(int) $term->term_id] = true;
        foreach (get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy') as $parent) {
            $cats[(int) $parent] = true;
        }
    }
    return [
        'own'     => ['product', $product_id],
        'inherit' => array_map(static fn($id) => ['product_cat', $id], array_keys($cats)),
    ];
}

/** Ngữ cảnh danh mục: chính danh mục + danh mục con trực tiếp. */
function hithean_research_context_for_term(int $term_id): array
{
    $children = get_terms(['taxonomy' => 'product_cat', 'parent' => $term_id, 'fields' => 'ids', 'hide_empty' => false, 'number' => 50]);
    return [
        'own'     => ['product_cat', $term_id],
        'inherit' => array_map(static fn($id) => ['product_cat', (int) $id], is_array($children) ? $children : []),
    ];
}

function hithean_research_context_for_page(int $page_id): array
{
    return ['own' => ['page', $page_id], 'inherit' => []];
}

/**
 * Tài liệu đã đăng của một ngữ cảnh, theo thứ tự hiển thị — 2 truy vấn, cache theo last_changed.
 *
 * @return array<int,array> danh sách dòng (đã có 'summary' giải mã).
 */
function hithean_research_for_context(array $context): array
{
    global $wpdb;
    $targets = array_merge([$context['own'] ?? []], (array) ($context['inherit'] ?? []));
    $where = [];
    $args  = [];
    foreach ($targets as $target) {
        if (isset($target[0], $target[1]) && (int) $target[1] > 0) {
            $where[] = '(target_type = %s AND target_id = %d)';
            $args[]  = (string) $target[0];
            $args[]  = (int) $target[1];
        }
    }
    if (!$where) {
        return [];
    }
    if (get_option(HITHEAN_RESEARCH_DB_OPTION) !== HITHEAN_RESEARCH_DB_VERSION) {
        return []; // chưa cài bảng (admin chưa mở lần nào) → không có gì để hiện
    }

    $cache_key = 'ctx:' . md5(wp_json_encode($context)) . ':' . wp_cache_get_last_changed(HITHEAN_RESEARCH_CACHE);
    $cached = wp_cache_get($cache_key, HITHEAN_RESEARCH_CACHE);
    if (is_array($cached)) {
        return $cached;
    }

    $links = $wpdb->get_results($wpdb->prepare(
        'SELECT research_id, target_type, target_id, mode, sort FROM ' . hithean_research_links_table() . ' WHERE ' . implode(' OR ', $where) . ' LIMIT 2000',
        $args
    ), ARRAY_A) ?: [];
    $rows  = hithean_research_get_many(array_column($links, 'research_id'));
    $items = [];
    foreach ($rows as $id => $row) {
        $summary = hithean_research_normalize_summary((string) $row['summary_public']);
        if (hithean_research_summary_complete($summary)) {
            $row['summary'] = $summary;
            $items[$id] = $row;
        }
    }
    $out = [];
    foreach (hithean_research_resolve($links, $items, $context) as $id) {
        $out[] = $items[$id];
    }
    wp_cache_set($cache_key, $out, HITHEAN_RESEARCH_CACHE, HOUR_IN_SECONDS);
    return $out;
}

/* ---------- Xoá cache trang (WP Rocket) ---------- */

/**
 * Xoá cache các trang bị ảnh hưởng bởi những dòng gắn. Danh mục → cả trang danh mục và SP trong đó (≤ 200).
 * Không có WP Rocket thì bỏ qua (trang không cache).
 */
function hithean_research_purge(array $links): void
{
    $urls = [];
    foreach ($links as $link) {
        $type = (string) ($link['target_type'] ?? '');
        $id   = (int) ($link['target_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($type === 'product' || $type === 'page') {
            $urls[] = get_permalink($id);
        } elseif ($type === 'product_cat') {
            $term_link = get_term_link($id, 'product_cat');
            if (is_string($term_link)) {
                $urls[] = $term_link;
            }
            $products = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 200,
                'no_found_rows' => true, 'tax_query' => [['taxonomy' => 'product_cat', 'terms' => $id, 'include_children' => true]]]);
            foreach ($products as $product_id) {
                $urls[] = get_permalink($product_id);
            }
        }
    }
    $urls = array_values(array_unique(array_filter($urls, 'is_string')));
    if ($urls && function_exists('rocket_clean_files')) {
        rocket_clean_files($urls);
    }
    do_action('hithean_research_purged', $urls);
}

/* ---------- Dựng HTML ---------- */

function hithean_research_source_url(array $row): string
{
    if (!empty($row['doi'])) {
        return 'https://doi.org/' . $row['doi'];
    }
    return (string) ($row['canonical_url'] ?: $row['url']);
}

/** Dòng "Nguồn · năm". */
function hithean_research_meta_line(array $row): string
{
    $parts = array_filter([
        trim((string) $row['publisher']),
        !empty($row['published_on']) ? substr((string) $row['published_on'], 0, 4) : '',
    ]);
    return implode(' · ', $parts);
}

/** Khối tóm tắt một ngôn ngữ (kèm disclaimer). */
function hithean_research_summary_html(array $summary, string $lang, string $topic): string
{
    $part = $summary[$lang] ?? [];
    $html = '<h5>' . esc_html($part['headline'] ?? '') . '</h5><p>' . esc_html($part['summary'] ?? '') . '</p>';
    if (!empty($part['points'])) {
        $html .= '<ul>';
        foreach ($part['points'] as $point) {
            $html .= '<li>' . esc_html($point) . '</li>';
        }
        $html .= '</ul>';
    }
    if (!empty($part['limits'])) {
        $html .= '<p class="hr-card__limits"><strong>' . ($lang === 'en' ? 'Limitations: ' : 'Giới hạn: ') . '</strong>' . esc_html($part['limits']) . '</p>';
    }
    return $html . '<p class="hr-card__disc">' . esc_html(hithean_research_disclaimer($lang, $topic)) . '</p>';
}

/**
 * Thẻ tài liệu — một nơi dựng cho popup ($mode 'dialog'), slider ('slide') và xem trước admin.
 * Tóm tắt nằm trong <template> (in sẵn vào trang → WP Rocket cache được, không gọi AJAX).
 */
function hithean_research_card_html(array $row, string $mode = 'dialog'): string
{
    $types   = hithean_research_types();
    $levels  = hithean_research_evidence_levels();
    $type    = isset($types[$row['source_type']]) ? $row['source_type'] : 'other';
    $summary = $row['summary'] ?? hithean_research_normalize_summary((string) ($row['summary_public'] ?? ''));
    $id      = (int) $row['id'];
    $thumb   = !empty($row['thumb_id']) ? wp_get_attachment_image_url((int) $row['thumb_id'], 'medium_large') : '';
    $level   = $levels[$row['evidence']] ?? null;
    $topic   = trim((string) $row['topic']);
    $topicEn = trim((string) ($row['topic_en'] ?? '')) ?: $topic;

    $media = '<div class="hr-card__media hr-card__media--' . esc_attr($type) . '">';
    if ($thumb) {
        $media .= '<img src="' . esc_url($thumb) . '" alt="" loading="lazy" decoding="async">';
    } else {
        $media .= '<span class="hr-card__mediaicon" aria-hidden="true">' . ($type === 'video' ? '▶' : ($type === 'news' ? '📰' : '📄')) . '</span>';
    }
    if ($type === 'video' && !empty($row['video_id']) && $mode === 'dialog') {
        $media .= '<button type="button" class="hr-card__play" data-hr-video="' . esc_attr($row['video_id']) . '" aria-label="Phát video">▶</button>';
    }
    $media .= '</div>';

    $chips = '<span class="hr-chip">' . esc_html($types[$type]['vi']) . '</span>';
    if ($level) {
        $chips .= '<span class="hr-chip hr-chip--' . esc_attr($level['tone']) . '">' . esc_html($level['vi']) . '</span>';
    }

    $body = '<div class="hr-card__body"><div class="hr-card__chips">' . $chips . '</div>'
        . '<h4 class="hr-card__title">' . esc_html((string) $row['title']) . '</h4>';
    $meta = hithean_research_meta_line($row);
    if ($meta !== '') {
        $body .= '<p class="hr-card__meta">' . esc_html($meta) . '</p>';
    }
    if ($topic !== '') {
        $body .= '<p class="hr-card__topic">Về thành phần: <b>' . esc_html($topic) . '</b></p>';
    }

    if ($mode === 'slide') {
        $body .= '<span class="hr-card__cta">✨ Đọc tóm tắt AI ›</span></div>';
        return '<article class="hr-card hr-card--slide" data-hr-open="' . $id . '" tabindex="0" role="button" aria-label="'
            . esc_attr('Đọc tóm tắt: ' . (string) $row['title']) . '">' . $media . $body . '</article>';
    }

    $body .= '<div class="hr-card__actions">'
        . '<button type="button" class="hr-btn" data-hr-read aria-expanded="false">✨ Đọc tóm tắt AI</button>'
        . '<a class="hr-btn hr-btn--ghost" href="' . esc_url(hithean_research_source_url($row)) . '" target="_blank" rel="nofollow noopener noreferrer">Xem nguồn gốc ↗</a>'
        . '</div>'
        . '<div class="hr-card__ai" data-hr-ai hidden>'
        . '<div class="hr-card__aihead"><span>✨ Tóm tắt bởi AI</span><span class="hr-seg" role="group" aria-label="Ngôn ngữ">'
        . '<button type="button" data-hr-lang="vi" aria-pressed="true">VI</button><button type="button" data-hr-lang="en" aria-pressed="false">EN</button></span></div>'
        . '<div class="hr-card__summary notranslate" translate="no" data-hr-summary></div>'
        . '<template data-hr-tpl="vi">' . hithean_research_summary_html($summary, 'vi', $topic) . '</template>'
        . '<template data-hr-tpl="en">' . hithean_research_summary_html($summary, 'en', $topicEn) . '</template>'
        . '</div></div>';

    return '<article class="hr-card" data-hr-id="' . $id . '" data-hr-type="' . esc_attr($type) . '">' . $media . $body . '</article>';
}

/** Dòng phụ dưới tiêu đề nút: "4 tài liệu · Tạp chí · Video". */
function hithean_research_trigger_subtitle(array $rows): string
{
    $types = hithean_research_types();
    $seen  = [];
    foreach ($rows as $row) {
        $seen[$row['source_type']] = $types[$row['source_type']]['vi'] ?? $types['other']['vi'];
    }
    return implode(' · ', array_merge([count($rows) . ' tài liệu'], array_values($seen)));
}

/** Nút mở popup. */
function hithean_research_trigger_html(array $rows, string $dialog_id): string
{
    if (!$rows) {
        return '';
    }
    return '<button type="button" class="hithean-research-trigger" data-hr-trigger="' . esc_attr($dialog_id) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($dialog_id) . '">'
        . '<span class="hithean-research-trigger__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6"/><path d="M10 3v6L4.5 18.5A1.7 1.7 0 0 0 6 21h12a1.7 1.7 0 0 0 1.5-2.5L14 9V3"/><path d="M7.5 15h9"/></svg></span>'
        . '<span class="hithean-research-trigger__text"><strong>Hồ sơ khoa học liên quan</strong><small>' . esc_html(hithean_research_trigger_subtitle($rows)) . '</small></span>'
        . '<span class="hithean-research-trigger__count">' . count($rows) . '</span>'
        . '</button>';
}

/** Popup (native dialog) với bộ lọc theo loại. */
function hithean_research_dialog_html(array $rows, string $dialog_id, string $subtitle): string
{
    if (!$rows) {
        return '';
    }
    $types  = hithean_research_types();
    $counts = array_count_values(array_column($rows, 'source_type'));
    $filters = '<button type="button" data-hr-filter="all" aria-pressed="true">Tất cả (' . count($rows) . ')</button>';
    if (count($counts) > 1) {
        foreach ($counts as $type => $n) {
            $filters .= '<button type="button" data-hr-filter="' . esc_attr($type) . '" aria-pressed="false">' . esc_html(($types[$type]['vi'] ?? $type) . ' (' . $n . ')') . '</button>';
        }
    }
    $cards = '';
    foreach ($rows as $row) {
        $cards .= hithean_research_card_html($row, 'dialog');
    }
    return '<dialog class="hithean-research-dialog" id="' . esc_attr($dialog_id) . '" aria-labelledby="' . esc_attr($dialog_id) . '-title">'
        . '<div class="hithean-research-dialog__head"><div><h3 id="' . esc_attr($dialog_id) . '-title">Hồ sơ khoa học liên quan</h3>'
        . ($subtitle !== '' ? '<p>' . esc_html($subtitle) . '</p>' : '') . '</div>'
        . '<button type="button" class="hithean-research-dialog__close" data-hr-close aria-label="Đóng">×</button></div>'
        . (count($counts) > 1 ? '<div class="hithean-research-dialog__filters" role="group" aria-label="Lọc theo loại">' . $filters . '</div>' : '')
        . '<div class="hithean-research-dialog__list">' . $cards . '</div>'
        . '<p class="hithean-research-dialog__foot">Nguồn mở ở tab mới. Tóm tắt do AI tạo và được The An Organics duyệt trước khi đăng.</p>'
        . '</dialog>';
}

/** Section slider "Hồ sơ khoa học liên quan". */
function hithean_research_slider_html(array $rows, string $dialog_id): string
{
    if (!$rows) {
        return '';
    }
    $cards = '';
    foreach ($rows as $row) {
        $cards .= hithean_research_card_html($row, 'slide');
    }
    $heading = $dialog_id . '-slider-title';
    return '<section class="hithean-research-slider" data-hr-slider="' . esc_attr($dialog_id) . '" aria-labelledby="' . esc_attr($heading) . '">'
        . '<div class="hithean-research-slider__head"><div><h2 id="' . esc_attr($heading) . '">Hồ sơ khoa học liên quan</h2>'
        . '<p>Nghiên cứu về thành phần trong sản phẩm, có tóm tắt tiếng Việt / English.</p></div>'
        . (count($rows) > 1 ? '<div class="hithean-research-slider__nav"><button type="button" data-hr-step="-1" aria-label="Trước">‹</button><button type="button" data-hr-step="1" aria-label="Sau">›</button></div>' : '')
        . '</div><div class="hithean-research-slider__track" data-hr-track>' . $cards . '</div></section>';
}

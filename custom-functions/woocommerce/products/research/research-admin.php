<?php
/**
 * Hồ sơ khoa học — quản trị: Sản phẩm → Hồ sơ khoa học (bảng Kanban), metabox SP, danh sách ở màn sửa danh mục, AJAX.
 *
 * Nạp bởi module `research_admin` (admin => true + ajax_actions trong module-loader).
 * Quyền: edit_products dán link / soạn / gửi duyệt; manage_woocommerce duyệt & đăng, gỡ, xoá.
 * AJAX: kiểm nonce → quyền → hàm domain (research-core / -extract / -ai) → JSON. Payload sửa gửi base64 (Wordfence chặn POST có HTML).
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/research-core.php';
require_once __DIR__ . '/research-extract.php';
require_once __DIR__ . '/research-ai.php';

const HITHEAN_RESEARCH_PAGE  = 'hithean-research';
const HITHEAN_RESEARCH_NONCE = 'hithean_research';

function hithean_research_can_publish(): bool
{
    return current_user_can('manage_woocommerce');
}

function hithean_research_board_url(int $id = 0): string
{
    $url = admin_url('edit.php?post_type=product&page=' . HITHEAN_RESEARCH_PAGE);
    return $id > 0 ? $url . '#hr-' . $id : $url;
}

add_action('admin_init', 'hithean_research_install');

add_action('admin_menu', static function (): void {
    add_submenu_page('edit.php?post_type=product', 'Hồ sơ khoa học', 'Hồ sơ khoa học', 'edit_products', HITHEAN_RESEARCH_PAGE, 'hithean_research_render_board');
}, 40);

/* ---------- Tài nguyên ---------- */

function hithean_research_admin_config(): array
{
    return [
        'ajax'       => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce(HITHEAN_RESEARCH_NONCE),
        'statuses'   => hithean_research_statuses(),
        'types'      => hithean_research_types(),
        'evidence'   => hithean_research_evidence_levels(),
        'canPublish' => hithean_research_can_publish(),
        'minText'    => HITHEAN_RESEARCH_MIN_TEXT,
        'boardUrl'   => hithean_research_board_url(),
    ];
}

add_action('admin_enqueue_scripts', static function (string $hook): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $board  = isset($_GET['page']) && $_GET['page'] === HITHEAN_RESEARCH_PAGE; // phpcs:ignore WordPress.Security.NonceVerification
    $product = $screen && $screen->id === 'product';
    if (!$board && !$product) {
        return;
    }
    $dir  = get_stylesheet_directory();
    $uri  = get_stylesheet_directory_uri();
    $base = '/custom-functions/woocommerce/products/research/assets/';
    wp_enqueue_style('hithean-research', $uri . $base . 'research.css', [], (string) @filemtime($dir . $base . 'research.css'));
    wp_enqueue_style('hithean-research-admin', $uri . $base . 'research-admin.css', ['hithean-research'], (string) @filemtime($dir . $base . 'research-admin.css'));
    wp_enqueue_script('hithean-research', $uri . $base . 'research.js', [], (string) @filemtime($dir . $base . 'research.js'), true);
    wp_enqueue_script('hithean-research-admin', $uri . $base . 'research-admin.js', [], (string) @filemtime($dir . $base . 'research-admin.js'), true);
    wp_add_inline_script('hithean-research-admin', 'window.hitheanResearch = ' . wp_json_encode(hithean_research_admin_config()) . ';', 'before');
});

function hithean_research_render_board(): void
{
    if (!current_user_can('edit_products')) {
        wp_die('Bạn không có quyền xem trang này.');
    }
    ?>
    <div class="wrap hr-admin">
        <h1 class="hr-admin__title">Hồ sơ khoa học</h1>
        <p class="hr-admin__lead">Dán link → hệ thống bóc tách → AI soạn tóm tắt VI/EN → quản lý duyệt rồi đăng. Tài liệu đã đăng hiện ở nút
            <b>"Hồ sơ khoa học liên quan"</b> trên trang SP / danh mục / landing đã gắn.</p>
        <div class="hr-admin__paste">
            <label for="hr-paste" class="screen-reader-text">Dán link</label>
            <textarea id="hr-paste" rows="2" placeholder="Dán link YouTube / DOI / bài tạp chí / bài báo / Google Scholar — mỗi dòng 1 link (tối đa 20)"></textarea>
            <button type="button" class="hr-abtn hr-abtn--primary" id="hr-paste-go">Bóc tách</button>
            <span class="hr-admin__progress" id="hr-paste-status" aria-live="polite"></span>
        </div>
        <div class="hr-admin__toolbar">
            <label for="hr-search" class="screen-reader-text">Tìm</label>
            <input type="search" id="hr-search" placeholder="Tìm theo tiêu đề, nguồn, chủ đề…">
            <span class="hr-admin__count" id="hr-count"></span>
        </div>
        <div class="hr-board" id="hr-board" aria-live="polite"><p class="hr-admin__loading">Đang tải…</p></div>
        <aside class="hr-drawer" id="hr-drawer" hidden aria-labelledby="hr-drawer-title"></aside>
    </div>
    <?php
}

/* ---------- AJAX ---------- */

/** Kiểm nonce + quyền; lỗi → JSON 403. */
function hithean_research_ajax_guard(string $cap = 'edit_products'): void
{
    if (!check_ajax_referer(HITHEAN_RESEARCH_NONCE, 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên làm việc hết hạn — tải lại trang.'], 403);
    }
    if (!current_user_can($cap)) {
        wp_send_json_error(['message' => 'Bạn không có quyền thao tác này.'], 403);
    }
}

/** Payload JSON gửi dạng base64 trong trường `payload`. */
function hithean_research_ajax_payload(): array
{
    $raw = isset($_POST['payload']) ? base64_decode((string) wp_unslash($_POST['payload']), true) : ''; // phpcs:ignore WordPress.Security.NonceVerification
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function hithean_research_ajax_id(): int
{
    return isset($_POST['id']) ? absint($_POST['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
}

function hithean_research_send_wp_error(WP_Error $error): void
{
    wp_send_json_error(['message' => $error->get_error_message()], 400);
}

/** Thẻ gọn cho bảng Kanban. */
function hithean_research_board_card(array $row, array $link_counts): array
{
    $draft  = hithean_research_normalize_summary((string) $row['summary_draft']);
    $public = hithean_research_normalize_summary((string) $row['summary_public']);
    $has_draft = hithean_research_summary_complete($draft);
    $strip = static fn(array $s): array => array_intersect_key($s, ['vi' => 1, 'en' => 1]);
    return [
        'id'        => (int) $row['id'],
        'title'     => (string) $row['title'],
        'type'      => (string) $row['source_type'],
        'status'    => (string) $row['status'],
        'publisher' => (string) $row['publisher'],
        'year'      => $row['published_on'] ? substr((string) $row['published_on'], 0, 4) : '',
        'topic'     => (string) $row['topic'],
        'note'      => (string) $row['source_note'],
        'hasText'   => hithean_research_strlen((string) $row['source_text']) >= HITHEAN_RESEARCH_MIN_TEXT || (int) $row['source_file_id'] > 0,
        'hasDraft'  => $has_draft,
        'stale'     => $has_draft && ($draft['source_hash'] ?? '') !== '' && $draft['source_hash'] !== hithean_research_source_hash($row),
        'unpublishedEdits' => $row['status'] === 'published' && $has_draft && $strip($draft) !== $strip($public),
        'links'     => (int) ($link_counts[(int) $row['id']] ?? 0),
        'updated'   => (string) $row['updated_at'],
    ];
}

add_action('wp_ajax_hithean_research_list', static function (): void {
    hithean_research_ajax_guard();
    hithean_research_install();
    global $wpdb;
    $table = hithean_research_table();
    $rows  = $wpdb->get_results(
        "(SELECT id, title, source_type, status, publisher, published_on, topic, source_note, source_text, source_file_id, summary_draft, summary_public, updated_at
           FROM {$table} WHERE status <> 'archived' ORDER BY updated_at DESC LIMIT 500)
         UNION ALL
         (SELECT id, title, source_type, status, publisher, published_on, topic, source_note, source_text, source_file_id, summary_draft, summary_public, updated_at
           FROM {$table} WHERE status = 'archived' ORDER BY updated_at DESC LIMIT 50)",
        ARRAY_A
    ) ?: [];
    $counts = [];
    foreach ($wpdb->get_results('SELECT research_id, COUNT(*) n FROM ' . hithean_research_links_table() . " WHERE mode <> 'exclude' GROUP BY research_id LIMIT 2000", ARRAY_A) ?: [] as $c) {
        $counts[(int) $c['research_id']] = (int) $c['n'];
    }
    wp_send_json_success(['items' => array_map(static fn($r) => hithean_research_board_card($r, $counts), $rows)]);
});

/** Nhãn hiển thị của một trang gắn. */
function hithean_research_target_label(string $type, int $id): string
{
    if ($type === 'product_cat') {
        $term = get_term($id, 'product_cat');
        return $term instanceof WP_Term ? 'Danh mục: ' . $term->name . ' (' . (int) $term->count . ' SP)' : 'Danh mục #' . $id . ' (đã xoá)';
    }
    $title = get_the_title($id);
    $prefix = $type === 'product' ? 'SP: ' : 'Trang: ';
    return $prefix . ($title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : '#' . $id);
}

/** Chi tiết đầy đủ cho ngăn sửa. */
function hithean_research_detail(int $id): ?array
{
    $row = hithean_research_get($id);
    if (!$row) {
        return null;
    }
    $links = [];
    foreach (hithean_research_links_of($id) as $link) {
        $links[] = ['type' => $link['target_type'], 'id' => (int) $link['target_id'], 'mode' => $link['mode'],
            'label' => hithean_research_target_label($link['target_type'], (int) $link['target_id'])];
    }
    $draft   = hithean_research_normalize_summary((string) $row['summary_draft']);
    $preview = $row;
    $preview['summary'] = $draft;
    $file = (int) $row['source_file_id'];
    return [
        'id'          => (int) $row['id'],
        'url'         => (string) $row['url'],
        'sourceUrl'   => hithean_research_source_url($row),
        'type'        => (string) $row['source_type'],
        'status'      => (string) $row['status'],
        'title'       => (string) $row['title'],
        'authors'     => (string) $row['authors'],
        'publisher'   => (string) $row['publisher'],
        'publishedOn' => (string) $row['published_on'],
        'doi'         => (string) $row['doi'],
        'evidence'    => (string) $row['evidence'],
        'topic'       => (string) $row['topic'],
        'topicEn'     => (string) $row['topic_en'],
        'sourceText'  => (string) $row['source_text'],
        'file'        => $file > 0 ? ['id' => $file, 'name' => wp_basename((string) get_attached_file($file)), 'url' => (string) wp_get_attachment_url($file)] : null,
        'note'        => (string) $row['source_note'],
        'draft'       => array_intersect_key($draft, ['vi' => 1, 'en' => 1]),
        'draftMeta'   => ['model' => $draft['model'] ?? '', 'at' => $draft['at'] ?? '',
            'stale' => ($draft['source_hash'] ?? '') !== '' && $draft['source_hash'] !== hithean_research_source_hash($row)],
        'links'       => $links,
        'publishedAt' => (string) $row['published_at'],
        'previewHtml' => hithean_research_summary_complete($draft) ? hithean_research_card_html($preview, 'dialog') : '',
    ];
}

function hithean_research_send_detail(int $id, string $message = ''): void
{
    $detail = hithean_research_detail($id);
    if (!$detail) {
        wp_send_json_error(['message' => 'Không tìm thấy tài liệu.'], 404);
    }
    wp_send_json_success(['item' => $detail, 'message' => $message]);
}

add_action('wp_ajax_hithean_research_get', static function (): void {
    hithean_research_ajax_guard();
    hithean_research_send_detail(hithean_research_ajax_id());
});

add_action('wp_ajax_hithean_research_ingest', static function (): void {
    hithean_research_ajax_guard();
    $data = hithean_research_ajax_payload();
    $result = hithean_research_ingest((string) ($data['url'] ?? ''), get_current_user_id());
    if (is_wp_error($result)) {
        hithean_research_send_wp_error($result);
    }
    $product = (int) ($data['product'] ?? 0);
    if ($product > 0 && get_post_type($product) === 'product') {
        hithean_research_set_link($result['id'], 'product', $product, 'include');
    }
    wp_send_json_success($result + ['message' => $result['created'] ? 'Đã thêm.' : 'Link đã có trong bảng — mở thẻ cũ.']);
});

add_action('wp_ajax_hithean_research_extract', static function (): void {
    hithean_research_ajax_guard();
    $id = hithean_research_ajax_id();
    $result = hithean_research_extract($id);
    if (is_wp_error($result)) {
        hithean_research_send_wp_error($result);
    }
    hithean_research_send_detail($id, 'Đã bóc lại.');
});

add_action('wp_ajax_hithean_research_save', static function (): void {
    hithean_research_ajax_guard();
    $id  = hithean_research_ajax_id();
    $row = hithean_research_get($id);
    if (!$row) {
        wp_send_json_error(['message' => 'Không tìm thấy tài liệu.'], 404);
    }
    $data = hithean_research_ajax_payload();
    $text = static fn($v, int $max) => hithean_research_plain_text($v, $max);
    $fields = [
        'title'        => $text($data['title'] ?? $row['title'], 500),
        'authors'      => $text($data['authors'] ?? $row['authors'], 1000),
        'publisher'    => $text($data['publisher'] ?? $row['publisher'], 250),
        'published_on' => hithean_research_parse_date((string) ($data['publishedOn'] ?? '')),
        'source_type'  => sanitize_key((string) ($data['type'] ?? $row['source_type'])),
        'evidence'     => sanitize_key((string) ($data['evidence'] ?? '')),
        'topic'        => $text($data['topic'] ?? '', 180),
        'topic_en'     => $text($data['topicEn'] ?? '', 180),
        'doi'          => hithean_research_doi_from_string((string) ($data['doi'] ?? '')),
        'source_text'  => $text($data['sourceText'] ?? $row['source_text'], HITHEAN_RESEARCH_MAX_TEXT),
    ];
    if (isset($data['draft']) && is_array($data['draft'])) {
        $old = hithean_research_normalize_summary((string) $row['summary_draft']);
        $new = hithean_research_normalize_summary($data['draft']);
        $fields['summary_draft'] = $new + array_intersect_key($old, ['model' => 1, 'source_hash' => 1, 'at' => 1]);
    }
    // Đã dán đủ nội dung → thẻ rời cột "Cần bổ sung".
    if (in_array($row['status'], ['inbox', 'needs_input'], true) && hithean_research_strlen($fields['source_text']) >= HITHEAN_RESEARCH_MIN_TEXT) {
        $fields['status'] = 'draft';
        $fields['source_note'] = '';
    }
    $saved = hithean_research_save($id, $fields);
    if (is_wp_error($saved)) {
        hithean_research_send_wp_error($saved);
    }
    if (isset($data['links']) && is_array($data['links'])) {
        $targets = [];
        foreach ($data['links'] as $link) {
            $targets[] = [sanitize_key((string) ($link['type'] ?? '')), absint($link['id'] ?? 0)];
        }
        hithean_research_replace_includes($id, $targets);
    } elseif ($row['status'] === 'published') {
        hithean_research_purge(hithean_research_links_of($id)); // metadata hiển thị đổi
    }
    hithean_research_send_detail($id, 'Đã lưu.');
});

add_action('wp_ajax_hithean_research_ai', static function (): void {
    hithean_research_ajax_guard();
    $id = hithean_research_ajax_id();
    $result = hithean_research_ai_generate($id);
    if (is_wp_error($result)) {
        hithean_research_send_wp_error($result);
    }
    hithean_research_send_detail($id, 'AI đã soạn bản nháp — đọc lại, sửa rồi gửi duyệt.');
});

add_action('wp_ajax_hithean_research_status', static function (): void {
    hithean_research_ajax_guard();
    $id   = hithean_research_ajax_id();
    $row  = hithean_research_get($id);
    $to   = sanitize_key((string) ($_POST['to'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if (!$row || !isset(hithean_research_statuses()[$to])) {
        wp_send_json_error(['message' => 'Yêu cầu không hợp lệ.'], 400);
    }
    $draft = hithean_research_normalize_summary((string) $row['summary_draft']);
    $has_text = hithean_research_strlen((string) $row['source_text']) >= HITHEAN_RESEARCH_MIN_TEXT || (int) $row['source_file_id'] > 0;
    $fields = ['status' => $to];
    $message = 'Đã chuyển.';

    if ($to === 'published') {
        if (!hithean_research_can_publish()) {
            wp_send_json_error(['message' => 'Chỉ quản lý (manage_woocommerce) được duyệt & đăng.'], 403);
        }
        if (!hithean_research_summary_complete($draft)) {
            wp_send_json_error(['message' => 'Tóm tắt cần đủ tiêu đề + đoạn tóm tắt ở cả VI và EN.'], 400);
        }
        if (trim((string) $row['title']) === '') {
            wp_send_json_error(['message' => 'Thiếu tiêu đề tài liệu.'], 400);
        }
        $fields += ['summary_public' => $draft, 'published_at' => current_time('mysql'), 'reviewed_by' => get_current_user_id()];
        $message = hithean_research_links_of($id) ? 'Đã đăng — đang hiện ở các trang đã gắn.' : 'Đã đăng — chưa gắn trang nào nên khách chưa thấy.';
    } elseif ($to === 'review') {
        if (!hithean_research_summary_complete($draft)) {
            wp_send_json_error(['message' => 'Tạo / viết tóm tắt VI và EN trước khi gửi duyệt.'], 400);
        }
        $message = 'Đã gửi duyệt.';
    } elseif ($to === 'archived') {
        if ($row['status'] === 'published' && !hithean_research_can_publish()) {
            wp_send_json_error(['message' => 'Chỉ quản lý được gỡ tài liệu đang đăng.'], 403);
        }
        $message = 'Đã chuyển vào Lưu trữ — khách không còn thấy.';
    } elseif ($to === 'draft' || $to === 'needs_input') {
        $fields['status'] = $has_text ? 'draft' : 'needs_input';
        if ($row['status'] === 'published' && !hithean_research_can_publish()) {
            wp_send_json_error(['message' => 'Chỉ quản lý được gỡ tài liệu đang đăng.'], 403);
        }
    } else {
        wp_send_json_error(['message' => 'Không chuyển tay sang cột này.'], 400);
    }

    $saved = hithean_research_save($id, $fields);
    if (is_wp_error($saved)) {
        hithean_research_send_wp_error($saved);
    }
    if ($row['status'] === 'published' || $fields['status'] === 'published') {
        hithean_research_purge(hithean_research_links_of($id));
    }
    hithean_research_send_detail($id, $message);
});

add_action('wp_ajax_hithean_research_delete', static function (): void {
    hithean_research_ajax_guard('manage_woocommerce');
    if (!hithean_research_delete(hithean_research_ajax_id())) {
        wp_send_json_error(['message' => 'Chỉ xoá được tài liệu trong Lưu trữ.'], 400);
    }
    wp_send_json_success(['message' => 'Đã xoá.']);
});

add_action('wp_ajax_hithean_research_upload', static function (): void {
    hithean_research_ajax_guard();
    if (!current_user_can('upload_files')) {
        wp_send_json_error(['message' => 'Bạn không có quyền tải tệp.'], 403);
    }
    $id  = hithean_research_ajax_id();
    $row = hithean_research_get($id);
    $file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
    if (!$row || !is_array($file) || (int) ($file['size'] ?? 0) <= 0) {
        wp_send_json_error(['message' => 'Chưa chọn tệp.'], 400);
    }
    if ((int) $file['size'] > 15 * MB_IN_BYTES) {
        wp_send_json_error(['message' => 'PDF tối đa 15 MB.'], 400);
    }
    $check = wp_check_filetype_and_ext((string) $file['tmp_name'], (string) $file['name'], ['pdf' => 'application/pdf']);
    if (($check['type'] ?? '') !== 'application/pdf') {
        wp_send_json_error(['message' => 'Chỉ nhận tệp PDF.'], 400);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment = media_handle_upload('file', 0, [], ['test_form' => false, 'mimes' => ['pdf' => 'application/pdf']]);
    if (is_wp_error($attachment)) {
        hithean_research_send_wp_error($attachment);
    }
    $fields = ['source_file_id' => (int) $attachment];
    if (in_array($row['status'], ['inbox', 'needs_input'], true)) {
        $fields += ['status' => 'draft', 'source_note' => ''];
    }
    $saved = hithean_research_save($id, $fields);
    if (is_wp_error($saved)) {
        hithean_research_send_wp_error($saved);
    }
    hithean_research_send_detail($id, 'Đã tải PDF — AI sẽ đọc tệp này khi tạo tóm tắt.');
});

add_action('wp_ajax_hithean_research_targets', static function (): void {
    hithean_research_ajax_guard();
    $type = sanitize_key((string) ($_POST['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    $q    = sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification
    $out  = [];
    if ($type === 'product_cat') {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 20, 'search' => $q]);
        foreach (is_array($terms) ? $terms : [] as $term) {
            $out[] = ['type' => 'product_cat', 'id' => (int) $term->term_id, 'label' => hithean_research_target_label('product_cat', (int) $term->term_id)];
        }
    } elseif ($type === 'product' || $type === 'page') {
        $ids = get_posts(['post_type' => $type, 'post_status' => ['publish', 'private', 'draft'], 's' => $q, 'numberposts' => 20,
            'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'title', 'order' => 'ASC']);
        foreach ($ids as $pid) {
            $out[] = ['type' => $type, 'id' => (int) $pid, 'label' => hithean_research_target_label($type, (int) $pid)];
        }
    }
    wp_send_json_success(['items' => $out]);
});

/** Ghim / ẩn / gỡ ở một SP (metabox). */
add_action('wp_ajax_hithean_research_link', static function (): void {
    hithean_research_ajax_guard();
    $product = absint($_POST['product'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
    $mode    = sanitize_key((string) ($_POST['mode'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if ($product <= 0 || !current_user_can('edit_post', $product) || !in_array($mode, ['', 'include', 'pin', 'exclude'], true)) {
        wp_send_json_error(['message' => 'Yêu cầu không hợp lệ.'], 400);
    }
    hithean_research_set_link(hithean_research_ajax_id(), 'product', $product, $mode);
    wp_send_json_success(['html' => hithean_research_product_box_html($product)]);
});

/* ---------- Metabox SP ---------- */

/**
 * Các tài liệu liên quan tới SP (đã đăng hoặc chưa), kèm trạng thái ở SP: pin | include | exclude | inherit.
 *
 * @return array<int,array{row:array,mode:string,shown:bool}>
 */
function hithean_research_product_candidates(int $product_id): array
{
    global $wpdb;
    if (get_option(HITHEAN_RESEARCH_DB_OPTION) !== HITHEAN_RESEARCH_DB_VERSION) {
        return [];
    }
    $context = hithean_research_context_for_product($product_id);
    $where = ['(target_type = %s AND target_id = %d)'];
    $args  = ['product', $product_id];
    foreach ($context['inherit'] as [$type, $tid]) {
        $where[] = '(target_type = %s AND target_id = %d)';
        $args[]  = $type;
        $args[]  = $tid;
    }
    $links = $wpdb->get_results($wpdb->prepare(
        'SELECT research_id, target_type, target_id, mode, sort FROM ' . hithean_research_links_table() . ' WHERE ' . implode(' OR ', $where) . ' LIMIT 500',
        $args
    ), ARRAY_A) ?: [];
    $rows = hithean_research_get_many(array_column($links, 'research_id'), false);
    $shown = array_flip(array_column(hithean_research_for_context($context), 'id'));
    $out = [];
    foreach ($links as $link) {
        $id = (int) $link['research_id'];
        if (!isset($rows[$id])) {
            continue;
        }
        $own  = $link['target_type'] === 'product';
        $mode = $own ? $link['mode'] : 'inherit';
        if (!isset($out[$id]) || $own) {
            $out[$id] = ['row' => $rows[$id], 'mode' => $mode, 'shown' => isset($shown[$id])];
        }
    }
    return $out;
}

function hithean_research_product_box_html(int $product_id): string
{
    $statuses = hithean_research_statuses();
    $items = hithean_research_product_candidates($product_id);
    $html = '<div class="hr-box" data-hr-product="' . $product_id . '">';
    if (!$items) {
        $html .= '<p class="hr-box__empty">Chưa có tài liệu nào gắn với SP này hoặc danh mục của nó.</p>';
    } else {
        $html .= '<ul class="hr-box__list">';
        $labels = ['pin' => '📌 Ghim đầu', 'include' => 'Gắn riêng SP', 'exclude' => '🚫 Đang ẩn ở SP này', 'inherit' => 'Theo danh mục'];
        foreach ($items as $id => $item) {
            $row = $item['row'];
            $html .= '<li class="hr-box__item' . ($item['shown'] ? '' : ' is-hidden') . '">'
                . '<a href="' . esc_url(hithean_research_board_url($id)) . '" target="_blank" rel="noopener">' . esc_html((string) $row['title']) . '</a>'
                . '<span class="hr-box__meta">' . esc_html($labels[$item['mode']] . ' · ' . ($statuses[$row['status']]['label'] ?? $row['status'])) . ($item['shown'] ? ' · <b>đang hiện</b>' : '') . '</span>'
                . '<span class="hr-box__acts">';
            if ($item['mode'] !== 'pin') {
                $html .= '<button type="button" class="hr-abtn" data-hr-link="pin" data-id="' . $id . '">📌 Ghim</button>';
            }
            if ($item['mode'] !== 'exclude') {
                $html .= '<button type="button" class="hr-abtn" data-hr-link="exclude" data-id="' . $id . '">🚫 Ẩn ở SP này</button>';
            }
            if ($item['mode'] !== 'inherit') {
                $html .= '<button type="button" class="hr-abtn" data-hr-link="" data-id="' . $id . '">' . ($item['mode'] === 'exclude' ? 'Hiện lại' : 'Bỏ gắn riêng') . '</button>';
            }
            $html .= '</span></li>';
        }
        $html .= '</ul>';
    }
    return $html . '<div class="hr-box__add"><input type="url" placeholder="Dán link nghiên cứu để gắn vào SP này" data-hr-box-url>'
        . '<button type="button" class="hr-abtn hr-abtn--primary" data-hr-box-add>Thêm</button></div>'
        . '<p class="hr-box__foot"><span data-hr-box-status aria-live="polite"></span> <a href="' . esc_url(hithean_research_board_url()) . '" target="_blank" rel="noopener">Mở bảng Hồ sơ khoa học ↗</a></p></div>';
}

add_action('add_meta_boxes_product', static function (): void {
    add_meta_box('hithean-research', 'Hồ sơ khoa học', static function (WP_Post $post): void {
        echo hithean_research_product_box_html((int) $post->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder.
    }, 'product', 'side', 'low');
});

/* ---------- Màn sửa danh mục ---------- */

add_action('product_cat_edit_form_fields', static function (WP_Term $term): void {
    if (get_option(HITHEAN_RESEARCH_DB_OPTION) !== HITHEAN_RESEARCH_DB_VERSION) {
        return;
    }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        'SELECT research_id FROM ' . hithean_research_links_table() . " WHERE target_type = 'product_cat' AND target_id = %d AND mode = 'include' LIMIT 100",
        (int) $term->term_id
    ));
    $rows = hithean_research_get_many($ids, false);
    $statuses = hithean_research_statuses();
    ?>
    <tr class="form-field">
        <th scope="row">Hồ sơ khoa học</th>
        <td>
            <?php if ($rows) : ?>
                <ul style="margin:0">
                    <?php foreach ($rows as $id => $row) : ?>
                        <li><a href="<?php echo esc_url(hithean_research_board_url($id)); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $row['title']); ?></a>
                            — <?php echo esc_html($statuses[$row['status']]['label'] ?? $row['status']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="description">Tài liệu gắn danh mục tự hiện ở mọi SP trong danh mục (và danh mục con). Ẩn ở từng SP trong metabox "Hồ sơ khoa học" của SP.</p>
            <?php else : ?>
                <p class="description">Chưa gắn tài liệu nào.</p>
            <?php endif; ?>
            <p><a href="<?php echo esc_url(hithean_research_board_url()); ?>" target="_blank" rel="noopener">Mở bảng Hồ sơ khoa học ↗</a></p>
        </td>
    </tr>
    <?php
});

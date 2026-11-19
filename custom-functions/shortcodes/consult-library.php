<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/shortcode-consult-faqs.php';

/**
 * Unified consultation model/UI over the existing stores. Stable typed keys prevent ID collisions;
 * legacy options and histories remain authoritative so rollout needs no destructive migration.
 * Common shape: {id: 'flow-N'|'faq-N', type, title, group, steps[], keywords[], skus[], status, uses, source, updated, by}.
 */
function hcl_key(string $key): ?array
{
    return preg_match('/^(flow|faq)-([1-9][0-9]*)$/D', $key, $m) ? [$m[1], (int) $m[2]] : null;
}

/** Pure adapters, preserving IDs, drafts, usage and authorship. */
function hcl_normalize(array $flows, array $faqs, bool $drafts): array
{
    $items = [];
    foreach ($flows as $flow) {
        if (!$drafts && ($flow['status'] ?? 'publish') !== 'publish') continue;
        $flow['id'] = 'flow-' . (int) $flow['id'];
        $flow['type'] = 'flow'; $flow['uses'] = 0; $flow['source'] = 'manual';
        $items[] = $flow;
    }
    foreach ($faqs as $faq) {
        if (!$drafts && ($faq['status'] ?? 'publish') !== 'publish') continue;
        $items[] = [
            'id' => 'faq-' . (int) $faq['id'], 'type' => 'faq', 'title' => $faq['q'],
            'group' => $faq['group'] ?? '', 'keywords' => $faq['keywords'], 'skus' => $faq['skus'],
            'steps' => [['title' => 'Trả lời', 'say' => $faq['a'], 'note' => $faq['note'] ?? '']],
            'status' => $faq['status'] ?? 'publish', 'uses' => $faq['uses'], 'source' => $faq['source'],
            'updated' => $faq['updated'], 'by' => $faq['by'],
        ];
    }
    usort($items, static function ($a, $b) { return [hcf_norm($a['group']), hcf_norm($a['title']), $a['id']] <=> [hcf_norm($b['group']), hcf_norm($b['title']), $b['id']]; });
    return $items;
}

function hcl_items(bool $drafts = false): array
{
    return hcl_normalize(array_values(hcf_store_read()['flows']), array_values(hcf_faq_read()['items']), $drafts);
}

/** Existing editors/importers and AI read the same records; never copy old data into a second source of truth. */
function hcl_ai_sources(): array
{
    $flows = $faqs = [];
    foreach (hcl_items(false) as $item) {
        [, $id] = hcl_key($item['id']);
        if ($item['type'] === 'flow') { $item['id'] = $id; $flows[] = $item; }
        else $faqs[] = ['id' => $id, 'q' => $item['title'], 'a' => $item['steps'][0]['say'], 'skus' => $item['skus'], 'keywords' => $item['keywords'], 'uses' => $item['uses'], 'status' => $item['status']];
    }
    return [$flows, $faqs];
}

/** New FAQ is available to order staff. All edits/deletes and scripts still require Woo management. */
function hcl_save_entry(array $raw, bool $manage)
{
    $key = is_scalar($raw['id'] ?? null) ? (string) $raw['id'] : '';
    $parsed = $key !== '' && $key !== '0' ? hcl_key($key) : null;
    if ($key !== '' && $key !== '0' && !$parsed) return new WP_Error('hcl_invalid', 'Nội dung không hợp lệ.');
    $type = is_string($raw['type'] ?? null) ? $raw['type'] : 'faq';
    if (isset($raw['type']) && !is_string($raw['type'])) return new WP_Error('hcl_invalid', 'Dạng nội dung không hợp lệ.');
    if (isset($raw['status']) && !in_array($raw['status'], ['draft', 'publish'], true)) return new WP_Error('hcl_invalid', 'Trạng thái không hợp lệ.');
    if (!in_array($type, ['flow', 'faq'], true) || ($parsed && $parsed[0] !== $type)) return new WP_Error('hcl_invalid', 'Không thể đổi dạng của nội dung đã lưu.');
    if (!$manage && ($type === 'flow' || $parsed)) return new WP_Error('hcl_forbidden', 'Không có quyền sửa nội dung hoặc quản lý kịch bản.');
    $id = $parsed[1] ?? 0;
    $raw['id'] = $id;
    if ($type === 'flow') {
        $id = hcf_save_flow($raw);
        return is_wp_error($id) ? $id : 'flow-' . $id;
    }
    $steps = is_array($raw['steps'] ?? null) ? array_values($raw['steps']) : [];
    if (count($steps) !== 1 || !is_array($steps[0])) return new WP_Error('hcl_invalid', 'Hỏi đáp cần đúng một câu trả lời.');
    $faq = [
        'id' => $id, 'q' => $raw['title'] ?? '', 'a' => $steps[0]['say'] ?? '', 'note' => $steps[0]['note'] ?? '',
        'group' => $raw['group'] ?? '', 'keywords' => $raw['keywords'] ?? [], 'skus' => $raw['skus'] ?? [],
        'status' => $manage ? ($raw['status'] ?? 'publish') : 'publish', 'source' => $raw['source'] ?? 'manual',
    ];
    [$store, $result, $before] = hcf_faq_store_upsert(hcf_faq_read(), $faq, current_time('mysql'), hcf_current_user_label(), $manage);
    if (!is_int($result)) return new WP_Error('hcl_invalid', $result);
    hcf_faq_write($store, $before);
    return 'faq-' . $result;
}

add_action('wp_ajax_hithean_library_list', 'hcl_ajax_list');
function hcl_ajax_list(): void
{
    hcf_ajax_guard(false);
    wp_send_json_success(['flows' => hcl_items(hcf_can_manage())]);
}

add_action('wp_ajax_hithean_library_save', 'hcl_ajax_save');
function hcl_ajax_save(): void
{
    hcf_ajax_guard(false);
    $input = $_POST['entry'] ?? '';
    $raw = is_string($input) && strlen($input) <= 300000 ? json_decode(wp_unslash($input), true) : null;
    if (!is_array($raw)) wp_send_json_error(['message' => 'Nội dung không hợp lệ hoặc quá dài.'], 400);
    $id = hcl_save_entry($raw, hcf_can_manage());
    if (is_wp_error($id)) wp_send_json_error(['message' => $id->get_error_message()], $id->get_error_code() === 'hcl_forbidden' ? 403 : 400);
    wp_send_json_success(['id' => $id, 'flows' => hcl_items(hcf_can_manage())]);
}

add_action('wp_ajax_hithean_library_delete', 'hcl_ajax_delete');
function hcl_ajax_delete(): void
{
    hcf_ajax_guard(true);
    $key = hcl_key(is_string($_POST['id'] ?? null) ? wp_unslash($_POST['id']) : '');
    if (!$key) wp_send_json_error(['message' => 'Nội dung không hợp lệ.'], 400);
    [$type, $id] = $key;
    $store = $type === 'flow' ? hcf_store_read() : hcf_faq_read();
    $field = $type === 'flow' ? 'flows' : 'items';
    if (!isset($store[$field][$id])) wp_send_json_error(['message' => 'Nội dung không còn tồn tại.'], 404);
    $before = $store[$field][$id]; unset($store[$field][$id]);
    if ($type === 'flow') hcf_store_write($store, $before, 'delete'); else hcf_faq_write($store, $before, 'delete');
    wp_send_json_success(['flows' => hcl_items(true)]);
}

/** Rank both kinds in one lookup, published entries only. Raw tokens stay intact until preview/copy. */
function hcl_suggest(array $entries, string $text, array $skus): array
{
    $ranked = [];
    foreach ($entries as $item) {
        if (($item['status'] ?? 'publish') !== 'publish') continue;
        $matches = $item['type'] === 'faq'
            ? hcf_faq_match([['q' => $item['title'], 'skus' => $item['skus'], 'keywords' => $item['keywords'], 'uses' => $item['uses']]], $text, $skus, 1)
            : hcf_match([$item], $text, $skus, 1);
        if (!$matches) continue;
        $parts = array_map(static function ($step) { return trim(preg_split('/\n\s*---+\s*\n/', $step['say'])[0]); }, $item['steps']);
        $ranked[] = ['key' => $item['id'], 'id' => $item['id'], 'type' => $item['type'], 'q' => $item['title'], 'a' => mb_substr(implode("\n\n", $parts), 0, 4000), 'score' => $matches[0]['score']];
    }
    usort($ranked, static function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($ranked, 0, 5);
}

add_action('wp_ajax_hithean_library_suggest', 'hcl_ajax_suggest');
function hcl_ajax_suggest(): void
{
    hcf_ajax_guard(false);
    $q = hcf_clean_text(wp_unslash($_POST['q'] ?? ''), 2000);
    $skus = hcf_clean_list(wp_unslash($_POST['skus'] ?? ''), 10, 60);
    wp_send_json_success(['items' => hcl_suggest(hcl_items(false), $q, $skus)]);
}

add_action('wp_ajax_hithean_library_copy', 'hcl_ajax_copy');
function hcl_ajax_copy(): void
{
    hcf_ajax_guard(false);
    $key = is_string($_POST['key'] ?? null) ? wp_unslash($_POST['key']) : '';
    $items = array_column(hcl_items(hcf_can_manage()), null, 'id');
    if (!isset($items[$key])) wp_send_json_error(['message' => 'Nội dung không còn khả dụng.'], 404);
    $item = $items[$key];
    if (isset($_POST['texts'])) {
        $raw = $_POST['texts'];
        $texts = is_string($raw) && strlen($raw) <= 200000 ? json_decode(wp_unslash($raw), true) : null;
        if (!is_array($texts) || count($texts) > 30 || array_filter($texts, static function ($v) { return !is_string($v) || mb_strlen($v, 'UTF-8') > 4000; })) wp_send_json_error(['message' => 'Nội dung copy không hợp lệ.'], 400);
    } else $texts = array_map(static function ($s) { return trim(preg_split('/\n\s*---+\s*\n/', $s['say'])[0]); }, $item['steps']);
    $result = hcf_variables_resolve($texts);
    if (!$result['errors'] && $item['type'] === 'faq') {
        [, $id] = hcl_key($key); $store = hcf_faq_read();
        if (isset($store['items'][$id])) { $store['items'][$id]['uses']++; update_option(HCF_FAQ_OPTION, $store, false); }
    }
    wp_send_json_success($result);
}

/** Tab con mở sẵn: ?hcl_view=ai (link công cụ cũ "advisor") → AI tư vấn, còn lại → Thư viện. */
function hcl_initial_view($raw): string
{
    return is_string($raw) && sanitize_key($raw) === 'ai' ? 'ai' : 'library';
}

add_shortcode('consult_library', 'hcl_shortcode');
/** Thư viện tư vấn + AI tư vấn chung 1 trang: 2 tab con, AI đọc / lưu đúng kho thư viện, cập nhật qua sự kiện cùng trang. */
function hcl_shortcode(): string
{
    if (!is_user_logged_in() || !hcf_can_view()) return '<p>Cần đăng nhập tài khoản nhân viên để xem thư viện tư vấn.</p>';
    if (!function_exists('hpa_shortcode')) require_once __DIR__ . '/shortcode-product-advisor.php';
    $view = hcl_initial_view($_GET['hcl_view'] ?? '');
    $views = ['library' => '📚 Thư viện', 'ai' => '✨ AI tư vấn'];
    ob_start(); ?>
    <div class="hcl-views" role="tablist" aria-label="Thư viện tư vấn" data-hcl-views>
        <?php foreach ($views as $key => $label) : ?>
            <button type="button" role="tab" id="hcl-tab-<?php echo esc_attr($key); ?>" aria-controls="hcl-view-<?php echo esc_attr($key); ?>" aria-selected="<?php echo $key === $view ? 'true' : 'false'; ?>" tabindex="<?php echo $key === $view ? '0' : '-1'; ?>" data-hcl-view="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>
    <section id="hcl-view-library" role="tabpanel" aria-labelledby="hcl-tab-library" data-hcl-panel="library" <?php echo $view !== 'library' ? 'hidden' : ''; ?>><?php echo hcl_library_markup(); ?></section>
    <section id="hcl-view-ai" role="tabpanel" aria-labelledby="hcl-tab-ai" data-hcl-panel="ai" <?php echo $view !== 'ai' ? 'hidden' : ''; ?>><?php echo hpa_shortcode(); ?></section>
    <?php
    $base = get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/';
    $ver = @filemtime(__DIR__ . '/assets/consult-views.js') ?: '1';
    echo '<script src="' . esc_url($base . 'consult-views.js?v=' . $ver) . '" defer></script>';
    return (string) ob_get_clean();
}

function hcl_library_markup(): string
{
    $cfg = ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('hcf_flows'), 'manage' => hcf_can_manage(), 'site' => sanitize_title(get_bloginfo('name')) ?: 'site', 'library' => true];
    ob_start(); ?>
    <div class="hcf" id="hcf-root" data-hcf>
        <div class="hcf-bar">
            <input type="search" class="hcf-in" data-hcf-q placeholder="Tìm câu hỏi, tình huống, câu trả lời, SKU…" aria-label="Tìm trong thư viện tư vấn">
            <select class="hcf-in" data-hcl-type aria-label="Dạng nội dung"><option value="">Tất cả</option><option value="faq">Hỏi đáp nhanh</option><option value="flow">Kịch bản</option></select>
            <select class="hcf-in" data-hcf-group aria-label="Nhóm"><option value="">Mọi nhóm</option></select>
            <input type="text" class="hcf-in hcf-in--name" data-hcf-name placeholder="Tên khách (thay {ten})" aria-label="Tên khách">
            <button type="button" class="hcf-btn hcf-btn--pri" data-hcf-new>＋ Thêm vào thư viện</button>
            <button type="button" class="hcf-btn" data-hcl-ask title="Mở tab AI tư vấn, điền sẵn nội dung đang tìm">✨ Hỏi AI</button>
            <?php if (hcf_can_manage()) : ?>
                <button type="button" class="hcf-btn" data-hcb-export="all">⤓ Xuất thư viện &amp; tài liệu</button>
                <label class="hcf-btn">⤒ Nhập<input type="file" accept=".json,application/json" data-hcb-import hidden></label>
                <a class="hcf-btn" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/consult-starter.json'); ?>" download="thu-vien-tu-van-mau.json">⤓ Mẫu để sửa</a>
            <?php endif; ?>
        </div>
        <p class="hcf-status" role="status" data-hcf-status>Đang tải thư viện…</p>
        <div class="hcf-body"><nav class="hcf-list" data-hcf-list aria-label="Thư viện tư vấn"></nav><section class="hcf-detail" data-hcf-detail aria-live="polite"></section></div>
    </div>
    <script type="application/json" id="hcf-cfg"><?php echo wp_json_encode($cfg); ?></script>
    <?php echo hcf_assets(); return (string) ob_get_clean();
}

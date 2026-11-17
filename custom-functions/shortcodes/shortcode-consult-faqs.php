<?php
defined('ABSPATH') || exit;

/**
 * FAQ tư vấn nội bộ — [consult_faqs] (tab Sản phẩm - Tư vấn ở /quan-ly-don) + lưu từ AI tư vấn.
 *
 * Câu hỏi khách hay gặp + câu trả lời đã dùng tốt, để tái sử dụng: AI tư vấn gợi ý FAQ khớp ngay khi nhân viên gõ câu hỏi
 * (copy dùng luôn, không tốn lượt AI) và đưa FAQ khớp vào ngữ cảnh để AI trả lời nhất quán.
 * KHÔNG phải trường "Câu hỏi thường gặp" công khai trên trang SP (product_info_faq).
 * Lưu 1 option (không CPT): `hithean_consult_faqs` = {next_id, items{id: q, a, skus[], keywords[], source ai|manual, uses, updated, by}};
 * sửa / xóa đẩy bản cũ vào `hithean_consult_faqs_history` (≤50).
 * Quyền: nhân viên (edit_shop_orders) xem / copy / thêm mới; sửa / xóa / nhập: manage_woocommerce.
 * Cùng file: tài liệu tham khảo chung cho AI (option `hithean_consult_reference`) và gói xuất / nhập sang site khác
 * (`thean-consult-bundle`: kịch bản + FAQ + tài liệu, không mang ID; AJAX hithean_consult_export / _import).
 * Hàm thuần: hcf_faq_sanitize, hcf_faq_store_*, hcf_faq_match, hcf_bundle_*, hcf_reference_merge — test `php tests/consult-flows.php`.
 */

require_once __DIR__ . '/shortcode-consult-flows.php';

defined('HCF_FAQ_OPTION') || define('HCF_FAQ_OPTION', 'hithean_consult_faqs');
defined('HCF_FAQ_HISTORY_OPTION') || define('HCF_FAQ_HISTORY_OPTION', 'hithean_consult_faqs_history');
defined('HCF_FAQ_MAX') || define('HCF_FAQ_MAX', 1000);

function hcf_faq_sanitize(array $raw): array
{
    return [
        'id'       => max(0, (int) ($raw['id'] ?? 0)),
        'q'        => hcf_clean_text($raw['q'] ?? '', 500),
        'a'        => hcf_clean_text($raw['a'] ?? '', 4000),
        'skus'     => array_map('strtoupper', hcf_clean_list($raw['skus'] ?? [], 10, 60)),
        'keywords' => hcf_clean_list($raw['keywords'] ?? [], 20),
        'source'   => ($raw['source'] ?? '') === 'ai' ? 'ai' : 'manual',
        'uses'     => max(0, (int) ($raw['uses'] ?? 0)),
        'updated'  => hcf_clean_text($raw['updated'] ?? '', 30, false),
        'by'       => hcf_clean_text($raw['by'] ?? '', 120, false),
    ];
}

function hcf_faq_store_normalize($raw): array
{
    $store = ['next_id' => 1, 'items' => []];
    if (!is_array($raw)) {
        return $store;
    }
    foreach ((array) ($raw['items'] ?? []) as $item) {
        $faq = is_array($item) ? hcf_faq_sanitize($item) : null;
        if ($faq && $faq['id'] > 0 && $faq['q'] !== '' && $faq['a'] !== '') {
            $store['items'][$faq['id']] = $faq;
        }
    }
    $store['next_id'] = max((int) ($raw['next_id'] ?? 1), $store['items'] ? max(array_keys($store['items'])) + 1 : 1);

    return $store;
}

/**
 * Thêm / sửa FAQ (hàm thuần). Câu hỏi trùng (bỏ dấu) với FAQ khác → cập nhật FAQ đó nếu $can_update, ngược lại báo trùng.
 * @return array{0:array,1:int|string,2:?array} [kho mới, id hoặc thông báo lỗi, bản cũ]
 */
function hcf_faq_store_upsert(array $store, array $raw, string $now, string $by, bool $can_update): array
{
    $faq = hcf_faq_sanitize($raw);
    if (mb_strlen($faq['q'], 'UTF-8') < 3 || $faq['a'] === '') {
        return [$store, 'Cần cả câu hỏi và câu trả lời.', null];
    }
    if (!$faq['id']) {
        $key = hcf_norm($faq['q']);
        foreach ($store['items'] as $item) {
            if (hcf_norm($item['q']) === $key) {
                if (!$can_update) {
                    return [$store, 'Đã có FAQ cùng câu hỏi (#' . $item['id'] . ') — nhờ quản lý cập nhật.', null];
                }
                $faq['id'] = $item['id'];
                break;
            }
        }
    }
    $before = null;
    if ($faq['id']) {
        if (!isset($store['items'][$faq['id']])) {
            return [$store, 'FAQ không còn tồn tại — tải lại trang.', null];
        }
        if (!$can_update) {
            return [$store, 'Không có quyền sửa FAQ.', null];
        }
        $before = $store['items'][$faq['id']];
        $faq['uses'] = $before['uses'];
        $faq['source'] = $before['source'];
    } else {
        if (count($store['items']) >= HCF_FAQ_MAX) {
            return [$store, 'Đã đủ ' . HCF_FAQ_MAX . ' FAQ — gộp hoặc xóa bớt.', null];
        }
        $faq['id'] = (int) $store['next_id'];
        $store['next_id'] = $faq['id'] + 1;
    }
    $faq['updated'] = $now;
    $faq['by'] = $by;
    $store['items'][$faq['id']] = $faq;

    return [$store, $faq['id'], $before];
}

/** Chữ có nghĩa của câu (bỏ dấu, bỏ từ xưng hô / đệm hay gặp trong chat). */
function hcf_faq_tokens(string $text): array
{
    static $stop = ['em', 'anh', 'chi', 'a', 'ah', 'nhe', 'nha', 'khong', 'ko', 'k', 'kh', 'co', 'la', 'thi', 'minh', 'cho', 'voi',
        'duoc', 'dc', 'gi', 'nao', 'sao', 'oi', 'vay', 'roi', 'cua', 'va', 'hay', 'ban', 'shop', 'ad', 'ak', 'u', 'uh', 'dang', 'nay', 'do',
        'the', 'ma', 'cung', 'nhu', 'lai', 'bi', 'se', 'can', 'muon', 'hoi', 'xin', 'giup', 'voi', 'nhi', 'ne', 'day'];
    $words = array_filter(explode(' ', hcf_norm($text)), static function ($w) use ($stop) {
        return $w !== '' && !in_array($w, $stop, true);
    });

    return array_values(array_unique($words));
}

/**
 * FAQ khớp câu hỏi (hàm thuần): độ giống Dice trên chữ có nghĩa (câu khách vs câu hỏi FAQ), + 0.5 nếu cụm từ khóa xuất hiện (1 từ khóa là đủ khớp),
 * + 0.2 nếu trùng SKU. Giữ điểm ≥ $min, cao trước (bằng điểm → dùng nhiều trước). Phần tử thêm khóa `score`.
 */
function hcf_faq_match(array $faqs, string $text, array $skus = [], int $limit = 3, float $min = 0.45): array
{
    $q = hcf_faq_tokens($text);
    $hay = ' ' . hcf_norm($text) . ' ';
    $skus = array_map(static function ($s) { return strtoupper(trim((string) $s)); }, $skus);
    $out = [];
    foreach ($faqs as $faq) {
        $f = hcf_faq_tokens((string) ($faq['q'] ?? ''));
        $score = ($q && $f) ? 2 * count(array_intersect($q, $f)) / (count($q) + count($f)) : 0.0;
        foreach ((array) ($faq['keywords'] ?? []) as $kw) {
            $kw = hcf_norm((string) $kw);
            if ($kw !== '' && strpos($hay, ' ' . $kw . ' ') !== false) {
                $score += 0.5;
                break;
            }
        }
        if ($skus && array_intersect($skus, (array) ($faq['skus'] ?? []))) {
            $score += 0.2;
        }
        if ($score >= $min) {
            $faq['score'] = round($score, 3);
            $out[] = $faq;
        }
    }
    usort($out, static function (array $a, array $b): int {
        return [$b['score'], (int) ($b['uses'] ?? 0)] <=> [$a['score'], (int) ($a['uses'] ?? 0)];
    });

    return array_slice($out, 0, max(0, $limit));
}

/** Danh sách FAQ: dùng nhiều trước, rồi mới cập nhật trước. */
function hcf_faq_list(array $store): array
{
    $items = array_values($store['items']);
    usort($items, static function (array $a, array $b): int {
        return [$b['uses'], $b['updated']] <=> [$a['uses'], $a['updated']];
    });

    return $items;
}

function hcf_faq_read(): array
{
    return hcf_faq_store_normalize(get_option(HCF_FAQ_OPTION, []));
}

function hcf_faq_write(array $store, ?array $before = null, string $action = 'save'): void
{
    update_option(HCF_FAQ_OPTION, $store, false);
    if ($before) {
        $history = get_option(HCF_FAQ_HISTORY_OPTION, []);
        $history = is_array($history) ? $history : [];
        array_unshift($history, ['at' => current_time('mysql'), 'by' => hcf_current_user_label(), 'action' => $action, 'faq' => $before]);
        update_option(HCF_FAQ_HISTORY_OPTION, array_slice($history, 0, 50), false);
    }
}

/** Gọn cho trình duyệt (không gửi lịch sử). */
function hcf_faq_public(array $faq): array
{
    return array_intersect_key($faq, array_flip(['id', 'q', 'a', 'skus', 'keywords', 'source', 'uses', 'updated', 'by', 'score']));
}

// ─────────────────────────────── AJAX ───────────────────────────────

add_action('wp_ajax_hithean_faq_list', 'hcf_faq_ajax_list');
function hcf_faq_ajax_list(): void
{
    hcf_ajax_guard(false);
    wp_send_json_success(['items' => array_map('hcf_faq_public', hcf_faq_list(hcf_faq_read()))]);
}

/** Gợi ý FAQ khớp câu đang gõ (AI tư vấn). */
add_action('wp_ajax_hithean_faq_suggest', 'hcf_faq_ajax_suggest');
function hcf_faq_ajax_suggest(): void
{
    hcf_ajax_guard(false);
    $text = hcf_clean_text(wp_unslash($_POST['q'] ?? ''), 2000);
    $skus = hcf_clean_list(wp_unslash($_POST['skus'] ?? ''), 10, 60);
    wp_send_json_success(['items' => array_map('hcf_faq_public', hcf_faq_match(array_values(hcf_faq_read()['items']), $text, $skus, 5))]);
}

add_action('wp_ajax_hithean_faq_save', 'hcf_faq_ajax_save');
function hcf_faq_ajax_save(): void
{
    hcf_ajax_guard(false); // nhân viên thêm được; sửa cần quản lý (kiểm trong upsert)
    $raw = json_decode((string) wp_unslash($_POST['faq'] ?? ''), true);
    [$store, $result, $before] = hcf_faq_store_upsert(hcf_faq_read(), is_array($raw) ? $raw : [], current_time('mysql'), hcf_current_user_label(), hcf_can_manage());
    if (!is_int($result)) {
        wp_send_json_error(['message' => $result], 400);
    }
    hcf_faq_write($store, $before);
    wp_send_json_success(['id' => $result, 'updated' => (bool) $before, 'items' => array_map('hcf_faq_public', hcf_faq_list($store))]);
}

add_action('wp_ajax_hithean_faq_delete', 'hcf_faq_ajax_delete');
function hcf_faq_ajax_delete(): void
{
    hcf_ajax_guard(true);
    $id = absint($_POST['id'] ?? 0);
    $store = hcf_faq_read();
    if (!$id || !isset($store['items'][$id])) {
        wp_send_json_error(['message' => 'Không tìm thấy FAQ.'], 404);
    }
    $before = $store['items'][$id];
    unset($store['items'][$id]);
    hcf_faq_write($store, $before, 'delete');
    wp_send_json_success(['items' => array_map('hcf_faq_public', hcf_faq_list($store))]);
}

/** Đếm lượt dùng khi nhân viên copy câu trả lời — FAQ dùng nhiều lên đầu danh sách. */
add_action('wp_ajax_hithean_faq_used', 'hcf_faq_ajax_used');
function hcf_faq_ajax_used(): void
{
    hcf_ajax_guard(false);
    $id = absint($_POST['id'] ?? 0);
    $store = hcf_faq_read();
    if ($id && isset($store['items'][$id])) {
        $store['items'][$id]['uses']++;
        update_option(HCF_FAQ_OPTION, $store, false);
    }
    wp_send_json_success();
}

// ───────────────────── Tài liệu tham khảo chung (AI luôn đọc) ─────────────────────

defined('HCF_REF_OPTION') || define('HCF_REF_OPTION', 'hithean_consult_reference');

/** {text, updated, by} — chính sách ship / đổi trả / ưu đãi đang chạy… AI tư vấn luôn đọc (≤ 20.000 ký tự). */
function hcf_reference(): array
{
    $raw = get_option(HCF_REF_OPTION, []);

    return [
        'text'    => hcf_clean_text(is_array($raw) ? ($raw['text'] ?? '') : '', 20000),
        'updated' => (string) (is_array($raw) ? ($raw['updated'] ?? '') : ''),
        'by'      => (string) (is_array($raw) ? ($raw['by'] ?? '') : ''),
    ];
}

function hcf_reference_save(string $text): array
{
    $ref = ['text' => hcf_clean_text($text, 20000), 'updated' => current_time('mysql'), 'by' => hcf_current_user_label()];
    update_option(HCF_REF_OPTION, $ref, false);

    return $ref;
}

add_action('wp_ajax_hithean_consult_ref_save', 'hcf_reference_ajax_save');
function hcf_reference_ajax_save(): void
{
    hcf_ajax_guard(true);
    wp_send_json_success(['reference' => hcf_reference_save((string) wp_unslash($_POST['text'] ?? ''))]);
}

// ───────────────────── Gói xuất / nhập sang site khác ─────────────────────

defined('HCF_BUNDLE_FORMAT') || define('HCF_BUNDLE_FORMAT', 'thean-consult-bundle');

/**
 * Gói JSON mang sang site khác (hàm thuần): không có ID / lượt dùng / người sửa của site nguồn.
 * $scope: all | flows | faqs.
 */
function hcf_bundle_build(array $flows, array $faqs, string $reference, string $site, string $now, string $scope = 'all'): array
{
    $bundle = ['format' => HCF_BUNDLE_FORMAT, 'version' => 1, 'site' => $site, 'exported' => $now];
    if ($scope !== 'faqs') {
        $bundle['flows'] = array_map(static function (array $f): array {
            return ['title' => $f['title'], 'group' => $f['group'], 'keywords' => $f['keywords'], 'skus' => $f['skus'], 'status' => $f['status'], 'steps' => $f['steps']];
        }, array_values($flows));
    }
    if ($scope !== 'flows') {
        $bundle['faqs'] = array_map(static function (array $f): array {
            return ['q' => $f['q'], 'a' => $f['a'], 'skus' => $f['skus'], 'keywords' => $f['keywords'], 'source' => $f['source']];
        }, array_values($faqs));
    }
    if ($scope === 'all' && $reference !== '') {
        $bundle['reference'] = $reference;
    }

    return $bundle;
}

/**
 * Tách gói đã giải mã (hàm thuần) → [flows[], faqs[], reference]. Nhận: gói chuẩn, file cũ chỉ có {flows} / {items},
 * hoặc mảng trơn (phần tử có `steps` = kịch bản, có `q` = FAQ).
 */
function hcf_bundle_parse($data): array
{
    $flows = $faqs = [];
    $reference = '';
    if (!is_array($data)) {
        return [$flows, $faqs, $reference];
    }
    $flows = isset($data['flows']) && is_array($data['flows']) ? $data['flows'] : [];
    $faqs = isset($data['faqs']) && is_array($data['faqs']) ? $data['faqs'] : (isset($data['items']) && is_array($data['items']) ? $data['items'] : []);
    $reference = is_string($data['reference'] ?? null) ? hcf_clean_text($data['reference'], 20000) : '';
    if (!$flows && !$faqs && array_keys($data) === range(0, count($data) - 1)) {
        foreach ($data as $item) {
            if (is_array($item) && isset($item['steps'])) {
                $flows[] = $item;
            } elseif (is_array($item) && isset($item['q'])) {
                $faqs[] = $item;
            }
        }
    }
    $strip = static function (array $items): array {
        return array_values(array_map(static function ($i) { if (is_array($i)) { unset($i['id'], $i['uses'], $i['updated'], $i['by']); } return $i; }, $items));
    };

    return [$strip($flows), $strip($faqs), $reference];
}

/** Gộp tài liệu tham khảo nhập vào (hàm thuần): trống → lấy luôn; đã chứa → giữ nguyên; khác → nối thêm có tiêu đề nguồn. */
function hcf_reference_merge(string $current, string $incoming, string $label): string
{
    $current = trim($current);
    $incoming = trim($incoming);
    if ($incoming === '' || ($current !== '' && strpos($current, $incoming) !== false)) {
        return $current;
    }

    return mb_substr($current === '' ? $incoming : $current . "\n\n--- " . $label . " ---\n" . $incoming, 0, 20000, 'UTF-8');
}

add_action('wp_ajax_hithean_consult_export', 'hcf_bundle_ajax_export');
function hcf_bundle_ajax_export(): void
{
    hcf_ajax_guard(true);
    $scope = sanitize_key(wp_unslash($_POST['scope'] ?? 'all'));
    $scope = in_array($scope, ['all', 'flows', 'faqs'], true) ? $scope : 'all';
    wp_send_json_success(['bundle' => hcf_bundle_build(
        hcf_get_flows(true), hcf_faq_list(hcf_faq_read()), hcf_reference()['text'],
        (string) wp_parse_url(home_url(), PHP_URL_HOST), current_time('c'), $scope
    )]);
}

/** Nhập gói (từ site này hoặc site khác) — ghi mỗi kho 1 lần; trùng tiêu đề kịch bản / câu hỏi FAQ → cập nhật. */
add_action('wp_ajax_hithean_consult_import', 'hcf_bundle_ajax_import');
function hcf_bundle_ajax_import(): void
{
    hcf_ajax_guard(true);
    $data = json_decode((string) wp_unslash($_POST['data'] ?? ''), true);
    [$flows, $faqs, $reference] = hcf_bundle_parse($data);
    if (!$flows && !$faqs && $reference === '') {
        wp_send_json_error(['message' => 'File không đúng định dạng (cần gói JSON xuất từ tab Kịch bản / FAQ tư vấn).'], 400);
    }
    $now = current_time('mysql');
    $by = hcf_current_user_label();
    $msg = [];
    if ($flows) {
        [$store, $c, $u, $err] = hcf_store_import(hcf_store_read(), array_slice($flows, 0, 300), $now, $by);
        if ($c || $u) {
            hcf_store_write($store);
        }
        $msg[] = sprintf('Kịch bản: thêm %d, cập nhật %d', $c, $u) . ($err ? ' (lỗi ' . count($err) . ')' : '');
    }
    if ($faqs) {
        $store = hcf_faq_read();
        $c = $u = $e = 0;
        foreach (array_slice($faqs, 0, HCF_FAQ_MAX) as $item) {
            [$store, $result, $before] = is_array($item) ? hcf_faq_store_upsert($store, $item, $now, $by, true) : [$store, 'x', null];
            is_int($result) ? ($before ? $u++ : $c++) : $e++;
        }
        if ($c || $u) {
            hcf_faq_write($store);
        }
        $msg[] = sprintf('FAQ: thêm %d, cập nhật %d', $c, $u) . ($e ? ' (lỗi ' . $e . ')' : '');
    }
    if ($reference !== '') {
        $current = hcf_reference()['text'];
        $merged = hcf_reference_merge($current, $reference, 'Nhập từ ' . (is_array($data) ? hcf_clean_text($data['site'] ?? 'file', 80, false) : 'file') . ' ' . substr($now, 0, 10));
        if ($merged !== $current) {
            hcf_reference_save($merged);
            $msg[] = 'Tài liệu tham khảo: đã gộp';
        }
    }
    wp_send_json_success([
        'message' => implode('. ', $msg) . '.',
        'flows'   => hcf_get_flows(true),
        'items'   => array_map('hcf_faq_public', hcf_faq_list(hcf_faq_read())),
    ]);
}

// ─────────────────────────────── Shortcode ───────────────────────────────

add_shortcode('consult_faqs', 'hcf_faq_shortcode');
function hcf_faq_shortcode(): string
{
    if (!is_user_logged_in() || !hcf_can_view()) {
        return '<p>Cần đăng nhập tài khoản nhân viên để xem FAQ tư vấn.</p>';
    }
    $cfg = [
        'ajax'   => admin_url('admin-ajax.php'),
        'nonce'  => wp_create_nonce('hcf_flows'),
        'manage' => hcf_can_manage(),
        'site'   => sanitize_title(get_bloginfo('name')) ?: 'site',
    ];
    $base = get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/';
    $dir = get_stylesheet_directory() . '/custom-functions/shortcodes/assets/';
    ob_start();
    ?>
    <div class="hcf hfq" id="hfq-root">
        <div class="hcf-bar">
            <input type="search" class="hcf-in" data-hfq-q placeholder="Tìm câu hỏi, câu trả lời, SKU…" aria-label="Tìm FAQ">
            <input type="text" class="hcf-in hcf-in--name" data-hfq-name placeholder="Tên khách (thay {ten})" aria-label="Tên khách">
            <button type="button" class="hcf-btn hcf-btn--pri" data-hfq-new>＋ Thêm FAQ</button>
            <?php if ($cfg['manage']) : ?>
                <button type="button" class="hcf-btn" data-hcb-export="faqs" title="Tải FAQ (JSON) — sao lưu / nhập sang site khác">⤓ Xuất</button>
                <button type="button" class="hcf-btn" data-hcb-export="all" title="Kịch bản + FAQ + tài liệu tham khảo chung trong 1 file — mang sang site khác">⤓ Trọn bộ</button>
                <label class="hcf-btn" title="Nhập file xuất từ site này hoặc site khác (trùng tiêu đề / câu hỏi → cập nhật)">⤒ Nhập<input type="file" accept=".json,application/json" data-hcb-import hidden></label>
            <?php endif; ?>
        </div>
        <p class="hcf-status" role="status" data-hfq-status>Đang tải FAQ…</p>
        <div data-hfq-form-slot></div>
        <div class="hfq-list" data-hfq-list aria-live="polite"></div>
    </div>
    <script type="application/json" id="hfq-cfg"><?php echo wp_json_encode($cfg); ?></script>
    <link rel="stylesheet" href="<?php echo esc_url($base . 'consult-tools.css?v=' . (@filemtime($dir . 'consult-tools.css') ?: '1')); ?>">
    <script src="<?php echo esc_url($base . 'consult-bundle.js?v=' . (@filemtime($dir . 'consult-bundle.js') ?: '1')); ?>" defer></script>
    <script src="<?php echo esc_url($base . 'consult-faqs.js?v=' . (@filemtime($dir . 'consult-faqs.js') ?: '1')); ?>" defer></script>
    <?php

    return (string) ob_get_clean();
}

<?php
defined('ABSPATH') || exit;

/**
 * Kịch bản tư vấn — [consult_flows] (tab Sản phẩm - Tư vấn ở /quan-ly-don).
 *
 * Lưu trong 1 option (không CPT): `hithean_consult_flows` = {next_id, flows: {id: {id, title, group, keywords[], skus[],
 * steps[{title, say, note}], status publish|draft, updated, by}}}, autoload = no. Số kịch bản nhỏ (≤ HCF_MAX_FLOWS) nên đọc / ghi nguyên khối.
 * Mỗi lần sửa / xóa đẩy bản cũ của kịch bản đó vào `hithean_consult_flows_history` (≤ 50 bản) để khôi phục.
 * Câu mẫu nhiều phương án tách bằng dòng `---`; `{ten}` được thay bằng tên khách khi copy.
 * Hàm thuần (hcf_norm / hcf_sanitize_flow / hcf_store_* / hcf_match) không gọi WordPress — test: `php tests/consult-flows.php`.
 * AI tư vấn (shortcode-product-advisor.php) đọc nội dung kịch bản: kịch bản khớp (hcf_match) + mục lục mọi kịch bản đang dùng.
 */

require_once __DIR__ . '/consult-variables.php';

defined('HCF_OPTION') || define('HCF_OPTION', 'hithean_consult_flows');
defined('HCF_HISTORY_OPTION') || define('HCF_HISTORY_OPTION', 'hithean_consult_flows_history');
defined('HCF_MAX_FLOWS') || define('HCF_MAX_FLOWS', 500);

/** Xem / copy kịch bản: nhân viên xử lý đơn. */
function hcf_can_view(): bool
{
    return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
}

/** Thêm / sửa / xóa / nhập kịch bản. */
function hcf_can_manage(): bool
{
    return current_user_can('manage_woocommerce');
}

/** Chuỗi so khớp: chữ thường, bỏ dấu tiếng Việt, chỉ giữ a-z0-9 cách nhau 1 khoảng trắng. */
function hcf_norm(string $text): string
{
    if (class_exists('Normalizer')) {
        $text = (string) Normalizer::normalize($text, Normalizer::FORM_C);
    }
    $text = mb_strtolower($text, 'UTF-8');
    static $map = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
        'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
    ];
    foreach ($map as $to => $chars) {
        $text = (string) preg_replace('/[' . $chars . ']/u', $to, $text);
    }
    $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
    $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

    return trim($text);
}

/** Làm sạch 1 chuỗi văn bản thuần (giữ xuống dòng), cắt độ dài. */
function hcf_clean_text($value, int $max = 4000, bool $multiline = true): string
{
    $text = is_scalar($value) ? (string) $value : '';
    $text = strip_tags(str_replace(["\r\n", "\r"], "\n", $text));
    $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    if (!$multiline) {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
    }
    $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

    return mb_substr($text, 0, $max, 'UTF-8');
}

/** Danh sách chuỗi ngắn (từ khóa / SKU) từ mảng hoặc chuỗi phân tách bằng dấu phẩy / xuống dòng. */
function hcf_clean_list($value, int $max_items = 40, int $max_len = 80): array
{
    $items = is_array($value) ? $value : preg_split('/[,;\n]+/u', is_scalar($value) ? (string) $value : '');
    $out = [];
    foreach ((array) $items as $item) {
        $item = hcf_clean_text($item, $max_len, false);
        if ($item !== '' && !in_array(mb_strtolower($item, 'UTF-8'), array_map('mb_strtolower', $out), true)) {
            $out[] = $item;
        }
        if (count($out) >= $max_items) {
            break;
        }
    }

    return $out;
}

/**
 * Chuẩn hóa dữ liệu 1 kịch bản (từ form / file nhập). Thiếu tiêu đề hoặc không có bước nào có nội dung → steps rỗng
 * (nơi lưu tự báo lỗi). Tối đa 30 bước.
 */
function hcf_sanitize_flow(array $raw): array
{
    $steps = [];
    foreach (array_slice((array) ($raw['steps'] ?? []), 0, 30) as $step) {
        if (!is_array($step)) {
            continue;
        }
        $clean = [
            'title' => hcf_clean_text($step['title'] ?? '', 160, false),
            'say'   => hcf_clean_text($step['say'] ?? '', 4000),
            'note'  => hcf_clean_text($step['note'] ?? '', 1500),
        ];
        if ($clean['title'] !== '' || $clean['say'] !== '') {
            $steps[] = $clean;
        }
    }
    $status = (string) ($raw['status'] ?? 'publish');

    return [
        'id'       => max(0, (int) ($raw['id'] ?? 0)),
        'title'    => hcf_clean_text($raw['title'] ?? '', 200, false),
        'group'    => hcf_clean_text($raw['group'] ?? '', 80, false),
        'keywords' => hcf_clean_list($raw['keywords'] ?? []),
        'skus'     => array_map('strtoupper', hcf_clean_list($raw['skus'] ?? [], 20, 60)),
        'steps'    => $steps,
        'status'   => in_array($status, ['publish', 'draft'], true) ? $status : 'publish',
    ];
}


/** Kho rỗng / chuẩn hóa kho đọc từ option. */
function hcf_store_normalize($raw): array
{
    $store = ['next_id' => 1, 'flows' => []];
    if (!is_array($raw)) {
        return $store;
    }
    foreach ((array) ($raw['flows'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $flow = hcf_sanitize_flow($item);
        if ($flow['id'] > 0 && $flow['title'] !== '') {
            $flow['updated'] = (string) ($item['updated'] ?? '');
            $flow['by'] = (string) ($item['by'] ?? '');
            $store['flows'][$flow['id']] = $flow;
        }
    }
    $store['next_id'] = max((int) ($raw['next_id'] ?? 1), $store['flows'] ? max(array_keys($store['flows'])) + 1 : 1);

    return $store;
}

/**
 * Thêm / sửa 1 kịch bản trong kho (hàm thuần). id = 0 → thêm mới.
 * @return array{0:array,1:int|string,2:?array} [kho mới, id hoặc thông báo lỗi, bản cũ (khi sửa)]
 */
function hcf_store_upsert(array $store, array $raw, string $now, string $by): array
{
    $flow = hcf_sanitize_flow($raw);
    if ($flow['title'] === '') {
        return [$store, 'Nhập nhu cầu / tình huống của khách.', null];
    }
    if (!$flow['steps']) {
        return [$store, 'Kịch bản cần ít nhất 1 bước có nội dung.', null];
    }
    $before = null;
    if ($flow['id']) {
        if (!isset($store['flows'][$flow['id']])) {
            return [$store, 'Kịch bản không còn tồn tại (có thể người khác vừa xóa) — tải lại trang.', null];
        }
        $before = $store['flows'][$flow['id']];
    } else {
        if (count($store['flows']) >= HCF_MAX_FLOWS) {
            return [$store, 'Đã đủ ' . HCF_MAX_FLOWS . ' kịch bản — gộp hoặc xóa bớt.', null];
        }
        $flow['id'] = (int) $store['next_id'];
        $store['next_id'] = $flow['id'] + 1;
    }
    $flow['updated'] = $now;
    $flow['by'] = $by;
    $store['flows'][$flow['id']] = $flow;

    return [$store, $flow['id'], $before];
}

/** Danh sách kịch bản đã sắp: nhóm (trống cuối) → tiêu đề, bỏ dấu. Nháp chỉ khi $include_drafts. */
function hcf_store_list(array $store, bool $include_drafts): array
{
    $flows = array_values(array_filter($store['flows'], static function (array $f) use ($include_drafts): bool {
        return $include_drafts || $f['status'] === 'publish';
    }));
    usort($flows, static function (array $a, array $b): int {
        return [$a['group'] === '' ? 1 : 0, hcf_norm($a['group']), hcf_norm($a['title'])]
            <=> [$b['group'] === '' ? 1 : 0, hcf_norm($b['group']), hcf_norm($b['title'])];
    });

    return $flows;
}

function hcf_store_read(): array
{
    return hcf_store_normalize(get_option(HCF_OPTION, []));
}

/** Ghi kho + đẩy bản cũ vào lịch sử (≤ 50). */
function hcf_store_write(array $store, ?array $before = null, string $action = 'save'): void
{
    update_option(HCF_OPTION, $store, false);
    if ($before) {
        $history = get_option(HCF_HISTORY_OPTION, []);
        $history = is_array($history) ? $history : [];
        array_unshift($history, ['at' => current_time('mysql'), 'by' => hcf_current_user_label(), 'action' => $action, 'flow' => $before]);
        update_option(HCF_HISTORY_OPTION, array_slice($history, 0, 50), false);
    }
}

function hcf_current_user_label(): string
{
    $user = wp_get_current_user();

    return $user && $user->exists() ? (string) $user->display_name : '';
}

/**
 * Nhập nhiều kịch bản (hàm thuần): trùng tiêu đề (bỏ dấu) → cập nhật, còn lại thêm mới.
 * @return array{0:array,1:int,2:int,3:string[]} [kho mới, số thêm, số cập nhật, lỗi]
 */
function hcf_store_import(array $store, array $items, string $now, string $by): array
{
    $by_title = [];
    foreach ($store['flows'] as $flow) {
        $by_title[hcf_norm($flow['title'])] = $flow['id'];
    }
    $created = $updated = 0;
    $errors = [];
    foreach (array_values($items) as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = hcf_norm((string) ($item['title'] ?? ''));
        $item['id'] = $by_title[$key] ?? 0;
        [$store, $result] = hcf_store_upsert($store, $item, $now, $by);
        if (!is_int($result)) {
            $errors[] = '#' . ($i + 1) . ': ' . $result;
            continue;
        }
        $item['id'] ? $updated++ : $created++;
        $by_title[$key] = $result;
    }

    return [$store, $created, $updated, $errors];
}

/** Mọi kịch bản (nháp chỉ cho người quản lý). */
function hcf_get_flows(bool $include_drafts = false): array
{
    return hcf_store_list(hcf_store_read(), $include_drafts);
}

/** Lưu (thêm / sửa) 1 kịch bản. @return int|WP_Error ID */
function hcf_save_flow(array $raw)
{
    [$store, $result, $before] = hcf_store_upsert(hcf_store_read(), $raw, current_time('mysql'), hcf_current_user_label());
    if (!is_int($result)) {
        return new WP_Error('hcf_invalid', $result);
    }
    hcf_store_write($store, $before, 'save');

    return $result;
}


/**
 * Chấm điểm kịch bản theo câu hỏi + SKU: cụm từ khóa xuất hiện nguyên cụm (+2 + số chữ), tiêu đề nằm trong câu (+3),
 * SKU trùng (+3 mỗi mã). Trả tối đa $limit kịch bản điểm > 0, cao trước; mỗi phần tử thêm khóa `score`.
 */
function hcf_match(array $flows, string $text, array $skus = [], int $limit = 3): array
{
    $hay = ' ' . hcf_norm($text) . ' ';
    $skus = array_map(static function ($s) { return strtoupper(trim((string) $s)); }, $skus);
    $scored = [];
    foreach ($flows as $flow) {
        $score = 0;
        foreach ((array) ($flow['keywords'] ?? []) as $kw) {
            $kw = hcf_norm((string) $kw);
            if ($kw !== '' && strpos($hay, ' ' . $kw . ' ') !== false) {
                $score += 2 + substr_count($kw, ' ') + 1;
            }
        }
        $title = hcf_norm((string) ($flow['title'] ?? ''));
        if ($title !== '' && strpos($hay, ' ' . $title . ' ') !== false) {
            $score += 3;
        }
        $score += 3 * count(array_intersect($skus, (array) ($flow['skus'] ?? [])));
        if ($score > 0) {
            $flow['score'] = $score;
            $scored[] = $flow;
        }
    }
    usort($scored, static function (array $a, array $b): int { return $b['score'] <=> $a['score']; });

    return array_slice($scored, 0, max(0, $limit));
}

/** Kịch bản mẫu chung (không gắn SP) — tạo khi người quản lý bấm "Tạo kịch bản mẫu" lúc danh sách trống. */
function hcf_starter_flows(): array
{
    return [
        [
            'title'    => 'Khách chưa rõ nên dùng sản phẩm nào',
            'group'    => 'Tư vấn chung',
            'keywords' => ['nên dùng loại nào', 'tư vấn giúp', 'loại nào phù hợp', 'dùng gì'],
            'steps'    => [
                ['title' => 'Chào & hỏi mục tiêu', 'say' => "Dạ em chào anh/chị {ten} ạ. Để em tư vấn đúng sản phẩm, anh/chị cho em hỏi mình đang muốn cải thiện điều gì nhất ạ (năng lượng, tiêu hóa, giấc ngủ, vóc dáng…)?", 'note' => 'Chưa giới thiệu sản phẩm ở bước này.'],
                ['title' => 'Hỏi thói quen & người dùng', 'say' => "Dạ sản phẩm này anh/chị dùng cho mình hay cho người nhà ạ? Mình bao nhiêu tuổi, có đang mang thai / cho con bú hoặc dùng thuốc gì thường xuyên không ạ?", 'note' => 'Có bệnh nền / đang dùng thuốc → khuyên hỏi ý kiến bác sĩ trước khi dùng.'],
                ['title' => 'Đề xuất 1–2 sản phẩm', 'say' => "Dạ với nhu cầu của anh/chị, em gợi ý [tên SP] vì [lý do theo thông tin SP]. Cách dùng: [HDSD]. Anh/chị muốn em gửi thêm thông tin thành phần không ạ?", 'note' => 'Dùng tab AI tư vấn để lấy đúng thành phần / HDSD theo SKU.'],
                ['title' => 'Chốt đơn', 'say' => "Dạ anh/chị gửi em xin tên, số điện thoại và địa chỉ nhận hàng, em lên đơn ngay cho mình ạ.\n---\nDạ hiện bên em đang có ưu đãi [ưu đãi], anh/chị lấy luôn để được giá tốt nhé ạ."],
            ],
        ],
        [
            'title'    => 'Khách hỏi sản phẩm có chữa được bệnh không',
            'group'    => 'Tuân thủ',
            'keywords' => ['chữa', 'trị bệnh', 'khỏi bệnh', 'có phải thuốc', 'thay thuốc'],
            'steps'    => [
                ['title' => 'Trả lời đúng quy định', 'say' => "Dạ sản phẩm là thực phẩm bổ sung, không phải là thuốc và không có tác dụng thay thế thuốc chữa bệnh ạ. Sản phẩm hỗ trợ bổ sung dinh dưỡng theo thông tin trên nhãn.", 'note' => 'Không cam kết hiệu quả, không dùng từ chữa / trị / khỏi.'],
                ['title' => 'Khuyên hỏi bác sĩ', 'say' => "Nếu anh/chị đang điều trị bệnh hoặc dùng thuốc, mình nên hỏi ý kiến bác sĩ trước khi dùng thêm sản phẩm nhé ạ."],
            ],
        ],
    ];
}

// ─────────────────────────────── AJAX ───────────────────────────────

function hcf_ajax_guard(bool $manage): void
{
    if (!check_ajax_referer('hcf_flows', 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên làm việc hết hạn, tải lại trang.'], 403);
    }
    if (!($manage ? hcf_can_manage() : hcf_can_view())) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
}

add_action('wp_ajax_hithean_cflow_list', 'hcf_ajax_list');
function hcf_ajax_list(): void
{
    hcf_ajax_guard(false);
    wp_send_json_success(['flows' => hcf_get_flows(hcf_can_manage())]);
}

add_action('wp_ajax_hithean_cflow_save', 'hcf_ajax_save');
function hcf_ajax_save(): void
{
    hcf_ajax_guard(true);
    $raw = json_decode((string) wp_unslash($_POST['flow'] ?? ''), true);
    $id = hcf_save_flow(is_array($raw) ? $raw : []);
    if (is_wp_error($id)) {
        wp_send_json_error(['message' => $id->get_error_message()], 400);
    }
    wp_send_json_success(['id' => (int) $id, 'flows' => hcf_get_flows(true)]);
}

add_action('wp_ajax_hithean_cflow_delete', 'hcf_ajax_delete');
function hcf_ajax_delete(): void
{
    hcf_ajax_guard(true);
    $id = absint($_POST['id'] ?? 0);
    $store = hcf_store_read();
    if (!$id || !isset($store['flows'][$id])) {
        wp_send_json_error(['message' => 'Không tìm thấy kịch bản.'], 404);
    }
    $before = $store['flows'][$id];
    unset($store['flows'][$id]);
    hcf_store_write($store, $before, 'delete');
    wp_send_json_success(['flows' => hcf_get_flows(true)]);
}


/** Tạo kịch bản mẫu (starter) hoặc nhập mảng kịch bản — ghi option 1 lần. Nhập file từ giao diện dùng gói chung hithean_consult_import (shortcode-consult-faqs.php). */
add_action('wp_ajax_hithean_cflow_import', 'hcf_ajax_import');
function hcf_ajax_import(): void
{
    hcf_ajax_guard(true);
    $starter = !empty($_POST['starter']);
    $items = $starter ? hcf_starter_flows() : json_decode((string) wp_unslash($_POST['data'] ?? ''), true);
    if (isset($items['flows']) && is_array($items['flows'])) {
        $items = $items['flows'];
    }
    if (!is_array($items) || !$items) {
        wp_send_json_error(['message' => 'File không đúng định dạng (cần mảng kịch bản JSON).'], 400);
    }
    [$store, $created, $updated, $errors] = hcf_store_import(hcf_store_read(), array_slice($items, 0, 300), current_time('mysql'), hcf_current_user_label());
    if ($created || $updated) {
        hcf_store_write($store);
    }
    wp_send_json_success([
        'message' => sprintf('Đã thêm %d, cập nhật %d kịch bản.', $created, $updated) . ($errors ? ' Lỗi: ' . implode('; ', array_slice($errors, 0, 5)) : ''),
        'flows'   => hcf_get_flows(true),
    ]);
}

// ─────────────────────────────── Shortcode ───────────────────────────────

add_shortcode('consult_flows', 'hcf_shortcode');
function hcf_shortcode(): string
{
    if (!is_user_logged_in() || !hcf_can_view()) {
        return '<p>Cần đăng nhập tài khoản nhân viên để xem kịch bản tư vấn.</p>';
    }
    $cfg = [
        'ajax'   => admin_url('admin-ajax.php'),
        'nonce'  => wp_create_nonce('hcf_flows'),
        'manage' => hcf_can_manage(),
        'site'   => sanitize_title(get_bloginfo('name')) ?: 'site',
    ];
    ob_start();
    ?>
    <div class="hcf" id="hcf-root" data-hcf>
        <div class="hcf-bar">
            <input type="search" class="hcf-in" data-hcf-q placeholder="Tìm nhu cầu, từ khóa, câu mẫu, SKU…" aria-label="Tìm kịch bản">
            <select class="hcf-in" data-hcf-group aria-label="Nhóm"><option value="">Mọi nhóm</option></select>
            <input type="text" class="hcf-in hcf-in--name" data-hcf-name placeholder="Tên khách (thay {ten})" aria-label="Tên khách">
            <?php if ($cfg['manage']) : ?>
                <button type="button" class="hcf-btn hcf-btn--pri" data-hcf-new>＋ Thêm kịch bản</button>
                <a class="hcf-btn" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/consult-starter.json'); ?>" download="kich-ban-tu-van-mau.json">⤓ Kịch bản mẫu để sửa</a>
                <button type="button" class="hcf-btn" data-hcb-export="flows" title="Tải kịch bản (JSON) — sao lưu / nhập sang site khác">⤓ Xuất</button>
                <button type="button" class="hcf-btn" data-hcb-export="all" title="Kịch bản + FAQ + tài liệu tham khảo chung trong 1 file — mang sang site khác">⤓ Trọn bộ</button>
                <label class="hcf-btn" title="Nhập file xuất từ site này hoặc site khác (trùng tiêu đề / câu hỏi → cập nhật)">⤒ Nhập<input type="file" accept=".json,application/json" data-hcb-import hidden></label>
            <?php endif; ?>
        </div>
        <p class="hcf-status" role="status" data-hcf-status>Đang tải kịch bản…</p>
        <div class="hcf-body">
            <nav class="hcf-list" data-hcf-list aria-label="Danh sách kịch bản"></nav>
            <section class="hcf-detail" data-hcf-detail aria-live="polite"></section>
        </div>
    </div>
    <script type="application/json" id="hcf-cfg"><?php echo wp_json_encode($cfg); ?></script>
    <?php
    echo hcf_assets();

    return (string) ob_get_clean();
}

/** CSS + JS của [consult_flows] (in 1 lần). */
function hcf_assets(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $base = get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/';
    $dir = get_stylesheet_directory() . '/custom-functions/shortcodes/assets/';
    $ver = static function (string $f) use ($dir): string { return (string) (@filemtime($dir . $f) ?: '1'); };

    return '<link rel="stylesheet" href="' . esc_url($base . 'consult-tools.css?v=' . $ver('consult-tools.css')) . '">'
        . '<script src="' . esc_url($base . 'consult-modal.js?v=' . $ver('consult-modal.js')) . '" defer></script>'
        . '<script src="' . esc_url($base . 'consult-bundle.js?v=' . $ver('consult-bundle.js')) . '" defer></script>'
        . '<script src="' . esc_url($base . 'consult-flows.js?v=' . $ver('consult-flows.js')) . '" defer></script>';
}

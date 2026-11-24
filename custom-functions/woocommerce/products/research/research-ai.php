<?php
/**
 * Hồ sơ khoa học — AI soạn tóm tắt VI/EN (feature `research_summary_ai`, Cài đặt ERP → AI).
 *
 * - Chỉ tóm tắt văn bản / PDF đã có; thiếu nội dung → không gọi AI.
 * - Kết quả vào summary_draft — KHÔNG BAO GIỜ tự đăng (đăng = người có manage_woocommerce duyệt).
 * - Prompt + parse là hàm thuần (tests/research-ai.php).
 */

defined('ABSPATH') || exit;

function hithean_research_ai_config(): array
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    $provider = theme_ai_default_provider();
    return apply_filters('hithean_research_ai_config', [
        'provider' => $provider === 'auto' ? 'gemini' : $provider,
        'model'    => theme_ai_default_model(),
        'pinned'   => false,
    ]);
}

/** Dấu của nội dung gốc: tóm tắt cũ hơn dấu này → cảnh báo "bản nháp cũ hơn nội dung". */
function hithean_research_source_hash(array $row): string
{
    return hash('sha256', (string) $row['source_text'] . '|' . (int) $row['source_file_id']);
}

/** @return array{0:string,1:string} [system, prompt] */
function hithean_research_ai_prompt(array $row): array
{
    $levels = implode(', ', array_keys(hithean_research_evidence_levels()));
    $system = 'Bạn là biên tập viên khoa học của The An Organics (thực phẩm bổ sung). Nhiệm vụ: tóm tắt trung thực MỘT tài liệu '
        . 'cho khách phổ thông bằng tiếng Việt và tiếng Anh. Luật bắt buộc: '
        . '(1) Chỉ dùng nội dung được cung cấp; không thêm số liệu, kết luận hay nguồn khác. '
        . '(2) Nêu thiết kế nghiên cứu, đối tượng / cỡ mẫu, liều, kết quả chính và giới hạn nếu tài liệu có ghi. '
        . '(3) Nói về thành phần / chủ đề được nghiên cứu, KHÔNG nói về sản phẩm cụ thể nào và không nhắc tên thương hiệu. '
        . '(4) Không dùng các từ chữa, điều trị, phòng bệnh, cam kết hiệu quả. '
        . '(5) Nếu nội dung không đủ để tóm tắt (chỉ có tiêu đề, menu trang, nội dung không liên quan) → insufficient = true. '
        . 'Trả về DUY NHẤT một JSON object, không markdown.';
    $schema = '{"insufficient":false,"evidence":"một trong: ' . $levels . '","topic_vi":"thành phần / chủ đề, ≤ 8 từ","topic_en":"…",'
        . '"vi":{"headline":"≤ 15 từ","summary":"2-4 câu","points":["≤ 4 ý ngắn"],"limits":"1-2 câu"},'
        . '"en":{"headline":"…","summary":"…","points":["…"],"limits":"…"}}';
    $info = array_filter([
        'Tiêu đề'   => (string) $row['title'],
        'Tác giả'   => (string) $row['authors'],
        'Nguồn'     => (string) $row['publisher'],
        'Ngày'      => (string) $row['published_on'],
        'DOI'       => (string) $row['doi'],
        'Loại'      => (string) $row['source_type'],
    ]);
    $prompt = "Thông tin tài liệu:\n";
    foreach ($info as $label => $value) {
        $prompt .= "- {$label}: {$value}\n";
    }
    $text = trim((string) $row['source_text']);
    $prompt .= $text !== '' ? "\nNội dung gốc:\n\"\"\"\n{$text}\n\"\"\"\n" : "\nNội dung gốc: xem tệp PDF đính kèm.\n";
    $prompt .= "\nĐịnh dạng JSON:\n{$schema}";
    return [$system, $prompt];
}

/**
 * Kiểm + chuẩn hoá JSON AI trả về.
 *
 * @return array{insufficient:bool,evidence:string,topic:string,topic_en:string,summary:array}
 */
function hithean_research_ai_parse(array $data): array
{
    $summary = hithean_research_normalize_summary($data);
    $evidence = (string) ($data['evidence'] ?? '');
    $topic = static function ($value): string {
        $value = trim(is_scalar($value) ? (string) $value : '');
        return function_exists('mb_substr') ? mb_substr($value, 0, 120) : substr($value, 0, 120);
    };
    return [
        'insufficient' => !empty($data['insufficient']) || !hithean_research_summary_complete($summary),
        'evidence'     => isset(hithean_research_evidence_levels()[$evidence]) ? $evidence : '',
        'topic'        => $topic($data['topic_vi'] ?? ''),
        'topic_en'     => $topic($data['topic_en'] ?? ''),
        'summary'      => $summary,
    ];
}

/**
 * Sinh bản nháp tóm tắt cho một tài liệu.
 *
 * @return true|WP_Error
 */
function hithean_research_ai_generate(int $id)
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
    if (!theme_ai_feature_enabled('research_summary_ai')) {
        return new WP_Error('hithean_research_ai_off', 'Tính năng AI tóm tắt hồ sơ khoa học đang tắt (Cài đặt ERP → AI).');
    }
    $row = hithean_research_get($id);
    if (!$row) {
        return new WP_Error('hithean_research_missing', 'Không tìm thấy tài liệu.');
    }
    $file = (int) $row['source_file_id'] > 0 ? (string) get_attached_file((int) $row['source_file_id']) : '';
    if (hithean_research_strlen((string) $row['source_text']) < HITHEAN_RESEARCH_MIN_TEXT && ($file === '' || !is_readable($file))) {
        return new WP_Error('hithean_research_no_source', 'Chưa đủ nội dung gốc — dán nội dung / transcript hoặc tải PDF trước.');
    }

    [$system, $prompt] = hithean_research_ai_prompt($row);
    $documents = $file !== '' && is_readable($file) ? [['path' => $file, 'mime_type' => 'application/pdf', 'title' => (string) $row['title']]] : [];
    $used = null;
    $raw = theme_ai_feature_call(hithean_research_ai_config(), static function (string $provider, string $model, string $api_key) use ($system, $prompt, $documents) {
        return $documents
            ? theme_ai_call_provider_with_documents($provider, $system, $prompt, $documents, 4000, 150, $model, ['api_key' => $api_key])
            : theme_ai_call_provider($provider, $system, [['role' => 'user', 'content' => $prompt]], 4000, $model, ['api_key' => $api_key]);
    }, $used);
    if (is_wp_error($raw)) {
        error_log('[hithean_research] AI #' . $id . ' lỗi: ' . $raw->get_error_message());
        return $raw;
    }
    $data = theme_ai_parse_json_object((string) $raw);
    if (is_wp_error($data)) {
        return new WP_Error('hithean_research_ai_json', 'AI trả về sai định dạng, thử lại.');
    }
    $result = hithean_research_ai_parse($data);
    if ($result['insufficient']) {
        hithean_research_save($id, ['status' => 'needs_input', 'source_note' => 'AI báo nội dung gốc chưa đủ để tóm tắt — bổ sung abstract / transcript / PDF.']);
        return new WP_Error('hithean_research_ai_insufficient', 'AI báo nội dung gốc chưa đủ để tóm tắt. Thẻ đã chuyển sang "Cần bổ sung".');
    }

    $summary = $result['summary'] + [
        'model'       => trim(($used['provider'] ?? '') . ' ' . ($used['model'] ?? '')),
        'source_hash' => hithean_research_source_hash($row),
        'at'          => current_time('mysql'),
    ];
    $fields = ['summary_draft' => $summary, 'source_note' => ''];
    if ($row['evidence'] === '' && $result['evidence'] !== '') {
        $fields['evidence'] = $result['evidence'];
    }
    if ($row['topic'] === '' && $result['topic'] !== '') {
        $fields['topic'] = $result['topic'];
    }
    if ($row['topic_en'] === '' && $result['topic_en'] !== '') {
        $fields['topic_en'] = $result['topic_en'];
    }
    if (in_array($row['status'], ['inbox', 'needs_input'], true)) {
        $fields['status'] = 'draft';
    }
    return hithean_research_save($id, $fields);
}

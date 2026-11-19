<?php
defined('ABSPATH') || exit;

/**
 * AI tư vấn sản phẩm — [product_advisor_ai] (tab Sản phẩm - Tư vấn ở /quan-ly-don).
 *
 * Nhân viên dán câu hỏi / đoạn chat của khách (+ chọn SKU nếu biết). Server tự gom ngữ cảnh:
 *  1. SKU nhập tay + SKU / tên SP nhận ra trong câu hỏi (hpa_detect_products — so khớp bỏ dấu với danh mục)
 *     → dữ liệu chi tiết từ catalogue + metabox product_info_* (giống snapshot của AI so sánh SP), tối đa HPA_MAX_DETAIL SP;
 *  2. danh mục rút gọn mọi SP đang bán (tên / SKU / giá / còn hàng) để AI gợi ý SP khác;
 *  3. kịch bản tư vấn: nội dung đầy đủ các kịch bản khớp từ khóa / SKU (hcf_match, shortcode-consult-flows.php)
 *     + mục lục mọi kịch bản đang dùng (id, tiêu đề, nhóm, từ khóa) để AI bám đúng bước & giọng công ty.
 *  4. FAQ tư vấn đã lưu khớp câu hỏi (hcf_faq_match) — AI ưu tiên dùng lại; nhân viên cũng thấy FAQ khớp ngay khi gõ (copy không tốn lượt AI);
 *  5. thông tin tham khảo: tài liệu chung (hcf_reference — ship, đổi trả, ưu đãi…) + ô "tham khảo thêm" nhân viên nhập riêng cho câu này.
 * AI cần dữ liệu SP / nội dung kịch bản chưa có → trả need_product_details / need_flow_ids → server tự hỏi lại 1 lượt kèm phần đó.
 * Tùy chọn đọc ảnh nhãn thành phần (dùng lại tpc_compare_ai_nutrition_* của AI so sánh SP).
 * Feature AI: `product_advisor_ai` (Cài đặt ERP → AI). Hàm thuần: hpa_detect_products, hpa_parse_reply — test `php tests/consult-flows.php`.
 */

require_once __DIR__ . '/shortcode-consult-faqs.php'; // kéo theo shortcode-consult-flows.php

defined('HPA_MAX_DETAIL') || define('HPA_MAX_DETAIL', 5);

function hpa_can_use(): bool
{
    return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
}

/**
 * Danh mục rút gọn SP đang bán: [{id, name, sku, skus[] (gồm SKU biến thể), price, stock, url}] — cache 30 phút
 * (file chỉ nạp ở công cụ / AJAX nên không nghe save_post; dữ liệu chi tiết SP luôn đọc trực tiếp).
 */
function hpa_catalogue(): array
{
    $cached = get_transient('hpa_catalogue_v1');
    if (is_array($cached)) {
        return $cached;
    }
    $out = [];
    $ids = wc_get_products(['status' => 'publish', 'limit' => 400, 'return' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product) {
            continue;
        }
        $skus = array_filter([(string) $product->get_sku()]);
        if ($product->is_type('variable')) {
            foreach (array_slice($product->get_children(), 0, 30) as $vid) {
                $sku = (string) get_post_meta($vid, '_sku', true);
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }
        $out[] = [
            'id'    => (int) $id,
            'name'  => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES, 'UTF-8'),
            'sku'   => (string) $product->get_sku(),
            'skus'  => array_values(array_unique($skus)),
            'price' => html_entity_decode(wp_strip_all_tags($product->get_price_html()), ENT_QUOTES, 'UTF-8'),
            'stock' => $product->is_in_stock() ? 'còn hàng' : ($product->backorders_allowed() ? 'đặt trước' : 'hết hàng'),
            'url'   => (string) get_permalink($id),
        ];
    }
    set_transient('hpa_catalogue_v1', $out, 30 * MINUTE_IN_SECONDS);

    return $out;
}

/**
 * Nhận ra SP trong câu hỏi (hàm thuần): SKU (cha hoặc biến thể) xuất hiện nguyên từ; hoặc tên SP — ≥ 2 chữ có nghĩa
 * (≥ 3 ký tự, không phải chữ chung) và phủ ≥ 60% các chữ đó. Trả ID SP, khớp SKU trước, theo độ phủ giảm dần.
 */
function hpa_detect_products(string $text, array $catalogue, int $limit = HPA_MAX_DETAIL): array
{
    $hay = ' ' . hcf_norm($text) . ' ';
    if (trim($hay) === '') {
        return [];
    }
    static $stop = ['vien', 'uong', 'bot', 'goi', 'hop', 'chai', 'lo', 'loai', 'san', 'pham', 'cho', 'va', 'voi', 'the', 'and', 'for', 'new', 'vi', 'huong'];
    $by_sku = [];
    $by_name = [];
    foreach ($catalogue as $item) {
        foreach ((array) ($item['skus'] ?? []) as $sku) {
            $sku = hcf_norm((string) $sku);
            if (strlen($sku) >= 3 && strpos($hay, ' ' . $sku . ' ') !== false) {
                $by_sku[(int) $item['id']] = true;
                continue 2;
            }
        }
        $words = array_unique(array_filter(explode(' ', hcf_norm((string) ($item['name'] ?? ''))), static function ($w) use ($stop) {
            return strlen($w) >= 3 && !in_array($w, $stop, true) && !ctype_digit($w);
        }));
        if (count($words) < 2) {
            continue;
        }
        $hit = 0;
        foreach ($words as $w) {
            if (strpos($hay, ' ' . $w . ' ') !== false) {
                $hit++;
            }
        }
        $cover = $hit / count($words);
        if ($hit >= 2 && $cover >= 0.6) {
            $by_name[(int) $item['id']] = $cover;
        }
    }
    arsort($by_name);
    $ids = array_merge(array_keys($by_sku), array_keys($by_name));

    return array_slice(array_values(array_unique($ids)), 0, $limit);
}

/** SKU (cha hoặc biến thể) → ID SP cha, theo danh mục. */
function hpa_ids_from_skus(array $skus, array $catalogue): array
{
    $want = array_filter(array_map(static function ($s) { return hcf_norm((string) $s); }, $skus));
    $ids = [];
    foreach ($catalogue as $item) {
        foreach ((array) $item['skus'] as $sku) {
            if (in_array(hcf_norm((string) $sku), $want, true)) {
                $ids[] = (int) $item['id'];
                break;
            }
        }
    }

    return array_values(array_unique($ids));
}

/** Văn bản thuần từ HTML / shortcode metabox, cắt độ dài. */
function hpa_plain($html, int $max): string
{
    $text = is_string($html) ? strip_shortcodes($html) : '';
    $text = wp_strip_all_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", $text));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = trim((string) preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));

    return mb_substr($text, 0, $max, 'UTF-8');
}

/** Dữ liệu chi tiết 1 SP cho AI (catalogue + metabox product_info_*), không có đường dẫn file / dữ liệu nội bộ. */
function hpa_product_snapshot(int $product_id): array
{
    $product = wc_get_product($product_id);
    if (!$product || get_post_status($product_id) !== 'publish') {
        return [];
    }
    $attributes = [];
    foreach ((array) $product->get_attributes() as $attribute) {
        if (!is_a($attribute, 'WC_Product_Attribute')) {
            continue;
        }
        $values = $attribute->is_taxonomy()
            ? wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names'])
            : $attribute->get_options();
        if ($values) {
            $attributes[wc_attribute_label($attribute->get_name())] = implode(', ', array_map('sanitize_text_field', (array) $values));
        }
    }
    $variations = [];
    if ($product->is_type('variable')) {
        foreach (array_slice($product->get_children(), 0, 12) as $vid) {
            $var = wc_get_product($vid);
            if (!$var || !$var->variation_is_visible()) {
                continue;
            }
            $variations[] = [
                'name'  => wp_strip_all_tags(wc_get_formatted_variation($var, true, false, false)),
                'sku'   => $var->get_sku(),
                'price' => html_entity_decode(wp_strip_all_tags($var->get_price_html()), ENT_QUOTES, 'UTF-8'),
                'stock' => $var->is_in_stock() ? 'còn hàng' : ($var->backorders_allowed() ? 'đặt trước' : 'hết hàng'),
            ];
        }
    }
    $meta = static function (string $key, int $max) use ($product_id): string {
        return hpa_plain((string) get_post_meta($product_id, 'product_info_' . $key, true), $max);
    };

    return array_filter([
        'id'                => $product_id,
        'name'              => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES, 'UTF-8'),
        'sku'               => $product->get_sku(),
        'price'             => html_entity_decode(wp_strip_all_tags($product->get_price_html()), ENT_QUOTES, 'UTF-8'),
        'stock'             => $product->is_in_stock() ? 'còn hàng' : ($product->backorders_allowed() ? 'đặt trước' : 'hết hàng'),
        'url'               => get_permalink($product_id),
        'categories'        => wp_strip_all_tags(wc_get_product_category_list($product_id, ', ')),
        'usp'               => $meta('subheading', 600),
        'short_description' => hpa_plain($product->get_short_description(), 1500),
        'description'       => hpa_plain($product->get_description(), 3000),
        'usage'             => $meta('hdsd', 2000),
        'ingredients'       => $meta('thanh_phan', 2500),
        'faq'               => $meta('faq', 2500),
        'attributes'        => $attributes,
        'variations'        => $variations,
    ], static function ($v) { return $v !== '' && $v !== []; });
}

function hpa_config(): array
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    $provider = theme_ai_default_provider();

    return apply_filters('hpa_ai_config', [
        'provider' => $provider === 'auto' ? 'gemini' : $provider,
        'model'    => theme_ai_default_model(),
        'pinned'   => false,
    ]);
}

function hpa_system_prompt(string $length): string
{
    $len = ['short' => 'ngắn gọn 2–4 câu', 'long' => 'đầy đủ, có thể 2–3 đoạn ngắn'][$length] ?? 'vừa phải 1–2 đoạn ngắn';

    return 'Bạn là trợ lý của nhân viên tư vấn bán hàng chat Messenger / Zalo của ' . get_bloginfo('name') . ' (thực phẩm bổ sung, thực phẩm dinh dưỡng). '
        . 'Nhiệm vụ: soạn sẵn tin nhắn trả lời khách để nhân viên kiểm tra rồi gửi. '
        . 'Quy tắc bắt buộc: chỉ dùng thông tin trong DỮ LIỆU SẢN PHẨM và KỊCH BẢN được cung cấp; không bịa thành phần, công dụng, giá, ưu đãi, thời gian giao hàng. '
        . 'Không chẩn đoán, không nói sản phẩm chữa / trị / khỏi bệnh hay thay thế thuốc; khách có bệnh nền, đang dùng thuốc, mang thai / cho con bú → khuyên hỏi ý kiến bác sĩ. '
        . 'THÔNG TIN THAM KHẢO (tài liệu chung + nhân viên nhập thêm) là dữ liệu đáng tin, dùng khi liên quan; phần nhân viên nhập thêm được ưu tiên nếu mâu thuẫn. '
        . 'Có FAQ ĐÃ LƯU khớp câu hỏi thì ưu tiên dùng lại nội dung đó (chỉnh cho hợp ngữ cảnh). '
        . 'Biến động trong kịch bản đã được tra theo site hiện tại; trường [DỮ LIỆU CHƯA CÓ] hoặc missing_variables không được đoán hay đưa vào tin nhắn, cần nhắc nhân viên kiểm tra. '
        . 'Có KỊCH BẢN khớp nhu cầu thì đi theo đúng bước và giọng văn của kịch bản (bước hiện tại tùy ngữ cảnh hội thoại). '
        . 'Thiếu thông tin để tư vấn đúng (mục tiêu, người dùng, tình trạng…) thì hỏi lại khách thay vì đoán. '
        . 'Giọng: xưng "em", gọi khách "anh/chị" (hoặc theo ngữ cảnh), lịch sự, tự nhiên như chat, tiếng Việt có dấu, không markdown, độ dài ' . $len . '. '
        . 'Trả về DUY NHẤT JSON hợp lệ: {"need_product_details": [SKU cần xem dữ liệu chi tiết mà chưa có trong DỮ LIỆU SẢN PHẨM — chỉ khi thật cần để trả lời; ngược lại để []], '
        . '"need_flow_ids": [id trong MỤC LỤC KỊCH BẢN hợp với tình huống mà chưa có nội dung ở KỊCH BẢN TƯ VẤN KHỚP; ngược lại để []], '
        . '"reply": "tin nhắn gửi khách", "follow_up_questions": ["câu nên hỏi thêm khách", ...tối đa 3], '
        . '"staff_notes": ["lưu ý nội bộ cho nhân viên: dữ liệu thiếu cần kiểm tra, rủi ro tuân thủ, gợi ý bán kèm…", ...tối đa 4], '
        . '"suggested_skus": ["SKU nên giới thiệu, lấy từ DANH MỤC", ...tối đa 3], "flow_id": ID kịch bản đã dùng hoặc 0}.';
}

/**
 * Đọc JSON AI trả về (hàm thuần): ép kiểu, cắt độ dài, bỏ phần tử rỗng.
 * @return array{need_product_details:string[],reply:string,follow_up_questions:string[],staff_notes:string[],suggested_skus:string[],flow_id:int}
 */
function hpa_parse_reply(array $data): array
{
    $list = static function ($v, int $max, int $len): array {
        $out = [];
        foreach (is_array($v) ? $v : [] as $item) {
            $item = hcf_clean_text($item, $len);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, $max);
    };

    return [
        'need_product_details' => $list($data['need_product_details'] ?? [], 4, 60),
        'need_flow_ids'        => array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($data['need_flow_ids'] ?? null) ? $data['need_flow_ids'] : []), static function (int $id): bool { return $id > 0; }))), 0, 2),
        'reply'                => hcf_clean_text($data['reply'] ?? '', 4000),
        'follow_up_questions'  => $list($data['follow_up_questions'] ?? [], 3, 400),
        'staff_notes'          => $list($data['staff_notes'] ?? [], 4, 600),
        'suggested_skus'       => $list($data['suggested_skus'] ?? [], 3, 60),
        'flow_id'              => max(0, (int) ($data['flow_id'] ?? 0)),
    ];
}

/** Gọi AI 1 lượt với ngữ cảnh đã gom. @return array|WP_Error */
function hpa_call_ai(array $ctx)
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
    $prompt = '';
    if ($ctx['history']) {
        $prompt .= "HỘI THOẠI TRƯỚC (đã trả lời):\n";
        foreach ($ctx['history'] as $turn) {
            $prompt .= '- Khách: ' . $turn['q'] . "\n- Em đã trả lời: " . $turn['a'] . "\n";
        }
        $prompt .= "\n";
    }
    $prompt .= "CÂU HỎI / TIN NHẮN MỚI CỦA KHÁCH:\n" . $ctx['question'] . "\n\n";
    if ($ctx['reference'] !== '') {
        $prompt .= "THÔNG TIN THAM KHẢO CHUNG:\n" . $ctx['reference'] . "\n\n";
    }
    if ($ctx['extra'] !== '') {
        $prompt .= "THÔNG TIN THAM KHẢO NHÂN VIÊN NHẬP THÊM CHO CÂU NÀY (không gửi nguyên văn cho khách):\n" . $ctx['extra'] . "\n\n";
    }
    if ($ctx['faqs']) {
        $prompt .= "FAQ ĐÃ LƯU KHỚP CÂU HỎI:\n" . wp_json_encode($ctx['faqs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    $prompt .= "DỮ LIỆU SẢN PHẨM (chi tiết):\n" . ($ctx['products'] ? wp_json_encode($ctx['products'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(chưa có — nếu cần, liệt kê SKU ở need_product_details)') . "\n\n";
    $prompt .= "KỊCH BẢN TƯ VẤN KHỚP (nội dung đầy đủ):\n" . ($ctx['flows'] ? wp_json_encode($ctx['flows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(không có)') . "\n\n";
    if ($ctx['flow_index']) {
        $prompt .= "MỤC LỤC KỊCH BẢN (chỉ tiêu đề — cần nội dung thì ghi id vào need_flow_ids):\n" . wp_json_encode($ctx['flow_index'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    $prompt .= "DANH MỤC SẢN PHẨM ĐANG BÁN (rút gọn):\n" . wp_json_encode($ctx['catalogue'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $system = hpa_system_prompt($ctx['length']);
    $documents = $ctx['documents'];
    $used = null;
    $raw = theme_ai_feature_call(hpa_config(), static function (string $provider, string $model, string $api_key) use ($system, $prompt, $documents) {
        return $documents
            ? theme_ai_call_provider_with_documents($provider, $system, $prompt, $documents, 3000, 120, $model, ['api_key' => $api_key])
            : theme_ai_call_provider($provider, $system, [['role' => 'user', 'content' => $prompt]], 3000, $model, ['api_key' => $api_key]);
    }, $used);
    if (is_wp_error($raw)) {
        return $raw;
    }
    $data = theme_ai_parse_json_object((string) $raw);
    if (is_wp_error($data)) {
        return $data;
    }
    $parsed = hpa_parse_reply($data);
    $parsed['model'] = trim(($used['provider'] ?? '') . ' ' . ($used['model'] ?? ''));

    return $parsed;
}

/** Ảnh nhãn thành phần của các SP (tái dùng logic AI so sánh SP: chỉ ảnh trong Media Library khai ở metabox, ≤ 3MB, ≤ 8 ảnh). */
function hpa_label_documents(array $product_ids): array
{
    if (!function_exists('tpc_compare_ai_nutrition_documents')) {
        require_once __DIR__ . '/product-compare-ai.php';
    }
    $snapshot = [];
    foreach ($product_ids as $id) {
        $snapshot[] = ['name' => get_the_title($id), 'nutrition_label_sources' => tpc_compare_ai_nutrition_label_sources((int) $id)];
    }

    return tpc_compare_ai_nutrition_documents($snapshot);
}

add_action('wp_ajax_hithean_advisor_ask', 'hpa_ajax_ask');
function hpa_ajax_ask(): void
{
    if (!check_ajax_referer('hpa_advisor', 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên làm việc hết hạn, tải lại trang.'], 403);
    }
    if (!hpa_can_use()) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('product_advisor_ai')) {
        wp_send_json_error(['message' => 'AI tư vấn đang tắt (Cài đặt ERP → AI).'], 403);
    }
    $question = hcf_clean_text(wp_unslash($_POST['question'] ?? ''), 4000);
    if (mb_strlen($question, 'UTF-8') < 3) {
        wp_send_json_error(['message' => 'Nhập câu hỏi / tin nhắn của khách.'], 400);
    }
    // ≤ 10 lượt / phút / người — tránh bấm liên tục làm cạn hạn mức AI.
    $rl_key = 'hpa_rl_' . get_current_user_id();
    $hits = (int) get_transient($rl_key);
    if ($hits >= 10) {
        wp_send_json_error(['message' => 'Hỏi quá nhanh, thử lại sau 1 phút.'], 429);
    }
    set_transient($rl_key, $hits + 1, MINUTE_IN_SECONDS);

    $history = [];
    foreach (array_slice((array) json_decode((string) wp_unslash($_POST['history'] ?? '[]'), true), -3) as $turn) {
        if (is_array($turn)) {
            $history[] = ['q' => hcf_clean_text($turn['q'] ?? '', 1500), 'a' => hcf_clean_text($turn['a'] ?? '', 2000)];
        }
    }
    $skus = hcf_clean_list(wp_unslash($_POST['skus'] ?? ''), 10, 60);
    $extra = hcf_clean_text(wp_unslash($_POST['extra'] ?? ''), 6000);
    $reference = hcf_reference()['text'];
    $length = sanitize_key(wp_unslash($_POST['length'] ?? 'medium'));
    $labels = !empty($_POST['labels']);

    $catalogue = hpa_catalogue();
    $context_text = $question . "\n" . implode("\n", array_column($history, 'q'));
    $manual_ids = hpa_ids_from_skus($skus, $catalogue);
    $ids = array_slice(array_values(array_unique(array_merge($manual_ids, hpa_detect_products($context_text, $catalogue)))), 0, HPA_MAX_DETAIL);

    // Cùng câu hỏi + ngữ cảnh + SP trong 30 phút → trả lại kết quả cũ, không tốn lượt AI.
    $cache_key = 'hpa_ans_' . md5((string) wp_json_encode([$question, $history, $ids, $length, $labels, $extra, md5($reference)]));
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        wp_send_json_success($cached + ['cached' => true]);
    }

    $flows_all = hcf_get_flows(false);
    $faq_items = array_values(hcf_faq_read()['items']);
    $build = static function (array $ids, array $extra_flow_ids = []) use ($question, $history, $length, $labels, $catalogue, $flows_all, $faq_items, $context_text, $extra, $reference, $skus): array {
        $products = array_values(array_filter(array_map('hpa_product_snapshot', $ids)));
        $flow_skus = array_column($products, 'sku');
        $picked = hcf_match($flows_all, $context_text, $flow_skus, 3);
        foreach ($flows_all as $f) {
            if (in_array($f['id'], $extra_flow_ids, true) && !in_array($f['id'], array_column($picked, 'id'), true)) {
                $picked[] = $f;
            }
        }
        $flows = array_map(static function (array $f): array {
            return hcf_variable_ai_flow(['id' => $f['id'], 'title' => $f['title'], 'group' => $f['group'], 'steps' => $f['steps']]);
        }, $picked);
        $picked_ids = array_column($flows, 'id');
        $flow_index = [];
        foreach (array_slice($flows_all, 0, 200) as $f) {
            if (!in_array($f['id'], $picked_ids, true)) {
                $flow_index[] = ['id' => $f['id'], 'title' => $f['title'], 'group' => $f['group'], 'keywords' => implode(', ', array_slice($f['keywords'], 0, 8))];
            }
        }

        return [
            'question'  => $question,
            'history'   => $history,
            'length'    => $length,
            'products'  => $products,
            'flows'     => $flows,
            'flow_index' => $flow_index,
            'faqs'      => array_map(static function (array $f): array {
                return ['id' => $f['id'], 'q' => $f['q'], 'a' => $f['a']];
            }, hcf_faq_match($faq_items, $question, array_merge($skus, $flow_skus), 3)),
            'extra'     => $extra,
            'reference' => $reference,
            'catalogue' => array_map(static function (array $c): array {
                return ['sku' => $c['sku'] ?: ($c['skus'][0] ?? ''), 'name' => $c['name'], 'price' => $c['price'], 'stock' => $c['stock']];
            }, array_slice($catalogue, 0, 300)),
            'documents' => $labels && $ids ? hpa_label_documents($ids) : [],
        ];
    };

    $ctx = $build($ids);
    $answer = hpa_call_ai($ctx);
    // Lượt 2 (tối đa 1 lần): AI cần dữ liệu SP / nội dung kịch bản chưa có → bổ sung rồi hỏi lại.
    if (!is_wp_error($answer) && ($answer['need_product_details'] || $answer['need_flow_ids'])) {
        $more = array_diff(hpa_ids_from_skus($answer['need_product_details'], $catalogue), $ids);
        $more_flows = array_values(array_intersect($answer['need_flow_ids'], array_column($ctx['flow_index'], 'id')));
        if ($more || $more_flows) {
            $ids = array_slice(array_values(array_unique(array_merge($ids, $more))), 0, HPA_MAX_DETAIL);
            $ctx = $build($ids, $more_flows);
            $retry = hpa_call_ai($ctx);
            if (!is_wp_error($retry)) {
                $answer = $retry;
            }
        }
    }
    if (is_wp_error($answer)) {
        wp_send_json_error(['message' => 'AI lỗi: ' . $answer->get_error_message()], 502);
    }
    if ($answer['reply'] === '') {
        wp_send_json_error(['message' => 'AI chưa soạn được câu trả lời, thử diễn đạt lại câu hỏi.'], 502);
    }

    $by_id = array_column($catalogue, null, 'id');
    $chip = static function (int $id) use ($by_id): array {
        $c = $by_id[$id] ?? null;
        return $c ? ['id' => $id, 'sku' => $c['sku'] ?: ($c['skus'][0] ?? ''), 'name' => $c['name'], 'url' => $c['url'], 'stock' => $c['stock']] : [];
    };
    $suggested = array_diff(hpa_ids_from_skus($answer['suggested_skus'], $catalogue), $ids);
    $result = [
        'reply'               => $answer['reply'],
        'follow_up_questions' => $answer['follow_up_questions'],
        'staff_notes'         => $answer['staff_notes'],
        'products'            => array_values(array_filter(array_map($chip, $ids))),
        'suggested'           => array_values(array_filter(array_map($chip, $suggested))),
        'flows'               => array_map(static function (array $f) use ($answer): array {
            return ['id' => $f['id'], 'title' => $f['title'], 'used' => $f['id'] === $answer['flow_id']];
        }, $ctx['flows']),
        'faqs'                => $ctx['faqs'],
        'labels_read'         => count($ctx['documents']),
        'model'               => $answer['model'],
    ];
    set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);
    wp_send_json_success($result);
}

add_shortcode('product_advisor_ai', 'hpa_shortcode');
function hpa_shortcode(): string
{
    if (!is_user_logged_in() || !hpa_can_use()) {
        return '<p>Cần đăng nhập tài khoản nhân viên để dùng AI tư vấn.</p>';
    }
    $cfg = [
        'ajax'      => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('hpa_advisor'),
        'flowNonce' => wp_create_nonce('hcf_flows'),
        'manage'    => hcf_can_manage(),
        'reference' => hcf_reference(),
        'catalogue' => array_map(static function (array $c): array {
            return ['sku' => $c['sku'] ?: ($c['skus'][0] ?? ''), 'name' => $c['name']];
        }, array_values(array_filter(hpa_catalogue(), static function (array $c): bool { return (bool) $c['skus']; }))),
    ];
    ob_start();
    ?>
    <div class="hpa" id="hpa-root">
        <form class="hpa-form" data-hpa-form>
            <label class="hpa-lbl" for="hpa-q">Câu hỏi / tin nhắn của khách <small>(dán cả đoạn chat được)</small></label>
            <textarea id="hpa-q" class="hcf-in hpa-q" name="question" rows="4" required placeholder="VD: Chị đang cho con bú dùng được viên ANC Prenatal không em? Uống ngày mấy viên?"></textarea>
            <div class="hpa-faq" data-hpa-faq hidden></div>
            <details class="hpa-extra">
                <summary>📎 Thông tin tham khảo thêm cho câu này <small>(AI đọc, không gửi nguyên văn cho khách)</small></summary>
                <textarea class="hcf-in hpa-q" name="extra" rows="3" placeholder="VD: khách đã mua đơn #1234 tháng trước; đang có ưu đãi mua 2 tặng 1; khách dị ứng đậu nành…"></textarea>
            </details>
            <div class="hpa-row">
                <div class="hpa-skus">
                    <label class="hpa-lbl" for="hpa-sku">Sản phẩm liên quan <small>(không bắt buộc — hệ thống tự nhận SKU / tên trong câu hỏi)</small></label>
                    <div class="hpa-chips" data-hpa-chips></div>
                    <input id="hpa-sku" class="hcf-in" list="hpa-cat" placeholder="Gõ SKU hoặc tên SP rồi Enter" data-hpa-sku autocomplete="off">
                    <datalist id="hpa-cat"></datalist>
                </div>
                <div class="hpa-opts">
                    <label>Độ dài <select class="hcf-in" name="reply_length"><option value="short">Ngắn</option><option value="medium" selected>Vừa</option><option value="long">Chi tiết</option></select></label>
                    <label title="Gửi kèm ảnh nhãn thành phần / dinh dưỡng (khai ở metabox SP) cho AI đọc — chậm hơn"><input type="checkbox" name="labels"> Đọc ảnh nhãn</label>
                </div>
            </div>
            <div class="hpa-actions">
                <button type="submit" class="hcf-btn hcf-btn--pri" data-hpa-ask>✨ Soạn câu trả lời</button>
                <label class="hpa-keep" title="Gửi kèm tối đa 3 lượt hỏi–đáp trước để AI hiểu ngữ cảnh"><input type="checkbox" data-hpa-keep checked> Giữ ngữ cảnh hội thoại <b data-hpa-turns></b></label>
                <button type="button" class="hcf-btn" data-hpa-reset>🧹 Hội thoại mới</button>
            </div>
        </form>
        <details class="hpa-ref" data-hpa-ref>
            <summary>📚 Tài liệu tham khảo chung <small>(AI luôn đọc: chính sách giao hàng, đổi trả, ưu đãi đang chạy, cách xưng hô…)</small></summary>
            <textarea class="hcf-in hpa-q" rows="8" data-hpa-ref-text <?php echo hcf_can_manage() ? '' : 'readonly'; ?> placeholder="Mỗi mục 1 đoạn. VD: Phí ship: miễn phí đơn từ 500k… Đổi trả: trong 7 ngày nếu…"></textarea>
            <p class="hpa-meta" data-hpa-ref-meta></p>
            <?php if (hcf_can_manage()) : ?><button type="button" class="hcf-btn" data-hpa-ref-save>💾 Lưu tài liệu</button><?php endif; ?>
        </details>
        <p class="hcf-status" role="status" data-hpa-status></p>
        <section class="hpa-out" data-hpa-out hidden aria-live="polite"></section>
    </div>
    <script type="application/json" id="hpa-cfg"><?php echo wp_json_encode($cfg); ?></script>
    <?php
    $base = get_stylesheet_directory_uri() . '/custom-functions/shortcodes/assets/';
    $dir = get_stylesheet_directory() . '/custom-functions/shortcodes/assets/';
    echo '<link rel="stylesheet" href="' . esc_url($base . 'consult-tools.css?v=' . (@filemtime($dir . 'consult-tools.css') ?: '1')) . '">';
    echo '<script src="' . esc_url($base . 'consult-modal.js?v=' . (@filemtime($dir . 'consult-modal.js') ?: '1')) . '" defer></script>';
    echo '<script src="' . esc_url($base . 'product-advisor.js?v=' . (@filemtime($dir . 'product-advisor.js') ?: '1')) . '" defer></script>';

    return (string) ob_get_clean();
}

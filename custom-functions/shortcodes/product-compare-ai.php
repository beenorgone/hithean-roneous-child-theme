<?php
defined('ABSPATH') || exit;

/**
 * Versioned AI copy for the public product comparison table.
 *
 * This stays separate from the shortcode renderer so persistence, authorization,
 * and the provider call remain small, testable server-side units.
 */
defined('TPC_COMPARE_AI_DB_VERSION') || define('TPC_COMPARE_AI_DB_VERSION', '1.0.0');
defined('TPC_COMPARE_AI_DB_OPTION') || define('TPC_COMPARE_AI_DB_OPTION', 'tpc_compare_ai_db_version');

function tpc_compare_ai_table_name(): string
{
    global $wpdb;
    return $wpdb->prefix . 'tpc_compare_ai_versions';
}

function tpc_compare_ai_can_manage(): bool
{
    return is_user_logged_in() && (current_user_can('manage_options') || current_user_can('manage_woocommerce'));
}

function tpc_compare_ai_normalize_product_ids($raw_ids): array
{
    if (is_array($raw_ids)) {
        $raw_ids = implode(',', $raw_ids);
    }
    $raw_ids = is_string($raw_ids) ? $raw_ids : '';
    $ids = array_map('absint', preg_split('/[\s,-]+/', $raw_ids));
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

    return count($ids) >= 2 && count($ids) <= 5 ? $ids : [];
}

function tpc_compare_ai_comparison_key(array $product_ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
    sort($ids, SORT_NUMERIC);
    return hash('sha256', implode('-', $ids));
}

function tpc_compare_ai_maybe_install_table(): void
{
    if ((string) get_option(TPC_COMPARE_AI_DB_OPTION, '') === TPC_COMPARE_AI_DB_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = tpc_compare_ai_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        comparison_key char(64) NOT NULL,
        product_ids text NOT NULL,
        product_snapshot longtext NOT NULL,
        source_hash char(64) NOT NULL,
        conclusion longtext NOT NULL,
        neutral_analysis longtext NOT NULL,
        status varchar(16) NOT NULL DEFAULT 'draft',
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
        published_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        published_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY comparison_status (comparison_key, status),
        KEY comparison_updated (comparison_key, updated_at)
    ) {$charset_collate};";
    dbDelta($sql);
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists === $table) {
        update_option(TPC_COMPARE_AI_DB_OPTION, TPC_COMPARE_AI_DB_VERSION, false);
    }
}

function tpc_compare_ai_table_is_ready(): bool
{
    return (string) get_option(TPC_COMPARE_AI_DB_OPTION, '') === TPC_COMPARE_AI_DB_VERSION;
}

function tpc_compare_ai_verify_ajax(bool $manage = false): void
{
    tpc_compare_verify_ajax_request();
    if ($manage && !tpc_compare_ai_can_manage()) {
        wp_send_json_error(['message' => 'Bạn không có quyền dùng So sánh bằng AI.'], 403);
    }
}

function tpc_compare_ai_clean_text($value, int $max_length = 6000): string
{
    $value = sanitize_textarea_field(wp_unslash((string) $value));
    return mb_substr(trim($value), 0, $max_length);
}

function tpc_compare_ai_format_text(string $text): string
{
    return wpautop(esc_html($text));
}

function tpc_compare_ai_format_version(array $row, bool $include_private = false): array
{
    $created_at = !empty($row['created_at']) ? strtotime($row['created_at'] . ' UTC') : 0;
    $published_at = !empty($row['published_at']) ? strtotime($row['published_at'] . ' UTC') : 0;
    $item = [
        'id'                    => absint($row['id'] ?? 0),
        'status'                => (string) ($row['status'] ?? 'draft'),
        'comparison_key'        => (string) ($row['comparison_key'] ?? ''),
        'conclusion_html'       => tpc_compare_ai_format_text((string) ($row['conclusion'] ?? '')),
        'neutral_analysis_html' => tpc_compare_ai_format_text((string) ($row['neutral_analysis'] ?? '')),
        'compared_label'        => $created_at ? wp_date('d/m/Y H:i', $created_at) : '',
        'published_label'       => $published_at ? wp_date('d/m/Y H:i', $published_at) : '',
    ];

    if ($include_private) {
        $item += [
            'conclusion'       => (string) ($row['conclusion'] ?? ''),
            'neutral_analysis' => (string) ($row['neutral_analysis'] ?? ''),
            'created_at'       => (string) ($row['created_at'] ?? ''),
            'updated_at'       => (string) ($row['updated_at'] ?? ''),
            'published_at'     => (string) ($row['published_at'] ?? ''),
        ];
    }

    return $item;
}

function tpc_compare_ai_read_public_version(array $product_ids): ?array
{
    if (!tpc_compare_ai_table_is_ready()) {
        return null;
    }

    $ids = tpc_compare_ai_normalize_product_ids($product_ids);
    if (!$ids) {
        return null;
    }

    $key = tpc_compare_ai_comparison_key($ids);
    $cache_key = 'public:' . $key;
    $cached = wp_cache_get($cache_key, 'tpc_product_compare_ai');
    if ($cached !== false) {
        return is_array($cached) ? $cached : null;
    }

    global $wpdb;
    $table = tpc_compare_ai_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE comparison_key = %s AND status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1",
        $key
    ), ARRAY_A);
    $version = is_array($row) ? tpc_compare_ai_format_version($row) : null;
    wp_cache_set($cache_key, $version ?: 0, 'tpc_product_compare_ai', HOUR_IN_SECONDS);

    return $version;
}

function tpc_compare_ai_invalidate_public_cache(string $comparison_key): void
{
    wp_cache_delete('public:' . $comparison_key, 'tpc_product_compare_ai');
}

/**
 * Returns only images deliberately entered in the product's nutrition-label
 * field. Product galleries are deliberately excluded: they frequently contain
 * lifestyle and pack-shot images, not a legible ingredient/nutrition panel.
 *
 * @return array<int,array{title:string,attachment_id:int,fingerprint:string}>
 */
function tpc_compare_ai_nutrition_label_sources(int $product_id): array
{
    $csv = trim((string) get_post_meta($product_id, 'product_nutrition_label_csv', true));
    if ($csv === '') {
        return [];
    }

    $sources = [];
    foreach (preg_split('/\R/', $csv) as $line) {
        $row = str_getcsv(trim((string) $line));
        if (count($row) < 2) {
            continue;
        }

        $url = esc_url_raw(trim((string) $row[1]));
        if ($url === '') {
            continue;
        }

        // Never download an arbitrary URL during an AI request. An image must
        // be a local Media Library attachment explicitly referenced by this
        // product's nutrition-label field.
        $attachment_id = attachment_url_to_postid($url);
        $path = $attachment_id ? get_attached_file($attachment_id) : '';
        $mime = $attachment_id ? get_post_mime_type($attachment_id) : '';
        if (!$attachment_id || !$path || !is_readable($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            continue;
        }

        $sources[] = [
            'title'         => sanitize_text_field(trim((string) $row[0])) ?: 'Bảng thành phần / dinh dưỡng',
            'attachment_id' => (int) $attachment_id,
            // Include a content-change marker in the saved snapshot/source
            // hash without exposing a filesystem path to the model.
            'fingerprint'   => hash('sha256', $attachment_id . '|' . (string) @filemtime($path) . '|' . (string) @filesize($path)),
        ];

        // A product usually has one ingredients panel and one nutrition panel.
        // Cap the source at two to keep the synchronous admin request bounded.
        if (count($sources) >= 2) {
            break;
        }
    }

    return $sources;
}

/**
 * Build provider documents from the explicit nutrition-label sources in the
 * snapshot. The limits protect PHP memory and the outbound request payload.
 *
 * @return array<int,array{path:string,mime_type:string,title:string}>
 */
function tpc_compare_ai_nutrition_documents(array $snapshot): array
{
    $documents = [];

    foreach ($snapshot as $item) {
        $product_name = sanitize_text_field((string) ($item['name'] ?? 'Sản phẩm'));
        foreach ((array) ($item['nutrition_label_sources'] ?? []) as $source) {
            $attachment_id = absint($source['attachment_id'] ?? 0);
            $path = $attachment_id ? get_attached_file($attachment_id) : '';
            $mime = $attachment_id ? get_post_mime_type($attachment_id) : '';
            if (!$attachment_id || !$path || !is_readable($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                continue;
            }

            // The shared document caller base64-encodes files. Do not allow a
            // few oversized uploads to turn a comparison click into a huge
            // request; normal label images are far below this threshold.
            if (filesize($path) > 3 * MB_IN_BYTES) {
                continue;
            }

            $documents[] = [
                'path'      => $path,
                'mime_type' => $mime,
                'title'     => 'Nhãn thành phần/dinh dưỡng — ' . $product_name . ' — ' . sanitize_text_field((string) ($source['title'] ?? 'Ảnh nhãn')),
            ];

            if (count($documents) >= 8) {
                return $documents;
            }
        }
    }

    return $documents;
}

function tpc_compare_ai_product_snapshot(array $product_ids, array $fields): array
{
    $items = [];
    foreach ($product_ids as $product_id) {
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product || get_post_type($product_id) !== 'product' || get_post_status($product_id) !== 'publish') {
            return [];
        }

        $attributes = [];
        foreach ((array) $product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }
            $label = wc_attribute_label($attribute->get_name());
            $values = $attribute->is_taxonomy()
                ? wc_get_product_terms($product_id, $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            if ($label && !empty($values)) {
                $attributes[$label] = implode(', ', array_map('sanitize_text_field', (array) $values));
            }
        }

        $field_values = [];
        foreach ($fields as $field) {
            $key = sanitize_key($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $field_values[(string) ($field['label'] ?? $key)] = wp_strip_all_tags(tpc_compare_get_field_value($product, $product_id, $key));
        }

        $items[] = [
            'id'                => $product_id,
            'name'              => wp_strip_all_tags(get_the_title($product_id)),
            'sku'               => $product->get_sku(),
            'price'             => wp_strip_all_tags($product->get_price_html()),
            'categories'        => wp_strip_all_tags(wc_get_product_category_list($product_id, ', ')),
            'short_description' => mb_substr(wp_strip_all_tags($product->get_short_description()), 0, 2500),
            'description'       => mb_substr(wp_strip_all_tags($product->get_description()), 0, 4500),
            'attributes'        => $attributes,
            'comparison_fields'      => $field_values,
            'nutrition_label_sources' => tpc_compare_ai_nutrition_label_sources($product_id),
        ];
    }

    return $items;
}

function tpc_compare_ai_config(): array
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    $provider = theme_ai_default_provider();
    if ($provider === 'auto') {
        $provider = 'gemini';
    }
    if (defined('PRODUCT_COMPARE_AI_PROVIDER') && PRODUCT_COMPARE_AI_PROVIDER) {
        $provider = (string) PRODUCT_COMPARE_AI_PROVIDER;
    }

    return apply_filters('tpc_compare_ai_config', [
        'provider' => $provider,
        'model'    => defined('PRODUCT_COMPARE_AI_MODEL') ? (string) PRODUCT_COMPARE_AI_MODEL : theme_ai_default_model(),
        'pinned'   => defined('PRODUCT_COMPARE_AI_PROVIDER') && PRODUCT_COMPARE_AI_PROVIDER, // chọn cứng → không fallback
    ]);
}

function tpc_compare_ai_generate_copy(array $snapshot)
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
    $system = 'Bạn là biên tập viên so sánh thực phẩm bổ sung của Hi Thean. '
        . 'Chỉ được dùng dữ liệu catalogue được cung cấp. Không suy đoán dữ liệu thiếu. '
        . 'Không chẩn đoán, điều trị, chữa bệnh, cam kết hiệu quả, hoặc dùng claim y tế. '
        . 'Viết tiếng Việt rõ ràng, cân bằng, thực tế và đủ cụ thể để khách biết nên chọn gì tiếp theo. '
        . 'Đánh giá hương vị/mùi, độ ngọt, kết cấu và cách pha/chế biến khi dữ liệu catalogue hoặc nhãn có ghi rõ; '
        . 'nêu là thiếu dữ liệu khi không có, không tự nhận xét cảm quan. '
        . 'Với ảnh nhãn thành phần/dinh dưỡng: chỉ đọc chữ và số nhìn rõ, gắn đúng với tên sản phẩm trong tiêu đề ảnh; '
        . 'ưu tiên các khác biệt có thể đối chiếu như khẩu phần, calo, protein, chất xơ, carbohydrate, chất béo, đường, '
        . 'thành phần, chất tạo ngọt/hương liệu/phụ gia và cảnh báo dị ứng. Bỏ qua phần mờ/không chắc; nếu ảnh và văn bản '
        . 'catalogue mâu thuẫn, chỉ nêu sự khác biệt chứ không tự kết luận dữ liệu nào đúng. '
        . 'Trả về DUY NHẤT JSON hợp lệ với hai khóa string: '
        . 'conclusion (kết luận mua hàng theo nhu cầu, ngắn gọn) và neutral_analysis '
        . '(phân tích trung lập về khác biệt, đối tượng phù hợp, hương vị/cách dùng, dinh dưỡng-thành phần và dữ liệu thiếu). '
        . 'Cả hai phần phải có ít nhất hai đoạn ngắn khi dữ liệu cho phép; không chỉ lặp lại thông số. '
        . 'Mỗi giá trị là plain text gồm các đoạn ngắn, không markdown, không HTML.';
    $prompt = "Dữ liệu catalogue cho bảng so sánh:\n" . wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cfg = tpc_compare_ai_config();
    $documents = tpc_compare_ai_nutrition_documents($snapshot);
    $raw = theme_ai_feature_call($cfg, static function (string $provider, string $model, string $api_key) use ($system, $prompt, $documents) {
        return $documents
            ? theme_ai_call_provider_with_documents($provider, $system, $prompt, $documents, 2400, 120, $model, ['api_key' => $api_key])
            : theme_ai_call_provider($provider, $system, [['role' => 'user', 'content' => $prompt]], 2400, $model, ['api_key' => $api_key]);
    });
    if (is_wp_error($raw)) {
        return $raw;
    }

    $data = theme_ai_parse_json_object($raw);
    if (is_wp_error($data)) {
        return $data;
    }

    $conclusion = tpc_compare_ai_clean_text($data['conclusion'] ?? '');
    $analysis = tpc_compare_ai_clean_text($data['neutral_analysis'] ?? '');
    if ($conclusion === '' || $analysis === '') {
        return new WP_Error('tpc_compare_ai_empty', 'AI chưa tạo đủ hai phần nội dung so sánh.');
    }

    return ['conclusion' => $conclusion, 'neutral_analysis' => $analysis];
}

function tpc_compare_ai_request_ids(): array
{
    return tpc_compare_ai_normalize_product_ids(wp_unslash($_POST['product_ids'] ?? ''));
}

function tpc_compare_ai_ajax_public(): void
{
    tpc_compare_ai_verify_ajax();
    $version = tpc_compare_ai_read_public_version(tpc_compare_ai_request_ids());
    wp_send_json_success(['version' => $version]);
}
add_action('wp_ajax_tpc_product_compare_ai_public', 'tpc_compare_ai_ajax_public');
add_action('wp_ajax_nopriv_tpc_product_compare_ai_public', 'tpc_compare_ai_ajax_public');

function tpc_compare_ai_ajax_generate(): void
{
    tpc_compare_ai_verify_ajax(true);
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('product_compare_ai')) {
        wp_send_json_error(['message' => 'Tính năng So sánh bằng AI đang tắt trong Cài đặt ERP.'], 403);
    }

    $ids = tpc_compare_ai_request_ids();
    if (!$ids) {
        wp_send_json_error(['message' => 'Chọn từ 2 đến 5 sản phẩm để so sánh.'], 422);
    }
    $fields = tpc_compare_parse_fields_from_json((string) wp_unslash($_POST['fields'] ?? ''));
    $snapshot = tpc_compare_ai_product_snapshot($ids, $fields);
    if (count($snapshot) !== count($ids)) {
        wp_send_json_error(['message' => 'Chỉ có thể tạo AI cho sản phẩm đang được xuất bản.'], 422);
    }

    $key = tpc_compare_ai_comparison_key($ids);
    $lock_key = 'tpc_compare_ai_generating_' . substr($key, 0, 32);
    if (get_transient($lock_key)) {
        wp_send_json_error(['message' => 'Một lượt tạo AI cho nhóm sản phẩm này đang chạy. Vui lòng chờ hoàn tất.'], 429);
    }
    set_transient($lock_key, (string) get_current_user_id(), 2 * MINUTE_IN_SECONDS);

    $copy = tpc_compare_ai_generate_copy($snapshot);
    delete_transient($lock_key);
    if (is_wp_error($copy)) {
        wp_send_json_error(['message' => $copy->get_error_message()], 502);
    }

    tpc_compare_ai_maybe_install_table();
    global $wpdb;
    $now = current_time('mysql', true);
    $user_id = get_current_user_id();
    $inserted = $wpdb->insert(tpc_compare_ai_table_name(), [
        'comparison_key'  => $key,
        'product_ids'     => wp_json_encode($ids),
        'product_snapshot' => wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_hash'     => hash('sha256', wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'conclusion'      => $copy['conclusion'],
        'neutral_analysis' => $copy['neutral_analysis'],
        'status'          => 'draft',
        'created_by'      => $user_id,
        'updated_by'      => $user_id,
        'created_at'      => $now,
        'updated_at'      => $now,
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
    if (!$inserted) {
        wp_send_json_error(['message' => 'Không lưu được bản nháp AI.'], 500);
    }
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tpc_compare_ai_table_name() . ' WHERE id = %d', $wpdb->insert_id), ARRAY_A);
    wp_send_json_success(['version' => tpc_compare_ai_format_version((array) $row, true)]);
}
add_action('wp_ajax_tpc_product_compare_ai_generate', 'tpc_compare_ai_ajax_generate');

function tpc_compare_ai_ajax_list(): void
{
    tpc_compare_ai_verify_ajax(true);
    tpc_compare_ai_maybe_install_table();
    $ids = tpc_compare_ai_request_ids();
    if (!$ids) {
        wp_send_json_error(['message' => 'Nhóm sản phẩm không hợp lệ.'], 422);
    }
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . tpc_compare_ai_table_name() . ' WHERE comparison_key = %s ORDER BY created_at DESC, id DESC',
        tpc_compare_ai_comparison_key($ids)
    ), ARRAY_A);
    wp_send_json_success(['versions' => array_map(static function ($row) { return tpc_compare_ai_format_version($row, true); }, (array) $rows)]);
}
add_action('wp_ajax_tpc_product_compare_ai_list', 'tpc_compare_ai_ajax_list');

function tpc_compare_ai_ajax_save(): void
{
    tpc_compare_ai_verify_ajax(true);
    tpc_compare_ai_maybe_install_table();
    $version_id = absint($_POST['version_id'] ?? 0);
    $conclusion = tpc_compare_ai_clean_text($_POST['conclusion'] ?? '');
    $analysis = tpc_compare_ai_clean_text($_POST['neutral_analysis'] ?? '');
    if (!$version_id || $conclusion === '' || $analysis === '') {
        wp_send_json_error(['message' => 'Cần có đủ Kết luận mua hàng và Phân tích trung lập.'], 422);
    }
    global $wpdb;
    $table = tpc_compare_ai_table_name();
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $version_id), ARRAY_A);
    if (!$existing) {
        wp_send_json_error(['message' => 'Không tìm thấy phiên bản AI.'], 404);
    }

    // A published version is immutable from the editor's point of view: saving
    // modified text creates a reviewable draft instead of silently changing copy
    // already visible to customers.
    if (($existing['status'] ?? '') === 'published'
        && ($conclusion !== (string) $existing['conclusion'] || $analysis !== (string) $existing['neutral_analysis'])) {
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($table, [
            'comparison_key'   => $existing['comparison_key'],
            'product_ids'      => $existing['product_ids'],
            'product_snapshot' => $existing['product_snapshot'],
            'source_hash'      => $existing['source_hash'],
            'conclusion'       => $conclusion,
            'neutral_analysis' => $analysis,
            'status'           => 'draft',
            'created_by'       => get_current_user_id(),
            'updated_by'       => get_current_user_id(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        if (!$inserted) {
            wp_send_json_error(['message' => 'Không lưu được bản nháp AI.'], 500);
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id), ARRAY_A);
        wp_send_json_success(['version' => tpc_compare_ai_format_version((array) $row, true)]);
    }

    $updated = $wpdb->update($table, [
        'conclusion' => $conclusion,
        'neutral_analysis' => $analysis,
        'updated_by' => get_current_user_id(),
        'updated_at' => current_time('mysql', true),
    ], ['id' => $version_id], ['%s', '%s', '%d', '%s'], ['%d']);
    if ($updated === false) {
        wp_send_json_error(['message' => 'Không lưu được nội dung AI.'], 500);
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $version_id), ARRAY_A);
    wp_send_json_success(['version' => tpc_compare_ai_format_version($row, true)]);
}
add_action('wp_ajax_tpc_product_compare_ai_save', 'tpc_compare_ai_ajax_save');

function tpc_compare_ai_ajax_publish(): void
{
    tpc_compare_ai_verify_ajax(true);
    tpc_compare_ai_maybe_install_table();
    $version_id = absint($_POST['version_id'] ?? 0);
    if (!$version_id) {
        wp_send_json_error(['message' => 'Chưa chọn phiên bản AI.'], 422);
    }

    global $wpdb;
    $table = tpc_compare_ai_table_name();
    $wpdb->query('START TRANSACTION');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $version_id), ARRAY_A);
    if (!$row) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => 'Không tìm thấy phiên bản AI.'], 404);
    }
    $key = (string) $row['comparison_key'];
    $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE comparison_key = %s FOR UPDATE", $key));
    $now = current_time('mysql', true);
    $archived = $wpdb->query($wpdb->prepare("UPDATE {$table} SET status = 'archived', updated_at = %s WHERE comparison_key = %s AND status = 'published' AND id != %d", $now, $key, $version_id));
    $published = $wpdb->update($table, [
        'status'       => 'published',
        'updated_by'   => get_current_user_id(),
        'published_by' => get_current_user_id(),
        'updated_at'   => $now,
        'published_at' => $now,
    ], ['id' => $version_id], ['%s', '%d', '%d', '%s', '%s'], ['%d']);
    if ($archived === false || $published === false) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => 'Không thể công khai phiên bản AI.'], 500);
    }
    $wpdb->query('COMMIT');
    tpc_compare_ai_invalidate_public_cache($key);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $version_id), ARRAY_A);
    wp_send_json_success(['version' => tpc_compare_ai_format_version((array) $row, true)]);
}
add_action('wp_ajax_tpc_product_compare_ai_publish', 'tpc_compare_ai_ajax_publish');

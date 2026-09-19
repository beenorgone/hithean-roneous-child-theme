<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('hithean_upload_internal_order_image')) {
    /**
     * Store internal order evidence images without generating every registered
     * theme/WooCommerce thumbnail size. These images are admin proof only.
     */
    function hithean_upload_internal_order_image(array $file, int $parent_post_id, string $target_filename, int $max_dimension = 1000)
    {
        if (!empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('invalid_upload', 'File upload không hợp lệ');
        }

        $ext = strtolower(pathinfo($target_filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return new WP_Error('invalid_type', 'Chỉ hỗ trợ JPG, JPEG, PNG');
        }

        $mime = wp_check_filetype($target_filename);
        if (empty($mime['type']) || !in_array($mime['type'], ['image/jpeg', 'image/png'], true)) {
            return new WP_Error('invalid_mime', 'Định dạng ảnh không hợp lệ');
        }

        $editor = wp_get_image_editor($file['tmp_name']);
        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            if (!empty($size['width']) && !empty($size['height']) && max($size['width'], $size['height']) > $max_dimension) {
                $editor->resize($max_dimension, $max_dimension, false);
            }

            if (method_exists($editor, 'set_quality')) {
                $editor->set_quality(82);
            }

            $editor->save($file['tmp_name'], $mime['type']);
            clearstatcache(true, $file['tmp_name']);
            $file['size'] = filesize($file['tmp_name']);
        }

        $file['name'] = sanitize_file_name($target_filename);
        $file['type'] = $mime['type'];

        $upload = wp_handle_sideload($file, [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg' => 'image/jpeg',
                'png' => 'image/png',
            ],
        ]);

        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error']);
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_text_field(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $parent_post_id,
        ], $upload['file'], $parent_post_id, true);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        update_attached_file($attachment_id, $upload['file']);

        $image_size = @getimagesize($upload['file']);
        if ($image_size) {
            update_post_meta($attachment_id, '_wp_attachment_metadata', [
                'width' => (int) $image_size[0],
                'height' => (int) $image_size[1],
                'file' => _wp_relative_upload_path($upload['file']),
                'sizes' => [],
            ]);
        }

        return $attachment_id;
    }
}

function hithean_get_export_upload_checklist(): array
{
    $default = implode("\n", [
        'Ảnh chụp đầy đủ sản phẩm',
        'Số lượng khớp với đơn',
        'Hàng không bị hư hỏng, rò rỉ, móp méo',
        'Ảnh chụp rõ mã đơn hoặc tên khách hàng',
        'Nhãn hàng The An Organics đã dán TEM NIÊM PHONG',
    ]);
    $raw = get_option('hithean_export_upload_checklist', $default);
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
}

function hithean_render_export_image_gallery(array $urls): string
{
    $safe_urls = array_values(array_filter(array_map('esc_url_raw', $urls)));
    if (empty($safe_urls)) {
        return '<em>Không có ảnh</em>';
    }

    $urls_json = esc_attr(wp_json_encode($safe_urls));
    $html = '<div class="order-images uexe-gallery-thumbs" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">';
    foreach ($safe_urls as $index => $url) {
        $u = esc_url($url);
        $html .= '<a href="' . $u . '" class="uexe-gallery-link" data-gallery="' . $urls_json . '" data-index="' . esc_attr($index) . '" style="display:inline-block;cursor:zoom-in;">';
        $html .= '<img src="' . $u . '" style="max-width:80px;height:auto;border:1px solid #ddd;border-radius:4px;">';
        $html .= '</a>';
    }
    $html .= '</div>';

    return $html;
}

/** AI review is advisory only; warehouse confirmation stays manual. */
function hithean_export_ai_config(): array
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    $provider = theme_ai_default_provider();
    if ($provider === 'auto') {
        $provider = 'gemini';
    }
    if (defined('HITHEAN_EXPORT_AI_PROVIDER') && HITHEAN_EXPORT_AI_PROVIDER) {
        $provider = (string) HITHEAN_EXPORT_AI_PROVIDER;
    }
    return apply_filters('hithean_export_ai_config', [
        'provider' => $provider,
        'model'    => defined('HITHEAN_EXPORT_AI_MODEL') ? (string) HITHEAN_EXPORT_AI_MODEL : theme_ai_default_model(),
    ]);
}

function hithean_export_ai_clean_text($value, int $limit = 500): string
{
    $value = is_scalar($value) ? sanitize_text_field((string) $value) : '';
    return function_exists('mb_substr') ? mb_substr(trim($value), 0, $limit) : substr(trim($value), 0, $limit);
}

function hithean_export_ai_clean_list($value, int $max_items = 8): array
{
    if (!is_array($value)) return [];
    $items = [];
    foreach ($value as $item) {
        $text = hithean_export_ai_clean_text($item, 250);
        if ($text !== '') $items[] = $text;
        if (count($items) >= $max_items) break;
    }
    return array_values(array_unique($items));
}

function hithean_export_ai_masked_order_reference(WC_Order $order): string
{
    return '#' . $order->get_id() . '**';
}

function hithean_export_ai_is_expected_masked_reference(string $reference, WC_Order $order): bool
{
    $reference = preg_replace('/\s+/', '', $reference);
    return is_string($reference) && preg_match('/^#?' . preg_quote((string) $order->get_id(), '/') . '\*\*$/', $reference) === 1;
}

function hithean_export_ai_result_pill(string $label, string $value, string $tone): string
{
    $tones = [
        'success' => ['#dcfce7', '#166534', '#86efac'],
        'warning' => ['#fef3c7', '#92400e', '#fcd34d'],
        'danger'  => ['#fee2e2', '#991b1b', '#fca5a5'],
        'neutral' => ['#e2e8f0', '#334155', '#cbd5e1'],
    ];
    [$background, $color, $border] = $tones[$tone] ?? $tones['neutral'];
    return '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border:1px solid ' . $border . ';border-radius:999px;background:' . $background . ';color:' . $color . ';font-size:12px;font-weight:600;line-height:1.2;">'
        . esc_html($label) . ': <strong>' . esc_html($value) . '</strong></span>';
}

function hithean_export_ai_order_note(array $result): string
{
    $labels = ['match' => 'Khớp', 'mismatch' => 'Không khớp', 'unclear' => 'Chưa đủ bằng chứng'];
    $lines = [
        '🤖 AI review ảnh xuất kho',
        'Kết quả: ' . (($result['overall'] ?? '') === 'pass' ? 'Khớp — vẫn cần nhân viên xác nhận.' : 'Cần kiểm tra thủ công.'),
        'Mã đơn/phiếu: ' . ($labels[$result['order_match'] ?? 'unclear'] ?? 'Chưa đủ bằng chứng') . (!empty($result['masked_order_match']) ? ' (khớp theo mã che chuẩn)' : ''),
        'Sản phẩm và số lượng: ' . ($labels[$result['items_match'] ?? 'unclear'] ?? 'Chưa đủ bằng chứng'),
        'Độ tin cậy: ' . ($result['confidence'] ?? 'low'),
    ];
    if (!empty($result['visible_order_reference'])) $lines[] = 'AI đọc được: ' . $result['visible_order_reference'];
    if (!empty($result['reason'])) $lines[] = 'Nhận định: ' . $result['reason'];
    $issues = array_merge((array) ($result['missing_or_suspected_missing'] ?? []), (array) ($result['unexpected_or_suspected_extra'] ?? []));
    if ($issues) $lines[] = 'Cần xem lại: ' . implode('; ', $issues);
    return implode("\n", $lines);
}

function hithean_export_ai_documents(int $order_id, array $urls)
{
    $documents = [];
    $attachment_ids = [];
    foreach ($urls as $url) {
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id && (int) get_post_field('post_parent', $attachment_id) === $order_id) $attachment_ids[] = (int) $attachment_id;
    }
    if (!$attachment_ids) {
        $attachment_ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $order_id,
            'post_mime_type' => 'image', 'fields' => 'ids', 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC',
        ]);
    }
    foreach (array_slice(array_unique(array_map('intval', $attachment_ids)), 0, 10) as $attachment_id) {
        $path = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);
        if (!$path || !is_readable($path) || strpos((string) $mime, 'image/') !== 0 || filesize($path) > 6 * MB_IN_BYTES) continue;
        $documents[] = ['path' => $path, 'mime_type' => $mime, 'title' => basename($path)];
    }
    return $documents ?: new WP_Error('hithean_export_ai_no_images', 'Không tìm được ảnh xuất kho hợp lệ để AI kiểm tra.');
}

function hithean_export_ai_check_order(WC_Order $order, int $requested_by = 0)
{
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('export_image_ai_check')) return new WP_Error('hithean_export_ai_disabled', 'Tính năng AI kiểm tra ảnh xuất kho đang tắt trong Cài đặt ERP.');

    $order_id = $order->get_id();
    $lock_key = '_hithean_export_ai_check_lock';
    $locked_at = (int) get_post_meta($order_id, $lock_key, true);
    if ($locked_at && time() - $locked_at < 180) return new WP_Error('hithean_export_ai_busy', 'AI đang kiểm tra ảnh của đơn này. Vui lòng đợi kết quả.');
    if ($locked_at) delete_post_meta($order_id, $lock_key);
    if (!add_post_meta($order_id, $lock_key, time(), true)) return new WP_Error('hithean_export_ai_busy', 'AI đang kiểm tra ảnh của đơn này. Vui lòng đợi kết quả.');

    try {
        $urls = array_values(array_filter(array_map('trim', explode("\n", (string) get_post_meta($order_id, 'warehouse_export_images', true)))));
        $documents = hithean_export_ai_documents($order_id, $urls);
        if (is_wp_error($documents)) return $documents;
        $expected_items = [];
        foreach ($order->get_items() as $item) {
            $expected_items[] = ['name' => ct_get_order_item_display_name($item), 'quantity' => (int) $item->get_quantity()];
        }

        require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
        $system = 'Bạn là trợ lý kiểm tra ảnh lấy hàng cho kho của HiThean. Chỉ đánh giá bằng những gì nhìn thấy rõ trong ảnh; không suy đoán khi nhãn, số lượng, mã đơn hoặc sản phẩm bị che/mờ. Đây là kết quả hỗ trợ nhân viên, không phải quyết định xuất kho. '
            . 'Trả về DUY NHẤT một JSON object hợp lệ, không markdown, đúng các khóa: overall (pass|review), order_match (match|mismatch|unclear), items_match (match|mismatch|unclear), confidence (high|medium|low), visible_order_reference (string), reason (string), missing_or_suspected_missing (array string), unexpected_or_suspected_extra (array string). '
            . 'QUY TẮC MÃ PHIẾU HITHEAN: phiếu xuất kho cố ý che đúng hai chữ số suffix ở cuối bằng **. Nếu ảnh đọc được đúng mẫu mã che chuẩn được cung cấp, hãy trả order_match=match; đây là bằng chứng đủ về mã đơn. '
            . 'overall=pass CHỈ khi mã/phiếu của đơn và toàn bộ sản phẩm cùng số lượng đều nhìn thấy đủ, rõ và khớp. Mọi trường hợp còn lại là review.';
        $prompt = "Đối chiếu ảnh đính kèm với dữ liệu đơn hàng sau.\n" . wp_json_encode([
            'order_id_goc' => (string) $order->get_id(),
            'ma_don_hien_thi_day_du' => (string) $order->get_order_number(),
            'ma_phieu_da_che_chuan' => hithean_export_ai_masked_order_reference($order),
            'expected_items' => $expected_items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nChỉ mẫu ma_phieu_da_che_chuan (ID gốc + đúng hai dấu *) được xem là mã che hợp lệ. Mã khác hoặc che kiểu khác phải dùng mismatch/unclear.";
        $cfg = hithean_export_ai_config();
        $raw = theme_ai_call_provider_with_documents($cfg['provider'], $system, $prompt, $documents, 1100, 120, $cfg['model']);
        if (is_wp_error($raw)) return $raw;
        $data = theme_ai_parse_json_object($raw);
        if (is_wp_error($data)) return $data;

        $allowed = ['overall' => ['pass', 'review'], 'order_match' => ['match', 'mismatch', 'unclear'], 'items_match' => ['match', 'mismatch', 'unclear'], 'confidence' => ['high', 'medium', 'low']];
        $result = [];
        foreach ($allowed as $key => $values) {
            $value = sanitize_key((string) ($data[$key] ?? ''));
            $result[$key] = in_array($value, $values, true) ? $value : ($key === 'overall' ? 'review' : 'unclear');
        }
        if ($result['order_match'] !== 'match' || $result['items_match'] !== 'match') $result['overall'] = 'review';
        $result['visible_order_reference'] = hithean_export_ai_clean_text($data['visible_order_reference'] ?? '', 120);
        $result['masked_order_match'] = hithean_export_ai_is_expected_masked_reference($result['visible_order_reference'], $order);
        if ($result['masked_order_match']) {
            $result['order_match'] = 'match';
            if ($result['items_match'] === 'match') $result['overall'] = 'pass';
        }
        $result['reason'] = hithean_export_ai_clean_text($data['reason'] ?? '', 600);
        $result['missing_or_suspected_missing'] = hithean_export_ai_clean_list($data['missing_or_suspected_missing'] ?? []);
        $result['unexpected_or_suspected_extra'] = hithean_export_ai_clean_list($data['unexpected_or_suspected_extra'] ?? []);
        $result['checked_at'] = current_time('mysql');
        $result['checked_by'] = $requested_by ?: get_current_user_id();
        $result['provider'] = sanitize_key((string) $cfg['provider']);
        update_post_meta($order_id, 'warehouse_export_ai_check', $result);
        $order->add_order_note(hithean_export_ai_order_note($result));
        do_action('hithean_export_ai_check_completed', $order, $result);
        return $result;
    } finally {
        delete_post_meta($order_id, $lock_key);
    }
}

function hithean_render_export_ai_check(int $order_id): string
{
    $result = get_post_meta($order_id, 'warehouse_export_ai_check', true);
    $result = is_array($result) ? $result : [];
    $review_status = get_post_meta($order_id, 'warehouse_export_ai_review_status', true);
    $review_state = is_array($review_status) ? ($review_status['state'] ?? '') : '';
    $is_pass = ($result['overall'] ?? '') === 'pass';
    $label = $review_state === 'queued' ? 'AI: Đang kiểm tra' : ($is_pass ? 'AI: Khớp' : ($result ? 'AI: Cần kiểm tra' : 'AI check'));
    $html = '<section class="uexe-ai-check" style="margin-top:14px;padding:12px;border:1px solid ' . ($is_pass ? '#86efac' : '#cbd5e1') . ';border-radius:8px;background:' . ($is_pass ? '#f0fdf4' : '#f8fafc') . ';">';
    $html .= '<button type="button" class="button uexe-ai-check-button" data-order-id="' . esc_attr($order_id) . '">' . esc_html($label) . '</button>';
    $html .= '<p style="margin:8px 0 0;font-size:12px;color:#52606d;">Nhấn AI check sẽ gửi ảnh tới AI provider đã cấu hình. AI chỉ hỗ trợ đối chiếu, không tự xác nhận xuất kho.</p>';
    if ($review_state === 'queued') {
        $html .= '<p style="margin:8px 0 0;font-size:13px;color:#0f766e;">AI đang kiểm tra nền; bạn có thể tiếp tục thao tác.</p>';
    }
    if ($result) {
        $labels = ['match' => 'Khớp', 'mismatch' => 'Không khớp', 'unclear' => 'Chưa đủ bằng chứng'];
        $order_state = $result['order_match'] ?? 'unclear'; $item_state = $result['items_match'] ?? 'unclear'; $confidence = $result['confidence'] ?? 'low';
        $order_tone = $order_state === 'match' ? 'success' : ($order_state === 'mismatch' ? 'danger' : 'warning');
        $item_tone = $item_state === 'match' ? 'success' : ($item_state === 'mismatch' ? 'danger' : 'warning');
        $order_value = $labels[$order_state] ?? 'Chưa đủ bằng chứng';
        if (!empty($result['masked_order_match'])) $order_value .= ' theo mã che';
        $confidence_labels = ['high' => 'Cao', 'medium' => 'Trung bình', 'low' => 'Thấp'];
        $html .= '<div style="margin-top:10px;font-size:13px;line-height:1.55;"><div style="display:flex;flex-wrap:wrap;gap:6px;">';
        $html .= hithean_export_ai_result_pill('Kết quả', $is_pass ? 'Khớp ảnh với đơn' : 'Cần kiểm tra thủ công', $is_pass ? 'success' : 'warning');
        $html .= hithean_export_ai_result_pill('Mã đơn', $order_value, $order_tone);
        $html .= hithean_export_ai_result_pill('Sản phẩm/SL', $labels[$item_state] ?? 'Chưa đủ bằng chứng', $item_tone);
        $html .= hithean_export_ai_result_pill('Độ tin cậy', $confidence_labels[$confidence] ?? 'Thấp', $confidence === 'high' ? 'success' : ($confidence === 'medium' ? 'warning' : 'neutral'));
        $html .= '</div>';
        if (!empty($result['visible_order_reference'])) $html .= '<br>AI đọc được: ' . esc_html($result['visible_order_reference']) . '.';
        if (!empty($result['reason'])) $html .= '<br>' . esc_html($result['reason']);
        $issues = array_merge((array) ($result['missing_or_suspected_missing'] ?? []), (array) ($result['unexpected_or_suspected_extra'] ?? []));
        if ($issues) { $html .= '<ul style="margin:6px 0 0 18px;">'; foreach ($issues as $issue) $html .= '<li>' . esc_html($issue) . '</li>'; $html .= '</ul>'; }
        $html .= '<small style="display:block;margin-top:7px;color:#64748b;">Lần kiểm tra: ' . esc_html($result['checked_at'] ?? '') . '</small></div>';
    }
    return $html . '</section>';
}

function hithean_render_export_ai_bulk_controls(string $scope): string
{
    $scope = sanitize_key($scope);
    return '<div class="uexe-ai-bulk" data-uexe-ai-bulk data-uexe-bulk-target="' . esc_attr($scope) . '" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;margin:0 0 14px;padding:14px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;"><label style="display:grid;gap:5px;min-width:min(100%,320px);font-size:13px;font-weight:600;color:#334155;">Ngoại trừ mã đơn<input type="text" data-uexe-bulk-exclude placeholder="#89339**, 8933991" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-weight:400;"></label><button type="button" class="button button-primary" data-uexe-bulk-check>AI check tất cả</button><span data-uexe-bulk-status role="status" aria-live="polite" style="font-size:13px;color:#475569;"></span><small style="flex-basis:100%;color:#64748b;">Chạy lần lượt từng đơn. Mã đầy đủ, ID gốc và mã che đều được nhận diện.</small></div>';
}

// ===== Shortcode Upload Ảnh =====
function shortcode_upload_export_images_form()
{
    if (!is_user_logged_in() || !current_user_can('upload_files') || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();
?>
    <h2>Upload ảnh đơn hàng</h2>
    <form id="upload-export-form" enctype="multipart/form-data">
        <p>
            <label for="ueif_order_id">Đơn hàng (Order ID):</label><br>
            <input type="number" name="ueif_order_id" id="ueif_order_id" required style="width:100%;max-width:500px;border-radius:5px;border:2px solid #ccc !important;">
        </p>
        <p>
            <label for="ueif_images">Ảnh xuất kho:</label><br>
            <input type="file" name="ueif_images[]" id="ueif_images" multiple accept="image/*" required style="width:100%;max-width:500px;border-radius:5px;border:2px solid #ccc!important;">
            <span id="ueif-images-notice" style="display:inline-block;margin-top:8px;padding:8px 14px;background:#fff3cd;border:2px solid #f0ad4e;border-radius:6px;color:#7a4f00;font-weight:600;font-size:0.9em;">
                ⚠️ Chỉ được chọn tối đa <strong>5 ảnh</strong> mỗi lần upload
            </span>
            <span id="ueif-images-error" style="display:none;margin-top:8px;padding:8px 14px;background:#f8d7da;border:2px solid #dc3545;border-radius:6px;color:#721c24;font-weight:600;font-size:0.9em;">
                ❌ Bạn đã chọn quá 5 ảnh! Vui lòng chọn lại tối đa 5 ảnh.
            </span>
        </p>
        <p style="max-width:500px;padding:10px 12px;border:1px solid #b7e4d5;border-radius:6px;background:#f0fdf8;"><label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;"><input type="checkbox" name="ueif_ai_check" value="1" checked style="margin-top:3px;"><span><strong>Gọi AI kiểm tra ảnh sau khi upload</strong><br><small>Ảnh sẽ được gửi tới AI provider đã cấu hình để đối chiếu mã đơn, sản phẩm và số lượng; kết quả chỉ hỗ trợ kiểm tra, không tự xác nhận xuất kho.</small></span></label></p>
        <p>
            <button type="submit" class="button button-primary">Upload ảnh xuất kho</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('upload_export_images_form', 'shortcode_upload_export_images_form');


// ===== Shortcode List Đơn Chưa Xác Nhận =====
function shortcode_list_unconfirmed_exports()
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();

    $args = [
        'post_type' => 'shop_order',
        'post_status' => 'any',
        'posts_per_page' => 40,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'warehouse_export_images',
                'compare' => 'EXISTS',
            ],
            [
                'key' => 'warehouse_export_images',
                'value' => '',
                'compare' => '!=',
            ],
            [
                'relation' => 'OR',
                [
                    'key' => 'export_confirmed_by',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'export_confirmed_by',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ],
    ];

    $orders = get_posts($args);

    echo '<h2>Ảnh lấy hàng chờ kiểm tra</h2>';

    if (empty($orders)) {
        echo '<p>Không có đơn nào chờ xác nhận.</p>';
        return ob_get_clean();
    }

    echo hithean_render_export_ai_bulk_controls('unconfirmed');
    echo '<div data-uexe-bulk-grid="unconfirmed" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">';

    foreach ($orders as $order_post) {
        $order_id = $order_post->ID;
        $order = wc_get_order($order_id);
        $status = wc_get_order_status_name($order->get_status());
        $edit_link = get_edit_post_link($order_id);

        echo '<div class="order-card uexe-export-card" data-uexe-order-id="' . esc_attr($order_id) . '" data-uexe-order-number="' . esc_attr($order->get_order_number()) . '" style="border:1px solid #ccc;border-radius:8px;padding:15px;background:#fff;">';
        echo '<h4 style="margin-top: 10px;">Đơn hàng #' . esc_html($order_id) . '</h4>';
        echo '<p>Trạng thái: <strong>' . esc_html($status) . '</strong> - ';
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">✏️ Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html(ct_get_order_item_display_name($item)) . ' × ' . esc_html($item->get_quantity()) . '</li>';
        }
        echo '</ul>';

        // Hiển thị ảnh
        $images_raw = get_post_meta($order_id, 'warehouse_export_images', true);
        $urls = array_filter(array_map('trim', explode("\n", $images_raw)));
        echo hithean_render_export_image_gallery($urls);
        echo hithean_render_export_ai_check($order_id);

        // Form xác nhận
    ?>
        <form class="export-confirm-form" style="margin-top:15px;">
            <input type="hidden" name="uexe_order_id" value="<?php echo esc_attr($order_id); ?>">
            <button type="submit" class="button" style="background:green;color:#fff;">Xác nhận xuất kho</button>
        </form>
        <?php

        echo '</div>'; // end card
    }

    echo '</div>'; // end grid

    return ob_get_clean();
}
add_shortcode('list_unconfirmed_exports', 'shortcode_list_unconfirmed_exports');

// ===== Shortcode List Đơn Đã Upload Ảnh nhưng Chưa Giao =====
function shortcode_list_uploaded_not_shipped_exports()
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();

    $args = [
        'post_type'      => 'shop_order',
        'post_status'    => 'any',
        'posts_per_page' => 40,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'warehouse_export_images',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'warehouse_export_images',
                'value'   => '',
                'compare' => '!=',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => 'export_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => 'export_date',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ],
    ];

    $orders = get_posts($args);

    // Lọc theo trạng thái đơn (loại bỏ shipping, delivered, completed)
    $filtered_orders = [];
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order) continue;
        $status = $order->get_status();

        if (!in_array($status, ['shipping', 'delivered', 'completed'])) {
            $filtered_orders[] = $order;
        }
    }

    echo '<h2>Đơn đã upload ảnh nhưng chưa giao đi</h2>';

    if (empty($filtered_orders)) {
        echo '<p>Không có đơn nào.</p>';
        return ob_get_clean();
    }

    echo hithean_render_export_ai_bulk_controls('not-shipped');
    echo '<div data-uexe-bulk-grid="not-shipped" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">';

    foreach ($filtered_orders as $order) {
        $order_id   = $order->get_id();
        $status     = wc_get_order_status_name($order->get_status());
        $edit_link  = get_edit_post_link($order_id);
        $confirmed  = get_post_meta($order_id, 'export_confirmed_by', true);

        echo '<div class="order-card uexe-export-card" data-uexe-order-id="' . esc_attr($order_id) . '" data-uexe-order-number="' . esc_attr($order->get_order_number()) . '" style="border:1px solid #ccc;border-radius:8px;padding:15px;background:#fff;">';
        echo '<h4 style="margin-top: 10px;">Đơn hàng #' . esc_html($order_id) . '</h4>';
        echo '<p>Trạng thái: <strong>' . esc_html($status) . '</strong> - ';
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">✏️ Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html(ct_get_order_item_display_name($item)) . ' × ' . esc_html($item->get_quantity()) . '</li>';
        }
        echo '</ul>';

        // Hiển thị ảnh
        $images_raw = get_post_meta($order_id, 'warehouse_export_images', true);
        $urls = array_filter(array_map('trim', explode("\n", $images_raw)));
        echo hithean_render_export_image_gallery($urls);
        echo hithean_render_export_ai_check($order_id);

        // Nút xác nhận
        echo '<div style="margin-top:15px;">';
        if (empty($confirmed)) {
        ?>
            <form class="export-confirm-form">
                <input type="hidden" name="uexe_order_id" value="<?php echo esc_attr($order_id); ?>">
                <button type="submit" class="button" style="background:green;color:#fff;">Xác nhận xuất kho</button>
            </form>
    <?php
        } else {
            echo '<p><strong style="color:green;">✅ Đã xác nhận</strong></p>';
        }
        echo '</div>';

        echo '</div>'; // end card
    }

    echo '</div>'; // end grid

    return ob_get_clean();
}
add_shortcode('list_uploaded_not_shipped_exports', 'shortcode_list_uploaded_not_shipped_exports');



// ===== AJAX HANDLERS =====

// Upload ảnh
add_action('wp_ajax_ajax_upload_images', function () {
    check_ajax_referer('ajax_upload_images_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Không có quyền');

    $order_id = absint($_POST['ueif_order_id'] ?? 0);
    if (!$order_id || empty($_FILES['ueif_images'])) {
        wp_send_json_error("Thiếu dữ liệu");
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Không tìm thấy đơn hàng.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $files = $_FILES['ueif_images'];

    if (count($files['name']) > 5) {
        wp_send_json_error('❌ Chỉ được upload tối đa 5 ảnh mỗi lần. Vui lòng chọn lại.');
    }

    $uploaded_urls = [];

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                continue;
            }

            $timestamp = date('Ymd-His');
            $filename = sprintf('order-export-image-%d-%s-%d.%s', $order_id, $timestamp, $i + 1, $ext);

            $upload_file = [
                'name'     => $filename,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];

            $attach_id = hithean_upload_internal_order_image($upload_file, $order_id, $filename);
            if (!is_wp_error($attach_id)) {
                $uploaded_urls[] = wp_get_attachment_url($attach_id);
            }
        }
    }

    if ($uploaded_urls) {
        $existing = get_post_meta($order_id, 'warehouse_export_images', true);
        $all = array_filter(array_merge(explode("\n", $existing), $uploaded_urls));
        update_post_meta($order_id, 'warehouse_export_images', implode("\n", $all));
        delete_post_meta($order_id, 'warehouse_export_ai_check');
        delete_post_meta($order_id, 'warehouse_export_ai_review_status');
        $message = '✅ Đã upload thành công ' . count($uploaded_urls) . ' ảnh';
        $ai_check = null;
        if (!empty($_POST['ueif_ai_check'])) {
            $queued = hithean_export_ai_schedule_check($order_id, get_current_user_id());
            if (is_wp_error($queued)) {
                $message .= '. AI chưa được đưa vào hàng đợi: ' . $queued->get_error_message();
                $order->add_order_note('🤖 AI review ảnh xuất kho chưa được đưa vào hàng đợi: ' . sanitize_text_field($queued->get_error_message()));
            } else {
                $message .= '. AI đang kiểm tra nền; bạn có thể tiếp tục thao tác.';
                $order->add_order_note('🤖 AI review ảnh xuất kho đã được đưa vào hàng đợi.');
            }
        }
        wp_send_json_success(['message' => $message, 'ai_check' => $ai_check]);
    }
    wp_send_json_error("Không upload được ảnh");
});

add_action('wp_ajax_hithean_export_ai_check', function () {
    check_ajax_referer('hithean_export_ai_check_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'Không có quyền.'], 403);
    $order_id = absint($_POST['order_id'] ?? 0);
    $order = $order_id ? wc_get_order($order_id) : false;
    if (!$order) wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    $result = hithean_export_ai_check_order($order);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
    wp_send_json_success([
        'message' => $result['overall'] === 'pass' ? 'AI nhận định ảnh khớp đơn; vẫn cần kiểm tra và xác nhận thủ công.' : 'AI cần nhân viên kiểm tra thủ công.',
        'html' => hithean_render_export_ai_check($order_id),
    ]);
});

// Xác nhận xuất kho
add_action('wp_ajax_ajax_confirm_export', function () {
    check_ajax_referer('ajax_confirm_export_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Không có quyền');

    $order_id = intval($_POST['uexe_order_id']);
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error("Không tìm thấy đơn");

    $image_urls = array_filter(array_map('trim', explode("\n", (string) get_post_meta($order_id, 'warehouse_export_images', true))));
    if (empty($image_urls)) {
        wp_send_json_error('Hãy upload ít nhất một ảnh xuất kho trước khi xác nhận.');
    }

    $user = wp_get_current_user();
    update_post_meta($order_id, 'export_confirmed_by', $user->ID);
    $order->add_order_note("✅ Đã xác nhận xuất kho bởi " . $user->display_name);
    do_action('hithean_warehouse_export_confirmed', $order);
    wp_send_json_success("✅ Đã xác nhận xuất kho đơn hàng #$order_id");
});


// ===== Enqueue JS + Modal =====
add_action('wp_footer', function () {
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) return;

    $checklist = hithean_get_export_upload_checklist();
    ?>
    <?php if (!empty($checklist)): ?>
    <div id="ueif-checklist-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:999999;justify-content:center;align-items:center;">
        <div style="background:#fff;border-radius:10px;padding:28px 30px;max-width:480px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.25);">
            <h3 style="margin:0 0 6px;font-size:1.1em;">📋 Kiểm tra trước khi upload ảnh</h3>
            <p style="margin:0 0 18px;color:#666;font-size:0.88em;">Xác nhận đầy đủ tất cả mục dưới đây trước khi upload:</p>
            <div id="ueif-checklist-items"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;border-top:1px solid #eee;padding-top:16px;">
                <button type="button" id="ueif-modal-cancel-btn" style="padding:8px 18px;border:1px solid #ccc;border-radius:4px;background:#f5f5f5;cursor:pointer;font-size:14px;">Hủy</button>
                <button type="button" id="ueif-modal-confirm-btn" style="padding:8px 18px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;opacity:0.5;" disabled>✅ Xác nhận &amp; Upload</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <style>
        .uexe-gallery-modal { position: fixed; inset: 0; z-index: 1000000; display: none; align-items: center; justify-content: center; background: rgba(17, 24, 39, 0.88); padding: 18px; }
        .uexe-gallery-modal.is-open { display: flex; }
        .uexe-gallery-dialog { position: relative; width: min(1100px, 96vw); height: min(760px, 90vh); display: grid; grid-template-rows: auto 1fr auto; gap: 10px; }
        .uexe-gallery-header { display: flex; align-items: center; justify-content: space-between; color: #fff; font-size: 14px; font-weight: 600; }
        .uexe-gallery-close, .uexe-gallery-prev, .uexe-gallery-next { border: 0; border-radius: 4px; background: rgba(255,255,255,0.92); color: #111827; cursor: pointer; font-size: 22px; line-height: 1; width: 42px; height: 42px; }
        .uexe-gallery-close { font-size: 28px; }
        .uexe-gallery-stage { position: relative; min-height: 0; display: flex; align-items: center; justify-content: center; }
        .uexe-gallery-stage img { max-width: 100%; max-height: 100%; object-fit: contain; background: #fff; border-radius: 4px; box-shadow: 0 18px 50px rgba(0,0,0,0.35); }
        .uexe-gallery-prev, .uexe-gallery-next { position: absolute; top: 50%; transform: translateY(-50%); }
        .uexe-gallery-prev { left: 10px; }
        .uexe-gallery-next { right: 10px; }
        .uexe-gallery-footer { text-align: center; }
        .uexe-gallery-open-new { color: #fff !important; font-size: 13px; text-decoration: underline; }
        .uexe-toast-container { position: fixed; top: 20px; right: 20px; z-index: 1000001; display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .uexe-toast { min-width: 240px; max-width: 360px; padding: 12px 16px; border-radius: 6px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); font-size: 14px; font-weight: 600; color: #fff; opacity: 0; transform: translateX(24px); transition: opacity 0.25s ease, transform 0.25s ease; }
        .uexe-toast.is-visible { opacity: 1; transform: translateX(0); }
        .uexe-toast.is-success { background: #1e8e3e; }
        .uexe-toast.is-error { background: #d93025; }
    </style>
    <div id="uexe-toast-container" class="uexe-toast-container"></div>
    <div id="uexe-gallery-modal" class="uexe-gallery-modal" aria-hidden="true">
        <div class="uexe-gallery-dialog" role="dialog" aria-modal="true" aria-label="Xem ảnh lấy hàng">
            <div class="uexe-gallery-header">
                <span id="uexe-gallery-counter"></span>
                <button type="button" class="uexe-gallery-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="uexe-gallery-stage">
                <button type="button" class="uexe-gallery-prev" aria-label="Ảnh trước">&#8249;</button>
                <img id="uexe-gallery-image" src="" alt="Ảnh lấy hàng">
                <button type="button" class="uexe-gallery-next" aria-label="Ảnh kế tiếp">&#8250;</button>
            </div>
            <div class="uexe-gallery-footer">
                <a id="uexe-gallery-open-new" class="uexe-gallery-open-new" href="#" target="_blank" rel="noopener">Mở ảnh gốc</a>
            </div>
        </div>
    </div>
    <script>
        var ueifNonce = "<?php echo esc_js(wp_create_nonce('ajax_upload_images_nonce')); ?>";
        var uexeNonce = "<?php echo esc_js(wp_create_nonce('ajax_confirm_export_nonce')); ?>";
        var uexeAiNonce = "<?php echo esc_js(wp_create_nonce('hithean_export_ai_check_nonce')); ?>";
        var ueifChecklist = <?php echo wp_json_encode($checklist); ?>;

        function uexeShowToast(message, isSuccess) {
            const container = document.getElementById('uexe-toast-container');
            if (!container) return;
            const toast = document.createElement('div');
            toast.className = 'uexe-toast ' + (isSuccess ? 'is-success' : 'is-error');
            toast.textContent = message;
            container.appendChild(toast);
            requestAnimationFrame(function() {
                toast.classList.add('is-visible');
            });
            setTimeout(function() {
                toast.classList.remove('is-visible');
                setTimeout(function() {
                    toast.remove();
                }, 250);
            }, 3500);
        }

        document.addEventListener("DOMContentLoaded", function() {
            const galleryModal = document.getElementById('uexe-gallery-modal');
            const galleryImage = document.getElementById('uexe-gallery-image');
            const galleryCounter = document.getElementById('uexe-gallery-counter');
            const galleryOpenNew = document.getElementById('uexe-gallery-open-new');
            let galleryUrls = [];
            let galleryIndex = 0;

            function showGalleryImage() {
                if (!galleryUrls.length) return;
                galleryIndex = (galleryIndex + galleryUrls.length) % galleryUrls.length;
                galleryImage.src = galleryUrls[galleryIndex];
                galleryOpenNew.href = galleryUrls[galleryIndex];
                galleryCounter.textContent = (galleryIndex + 1) + ' / ' + galleryUrls.length;
            }

            function openGallery(urls, index) {
                galleryUrls = urls.filter(Boolean);
                galleryIndex = Number.isFinite(index) ? index : 0;
                showGalleryImage();
                galleryModal.classList.add('is-open');
                galleryModal.setAttribute('aria-hidden', 'false');
            }

            function closeGallery() {
                galleryModal.classList.remove('is-open');
                galleryModal.setAttribute('aria-hidden', 'true');
                galleryImage.src = '';
            }

            document.addEventListener('click', function(e) {
                const link = e.target.closest('.uexe-gallery-link');
                if (!link) return;
                e.preventDefault();
                let urls = [];
                try { urls = JSON.parse(link.getAttribute('data-gallery') || '[]'); } catch(err) {}
                openGallery(urls.length ? urls : [link.href], parseInt(link.getAttribute('data-index'), 10) || 0);
            });

            galleryModal.addEventListener('click', function(e) {
                if (e.target === galleryModal) closeGallery();
            });
            galleryModal.querySelector('.uexe-gallery-close').addEventListener('click', closeGallery);
            galleryModal.querySelector('.uexe-gallery-prev').addEventListener('click', function() {
                galleryIndex--;
                showGalleryImage();
            });
            galleryModal.querySelector('.uexe-gallery-next').addEventListener('click', function() {
                galleryIndex++;
                showGalleryImage();
            });
            document.addEventListener('keydown', function(e) {
                if (!galleryModal.classList.contains('is-open')) return;
                if (e.key === 'Escape') closeGallery();
                if (e.key === 'ArrowLeft') {
                    galleryIndex--;
                    showGalleryImage();
                }
                if (e.key === 'ArrowRight') {
                    galleryIndex++;
                    showGalleryImage();
                }
            });

            // Upload ảnh
            const uploadForm = document.querySelector('#upload-export-form');
            if (uploadForm) {
                const imageInput = document.querySelector('#ueif_images');
                const noticeEl   = document.querySelector('#ueif-images-notice');
                const errorEl    = document.querySelector('#ueif-images-error');
                const submitBtn  = uploadForm.querySelector('button[type="submit"]');

                imageInput.addEventListener('change', function() {
                    if (imageInput.files.length > 5) {
                        noticeEl.style.display = 'none';
                        errorEl.style.display  = 'inline-block';
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.5';
                    } else {
                        errorEl.style.display  = 'none';
                        noticeEl.style.display = 'inline-block';
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '';
                    }
                });

                function doUpload() {
                    const btn = uploadForm.querySelector('button[type="submit"]');
                    const originalText = btn.textContent;
                    const aiCheckRequested = !!uploadForm.querySelector('[name="ueif_ai_check"]:checked');
                    btn.disabled = true;
                    btn.textContent = aiCheckRequested ? "⏳ Đang upload, đưa AI vào hàng đợi..." : "⏳ Đang upload...";

                    const formData = new FormData(uploadForm);
                    formData.append("action", "ajax_upload_images");
                    formData.append("nonce", ueifNonce);

                    fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                        method: "POST",
                        body: formData
                    })
                    .then(r => r.json())
                    .then(res => {
                        const message = res && res.data && typeof res.data === 'object' ? res.data.message : res.data;
                        uexeShowToast(message, res.success);
                        if (res.success) {
                            uploadForm.reset();
                        }
                    })
                    .catch(() => {
                        uexeShowToast("Lỗi kết nối. Vui lòng thử lại.", false);
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
                }

                uploadForm.addEventListener("submit", function(e) {
                    e.preventDefault();

                    if (imageInput.files.length > 5) {
                        errorEl.style.display  = 'inline-block';
                        noticeEl.style.display = 'none';
                        return;
                    }

                    const modal = document.getElementById('ueif-checklist-modal');
                    if (modal && ueifChecklist.length > 0) {
                        const container = document.getElementById('ueif-checklist-items');
                        container.innerHTML = '';

                        const selectAllDiv = document.createElement('div');
                        selectAllDiv.style.cssText = 'margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #eee;display:flex;align-items:center;gap:8px;';
                        const selectAllCb = document.createElement('input');
                        selectAllCb.type = 'checkbox';
                        selectAllCb.id = 'ueif-chk-all';
                        selectAllCb.style.cssText = 'flex-shrink:0;width:16px;height:16px;cursor:pointer;';
                        const selectAllLbl = document.createElement('label');
                        selectAllLbl.htmlFor = 'ueif-chk-all';
                        selectAllLbl.textContent = 'Tích tất cả';
                        selectAllLbl.style.cssText = 'cursor:pointer;font-size:14px;font-weight:600;';
                        selectAllDiv.appendChild(selectAllCb);
                        selectAllDiv.appendChild(selectAllLbl);
                        container.appendChild(selectAllDiv);

                        function updateConfirmBtn() {
                            const items = container.querySelectorAll('input[type="checkbox"]:not(#ueif-chk-all)');
                            const allChecked = Array.from(items).every(function(c) { return c.checked; });
                            const confirmBtn = document.getElementById('ueif-modal-confirm-btn');
                            confirmBtn.disabled = !allChecked;
                            confirmBtn.style.opacity = allChecked ? '1' : '0.5';
                            selectAllCb.checked = allChecked;
                        }

                        selectAllCb.addEventListener('change', function() {
                            container.querySelectorAll('input[type="checkbox"]:not(#ueif-chk-all)').forEach(function(cb) {
                                cb.checked = selectAllCb.checked;
                            });
                            updateConfirmBtn();
                        });

                        ueifChecklist.forEach(function(item, idx) {
                            const div = document.createElement('div');
                            div.style.cssText = 'margin-bottom:10px;display:flex;align-items:flex-start;gap:8px;';
                            const cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.id = 'ueif-chk-' + idx;
                            cb.style.cssText = 'margin-top:3px;flex-shrink:0;width:16px;height:16px;cursor:pointer;';
                            const lbl = document.createElement('label');
                            lbl.htmlFor = 'ueif-chk-' + idx;
                            lbl.textContent = item;
                            lbl.style.cssText = 'cursor:pointer;font-size:14px;line-height:1.5;';
                            div.appendChild(cb);
                            div.appendChild(lbl);
                            container.appendChild(div);
                            cb.addEventListener('change', updateConfirmBtn);
                        });

                        document.getElementById('ueif-modal-confirm-btn').disabled = true;
                        document.getElementById('ueif-modal-confirm-btn').style.opacity = '0.5';
                        modal.style.display = 'flex';
                    } else {
                        doUpload();
                    }
                });

                const modalConfirmBtn = document.getElementById('ueif-modal-confirm-btn');
                if (modalConfirmBtn) {
                    modalConfirmBtn.addEventListener('click', function() {
                        document.getElementById('ueif-checklist-modal').style.display = 'none';
                        doUpload();
                    });
                }

                const modalCancelBtn = document.getElementById('ueif-modal-cancel-btn');
                if (modalCancelBtn) {
                    modalCancelBtn.addEventListener('click', function() {
                        document.getElementById('ueif-checklist-modal').style.display = 'none';
                    });
                }

                const modal = document.getElementById('ueif-checklist-modal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            modal.style.display = 'none';
                        }
                    });
                }
            }

            // Xác nhận xuất kho
            document.querySelectorAll("form.export-confirm-form").forEach(form => {
                form.addEventListener("submit", function(e) {
                    e.preventDefault();
                    const btn = form.querySelector('button[type="submit"]');
                    const originalText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = "⏳ Đang xử lý...";

                    const formData = new FormData(form);
                    formData.append("action", "ajax_confirm_export");
                    formData.append("nonce", uexeNonce);
                    fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                        method: "POST",
                        body: formData
                    })
                    .then(r => r.json())
                    .then(res => {
                        uexeShowToast(res.data, res.success);
                        if (res.success) {
                            form.outerHTML = '<p><strong style="color:green;">✅ Đã xác nhận</strong></p>';
                        } else {
                            btn.disabled = false;
                            btn.textContent = originalText;
                        }
                    })
                    .catch(function() {
                        uexeShowToast("Lỗi kết nối. Vui lòng thử lại.", false);
                        btn.disabled = false;
                        btn.textContent = originalText;
                    });
                });
            });

            function requestAiCheck(orderId) {
                const formData = new FormData();
                formData.append('action', 'hithean_export_ai_check');
                formData.append('nonce', uexeAiNonce);
                formData.append('order_id', String(orderId));
                return fetch("<?php echo admin_url('admin-ajax.php'); ?>", { method: 'POST', body: formData })
                    .then(function(response) { return response.json(); })
                    .then(function(response) {
                        const message = response && response.data && typeof response.data === 'object' ? response.data.message : response.data;
                        if (!response.success) throw new Error(message || 'Không thể AI check ảnh.');
                        return { message: message, html: response.data.html || '' };
                    });
            }
            function updateAiCheckCard(card, result) {
                const holder = card && card.querySelector('.uexe-ai-check');
                if (holder && result.html) holder.outerHTML = result.html;
            }
            function normalizedOrderCode(value) { return String(value || '').replace(/\D/g, ''); }

            document.addEventListener('click', function(event) {
                const button = event.target.closest('.uexe-ai-check-button');
                if (!button) return;
                const orderId = parseInt(button.getAttribute('data-order-id'), 10) || 0;
                if (!orderId) return;
                const card = button.closest('.uexe-export-card');
                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = '⏳ AI đang kiểm tra...';
                requestAiCheck(orderId).then(function(result) {
                    updateAiCheckCard(card, result);
                    uexeShowToast(result.message, true);
                }).catch(function(error) {
                    uexeShowToast(error.message || 'Lỗi kết nối. Vui lòng thử lại.', false);
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });

            document.addEventListener('click', function(event) {
                const button = event.target.closest('[data-uexe-bulk-check]');
                if (!button || button.disabled) return;
                const controls = button.closest('[data-uexe-ai-bulk]');
                if (!controls) return;
                const target = controls.getAttribute('data-uexe-bulk-target');
                const grid = document.querySelector('[data-uexe-bulk-grid="' + target + '"]');
                const status = controls.querySelector('[data-uexe-bulk-status]');
                const excludeInput = controls.querySelector('[data-uexe-bulk-exclude]');
                if (!grid || !status || !excludeInput) return;
                const excluded = new Set(excludeInput.value.split(/[\s,;]+/).map(normalizedOrderCode).filter(Boolean));
                const queue = Array.from(grid.querySelectorAll('.uexe-export-card')).filter(function(card) {
                    return !excluded.has(normalizedOrderCode(card.getAttribute('data-uexe-order-id')))
                        && !excluded.has(normalizedOrderCode(card.getAttribute('data-uexe-order-number')));
                });
                if (!queue.length) {
                    status.textContent = excluded.size ? 'Không có đơn cần check sau khi loại trừ.' : 'Không có đơn cần check.';
                    return;
                }
                const originalText = button.textContent;
                button.disabled = true;
                excludeInput.disabled = true;
                let completed = 0, failed = 0;
                (async function() {
                    for (const card of queue) {
                        const orderId = parseInt(card.getAttribute('data-uexe-order-id'), 10) || 0;
                        status.textContent = 'Đang AI check ' + (completed + 1) + '/' + queue.length + ' — đơn #' + orderId + '…';
                        const individualButton = card.querySelector('.uexe-ai-check-button');
                        if (individualButton) { individualButton.disabled = true; individualButton.textContent = '⏳ Đang check…'; }
                        try {
                            updateAiCheckCard(card, await requestAiCheck(orderId));
                        } catch (error) {
                            failed++;
                            if (individualButton) { individualButton.disabled = false; individualButton.textContent = 'AI check lại'; }
                        }
                        completed++;
                    }
                    status.textContent = 'Đã AI check ' + completed + '/' + queue.length + ' đơn' + (failed ? '; lỗi ' + failed + ' đơn.' : '.');
                    uexeShowToast(status.textContent, failed === 0);
                    button.disabled = false;
                    button.textContent = originalText;
                    excludeInput.disabled = false;
                }());
            });
        });
    </script>
<?php
});

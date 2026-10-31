<?php
if (!defined('ABSPATH')) exit;

/**
 * Nhập thông tin xuất VAT cho đơn (nút "🧾 Nhập VAT" ở bảng điều phối, cột Thao tác, xem nhanh — popup
 * assets/order-vat-request.js). Dùng chung theanmarket + hithean; cần plugin eim (vn-einvoice-manager).
 *
 * Luồng: BẮT BUỘC up ảnh chụp tin nhắn thực tế của khách (≤ 5 ảnh) → mới mở ô nhập MST / công ty / địa chỉ / email
 * (có "🤖 Bóc tách bằng AI" đọc ảnh) → lưu:
 * - thông tin người mua vào meta eim `_eim_buyer_*` (giống khung HĐ VAT của eim trên trang sửa đơn, đánh dấu chốt tay);
 * - `_eim_vat_request` = {at, by, proof: [url ảnh], note} → eim gửi kèm khi hub đồng bộ, hub tự đưa vào "VAT chờ khớp"
 *   (eim_order_vat_request / eim_pending_vat_from_site_request).
 * Đơn đã vào lô / đã xuất HĐ thì khóa (như eim). Quyền: manage_woocommerce.
 *
 * AJAX: thean_vat_request_get, thean_vat_request_upload, thean_vat_request_ai, thean_vat_request_save.
 */

const THEAN_VAT_PROOF_META = '_thean_vat_proof_ids'; // ID attachment ảnh tin nhắn khách
const THEAN_VAT_PROOF_MAX  = 5;

/** Có thể nhập VAT (plugin eim đang chạy + quyền)? */
function thean_vat_request_enabled(): bool
{
    return function_exists('eim_set_order_buyer') && current_user_can('manage_woocommerce');
}

add_filter('theme_ai_feature_registry', function ($features) {
    $features['order_vat_ai_extract'] = [
        'label'       => 'Đơn hàng — AI bóc thông tin xuất VAT',
        'description' => 'Nút "🤖 Bóc tách bằng AI" trong popup Nhập VAT (bảng điều phối / ô tìm đơn): đọc ảnh tin nhắn của khách, điền MST, công ty, địa chỉ, email.',
    ];
    return $features;
});

/** Đơn đã vào lô / đã xuất HĐ → khóa sửa thông tin người mua (như khung HĐ VAT của eim). */
function thean_vat_request_locked(WC_Order $order): bool
{
    return in_array((string) $order->get_meta('_eim_status'), ['issued', 'in_batch'], true) || (string) $order->get_meta('_eim_invoice_no') !== '';
}

/** @return int[] */
function thean_vat_request_proof_ids(WC_Order $order): array
{
    return array_values(array_filter(array_map('intval', (array) $order->get_meta(THEAN_VAT_PROOF_META))));
}

/** Dữ liệu cho popup. */
function thean_vat_request_data(WC_Order $order): array
{
    $proof = [];
    foreach (thean_vat_request_proof_ids($order) as $id) {
        $url = wp_get_attachment_url($id);
        if ($url) {
            $proof[] = ['id' => $id, 'url' => $url, 'thumb' => wp_get_attachment_image_url($id, 'thumbnail') ?: $url];
        }
    }
    $request = json_decode((string) $order->get_meta('_eim_vat_request'), true);
    return [
        'order_id' => $order->get_id(),
        'number'   => $order->get_order_number(),
        'locked'   => thean_vat_request_locked($order),
        'status'   => function_exists('eim_order_vat_status_label') ? wp_strip_all_tags(eim_order_vat_status_label($order)) : '',
        'buyer'    => [
            'tax_code' => (string) $order->get_meta('_eim_buyer_tax_code'),
            'company'  => (string) $order->get_meta('_eim_buyer_company'),
            'address'  => (string) $order->get_meta('_eim_buyer_address'),
            'email'    => (string) $order->get_meta('_eim_buyer_email') ?: $order->get_billing_email(),
        ],
        'proof'    => $proof,
        'request'  => is_array($request) ? $request : null,
    ];
}

/** Kiểm tra AJAX chung: nonce + quyền + đơn. */
function thean_vat_request_ajax_order(): WC_Order
{
    check_ajax_referer('thean_vat_request', 'nonce');
    if (!thean_vat_request_enabled()) {
        wp_send_json_error(['message' => 'Không có quyền hoặc chưa bật plugin hóa đơn (eim).'], 403);
    }
    $order = wc_get_order(absint($_POST['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }
    return $order;
}

add_action('wp_ajax_thean_vat_request_get', function (): void {
    wp_send_json_success(thean_vat_request_data(thean_vat_request_ajax_order()));
});

// Up ảnh tin nhắn khách (bắt buộc trước khi nhập). Thêm vào danh sách ảnh của đơn, tối đa THEAN_VAT_PROOF_MAX.
add_action('wp_ajax_thean_vat_request_upload', function (): void {
    $order = thean_vat_request_ajax_order();
    if (thean_vat_request_locked($order)) {
        wp_send_json_error(['message' => 'Đơn đã vào lô / đã xuất HĐ — không sửa thông tin VAT được.']);
    }
    $files = $_FILES['images'] ?? null;
    if (!is_array($files) || empty($files['name'])) {
        wp_send_json_error(['message' => 'Chọn ảnh chụp tin nhắn của khách.']);
    }
    $ids = thean_vat_request_proof_ids($order);
    $names = (array) $files['name'];
    if (count($ids) + count($names) > THEAN_VAT_PROOF_MAX) {
        wp_send_json_error(['message' => sprintf('Tối đa %d ảnh / đơn.', THEAN_VAT_PROOF_MAX)]);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    foreach (array_keys($names) as $i) {
        $type = (string) ((array) $files['type'])[$i];
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            wp_send_json_error(['message' => 'Chỉ nhận ảnh JPG / PNG / WebP.']);
        }
        $_FILES['thean_vat_one'] = [
            'name'     => ((array) $files['name'])[$i],
            'type'     => $type,
            'tmp_name' => ((array) $files['tmp_name'])[$i],
            'error'    => ((array) $files['error'])[$i],
            'size'     => ((array) $files['size'])[$i],
        ];
        $id = media_handle_upload('thean_vat_one', 0, ['post_title' => sprintf('VAT đơn #%d — tin nhắn khách', $order->get_id())]);
        if (is_wp_error($id)) {
            wp_send_json_error(['message' => 'Không lưu được ảnh: ' . $id->get_error_message()]);
        }
        update_post_meta($id, '_thean_vat_order_id', $order->get_id());
        $ids[] = (int) $id;
    }
    $order->update_meta_data(THEAN_VAT_PROOF_META, $ids);
    $order->save_meta_data();
    wp_send_json_success(thean_vat_request_data($order));
});

/** Bóc JSON từ trả lời của AI (có thể bọc ```json). */
function thean_vat_request_parse_ai(string $text): array
{
    if (preg_match('/\{.*\}/s', $text, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) {
            return [
                'tax_code' => preg_replace('/[^0-9\-]/', '', (string) ($data['tax_code'] ?? '')),
                'company'  => trim((string) ($data['company'] ?? '')),
                'address'  => trim((string) ($data['address'] ?? '')),
                'email'    => trim((string) ($data['email'] ?? '')),
                'note'     => trim((string) ($data['note'] ?? '')),
            ];
        }
    }
    return [];
}

add_action('wp_ajax_thean_vat_request_ai', function (): void {
    $order = thean_vat_request_ajax_order();
    $ids   = thean_vat_request_proof_ids($order);
    if (!$ids) {
        wp_send_json_error(['message' => 'Up ảnh tin nhắn của khách trước.']);
    }
    $dir = get_stylesheet_directory() . '/custom-functions/core/';
    require_once $dir . 'ai-settings.php';
    require_once $dir . 'ai-providers.php';
    if (!theme_ai_feature_enabled('order_vat_ai_extract')) {
        wp_send_json_error(['message' => 'Tính năng AI bóc thông tin VAT đang tắt (Cài đặt ERP → AI).']);
    }
    $docs = [];
    foreach ($ids as $i => $id) {
        $path = get_attached_file($id);
        if ($path && is_readable($path)) {
            $docs[] = ['path' => $path, 'mime_type' => (string) get_post_mime_type($id), 'title' => 'Ảnh tin nhắn ' . ($i + 1)];
        }
    }
    if (!$docs) {
        wp_send_json_error(['message' => 'Không đọc được file ảnh đã up.']);
    }
    $system = 'Bạn đọc ảnh chụp tin nhắn của khách hàng Việt Nam yêu cầu xuất hóa đơn VAT. Chỉ trích thông tin CÓ TRONG ẢNH, không đoán.';
    $prompt = 'Trích thông tin xuất hóa đơn VAT từ các ảnh. Trả về DUY NHẤT 1 JSON: {"tax_code":"mã số thuế (10 hoặc 13 số, giữ dấu -)",'
        . '"company":"tên công ty / đơn vị / người mua đúng như khách ghi","address":"địa chỉ xuất hóa đơn","email":"email nhận hóa đơn",'
        . '"note":"ghi chú ngắn nếu thông tin mơ hồ / thiếu / có nhiều phương án"}. Trường không có trong ảnh để chuỗi rỗng.';
    $res = theme_ai_call_provider_with_documents(theme_ai_default_provider(), $system, $prompt, $docs, 800, 90, theme_ai_default_model());
    if (is_wp_error($res)) {
        wp_send_json_error(['message' => 'AI lỗi: ' . $res->get_error_message()]);
    }
    $data = thean_vat_request_parse_ai((string) $res);
    if (!$data) {
        wp_send_json_error(['message' => 'AI không trả về thông tin đọc được — nhập tay.']);
    }
    wp_send_json_success($data);
});

add_action('wp_ajax_thean_vat_request_save', function (): void {
    $order = thean_vat_request_ajax_order();
    if (thean_vat_request_locked($order)) {
        wp_send_json_error(['message' => 'Đơn đã vào lô / đã xuất HĐ — không sửa thông tin VAT được.']);
    }
    $ids = thean_vat_request_proof_ids($order);
    if (!$ids) {
        wp_send_json_error(['message' => 'Bắt buộc up ảnh chụp tin nhắn thực tế của khách trước khi lưu.']);
    }
    $tax = sanitize_text_field(wp_unslash($_POST['tax_code'] ?? ''));
    $tax = function_exists('eim_normalize_mst') ? eim_normalize_mst($tax) : preg_replace('/[^0-9\-]/', '', $tax);
    if ($tax !== '' && function_exists('eim_is_valid_mst') && !eim_is_valid_mst($tax)) {
        wp_send_json_error(['message' => 'MST "' . $tax . '" không hợp lệ.']);
    }
    $company = sanitize_text_field(wp_unslash($_POST['company'] ?? ''));
    $address = sanitize_text_field(wp_unslash($_POST['address'] ?? ''));
    $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $note    = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));
    if ($tax === '' && $company === '') {
        wp_send_json_error(['message' => 'Cần ít nhất MST hoặc tên người mua.']);
    }
    // Có MST mà thiếu tên / địa chỉ → tra MST (eim) để điền.
    if ($tax !== '' && ($company === '' || $address === '') && function_exists('eim_lookup_mst')) {
        $lk = eim_lookup_mst($tax);
        if (($lk['status'] ?? '') === 'ok') {
            $company = $company !== '' ? $company : (string) ($lk['company'] ?? '');
            $address = $address !== '' ? $address : (string) ($lk['address'] ?? '');
        }
    }

    // Như khung HĐ VAT của eim: đổi MST = chốt tay (bỏ fallback hồ sơ VAT của khách).
    if ($tax !== (string) $order->get_meta('_eim_buyer_tax_code')) {
        $order->update_meta_data('_eim_buyer_manual', '1');
    }
    eim_set_order_buyer($order, [
        '_eim_buyer_tax_code' => $tax,
        '_eim_buyer_company'  => $company,
        '_eim_buyer_address'  => $address,
        '_eim_buyer_email'    => $email,
    ]);
    $order->update_meta_data('_eim_invoice_template', $tax !== '' ? 'company' : 'mtt');

    $user  = wp_get_current_user();
    $proof = array_values(array_filter(array_map('wp_get_attachment_url', $ids)));
    $order->update_meta_data('_eim_vat_request', wp_json_encode([
        'at'    => current_time('mysql'),
        'by'    => $user ? $user->display_name : '',
        'proof' => $proof,
        'note'  => $note,
    ]));
    $order->add_order_note(sprintf(
        '🧾 Nhập thông tin xuất VAT: %s%s%s (kèm %d ảnh tin nhắn khách).%s',
        $tax !== '' ? 'MST ' . $tax . ' · ' : '',
        $company,
        $email !== '' ? ' · ' . $email : '',
        count($proof),
        $note !== '' ? ' Ghi chú: ' . $note : ''
    ), false, true);
    $order->save();
    wp_send_json_success(['message' => 'Đã lưu thông tin xuất VAT — hub nhận khi đồng bộ và đưa vào "VAT chờ khớp".'] + thean_vat_request_data($order));
});

/** Nút mở popup (dùng ở ô tìm đơn / board / bảng điều phối). */
function thean_vat_request_button(WC_Order $order, string $class = 'thean-ous-btn thean-ous-btn--view'): string
{
    if (!thean_vat_request_enabled()) {
        return '';
    }
    $has = (string) $order->get_meta('_eim_buyer_tax_code') !== '' || (string) $order->get_meta('_eim_vat_request') !== '';
    return '<button type="button" class="' . esc_attr($class) . '" data-thean-vat-request data-order-id="' . esc_attr($order->get_id()) . '">'
        . ($has ? '🧾 Sửa VAT' : '🧾 Nhập VAT') . '</button>';
}

/** Nạp popup (assets/order-vat-request.js). Trang sửa đơn: reload = true. */
function thean_vat_request_enqueue(bool $reload): void
{
    if (!thean_vat_request_enabled()) {
        return;
    }
    $dir = get_stylesheet_directory() . '/custom-functions/woocommerce/orders/assets/';
    $uri = get_stylesheet_directory_uri() . '/custom-functions/woocommerce/orders/assets/';
    wp_enqueue_script('thean-order-vat-request', $uri . 'order-vat-request.js', [], (string) @filemtime($dir . 'order-vat-request.js'), true);
    wp_add_inline_script('thean-order-vat-request', 'window.theanVatCfg = ' . wp_json_encode([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('thean_vat_request'),
        'reload'  => $reload,
        'max'     => THEAN_VAT_PROOF_MAX,
    ]) . ';', 'before');
}

<?php
/**
 * Up ảnh xuất kho nhiều đơn website 1 lần — AI tự tách theo đơn.
 * Nạp cùng shortcodes/shortcode-order-export-confirm.php (form [upload_export_images_form] → Nhiều đơn). Port từ theanmarket (woocommerce/shipping/export-bulk-web.php).
 *
 * 1. hithean_export_bulk_upload : ảnh vào "lô" (attachment chưa gắn đơn, meta _uexe_bulk / _uexe_bulk_seq).
 * 2. hithean_export_bulk_read   : AI đọc mã đơn trên phiếu / nhãn giao vận từng ảnh (≤ 6 ảnh / lần gọi)
 *                               → ID đơn: "#10276**" (phiếu che 2 số cuối), "10276.1" (kiện), mã vận đơn trong order_ship_code.
 * 3. hithean_export_bulk_group  : gom theo ID đơn (ảnh không thấy mã theo ảnh chụp liền trước), gắn ảnh vào
 *                               warehouse_export_images của đơn. Client chạy AI kiểm tra từng đơn (hithean_export_ai_check) như upload 1 đơn.
 *
 * An toàn: chỉ gắn vào đơn có thật, đang chờ xuất kho, chưa xác nhận. Ảnh có mã nhưng không hợp lệ
 * KHÔNG đi theo ảnh trước — vào danh sách "không gán được" để gán tay.
 */
if (!defined('ABSPATH')) exit;

defined('HITHEAN_EXPORT_BULK_MAX') || define('HITHEAN_EXPORT_BULK_MAX', 60);

/** Trạng thái đơn không nhận ảnh xuất kho nữa. */
function hithean_export_bulk_closed_statuses(): array
{
    return ['shipping', 'delivered', 'completed', 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'];
}

/**
 * Đơn có nhận ảnh xuất kho không. @return string '' nếu được, ngược lại là lý do.
 */
function hithean_export_bulk_order_problem(int $order_id): string
{
    $order = $order_id ? wc_get_order($order_id) : null;
    if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') {
        return 'Không có đơn #' . $order_id;
    }
    if (in_array($order->get_status(), hithean_export_bulk_closed_statuses(), true)) {
        return 'Đơn #' . $order_id . ' đang ' . wc_get_order_status_name($order->get_status());
    }
    if (get_post_meta($order_id, 'export_confirmed_by', true)) {
        return 'Đơn #' . $order_id . ' đã xác nhận xuất kho';
    }
    return '';
}

/**
 * Mã AI đọc được → ID đơn (0 = không phải mã đơn).
 * "#10276**" / "10276**" → 10276 · "10276.1" → 10276 · mã vận đơn → tra order_ship_code (chỉ nhận khi đúng 1 đơn).
 */
function hithean_export_bulk_resolve_ref(string $ref): int
{
    $ref = preg_replace('/\s+/', '', $ref);
    if (preg_match('/^#?(\d{3,9})\*{1,3}$/', $ref, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^#?(\d{3,9})\.\d{1,3}$/', $ref, $m)) {
        return (int) $m[1];
    }
    if (strlen($ref) >= 8 && preg_match('/[A-Za-z]/', $ref) && function_exists('wc_get_orders')) {
        $ids = wc_get_orders([
            'limit'      => 2,
            'return'     => 'ids',
            'status'     => array_keys(wc_get_order_statuses()),
            'meta_query' => [['key' => 'order_ship_code', 'value' => $ref, 'compare' => 'LIKE']],
        ]);
        return count($ids) === 1 ? (int) $ids[0] : 0;
    }
    return 0;
}

/**
 * Gom ảnh theo khóa (hàm thuần). Ảnh key '' (không thấy mã) theo ảnh liền trước có khóa, đầu lô theo ảnh liền sau.
 *
 * @param array $images [{id, seq, key}] — key '' = không thấy mã.
 * @return array{groups: array<string,array{images:int[],by_sequence:int[]}>, unassigned:int[]}
 */
function hithean_export_bulk_group_by_key(array $images): array
{
    usort($images, static function ($a, $b) {
        return ((int) $a['seq'] <=> (int) $b['seq']) ?: ((int) $a['id'] <=> (int) $b['id']);
    });
    $keys = array_map(static function ($img) {
        return (string) $img['key'] !== '' ? (string) $img['key'] : null;
    }, $images);

    $count = count($images);
    $by_sequence = [];
    for ($i = 0; $i < $count; $i++) {
        if ($keys[$i] !== null) {
            continue;
        }
        $key = null;
        for ($j = $i - 1; $j >= 0 && $key === null; $j--) {
            if ($keys[$j] !== null && !isset($by_sequence[$j])) {
                $key = $keys[$j];
            }
        }
        for ($j = $i + 1; $j < $count && $key === null; $j++) {
            if ($keys[$j] !== null && !isset($by_sequence[$j])) {
                $key = $keys[$j];
            }
        }
        if ($key !== null) {
            $keys[$i] = $key;
            $by_sequence[$i] = true;
        }
    }

    $groups = [];
    $unassigned = [];
    foreach ($images as $i => $img) {
        if ($keys[$i] === null) {
            $unassigned[] = (int) $img['id'];
            continue;
        }
        $groups[$keys[$i]]['images'][] = (int) $img['id'];
        $groups[$keys[$i]]['by_sequence'] = $groups[$keys[$i]]['by_sequence'] ?? [];
        if (isset($by_sequence[$i])) {
            $groups[$keys[$i]]['by_sequence'][] = (int) $img['id'];
        }
    }
    return ['groups' => $groups, 'unassigned' => $unassigned];
}

function hithean_export_bulk_id_clean(string $batch): string
{
    return preg_match('/^[a-z0-9]{8,32}$/', $batch) ? $batch : '';
}

/** @return int[] attachment của lô (chưa gắn đơn), theo thứ tự chụp. */
function hithean_export_bulk_attachments(string $batch): array
{
    if ($batch === '') {
        return [];
    }
    $ids = array_map('intval', get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => HITHEAN_EXPORT_BULK_MAX,
        'fields'         => 'ids',
        'meta_key'       => '_uexe_bulk',
        'meta_value'     => $batch,
        'no_found_rows'  => true,
    ]));
    if ($ids) {
        update_meta_cache('post', $ids);
    }
    usort($ids, static function ($a, $b) {
        return ((int) get_post_meta($a, '_uexe_bulk_seq', true) <=> (int) get_post_meta($b, '_uexe_bulk_seq', true)) ?: ($a <=> $b);
    });
    return $ids;
}

function hithean_export_bulk_image_for_client(int $attachment_id, string $reason = ''): array
{
    return ['id' => $attachment_id, 'url' => (string) wp_get_attachment_url($attachment_id), 'reason' => $reason];
}

/**
 * Gắn ảnh lô vào đơn: thêm URL vào warehouse_export_images, đổi parent ảnh, xóa kết quả AI cũ.
 *
 * @return array{status:string, message?:string, order_id?:int}
 */
function hithean_export_bulk_attach(int $order_id, array $attachment_ids, int $by_sequence = 0): array
{
    $problem = hithean_export_bulk_order_problem($order_id);
    if ($problem !== '') {
        return ['status' => 'skipped', 'message' => $problem];
    }
    $urls = [];
    foreach ($attachment_ids as $attachment_id) {
        $url = wp_get_attachment_url((int) $attachment_id);
        if (!$url) {
            continue;
        }
        wp_update_post(['ID' => (int) $attachment_id, 'post_parent' => $order_id]);
        delete_post_meta((int) $attachment_id, '_uexe_bulk');
        $urls[] = $url;
    }
    if (!$urls) {
        return ['status' => 'skipped', 'message' => 'Không còn ảnh hợp lệ.'];
    }
    $existing = (string) get_post_meta($order_id, 'warehouse_export_images', true);
    $all = array_values(array_unique(array_filter(array_merge(explode("\n", $existing), $urls))));
    update_post_meta($order_id, 'warehouse_export_images', implode("\n", $all));
    delete_post_meta($order_id, 'warehouse_export_ai_check');
    delete_post_meta($order_id, 'warehouse_export_ai_review_status');

    $order = wc_get_order($order_id);
    if ($order) {
        $order->add_order_note('📷 Upload ' . count($urls) . ' ảnh xuất kho (lô nhiều đơn, AI tự tách' . ($by_sequence ? '; ' . $by_sequence . ' ảnh gán theo thứ tự chụp' : '') . ') bởi ' . wp_get_current_user()->display_name);
    }
    return ['status' => 'appended', 'order_id' => $order_id];
}

function hithean_export_bulk_package_for_client(int $order_id, array $result, int $image_count, int $by_sequence): array
{
    $order = $order_id ? wc_get_order($order_id) : null;
    return [
        'status'      => $result['status'],
        'message'     => $result['message'] ?? '',
        'code'        => '#' . $order_id,
        'image_count' => $image_count,
        'by_sequence' => $by_sequence,
        'order'       => $order ? [
            'id'       => $order_id,
            'number'   => $order->get_order_number(),
            'customer' => $order->get_formatted_billing_full_name(),
            'url'      => $order->get_edit_order_url(),
        ] : null,
    ];
}

/* ------------------------------------------------------------------ */
/* AJAX                                                                */
/* ------------------------------------------------------------------ */

function hithean_export_bulk_guard(): void
{
    if (!check_ajax_referer('hithean_export_bulk', 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên làm việc hết hạn, tải lại trang.'], 403);
    }
    if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thao tác.'], 403);
    }
}

function hithean_export_bulk_request_ids(string $batch): array
{
    return array_values(array_intersect(
        array_map('intval', (array) json_decode(wp_unslash((string) ($_POST['ids'] ?? '[]')), true)),
        hithean_export_bulk_attachments($batch)
    ));
}

add_action('wp_ajax_hithean_export_bulk_upload', function () {
    hithean_export_bulk_guard();
    $files = [];
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
        foreach ($_FILES['images']['name'] as $i => $name) {
            $files[] = [
                'name'     => $name,
                'type'     => $_FILES['images']['type'][$i] ?? '',
                'tmp_name' => $_FILES['images']['tmp_name'][$i] ?? '',
                'error'    => $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES['images']['size'][$i] ?? 0,
            ];
        }
    }
    if (!$files || count($files) > 6) {
        wp_send_json_error(['message' => 'Mỗi lần gửi 1–6 ảnh.']);
    }
    $batch = hithean_export_bulk_id_clean(sanitize_key($_POST['batch'] ?? ''));
    if ($batch === '') {
        $batch = substr(str_replace('-', '', wp_generate_uuid4()), 0, 16);
    } elseif (count(hithean_export_bulk_attachments($batch)) + count($files) > HITHEAN_EXPORT_BULK_MAX) {
        wp_send_json_error(['message' => 'Tối đa ' . HITHEAN_EXPORT_BULK_MAX . ' ảnh mỗi lô.']);
    }
    $seqs = array_map('intval', (array) json_decode(wp_unslash((string) ($_POST['seqs'] ?? '[]')), true));

    $images = [];
    $errors = [];
    foreach ($files as $i => $file) {
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        // Chưa biết đơn → parent 0, gắn parent khi tách xong. Cùng cỡ 1000px với ảnh xuất kho 1 đơn.
        $attachment_id = hithean_upload_internal_order_image($file, 0, 'xuat-kho-lo-' . $batch . '-' . ($seqs[$i] ?? $i) . '.' . $ext);
        if (is_wp_error($attachment_id)) {
            $errors[] = $file['name'] . ': ' . $attachment_id->get_error_message();
            continue;
        }
        update_post_meta($attachment_id, '_uexe_bulk', $batch);
        update_post_meta($attachment_id, '_uexe_bulk_seq', $seqs[$i] ?? $i);
        $images[] = hithean_export_bulk_image_for_client((int) $attachment_id);
    }
    wp_send_json_success(['batch' => $batch, 'images' => $images, 'errors' => $errors]);
});

add_action('wp_ajax_hithean_export_bulk_read', function () {
    hithean_export_bulk_guard();
    $batch = hithean_export_bulk_id_clean(sanitize_key($_POST['batch'] ?? ''));
    $ids = array_slice(hithean_export_bulk_request_ids($batch), 0, 6);
    if (!$ids) {
        wp_send_json_error(['message' => 'Không có ảnh hợp lệ trong lô.']);
    }
    require_once get_stylesheet_directory() . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('export_image_ai_check')) {
        wp_send_json_error(['message' => 'AI kiểm tra ảnh xuất kho đang tắt trong Cài đặt ERP.']);
    }

    $documents = [];
    $order = [];
    foreach ($ids as $attachment_id) {
        $path = get_attached_file($attachment_id);
        $mime = (string) get_post_mime_type($attachment_id);
        if ($path && is_readable($path) && strpos($mime, 'image/') === 0 && filesize($path) <= 6 * MB_IN_BYTES) {
            $documents[] = ['path' => $path, 'mime_type' => $mime, 'title' => basename($path)];
            $order[] = $attachment_id;
        }
    }
    if (!$documents) {
        wp_send_json_error(['message' => 'Không đọc được ảnh nào trong lô.']);
    }

    require_once get_stylesheet_directory() . '/custom-functions/core/ai-providers.php';
    $system = 'Bạn là trợ lý kho của HiThean. Mỗi ảnh là ảnh lấy hàng / đóng gói 1 đơn website. '
        . 'Với TỪNG ảnh (đúng thứ tự gửi, index từ 0), liệt kê nguyên văn mọi mã đơn nhìn thấy rõ: '
        . 'mã trên phiếu xuất kho (dạng #ID** — phiếu cố ý che 2 số cuối bằng **, vd #10276**), '
        . 'mã kiện trên nhãn giao vận (dạng ID.N, vd 10276.1), mã vận đơn của đơn vị vận chuyển (GHTK, GHN, Viettel Post, J&T, Ahamove…). '
        . 'Không đưa số điện thoại, mã sản phẩm, mã vạch sản phẩm. Ký tự không chắc thì bỏ mã đó, không đoán. '
        . 'kind: slip = có phiếu xuất kho / nhãn, box = thùng đã đóng gói, product = chỉ có sản phẩm, other = khác. '
        . 'Trả về DUY NHẤT JSON: {"images":[{"index":0,"refs":["#10276**"],"kind":"slip"}]}';
    $prompt = 'Có ' . count($documents) . ' ảnh. Trả đủ ' . count($documents) . ' phần tử images.';

    $cfg = hithean_export_ai_config();
    $raw = theme_ai_feature_call($cfg, static function (string $provider, string $model, string $api_key) use ($system, $prompt, $documents) {
        return theme_ai_call_provider_with_documents($provider, $system, $prompt, $documents, 700, 120, $model, ['api_key' => $api_key]);
    });
    if (is_wp_error($raw)) {
        wp_send_json_error(['message' => $raw->get_error_message()]);
    }
    $data = theme_ai_parse_json_object($raw);
    if (is_wp_error($data)) {
        wp_send_json_error(['message' => $data->get_error_message()]);
    }

    $seen = [];
    foreach ((array) ($data['images'] ?? []) as $img) {
        $idx = (int) ($img['index'] ?? -1);
        if (!isset($order[$idx])) {
            continue;
        }
        $refs = array_slice(array_values(array_filter(array_map(static function ($r) {
            return mb_substr(sanitize_text_field(is_scalar($r) ? (string) $r : ''), 0, 60);
        }, (array) ($img['refs'] ?? [])))), 0, 6);
        update_post_meta($order[$idx], '_uexe_bulk_read', ['refs' => $refs, 'kind' => sanitize_key((string) ($img['kind'] ?? 'other'))]);
        $seen[$order[$idx]] = true;
    }
    foreach ($order as $attachment_id) {
        if (empty($seen[$attachment_id])) {
            update_post_meta($attachment_id, '_uexe_bulk_read', ['refs' => [], 'kind' => 'other']);
        }
    }
    wp_send_json_success(['read' => count($order)]);
});

add_action('wp_ajax_hithean_export_bulk_group', function () {
    hithean_export_bulk_guard();
    $batch = hithean_export_bulk_id_clean(sanitize_key($_POST['batch'] ?? ''));
    $attachments = hithean_export_bulk_attachments($batch);
    if (!$attachments) {
        wp_send_json_error(['message' => 'Lô không còn ảnh chưa gắn đơn.']);
    }

    $images = [];
    $invalid = [];
    $resolved = [];
    foreach ($attachments as $attachment_id) {
        $read = (array) get_post_meta($attachment_id, '_uexe_bulk_read', true);
        $ids = [];
        foreach ((array) ($read['refs'] ?? []) as $ref) {
            $key = (string) $ref;
            if (!isset($resolved[$key])) {
                $resolved[$key] = hithean_export_bulk_resolve_ref($key);
            }
            if ($resolved[$key]) {
                $ids[$resolved[$key]] = true;
            }
        }
        $ids = array_keys($ids);
        $key = '';
        if (count($ids) > 1) {
            $invalid[$attachment_id] = 'Ảnh có mã của nhiều đơn: #' . implode(', #', $ids);
            continue;
        }
        if ($ids) {
            $problem = hithean_export_bulk_order_problem((int) $ids[0]);
            if ($problem !== '') {
                $invalid[$attachment_id] = $problem;
                continue;
            }
            $key = (string) $ids[0];
        } elseif (!empty($read['refs'])) {
            // Có mã nhưng không ra đơn nào → không cho đi theo ảnh trước (dễ gắn nhầm).
            $invalid[$attachment_id] = 'Không khớp đơn: ' . implode(', ', (array) $read['refs']);
            continue;
        }
        $images[] = ['id' => $attachment_id, 'seq' => (int) get_post_meta($attachment_id, '_uexe_bulk_seq', true), 'key' => $key];
    }

    $grouped = hithean_export_bulk_group_by_key($images);
    $packages = [];
    foreach ($grouped['groups'] as $order_id => $group) {
        $result = hithean_export_bulk_attach((int) $order_id, $group['images'], count($group['by_sequence']));
        $packages[] = hithean_export_bulk_package_for_client((int) $order_id, $result, count($group['images']), count($group['by_sequence']));
    }

    $unassigned = array_map('hithean_export_bulk_image_for_client', $grouped['unassigned']);
    foreach ($invalid as $attachment_id => $reason) {
        $unassigned[] = hithean_export_bulk_image_for_client((int) $attachment_id, $reason);
    }
    wp_send_json_success(['packages' => $packages, 'unassigned' => $unassigned]);
});

add_action('wp_ajax_hithean_export_bulk_assign', function () {
    hithean_export_bulk_guard();
    $batch = hithean_export_bulk_id_clean(sanitize_key($_POST['batch'] ?? ''));
    $ids = hithean_export_bulk_request_ids($batch);
    $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
    $order_id = preg_match('/^#?\d+$/', trim($code)) ? absint(ltrim(trim($code), '#')) : hithean_export_bulk_resolve_ref($code);
    if (!$ids || !$order_id) {
        wp_send_json_error(['message' => 'Chọn ảnh và nhập ID đơn (vd 10276) hoặc mã vận đơn.']);
    }
    $result = hithean_export_bulk_attach($order_id, $ids);
    if ($result['status'] === 'skipped') {
        wp_send_json_error(['message' => $result['message'] ?? 'Không gán được.']);
    }
    wp_send_json_success(hithean_export_bulk_package_for_client($order_id, $result, count($ids), 0));
});

add_action('wp_ajax_hithean_export_bulk_discard', function () {
    hithean_export_bulk_guard();
    $batch = hithean_export_bulk_id_clean(sanitize_key($_POST['batch'] ?? ''));
    $deleted = 0;
    foreach (hithean_export_bulk_request_ids($batch) as $attachment_id) {
        if (wp_delete_attachment($attachment_id, true)) {
            $deleted++;
        }
    }
    wp_send_json_success(['deleted' => $deleted]);
});

<?php
/**
 * HPOS: giữ meta do code cũ ghi thẳng post meta (update_post_meta) khớp với bảng meta HPOS.
 * Dùng chung theanmarket + hithean (file giống hệt nhau).
 *
 * Lỗi gốc: luồng xuất kho / giao hàng / xác nhận thanh toán ghi `update_post_meta()`; trên HPOS bảng posts chỉ là
 * bản sao — WC KHÔNG kéo post meta sang HPOS (sync on read chỉ chạy khi post_modified mới hơn, ghi meta không đổi
 * post_modified). Trang sửa đơn + Meta Box đọc HPOS → ô "Ảnh xuất kho" trống; bấm Cập nhật đơn → Meta Box ghi giá
 * trị trống vào HPOS → WC đồng bộ HPOS → posts xóa luôn bản ở posts. Đơn đã upload ảnh + xác nhận rơi vào
 * "Thiếu chứng từ XK" (VD hithean #10786).
 *
 * Xử lý:
 *  1. Mọi lượt ghi / xóa post meta của các key dưới trên đơn → ghi tương ứng sang HPOS (thean_legacy_meta_mirror).
 *  2. Đơn đang lệch (posts có, HPOS trống) → kéo sang HPOS khi mở trang sửa đơn / khi kiểm tra chứng từ
 *     (thean_legacy_meta_reconcile).
 *  3. Đơn đã mất cả 2 bên → khôi phục ảnh từ file đính kèm của đơn (order-export-image-<id>-… / xuat-kho-lo-…) và
 *     người xác nhận từ ghi chú "Đã xác nhận (ảnh) xuất kho bởi <tên>" (thean_export_proof_recover).
 *  4. Meta Box không được lưu ô "Ảnh xuất kho" trống đè lên ảnh đã có.
 *
 * Test: php tests/order-universal-search.php
 */
if (!defined('ABSPATH')) {
    exit;
}

/** Key do code cũ ghi bằng update_post_meta trên đơn (cả tên theanmarket lẫn tên "order_*" của hithean). */
function thean_legacy_meta_keys(): array
{
    return (array) apply_filters('thean_legacy_meta_keys', [
        'warehouse_export_images', 'warehouse_export_uploaded_by', 'warehouse_export_ai_check', 'warehouse_export_ai_review_status',
        'warehouse_export_ship_codes_log', 'warehouse_export_lark_status', 'warehouse_export_lark_notified_at',
        'export_confirmed_by', 'export_by', 'order_export_by', 'export_date', 'order_export_date',
        'shipper', 'ship_code', 'order_shipper', 'order_ship_code',
        'paid_date', 'order_paid_date', 'bank_account_received', 'order_bank_account_received', '_order_payment_confirmation_audit',
        '_thean_vat_order_id',
    ]);
}

/** HPOS đang là nơi lưu chính? */
function thean_legacy_meta_hpos(): bool
{
    return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/** Cờ đang tự ghi (chặn vòng lặp: ghi HPOS → WC đồng bộ ngược ra posts → hook post meta). */
function thean_legacy_meta_busy(?bool $set = null): bool
{
    static $busy = false;
    if ($set !== null) {
        $busy = $set;
    }
    return $busy;
}

/** Giá trị meta coi là trống ('' / null / [] / chuỗi toàn khoảng trắng). Hàm thuần. */
function thean_legacy_meta_empty($value): bool
{
    if (is_array($value)) {
        return $value === [];
    }
    return $value === null || $value === false || trim((string) $value) === '';
}

/** Lưu meta HPOS của đơn (không gọi save() — không đổi trạng thái / không kích hook lưu đơn). */
function thean_legacy_meta_save(WC_Order $order): void
{
    thean_legacy_meta_busy(true);
    try {
        $order->save_meta_data();
    } finally {
        thean_legacy_meta_busy(false);
    }
    if (function_exists('thean_ops_order_cache_bust')) {
        thean_ops_order_cache_bust([$order->get_id()]);
    }
}

/** 1. Post meta của đơn vừa đổi → ghi sang HPOS. */
function thean_legacy_meta_mirror(int $post_id, string $key, $value, bool $delete): void
{
    if (thean_legacy_meta_busy() || $post_id <= 0 || !in_array($key, thean_legacy_meta_keys(), true) || !thean_legacy_meta_hpos()
        || !in_array(get_post_type($post_id), ['shop_order', 'shop_order_placehold'], true)) {
        return;
    }
    if (function_exists('thean_ops_order_cache_bust')) {
        thean_ops_order_cache_bust([$post_id]);
    }
    $order = wc_get_order($post_id);
    if (!$order instanceof WC_Order) {
        return;
    }
    if ($delete) {
        if (!$order->meta_exists($key)) {
            return;
        }
        $order->delete_meta_data($key);
    } else {
        if ($order->meta_exists($key) && maybe_serialize($order->get_meta($key, true)) === maybe_serialize($value)) {
            return;
        }
        $order->update_meta_data($key, $value);
    }
    thean_legacy_meta_save($order);
}

foreach (['added_post_meta', 'updated_post_meta'] as $thean_lms_hook) {
    add_action($thean_lms_hook, static function ($meta_id, $object_id, $meta_key, $meta_value) {
        thean_legacy_meta_mirror((int) $object_id, (string) $meta_key, $meta_value, false);
    }, 20, 4);
}
unset($thean_lms_hook);
add_action('deleted_post_meta', static function ($meta_ids, $object_id, $meta_key) {
    thean_legacy_meta_mirror((int) $object_id, (string) $meta_key, null, true);
}, 20, 3);

/** 2. HPOS trống nhưng posts có → kéo sang HPOS (cập nhật luôn $order). Trả true nếu có đổi. */
function thean_legacy_meta_reconcile(WC_Order $order): bool
{
    if (!thean_legacy_meta_hpos() || $order->get_id() <= 0) {
        return false;
    }
    $changed = false;
    foreach (thean_legacy_meta_keys() as $key) {
        if (!thean_legacy_meta_empty($order->get_meta($key, true))) {
            continue;
        }
        $legacy = get_post_meta($order->get_id(), $key, true);
        if (!thean_legacy_meta_empty($legacy)) {
            $order->update_meta_data($key, $legacy);
            $changed = true;
        }
    }
    if ($changed) {
        thean_legacy_meta_save($order);
    }
    return $changed;
}

/** Ghi meta vào cả posts lẫn HPOS (và $order đang cầm). */
function thean_legacy_meta_put(WC_Order $order, string $key, $value): void
{
    thean_legacy_meta_busy(true);
    try {
        update_post_meta($order->get_id(), $key, $value);
    } finally {
        thean_legacy_meta_busy(false);
    }
    $order->update_meta_data($key, $value);
    thean_legacy_meta_save($order);
}

/** Trang sửa đơn: kéo meta lệch sang HPOS TRƯỚC khi Meta Box đọc giá trị → ô không còn trống, lưu không xóa nhầm. */
add_action('current_screen', static function ($screen) {
    if (!$screen || !in_array($screen->id, ['woocommerce_page_wc-orders', 'shop_order'], true) || !current_user_can('edit_shop_orders')) {
        return;
    }
    $id = absint($_GET['id'] ?? ($_GET['post'] ?? 0));
    if ($id > 0 && ($order = wc_get_order($id)) instanceof WC_Order) {
        thean_legacy_meta_reconcile($order);
        thean_export_proof_recover($order);
    }
});

/** Tên file ảnh xuất kho đính kèm đơn (upload từng đơn / lô nhiều đơn AI tách). Hàm thuần. */
function thean_export_proof_image_file(string $basename, int $order_id): bool
{
    return (bool) preg_match('/^order-export-image-' . $order_id . '-/', $basename) || (bool) preg_match('/^xuat-kho-lo-/', $basename);
}

/** Tên người xác nhận trong ghi chú "✅ Đã xác nhận (ảnh) xuất kho bởi <tên>"; '' nếu không phải. Hàm thuần. */
function thean_export_confirm_name_from_note(string $note): string
{
    return preg_match('/Đã xác nhận (?:ảnh )?xuất kho bởi\s+(.+)$/u', trim($note), $m) ? trim($m[1]) : '';
}

/**
 * 3. Đơn đã mất ảnh / người xác nhận ở cả 2 bên → khôi phục từ file đính kèm + ghi chú. Mỗi đơn thử tối đa
 * 12 giờ/lần (transient) vì gọi từ bộ kiểm tra chứng từ. Trả true nếu khôi phục được gì đó.
 */
function thean_export_proof_recover(WC_Order $order): bool
{
    $id = $order->get_id();
    if ($id <= 0 || $order->get_type() !== 'shop_order') {
        return false;
    }
    $need_images  = thean_legacy_meta_empty($order->get_meta('warehouse_export_images'))
        && thean_legacy_meta_empty(get_post_meta($id, 'warehouse_export_images', true));
    $need_confirm = thean_legacy_meta_empty($order->get_meta('export_confirmed_by'))
        && thean_legacy_meta_empty(get_post_meta($id, 'export_confirmed_by', true));
    if ((!$need_images && !$need_confirm) || get_transient('thean_proof_recover_' . $id)) {
        return false;
    }
    set_transient('thean_proof_recover_' . $id, 1, 12 * HOUR_IN_SECONDS);

    $restored = [];
    if ($need_images) {
        $urls = [];
        foreach (get_posts(['post_type' => 'attachment', 'post_parent' => $id, 'post_status' => 'inherit', 'posts_per_page' => 60,
            'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids']) as $aid) {
            $file = (string) get_attached_file((int) $aid);
            if ($file !== '' && thean_export_proof_image_file(wp_basename($file), $id) && ($url = wp_get_attachment_url((int) $aid))) {
                $urls[] = $url;
            }
        }
        if ($urls) {
            thean_legacy_meta_put($order, 'warehouse_export_images', implode("\n", array_values(array_unique($urls))));
            $restored[] = count($urls) . ' ảnh xuất kho';
        }
    }
    if ($need_confirm && function_exists('wc_get_order_notes')) {
        foreach (wc_get_order_notes(['order_id' => $id, 'type' => 'internal']) as $note) {
            $name = thean_export_confirm_name_from_note(wp_strip_all_tags((string) $note->content));
            if ($name === '') {
                continue;
            }
            $users = get_users(['search' => $name, 'search_columns' => ['display_name', 'user_login'], 'number' => 2, 'fields' => 'ID']);
            $uid   = count($users) === 1 ? (int) $users[0] : 0;
            if ($uid <= 0 && !empty($note->added_by) && ($by = get_user_by('login', (string) $note->added_by))) {
                $uid = (int) $by->ID;
            }
            if ($uid > 0) {
                thean_legacy_meta_put($order, 'export_confirmed_by', $uid);
                $restored[] = 'xác nhận xuất kho (' . $name . ')';
            }
            break; // ghi chú mới nhất
        }
    }
    if ($restored) {
        $order->add_order_note('♻️ Khôi phục ' . implode(' + ', $restored) . ' — dữ liệu bị mất khi lưu trang sửa đơn (lỗi đồng bộ HPOS).');
        return true;
    }
    return false;
}

/** 4. Meta Box: ô "Ảnh xuất kho" gửi trống mà đơn đang có ảnh → giữ ảnh cũ (muốn xóa thì xóa từng dòng URL). */
add_filter('rwmb_warehouse_export_images_value', static function ($new, $field, $old, $object_id = 0) {
    if (!thean_legacy_meta_empty($new)) {
        return $new;
    }
    if (!thean_legacy_meta_empty($old)) {
        return $old;
    }
    $legacy = $object_id ? get_post_meta((int) $object_id, 'warehouse_export_images', true) : '';
    return thean_legacy_meta_empty($legacy) ? $new : $legacy;
}, 10, 4);

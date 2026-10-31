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

/**
 * Mọi giá trị của key ở bảng posts, đọc thẳng DB (bỏ qua filter get_post_metadata của order-hpos-compat.php — filter
 * đó trả bản HPOS). Field chọn nhiều của Meta Box (order_export_by, order_shipper…) lưu mỗi giá trị 1 dòng.
 */
function thean_legacy_post_meta_raw(int $post_id, string $key): array
{
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $post_id, $key));
    return array_map('maybe_unserialize', (array) $rows);
}

/** Giá trị HPOS hiện có của key (danh sách). */
function thean_legacy_order_meta_values(WC_Order $order, string $key): array
{
    $out = [];
    foreach ($order->get_meta($key, false) as $meta) {
        $out[] = $meta->value;
    }
    return $out;
}

/** Đặt toàn bộ giá trị key trên HPOS = $values (giữ nhiều dòng). Trả true nếu có đổi (chưa lưu). */
function thean_legacy_order_meta_assign(WC_Order $order, string $key, array $values): bool
{
    if (array_map('maybe_serialize', thean_legacy_order_meta_values($order, $key)) === array_map('maybe_serialize', $values)) {
        return false;
    }
    $order->delete_meta_data($key);
    foreach ($values as $v) {
        $order->add_meta_data($key, $v, false);
    }
    return true;
}

/** 1. Post meta của đơn vừa đổi → chép nguyên tập giá trị của key sang HPOS. */
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
    if (thean_legacy_order_meta_assign($order, $key, thean_legacy_post_meta_raw($post_id, $key))) {
        thean_legacy_meta_save($order);
    }
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
        $legacy = array_values(array_filter(thean_legacy_post_meta_raw($order->get_id(), $key), static fn($v) => !thean_legacy_meta_empty($v)));
        if ($legacy && thean_legacy_order_meta_assign($order, $key, $legacy)) {
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

/**
 * Meta Box trên màn hình sửa đơn HPOS (hithean khai post_types 'woocommerce_page_wc-orders') lấy ID đơn bằng
 * get_the_ID() — màn hình HPOS không đặt $post toàn cục → ID 0 → MỌI ô hiện trống, bấm Cập nhật gửi trống → xóa
 * shipper / mã vận đơn / ảnh xuất kho… Đặt $post = bản ghi đơn ở bảng posts (đồng bộ HPOS đang bật) trước khi vẽ box.
 */
add_action('add_meta_boxes', static function ($screen_id, $object = null) {
    if ($screen_id !== 'woocommerce_page_wc-orders' || !$object instanceof WC_Order || !empty($GLOBALS['post'])) {
        return;
    }
    $post = get_post($object->get_id());
    if ($post instanceof WP_Post) {
        $GLOBALS['post'] = $post;
        setup_postdata($post);
    }
}, 0, 2);

/** ID đơn đang mở ở màn sửa đơn HPOS (admin.php?page=wc-orders&action=edit&id=…); 0 nếu không phải. */
function thean_legacy_hpos_edit_order_id(): int
{
    if (!is_admin() || ($_GET['page'] ?? '') !== 'wc-orders' || ($_GET['action'] ?? '') !== 'edit') {
        return 0;
    }
    return absint($_GET['id'] ?? 0);
}

/**
 * Dự phòng cho trên: Meta Box vẫn không lấy được ID đơn (ô trống) → nạp giá trị thẳng từ đơn (HPOS, rồi posts).
 * Chỉ điền khi Meta Box trả trống, chỉ trên màn sửa đơn HPOS.
 */
add_filter('rwmb_field_meta', static function ($meta, $field, $saved = true) {
    $id  = thean_legacy_hpos_edit_order_id();
    $key = is_array($field) ? (string) ($field['id'] ?? '') : '';
    if ($id <= 0 || $key === '' || !thean_legacy_meta_empty($meta) || !($order = wc_get_order($id)) instanceof WC_Order) {
        return $meta;
    }
    $values = array_values(array_filter(thean_legacy_order_meta_values($order, $key), static fn($v) => !thean_legacy_meta_empty($v)));
    if (!$values) {
        $values = array_values(array_filter(thean_legacy_post_meta_raw($id, $key), static fn($v) => !thean_legacy_meta_empty($v)));
    }
    if (!$values) {
        return $meta;
    }
    return (!empty($field['multiple']) || !empty($field['clone'])) && !is_array($values[0]) ? $values : $values[0];
}, 20, 3);

add_action('admin_footer', static function () {
    if (thean_legacy_hpos_edit_order_id() > 0) {
        echo '<meta name="thean-legacy-meta-sync" content="3">';
    }
});

/**
 * Danh sách xuất kho (đọc HPOS): đơn đã xác nhận nhưng export_confirmed_by chỉ nằm ở posts → kéo sang HPOS và trả
 * true để bỏ khỏi "Ảnh lấy hàng chờ kiểm tra" (trước đây đơn đã xác nhận cứ quay lại danh sách).
 */
function thean_export_confirmed_settle(int $order_id): bool
{
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) {
        return false;
    }
    thean_legacy_meta_reconcile($order);
    if (!thean_legacy_meta_empty($order->get_meta('export_confirmed_by'))) {
        return true;
    }
    foreach (thean_legacy_post_meta_raw($order_id, 'export_confirmed_by') as $v) {
        if (!thean_legacy_meta_empty($v)) {
            return true;
        }
    }
    return false;
}

/**
 * Backfill 1 lần: đơn có meta luồng cũ ở posts mà HPOS trống → reconcile, 100 đơn / lượt, chạy cuối request admin
 * (tối đa 1 lượt / 2 phút) tới khi hết rồi ghi option xong. Đổi THEAN_LEGACY_META_BACKFILL khi thêm key cần chép lại.
 */
const THEAN_LEGACY_META_BACKFILL = '1';
function thean_legacy_meta_backfill_ids(int $limit = 100): array
{
    global $wpdb;
    if (!thean_legacy_meta_hpos() || !class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
        return [];
    }
    $orders = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
    $meta   = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
    $keys   = implode(',', array_map(static fn($k) => $wpdb->prepare('%s', $k), thean_legacy_meta_keys()));
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
         JOIN {$orders} o ON o.id = pm.post_id AND o.type = 'shop_order'
         WHERE pm.meta_key IN ({$keys}) AND pm.meta_value <> ''
           AND NOT EXISTS (SELECT 1 FROM {$meta} om WHERE om.order_id = pm.post_id AND om.meta_key = pm.meta_key AND om.meta_value <> '')
         ORDER BY pm.post_id DESC LIMIT %d", $limit)));
}

function thean_legacy_meta_backfill_run(): int
{
    if (get_option('thean_legacy_meta_backfill') === THEAN_LEGACY_META_BACKFILL || get_transient('thean_legacy_meta_backfill_lock')) {
        return 0;
    }
    set_transient('thean_legacy_meta_backfill_lock', 1, 2 * MINUTE_IN_SECONDS);
    $ids = thean_legacy_meta_backfill_ids(100);
    if (!$ids) {
        update_option('thean_legacy_meta_backfill', THEAN_LEGACY_META_BACKFILL, false);
        return 0;
    }
    $done = 0;
    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if ($order instanceof WC_Order && thean_legacy_meta_reconcile($order)) {
            $done++;
        }
    }
    if ($done === 0) {
        update_option('thean_legacy_meta_backfill', THEAN_LEGACY_META_BACKFILL, false); // không tiến triển → dừng, tránh lặp vô hạn
    }
    return $done;
}

add_action('admin_init', static function () {
    if (wp_doing_ajax() || !current_user_can('manage_woocommerce') || get_option('thean_legacy_meta_backfill') === THEAN_LEGACY_META_BACKFILL) {
        return;
    }
    add_action('shutdown', 'thean_legacy_meta_backfill_run', 20);
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
        return thean_export_ai_recover($order);
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
    }
    return thean_export_ai_recover($order) || (bool) $restored;
}

/**
 * Ghi chú "🤖 AI review ảnh xuất kho" (thean_/hithean_export_ai_order_note) → mảng kết quả như warehouse_export_ai_check;
 * null nếu không phải ghi chú AI. Hàm thuần.
 */
function thean_export_ai_result_from_note(string $note): ?array
{
    if (strpos($note, 'AI review ảnh xuất kho') === false || !preg_match('/^Kết quả:\s*(.+)$/mu', $note, $m)) {
        return null;
    }
    $field = static function (string $label) use ($note): string {
        return preg_match('/^' . preg_quote($label, '/') . ':\s*(.*)$/mu', $note, $mm) ? trim($mm[1]) : '';
    };
    $state = static function (string $v): string {
        if (strpos($v, 'Không khớp') === 0) {
            return 'mismatch';
        }
        return strpos($v, 'Khớp') === 0 ? 'match' : 'unclear';
    };
    $order_v = $field('Mã đơn/phiếu');
    $codes   = $field('Mã vận đơn đọc được');
    $issues  = $field('Cần xem lại');
    $conf    = $field('Độ tin cậy');
    return [
        'overall'                       => strpos(trim($m[1]), 'Khớp') === 0 ? 'pass' : 'review',
        'order_match'                   => $state($order_v),
        'items_match'                   => $state($field('Sản phẩm và số lượng')),
        'confidence'                    => in_array($conf, ['high', 'medium', 'low'], true) ? $conf : 'low',
        'visible_order_reference'       => $field('AI đọc được'),
        'masked_order_match'            => strpos($order_v, 'mã che chuẩn') !== false,
        'shipment_order_match'          => strpos($order_v, 'mã giao vận') !== false,
        'reason'                        => $field('Nhận định'),
        'shipping_codes'                => $codes === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $codes)))),
        'missing_or_suspected_missing'  => $issues === '' ? [] : array_values(array_filter(array_map('trim', explode(';', $issues)))),
        'unexpected_or_suspected_extra' => [],
        'restored_from_note'            => true,
    ];
}

/**
 * Kết quả AI check bị mất (cả 2 bảng) mà ghi chú AI còn → dựng lại warehouse_export_ai_check từ ghi chú AI mới nhất.
 * Bỏ qua nếu sau ghi chú AI có lượt upload ảnh mới (kết quả cũ không còn đúng bộ ảnh). Thử tối đa 12 giờ/lần.
 */
function thean_export_ai_recover(WC_Order $order): bool
{
    $id = $order->get_id();
    if ($id <= 0 || !function_exists('wc_get_order_notes') || get_transient('thean_ai_recover_' . $id)
        || !thean_legacy_meta_empty($order->get_meta('warehouse_export_ai_check'))
        || array_filter(thean_legacy_post_meta_raw($id, 'warehouse_export_ai_check'), static fn($v) => !thean_legacy_meta_empty($v))
        || thean_legacy_meta_empty($order->get_meta('warehouse_export_images'))) {
        return false;
    }
    set_transient('thean_ai_recover_' . $id, 1, 12 * HOUR_IN_SECONDS);
    // Ảnh xuất kho mới nhất đính kèm đơn: ghi chú AI cũ hơn ảnh này = kết quả của bộ ảnh trước → không dùng.
    $latest_img = 0;
    foreach (get_posts(['post_type' => 'attachment', 'post_parent' => $id, 'post_status' => 'inherit', 'posts_per_page' => 60, 'fields' => 'ids']) as $aid) {
        $file = (string) get_attached_file((int) $aid);
        if ($file !== '' && thean_export_proof_image_file(wp_basename($file), $id)) {
            $latest_img = max($latest_img, (int) get_post_time('U', true, (int) $aid));
        }
    }
    foreach (wc_get_order_notes(['order_id' => $id, 'type' => 'internal']) as $note) { // mới nhất trước
        $result = thean_export_ai_result_from_note(wp_strip_all_tags((string) $note->content));
        if ($result === null) {
            continue;
        }
        if ($note->date_created instanceof WC_DateTime && $note->date_created->getTimestamp() < $latest_img) {
            return false;
        }
        $result['checked_at'] = $note->date_created instanceof WC_DateTime ? $note->date_created->date_i18n('Y-m-d H:i:s') : current_time('mysql');
        $result['checked_by'] = 0;
        $result['provider']   = 'note';
        thean_legacy_meta_put($order, 'warehouse_export_ai_check', $result);
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

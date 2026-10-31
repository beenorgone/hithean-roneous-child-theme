<?php
/**
 * HPOS: helper + đọc meta đơn từ HPOS. Dùng chung theanmarket + hithean (file giống hệt nhau).
 *
 * - thean_hpos_on()             HPOS là nơi lưu chính?
 * - thean_is_shop_order_id()    ID có phải đơn bán (shop_order)? Đúng cả khi posts chỉ còn bản giữ chỗ shop_order_placehold.
 * - thean_order_edit_url()      Link sửa đơn (HPOS: admin.php?page=wc-orders&action=edit&id=…). KHÔNG dùng
 *                               get_edit_post_link() / post.php?post=… cho đơn: post shop_order_placehold không có màn sửa.
 * - thean_order_list_url()      Link danh sách đơn (+ tìm ?s=).
 * - thean_order_meta_table()    Bảng meta đơn cho SQL tự viết: ['table' => …, 'id' => 'order_id'|'post_id'].
 * - Filter get_post_metadata    Code cũ đọc get_post_meta() trên đơn → trả giá trị HPOS (Meta Box, order API, eim… chỉ
 *                               ghi HPOS). HPOS trống → để WP đọc posts như cũ (order-legacy-meta-sync.php cần đọc bản
 *                               posts để kéo sang HPOS). Chiều ghi do order-legacy-meta-sync.php lo.
 */
if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

function thean_hpos_on(): bool
{
    static $on = null;
    if ($on === null) {
        if (!class_exists(OrderUtil::class) || !did_action('woocommerce_loaded')) {
            return false; // chưa chắc chắn → không cache
        }
        $on = OrderUtil::custom_orders_table_usage_is_enabled();
    }
    return $on;
}

function thean_is_shop_order_id(int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    if (class_exists(OrderUtil::class) && method_exists(OrderUtil::class, 'get_order_type')) {
        return OrderUtil::get_order_type($id) === 'shop_order';
    }
    return get_post_type($id) === 'shop_order';
}

function thean_order_edit_url(int $id): string
{
    if ($id <= 0) {
        return '';
    }
    if (class_exists(OrderUtil::class) && method_exists(OrderUtil::class, 'get_order_admin_edit_url')) {
        return (string) OrderUtil::get_order_admin_edit_url($id);
    }
    return admin_url('post.php?post=' . $id . '&action=edit');
}

function thean_order_list_url(array $args = []): string
{
    if (thean_hpos_on()) {
        if (isset($args['post_status'])) {
            $args['status'] = $args['post_status'];
            unset($args['post_status']);
        }
        return add_query_arg(array_merge(['page' => 'wc-orders'], $args), admin_url('admin.php'));
    }
    return add_query_arg(array_merge(['post_type' => 'shop_order'], $args), admin_url('edit.php'));
}

function thean_order_meta_table(): array
{
    global $wpdb;
    return thean_hpos_on()
        ? ['table' => $wpdb->prefix . 'wc_orders_meta', 'id' => 'order_id']
        : ['table' => $wpdb->postmeta, 'id' => 'post_id'];
}

/** Key bắt đầu "_" vẫn đọc từ HPOS (meta riêng, không phải cột lõi của WC). Key "_" khác (khóa _thean_*_lock, cột lõi) đi posts. */
function thean_hpos_read_private_keys(): array
{
    return (array) apply_filters('thean_hpos_read_private_keys', [
        '_order_payment_confirmation_audit', '_thean_vat_order_id', '_thean_preorder_confirm', '_thean_export_proof_skip',
        '_spx_date', '_eim_internal', '_eim_vat_request',
    ]);
}

/** Có đọc key này từ HPOS không? Hàm thuần. */
function thean_hpos_read_key(string $key, array $private_keys): bool
{
    return $key !== '' && ($key[0] !== '_' || in_array($key, $private_keys, true));
}

/**
 * ID các ô Meta Box gắn lên đơn. Meta Box lưu bằng update_post_meta → chỉ vào posts; hook dưới chép sang HPOS bằng
 * hàm của order-legacy-meta-sync.php. Thiếu bước này: filter đọc trả giá trị HPOS cũ → sửa ô mà "không ăn".
 * KHÔNG đưa vào thean_legacy_meta_keys(): reconcile duyệt mọi key đó cho từng đơn (≤2000 đơn khi Xoá cache) → quá nhiều SQL.
 */
function thean_hpos_order_metabox_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    if (!function_exists('rwmb_get_registry') || !did_action('init')) {
        return [];
    }
    $out = [];
    foreach (rwmb_get_registry('meta_box')->all() as $box) {
        $mb = is_object($box) && isset($box->meta_box) ? (array) $box->meta_box : [];
        if (!array_intersect((array) ($mb['post_types'] ?? []), ['shop_order', 'woocommerce_page_wc-orders'])) {
            continue;
        }
        foreach ((array) ($mb['fields'] ?? []) as $field) {
            if (!empty($field['id']) && is_string($field['id'])) {
                $out[] = $field['id'];
            }
        }
    }
    if ($out === [] && !did_action('wp_loaded')) {
        return []; // Meta Box chưa đăng ký xong → chưa cache
    }
    return $keys = array_values(array_unique($out));
}

/** Ghi post meta một ô Meta Box của đơn → chép nguyên tập giá trị sang HPOS. */
function thean_hpos_mirror_metabox_meta(int $post_id, string $key): void
{
    if ($post_id <= 0 || !function_exists('thean_legacy_order_meta_assign') || thean_legacy_meta_busy()
        || in_array($key, thean_legacy_meta_keys(), true) // order-legacy-meta-sync.php tự lo
        || !in_array($key, thean_hpos_order_metabox_keys(), true) || !thean_hpos_on()
        || !in_array(get_post_type($post_id), ['shop_order', 'shop_order_placehold'], true)) {
        return;
    }
    $order = wc_get_order($post_id);
    if ($order instanceof WC_Order && thean_legacy_order_meta_assign($order, $key, thean_legacy_post_meta_raw($post_id, $key))) {
        thean_legacy_meta_save($order);
    }
}
foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $thean_hpc_hook) {
    add_action($thean_hpc_hook, static function ($meta_id, $object_id, $meta_key) {
        thean_hpos_mirror_metabox_meta((int) $object_id, (string) $meta_key);
    }, 20, 3);
}
unset($thean_hpc_hook);

add_filter('get_post_metadata', static function ($value, $object_id, $meta_key, $single) {
    static $busy = false;
    $object_id = (int) $object_id;
    if ($value !== null || $busy || $object_id <= 0 || !is_string($meta_key)
        || !thean_hpos_read_key($meta_key, thean_hpos_read_private_keys()) || !thean_hpos_on()
        || !in_array(get_post_type($object_id), ['shop_order', 'shop_order_placehold'], true)) {
        return $value;
    }
    $busy = true;
    try {
        $order = wc_get_order($object_id);
        if (!$order instanceof WC_Order) {
            return $value;
        }
        $values = [];
        foreach ($order->get_meta($meta_key, false) as $meta) {
            $values[] = $meta->value;
        }
    } finally {
        $busy = false;
    }
    if ($values === [] || ($single && function_exists('thean_legacy_meta_empty') && thean_legacy_meta_empty($values[0]))) {
        return $value; // HPOS trống → đọc posts như cũ
    }
    return $values; // WP tự lấy [0] khi $single
}, 5, 4);

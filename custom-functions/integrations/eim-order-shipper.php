<?php
if (!defined('ABSPATH')) exit;

/**
 * Nối dữ liệu vận hành đơn của theme cho plugin hóa đơn VAT (eim).
 * Cùng tên file với theanmarket; hithean khác ở meta có tiền tố "order_".
 *
 * Luôn nạp ($general_includes): eim gọi REST pull đơn, không đi qua trang/shortcode
 * nên không được dựa vào module nạp có điều kiện (vd oppc_get_order_shipper_display()).
 * Nhãn lấy từ hithean_shipper_labels() (woocommerce/orders/order-shipper.php).
 */

/** "Đơn vị vận chuyển" thật — chính xác hơn get_shipping_method() (gói cước lúc checkout). */
add_filter('eim_order_shipper_label', function ($label, $order) {
    if (!($order instanceof WC_Order)) {
        return $label;
    }
    $names = hithean_order_shipper_names($order);
    return $names !== '' ? $names : $label;
}, 10, 2);

add_filter('eim_order_shipper_tracking_code', function ($code, $order) {
    if (!($order instanceof WC_Order)) {
        return $code;
    }
    $v = trim((string) $order->get_meta('order_ship_code', true));
    return $v !== '' ? $v : $code;
}, 10, 2);

/**
 * Map meta xuất kho — field Meta Box của hithean có tiền tố "order_"
 * (order-metabox.php), khác default không tiền tố của plugin.
 */
add_filter('eim_export_meta_map', function (array $map): array {
    return array_merge($map, [
        'export_date' => 'order_ship_date',
        'shipper'     => 'order_shipper',
        'ship_code'   => 'order_ship_code',
        'export_by'   => 'order_export_by',
        'paid_date'   => 'order_paid_date',
        'bank'        => 'order_bank_account_received',
        'handling'    => 'order_handling_status',
        // images + confirmed_by: hithean dùng cùng tên default
        // (warehouse_export_images, export_confirmed_by).
    ]);
});

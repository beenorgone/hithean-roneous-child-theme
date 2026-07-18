<?php
if (!defined('ABSPATH')) exit;

/**
 * Bootstrap chính của hithean child theme.
 *
 * Nạp các file "feature" trong core/ trước (chỉ đăng ký hook), rồi tới
 * module-loader.php — nơi chứa TOÀN BỘ logic what-loads-when ($general_includes,
 * tpc_loader, conditional/admin/ajax loaders, social-display, editor-tools).
 *
 * Yêu cầu: hằng HITHEAN_THEME_DIR đã được define ở functions.php (= thư mục theme).
 */

require_once __DIR__ . '/core/template-router.php';
require_once __DIR__ . '/core/enqueue.php';
require_once __DIR__ . '/core/admin.php';
require_once __DIR__ . '/core/email-overrides.php';
require_once __DIR__ . '/core/upload-guard.php';
require_once __DIR__ . '/core/public-file-guard.php';
require_once __DIR__ . '/core/module-loader.php';

/**
 * Nối "đơn vị vận chuyển" thật (field "order_shipper" của theme, Meta Box
 * select_advanced multiple + "order_ship_code") cho plugin hóa đơn VAT (eim)
 * — chính xác hơn get_shipping_method() (chỉ là gói cước chọn lúc checkout).
 * Tự chứa: module payment_pending_confirm chỉ load theo trang/shortcode,
 * KHÔNG chạy khi eim gọi REST pull đơn — không dựa vào oppc_get_order_shipper_display().
 * Nhãn đồng bộ tay với custom-functions/woocommerce/orders/order-metabox.php.
 */
add_filter('eim_order_shipper_label', function ($label, $order) {
    if (!($order instanceof WC_Order)) {
        return $label;
    }
    $map = ['self' => 'THEAN', 'ghtk' => 'Giao Hang Tiet Kiem', 'ahamove' => 'Ahamove', 'viettel' => 'Viettel Post'];
    $raw = $order->get_meta('order_shipper');
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : [$raw];
    }
    if (!is_array($raw)) {
        return $label;
    }
    $names = [];
    foreach ($raw as $v) {
        $v = strtolower(trim((string) $v));
        if ($v !== '') {
            $names[] = $map[$v] ?? $v;
        }
    }
    return $names ? implode(', ', array_unique($names)) : $label;
}, 10, 2);

add_filter('eim_order_shipper_tracking_code', function ($code, $order) {
    if (!($order instanceof WC_Order)) {
        return $code;
    }
    $v = trim((string) $order->get_meta('order_ship_code', true));
    return $v !== '' ? $v : $code;
}, 10, 2);

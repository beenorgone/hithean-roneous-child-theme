<?php
if (!defined('ABSPATH')) exit;

/**
 * Đơn vị vận chuyển của đơn — NGUỒN DUY NHẤT cho nhãn mã shipper.
 *
 * Meta `order_shipper` (Meta Box select_advanced nhiều giá trị, order-metabox.php)
 * lưu mã self/ghtk/ahamove/viettel. Dùng ở: options field Meta Box, filter eim
 * (integrations/eim-order-shipper.php), ô tìm đơn, trang xác nhận thanh toán.
 * Thêm đơn vị mới → chỉ sửa ở đây.
 */

function hithean_shipper_labels(bool $short = false): array
{
    $labels = [
        'self'    => 'THEAN',
        'ghtk'    => 'Giao Hang Tiet Kiem',
        'ahamove' => 'Ahamove',
        'viettel' => 'Viettel Post',
    ];
    // Nhãn ngắn cho chỗ hẹp (ô tìm đơn / board).
    return $short ? array_merge($labels, ['ghtk' => 'GHTK']) : $labels;
}

/** Mã shipper đã chọn (chữ thường, không trùng). Đơn cũ lưu chuỗi đơn → bọc thành mảng. */
function hithean_order_shipper_codes(WC_Order $order): array
{
    $raw = $order->get_meta('order_shipper');
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : [$raw];
    }
    if (!is_array($raw)) {
        return [];
    }
    $codes = [];
    foreach ($raw as $v) {
        $v = strtolower(trim((string) $v));
        if ($v !== '') {
            $codes[] = $v;
        }
    }
    return array_values(array_unique($codes));
}

/** Nhãn shipper nối bằng ", "; rỗng nếu chưa chọn. Mã lạ giữ nguyên. */
function hithean_order_shipper_names(WC_Order $order, bool $short = false): string
{
    $labels = hithean_shipper_labels($short);
    $names  = [];
    foreach (hithean_order_shipper_codes($order) as $code) {
        $names[] = $labels[$code] ?? $code;
    }
    return implode(', ', array_unique($names));
}

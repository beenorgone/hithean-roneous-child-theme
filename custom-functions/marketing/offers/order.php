<?php
/**
 * Thứ tự hiển thị chương trình ưu đãi — MỘT nguồn cho: popup "Ưu đãi đang áp dụng" trang SP (product-purchase.js qua
 * config.offerOrder), popup nút ưu đãi ở danh sách SP, /uu-dai/ (cả 2 chế độ xem), popup "Ưu đãi tiêu biểu" thanh menu đáy.
 * Sắp theo nhóm; admin đổi thứ tự nhóm ở Ưu đãi sản phẩm → Cấu hình & xuất bản (settings 'order'). Chưa sắp gì =
 * mặc định: giảm giá trước, quà tặng tiếp theo, vận chuyển cuối. Chương trình "Ghim nổi bật" vẫn đứng đầu trang /uu-dai/.
 */
defined('ABSPATH') || exit;

/** Nhóm theo thứ tự MẶC ĐỊNH => nhãn ở trang cài đặt. */
function hithean_offers_order_groups(): array {
    return [
        'discount' => 'Giảm giá / giá ưu đãi',
        'tiers' => 'Mua nhiều giảm giá',
        'gift' => 'Quà tặng & ưu đãi mua kèm',
        'wheel' => 'Vòng quay may mắn',
        'other' => 'Ưu đãi khác',
        'shipping' => 'Ưu đãi vận chuyển / free ship',
    ];
}

/** Chuẩn hóa thứ tự đã lưu (hàm thuần): giữ nhóm hợp lệ theo thứ tự đã chọn, bỏ trùng, nhóm còn thiếu nối theo thứ tự mặc định. */
function hithean_offers_order_sanitize($order): array {
    $groups = array_keys(hithean_offers_order_groups());
    $picked = array_values(array_intersect(array_unique(array_map('strval', is_array($order) ? $order : [])), $groups));
    return array_merge($picked, array_values(array_diff($groups, $picked)));
}

/** Thứ tự nhóm đang dùng. */
function hithean_offers_order(): array {
    static $order = null;
    return $order ??= hithean_offers_order_sanitize(hithean_offers_settings()['order'] ?? []);
}

/** Vị trí của 1 nhóm (nhỏ = lên trước). Nhận cả tên nhóm của thẻ (addon / quantity) — quy về nhóm sắp xếp. */
function hithean_offers_order_rank(string $group, ?array $order = null): int {
    $alias = ['addon' => 'gift', 'quantity' => 'tiers', 'sale' => 'discount', 'coupon' => 'discount'];
    $position = array_search($alias[$group] ?? $group, $order ?? hithean_offers_order(), true);
    return $position === false ? 99 : (int) $position;
}

/** Nhóm sắp xếp của 1 dòng snapshot (hàm thuần) — cùng cách phân loại thẻ ở hithean_offer_cards_from_rows(). */
function hithean_offers_row_group(array $row): string {
    $family = (string) ($row['family'] ?? '');
    $kind = (string) ($row['kind'] ?? '');
    if ($family === 'wheel') { return 'wheel'; }
    if ($family === 'addon' || $kind === 'gift') { return 'gift'; }
    if ($kind === 'shipping') { return 'shipping'; }
    if ($kind === 'quantity') { return 'tiers'; }
    if ($family === 'sale' || $kind === 'discount') { return 'discount'; }
    return 'other';
}

/** Vị trí thấp nhất trong các dòng (thẻ gộp nhiều ưu đãi xếp theo ưu đãi đứng đầu của nó). */
function hithean_offers_rows_rank(array $rows): int {
    $ranks = array_map(static fn($row) => hithean_offers_order_rank(hithean_offers_row_group((array) $row)), $rows);
    return $ranks ? min($ranks) : 99;
}

/** Sắp các dòng snapshot theo nhóm, giữ thứ tự cũ khi cùng nhóm (usort ổn định từ PHP 8). */
function hithean_offers_sort_rows(array $rows): array {
    usort($rows, static fn($a, $b) => hithean_offers_order_rank(hithean_offers_row_group($a)) <=> hithean_offers_order_rank(hithean_offers_row_group($b)));
    return $rows;
}

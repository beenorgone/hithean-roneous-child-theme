<?php
/**
 * Gom snapshot /uu-dai/ thành CHƯƠNG TRÌNH (1 nguồn rule = 1 chương trình, nhiều sản phẩm) — nguồn chung cho
 * popup "Ưu đãi tiêu biểu" (site-footer-menu.php → hithean_sfm_featured_cards) và popup "Xem chi tiết" của thanh ưu đãi
 * đầu trang (offers/topbar.php). Vòng quay không tính ở đây (có thẻ riêng).
 */
defined('ABSPATH') || exit;

/** URL trang /uu-dai/ khi đã xuất bản, ngược lại ''. */
function hithean_offers_page_url(): string {
    static $url = null;
    if ($url === null) {
        $page = function_exists('get_page_by_path') ? get_page_by_path('uu-dai') : null;
        $url = $page && $page->post_status === 'publish' ? (string) get_permalink($page) : '';
    }
    return $url;
}

/**
 * Chương trình đang hiệu lực: mỗi phần tử = [product_id => dòng snapshot]. Xếp theo nhóm ưu đãi (hithean_offers_order(),
 * mặc định giảm giá → quà tặng → vận chuyển), cùng nhóm thì chương trình nhiều sản phẩm trước. $limit = 0 → tất cả.
 */
function hithean_offers_programs(int $limit = 0): array {
    if (!function_exists('hithean_offers_visible_items')) { return []; }
    $programs = [];
    foreach (hithean_offers_visible_items() as $row) {
        if (empty($row['product_id']) || empty($row['product_name']) || ($row['family'] ?? '') === 'wheel') { continue; }
        $key = (string) ($row['source_id'] ?? '');
        $programs[$key !== '' ? $key : $row['product_id'] . ':' . md5((string) ($row['title'] ?? ''))][(int) $row['product_id']] = $row;
    }
    uasort($programs, static function (array $a, array $b): int {
        return [hithean_offers_rows_rank([reset($a)]), count($b)] <=> [hithean_offers_rows_rank([reset($b)]), count($a)];
    });
    $programs = array_values($programs);
    return $limit > 0 ? array_slice($programs, 0, $limit) : $programs;
}

/**
 * Phạm vi của 1 chương trình nhiều sản phẩm: ['site_wide' => bool, 'url' => trang xem cả nhóm].
 * Rule Discount Rules theo danh mục → trang danh mục; còn lại → /uu-dai/ chế độ chương trình (hoặc /shop).
 */
function hithean_offers_program_scope(array $rows): array {
    $row = reset($rows);
    if (($row['family'] ?? '') === 'wdr' && function_exists('hithean_offers_group_label')) {
        $group = hithean_offers_group_label($row);
        return ['site_wide' => !empty($group['site_wide']), 'url' => (string) $group['url']];
    }
    $offers_url = hithean_offers_page_url();
    return ['site_wide' => false, 'url' => $offers_url !== '' ? add_query_arg('xem', 'chuong-trinh', $offers_url)
        : (function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/san-pham/'))];
}

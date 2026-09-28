<?php
if (!defined('ABSPATH')) exit;

/*
 * Ưu đãi đại lý mới (giá nhập riêng cho N đơn đầu) trên landing an-new-chapter-b2b.
 * Xem plans/anc-b2b-new-partner-offer-2026-10.md.
 *
 * [anc_new_partner_offer]...[/anc_new_partner_offer]
 *   Chỉ render khi chương trình đang chạy. Placeholder trong nội dung:
 *   {orders} = số đơn áp dụng, {next_order} = đơn đầu tiên về giá thường,
 *   {end_date} = 15/10/2026, {end_day} = 15/10,
 *   {end_iso} = hạn chót dạng ISO 8601 cho đồng hồ đếm ngược (data-anc-countdown).
 * [anc_new_partner_offer_else]...[/anc_new_partner_offer_else]
 *   Copy thường, chỉ render khi chương trình KHÔNG chạy. Đặt ngay cạnh khối trên.
 *
 * Hai shortcode tách riêng (không lồng) vì WP không hỗ trợ lồng cùng tên shortcode.
 *
 * Giả lập ngày (chỉ user có quyền edit_pages): ?anc_offer_date=2026-11-01
 */

add_shortcode('anc_new_partner_offer', 'hithean_anc_offer_shortcode');
add_shortcode('anc_new_partner_offer_else', 'hithean_anc_offer_else_shortcode');
add_action('hithean_anc_offer_expired', 'hithean_anc_offer_purge_cache');

/**
 * Cấu hình DUY NHẤT của chương trình. Số đơn khai báo tay, không đọc từ
 * max_orders bên ERP ivarvietnam — đổi bên ERP thì sửa cả ở đây.
 * start rỗng = áp dụng ngay (lead cuối tháng 9 vẫn được tính).
 */
function hithean_anc_offer_config(): array
{
    return apply_filters('hithean_anc_offer_config', [
        'start'    => '',
        'end'      => '2026-10-15',
        'orders'   => 2,
        'timezone' => 'Asia/Ho_Chi_Minh',
    ]);
}

function hithean_anc_offer_end(): DateTimeImmutable
{
    $config = hithean_anc_offer_config();

    return new DateTimeImmutable($config['end'] . ' 23:59:59', new DateTimeZone($config['timezone']));
}

function hithean_anc_offer_now(): DateTimeImmutable
{
    $now = current_datetime();

    if (isset($_GET['anc_offer_date']) && current_user_can('edit_pages')) {
        $date = sanitize_text_field(wp_unslash($_GET['anc_offer_date']));
        $config = hithean_anc_offer_config();
        $debug = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone($config['timezone']));
        if ($debug instanceof DateTimeImmutable) {
            $now = $debug->setTime(12, 0);
        }
    }

    return $now;
}

function hithean_anc_offer_is_active(): bool
{
    static $active = null;

    if ($active !== null) {
        return $active;
    }

    $config = hithean_anc_offer_config();
    $now = hithean_anc_offer_now();
    $active = $now <= hithean_anc_offer_end();

    if ($active && $config['start'] !== '') {
        $start = new DateTimeImmutable($config['start'] . ' 00:00:00', new DateTimeZone($config['timezone']));
        $active = $now >= $start;
    }

    return $active;
}

function hithean_anc_offer_fill(string $content): string
{
    $config = hithean_anc_offer_config();
    $end = hithean_anc_offer_end();

    return strtr($content, [
        '{orders}'     => (string) (int) $config['orders'],
        '{next_order}' => (string) ((int) $config['orders'] + 1),
        '{end_date}'   => $end->format('d/m/Y'),
        '{end_day}'    => $end->format('d/m'),
        '{end_iso}'    => $end->format('c'),
    ]);
}

function hithean_anc_offer_shortcode($atts, $content = ''): string
{
    if (!hithean_anc_offer_is_active()) {
        return '';
    }

    hithean_anc_offer_schedule_purge();

    return do_shortcode(hithean_anc_offer_fill((string) $content));
}

function hithean_anc_offer_else_shortcode($atts, $content = ''): string
{
    if (hithean_anc_offer_is_active()) {
        return '';
    }

    return do_shortcode((string) $content);
}

/**
 * Trang được page-cache sẽ giữ nguyên HTML ưu đãi sau hạn chót. Hẹn một cron
 * ngay sau hạn để purge cache của trang này. Cron được nạp qua 'cron_hooks'
 * trong module-loader.
 */
function hithean_anc_offer_schedule_purge(): void
{
    if (isset($_GET['anc_offer_date'])) {
        return;
    }

    $post_id = (int) get_queried_object_id();
    $args = [$post_id];

    if ($post_id <= 0 || wp_next_scheduled('hithean_anc_offer_expired', $args)) {
        return;
    }

    wp_schedule_single_event(hithean_anc_offer_end()->getTimestamp() + 1, 'hithean_anc_offer_expired', $args);
}

function hithean_anc_offer_purge_cache($post_id = 0): void
{
    $post_id = (int) $post_id;
    $purged = false;

    if ($post_id > 0) {
        clean_post_cache($post_id);

        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
            $purged = true;
        }

        if (has_action('litespeed_purge_post')) {
            do_action('litespeed_purge_post', $post_id);
            $purged = true;
        }
    }

    if (!$purged && function_exists('thean_theme_purge_cache')) {
        thean_theme_purge_cache();
    }
}

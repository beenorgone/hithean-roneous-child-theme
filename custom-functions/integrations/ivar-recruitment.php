<?php
/**
 * Landing Tuyển dụng /tuyen-dung/ — nội dung soạn ở ERP ivarvietnam (/hr/?view=recruit), hithean chỉ hiển thị.
 *
 *   [ivar_tuyen_dung]  in bản đã lấy về (pages/tuyen-dung/tuyen-dung.html chỉ chứa shortcode này; template Landing Page).
 *   Lấy từ GET https://ivarvietnam.com/wp-json/ivar/v1/tuyen-dung?site=hithean → {html, css, revision}; HTML còn
 *   shortcode [company_info …] → chạy ở đây bằng Thông tin doanh nghiệp của hithean. CSS in nội tuyến (bản gốc ở IVAR).
 *
 * - Hiển thị không chờ mạng: in bản tốt cuối cùng (option, không transient). Chỉ khi chưa từng có bản nào mới gọi IVAR
 *   ngay (lỗi thì không thử lại trong 5 phút).
 * - Lấy lỗi → giữ bản cũ, ghi lỗi vào option + error_log.
 * - IVAR lưu xong gọi POST /wp-json/ivar-recruit/v1/refresh → lấy bản mới ngay (request không mang dữ liệu, chỉ là tín
 *   hiệu; tối đa 1 lần / 20 giây). Cron hằng giờ lấy lại phòng khi tín hiệu lỡ. Bản đổi → xoá cache WP Rocket của trang.
 * - HTML nhận về qua wp_kses với danh sách thẻ của landing (IVAR đã escape chữ người soạn; đây là lớp chặn thứ hai).
 */

defined('ABSPATH') || exit;

const HITHEAN_RECRUIT_CACHE  = 'hithean_recruit_cache';
const HITHEAN_RECRUIT_CRON   = 'hithean_recruit_refresh';
const HITHEAN_RECRUIT_SOURCE = 'https://ivarvietnam.com/wp-json/ivar/v1/tuyen-dung?site=hithean';

function hithean_recruit_cache(): array
{
    $c = get_option(HITHEAN_RECRUIT_CACHE, []);
    return is_array($c) ? $c : [];
}

/** Thẻ / thuộc tính được phép trong HTML landing nhận từ IVAR. */
function hithean_recruit_allowed_html(): array
{
    $cls = ['class' => true];
    return [
        'main' => $cls + ['data-site' => true], 'header' => $cls, 'footer' => $cls, 'div' => $cls, 'nav' => ['aria-label' => true],
        'section' => $cls + ['id' => true, 'aria-label' => true], 'article' => $cls + ['id' => true, 'data-job' => true],
        'a' => $cls + ['href' => true, 'target' => true, 'rel' => true, 'aria-label' => true],
        'img' => $cls + ['src' => true, 'alt' => true, 'height' => true, 'data-skip-lazy' => true],
        'p' => $cls, 'h1' => [], 'h2' => [], 'h3' => $cls, 'h4' => [], 'br' => [], 'em' => [], 'b' => [],
        'ul' => $cls, 'ol' => [], 'li' => [], 'span' => $cls, 'details' => [], 'summary' => [],
        'input' => $cls + ['type' => true, 'name' => true, 'id' => true, 'checked' => true], 'label' => ['for' => true],
    ];
}

/** Lấy bản mới từ IVAR. true = đã có bản mới nhất; WP_Error = lỗi (bản cũ giữ nguyên). */
function hithean_recruit_fetch()
{
    $res = wp_remote_get(HITHEAN_RECRUIT_SOURCE, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $body = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
    $cache = hithean_recruit_cache();
    if ($code !== 200 || !is_array($body) || !is_string($body['html'] ?? null) || strpos($body['html'], 'class="tdj"') === false) {
        $msg = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . $code . ' / nội dung không đúng dạng';
        error_log('[recruit] lấy trang tuyển dụng từ IVAR lỗi: ' . $msg);
        $cache['error'] = $msg;
        $cache['error_at'] = time();
        update_option(HITHEAN_RECRUIT_CACHE, $cache, false);
        return new WP_Error('recruit_fetch', $msg);
    }
    $html = wp_kses($body['html'], hithean_recruit_allowed_html(), ['http', 'https']);
    $css = str_ireplace('</style', '', (string) ($body['css'] ?? ''));
    $changed = ($cache['html'] ?? '') !== $html || ($cache['css'] ?? '') !== $css;
    update_option(HITHEAN_RECRUIT_CACHE, [
        'html' => $html, 'css' => $css, 'revision' => (string) ($body['revision'] ?? ''),
        'fetched_at' => time(), 'error' => '', 'error_at' => 0,
    ], false);
    if ($changed) {
        $page = get_page_by_path('tuyen-dung');
        if ($page && function_exists('rocket_clean_post')) {
            rocket_clean_post($page->ID);
        }
    }
    return true;
}

add_shortcode('ivar_tuyen_dung', static function (): string {
    $cache = hithean_recruit_cache();
    if (($cache['html'] ?? '') === '' && time() - (int) ($cache['error_at'] ?? 0) > 5 * MINUTE_IN_SECONDS) {
        hithean_recruit_fetch();   // chưa từng có bản nào — lấy ngay một lần
        $cache = hithean_recruit_cache();
    }
    if (($cache['html'] ?? '') === '') {
        return '<p style="padding:40px 16px;text-align:center">Trang tuyển dụng đang cập nhật. Gọi [company_info field="hotline" link="yes"] để được tư vấn.</p>';
    }
    return '<style id="tdj-css">' . $cache['css'] . '</style>' . do_shortcode($cache['html']);
});

add_action('rest_api_init', static function (): void {
    register_rest_route('ivar-recruit/v1', '/refresh', [
        'methods' => 'POST',
        // Công khai có chủ ý: request chỉ là tín hiệu "lấy lại", nội dung luôn lấy từ địa chỉ IVAR cố định ở trên.
        'permission_callback' => '__return_true',
        'callback' => static function () {
            if (get_transient('hithean_recruit_refresh_lock')) {
                return new WP_REST_Response(['ok' => false, 'message' => 'Vừa làm mới, thử lại sau 20 giây.'], 429);
            }
            set_transient('hithean_recruit_refresh_lock', 1, 20);
            $r = hithean_recruit_fetch();
            if (is_wp_error($r)) {
                return new WP_REST_Response(['ok' => false, 'message' => $r->get_error_message()], 502);
            }
            return ['ok' => true, 'revision' => hithean_recruit_cache()['revision'] ?? ''];
        },
    ]);
});

add_action(HITHEAN_RECRUIT_CRON, 'hithean_recruit_fetch');
add_action('init', static function (): void {
    if (!wp_next_scheduled(HITHEAN_RECRUIT_CRON)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', HITHEAN_RECRUIT_CRON);
    }
});

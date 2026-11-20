<?php
/**
 * Giữ snapshot công khai /uu-dai/ luôn mới.
 *
 * Mỗi dòng ưu đãi chỉ "đang áp dụng" trong HITHEAN_OFFERS_FRESHNESS (8 ngày) kể từ lần quét — quá hạn thì bị ẩn khỏi
 * /uu-dai/, "Nhận ưu đãi", nút ưu đãi danh mục, thanh ưu đãi đầu trang. Trước đây chỉ lịch tuần (ô "Tự đồng bộ mỗi tuần")
 * quét lại → tắt ô đó (hoặc 1 lượt tuần lỗi) là ~8 ngày sau trang trống, không ai biết (sự cố 19/11/2026).
 *
 * Nay: khi đang hiển thị công khai, cron 2 lần / ngày (hithean_offers_freshness) tự quét lại khi lần thành công gần nhất
 * đã cũ ≥ 6 ngày (còn 2 ngày trước hạn) — kể cả khi tắt lịch tuần. Còn ≤ 1 ngày mà vẫn chưa quét được → báo Lark kênh
 * "uu_dai" (≤ 1 tin / 20 giờ) + cảnh báo đỏ ở trang quản trị Ưu đãi sản phẩm.
 */
defined('ABSPATH') || exit;

const HITHEAN_OFFERS_REFRESH_MARGIN = 2 * DAY_IN_SECONDS;  // quét lại khi còn ≤ 2 ngày trước hạn
const HITHEAN_OFFERS_ALERT_MARGIN = DAY_IN_SECONDS;        // còn ≤ 1 ngày mà chưa mới → báo động
const HITHEAN_OFFERS_ALERT_GAP = 20 * HOUR_IN_SECONDS;

/**
 * Hàm thuần — có test. $last = lần quét thành công gần nhất (0 = chưa có).
 * → ['scan' => cần quét, 'alert' => cần báo, 'expires' => mốc dữ liệu hết hạn (0 = chưa có), 'level' => ok|warn|stale].
 */
function hithean_offers_freshness_state(int $last, int $now, bool $public, bool $running): array {
    $expires = $last ? $last + HITHEAN_OFFERS_FRESHNESS : 0;
    $left = $expires - $now;
    $level = !$last || $left <= 0 ? 'stale' : ($left <= HITHEAN_OFFERS_REFRESH_MARGIN ? 'warn' : 'ok');
    return [
        'scan' => $public && !$running && $level !== 'ok',
        'alert' => $public && ($level === 'stale' || $left <= HITHEAN_OFFERS_ALERT_MARGIN),
        'expires' => $expires,
        'level' => $level,
    ];
}

function hithean_offers_freshness_current(): array {
    $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
    return hithean_offers_freshness_state((int) get_option('hithean_offers_last_success', 0), time(),
        !hithean_offers_settings()['preview'], in_array($run['status'] ?? '', ['queued', 'running'], true));
}

/** Cron hithean_offers_freshness. */
function hithean_offers_keep_fresh(): void {
    $state = hithean_offers_freshness_current();
    $error = '';
    if ($state['scan']) {
        try { hithean_offers_start(); update_option('hithean_offers_scheduler_error', '', false); }
        catch (Throwable $e) { $error = sanitize_text_field($e->getMessage()); update_option('hithean_offers_scheduler_error', $error, false); }
    }
    if (!$state['alert'] || (int) get_option('hithean_offers_freshness_alerted', 0) + HITHEAN_OFFERS_ALERT_GAP > time()) { return; }
    $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
    $body = $state['level'] === 'stale'
        ? '**Trang /uu-dai/ và popup "Nhận ưu đãi" đang trống** vì dữ liệu ưu đãi quá ' . (HITHEAN_OFFERS_FRESHNESS / DAY_IN_SECONDS) . ' ngày chưa đồng bộ lại.'
        : 'Dữ liệu ưu đãi sẽ hết hạn lúc **' . wp_date('H:i d/m/Y', $state['expires']) . '** — sau mốc đó /uu-dai/ sẽ trống nếu chưa đồng bộ được.';
    $body .= "\nĐã tự đồng bộ lại nhưng chưa thành công. Vào Ưu đãi sản phẩm → \"Đồng bộ ngay\" và xem lỗi.";
    if ($error || !empty($run['errors'])) { $body .= "\nLỗi: " . ($error ?: (string) ($run['errors'][0]['message'] ?? '')); }
    child_theme_lark_load();
    if (function_exists('child_theme_lark_ready') && child_theme_lark_ready('uu_dai')
        && child_theme_lark_notify('uu_dai', ['title' => '⚠️ Ưu đãi chưa đồng bộ được', 'body' => $body, 'status' => $state['level'] === 'stale' ? 'danger' : 'warning',
            'url' => admin_url('admin.php?page=hithean-offers')]) === '') {
        update_option('hithean_offers_freshness_alerted', time(), false);
    }
}
add_action('hithean_offers_freshness', 'hithean_offers_keep_fresh');

/** Cảnh báo ở trang quản trị Ưu đãi sản phẩm (gọi từ admin.php). */
function hithean_offers_freshness_notice(): string {
    $state = hithean_offers_freshness_current();
    if (hithean_offers_settings()['preview'] || $state['level'] === 'ok') { return ''; }
    return '<p class="ho-error">' . ($state['level'] === 'stale'
        ? 'Dữ liệu ưu đãi đã quá hạn đồng bộ — trang /uu-dai/ và popup "Nhận ưu đãi" đang trống. Bấm “Đồng bộ ngay”.'
        : 'Dữ liệu ưu đãi hết hạn lúc ' . esc_html(wp_date('H:i d/m/Y', $state['expires'])) . '; hệ thống đang tự đồng bộ lại. Nếu cảnh báo còn, bấm “Đồng bộ ngay”.') . '</p>';
}

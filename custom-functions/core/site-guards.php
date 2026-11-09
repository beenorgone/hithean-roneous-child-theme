<?php
if (!defined('ABSPATH')) exit;

/**
 * Header / chặn mức site (cùng tên file với theanmarket).
 */

/**
 * Cho phép HUB (ivarvietnam.com) NHÚNG trang /tien-ich-admin trong iframe
 * (tab "Tiện ích {SITE}" của app Kế toán).
 *
 * - X-Frame-Options bị GỠ: chuẩn này không có allowlist (ALLOW-FROM đã bị các
 *   trình duyệt bỏ), để nguyên SAMEORIGIN là hub bị chặn.
 * - Thay bằng CSP frame-ancestors: chỉ cho chính site + hub, không mở cho ai khác.
 * Chỉ áp cho ĐÚNG trang này, không đụng phần còn lại của site.
 * Priority 99 để chạy sau các plugin bảo mật thường thêm header ở priority mặc định.
 */
add_action('send_headers', function (): void {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'tien-ich-admin') {
        return;
    }
    $allow = apply_filters('tpc_embed_allow_origins', ['https://ivarvietnam.com']);
    header_remove('X-Frame-Options');
    header("Content-Security-Policy: frame-ancestors 'self' " . implode(' ', array_map('esc_url_raw', (array) $allow)));
}, 99);

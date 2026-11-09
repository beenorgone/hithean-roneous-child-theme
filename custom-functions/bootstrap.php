<?php
if (!defined('ABSPATH')) exit;

/**
 * Bootstrap chính của hithean child theme — CHỈ nạp file, không chứa logic.
 *
 * Nạp các file hạ tầng trong core/ trước (chỉ đăng ký hook), rồi tới
 * module-loader.php — nơi chứa TOÀN BỘ logic what-loads-when ($general_includes,
 * tpc_loader, conditional/admin/ajax loaders, social-display, editor-tools).
 * Hook / filter mới → viết thành file riêng rồi khai ở module-loader, không thêm vào đây.
 */

// functions.php define sẵn; phòng khi bootstrap bị nạp từ chỗ khác (test, WP-CLI eval-file).
if (!defined('HITHEAN_THEME_DIR')) {
    define('HITHEAN_THEME_DIR', dirname(__DIR__));
}

require_once __DIR__ . '/core/template-router.php';
require_once __DIR__ . '/core/enqueue.php';
require_once __DIR__ . '/core/admin.php';
require_once __DIR__ . '/core/email-overrides.php';
require_once __DIR__ . '/core/upload-guard.php';
require_once __DIR__ . '/core/public-file-guard.php';
require_once __DIR__ . '/core/module-loader.php';

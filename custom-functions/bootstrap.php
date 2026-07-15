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
require_once __DIR__ . '/core/guest-page-access.php';
require_once __DIR__ . '/core/enqueue.php';
require_once __DIR__ . '/core/admin.php';
require_once __DIR__ . '/core/email-overrides.php';
require_once __DIR__ . '/core/upload-guard.php';
require_once __DIR__ . '/core/public-file-guard.php';
require_once __DIR__ . '/core/module-loader.php';

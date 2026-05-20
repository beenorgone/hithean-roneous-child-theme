<?php
if (!defined('ABSPATH')) exit;

/* ===================== SQLs =====================
 * Tối ưu các query WordPress/WooCommerce chậm.
 * ================================================ */

// Bỏ qua "SELECT DISTINCT meta_key FROM wp_postmeta" — query scan toàn bảng
// chạy khi mở/lưu post để populate dropdown Custom Fields metabox.
// Trả về [] (non-null) để WP skip query; dropdown trống nhưng không ảnh hưởng workflow.
add_filter('postmeta_form_keys', '__return_empty_array');

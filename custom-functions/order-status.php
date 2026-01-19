<?php

/**
 * Plugin Name: Custom Order Status Chains for WooCommerce
 * Description: Implements custom order statuses with Vietnamese translations and restrictions.
 * Author: Your Name
 * Version: 1.4
 */

if (!defined('ABSPATH')) exit;

/**
 * Register Custom Order Statuses
 */
function register_custom_order_statuses()
{
    register_post_status('wc-paid', array(
        'label'                     => 'Đã CK',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('Đã CK <span class="count">(%s)</span>', 'Đã CK <span class="count">(%s)</span>', 'woocommerce'),
    ));
	
	    register_post_status('wc-partial-paid', array(
        'label'                     => 'CK 1 phần',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('CK 1 phần <span class="count">(%s)</span>', 'CK 1 phần <span class="count">(%s)</span>', 'woocommerce'),
    ));

    register_post_status('wc-packaging', array(
        'label'                     => 'Chuẩn bị giao VC',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('Chuẩn bị giao VC <span class="count">(%s)</span>', 'Chuẩn bị giao VC <span class="count">(%s)</span>', 'woocommerce'),
    ));

    register_post_status('wc-local-shipping', array(
        'label'                     => 'Giao nhanh',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('Giao nhanh <span class="count">(%s)</span>', 'Giao nhanh <span class="count">(%s)</span>', 'woocommerce'),
    ));

    register_post_status('wc-shipping', array(
        'label'                     => 'Đang giao',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('Đang giao <span class="count">(%s)</span>', 'Đang giao <span class="count">(%s)</span>', 'woocommerce'),
    ));

    register_post_status('wc-delivered', array(
        'label'                     => 'Đã giao',
        'public'                    => true,
        'show_in_admin_status_list'  => true,
        'show_in_admin_all_list'     => true,
        'label_count'                => _n_noop('Đã giao <span class="count">(%s)</span>', 'Đã giao <span class="count">(%s)</span>', 'woocommerce'),
    ));
}
add_action('init', 'register_custom_order_statuses');

/**
 * Add Custom Order Statuses to WooCommerce List
 */
function add_custom_wc_order_statuses($order_statuses)
{
    $order_statuses['wc-paid'] = 'Đã CK';
    $order_statuses['wc-packaging'] = 'Chuẩn bị giao VC';
    $order_statuses['wc-local-shipping'] = 'Giao nhanh';
    $order_statuses['wc-shipping'] = 'Đang giao';
    $order_statuses['wc-delivered'] = 'Đã giao';
    return $order_statuses;
}
add_filter('wc_order_statuses', 'add_custom_wc_order_statuses');

/**
 * Add Custom Colors & Icons for Statuses
 */
function custom_order_status_styles()
{
    echo '<style>
        .order-status.status-paid { background: yellow; color: #000; }
        .order-status.status-packaging { background: blue; color: #fff; }
        .order-status.status-local-shipping { background: #111; color: #fff; }
        .order-status.status-shipping { background: green; color: #fff; }
        .order-status.status-delivered { background: #999; color: #fff; }
    </style>';
}
add_action('admin_head', 'custom_order_status_styles');

/**
 * 🚫 Ngăn WooCommerce hoàn kho khi chuyển sang trạng thái tùy chỉnh
 */
add_filter('woocommerce_order_statuses_that_restore_stock', function ($statuses) {
    $custom_statuses = array_keys(cws_get_custom_statuses());

    // Thêm tiền tố 'wc-' vì WooCommerce lưu trạng thái dạng 'wc-status'
    $custom_wc_statuses = array_map(function ($s) {
        return 'wc-' . $s;
    }, $custom_statuses);

    // Loại bỏ trạng thái tùy chỉnh khỏi danh sách được phép hoàn kho
    return array_diff($statuses, $custom_wc_statuses);
});

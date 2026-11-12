<?php
if (!defined('ABSPATH')) exit;

/**
 * Force WooCommerce invoice/order-details emails to use child-theme override.
 * This avoids fallback to plugin/default template when other hooks interfere.
 */
function hroneous_force_email_order_details_template($emails)
{
    if (! is_object($emails) || ! method_exists($emails, 'order_details')) {
        return;
    }

    remove_action('woocommerce_email_order_details', array($emails, 'order_details'), 10);
    add_action('woocommerce_email_order_details', 'hroneous_render_custom_email_order_details', 10, 4);
}
add_action('woocommerce_email', 'hroneous_force_email_order_details_template', 999);

function hroneous_render_custom_email_order_details($order, $sent_to_admin, $plain_text, $email)
{
    if (! $order instanceof WC_Order) {
        return;
    }

    wc_get_template(
        'emails/email-order-details.php',
        array(
            'order'         => $order,
            'sent_to_admin' => $sent_to_admin,
            'plain_text'    => $plain_text,
            'email'         => $email,
        ),
        '',
        trailingslashit(get_stylesheet_directory()) . 'woocommerce/'
    );
}

/**
 * Bố cục email giao dịch trên điện thoại (mọi email WooCommerce).
 * Media query không bị inline nên được giữ trong <style> ở <head>; dùng !important để đè style inline của Woo.
 * Không dùng display:none ở class mặc định (HtmlPruner của Woo xóa phần tử bị ẩn) — chỉ ẩn trong media query.
 */
function hroneous_email_mobile_styles($css)
{
    $css .= '
@media screen and (max-width: 620px) {
    #wrapper { padding: 0 !important; }
    #template_container, #template_header, #template_body, #template_footer,
    #template_header_image table, #body_content { width: 100% !important; max-width: 100% !important; }
    #template_container { border-radius: 0 !important; box-shadow: none !important; zoom: 1 !important; }
    #template_header_image img { max-width: 160px !important; height: auto !important; }
    #header_wrapper { padding: 20px 16px !important; }
    #header_wrapper h1, #template_header h1 { font-size: 22px !important; line-height: 1.3 !important; }
    /* Woo đặt lề 48px ở ô td bao #body_content_inner (selector "#body_content table td"; WC mới: #body_content_inner_cell). */
    #body_content > table > tbody > tr > td, #body_content > table > tr > td, #body_content_inner_cell { padding: 20px 14px 16px !important; }
    #body_content_inner { padding: 0 !important; font-size: 15px !important; line-height: 1.5 !important; }
    #body_content_inner h2 { font-size: 18px !important; line-height: 1.35 !important; }
    #body_content_inner p, #body_content_inner li { font-size: 15px !important; line-height: 1.5 !important; }
    #body_content_inner blockquote { margin: 12px 0 !important; padding: 8px 12px !important; border-left: 3px solid #0047ba !important; }
    #body_content_inner table.td { font-size: 13px !important; }
    #body_content_inner table.td th, #body_content_inner table.td td { padding: 8px 6px !important; }
    #body_content_inner .hr-email-order-table { table-layout: auto !important; }
    #body_content_inner .hr-email-order-table col { width: auto !important; }
    #body_content_inner .hr-email-order-table .hr-col-regular { display: none !important; }
    #body_content_inner .hr-email-order-table thead th { width: auto !important; white-space: nowrap !important; }
    #body_content_inner .hr-email-order-table tbody td:first-child { overflow-wrap: break-word !important; word-break: normal !important; }
    #body_content_inner table:first-child tfoot tr:last-child { font-size: 115% !important; }
    #addresses td { display: block !important; width: 100% !important; padding: 0 0 12px !important; }
    #addresses td address { padding-right: 0 !important; }
    #body_content_inner img { max-width: 100% !important; height: auto !important; }
    #template_footer #credit { padding: 16px 14px !important; }
}';
    return $css;
}
add_filter('woocommerce_email_styles', 'hroneous_email_mobile_styles', 99);

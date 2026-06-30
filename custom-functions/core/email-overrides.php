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

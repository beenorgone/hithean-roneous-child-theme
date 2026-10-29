<?php
/** Canonical bridge for the warehouse workflow's legacy post-meta confirmation. */
if (!defined('ABSPATH')) exit;

function child_theme_order_export_is_confirmed(WC_Order $order): bool {
    // Existing export handlers still write post meta, including when HPOS is enabled.
    return (bool) ($order->get_meta('export_confirmed_by') ?: get_post_meta($order->get_id(), 'export_confirmed_by', true));
}

<?php
// Send processing email manually for testing - Gui email xu ly don hang bang tay
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order_id);
}, 10, 1);



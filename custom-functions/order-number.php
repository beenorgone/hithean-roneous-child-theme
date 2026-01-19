<?php
function change_order_number($order_id)
{
    // Handle cases where order ID is invalid (0 or non-numeric)
    if ($order_id <= 0) {
        return 'Invalid Order ID';
    }

    // Remove "#" if it's mistakenly included
    $order_id = str_ireplace("#", "", $order_id);

    // Split the order ID into two-digit segments
    $nums1 = str_split($order_id, 2);
    $nums2 = str_split(strrev($order_id), 2);

    // Ensure $nums1 and $nums2 have valid elements to prevent errors
    $suffix = (isset($nums1[0]) && isset($nums2[0])) ? ceil(($nums1[0] + $nums2[0]) / 2) : 0;

    // Generate the new order ID
    $new_order_id = $order_id . $suffix;

    return $new_order_id;
}

add_filter( 'woocommerce_order_number', 'thean_change_woocommerce_order_number' );

function thean_change_woocommerce_order_number( $order_id ) {

    $order_id = str_ireplace("#", "", $order_id);//remove # before from order id
    $nums1 = str_split($order_id, 2);
    $nums2 = str_split(strrev($order_id), 2);
    $suffix= ceil(($nums1[0] + $nums2[0]) / 2);

    $new_order_id = $order_id . $suffix;
    return $new_order_id;
}

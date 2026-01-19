<?php
// Make custom fields searchable

function custom_order_search( $search_fields ) {
    // Add the custom field to the searchable fields
    $search_fields[] = 'order_shipper';
    $search_fields[] = 'order_ship_code';
    $search_fields[] = 'order_handling_status';
    $search_fields[] = '_ghtk_ordercode';

    return $search_fields;
}
//add_filter( 'woocommerce_shop_order_search_fields', 'custom_order_search' );



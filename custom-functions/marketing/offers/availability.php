<?php
defined('ABSPATH') || exit;

/** A product can advertise offers while in stock or explicitly accepting backorders. */
function hithean_offers_product_available($product): bool {
    return $product && ($product->is_in_stock() || $product->backorders_allowed());
}

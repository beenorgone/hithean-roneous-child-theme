<?php

if (!function_exists('ct_get_order_item_display_name')) {
    function ct_get_order_item_display_name($item)
    {
        if (!$item || !is_a($item, 'WC_Order_Item_Product')) {
            return '';
        }

        $product = $item->get_product();
        $item_name = $item->get_name();

        if (!$product || !$product->is_type('variation')) {
            return $item_name;
        }

        $parent = wc_get_product($product->get_parent_id());
        $parent_name = $parent ? $parent->get_name() : '';
        $variation_label = wc_get_formatted_variation($product, true, false, false);

        if ($variation_label === '') {
            $variation_label = wc_get_formatted_variation($item, true, false, false);
        }

        if ($variation_label === '') {
            return $item_name;
        }

        if ($parent_name && strpos($item_name, $parent_name) === 0) {
            return $parent_name . ' (' . $variation_label . ')';
        }

        return $item_name . ' (' . $variation_label . ')';
    }
}

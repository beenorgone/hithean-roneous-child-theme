<?php
/* Đổi mô tả hình thức giao hàng */
add_filter('devvn_ghtk_shipping_methob', 'devvn_custom_ghtk_shipping_methob');
function devvn_custom_ghtk_shipping_methob($methob)
{
    $methob = array(
        // 'road' => 'GHTK giao th ^ ng',
        'fly' => 'GHTK giao nhanh / Gói giao nhanh không loại trừ sự cố có thể phát sinh'
    );
    return $methob;
}

add_filter('devvn_ghtk_create_order_products', 'custom_devvn_ghtk_create_order_products', 10, 3);

function custom_devvn_ghtk_create_order_products($products, $main_class, $order)
{
    // Kiểm tra nếu có danh sách sản phẩm
    if (is_array($products) && count($products) > 0) {
        foreach ($products as $key => $product) {
            // Chỉ thay đổi tên sản phẩm
            $products[$key]['name'] = 'Thực phẩm bao gói';
            // Lưu ý: Các thông tin khác như weight, quantity sẽ được giữ nguyên từ đơn hàng gốc
        }
    }
    return $products;
}

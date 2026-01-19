<?php

/**
 * Thêm nút tùy chỉnh kế bên nút Add to Cart trong danh sách sản phẩm
 */
add_action('woocommerce_after_shop_loop_item', 'custom_button_after_add_to_cart', 15);
function custom_button_after_add_to_cart()
{
    global $product;

    $product_link = get_permalink($product->get_id());
    echo '<a href="' . esc_url($product_link) . '" class="button--light-blue">' . esc_html__('Xem chi tiết', 'hithean.com') . '</a>';
}


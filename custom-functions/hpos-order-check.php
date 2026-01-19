<?php
add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Kiểm tra đơn HPOS chưa migrate', 'HPOS Check', 'manage_woocommerce', 'hpos-check', function () {
        global $wpdb;

        // Tìm tất cả đơn từ bảng HPOS
        $order_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders");

        echo "<h2>🔍 Danh sách đơn hàng HPOS chưa có bản ghi post_type='shop_order'</h2>";
        echo "<table class='widefat'><thead><tr><th>ID</th><th>Link</th></tr></thead><tbody>";

        $missing = 0;

        foreach ($order_ids as $order_id) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'",
                $order_id
            ));

            if (!$exists) {
                echo "<tr><td>{$order_id}</td><td><a href='" . admin_url("post.php?post={$order_id}&action=edit") . "'>Xem đơn hàng</a></td></tr>";
                $missing++;
            }
        }

        echo "</tbody></table>";
        echo "<p><strong>Tổng số đơn HPOS không có post shop_order: {$missing}</strong></p>";
    });
});


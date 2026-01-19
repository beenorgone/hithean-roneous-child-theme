<?php
/*
 * Shortcode theo dõi đơn: hỗ trợ SĐT / Order ID / Email
 * Author: levantoan.com + thean
 */
add_action('init', function () {
    remove_shortcode('woocommerce_order_tracking');
    add_shortcode('woocommerce_order_tracking', function ($atts) {
        $atts = shortcode_atts([], $atts, 'woocommerce_order_tracking');

        ob_start();

        // Xử lý submit
        $nonce_value = wc_get_var($_REQUEST['woocommerce-order-tracking-nonce'], wc_get_var($_REQUEST['_wpnonce'], ''));
        $query       = isset($_REQUEST['order_lookup']) ? wp_unslash($_REQUEST['order_lookup']) : '';
        $query       = is_string($query) ? trim($query) : '';

        if (!empty($query) && wp_verify_nonce($nonce_value, 'woocommerce-order_tracking')) {

            $orders_to_render = [];

            // Helper: chuẩn hoá SĐT VN
            $normalize_phone = function ($raw) {
                // bỏ mọi ký tự không phải số
                $digits = preg_replace('/\D+/', '', $raw);

                // Nếu bắt đầu bằng 84 và độ dài >= 10 => chuyển về 0xxxxxxxxx
                if (strpos($digits, '84') === 0 && strlen($digits) >= 11) {
                    $digits = '0' . substr($digits, 2);
                }

                // Nếu dài > 11 thì lấy 10-11 số cuối (phòng khi nhập cả mã vùng/quốc gia)
                if (strlen($digits) > 11) {
                    $digits = substr($digits, -11);
                }

                return $digits;
            };

            $is_email    = is_email($query);
            $is_order_id = preg_match('/^#?\d+$/', $query); // cho phép nhập #12345

            try {
                if ($is_email) {
                    // Tìm theo email billing
                    $orders = wc_get_orders([
                        'billing_email' => sanitize_email($query),
                        'limit'         => 3,
                        'orderby'       => 'date',
                        'order'         => 'DESC',
                        'return'        => 'objects',
                    ]);
                    $orders_to_render = $orders;
                } elseif ($is_order_id) {
                    // Tìm theo mã đơn chính xác
                    $order_id = (int) ltrim($query, '#');
                    $order    = wc_get_order($order_id);
                    if ($order) {
                        $orders_to_render = [$order];
                    }

                    // Nếu không thấy theo ID thì thử coi như SĐT (trường hợp người dùng nhập toàn số nhưng không phải ID)
                    if (!$orders_to_render) {
                        $phone_norm = $normalize_phone($query);
                        if ($phone_norm) {
                            // Thử exact trước
                            $orders_exact = wc_get_orders([
                                'billing_phone' => $phone_norm,
                                'limit'         => 3,
                                'orderby'       => 'date',
                                'order'         => 'DESC',
                                'return'        => 'objects',
                            ]);

                            if (!empty($orders_exact)) {
                                $orders_to_render = $orders_exact;
                            } else {
                                // Fallback LIKE (phòng dữ liệu lưu có khoảng trắng)
                                $orders_like = wc_get_orders([
                                    'limit'   => 3,
                                    'orderby' => 'date',
                                    'order'   => 'DESC',
                                    'return'  => 'objects',
                                    'meta_query' => [
                                        [
                                            'key'     => '_billing_phone',
                                            'value'   => $phone_norm,
                                            'compare' => 'LIKE',
                                        ],
                                    ],
                                ]);
                                $orders_to_render = $orders_like;
                            }
                        }
                    }
                } else {
                    // Mặc định coi như SĐT
                    $phone_norm = $normalize_phone($query);

                    if ($phone_norm) {
                        // Thử exact trước
                        $orders = wc_get_orders([
                            'billing_phone' => $phone_norm,
                            'limit'         => 3,
                            'orderby'       => 'date',
                            'order'         => 'DESC',
                            'return'        => 'objects',
                        ]);

                        if (empty($orders)) {
                            // Fallback LIKE
                            $orders = wc_get_orders([
                                'limit'   => 3,
                                'orderby' => 'date',
                                'order'   => 'DESC',
                                'return'  => 'objects',
                                'meta_query' => [
                                    [
                                        'key'     => '_billing_phone',
                                        'value'   => $phone_norm,
                                        'compare' => 'LIKE',
                                    ],
                                ],
                            ]);
                        }

                        $orders_to_render = $orders;
                    }
                }
            } catch (Throwable $e) {
                wc_add_notice(__('Đã xảy ra lỗi khi tra cứu. Vui lòng thử lại.', 'devvn'), 'error');
            }

            if (!empty($orders_to_render)) {
                echo '<div id="theo-doi-don">';
                foreach ($orders_to_render as $order) {
                    // Hiển thị giống trang tracking mặc định của Woo
                    wc_get_template('order/tracking.php', ['order' => $order]);
                }
                echo '</div>';

                return ob_get_clean();
            } else {
                wc_print_notice(__('Xin lỗi, không tìm thấy đơn hàng phù hợp với thông tin bạn nhập.', 'devvn'), 'error');
            }
        }

        // Form nhập liệu
        wc_print_notices();
?>
        <style>
            #order_lookup {
                width: 100%;
                max-width: 500px;
                border-radius: 5px;
                border: 2px solid var(--default-color-green) !important;
            }
        </style>
        <div id="theo-doi-don">
            <h2>Theo dõi đơn hàng</h2>
            <form action="" method="post" class="woocommerce-form woocommerce-form-track-order track_order">
                <?php do_action('woocommerce_order_tracking_form_start'); ?><p><?php esc_html_e('Nhập số điện thoại, mã đơn hàng hoặc Email để theo dõi (hiển thị tối đa 3 đơn gần nhất):', 'devvn'); ?></p>
                <p class="form-row form-row-first">
                    <label for="order_lookup"><?php esc_html_e('SĐT / Mã Đơn / Email', 'devvn'); ?></label>
                    <input class="input-text" type="text" name="order_lookup" id="order_lookup"
                        value="<?php echo isset($_REQUEST['order_lookup']) ? esc_attr(wp_unslash($_REQUEST['order_lookup'])) : ''; ?>"
                        placeholder="<?php esc_attr_e('Ví dụ: 0988xxxxxx hoặc #12345 hoặc email@domain.com', 'devvn'); ?>" />
                </p>

                <div class="clear"></div>
                <?php do_action('woocommerce_order_tracking_form'); ?>

                <p class="form-row">
                    <button type="submit"
                        class="button button--green<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>"
                        name="track" value="<?php esc_attr_e('Theo dõi', 'devvn'); ?>">
                        <?php esc_html_e('Theo dõi', 'devvn'); ?>
                    </button>
                </p>

                <?php wp_nonce_field('woocommerce-order_tracking', 'woocommerce-order-tracking-nonce'); ?>
                <?php do_action('woocommerce_order_tracking_form_end'); ?>
            </form>
        </div>
<?php

        return ob_get_clean();
    });
}, 20);

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
            $results_html     = '';

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

            $is_email         = is_email($query);
            $query_phone_norm = $normalize_phone($query);
            $is_phone_like    = (bool) preg_match('/^0\d{9,10}$/', $query_phone_norm);
            $is_order_id      = preg_match('/^#?\d+$/', $query) && !$is_phone_like; // cho phép nhập #12345, tránh nhầm với SĐT
            $mask_address = function ($address) {
                $address = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $address)));
                if ($address === '') {
                    return '';
                }

                if (preg_match('/^(.{1,12}).*$/u', $address, $matches)) {
                    return $matches[1] . '*******';
                }

                return '*******';
            };
            $find_orders_by_phone = function ($raw_phone, $limit = 3) use ($normalize_phone) {
                $phone_norm = $normalize_phone($raw_phone);
                if (!$phone_norm) {
                    return [];
                }

                $matched   = [];
                $matched_id = [];
                $append_match = function ($orders) use (&$matched, &$matched_id, $normalize_phone, $phone_norm, $limit) {
                    foreach ($orders as $order) {
                        if (!($order instanceof WC_Order)) {
                            continue;
                        }
                        if ($normalize_phone($order->get_billing_phone()) !== $phone_norm) {
                            continue;
                        }
                        $order_id = $order->get_id();
                        if (isset($matched_id[$order_id])) {
                            continue;
                        }

                        $matched_id[$order_id] = true;
                        $matched[] = $order;
                        if (count($matched) >= $limit) {
                            break;
                        }
                    }
                };

                // Tìm exact trước.
                $append_match(wc_get_orders([
                    'billing_phone' => $phone_norm,
                    'limit'         => $limit,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                    'return'        => 'objects',
                ]));

                // Fallback LIKE, nhưng chỉ giữ đơn có số điện thoại chuẩn hoá trùng hoàn toàn.
                if (count($matched) < $limit) {
                    $append_match(wc_get_orders([
                        'limit'      => 20,
                        'orderby'    => 'date',
                        'order'      => 'DESC',
                        'return'     => 'objects',
                        'meta_query' => [
                            [
                                'key'     => '_billing_phone',
                                'value'   => $phone_norm,
                                'compare' => 'LIKE',
                            ],
                        ],
                    ]));
                }

                // Trường hợp dữ liệu có dấu cách/ký tự phân tách khiến LIKE không ăn khớp.
                if (empty($matched)) {
                    $append_match(wc_get_orders([
                        'limit'   => 300,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                        'return'  => 'objects',
                    ]));
                }

                return $matched;
            };

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
                    $order_id_raw = ltrim($query, '#');
                    $order_id     = (int) $order_id_raw;
                    $order        = wc_get_order($order_id);
                    if ($order) {
                        $orders_to_render = [$order];
                    }

                    // Nếu không thấy theo ID thì bỏ 2 chữ số cuối và tìm lại.
                    if (!$orders_to_render) {
                        if (strlen($order_id_raw) > 2) {
                            $trimmed_order_id = (int) substr($order_id_raw, 0, -2);
                            if ($trimmed_order_id > 0) {
                                $trimmed_order = wc_get_order($trimmed_order_id);
                                if ($trimmed_order) {
                                    $orders_to_render = [$trimmed_order];
                                }
                            }
                        }
                    }

                    // Không thấy theo mã đơn mới chuyển sang SĐT.
                    if (!$orders_to_render) {
                        $orders_to_render = $find_orders_by_phone($query, 3);
                    }
                } else {
                    // Mặc định coi như SĐT
                    $orders_to_render = $find_orders_by_phone($query, 3);
                }
            } catch (Throwable $e) {
                wc_add_notice(__('Đã xảy ra lỗi khi tra cứu. Vui lòng thử lại.', 'devvn'), 'error');
            }

            if (!empty($orders_to_render)) {
                ob_start();
                echo '<div class="tracking-results">';
                foreach ($orders_to_render as $order) {
                    $customer_name    = trim($order->get_formatted_billing_full_name());
                    $customer_phone   = $order->get_billing_phone();
                    $customer_email   = $order->get_billing_email();
                    $billing_address  = $order->get_formatted_billing_address();
                    $masked_address   = $mask_address($billing_address);

                    echo '<div class="tracking-customer">';
                    echo '<strong>' . esc_html__('Người đặt:', 'devvn') . '</strong> ';
                    echo esc_html($customer_name ?: __('(Không có tên)', 'devvn'));
                    if (!empty($customer_phone)) {
                        echo ' | <strong>' . esc_html__('SĐT:', 'devvn') . '</strong> ' . esc_html($customer_phone);
                    }
                    if (!empty($customer_email)) {
                        echo ' | <strong>' . esc_html__('Email:', 'devvn') . '</strong> ' . esc_html($customer_email);
                    }
                    if (!empty($masked_address)) {
                        echo ' | <strong>' . esc_html__('Địa chỉ:', 'devvn') . '</strong> ' . esc_html($masked_address);
                    }
                    echo '</div>';

                    // Hiển thị giống trang tracking mặc định của Woo
                    wc_get_template('order/tracking.php', ['order' => $order]);
                }
                echo '</div>';
                $results_html = ob_get_clean();
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
            .tracking-customer {
                margin: 12px 0 8px;
                padding: 10px 12px;
                background: #f6faf7;
                border-left: 3px solid var(--default-color-green);
                border-radius: 4px;
                font-size: 14px;
                line-height: 1.5;
            }
            .tracking-reset-btn {
                margin-left: 8px;
            }
        </style>
        <div id="theo-doi-don">
            <?php if (!empty($results_html)) : ?>
                <?php echo $results_html; ?>
            <?php endif; ?>
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
                    <button type="button" class="button tracking-reset-btn" id="tracking-reset-btn">
                        <?php esc_html_e('Tìm lại', 'devvn'); ?>
                    </button>
                </p>

                <?php wp_nonce_field('woocommerce-order_tracking', 'woocommerce-order-tracking-nonce'); ?>
                <?php do_action('woocommerce_order_tracking_form_end'); ?>
            </form>
        </div>
        <script>
            (function() {
                var resetBtn = document.getElementById('tracking-reset-btn');
                var lookupInput = document.getElementById('order_lookup');
                var trackingRoot = document.getElementById('theo-doi-don');
                if (!resetBtn || !lookupInput || !trackingRoot) {
                    return;
                }

                resetBtn.addEventListener('click', function() {
                    lookupInput.value = '';
                    lookupInput.focus();

                    var results = trackingRoot.querySelector('.tracking-results');
                    if (results) {
                        results.remove();
                    }

                    var notices = document.querySelectorAll('.woocommerce-error, .woocommerce-message, .woocommerce-info');
                    notices.forEach(function(node) {
                        node.remove();
                    });
                });
            })();
        </script>
<?php

        return ob_get_clean();
    });
}, 20);

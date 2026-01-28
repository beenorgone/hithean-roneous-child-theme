<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>In hóa đơn</title>
    <style>
        * {
            padding: 0;
            margin: 0;
            -webkit-box-sizing: border-box;
            -moz-box-sizing: border-box;
            box-sizing: border-box;
        }

        .invoice_a4 {
            width: 297mm;
            margin: 0 auto;
            overflow: hidden;
        }

        .page {
            float: left;
            width: 50%;
            height: 100%;
            height: 209mm;
            position: relative;
            padding: 10mm 0;
        }

        img {
            max-width: 100%;
            height: auto;
        }

        body,
        table {
            font-family: Arial;
            font-size: 12px;
        }

        .invoice_wrap {
            display: block;
            /*height: 100%;*/
            overflow: hidden;
            border: 1px solid black;
            margin: 0 7mm;
        }

        .invoice_wrap table {
            border-collapse: collapse;
            border: 1px solid #000;
            width: 100%;
        }

        .invoice_wrap table td,
        .invoice_wrap table th {
            padding: 5px;
            border: 1px solid #000;
            vertical-align: top;
        }

        .invoice_wrap table td:first-child {
            font-size: 16px;
            font-weight: 700;
        }

        .invoice_header:after,
        .invoice_total:after {
            content: "";
            display: table;
            clear: both;
        }

        .invoice_logo {
            display: table-cell;
            text-align: center;
            vertical-align: middle;
        }

        .invoice_infor {
            display: table-cell;
            padding: 5px 0;
            text-align: center;
            vertical-align: middle;
            width: 69mm;
        }

        div[id^="barcode_mvd"] {
            margin: 0 auto;
        }

        .invoice_row~.invoice_row {
            border-top: 1px dashed #000;
            padding-top: 5px;
            padding-bottom: 5px;
        }

        .invoice_body {
            display: table;
            width: 100%;
        }

        .invoice_body_left,
        .invoice_body_right {
            display: table-cell;
            padding: 0 10px;
        }

        .invoice_body_left {
            border-right: 1px solid #000;
            width: 50px;
        }

        .invoice_body_right div[id^="barcode_mdh"] {
            float: right;
        }

        .invoice_total_left,
        .invoice_total_right {
            width: auto;
            float: left;
            text-align: center;
        }

        .invoice_total strong {
            /* display: block; */
            margin: 0 0 5px 0;
        }

        span.total_price {
            font-size: 20px;
            font-weight: 700;
        }

        .invoice_total_right {
            min-height: 80px;
        }

        .invoice_wrap table tr th:nth-child(2),
        .invoice_wrap table tr td:nth-child(2) {
            width: 30px;
            text-align: center;
        }

        .invoice_sanpham {
            padding: 0 5px 5px 5px;
        }

        .invoice_logo .no-print {
            padding-top: 10px;
            color: red;
        }

        .invoice_header {
            height: 33mm;
            overflow: hidden;
            display: table;
            width: 100%;
        }

        .invoice_row.shop_address {
            max-height: 24.3mm;
            overflow: hidden;
        }

        .invoice_row.customer_address {
            max-height: 20mm;
            overflow: hidden;
        }

        .invoice_row.product_row {
            height: 80mm;
            /* overflow: hidden; */
        }

        .invoice_row.note_row {
            height: 17.2mm;
            overflow: hidden;
            line-height: 1.3;
        }

        .invoice_total {
            padding-top: 10px;
        }

        .invoice_total_left {
            text-align: left;
            padding-left: 20px;
        }

        .invoice_total_left p {
            margin-top: 10px;
        }

        .invoice_total_left p strong {
            display: inline-block;
        }

        .note_print_before {
            width: 100%;
            max-width: 800px;
            margin: 30px auto 0;
            border: 2px dashed red;
            padding: 10px;
            font-size: 15px;
            text-align: center;
        }

        body:not(.print_1_col) .invoice_a4 .page:nth-of-type(2n+1) {
            border-right: 1px solid #000;
        }

        .note_print_before button {
            padding: 5px 10px;
            border-radius: 5px;
            border: 0;
            background: green;
            color: #fff;
            margin: 10px 10px 0 10px;
            cursor: pointer;
            outline: none;
        }

        .note_print_before button.selected {
            background: red;
        }

        @media print {
            .no-print {
                display: none;
            }
        }

        /*@page { size: A5 landscape; margin: 0;}*/
        .print_1_col .page {
            width: 100%;
        }

        .print_1_col .invoice_a4 {
            width: 148mm;
        }

        /*@page { size: portrait; margin: 0;}*/
        .free_height {
            display: none;
        }

        .print_1_col .free_height {
            display: inline-block;
        }

        .free_height_body .invoice_row.product_row {
            height: auto;
        }

        .free_height_body .page {
            height: auto;
            padding: 3mm 0;
        }

        .print_a8 .invoice_a4 {
            width: 88mm;
        }

        .print_a8 .invoice_wrap {
            margin: 0;
            border: 0;
        }

        .print_a8 .invoice_logo {
            display: block;
            width: 100%;
        }

        .print_a8 .invoice_header {
            display: block;
            height: auto;
        }

        .print_a8 .invoice_infor {
            display: block;
            width: 100%;
        }

        .print_a8 .invoice_total {
            padding: 0;
        }

        .print_a8 .invoice_total_left {
            padding-left: 5px;
        }

        .free_height_body .invoice_row.note_row {
            height: auto;
        }

        .print_a8 .page {
            border-bottom: 1px solid #000;
        }

        .print_a8 .page:last-of-type {
            border-bottom: 0;
        }

        .free_height_body .invoice_row.shop_address {
            max-height: inherit;
        }

        .big {
            font-size: 25px;
        }

        .big2 {
            font-size: 18px;
        }

        .bold {
            font-weight: 700;
        }
    </style>

    <script type='text/javascript' src='<?php echo home_url(); ?>/wp-includes/js/jquery/jquery.js'></script>
    <script type="text/javascript" src="<?php echo DEVVN_GHTK_URL; ?>assets/js/jquery-barcode.min.js"></script>
    <script>
        (function($) {
            $(document).ready(function() {
                function resetBodyClass() {
                    $('body').removeClass('print_a8 print_1_col free_height_body');
                }
                $('.in_a5').on('click', function() {
                    resetBodyClass();
                    $('.note_print_before button').removeClass('selected');
                    $(this).addClass('selected');
                    $('.style_js').html('<style>@page { size: A5 landscape; margin: 0;}</style>');
                });
                $('.in_a8').on('click', function() {
                    resetBodyClass();
                    $('body').addClass('print_a8');
                    $('body').addClass('print_1_col');
                    $('body').addClass('free_height_body');
                    $('#free_height').prop('checked', true);
                    $('.note_print_before button').removeClass('selected');
                    $(this).addClass('selected');
                    $('.style_js').html('<style>@page { size: portrait; margin: 0;}</style>');
                });
                $('.in_a6').on('click', function() {
                    resetBodyClass();
                    $('body').addClass('print_1_col');
                    $('.note_print_before button').removeClass('selected');
                    $(this).addClass('selected');
                    $('.style_js').html('<style>@page { size: portrait; margin: 0;}</style>');
                    if ($('#free_height').is(':checked')) {
                        $('body').addClass('free_height_body');
                    } else {
                        $('body').removeClass('free_height_body');
                    }
                });
                $('#free_height').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('body').addClass('free_height_body');
                    } else {
                        $('body').removeClass('free_height_body');
                    }
                });
            });
        })(jQuery);
    </script>
</head>

<body class="print_1_col">
    <div class="note_print_before no-print">
        Chú ý: In với khổ giấy A5 theo chiều ngang (Hóa đơn sẽ có kích thước khổ giấy A6)
        <p>
            <button class="in_a5">In A5 - Khổ ngang</button>
            <button class="in_a8">Khổ 80mm</button>
            <button class="in_a6 selected">In A6 - Khổ dọc</button>
            <label class="free_height"><input type="checkbox" name="free_height" value="0" id="free_height" /> Không giới hạn chiều cao</label>
        </p>
        <p>
            <button onclick="print()" style=" background: #001fff; font-size: 20px;">IN NGAY</button>
        </p>
    </div>
    <div class="style_js">
        <style>
            @page {
                size: A6 portrait;
                margin: 0;
            }
        </style>
    </div>

    <div class="invoice_a4">
        <?php
        include('invoice-print-helper.php');
        function devvn_order_print_formatted_address_replacements($address)
        {
            unset($address['first_name']);
            unset($address['last_name']);
            return $address;
        }
        foreach ($order_args as $order) :
            $item_count = $order->get_item_count();
            $line_item_count = count($order->get_items());
            $line_item_rows_limit = 6;
            $order_id = $order->get_id();
            $is_wholesale_order = check_wholesale_order($order_id);
            $is_dropship_order = check_dropship_order($order_id);
            $is_express_delivery = is_express_delivery_order($order_id);
            $is_ivar_order = check_ivar_order($order_id);
            $ivar_logo = '66621';
            $order_cod = get_order_cod($order_id);

            $ghtk_status = apply_filters('devvn_invoice_order_ghtk_full', get_post_meta($order_id, '_order_ghtk_full', true));
            $ghtk_order = apply_filters('devvn_invoice_order_ghtk_fullinfor', get_post_meta($order_id, '_order_ghtk_fullinfor', true));
            $ghtk_id = isset($ghtk_status['order']['label_id']) ? $ghtk_status['order']['label_id'] : '';
            if (!$ghtk_id) $ghtk_id = isset($ghtk_status['order']['label']) ? $ghtk_status['order']['label'] : '';
            $ghtk_id_num = preg_split("/[.]/", $ghtk_id);
            if ($ghtk_id_num && is_array($ghtk_id_num)) {
                $ghtk_id_num = end($ghtk_id_num);
            } else {
                $ghtk_id_num = '';
            }
        ?>
            <div class="page">
                <div class="invoice_wrap">
                    <div class="invoice_row">
                        <div class="invoice_header" contenteditable="true">

                            <?php if ($is_ivar_order === true) { ?>
                                <div class="invoice_logo">
                                    <?php echo wp_get_attachment_image($ivar_logo, 'full'); ?>
                                    <!--div class="no-print">Logo cho đơn IVAR. Thay logo tại Template invoice-print.php -> $ivar_logo (Thông báo này sẽ không hiển thị khi in)</div-->
                                </div>
                            <?php } ?>

                            <?php if ($is_dropship_order !== true && $is_ivar_order !== true) { ?>
                                <div class="invoice_logo">
                                    <?php if ($logo = $this_class->get_options('print_logo')) : ?>
                                        <?php echo wp_get_attachment_image($logo, 'full'); ?>
                                    <?php else : ?>
                                        <div class="no-print">Thay logo tại WP Admin -> Woocommerce -> Cài đặt GHTK -> Cài đặt in
                                            hóa đơn -> Logo. (Thông báo này sẽ không hiển thị khi in)</div>
                                    <?php endif; ?>
                                </div>
                            <?php } ?>

                            <div class="invoice_infor">
                                <div id="barcode_mvd_<?php echo $order_id; ?>" contenteditable="true"></div>
                                <div class="invoice_infor_codes">
                                    <?php if ($is_express_delivery == true || $ghtk_id_num == '') { ?>
                                        <div><span class="big" contenteditable="true">Phiếu xuất hàng</span></div></br>


                                        <div>
                                            <!--label for="shipping-method">Chọn phương thức giao hàng:</label-->
                                            <select id="shipping-method" name="shipping-method" style="padding: 5px; border-radius: 5px; border: 0;">
                                                <option value="giao-nhanh">Giao nhanh</option>
                                                <option value="ntlog">Nhất Tín Logistics</option>
                                                <option value="247express">247Express</option>
                                            </select>
                                        </div>
                                        <br>

                                        <!--div><input type="radio" id="giao-nhanh" name="giao-nhanh" value="giao-nhanh">
                                            <label for="giao-nhanh"> Giao nhanh</label>
                                            <input type="radio" id="ntlog" name="ntlog" value="ntlog">
                                            <label for="ntlog"> Nhất Tín Logistics</label>
                                        </div></br-->

                                    <?php } else { ?>
                                        <div>Mã vận đơn: <span class="big" contenteditable="true"><?php echo $ghtk_id; ?> </span>
                                        </div>
                                    <?php } ?>
                                    <div>Mã đơn hàng: <span class="big">#<?php echo $order_id; ?>**</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="invoice_row customer_address">
                        <div class="invoice_body">
                            <div class="invoice_body_left">Đến</div>
                            <div class="invoice_body_right">
                                Tên: <?php echo $order->get_formatted_shipping_full_name(); ?><br>
                                <?php
                                $shipping_phone = get_post_meta($order_id, '_shipping_phone', true);
                                $shipping_phone = ($shipping_phone) ? $shipping_phone : $order->get_billing_phone();
                                ?>
                                <!--span>ĐT: <?php echo vn_checkout()->phone_hide($shipping_phone); ?></span-->
                                <span>ĐC:
                                    <?php
                                    add_filter('woocommerce_order_formatted_shipping_address', 'devvn_order_print_formatted_address_replacements', 10);
                                    add_filter('woocommerce_order_formatted_billing_address', 'devvn_order_print_formatted_address_replacements', 10);
                                    if (!wc_ship_to_billing_address_only() && $order->needs_shipping_address()) :
                                        $address = $order->get_formatted_shipping_address();
                                    else :
                                        $address = $order->get_formatted_billing_address();
                                    endif;
                                    if ($address) {
                                        $address = preg_replace('#<br\s*/?>#i', ', ', $address);
                                        echo esc_html(vn_checkout()->string_hide($address)) . '<br>';
                                    }
                                    remove_filter(
                                        'woocommerce_order_formatted_billing_address',
                                        'devvn_order_print_formatted_address_replacements',
                                        10
                                    );
                                    remove_filter(
                                        'woocommerce_order_formatted_shipping_address',
                                        'devvn_order_print_formatted_address_replacements',
                                        10
                                    );
                                    ?>
                                    <?php do_action('devvn_invoice_after_customer_address', $order); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="invoice_row product_row">

                        <div class="invoice_sanpham" contenteditable="true">
                            <strong style="display: block; margin-bottom: 5px;">Sản phẩm - Tổng SL sản phẩm: <span class="big"><?php echo $item_count; ?></span></strong>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tên sp</th>
                                        <th>SL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $all_prods = vn_checkout()->get_product_args($order);
                                    if ($all_prods && !is_wp_error($all_prods) && !empty($all_prods)) :
                                        foreach ($all_prods as $product) :
                                    ?>
                                            <tr>
                                                <td><?php echo $product['name'] ?></td>
                                                <td class="big bold"><?php echo $product['quantity'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                    <div class="invoice_row note_row">
                        <div class="invoice_sanpham big2" contenteditable="true">
                            <strong>Ghi chú: </strong> <?php echo isset($ghtk_order['order']['note']) ? sanitize_textarea_field($ghtk_order['order']['note']) : ''; ?>
                        </div>
                    </div>
                    <div class="invoice_row">
                        <div class="invoice_total">
                            <div class="invoice_total_left">

                                <?php if ($is_wholesale_order !== true && $is_express_delivery !== true && $ghtk_id_num !== '') { ?>

                                    <strong>Tiền đơn hàng:</strong>
                                    <?php
                                    $is_freeship = isset($ghtk_order['order']['is_freeship']) ? intval($ghtk_order['order']['is_freeship']) : 0;
                                    ?>
                                    <span class="total_price" contenteditable="true"><?php echo ($is_freeship) ? wc_price(intval($ghtk_order['order']['pick_money'])) : wc_price(intval($ghtk_order['order']['pick_money']) + intval($ghtk_status['order']['fee']) + intval($ghtk_status['order']['insurance_fee'])); ?></span>
                                <?php } ?>

                                <?php if ($is_wholesale_order == true) { ?>
                                    <strong>ĐẠI LÝ</strong>
                                <?php } ?>

                                <?php if ($is_express_delivery === true || $ghtk_id_num === '') {
                                    if ($is_wholesale_order !== true) {
                                ?>

                                        <strong>Tiền đơn hàng:</strong>
                                        <span class="total_price" contenteditable="true"><?php echo wc_price(intval(get_order_cod($order_id))); ?></span>
                                <?php }
                                } ?>
                                <?php if ($is_dropship_order !== true && $is_ivar_order !== true) { ?>
                                    <?php if ($note_print = $this_class->get_options('print_note')) : ?>
                                        <p class="invoice_total_note big2">
                                            <?php echo $note_print; ?>
                                        </p>
                                    <?php endif; ?>
                                <?php } ?>

                                <?php if ($is_dropship_order == true || $is_ivar_order == true) { ?>
                                    <p class="invoice_total_note big2">Vui lòng quay lại video khi mở gói hàng và sản phẩm để
                                        được hỗ trợ khi có sự cố.</p>
                                <?php } ?>

                            </div>
                            <!--div class="invoice_total_right">
                            <strong>Chữ ký người nhận</strong>
                            <span>(Kí và ghi rõ họ tên)</span>
                        </div-->
                        </div>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $("#barcode_mvd_<?php echo $order_id; ?>").barcode(
                        "<?php echo $ghtk_id_num; ?>",
                        "code128", {
                            barWidth: 2,
                            barHeight: 50,
                            moduleSize: 5,
                            fontSize: 14,
                        }
                    );
                    $("#barcode_mdh_<?php echo $order_id; ?>").barcode(
                        "<?php echo $order_id; ?>",
                        "code128",
                    );
                })
            </script>
        <?php endforeach; ?>
    </div>
</body>

</html><?php
function check_wholesale_order($order_id) {
    // Get the order
    $order = wc_get_order($order_id);
    if (!$order) {
        return false; // Order not found
    }

    // Get the user ID from the order
    $user_id = $order->get_user_id();
    if (!$user_id) {
        return false; // No user associated with the order
    }

    // Get user by ID
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false; // User not found
    }

    // Check if user roles contain 'wholesale' or 'dai-ly'
    foreach ($user->roles as $role) {
        if (strpos($role, 'wholesale') !== false ||
        strpos($role, 'dai-ly') !== false) {
            return true; // Role contains 'wholesale' or 'dai-ly'
        }
    }

    return false; // No 'wholesale' role found
}

function check_dropship_order($order_id) {
    // Get the order object
    $order = wc_get_order($order_id);
    if (!$order) {
        return false; // Order not found
    }

    // Assuming 'handling_notes' is the meta key for handling notes
    $handling_notes = $order->get_meta('handling_notes');

    // Check if 'handling_notes' contain 'dropship'
    if (strpos($handling_notes, 'dropship') !== false) {
        return true; // Contains 'dropship'
    }

    return false; // Does not contain 'dropship'
}

function check_ivar_order($order_id) {
    // Get the order object
    $order = wc_get_order($order_id);
    if (!$order) {
        return false; // Order not found
    }

    // Assuming 'handling_notes' is the meta key for handling notes
    $handling_notes = $order->get_meta('handling_notes');

    // Check if 'handling_notes' contain 'ivar'
    if (strpos($handling_notes, 'ivar') !== false) {
        return true; // Contains 'ivar'
    }

    // Get the customer ID from the order
    $customer_id = $order->get_customer_id();
    if (!$customer_id) {
        return false; // Customer ID not found
    }

    // Get the user object
    $user = get_userdata($customer_id);
    if (!$user) {
        return false; // User not found
    }

    // Get the user roles
    $user_roles = $user->roles;

    // Check if any role contains 'ivar'
    foreach ($user_roles as $role) {
        if (strpos($role, 'ivar') !== false) {
            return true; // Role contains 'ivar'
        }
    }

    return false; // Does not contain 'ivar'
}

function is_express_delivery_order($order_id) {
    $order = wc_get_order($order_id);
    $order_status = $order->get_status();
    if ($order_status == 'local-shipping') {
        return true; // Local shipping
    }

    return false;
}

function get_order_cod($order_id) {
    $order = wc_get_order($order_id);
    $payment_method = $order->get_payment_method();
    $paid_date = $order->get_meta('paid_date');
    $bank_account_received = $order->get_meta('bank_account_received');

    if ($payment_method == 'cod') {
        // If payment method is Cash on Delivery. Return order total
        return $order->get_total();
    } elseif ($payment_method == 'bacs' && !empty($paid_date) && !empty($bank_account_received)) {
        // If payment method is BACS and both paid_date and bank_account_received are filled
        return 0;
    } else {
        return 'Không phải đơn giao nhanh hoặc chưa đăng GHTK';
    }

}
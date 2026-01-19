<?php
// Filter to add no-link class for shop_order post type
add_filter('post_class', function ($classes) {
    if (is_admin()) {
        $current_screen = get_current_screen();
        if ($current_screen->base === 'edit' && $current_screen->post_type === 'shop_order') {
            $classes[] = 'no-link';
        }
    }
    return $classes;
});

// Set IP for order
add_filter('request', 'set_ip_for_customer');
function set_ip_for_customer($vars)
{
    global $typenow;
    if ('shop_order' === $typenow && isset($_GET['_shop_order_ip'])) {
        $vars['meta_key'] = '_customer_ip_address';
        $vars['meta_value'] = wc_clean($_GET['_shop_order_ip']);
    }
    return $vars;
}

// Add custom actions to WooCommerce admin order actions
add_filter('woocommerce_admin_order_actions', 'add_custom_actions', 100, 2);
function add_custom_actions($actions, $order)
{
    $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    $cus_id = $order->get_customer_id();
    $bill_phone = $order->get_billing_phone();
    $ship_phone = $order->get_shipping_phone();
    $order_total = $order->get_total();
    //$compensate_status = $order->get_meta('compensate_status');
    //$refund_status = $order->get_meta('refund_status');

    $bank_account = '113600098383';
    $bank_code = 'VietinBank';
    $qr_url = "https://qr.sepay.vn/img?bank={$bank_code}&acc={$bank_account}&amount={$order_total}&des=P0{$order_id}&template=compact";

    // Add QR code action
    $actions['qr-code'] = [
        'url' => $qr_url,
        'name' => __('QR', 'woocommerce'),
        'action' => 'qr-code',
        'target' => '_blank'
    ];

    // Add GHTK action
    $actions['in-van-don-ghtk'] = [
        'url' => admin_url("admin-ajax.php?action=inhoadon_ghtk&order_id=$order_id"),
        'name' => __('In phiếu xuất kho / vận đơn theo mẫu riêng, GHTK', 'woocommerce'),
        'action' => 'in-van-don-ghtk'
    ];

    // Add photos action
    $actions['anh-don-hang'] = [
        'url' => 'https://hithean.com/tien-ich/#warehouse-tasks',
        'name' => __('Ảnh lấy hàng, đơn hàng', 'woocommerce'),
        'action' => 'anh-don-hang',
        'target' => '_blank'
    ];

    if (wp_is_mobile()) {
        // Add mobile-specific actions
        $actions['goi-sdt-dat-hang'] = [
            'url' => "tel:$bill_phone",
            'name' => __('Gọi số người đặt ' . $bill_phone, 'woocommerce'),
            'action' => 'goi-sdt-dat-hang'
        ];
        $actions['goi-sdt-nhan-hang'] = [
            'url' => "tel:$ship_phone",
            'name' => __('Gọi số người nhận ' . $ship_phone, 'woocommerce'),
            'action' => 'goi-sdt-nhan-hang'
        ];
    }

    if (!$compensate_status) {
        // Nhap yeu cau boi hoan
        $actions['don-boi-hoan'] = [
            'url' => 'https://ivarvietnam.sg.larksuite.com/share/base/form/shrlgwbWoZJPmNmVbedSg7uDxah',
            'name' => __('Nhập yêu cầu shipper đền bù', 'woocommerce'),
            'action' => 'don-boi-hoan',
            'target' => '_blank'
        ];
    }

    if ($compensate_status) {
        // Theo doi yeu cau boi hoan
        $actions['theo-doi-boi-hoan'] = [
            'url' => 'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgvghQ7MdT8Dn24B500a5pBg',
            'name' => __('Theo dõi yêu cầu đền bù với shipper', 'woocommerce'),
            'action' => 'theo-doi-boi-hoan',
            'target' => '_blank'
        ];
    }

    if (!$refund_status) {
        // Nhap yeu cau hoan tien: https://applink.larksuite.com/T8T1KEvmpDQw
        $actions['don-hoan-tien'] = [
            'url' => 'https://applink.larksuite.com/T8T1KEvmpDQw',
            'name' => __('Nhập yêu cầu hoàn tiền cho khách', 'woocommerce'),
            'action' => 'don-hoan-tien',
            'target' => '_blank'
        ];
    }

    if ($refund_status) {
        // Theo doi yeu cau hoan tien:
        $actions['theo-doi-hoan-tien'] = [
            'url' => 'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgfNPS0U52nGaZClUNvARIpb',
            'name' => __('Theo dõi yêu cầu hoàn tiền cho khách', 'woocommerce'),
            'action' => 'theo-doi-hoan-tien',
            'target' => '_blank'
        ];
    }

    // Add GHTK action: Tra van don
    $actions['tra-don-ghtk'] = [
        'url' => 'https://khachhang.giaohangtietkiem.vn/web/don-hang',
        'name' => __('Tra cứu vận đơn tại GHTK', 'woocommerce'),
        'action' => 'tra-don-ghtk'
    ];

    // Add Viettel Post action: Tra van don
    $actions['tra-don-vtp'] = [
        'url' => 'https://viettelpost.vn/quan-ly-van-don',
        'name' => __('Tra cứu vận đơn tại Viettel Post', 'woocommerce'),
        'action' => 'tra-don-vtp'
    ];

    return $actions;
}

// Add custom styles for order action buttons
add_action('admin_head', 'add_custom_order_actions_button_css');
function add_custom_order_actions_button_css()
{
    $styles = [
        'in-van-don-ghtk' => 'background: green !important; color: #fff !important; content: "\f193 Phiếu GHTK"',
        // 'in-van-don-ntlog' => 'background: #FCD804; content: url(https://theanorganics.com/wp-content/uploads/2024/03/nhattin-logo-30x30-1.png);',
        'anh-don-hang' => 'content: "Ảnh lấy hàng"',
        'qr-code' => 'content: "QR thanh toán"',
        'don-boi-hoan' => 'content: "YC ship đền bù"',
        'don-hoan-tien' => 'content: "YC hoàn tiền KH"',
        'theo-doi-boi-hoan' => 'content: "Theo dõi bồi hoàn"',
        'theo-doi-hoan-tien' => 'content: "Theo dõi hoàn tiền KH"',
        'tra-don-ghtk' => 'background: green !important; opacity: 0.8; color:  #fff !important; content: "Tra đơn GHTK"',
        'tra-don-vtp' => 'background: red !important; opacity: 0.8; color:  #fff !important; content: "Tra đơn Viettel"'
    ];

    if (wp_is_mobile()) {
        $styles['goi-sdt-dat-hang'] = 'content: "\f525 Gọi số đặt";';
        $styles['goi-sdt-nhan-hang'] = 'content: "\f470 Gọi số nhận";';
    }

    foreach ($styles as $action => $style) {
        echo "<style>.wc-action-button-$action::after { font-size: 15px; font-weight: 500 !important; $style }</style>";
    }

    // Add customizable button width via CSS variable
    echo '<style>.wc-action-button::after { border-radius: 5px; background: cornsilk; C6E1C6; color: black;}</style>';
}

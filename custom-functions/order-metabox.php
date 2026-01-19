<?php
/**
 * Cấu hình Meta Box cho WooCommerce Order
 * Sử dụng plugin Meta Box để thay thế form thủ công
 */

add_filter('rwmb_meta_boxes', 'thean_register_order_meta_boxes');

function thean_register_order_meta_boxes($meta_boxes)
{
    // Định nghĩa các options (đưa ra ngoài để dễ quản lý hoặc dùng chung nếu cần)
    $handling_status_options = [
        'Đã in phiếu xuất hàng'      => 'Đã in phiếu xuất hàng',
        'Chờ in vận đơn'             => 'Chờ in vận đơn',
        'Đã in vận đơn, chờ xử lý'   => 'Đã in vận đơn, chờ xử lý',
        'Đã nhập kho vận chuyển'     => 'Đã nhập kho vận chuyển',
        'Cần chỉnh đơn'              => 'Cần chỉnh đơn',
        'Giao nhanh 1 giờ'           => 'Giao nhanh 1 giờ',
        'Giao nhanh 2 giờ'           => 'Giao nhanh 2 giờ',
        'Giao nhanh 4 giờ'           => 'Giao nhanh 4 giờ',
        'Thiếu SP'                   => 'Thiếu SP',
    ];

    $bank_options = [
        'Vietinbank 113600098383' => 'Vietinbank 113600098383',
        'ACB 11090087'            => 'ACB 11090087',
        'Momo 0766333454'         => 'Momo 0766333454',
        'ACB 212658699'           => 'ACB 212658699',
    ];

    $shipper_options = [
        // 'Giao Hang Tiet Kiem' => 'Giao Hang Tiet Kiem',
        // 'Ahamove'             => 'Ahamove',
        // 'Viettel Post'        => 'Viettel Post',
		'self'				  => 'THEAN',
		'ghtk' => 'Giao Hang Tiet Kiem',
		'ahamove' => 'Ahamove',
		'viettel' => 'Viettel Post',
    ];

    $export_users = [
        'ctv3kiennguyen' => 'ctv3kiennguyen',
        '6ducha'         => '6ducha',
        '1ngocbui'       => '1ngocbui',
        '2tuanha'        => '2tuanha',
    ];

    // Đăng ký Meta Box
    $meta_boxes[] = [
        'title'      => 'Thông tin bổ sung (Xử lý đơn hàng)',
        'post_types' => ['shop_order', 'woocommerce_page_wc-orders'], // Hỗ trợ cả Legacy và HPOS (High Performance Order Storage)
        'context'    => 'side', // Hiển thị ở cột bên phải (giống vị trí cũ của bạn)
        'priority'   => 'high',
        'fields'     => [
            // 1. Handling Status
            [
                'name'     => 'XỬ LÝ. Trạng thái',
                'id'       => 'order_handling_status', // ID trùng với meta_key cũ
                'type'     => 'select_advanced', // Dùng select_advanced đẹp hơn và có tìm kiếm
                'options'  => $handling_status_options,
                'multiple' => true,
                'placeholder' => 'Chọn trạng thái',
            ],
            // 2. Paid Date
            [
                'name' => 'CK. Ngày nhận',
                'id'   => 'order_paid_date',
                'type' => 'date',
            ],
            // 3. Bank Account Received
            [
                'name'    => 'CK. Tài khoản nhận',
                'id'      => 'order_bank_account_received',
                'type'    => 'select',
                'options' => $bank_options,
                'placeholder' => 'Chọn tài khoản',
            ],
            // 4. Ship Date
            [
                'name' => 'Ngày giao hàng',
                'id'   => 'order_ship_date',
                'type' => 'date',
            ],
            // 5. Shipper
            [
                'name'     => 'Đối tác giao hàng',
                'id'       => 'order_shipper',
                'type'     => 'select_advanced',
                'options'  => $shipper_options,
                'multiple' => true,
                'placeholder' => 'Chọn đối tác',
            ],
            // 6. Ship Code
            [
                'name'        => 'Mã giao vận',
                'id'          => 'order_ship_code',
                'type'        => 'text',
                'placeholder' => 'Nhập mã giao vận',
            ],
            // 7. Export By
            [
                'name'     => 'Xuất kho bởi',
                'id'       => 'order_export_by',
                'type'     => 'select_advanced',
                'options'  => $export_users,
                'multiple' => true,
                'placeholder' => 'Chọn nhân viên',
            ],
			[
                'id' => $prefix . 'warehouse_export_images',
                'name' => esc_html__('Ảnh xuất kho (URLs)'),
                'type' => 'textarea',
                'desc' => esc_html__('Mỗi dòng là một URL ảnh đã upload', 'order-metabox'),
            ],

            [
                'id' => $prefix . 'export_confirmed_by',
                'name' => esc_html__('GIAO HÀNG. Xác nhận bởi'),
                'type' => 'user',
                'field_type' => 'select_advanced',
                'role__in' => ['shop_manager', 'administrator'],
                'attributes' => [
                    'disabled' => true, // không cho chỉnh sửa bằng tay
                ],
                'desc' => esc_html__('Tự động lưu người xác nhận là admin hoặc quản lý cửa hàng.'),
            ],

        ],
    ];

    return $meta_boxes;
}

/**
 * Phần tích hợp Admin Columns Pro (ACP)
 * Giữ nguyên logic cũ của bạn vì data lưu trong DB không đổi
 */

add_filter('acp/storage_model/meta', function ($meta_keys, $post_type) {
    if ($post_type === 'shop_order' || $post_type === 'woocommerce_page_wc-orders') {
        $defined_keys = [
            'order_handling_status',
            'order_paid_date',
            'order_bank_account_received',
            'order_ship_date',
            'order_shipper',
            'order_ship_code',
            'order_export_by'
        ];
        // Merge mảng để code gọn hơn
        $meta_keys = array_merge($meta_keys, $defined_keys);
    }
    return $meta_keys;
}, 10, 2);

add_filter('acp/column/value', function ($value, $id, $column) {
    // Các field dạng mảng (multiple select) cần xử lý hiển thị
    $multi_fields = ['order_handling_status', 'order_export_by', 'order_shipper'];
    
    // Lấy tên cột đang render (format của ACP thường là column_meta_KEYNAME)
    $column_name = $column->get_name();

    foreach ($multi_fields as $key) {
        if ($column_name === 'column_meta_' . $key) {
            $order = wc_get_order($id);
            if (!$order) return $value;

            // Lấy meta data. Meta Box lưu multiple dưới dạng mảng serialize, WP tự unserialize khi get
            $meta = $order->get_meta($key, true);

            if (is_array($meta) && !empty($meta)) {
                return implode(', ', array_map('esc_html', $meta));
            } elseif (!is_array($meta) && !empty($meta)) {
                return esc_html($meta);
            }
        }
    }

    return $value;
}, 10, 3);
<?php
if (!defined('ABSPATH')) exit;

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

    // TK nhận: WooCommerce BACS + eim (bank-accounts.php). Giữ TK đơn đang mở đã
    // lưu để bấm Lưu đơn cũ không xoá mất TK đã bỏ khỏi cấu hình.
    $current_bank = [];
    $editing_id   = absint($_GET['id'] ?? $_GET['post'] ?? 0);
    if ($editing_id && is_admin() && did_action('woocommerce_init') && ($editing = wc_get_order($editing_id))) {
        $current_bank[] = (string) $editing->get_meta('order_bank_account_received');
    }
    $bank_list    = hithean_payment_bank_accounts($current_bank);
    $bank_options = array_combine($bank_list, $bank_list);

    $shipper_options = [
        // 'Giao Hang Tiet Kiem' => 'Giao Hang Tiet Kiem',
        // 'Ahamove'             => 'Ahamove',
        // 'Viettel Post'        => 'Viettel Post',
		'self'				  => 'THEAN',
		'ghtk' => 'Giao Hang Tiet Kiem',
		'ahamove' => 'Ahamove',
		'viettel' => 'Viettel Post',
    ];

    // Mọi user quản lý đơn (cùng quyền upload ảnh xuất kho) → người upload tự điền
    // vào "Xuất kho bởi" luôn có trong options. Giữ các tài khoản cũ để giá trị đã lưu không mất.
    $export_users = [
        'ctv3kiennguyen' => 'ctv3kiennguyen',
        '6ducha'         => '6ducha',
        '1ngocbui'       => '1ngocbui',
        '2tuanha'        => '2tuanha',
    ];
    foreach (get_users(['capability' => 'manage_woocommerce', 'fields' => ['user_login', 'display_name'], 'orderby' => 'login']) as $user) {
        $export_users[$user->user_login] = $user->display_name && $user->display_name !== $user->user_login
            ? $user->display_name . ' (' . $user->user_login . ')'
            : $user->user_login;
    }

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
                'id' => 'warehouse_export_images',
                'name' => esc_html__('Ảnh xuất kho (URLs)'),
                'type' => 'textarea',
                'desc' => esc_html__('Mỗi dòng là một URL ảnh đã upload', 'order-metabox'),
            ],

            [
                'id' => 'export_confirmed_by',
                'name' => esc_html__('GIAO HÀNG. Xác nhận bởi'),
                'type' => 'user',
                'field_type' => 'select_advanced',
                'role__in' => ['shop_manager', 'administrator'],
                'attributes' => [
                    'disabled' => true, // không cho chỉnh sửa bằng tay
                ],
                'desc' => esc_html__('Tự động lưu người xác nhận là admin hoặc quản lý cửa hàng.'),
            ],

            /* HOÀN HÀNG — dùng bởi shortcode [order_return_management] */
            [
                'id'          => 'return_status',
                'name'        => esc_html__('HOÀN HÀNG. Trạng thái', 'order-metabox'),
                'type'        => 'select_advanced',
                'placeholder' => esc_html__('Nhập trạng thái hoàn hàng tại đây', 'order-metabox'),
                'options'     => [
                    // Thường dùng lên trước
                    'Cần đổi trả'                     => 'Cần đổi trả',
                    'Cần thu hồi'                     => 'Cần thu hồi',
                    'Cần giao lại'                    => 'Cần giao lại',
                    'Chờ hoàn (Hủy)'                  => 'Chờ hoàn (Hủy)',
                    'Chờ hoàn (Không giao được)'      => 'Chờ hoàn (Không giao được)',
                    'Chờ hoàn (Đổi trả)'              => 'Chờ hoàn (Đổi trả)',
                    'Chờ hoàn (Thu hồi)'              => 'Chờ hoàn (Thu hồi)',
                    'Chờ hoàn (Giao 1 phần)'          => 'Chờ hoàn (Giao 1 phần)',
                    'Đã nhận hàng hoàn (Không sự cố)' => 'Đã nhận hàng hoàn (Không sự cố)',
                    'Đã nhận hàng hoàn (Có sự cố)'    => 'Đã nhận hàng hoàn (Có sự cố)',
                    'Hủy yêu cầu'                     => 'Hủy yêu cầu',
                ],
            ],
            [
                'id'          => 'return_code',
                'name'        => esc_html__('HOÀN HÀNG. Log mã giao vận', 'order-metabox'),
                'type'        => 'text',
                'placeholder' => esc_html__('Nhập mã hoàn hàng', 'order-metabox'),
            ],
            [
                'id'   => 'issue_report_images',
                'name' => esc_html__('HOÀN HÀNG. Ảnh sự cố phát sinh (URLs)', 'order-metabox'),
                'type' => 'textarea',
                'desc' => esc_html__('Mỗi dòng là một URL ảnh (tự thêm khi gắn đơn phát sinh trả hàng)', 'order-metabox'),
            ],
            [
                'id'   => 'issue_result_images',
                'name' => esc_html__('HOÀN HÀNG. Ảnh trả hàng (URLs)', 'order-metabox'),
                'type' => 'textarea',
                'desc' => esc_html__('Mỗi dòng là một URL ảnh (tự thêm khi xác nhận hàng hoàn)', 'order-metabox'),
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
            'order_export_by',
            'return_status',
            'return_code',
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

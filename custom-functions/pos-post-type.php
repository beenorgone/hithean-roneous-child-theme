<?php

add_action('mb_relationships_init', function () {
    MB_Relationships_API::register([
        'id'   => 'pointofsales_to_products',
        'from' => 'diem-ban',
        'to'   => 'product',
    ]);

    MB_Relationships_API::register([
        'id'   => 'posts_to_pages',
        'from' => [
            'object_type'  => 'post',
            'admin_column' => true,
        ],
        'to'   => [
            'object_type'  => 'post',
            'post_type'    => 'page',
            'admin_column' => 'after title',
        ],
    ]);
});

// Add custom post type for point of sales (POS)
function point_of_sale_post_type()
{
    $labels = [
        'name'                     => esc_html__('Điểm bán', 'hithean.com'),
        'singular_name'            => esc_html__('Điểm bán', 'hithean.com'),
        'add_new'                  => esc_html__('Thêm Mới', 'hithean.com'),
        'add_new_item'             => esc_html__('Thêm Điểm bán Mới', 'hithean.com'),
        'edit_item'                => esc_html__('Sửa Điểm bán', 'hithean.com'),
        'new_item'                 => esc_html__('Điểm bán Mới', 'hithean.com'),
        'view_item'                => esc_html__('Xem Điểm bán', 'hithean.com'),
        'view_items'               => esc_html__('Xem Các Điểm bán', 'hithean.com'),
        'search_items'             => esc_html__('Tìm Điểm bán', 'hithean.com'),
        'not_found'                => esc_html__('Không Tìm Thấy Điểm bán Nào.', 'hithean.com'),
        'not_found_in_trash'       => esc_html__('Không Tìm Thấy Điểm bán Nào Trong Thùng Rác.', 'hithean.com'),
        'all_items'                => esc_html__('Tất Cả Các Điểm bán', 'hithean.com'),
        'archives'                 => esc_html__('Lưu Trữ Điểm bán', 'hithean.com'),
        'attributes'               => esc_html__('Thuộc Tính Điểm bán', 'hithean.com'),
        'insert_into_item'         => esc_html__('Chèn Vào Điểm bán', 'hithean.com'),
        'uploaded_to_this_item'    => esc_html__('Được Tải Lên Điểm bán Này', 'hithean.com'),
        'featured_image'           => esc_html__('Ảnh Đại Diện', 'hithean.com'),
        'set_featured_image'       => esc_html__('Đặt Ảnh Đại Diện', 'hithean.com'),
        'remove_featured_image'    => esc_html__('Gỡ Ảnh Đại Diện', 'hithean.com'),
        'use_featured_image'       => esc_html__('Sử Dụng Làm Ảnh Đại Diện', 'hithean.com'),
        'menu_name'                => esc_html__('Điểm bán', 'hithean.com'),
        'filter_items_list'        => esc_html__('Lọc Danh Sách Điểm bán', 'hithean.com'),
        'items_list_navigation'    => esc_html__('Điều Hướng Danh Sách Điểm bán', 'hithean.com'),
        'items_list'               => esc_html__('Danh Sách Điểm bán', 'hithean.com'),
        'item_published'           => esc_html__('Điểm bán Đã Được Đăng.', 'hithean.com'),
        'item_published_privately' => esc_html__('Điểm bán Được Đăng Riêng Tư.', 'hithean.com'),
        'item_reverted_to_draft'   => esc_html__('Điểm bán Được Chuyển Về Bản Nháp.', 'hithean.com'),
        'item_scheduled'           => esc_html__('Điểm bán Đã Được Lên Lịch.', 'hithean.com'),
        'item_updated'             => esc_html__('Điểm bán Đã Được Cập Nhật.', 'hithean.com'),
        'text_domain'              => esc_html__('hithean.com', 'hithean.com'),
    ];

    $args = [
        'label'               => esc_html__('Điểm bán', 'hithean.com'),
        'labels'              => $labels,
        'public'              => true,
        'hierarchical'        => false,
        'exclude_from_search' => false,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_nav_menus'   => true,
        'show_in_admin_bar'   => true,
        'show_in_rest'        => true,
        'query_var'           => true,
        'can_export'          => true,
        'delete_with_user'    => false,
        'has_archive'         => true,
        'rest_base'           => '',
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-schedule',
        'capability_type'     => 'post',
        'supports'            => ['title', 'custom-fields', 'revisions', 'thumbnail', 'excerpt'],
        'rewrite'             => [
            'with_front' => false,
        ],
    ];

    register_post_type('diem-ban', $args);
}
add_action('init', 'point_of_sale_post_type');


// Define custom fields with Metabox for point_of_sale
add_filter('rwmb_meta_boxes', 'point_of_sale_meta_boxes');
function point_of_sale_meta_boxes($meta_boxes)
{
    $prefix = 'pos_';

    $meta_boxes[] = [
        'title'      => 'Cài đặt Điểm bán',
        'id'         => 'point_of_sale_metabox',
        'post_types' => 'diem-ban',
        'context'    => 'advanced',
        'priority'   => 'default',
        'autosave'   => true,
        'fields'     => [
            [
                'id'            => $prefix . 'wholesaler',
                'title'         => 'Đối tác',
                'type'          => 'user',
                'ajax'          => true,
                'query_args'    => [
                    'number'    => 10,
                ],
                'field_type'    => 'select_advanced',
            ],
            [
                'id'            => $prefix . 'featured',
                'name'          => 'Nổi bật',
                'type'          => 'checkbox',
                'std'           => 0, // 0 or 1

            ],
            [
                'id'            => $prefix . 'priority',
                'name'          => 'Priority',
                'type'          => 'number',
                'min'           => 0,
                'step'          => 1,
                'std'           => 0,
            ],
            [
                'id'    => $prefix . 'type',
                'name'      => 'Phân loại',
                'type'            => 'checkbox_list',
                'inline'          => true,
                'select_all_none' => true,
                'options' => [
                    'ecommerce' => 'Shop TMĐT',
                    'offline' => 'Offline',
                ],
            ],
            [
                'id'            => $prefix . 'ecommerce-link',
                'name'          => 'Đặt mua online',
                'visible'        => [$prefix . 'type', '!=', 'ecommerce'],
                'type'    => 'wysiwyg',
                'raw'     => false,
                'options' => [
                    'textarea_rows' => 6,
                    'teeny'         => true,
                ],

            ],
            // Address fields / Địa chỉ điểm bán
            // Address fields /  ^   ^ a ch  ^   ^ i  ^ m b  n
            [
                'id'            => $prefix . 'diachichitiet',
                'name'          => 'Địa chỉ',
                'type'          => 'text',
            ],
            [
                'id'            => $prefix . 'linkchiduong',
                'name'          => 'Link chỉ đường',
                'type'          => 'url',
            ],
            [
                'id'            => $prefix . 'quanhuyen',
                'name'          => 'Quận / Huyện',
                'visible'        => [$prefix . 'type', 'contains', 'offline'],
                'type'    => 'text',
                'datalist'    => [
                    'id'      => 'ds_quanhuyen',
                    'options' => [
                        'Quận Nam Từ Liêm',
                        'Quận Tây Hồ',
                        'Quận Hà Đông',
                        'Quận Hoàng Mai',
                        'Huyện Gia Lâm',
                        'Quận Thanh Xuân',
                        'Quận Bắc Từ Liêm',
                        'Quận Cầu Giấy',
                        'Quận Đống Đa',
						'Quận Long Biên',
						'Huyện Văn Giang'
                    ],
                ],
            ],
            [
                'id'            => $prefix . 'tinhthanhpho',
                'name'          => 'Tỉnh/TP',
                'visible'        => [$prefix . 'type', 'contains', 'offline'],
                'type'    => 'text',
                'datalist'    => [
                    'id'      => 'ds_tinhthanhpho',
                    'options' => [
                        'Hà Nội',
						'Hưng Yên',
                        'TP. Hồ Chí Minh',
                    ],
                ],
            ],
            [
                'id'            => $prefix . 'products',
                'name'          => 'Điểm bán của sản phẩm', // Điểm bán cua san pham
                'type'          => 'post',
                'post_type'     => 'product',
                'field_type'    => 'select_advanced',
                'multiple'      => true,
            ],
            [
                'id'                => $prefix . 'image',
                'name'              => 'Ảnh điểm bán', // Anh Điểm bán
                'type'              => 'image_advanced',
                'force_delete'      => false,
                'max_file_uploads'  => 5,
                'max_status'        => false,
                'image_size'        => 'thumbnail',
            ],
        ],
    ];

    return $meta_boxes;
}


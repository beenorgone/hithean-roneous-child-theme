<?php

// Add custom post type for product tabs
function product_tab_post_type()
{
    $labels = [
        'name'                     => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'singular_name'            => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'add_new'                  => esc_html__('Thêm Mới', 'hithean.com'),
        'add_new_item'             => esc_html__('Thêm Tab Sản Phẩm Mới', 'hithean.com'),
        'edit_item'                => esc_html__('Sửa Tab Sản Phẩm', 'hithean.com'),
        'new_item'                 => esc_html__('Tab Sản Phẩm Mới', 'hithean.com'),
        'view_item'                => esc_html__('Xem Tab Sản Phẩm', 'hithean.com'),
        'view_items'               => esc_html__('Xem Các Tab Sản Phẩm', 'hithean.com'),
        'search_items'             => esc_html__('Tìm Tab Sản Phẩm', 'hithean.com'),
        'not_found'                => esc_html__('Không Tìm Thấy Tab Sản Phẩm Nào.', 'hithean.com'),
        'not_found_in_trash'       => esc_html__('Không Tìm Thấy Tab Sản Phẩm Nào Trong Thùng Rác.', 'hithean.com'),
        'all_items'                => esc_html__('Tất Cả Các Tab Sản Phẩm', 'hithean.com'),
        'archives'                 => esc_html__('Lưu Trữ Tab Sản Phẩm', 'hithean.com'),
        'attributes'               => esc_html__('Thuộc Tính Tab Sản Phẩm', 'hithean.com'),
        'insert_into_item'         => esc_html__('Chèn Vào Tab Sản Phẩm', 'hithean.com'),
        'uploaded_to_this_item'    => esc_html__('Được Tải Lên Tab Sản Phẩm Này', 'hithean.com'),
        'featured_image'           => esc_html__('Ảnh Đại Diện', 'hithean.com'),
        'set_featured_image'       => esc_html__('Đặt Ảnh Đại Diện', 'hithean.com'),
        'remove_featured_image'    => esc_html__('Gỡ Ảnh Đại Diện', 'hithean.com'),
        'use_featured_image'       => esc_html__('Sử Dụng Làm Ảnh Đại Diện', 'hithean.com'),
        'menu_name'                => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'filter_items_list'        => esc_html__('Lọc Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'items_list_navigation'    => esc_html__('Điều Hướng Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'items_list'               => esc_html__('Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'item_published'           => esc_html__('Tab Sản Phẩm Đã Được Đăng.', 'hithean.com'),
        'item_published_privately' => esc_html__('Tab Sản Phẩm Được Đăng Riêng Tư.', 'hithean.com'),
        'item_reverted_to_draft'   => esc_html__('Tab Sản Phẩm Được Chuyển Về Bản Nháp.', 'hithean.com'),
        'item_scheduled'           => esc_html__('Tab Sản Phẩm Đã Được Lên Lịch.', 'hithean.com'),
        'item_updated'             => esc_html__('Tab Sản Phẩm Đã Được Cập Nhật.', 'hithean.com'),
        'text_domain'              => esc_html__('hithean.com', 'hithean.com'),
    ];

    $args = [
        'label'               => esc_html__('Tab Sản Phẩm', 'hithean.com'),
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
        'menu_icon'           => 'dashicons-welcome-add-page',
        'capability_type'     => 'post',
        'supports'            => ['title', 'editor', 'custom-fields', 'revisions'],
        'taxonomies'          => ['product_cat', 'product_tag'],
        'rewrite'             => [
            'with_front' => false,
        ],
    ];

    register_post_type('product-tab', $args);
}
add_action('init', 'product_tab_post_type');


// Define custom fields with Metabox for product_tab
add_filter('rwmb_meta_boxes', 'product_tab_meta_boxes');
function product_tab_meta_boxes($meta_boxes)
{
    $prefix = 'product_tab_';

    $meta_boxes[] = [
        'title'      => 'Cài đặt Tab',
        'id'         => 'product_tab_metabox',
        'post_types' => 'product-tab',
        'context'    => 'advanced',
        'priority'   => 'default',
        'autosave'   => true,
        'fields'     => [
            [
                'id'      => $prefix . 'global_tab',
                'name'    => 'Tab Toàn Cục',
                'type'    => 'checkbox',
            ],
            [
                'id'   => $prefix . 'priority',
                'name' => 'Độ Ưu Tiên',
                'type' => 'number',
                'std'  => 60,
            ],
            [
                'id'   => $prefix . 'products',
                'name' => 'Dùng Tab cho Sản Phẩm',
                'type' => 'post',
                'post_type' => 'product',
                'field_type' => 'select_advanced',
                'multiple' => true,
            ],
            [
                'id'   => $prefix . 'thuong_hieu',
                'name' => 'Dùng Tab cho Thương Hiệu',
                'type' => 'taxonomy_advanced',
                'taxonomy' => 'thuong-hieu', // Product Brands
                'multiple' => true, // Allow multiple
            ],
        ],
    ];

    return $meta_boxes;
}


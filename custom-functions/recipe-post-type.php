<?php

/*-------------------------------*\
  REGISTER RECIPE POST TYPE
\*-------------------------------*/

// Relationship with products

add_action('mb_relationships_init', function () {
    MB_Relationships_API::register([
        'id'   => 'recipe_posts_to_products',
        'from' => 'cong-thuc',
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

// Register function

add_action('init', 'recipe_post_type');
function recipe_post_type()
{
    $labels = [
        'name'                     => esc_html__('Công thức', 'hithean.com'),
        'singular_name'            => esc_html__('Công thức', 'hithean.com'),
        'add_new'                  => esc_html__('Tạo mới', 'hithean.com'),
        'add_new_item'             => esc_html__('Thêm một công thức', 'hithean.com'),
        'edit_item'                => esc_html__('Sửa công thức', 'hithean.com'),
        'new_item'                 => esc_html__('Công thức mới', 'hithean.com'),
        'view_item'                => esc_html__('Xem công thức', 'hithean.com'),
        'view_items'               => esc_html__('Xem công thức', 'hithean.com'),
        'search_items'             => esc_html__('Tìm công thức', 'hithean.com'),
        'not_found'                => esc_html__('Không tìm thấy công thức nào.', 'hithean.com'),
        'not_found_in_trash'       => esc_html__('Không tìm thấy công thức nào trong Thùng Rác.', 'hithean.com'),
        'parent_item_colon'        => esc_html__('Công thức cha:', 'hithean.com'),
        'all_items'                => esc_html__('Tất cả công thức', 'hithean.com'),
        'archives'                 => esc_html__('Lưu trữ công thức', 'hithean.com'),
        'attributes'               => esc_html__('Các thuộc tính công thức', 'hithean.com'),
        'insert_into_item'         => esc_html__('Thêm vào công thức', 'hithean.com'),
        'uploaded_to_this_item'    => esc_html__('Tải lên vào this công thức', 'hithean.com'),
        'featured_image'           => esc_html__('Ảnh đại diện', 'hithean.com'),
        'set_featured_image'       => esc_html__('Chọn ảnh đại diện', 'hithean.com'),
        'remove_featured_image'    => esc_html__('Xóa ảnh đại diện', 'hithean.com'),
        'use_featured_image'       => esc_html__('Sử dụng làm ảnh đại diện', 'hithean.com'),
        'menu_name'                => esc_html__('Công thức', 'hithean.com'),
        'filter_items_list'        => esc_html__('Lọc danh sách công thức', 'hithean.com'),
        'filter_by_date'           => esc_html__('', 'hithean.com'),
        'items_list_navigation'    => esc_html__('Điều hướng danh sách công thức', 'hithean.com'),
        'items_list'               => esc_html__('Danh sách công thức', 'hithean.com'),
        'item_published'           => esc_html__('Công thức đã xuất bản.', 'hithean.com'),
        'item_published_privately' => esc_html__('Công thức riêng tư đã xuất bản.', 'hithean.com'),
        'item_reverted_to_draft'   => esc_html__('Công thức đã chuyển thành bản nháp', 'hithean.com'),
        'item_scheduled'           => esc_html__('Công thức đã lên lịch đăng.', 'hithean.com'),
        'item_updated'             => esc_html__('Công thức đã cập nhật.', 'hithean.com'),
        'text_domain'              => esc_html__('hithean.com', 'hithean.com'),
    ];
    $args = [
        'label'               => esc_html__('Công thức', 'hithean.com'),
        'labels'              => $labels,
        'description'         => 'Công thức pha chế, hướng dẫn sử dụng sản phẩm',
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
        'delete_with_user'    => true,
        'has_archive'         => true,
        'rest_base'           => '',
        'show_in_menu'        => true,
        'menu_position'       => '',
        'menu_icon'           => 'dashicons-admin-links',
        'capability_type'     => 'post',
        'supports'            => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'comments', 'post-formats', 'page-attributes', 'custom-fields', 'author'],
        'taxonomies'          => [],
        'rewrite'             => [
            'with_front' => false,
        ],
    ];

    register_post_type('cong-thuc', $args);
}

// Define custom fields with metabox
add_filter('rwmb_meta_boxes', 'recipe_post_meta_boxes');
function recipe_post_meta_boxes($meta_boxes)
{
    $prefix = 'recipe_';

    $meta_boxes[] = [
        'title'      => 'Thông tin bổ sung',
        'id'       => 'recipe_content_metabox',
        'post_types' => 'cong-thuc',
        'context' => 'advanced',
        'autosave' => true,
        'fields'     => [
            [
                'id'   => $prefix . 'san_pham',
                'name' => 'Sản Phẩm Sử Dụng Trong Công Thức',
                'type' => 'post',
                'post_type' => 'product', // Set to 'product' to relate to WooCommerce products
                'multiple' => true, // Allow multiple product selections
            ],
        ],
    ];

    return $meta_boxes;
}

// Add Custom Taxonomy: Bo Suu Tap (Collection)
add_action('init', 'register_bo_suu_tap_taxonomy');
function register_bo_suu_tap_taxonomy()
{
    $labels = [
        'name'              => _x('Bộ Sưu Tập', 'taxonomy general name', 'hithean.com'),
        'singular_name'     => _x('Bộ Sưu Tập', 'taxonomy singular name', 'hithean.com'),
        'search_items'      => __('Tìm kiếm Bộ Sưu Tập', 'hithean.com'),
        'all_items'         => __('Tất cả Bộ Sưu Tập', 'hithean.com'),
        'parent_item'       => __('Bộ Sưu Tập cha', 'hithean.com'),
        'parent_item_colon' => __('Bộ Sưu Tập cha:', 'hithean.com'),
        'edit_item'         => __('Sửa Bộ Sưu Tập', 'hithean.com'),
        'update_item'       => __('Cập nhật Bộ Sưu Tập', 'hithean.com'),
        'add_new_item'      => __('Thêm Bộ Sưu Tập mới', 'hithean.com'),
        'new_item_name'     => __('Tên Bộ Sưu Tập mới', 'hithean.com'),
        'menu_name'         => __('Bộ Sưu Tập', 'hithean.com'),
    ];

    $args = [
        'hierarchical'      => true, // Set to true for category-like taxonomy
        'labels'            => $labels,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true, // Enable Gutenberg editor support
        'query_var'         => true,
        'rewrite'           => [
            'slug'       => 'bo-suu-tap', // Custom slug
            'with_front' => false,         // Prevents adding /blog/ to the slug
        ],
    ];

    register_taxonomy('bo-suu-tap', ['cong-thuc'], $args);
}


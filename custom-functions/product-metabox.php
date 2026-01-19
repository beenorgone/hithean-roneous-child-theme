<?php
// Product Additional Info
function hithean_product_additional_info_metabox($meta_boxes)
{
    $prefix = 'product_info_';

    $meta_boxes[] = [
        'title' => "Thông tin cơ bản",
        'id' => 'product_addtional_info_metabox',
        'post_types' => array('product'),
        'context' => 'advanced',
        'priority' => 'default',
        'autosave' => true,
        'fields' => [
            [
                'id' => $prefix . 'subheading',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Nhấn mạnh sản phẩm: Tagline, Subheading, USP ...', 'hithean-product-metabox'),
                'desc' => esc_html__('Hiển thị phía dưới tên sản phẩm để nêu bật điểm mạnh, điểm riêng biệt, USP', 'hithean-product-metabox'),
            ],
            // custom info use for tabs
            [
                'id' => $prefix . 'hdsd',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Hướng dẫn sử dụng', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Hướng Dẫn Sử Dụng', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'thanh_phan',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Thành phần và bảng giá trị dinh dưỡng', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Thành Phần', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'nhan_phu',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Nhãn phụ', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Nhãn Phụ', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'ho_so_phap_ly',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Hồ sơ pháp lý sản phẩm', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Hồ Sơ Sản Phẩm', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'faq',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Câu hỏi thường gặp', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Câu Hỏi Thường Gặp', 'hithean-product-metabox'),
            ],
        ],
    ];

    return $meta_boxes;
}

add_filter('rwmb_meta_boxes', 'hithean_product_additional_info_metabox');


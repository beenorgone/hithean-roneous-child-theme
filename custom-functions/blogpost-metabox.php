<?php
add_filter('rwmb_meta_boxes', function ($meta_boxes) {
    $meta_boxes[] = [
        'id'         => 'post_meta_box', // Unique ID for the meta box
        'title'      => 'Thông tin bổ sung',    // Meta box title
        'post_types' => ['post'],               // Post types where the meta box appears
        'context'    => 'normal',                 // Location in the editor: normal, side, or advanced
        'priority'   => 'default',              // Priority of the meta box: high, low, or default
        'fields'     => [
            [
                'id'       => 'post_san_pham',     // ID of the custom field
                'name'     => 'Chọn sản phẩm',    // Label for the input field
                'type'     => 'post',            // Field type to select posts
                'post_type' => 'product',        // Post type to populate the dropdown (e.g., WooCommerce products)
                'field_type' => 'select_advanced', // Advanced select dropdown with search functionality
                'multiple'  => true,            // Allow multiple selections
                'placeholder' => 'Chọn sản phẩm liên quan...', // Placeholder text
            ],
        ],
    ];
    return $meta_boxes;
});


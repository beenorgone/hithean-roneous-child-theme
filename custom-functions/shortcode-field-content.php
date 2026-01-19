<?php
// Thêm mã này vào tệp functions.php của giao diện hoặc plugin tùy chỉnh.
function display_custom_field_content_shortcode($atts) {
    // Các tham số mặc định
    $atts = shortcode_atts(
        array(
            'type'  => 'post', // Loại bài viết mặc định
            'field' => '',     // Tên trường cần hiển thị
            'id'    => null,   // ID bài viết (mặc định là bài viết hiện tại)
        ),
        $atts,
        'field_content'
    );

    // Kiểm tra xem trường có được cung cấp hay không
    if (empty($atts['field'])) {
        return '<p style="color:red;">Trường (field) không được chỉ định.</p>';
    }

    // Lấy ID bài viết nếu không được cung cấp
    $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

    // Kiểm tra ID bài viết có hợp lệ hay không
    if (!$post_id) {
        return '<p style="color:red;">ID bài viết không hợp lệ.</p>';
    }

    // Lấy giá trị của trường
    $field_value = get_post_meta($post_id, $atts['field'], true);

    // Kiểm tra xem trường có giá trị không
    if (empty($field_value)) {
        return '<p style="color:gray;">Không có dữ liệu cho trường: ' . esc_html($atts['field']) . '</p>';
    }

    // Trả về nội dung trường
    return esc_html($field_value);
}
add_shortcode('field_content', 'display_custom_field_content_shortcode');


<?php
function display_blog_posts_slider($atts)
{
    // Lấy các giá trị của shortcode
    $atts = shortcode_atts([
        'posts_per_page' => 5, // Số bài viết hiển thị
    ], $atts);

    // Query blog posts
    $args = [
        'post_type'      => 'post',
        'posts_per_page' => intval($atts['posts_per_page']),
    ];

    $query = new WP_Query($args);

    // Nếu không có bài viết nào được tìm thấy
    if (!$query->have_posts()) {
        return '<p>Không tìm thấy bài viết nào.</p>';
    }

    // Tạo nội dung slider
    $output = '<div class="swiper-container swiper-posts posts-slider" style="margin: 0 auto; padding: 20px 0; max-width: unset;" data-slides-per-view="4" data-autoplay="1" data-speed="5000" data-navigation="false" data-space-between="10" data-loop="true">';
    $output .= '<div class="swiper-wrapper posts-slider-list">';

    while ($query->have_posts()) {
        $query->the_post();

        // Tạo từng slide
        $output .= '<div class="swiper-slide post-item">';
        // $output .= '<div class="slider-item">';

        // Hiển thị hình ảnh đại diện
        if (has_post_thumbnail()) {
            $output .= '<div class="post-thumbnail">';
            $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            $output .= '</div>';
        }

        // Hien thi tieu de
        $output .= '<h4 class="post-title"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h4>';

        // Hien thi  excerpt
        $output .= '<div class="post-excerpt">' . get_the_excerpt() . '</div>';

        // Hien thi nut xem them
        $output .= '<div class="post-read-more"><a class="" href="' . get_permalink() . '">Đọc thêm</a></div>';

        $output .= '</div>'; // .swiper-slide
    }

    $output .= '</div>'; // .swiper-wrapper

    // Thêm navigation buttons
    $output .= '<div class="swiper-button-next"></div>';
    $output .= '<div class="swiper-button-prev"></div>';

    // Thêm pagination
    $output .= '<div class="swiper-pagination"></div>';

    $output .= '</div>'; // .blog-slider-container

    wp_reset_postdata();

    return $output;
}
add_shortcode('blog_posts_slider', 'display_blog_posts_slider');

<?php
function display_recipes_by_products($atts)
{
    // Lay cac gia tri cua shortcode
    $atts = shortcode_atts([
        'product-ids' => '',
    ], $atts);

    // Kiem tra neu khong co product-ids duoc truyen vao
    if (empty($atts['product-ids'])) {
        return '<p>Vui long cung cap ID san pham.</p>';
    }

    // Chuyen doi product-ids thanh mang cac ID
    $product_ids = array_map('intval', explode(',', $atts['product-ids']));

    // Neu khong co ID hop le
    if (empty($product_ids)) {
        return '<p>Khong co san pham hop le duoc cung cap.</p>';
    }

    // Query cac bai viet thuoc danh muc 'cong-thuc' va co san pham trong custom field 'post_san_pham'
    $args = [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'category_name'  => 'cong-thuc', // Chi cac bai viet thuoc danh muc 'cong-thuc'
        'meta_query'     => [
            [
                'key'     => 'post_san_pham',
                'value'   => $product_ids,
                'compare' => 'IN', // Kiem tra xem product-id co trong custom field khong
            ],
        ],
    ];

    $query = new WP_Query($args);

    // Neu khong co bai viet nao duoc tim thay
    if (!$query->have_posts()) {
        return '<p>Khong tim thay cong thuc nao cho cac san pham nay.</p>';
    }

    // Bat dau tao noi dung hien thi
    $output = '<div class="recipes-list">';
    while ($query->have_posts()) {
        $query->the_post();

        // Boc moi cong thuc trong the <div class="recipe-item">
        $output .= '<div class="recipe-item">';

        // Hien thi Featured Image hinh vuong (kich thuoc thumbnail)
        if (has_post_thumbnail()) {
            $output .= '<div class="recipe-thumbnail">';
            $output .= get_the_post_thumbnail(get_the_ID(), 'large'); // Su dung kich thuoc 'large'
            $output .= '</div>';
        }

        // Hien thi tieu de cong thuc va link toi trang chi tiet
        $output .= '<h4 class="recipe-title"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h4>';

        // Hien thi excerpt
        $output .= '<div class="recipe-excerpt">' . get_the_excerpt() . '</div>';

        // Them lien ket "Doc them" dan toi bai viet chi tiet
        $output .= '<div class="recipe-read-more"><a class="" href="' . get_permalink() . '">Xem cong thuc</a></div>';

        // Dong the <div class="recipe-item">
        $output .= '</div>';
    }
    $output .= '</div>';

    // Reset lai WP Query
    wp_reset_postdata();

    return $output;
}
add_shortcode('recipes', 'display_recipes_by_products');

/*----------------------*\
  RECIPES SLIDER
\*----------------------*/

function display_recipes_slider($atts)
{
    // Lay cac gia tri cua shortcode
    $atts = shortcode_atts([
        'product-ids' => '',
    ], $atts);

    // Kiem tra neu khong co product-ids duoc truyen vao
    if (empty($atts['product-ids'])) {
        return '<p>Vui long cung cap ID san pham.</p>';
    }

    // Chuyen doi product-ids thanh mang cac ID
    $product_ids = array_map('intval', explode(',', $atts['product-ids']));

    // Neu khong co ID hop le
    if (empty($product_ids)) {
        return '<p>Khong co san pham hop le duoc cung cap.</p>';
    }

    // Query cac bai viet thuoc danh muc 'cong-thuc' va co san pham trong custom field 'post_san_pham'
    $args = [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'category_name'  => 'cong-thuc', // Chi cac bai viet thuoc danh muc 'cong-thuc'
        'meta_query'     => [
            [
                'key'     => 'post_san_pham',
                'value'   => $product_ids,
                'compare' => 'IN', // Kiem tra xem product-id co trong custom field khong
            ],
        ],
    ];

    $query = new WP_Query($args);

    // Neu khong co bai viet nao duoc tim thay
    if (!$query->have_posts()) {
        return '<p>Khong tim thay cong thuc nao cho cac san pham nay.</p>';
    }

    // Tao noi dung slider
    $output = '<div class="swiper-container swiper-posts recipes-slider" style="margin: 0 auto; padding: 20px 0; max-width: unset;" data-slides-per-view="3" data-autoplay="1" data-speed="5000" data-navigation="false" data-space-between="10" data-loop="true" data-centeredSlides="true">';
    $output .= '<div class="swiper-wrapper recipes-slider-list">';

    while ($query->have_posts()) {
        $query->the_post();

        $output .= '<div class="swiper-slide recipe-item">';

        // Hien thi Featured Image
        if (has_post_thumbnail()) {
            $output .= '<div class="recipe-thumbnail">';
            $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            $output .= '</div>';
        }

        // Hien thi tieu de va link
        $output .= '<h4 class="recipe-title"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h4>';

        // Hien thi excerpt
        $output .= '<div class="recipe-excerpt">' . get_the_excerpt() . '</div>';

        // Hien thi nut xem them
        $output .= '<div class="recipe-read-more"><a class="" href="' . get_permalink() . '">Xem công thức</a></div>';

        $output .= '</div>'; // Dong swiper-slide
    }

    $output .= '</div>'; // Dong swiper-wrapper

    // Them nut dieu huong Swiper
    $output .= '<div class="swiper-button-next"></div>';
    $output .= '<div class="swiper-button-prev"></div>';
    $output .= '<div class="swiper-pagination"></div>';

    $output .= '</div>'; // Dong swiper-container

    // Reset lai WP Query
    wp_reset_postdata();

    return $output;
}
add_shortcode('recipes-slider', 'display_recipes_slider');


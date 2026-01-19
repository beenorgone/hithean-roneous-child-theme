<?php

/*---------------------------------------*\
  REMOVE RELATED PRODUCTS SECTION
\*---------------------------------------*/
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);


/* PRODUCT PAGE TABS BUILDING */

// Generate a slug from a string
function generate_slug($string)
{
    return sanitize_title($string);
}

add_filter('woocommerce_product_tabs', 'add_custom_product_tabs');

function add_custom_product_tabs($tabs)
{
    global $product;

    // Add global tabs
    $global_tabs = get_posts([
        'post_type'      => 'product-tab',
        'meta_key'       => 'product_tab_global_tab',
        'meta_value'     => 1,
        'posts_per_page' => -1,
    ]);

    foreach ($global_tabs as $global_tab) {
        $slug = generate_slug($global_tab->post_title);
        $tabs[$global_tab->ID] = [
            'id'       => 'tab-' . $slug,
            'title'    => $global_tab->post_title,
            'callback' => 'display_product_tab_content',
            'priority' => (int) get_post_meta($global_tab->ID, 'product_tab_priority', true),
            'content'  => $global_tab->post_content,
        ];
    }

    // Add tabs based on taxonomies
    $taxonomies = ['product_cat', 'product_tag'];
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_post_terms($product->get_id(), $taxonomy, ['fields' => 'ids']);

        if (!empty($terms)) {
            $assigned_tabs = new WP_Query([
                'post_type'      => 'product-tab',
                'tax_query'      => [
                    [
                        'taxonomy' => $taxonomy,
                        'field'    => 'term_id',
                        'terms'    => $terms,
                        'operator' => 'IN',
                        'include_children' => false, // Ensure exact match only
                    ],
                ],
                'posts_per_page' => -1,
            ]);

            if ($assigned_tabs->have_posts()) {
                while ($assigned_tabs->have_posts()) {
                    $assigned_tabs->the_post();
                    $slug = generate_slug(get_the_title());
                    $tabs[get_the_ID()] = [
                        'id'       => 'tab-' . $slug,
                        'title'    => get_the_title(),
                        'callback' => 'display_product_tab_content',
                        'priority' => (int) get_post_meta(get_the_ID(), 'product_tab_priority', true),
                        'content'  => get_the_content(),
                    ];
                }
                wp_reset_postdata();
            }
        }
    }

// Tabs for specific products

function get_product_assigned_tabs($product_id)
{
        $meta_query = [
           [
                'key'     => 'product_tab_products',
                'value'   => $product_id,
                'compare' => 'LIKE',
            ],
        ];

        return new WP_Query([
            'post_type'      => 'product-tab',
            'meta_query'     => $meta_query,
            'posts_per_page' => -1,
        ]);
}
        $product_id = $product->get_id();
        $product_assigned_tabs = get_product_assigned_tabs($product_id);

if ($product_assigned_tabs->have_posts()) {
    while ($product_assigned_tabs->have_posts()) {
        $product_assigned_tabs->the_post();
        $slug = generate_slug(get_the_title());
        $tabs[get_the_ID()] = [
            'id'       => 'tab-' . $slug,
            'title'    => get_the_title(),
            'callback' => 'display_product_tab_content',
            'priority' => (int) get_post_meta(get_the_ID(), 'product_tab_priority', true),
            'content'  => get_the_content(),
        ];
    }
    wp_reset_postdata();
}


    // Add custom field tabs
    // Define your custom fields, tab titles, and their priorities
    $custom_fields = array(
        'product_info_faq' => array('title' => 'Câu hỏi thường gặp', 'priority' => 20),
        'product_info_hdsd' => array('title' => 'Hướng dẫn sử dụng', 'priority' => 15),
        'product_info_thanh_phan' => array('title' => 'Thành phần', 'priority' => 10),
        'product_info_nhan_phu' => array('title' => 'Nhãn phụ', 'priority' => 25),
        'product_info_ho_so_phap_ly' => array('title' => 'Hồ sơ sản phẩm', 'priority' => 30),
    );

    foreach ($custom_fields as $field_key => $info) {
        $field_value = get_post_meta($product->get_id(), $field_key, true);

        if (!empty($field_value)) {
            $tabs[$field_key] = array(
                'id'       => 'tab-' . $slug,
                'title'    => $info['title'],
                'callback' => 'display_custom_product_field_tab_content',
                'priority' => $info['priority'],
            );
        }
    }

    // Add tabs based on thuong-hieu or products assigned to the tab
    $terms_thuong_hieu = wp_get_post_terms($product->get_id(), 'thuong-hieu', ['fields' => 'ids']);

    $meta_query = ['relation' => 'OR'];
    if (!empty($terms_thuong_hieu)) {
        $meta_query[] = [
            'key'     => 'product_tab_thuong_hieu',
            'value'   => $terms_thuong_hieu,
            'compare' => 'IN',
        ];
    }
    $meta_query[] = [
        'key'     => 'product_tab_products',
        'value'   => '"' . $product->get_id() . '"',
        'compare' => 'LIKE',
    ];

    $assigned_tabs_custom = new WP_Query([
        'post_type'      => 'product-tab',
        'meta_query'     => $meta_query,
        'posts_per_page' => -1,
    ]);

    if ($assigned_tabs_custom->have_posts()) {
        while ($assigned_tabs_custom->have_posts()) {
            $assigned_tabs_custom->the_post();
            $slug = generate_slug(get_the_title());
            $tabs[get_the_ID()] = [
                'id'       => 'tab-' . $slug,
                'title'    => get_the_title(),
                'callback' => 'display_product_tab_content',
                'priority' => (int) get_post_meta(get_the_ID(), 'product_tab_priority', true),
                'content'  => get_the_content(),
            ];
        }
        wp_reset_postdata();
    }

    // Add Thương hiệu tab
    $thuong_hieu_term = wp_get_post_terms($product->get_id(), 'thuong-hieu');
    if (!is_wp_error($thuong_hieu_term) && !empty($thuong_hieu_term)) {
        $thuong_hieu_description = term_description($thuong_hieu_term[0]->term_id, 'thuong-hieu');
        if (!empty($thuong_hieu_description)) {
            $tabs['thuong_hieu'] = array(
                'id'       => 'tab-thuong-hieu',
                'title'    => 'Thương hiệu',
                'callback' => 'display_thuong_hieu_tab_content',
                'priority' => 35,
            );
        }
    }

/*
    // Check if there are any points of sale linked to the product
    if (has_points_of_sale($product->get_id(), 'offline')) {
        $tabs['diem_ban_gan_ban'] = [
            'title'    => __('Điểm bán gần bạn', 'hithean.com'),
            'priority' => 42,
            'callback' => 'diem_ban_gan_ban_tab_content',
        ];
    }

    if (has_points_of_sale($product->get_id(), 'ecommerce')) {
        $tabs['mua_hang_online'] = [
            'title'    => __('Mua hàng online', 'hithean.com'),
            'priority' => 41,
            'callback' => 'mua_hang_online_tab_content',
        ];
    }
*/

    return $tabs;
}

function display_custom_product_field_tab_content($key, $tab)
{
    global $product;
    $field_value = get_post_meta($product->get_id(), $key, true);
    if (!empty($field_value)) {
        // echo '<h2>' . esc_html($tab['title']) . '</h2>';  // Output the tab title as an <h2> tag
        echo '<h2 id="' . esc_attr($tab['id']) . '" class="tab-title">' . esc_html($tab['title']) . '</h2>';  // Output the tab title as an <h2> tag
        echo '<div class="tab-content">' . wpautop(do_shortcode($field_value)) . '</div>';  // Process shortcodes and format text
    }
}


function display_product_tab_content($key, $tab)
{
    //    echo '<h2>' . esc_html($tab['title']) . '</h2>';
    echo '<h2 id="' . esc_attr($tab['id']) . '" class="tab-title">' . esc_html($tab['title']) . '</h2>';
    echo '<div class="tab-content">' . wpautop(do_shortcode($tab['content'])) . '</div>';
}

function display_thuong_hieu_tab_content()
{
    global $product;
    $thuong_hieu_term = wp_get_post_terms($product->get_id(), 'thuong-hieu');
    if (!is_wp_error($thuong_hieu_term) && !empty($thuong_hieu_term)) {
        $thuong_hieu_description = term_description($thuong_hieu_term[0]->term_id, 'thuong-hieu');
        //        echo '<h2>Thương hiệu</h2>';  // Output the tab title as an <h2> tag
        echo '<h2 id="tab-thuong-hieu" class="tab-title">Thương hiệu</h2>';  // Output the tab title as an <h2> tag
        echo '<div class="tab-content">' . wpautop(do_shortcode($thuong_hieu_description)) . '</div>';  // Process shortcodes and format text
    }
}

// Unset tabs
function unset_tabs($tabs)
{
    unset($tabs['reviews']);               // Remove the reviews tab
    unset($tabs['additional_information']);   // Remove the additional information tab

    return $tabs;
}
add_filter('woocommerce_product_tabs', 'unset_tabs', 98);

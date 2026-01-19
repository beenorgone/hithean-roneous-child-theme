<?php
add_shortcode('pos', 'display_points_of_sale_shortcode');

function display_points_of_sale_shortcode($atts)
{
    // Extract shortcode attributes
    $atts = shortcode_atts([
        'type'   => 'offline', // Default to 'offline'
        'ids'    => '',        // Default to empty, use the current product ID
        'filter' => 'false',   // Default to no filter
    ], $atts, 'pos');

    $type = sanitize_text_field($atts['type']);
    $ids = sanitize_text_field($atts['ids']);
    $filter = filter_var($atts['filter'], FILTER_VALIDATE_BOOLEAN);

    global $product;
    $product_ids = !empty($ids) ? explode(',', $ids) : [$product->get_id()];

    // Get points of sale
    $meta_query = [
        'relation' => 'AND',
        [
            'key'     => 'pos_type',
            'value'   => $type,
            'compare' => 'LIKE',
        ],
        [
            'key'     => 'pos_products',
            'value'   => $product_ids,
            'compare' => 'LIKE',
        ],
    ];

    $pos_query = new WP_Query([
        'post_type'      => 'diem-ban',
        'meta_query'     => $meta_query,
        'posts_per_page' => -1,
    ]);

    // Start output buffering
    ob_start();

    if ($type === 'ecommerce' && $pos_query->have_posts()) {
        // Render e-commerce grid
        echo '<h2 id="mua-hang-online" class="">Mua hàng online</h2>';
	echo '<p style="text-align: center;"><i>Danh sách chỉ gồm những đại lý tiêu biểu và sẵn hàng. Đang cập nhật thêm</i></p>';
        echo '<div id="ecommerce-store-list" class="b-flex-blocks b-flex-blocks-3">';

        while ($pos_query->have_posts()) {
            $pos_query->the_post();
            $ecommerce_link = get_post_meta(get_the_ID(), 'pos_ecommerce-link', true); // Get e-commerce store link
            $logo_url = get_the_post_thumbnail_url(get_the_ID(), 'full'); // Get the featured image URL

            echo '<div class="ecommerce-store-item">';
            echo '<div class="ecommerce-store-item-title-section">';
            if (!empty($logo_url)) {
                echo '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr(get_the_title()) . '" style="width:100px; height:auto; margin-right:10px; vertical-align: middle;">'; // Display the featured image
            }
            echo '<p>' . esc_html(get_the_title()) . '</p></div>';
            echo '<div class="ecommerce-store-item-details-section">' . $ecommerce_link . '</div>';
            echo '</div>';
        }

        echo '</div>';
    } elseif ($pos_query->have_posts()) {

        echo '<h2 id="diem-ban-offline" class="">Điểm bán gần bạn</h2>';  // Output the tab ti$
	echo '<p style="text-align: center;"><i>Danh sách chỉ gồm những đại lý tiêu biểu và sẵn hàng. Đang cập nhật thêm</i></p>';
        // Handle offline or other types (with filter for offline)
        if ($filter && $type === 'offline') {
            echo '<div id="filter-form">';
            echo '<select id="filter-tinhthanhpho"><option value="">Chọn Tỉnh / Thành phố</option></select>';
            echo '<select id="filter-quanhuyen"><option value="">Chọn Quận / Huyện</option></select>';
            echo '</div>';
        }

        echo '<div id="locations-list" class="b-flex-blocks b-flex-blocks-3">';
        $locations = [];

        while ($pos_query->have_posts()) {
            $pos_query->the_post();
            $logo_url = get_the_post_thumbnail_url(get_the_ID(), 'full'); // Get the featured image URL
            $tinhthanhpho = get_post_meta(get_the_ID(), 'pos_tinhthanhpho', true);
            $quanhuyen = get_post_meta(get_the_ID(), 'pos_quanhuyen', true);
	    $diachi = get_post_meta(get_the_ID(), 'pos_diachichitiet', true);
            $linkchiduong = get_post_meta(get_the_ID(), 'pos_linkchiduong', true);

            if (!isset($locations[$tinhthanhpho])) {
                $locations[$tinhthanhpho] = [];
            }

            if (!isset($locations[$tinhthanhpho][$quanhuyen])) {
                $locations[$tinhthanhpho][$quanhuyen] = [];
            }

            $locations[$tinhthanhpho][$quanhuyen][] = [
                'title'    => get_the_title(),
                'linkmap'  => $linkchiduong,
		'diachi'   => $diachi,
                'id'    => get_the_ID(),
                'logo'  => $logo_url // featured image URL
            ];
	}

        foreach ($locations as $tinhthanhpho => $districts) {
            foreach ($districts as $quanhuyen => $posts) {
                foreach ($posts as $post) {
	            echo '<div class="location-item" style="" data-tinhthanhpho="' . esc_attr($tinhthanhpho) . '" data-quanhuyen="' . esc_attr($quanhuyen) . '">';
		    echo '<div class="location-item-title-section">';
	            	if (!empty($post['logo'])) {
                            echo '<img src="' . esc_url($post['logo']) . '" alt="' . esc_attr($post['title']) . '" style="width:100px; vertical-align:middle; margin-right:10px;">';
                        }
                        echo '<p>' . esc_html($post['title']) . '</p></br>';
		    echo '</div>';
                    echo '<div class="location-item-address-section">';
		    if (!empty($post['diachi'])) {
                        echo '<p><img src="https://hithean.com/wp-content/uploads/2025/01/address-icon-50x50-transparent.png" alt="Địa chỉ chi tiết" style="width:20px;height:20px;vertical-align:middle;margin-right:5px;">' . esc_html($post['diachi']) . '</p>';
		    }
	            if (!empty($post['linkmap'])) {
        	        echo '<p><a href="' . esc_url($post['linkmap']) . '" target="_blank"><img src="https://w7.pngwing.com/pngs/960/425/png-transparent-google-maps-new-logo-icon-thumbnail.png" alt="Google Maps" style="width:16px;height:16px;vertical-align:middle;margin-right:5px;">Chỉ đường</a></p>';
	            }
        	    echo '</div></div>';
        	}
	    }
	}
        echo '</div>';

        if ($filter && $type === 'offline') {
    // Include JavaScript for filtering
    echo '<script>
    jQuery(document).ready(function($) {
        // Populate Tỉnh / Thành phố filter
        var locations = ' . json_encode($locations) . ';
        for (var tinhthanhpho in locations) {
            $("#filter-tinhthanhpho").append("<option value=\'" + tinhthanhpho + "\'>" + tinhthanhpho + "</option>");
        }

        // Populate Quận / Huyện filter based on selected Tỉnh / Thành phố
        $("#filter-tinhthanhpho").change(function() {
            var tinhthanhpho = $(this).val();
            $("#filter-quanhuyen").empty().append("<option value=\'\'>Chọn Quận / Huyện</option>");
            if (tinhthanhpho && locations[tinhthanhpho]) {
                for (var quanhuyen in locations[tinhthanhpho]) {
                    $("#filter-quanhuyen").append("<option value=\'" + quanhuyen + "\'>" + quanhuyen + "</option>");
                }
            }
            filterLocations();
        });

        // Filter locations based on selected filters
        $("#filter-quanhuyen").change(function() {
            filterLocations();
        });

        function filterLocations() {
            var tinhthanhpho = $("#filter-tinhthanhpho").val();
            var quanhuyen = $("#filter-quanhuyen").val();

            $(".location-item").hide().filter(function() {
                var match = true;
                if (tinhthanhpho && $(this).data("tinhthanhpho") != tinhthanhpho) {
                    match = false;
                }
                if (quanhuyen && $(this).data("quanhuyen") != quanhuyen) {
                    match = false;
                }
                return match;
            }).show();
        }
    });
    </script>';

        }
    } else {
        echo '<p>Không có điểm bán nào được tìm thấy.</p>';
    }

    wp_reset_postdata();

    return ob_get_clean();
}


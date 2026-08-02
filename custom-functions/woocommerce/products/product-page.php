<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  REMOVE RELATED PRODUCTS SECTION
\*---------------------------------------*/
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);

/*---------------------------------------*\
  SALE PRICE DATE NOTICE
\*---------------------------------------*/

add_filter('woocommerce_get_price_html', 'hithean_append_sale_price_date_notice_to_price_html', 20, 2);

function hithean_append_sale_price_date_notice_to_price_html($price_html, $product)
{
    if (!is_product() || !$product instanceof WC_Product || !$product->is_on_sale()) {
        return $price_html;
    }

    $queried_product_id = (int) get_queried_object_id();
    if ($queried_product_id <= 0) {
        return $price_html;
    }

    $product_id = (int) $product->get_id();
    $parent_id  = (int) $product->get_parent_id();
    if ($product_id !== $queried_product_id && $parent_id !== $queried_product_id) {
        return $price_html;
    }

    $date_range = hithean_get_sale_price_date_range($product);
    $notice     = hithean_format_sale_price_date_notice($date_range['from'], $date_range['to']);

    return $price_html . '<span class="hithean-sale-price-date-notice">' . esc_html($notice) . '</span>';
}

function hithean_get_sale_price_date_range(WC_Product $product): array
{
    $from = hithean_wc_datetime_to_timestamp($product->get_date_on_sale_from());
    $to   = hithean_wc_datetime_to_timestamp($product->get_date_on_sale_to());

    if (($from || $to) || !$product->is_type('variable')) {
        return [
            'from' => $from,
            'to'   => $to,
        ];
    }

    $variation_ids = $product->get_children();
    foreach ($variation_ids as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (!$variation instanceof WC_Product || !$variation->is_on_sale()) {
            continue;
        }

        $variation_from = hithean_wc_datetime_to_timestamp($variation->get_date_on_sale_from());
        $variation_to   = hithean_wc_datetime_to_timestamp($variation->get_date_on_sale_to());

        if ($variation_from && (!$from || $variation_from < $from)) {
            $from = $variation_from;
        }
        if ($variation_to && (!$to || $variation_to > $to)) {
            $to = $variation_to;
        }
    }

    return [
        'from' => $from,
        'to'   => $to,
    ];
}

function hithean_wc_datetime_to_timestamp($datetime): int
{
    return $datetime instanceof WC_DateTime ? $datetime->getTimestamp() : 0;
}

function hithean_format_sale_price_date_notice(int $from, int $to): string
{
    $date_format = get_option('date_format') ?: 'd/m/Y';

    if ($from && $to) {
        return sprintf('Áp dụng đến hết %s', wp_date($date_format, $to));
    }

    if ($from) {
        return sprintf('Áp dụng từ %s', wp_date($date_format, $from));
    }

    if ($to) {
        return sprintf('Áp dụng đến hết %s', wp_date($date_format, $to));
    }

    return 'Đang áp dụng giá sale';
}

add_action('wp_head', 'hithean_sale_price_date_notice_css');

function hithean_sale_price_date_notice_css()
{
    if (!is_product()) {
        return;
    }
    ?>
    <style>
        .woocommerce div.product p.price .hithean-sale-price-date-notice,
        .woocommerce div.product span.price .hithean-sale-price-date-notice {
            display: flex;
            align-items: center;
            width: fit-content;
            margin: 8px 0 0;
            padding: 6px 10px;
            border-radius: 4px;
            background: #fff7ed;
            color: #9a3412 !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            line-height: 1.4;
        }

        @media (max-width: 768px) {
            .woocommerce div.product p.price .hithean-sale-price-date-notice,
            .woocommerce div.product span.price .hithean-sale-price-date-notice {
                text-align: center;
                margin-right: auto;
                margin-left: auto;
                font-style: italic;
            }
        }
    </style>
    <?php
}


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
        $tabs[$slug] = [
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
                    $tabs[$slug] = [
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
            $tabs[$slug] = [
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
            $slug = generate_slug($info['title']);
            $tabs[$slug] = array(
                'id'       => 'tab-' . $slug,
                'title'    => $info['title'],
                'meta_key' => $field_key,
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
            $tabs[$slug] = [
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
            $tabs['thuong-hieu'] = array(
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

    if (isset($tabs['description'])) {
        $tabs['description']['priority'] = 1;
    }

    return $tabs;
}

function display_custom_product_field_tab_content($key, $tab)
{
    global $product;
    $meta_key = isset($tab['meta_key']) ? $tab['meta_key'] : $key;
    $field_value = get_post_meta($product->get_id(), $meta_key, true);
    if (!empty($field_value)) {
        // echo '<h2>' . esc_html($tab['title']) . '</h2>';  // Output the tab title as an <h2> tag
        echo '<h2 class="tab-title">' . esc_html($tab['title']) . '</h2>';  // Output the tab title as an <h2> tag
        echo '<div class="tab-content">' . wpautop(do_shortcode($field_value)) . '</div>';  // Process shortcodes and format text
    }
}


function display_product_tab_content($key, $tab)
{
    //    echo '<h2>' . esc_html($tab['title']) . '</h2>';
    echo '<h2 class="tab-title">' . esc_html($tab['title']) . '</h2>';
    echo '<div class="tab-content">' . wpautop(do_shortcode($tab['content'])) . '</div>';
}

function display_thuong_hieu_tab_content()
{
    global $product;
    $thuong_hieu_term = wp_get_post_terms($product->get_id(), 'thuong-hieu');
    if (!is_wp_error($thuong_hieu_term) && !empty($thuong_hieu_term)) {
        $thuong_hieu_description = term_description($thuong_hieu_term[0]->term_id, 'thuong-hieu');
        //        echo '<h2>Thương hiệu</h2>';  // Output the tab title as an <h2> tag
        echo '<h2 class="tab-title">Thương hiệu</h2>';  // Output the tab title as an <h2> tag
        echo '<div class="tab-content">' . wpautop(do_shortcode($thuong_hieu_description)) . '</div>';  // Process shortcodes and format text
    }
}

/*---------------------------------------*\
  STICKY ADD TO CART BAR (MOBILE)
\*---------------------------------------*/

add_action('wp_footer', 'hithean_sticky_atc_js');

function hithean_sticky_atc_js()
{
    if (!is_singular('product')) {
        return;
    }

    global $product;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
        return;
    }

    $max      = $product->get_max_purchase_quantity();
    $max_attr = $max > 0 ? $max : 9999;
    $btn_text = esc_html($product->single_add_to_cart_text());
    ?>
    <div class="woocommerce sticky-atc-bar" aria-label="Thêm vào giỏ hàng">
        <div class="quantity sticky-atc-bar__qty">
            <input class="input-text qty text sticky-atc-bar__qty-input" type="number" step="1" value="1" min="1" max="<?php echo esc_attr($max_attr); ?>" />
        </div>
        <button class="single_add_to_cart_button button alt sticky-atc-bar__btn" type="button" style="margin-bottom: 0 !important;"><?php echo $btn_text; ?></button>
    </div>
    <script>
    jQuery(function($) {
        var $bar = $('.sticky-atc-bar');
        if (!$bar.length) return;

        var $qtyInput   = $bar.find('.sticky-atc-bar__qty-input');
        var $stickyBtn  = $bar.find('.sticky-atc-bar__btn');
        var $origBtn    = $('form.cart button.single_add_to_cart_button').first();
        var btnOrigText = $stickyBtn.text().trim();
        var ajaxUrl  = '<?php echo esc_url(add_query_arg('wc-ajax', 'add_to_cart', home_url('/'))); ?>';
        var cartUrl  = '<?php echo esc_url(wc_get_cart_url()); ?>';
        var $toast   = $('<div class="sticky-atc-toast" role="status" aria-live="polite"><span class="sticky-atc-toast__msg"></span></div>').appendTo('body');
        var toastTimer = null;

        function showToast(msg, type, cta) {
            clearTimeout(toastTimer);
            $toast.find('.sticky-atc-toast__cta').remove();
            $toast.removeClass('sticky-atc-toast--success sticky-atc-toast--error is-visible')
                  .find('.sticky-atc-toast__msg').text(msg);
            $toast.addClass('sticky-atc-toast--' + type);
            if (cta) {
                $('<a class="sticky-atc-toast__cta">')
                    .attr('href', cta.url)
                    .text(cta.label)
                    .appendTo($toast);
            }
            void $toast[0].offsetWidth;
            $toast.addClass('is-visible');
            toastTimer = setTimeout(function() { $toast.removeClass('is-visible'); }, 4000);
        }

        $stickyBtn.on('click', function() {
            if ($stickyBtn.hasClass('disabled') || $stickyBtn.prop('disabled')) return;

            var $form     = $('form.cart');
            var productId = $form.find('[name="add-to-cart"]').val() || $form.data('product_id');
            var formData  = $form.serializeArray().filter(function(f) { return f.name !== 'add-to-cart'; });

            // Ensure quantity comes from sticky bar
            var hasQty = false;
            $.each(formData, function(i, f) {
                if (f.name === 'quantity') { f.value = parseInt($qtyInput.val()) || 1; hasQty = true; }
            });
            if (!hasQty) formData.push({ name: 'quantity', value: parseInt($qtyInput.val()) || 1 });
            formData.push({ name: 'product_id', value: productId });

            $stickyBtn.prop('disabled', true).text('Đang thêm...');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: $.param(formData),
                dataType: 'json',
            }).done(function(response) {
                if (response && response.error) {
                    showToast('Không thể thêm sản phẩm', 'error');
                } else {
                    $(document.body).trigger('wc_fragment_refresh');
                    $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $stickyBtn]);
                    showToast('Đã thêm vào giỏ hàng!', 'success', { url: cartUrl, label: 'Xem giỏ hàng' });
                }
            }).fail(function(xhr) {
                // WC sometimes responds with 200 but non-JSON — parse manually before showing error
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (!res.error) {
                        $(document.body).trigger('wc_fragment_refresh');
                        showToast('Đã thêm vào giỏ hàng!', 'success', { url: cartUrl, label: 'Xem giỏ hàng' });
                        return;
                    }
                } catch (e) {}
                showToast('Có lỗi xảy ra, vui lòng thử lại', 'error');
            }).always(function() {
                $stickyBtn.prop('disabled', false).text(btnOrigText);
            });
        });

        // Mirror disabled state from original button (e.g. variable product, no variation selected)
        function syncDisabled() {
            var isDisabled = $origBtn.prop('disabled') || $origBtn.hasClass('disabled');
            $stickyBtn.toggleClass('disabled', isDisabled).prop('disabled', isDisabled);
        }

        if ($origBtn.length && window.MutationObserver) {
            new MutationObserver(syncDisabled).observe($origBtn[0], { attributes: true });
        }
        syncDisabled();
    });
    </script>
    <?php
}

// Unset tabs
function unset_tabs($tabs)
{
    unset($tabs['reviews']);               // Remove the reviews tab
    unset($tabs['additional_information']);   // Remove the additional information tab

    return $tabs;
}
add_filter('woocommerce_product_tabs', 'unset_tabs', 98);

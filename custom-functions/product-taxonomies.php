<?php

/**
 * Unified Term Thumbnail for taxonomies: thuong-hieu, product_tag
 * - Adds Image field to Add/Edit screens
 * - Saves to term meta 'thumbnail_id'
 * - Provides helpers to retrieve thumbnail id/html
 */

add_action('init', function () {
    $taxes = ['thuong-hieu', 'product_tag'];

    // 1) Register term meta (nice for REST)
    foreach ($taxes as $tax) {
        register_term_meta($tax, 'thumbnail_id', [
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
            'auth_callback'     => function () { return current_user_can('edit_posts'); },
        ]);
    }

    // 2) Add fields on "Add New"
    foreach ($taxes as $tax) {
        add_action("{$tax}_add_form_fields", function () use ($tax) {
            ?>
            <div class="form-field term-thumbnail-wrap">
                <label><?php esc_html_e('Image', 'theanorganics.com'); ?></label>
                <div id="<?php echo esc_attr($tax); ?>_thumbnail" style="margin-bottom:8px;"></div>
                <input type="hidden" name="thumbnail_id" id="<?php echo esc_attr($tax); ?>_thumbnail_id" value="">
                <button type="button" class="button upload_term_image" data-tax="<?php echo esc_attr($tax); ?>">
                    <?php esc_html_e('Upload/Add image', 'theanorganics.com'); ?>
                </button>
                <button type="button" class="button remove_term_image" data-tax="<?php echo esc_attr($tax); ?>" style="display:none;">
                    <?php esc_html_e('Remove image', 'theanorganics.com'); ?>
                </button>
            </div>
            <?php
        });
    }

    // 3) Add fields on "Edit"
    foreach ($taxes as $tax) {
        add_action("{$tax}_edit_form_fields", function ($term) use ($tax) {
            $image_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            $image    = $image_id ? wp_get_attachment_image($image_id, 'thumbnail') : '';
            ?>
            <tr class="form-field term-thumbnail-wrap">
                <th scope="row"><label><?php esc_html_e('Image', 'theanorganics.com'); ?></label></th>
                <td>
                    <div id="<?php echo esc_attr($tax); ?>_thumbnail" style="margin-bottom:8px;"><?php echo $image; ?></div>
                    <input type="hidden" name="thumbnail_id" id="<?php echo esc_attr($tax); ?>_thumbnail_id" value="<?php echo esc_attr($image_id); ?>">
                    <button type="button" class="button upload_term_image" data-tax="<?php echo esc_attr($tax); ?>">
                        <?php esc_html_e('Upload/Add image', 'theanorganics.com'); ?>
                    </button>
                    <button type="button" class="button remove_term_image" data-tax="<?php echo esc_attr($tax); ?>" <?php if (!$image_id) echo 'style="display:none;"'; ?>>
                        <?php esc_html_e('Remove image', 'theanorganics.com'); ?>
                    </button>
                </td>
            </tr>
            <?php
        });
    }

    // 4) Save meta on create/update
    foreach ($taxes as $tax) {
        add_action("created_{$tax}", function ($term_id) {
            if (isset($_POST['thumbnail_id'])) {
                update_term_meta($term_id, 'thumbnail_id', absint($_POST['thumbnail_id']));
            }
        });
        add_action("edited_{$tax}", function ($term_id) {
            if (isset($_POST['thumbnail_id'])) {
                update_term_meta($term_id, 'thumbnail_id', absint($_POST['thumbnail_id']));
            }
        });
    }
});

// 5) Enqueue media + one inline JS for both taxonomies
add_action('admin_enqueue_scripts', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return;

    $taxes = ['thuong-hieu', 'product_tag'];
    $is_target = (isset($screen->taxonomy) && in_array($screen->taxonomy, $taxes, true))
                 || (isset($_GET['taxonomy']) && in_array($_GET['taxonomy'], $taxes, true));

    if (! $is_target) return;

    wp_enqueue_media();
    wp_add_inline_script('jquery', "
        jQuery(function($){
            var frame;
            function setImage(tax, id, url){
                $('#'+tax+'_thumbnail').html('<img src=\"'+url+'\" />');
                $('#'+tax+'_thumbnail_id').val(id);
                $('.remove_term_image[data-tax=\"'+tax+'\"]').show();
            }
            $(document).on('click', '.upload_term_image', function(e){
                e.preventDefault();
                var tax = $(this).data('tax');
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: '".esc_js(__('Choose image','theanorganics.com'))."',
                    button: { text: '".esc_js(__('Use image','theanorganics.com'))."' },
                    multiple: false
                });
                frame.on('select', function(){
                    var a = frame.state().get('selection').first().toJSON();
                    var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
                    setImage(tax, a.id, url);
                });
                frame.open();
            });
            $(document).on('click', '.remove_term_image', function(e){
                e.preventDefault();
                var tax = $(this).data('tax');
                $('#'+tax+'_thumbnail').empty();
                $('#'+tax+'_thumbnail_id').val('');
                $(this).hide();
            });
        });
    ");
});

// 6) Helpers (generic)
function tao_term_thumbnail_id($term = null){
    $term = $term ?: get_queried_object();
    if (!$term || empty($term->term_id)) return 0;
    return (int) get_term_meta($term->term_id, 'thumbnail_id', true);
}
function tao_term_thumbnail_html($term = null, $size = 'large', $attr = []){
    $id = tao_term_thumbnail_id($term);
    return $id ? wp_get_attachment_image($id, $size, false, $attr) : '';
}

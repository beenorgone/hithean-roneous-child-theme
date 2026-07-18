<?php
if (!defined('ABSPATH')) exit;

/**
 * Plugin Module: Custom Post Type Revisions Support
 * Kích hoạt hỗ trợ revisions và so sánh meta fields cho mọi CPT
 * Author: WP Creator
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta keys cần snapshot vào post revisions.
 *
 * @return string[]
 */
function hithean_revision_meta_keys(): array
{
    $meta_keys = [
        'csv_data',
        'log_history',
        'receipt_status',
        'inventory_receipt_notes',
        'cogs_log',
        'product_expiry_date',
        'product_expiry_notes',
        'product_info_unit',
        'product_info_reference_from_brand',
        'product_info_thean_link',
        'product_info_subheading',
        'product_info_hdsd',
        'product_info_thanh_phan',
        'product_info_nhan_phu',
        'product_info_ho_so_phap_ly',
        'product_info_faq',
        'from_sourcing_order_variation',
        'product_expiry_date_variation',
        'product_expiry_notes_variation',
        'product_expiry_date_new_variation',
        'log_variation',
        'inventory_check_log_variation',
        'cogs_log_variation',
        'product_order_new_variation',
        'product_quant_new_variation',
        'product_cogs_new_variation',
        '_sku',
        '_global_unique_id',
        '_regular_price',
        '_sale_price',
        '_price',
        '_manage_stock',
        '_stock',
        '_stock_status',
        '_backorders',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_thumbnail_id',
        '_product_image_gallery',
        '_visibility',
        '_catalog_visibility',
    ];

    return array_values(array_unique(array_filter((array) apply_filters('mfg_revision_meta_keys', $meta_keys), 'is_string')));
}

/**
 * Product taxonomies cần snapshot cùng revision.
 *
 * @return string[]
 */
function hithean_revision_taxonomies(): array
{
    $taxonomies = [
        'product_cat',
        'product_tag',
        'thuong-hieu',
        'quoc-gia',
        'chung-nhan',
    ];

    return array_values(array_unique(array_filter((array) apply_filters('mfg_revision_taxonomies', $taxonomies), 'is_string')));
}

function hithean_revision_term_meta_key(string $taxonomy): string
{
    return '_hithean_revision_terms_' . sanitize_key($taxonomy);
}

function hithean_revision_get_meta_value(int $post_id, string $key): string
{
    $value = get_post_meta($post_id, $key, true);

    if (is_array($value) || is_object($value)) {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    return (string) $value;
}

function hithean_revision_get_term_value(int $post_id, string $taxonomy): string
{
    if (!taxonomy_exists($taxonomy)) {
        return '';
    }

    $term_ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
    if (is_wp_error($term_ids)) {
        return '';
    }

    $term_ids = array_map('intval', (array) $term_ids);
    sort($term_ids);

    return implode(',', $term_ids);
}

function hithean_revision_copy_meta_to_revision(int $post_id, int $revision_id): void
{
    foreach (hithean_revision_meta_keys() as $key) {
        $value = get_post_meta($post_id, $key, true);
        if ($value !== '' && $value !== [] && $value !== null) {
            update_metadata('post', $revision_id, $key, $value);
        } else {
            delete_metadata('post', $revision_id, $key);
        }
    }

    foreach (hithean_revision_taxonomies() as $taxonomy) {
        $value = hithean_revision_get_term_value($post_id, $taxonomy);
        $meta_key = hithean_revision_term_meta_key($taxonomy);

        if ($value !== '') {
            update_metadata('post', $revision_id, $meta_key, $value);
        } else {
            delete_metadata('post', $revision_id, $meta_key);
        }
    }
}

function hithean_save_post_meta_revision(int $post_id)
{
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return false;
    }

    $post_type = get_post_type($post_id);
    if (!is_string($post_type) || !post_type_supports($post_type, 'revisions')) {
        return false;
    }

    $revision_id = wp_save_post_revision($post_id);
    if (!$revision_id) {
        return false;
    }

    hithean_revision_copy_meta_to_revision($post_id, (int) $revision_id);

    return $revision_id;
}

/**
 * Kích hoạt revisions và theo dõi meta cho mọi CPT (bao gồm không public).
 */
function hithean_enable_revisions_for_all_custom_post_types_with_meta()
{

    // Bật revisions cho mọi CPT không phải mặc định
    add_action('init', function () {
        $args = ['_builtin' => false];
        $custom_post_types = get_post_types($args, 'names');

        foreach ($custom_post_types as $cpt) {
            add_post_type_support($cpt, 'revisions');
        }
    });

    // Kiểm tra thay đổi meta để tạo bản revision mới
    add_filter('wp_save_post_revision_check_for_changes', function ($check, $last_revision, $post) {
        foreach (hithean_revision_meta_keys() as $key) {
            $old = hithean_revision_get_meta_value((int) $last_revision->ID, $key);
            $new = hithean_revision_get_meta_value((int) $post->ID, $key);
            if ($old !== $new) return true;
        }

        foreach (hithean_revision_taxonomies() as $taxonomy) {
            $old = (string) get_post_meta((int) $last_revision->ID, hithean_revision_term_meta_key($taxonomy), true);
            $new = hithean_revision_get_term_value((int) $post->ID, $taxonomy);
            if ($old !== $new) return true;
        }

        return $check;
    }, 10, 3);

    // Lưu meta vào bản revision
    add_action('save_post', function ($post_id) {
        hithean_save_post_meta_revision((int) $post_id);
    }, 99);

    // Khôi phục meta khi restore revision
    add_action('wp_restore_post_revision', function ($post_id, $revision_id) {
        foreach (hithean_revision_meta_keys() as $key) {
            $value = get_metadata('post', $revision_id, $key, true);
            if ($value !== '' && $value !== [] && $value !== null) {
                update_post_meta($post_id, $key, $value);
            } else {
                delete_post_meta($post_id, $key);
            }
        }

        foreach (hithean_revision_taxonomies() as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $value = (string) get_metadata('post', $revision_id, hithean_revision_term_meta_key($taxonomy), true);
            $term_ids = $value !== '' ? array_filter(array_map('absint', explode(',', $value))) : [];
            wp_set_object_terms((int) $post_id, $term_ids, $taxonomy);
        }
    }, 10, 2);

    // Hiển thị diff trong giao diện Revisions
    add_filter('_wp_post_revision_fields', function ($fields, $post = null) {
        foreach (hithean_revision_meta_keys() as $key) {
            $label = ucwords(str_replace('_', ' ', $key));
            $fields[$key] = $label;
        }

        foreach (hithean_revision_taxonomies() as $taxonomy) {
            $fields[hithean_revision_term_meta_key($taxonomy)] = 'Taxonomy: ' . $taxonomy;
        }

        return $fields;
    }, 10, 2);

    foreach (hithean_revision_meta_keys() as $key) {
        add_filter('_wp_post_revision_field_' . $key, function ($value, $field, $revision) {
            return hithean_revision_get_meta_value((int) $revision->ID, (string) $field);
        }, 10, 3);
    }

    foreach (hithean_revision_taxonomies() as $taxonomy) {
        $field = hithean_revision_term_meta_key($taxonomy);
        add_filter('_wp_post_revision_field_' . $field, function ($value, $field, $revision) {
            $revision_id = (int) $revision->ID;
            if (get_post_type($revision_id) === 'revision') {
                return (string) get_post_meta($revision_id, (string) $field, true);
            }

            $taxonomy = preg_replace('/^_hithean_revision_terms_/', '', (string) $field);
            return is_string($taxonomy) ? hithean_revision_get_term_value($revision_id, $taxonomy) : '';
        }, 10, 3);
    }
}
hithean_enable_revisions_for_all_custom_post_types_with_meta();

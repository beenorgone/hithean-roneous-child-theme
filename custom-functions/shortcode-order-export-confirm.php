<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('hithean_upload_internal_order_image')) {
    /**
     * Store internal order evidence images without generating every registered
     * theme/WooCommerce thumbnail size. These images are admin proof only.
     */
    function hithean_upload_internal_order_image(array $file, int $parent_post_id, string $target_filename, int $max_dimension = 1000)
    {
        if (!empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('invalid_upload', 'File upload không hợp lệ');
        }

        $ext = strtolower(pathinfo($target_filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return new WP_Error('invalid_type', 'Chỉ hỗ trợ JPG, JPEG, PNG');
        }

        $mime = wp_check_filetype($target_filename);
        if (empty($mime['type']) || !in_array($mime['type'], ['image/jpeg', 'image/png'], true)) {
            return new WP_Error('invalid_mime', 'Định dạng ảnh không hợp lệ');
        }

        $editor = wp_get_image_editor($file['tmp_name']);
        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            if (!empty($size['width']) && !empty($size['height']) && max($size['width'], $size['height']) > $max_dimension) {
                $editor->resize($max_dimension, $max_dimension, false);
            }

            if (method_exists($editor, 'set_quality')) {
                $editor->set_quality(82);
            }

            $editor->save($file['tmp_name'], $mime['type']);
            clearstatcache(true, $file['tmp_name']);
            $file['size'] = filesize($file['tmp_name']);
        }

        $file['name'] = sanitize_file_name($target_filename);
        $file['type'] = $mime['type'];

        $upload = wp_handle_sideload($file, [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg' => 'image/jpeg',
                'png' => 'image/png',
            ],
        ]);

        if (!empty($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error']);
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_text_field(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $parent_post_id,
        ], $upload['file'], $parent_post_id, true);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        update_attached_file($attachment_id, $upload['file']);

        $image_size = @getimagesize($upload['file']);
        if ($image_size) {
            update_post_meta($attachment_id, '_wp_attachment_metadata', [
                'width' => (int) $image_size[0],
                'height' => (int) $image_size[1],
                'file' => _wp_relative_upload_path($upload['file']),
                'sizes' => [],
            ]);
        }

        return $attachment_id;
    }
}

// ===== Shortcode Upload Ảnh =====
function shortcode_upload_export_images_form()
{
    if (!is_user_logged_in() || !current_user_can('upload_files') || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();
?>
    <h2>Upload ảnh đơn hàng</h2>
    <form id="upload-export-form" enctype="multipart/form-data">
        <p>
            <label for="ueif_order_id">Đơn hàng (Order ID):</label><br>
            <input type="number" name="ueif_order_id" id="ueif_order_id" required style="width:100%;max-width:500px;border-radius:5px;border:2px solid #ccc !important;">
        </p>
        <p>
            <label for="ueif_images">Ảnh xuất kho:</label><br>
            <input type="file" name="ueif_images[]" id="ueif_images" multiple accept="image/*" required style="width:100%;max-width:500px;border-radius:5px;border:2px solid #ccc!important;">
            <span id="ueif-images-notice" style="display:inline-block;margin-top:8px;padding:8px 14px;background:#fff3cd;border:2px solid #f0ad4e;border-radius:6px;color:#7a4f00;font-weight:600;font-size:0.9em;">
                ⚠️ Chỉ được chọn tối đa <strong>5 ảnh</strong> mỗi lần upload
            </span>
            <span id="ueif-images-error" style="display:none;margin-top:8px;padding:8px 14px;background:#f8d7da;border:2px solid #dc3545;border-radius:6px;color:#721c24;font-weight:600;font-size:0.9em;">
                ❌ Bạn đã chọn quá 5 ảnh! Vui lòng chọn lại tối đa 5 ảnh.
            </span>
        </p>
        <p>
            <button type="submit" class="button button-primary">Upload ảnh xuất kho</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('upload_export_images_form', 'shortcode_upload_export_images_form');


// ===== Shortcode List Đơn Chưa Xác Nhận =====
function shortcode_list_unconfirmed_exports()
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();

    $args = [
        'post_type' => 'shop_order',
        'post_status' => 'any',
        'posts_per_page' => 40,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'warehouse_export_images',
                'compare' => 'EXISTS',
            ],
            [
                'key' => 'warehouse_export_images',
                'value' => '',
                'compare' => '!=',
            ],
            [
                'relation' => 'OR',
                [
                    'key' => 'export_confirmed_by',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'export_confirmed_by',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ],
    ];

    $orders = get_posts($args);

    echo '<h2>Ảnh lấy hàng chờ kiểm tra</h2>';

    if (empty($orders)) {
        echo '<p>Không có đơn nào chờ xác nhận.</p>';
        return ob_get_clean();
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">';

    foreach ($orders as $order_post) {
        $order_id = $order_post->ID;
        $order = wc_get_order($order_id);
        $status = wc_get_order_status_name($order->get_status());
        $edit_link = get_edit_post_link($order_id);

        echo '<div class="order-card" style="border:1px solid #ccc;border-radius:8px;padding:15px;background:#fff;">';
        echo '<h4 style="margin-top: 10px;">Đơn hàng #' . esc_html($order_id) . '</h4>';
        echo '<p>Trạng thái: <strong>' . esc_html($status) . '</strong> - ';
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">✏️ Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html(ct_get_order_item_display_name($item)) . ' × ' . esc_html($item->get_quantity()) . '</li>';
        }
        echo '</ul>';

        // Hiển thị ảnh
        $images_raw = get_post_meta($order_id, 'warehouse_export_images', true);
        $urls = array_filter(array_map('trim', explode("\n", $images_raw)));
        if (!empty($urls)) {
            echo '<div class="order-images" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">';
            foreach ($urls as $url) {
                echo '<a href="' . esc_url($url) . '" target="_blank">';
                echo '<img src="' . esc_url($url) . '" style="max-width:80px;height:auto;border:1px solid #ddd;border-radius:4px;">';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<em>Không có ảnh</em>';
        }

        // Form xác nhận
    ?>
        <form class="export-confirm-form" style="margin-top:15px;">
            <input type="hidden" name="uexe_order_id" value="<?php echo esc_attr($order_id); ?>">
            <button type="submit" class="button" style="background:green;color:#fff;">Xác nhận xuất kho</button>
        </form>
        <?php

        echo '</div>'; // end card
    }

    echo '</div>'; // end grid

    return ob_get_clean();
}
add_shortcode('list_unconfirmed_exports', 'shortcode_list_unconfirmed_exports');

// ===== Shortcode List Đơn Đã Upload Ảnh nhưng Chưa Giao =====
function shortcode_list_uploaded_not_shipped_exports()
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();

    $args = [
        'post_type'      => 'shop_order',
        'post_status'    => 'any',
        'posts_per_page' => 40,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => 'warehouse_export_images',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'warehouse_export_images',
                'value'   => '',
                'compare' => '!=',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => 'export_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => 'export_date',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ],
    ];

    $orders = get_posts($args);

    // Lọc theo trạng thái đơn (loại bỏ shipping, delivered, completed)
    $filtered_orders = [];
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order) continue;
        $status = $order->get_status();

        if (!in_array($status, ['shipping', 'delivered', 'completed'])) {
            $filtered_orders[] = $order;
        }
    }

    echo '<h2>Đơn đã upload ảnh nhưng chưa giao đi</h2>';

    if (empty($filtered_orders)) {
        echo '<p>Không có đơn nào.</p>';
        return ob_get_clean();
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px;">';

    foreach ($filtered_orders as $order) {
        $order_id   = $order->get_id();
        $status     = wc_get_order_status_name($order->get_status());
        $edit_link  = get_edit_post_link($order_id);
        $confirmed  = get_post_meta($order_id, 'export_confirmed_by', true);

        echo '<div class="order-card" style="border:1px solid #ccc;border-radius:8px;padding:15px;background:#fff;">';
        echo '<h4 style="margin-top: 10px;">Đơn hàng #' . esc_html($order_id) . '</h4>';
        echo '<p>Trạng thái: <strong>' . esc_html($status) . '</strong> - ';
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">✏️ Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html(ct_get_order_item_display_name($item)) . ' × ' . esc_html($item->get_quantity()) . '</li>';
        }
        echo '</ul>';

        // Hiển thị ảnh
        $images_raw = get_post_meta($order_id, 'warehouse_export_images', true);
        $urls = array_filter(array_map('trim', explode("\n", $images_raw)));
        if (!empty($urls)) {
            echo '<div class="order-images" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">';
            foreach ($urls as $url) {
                echo '<a href="' . esc_url($url) . '" target="_blank">';
                echo '<img src="' . esc_url($url) . '" style="max-width:80px;height:auto;border:1px solid #ddd;border-radius:4px;">';
                echo '</a>';
            }
            echo '</div>';
        } else {
            echo '<em>Không có ảnh</em>';
        }

        // Nút xác nhận
        echo '<div style="margin-top:15px;">';
        if (empty($confirmed)) {
        ?>
            <form class="export-confirm-form">
                <input type="hidden" name="uexe_order_id" value="<?php echo esc_attr($order_id); ?>">
                <button type="submit" class="button" style="background:green;color:#fff;">Xác nhận xuất kho</button>
            </form>
    <?php
        } else {
            echo '<p><strong style="color:green;">✅ Đã xác nhận</strong></p>';
        }
        echo '</div>';

        echo '</div>'; // end card
    }

    echo '</div>'; // end grid

    return ob_get_clean();
}
add_shortcode('list_uploaded_not_shipped_exports', 'shortcode_list_uploaded_not_shipped_exports');



// ===== AJAX HANDLERS =====

// Upload ảnh
add_action('wp_ajax_ajax_upload_images', function () {
    check_ajax_referer('ajax_upload_images_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Không có quyền');

    $order_id = intval($_POST['ueif_order_id']);
    if (!$order_id || empty($_FILES['ueif_images'])) {
        wp_send_json_error("Thiếu dữ liệu");
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $files = $_FILES['ueif_images'];

    if (count($files['name']) > 5) {
        wp_send_json_error('❌ Chỉ được upload tối đa 5 ảnh mỗi lần. Vui lòng chọn lại.');
    }

    $uploaded_urls = [];

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                continue;
            }

            $timestamp = date('Ymd-His');
            $filename = sprintf('order-export-image-%d-%s-%d.%s', $order_id, $timestamp, $i + 1, $ext);

            $upload_file = [
                'name'     => $filename,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];

            $attach_id = hithean_upload_internal_order_image($upload_file, $order_id, $filename);
            if (!is_wp_error($attach_id)) {
                $uploaded_urls[] = wp_get_attachment_url($attach_id);
            }
        }
    }

    if ($uploaded_urls) {
        $existing = get_post_meta($order_id, 'warehouse_export_images', true);
        $all = array_filter(array_merge(explode("\n", $existing), $uploaded_urls));
        update_post_meta($order_id, 'warehouse_export_images', implode("\n", $all));
        wp_send_json_success("✅ Đã upload thành công " . count($uploaded_urls) . " ảnh");
    }
    wp_send_json_error("Không upload được ảnh");
});

// Xác nhận xuất kho
add_action('wp_ajax_ajax_confirm_export', function () {
    check_ajax_referer('ajax_confirm_export_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Không có quyền');

    $order_id = intval($_POST['uexe_order_id']);
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error("Không tìm thấy đơn");

    $user = wp_get_current_user();
    update_post_meta($order_id, 'export_confirmed_by', $user->ID);
    $order->add_order_note("✅ Đã xác nhận xuất kho bởi " . $user->display_name);
    wp_send_json_success("✅ Đã xác nhận xuất kho đơn hàng #$order_id");
});


// ===== Enqueue JS =====
add_action('wp_footer', function () {
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) return;
    ?>
    <script>
        var ueifNonce = "<?php echo esc_js(wp_create_nonce('ajax_upload_images_nonce')); ?>";
        var uexeNonce = "<?php echo esc_js(wp_create_nonce('ajax_confirm_export_nonce')); ?>";
        document.addEventListener("DOMContentLoaded", function() {
            // Upload ảnh
            const uploadForm = document.querySelector('#upload-export-form');
            if (uploadForm) {
                const imageInput = document.querySelector('#ueif_images');
                const noticeEl   = document.querySelector('#ueif-images-notice');
                const errorEl    = document.querySelector('#ueif-images-error');

                imageInput.addEventListener('change', function() {
                    if (imageInput.files.length > 5) {
                        imageInput.value = '';
                        noticeEl.style.display = 'none';
                        errorEl.style.display  = 'inline-block';
                    } else {
                        errorEl.style.display  = 'none';
                        noticeEl.style.display = 'inline-block';
                    }
                });

                uploadForm.addEventListener("submit", function(e) {
                    e.preventDefault();

                    if (imageInput.files.length > 5) {
                        errorEl.style.display  = 'inline-block';
                        noticeEl.style.display = 'none';
                        imageInput.value = '';
                        return;
                    }

                    const btn = uploadForm.querySelector('button[type="submit"]');
                    const originalText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = "⏳ Đang upload...";

                    const formData = new FormData(uploadForm);
                    formData.append("action", "ajax_upload_images");
                    formData.append("nonce", ueifNonce);

                    fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                            method: "POST",
                            body: formData
                        })
                        .then(r => r.json())
                        .then(res => {
                            alert(res.data);
                            if (res.success) {
                                uploadForm.reset();
                            }
                        })
                        .catch(() => {
                            alert("Lỗi kết nối. Vui lòng thử lại.");
                        })
                        .finally(() => {
                            btn.disabled = false;
                            btn.textContent = originalText;
                        });
                });
            }



            // Xác nhận xuất kho
            document.querySelectorAll("form.export-confirm-form").forEach(form => {
                form.addEventListener("submit", function(e) {
                    e.preventDefault();
                    const formData = new FormData(form);
                    formData.append("action", "ajax_confirm_export");
                    formData.append("nonce", uexeNonce);
                    fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                            method: "POST",
                            body: formData
                        })
                        .then(r => r.json())
                        .then(res => {
                            alert(res.data);
                            if (res.success) {
                                // form.closest(".order-card").style.display = "none";
                                // Đổi nút thành "Đã xác nhận" thay vì ẩn card
                                form.outerHTML = '<p><strong style="color:green;">✅ Đã xác nhận</strong></p>';
                            }
                        });
                });
            });
        });
    </script>
<?php
});

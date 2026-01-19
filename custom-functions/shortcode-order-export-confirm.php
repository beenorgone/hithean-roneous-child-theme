<?php
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
            <label for="ueif_images">Ảnh xuất kho (tối đa 5 ảnh):</label><br>
            <input type="file" name="ueif_images[]" id="ueif_images" multiple accept="image/*" required style="width:100%;max-width:500px;border-radius:5px;border:2px solid #ccc!important;">
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
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html($item->get_name()) . ' × ' . esc_html($item->get_quantity()) . '</li>';
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
        echo '<a href="' . esc_url($edit_link) . '" target="_blank">Chỉnh sửa đơn</a></p>';

        echo '<ul style="margin:0;padding-left:18px;">';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html($item->get_name()) . ' × ' . esc_html($item->get_quantity()) . '</li>';
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
    $order_id = intval($_POST['ueif_order_id']);
    if (!$order_id || empty($_FILES['ueif_images'])) {
        wp_send_json_error("Thiếu dữ liệu");
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $files = $_FILES['ueif_images'];
    $uploaded_urls = [];

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                continue;
            }

            $timestamp = date('Ymd-His');
            $filename = sprintf('order-export-image-%d-%s-%d.%s', $order_id, $timestamp, $i + 1, $ext);

            $tmp_path = $files['tmp_name'][$i];

            // ✅ Resize ảnh nếu lớn hơn 1000px
            $image_info = getimagesize($tmp_path);
            $width = $image_info[0];
            $height = $image_info[1];

            if ($width > 1000 || $height > 1000) {
                // Load ảnh theo kiểu
                switch ($image_info['mime']) {
                    case 'image/jpeg':
                        $src = imagecreatefromjpeg($tmp_path);
                        break;
                    case 'image/png':
                        $src = imagecreatefrompng($tmp_path);
                        break;
                    default:
                        continue 2;
                }

                // Tính kích thước mới
                if ($width >= $height) {
                    $new_width = 1000;
                    $new_height = intval($height * (1000 / $width));
                } else {
                    $new_height = 1000;
                    $new_width = intval($width * (1000 / $height));
                }

                $dst = imagecreatetruecolor($new_width, $new_height);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                // Ghi đè tạm file đã resize
                switch ($image_info['mime']) {
                    case 'image/jpeg':
                        imagejpeg($dst, $tmp_path, 85); // chất lượng 85%
                        break;
                    case 'image/png':
                        imagepng($dst, $tmp_path, 6);
                        break;
                }

                imagedestroy($src);
                imagedestroy($dst);
            }

            // Gán tên mới
            $_FILES['upload_file'] = [
                'name'     => $filename,
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];

            $attach_id = media_handle_upload('upload_file', $order_id);
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
        document.addEventListener("DOMContentLoaded", function() {
            // Upload ảnh
            const uploadForm = document.querySelector('#upload-export-form');
            if (uploadForm) {
                uploadForm.addEventListener("submit", function(e) {
                    e.preventDefault();

                    const btn = uploadForm.querySelector('button[type="submit"]');
                    const originalText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = "⏳ Đang upload...";

                    const formData = new FormData(uploadForm);
                    formData.append("action", "ajax_upload_images");

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

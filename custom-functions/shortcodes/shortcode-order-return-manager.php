<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('hithean_upload_internal_order_image')) {
    /**
     * Store internal order evidence images without generating every registered
     * theme/WooCommerce thumbnail size. These images are admin proof only.
     * (Bản copy có guard để module return-manager tự chạy độc lập khi module
     * order-export-confirm không được nạp — cùng định nghĩa nên không trùng.)
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

/**
 * Shortcode: [order_return_management]
 * Quản lý đơn hoàn hàng — 2 bảng riêng biệt (chưa trả / đã trả).
 *
 * Query dùng wc_get_orders() (storage-agnostic: chạy đúng cả HPOS lẫn legacy).
 * Meta đọc/ghi qua WC_Order để tương thích HPOS.
 */

add_shortcode('order_return_management', function () {
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start(); ?>
    <div class="order-return-list">
        <h2>Quản lý đơn hoàn hàng</h2>

        <div style="display:flex; gap:10px; margin-bottom:15px;">
            <button id="load_pending_btn" class="button--green">Tải đơn chưa trả</button>
            <button id="load_completed_btn" class="button--white">Tải đơn đã trả</button>
        </div>

        <div id="return_orders_container">
            <div id="pending_orders_wrap" style="margin-top:20px;">
                <p class="loading-msg" id="loading_pending" style="display:none;">⏳ Đang tải đơn chưa trả...</p>
                <div class="orders-table-wrap scroll-container"></div>
            </div>

            <div id="completed_orders_wrap" style="margin-top:40px;">
                <p class="loading-msg" id="loading_completed" style="display:none;">⏳ Đang tải đơn đã trả...</p>
                <div class="orders-table-wrap scroll-container"></div>
            </div>
        </div>
    </div>

    <style>
        .orders-table-wrap table tr th {
            font-weight: 900;
            text-align: center;
        }

        .return-confirm-form {
            display: flex;
            flex-direction: column;
            gap: 30px;
            width: fit-content
        }

        .return-form-row td {
            padding: 20px !important;
            background: #fafafa;
        }

        .return-images-preview img {
            max-width: 80px;
            height: auto;
            margin-right: 6px;
            border-radius: 6px;
            border: 1px solid #ddd;
        }

        /* Stripe table rows cho cả 2 bảng */
        .widefat.striped tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        .widefat.striped tbody tr:nth-child(even) {
            background-color: #f7f7f7;
        }

        .widefat.striped tbody tr:hover {
            background-color: #eef6ee;
        }
    </style>

    <script>
        var ormLoadNonce = "<?php echo esc_js(wp_create_nonce('load_return_orders_nonce')); ?>";
        var ormUploadNonce = "<?php echo esc_js(wp_create_nonce('upload_return_images_nonce')); ?>";
        jQuery(function($) {
            if (typeof ajaxurl === 'undefined') {
                var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            }

            function loadOrders(type) {
                const wrap = (type === 'pending') ? $('#pending_orders_wrap') : $('#completed_orders_wrap');
                wrap.find('.loading-msg').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'load_return_orders',
                        list_type: type,
                        nonce: ormLoadNonce
                    },
                    success: function(resp) {
                        wrap.find('.loading-msg').hide();
                        if (resp.success) {
                            wrap.find('.orders-table-wrap').html(resp.data.html);
                        } else {
                            wrap.find('.orders-table-wrap').html('<p>❌ ' + resp.data + '</p>');
                        }
                    },
                    error: function() {
                        wrap.find('.loading-msg').hide();
                        wrap.find('.orders-table-wrap').html('<p>❌ Lỗi tải dữ liệu.</p>');
                    }
                });
            }

            $('#load_pending_btn').on('click', function() {
                loadOrders('pending');
            });
            $('#load_completed_btn').on('click', function() {
                loadOrders('completed');
            });

            // Mở form upload ảnh
            $(document).on('click', '.confirm-return-btn', function() {
                const tr = $(this).closest('tr');
                const orderId = tr.data('order-id');
                if (tr.next().hasClass('return-form-row')) {
                    tr.next().remove();
                    return;
                }

                const formHtml = `
            <tr class="return-form-row">
                <td colspan="9">
                    <form class="return-confirm-form" data-order-id="${orderId}">
                        <p><strong>Upload ảnh trả hàng:</strong><br>
                        <input type="file" name="return_images[]" multiple accept="image/*"></p>
                        <p>
                            <label><input type="radio" name="has_issue" value="0" checked> Không sự cố</label>
                            <label style="margin-left:10px;"><input type="radio" name="has_issue" value="1"> Có sự cố</label>
                        </p>
                        <button type="submit" class="button-green">Up ảnh và xác nhận</button>
                        <div class="upload-status" style="margin-top:5px;color:#0073aa;"></div>
                    </form>
                </td>
            </tr>`;
                tr.after(formHtml);
            });

            // Upload ảnh + cập nhật trạng thái, không reload
            $(document).on('submit', '.return-confirm-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const tr = $('tr[data-order-id="' + orderId + '"]');
                const files = form.find('input[type=file]')[0].files;
                const hasIssue = form.find('input[name=has_issue]:checked').val();

                if (!files.length) {
                    alert('Vui lòng chọn ít nhất 1 ảnh.');
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'upload_return_images');
                fd.append('order_id', orderId);
                fd.append('has_issue', hasIssue);
                fd.append('nonce', ormUploadNonce);
                for (let i = 0; i < files.length; i++) fd.append('images[]', files[i]);

                form.find('.upload-status').text('⏳ Đang upload...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            // ✅ Cập nhật UI tại chỗ, không reload
                            const statusText = (hasIssue == "1") ?
                                '✅ Đã xác nhận hàng hoàn (Có sự cố)' :
                                '✅ Đã xác nhận hàng hoàn (Không sự cố)';
                            form.find('.upload-status').text('✅ Hoàn tất.');
                            // Thay nút bằng thông báo
                            tr.find('.confirm-return-btn')
                                .replaceWith('<span class="status-done" style="color:#16a085;font-weight:600;">' + statusText + '</span>');
                            // Xóa form sau 1s
                            setTimeout(() => form.closest('tr.return-form-row').fadeOut(300, function() {
                                $(this).remove();
                            }), 800);
                        } else {
                            form.find('.upload-status').text('❌ ' + resp.data);
                        }
                    },
                    error: function() {
                        form.find('.upload-status').text('❌ Lỗi hệ thống.');
                    }
                });
            });
        });
    </script>

<?php
    return ob_get_clean();
});


/**
 * AJAX: Load danh sách đơn hoàn hàng
 */
add_action('wp_ajax_load_return_orders', function () {
    check_ajax_referer('load_return_orders_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Không có quyền truy cập');
    }

    $type = sanitize_text_field($_POST['list_type'] ?? 'pending');
    $cache_key = 'return_orders_' . $type . '_v4';

    $cached = wp_cache_get($cache_key, 'orders');
    if ($cached !== false) {
        wp_send_json_success(['html' => $cached]);
    }

    // wc_get_orders() → storage-agnostic (HPOS + legacy).
    if ($type === 'completed') {
        $query_args = [
            'limit'    => 20,
            'orderby'  => 'ID',
            'order'    => 'DESC',
            'return'   => 'ids',
            'status'   => array_keys(wc_get_order_statuses()),
            'meta_query' => [
                ['key' => 'return_status', 'value' => 'Đã nhận', 'compare' => 'LIKE'],
            ],
        ];
    } else {
        $query_args = [
            'limit'    => 100,
            'orderby'  => 'ID',
            'order'    => 'DESC',
            'return'   => 'ids',
            'status'   => array_keys(wc_get_order_statuses()),
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'return_status', 'value' => '', 'compare' => '!='],
                ['key' => 'return_status', 'value' => 'Đã nhận', 'compare' => 'NOT LIKE'],
                ['key' => 'return_status', 'value' => 'Hủy yêu cầu', 'compare' => 'NOT LIKE'],
            ],
        ];
    }

    $order_ids = wc_get_orders($query_args);
    if (empty($order_ids)) wp_send_json_error('Không có đơn nào.');

    ob_start(); ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Trạng thái</th>
                <th>SĐT</th>
                <th>Tên người đặt</th>
                <th>Sản phẩm</th>
                <th>Mã hoàn</th>
                <th>Trạng thái hoàn</th>
                <?php if ($type === 'pending'): ?>
                    <th>Thao tác</th>
                <?php else: ?>
                    <th>Ảnh trả hàng</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order_ids as $oid):
                $order = wc_get_order($oid);
                if (!$order) continue;

                $edit_link = esc_url($order->get_edit_order_url());
                $return_status = $order->get_meta('return_status');
                $return_code   = $order->get_meta('return_code');
                $return_images = $order->get_meta('issue_result_images');
                $billing_phone = $order->get_billing_phone();
                $billing_name  = $order->get_formatted_billing_full_name();

                $items_html = '';
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    $variation = ($product && $product->is_type('variation'))
                        ? ' (' . wc_get_formatted_variation($product, true, false, false) . ')'
                        : '';
                    $items_html .= esc_html($item->get_name() . $variation . ' x ' . $item->get_quantity()) . '<br>';
                }
            ?>
                <tr data-order-id="<?= esc_attr($oid) ?>">
                    <td>
                        <a href="<?= $edit_link ?>" target="_blank" style="text-decoration:none;color:#0073aa;">
                            #<?= esc_html($oid) ?>
                        </a>
                    </td>
                    <td><?= esc_html($order->get_status()) ?></td>
                    <td><?= esc_html($billing_phone) ?></td>
                    <td><?= esc_html($billing_name) ?></td>
                    <td><?= $items_html ?></td>
                    <td><?= esc_html($return_code) ?></td>
                    <td><?= esc_html($return_status) ?></td>
                    <?php if ($type === 'pending'): ?>
                        <td>
                            <button class="button-green button-small confirm-return-btn">
                                Đã nhận hàng hoàn
                            </button>
                        </td>
                    <?php else: ?>
                        <td class="return-images-preview">
                            <?php
                            if ($return_images) {
                                $urls = array_filter(array_map('trim', explode("\n", $return_images)));
                                foreach ($urls as $url) {
                                    echo '<a href="' . esc_url($url) . '" target="_blank">
                                            <img src="' . esc_url($url) . '" alt="">
                                          </a>';
                                }
                            }
                            ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php
    $html = ob_get_clean();

    wp_cache_set($cache_key, $html, 'orders', 300);
    wp_send_json_success(['html' => $html]);
});


/**
 * AJAX: Upload ảnh + xác nhận hoàn hàng
 */
add_action('wp_ajax_upload_return_images', function () {
    check_ajax_referer('upload_return_images_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Không có quyền');

    $order_id = intval($_POST['order_id'] ?? 0);
    $has_issue = intval($_POST['has_issue'] ?? 0);
    if (!$order_id) wp_send_json_error('Thiếu ID đơn');

    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error('Không tìm thấy đơn');

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $uploaded_urls = [];
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['name'] as $i => $name) {
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $filename = sprintf('order-return-image-%d-%s-%d.%s', $order_id, date('Ymd-His'), $i + 1, $ext);
            $file = [
                'name' => $filename,
                'type' => $_FILES['images']['type'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error' => $_FILES['images']['error'][$i],
                'size' => $_FILES['images']['size'][$i],
            ];
            $upload_id = hithean_upload_internal_order_image($file, $order_id, $filename);
            if (!is_wp_error($upload_id)) {
                $uploaded_urls[] = wp_get_attachment_url($upload_id);
            }
        }
    }

    $old_val = (string) $order->get_meta('issue_result_images');
    $new_val = trim($old_val . "\n" . implode("\n", $uploaded_urls));
    $order->update_meta_data('issue_result_images', $new_val);

    $new_status = $has_issue ? 'Đã nhận hàng hoàn (Có sự cố)' : 'Đã nhận hàng hoàn (Không sự cố)';
    $order->update_meta_data('return_status', $new_status);
    $order->save();

    wp_cache_delete('return_orders_pending_v4', 'orders');
    wp_cache_delete('return_orders_completed_v4', 'orders');

    wp_send_json_success(['urls' => $uploaded_urls]);
});

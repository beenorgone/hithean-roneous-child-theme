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

        <div class="return-attach-box">
            <h3 style="margin-top:0;">➕ Gắn đơn phát sinh trả hàng</h3>
            <p style="margin:0 0 8px;">Tìm đơn theo <strong>SĐT, mã đơn hoặc email</strong> (nhiều giá trị cách nhau bởi dấu cách/phẩy):</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="text" id="return_search_input" placeholder="VD: 0912345678, P0123, email@abc.com" style="min-width:300px; padding:6px 10px;">
                <button id="return_search_btn" class="button--green">Tìm đơn</button>
            </div>
            <p class="return-search-msg" style="margin:8px 0 0; color:#0073aa;"></p>
            <div id="return_search_results" style="margin-top:12px;"></div>
        </div>

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

        .return-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .widefat.striped tbody tr.return-active-row td {
            background-color: #fff7d6 !important;
            box-shadow: inset 4px 0 0 #f2b705;
        }

        .widefat.striped tbody tr.return-busy-row td {
            background-color: #e8f5ff !important;
            box-shadow: inset 4px 0 0 #0073aa;
        }

        .return-images-preview img {
            max-width: 80px;
            height: auto;
            margin-right: 6px;
            border-radius: 6px;
            border: 1px solid #ddd;
        }

        .return-attach-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background: #fbfbfb;
        }

        .attach-return-form select,
        .attach-return-form input[type="text"] {
            padding: 4px 8px;
        }

        .attach-return-form input[name="return_code"] {
            margin-left: 6px;
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
        var ormLookupNonce = "<?php echo esc_js(wp_create_nonce('return_lookup_order_nonce')); ?>";
        var ormAttachNonce = "<?php echo esc_js(wp_create_nonce('attach_return_order_nonce')); ?>";
        var ormProcessNonce = "<?php echo esc_js(wp_create_nonce('process_return_order_nonce')); ?>";
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

            // ===== Gắn đơn phát sinh trả hàng: tìm đơn theo SĐT / mã đơn / email =====
            function runReturnSearch() {
                const q = $('#return_search_input').val().trim();
                const msg = $('.return-search-msg');
                const box = $('#return_search_results');
                if (!q) {
                    msg.text('Vui lòng nhập SĐT, mã đơn hoặc email.');
                    return;
                }
                msg.text('⏳ Đang tìm...');
                box.empty();
                $.post(ajaxurl, {
                    action: 'return_lookup_order',
                    search: q,
                    nonce: ormLookupNonce
                }, function(resp) {
                    if (resp.success) {
                        msg.text(resp.data.message || '');
                        box.html(resp.data.html);
                    } else {
                        msg.text('❌ ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                    }
                }).fail(function() {
                    msg.text('❌ Lỗi tìm kiếm.');
                });
            }
            $('#return_search_btn').on('click', runReturnSearch);
            $('#return_search_input').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    runReturnSearch();
                }
            });

            // Gắn trả hàng cho 1 đơn (kèm mã vận đơn hoàn + ảnh sự cố phát sinh)
            $(document).on('submit', '.attach-return-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const status = form.find('select[name=return_status]').val();
                const st = form.find('.attach-status');
                if (!status) {
                    st.text('Vui lòng chọn trạng thái hoàn.');
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'attach_return_order');
                fd.append('order_id', orderId);
                fd.append('return_status', status);
                fd.append('return_code', form.find('input[name=return_code]').val());
                fd.append('return_note', form.find('textarea[name=return_note]').val());
                fd.append('nonce', ormAttachNonce);
                const files = form.find('input[type=file]')[0].files;
                for (let i = 0; i < files.length; i++) fd.append('issue_images[]', files[i]);

                st.text('⏳ Đang gắn...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            form.replaceWith('<span style="color:#16a085;font-weight:600;">✅ Đã gắn trả hàng: ' + resp.data.status + '</span>');
                            loadOrders('pending'); // làm mới bảng chưa trả
                        } else {
                            st.text('❌ ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                        }
                    },
                    error: function() {
                        st.text('❌ Lỗi hệ thống.');
                    }
                });
            });

            // Chuyển trạng thái HOÀN HÀNG từ "Cần..." sang "Chờ hoàn..."
            $(document).on('click', '.process-return-btn', function() {
                const btn = $(this);
                const tr = btn.closest('tr');
                const orderId = tr.data('order-id');
                const nextStatus = btn.data('next-status');
                if (tr.next().hasClass('return-form-row')) {
                    tr.next().remove();
                    tr.removeClass('return-active-row return-busy-row');
                    return;
                }
                $('.return-active-row, .return-busy-row').removeClass('return-active-row return-busy-row');
                $('.return-form-row').remove();
                tr.addClass('return-active-row');

                const formHtml = `
            <tr class="return-form-row">
                <td colspan="9">
                    <form class="return-process-form" data-order-id="${orderId}">
                        <p style="margin-top:0;"><strong>Chuyển HOÀN HÀNG sang:</strong> ${nextStatus}</p>
                        <p><label>Mã vận đơn hoàn hàng:<br>
                        <input type="text" name="return_code" style="min-width:360px;width:100%;" placeholder="Nhập mã vận đơn hoàn hàng"></label></p>
                        <p><label>Ghi chú xử lý (tuỳ chọn):<br>
                        <textarea name="return_note" rows="3" style="min-width:360px;width:100%;" placeholder="Ghi chú nội bộ lưu vào đơn hàng"></textarea></label></p>
                        <button type="submit" class="button-green">Xác nhận đã xử lý</button>
                        <div class="process-status" style="margin-top:5px;color:#0073aa;"></div>
                    </form>
                </td>
            </tr>`;
                tr.after(formHtml);
            });

            $(document).on('submit', '.return-process-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const tr = $('tr[data-order-id="' + orderId + '"]');
                tr.removeClass('return-active-row').addClass('return-busy-row');
                const fd = new FormData();
                fd.append('action', 'process_return_order');
                fd.append('order_id', orderId);
                fd.append('return_code', form.find('input[name=return_code]').val());
                fd.append('return_note', form.find('textarea[name=return_note]').val());
                fd.append('nonce', ormProcessNonce);

                form.find('.process-status').text('⏳ Đang chuyển trạng thái...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            tr.find('.return-status-cell').text(resp.data.status);
                            tr.find('.process-return-btn').replaceWith('<span class="status-done" style="color:#16a085;font-weight:600;">Đã xử lý</span>');
                            form.find('.process-status').text('✅ Đã chuyển sang ' + resp.data.status + '.');
                            setTimeout(() => form.closest('tr.return-form-row').fadeOut(300, function() {
                                $(this).remove();
                                tr.removeClass('return-busy-row');
                            }), 800);
                        } else {
                            tr.removeClass('return-busy-row').addClass('return-active-row');
                            form.find('.process-status').text('❌ ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                        }
                    },
                    error: function() {
                        tr.removeClass('return-busy-row').addClass('return-active-row');
                        form.find('.process-status').text('❌ Lỗi hệ thống.');
                    }
                });
            });

            // Mở form upload ảnh
            $(document).on('click', '.confirm-return-btn', function() {
                const tr = $(this).closest('tr');
                const orderId = tr.data('order-id');
                if (tr.next().hasClass('return-form-row')) {
                    tr.next().remove();
                    tr.removeClass('return-active-row return-busy-row');
                    return;
                }
                $('.return-active-row, .return-busy-row').removeClass('return-active-row return-busy-row');
                $('.return-form-row').remove();
                tr.addClass('return-active-row');

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

                tr.removeClass('return-active-row').addClass('return-busy-row');
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
                                tr.removeClass('return-busy-row');
                            }), 800);
                        } else {
                            tr.removeClass('return-busy-row').addClass('return-active-row');
                            form.find('.upload-status').text('❌ ' + resp.data);
                        }
                    },
                    error: function() {
                        tr.removeClass('return-busy-row').addClass('return-active-row');
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
 * Có bật HPOS (custom order tables) không?
 * Quyết định query bảng meta nào: wc_orders_meta (HPOS) hay postmeta (legacy).
 */
function hithean_return_hpos_enabled(): bool
{
    if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
    return false;
}

/**
 * Lấy danh sách order ID theo return_status.
 * Query trực tiếp bảng meta đúng theo storage — KHÔNG dùng wc_get_orders()+meta_query
 * vì meta_query bị data store bỏ qua ở một số phiên bản → trả về tất cả đơn.
 *
 * @param string $type 'pending' | 'completed'
 * @return int[]
 */
function hithean_return_get_order_ids(string $type): array
{
    global $wpdb;
    $meta_key = 'return_status';

    if (hithean_return_hpos_enabled()) {
        $orders_table = "{$wpdb->prefix}wc_orders";
        $meta_table   = "{$wpdb->prefix}wc_orders_meta";

        if ($type === 'completed') {
            $sql = $wpdb->prepare("
                SELECT DISTINCT o.id
                FROM {$orders_table} o
                INNER JOIN {$meta_table} m ON o.id = m.order_id
                WHERE o.type = 'shop_order'
                  AND o.status <> 'trash'
                  AND m.meta_key = %s
                  AND m.meta_value <> ''
                  AND m.meta_value LIKE %s
                ORDER BY o.id DESC
                LIMIT 20
            ", $meta_key, '%Đã nhận%');
        } else {
            $sql = $wpdb->prepare("
                SELECT DISTINCT o.id
                FROM {$orders_table} o
                INNER JOIN {$meta_table} m ON o.id = m.order_id
                WHERE o.type = 'shop_order'
                  AND o.status <> 'trash'
                  AND m.meta_key = %s
                  AND m.meta_value <> ''
                  AND m.meta_value NOT LIKE %s
                  AND m.meta_value NOT LIKE %s
                ORDER BY o.id DESC
                LIMIT 100
            ", $meta_key, '%Đã nhận%', '%Hủy yêu cầu%');
        }
    } else {
        if ($type === 'completed') {
            $sql = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                  AND p.post_status NOT IN ('trash')
                  AND pm.meta_key = %s
                  AND pm.meta_value <> ''
                  AND pm.meta_value LIKE %s
                ORDER BY p.ID DESC
                LIMIT 20
            ", $meta_key, '%Đã nhận%');
        } else {
            $sql = $wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                  AND p.post_status NOT IN ('trash')
                  AND pm.meta_key = %s
                  AND pm.meta_value <> ''
                  AND pm.meta_value NOT LIKE %s
                  AND pm.meta_value NOT LIKE %s
                ORDER BY p.ID DESC
                LIMIT 100
            ", $meta_key, '%Đã nhận%', '%Hủy yêu cầu%');
        }
    }

    return array_map('intval', $wpdb->get_col($sql));
}

/**
 * Danh sách trạng thái hoàn "cần xử lý" — dùng cho form gắn đơn phát sinh.
 */
function hithean_return_pending_statuses(): array
{
    return [
        'Cần đổi trả',
        'Cần thu hồi',
        'Cần giao lại',
        'Chờ hoàn (Hủy)',
        'Chờ hoàn (Không giao được)',
        'Chờ hoàn (Đổi trả)',
        'Chờ hoàn (Thu hồi)',
        'Chờ hoàn (Giao 1 phần)',
    ];
}

function hithean_return_next_status(string $status): string
{
    $map = [
        'Cần đổi trả' => 'Chờ hoàn (Đổi trả)',
        'Cần thu hồi' => 'Chờ hoàn (Thu hồi)',
        'Cần giao lại' => 'Chờ hoàn (Giao 1 phần)',
    ];

    return $map[trim($status)] ?? '';
}

/* ---- Resolver mã đơn (mô phỏng bank-transfer: bỏ tiền tố P0/P1, thử ID và ID bỏ 2 số cuối) ---- */
function hithean_return_normalize_code($token): string
{
    $code = strtoupper(trim((string) $token));
    $code = str_replace('#', '', $code);
    if (strpos($code, 'P0') === 0 || strpos($code, 'P1') === 0) {
        $code = substr($code, 2);
    }
    return preg_replace('/\D+/', '', $code);
}

function hithean_return_resolve_code($token)
{
    $normalized = hithean_return_normalize_code($token);
    if ($normalized === '') return null;

    $attempts = [$normalized];
    if (strlen($normalized) > 2) {
        $attempts[] = substr($normalized, 0, -2);
    }

    foreach ($attempts as $candidate) {
        if ($candidate === '' || !is_numeric($candidate)) continue;
        $order = wc_get_order((int) $candidate);
        if ($order && $order->get_type() === 'shop_order') return $order;
    }
    return null;
}

/* ---- Tìm theo SĐT (storage-aware) ---- */
function hithean_return_phone_core($phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strpos($digits, '84') === 0) $digits = substr($digits, 2);
    return ltrim($digits, '0');
}

function hithean_return_find_ids_by_phone($phone): array
{
    global $wpdb;
    $core = hithean_return_phone_core($phone);
    if (strlen($core) < 8) return [];
    $like = '%' . $wpdb->esc_like($core) . '%';

    if (hithean_return_hpos_enabled()) {
        $sql = $wpdb->prepare("
            SELECT DISTINCT o.id
            FROM {$wpdb->prefix}wc_orders o
            INNER JOIN {$wpdb->prefix}wc_order_addresses a ON o.id = a.order_id AND a.address_type = 'billing'
            WHERE o.type = 'shop_order' AND o.status <> 'trash' AND a.phone LIKE %s
            ORDER BY o.id DESC LIMIT 30
        ", $like);
    } else {
        $sql = $wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash')
              AND pm.meta_key = '_billing_phone' AND pm.meta_value LIKE %s
            ORDER BY p.ID DESC LIMIT 30
        ", $like);
    }
    return array_map('intval', $wpdb->get_col($sql));
}

/* ---- Tìm theo email (storage-aware) ---- */
function hithean_return_find_ids_by_email($email): array
{
    global $wpdb;
    $email = sanitize_email((string) $email);
    if (!$email) return [];
    $like = '%' . $wpdb->esc_like($email) . '%';

    if (hithean_return_hpos_enabled()) {
        $sql = $wpdb->prepare("
            SELECT DISTINCT id FROM {$wpdb->prefix}wc_orders
            WHERE type = 'shop_order' AND status <> 'trash' AND billing_email LIKE %s
            ORDER BY id DESC LIMIT 30
        ", $like);
    } else {
        $sql = $wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ('trash')
              AND pm.meta_key = '_billing_email' AND pm.meta_value LIKE %s
            ORDER BY p.ID DESC LIMIT 30
        ", $like);
    }
    return array_map('intval', $wpdb->get_col($sql));
}

/**
 * Tìm đơn hợp nhất: tách token, dispatch theo loại (email / SĐT / mã đơn).
 * @return WC_Order[]
 */
function hithean_return_search_orders($raw): array
{
    $parts = preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) return [];

    $seen = [];
    $orders = [];
    $push = function ($oid) use (&$seen, &$orders) {
        $oid = (int) $oid;
        if (!$oid || isset($seen[$oid])) return;
        $order = wc_get_order($oid);
        if ($order && $order->get_type() === 'shop_order') {
            $seen[$oid] = true;
            $orders[] = $order;
        }
    };

    foreach ($parts as $token) {
        if (strpos($token, '@') !== false) {
            foreach (hithean_return_find_ids_by_email($token) as $id) $push($id);
            continue;
        }
        $digits = preg_replace('/\D+/', '', $token);
        if (strlen($digits) >= 9) {
            foreach (hithean_return_find_ids_by_phone($token) as $id) $push($id);
            continue;
        }
        $order = hithean_return_resolve_code($token);
        if ($order) $push($order->get_id());
    }

    return $orders;
}

/**
 * Render bảng kết quả tìm đơn — mỗi đơn chưa có return_status thì hiện form gắn
 * (chọn trạng thái + mã vận đơn hoàn + upload ảnh sự cố). Đơn đã có thì chặn (chống ghi đè).
 */
function hithean_return_render_search_results(array $orders): string
{
    $statuses = hithean_return_pending_statuses();
    ob_start(); ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Mã đơn</th>
                <th>Khách hàng</th>
                <th>Ngày</th>
                <th>Trạng thái đơn</th>
                <th>Sản phẩm</th>
                <th>Gắn trả hàng</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order):
                $oid = $order->get_id();
                $rs  = trim((string) $order->get_meta('return_status'));
                $items = '';
                foreach ($order->get_items() as $item) {
                    $items .= esc_html($item->get_name() . ' x ' . $item->get_quantity()) . '<br>';
                }
            ?>
                <tr>
                    <td>
                        <a href="<?= esc_url($order->get_edit_order_url()) ?>" target="_blank" style="color:#0073aa;">#<?= esc_html($oid) ?></a>
                    </td>
                    <td>
                        <?= esc_html($order->get_formatted_billing_full_name()) ?>
                        <br><small><?= esc_html($order->get_billing_phone()) ?></small>
                        <?php if ($order->get_billing_email()): ?><br><small><?= esc_html($order->get_billing_email()) ?></small><?php endif; ?>
                    </td>
                    <td><?= esc_html(wc_format_datetime($order->get_date_created())) ?></td>
                    <td><?= esc_html(wc_get_order_status_name($order->get_status())) ?></td>
                    <td><?= $items ?></td>
                    <td>
                        <?php if ($rs !== ''): ?>
                            <span style="color:#888;">Đã có: <strong><?= esc_html($rs) ?></strong><br>(không thể ghi đè)</span>
                        <?php else: ?>
                            <form class="attach-return-form" data-order-id="<?= esc_attr($oid) ?>" enctype="multipart/form-data">
                                <div>
                                    <select name="return_status">
                                        <option value="">— Chọn trạng thái hoàn —</option>
                                        <?php foreach ($statuses as $s): ?>
                                            <option value="<?= esc_attr($s) ?>"><?= esc_html($s) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="return_code" placeholder="Mã vận đơn hoàn hàng (tuỳ chọn)">
                                </div>
                                <div style="margin-top:6px;">
                                    <label>Ảnh sự cố phát sinh (tuỳ chọn):<br>
                                        <input type="file" name="issue_images[]" multiple accept="image/*"></label>
                                </div>
                                <div style="margin-top:6px;">
                                    <label>Ghi chú đơn (tuỳ chọn):<br>
                                        <textarea name="return_note" rows="3" style="min-width:300px;width:100%;" placeholder="Ghi chú nội bộ lưu vào đơn hàng"></textarea></label>
                                </div>
                                <div style="margin-top:6px;">
                                    <button type="submit" class="button-green button-small">Gắn trả hàng</button>
                                </div>
                                <div class="attach-status" style="color:#0073aa;margin-top:4px;"></div>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php
    return ob_get_clean();
}

/**
 * AJAX: Load danh sách đơn hoàn hàng
 */
add_action('wp_ajax_load_return_orders', function () {
    check_ajax_referer('load_return_orders_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Không có quyền truy cập');
    }

    $type = sanitize_text_field($_POST['list_type'] ?? 'pending');
    $type = ($type === 'completed') ? 'completed' : 'pending';
    $cache_key = 'return_orders_' . $type . '_v7';

    $cached = wp_cache_get($cache_key, 'orders');
    if ($cached !== false) {
        wp_send_json_success(['html' => $cached]);
    }

    $order_ids = hithean_return_get_order_ids($type);
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
                $next_status   = hithean_return_next_status($return_status);
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
                    <td class="return-status-cell"><?= esc_html($return_status) ?></td>
                    <?php if ($type === 'pending'): ?>
                        <td>
                            <div class="return-actions">
                            <?php if ($next_status !== ''): ?>
                                <button class="button-white button-small process-return-btn" data-next-status="<?= esc_attr($next_status) ?>">
                                    Đã xử lý
                                </button>
                            <?php endif; ?>
                            <button class="button-green button-small confirm-return-btn">
                                Đã nhận hàng hoàn
                            </button>
                            </div>
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

    wp_cache_delete('return_orders_pending_v7', 'orders');
    wp_cache_delete('return_orders_completed_v7', 'orders');

    wp_send_json_success(['urls' => $uploaded_urls]);
});


/**
 * AJAX: Đánh dấu đã xử lý yêu cầu hoàn hàng và chuyển sang trạng thái chờ hoàn tương ứng.
 */
add_action('wp_ajax_process_return_order', function () {
    check_ajax_referer('process_return_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền'], 403);
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    $code = sanitize_text_field(wp_unslash($_POST['return_code'] ?? ''));
    $note = sanitize_textarea_field(wp_unslash($_POST['return_note'] ?? ''));
    if (!$order_id) {
        wp_send_json_error(['message' => 'Thiếu ID đơn']);
    }

    $order = wc_get_order($order_id);
    if (!$order || $order->get_type() !== 'shop_order') {
        wp_send_json_error(['message' => 'Không tìm thấy đơn']);
    }

    $current_status = trim((string) $order->get_meta('return_status'));
    $next_status = hithean_return_next_status($current_status);
    if ($next_status === '') {
        wp_send_json_error(['message' => 'Trạng thái hiện tại không có bước xử lý tiếp theo']);
    }

    $order->update_meta_data('return_status', $next_status);
    $order_note = sprintf('HOÀN HÀNG đã xử lý: %s → %s', $current_status, $next_status);
    if ($code !== '') {
        $existing_code = trim((string) $order->get_meta('return_code'));
        $existing_codes = array_map('trim', explode(',', $existing_code));
        if ($existing_code === '') {
            $order->update_meta_data('return_code', $code);
        } elseif (!in_array($code, $existing_codes, true)) {
            $order->update_meta_data('return_code', $existing_code . ', ' . $code);
        }
        $order_note .= "\nMã vận đơn hoàn hàng: " . $code;
    }
    if ($note !== '') {
        $order_note .= "\nGhi chú: " . $note;
    }
    $order->add_order_note($order_note, false, true);
    $order->save();

    wp_cache_delete('return_orders_pending_v7', 'orders');
    wp_cache_delete('return_orders_completed_v7', 'orders');

    wp_send_json_success(['status' => $next_status]);
});


/**
 * AJAX: Tìm đơn để gắn trả hàng (theo SĐT / mã đơn / email)
 */
add_action('wp_ajax_return_lookup_order', function () {
    check_ajax_referer('return_lookup_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền truy cập'], 403);
    }

    $search = sanitize_text_field($_POST['search'] ?? '');
    if ($search === '') {
        wp_send_json_error(['message' => 'Vui lòng nhập SĐT, mã đơn hoặc email.']);
    }

    $orders = hithean_return_search_orders($search);
    if (empty($orders)) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn phù hợp.']);
    }

    wp_send_json_success([
        'html'    => hithean_return_render_search_results($orders),
        'message' => sprintf('Tìm thấy %d đơn.', count($orders)),
    ]);
});


/**
 * AJAX: Gắn 1 đơn phát sinh trả hàng (chặn ghi đè nếu đã có return_status).
 * Kèm upload ảnh sự cố phát sinh (issue_report_images) + mã vận đơn hoàn (return_code).
 */
add_action('wp_ajax_attach_return_order', function () {
    check_ajax_referer('attach_return_order_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền'], 403);
    }

    $order_id = intval($_POST['order_id'] ?? 0);
    $status   = sanitize_text_field($_POST['return_status'] ?? '');
    $code     = sanitize_text_field($_POST['return_code'] ?? '');
    $note     = sanitize_textarea_field(wp_unslash($_POST['return_note'] ?? ''));

    if (!$order_id) wp_send_json_error(['message' => 'Thiếu ID đơn']);
    if (!in_array($status, hithean_return_pending_statuses(), true)) {
        wp_send_json_error(['message' => 'Trạng thái hoàn không hợp lệ']);
    }

    $order = wc_get_order($order_id);
    if (!$order || $order->get_type() !== 'shop_order') {
        wp_send_json_error(['message' => 'Không tìm thấy đơn']);
    }

    // Chặn ghi đè.
    $existing = trim((string) $order->get_meta('return_status'));
    if ($existing !== '') {
        wp_send_json_error(['message' => 'Đơn #' . $order_id . ' đã có trạng thái hoàn: ' . $existing . ' — không thể ghi đè.']);
    }

    // Upload ảnh sự cố phát sinh (tuỳ chọn).
    $uploaded_urls = [];
    if (!empty($_FILES['issue_images']['name'][0])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($_FILES['issue_images']['name'] as $i => $name) {
            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $filename = sprintf('order-return-issue-%d-%s-%d.%s', $order_id, date('Ymd-His'), $i + 1, $ext);
            $file = [
                'name' => $filename,
                'type' => $_FILES['issue_images']['type'][$i],
                'tmp_name' => $_FILES['issue_images']['tmp_name'][$i],
                'error' => $_FILES['issue_images']['error'][$i],
                'size' => $_FILES['issue_images']['size'][$i],
            ];
            $aid = hithean_upload_internal_order_image($file, $order_id, $filename);
            if (!is_wp_error($aid)) {
                $uploaded_urls[] = wp_get_attachment_url($aid);
            }
        }
    }

    $order->update_meta_data('return_status', $status);
    if ($code !== '') {
        $order->update_meta_data('return_code', $code);
    }
    if (!empty($uploaded_urls)) {
        $old = (string) $order->get_meta('issue_report_images');
        $order->update_meta_data('issue_report_images', trim($old . "\n" . implode("\n", $uploaded_urls)));
    }
    if ($note !== '') {
        $order->add_order_note('Ghi chú hoàn hàng: ' . $note, false, true);
    }
    $order->save();

    wp_cache_delete('return_orders_pending_v7', 'orders');
    wp_cache_delete('return_orders_completed_v7', 'orders');

    wp_send_json_success(['status' => $status, 'images' => $uploaded_urls]);
});

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
    <div class="orm-dashboard">
        <div class="orm-header">
            <div>
                <h2>Quản lý hoàn hàng</h2>
                <p>Flow đối soát theo Kanban: thấy trạng thái, biết việc kế tiếp, xử lý ngay trên từng đơn.</p>
            </div>
            <button type="button" id="orm_refresh_btn" class="button button-primary">Tải lại board</button>
        </div>

        <div id="orm_summary_cards" class="orm-summary-grid" aria-live="polite"></div>

        <div class="orm-toolbar">
            <button type="button" class="orm-filter is-active" data-filter="open">Tất cả đang mở</button>
            <button type="button" class="orm-filter" data-filter="today">Hôm nay</button>
            <button type="button" class="orm-filter" data-filter="7days">7 ngày</button>
            <button type="button" class="orm-filter" data-filter="has_code">Có mã hoàn</button>
            <button type="button" class="orm-filter" data-filter="missing_code">Thiếu mã hoàn</button>
            <button type="button" class="orm-filter" data-filter="issue">Có sự cố</button>
            <button type="button" class="orm-filter" data-filter="no_images">Chưa upload ảnh</button>
        </div>

        <div class="orm-attach-panel">
            <div class="orm-panel-title">Gắn đơn phát sinh trả hàng</div>
            <div class="orm-search-row">
                <input type="text" id="return_search_input" placeholder="SĐT, mã đơn hoặc email. Có thể nhập nhiều giá trị cách nhau bằng dấu cách/phẩy.">
                <button type="button" id="return_search_btn" class="button button-primary">Tìm đơn</button>
            </div>
            <p class="return-search-msg"></p>
            <div id="return_search_results" class="orm-search-results"></div>
        </div>

        <div id="orm_board_status" class="orm-board-status">Đang tải board...</div>
        <div id="orm_board" class="orm-board" aria-live="polite"></div>
        <div class="orm-history-note">Hoàn tất/lịch sử được giữ trên board khi chọn bộ lọc phù hợp; mặc định ưu tiên các đơn đang mở để nhân sự xử lý trong ngày.</div>
    </div>

    <script type="text/template" id="orm_process_template">
        <form class="orm-inline-form return-process-form" data-order-id="{{orderId}}">
            <strong>Chuyển sang: {{nextStatus}}</strong>
            <label>Mã vận đơn hoàn hàng
                <input type="text" name="return_code" placeholder="Nhập mã vận đơn hoàn hàng">
            </label>
            <label>Ghi chú xử lý
                <textarea name="return_note" rows="3" placeholder="Ghi chú nội bộ lưu vào đơn hàng"></textarea>
            </label>
            <button type="submit" class="button button-primary">Xác nhận đã xử lý</button>
            <div class="process-status orm-form-status"></div>
        </form>
    </script>

    <script type="text/template" id="orm_receive_template">
        <form class="orm-inline-form return-confirm-form" data-order-id="{{orderId}}">
            <strong>Upload ảnh hàng hoàn</strong>
            <label>Ảnh trả hàng
                <input type="file" name="return_images[]" multiple accept="image/*">
            </label>
            <div class="orm-radio-row">
                <label><input type="radio" name="has_issue" value="0" checked> Không sự cố</label>
                <label><input type="radio" name="has_issue" value="1"> Có sự cố</label>
            </div>
            <button type="submit" class="button button-primary">Upload và xác nhận</button>
            <div class="upload-status orm-form-status"></div>
        </form>
    </script>

    <style>
        .orm-dashboard {
            --orm-border: #dfe3e8;
            --orm-text: #1f2933;
            --orm-muted: #667085;
            --orm-blue: #1976d2;
            --orm-green: #2e7d32;
            --orm-orange: #ed6c02;
            --orm-red: #d32f2f;
            --orm-gray: #607d8b;
            color: var(--orm-text);
        }

        .orm-header,
        .orm-search-row,
        .orm-toolbar,
        .orm-card-actions,
        .orm-radio-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .orm-header {
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .orm-header h2 {
            margin: 0 0 4px;
            font-size: 24px;
            line-height: 1.25;
        }

        .orm-header p,
        .orm-history-note,
        .return-search-msg {
            margin: 0;
            color: var(--orm-muted);
        }

        .orm-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .orm-summary-card,
        .orm-attach-panel,
        .orm-column,
        .orm-order-card,
        .orm-search-card {
            border: 1px solid var(--orm-border);
            border-radius: 8px;
            background: #fff;
        }

        .orm-summary-card {
            padding: 12px;
            border-left: 4px solid var(--orm-blue);
        }

        .orm-summary-label {
            color: var(--orm-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .orm-summary-value {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            margin-top: 4px;
        }

        .orm-toolbar {
            margin-bottom: 14px;
        }

        .orm-filter {
            border: 1px solid var(--orm-border);
            border-radius: 18px;
            background: #fff;
            color: var(--orm-text);
            cursor: pointer;
            font-weight: 700;
            padding: 7px 12px;
        }

        .orm-filter.is-active {
            background: #e3f2fd;
            border-color: var(--orm-blue);
            color: #0d47a1;
        }

        .orm-attach-panel {
            padding: 14px;
            margin-bottom: 14px;
        }

        .orm-panel-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .orm-search-row input[type="text"] {
            flex: 1 1 320px;
            min-width: 220px;
            padding: 8px 10px;
        }

        .orm-search-results {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .orm-board-status {
            margin: 10px 0;
            color: var(--orm-muted);
        }

        .orm-board {
            display: grid;
            grid-template-columns: repeat(5, minmax(240px, 1fr));
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .orm-column {
            min-width: 240px;
            background: #f8fafc;
            overflow: hidden;
        }

        .orm-column-header {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: center;
            border-top: 4px solid var(--orm-gray);
            padding: 10px 12px;
            background: #fff;
            font-weight: 800;
        }

        .orm-column[data-column="needs_action"] .orm-column-header,
        .orm-summary-card[data-key="needs_action"] {
            border-color: var(--orm-blue);
        }

        .orm-column[data-column="waiting_return"] .orm-column-header,
        .orm-summary-card[data-key="waiting_return"] {
            border-color: var(--orm-orange);
        }

        .orm-column[data-column="issue"] .orm-column-header,
        .orm-summary-card[data-key="issue"] {
            border-color: var(--orm-red);
        }

        .orm-column[data-column="received"] .orm-column-header,
        .orm-summary-card[data-key="received_today"] {
            border-color: var(--orm-green);
        }

        .orm-column-count {
            border-radius: 14px;
            background: #eef2f6;
            min-width: 28px;
            padding: 3px 8px;
            text-align: center;
        }

        .orm-column-body {
            display: grid;
            gap: 10px;
            padding: 10px;
        }

        .orm-order-card,
        .orm-search-card {
            padding: 12px;
        }

        .orm-card-top {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .orm-order-link {
            color: var(--orm-blue);
            font-weight: 800;
            text-decoration: none;
        }

        .orm-status-chip {
            border-radius: 14px;
            background: #eef2f6;
            color: var(--orm-text);
            font-size: 12px;
            font-weight: 700;
            padding: 3px 8px;
            text-align: right;
        }

        .orm-card-meta,
        .orm-card-products,
        .orm-card-code,
        .orm-card-date {
            color: var(--orm-muted);
            font-size: 13px;
            line-height: 1.35;
            margin-top: 5px;
        }

        .orm-card-products {
            color: var(--orm-text);
            max-height: 42px;
            overflow: hidden;
        }

        .orm-card-actions {
            margin-top: 10px;
        }

        .orm-card-actions .button,
        .orm-inline-form .button,
        .attach-return-form .button {
            min-height: 32px;
        }

        .orm-inline-slot {
            margin-top: 10px;
        }

        .orm-inline-form,
        .attach-return-form {
            display: grid;
            gap: 10px;
            border-top: 1px solid var(--orm-border);
            margin-top: 10px;
            padding-top: 10px;
        }

        .orm-inline-form input[type="text"],
        .orm-inline-form textarea,
        .attach-return-form input[type="text"],
        .attach-return-form textarea,
        .attach-return-form select {
            width: 100%;
            max-width: 100%;
            padding: 7px 9px;
        }

        .orm-checklist {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(2, minmax(160px, 1fr));
        }

        .orm-checklist label {
            font-weight: 700;
        }

        .orm-form-status {
            color: var(--orm-blue);
            font-weight: 700;
        }

        .orm-images-preview img {
            width: 54px;
            height: 54px;
            object-fit: cover;
            border: 1px solid var(--orm-border);
            border-radius: 6px;
            margin: 6px 4px 0 0;
        }

        .orm-empty {
            color: var(--orm-muted);
            font-size: 13px;
            padding: 10px;
        }

        .orm-history-note {
            margin-top: 10px;
            font-size: 13px;
        }

        @media (max-width: 960px) {
            .orm-summary-grid {
                grid-template-columns: repeat(2, minmax(120px, 1fr));
            }

            .orm-board {
                grid-template-columns: repeat(5, minmax(220px, 78vw));
            }

            .orm-checklist {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        var ormBoardNonce = "<?php echo esc_js(wp_create_nonce('load_return_board_nonce')); ?>";
        var ormUploadNonce = "<?php echo esc_js(wp_create_nonce('upload_return_images_nonce')); ?>";
        var ormLookupNonce = "<?php echo esc_js(wp_create_nonce('return_lookup_order_nonce')); ?>";
        var ormAttachNonce = "<?php echo esc_js(wp_create_nonce('attach_return_order_nonce')); ?>";
        var ormProcessNonce = "<?php echo esc_js(wp_create_nonce('process_return_order_nonce')); ?>";

        jQuery(function($) {
            if (typeof ajaxurl === 'undefined') {
                var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            }

            let currentFilter = 'open';

            function setBusy(form, isBusy) {
                form.find('button, input, select, textarea').prop('disabled', isBusy);
            }

            function loadReturnBoard() {
                $('#orm_board_status').text('Đang tải board...');
                $.post(ajaxurl, {
                    action: 'load_return_board',
                    filter: currentFilter,
                    nonce: ormBoardNonce
                }, function(resp) {
                    if (resp.success) {
                        $('#orm_summary_cards').html(resp.data.summary_html);
                        $('#orm_board').html(resp.data.board_html);
                        $('#orm_board_status').text(resp.data.message || '');
                    } else {
                        $('#orm_board_status').text('Lỗi tải board: ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                    }
                }).fail(function() {
                    $('#orm_board_status').text('Lỗi hệ thống khi tải board.');
                });
            }

            function runReturnSearch() {
                const q = $('#return_search_input').val().trim();
                const msg = $('.return-search-msg');
                const box = $('#return_search_results');
                if (!q) {
                    msg.text('Vui lòng nhập SĐT, mã đơn hoặc email.');
                    return;
                }
                msg.text('Đang tìm...');
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
                        msg.text('Lỗi: ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                    }
                }).fail(function() {
                    msg.text('Lỗi tìm kiếm.');
                });
            }

            $('.orm-filter').on('click', function() {
                currentFilter = $(this).data('filter');
                $('.orm-filter').removeClass('is-active');
                $(this).addClass('is-active');
                loadReturnBoard();
            });

            $('#orm_refresh_btn').on('click', loadReturnBoard);
            $('#return_search_btn').on('click', runReturnSearch);
            $('#return_search_input').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    runReturnSearch();
                }
            });

            $(document).on('change', '.attach-return-form input[name="attach_flow"]', function() {
                const form = $(this).closest('form');
                const directReceive = form.find('input[name="attach_flow"]:checked').val() === 'received';
                form.find('.attach-pending-fields').toggle(!directReceive);
                form.find('.attach-received-fields').toggle(directReceive);
                form.find('select[name="return_status"]').prop('required', !directReceive);
            });

            $(document).on('submit', '.attach-return-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const status = form.find('select[name=return_status]').val();
                const flow = form.find('input[name=attach_flow]:checked').val();
                const st = form.find('.attach-status');
                const issueFiles = form.find('input[name="issue_images[]"]')[0].files;
                const resultFiles = form.find('input[name="result_images[]"]')[0].files;

                if (flow !== 'received' && !status) {
                    st.text('Vui lòng chọn trạng thái hoàn.');
                    return;
                }
                if (flow === 'received' && !resultFiles.length) {
                    st.text('Vui lòng upload ảnh hàng hoàn khi ghi nhận đã nhận hàng.');
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'attach_return_order');
                fd.append('order_id', orderId);
                fd.append('attach_flow', flow);
                fd.append('return_status', status);
                fd.append('return_code', form.find('input[name=return_code]').val());
                fd.append('return_note', form.find('textarea[name=return_note]').val());
                fd.append('has_issue', form.find('input[name=has_issue]:checked').val() || '0');
                fd.append('nonce', ormAttachNonce);
                for (let i = 0; i < issueFiles.length; i++) fd.append('issue_images[]', issueFiles[i]);
                for (let i = 0; i < resultFiles.length; i++) fd.append('result_images[]', resultFiles[i]);

                setBusy(form, true);
                st.text('Đang ghi nhận...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            form.replaceWith('<div class="orm-form-status">Đã ghi nhận: ' + resp.data.status + '</div>');
                            loadReturnBoard();
                        } else {
                            setBusy(form, false);
                            st.text('Lỗi: ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                        }
                    },
                    error: function() {
                        setBusy(form, false);
                        st.text('Lỗi hệ thống.');
                    }
                });
            });

            $(document).on('click', '.process-return-btn, .confirm-return-btn', function() {
                const btn = $(this);
                const card = btn.closest('.orm-order-card');
                const slot = card.find('.orm-inline-slot');
                const orderId = card.data('order-id');
                const isProcess = btn.hasClass('process-return-btn');
                const templateId = isProcess ? '#orm_process_template' : '#orm_receive_template';
                let html = $(templateId).html().replaceAll('{{orderId}}', orderId);
                html = html.replaceAll('{{nextStatus}}', btn.data('next-status') || '');

                if (slot.data('open') === btn.attr('class')) {
                    slot.empty().removeData('open');
                    return;
                }
                $('.orm-inline-slot').empty().removeData('open');
                slot.html(html).data('open', btn.attr('class'));
            });

            $(document).on('submit', '.return-process-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const fd = new FormData();
                fd.append('action', 'process_return_order');
                fd.append('order_id', orderId);
                fd.append('return_code', form.find('input[name=return_code]').val());
                fd.append('return_note', form.find('textarea[name=return_note]').val());
                fd.append('nonce', ormProcessNonce);

                setBusy(form, true);
                form.find('.process-status').text('Đang chuyển trạng thái...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            form.find('.process-status').text('Đã chuyển sang ' + resp.data.status + '.');
                            loadReturnBoard();
                        } else {
                            setBusy(form, false);
                            form.find('.process-status').text('Lỗi: ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                        }
                    },
                    error: function() {
                        setBusy(form, false);
                        form.find('.process-status').text('Lỗi hệ thống.');
                    }
                });
            });

            $(document).on('submit', '.return-confirm-form', function(e) {
                e.preventDefault();
                const form = $(this);
                const orderId = form.data('order-id');
                const files = form.find('input[type=file]')[0].files;
                const hasIssue = form.find('input[name=has_issue]:checked').val();

                if (!files.length) {
                    form.find('.upload-status').text('Vui lòng chọn ít nhất 1 ảnh.');
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'upload_return_images');
                fd.append('order_id', orderId);
                fd.append('has_issue', hasIssue);
                fd.append('nonce', ormUploadNonce);
                for (let i = 0; i < files.length; i++) fd.append('images[]', files[i]);

                setBusy(form, true);
                form.find('.upload-status').text('Đang upload...');
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(resp) {
                        if (resp.success) {
                            form.find('.upload-status').text('Hoàn tất.');
                            loadReturnBoard();
                        } else {
                            setBusy(form, false);
                            form.find('.upload-status').text('Lỗi: ' + (resp.data && resp.data.message ? resp.data.message : resp.data));
                        }
                    },
                    error: function() {
                        setBusy(form, false);
                        form.find('.upload-status').text('Lỗi hệ thống.');
                    }
                });
            });

            loadReturnBoard();
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

function hithean_return_max_upload_files(): int
{
    return 6;
}

function hithean_return_max_upload_bytes(): int
{
    return 5 * 1024 * 1024;
}

function hithean_return_allowed_filters(): array
{
    return ['open', 'today', '7days', 'has_code', 'missing_code', 'issue', 'no_images'];
}

function hithean_return_board_columns(): array
{
    return [
        'new' => [
            'label' => 'Mới phát sinh',
            'hint' => 'Đơn vừa ghi nhận hoàn hàng.',
        ],
        'needs_action' => [
            'label' => 'Cần xử lý',
            'hint' => 'Cần đổi trả, thu hồi hoặc giao lại.',
        ],
        'waiting_return' => [
            'label' => 'Chờ hàng hoàn về',
            'hint' => 'Đã xử lý yêu cầu, đang chờ hàng hoàn.',
        ],
        'issue' => [
            'label' => 'Có sự cố cần xử lý',
            'hint' => 'Đã nhận hàng hoàn nhưng có sự cố.',
        ],
        'received' => [
            'label' => 'Đã nhận / lịch sử',
            'hint' => 'Đã nhận hàng hoàn không sự cố hoặc lịch sử hoàn tất.',
        ],
    ];
}

function hithean_return_column_for_status(string $status): string
{
    $status = trim($status);
    if ($status === '') {
        return 'new';
    }
    if (in_array($status, ['Cần đổi trả', 'Cần thu hồi', 'Cần giao lại'], true)) {
        return 'needs_action';
    }
    if (strpos($status, 'Chờ hoàn') === 0) {
        return 'waiting_return';
    }
    if (strpos($status, 'Có sự cố') !== false) {
        return 'issue';
    }
    if (strpos($status, 'Đã nhận') !== false) {
        return 'received';
    }
    return 'new';
}

function hithean_return_status_next_action(string $status): array
{
    $next_status = hithean_return_next_status($status);
    if ($next_status !== '') {
        return [
            'type' => 'process',
            'label' => 'Xác nhận đã xử lý',
            'next_status' => $next_status,
        ];
    }
    if (strpos($status, 'Chờ hoàn') === 0 || $status === '') {
        return [
            'type' => 'receive',
            'label' => 'Upload ảnh hàng hoàn',
            'next_status' => '',
        ];
    }
    return [
        'type' => 'done',
        'label' => 'Đã ghi nhận',
        'next_status' => '',
    ];
}

function hithean_return_clear_cache(): void
{
    wp_cache_delete('return_orders_pending_v7', 'orders');
    wp_cache_delete('return_orders_completed_v7', 'orders');
    foreach (hithean_return_allowed_filters() as $filter) {
        wp_cache_delete('return_board_v1_' . $filter, 'orders');
    }
}

function hithean_return_now_mysql(): string
{
    return current_time('mysql');
}

function hithean_return_touch_meta(WC_Order $order, string $event): void
{
    $now = hithean_return_now_mysql();
    if (!$order->get_meta('return_created_at')) {
        $order->update_meta_data('return_created_at', $now);
    }

    if ($event === 'processed') {
        $order->update_meta_data('return_processed_at', $now);
    } elseif ($event === 'received') {
        $order->update_meta_data('return_received_at', $now);
    }

    $order->update_meta_data('return_last_action_at', $now);
    $order->update_meta_data('return_last_action_by', get_current_user_id());
}

function hithean_return_order_date_value(WC_Order $order, string $meta_key): int
{
    $raw = (string) $order->get_meta($meta_key);
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts) {
            return $ts;
        }
    }

    $date_created = $order->get_date_created();
    return $date_created ? $date_created->getTimestamp() : 0;
}

function hithean_return_order_has_issue(WC_Order $order): bool
{
    $status = (string) $order->get_meta('return_status');
    return (bool) $order->get_meta('return_has_issue') || strpos($status, 'Có sự cố') !== false;
}

function hithean_return_order_matches_filter(WC_Order $order, string $filter): bool
{
    $status = trim((string) $order->get_meta('return_status'));
    $code = trim((string) $order->get_meta('return_code'));
    $result_images = trim((string) $order->get_meta('issue_result_images'));
    $column = hithean_return_column_for_status($status);
    $now = current_time('timestamp');

    if ($filter === 'today') {
        $ts = max(
            hithean_return_order_date_value($order, 'return_last_action_at'),
            hithean_return_order_date_value($order, 'return_created_at'),
            hithean_return_order_date_value($order, 'return_received_at')
        );
        return $ts >= strtotime('today', $now);
    }
    if ($filter === '7days') {
        $ts = max(
            hithean_return_order_date_value($order, 'return_last_action_at'),
            hithean_return_order_date_value($order, 'return_created_at'),
            hithean_return_order_date_value($order, 'return_received_at')
        );
        return $ts >= ($now - 7 * DAY_IN_SECONDS);
    }
    if ($filter === 'has_code') {
        return $code !== '';
    }
    if ($filter === 'missing_code') {
        return $code === '' && in_array($column, ['new', 'needs_action', 'waiting_return'], true);
    }
    if ($filter === 'issue') {
        return hithean_return_order_has_issue($order);
    }
    if ($filter === 'no_images') {
        return $result_images === '' && in_array($column, ['new', 'needs_action', 'waiting_return'], true);
    }

    return in_array($column, ['new', 'needs_action', 'waiting_return', 'issue'], true);
}

function hithean_return_get_board_order_ids(string $filter): array
{
    $ids = array_merge(
        hithean_return_get_order_ids('pending'),
        hithean_return_get_order_ids('completed')
    );
    return array_values(array_unique(array_map('intval', $ids)));
}

function hithean_return_order_priority(WC_Order $order): array
{
    $status = trim((string) $order->get_meta('return_status'));
    $column = hithean_return_column_for_status($status);
    $code = trim((string) $order->get_meta('return_code'));
    $column_weight = [
        'issue' => 0,
        'needs_action' => 1,
        'waiting_return' => 2,
        'new' => 3,
        'received' => 4,
    ][$column] ?? 5;

    $missing_code_weight = $code === '' ? 0 : 1;
    $oldest_ts = hithean_return_order_date_value($order, 'return_created_at');

    return [$column_weight, $missing_code_weight, $oldest_ts ?: PHP_INT_MAX, -$order->get_id()];
}

function hithean_return_order_items_summary(WC_Order $order, int $limit = 2): string
{
    $parts = [];
    foreach ($order->get_items() as $item) {
        $parts[] = esc_html($item->get_name() . ' x ' . $item->get_quantity());
        if (count($parts) >= $limit) {
            break;
        }
    }
    $total = count($order->get_items());
    if ($total > $limit) {
        $parts[] = esc_html('+' . ($total - $limit) . ' sản phẩm khác');
    }
    return implode('<br>', $parts);
}

function hithean_return_render_summary_cards(array $counts): string
{
    $summary = [
        'today' => ['label' => 'Mới hôm nay', 'value' => $counts['today'] ?? 0],
        'needs_action' => ['label' => 'Cần xử lý', 'value' => $counts['needs_action'] ?? 0],
        'waiting_return' => ['label' => 'Chờ hàng về', 'value' => $counts['waiting_return'] ?? 0],
        'issue' => ['label' => 'Có sự cố', 'value' => $counts['issue'] ?? 0],
        'received_today' => ['label' => 'Đã hoàn hôm nay', 'value' => $counts['received_today'] ?? 0],
    ];

    ob_start();
    foreach ($summary as $key => $item): ?>
        <div class="orm-summary-card" data-key="<?= esc_attr($key) ?>">
            <div class="orm-summary-label"><?= esc_html($item['label']) ?></div>
            <div class="orm-summary-value"><?= esc_html((string) $item['value']) ?></div>
        </div>
    <?php endforeach;
    return ob_get_clean();
}

function hithean_return_render_order_card(WC_Order $order): string
{
    $oid = $order->get_id();
    $status = trim((string) $order->get_meta('return_status'));
    $code = trim((string) $order->get_meta('return_code'));
    $action = hithean_return_status_next_action($status);
    $received_images = trim((string) $order->get_meta('issue_result_images'));
    $created_at = (string) $order->get_meta('return_created_at');
    $last_at = (string) $order->get_meta('return_last_action_at');
    $date_label = $last_at !== '' ? $last_at : ($created_at !== '' ? $created_at : wc_format_datetime($order->get_date_created()));
    ob_start(); ?>
    <article class="orm-order-card" data-order-id="<?= esc_attr($oid) ?>">
        <div class="orm-card-top">
            <a class="orm-order-link" href="<?= esc_url($order->get_edit_order_url()) ?>" target="_blank">#<?= esc_html($oid) ?></a>
            <span class="orm-status-chip"><?= esc_html($status !== '' ? $status : 'Mới phát sinh') ?></span>
        </div>
        <div class="orm-card-meta">
            <strong><?= esc_html($order->get_formatted_billing_full_name()) ?></strong><br>
            <?= esc_html($order->get_billing_phone()) ?>
        </div>
        <div class="orm-card-products"><?= hithean_return_order_items_summary($order) ?></div>
        <div class="orm-card-code">Mã hoàn: <strong><?= esc_html($code !== '' ? $code : 'Chưa có') ?></strong></div>
        <div class="orm-card-date">Cập nhật: <?= esc_html($date_label) ?></div>
        <?php if ($received_images !== ''): ?>
            <div class="orm-images-preview">
                <?php foreach (array_slice(array_filter(array_map('trim', explode("\n", $received_images))), 0, 4) as $url): ?>
                    <a href="<?= esc_url($url) ?>" target="_blank"><img src="<?= esc_url($url) ?>" alt=""></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="orm-card-actions">
            <?php if ($action['type'] === 'process'): ?>
                <button type="button" class="button process-return-btn" data-next-status="<?= esc_attr($action['next_status']) ?>"><?= esc_html($action['label']) ?></button>
                <button type="button" class="button button-primary confirm-return-btn">Upload ảnh hàng hoàn</button>
            <?php elseif ($action['type'] === 'receive'): ?>
                <button type="button" class="button button-primary confirm-return-btn"><?= esc_html($action['label']) ?></button>
            <?php else: ?>
                <span class="orm-form-status"><?= esc_html($action['label']) ?></span>
            <?php endif; ?>
        </div>
        <div class="orm-inline-slot"></div>
    </article>
<?php
    return ob_get_clean();
}

function hithean_return_render_board(array $orders): array
{
    $columns = hithean_return_board_columns();
    $groups = array_fill_keys(array_keys($columns), []);
    $counts = [
        'today' => 0,
        'needs_action' => 0,
        'waiting_return' => 0,
        'issue' => 0,
        'received_today' => 0,
    ];
    $today_start = strtotime('today', current_time('timestamp'));

    foreach ($orders as $order) {
        $status = trim((string) $order->get_meta('return_status'));
        $column = hithean_return_column_for_status($status);
        $groups[$column][] = $order;

        if (in_array($column, ['needs_action', 'waiting_return'], true)) {
            $counts[$column]++;
        } elseif ($column === 'issue') {
            $counts['issue']++;
        }
        if (hithean_return_order_date_value($order, 'return_created_at') >= $today_start) {
            $counts['today']++;
        }
        if ($column === 'received' && hithean_return_order_date_value($order, 'return_received_at') >= $today_start) {
            $counts['received_today']++;
        }
    }

    ob_start();
    foreach ($columns as $key => $column): ?>
        <section class="orm-column" data-column="<?= esc_attr($key) ?>">
            <div class="orm-column-header">
                <div>
                    <?= esc_html($column['label']) ?>
                    <div class="orm-card-date"><?= esc_html($column['hint']) ?></div>
                </div>
                <span class="orm-column-count"><?= esc_html((string) count($groups[$key])) ?></span>
            </div>
            <div class="orm-column-body">
                <?php if (empty($groups[$key])): ?>
                    <div class="orm-empty">Không có đơn.</div>
                <?php else: ?>
                    <?php foreach ($groups[$key] as $order): ?>
                        <?= hithean_return_render_order_card($order) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach;

    return [
        'board_html' => ob_get_clean(),
        'summary_html' => hithean_return_render_summary_cards($counts),
    ];
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
    <?php foreach ($orders as $order):
        $oid = $order->get_id();
        $rs  = trim((string) $order->get_meta('return_status'));
    ?>
        <article class="orm-search-card">
            <div class="orm-card-top">
                <a class="orm-order-link" href="<?= esc_url($order->get_edit_order_url()) ?>" target="_blank">#<?= esc_html($oid) ?></a>
                <span class="orm-status-chip"><?= esc_html(wc_get_order_status_name($order->get_status())) ?></span>
            </div>
            <div class="orm-card-meta">
                <strong><?= esc_html($order->get_formatted_billing_full_name()) ?></strong><br>
                <?= esc_html($order->get_billing_phone()) ?>
                <?php if ($order->get_billing_email()): ?><br><?= esc_html($order->get_billing_email()) ?><?php endif; ?>
            </div>
            <div class="orm-card-products"><?= hithean_return_order_items_summary($order, 3) ?></div>
            <div class="orm-card-date">Ngày đặt: <?= esc_html(wc_format_datetime($order->get_date_created())) ?></div>
            <?php if ($rs !== ''): ?>
                <div class="orm-form-status">Đã có trạng thái hoàn: <?= esc_html($rs) ?>. Không ghi đè.</div>
            <?php else: ?>
                <form class="attach-return-form" data-order-id="<?= esc_attr($oid) ?>" enctype="multipart/form-data">
                    <div class="orm-radio-row">
                        <label><input type="radio" name="attach_flow" value="pending" checked> Ghi nhận vào flow chờ hoàn</label>
                        <label><input type="radio" name="attach_flow" value="received"> Đã nhận hàng hoàn ngay</label>
                    </div>
                    <div class="orm-checklist">
                        <label>Loại hoàn
                            <select name="return_status" required>
                                <option value="">Chọn trạng thái hoàn</option>
                                <?php foreach ($statuses as $s): ?>
                                    <option value="<?= esc_attr($s) ?>"><?= esc_html($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Mã vận đơn hoàn
                            <input type="text" name="return_code" placeholder="Tùy chọn">
                        </label>
                    </div>
                    <div class="attach-pending-fields">
                        <label>Ảnh sự cố phát sinh
                            <input type="file" name="issue_images[]" multiple accept="image/*">
                        </label>
                    </div>
                    <div class="attach-received-fields" style="display:none;">
                        <label>Ảnh hàng hoàn đã nhận
                            <input type="file" name="result_images[]" multiple accept="image/*">
                        </label>
                        <div class="orm-radio-row">
                            <label><input type="radio" name="has_issue" value="0" checked> Không sự cố</label>
                            <label><input type="radio" name="has_issue" value="1"> Có sự cố</label>
                        </div>
                    </div>
                    <label>Ghi chú nội bộ
                        <textarea name="return_note" rows="3" placeholder="Ghi chú lưu vào đơn hàng"></textarea>
                    </label>
                    <button type="submit" class="button button-primary">Xác nhận gắn đơn</button>
                    <div class="attach-status orm-form-status"></div>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
<?php
    return ob_get_clean();
}

/**
 * AJAX: Load Kanban dashboard hoàn hàng.
 */
add_action('wp_ajax_load_return_board', function () {
    check_ajax_referer('load_return_board_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền truy cập'], 403);
    }

    $filter = sanitize_key($_POST['filter'] ?? 'open');
    if (!in_array($filter, hithean_return_allowed_filters(), true)) {
        $filter = 'open';
    }

    $cache_key = 'return_board_v1_' . $filter;
    $cached = wp_cache_get($cache_key, 'orders');
    if ($cached !== false) {
        wp_send_json_success($cached);
    }

    $orders = [];
    foreach (hithean_return_get_board_order_ids($filter) as $oid) {
        $order = wc_get_order($oid);
        if (!$order || $order->get_type() !== 'shop_order') {
            continue;
        }
        if (!hithean_return_order_matches_filter($order, $filter)) {
            continue;
        }
        $orders[] = $order;
    }

    usort($orders, function (WC_Order $a, WC_Order $b) {
        return hithean_return_order_priority($a) <=> hithean_return_order_priority($b);
    });

    $rendered = hithean_return_render_board($orders);
    $data = [
        'summary_html' => $rendered['summary_html'],
        'board_html' => $rendered['board_html'],
        'message' => sprintf('Đang hiển thị %d đơn theo bộ lọc hiện tại.', count($orders)),
    ];

    wp_cache_set($cache_key, $data, 'orders', 180);
    wp_send_json_success($data);
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
        $file_count = count($_FILES['images']['name']);
        if ($file_count > hithean_return_max_upload_files()) {
            wp_send_json_error(['message' => 'Chỉ được upload tối đa ' . hithean_return_max_upload_files() . ' ảnh mỗi lần.']);
        }

        foreach ($_FILES['images']['name'] as $i => $name) {
            $size = intval($_FILES['images']['size'][$i] ?? 0);
            if ($size > hithean_return_max_upload_bytes()) {
                wp_send_json_error(['message' => 'Mỗi ảnh tối đa 5MB.']);
            }

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
    if (empty($uploaded_urls)) {
        wp_send_json_error(['message' => 'Không có ảnh hợp lệ để upload.']);
    }

    $new_val = trim($old_val . "\n" . implode("\n", $uploaded_urls));
    $order->update_meta_data('issue_result_images', $new_val);

    $new_status = $has_issue ? 'Đã nhận hàng hoàn (Có sự cố)' : 'Đã nhận hàng hoàn (Không sự cố)';
    $order->update_meta_data('return_status', $new_status);
    $order->update_meta_data('return_has_issue', $has_issue ? '1' : '0');
    hithean_return_touch_meta($order, 'received');
    $order->add_order_note(sprintf(
        "HOÀN HÀNG đã nhận hàng hoàn: %s\nẢnh đã upload: %d",
        $new_status,
        count($uploaded_urls)
    ), false, true);
    $order->save();

    hithean_return_clear_cache();

    wp_send_json_success(['urls' => $uploaded_urls, 'status' => $new_status]);
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
    hithean_return_touch_meta($order, 'processed');
    $order->save();

    hithean_return_clear_cache();

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
    $flow     = sanitize_key($_POST['attach_flow'] ?? 'pending');
    $status   = sanitize_text_field($_POST['return_status'] ?? '');
    $code     = sanitize_text_field($_POST['return_code'] ?? '');
    $note     = sanitize_textarea_field(wp_unslash($_POST['return_note'] ?? ''));
    $has_issue = intval($_POST['has_issue'] ?? 0);

    if (!$order_id) wp_send_json_error(['message' => 'Thiếu ID đơn']);
    if ($flow !== 'received' && !in_array($status, hithean_return_pending_statuses(), true)) {
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

    // Upload ảnh sự cố phát sinh hoặc ảnh hàng hoàn đã nhận.
    $issue_urls = [];
    $result_urls = [];
    $upload_groups = [
        'issue_images' => 'issue',
        'result_images' => 'result',
    ];

    foreach ($upload_groups as $field => $target) {
        if (empty($_FILES[$field]['name'][0])) {
            continue;
        }

        $file_count = count($_FILES[$field]['name']);
        if ($file_count > hithean_return_max_upload_files()) {
            wp_send_json_error(['message' => 'Chỉ được upload tối đa ' . hithean_return_max_upload_files() . ' ảnh mỗi lần.']);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($_FILES[$field]['name'] as $i => $name) {
            $size = intval($_FILES[$field]['size'][$i] ?? 0);
            if ($size > hithean_return_max_upload_bytes()) {
                wp_send_json_error(['message' => 'Mỗi ảnh tối đa 5MB.']);
            }

            $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $filename = sprintf('order-return-%s-%d-%s-%d.%s', $target, $order_id, date('Ymd-His'), $i + 1, $ext);
            $file = [
                'name' => $filename,
                'type' => $_FILES[$field]['type'][$i],
                'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                'error' => $_FILES[$field]['error'][$i],
                'size' => $_FILES[$field]['size'][$i],
            ];
            $aid = hithean_upload_internal_order_image($file, $order_id, $filename);
            if (!is_wp_error($aid)) {
                if ($target === 'result') {
                    $result_urls[] = wp_get_attachment_url($aid);
                } else {
                    $issue_urls[] = wp_get_attachment_url($aid);
                }
            }
        }
    }

    if ($flow === 'received') {
        if (empty($result_urls)) {
            wp_send_json_error(['message' => 'Vui lòng upload ít nhất 1 ảnh hàng hoàn hợp lệ.']);
        }
        $status = $has_issue ? 'Đã nhận hàng hoàn (Có sự cố)' : 'Đã nhận hàng hoàn (Không sự cố)';
    }

    $order->update_meta_data('return_status', $status);
    if ($code !== '') {
        $order->update_meta_data('return_code', $code);
    }
    if (!empty($issue_urls)) {
        $old = (string) $order->get_meta('issue_report_images');
        $order->update_meta_data('issue_report_images', trim($old . "\n" . implode("\n", $issue_urls)));
    }
    if (!empty($result_urls)) {
        $old = (string) $order->get_meta('issue_result_images');
        $order->update_meta_data('issue_result_images', trim($old . "\n" . implode("\n", $result_urls)));
        $order->update_meta_data('return_has_issue', $has_issue ? '1' : '0');
    }
    if ($note !== '') {
        $order->add_order_note('Ghi chú hoàn hàng: ' . $note, false, true);
    }
    $order->add_order_note(sprintf(
        "HOÀN HÀNG ghi nhận: %s\nFlow: %s\nẢnh sự cố: %d\nẢnh hàng hoàn: %d",
        $status,
        $flow === 'received' ? 'Đã nhận hàng hoàn ngay' : 'Chờ hoàn',
        count($issue_urls),
        count($result_urls)
    ), false, true);
    hithean_return_touch_meta($order, $flow === 'received' ? 'received' : 'created');
    $order->save();

    hithean_return_clear_cache();

    wp_send_json_success(['status' => $status, 'images' => array_merge($issue_urls, $result_urls)]);
});

<?php
defined('ABSPATH') || exit;

add_action('admin_menu', static function (): void {
    add_submenu_page('woocommerce', 'Ưu đãi sản phẩm', 'Ưu đãi sản phẩm', 'manage_woocommerce', 'hithean-offers', 'hithean_offers_admin');
});
add_action('admin_enqueue_scripts', static function ($hook): void {
    if ($hook !== 'woocommerce_page_hithean-offers') { return; }
    $base = HITHEAN_THEME_DIR . '/custom-functions/marketing/offers/';
    wp_enqueue_style('hithean-offers-admin', get_stylesheet_directory_uri() . '/custom-functions/marketing/offers/admin.css', [], filemtime($base . 'admin.css'));
    wp_enqueue_script('hithean-offers-admin', get_stylesheet_directory_uri() . '/custom-functions/marketing/offers/admin.js', [], filemtime($base . 'admin.js'), true);
    wp_localize_script('hithean-offers-admin', 'HitheanOffersAdmin', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('hithean_offers_status')]);
});
add_action('wp_ajax_hithean_offers_status', static function (): void {
    if (!current_user_can('manage_woocommerce')) { wp_send_json_error(null, 403); }
    check_ajax_referer('hithean_offers_status', 'nonce');
    $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
    unset($run['ids'], $run['catalog_ids'], $run['product_success']);
    wp_send_json_success($run);
});
add_action('admin_post_hithean_offers_action', static function (): void {
    if (!current_user_can('manage_woocommerce')) { wp_die('Không có quyền.', '', ['response' => 403]); }
    check_admin_referer('hithean_offers_action');
    $action = sanitize_key(wp_unslash($_POST['offer_action'] ?? ''));
    $notice = 'Đã cập nhật.';
    try {
        if ($action === 'scan') {
            if (get_transient('hithean_offers_scan_' . get_current_user_id())) { throw new RuntimeException('Vui lòng chờ vài giây trước khi yêu cầu quét tiếp.'); }
            set_transient('hithean_offers_scan_' . get_current_user_id(), 1, 15);
            $run = hithean_offers_start(absint($_POST['product_id'] ?? 0));
            $notice = 'Lượt quét: ' . $run['id'];
        } elseif ($action === 'stop') {
            $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
            update_option('hithean_offers_stop_requested', $run['id'] ?? '', false);
            $notice = 'Đã yêu cầu dừng sau sản phẩm đang xử lý.';
        } elseif ($action === 'settings') {
            $old = hithean_offers_settings();
            $provider = sanitize_key(wp_unslash($_POST['provider'] ?? 'auto'));
            if (!in_array($provider, ['auto', 'openai', 'claude', 'gemini', 'gemini_billing', 'qwen'], true)) { throw new RuntimeException('Provider không hợp lệ.'); }
            $next = ['enabled' => !empty($_POST['enabled']), 'ai_enabled' => !empty($_POST['ai_enabled']), 'provider' => $provider,
                'model' => sanitize_text_field(wp_unslash($_POST['model'] ?? '')), 'preview' => empty($_POST['public'])];
            update_option('hithean_offers_settings', $next, false);
            hithean_offers_sync_schedule();
            if ($old['preview'] !== $next['preview']) { hithean_offers_purge(); }
        } elseif ($action === 'page') {
            if (get_page_by_path('uu-dai')) { throw new RuntimeException('Trang /uu-dai/ đã tồn tại. Hãy kiểm tra nội dung và chọn template Landing Page trong trình sửa trang.'); }
            $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Ưu đãi', 'post_name' => 'uu-dai', 'post_content' => '[hithean_offers]'], true);
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            update_post_meta($id, '_wp_page_template', 'templates/template-landing-page.php');
            $notice = 'Đã tạo trang nháp /uu-dai/. Mở trình sửa trang để xem trước và xuất bản.';
        } elseif (in_array($action, ['approve', 'reject', 'restore', 'pin', 'unpin'], true)) {
            $fields = [];
            foreach (['start', 'end', 'kind', 'scope', 'verified', 'no_end', 'title'] as $field) {
                if (isset($_POST[$field]) && is_scalar($_POST[$field])) { $fields[$field] = wp_unslash((string) $_POST[$field]); }
            }
            hithean_offers_review(absint($_POST['offer_id'] ?? 0), sanitize_text_field(wp_unslash($_POST['source_key'] ?? '')), $action, $fields);
            hithean_offers_publish();
            if ($action === 'restore') { $notice = 'Đã khôi phục card. Kiểm tra nguồn và duyệt lại trước khi đăng.'; }
        } elseif ($action === 'rollback') {
            $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
            if (in_array($run['status'] ?? '', ['queued', 'running'], true)) { throw new RuntimeException('Dừng lượt quét trước khi khôi phục snapshot.'); }
            $previous = get_option('hithean_offers_previous_snapshot');
            if (!is_array($previous)) { throw new RuntimeException('Chưa có snapshot trước đó.'); }
            $current = get_option(HITHEAN_OFFERS_SNAPSHOT);
            update_option(HITHEAN_OFFERS_SNAPSHOT, $previous, false);
            update_option('hithean_offers_previous_snapshot', $current, false);
            hithean_offers_purge();
            $notice = 'Đã khôi phục snapshot trước. Tắt lịch nếu cần giữ bản này; ưu đãi hết hạn vẫn tự ẩn.';
        }
    } catch (Throwable $error) { $notice = $error->getMessage(); }
    set_transient('hithean_offers_notice_' . get_current_user_id(), $notice, 60);
    wp_safe_redirect(admin_url('admin.php?page=hithean-offers')); exit;
});
function hithean_offers_form_start(string $action, array $hidden = []): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="hithean_offers_action"><input type="hidden" name="offer_action" value="' . esc_attr($action) . '">';
    wp_nonce_field('hithean_offers_action');
    foreach ($hidden as $name => $value) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
}
function hithean_offers_admin(): void {
    if (!current_user_can('manage_woocommerce')) { return; }
    $s = hithean_offers_settings(); $run = (array) get_option(HITHEAN_OFFERS_RUN, []);
    $labels = ['review' => 'Cần duyệt', 'active' => 'Đang áp dụng', 'scheduled' => 'Sắp áp dụng', 'blocked' => 'Không công khai', 'expired' => 'Hết hạn', 'removed' => 'Đã gỡ', 'rejected' => 'Đã loại', 'stale' => 'Cần đồng bộ lại'];
    $rows = hithean_offers_records();
    $has_wheel_program = (bool) array_filter($rows, static fn($row) => ($row['source_id'] ?? '') === 'wheel:program');
    if ($has_wheel_program) {
        // Keep superseded prize records for audit, without duplicating the program on the board.
        $rows = array_filter($rows, static fn($row) => !(($row['family'] ?? '') === 'wheel' && ($row['source_id'] ?? '') !== 'wheel:program' && $row['status'] === 'removed'));
    }
    $notice = get_transient('hithean_offers_notice_' . get_current_user_id());
    echo '<div class="wrap ho-admin"><h1>Ưu đãi sản phẩm</h1><p>Đồng bộ cấu hình → Duyệt rule chung → Áp dụng cho sản phẩm → Theo dõi hiệu lực</p>';
    if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; delete_transient('hithean_offers_notice_' . get_current_user_id()); }
    echo '<section class="ho-panel"><h2>Đồng bộ danh sách</h2><p data-ho-progress aria-live="polite">' . esc_html(hithean_offers_progress($run)) . '</p><div class="ho-actions">';
    hithean_offers_form_start('scan'); echo '<button class="button button-primary">Đồng bộ ngay</button></form>';
    hithean_offers_form_start('scan'); echo '<label>ID sản phẩm <input name="product_id" type="number" min="1" required style="width:100px"></label> <button class="button">Đồng bộ sản phẩm</button></form>';
    hithean_offers_form_start('stop'); echo '<button class="button">Dừng sau sản phẩm hiện tại</button></form>';
    hithean_offers_form_start('rollback'); echo '<button class="button">Khôi phục snapshot trước</button></form></div>';
    $last = (int) get_option('hithean_offers_last_success', 0); $next = wp_next_scheduled('hithean_offers_weekly');
    echo '<p>Thành công gần nhất: ' . esc_html($last ? wp_date('d/m/Y H:i', $last) : 'Chưa có') . ' · Lịch tiếp theo: ' . esc_html($next ? wp_date('d/m/Y H:i', $next) : 'Chưa bật') . '</p>';
    echo '<p>Đọc cấu hình Discount Rules, Product Addon, giá sale WooCommerce và vòng quay. Đồng bộ tự động mỗi tuần một lần khi bật lịch, hoặc khi bấm “Đồng bộ ngay”. Cron server mỗi 5 phút chỉ xử lý tác vụ đến hạn.</p>';
    $scheduler_error = get_option('hithean_offers_scheduler_error', '');
    if ($scheduler_error) { echo '<p class="ho-error">Lịch quét: ' . esc_html($scheduler_error) . '</p>'; }
    foreach ($run['errors'] ?? [] as $error) { echo '<p class="ho-error">SP #' . absint($error['product_id']) . ': ' . esc_html($error['message']) . '</p>'; }
    echo '</section><details class="ho-panel"><summary>Cấu hình & xuất bản</summary>';
    hithean_offers_form_start('settings');
    echo '<p><label><input type="checkbox" name="enabled" ' . checked($s['enabled'], true, false) . '> Tự đồng bộ mỗi tuần một lần</label></p><p><label><input type="checkbox" name="public" ' . checked(!$s['preview'], true, false) . '> Hiển thị snapshot trên trang công khai</label></p><p>Dữ liệu cấu trúc được đọc trực tiếp từ cấu hình. Nội dung và điều kiện chưa rõ sẽ chờ duyệt.</p><p><button class="button button-primary">Lưu cấu hình</button></p></form>';
    $page = get_page_by_path('uu-dai');
    if ($page) { echo '<p><a class="button" href="' . esc_url(get_edit_post_link($page->ID)) . '">Kiểm tra / sửa trang Ưu đãi</a> <a class="button" href="' . esc_url(get_permalink($page->ID)) . '">Xem trang</a></p>'; }
    else { hithean_offers_form_start('page'); echo '<button class="button">Tạo trang nháp /uu-dai/</button></form>'; }
    echo '</details><div class="ho-view-switch"><button type="button" class="button" data-ho-view="board" aria-pressed="true">Kanban</button> <button type="button" class="button" data-ho-view="table" aria-pressed="false">Bảng</button></div><div class="ho-board" data-ho-board>';
    foreach ($labels as $status => $label) {
        $group = array_filter($rows, static fn($row) => hithean_offers_status($row) === $status);
        echo '<section class="ho-column"><h2>' . esc_html($label) . ' <span>' . count($group) . '</span></h2>';
        foreach ($group as $row) {
            $id = (int) $row['id']; $product = wc_get_product($row['product_id']);
            $shared = isset($row['source_id']);
            $heading = $shared ? ($row['source_title'] ?? $row['source_id']) : ($product ? $product->get_name() : '#' . $row['product_id']);
            $dialog_id = 'ho-offer-dialog-' . $id;
            $title_id = $dialog_id . '-title';
            echo '<article class="ho-offer"><h3><button type="button" class="ho-offer-title" data-ho-open="' . esc_attr($dialog_id) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($dialog_id) . '">' . esc_html($heading) . '</button></h3>';
            echo '<dialog class="ho-offer-dialog" id="' . esc_attr($dialog_id) . '" aria-labelledby="' . esc_attr($title_id) . '"><header class="ho-dialog-header"><div><h2 id="' . esc_attr($title_id) . '">' . esc_html($heading) . '</h2><p class="ho-muted">' . esc_html($label) . '</p></div><button type="button" class="button ho-dialog-close" data-ho-close aria-label="Đóng cửa sổ chỉnh sửa" autofocus>Đóng ×</button></header><div class="ho-dialog-body"><p>' . esc_html($row['title']) . '</p><p class="ho-error">' . esc_html($row['reason']) . '</p>';
            if ($shared) {
                echo '<details><summary>Áp dụng cho ' . count($row['product_ids']) . ' sản phẩm · Duyệt một lần cho cả rule</summary><ul>';
                foreach ($row['product_ids'] as $product_id) {
                    $linked_product = wc_get_product($product_id);
                    echo '<li><a href="' . esc_url(get_permalink($product_id)) . '">' . esc_html($linked_product ? $linked_product->get_name() : '#' . $product_id) . '</a></li>';
                }
                echo '</ul></details>';
            }
            echo '<details><summary>Xem bằng chứng & lịch sử</summary><blockquote>' . esc_html($row['evidence']) . '</blockquote><a href="' . esc_url($row['source']) . '" target="_blank" rel="noopener noreferrer">Mở nguồn cấu hình</a><p>Kiểm tra: ' . esc_html(wp_date('d/m/Y H:i', $row['verified_at'])) . '</p>';
            if (($row['family'] ?? '') !== 'wheel' && !empty($row['source_config'])) { echo '<pre>' . esc_html(wp_json_encode($row['source_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>'; }
            if (!empty($row['source_constraints'])) { echo '<p>Điều kiện cấu trúc tại nguồn:</p><pre>' . esc_html(wp_json_encode($row['source_constraints'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>'; }
            foreach (array_filter((array) get_post_meta($id, '_ho_audit', true), 'is_array') as $audit) { echo '<p>' . esc_html(wp_date('d/m H:i', $audit['at']) . ' · #' . $audit['user'] . ' · ' . $audit['action']) . '</p>'; }
            echo '</details>';
            if ($status === 'rejected') {
                hithean_offers_form_start('restore', ['offer_id' => $id, 'source_key' => $row['key']]);
                echo '<p><button class="button">Khôi phục để duyệt lại</button></p><p class="ho-muted">Khôi phục cả rule và các sản phẩm liên kết; cần duyệt lại trước khi đăng.</p></form>';
            }
            if (in_array($status, ['review', 'active', 'scheduled'], true) && empty($row['blocked'])) {
                echo '<details open><summary>Duyệt / xác nhận điều kiện</summary>';
                hithean_offers_form_start('approve', ['offer_id' => $id, 'source_key' => $row['key']]);
                echo '<p><label>Nội dung công khai (bao gồm đủ điều kiện)<textarea name="title" rows="5" required maxlength="3000" class="widefat">' . esc_textarea($row['title']) . '</textarea></label></p>';
                if ($shared && empty($row['editable_dates'])) { echo '<p>Hiệu lực nguồn: ' . esc_html($row['start'] ? wp_date('d/m/Y H:i', $row['start']) : 'Không giới hạn bắt đầu') . ' → ' . esc_html($row['end'] ? wp_date('d/m/Y H:i', $row['end']) : 'Không công bố ngày kết thúc') . '. Ngày giờ được đồng bộ từ nguồn.</p>'; }
                foreach (['start' => 'Từ ngày', 'end' => 'Đến hết ngày'] as $field => $label_date) {
                    echo '<p><label>' . $label_date . ' <input type="date" name="' . $field . '" value="' . esc_attr($row[$field] ? wp_date('Y-m-d', $row[$field], new DateTimeZone('Asia/Ho_Chi_Minh')) : '') . '"' . ($shared && empty($row['editable_dates']) ? ' readonly' : '') . '></label></p>';
                }
                echo '<p><label><input type="checkbox" name="no_end"> Nguồn không công bố ngày kết thúc</label></p><p><label>Phạm vi <select name="scope"><option value="product">Sản phẩm</option><option value="order" ' . selected($row['scope'], 'order', false) . '>Theo đơn hàng</option></select></label></p><p><label>Loại ưu đãi <select name="kind">';
                foreach (['gift' => 'Quà tặng', 'discount' => 'Giảm giá', 'shipping' => 'Vận chuyển', 'quantity' => 'Mua nhiều', 'other' => 'Khác'] as $kind => $kind_label) { echo '<option value="' . esc_attr($kind) . '" ' . selected($row['kind'], $kind, false) . '>' . esc_html($kind_label) . '</option>'; }
                echo '</select></label></p><p><label><input type="checkbox" required name="verified"> Đã kiểm tra đầy đủ điều kiện, hiệu lực và đối tượng khách lẻ</label></p><button class="button button-primary">' . ($shared ? 'Duyệt rule cho ' . count($row['product_ids']) . ' sản phẩm' : 'Duyệt & cập nhật trang') . '</button></form></details>';
                echo '<div class="ho-actions">';
                foreach (['reject' => 'Loại bỏ', !empty($row['pinned']) ? 'unpin' : 'pin' => !empty($row['pinned']) ? 'Bỏ ghim' : 'Ghim nổi bật'] as $action => $button) {
                    hithean_offers_form_start($action, ['offer_id' => $id, 'source_key' => $row['key']]); echo '<button class="button">' . esc_html($button) . '</button></form>';
                }
                echo '</div>';
            }
            hithean_offers_form_start('scan', $shared ? [] : ['product_id' => $row['product_id']]); echo '<p><button class="button">Đồng bộ lại nguồn</button></p></form></div></dialog></article>';
        }
        if (!$group) { echo '<p class="ho-muted">Chưa có ưu đãi.</p>'; }
        echo '</section>';
    }
    echo '</div><details class="ho-panel"><summary>Lịch sử quét (30 ngày)</summary>';
    foreach (array_reverse((array) get_option('hithean_offers_history', [])) as $entry) { echo '<p>' . esc_html(wp_date('d/m/Y H:i', $entry['started_at']) . ' · ' . $entry['status'] . ' · ' . $entry['ok'] . '/' . $entry['total'] . ' · ' . $entry['failed'] . ' lỗi') . '</p>'; }
    echo '</details></div>';
}
function hithean_offers_progress(array $run): string {
    $labels = ['queued' => 'Chờ xử lý', 'running' => 'Đang quét', 'complete' => 'Hoàn tất', 'partial' => 'Hoàn tất một phần', 'stopped' => 'Đã dừng'];
    if (!$run) { return 'Chưa chạy. Bấm “Đồng bộ ngay” để tạo danh sách đầu tiên.'; }
    return ($labels[$run['status']] ?? $run['status']) . ': ' . ($run['cursor'] ?? 0) . '/' . ($run['total'] ?? 0) . ' bước · ' . ($run['failed'] ?? 0) . ' lỗi';
}

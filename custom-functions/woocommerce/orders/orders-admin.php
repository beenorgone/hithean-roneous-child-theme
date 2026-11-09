<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/order-actions-column.php'; // cột Thao tác: bước tiếp theo + menu Thêm
require_once __DIR__ . '/order-quick-modals.php';   // 4 popup thao tác nhanh (dùng chung theanmarket / hithean)
// Filter to add no-link class for shop_order post type
add_filter('post_class', function ($classes) {
    if (is_admin()) {
        $current_screen = get_current_screen();
        if ($current_screen->base === 'edit' && $current_screen->post_type === 'shop_order') {
            $classes[] = 'no-link';
        }
    }
    return $classes;
});

// Set IP for order
add_filter('request', 'set_ip_for_customer');
function set_ip_for_customer($vars)
{
    global $typenow;
    if ('shop_order' === $typenow && isset($_GET['_shop_order_ip'])) {
        $vars['meta_key'] = '_customer_ip_address';
        $vars['meta_value'] = wc_clean($_GET['_shop_order_ip']);
    }
    return $vars;
}

function hithean_order_admin_action_url(string $action, int $order_id, array $args = []): string
{
    return add_query_arg(array_merge([
        'hithean_order_action' => sanitize_key($action),
        'order_id'             => absint($order_id),
    ], $args), admin_url('admin.php'));
}

// Add custom actions to WooCommerce admin order actions
add_filter('woocommerce_admin_order_actions', 'add_custom_actions', 100, 2);
function add_custom_actions($actions, $order)
{
    // Bỏ nút core "Đang xử lý" / "Hoàn thành": đổi trạng thái đi qua luồng điều phối đơn.
    unset($actions['processing'], $actions['complete']);

    $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    $cus_id = $order->get_customer_id();
    $bill_phone = $order->get_billing_phone();
    $ship_phone = $order->get_shipping_phone();
    $order_total = $order->get_total();
    $refund_status = $order->get_meta('refund_status');

    $qr_url = hithean_payment_qr_url(hithean_primary_bank_account(), (float) $order_total, hithean_payment_transfer_content($order_id));

    if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
        $route = defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don';
        $actions['chinh-don-tao-don'] = [
            'url' => home_url('/' . $route . '/?order_id=' . absint($order_id)),
            'name' => __('Chỉnh đơn trên trang Tạo đơn', 'woocommerce'),
            'action' => 'chinh-don-tao-don',
            'target' => '_blank'
        ];
    }

    // These are modal/AJAX actions; the URL only carries the order context to
    // the admin script while the existing endpoints remain the source of truth.
    if (current_user_can('manage_woocommerce')) {
        $actions['hithean-order-payment'] = [
            'url'    => hithean_order_admin_action_url('payment', $order_id, ['amount' => wc_format_decimal($order_total, 2)]),
            'name'   => __('Xác nhận thanh toán', 'woocommerce'),
            'action' => 'hithean-order-payment',
        ];

        if ($order->get_payment_method() === 'cod' && in_array($order->get_status(), ['on-hold', 'processing'], true)) {
            $actions['hithean-order-customer-confirmed'] = [
                'url'    => hithean_order_admin_action_url('customer-confirmed', $order_id),
                'name'   => __('Khách đã xác nhận COD', 'woocommerce'),
                'action' => 'hithean-order-customer-confirmed',
            ];
        }

        $actions['hithean-order-export-images'] = [
            'url'    => hithean_order_admin_action_url('export-images', $order_id),
            'name'   => __('Ảnh xuất kho: xem / up / xác nhận', 'woocommerce'),
            'action' => 'hithean-order-export-images',
        ];

        if (!$order->get_meta('export_confirmed_by')) {
            $actions['hithean-order-export-confirm'] = [
                'url'    => hithean_order_admin_action_url('export-confirm', $order_id),
                'name'   => __('Xác nhận xuất kho', 'woocommerce'),
                'action' => 'hithean-order-export-confirm',
            ];
        }

        if (trim((string) $order->get_meta('return_status')) === '') {
            $actions['hithean-order-return-record'] = [
                'url'    => hithean_order_admin_action_url('return-record', $order_id),
                'name'   => __('Ghi nhận đơn hoàn hàng', 'woocommerce'),
                'action' => 'hithean-order-return-record',
            ];
        }

        $actions['hithean-order-return-images'] = [
            'url'    => hithean_order_admin_action_url('return-images', $order_id),
            'name'   => __('Upload ảnh hoàn hàng', 'woocommerce'),
            'action' => 'hithean-order-return-images',
        ];
    }

    // Add QR code action (chưa khai TK ở WooCommerce → không có nút)
    if ($qr_url !== '') {
        $actions['qr-code'] = [
            'url' => $qr_url,
            'name' => __('QR', 'woocommerce'),
            'action' => 'qr-code'
        ];
    }

    // Add GHTK action
    $actions['in-van-don-ghtk'] = [
        'url' => admin_url("admin-ajax.php?action=inhoadon_ghtk&order_id=$order_id"),
        'name' => __('In phiếu xuất kho / vận đơn theo mẫu riêng, GHTK', 'woocommerce'),
        'action' => 'in-van-don-ghtk'
    ];

    if (wp_is_mobile()) {
        // Add mobile-specific actions
        $actions['goi-sdt-dat-hang'] = [
            'url' => "tel:$bill_phone",
            'name' => __('Gọi số người đặt ' . $bill_phone, 'woocommerce'),
            'action' => 'goi-sdt-dat-hang'
        ];
        $actions['goi-sdt-nhan-hang'] = [
            'url' => "tel:$ship_phone",
            'name' => __('Gọi số người nhận ' . $ship_phone, 'woocommerce'),
            'action' => 'goi-sdt-nhan-hang'
        ];
    }

    if (!$refund_status) {
        // Nhap yeu cau hoan tien: https://applink.larksuite.com/T8T1KEvmpDQw
        $actions['don-hoan-tien'] = [
            'url' => 'https://applink.larksuite.com/T8T1KEvmpDQw',
            'name' => __('Nhập yêu cầu hoàn tiền cho khách', 'woocommerce'),
            'action' => 'don-hoan-tien',
            'target' => '_blank'
        ];
    }

    return $actions;
}

function hithean_order_admin_is_list_screen(): bool
{
    if (function_exists('thean_order_portal_is_route') && thean_order_portal_is_route()) return thean_order_portal_can();
    if (!function_exists('get_current_screen')) {
        return false;
    }

    $screen = get_current_screen();
    if (!$screen) {
        return false;
    }

    return $screen->id === 'edit-shop_order'
        || $screen->id === 'woocommerce_page_wc-orders'
        || $screen->base === 'woocommerce_page_wc-orders';
}

function hithean_order_admin_export_checklist(): array
{
    $default = implode("\n", [
        'Ảnh chụp đầy đủ sản phẩm',
        'Số lượng khớp với đơn',
        'Hàng không bị hư hỏng, rò rỉ, móp méo',
        'Ảnh chụp rõ mã đơn hoặc tên khách hàng',
        'Nhãn hàng HiThean đã dán TEM NIÊM PHONG',
    ]);
    $raw = get_option('hithean_export_upload_checklist', $default);
    return array_values(array_filter(array_map('trim', explode("\n", (string) $raw))));
}

// Popup thao tác nhanh (thanh toán / khách XN COD / ảnh xuất kho / xác nhận xuất kho): order-quick-modals.php (dùng chung 2 site).

function hithean_order_admin_render_return_action_modals(): void
{
    if (!hithean_order_admin_is_list_screen() || !current_user_can('manage_woocommerce')) {
        return;
    }

    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'statuses' => [
            'Cần đổi trả', 'Cần thu hồi', 'Cần giao lại', 'Chờ hoàn (Hủy)',
            'Chờ hoàn (Không giao được)', 'Chờ hoàn (Đổi trả)', 'Chờ hoàn (Thu hồi)', 'Chờ hoàn (Giao 1 phần)',
        ],
        'nonces' => [
            'record' => wp_create_nonce('attach_return_order_nonce'),
            'images' => wp_create_nonce('upload_return_images_nonce'),
        ],
    ];
    ?>
    <style>
        .hithean-return-modal[hidden] { display:none; }.hithean-return-modal { position:fixed; z-index:1000002; inset:0; display:grid; place-items:center; padding:24px; }.hithean-return-modal__backdrop { position:absolute; inset:0; background:rgba(15,23,42,.55); }.hithean-return-modal__dialog { position:relative; width:min(500px,100%); box-sizing:border-box; padding:24px; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(15,23,42,.32); }.hithean-return-modal h2 { margin:0 32px 12px 0; }.hithean-return-modal label { display:grid; gap:6px; margin-top:12px; font-weight:600; }.hithean-return-modal input:not([type="radio"]),.hithean-return-modal select,.hithean-return-modal textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #cbd5e1; border-radius:7px; }.hithean-return-modal__close { position:absolute; top:12px; right:12px; border:0; border-radius:50%; width:32px; height:32px; font-size:22px; cursor:pointer; }.hithean-return-modal__radio { display:flex; gap:16px; margin-top:14px; }.hithean-return-modal__radio label { display:flex; gap:6px; margin:0; font-weight:400; }.hithean-return-modal__actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }.hithean-return-modal button { padding:9px 15px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; cursor:pointer; }.hithean-return-modal button[type="submit"] { border-color:#007a52; background:#08a875; color:#fff; font-weight:600; }.hithean-return-modal__notice { min-height:20px; margin-top:12px; font-weight:600; color:#b42318; }
    </style>
    <div class="hithean-return-modal" id="hithean-return-record-modal" hidden><div class="hithean-return-modal__backdrop" data-hithean-return-close></div><form class="hithean-return-modal__dialog" id="hithean-return-record-form"><button type="button" class="hithean-return-modal__close" data-hithean-return-close>&times;</button><h2>Ghi nhận đơn hoàn hàng</h2><input type="hidden" name="order_id"><label>Loại hoàn<select name="return_status" required><option value="">Chọn trạng thái hoàn</option><?php foreach ($config['statuses'] as $status): ?><option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label><label>Mã vận đơn hoàn<input name="return_code" type="text" placeholder="Tùy chọn"></label><label>Ghi chú nội bộ<textarea name="return_note" rows="3"></textarea></label><div class="hithean-return-modal__actions"><button type="button" data-hithean-return-close>Hủy</button><button type="submit">Ghi nhận hoàn</button></div><div class="hithean-return-modal__notice" aria-live="polite"></div></form></div>
    <div class="hithean-return-modal" id="hithean-return-images-modal" hidden><div class="hithean-return-modal__backdrop" data-hithean-return-close></div><form class="hithean-return-modal__dialog" id="hithean-return-images-form" enctype="multipart/form-data"><button type="button" class="hithean-return-modal__close" data-hithean-return-close>&times;</button><h2>Upload ảnh hoàn hàng</h2><input type="hidden" name="order_id"><label>Ảnh hàng hoàn<input name="images[]" type="file" accept="image/jpeg,image/png" multiple required></label><div class="hithean-return-modal__radio"><label><input type="radio" name="has_issue" value="0" checked> Không sự cố</label><label><input type="radio" name="has_issue" value="1"> Có sự cố</label></div><div class="hithean-return-modal__actions"><button type="button" data-hithean-return-close>Hủy</button><button type="submit">Upload ảnh hoàn</button></div><div class="hithean-return-modal__notice" aria-live="polite"></div></form></div>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>, active = null;
        function modal(type) { return document.getElementById('hithean-return-' + type + '-modal'); }
        function form(type) { return document.getElementById('hithean-return-' + type + '-form'); }
        function id(button) { return String(parseInt(new URL(button.href, window.location.origin).searchParams.get('order_id'), 10) || 0); }
        function open(type, button) { active = { type:type, button:button }; var current = modal(type), currentForm = form(type); currentForm.reset(); currentForm.elements.order_id.value = id(button); currentForm.querySelector('[aria-live]').textContent = ''; current.hidden = false; }
        function close(current) { (current || document.querySelector('.hithean-return-modal:not([hidden])')).hidden = true; active = null; }
        function message(response, fallback) { return response && response.data && response.data.message ? response.data.message : fallback; }
        function request(action, data) { data.append('action', action); var ids = String(data.get('order_id') || data.get('ueif_order_id') || data.get('uexe_order_id') || data.get('order_ids') || ''); return fetch(cfg.ajaxUrl, { method:'POST', credentials:'same-origin', body:data }).then(function (response) { return response.json(); }).then(function (response) { if (response.success && ids) document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderIds: ids.split(',') } })); return response; }); }
        function complete(button, label) { button.classList.add('is-hithean-complete'); button.dataset.hitheanCompleteLabel = label; button.removeAttribute('href'); }
        document.addEventListener('click', function (event) { var button = event.target.closest('.wc-action-button-hithean-order-return-record, .wc-action-button-hithean-order-return-images'); if (button) { event.preventDefault(); open(button.classList.contains('wc-action-button-hithean-order-return-record') ? 'record' : 'images', button); return; } if (event.target.closest('[data-hithean-return-close]')) close(event.target.closest('.hithean-return-modal')); });
        ['record', 'images'].forEach(function (type) { form(type).addEventListener('submit', function (event) { event.preventDefault(); var currentForm = event.currentTarget, submit = currentForm.querySelector('[type="submit"]'), files = currentForm.querySelector('[type="file"]'); if (files && (!files.files.length || files.files.length > 5)) { currentForm.querySelector('[aria-live]').textContent = files.files.length > 5 ? 'Chỉ được upload tối đa 5 ảnh.' : 'Hãy chọn ảnh hoàn hàng.'; return; } submit.disabled = true; var data = new FormData(currentForm), action = type === 'record' ? 'attach_return_order' : 'upload_return_images'; data.append('nonce', cfg.nonces[type]); if (type === 'record') data.append('attach_flow', 'pending'); request(action, data).then(function (response) { if (!response.success) throw new Error(message(response, 'Không thể cập nhật đơn hoàn.')); if (type === 'record' && active) complete(active.button, 'Đã ghi nhận hoàn'); close(modal(type)); }).catch(function (error) { currentForm.querySelector('[aria-live]').textContent = error.message || 'Lỗi kết nối.'; }).finally(function () { submit.disabled = false; }); }); });
    }());
    </script>
    <?php
}
add_action('admin_footer', 'hithean_order_admin_render_return_action_modals');

/**
 * Danh sách đơn (legacy + HPOS): CHỈ bấm vào ô cột "Đơn hàng" mới mở trang chi tiết đơn, và mở ở TAB MỚI.
 * Bấm các ô khác trên dòng không mở đơn (chặn handler click dòng của WooCommerce ở capture phase).
 * Link, nút, ô tích và lúc đang bôi đen chữ giữ hành vi riêng.
 */
function hithean_order_admin_open_orders_in_new_tab(): void
{
    if (!hithean_order_admin_is_list_screen() || in_array($_GET['action'] ?? '', ['edit', 'new'], true)) {
        return;
    }
    ?>
    <script>
    (function () {
        function markLinks() {
            document.querySelectorAll('.wp-list-table a.order-view').forEach(function (a) {
                a.target = '_blank';
                a.rel = 'noopener';
            });
        }
        markLinks();
        document.addEventListener('click', function (e) {
            var row = e.target.closest('.wp-list-table tbody tr');
            if (!row || e.button !== 0) return;
            if (e.target.closest('a, button, input, select, textarea, label, .check-column')) return;
            e.stopPropagation(); // không cho WC mở đơn khi bấm cả dòng
            if (!e.target.closest('td.column-order_number')) return;
            if (window.getSelection && String(window.getSelection()).length) return;
            var link = row.querySelector('a.order-view');
            if (!link || !link.href) return;
            e.preventDefault();
            window.open(link.href, '_blank', 'noopener');
        }, true);
    }());
    </script>
    <?php
}
add_action('admin_footer', 'hithean_order_admin_open_orders_in_new_tab');

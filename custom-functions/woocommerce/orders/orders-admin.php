<?php
if (!defined('ABSPATH')) exit;
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
    $order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    $cus_id = $order->get_customer_id();
    $bill_phone = $order->get_billing_phone();
    $ship_phone = $order->get_shipping_phone();
    $order_total = $order->get_total();
    $compensate_status = $order->get_meta('compensate_status');
    $refund_status = $order->get_meta('refund_status');

    $bank_account = '113600098383';
    $bank_code = 'VietinBank';
    $qr_url = "https://qr.sepay.vn/img?bank={$bank_code}&acc={$bank_account}&amount={$order_total}&des=P0{$order_id}&template=compact";

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
            'name'   => __('Upload ảnh xuất kho', 'woocommerce'),
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

    // Add QR code action
    $actions['qr-code'] = [
        'url' => $qr_url,
        'name' => __('QR', 'woocommerce'),
        'action' => 'qr-code',
        'target' => '_blank'
    ];

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

    if ($compensate_status) {
        // Theo doi yeu cau boi hoan
        $actions['theo-doi-boi-hoan'] = [
            'url' => 'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgvghQ7MdT8Dn24B500a5pBg',
            'name' => __('Theo dõi yêu cầu đền bù với shipper', 'woocommerce'),
            'action' => 'theo-doi-boi-hoan',
            'target' => '_blank'
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

    if ($refund_status) {
        // Theo doi yeu cau hoan tien:
        $actions['theo-doi-hoan-tien'] = [
            'url' => 'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgfNPS0U52nGaZClUNvARIpb',
            'name' => __('Theo dõi yêu cầu hoàn tiền cho khách', 'woocommerce'),
            'action' => 'theo-doi-hoan-tien',
            'target' => '_blank'
        ];
    }

    // Add GHTK action: Tra van don
    $actions['tra-don-ghtk'] = [
        'url' => 'https://khachhang.giaohangtietkiem.vn/web/don-hang',
        'name' => __('Tra cứu vận đơn tại GHTK', 'woocommerce'),
        'action' => 'tra-don-ghtk'
    ];

    // Add Viettel Post action: Tra van don
    $actions['tra-don-vtp'] = [
        'url' => 'https://viettelpost.vn/quan-ly-van-don',
        'name' => __('Tra cứu vận đơn tại Viettel Post', 'woocommerce'),
        'action' => 'tra-don-vtp'
    ];

    return $actions;
}

// Add custom styles for order action buttons
add_action('admin_head', 'add_custom_order_actions_button_css');
function add_custom_order_actions_button_css()
{
    $styles = [
        'in-van-don-ghtk' => 'background: green !important; color: #fff !important; content: "🖨 Phiếu GHTK"',
        // 'in-van-don-ntlog' => 'background: #FCD804; content: url(https://theanorganics.com/wp-content/uploads/2024/03/nhattin-logo-30x30-1.png);',
        'qr-code' => 'content: "▦ QR thanh toán"',
        'don-hoan-tien' => 'content: "↩ YC hoàn tiền KH"',
        'theo-doi-boi-hoan' => 'content: "🔎 Theo dõi bồi hoàn"',
        'theo-doi-hoan-tien' => 'content: "🔎 Theo dõi hoàn tiền KH"',
        'chinh-don-tao-don' => 'background: #08e097 !important; color: #002b1c !important; content: "✏ Chỉnh đơn"',
        'tra-don-ghtk' => 'background: green !important; opacity: 0.8; color:  #fff !important; content: "🔎 Tra đơn GHTK"',
        'tra-don-vtp' => 'background: red !important; opacity: 0.8; color:  #fff !important; content: "🔎 Tra đơn Viettel"',
        'hithean-order-payment' => 'background: #0068ff !important; color: #fff !important; content: "💳 Xác nhận TT"',
        'hithean-order-customer-confirmed' => 'background: #007a52 !important; color: #fff !important; content: "✓ Khách đã XN"',
        'hithean-order-export-images' => 'background: #6b46c1 !important; color: #fff !important; content: "📦 Ảnh xuất kho"',
        'hithean-order-export-confirm' => 'background: #15803d !important; color: #fff !important; content: "✓ Xác nhận XK"',
        'hithean-order-return-record' => 'background: #b45309 !important; color: #fff !important; content: "↩ Ghi nhận hoàn"',
        'hithean-order-return-images' => 'background: #7c3aed !important; color: #fff !important; content: "📷 Ảnh hoàn hàng"'
    ];

    if (wp_is_mobile()) {
        $styles['goi-sdt-dat-hang'] = 'content: "📞 Gọi số đặt";';
        $styles['goi-sdt-nhan-hang'] = 'content: "📞 Gọi số nhận";';
    }

    foreach ($styles as $action => $style) {
        echo "<style>.wc-action-button-$action::after { font-size: 15px; font-weight: 500 !important; $style }</style>";
    }

    // Add customizable button width via CSS variable
    echo '<style>.wc-action-button::after { border-radius: 5px; background: cornsilk; C6E1C6; color: black;}</style>';
}

function hithean_order_admin_is_list_screen(): bool
{
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

function hithean_order_admin_render_quick_action_modal(): void
{
    if (!hithean_order_admin_is_list_screen() || !current_user_can('manage_woocommerce')) {
        return;
    }

    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonces'  => [
            'payment'           => wp_create_nonce('confirm_order_payment_nonce'),
            'customerConfirmed' => wp_create_nonce(defined('ORDER_CREATOR_NONCE') ? ORDER_CREATOR_NONCE : 'order_creator_nonce'),
            'exportImages'      => wp_create_nonce('ajax_upload_images_nonce'),
            'exportConfirm'     => wp_create_nonce('ajax_confirm_export_nonce'),
        ],
        'checklist' => hithean_order_admin_export_checklist(),
    ];
    ?>
    <style>
        .hithean-order-modal[hidden] { display:none; }
        .hithean-order-modal { position:fixed; z-index:1000000; inset:0; display:grid; place-items:center; padding:24px; }
        .hithean-order-modal__backdrop { position:absolute; inset:0; background:rgba(15,23,42,.55); }
        .hithean-order-modal__dialog { position:relative; width:min(560px,100%); max-height:calc(100vh - 48px); overflow:auto; box-sizing:border-box; padding:24px; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(15,23,42,.32); }
        .hithean-order-modal h2 { margin:0 32px 8px 0; color:#172033; font-size:20px; line-height:1.3; }
        .hithean-order-modal p { margin:0 0 16px; color:#52606d; }
        .hithean-order-modal__close { position:absolute; top:12px; right:12px; width:32px; height:32px; border:0; border-radius:50%; background:#eef2f7; color:#334155; font-size:22px; cursor:pointer; }
        .hithean-order-modal__summary { margin:14px 0; padding:10px 12px; border-radius:8px; background:#f1f5f9; color:#334155; }
        .hithean-order-modal__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .hithean-order-modal__field { display:grid; gap:6px; font-weight:600; color:#334155; }
        .hithean-order-modal__field--wide { grid-column:1 / -1; }
        .hithean-order-modal input:not([type="checkbox"]), .hithean-order-modal select, .hithean-order-modal textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #cbd5e1; border-radius:7px; color:#172033; background:#fff; }
        .hithean-order-modal textarea { min-height:70px; resize:vertical; }
        .hithean-order-modal__checklist { display:grid; gap:9px; padding:12px; border:1px solid #dbe4ee; border-radius:8px; background:#f8fafc; }
        .hithean-order-modal__checklist label { display:flex; gap:8px; align-items:flex-start; color:#334155; }
        .hithean-order-modal__checklist input[type="checkbox"] { width:auto; margin:2px 0 0; flex:0 0 auto; }
        .hithean-order-modal__actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0; }
        .hithean-order-modal__button { padding:9px 15px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#334155; font-weight:600; cursor:pointer; }
        .hithean-order-modal__button--primary { border-color:#007a52; background:#08a875; color:#fff; }
        .hithean-order-modal__button:disabled { opacity:.55; cursor:wait; }
        .hithean-order-modal__notice { min-height:20px; margin-top:12px; font-weight:600; }
        .hithean-order-modal__notice.is-error { color:#b42318; }.hithean-order-modal__notice.is-success { color:#087443; }
        .hithean-order-toast { position:fixed; right:24px; bottom:24px; z-index:1000001; max-width:360px; padding:12px 16px; border-radius:8px; background:#087443; color:#fff; box-shadow:0 8px 24px rgba(15,23,42,.22); font-weight:600; }
        .wc-action-button.is-hithean-complete { pointer-events:none; opacity:.72; }.wc-action-button.is-hithean-complete::after { content:attr(data-hithean-complete-label) !important; }
        @media (max-width:600px) { .hithean-order-modal { padding:12px; }.hithean-order-modal__dialog { padding:20px; }.hithean-order-modal__grid { grid-template-columns:1fr; } }
    </style>
    <div class="hithean-order-modal" id="hithean-order-modal" hidden aria-hidden="true">
        <div class="hithean-order-modal__backdrop" data-hithean-close></div>
        <form class="hithean-order-modal__dialog" id="hithean-order-modal-form" enctype="multipart/form-data">
            <button type="button" class="hithean-order-modal__close" data-hithean-close aria-label="Đóng">&times;</button>
            <h2 data-hithean-title></h2><div class="hithean-order-modal__summary" data-hithean-summary></div>
            <div data-hithean-fields></div>
            <div class="hithean-order-modal__actions"><button type="button" class="hithean-order-modal__button" data-hithean-close>Hủy</button><button class="hithean-order-modal__button hithean-order-modal__button--primary" type="submit" data-hithean-submit></button></div>
            <div class="hithean-order-modal__notice" aria-live="polite"></div>
        </form>
    </div>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        var modal = document.getElementById('hithean-order-modal');
        var form = document.getElementById('hithean-order-modal-form');
        var active = null;
        var modes = {
            'hithean-order-payment': 'payment', 'hithean-order-customer-confirmed': 'customer',
            'hithean-order-export-images': 'images', 'hithean-order-export-confirm': 'export'
        };
        function today() { return new Date().toISOString().slice(0, 10); }
        function payload(button) { var url = new URL(button.href, window.location.origin); return { id:String(parseInt(url.searchParams.get('order_id'), 10) || 0), amount:url.searchParams.get('amount') || '' }; }
        function notice(message, error) { var el = form.querySelector('[aria-live]'); el.textContent = message || ''; el.classList.toggle('is-error', !!error); el.classList.toggle('is-success', !!message && !error); }
        function close() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); active = null; }
        function toast(message) { var el = document.createElement('div'); el.className = 'hithean-order-toast'; el.textContent = message; document.body.appendChild(el); window.setTimeout(function () { el.remove(); }, 5000); }
        function setLoading(loading, label) { var button = form.querySelector('[data-hithean-submit]'); if (!button.dataset.label) button.dataset.label = button.textContent; button.disabled = loading; button.textContent = loading ? label : button.dataset.label; }
        function fieldsFor(mode, data) {
            if (mode === 'payment') return '<input type="hidden" name="order_id" value="' + data.id + '"><div class="hithean-order-modal__grid"><label class="hithean-order-modal__field">Tài khoản nhận<select name="bank_account" required><option value="ACB 8700507">ACB 8700507</option><option value="Vietinbank 113600098383">Vietinbank 113600098383 / TK công ty</option><option value="ACB 11090087">ACB 11090087</option><option value="tiền mặt">tiền mặt</option></select></label><label class="hithean-order-modal__field">Ngày nhận<input type="date" name="paid_date" value="' + today() + '" required></label><label class="hithean-order-modal__field">Số tiền nhận<input type="number" name="amount_received" min="0" step="1000" value="' + data.amount + '"></label><label class="hithean-order-modal__field">Người chuyển khoản<select name="payer"><option value="customer">Khách hàng</option><option value="shipper">Shipper TT COD</option><option value="self">Nhân viên TT COD</option></select></label><label class="hithean-order-modal__field hithean-order-modal__field--wide" data-hithean-cod-note hidden>Ghi chú COD<textarea name="cod_note"></textarea></label></div>';
            if (mode === 'customer') return '<input type="hidden" name="order_id" value="' + data.id + '"><p>Hệ thống sẽ ghi nhận người xác nhận và chuyển đơn theo quy tắc giao nhanh / chuẩn bị bàn giao hiện có.</p>';
            if (mode === 'images') return '<input type="hidden" name="ueif_order_id" value="' + data.id + '"><label class="hithean-order-modal__field">Ảnh xuất kho (tối đa 5 ảnh)<input type="file" name="ueif_images[]" accept="image/jpeg,image/png" multiple required></label><div class="hithean-order-modal__checklist">' + cfg.checklist.map(function (item, index) { return '<label><input type="checkbox" name="checklist_' + index + '">' + item + '</label>'; }).join('') + '</div>';
            return '<input type="hidden" name="uexe_order_id" value="' + data.id + '"><p>Chỉ xác nhận khi ảnh xuất kho đã được tải lên và hàng đã bàn giao cho đơn này.</p>';
        }
        function open(button, mode) {
            var data = payload(button), labels = { payment:'Xác nhận thanh toán', customer:'Khách đã xác nhận COD', images:'Upload ảnh xuất kho', export:'Xác nhận xuất kho' }, submits = { payment:'Xác nhận', customer:'Xác nhận khách đã đồng ý', images:'Xác nhận và upload', export:'Xác nhận xuất kho' };
            active = { button:button, mode:mode, data:data }; form.reset(); notice('', false);
            form.querySelector('[data-hithean-title]').textContent = labels[mode]; form.querySelector('[data-hithean-summary]').textContent = mode === 'customer' ? 'Xác nhận khách đã đồng ý nhận đơn COD #' + data.id + '.' : 'Đơn #' + data.id;
            form.querySelector('[data-hithean-fields]').innerHTML = fieldsFor(mode, data); form.querySelector('[data-hithean-submit]').textContent = submits[mode]; form.querySelector('[data-hithean-submit]').dataset.label = submits[mode];
            modal.hidden = false; modal.setAttribute('aria-hidden', 'false');
        }
        function request(action, data) { data.append('action', action); return fetch(cfg.ajaxUrl, { method:'POST', credentials:'same-origin', body:data }).then(function (response) { return response.json(); }); }
        function message(response, fallback) { return !response || !response.data ? fallback : (typeof response.data === 'string' ? response.data : (response.data.message || fallback)); }
        function row(orderId, button) { return (button && button.closest('tr')) || document.getElementById('post-' + orderId) || document.getElementById('order-' + orderId) || document.querySelector('tr[data-order-id="' + String(parseInt(orderId, 10) || 0) + '"]'); }
        function updateStatus(orderId, status, label, button) { var current = row(orderId, button); if (!current || !status || !label) return; var cell = current.querySelector('.column-order_status, .column-status, [data-colname="Status"]'); if (!cell) return; var mark = cell.querySelector('mark.order-status'); if (!mark) { mark = document.createElement('mark'); cell.prepend(mark); } mark.className = 'order-status status-' + status.replace(/[^a-z0-9_-]/g, ''); mark.replaceChildren(); var span = document.createElement('span'); span.textContent = label; mark.appendChild(span); }
        function complete(button, label) { button.classList.add('is-hithean-complete'); button.dataset.hitheanCompleteLabel = label; button.setAttribute('aria-disabled', 'true'); button.removeAttribute('href'); }
        function toggleCodNote() { var note = form.querySelector('[data-hithean-cod-note]'); if (note) note.hidden = !['shipper', 'self'].includes(form.elements.payer.value); }
        document.addEventListener('click', function (event) {
            var linked = event.target.closest('.wc-action-button-chinh-don-tao-don, .wc-action-button-qr-code, .wc-action-button-in-van-don-ghtk, .wc-action-button-tra-don-ghtk, .wc-action-button-tra-don-vtp, .wc-action-button-theo-doi-boi-hoan, .wc-action-button-don-hoan-tien, .wc-action-button-theo-doi-hoan-tien');
            if (linked && linked.href) { event.preventDefault(); window.open(linked.href, '_blank', 'noopener'); return; }
            var button = event.target.closest('.wc-action-button-hithean-order-payment, .wc-action-button-hithean-order-customer-confirmed, .wc-action-button-hithean-order-export-images, .wc-action-button-hithean-order-export-confirm');
            if (!button || button.classList.contains('is-hithean-complete')) return; event.preventDefault(); Object.keys(modes).some(function (key) { if (!button.classList.contains('wc-action-button-' + key)) return false; open(button, modes[key]); return true; });
        });
        document.addEventListener('click', function (event) { if (event.target.closest('[data-hithean-close]')) close(); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) close(); });
        form.addEventListener('change', function (event) { if (event.target.name === 'payer') toggleCodNote(); });
        form.addEventListener('submit', function (event) {
            event.preventDefault(); if (!active) return; var fd = new FormData(form), action = '', nonce = '', loading = 'Đang xử lý...';
            if (active.mode === 'payment') { action = 'confirm_order_payment'; nonce = cfg.nonces.payment; fd.append('order_ids', active.data.id); }
            if (active.mode === 'customer') { action = 'order_creator_customer_confirmed'; nonce = cfg.nonces.customerConfirmed; }
            if (active.mode === 'images') { var files = form.querySelector('[type="file"]').files; if (!files.length) return notice('Hãy chọn ảnh xuất kho.', true); if (files.length > 5) return notice('Chỉ được upload tối đa 5 ảnh mỗi lần.', true); if (form.querySelectorAll('.hithean-order-modal__checklist input:not(:checked)').length) return notice('Hãy xác nhận đầy đủ checklist trước khi upload.', true); action = 'ajax_upload_images'; nonce = cfg.nonces.exportImages; loading = 'Đang upload...'; }
            if (active.mode === 'export') { action = 'ajax_confirm_export'; nonce = cfg.nonces.exportConfirm; }
            fd.append('nonce', nonce); setLoading(true, loading); request(action, fd).then(function (response) {
                if (!response.success) throw new Error(message(response, 'Không thể cập nhật đơn hàng.'));
                if (active.mode === 'payment') ((response.data && response.data.orders) || []).forEach(function (order) { updateStatus(order.order_id, order.status, order.status_label, active.button); });
                if (active.mode === 'customer') { updateStatus(response.data.order_id, response.data.status, response.data.status_label, active.button); complete(active.button, 'Đã xác nhận'); }
                if (active.mode === 'export') complete(active.button, 'Đã xuất kho'); close(); toast(message(response, 'Đã cập nhật đơn hàng.'));
            }).catch(function (error) { notice(error.message || 'Lỗi kết nối. Vui lòng thử lại.', true); }).finally(function () { setLoading(false); });
        });
    }());
    </script>
    <?php
}
add_action('admin_footer', 'hithean_order_admin_render_quick_action_modal');

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
        function request(action, data) { data.append('action', action); return fetch(cfg.ajaxUrl, { method:'POST', credentials:'same-origin', body:data }).then(function (response) { return response.json(); }); }
        function complete(button, label) { button.classList.add('is-hithean-complete'); button.dataset.hitheanCompleteLabel = label; button.removeAttribute('href'); }
        document.addEventListener('click', function (event) { var button = event.target.closest('.wc-action-button-hithean-order-return-record, .wc-action-button-hithean-order-return-images'); if (button) { event.preventDefault(); open(button.classList.contains('wc-action-button-hithean-order-return-record') ? 'record' : 'images', button); return; } if (event.target.closest('[data-hithean-return-close]')) close(event.target.closest('.hithean-return-modal')); });
        ['record', 'images'].forEach(function (type) { form(type).addEventListener('submit', function (event) { event.preventDefault(); var currentForm = event.currentTarget, submit = currentForm.querySelector('[type="submit"]'), files = currentForm.querySelector('[type="file"]'); if (files && (!files.files.length || files.files.length > 5)) { currentForm.querySelector('[aria-live]').textContent = files.files.length > 5 ? 'Chỉ được upload tối đa 5 ảnh.' : 'Hãy chọn ảnh hoàn hàng.'; return; } submit.disabled = true; var data = new FormData(currentForm), action = type === 'record' ? 'attach_return_order' : 'upload_return_images'; data.append('nonce', cfg.nonces[type]); if (type === 'record') data.append('attach_flow', 'pending'); request(action, data).then(function (response) { if (!response.success) throw new Error(message(response, 'Không thể cập nhật đơn hoàn.')); if (type === 'record') complete(active.button, 'Đã ghi nhận hoàn'); close(modal(type)); }).catch(function (error) { currentForm.querySelector('[aria-live]').textContent = error.message || 'Lỗi kết nối.'; }).finally(function () { submit.disabled = false; }); }); });
    }());
    </script>
    <?php
}
add_action('admin_footer', 'hithean_order_admin_render_return_action_modals');

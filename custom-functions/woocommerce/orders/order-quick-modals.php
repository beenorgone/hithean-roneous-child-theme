<?php
/**
 * Popup thao tác nhanh ở danh sách đơn admin (cột Thao tác, ô tìm đơn, board, Xem nhanh) — 4 modal riêng:
 *   Xác nhận thanh toán · Khách đã xác nhận COD · Ảnh xuất kho (xem ảnh đã up + up thêm + xác nhận xuất kho) · Xác nhận xuất kho.
 *
 * FILE GIỐNG HỆT ở theanmarket & hithean — sửa thì chép sang cả 2 site. Khác biệt giữa 2 site lấy qua
 * thean_qm_site() (tiền tố nút thao tác, hàm danh sách đơn / checklist), không sửa trong thân file.
 *
 * - Khung modal lặp lại (backdrop, nút đóng, tiêu đề, Hủy / nút chính, vùng thông báo): thean_qm_open() / thean_qm_close().
 * - TK nhận tiền: thean_qm_bank_accounts() — không viết cứng số TK (nguồn: BACS WooCommerce + cài đặt eim).
 * - Ảnh đã up: AJAX `thean_export_images_list` (khai ở file xác nhận xuất kho của mỗi site + danh sách AJAX được nạp).
 * - AJAX dùng chung: confirm_order_payment, order_creator_customer_confirmed, ajax_upload_images, ajax_confirm_export.
 */
if (!defined('ABSPATH')) exit;

/** Khác biệt giữa 2 site. */
function thean_qm_site(): array
{
    $hithean = function_exists('hithean_order_admin_is_list_screen');
    return [
        'prefix'   => $hithean ? 'hithean-order-' : 'thean-order-',
        'is_list'  => $hithean ? 'hithean_order_admin_is_list_screen' : 'thean_order_admin_is_list_screen',
        'checklist'=> $hithean ? 'hithean_order_admin_export_checklist' : 'thean_order_admin_export_checklist',
    ];
}

/**
 * TK nhận tiền cho ô chọn "Tài khoản nhận". Thứ tự nguồn:
 * hithean_payment_bank_accounts() (hithean, đã gộp eim) → eim_payment_bank_accounts() (TK eim + BACS)
 * → BACS WooCommerce. Luôn có "tiền mặt".
 */
function thean_qm_bank_accounts(): array
{
    if (function_exists('hithean_payment_bank_accounts')) {
        $accounts = hithean_payment_bank_accounts();
    } elseif (function_exists('eim_payment_bank_accounts')) {
        $accounts = eim_payment_bank_accounts();
    } else {
        $accounts = [];
        foreach ((array) get_option('woocommerce_bacs_accounts', []) as $acc) {
            $label = trim(trim((string) ($acc['bank_name'] ?? '')) . ' ' . trim((string) ($acc['account_number'] ?? '')));
            if ($label !== '') $accounts[] = $label;
        }
    }
    $accounts = array_values(array_filter(array_map('trim', array_map('strval', (array) $accounts))));
    if (!in_array('tiền mặt', $accounts, true)) $accounts[] = 'tiền mặt';
    return array_values(array_unique($accounts));
}

/** Mở khung modal: wrapper + backdrop + form + nút đóng + tiêu đề + dòng tóm tắt. */
function thean_qm_open(string $key, string $title, bool $multipart = false): void
{
    printf(
        '<div class="thean-quick-modal" id="thean-%1$s-modal" hidden aria-hidden="true"><div class="thean-quick-modal__backdrop" data-thean-close></div>'
        . '<form class="thean-quick-modal__dialog" id="thean-%1$s-form"%2$s><button type="button" class="thean-quick-modal__close" data-thean-close aria-label="Đóng">&times;</button>'
        . '<h2>%3$s</h2><div class="thean-quick-modal__summary" data-thean-summary></div>',
        esc_attr($key),
        $multipart ? ' enctype="multipart/form-data"' : '',
        esc_html($title)
    );
}

/** Đóng khung modal: thanh nút (Hủy + nút phụ tùy chọn + nút chính) + vùng thông báo. $extra là HTML đã escape. */
function thean_qm_close(string $submit, string $extra = ''): void
{
    echo '<div class="thean-quick-modal__actions"><button type="button" class="thean-quick-modal__button" data-thean-close>Hủy</button>'
        . $extra // phpcs:ignore WordPress.Security.EscapeOutput -- HTML tĩnh do file này truyền vào
        . '<button class="thean-quick-modal__button thean-quick-modal__button--primary" type="submit">' . esc_html($submit) . '</button></div>'
        . '<div class="thean-quick-modal__notice" aria-live="polite"></div></form></div>';
}

add_action('admin_footer', 'thean_qm_render');
function thean_qm_render(): void
{
    $site = thean_qm_site();
    if (!function_exists($site['is_list']) || !call_user_func($site['is_list']) || !current_user_can('manage_woocommerce')) {
        return;
    }

    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'prefix'  => $site['prefix'],
        'nonces'  => [
            'payment'           => wp_create_nonce('confirm_order_payment_nonce'),
            'customerConfirmed' => wp_create_nonce(defined('ORDER_CREATOR_NONCE') ? ORDER_CREATOR_NONCE : 'order_creator_nonce'),
            'exportImages'      => wp_create_nonce('ajax_upload_images_nonce'),
            'exportConfirm'     => wp_create_nonce('ajax_confirm_export_nonce'),
        ],
        'checklist' => function_exists($site['checklist']) ? (array) call_user_func($site['checklist']) : [],
    ];
    ?>
    <style>
        .thean-quick-modal[hidden] { display:none; }
        .thean-quick-modal { position:fixed; z-index:1000000; inset:0; display:grid; place-items:center; padding:24px; }
        .thean-quick-modal__backdrop { position:absolute; inset:0; background:rgba(15,23,42,.55); }
        .thean-quick-modal__dialog { position:relative; width:min(560px,100%); max-height:calc(100vh - 48px); overflow:auto; box-sizing:border-box; padding:24px; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(15,23,42,.32); }
        .thean-quick-modal h2 { margin:0 32px 8px 0; color:#172033; font-size:20px; line-height:1.3; }
        .thean-quick-modal p { margin:0 0 16px; color:#52606d; }
        .thean-quick-modal__close { position:absolute; top:12px; right:12px; width:32px; height:32px; border:0; border-radius:50%; background:#eef2f7; color:#334155; font-size:22px; cursor:pointer; }
        .thean-quick-modal__summary { margin:14px 0; padding:10px 12px; border-radius:8px; background:#f1f5f9; color:#334155; }
        .thean-quick-modal__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .thean-quick-modal__field { display:grid; gap:6px; font-weight:600; color:#334155; }
        .thean-quick-modal__field--wide { grid-column:1 / -1; }
        .thean-quick-modal input:not([type="checkbox"]), .thean-quick-modal select, .thean-quick-modal textarea { width:100%; box-sizing:border-box; padding:9px 10px; border:1px solid #cbd5e1; border-radius:7px; color:#172033; background:#fff; }
        .thean-quick-modal textarea { min-height:70px; resize:vertical; }
        .thean-quick-modal__checklist { display:grid; gap:9px; margin-top:14px; padding:12px; border:1px solid #dbe4ee; border-radius:8px; background:#f8fafc; }
        .thean-quick-modal__checklist label { display:flex; gap:8px; align-items:flex-start; color:#334155; }
        .thean-quick-modal__checklist input[type="checkbox"] { width:auto; margin:2px 0 0; flex:0 0 auto; }
        .thean-quick-modal__field--checkbox { display:flex; align-items:flex-start; gap:9px; margin-top:14px; padding:12px; border:1px solid #c7e7dc; border-radius:8px; background:#f0fdf8; }
        .thean-quick-modal__field--checkbox input { width:auto; margin:3px 0 0; }
        .thean-quick-modal__field--checkbox small { display:block; margin-top:4px; color:#52606d; font-weight:400; line-height:1.45; }
        .thean-quick-modal__existing { display:grid; gap:4px; margin-bottom:14px; padding:12px; border:1px solid #dbe4ee; border-radius:8px; background:#f8fafc; color:#334155; }
        .thean-quick-modal__existing small { color:#52606d; }
        .thean-quick-modal__existing .is-ok { color:#087443; font-weight:600; }
        .thean-quick-modal__thumbs { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
        .thean-quick-modal__thumbs a { display:block; width:80px; height:80px; overflow:hidden; border:1px solid #cbd5e1; border-radius:6px; }
        .thean-quick-modal__thumbs img { display:block; width:100%; height:100%; object-fit:cover; }
        .thean-quick-modal__actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e2e8f0; }
        .thean-quick-modal__button { padding:9px 15px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#334155; font-weight:600; cursor:pointer; }
        .thean-quick-modal__button[hidden] { display:none; }
        .thean-quick-modal__button--primary { border-color:#007a52; background:#08a875; color:#fff; }
        .thean-quick-modal__button--confirm { border-color:#0047ba; background:#0047ba; color:#fff; }
        .thean-quick-modal__button:disabled { opacity:.55; cursor:wait; }
        .thean-quick-modal__notice { min-height:20px; margin-top:12px; font-weight:600; }
        .thean-quick-modal__notice.is-error { color:#b42318; }
        .thean-quick-modal__notice.is-success { color:#087443; }
        .thean-quick-toast { position:fixed; right:24px; bottom:24px; z-index:1000001; max-width:360px; padding:12px 16px; border-radius:8px; background:#087443; color:#fff; box-shadow:0 8px 24px rgba(15,23,42,.22); font-weight:600; }
        body.thean-quick-modal-open { overflow:hidden; }
        .wc-action-button.is-thean-complete { pointer-events:none; opacity:.72; }
        .wc-action-button.is-thean-complete::after { content:attr(data-thean-complete-label) !important; }
        @media (max-width:600px) { .thean-quick-modal { padding:12px; } .thean-quick-modal__dialog { padding:20px; } .thean-quick-modal__grid { grid-template-columns:1fr; } }
    </style>

    <?php thean_qm_open('payment', 'Xác nhận thanh toán'); ?>
        <input type="hidden" name="order_id">
        <div class="thean-quick-modal__grid">
            <label class="thean-quick-modal__field">Tài khoản nhận<select name="bank_account" required>
                <?php foreach (thean_qm_bank_accounts() as $account) : ?>
                    <option value="<?php echo esc_attr($account); ?>"><?php echo esc_html($account); ?></option>
                <?php endforeach; ?>
            </select></label>
            <label class="thean-quick-modal__field">Ngày nhận<input type="date" name="paid_date" required></label>
            <label class="thean-quick-modal__field">Số tiền nhận<input type="number" name="amount_received" min="0" step="1000"></label>
            <label class="thean-quick-modal__field">Người chuyển khoản<select name="payer"><option value="customer">Khách hàng</option><option value="shipper">Shipper TT COD</option><option value="self">Nhân viên TT COD</option></select></label>
            <label class="thean-quick-modal__field thean-quick-modal__field--wide" data-thean-cod-note hidden>Ghi chú COD<textarea name="cod_note"></textarea></label>
        </div>
    <?php thean_qm_close('Xác nhận'); ?>

    <?php thean_qm_open('customer-confirmed', 'Khách đã xác nhận COD'); ?>
        <input type="hidden" name="order_id">
        <p>Hệ thống sẽ ghi nhận người xác nhận và chuyển đơn theo quy tắc giao nhanh / chuẩn bị bàn giao hiện có.</p>
    <?php thean_qm_close('Xác nhận khách đã đồng ý'); ?>

    <?php thean_qm_open('export-images', '📦 Ảnh xuất kho', true); ?>
        <input type="hidden" name="ueif_order_id">
        <div class="thean-quick-modal__existing" data-thean-existing></div>
        <label class="thean-quick-modal__field">Up thêm ảnh (tối đa 5 ảnh / lần — ảnh mới được thêm vào, không thay ảnh cũ)<input type="file" name="ueif_images[]" accept="image/jpeg,image/png" multiple></label>
        <label class="thean-quick-modal__field thean-quick-modal__field--checkbox"><input type="checkbox" name="ueif_ai_check" value="1" checked> <span><strong>Gọi AI kiểm tra ảnh sau khi upload</strong><small>Ảnh sẽ được gửi tới AI provider đã cấu hình để đối chiếu mã đơn, sản phẩm và số lượng; kết quả chỉ hỗ trợ kiểm tra, không tự xác nhận xuất kho.</small></span></label>
        <div class="thean-quick-modal__checklist" data-thean-checklist></div>
    <?php thean_qm_close('Upload ảnh', '<button type="button" class="thean-quick-modal__button thean-quick-modal__button--confirm" data-thean-export-confirm hidden>✓ Xác nhận xuất kho</button>'); ?>

    <?php thean_qm_open('export-confirm', 'Xác nhận xuất kho'); ?>
        <input type="hidden" name="uexe_order_id">
        <p>Chỉ xác nhận khi ảnh xuất kho đã được tải lên và hàng đã bàn giao cho đơn này.</p>
    <?php thean_qm_close('Xác nhận xuất kho'); ?>

    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        var P = cfg.prefix, activeButton = null, activeOrder = '';
        // Đuôi slug nút thao tác (sau tiền tố site) = khóa modal.
        var KEYS = ['payment', 'customer-confirmed', 'export-images', 'export-confirm'];

        function formOf(key) { return document.getElementById('thean-' + key + '-form'); }
        function modalOf(form) { return form.closest('.thean-quick-modal'); }
        function payload(button) { var url = new URL(button.href, window.location.origin); return { orderId: String(parseInt(url.searchParams.get('order_id'), 10) || ''), amount: url.searchParams.get('amount') || '' }; }
        function today() { return new Date().toISOString().slice(0, 10); }
        function esc(v) { return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        function setNotice(form, message, isError) { var el = form.querySelector('.thean-quick-modal__notice'); el.textContent = message || ''; el.classList.toggle('is-error', !!isError); el.classList.toggle('is-success', !!message && !isError); }
        function setLoading(form, loading, label) { var submit = form.querySelector('[type="submit"]'); if (!submit.dataset.defaultLabel) submit.dataset.defaultLabel = submit.textContent; submit.disabled = loading; submit.textContent = loading ? (label || 'Đang xử lý...') : submit.dataset.defaultLabel; }
        function closeModal(modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('thean-quick-modal-open'); activeButton = null; activeOrder = ''; }
        function toast(message) { var el = document.createElement('div'); el.className = 'thean-quick-toast'; el.textContent = message; document.body.appendChild(el); window.setTimeout(function () { el.remove(); }, 5000); }
        function messageFrom(response, fallback) { if (!response || !response.data) return fallback; return typeof response.data === 'string' ? response.data : (response.data.message || fallback); }
        function request(action, fd) {
            fd.append('action', action);
            var ids = String(fd.get('order_id') || fd.get('ueif_order_id') || fd.get('uexe_order_id') || fd.get('order_ids') || '');
            return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (response) {
                if (response.success && ids && action !== 'thean_export_images_list') document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderIds: ids.split(',') } }));
                return response;
            });
        }
        // 2 site dùng class "đã xong" khác nhau (cột Thao tác thean-oa / hithean-oa) → gắn cả 2.
        function markComplete(button, label) { if (!button) return; button.classList.add('is-thean-complete', 'is-hithean-complete'); button.dataset.theanCompleteLabel = label; button.dataset.hitheanCompleteLabel = label; button.setAttribute('aria-disabled', 'true'); button.removeAttribute('href'); }
        function findRow(orderId, button) { if (button && button.closest('tr')) return button.closest('tr'); var id = String(parseInt(orderId, 10) || 0); return document.getElementById('post-' + id) || document.getElementById('order-' + id) || document.querySelector('tr[data-order-id="' + id + '"]'); }
        function updateOrderStatus(orderId, status, label, button) {
            var row = findRow(orderId, button); if (!row || !status || !label) return;
            var cell = row.querySelector('.column-order_status, .column-status, [data-colname="Status"]'); if (!cell) return;
            var mark = cell.querySelector('mark.order-status'); if (!mark) { mark = document.createElement('mark'); cell.prepend(mark); }
            mark.className = 'order-status status-' + status.replace(/[^a-z0-9_-]/g, ''); mark.replaceChildren();
            var span = document.createElement('span'); span.textContent = label; mark.appendChild(span);
        }
        function renderChecklist(form) {
            var holder = form.querySelector('[data-thean-checklist]'); holder.replaceChildren();
            (cfg.checklist || []).forEach(function (text, index) { var label = document.createElement('label'), input = document.createElement('input'); input.type = 'checkbox'; input.name = 'checklist_' + index; label.append(input, document.createTextNode(text)); holder.appendChild(label); });
            holder.hidden = !(cfg.checklist || []).length;
        }
        function toggleCodNote(form) { form.querySelector('[data-thean-cod-note]').hidden = !['shipper', 'self'].includes(form.elements.payer.value); }

        // Popup Ảnh xuất kho: ảnh đã up (AJAX thean_export_images_list) + trạng thái AI / xác nhận; nút Xác nhận xuất kho khi có ảnh mà chưa xác nhận.
        function loadExisting(orderId) {
            var form = formOf('export-images'), box = form.querySelector('[data-thean-existing]'), confirmBtn = form.querySelector('[data-thean-export-confirm]');
            confirmBtn.hidden = true; box.innerHTML = '<small>Đang tải ảnh đã up…</small>';
            var fd = new FormData(); fd.append('order_id', orderId); fd.append('nonce', cfg.nonces.exportImages);
            request('thean_export_images_list', fd).then(function (response) {
                if (activeOrder !== orderId || modalOf(form).hidden) return;
                if (!response.success) { box.innerHTML = '<small>' + esc(messageFrom(response, 'Không tải được ảnh đã up.')) + '</small>'; return; }
                var d = response.data || {}, urls = d.images || [], html;
                html = urls.length
                    ? '<strong>Đã có ' + urls.length + ' ảnh xuất kho</strong><div class="thean-quick-modal__thumbs">' + urls.map(function (u) { return '<a href="' + esc(u) + '" target="_blank" rel="noopener"><img src="' + esc(u) + '" alt="Ảnh xuất kho" loading="lazy"></a>'; }).join('') + '</div>'
                    : '<strong>Chưa có ảnh xuất kho</strong><small>Up ảnh bên dưới rồi mới xác nhận xuất kho được.</small>';
                if (d.ai) html += '<small>🤖 AI: ' + esc(d.ai) + '</small>';
                if (d.confirmed) html += '<small class="is-ok">✅ Đã xác nhận xuất kho' + (d.confirmed_by ? ' bởi ' + esc(d.confirmed_by) : '') + '</small>';
                box.innerHTML = html;
                confirmBtn.hidden = !(urls.length && !d.confirmed);
            }).catch(function () { box.innerHTML = '<small>Không tải được ảnh đã up.</small>'; });
        }

        function openModal(key, button) {
            var form = formOf(key), data = payload(button), summary = form.querySelector('[data-thean-summary]');
            activeButton = button; activeOrder = data.orderId;
            form.reset(); setNotice(form, '', false); summary.textContent = 'Đơn #' + data.orderId;
            if (key === 'payment') { form.elements.order_id.value = data.orderId; form.elements.amount_received.value = data.amount; form.elements.paid_date.value = today(); toggleCodNote(form); }
            if (key === 'customer-confirmed') { form.elements.order_id.value = data.orderId; summary.textContent = 'Xác nhận khách đã đồng ý nhận đơn COD #' + data.orderId + '.'; }
            if (key === 'export-images') { form.elements.ueif_order_id.value = data.orderId; renderChecklist(form); loadExisting(data.orderId); }
            if (key === 'export-confirm') form.elements.uexe_order_id.value = data.orderId;
            var modal = modalOf(form); modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('thean-quick-modal-open');
        }
        function openHubPay(button) {
            if (button.dataset.theanBusy) return; button.dataset.theanBusy = '1';
            window.hubPay.open({ orderId: payload(button).orderId, onDone: function (confirmed) {
                if (!confirmed) return; markComplete(button, 'Đã xác nhận TT');
                document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: payload(button).orderId } })); toast('Đã xác nhận thanh toán.');
            } }).catch(function (error) { toast(error.message || 'Không mở được công cụ xác nhận thanh toán.'); }).then(function () { delete button.dataset.theanBusy; });
        }

        document.addEventListener('click', function (event) {
            var selector = KEYS.map(function (k) { return '.wc-action-button-' + P + k; }).join(', ');
            var button = event.target.closest(selector);
            if (!button || button.classList.contains('is-thean-complete') || button.classList.contains('is-hithean-complete')) return;
            event.preventDefault();
            // Plugin eim chạy → xác nhận thanh toán bằng công cụ của hub (khớp giao dịch Sepay — payment-hub-link.php).
            if (button.classList.contains('wc-action-button-' + P + 'payment') && window.hubPay && window.hubPay.enabled) { openHubPay(button); return; }
            KEYS.some(function (k) { if (!button.classList.contains('wc-action-button-' + P + k)) return false; openModal(k, button); return true; });
        });
        document.addEventListener('click', function (event) { if (!event.target.closest('[data-thean-close]')) return; var modal = event.target.closest('.thean-quick-modal'); if (modal) closeModal(modal); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') document.querySelectorAll('.thean-quick-modal:not([hidden])').forEach(closeModal); });

        function submit(key, run) {
            formOf(key).addEventListener('submit', function (event) { event.preventDefault(); run(event.currentTarget); });
        }
        function fail(form) { return function (error) { setNotice(form, error.message || 'Lỗi kết nối. Vui lòng thử lại.', true); }; }

        formOf('payment').addEventListener('change', function (event) { if (event.target.name === 'payer') toggleCodNote(event.currentTarget); });
        submit('payment', function (form) {
            setLoading(form, true); var fd = new FormData(form); fd.append('order_ids', form.elements.order_id.value); fd.append('nonce', cfg.nonces.payment);
            request('confirm_order_payment', fd).then(function (response) {
                if (!response.success) throw new Error(messageFrom(response, 'Không thể xác nhận thanh toán.'));
                ((response.data && response.data.orders) || []).forEach(function (o) { updateOrderStatus(o.order_id, o.status, o.status_label, activeButton); });
                closeModal(modalOf(form)); toast(messageFrom(response, 'Đã xác nhận thanh toán.'));
            }).catch(fail(form)).finally(function () { setLoading(form, false); });
        });
        submit('customer-confirmed', function (form) {
            setLoading(form, true); var fd = new FormData(form); fd.append('nonce', cfg.nonces.customerConfirmed);
            request('order_creator_customer_confirmed', fd).then(function (response) {
                if (!response.success) throw new Error(messageFrom(response, 'Không thể xác nhận khách.'));
                updateOrderStatus(response.data.order_id, response.data.status, response.data.status_label, activeButton);
                markComplete(activeButton, 'Đã xác nhận'); closeModal(modalOf(form)); toast(messageFrom(response, 'Đã xác nhận khách hàng.'));
            }).catch(fail(form)).finally(function () { setLoading(form, false); });
        });
        submit('export-images', function (form) {
            var files = form.querySelector('[type="file"]').files;
            if (!files.length) { setNotice(form, 'Hãy chọn ảnh xuất kho để upload.', true); return; }
            if (files.length > 5) { setNotice(form, 'Chỉ được upload tối đa 5 ảnh mỗi lần.', true); return; }
            if (form.querySelectorAll('[data-thean-checklist] input:not(:checked)').length) { setNotice(form, 'Hãy xác nhận đầy đủ checklist trước khi upload.', true); return; }
            var aiCheck = form.elements.ueif_ai_check && form.elements.ueif_ai_check.checked, orderId = activeOrder;
            setLoading(form, true, aiCheck ? 'Đang upload, đưa AI vào hàng đợi...' : 'Đang upload...');
            var fd = new FormData(form); fd.append('nonce', cfg.nonces.exportImages);
            request('ajax_upload_images', fd).then(function (response) {
                if (!response.success) throw new Error(messageFrom(response, 'Không thể upload ảnh.'));
                // Giữ popup mở: tải lại ảnh để xác nhận xuất kho ngay.
                form.querySelector('[type="file"]').value = ''; setNotice(form, messageFrom(response, 'Đã upload ảnh xuất kho.'), false); loadExisting(orderId);
            }).catch(fail(form)).finally(function () { setLoading(form, false); });
        });
        formOf('export-images').querySelector('[data-thean-export-confirm]').addEventListener('click', function () {
            var form = formOf('export-images'), btn = this, fd = new FormData(); fd.append('uexe_order_id', activeOrder); fd.append('nonce', cfg.nonces.exportConfirm);
            btn.disabled = true;
            request('ajax_confirm_export', fd).then(function (response) {
                if (!response.success) throw new Error(messageFrom(response, 'Không thể xác nhận xuất kho.'));
                var row = findRow(activeOrder, activeButton); markComplete(row && row.querySelector('.wc-action-button-' + P + 'export-confirm'), 'Đã xuất kho');
                closeModal(modalOf(form)); toast(messageFrom(response, 'Đã xác nhận xuất kho.'));
            }).catch(fail(form)).finally(function () { btn.disabled = false; });
        });
        submit('export-confirm', function (form) {
            setLoading(form, true); var fd = new FormData(form); fd.append('nonce', cfg.nonces.exportConfirm);
            request('ajax_confirm_export', fd).then(function (response) {
                if (!response.success) throw new Error(messageFrom(response, 'Không thể xác nhận xuất kho.'));
                markComplete(activeButton, 'Đã xuất kho'); closeModal(modalOf(form)); toast(messageFrom(response, 'Đã xác nhận xuất kho.'));
            }).catch(fail(form)).finally(function () { setLoading(form, false); });
        });
    }());
    </script>
    <?php
}

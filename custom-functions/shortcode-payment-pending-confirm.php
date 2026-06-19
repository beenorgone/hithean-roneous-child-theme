<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [order_payment_pending_confirm]
 * Purpose: Admin/Manager tool for loading pending payment confirmations on demand.
 */
add_shortcode('order_payment_pending_confirm', 'oppc_shortcode');

function oppc_shortcode()
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();
    oppc_render_ui();
    oppc_render_assets();
    return ob_get_clean();
}

function oppc_default_date_from(): string
{
    return wp_date('Y-m-d', strtotime('-14 days', current_time('timestamp')));
}

function oppc_default_date_to(): string
{
    return wp_date('Y-m-d', current_time('timestamp'));
}

function oppc_get_order_status_options(): array
{
    if (!function_exists('wc_get_order_statuses')) {
        return [];
    }

    $statuses = [];
    foreach (wc_get_order_statuses() as $key => $label) {
        $statuses[str_replace('wc-', '', $key)] = $label;
    }

    return $statuses;
}

function oppc_get_confirmer_options(): array
{
    $users = get_users([
        'capability' => 'manage_woocommerce',
        'fields' => ['ID', 'display_name', 'user_login'],
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);

    return is_array($users) ? $users : [];
}

function oppc_render_ui(): void
{
    $date_from = oppc_default_date_from();
    $date_to = oppc_default_date_to();
    $statuses = oppc_get_order_status_options();
    $confirmers = oppc_get_confirmer_options();
    ?>
    <div class="oppc" data-default-from="<?php echo esc_attr($date_from); ?>" data-default-to="<?php echo esc_attr($date_to); ?>">
        <div class="oppc__panel">
            <h2>Đơn cần xác nhận thanh toán</h2>
            <p>Tải dữ liệu khi cần để tránh query đơn hàng lúc mở trang.</p>
        </div>

        <div class="oppc__panel oppc__filters">
            <label>
                <span>Từ ngày</span>
                <input type="date" id="oppc_date_from" value="<?php echo esc_attr($date_from); ?>">
            </label>
            <label>
                <span>Đến ngày</span>
                <input type="date" id="oppc_date_to" value="<?php echo esc_attr($date_to); ?>">
            </label>
            <label>
                <span>Trạng thái đơn</span>
                <select id="oppc_order_status">
                    <option value="all_active">Tất cả trạng thái đang xử lý</option>
                    <?php foreach ($statuses as $status_key => $status_label) : ?>
                        <option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Nhóm thanh toán</span>
                <select id="oppc_payment_bucket">
                    <option value="all_pending">Tất cả cần xác nhận</option>
                    <option value="bacs_pending">Chuyển khoản chưa xác nhận</option>
                    <option value="cod_pending">COD cần xác nhận nội bộ</option>
                </select>
            </label>
            <label>
                <span>Người xác nhận</span>
                <select id="oppc_confirmed_by">
                    <option value="">Tất cả người xác nhận</option>
                    <?php foreach ($confirmers as $user) : ?>
                        <option value="<?php echo esc_attr((string) $user->ID); ?>"><?php echo esc_html($user->display_name ?: $user->user_login); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="oppc__wide">
                <span>Từ khóa</span>
                <input type="search" id="oppc_keyword" placeholder="Mã đơn, SĐT, tên khách hàng">
            </label>
            <div class="oppc__actions oppc__wide">
                <button type="button" id="oppc_load" class="button--small button--green">Tải đơn cần xác nhận</button>
                <button type="button" id="oppc_reset" class="button--small button--white">Reset 14 ngày</button>
            </div>
        </div>

        <div id="oppc_notice" class="oppc__notice" aria-live="polite"></div>
        <div id="oppc_results" class="oppc__panel oppc__results" hidden>
            <p>Chưa tải dữ liệu.</p>
        </div>
    </div>

    <div id="oppc_confirm_modal" class="oppc-modal" hidden>
        <div class="oppc-modal__backdrop" data-ppc-close="1"></div>
        <div class="oppc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="oppc_confirm_title">
            <button type="button" class="oppc-modal__close" data-ppc-close="1" aria-label="Đóng">&times;</button>
            <h3 id="oppc_confirm_title">Xác nhận thanh toán</h3>
            <label>Thông tin đơn</label>
            <textarea id="oppc_order_summary" rows="3" readonly></textarea>
            <div class="oppc__modal-grid">
                <label>
                    <span>Tài khoản nhận</span>
                    <select id="oppc_bank_account">
                        <option value="ACB 8700507">ACB 8700507</option>
                        <option value="Vietinbank 113600098383">Vietinbank 113600098383 / TK công ty</option>
                        <option value="ACB 11090087">ACB 11090087</option>
                        <option value="tiền mặt">tiền mặt</option>
                    </select>
                </label>
                <label>
                    <span>Ngày nhận CK</span>
                    <input type="date" id="oppc_paid_date" value="<?php echo esc_attr($date_to); ?>">
                </label>
                <label>
                    <span>Số tiền nhận</span>
                    <input type="number" id="oppc_amount" min="0" step="1000">
                </label>
                <label>
                    <span>Người chuyển/nộp tiền</span>
                    <select id="oppc_payer">
                        <option value="customer">Khách hàng</option>
                        <option value="shipper">Shipper TT COD</option>
                        <option value="self">Nhân viên TT COD</option>
                    </select>
                </label>
                <label class="oppc__wide">
                    <span>Ghi chú</span>
                    <textarea id="oppc_note" rows="3"></textarea>
                </label>
            </div>
            <div class="oppc__actions">
                <button type="button" class="button--small button--white" data-ppc-close="1">Hủy</button>
                <button type="button" id="oppc_confirm" class="button--small button--green">Xác nhận</button>
            </div>
        </div>
    </div>

    <div id="oppc_sms_modal" class="oppc-modal" hidden>
        <div class="oppc-modal__backdrop" data-ppc-sms-close="1"></div>
        <div class="oppc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="oppc_sms_title">
            <button type="button" class="oppc-modal__close" data-ppc-sms-close="1" aria-label="Đóng">&times;</button>
            <h3 id="oppc_sms_title">Gửi SMS</h3>
            <label>Số điện thoại</label>
            <input type="text" id="oppc_sms_phone" class="oppc-copy" readonly>
            <p><a class="button--black" href="https://messages.google.com/web/conversations/new" target="_blank" rel="noopener noreferrer">Mở Google Messages</a></p>
            <label>Nội dung: Đã nhận CK</label>
            <textarea id="oppc_sms_paid" class="oppc-copy" rows="3" readonly></textarea>
            <label>Nội dung: Thu hồi SMS</label>
            <textarea id="oppc_sms_recall" class="oppc-copy" rows="3" readonly></textarea>
        </div>
    </div>
    <?php
}

function oppc_render_assets(): void
{
    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'loadNonce' => wp_create_nonce('oppc_load_orders'),
        'confirmNonce' => wp_create_nonce('oppc_confirm_payment'),
    ];
    ?>
    <style>
        .oppc, .oppc *, .oppc-modal, .oppc-modal * { box-sizing: border-box; }
        .oppc { display: grid; gap: 10px; width: 100%; max-width: 100%; min-width: 0; overflow-x: hidden; font-size: 12px; line-height: 1.35; }
        .oppc__panel, .oppc__notice { width: 100%; max-width: 100%; min-width: 0; overflow: hidden; background: #fff; border: 1px solid #d6dee7; border-radius: 8px; padding: 10px 12px; }
        .oppc__panel h2, .oppc__panel p { margin: 0; overflow-wrap: anywhere; }
        .oppc__filters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px 10px; align-items: end; }
        .oppc__filters > *, .oppc label { min-width: 0; max-width: 100%; }
        .oppc label span { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }
        .oppc input, .oppc select, .oppc textarea, .oppc-modal input, .oppc-modal select, .oppc-modal textarea { width: 100%; max-width: 100%; min-width: 0; border: 1px solid #c9d3df; border-radius: 6px; padding: 6px 8px; background: #fff; font-size: 12px; line-height: 1.3; }
        .oppc__wide { grid-column: 1 / -1; }
        .oppc__actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .oppc__notice { display: none; }
        .oppc__notice.is-visible { display: block; }
        .oppc__notice.is-success { border-color: #b8ddc7; background: #edf8f1; color: #1f6b3a; }
        .oppc__notice.is-error { border-color: #efc0c0; background: #fff2f2; color: #9c2f2f; }
        .oppc-table-wrap { width: 100%; max-width: 100%; min-width: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .oppc-table { width: 100%; min-width: 860px; border-collapse: collapse; table-layout: fixed; }
        .oppc-table th, .oppc-table td { padding: 7px 8px !important; border-bottom: 1px solid #e5ebf1; text-align: left; vertical-align: top; overflow-wrap: anywhere; font-size: 12px; line-height: 1.35; }
        .oppc-table th { background: #f6f8fb; color: #5b6470; font-size: 12px; text-transform: uppercase; }
        .oppc-table ul { margin: 0; padding-left: 14px; }
        .oppc-table li { margin: 0 0 2px; }
        .oppc-recent { margin-bottom: 12px; }
        .oppc-recent h3 { margin: 0 0 8px; font-size: 14px; }
        .oppc-muted { display: block; margin-top: 2px; color: #5b6470; font-size: 12px; line-height: 1.3; }
        .oppc-result-summary { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 8px 0; padding: 8px 10px; border: 1px solid #d6dee7; border-radius: 6px; background: #f6f8fb; font-size: 12px; line-height: 1.35; }
        .oppc-result-summary strong { color: #111827; }
        .oppc-bulk-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 8px 0; }
        .oppc-row-actions { display: flex; flex-wrap: wrap; gap: 5px; }
        .oppc-select-cell { width: 34px; text-align: center; }
        .oppc input.oppc-row-select, .oppc input#oppc_select_all { width: auto; min-width: 0; }
        .oppc-modal[hidden] { display: none; }
        .oppc-modal { position: fixed; inset: 0; z-index: 9999; display: block; width: 100%; height: 100%; padding: 0; overflow: visible; pointer-events: none; }
        .oppc-modal__backdrop { position: fixed; inset: 0; background: rgba(17, 24, 39, .35); pointer-events: auto; }
        .oppc-modal__dialog { position: fixed; width: min(680px, calc(100vw - 32px)); max-height: min(680px, calc(100vh - 32px)); overflow: auto; background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 18px 60px rgba(0,0,0,.22); pointer-events: auto; font-size: 12px; line-height: 1.35; }
        .oppc-modal__close { position: absolute; top: 10px; right: 12px; border: 0; background: transparent; font-size: 30px; line-height: 1; cursor: pointer; }
        .oppc__modal-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 10px 0; }
        .oppc-copy { cursor: copy; }
        .oppc .button--small, .oppc-modal .button--small, .oppc .button--green, .oppc .button--white, .oppc-modal .button--green, .oppc-modal .button--white { min-height: 30px; padding: 5px 9px; font-size: 12px; line-height: 1.2; border-radius: 6px; }
        body.oppc-modal-open { overflow: hidden; }
        @media (max-width: 1199px) {
            .oppc__filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .oppc__filters, .oppc__modal-grid { grid-template-columns: 1fr; }
            .oppc-table, .oppc-table thead, .oppc-table tbody, .oppc-table tr, .oppc-table th, .oppc-table td { display: block; width: 100%; }
            .oppc-table { min-width: 0; }
            .oppc-table thead { display: none; }
            .oppc-table tbody { display: grid; gap: 12px; }
            .oppc-table tr { border: 1px solid #d6dee7; border-radius: 8px; overflow: hidden; }
            .oppc-table td::before { content: attr(data-label); display: block; margin-bottom: 6px; font-size: 12px; text-transform: uppercase; color: #5b6470; }
        }
    </style>
    <script>
        var oppcConfig = <?php echo wp_json_encode($config); ?>;
        document.addEventListener('DOMContentLoaded', function() {
            var root = document.querySelector('.oppc');
            if (!root) return;

            var state = { orderId: '', orderIds: [], lastLoaded: false, selectedRow: null, selectedRows: [] };
            var noticeEl = document.getElementById('oppc_notice');
            var resultsEl = document.getElementById('oppc_results');
            var loadBtn = document.getElementById('oppc_load');
            var resetBtn = document.getElementById('oppc_reset');
            var confirmModal = document.getElementById('oppc_confirm_modal');
            var smsModal = document.getElementById('oppc_sms_modal');
            var confirmBtn = document.getElementById('oppc_confirm');

            function showNotice(message, type) {
                noticeEl.textContent = message || '';
                noticeEl.className = 'oppc__notice is-visible ' + (type === 'success' ? 'is-success' : 'is-error');
            }

            function clearNotice() {
                noticeEl.textContent = '';
                noticeEl.className = 'oppc__notice';
            }

            function setLoading(button, loading, loadingText, idleText) {
                button.disabled = !!loading;
                button.textContent = loading ? loadingText : idleText;
            }

            function filters() {
                return {
                    action: 'oppc_load_orders',
                    nonce: oppcConfig.loadNonce,
                    date_from: document.getElementById('oppc_date_from').value,
                    date_to: document.getElementById('oppc_date_to').value,
                    order_status: document.getElementById('oppc_order_status').value,
                    payment_bucket: document.getElementById('oppc_payment_bucket').value,
                    confirmed_by: document.getElementById('oppc_confirmed_by').value,
                    keyword: document.getElementById('oppc_keyword').value
                };
            }

            function loadOrders(keepNotice) {
                if (!keepNotice) clearNotice();
                setLoading(loadBtn, true, 'Đang tải...', 'Lọc đơn');

                jQuery.post(oppcConfig.ajaxUrl, filters(), function(response) {
                    if (response && response.success && response.data) {
                        resultsEl.innerHTML = response.data.html || '';
                        resultsEl.hidden = false;
                        state.orderId = '';
                        state.orderIds = [];
                        state.selectedRow = null;
                        state.selectedRows = [];
                        updateBulkState();
                        state.lastLoaded = true;
                        if (!keepNotice) showNotice(response.data.message || 'Đã tải dữ liệu.', 'success');
                    } else {
                        resultsEl.innerHTML = '<p>Không có dữ liệu phù hợp.</p>';
                        resultsEl.hidden = false;
                        state.orderId = '';
                        state.orderIds = [];
                        state.selectedRow = null;
                        state.selectedRows = [];
                        updateBulkState();
                        showNotice(response && response.data && response.data.message ? response.data.message : 'Không tải được dữ liệu.', 'error');
                    }
                }).fail(function() {
                    showNotice('Yêu cầu tải dữ liệu thất bại.', 'error');
                }).always(function() {
                    setLoading(loadBtn, false, 'Đang tải...', 'Lọc đơn');
                });
            }

            function placeAnchoredModal(modal, button) {
                var dialog = modal.querySelector('.oppc-modal__dialog');
                if (!dialog) return;

                var rect = button.getBoundingClientRect();
                var margin = 16;
                var dialogWidth = Math.min(760, window.innerWidth - (margin * 2));
                var left = rect.left;
                var maxLeft = window.innerWidth - dialogWidth - margin;

                left = Math.max(margin, Math.min(left, maxLeft));
                dialog.style.width = dialogWidth + 'px';
                dialog.style.left = left + 'px';

                var dialogHeight = Math.min(dialog.offsetHeight || 520, window.innerHeight - (margin * 2));
                var preferredTop = rect.bottom + 8;
                var maxTop = window.innerHeight - dialogHeight - margin;
                var top = preferredTop;

                if (top > maxTop) {
                    top = rect.top - dialogHeight - 8;
                }

                top = Math.max(margin, Math.min(top, maxTop));
                dialog.style.top = top + 'px';
            }

            function ensureRecentGroup() {
                var existing = document.getElementById('oppc_recent_confirmed');
                if (existing) return existing.querySelector('tbody');

                var wrap = document.createElement('div');
                wrap.id = 'oppc_recent_confirmed';
                wrap.className = 'oppc-recent';
                wrap.innerHTML = '<h3>Vừa xác nhận</h3><div class="oppc-table-wrap"><table class="oppc-table"><thead><tr><th class="oppc-select-cell"></th><th>Mã đơn</th><th>Khách hàng</th><th>Thanh toán</th><th>Sản phẩm</th><th>Xác nhận</th><th>Thao tác</th></tr></thead><tbody></tbody></table></div>';
                resultsEl.insertBefore(wrap, resultsEl.firstChild);
                return wrap.querySelector('tbody');
            }

            function moveRowsToRecent(rows) {
                if (!rows || !rows.length) return;

                var tbody = ensureRecentGroup();
                rows.forEach(function(row) {
                    if (!row || !document.body.contains(row)) return;

                    var checkbox = row.querySelector('.oppc-row-select');
                    if (checkbox) checkbox.checked = false;

                    var auditCell = row.querySelector('[data-label="Xác nhận"]');
                    if (auditCell) {
                        auditCell.textContent = 'Vừa xác nhận';
                    }
                    tbody.insertBefore(row, tbody.firstChild);
                });
                updateBulkState();
            }

            function selectedCheckboxes() {
                return Array.prototype.slice.call(resultsEl.querySelectorAll('.oppc-row-select:checked'));
            }

            function selectedOrderIds() {
                return selectedCheckboxes().map(function(input) { return input.value; }).filter(Boolean);
            }

            function updateBulkState() {
                var count = selectedOrderIds().length;
                var bulkBtn = document.getElementById('oppc_bulk_confirm');
                var countEl = document.getElementById('oppc_bulk_count');
                var selectAll = document.getElementById('oppc_select_all');
                var allBoxes = Array.prototype.slice.call(resultsEl.querySelectorAll('.oppc-row-select'));

                if (bulkBtn) bulkBtn.disabled = count === 0;
                if (countEl) countEl.textContent = count ? count + ' đơn đã chọn' : 'Chưa chọn đơn';
                if (selectAll) {
                    selectAll.checked = allBoxes.length > 0 && count === allBoxes.length;
                    selectAll.indeterminate = count > 0 && count < allBoxes.length;
                }
            }

            function moveSelectedRowToRecent() {
                moveRowsToRecent(state.selectedRows && state.selectedRows.length ? state.selectedRows : [state.selectedRow]);
            }

            function openConfirm(button) {
                state.orderId = button.getAttribute('data-order-id') || '';
                state.orderIds = state.orderId ? [state.orderId] : [];
                state.selectedRow = button.closest('tr');
                state.selectedRows = state.selectedRow ? [state.selectedRow] : [];
                document.getElementById('oppc_order_summary').value = button.getAttribute('data-order-summary') || '';
                document.getElementById('oppc_amount').value = button.getAttribute('data-order-total') || '';
                document.getElementById('oppc_paid_date').value = new Date().toISOString().split('T')[0];
                document.getElementById('oppc_payer').value = button.getAttribute('data-default-payer') || 'customer';
                document.getElementById('oppc_note').value = '';
                confirmModal.hidden = false;
                placeAnchoredModal(confirmModal, button);
                document.body.classList.add('oppc-modal-open');
            }

            function closeConfirm() {
                state.orderId = '';
                state.orderIds = [];
                state.selectedRows = [];
                confirmModal.hidden = true;
                document.body.classList.remove('oppc-modal-open');
            }

            function openBulkConfirm(button) {
                var ids = selectedOrderIds();
                if (!ids.length) {
                    showNotice('Vui lòng chọn ít nhất một đơn.', 'error');
                    return;
                }

                state.orderId = ids[0] || '';
                state.orderIds = ids;
                state.selectedRows = selectedCheckboxes().map(function(input) { return input.closest('tr'); }).filter(Boolean);
                state.selectedRow = state.selectedRows[0] || null;
                document.getElementById('oppc_order_summary').value = 'Xác nhận ' + ids.length + ' đơn: #' + ids.join(', #');
                document.getElementById('oppc_amount').value = '';
                document.getElementById('oppc_paid_date').value = new Date().toISOString().split('T')[0];
                document.getElementById('oppc_payer').value = 'customer';
                document.getElementById('oppc_note').value = '';
                confirmModal.hidden = false;
                placeAnchoredModal(confirmModal, button);
                document.body.classList.add('oppc-modal-open');
            }

            function openSms(button) {
                var phone = button.getAttribute('data-sms-phone') || '';
                var paid = button.getAttribute('data-sms-paid') || '';
                if (window.matchMedia('(max-width: 767px)').matches && phone && paid) {
                    window.location.href = 'sms:' + phone + '?body=' + encodeURIComponent(paid);
                    return;
                }

                document.getElementById('oppc_sms_phone').value = phone;
                document.getElementById('oppc_sms_paid').value = paid;
                document.getElementById('oppc_sms_recall').value = button.getAttribute('data-sms-recall') || '';
                smsModal.hidden = false;
                placeAnchoredModal(smsModal, button);
                document.body.classList.add('oppc-modal-open');
            }

            function closeSms() {
                smsModal.hidden = true;
                document.body.classList.remove('oppc-modal-open');
            }

            function buildConfirmPreview(ids, bankAccount, paidDate, amountReceived, payer, note) {
                var payerLabels = {
                    customer: 'Khách hàng',
                    shipper: 'Shipper TT COD',
                    self: 'Nhân viên TT COD'
                };
                var summary = document.getElementById('oppc_order_summary').value || '';
                var amountText = amountReceived ? amountReceived : 'Trống/0 - xử lý theo logic nhận đủ nếu không phải thanh toán một phần';
                var lines = [
                    'PREVIEW XÁC NHẬN THANH TOÁN',
                    '',
                    'Số đơn: ' + ids.length,
                    'Mã đơn: #' + ids.join(', #'),
                    summary ? 'Thông tin: ' + summary : '',
                    '',
                    'Sẽ cập nhật:',
                    '- Tài khoản nhận: ' + bankAccount,
                    '- Ngày nhận CK: ' + paidDate,
                    '- Số tiền nhận: ' + amountText,
                    '- Người thanh toán: ' + (payerLabels[payer] || payer),
                    note ? '- Ghi chú: ' + note : '- Ghi chú: Không có',
                    '- Lưu audit _order_payment_confirmation_audit với người xác nhận hiện tại',
                    '',
                    'Bấm OK để cập nhật thật. Bấm Cancel để quay lại chỉnh.'
                ];

                return lines.filter(Boolean).join('\n');
            }

            function confirmPayment() {
                var ids = state.orderIds && state.orderIds.length ? state.orderIds : (state.orderId ? [state.orderId] : []);
                if (!ids.length) {
                    showNotice('Chưa chọn đơn hàng.', 'error');
                    return;
                }

                var bankAccount = document.getElementById('oppc_bank_account').value;
                var paidDate = document.getElementById('oppc_paid_date').value;
                var amountReceived = document.getElementById('oppc_amount').value;
                var payer = document.getElementById('oppc_payer').value;
                var note = document.getElementById('oppc_note').value;

                if (!window.confirm(buildConfirmPreview(ids, bankAccount, paidDate, amountReceived, payer, note))) {
                    return;
                }

                clearNotice();
                setLoading(confirmBtn, true, 'Đang xác nhận...', 'Xác nhận');
                jQuery.post(oppcConfig.ajaxUrl, {
                    action: 'oppc_confirm_payment',
                    nonce: oppcConfig.confirmNonce,
                    order_id: state.orderId,
                    order_ids: ids.join(','),
                    bank_account: bankAccount,
                    paid_date: paidDate,
                    amount_received: amountReceived,
                    payer: payer,
                    note: note
                }, function(response) {
                    if (response && response.success && response.data) {
                        moveSelectedRowToRecent();
                        closeConfirm();
                        showNotice(response.data.message || 'Đã xác nhận thanh toán.', 'success');
                    } else {
                        showNotice(response && response.data && response.data.message ? response.data.message : 'Không thể xác nhận.', 'error');
                    }
                }).fail(function() {
                    showNotice('Yêu cầu xác nhận thất bại.', 'error');
                }).always(function() {
                    setLoading(confirmBtn, false, 'Đang xác nhận...', 'Xác nhận');
                });
            }

            loadBtn.addEventListener('click', function() { loadOrders(false); });
            resetBtn.addEventListener('click', function() {
                document.getElementById('oppc_date_from').value = root.getAttribute('data-default-from') || '';
                document.getElementById('oppc_date_to').value = root.getAttribute('data-default-to') || '';
                document.getElementById('oppc_order_status').value = 'all_active';
                document.getElementById('oppc_payment_bucket').value = 'all_pending';
                document.getElementById('oppc_confirmed_by').value = '';
                document.getElementById('oppc_keyword').value = '';
                resultsEl.hidden = true;
                resultsEl.innerHTML = '<p>Chưa tải dữ liệu.</p>';
                clearNotice();
                state.lastLoaded = false;
            });
            resultsEl.addEventListener('click', function(e) {
                var confirmButton = e.target.closest('[data-ppc-confirm="1"]');
                var smsButton = e.target.closest('[data-ppc-sms="1"]');
                var selectAll = e.target.closest('#oppc_select_all');
                var rowSelect = e.target.closest('.oppc-row-select');
                var bulkButton = e.target.closest('#oppc_bulk_confirm');

                if (selectAll) {
                    Array.prototype.forEach.call(resultsEl.querySelectorAll('.oppc-row-select'), function(input) { input.checked = selectAll.checked; });
                    updateBulkState();
                    return;
                }
                if (rowSelect) {
                    updateBulkState();
                    return;
                }
                if (bulkButton) {
                    openBulkConfirm(bulkButton);
                    return;
                }
                if (confirmButton) openConfirm(confirmButton);
                if (smsButton) openSms(smsButton);
            });
            confirmBtn.addEventListener('click', confirmPayment);
            Array.prototype.forEach.call(document.querySelectorAll('[data-ppc-close="1"]'), function(el) { el.addEventListener('click', closeConfirm); });
            Array.prototype.forEach.call(document.querySelectorAll('[data-ppc-sms-close="1"]'), function(el) { el.addEventListener('click', closeSms); });
            Array.prototype.forEach.call(document.querySelectorAll('.oppc-copy'), function(el) {
                el.addEventListener('click', function() {
                    this.focus();
                    this.select();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(this.value).then(function() { showNotice('Đã copy nội dung.', 'success'); });
                    }
                });
            });
        });
    </script>
    <?php
}

add_action('wp_ajax_oppc_load_orders', 'oppc_ajax_load_orders');
add_action('wp_ajax_oppc_confirm_payment', 'oppc_ajax_confirm_payment');

function oppc_ajax_load_orders(): void
{
    check_ajax_referer('oppc_load_orders', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    $filters = oppc_sanitize_filters($_POST);
    $orders = oppc_get_filtered_orders($filters);

    ob_start();
    oppc_render_results($orders, $filters);
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'message' => sprintf('Đã tải %d đơn hàng.', count($orders)),
    ]);
}

function oppc_sanitize_filters(array $source): array
{
    $date_from = sanitize_text_field(wp_unslash($source['date_from'] ?? ''));
    $date_to = sanitize_text_field(wp_unslash($source['date_to'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = oppc_default_date_from();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = oppc_default_date_to();
    }

    $payment_bucket = sanitize_key(wp_unslash($source['payment_bucket'] ?? 'all_pending'));
    if (!in_array($payment_bucket, ['all_pending', 'bacs_pending', 'cod_pending'], true)) {
        $payment_bucket = 'all_pending';
    }

    $order_status = sanitize_key(wp_unslash($source['order_status'] ?? 'all_active'));
    if ($order_status !== 'all_active' && !isset(oppc_get_order_status_options()[$order_status])) {
        $order_status = 'all_active';
    }

    return [
        'date_from' => $date_from,
        'date_to' => $date_to,
        'order_status' => $order_status,
        'payment_bucket' => $payment_bucket,
        'confirmed_by' => absint($source['confirmed_by'] ?? 0),
        'keyword' => sanitize_text_field(wp_unslash($source['keyword'] ?? '')),
    ];
}

function oppc_get_filtered_orders(array $filters): array
{
    if (!function_exists('wc_get_orders')) {
        return [];
    }

    $statuses = array_keys(oppc_get_order_status_options());
    if ($filters['order_status'] !== 'all_active') {
        $statuses = [$filters['order_status']];
    }

    $args = [
        'type' => 'shop_order',
        'status' => $statuses,
        'limit' => 120,
        'orderby' => 'date',
        'order' => 'DESC',
        'date_created' => $filters['date_from'] . ' 00:00:00...' . $filters['date_to'] . ' 23:59:59',
        'return' => 'objects',
    ];

    if ($filters['payment_bucket'] === 'bacs_pending') {
        $args['payment_method'] = 'bacs';
    } elseif ($filters['payment_bucket'] === 'cod_pending') {
        $args['payment_method'] = 'cod';
    }

    $orders = wc_get_orders($args);
    if (!is_array($orders)) {
        return [];
    }

    $filtered = [];
    foreach ($orders as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }

        if ($filters['keyword'] !== '' && !oppc_order_matches_keyword($order, $filters['keyword'])) {
            continue;
        }

        if ($filters['confirmed_by'] === 0 && in_array($order->get_status(), ['cancelled', 'failed'], true)) {
            continue;
        }

        if ($filters['confirmed_by'] > 0) {
            $audit = oppc_get_confirmation_audit($order);
            if ((int) ($audit['confirmed_by'] ?? 0) !== $filters['confirmed_by']) {
                continue;
            }
            $filtered[] = $order;
            continue;
        }

        if ($filters['payment_bucket'] === 'bacs_pending' && !oppc_is_pending_bacs($order)) {
            continue;
        }
        if ($filters['payment_bucket'] === 'cod_pending' && !oppc_is_pending_cod($order)) {
            continue;
        }
        if ($filters['payment_bucket'] === 'all_pending' && !oppc_is_pending_bacs($order) && !oppc_is_pending_cod($order)) {
            continue;
        }

        $filtered[] = $order;
    }

    return $filtered;
}

function oppc_order_matches_keyword(WC_Order $order, string $keyword): bool
{
    $needle = strtolower(trim($keyword));
    if ($needle === '') {
        return true;
    }

    $order_id = (string) $order->get_id();
    $formatted = function_exists('change_order_number') ? (string) change_order_number($order->get_id()) : $order_id;
    $order_codes = array_filter(array_unique([
        $order_id,
        '#' . $order_id,
        $formatted,
        '#' . $formatted,
        'P1' . $formatted,
        'P0' . $formatted,
    ]));
    $haystack = implode(' ', array_merge($order_codes, [
        $order->get_billing_phone(),
        $order->get_billing_first_name(),
        $order->get_billing_last_name(),
        $order->get_billing_email(),
    ]));

    if (stripos($haystack, $needle) !== false) {
        return true;
    }

    foreach (oppc_parse_keyword_tokens($keyword) as $token) {
        if (oppc_token_matches_order_codes($token, $order_codes)) {
            return true;
        }
    }

    return false;
}

function oppc_parse_keyword_tokens(string $keyword): array
{
    $parts = preg_split('/[\s,]+/', $keyword, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }

    return array_values(array_unique(array_map('trim', $parts)));
}

function oppc_token_matches_order_codes(string $token, array $order_codes): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $candidates = [$token, ltrim($token, '#')];
    $normalized = oppc_normalize_order_code_token($token);
    if ($normalized !== '') {
        $candidates[] = $normalized;
        if (strlen($normalized) > 2) {
            $candidates[] = substr($normalized, 0, -2);
        }
    }

    foreach (array_unique($candidates) as $candidate) {
        foreach ($order_codes as $code) {
            if (strcasecmp((string) $code, (string) $candidate) === 0) {
                return true;
            }
        }
    }

    return false;
}

function oppc_normalize_order_code_token(string $token): string
{
    $code = strtoupper(trim($token));
    $code = str_replace('#', '', $code);

    if (strpos($code, 'P0') === 0 || strpos($code, 'P1') === 0) {
        $code = substr($code, 2);
    }

    return preg_replace('/\D+/', '', $code);
}

function oppc_get_confirmation_audit(WC_Order $order): array
{
    $raw = $order->get_meta('_order_payment_confirmation_audit');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function oppc_has_confirmation_audit(WC_Order $order): bool
{
    return !empty(oppc_get_confirmation_audit($order));
}

function oppc_has_legacy_bank_confirmation(WC_Order $order): bool
{
    return $order->get_meta('order_paid_date') !== '' && $order->get_meta('order_bank_account_received') !== '';
}

function oppc_is_pending_bacs(WC_Order $order): bool
{
    return $order->get_payment_method() === 'bacs'
        && !oppc_has_confirmation_audit($order);
}

function oppc_is_pending_cod(WC_Order $order): bool
{
    return $order->get_payment_method() === 'cod'
        && !oppc_has_confirmation_audit($order)
        && !oppc_has_only_external_cod_carriers($order);
}

function oppc_has_only_external_cod_carriers(WC_Order $order): bool
{
    $carriers = oppc_get_order_shippers($order);
    if (empty($carriers)) {
        return false;
    }

    foreach ($carriers as $carrier) {
        if (!oppc_is_external_carrier($carrier)) {
            return false;
        }
    }

    return true;
}

/**
 * Đối tác giao hàng được lưu ở meta "order_shipper" (Meta Box select_advanced, multiple).
 * Trả về mảng các giá trị đã chọn (đã lowercase/trim).
 */
function oppc_get_order_shippers(WC_Order $order): array
{
    $raw = $order->get_meta('order_shipper');
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : [$raw];
    }
    if (!is_array($raw)) {
        return [];
    }

    $carriers = [];
    foreach ($raw as $value) {
        $value = strtolower(trim((string) $value));
        if ($value !== '') {
            $carriers[] = $value;
        }
    }

    return array_values(array_unique($carriers));
}

/**
 * GHTK và Viettel Post tự thu hộ COD nên không cần xác nhận thanh toán nội bộ.
 */
function oppc_is_external_carrier(string $carrier): bool
{
    return $carrier === 'ghtk'
        || $carrier === 'viettel'
        || strpos($carrier, 'ghtk') !== false
        || strpos($carrier, 'tiet kiem') !== false
        || strpos($carrier, 'viettel') !== false;
}

function oppc_render_results(array $orders, array $filters): void
{
    $is_audit_view = !empty($filters['confirmed_by']);
    $result_total = 0.0;
    foreach ($orders as $order) {
        if ($order instanceof WC_Order) {
            $result_total += (float) $order->get_total();
        }
    }

    echo '<div><h3>' . esc_html($is_audit_view ? 'Đơn theo người xác nhận' : 'Đơn cần xác nhận') . '</h3></div>';

    if (empty($orders)) {
        echo '<p>Không có đơn hàng phù hợp.</p>';
        return;
    }

    echo '<div class="oppc-result-summary"><span>Số đơn: <strong>' . esc_html((string) count($orders)) . '</strong></span><span>Tổng số tiền: <strong>' . wp_kses_post(wc_price($result_total)) . '</strong></span></div>';

    if (!$is_audit_view) {
        echo '<div class="oppc-bulk-actions"><button type="button" id="oppc_bulk_confirm" class="button--small button--green" disabled>Xác nhận đã chọn</button><span id="oppc_bulk_count" class="oppc-muted">Chưa chọn đơn</span></div>';
    }

    echo '<div class="oppc-table-wrap"><table class="oppc-table"><thead><tr>';
    echo '<th class="oppc-select-cell">' . ($is_audit_view ? '' : '<input type="checkbox" id="oppc_select_all" aria-label="Chọn tất cả đơn">') . '</th><th>Mã đơn</th><th>Khách hàng</th><th>Thanh toán</th><th>Sản phẩm</th><th>Xác nhận</th><th>Thao tác</th>';
    echo '</tr></thead><tbody>';
    foreach ($orders as $order) {
        oppc_render_order_row($order, $is_audit_view);
    }
    echo '</tbody></table></div>';
}

function oppc_render_order_row(WC_Order $order, bool $is_audit_view): void
{
    $order_id = $order->get_id();
    $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $billing_phone = $order->get_billing_phone();
    $order_total = wc_price($order->get_total());
    $order_date = $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '';
    $payment_method = $order->get_payment_method_title() ?: $order->get_payment_method();
    $shipping_method = $order->get_shipping_method();
    $order_status = wc_get_order_status_name($order->get_status());
    $new_order_id = function_exists('change_order_number') ? change_order_number($order_id) : $order_id;
    $sms_paid = "The An da nhan duoc thanh toan don $new_order_id. Don hang se som duoc giao toi ban. Cam on ban da tin mua An (Tin nhan tu dong)";
    $sms_recall = "The An xin phep thu hoi tin nhan loi. Rat xin loi vi da lam phien ban (Tin nhan tu dong)";
    $summary = sprintf('Đơn #%d | Khách: %s | SĐT: %s | Tổng: %s | Trạng thái: %s', $order_id, $billing_name, $billing_phone, wp_strip_all_tags($order_total), $order_status);
    $default_payer = $order->get_payment_method() === 'cod' ? 'shipper' : 'customer';

    echo '<tr>';
    echo '<td class="oppc-select-cell" data-label="Chọn">' . ($is_audit_view ? '' : '<input type="checkbox" class="oppc-row-select" value="' . intval($order_id) . '" aria-label="Chọn đơn #' . intval($order_id) . '">') . '</td>';
    echo '<td data-label="Mã đơn"><strong>#' . intval($order_id) . '</strong><span class="oppc-muted">Mã: ' . esc_html($new_order_id) . '</span><a href="' . esc_url(admin_url('post.php?post=' . intval($order_id) . '&action=edit')) . '" target="_blank" rel="noopener noreferrer">Chỉnh sửa</a></td>';
    echo '<td data-label="Khách hàng">' . esc_html($billing_name ?: 'Khách lẻ') . '<span class="oppc-muted">' . esc_html($billing_phone) . '</span><span class="oppc-muted">Trạng thái: ' . esc_html($order_status) . '</span></td>';
    echo '<td data-label="Thanh toán"><strong>' . $order_total . '</strong><span class="oppc-muted">' . esc_html($payment_method) . '</span><span class="oppc-muted">Giao bởi: ' . esc_html($shipping_method ?: 'Chưa có') . '</span><span class="oppc-muted">' . esc_html($order_date) . '</span></td>';
    echo '<td data-label="Sản phẩm"><ul>';
    foreach ($order->get_items() as $item) {
        echo '<li>' . esc_html($item->get_name()) . ' x ' . intval($item->get_quantity()) . '</li>';
    }
    echo '</ul></td>';
    echo '<td data-label="Xác nhận">' . oppc_render_audit_cell($order) . '</td>';
    echo '<td data-label="Thao tác"><div class="oppc-row-actions">';
    if (!$is_audit_view && (oppc_is_pending_bacs($order) || oppc_is_pending_cod($order))) {
        echo '<button type="button" class="button--small button--green" data-ppc-confirm="1" data-order-id="' . intval($order_id) . '" data-order-total="' . esc_attr(wc_format_decimal($order->get_total(), 0)) . '" data-order-summary="' . esc_attr($summary) . '" data-default-payer="' . esc_attr($default_payer) . '">Xác nhận TT</button>';
    }
    echo '<button type="button" class="button--small button--white" data-ppc-sms="1" data-sms-phone="' . esc_attr($billing_phone) . '" data-sms-paid="' . esc_attr($sms_paid) . '" data-sms-recall="' . esc_attr($sms_recall) . '">Gửi SMS</button>';
    echo '</div></td>';
    echo '</tr>';
}

function oppc_render_audit_cell(WC_Order $order): string
{
    $audit = oppc_get_confirmation_audit($order);
    if (!empty($audit)) {
        $parts = [];
        $parts[] = 'Người xác nhận: ' . ($audit['confirmed_name'] ?? ('User #' . ($audit['confirmed_by'] ?? '')));
        if (!empty($audit['confirmed_at'])) {
            $parts[] = 'Lúc: ' . $audit['confirmed_at'];
        }
        if (!empty($audit['type'])) {
            $parts[] = 'Loại: ' . $audit['type'];
        }
        if (isset($audit['amount'])) {
            $parts[] = 'Số tiền: ' . wp_strip_all_tags(wc_price((float) $audit['amount']));
        }
        return esc_html(implode(' | ', array_filter($parts)));
    }

    if (oppc_has_legacy_bank_confirmation($order)) {
        return 'Đã xác nhận cũ - chưa có người xác nhận';
    }

    return 'Chưa xác nhận';
}

function oppc_ajax_confirm_payment(): void
{
    check_ajax_referer('oppc_confirm_payment', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    $order_ids = oppc_parse_order_ids($_POST['order_ids'] ?? ($_POST['order_id'] ?? ''));
    if (empty($order_ids)) {
        wp_send_json_error(['message' => 'Chưa chọn đơn hàng.'], 400);
    }

    $bank_account = sanitize_text_field(wp_unslash($_POST['bank_account'] ?? ''));
    $paid_date = sanitize_text_field(wp_unslash($_POST['paid_date'] ?? ''));
    $amount_received = (float) wc_format_decimal(wp_unslash($_POST['amount_received'] ?? 0));
    $payer = sanitize_key(wp_unslash($_POST['payer'] ?? 'customer'));
    $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));

    if (!in_array($payer, ['customer', 'shipper', 'self'], true)) {
        $payer = 'customer';
    }

    if ($payer === 'customer' && ($bank_account === '' || $paid_date === '')) {
        wp_send_json_error(['message' => 'Vui lòng nhập tài khoản nhận và ngày nhận chuyển khoản.'], 400);
    }

    $confirmed = 0;
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            continue;
        }

        oppc_process_confirmation($order, $bank_account, $paid_date, $amount_received, $payer, $note);
        $confirmed++;
    }

    if ($confirmed === 0) {
        wp_send_json_error(['message' => 'Không có đơn hợp lệ để xác nhận.'], 404);
    }

    wp_send_json_success(['message' => sprintf('Đã xác nhận thanh toán %d đơn và lưu người xác nhận.', $confirmed)]);
}

function oppc_parse_order_ids($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = preg_split('/[\s,]+/', (string) wp_unslash($raw), -1, PREG_SPLIT_NO_EMPTY);
    }

    $ids = array_map('absint', is_array($parts) ? $parts : []);
    $ids = array_filter($ids);
    return array_values(array_unique($ids));
}

function oppc_process_confirmation(WC_Order $order, string $bank_account, string $paid_date, float $amount_received, string $payer, string $note): void
{
    $order_id = $order->get_id();
    $order_total = (float) $order->get_total();
    $type = 'bank_transfer';

    if ($payer === 'shipper') {
        $type = 'cod_shipper';
    } elseif ($payer === 'self') {
        $type = 'cod_staff';
    }

    if ($payer === 'customer') {
        if ($order->get_payment_method() === 'cod') {
            $order->set_payment_method('bacs');
            $order->set_payment_method_title('Chuyển khoản ngân hàng');
            $order->add_order_note('Phương thức thanh toán đã được đổi từ COD sang Chuyển khoản ngân hàng.');
        }

        update_post_meta($order_id, 'order_paid_date', $paid_date);
        update_post_meta($order_id, 'order_bank_account_received', $bank_account);

        if ($amount_received > 0 && $amount_received < $order_total) {
            $order->update_status('partial-paid', sprintf('Đã nhận %s đ (Thanh toán một phần)', wc_format_decimal($amount_received, 0)));
        } else {
            $order->update_status('paid', 'Đã nhận đủ tiền, đơn hàng được đánh dấu là Đã CK.');
            if (function_exists('wc_reduce_stock_levels')) {
                wc_reduce_stock_levels($order_id);
            }
        }
    } elseif ($payer === 'self' && !($amount_received > 0 && $amount_received < $order_total)) {
        $order->update_status('completed', 'Nhân viên đã thanh toán COD, đơn hàng được đánh dấu là Hoàn thành.');
    }

    oppc_record_confirmation_audit($order, $type, $amount_received, $payer, $bank_account, $paid_date, $note);
    $order->save();
}

function oppc_record_confirmation_audit(WC_Order $order, string $type, float $amount, string $payer, string $bank_account, string $paid_date, string $note): void
{
    $user = wp_get_current_user();
    $display_name = $user && $user->ID ? ($user->display_name ?: $user->user_login) : 'Unknown user';
    $confirmed_at = current_time('mysql');

    $audit = [
        'type' => $type,
        'confirmed_by' => $user ? (int) $user->ID : 0,
        'confirmed_name' => $display_name,
        'confirmed_at' => $confirmed_at,
        'amount' => $amount,
        'payer' => $payer,
        'bank_account' => $bank_account,
        'paid_date' => $paid_date,
        'note' => $note,
    ];

    update_post_meta($order->get_id(), '_order_payment_confirmation_audit', wp_json_encode($audit, JSON_UNESCAPED_UNICODE));

    $note_parts = [
        sprintf('Thanh toán đã được xác nhận bởi %s lúc %s.', $display_name, $confirmed_at),
        'Loại: ' . $type,
        'Số tiền: ' . wp_strip_all_tags(wc_price($amount)),
    ];
    if ($note !== '') {
        $note_parts[] = 'Ghi chú: ' . $note;
    }

    $order->add_order_note(implode(' | ', $note_parts));
}

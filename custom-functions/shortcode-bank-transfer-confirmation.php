<?php

/**
 * Shortcode: [order_paid_confirmation]
 * Purpose: Admin/Manager tool for searching orders and confirming bank transfers.
 */
add_shortcode('order_paid_confirmation', 'order_paid_confirmation_shortcode');
function order_paid_confirmation_shortcode()
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }

    ob_start();
    render_order_paid_confirmation_ui();
    render_order_paid_confirmation_script();
    return ob_get_clean();
}

function render_order_paid_confirmation_ui()
{ ?>
    <div class="order-paid-confirmation btc-ui">
        <div class="btc-panel">
            <h2>Xác nhận thanh toán đơn hàng</h2>
            <p>Tìm đơn hàng, xem kết quả dạng bảng và chỉ mở popup khi bấm vào các nút thao tác.</p>
        </div>

        <div class="btc-panel btc-search-panel">
            <p><input type="text" id="order_search" placeholder="Nhập mã đơn (nhiều mã: cách nhau bằng dấu phẩy hoặc khoảng trắng)"></p>
            <div class="btc-actions-row">
                <button type="button" id="btn_search_order" class="button--small button--green">Tìm kiếm</button>
                <button type="button" id="clear_results_btn" class="button--small button--red">Xóa kết quả</button>
            </div>
        </div>

        <div id="btc_notice" class="btc-notice" aria-live="polite"></div>
        <div id="order_results" class="btc-panel" style="display:none;"></div>
    </div>

    <div id="btc_payment_modal" class="btc-modal" hidden>
        <div class="btc-modal__backdrop" data-modal-close="1"></div>
        <div class="btc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="btc_modal_title">
            <button type="button" class="btc-modal__close" data-modal-close="1" aria-label="Đóng">&times;</button>
            <h3 id="btc_modal_title">Xác nhận chuyển khoản</h3>
            <div class="btc-modal__summary">
                <label>Thông tin đơn hàng</label>
                <textarea id="selected_order_info" rows="3" readonly></textarea>
            </div>

            <div class="btc-form-grid">
                <div>
                    <label>Tài khoản nhận</label>
                    <select id="bank_account">
                        <option value="Vietinbank 113600098383">Vietinbank 113600098383 / TK công ty</option>
                        <option value="ACB 11090087">ACB 11090087</option>
                        <option value="ACB 8700507">ACB 8700507</option>
                    </select>
                </div>

                <div>
                    <label>Ngày nhận CK</label>
                    <input type="date" id="paid_date">
                </div>

                <div>
                    <label>Số tiền nhận</label>
                    <input type="number" id="amount_received" min="0" step="1000">
                </div>

                <div>
                    <label>Người chuyển khoản</label>
                    <select id="payer">
                        <option value="customer">Khách hàng</option>
                        <option value="shipper">Shipper TT COD</option>
                        <option value="self">Nhân viên TT COD</option>
                    </select>
                </div>

                <div id="cod_note_section" class="btc-form-span" style="display:none;">
                    <label>Ghi chú</label>
                    <textarea id="cod_note"></textarea>
                </div>
            </div>

            <div class="btc-actions-row">
                <button type="button" class="button--small button--white" data-modal-close="1">Hủy</button>
                <button class="button--small button button--green" type="button" id="btn_confirm_payment">Xác nhận</button>
            </div>
        </div>
    </div>

    <div id="btc_sms_modal" class="btc-modal" hidden>
        <div class="btc-modal__backdrop" data-sms-modal-close="1"></div>
        <div class="btc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="btc_sms_modal_title">
            <button type="button" class="btc-modal__close" data-sms-modal-close="1" aria-label="Đóng">&times;</button>
            <h3 id="btc_sms_modal_title">Gửi SMS xác nhận chuyển khoản</h3>
            <div class="btc-modal__summary">
                <label>Thông tin đơn hàng</label>
                <textarea id="sms_order_info" rows="3" readonly></textarea>
            </div>
            <div class="btc-form-grid">
                <div class="btc-form-span">
                    <label>Số điện thoại khách hàng</label>
                    <input type="text" id="sms_customer_phone" class="btc-copy-field" readonly title="Bấm để copy số điện thoại">
                </div>
                <div class="btc-form-span">
                    <p><strong>BƯỚC 1:</strong> Copy số điện thoại khách hàng.</p>
                    <p><strong>BƯỚC 2:</strong> Bấm mở Google Messages.</p>
                    <p><strong>BƯỚC 3:</strong> Copy một trong các nội dung SMS bên dưới để gửi.</p>
                </div>
                <div class="btc-form-span">
                    <a class="button--black" href="https://messages.google.com/web/conversations/new" target="_blank" rel="noopener noreferrer">Mở Google Messages</a>
                </div>
                <div class="btc-form-span">
                    <label>Nội dung SMS: Đã nhận CK</label>
                    <textarea id="sms_template_paid" class="btc-copy-field" rows="3" readonly title="Bấm để copy nội dung SMS"></textarea>
                </div>
                <div class="btc-form-span">
                    <label>Nội dung SMS: Thu hồi SMS</label>
                    <textarea id="sms_template_recall" class="btc-copy-field" rows="3" readonly title="Bấm để copy nội dung SMS"></textarea>
                </div>
            </div>
        </div>
    </div>
<?php
}

function render_order_paid_confirmation_script()
{
    $config = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'searchNonce' => wp_create_nonce('search_order_ajax_nonce'),
        'confirmNonce' => wp_create_nonce('confirm_order_payment_nonce'),
    ];
?>
    <style>
        .btc-ui { display: grid; gap: 16px; }
        .btc-panel, .btc-notice { background: #fff; border: 1px solid #d6dee7; border-radius: 14px; padding: 16px; }
        .btc-panel h2, .btc-panel p { margin: 0; }
        .btc-panel p + p { margin-top: 8px; }
        .btc-search-panel { display: grid; gap: 12px; }
        .btc-search-panel input,
        .btc-modal textarea,
        .btc-modal input,
        .btc-modal select { width: 100%; border: 1px solid #c9d3df; border-radius: 10px; padding: 10px 12px; background: #fff; }
        .btc-actions-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .btc-notice { display: none; }
        .btc-notice.is-visible { display: block; }
        .btc-notice.is-success { border-color: #b8ddc7; background: #edf8f1; color: #1f6b3a; }
        .btc-notice.is-error { border-color: #efc0c0; background: #fff2f2; color: #9c2f2f; }
        .btc-table-wrap { overflow-x: auto; }
        .btc-table { width: 100%; min-width: 980px; border-collapse: collapse; }
        .btc-table th, .btc-table td { padding: 12px; border-bottom: 1px solid #e5ebf1; vertical-align: top; text-align: left; }
        .btc-table th { background: #f6f8fb; font-size: 13px; text-transform: uppercase; letter-spacing: .03em; color: #5b6470; }
        .btc-table tr:hover td { background: #fbfcfe; }
        .btc-table ul { margin: 0; padding-left: 18px; }
        .btc-table__actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btc-copy-field { cursor: copy; }
        .btc-modal[hidden] { display: none; }
        .btc-modal { position: fixed; inset: 0; z-index: 9999; }
        .btc-modal__backdrop { position: absolute; inset: 0; background: rgba(17, 24, 39, .55); }
        .btc-modal__dialog { position: relative; width: min(760px, calc(100vw - 32px)); max-height: calc(100vh - 32px); overflow: auto; margin: 16px auto; background: #fff; border-radius: 16px; padding: 22px; box-shadow: 0 18px 60px rgba(0,0,0,.22); }
        .btc-modal__close { position: absolute; top: 10px; right: 12px; border: 0; background: transparent; font-size: 30px; line-height: 1; cursor: pointer; }
        .btc-modal__summary, .btc-modal__dialog h3 { margin-bottom: 16px; }
        .btc-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
        .btc-form-span { grid-column: 1 / -1; }
        body.btc-modal-open { overflow: hidden; }
        @media (max-width: 767px) {
            .btc-form-grid { grid-template-columns: 1fr; }
            .btc-modal__dialog { width: calc(100vw - 20px); margin: 10px auto; padding: 18px; }
        }
    </style>
    <script>
        var btcConfig = <?php echo wp_json_encode($config); ?>;
        document.addEventListener('DOMContentLoaded', function() {
            var state = { selectedOrderId: '', lastSearch: '' };
            var noticeEl = document.getElementById('btc_notice');
            var resultsEl = document.getElementById('order_results');
            var modalEl = document.getElementById('btc_payment_modal');
            var smsModalEl = document.getElementById('btc_sms_modal');
            var summaryEl = document.getElementById('selected_order_info');
            var smsSummaryEl = document.getElementById('sms_order_info');
            var smsPhoneEl = document.getElementById('sms_customer_phone');
            var smsPaidTemplateEl = document.getElementById('sms_template_paid');
            var smsRecallTemplateEl = document.getElementById('sms_template_recall');
            var payerEl = document.getElementById('payer');
            var codNoteSection = document.getElementById('cod_note_section');
            var confirmBtn = document.getElementById('btn_confirm_payment');
            var searchBtn = document.getElementById('btn_search_order');
            var searchInput = document.getElementById('order_search');

            function showNotice(message, type) {
                noticeEl.textContent = message || '';
                noticeEl.className = 'btc-notice is-visible ' + (type === 'success' ? 'is-success' : 'is-error');
            }

            function clearNotice() {
                noticeEl.textContent = '';
                noticeEl.className = 'btc-notice';
            }

            function setButtonLoading(button, isLoading, loadingText, idleText) {
                if (!button) return;
                button.disabled = !!isLoading;
                button.textContent = isLoading ? loadingText : idleText;
            }

            function copyText(value) {
                if (!value) {
                    showNotice('Không có nội dung để copy.', 'error');
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function() {
                        showNotice('Đã copy nội dung.', 'success');
                    }).catch(function() {
                        showNotice('Không thể copy tự động. Vui lòng thử lại.', 'error');
                    });
                    return;
                }

                var tempInput = document.createElement('textarea');
                tempInput.value = value;
                document.body.appendChild(tempInput);
                tempInput.select();

                try {
                    document.execCommand('copy');
                    showNotice('Đã copy nội dung.', 'success');
                } catch (error) {
                    showNotice('Không thể copy tự động. Vui lòng thử lại.', 'error');
                }

                document.body.removeChild(tempInput);
            }

            function toggleCodNote() {
                codNoteSection.style.display = (payerEl.value === 'shipper' || payerEl.value === 'self') ? 'block' : 'none';
                if (codNoteSection.style.display === 'none') {
                    document.getElementById('cod_note').value = '';
                }
            }

            function openModal(buttonEl) {
                state.selectedOrderId = buttonEl.getAttribute('data-order-id') || '';
                summaryEl.value = buttonEl.getAttribute('data-order-basic-info') || '';
                document.getElementById('amount_received').value = buttonEl.getAttribute('data-order-total-raw') || '';
                document.getElementById('paid_date').value = new Date().toISOString().split('T')[0];
                document.getElementById('bank_account').selectedIndex = 0;
                payerEl.value = 'customer';
                document.getElementById('cod_note').value = '';
                toggleCodNote();
                modalEl.hidden = false;
                document.body.classList.add('btc-modal-open');
            }

            function closeModal() {
                state.selectedOrderId = '';
                modalEl.hidden = true;
                document.body.classList.remove('btc-modal-open');
            }

            function openSmsModal(buttonEl) {
                var phone = buttonEl.getAttribute('data-sms-phone') || '';
                var paidTemplate = buttonEl.getAttribute('data-sms-paid') || '';
                var recallTemplate = buttonEl.getAttribute('data-sms-recall') || '';

                if (window.matchMedia('(max-width: 767px)').matches) {
                    if (!phone || !paidTemplate) {
                        showNotice('Không có đủ thông tin để gửi SMS.', 'error');
                        return;
                    }
                    window.location.href = 'sms:' + phone + '?body=' + encodeURIComponent(paidTemplate);
                    return;
                }

                smsSummaryEl.value = buttonEl.getAttribute('data-order-basic-info') || '';
                smsPhoneEl.value = phone;
                smsPaidTemplateEl.value = paidTemplate;
                smsRecallTemplateEl.value = recallTemplate;
                smsModalEl.hidden = false;
                document.body.classList.add('btc-modal-open');
            }

            function closeSmsModal() {
                smsModalEl.hidden = true;
                document.body.classList.remove('btc-modal-open');
            }

            function renderResults(html) {
                resultsEl.innerHTML = html || '';
                resultsEl.style.display = html ? 'block' : 'none';
            }

            function searchOrder(keepNotice) {
                var searchKey = searchInput.value.trim();
                if (!searchKey) {
                    showNotice('Vui lòng nhập mã đơn hàng hoặc số điện thoại!', 'error');
                    return;
                }

                if (!keepNotice) {
                    clearNotice();
                }

                state.lastSearch = searchKey;
                setButtonLoading(searchBtn, true, 'Đang tìm...', 'Tìm kiếm');

                jQuery.post(btcConfig.ajaxUrl, {
                    action: 'search_order_ajax',
                    search: searchKey,
                    nonce: btcConfig.searchNonce
                }, function(response) {
                    if (response && response.success && response.data) {
                        renderResults(response.data.html || '');
                        if (!keepNotice) {
                            showNotice(response.data.message || 'Đã tải kết quả tìm kiếm.', 'success');
                        }
                    } else {
                        renderResults('');
                        showNotice(response && response.data && response.data.message ? response.data.message : 'Không tìm thấy đơn hàng.', 'error');
                    }
                }).fail(function() {
                    renderResults('');
                    showNotice('Yêu cầu tìm kiếm thất bại. Vui lòng thử lại.', 'error');
                }).always(function() {
                    setButtonLoading(searchBtn, false, 'Đang tìm...', 'Tìm kiếm');
                });
            }

            function confirmPayment() {
                var bankAccount = document.getElementById('bank_account').value;
                var paidDate = document.getElementById('paid_date').value;
                var amountReceived = document.getElementById('amount_received').value;
                var payer = payerEl.value;
                var codNote = document.getElementById('cod_note').value;

                if (!state.selectedOrderId || !paidDate || !bankAccount) {
                    showNotice('Vui lòng nhập đầy đủ thông tin!', 'error');
                    return;
                }

                clearNotice();
                setButtonLoading(confirmBtn, true, 'Đang xác nhận...', 'Xác nhận');

                jQuery.post(btcConfig.ajaxUrl, {
                    action: 'confirm_order_payment',
                    order_id: state.selectedOrderId,
                    bank_account: bankAccount,
                    paid_date: paidDate,
                    amount_received: amountReceived,
                    payer: payer,
                    cod_note: codNote,
                    nonce: btcConfig.confirmNonce
                }, function(response) {
                    if (response && response.success && response.data) {
                        closeModal();
                        showNotice(response.data.message || 'Đã cập nhật đơn hàng.', 'success');
                        if (state.lastSearch) {
                            searchOrder(true);
                        }
                    } else {
                        showNotice(response && response.data && response.data.message ? response.data.message : 'Không thể cập nhật đơn hàng.', 'error');
                    }
                }).fail(function() {
                    showNotice('Yêu cầu xác nhận thất bại. Vui lòng thử lại.', 'error');
                }).always(function() {
                    setButtonLoading(confirmBtn, false, 'Đang xác nhận...', 'Xác nhận');
                });
            }

            searchBtn.addEventListener('click', function() { searchOrder(false); });
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchOrder(false);
                }
            });
            document.getElementById('clear_results_btn').addEventListener('click', function() {
                searchInput.value = '';
                renderResults('');
                closeModal();
                clearNotice();
                state.lastSearch = '';
            });
            confirmBtn.addEventListener('click', confirmPayment);
            payerEl.addEventListener('change', toggleCodNote);
            resultsEl.addEventListener('click', function(e) {
                var confirmButton = e.target.closest('[data-open-confirm-modal="1"]');
                var smsButton = e.target.closest('[data-open-sms-modal="1"]');
                if (confirmButton) {
                    openModal(confirmButton);
                }
                if (smsButton) {
                    openSmsModal(smsButton);
                }
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-modal-close="1"]'), function(el) {
                el.addEventListener('click', closeModal);
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-sms-modal-close="1"]'), function(el) {
                el.addEventListener('click', closeSmsModal);
            });
            Array.prototype.forEach.call(document.querySelectorAll('.btc-copy-field'), function(el) {
                el.addEventListener('click', function() {
                    this.focus();
                    this.select();
                    copyText(this.value);
                });
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !modalEl.hidden) {
                    closeModal();
                }
                if (e.key === 'Escape' && !smsModalEl.hidden) {
                    closeSmsModal();
                }
            });
        });
    </script>
<?php
}

add_action('wp_ajax_search_order_ajax', 'handle_search_order_ajax');

function parse_order_search_tokens($raw_search)
{
    if (!is_string($raw_search) || $raw_search === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', $raw_search, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }

    return array_values(array_unique(array_map('trim', $parts)));
}

function normalize_order_search_code($token)
{
    $code = strtoupper(trim((string) $token));
    $code = str_replace('#', '', $code);

    if (strpos($code, 'P0') === 0 || strpos($code, 'P1') === 0) {
        $code = substr($code, 2);
    }

    return preg_replace('/\D+/', '', $code);
}

function resolve_order_from_search_code($token)
{
    $normalized = normalize_order_search_code($token);
    if ($normalized === '') {
        return null;
    }

    $attempts = [$normalized];
    if (strlen($normalized) > 2) {
        $attempts[] = substr($normalized, 0, -2);
    }

    foreach ($attempts as $candidate) {
        if ($candidate === '' || !is_numeric($candidate)) {
            continue;
        }

        $order = wc_get_order((int) $candidate);
        if ($order) {
            return $order;
        }
    }

    return null;
}

function handle_search_order_ajax()
{
    check_ajax_referer('search_order_ajax_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    $search = sanitize_text_field($_POST['search'] ?? '');
    if (empty($search)) {
        wp_send_json_error(['message' => 'Vui lòng nhập mã đơn hàng.'], 400);
    }

    $tokens = parse_order_search_tokens($search);
    if (empty($tokens)) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }

    $orders = [];
    $seen_order_ids = [];

    foreach ($tokens as $token) {
        $order = resolve_order_from_search_code($token);
        if (!$order) {
            continue;
        }

        $order_id = $order->get_id();
        if (isset($seen_order_ids[$order_id])) {
            continue;
        }

        $seen_order_ids[$order_id] = true;
        $orders[] = $order;
    }

    if (empty($orders)) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }

    ob_start();
    render_order_search_result($orders);
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'message' => sprintf('Đã tìm thấy %d đơn hàng.', count($orders)),
    ]);
}

function render_order_search_result($orders)
{
    echo '<div><h3>Đơn hàng tìm được</h3><p>Chọn đúng đơn hàng rồi bấm "Xác nhận chuyển khoản" hoặc "Gửi SMS" để thao tác.</p></div>';
    echo '<div class="btc-table-wrap"><table class="btc-table"><thead><tr>';
    echo '<th>Đơn hàng</th><th>Khách hàng</th><th>SĐT</th><th>Ngày đặt</th><th>Tổng tiền</th><th>Thanh toán</th><th>Trạng thái</th><th>Xử lý</th><th>Sản phẩm</th><th>Thao tác</th>';
    echo '</tr></thead><tbody>';
    foreach ($orders as $order) render_single_order_info($order);
    echo '</tbody></table></div>';
}

function render_single_order_info($order)
{
    $order_id = $order->get_id();
    $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $order_total = wc_price($order->get_total());
    $order_date = wc_format_datetime($order->get_date_created());
    $payment_method = $order->get_payment_method_title();
    $billing_phone = $order->get_billing_phone();
    $order_status = wc_get_order_status_name($order->get_status());
    $handling_status = get_post_meta($order_id, 'order_handling_status', true) ?: '';
    $paid_date = $order->get_meta('order_paid_date');
    $bank_account = $order->get_meta('order_bank_account_received');
    $new_order_id = function_exists('change_order_number') ? change_order_number($order_id) : $order_id;
    $sms_paid = "The An da nhan duoc thanh toan don $new_order_id. Don hang se som duoc giao toi ban. Cam on ban da tin mua An (Tin nhan tu dong)";
    $sms_recall = "The An xin phep thu hoi tin nhan loi. Rat xin loi vi da lam phien ban (Tin nhan tu dong)";
    $basic_info = sprintf(
        'Đơn #%d | Khách: %s | SĐT: %s | Tổng: %s | Trạng thái: %s',
        (int) $order_id,
        trim($billing_name),
        trim($billing_phone),
        wp_strip_all_tags($order_total),
        trim($order_status)
    );

    echo '<tr>';
    echo '<td><strong>#' . intval($order_id) . '</strong><br><a href="' . esc_url(admin_url('post.php?post=' . intval($order_id) . '&action=edit')) . '" target="_blank">Chỉnh sửa đơn hàng</a></td>';
    echo '<td>' . esc_html($billing_name) . '</td>';
    echo '<td>' . esc_html($billing_phone) . '</td>';
    echo '<td>' . esc_html($order_date) . '</td>';
    echo '<td>' . $order_total . '</td>';
    echo '<td>' . esc_html($payment_method);
    if ($paid_date || $bank_account) {
        echo '<br><small>';
        if ($paid_date) {
            echo 'Ngày nhận: ' . esc_html($paid_date);
        }
        if ($paid_date && $bank_account) {
            echo ' | ';
        }
        if ($bank_account) {
            echo 'TK nhận: ' . esc_html($bank_account);
        }
        echo '</small>';
    }
    echo '</td>';
    echo '<td>' . esc_html($order_status) . '</td>';
    echo '<td>' . esc_html(is_array($handling_status) ? implode(', ', $handling_status) : $handling_status) . '</td>';
    echo '<td><ul>';
    foreach ($order->get_items() as $item) {
        echo '<li>' . esc_html($item->get_name()) . ' x ' . intval($item->get_quantity()) . '</li>';
    }
    echo '</ul></td>';
    echo '<td><div class="btc-table__actions">';
    echo '<button type="button" class="button--small button--green" data-open-confirm-modal="1" data-order-id="' . intval($order_id) . '" data-order-total-raw="' . esc_attr(wc_format_decimal($order->get_total(), 0)) . '" data-order-basic-info="' . esc_attr($basic_info) . '">Xác nhận chuyển khoản</button>';
    echo '<button type="button" class="button--small button--white" data-open-sms-modal="1" data-order-basic-info="' . esc_attr($basic_info) . '" data-sms-phone="' . esc_attr($billing_phone) . '" data-sms-paid="' . esc_attr($sms_paid) . '" data-sms-recall="' . esc_attr($sms_recall) . '">Gửi SMS</button>';
    echo '</div></td>';
    echo '</tr>';
}

function render_sms_buttons($order)
{
    $phone = $order->get_billing_phone();
    $order_id = $order->get_id();
    $new_order_id = function_exists('change_order_number') ? change_order_number($order_id) : $order_id;

    $sms_templates = [
        'Đã nhận CK' => "The An da nhan duoc thanh toan don $new_order_id. Don hang se som duoc giao toi ban. Cam on ban da tin mua An (Tin nhan tu dong)",
        'Thu hồi SMS' => "The An xin phep thu hoi tin nhan loi. Rat xin loi vi da lam phien ban (Tin nhan tu dong)"
    ];

    echo '<h3>Gửi SMS</h3>';
    if (wp_is_mobile()) {
        echo '<div class="info-box" style="padding-left: 20px;"><div class="info-box__button-group">';
        foreach ($sms_templates as $type => $content) {
            $class = ($type === 'Đã nhận CK') ? 'info-box__btn--yellow button--green' : 'info-box__btn--black button--white';
            echo '<a style="margin-bottom: 20px;" class="info-box__btn ' . $class . '" href="sms:' . esc_attr($phone) . '?body=' . rawurlencode($content) . '">' . ucfirst($type) . '</a> ';
        }
        echo '</div></div>';
    } else {
        echo '<div style="margin-bottom: 20px;"><strong>BƯỚC 1: Copy SĐT</strong><br>' . esc_html($phone) . '</div>';
        echo '<p><strong>BƯỚC 2: Gửi qua Google Messages</strong></p>';
        echo '<div style="padding-left: 20px;"><a class="button--black" href="https://messages.google.com/web/conversations/new" target="_blank">Mở Google Messages</a></div>';
        echo '<div class="info-box"><p><strong>BƯỚC 3: Copy nội dung SMS</strong></p><p style="padding-left: 20px;">';
        foreach ($sms_templates as $type => $content) {
            echo '<span><b>' . ucfirst($type) . ':</b> <textarea readonly rows="1" style="width:100%;">' . esc_html($content) . '</textarea></span><br>';
        }
        echo '</p></div>';
    }
}

add_action('wp_ajax_confirm_order_payment', 'handle_confirm_order_payment');
function handle_confirm_order_payment()
{
    check_ajax_referer('confirm_order_payment_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
    }

    $order_id = intval($_POST['order_id']);
    $bank_account = sanitize_text_field($_POST['bank_account']);
    $paid_date = sanitize_text_field($_POST['paid_date']);
    $amount_received = floatval($_POST['amount_received']);
    $payer = sanitize_text_field($_POST['payer']);
    $cod_note = sanitize_textarea_field($_POST['cod_note']);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Đơn hàng không tồn tại.'], 404);
    }

    process_order_payment($order, $bank_account, $paid_date, $amount_received, $payer, $cod_note);
    wp_send_json_success([
        'message' => 'Đã cập nhật đơn hàng. Bank: ' . esc_html($order->get_meta('order_bank_account_received')) . ' | Date: ' . esc_html($order->get_meta('order_paid_date')),
    ]);
}

function process_order_payment($order, $bank_account, $paid_date, $amount_received, $payer, $cod_note)
{
    $order_id = $order->get_id();
    $order_total = $order->get_total();

    if (in_array($payer, ['shipper', 'self'])) {
        if (!empty($cod_note)) {
            $order->add_order_note("💰 {$payer} đã thanh toán tiền COD: {$cod_note}");
        }
        if ($payer === 'self' && !($amount_received > 0 && $amount_received < $order_total)) {
            $order->add_order_note("💰 Nhân viên đã thanh toán COD, đơn hàng được đánh dấu là Hoàn thành.");
            $order->update_status('completed');
        }
    } elseif ($payer === 'customer') {
        if ($order->get_payment_method() === 'cod') {
            $order->set_payment_method('bacs');
            $order->set_payment_method_title('Chuyển khoản ngân hàng');
            $order->add_order_note("🔄 Phương thức thanh toán đã được đổi từ COD sang Chuyển khoản ngân hàng.");
        }

        if (!empty($paid_date)) update_post_meta($order_id, 'order_paid_date', $paid_date);
        if (!empty($bank_account)) update_post_meta($order_id, 'order_bank_account_received', $bank_account);

        if ($amount_received > 0 && $amount_received < $order_total) {
            $order->update_status('partial-paid', "⚠️ Đã nhận {$amount_received} đ (Thanh toán một phần)");
        } else {
            $order->update_status('paid', "✅ Đã nhận đủ tiền, đơn hàng được đánh dấu là Đã CK.");
            if (function_exists('wc_reduce_stock_levels')) wc_reduce_stock_levels($order_id);
        }
    }

    $order->save();
}

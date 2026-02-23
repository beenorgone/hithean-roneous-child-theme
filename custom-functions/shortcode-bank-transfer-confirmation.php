<?php

/**
 * Shortcode: [order_paid_confirmation]
 * Purpose: Admin/Manager tool for searching orders and confirming bank transfers.
 */

// ==============================
// 🔹 MAIN SHORTCODE
// ==============================
add_shortcode('order_paid_confirmation', 'order_paid_confirmation_shortcode');
function order_paid_confirmation_shortcode()
{
    if (!current_user_can('manage_woocommerce')) {
        return ''; // Restrict to Admin / Shop Manager only
    }

    ob_start();

    render_order_paid_confirmation_ui();
    render_order_paid_confirmation_script();

    return ob_get_clean();
}

// ==============================
// 🔹 FRONT-END HTML LAYOUT
// ==============================
function render_order_paid_confirmation_ui()
{ ?>
    <div class="order-paid-confirmation">
        <h2>Xác nhận thanh toán đơn hàng</h2>

        <!-- 🔍 Search Box -->
        <?php render_search_ui(); ?>

        <!-- 📋 Search Results -->
        <p id="order_results" style="margin-top: 30px;"></p>

        <!-- Hidden holder for movable form -->
        <div id="order_details_holder" style="display:none;">
            <?php render_order_details_ui(); ?>
        </div>
    </div>
<?php
}

// ==============================
// 🔹 SEARCH UI
// ==============================
function render_search_ui()
{ ?>
    <div>
        <p><input type="text" id="order_search" placeholder="Nhập mã đơn (nhiều mã: cách nhau bằng dấu phẩy hoặc khoảng trắng)"></p>
        <div style="display: flex; flex-wrap: wrap; gap: 5px;">
            <button type="button" id="btn_search_order" class="button--small button--green">Tìm kiếm</button>
            <button type="button" id="clear_results_btn" class="button--small button--red">Xóa kết quả</button>
        </div>
    </div>
<?php
}

// ==============================
// 🔹 ORDER DETAILS UI
// ==============================
function render_order_details_ui()
{ ?>
    <div id="order_details" style="display: none; border: 2px solid var(--default-color-grey-2); padding: 20px; margin-top: 12px;">
        <h3>Xác nhận thông tin thanh toán</h3>
        <div style="padding-left: 20px; padding-left: 20px;display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
            <div style="display: flex; flex-direction: column; min-width: 320px; flex: 1 1 100%;">
                <label>Thông tin đơn hàng:</label>
                <textarea id="selected_order_info" rows="2" readonly style="resize: vertical;"></textarea>
            </div>

            <!-- Bank Account -->
            <div>
                <label>Tài khoản nhận:</label>
                <select id="bank_account">
                    <option value="Vietinbank 113600098383">Vietinbank 113600098383 / TK công ty</option>
                    <option value="ACB 11090087">ACB 11090087</option>
                    <option value="ACB 8700507">ACB 8700507</option>
                </select>
            </div>

            <!-- Paid Date -->
            <div><label>Ngày nhận CK:</label><input type="date" id="paid_date"></div>

            <!-- Amount -->
            <div><label>Số tiền nhận:</label><input type="number" id="amount_received"></div>

            <!-- Payer -->
            <div>
                <label>Người chuyển khoản:</label>
                <select id="payer">
                    <option value="customer">Khách hàng</option>
                    <option value="shipper">Shipper TT COD</option>
                    <option value="self">Nhân viên TT COD</option>
                </select>
            </div>

            <!-- COD Note -->
            <div id="cod_note_section" style="display: none;">
                <label>Ghi chú:</label>
                <textarea id="cod_note"></textarea>
            </div>

            <button class="button--small button" type="button" id="btn_confirm_payment">Xác nhận</button>
        </div>
    </div>
<?php
}

// ==============================
// 🔹 JAVASCRIPT HANDLERS
// ==============================
function render_order_paid_confirmation_script()
{ ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            function resetOrderDetailsPosition() {
                var holder = document.getElementById('order_details_holder');
                var details = document.getElementById('order_details');
                if (!holder || !details) return;
                holder.appendChild(details);
                details.style.display = 'none';
                details.removeAttribute('data-order-id');
            }

            window.resetOrderDetailsPosition = resetOrderDetailsPosition;

            // Bind Search Button
            var btnSearch = document.getElementById('btn_search_order');
            if (btnSearch) {
                btnSearch.addEventListener('click', searchOrder);
            }

            // Bind Enter Key for Search
            var inputSearch = document.getElementById('order_search');
            if (inputSearch) {
                inputSearch.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') searchOrder();
                });
            }

            // Bind Clear Button
            var btnClear = document.getElementById('clear_results_btn');
            if (btnClear) {
                btnClear.addEventListener('click', function() {
                    document.getElementById('order_search').value = '';
                    document.getElementById('order_results').innerHTML = '';
                    resetOrderDetailsPosition();
                });
            }

            // Bind Confirm Payment Button
            var btnConfirm = document.getElementById('btn_confirm_payment');
            if (btnConfirm) {
                btnConfirm.addEventListener('click', confirmPayment);
            }

            // Bind Payer Change
            var payerSelect = document.getElementById('payer');
            if (payerSelect) {
                payerSelect.addEventListener('change', function() {
                    document.getElementById('cod_note_section').style.display = (this.value === 'shipper' || this.value === 'self') ? 'block' : 'none';
                });
            }
        });

        function searchOrder() {
            var searchKey = document.getElementById('order_search').value;
            if (!searchKey) {
                alert("Vui lòng nhập mã đơn hàng hoặc số điện thoại!");
                return;
            }
            if (typeof resetOrderDetailsPosition === 'function') {
                resetOrderDetailsPosition();
            }
            var data = {
                action: 'search_order_ajax',
                search: searchKey
            };
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response) {
                document.getElementById('order_results').innerHTML = response;
            });
        }

        function selectOrder(buttonEl) {
            var orderID = buttonEl.getAttribute('data-order-id');
            var orderBasicInfo = buttonEl.getAttribute('data-order-basic-info') || '';
            var details = document.getElementById('order_details');
            var slot = document.getElementById('order-confirm-slot-' + orderID);
            if (!details || !slot) return;

            details.style.display = 'block';
            details.setAttribute('data-order-id', orderID);
            document.getElementById('selected_order_info').value = orderBasicInfo;
            slot.appendChild(details);

            var today = new Date().toISOString().split('T')[0];
            document.getElementById('paid_date').value = today;
        }

        function confirmPayment() {
            var orderID = document.getElementById('order_details').getAttribute('data-order-id');
            var bankAccount = document.getElementById('bank_account').value;
            var paidDate = document.getElementById('paid_date').value;
            var amountReceived = document.getElementById('amount_received').value;
            var payer = document.getElementById('payer').value;
            var codNote = document.getElementById('cod_note').value;

            if (!orderID || !paidDate || !bankAccount) {
                alert("Vui lòng nhập đầy đủ thông tin!");
                return;
            }

            var data = {
                action: 'confirm_order_payment',
                order_id: orderID,
                bank_account: bankAccount,
                paid_date: paidDate,
                amount_received: amountReceived,
                payer: payer,
                cod_note: codNote
            };

            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response) {
                alert(response);
                var lastSearch = document.getElementById('order_search').value;
                if (lastSearch) searchOrder();
            });
        }
    </script>
<?php
}

// ==============================
// 🔹 AJAX HANDLER: SEARCH ORDER
// ==============================
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
    if (!current_user_can('manage_woocommerce')) wp_die('Bạn không có quyền thực hiện thao tác này.');

    $search = sanitize_text_field($_POST['search'] ?? '');
    if (empty($search)) wp_die('Vui lòng nhập mã đơn hàng.');

    $tokens = parse_order_search_tokens($search);
    if (empty($tokens)) {
        echo '<p>Không tìm thấy đơn hàng.</p>';
        wp_die();
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
        echo '<p>Không tìm thấy đơn hàng.</p>';
        wp_die();
    }

    render_order_search_result($orders);
    wp_die();
}

// ==============================
// 🔹 DISPLAY ORDER SEARCH RESULT
// ==============================
function render_order_search_result($orders)
{
    echo '<div><h3>Đơn hàng tìm được</h3><p>Chọn đúng thẻ đơn hàng để xác nhận thanh toán:</p></div><div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; align-items: start;">';
    foreach ($orders as $order) render_single_order_info($order);
    echo '</div>';
}

function render_single_order_info($order)
{
    $order_id = $order->get_id();
    $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $order_total = wc_price($order->get_total());
    $order_date = wc_format_datetime($order->get_date_created());
    $payment_method = $order->get_payment_method_title();
    $billing_phone = $order->get_billing_phone();
    $order_status = wc_get_order_status_name($order->get_status());
    $handling_status = get_post_meta($order_id, 'order_handling_status', true) ?: '';
    $basic_info = sprintf(
        'Đơn #%d | Khách: %s | SĐT: %s | Tổng: %s | Trạng thái: %s',
        (int) $order_id,
        trim($billing_name),
        trim($billing_phone),
        wp_strip_all_tags($order_total),
        trim($order_status)
    );

    echo '<div style="border: 2px solid var(--default-color-grey-2); border-radius: 10px; padding: 14px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px;">';
    echo '<div><strong style="font-size:16px;">#' . intval($order_id) . '</strong> - ' . esc_html($billing_name) . '</div>';
    echo '<button type="button" class="button--small button--green" onclick="selectOrder(this)" data-order-id="' . intval($order_id) . '" data-order-basic-info="' . esc_attr($basic_info) . '">Chọn đơn này</button>';
    echo '</div>';
    echo '<div style="line-height:1.7;">';
    echo '<strong>Ngày đặt:</strong> ' . esc_html($order_date) . '<br>';
    echo '<strong>Tổng tiền:</strong> ' . $order_total . '<br>';
    echo '<strong>Phương thức thanh toán:</strong> <span class="text-highlight-green">' . esc_html($payment_method) . '</span><br>';
    echo '<strong>Trạng thái đơn hàng:</strong> <span class="text-highlight-green">' . esc_html($order_status) . '</span><br>';
    echo '<strong>Tình trạng xử lý:</strong> ' . esc_html($handling_status) . '<br>';
    echo '<strong>SĐT:</strong> ' . esc_html($billing_phone) . '<br>';

    if ($order->get_items()) {
        echo '<strong>Sản phẩm:</strong><ul>';
        foreach ($order->get_items() as $item) {
            echo '<li>' . esc_html($item->get_name()) . ' x ' . intval($item->get_quantity()) . '</li>';
        }
        echo '</ul>';
    }

    $edit_url = admin_url('post.php?post=' . intval($order_id) . '&action=edit');
    echo '<a href="' . esc_url($edit_url) . '" target="_blank">Chỉnh sửa đơn hàng</a>';

    echo '</div>';
    render_sms_buttons($order);
    echo '<div id="order-confirm-slot-' . intval($order_id) . '" style="margin-top: 12px;"></div>';
    echo '</div>';
}

// ==============================
// 🔹 RENDER SMS BUTTONS
// ==============================
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

// ==============================
// 🔹 AJAX HANDLER: CONFIRM PAYMENT
// ==============================
add_action('wp_ajax_confirm_order_payment', 'handle_confirm_order_payment');
function handle_confirm_order_payment()
{
    if (!current_user_can('manage_woocommerce')) wp_die('Bạn không có quyền thực hiện thao tác này.');

    $order_id = intval($_POST['order_id']);
    $bank_account = sanitize_text_field($_POST['bank_account']);
    $paid_date = sanitize_text_field($_POST['paid_date']);
    $amount_received = floatval($_POST['amount_received']);
    $payer = sanitize_text_field($_POST['payer']);
    $cod_note = sanitize_textarea_field($_POST['cod_note']);

    $order = wc_get_order($order_id);
    if (!$order) wp_die('Đơn hàng không tồn tại.');

    process_order_payment($order, $bank_account, $paid_date, $amount_received, $payer, $cod_note);
    wp_die("Đã cập nhật đơn hàng. Bank: " . $order->get_meta('order_bank_account_received') . " | Date: " . $order->get_meta('order_paid_date'));
}

// ==============================
// 🔹 CORE PAYMENT PROCESSOR
// ==============================
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

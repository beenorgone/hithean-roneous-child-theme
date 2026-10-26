<?php
if (!defined('ABSPATH')) exit;
/**
 * Tùy chỉnh hiển thị thông tin chuyển khoản & QR Code (Email + Thank You Page)
 * Tối ưu hóa: Không khai báo lại hàm, sử dụng Hook chuẩn.
 */

// 1. Tắt hiển thị BACS mặc định
add_filter('woocommerce_bacs_accounts', '__return_false');

// 2. Hook hiển thị QR vào Email (Thay thế code lỗi trong template cũ)
add_action('woocommerce_email_after_order_table', 'devvn_add_qr_to_email', 10, 4);

function devvn_add_qr_to_email($order, $sent_to_admin, $plain_text, $email) {
    if ($plain_text) return; // Không hiển thị trong email plain text

    // Chỉ hiển thị nếu phương thức là chuyển khoản ngân hàng (bacs)
    if ($order->get_payment_method() === 'bacs') {
        
        $order_id = $order->get_id();
        $order_total = $order->get_total();
        
        // QR theo TK chính (dòng đầu ở WooCommerce → Chuyển khoản ngân hàng)
        $qr_url = hithean_payment_qr_url(hithean_primary_bank_account(), (float) $order_total, hithean_payment_transfer_content($order_id));
        if ($qr_url === '') return;

        echo '<div style="margin-top: 20px; text-align: center; font-family: Be Vietnam, sans-serif;">';
        echo '<h3 style="font-weight: 700; margin-bottom: 20px;">QR THANH TOÁN</h3>';
        echo '<img src="' . esc_url($qr_url) . '" style="width: 200px; height: auto;">';
        echo '</div>';
    }
}

// 3. Hook hiển thị hướng dẫn trong Email (Trước bảng order)
add_action('woocommerce_email_before_order_table', 'devvn_email_instructions', 10, 3);
function devvn_email_instructions($order, $sent_to_admin, $plain_text = false) {
    if (!$sent_to_admin && $order->get_payment_method() === 'bacs' && $order->has_status('on-hold')) {
        devvn_bank_details($order->get_id());
    }
}

// 4. Hook hiển thị trang Thank You
add_action('woocommerce_thankyou_bacs', 'devvn_thankyou_page');
function devvn_thankyou_page($order_id) {
    devvn_bank_details($order_id);
}

// 5. Hàm helper để tính toán suffix mã đơn hàng (Dùng chung cho cả Email và Web)
function calculate_sepay_suffix($order_id) {
    $order_id_str = (string)$order_id; // Ép kiểu chuỗi để xử lý
    $nums1 = str_split($order_id_str, 2);
    $nums2 = str_split(strrev($order_id_str), 2);
    
    // Xử lý an toàn nếu mảng rỗng (dù hiếm khi xảy ra)
    $val1 = isset($nums1[0]) ? (int)$nums1[0] : 0;
    $val2 = isset($nums2[0]) ? (int)$nums2[0] : 0;
    
    $suffix = ceil(($val1 + $val2) / 2);
    return $order_id . $suffix;
}

// 6. Hàm hiển thị chi tiết ngân hàng (Logic chính)
function devvn_bank_details($order_id = '') {
    // Ẩn bảng mặc định lần nữa cho chắc chắn
    add_filter('woocommerce_bacs_accounts', '__return_false');
    
    $order_id = str_ireplace("#", "", $order_id); 
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Sử dụng hàm helper dùng chung
    $new_order_id = calculate_sepay_suffix($order_id);
    // Nội dung CK đầy đủ (tiền tố tài khoản ảo + mã đơn) — xem bank-accounts.php
    $transfer_content = hithean_payment_transfer_content($order_id);

    $order_total = $order->get_total(); 

    $tracking_url = home_url('/tra-cuu/');

    // Tài khoản từ cài đặt WooCommerce → Chuyển khoản ngân hàng (xem bank-accounts.php)
    $bacs_accounts = hithean_bank_accounts();
    
    if (!empty($bacs_accounts)) {
        ob_start();
        ?>
        <div id="section-notice" style="max-width: 900px; margin-bottom: 20px;">
            <h2>LƯU Ý</h2>
            <p>Cảm ơn bạn đã đặt hàng. Nếu bạn lựa chọn phương thức thanh toán qua ngân hàng / ví điện tử <strong>xin vui lòng chuyển đúng số tiền trong đơn hàng vào 1 trong các tài khoản của The An (phía dưới) kèm nội dung chuyển khoản như hướng dẫn trong bảng sau đây.</strong></p>
            <ul>
                <li>The An sẽ <strong>gửi tin nhắn xác nhận tự động tới số điện thoại đặt hàng</strong> của bạn ngay khi hệ thống nhận được chuyển khoản với nội dung chính xác từ bạn.</li>
            </ul>
        </div>
        
        <table style="border: 1px solid #ddd; border-collapse: collapse; max-width: 900px; width: 100%;">
            <tr>
                <td style="text-align: center; vertical-align: middle; background: #f9f9f9; padding: 10px; border: 1px solid #eaeaea;"><strong>Ngân hàng</strong></td>
                <td style="text-align: center; vertical-align: middle; background: #f9f9f9; padding: 10px; border: 1px solid #eaeaea;"><strong>Nội dung</strong></td>
            </tr>
            <?php
            foreach ($bacs_accounts as $bacs_account) {
                $bank_name = esc_html($bacs_account['bank']);
                $stk = esc_html($bacs_account['number']);
                $icon = esc_url($bacs_account['icon']); // Trường IBAN ở WooCommerce chứa link icon
                // QR riêng cho từng TK — khách quét đúng TK đang đọc
                $qr_code_url = esc_url(hithean_payment_qr_url($bacs_account, (float) $order_total, $transfer_content));
                ?>
                <tr>
                    <td style="width: 200px; border: 1px solid #eaeaea; padding: 10px; text-align: center; vertical-align: middle;">
                        <?php if ($icon): ?>
                            <img src="<?php echo $icon; ?>" alt="<?php echo $bank_name; ?>" style="max-width: 100px; height: auto;" />
                        <?php else: ?>
                            <strong><?php echo $bank_name; ?></strong>
                        <?php endif; ?>
                    </td>
                    <td style="border: 1px solid #eaeaea; padding: 15px;">
                        <div><strong>STK:</strong> <?php echo $stk; ?></div>
                        <div><strong>Ngân hàng:</strong> <?php echo $bank_name; ?></div>
                        <div style="margin-top: 10px; margin-bottom: 5px;"><strong>Nội dung chuyển khoản:</strong></div>
                        <div style="font-size: 24px; font-weight: 700; color: #0047ba;"><?php echo esc_html($transfer_content); ?></div>
                        <hr style="border: 0; border-top: 1px solid #eee; margin: 10px 0;">
                        <i style="font-size: 13px; color: #666;">Vui lòng ghi đúng và đủ nội dung trên. Trong đó <?php echo esc_html($new_order_id); ?> là mã đơn hàng của bạn</i>
                        <?php if ($qr_code_url): ?>
                            <div style="margin-top: 15px;">
                                <strong>QR Thanh Toán</strong><br>
                                <img style="width: 200px;" src="<?php echo $qr_code_url; ?>" alt="QR Code" />
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
            ?>
        </table>

        <div id="section-notice-2" style="max-width: 900px; margin-top: 20px;">
            <ul>
                <li>Nếu cần hỗ trợ chuyển đổi phương thức thanh toán. Bạn vui lòng liên hệ An qua các kênh hỗ trợ sau:
                    <ul>
                        <li>Email: <a href="mailto:info@hithean.com">info@hithean.com</a></li>
                        <li>Facebook: <a href="https://facebook.com/hitheanorganics">The An Organics</a></li>
                    </ul>
                </li>
                <li>Đơn hàng sẽ tự động hủy sau 1 giờ nếu không nhận được chuyển khoản hoặc thông tin điều chỉnh khác.</li>
                <li><b>Bạn sẽ nhận được email tự động chứa mã vận đơn và link theo dõi trạng thái đơn hàng khi đơn hàng xuất kho.</b></li>
                <li><b>Khách hàng vui lòng quay lại video khi mở gói hàng và sản phẩm để được hỗ trợ khi có sự cố liên quan đến sản phẩm</b>. The An sẽ không chịu trách nhiệm trong trường hợp hàng vỡ, mất, thiếu nếu khách hàng không quay lại video.</li>
            </ul>
            <div style="margin-top: 25px; text-align: left; margin-bottom: 20px;">
                <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" style="
                    display: inline-block; background-color: #b0dded; color: #0047ba;
                    padding: 12px 25px; text-decoration: none; border-radius: 5px;
                    font-weight: bold; font-size: 16px;">THEO DÕI ĐƠN HÀNG</a>
                <p style="margin-top: 10px; font-style: italic; font-size: 14px;">Bấm vào nút trên để đến trang tra cứu trạng thái đơn hàng</p>
            </div>
        </div>
        <?php
        echo ob_get_clean();
    }
}

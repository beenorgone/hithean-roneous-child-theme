<?php
if (!defined('ABSPATH')) exit;

/**
 * Tài khoản nhận tiền — điểm đọc DUY NHẤT của theme.
 *
 * Không khai số TK trong code theme. Nguồn:
 *  - TK ngân hàng (khách thấy + QR): WooCommerce → Cài đặt → Thanh toán →
 *    Chuyển khoản ngân hàng. TK đầu tiên = TK chính in QR ở email / danh sách đơn.
 *  - Danh sách chọn khi xác nhận thanh toán: plugin eim (Cài đặt → Kế toán →
 *    Tài khoản nhận tiền cho tiền mặt/ví/TK ngoài) đã tự gộp TK WooCommerce.
 *
 * Logic đọc/gộp/dựng QR nằm ở plugin eim (dùng chung nhiều site) — theme chỉ
 * gọi qua đây; eim tắt thì còn đọc thẳng WooCommerce, không có QR.
 */

/**
 * @return array<int,array{bank:string,number:string,holder:string,icon:string,label:string}>
 */
function hithean_bank_accounts(): array
{
    if (function_exists('eim_payment_bacs_accounts')) {
        return eim_payment_bacs_accounts();
    }
    $out = [];
    foreach ((array) get_option('woocommerce_bacs_accounts', []) as $row) {
        $row    = (array) $row;
        $number = preg_replace('/\s+/', '', (string) ($row['account_number'] ?? ''));
        if ($number === '') {
            continue;
        }
        $bank  = trim((string) ($row['bank_name'] ?? ''));
        $out[] = [
            'bank'   => $bank,
            'number' => $number,
            'holder' => trim((string) ($row['account_name'] ?? '')),
            'icon'   => trim((string) ($row['iban'] ?? '')),
            'label'  => trim($bank . ' ' . $number),
        ];
    }
    return $out;
}

/** TK chính (dòng đầu ở WooCommerce) — null nếu chưa khai. */
function hithean_primary_bank_account(): ?array
{
    if (function_exists('eim_payment_primary_account')) {
        return eim_payment_primary_account();
    }
    $accounts = hithean_bank_accounts();
    return $accounts[0] ?? null;
}

/**
 * Nội dung chuyển khoản của 1 đơn: tiền tố cấu hình ở eim (Cài đặt → Kế toán →
 * QR chuyển khoản, vd "TKPHTA" = tài khoản ảo HTA của ACB) + mã đơn hiển thị cho
 * khách (calculate_sepay_suffix()). Khách CK tay cũng phải ghi đủ chuỗi này.
 */
function hithean_payment_transfer_content($order_id): string
{
    $ref = calculate_sepay_suffix(absint($order_id));
    return function_exists('eim_payment_qr_content') ? eim_payment_qr_content((string) $ref) : 'TT' . $ref;
}

/** Link ảnh QR chuyển khoản; '' khi không có TK hoặc eim tắt. */
function hithean_payment_qr_url(?array $account, float $amount, string $des): string
{
    if (!$account || !function_exists('eim_payment_qr_url')) {
        return '';
    }
    return eim_payment_qr_url($account, $amount, $des);
}

/**
 * Nhãn TK cho dropdown "Tài khoản nhận" (metabox, trang tạo đơn, form xác nhận).
 * $keep: giá trị đơn đang lưu — luôn giữ trong danh sách để lưu lại đơn cũ không
 * xoá mất TK đã bỏ khỏi cấu hình.
 *
 * @param string[] $keep
 * @return string[]
 */
function hithean_payment_bank_accounts(array $keep = []): array
{
    if (function_exists('eim_payment_bank_accounts')) {
        $accounts = eim_payment_bank_accounts();
    } else {
        $accounts = array_column(hithean_bank_accounts(), 'label');
        $accounts[] = 'tiền mặt';
    }
    foreach ($keep as $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array($value, $accounts, true)) {
            $accounts[] = $value;
        }
    }
    return array_values(array_unique($accounts));
}

<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `woocommerce/checkout`

> Checkout: TK nhận tiền (BACS + eim), trang thanh toán, trang cảm ơn, xác nhận chuyển khoản / chờ thanh toán.

5 file · ~2.600 dòng · 59 hàm (17% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `confirm_order_payment` | shortcode-bank-transfer-confirmation.php |
| AJAX | `oppc_confirm_payment` | shortcode-payment-pending-confirm.php |
| AJAX | `oppc_load_orders` | shortcode-payment-pending-confirm.php |
| AJAX | `search_order_ajax` | shortcode-bank-transfer-confirmation.php |
| shortcode | `[order_paid_confirmation]` | shortcode-bank-transfer-confirmation.php |
| shortcode | `[order_payment_pending_confirm]` | shortcode-payment-pending-confirm.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `child_theme_eim_hub_payment_url` | shortcode-bank-transfer-confirmation.php |

## Hook khu này lắng nghe

`woocommerce_bacs_accounts` ×2 · `woocommerce_before_checkout_form` · `woocommerce_email_after_order_table` · `woocommerce_email_before_order_table` · `woocommerce_thankyou_bacs`

## File

### `woocommerce/checkout/bank-accounts.php`

| Hàm | Mô tả |
|---|---|
| `hithean_bank_accounts()` | — |
| `hithean_primary_bank_account()` | TK chính (dòng đầu ở WooCommerce) — null nếu chưa khai. |
| `hithean_payment_transfer_content($order_id)` | Nội dung chuyển khoản của 1 đơn: tiền tố cấu hình ở eim (Cài đặt → Kế toán → QR chuyển khoản, vd "TKPHTA" = tài khoản ảo HTA của ACB) + mã đơn hiển thị cho khách (calculate_sepa… |
| `hithean_payment_qr_url(?array $account, float $amount, string $des)` | Link ảnh QR chuyển khoản; '' khi không có TK hoặc eim tắt. |
| `hithean_payment_bank_accounts(array $keep = [])` | Nhãn TK cho dropdown "Tài khoản nhận" (metabox, trang tạo đơn, form xác nhận). $keep: giá trị đơn đang lưu — luôn giữ trong danh sách để lưu lại đơn cũ không xoá mất TK đã bỏ kh… |

### `woocommerce/checkout/checkout-page.php`

| Hàm | Mô tả |
|---|---|
| `add_custom_content_before_checkout()` | — |

### `woocommerce/checkout/shortcode-bank-transfer-confirmation.php`

| Hàm | Mô tả |
|---|---|
| `order_paid_confirmation_shortcode()` | — |
| `render_order_paid_confirmation_ui()` | — |
| `render_order_paid_confirmation_script()` | — |
| `parse_order_search_tokens($raw_search)` | — |
| `normalize_order_search_code($token)` | — |
| `resolve_order_from_search_code($token)` | — |
| `handle_search_order_ajax()` | — |
| `render_order_search_result($orders)` | — |
| `render_single_order_info($order)` | — |
| `render_sms_buttons($order)` | — |
| `handle_confirm_order_payment()` | — |
| `btc_eim_hub_payment_url(array $order_ids)` | URL công cụ "Xác nhận thanh toán" của plugin eim ở hub, mã đơn điền sẵn (?pc_site=&pc_ref=). Đơn khách CK chỉ xác nhận ở hub — nơi có danh sách giao dịch Sepay để gắn (mỗi giao… |
| `btc_eim_payment_gate(array $order_ids, string $payer, string $bank_account, string $paid_date, float $amount,…)` | Một cửa ghi: khi plugin eim bật, mọi xác nhận từ các form của theme đi qua engine eim (bắt buộc số tiền, nhật ký chỉ ghi thêm, gắn giao dịch ngân hàng). - Khách CK → không xác n… |
| `btc_parse_order_ids($raw)` | — |
| `process_order_payment($order, $bank_account, $paid_date, $amount_received, $payer, $cod_note)` | — |
| `btc_get_payment_confirmation_audit($order)` | — |
| `btc_record_payment_confirmation_audit($order, $type, $amount, $payer, $bank_account, $paid_date, $note)` | — |

### `woocommerce/checkout/shortcode-payment-pending-confirm.php`

| Hàm | Mô tả |
|---|---|
| `oppc_shortcode()` | — |
| `oppc_default_date_from()` | — |
| `oppc_default_date_to()` | — |
| `oppc_get_order_status_options()` | — |
| `oppc_get_confirmer_options()` | — |
| `oppc_render_ui()` | — |
| `oppc_render_assets()` | — |
| `oppc_ajax_load_orders()` | — |
| `oppc_sanitize_filters(array $source)` | — |
| `oppc_get_filtered_orders(array $filters)` | — |
| `oppc_order_matches_keyword(WC_Order $order, string $keyword)` | — |
| `oppc_parse_keyword_tokens(string $keyword)` | — |
| `oppc_token_matches_order_codes(string $token, array $order_codes)` | — |
| `oppc_normalize_order_code_token(string $token)` | — |
| `oppc_get_confirmation_audit(WC_Order $order)` | — |
| `oppc_has_confirmation_audit(WC_Order $order)` | — |
| `oppc_has_legacy_bank_confirmation(WC_Order $order)` | — |
| `oppc_is_pending_bacs(WC_Order $order)` | — |
| `oppc_is_pending_cod(WC_Order $order)` | — |
| `oppc_has_only_external_cod_carriers(WC_Order $order)` | — |
| `oppc_get_order_shippers(WC_Order $order)` | Đối tác giao hàng được lưu ở meta "order_shipper" (Meta Box select_advanced, multiple). Trả về mảng các giá trị đã chọn (đã lowercase/trim). |
| `oppc_get_shipper_labels()` | Map key đối tác giao hàng sang nhãn hiển thị — đọc từ hithean_shipper_labels() (order-shipper.php, luôn nạp); mảng dưới chỉ là dự phòng. |
| `oppc_get_order_shipper_display(WC_Order $order)` | Chuỗi đối tác giao hàng đã chọn để hiển thị; rỗng nếu chưa chọn. |
| `oppc_is_external_carrier(string $carrier)` | GHTK và Viettel Post tự thu hộ COD nên không cần xác nhận thanh toán nội bộ. |
| `oppc_render_results(array $orders, array $filters)` | — |
| `oppc_render_order_row(WC_Order $order, bool $is_audit_view)` | — |
| `oppc_render_audit_cell(WC_Order $order)` | — |
| `oppc_ajax_confirm_payment()` | — |
| `oppc_parse_order_ids($raw)` | — |
| `oppc_process_confirmation(WC_Order $order, string $bank_account, string $paid_date, float $amount_received, string…)` | — |
| `oppc_record_confirmation_audit(WC_Order $order, string $type, float $amount, string $payer, string $bank_account, string…)` | — |

### `woocommerce/checkout/thank-you-page.php`

| Hàm | Mô tả |
|---|---|
| `devvn_add_qr_to_email($order, $sent_to_admin, $plain_text, $email)` | — |
| `devvn_email_instructions($order, $sent_to_admin, $plain_text = false)` | — |
| `devvn_thankyou_page($order_id)` | — |
| `calculate_sepay_suffix($order_id)` | — |
| `devvn_bank_details($order_id = '')` | — |


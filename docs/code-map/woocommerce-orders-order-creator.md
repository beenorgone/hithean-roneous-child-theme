<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `woocommerce/orders/order-creator`

> Trang Tạo đơn hộ khách /tao-don/.

2 file · ~4.600 dòng · 102 hàm (59% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `order_creator_ai_extract_customer` | create-order-for-customer.php |
| AJAX | `order_creator_ai_extract_order` | create-order-for-customer.php |
| AJAX | `order_creator_create_customer` | create-order-for-customer.php |
| AJAX | `order_creator_create_order` | create-order-for-customer.php |
| AJAX | `order_creator_customer_confirmed` | create-order-for-customer.php |
| AJAX | `order_creator_customer_coupons` | create-order-for-customer.php |
| AJAX | `order_creator_customer_history` | create-order-for-customer.php |
| AJAX | `order_creator_invoice_preview` | create-order-for-customer.php |
| AJAX | `order_creator_load_order` | create-order-for-customer.php |
| AJAX | `order_creator_match_customer` | create-order-for-customer.php |
| AJAX | `order_creator_recalculate` | create-order-for-customer.php |
| AJAX | `order_creator_save_customer_addresses` | create-order-for-customer.php |
| AJAX | `order_creator_save_settings` | create-order-for-customer.php |
| AJAX | `order_creator_search_coupons` | create-order-for-customer.php |
| AJAX | `order_creator_search_customers` | create-order-for-customer.php |
| AJAX | `order_creator_search_orders` | create-order-for-customer.php |
| AJAX | `order_creator_search_products` | create-order-for-customer.php |
| AJAX | `order_creator_vn_wards` | create-order-for-customer.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `order_creator_ai_catalog_limit` | create-order-for-customer.php |
| `order_creator_ai_config` | create-order-for-customer.php |
| `order_creator_bank_accounts` | create-order-for-customer.php |
| `order_creator_customer_custom_field_defs` | create-order-for-customer.php |
| `order_creator_customer_info_field_defs` | create-order-for-customer.php |
| `woocommerce_checkout_create_order_line_item` | create-order-for-customer.php |
| `woocommerce_order_statuses_that_restore_stock` | create-order-for-customer.php |

## Hook khu này lắng nghe

`admin_init` · `show_admin_bar` · `template_redirect` · `woocommerce_add_to_cart` · `woocommerce_add_to_cart_validation` · `woocommerce_applied_coupon` · `woocommerce_before_calculate_totals` · `woocommerce_cart_calculate_fees` · `woocommerce_checkout_create_order_fee_item` · `woocommerce_checkout_create_order_line_item` · `woocommerce_checkout_customer_id` · `woocommerce_coupon_get_usage_count` · `woocommerce_coupon_validate_user_usage_limit` · `woocommerce_email_additional_content_customer_invoice` · `woocommerce_email_before_order_table` ×2 · `woocommerce_get_order_item_totals` · `woocommerce_is_purchasable` · `woocommerce_package_rates` · `woocommerce_product_get_price` · `woocommerce_product_has_enough_stock` · `woocommerce_product_is_in_stock` · `⟨"woocommerce_email_enabled_{$id}"⟩` · `⟨$order_creator_catalog_hook⟩`

## File

### `woocommerce/orders/order-creator/ai-address.php`

| Hàm | Mô tả |
|---|---|
| `order_creator_ai_prepare_customer(array $data, bool $convert, ?array &$used = null)` | Quy đổi riêng sau bóc tách để model không phải tra địa chỉ cùng lúc với catalog sản phẩm. Chỉ nhận kết quả xác minh được và khớp danh mục; lỗi tra cứu vẫn giữ nguyên đơn. Không… |

### `woocommerce/orders/order-creator/create-order-for-customer.php`

| Hàm | Mô tả |
|---|---|
| `order_creator_state()` | — |
| `order_creator_can()` | — |
| `order_creator_verify_ajax()` | — |
| `order_creator_get_payload()` | — |
| `order_creator_ensure_wc_cart()` | Đảm bảo WC cart/session/customer được khởi tạo (admin-ajax không tự load). |
| `order_creator_default_settings()` | — |
| `order_creator_get_settings()` | — |
| `order_creator_customer_info_field_defs()` | Field "Thông tin khách hàng" (chỉ đọc) hiển thị từ user-meta. hithean chưa có hệ thống điểm/CRM riêng → để trống; thêm field qua filter khi cần: add_filter('order_creator_custom… |
| `order_creator_order_field_defs()` | Field meta-đơn cấu hình hiển thị phía trên bảng tổng. Key trùng meta_key của order-metabox.php để dữ liệu khớp với metabox WP Admin + cột ACP. |
| `order_creator_apply_order_meta(WC_Order $order, array $payload)` | — |
| `order_creator_customer_custom_field_defs()` | Field user-meta tùy biến hiển thị/sửa trong popup khách hàng. hithean chưa có metabox user riêng → để trống; mở rộng qua filter khi cần: add_filter('order_creator_customer_custo… |
| `order_creator_customer_field_defs()` | Danh sách field có thể bật/tắt cho popup khách hàng. |
| `order_creator_wdr_free_shipping_applies()` | Woo Discount Rules Pro có rule freeship riêng nhưng đôi khi chưa register rate trong AJAX. |
| `order_creator_with_customer_context(int $user_id, callable $fn)` | Chạy callback dưới danh tính khách hàng đích, snapshot + khôi phục giỏ của nhân viên. Mạo danh current_user để plugin đọc đúng role/khách. |
| `order_creator_coupon_edit_url(int $coupon_id)` | Link sửa coupon cho operator. Không dùng get_edit_post_link() trong lúc mạo danh khách vì hàm đó kiểm tra quyền theo current user hiện tại. |
| `order_creator_coupon_description(WC_Coupon $coupon)` | — |
| `order_creator_reset_shipping_cache()` | Xoá cache rate để các rule ship/free-ship chạy lại theo cart hiện tại. |
| `order_creator_choose_checkout_shipping_rate()` | Chọn shipping rate giống checkout: ưu tiên freeship, rồi rate đang chọn, rồi rate đầu tiên. |
| `order_creator_calculate_cart_totals()` | Tính tổng theo checkout pipeline và refresh shipping sau khi discount rules đã chạy, vì một số rule freeship chỉ quyết định trong pha calculate_totals. |
| `order_creator_populate_cart(array $payload)` | Nạp SP, coupon, phí, ship vào giỏ theo payload (chưa tính tổng). |
| `order_creator_verify_coupon_results()` | Đối chiếu kết quả áp mã với giỏ SAU KHI tính tổng xong. |
| `order_creator_assert_coupons_applied()` | Tạo / cập nhật đơn: chặn khi có mã đã chọn mà không áp được (tránh đơn thiếu khoản giảm). |
| `order_creator_coupon_detail(string $code)` | Thông tin tóm tắt 1 mã ưu đãi để hiển thị popup "Xem chi tiết". Trả về null khi mã không tồn tại trong hệ thống. |
| `order_creator_is_wdr_gift_data(array $data)` | Dòng quà Woo Discount Rules tự thêm (BOGO mua X tặng X / tặng Y): cart item mang khóa `wdr_free_product`… Quà do rule quyết định theo giỏ — KHÔNG phải hàng nhân viên chọn. Hàm t… |
| `order_creator_order_item_is_wdr_gift(WC_Order_Item $item)` | Dòng đơn là quà WDR tự thêm? Cờ `_oc_auto_gift` (Trang Tạo đơn ghi khi lưu) / khóa meta WDR có "free"; dòng 0đ có meta WDR cũng tính (đơn cũ). Dòng 0đ không có meta WDR = quà nh… |
| `order_creator_wdr_labels()` | Nhãn ưu đãi của Woo Discount Rules (tiêu đề rule + nhãn giảm giỏ `cart_adjustments.label`), chữ thường — để nhận ra phí âm / coupon ảo WDR tạo trên đơn CŨ (chưa có cờ `_oc_auto_… |
| `order_creator_cart_fee_is_manual($fee)` | Phí của giỏ có phải phí nhân viên nhập (state fees) không — còn lại do plugin (WDR giảm giỏ dạng phí âm…) tự thêm. |
| `order_creator_order_fee_is_auto(WC_Order_Item_Fee $item)` | Dòng phí trên đơn do plugin giảm giá tự thêm (không nạp lại khi sửa đơn). |
| `order_creator_snapshot_cart()` | — |
| `order_creator_disable_emails()` | Tắt toàn bộ email giao dịch WooCommerce cho request hiện tại. |
| `order_creator_draft_status()` | Status cho đơn nháp: ưu tiên 'checkout-draft' của WC, fallback 'pending'. |
| `order_creator_should_suppress_email(array $payload)` | Email mặc định TẮT khi tạo/sửa đơn; chỉ gửi khi suppress_email === false. |
| `order_creator_guard_operator_identity(WC_Order $order)` | Chốt danh tính đơn: customer_id = khách đích; email billing trùng email nhân viên thao tác (khi đơn không phải của chính nhân viên đó) → thay bằng email khách (hồ sơ) hoặc để tr… |
| `order_creator_apply_addresses(WC_Order $order, array $payload)` | Gán địa chỉ billing/shipping lên đơn từ payload. |
| `order_creator_save_customer_addresses(array $payload)` | Đồng bộ duy nhất thông tin billing/shipping chuẩn khi nhân viên chủ động chọn lưu. |
| `order_creator_custom_shipping_title(array $payload)` | Đồng bộ line item / fee / shipping / coupon từ giỏ đã tính sang đơn có sẵn. Dùng cho luồng CHỈNH SỬA (xoá item cũ, dựng lại từ giỏ). |
| `order_creator_shipping_method_parts(string $rate_id)` | — |
| `order_creator_add_custom_shipping_item(WC_Order $order, array $payload)` | — |
| `order_creator_apply_custom_shipping_title(WC_Order $order, array $payload)` | — |
| `order_creator_sync_order_from_cart(WC_Order $order, array $payload)` | — |
| `order_creator_order_stock_was_reduced(WC_Order $order)` | — |
| `order_creator_set_order_stock_reduced(WC_Order $order, bool $reduced)` | — |
| `order_creator_order_status_should_reduce_stock(WC_Order $order, bool $is_draft)` | — |
| `order_creator_restore_reduced_stock_before_edit(WC_Order $order)` | — |
| `order_creator_reduce_stock_after_edit(WC_Order $order, bool $is_draft)` | — |
| `order_creator_apply_source(WC_Order $order)` | Gắn nguồn đơn = "Quản trị web" (WooCommerce Order Attribution). source_type 'admin' → cột "Nguồn" hiển thị "Web admin" / "Quản trị web". |
| `order_creator_apply_status_and_notes(WC_Order $order, array $payload, bool $is_draft, string $action_label)` | — |
| `order_creator_update_order(int $order_id, array $payload)` | CHỈNH SỬA đơn có sẵn: chạy lại pipeline rồi đồng bộ vào đơn. |
| `order_creator_build_order(array $payload)` | Dựng WC_Order từ giỏ đã tính (giữ nguyên giá chính sách). |
| `order_creator_order_meta_text(WC_Order $order, array $keys)` | — |
| `order_creator_order_has_quick_shipping(WC_Order $order)` | — |
| `order_creator_order_is_local_delivery(WC_Order $order)` | — |
| `order_creator_customer_confirmed_cod_order(WC_Order $order)` | — |
| `order_creator_stock_label(WC_Product $product)` | Nhãn tồn kho ngắn gọn cho 1 product/variation. |
| `order_creator_product_image(WC_Product $product)` | Ảnh thumbnail của product (fallback placeholder). |
| `order_creator_product_is_hidden(WC_Product $product)` | SP ẩn (hidden visibility) hoặc riêng tư (private status). |
| `order_creator_product_price(WC_Product $product)` | Giá hiệu lực, fallback về _price khi rule front-end đang ẩn giá sản phẩm. |
| `order_creator_format_product(WC_Product $product)` | Chuẩn hoá 1 product → entry cho kết quả tìm/SP mặc định. |
| `order_creator_sort_hidden_last(array $entries)` | Sắp SP ẩn/riêng tư xuống cuối (giữ thứ tự gốc trong mỗi nhóm). |
| `order_creator_customer_contacts(array $payload)` | SĐT (dạng 0xxxxxxxxx), email và user id của khách trong payload để dò mã gắn riêng. |
| `order_creator_customer_coupon_ids(array $contacts)` | Coupon gắn với khách: tạo từ Lucky Wheel (SĐT/email/tài khoản lúc quay) hoặc gắn trực tiếp (giới hạn email trong coupon, hoặc SĐT ghi trong mô tả coupon). |
| `order_creator_coupon_value_text(WC_Coupon $coupon)` | — |
| `order_creator_customer_coupons(array $payload, bool $check_cart)` | Danh sách mã của khách còn hạn/còn lượt. $check_cart = true khi đang ở trong order_creator_with_customer_context() với giỏ đã nạp: kiểm tra luôn mã có áp được cho giỏ không. |
| `order_creator_phone_variants(string $raw)` | — |
| `order_creator_user_phone_meta_query(string $term)` | — |
| `order_creator_is_phone_search(string $value)` | — |
| `order_creator_customer_has_role_fragment(WP_User $user, array $needles)` | — |
| `order_creator_latest_customer_payment_method(int $user_id)` | — |
| `order_creator_default_customer_payment_method(WP_User $user)` | — |
| `order_creator_format_customer(WP_User $user)` | — |
| `order_creator_normalize_order_search_term(string $term)` | — |
| `order_creator_add_order_search_candidate(array &$orders, string $term)` | — |
| `order_creator_search_order_ids(string $term, int $limit = 20)` | ID đơn khớp từ khóa (tên, SĐT, email, địa chỉ, SP…), mới nhất trước. wc_order_search() chạy cả HPOS lẫn posts; wc_get_orders() KHÔNG có tham số 'search' (bị bỏ qua → trả đơn mới… |
| `order_creator_ai_config()` | Cấu hình AI riêng cho feature bóc tách khách hàng. Mặc định lấy từ trang Settings → Cài đặt ERP (mục AI); override qua wp-config.php: define('ORDER_CREATOR_AI_PROVIDER', 'claude… |
| `order_creator_ai_convert_flag()` | Checkbox "Chuyển địa chỉ cũ sang 2026" (mặc định bật). |
| `order_creator_ai_uploaded_images(string $field, int $max)` | Ảnh upload cho AI: nhận cả field đơn (`image`) lẫn mảng (`images[]`). |
| `order_creator_ai_search_enabled(string $provider, bool $convert_old_address)` | Google Search chỉ bật với Gemini và khi cần quy đổi địa chỉ cũ. |
| `order_creator_ai_run(string $system_head, string $search_rules, string $system_tail, string $prompt, array $im…)` | Gọi AI theo cấu hình feature, tự chuyển key/provider khi bị limit. System prompt dựng lại theo provider thực tế của từng lượt thử vì quy tắc Google Search chỉ áp cho Gemini. |
| `order_creator_ai_customer_rules()` | Quy tắc tên/SĐT/địa chỉ dùng chung cho mọi prompt bóc tách khách. |
| `order_creator_ai_address_rules(bool $convert_old_address)` | — |
| `order_creator_ai_normalize_customer(array $data)` | Chuẩn hóa object khách AI trả về: gộp full địa chỉ vào address_1, quy state/city về mã danh mục. |
| `order_creator_ai_catalog_lines()` | Dòng catalog gửi AI: "id\|sku\|tên", biến thể tách dòng riêng. Cache 30 phút, xoá khi sửa SP. |
| `order_creator_ai_product_entry(int $id)` | 1 sản phẩm bán được (simple/variation) dạng dòng giỏ. Variable cha → null (phải chọn biến thể). |
| `order_creator_ai_entries_for(int $id)` | Ứng viên cho 1 id: variable cha → mọi biến thể; còn lại → chính nó. |
| `order_creator_ai_resolve_item(array $raw)` | Khớp từng dòng AI đọc được với sản phẩm thật. Dòng chỉ "ok" khi xác định đúng 1 SP bán được, AI chắc chắn và SL là số nguyên dương; còn lại "confirm" kèm ứng viên để nhân viên c… |
| `order_creator_find_existing_customers(string $phone, string $email)` | Tìm khách cũ theo SĐT/email: tài khoản (email, billing_email, SĐT billing/shipping đã chuẩn hóa) và lịch sử đơn (đơn gắn tài khoản → tài khoản đó; đơn khách lẻ → khách cũ chưa c… |
| `order_creator_order_customer_has_trade_role(WC_Order $order)` | — |
| `order_creator_order_meta_values(WC_Order $order, string $key)` | — |
| `order_creator_order_needs_compact_invoice(WC_Order $order)` | — |
| `order_creator_invoice_html(WC_Order $order)` | — |
| `order_creator_invoice_qr_hosts()` | Host ảnh QR được phép tải về nhúng vào hóa đơn (danh sách cố định — không tải URL bất kỳ, tránh SSRF). Mẫu link QR đổi được ở cài đặt eim (mặc định qr.sepay.vn). |
| `order_creator_invoice_qr_host_allowed(string $url)` | URL ảnh có thuộc host QR cho phép không (https + host trong danh sách). |
| `order_creator_invoice_qr_data_uri(string $url)` | Ảnh QR → data URI (cache transient 1 ngày theo md5(url)). Lỗi / quá 300KB / không phải ảnh → ''. |
| `order_creator_invoice_inline_qr_images(string $html)` | Nhúng ảnh QR của hóa đơn thành data URI — chỉ dùng cho trang xem/chụp hóa đơn (không đổi email / trang cảm ơn). |
| `order_creator_first_segment()` | Segment đầu tiên của URL (vd: /tao-don/abc → 'tao-don'). |
| `order_creator_is_route()` | — |
| `order_creator_assets_uri(string $file)` | — |
| `order_creator_assets_path(string $file)` | — |
| `order_creator_configured_shipping_methods()` | Shipping methods enabled in WooCommerce Shipping Zones, for the selector UI. |
| `order_creator_js_config()` | Cấu hình truyền sang JS. |
| `order_creator_render_page()` | — |


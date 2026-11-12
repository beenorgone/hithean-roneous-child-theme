<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `woocommerce/orders`

> Đơn hàng: trạng thái, số đơn, khóa đơn cũ, bước vận hành + board "Cần xử lý", bảng điều phối, tìm đơn, /quan-ly-don, HPOS, VAT, xuất kho + Lark.

35 file · ~11.000 dòng · 411 hàm (62% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `child_theme_hub_pay_url` | payment-hub-link.php |
| AJAX | `child_theme_hub_sepay_url` | payment-hub-link.php |
| AJAX | `thean_customer_note_templates` | customer-note-templates.php |
| AJAX | `thean_order_export_review` | order-export-review.php |
| AJAX | `thean_order_usearch` | order-universal-search.php |
| AJAX | `thean_order_usearch_alerts` | order-universal-search.php |
| AJAX | `thean_order_usearch_board` | order-universal-search.php |
| AJAX | `thean_order_usearch_bulk` | order-universal-search.php |
| AJAX | `thean_order_usearch_flush` | order-universal-search.php |
| AJAX | `thean_order_usearch_preview` | order-universal-search.php |
| AJAX | `thean_order_usearch_rows` | order-universal-search.php |
| AJAX | `thean_order_workspace` | order-workspace.php |
| AJAX | `thean_vat_request_ai` | order-vat-request.php |
| AJAX | `thean_vat_request_get` | order-vat-request.php |
| AJAX | `thean_vat_request_save` | order-vat-request.php |
| AJAX | `thean_vat_request_upload` | order-vat-request.php |
| admin-post | `hithean_export_lark_review` | warehouse-export-lark-follow-up.php |
| admin-post công khai | `hithean_export_lark_review` | warehouse-export-lark-follow-up.php |
| cron | `hithean_export_ai_check_async` | warehouse-export-workflow.php |
| cron | `hithean_export_lark_alert_async` | warehouse-export-lark.php |
| cron | `hithean_export_lark_batch_async` | warehouse-export-lark.php |
| cron | `hithean_export_lark_notify_async` | warehouse-export-lark.php |
| cron | `hithean_export_lark_remind_async` | warehouse-export-lark-follow-up.php |
| cron | `thean_ops_step_backfill` | order-ops-step.php |
| cron | `thean_ops_step_sweep` | order-ops-step.php |
| cron | `⟨$hook⟩` | order-ops-step.php |
| shortcode | `[bulk_change_order_status_form]` | shortcode-order-status-change.php |
| shortcode | `[woocommerce_order_tracking]` | order-tracking.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `thean_hpos_read_private_keys` | order-hpos-compat.php |
| `thean_legacy_meta_keys` | order-legacy-meta-sync.php |
| `thean_ops_excluded_statuses` | order-ops-step.php |
| `thean_ous_status_groups` | order-universal-search.php |
| `woocommerce_admin_order_actions` | order-universal-search.php |
| `woocommerce_order_tracking_form` | order-tracking.php |
| `woocommerce_order_tracking_form_end` | order-tracking.php |
| `woocommerce_order_tracking_form_start` | order-tracking.php |

## Hook khu này lắng nghe

`acp/column/value` · `acp/storage_model/meta` · `add_meta_boxes` · `added_post_meta` · `admin_body_class` ×2 · `admin_enqueue_scripts` ×3 · `admin_footer` ×9 · `admin_head` ×2 · `admin_init` ×2 · `admin_menu` · `admin_notices` · `admin_print_footer_scripts` · `child_theme_lark_settings_fields` · `child_theme_lark_settings_save` · `cron_schedules` · `current_screen` · `deleted_post_meta` ×2 · `disable_months_dropdown` · `edit_form_top` · `eim_is_internal_order` · `get_post_metadata` · `hithean_export_ai_check_async` · `hithean_export_ai_check_completed` · `hithean_export_lark_alert_async` · `hithean_export_lark_batch_async` · `hithean_export_lark_notify_async` · `hithean_export_lark_remind_async` · `hithean_warehouse_export_confirmed` · `init` ×3 · `manage_posts_extra_tablenav` · `order_edit_form_top` · `post_class` · `posts_pre_query` · `redirect_post_location` · `request` · `rwmb_field_meta` · `rwmb_meta_boxes` · `rwmb_warehouse_export_images_value` · `save_post_shop_order` · `shutdown` ×3 · `template_redirect` ×2 · `thean_ops_step_backfill` · `thean_ops_step_sweep` · `theme_ai_feature_registry` · `updated_post_meta` · `wc_order_statuses` · `woocommerce_admin_order_actions` ×4 · `woocommerce_admin_order_actions_end` · `woocommerce_admin_order_actions_start` · `woocommerce_after_order_object_save` · `woocommerce_before_order_itemmeta` · `woocommerce_before_order_object_save` · `woocommerce_hpos_pre_query` · `woocommerce_order_list_table_extra_tablenav` · `woocommerce_order_list_table_prepare_items_query_args` · `woocommerce_order_number` · `woocommerce_order_status_changed` ×2 · `woocommerce_order_status_failed` · `woocommerce_order_status_on-hold` · `woocommerce_order_status_packaging` · `woocommerce_order_status_processing` · `woocommerce_order_status_received-payment` · `woocommerce_order_status_shipping` · `woocommerce_order_statuses_that_restore_stock` · `woocommerce_process_shop_order_meta` · `woocommerce_thankyou` · `woocommerce_update_order` · `wp_footer` ×2 · `⟨$thean_hpc_hook⟩` · `⟨$thean_lms_hook⟩` · `⟨$thean_ops_hook⟩` ×2

## File

### `woocommerce/orders/customer-note-templates.php`

| Hàm | Mô tả |
|---|---|
| `thean_customer_note_templates_option_name()` | — |
| `thean_customer_note_templates_user_can_manage()` | — |
| `thean_customer_note_templates_normalize_type(string $type)` | — |
| `thean_customer_note_templates_defaults()` | — |
| `thean_customer_note_templates_normalize($templates)` | — |
| `thean_customer_note_templates_get()` | — |
| `thean_customer_note_templates_save(array $templates)` | — |
| `thean_customer_note_templates_is_order_admin_screen()` | — |
| `thean_customer_note_templates_ajax_response()` | — |
| `thean_customer_note_templates_enqueue()` | — |

### `woocommerce/orders/edit-order-page.php`

| Hàm | Mô tả |
|---|---|
| `hithean_order_item_has_the_an_organics_brand($product)` | — |
| `hithean_display_order_item_seal_checkbox($item_id, $item, $product)` | — |
| `wr_order_item_edit_lock_user_is_restricted()` | — |
| `wr_order_item_edit_lock_render_ui()` | — |

### `woocommerce/orders/hpos-order-check.php`

### `woocommerce/orders/order-actions-column.php`

Cột "Thao tác" ở danh sách đơn admin: [nút bước tiếp theo] [✏ Sửa] [⋯ Thêm ▾].

| Hàm | Mô tả |
|---|---|
| `hithean_order_action_groups()` | Nhóm hiển thị trong menu "Thêm" (cũng là nhóm màu ở ô tìm đơn). |
| `hithean_order_action_meta(string $slug)` | Nhãn đủ, nhãn ngắn (ô tìm đơn), biểu tượng và nhóm của từng action. |
| `hithean_oac_meta_list(WC_Order $order, string $key)` | Meta nhiều giá trị (handling_status...) → mảng chuỗi. $map: tên field → meta key riêng của hithean (như thean_ow_key). |
| `hithean_oac_export_statuses()` | Trạng thái còn ở khâu kho (chưa giao) — mới gợi ý bước up ảnh / xác nhận xuất kho. |
| `hithean_oac_primary_slug(WC_Order $order, array $actions)` | Action của bước tiếp theo (rút gọn từ luồng ở order-workspace.php): COD cần khách xác nhận → CK chưa thanh toán → chưa có ảnh xuất kho → chưa xác nhận xuất kho. Trả '' khi không… |
| `hithean_oac_tracking_actions($actions, $order)` | — |
| `hithean_oac_capture($actions, $order)` | — |
| `hithean_oac_render($order)` | — |
| `hithean_oac_toolbar_links()` | Link dùng chung (không theo từng đơn) — đặt 1 lần trên thanh công cụ thay vì lặp ở mỗi dòng. |
| `hithean_oac_render_toolbar(string $which)` | — |
| `hithean_oac_assets()` | — |

### `woocommerce/orders/order-admin-error-solver.php`

| Hàm | Mô tả |
|---|---|
| `hithean_order_admin_current_post_id()` | — |
| `hithean_admin_error_solver_write_log($name, $line)` | — |
| `hithean_order_save_solver_mark($event, $post_id = 0)` | — |
| `hithean_keep_order_edit_redirect($location, $post_id = 0)` | — |
| `hithean_order_save_error_solver()` | — |

### `woocommerce/orders/order-age-lock.php`

| Hàm | Mô tả |
|---|---|
| `thean_order_lock_cutoff_date()` | Ngày mốc khóa chọn ở Cài đặt ERP (Y-m-d) — '' = dùng mặc định THEAN_ORDER_STATUS_LOCK_DAYS ngày. |
| `thean_order_lock_cutoff_ts()` | Mốc khóa (timestamp): đơn tạo TRƯỚC mốc là đơn cũ. Ngày mốc tính từ 00:00 theo múi giờ site. |
| `thean_order_lock_label()` | Mô tả mốc cho thông báo: "tạo trước 01/01/2025" \| "tạo hơn 365 ngày". |
| `thean_order_age_lock_relaxed_until()` | Khóa đang tạm nới tới bao giờ (timestamp; 0 = không nới). |
| `thean_order_is_old($order)` | Đơn cũ (tạo trước mốc khóa)? |
| `thean_order_change_source()` | Request hiện tại là tiến trình tự động (không phải người thao tác tay)? Trả nguồn ('' = người dùng). |
| `thean_order_status_is_locked($order)` | Đơn bị khóa đổi trạng thái trong request này? Đơn cũ + (tiến trình tự động, hoặc người dùng không phải admin). Các màn hình gọi hàm này để ẩn / chặn nút đổi trạng thái. |
| `thean_order_age_lock_settings_section()` | Mục "Khóa đơn cũ" trong Cài đặt ERP → Xử lý đơn (gọi từ thean_order_ops_settings_section, order-ops-step.php): tạm nới khóa có thời hạn / khóa lại ngay. |

### `woocommerce/orders/order-auto-tasks.php`

| Hàm | Mô tả |
|---|---|
| `link_guest_order_to_user($order_id)` | — |
| `update_order_meta_field($order, $key, $values_to_add = null, $values_to_remove = null)` | Helpers |
| `count_customer_orders($customer_id)` | — |
| `is_hanoi_quick($order)` | — |
| `order_auto_tasks_is_expired_final_order($order, $status_transition = null)` | — |
| `status_change_handle_processing_status($order_id, $order = null, $status_transition = null)` | Các handler cho từng trạng thái |
| `status_change_handle_on_hold_status($order_id, $order = null, $status_transition = null)` | — |
| `status_change_handle_packaging_status($order_id, $order = null, $status_transition = null)` | — |
| `status_change_handle_shipping_status($order_id, $order = null, $status_transition = null)` | — |
| `status_change_handle_received_payment_status($order_id, $order = null, $status_transition = null)` | — |

### `woocommerce/orders/order-export-review.php`

| Hàm | Mô tả |
|---|---|
| `thean_export_review_meta(WC_Order $order, string $key)` | Read primary order metadata, with the same legacy-post fallback as the export tools. |
| `thean_export_review_data(WC_Order $order)` | Review only: no recovery writes, AI calls, status changes or stock operations. |
| `thean_export_review_ajax()` | — |
| `thean_export_review_is_list()` | — |
| `thean_export_review_enqueue()` | — |
| `thean_export_review_render()` | — |

### `woocommerce/orders/order-flow-notes.php`

| Hàm | Mô tả |
|---|---|
| `thean_flow_note_kinds()` | Loại thao tác có ô ghi chú (khóa = kind của order-flow-popup.js) => nhãn ở trang cài đặt. |
| `thean_flow_note_templates_defaults()` | — |
| `thean_flow_note_parse_lines(string $raw)` | Tách mẫu: 1 mẫu / dòng, bỏ trùng + dòng trống, ≤ 20 mẫu, ≤ 200 ký tự. Hàm thuần. |
| `thean_flow_note_templates()` | — |
| `thean_flow_note_templates_settings_section()` | Mục "Mẫu ghi chú thao tác" ở Cài đặt ERP → Xử lý đơn (gọi từ thean_order_ops_settings_section). |
| `thean_delivery_issue(WC_Order $order)` | ['on' => bool, 'note' => string, 'at' => int, 'by' => string] |
| `thean_delivery_issue_steps()` | Bước board được tách sang "Sự cố giao hàng" khi có cờ (luồng giao hàng). |
| `thean_delivery_issue_clear_statuses()` | Trạng thái tự gỡ cờ sự cố (giao xong / đóng đơn). |
| `thean_delivery_issue_apply(WC_Order $order, string $op, string $note)` | Gắn / cập nhật (op flag) hoặc gỡ (op resolve) cờ sự cố. Gắn cờ bắt buộc ghi chú; chỉ đơn đang ở luồng giao hàng (Chờ giao VC / Đang giao / đã có sự cố). Trả [ok, message]. |
| `thean_delivery_issue_clear(WC_Order $order)` | — |
| `thean_order_add_flow_note(WC_Order $order, string $note, string $type)` | Thêm ghi chú đơn: $type private (nội bộ) \| customer (gửi email cho khách). Trả [ok, message]. |
| `thean_order_note_button(WC_Order $order)` | Nút 📝 Ghi chú (popup kind note). |
| `thean_delivery_issue_buttons(WC_Order $order, string $step)` | Nút Báo sự cố / Xử lý xong sự cố theo bước board. |

### `woocommerce/orders/order-hpos-compat.php`

HPOS: helper + đọc meta đơn từ HPOS. Dùng chung theanmarket + hithean (file giống hệt nhau).

| Hàm | Mô tả |
|---|---|
| `thean_hpos_on()` | — |
| `thean_is_shop_order_id(int $id)` | — |
| `thean_order_edit_url(int $id)` | — |
| `thean_order_list_url(array $args = [])` | — |
| `thean_order_meta_table()` | — |
| `thean_hpos_read_private_keys()` | Key bắt đầu "_" vẫn đọc từ HPOS (meta riêng, không phải cột lõi của WC). Key "_" khác (khóa _thean_*_lock, cột lõi) đi posts. |
| `thean_hpos_read_key(string $key, array $private_keys)` | Có đọc key này từ HPOS không? Hàm thuần. |
| `thean_hpos_order_metabox_keys()` | ID các ô Meta Box gắn lên đơn. Meta Box lưu bằng update_post_meta → chỉ vào posts; hook dưới chép sang HPOS bằng hàm của order-legacy-meta-sync.php. Thiếu bước này: filter đọc t… |
| `thean_hpos_mirror_metabox_meta(int $post_id, string $key)` | Ghi post meta một ô Meta Box của đơn → chép nguyên tập giá trị sang HPOS. |

### `woocommerce/orders/order-internal-flow.php`

| Hàm | Mô tả |
|---|---|
| `thean_order_not_internal_override(WC_Order $order)` | Đơn đã được xác nhận "Không phải nội bộ"? |
| `thean_phone_variants(string $phone)` | Các cách viết SĐT để so meta billing_phone: 0xxx, 84xxx, +84xxx. Hàm thuần; [] khi không phải SĐT VN hợp lệ. |
| `thean_customer_sms_email(string $phone)` | Email tự sinh cho khách không có email: 84<SĐT>@sms.<domain site>. '' khi SĐT không hợp lệ. |
| `thean_customer_user_eligible(WP_User $user)` | Tài khoản đem gắn cho đơn khách được không: không phải nhân viên, email không thuộc domain nội bộ. |
| `thean_order_find_customer(WC_Order $order)` | Tìm tài khoản khách khớp đơn: ['user' => WP_User, 'by' => phone\|email] \| null. |
| `thean_order_real_billing_email(WC_Order $order)` | Email thật (không nội bộ) đang ở billing, '' nếu không có. |
| `thean_not_internal_preview(WC_Order $order)` | Tình trạng đơn cho popup. |
| `thean_order_create_customer(WC_Order $order)` | Tạo tài khoản khách theo thông tin đặt / giao của đơn. WP_User \| WP_Error. |
| `thean_not_internal_apply(WC_Order $order, array $opts)` | Áp "Không phải nội bộ". $opts: remove_email (bool), new_email (string), customer (bool), note (string). Trả [ok, message]. |
| `thean_not_internal_button(WC_Order $order)` | Nút "🙅 Không phải nội bộ" (popup kind notinternal). |

### `woocommerce/orders/order-legacy-meta-sync.php`

HPOS: giữ meta do code cũ ghi thẳng post meta (update_post_meta) khớp với bảng meta HPOS. Dùng chung theanmarket + hithean (file giống hệt nhau).

| Hàm | Mô tả |
|---|---|
| `thean_legacy_meta_keys()` | Key do code cũ ghi bằng update_post_meta trên đơn (cả tên theanmarket lẫn tên "order_*" của hithean). |
| `thean_legacy_meta_hpos()` | HPOS đang là nơi lưu chính? |
| `thean_legacy_meta_busy(?bool $set = null)` | Cờ đang tự ghi (chặn vòng lặp: ghi HPOS → WC đồng bộ ngược ra posts → hook post meta). |
| `thean_legacy_meta_empty($value)` | Giá trị meta coi là trống ('' / null / [] / chuỗi toàn khoảng trắng). Hàm thuần. |
| `thean_legacy_meta_save(WC_Order $order)` | Lưu meta HPOS của đơn (không gọi save() — không đổi trạng thái / không kích hook lưu đơn). |
| `thean_legacy_post_meta_raw(int $post_id, string $key)` | Mọi giá trị của key ở bảng posts, đọc thẳng DB (bỏ qua filter get_post_metadata của order-hpos-compat.php — filter đó trả bản HPOS). Field chọn nhiều của Meta Box (order_export_… |
| `thean_legacy_order_meta_values(WC_Order $order, string $key)` | Giá trị HPOS hiện có của key (danh sách). |
| `thean_legacy_order_meta_assign(WC_Order $order, string $key, array $values)` | Đặt toàn bộ giá trị key trên HPOS = $values (giữ nhiều dòng). Trả true nếu có đổi (chưa lưu). |
| `thean_legacy_meta_mirror(int $post_id, string $key, $value, bool $delete)` | 1. Post meta của đơn vừa đổi → chép nguyên tập giá trị của key sang HPOS. |
| `thean_order_meta_write(int $order_id, string $key, $value, string $op)` | GHI META ĐƠN — dùng thay update_post_meta / add_post_meta / delete_post_meta trên đơn hàng. Ghi thẳng HPOS ($order->update_meta_data + save_meta_data — không gọi save() nên khôn… |
| `thean_order_meta_set(int $order_id, string $key, $value)` | — |
| `thean_order_meta_add(int $order_id, string $key, $value)` | Thêm 1 dòng (field nhiều giá trị, VD order_export_by của hithean). |
| `thean_order_meta_delete(int $order_id, string $key)` | — |
| `thean_legacy_meta_reconcile(WC_Order $order)` | 2. HPOS trống nhưng posts có → kéo sang HPOS (cập nhật luôn $order). Trả true nếu có đổi. |
| `thean_legacy_meta_put(WC_Order $order, string $key, $value)` | Ghi meta vào cả posts lẫn HPOS (và $order đang cầm). |
| `thean_legacy_hpos_edit_order_id()` | ID đơn đang mở ở màn sửa đơn HPOS (admin.php?page=wc-orders&action=edit&id=…); 0 nếu không phải. |
| `thean_export_confirmed_settle(int $order_id)` | Danh sách xuất kho (đọc HPOS): đơn đã xác nhận nhưng export_confirmed_by chỉ nằm ở posts → kéo sang HPOS và trả true để bỏ khỏi "Ảnh lấy hàng chờ kiểm tra" (trước đây đơn đã xác… |
| `thean_legacy_meta_backfill_ids(int $limit = 100)` | — |
| `thean_legacy_meta_backfill_run()` | — |
| `thean_export_proof_image_file(string $basename, int $order_id)` | Tên file ảnh xuất kho đính kèm đơn (upload từng đơn / lô nhiều đơn AI tách). Hàm thuần. |
| `thean_export_confirm_name_from_note(string $note)` | Tên người xác nhận trong ghi chú "✅ Đã xác nhận (ảnh) xuất kho bởi <tên>"; '' nếu không phải. Hàm thuần. |
| `thean_export_proof_recover(WC_Order $order)` | 3. Đơn đã mất ảnh / người xác nhận ở cả 2 bên → khôi phục từ file đính kèm + ghi chú. Mỗi đơn thử tối đa 12 giờ/lần (transient) vì gọi từ bộ kiểm tra chứng từ. Trả true nếu khôi… |
| `thean_export_ai_result_from_note(string $note)` | Ghi chú "🤖 AI review ảnh xuất kho" (thean_/hithean_export_ai_order_note) → mảng kết quả như warehouse_export_ai_check; null nếu không phải ghi chú AI. Hàm thuần. |
| `thean_export_ai_recover(WC_Order $order)` | Kết quả AI check bị mất (cả 2 bảng) mà ghi chú AI còn → dựng lại warehouse_export_ai_check từ ghi chú AI mới nhất. Bỏ qua nếu sau ghi chú AI có lượt upload ảnh mới (kết quả cũ k… |

### `woocommerce/orders/order-list-lazy.php`

| Hàm | Mô tả |
|---|---|
| `thean_wcl_is_list_request()` | Đang ở màn danh sách đơn (không phải màn sửa / tạo đơn)? Không dùng get_current_screen (chưa có lúc này). |
| `thean_wcl_requested()` | Người xem đã bấm "Tải danh sách Woo" (?thean_wc_list=1)? Mọi trường hợp khác bảng Woo đều ẩn. |
| `thean_wcl_off()` | Bảng Woo đang tắt cho request này? (chỉ với người dùng có ô tìm đơn thay thế) |
| `thean_wcl_state()` | Trạng thái cho JS (window.theanOusCfg.wcList): JS tự gắn ?thean_wc_list=1 vào URL hiện tại để bật. |
| `thean_order_creator_url()` | URL trang Tạo đơn (/tao-don/) nếu module tạo đơn hộ khách có trong theme: đã nạp (hithean nạp mọi request → có ORDER_CREATOR_ROUTE) hoặc được khai trong tpc_loader_modules() (th… |

### `woocommerce/orders/order-metabox.php`

| Hàm | Mô tả |
|---|---|
| `thean_register_order_meta_boxes($meta_boxes)` | — |

### `woocommerce/orders/order-navigation.php`

| Hàm | Mô tả |
|---|---|
| `thean_order_portal_is_route()` | Order navigation only; loaded for order admin requests, the standalone workspace and (lazily, at admin_menu) admin pages. |
| `thean_order_portal_can()` | — |
| `thean_order_portal_url(array $args = [])` | — |
| `thean_order_portal_redirect_url()` | Only redirect navigation, never a mutation, edit screen, AJAX or explicit Woo fallback. |
| `thean_order_portal_is_order_screen()` | Woo order screens (list, edit, new, Phone Orders) — their own menu slugs must stay registered. |
| `thean_order_portal_rewrite_menu()` | Menu destinations → portal, so routine navigation skips the wp-admin redirect hop. Hooked from the theme loader at admin_init (PHP_INT_MAX), NOT admin_menu: wp-admin/menu.php ch… |

### `woocommerce/orders/order-number.php`

| Hàm | Mô tả |
|---|---|
| `change_order_number($order_id)` | — |
| `thean_change_woocommerce_order_number($order_id)` | — |

### `woocommerce/orders/order-operations-domain.php`

Canonical bridge for the warehouse workflow's legacy post-meta confirmation.

| Hàm | Mô tả |
|---|---|
| `child_theme_order_export_is_confirmed(WC_Order $order)` | — |

### `woocommerce/orders/order-ops-step.php`

| Hàm | Mô tả |
|---|---|
| `thean_ops_step_tabs()` | Tab board "Cần xử lý": khóa => nhãn, theo thứ tự luồng. |
| `thean_ops_step_flow()` | Thứ tự vận hành của board — nguồn duy nhất cho tab (đánh số + mũi tên), select mobile và sơ đồ ở 📖 Quy trình. 'main' = luồng chính, đơn đi lần lượt từ trái sang phải; 'side' = h… |
| `thean_ops_step_position(string $tab)` | Vị trí tab trong luồng: ['lane' => main\|side, 'no' => số thứ tự 1.. trong lane]. |
| `thean_ops_step_tab(string $step)` | Bước lưu sẵn vẫn riêng biệt để giữ đúng thao tác; chỉ gộp ở lớp hiển thị board. |
| `thean_ops_step_groups(string $tab)` | Hai nhóm trong cùng một tab; khóa là bước gốc, dùng chung cho SQL, badge và UI. |
| `thean_ops_step_labels()` | — |
| `thean_ops_board_url(string $tab = '')` | Link mở thẳng board "Đơn cần xử lý" ở 1 tab (danh sách đơn admin + ?thean_board=<tab>, xử lý ở assets/order-universal-search.js). Tab không hợp lệ / trống → board mở tab có đơn… |
| `thean_ops_excluded_statuses()` | Trạng thái không thuộc luồng vận hành: đã đóng / xong, và "Chưa ghi nhận" / "Đã ghi nhận" (đơn kế toán / nội bộ). Đơn đóng còn hoàn hàng dở vẫn vào tab Hoàn hàng. |
| `thean_internal_email_domains()` | Domain email nội bộ (Cài đặt ERP → Xử lý đơn, option `thean_internal_email_domains`). Trống thì dùng danh sách `internal_email_domains` của plugin eim nếu có. Chuẩn hóa: chữ thư… |
| `thean_internal_domains_enabled()` | Lọc đơn nội bộ theo domain email đang bật? Mặc định BẬT. Option `thean_internal_domains_off` = '1' để tắt (key cũ `thean_internal_domains_enabled` — mặc định tắt — bỏ từ 31/10/2… |
| `thean_parse_email_domains(string $raw)` | Tách danh sách domain (phẩy / chấm phẩy / khoảng trắng / xuống dòng). Hàm thuần. |
| `thean_email_is_internal(string $email, ?array $domains = null)` | Email thuộc domain nội bộ? Khớp ĐÚNG domain sau "@": khai "thean.com" thì a@thean.com là nội bộ, còn a@hn.thean.com (subdomain) vẫn là đơn thường — muốn loại subdomain thì khai… |
| `thean_order_is_internal(WC_Order $order)` | Đơn nội bộ: cờ `_eim_internal` hoặc email người mua thuộc domain nội bộ — trừ đơn đã xác nhận "Không phải nội bộ". |
| `thean_ops_internal_sql(array $t, bool $exclude = true)` | Điều kiện SQL loại đơn nội bộ (alias đơn = o); $exclude = false → điều kiện CHỌN đơn nội bộ (tab Đơn nội bộ). |
| `thean_can_skip_export_proof()` | Chỉ admin (manage_options) được bỏ qua lỗi thiếu chứng từ xuất kho. |
| `thean_can_change_any_status()` | Được đổi trạng thái tùy ý (từng đơn + hàng loạt) ở ô tìm đơn / board: người được chỉ định ở Cài đặt ERP → Xử lý đơn (option `thean_status_change_users`, ID user). Danh sách trốn… |
| `thean_ops_proof_statuses()` | Trạng thái đã giao / xong — thiếu chứng từ xuất kho thì chỉ ở tab "Thiếu / chưa xác nhận ảnh xuất kho". |
| `thean_ops_shipping_statuses()` | Trạng thái đang giao — thiếu chứng từ thì ở cả tab "Đang giao" (theo dõi) lẫn "Thiếu / chưa xác nhận ảnh xuất kho", không về "Lấy hàng". |
| `thean_ops_missing_proof(WC_Order $order)` | Chứng từ xuất kho còn thiếu của đơn đang giao / đã giao / xong (rỗng = đủ, hoặc đã đánh dấu bỏ qua, hoặc đơn ngoài diện xét). |
| `thean_ops_step_from_flow(array $flow, string $status = '')` | Bước tiếp theo (thean_ow_flow) → tab board; '' = không cần xử lý. Hàm thuần. |
| `thean_ops_step_excluded(WC_Order $order, bool $gated = true)` | Kiểm tra phạm vi riêng để tái dùng flow đã tính mà vẫn giữ bộ lọc hiển thị. Đơn nội bộ KHÔNG bị loại — đi luồng ngoại lệ "Đơn nội bộ" (cờ 'internal' của thean_ops_step_compute). |
| `thean_ops_step_compute(WC_Order $order, bool $gated = true)` | Tính bước + gợi ý cho 1 đơn (không ghi). ['step' => tab\|'', 'hint' => string, 'proof' => thiếu chứng từ XK?, 'internal' => đơn nội bộ? (board xếp vào tab Đơn nội bộ thay cho tab… |
| `thean_ops_step_sync(WC_Order $order)` | Tính và lưu bước của đơn. Trả kết quả thean_ops_step_compute(). |
| `thean_ops_step_sync_ids(array $ids, ?array &$loaded_orders = null)` | Tính + lưu bước cho nhiều đơn, đọc gộp 1 lần wc_get_orders. Trả [id => kết quả]. |
| `thean_ops_board_invalidate()` | Token riêng cho cache ID/số đếm board; không cache HTML hoặc quyền thao tác. |
| `thean_ops_step_request_backfill()` | Chỉ xếp lịch; không quét hay đọc đơn trên request mở board. |
| `thean_ops_step_queue(int $order_id)` | Gom ID đơn cần tính lại, xử lý 1 lần ở shutdown. |
| `thean_ops_order_cache_bust(array $ids)` | Bỏ đơn khỏi cache đối tượng của WC (OrderCache — nhóm `orders`, lưu bền ở Redis, gồm cả meta) để lần đọc kế tiếp đọc lại DB. Cần vì nhiều luồng cũ ghi update_post_meta (xác nhận… |
| `thean_ops_step_flush()` | — |
| `thean_flow_popup_enqueue(bool $reload)` | Nạp popup thao tác luồng đơn (assets/order-flow-popup.js: Tạm giữ / Tiếp tục / Hủy / Đặt trước…). Trang sửa đơn: reload = true. |
| `thean_ops_step_watched_meta()` | Meta ảnh hưởng bước vận hành (cả tên theanmarket lẫn tên "order_*" của hithean). |
| `thean_ops_age_cutoff_ts()` | Mốc bỏ đơn cũ của luồng vận hành = mốc khóa đơn cũ (order-age-lock.php: ngày mốc ở Cài đặt ERP, mặc định 365 ngày). |
| `thean_ops_age_sql(array $t)` | Điều kiện SQL: đơn tạo từ mốc khóa đơn cũ trở đi (alias đơn = o). |
| `thean_ops_step_tables()` | Bảng / cột đơn + meta theo kiểu lưu trữ (HPOS hoặc legacy). |
| `thean_ops_step_open_sql(array $t)` | FROM … WHERE chung: đơn mở + đơn đóng còn hoàn hàng + đơn đã giao / xong trong THEAN_OPS_PROOF_DAYS ngày (soát chứng từ XK); bỏ đơn cũ (trước mốc khóa đơn cũ). |
| `thean_ops_step_unsynced_ids(int $limit)` | Đơn mở chưa được tính bước theo logic hiện tại (THEAN_OPS_STEP_VERSION), mới nhất trước. @return int[] |
| `thean_ops_step_sweep_ids(int $limit = THEAN_OPS_SWEEP)` | Đơn mở cần (tính lại) bước — cho cron. Đơn chưa tính trước, rồi xoay vòng theo con trỏ ID giảm dần. |
| `thean_order_ops_settings_section()` | Các mục của luồng vận hành trong Cài đặt ERP → tab Xử lý đơn (gọi từ core/ai-settings.php của mỗi theme): "Đơn nội bộ" + "Quyền đổi trạng thái tùy ý" + "Mẫu ghi chú thao tác" +… |
| `thean_status_change_settings_section()` | Mục "Quyền đổi trạng thái tùy ý": danh sách user (email / tên đăng nhập / ID) → option thean_status_change_users. |
| `thean_internal_domains_settings_section()` | Mục "Đơn nội bộ" (domain email nội bộ). |

### `woocommerce/orders/order-portal.php`

| Hàm | Mô tả |
|---|---|
| `thean_order_portal_legacy_url()` | Danh sách đơn WooCommerce gốc (đường lui, không bị chuyển hướng) — nút "Phiên bản cũ" ở hàng tab. |
| `thean_order_portal_render_create_tab()` | — |
| `thean_order_portal_sepay_enabled()` | Tab 🏦 Sepay: chỉ ở /quan-ly-don, cần plugin eim (widget hub) + quyền xác nhận thanh toán. |
| `thean_order_portal_render_sepay_tab()` | Hàng đợi Sepay của hub (widget eim, tab sepay_all = đủ mọi giao dịch, không lọc theo site). URL iframe mang token dùng 1 lần nên không in sẵn: order-workspace-tabs.js gọi AJAX c… |
| `thean_order_portal_render_returns_panel()` | Board tab Hoàn hàng → khung [order_return_management] (iframe công cụ ?thean_order_tool=returns) ngay dưới danh sách đơn hoàn. JS chuyển khung xuống sau #thean-ous-board, hiện k… |
| `thean_order_portal_board_initial()` | Trang đầu board in sẵn vào trang (bỏ 1 lượt admin-ajax). Tab = ?thean_board, không có thì tab đã xem lần trước (cookie thean_ous_tab do JS ghi cùng localStorage). Mở thẳng tab T… |

### `woocommerce/orders/order-product-tools.php`

| Hàm | Mô tả |
|---|---|
| `thean_order_product_tools()` | Fixed registry: request values never become shortcode names or include paths. |
| `thean_order_export_tools()` | Export lists come first; uploads are a separate, final step. |
| `thean_order_export_has_shopee()` | Module Xuất kho Shopee có ở site này không (theanmarket có, hithean không). Có → khu Xuất kho thêm bộ chọn loại đơn Website / Shopee, truyền vào iframe bằng ?thean_export_kind=a… |
| `thean_order_return_tools()` | Board hoàn hàng (shortcode ở shipping/) — /quan-ly-don nhúng dưới tab Hoàn hàng của board. |
| `thean_order_workspace_tools()` | — |
| `thean_order_product_tool_url(string $tool)` | — |
| `thean_order_product_tool_response()` | Isolated document keeps the existing shortcodes' CSS, IDs and inline JS out of the order board. |
| `thean_order_render_product_workspace()` | — |
| `thean_order_render_export_workspace()` | — |
| `thean_order_render_tool_workspace(string $scope)` | — |

### `woocommerce/orders/order-quick-modals.php`

Popup thao tác nhanh ở danh sách đơn admin (cột Thao tác, ô tìm đơn, board, Xem nhanh) — các modal riêng: QR thanh toán · Xác nhận thanh toán · Khách đã xác nhận COD · Ảnh xuất…

| Hàm | Mô tả |
|---|---|
| `thean_qm_site()` | Khác biệt giữa 2 site. |
| `thean_qm_bank_accounts()` | TK nhận tiền cho ô chọn "Tài khoản nhận". Thứ tự nguồn: hithean_payment_bank_accounts() / thean_payment_bank_accounts() (nguồn chung của mỗi site, đã gộp eim + BACS) → eim_payme… |
| `thean_qm_open(string $key, string $title, bool $multipart = false)` | Mở khung modal: wrapper + backdrop + form + nút đóng + tiêu đề + dòng tóm tắt. |
| `thean_qm_close(string $submit, string $extra = '')` | Đóng khung modal: thanh nút (Hủy + nút phụ tùy chọn + nút chính) + vùng thông báo. $extra là HTML đã escape. |
| `thean_qm_render()` | — |

### `woocommerce/orders/order-search.php`

| Hàm | Mô tả |
|---|---|
| `custom_order_search($search_fields)` | — |

### `woocommerce/orders/order-shipper.php`

| Hàm | Mô tả |
|---|---|
| `hithean_shipper_labels(bool $short = false)` | Đơn vị vận chuyển của đơn — NGUỒN DUY NHẤT cho nhãn mã shipper. |
| `hithean_order_shipper_codes(WC_Order $order)` | Mã shipper đã chọn (chữ thường, không trùng). Đơn cũ lưu chuỗi đơn → bọc thành mảng. |
| `hithean_order_shipper_names(WC_Order $order, bool $short = false)` | Nhãn shipper nối bằng ", "; rỗng nếu chưa chọn. Mã lạ giữ nguyên. |

### `woocommerce/orders/order-status.php`

| Hàm | Mô tả |
|---|---|
| `cws_get_custom_statuses()` | — |
| `register_custom_order_statuses()` | Register Custom Order Statuses |
| `add_custom_wc_order_statuses($order_statuses)` | Add Custom Order Statuses to WooCommerce List |
| `custom_order_status_styles()` | Add Custom Colors & Icons for Statuses |

### `woocommerce/orders/order-tracking.php`

### `woocommerce/orders/order-universal-search.php`

| Hàm | Mô tả |
|---|---|
| `thean_ous_tracking_meta_keys()` | Meta vận đơn / hoàn hàng được tìm theo chuỗi con. Hithean: field Meta Box có tiền tố "order_" (order-metabox.php, order_shipper là select nhiều giá trị); giữ key cũ để tìm đơn cũ. |
| `thean_ous_meta(WC_Order $order, array $keys)` | Đọc meta vận hành: key "order_*" (hiện tại) trước, rồi key cũ. Mảng → nối bằng dấu phẩy. |
| `thean_ous_shipper(WC_Order $order)` | Shipper (mã self/ghtk/…) → nhãn ngắn. Nhãn: hithean_shipper_labels() (order-shipper.php); map dưới là dự phòng khi port. |
| `thean_ous_ship_code(WC_Order $order)` | — |
| `thean_ous_commands()` | Lệnh lọc: khóa chuẩn => [nhãn, ví dụ, mô tả]. |
| `thean_ous_command_aliases()` | — |
| `thean_ous_user_can()` | — |
| `thean_ous_hpos_enabled()` | — |
| `thean_ous_tokens(string $term)` | Tách từ khóa thành các token, tối đa 6 token. |
| `thean_ous_norm(string $text)` | Chuẩn hóa không dấu để so trạng thái. |
| `thean_ous_statuses()` | [['slug' => 'processing', 'name' => 'Đang xử lý', 'norm' => 'dang-xu-ly'], ...] |
| `thean_ous_status_groups()` | Nhóm trạng thái cho bộ lọc (nút "Trạng thái" ở thanh tìm + lệnh /nhom). Chỉ giữ slug có thật ở site; trạng thái chưa thuộc nhóm nào gom vào "Khác". Filter thean_ous_status_group… |
| `thean_ous_resolve_status_group(string $arg)` | Tham số /nhom → slug của nhóm (khớp key hoặc tên nhóm không dấu; khớp đúng trước, sau đó chứa chuỗi). |
| `thean_ous_resolve_status(string $arg)` | Tham số /tt → danh sách slug khớp (khớp đúng slug/tên ưu tiên, sau đó chứa chuỗi). |
| `thean_ous_parse_query(string $query)` | Tách truy vấn thành bộ lọc. Lệnh không biết → giữ nguyên trong text tự do. /tt và /id chỉ lấy 1 từ làm tham số, phần sau trả về text tự do. |
| `thean_ous_validate(array $f)` | Bộ lọc có đủ điều kiện để chạy không (tránh LIKE 1–2 ký tự quét cả bảng). |
| `thean_ous_order_ref_digits(string $term)` | Chuỗi số có thể là ID / mã đơn trong từ người dùng gõ — cùng cách bóc của eim (eim_order_ref_variants): bỏ "#", "*", khoảng trắng; bỏ 2 ký tự đầu P0/P1 (nội dung CK "P1<mã đơn>"… |
| `thean_ous_candidate_ids(string $term)` | Các ID có thể ứng với từ người dùng gõ: ID trực tiếp, hoặc mã đơn hiển thị (ID + hậu tố 1–2 chữ số, xem order-number.php) → bỏ 1–2 số cuối để tìm lại. Site có hàm đánh số: chỉ n… |
| `thean_ous_phone_needle(string $term, int $min = 6)` | SĐT dạng chuẩn hóa để LIKE: bỏ ký tự không phải số, bỏ 84/0 đầu. |
| `thean_ous_phone_sql(string $column)` | SQL biểu thức chuẩn hóa SĐT (bỏ khoảng trắng, chấm, gạch, ngoặc, +). |
| `thean_ous_tokens_like(string $column, array $tokens)` | "col LIKE %t1% AND col LIKE %t2% ..." (đã prepare — không bọc thêm prepare()). |
| `thean_ous_like(string $term)` | — |
| `thean_ous_range(string $range)` | Khoảng theo ngày tạo đơn (UTC), dùng chung UI, SQL và cache. |
| `thean_ous_range_label(string $range, string $tab = '')` | — |
| `thean_ous_range_sql(string $range, string $alias = 'o', ?string $step_column = null, ?int $now = null)` | $alias / $step_column chỉ do code nội bộ truyền, không nhận tên cột từ request. |
| `thean_ous_source_range_sql(string $column, string $range, array $only_ids = [])` | Áp dụng giới hạn ngay ở từng nguồn, trước LIMIT ứng viên. |
| `thean_ous_ids_product(array $tokens, string $range = 'all', array $only_ids = [])` | — |
| `thean_ous_ids_customer(array $tokens, string $like, string $range = 'all', array $only_ids = [])` | — |
| `thean_ous_ids_phone(string $needle, string $range = 'all', array $only_ids = [])` | — |
| `thean_ous_ids_email(string $like, string $range = 'all', array $only_ids = [])` | — |
| `thean_ous_ids_ship(string $like, string $range = 'all', array $only_ids = [])` | — |
| `thean_ous_int_ids(array $ids)` | — |
| `thean_ous_find_order_ids(array $f, int $cap = THEAN_OUS_CAP, string $range = 'auto', array $only_ids = [])` | Tìm ID đơn khớp bộ lọc: mỗi bộ lọc cho 1 tập ứng viên, giao các tập lại (AND), rồi lọc lần cuối theo loại đơn / trạng thái, mới nhất trước. Text tự do = hợp mọi nguồn. |
| `thean_ous_orders_version()` | Version cache "orders" của WC — đổi mỗi khi đơn được lưu / đổi trạng thái. |
| `thean_ous_ids_cache_key(array $f, string $range = 'auto')` | Khóa transient cho danh sách ID khớp (Redis nếu có object cache). Phân trang chỉ cắt mảng này. |
| `thean_ous_rate_blocked(int $user_id, ?int $now = null)` | Còn bị khóa tìm kiếm bao nhiêu giây (0 = không). |
| `thean_ous_rate_hit(int $user_id, ?int $now = null)` | Ghi 1 lượt tìm thật (truy vấn DB). Quá THEAN_OUS_RL_MAX lượt trong THEAN_OUS_RL_WINDOW giây thì khóa THEAN_OUS_RL_BLOCK giây. Trả số giây bị khóa (0 = được tìm). |
| `thean_ous_highlight_tokens(array $f)` | Token để tô vàng trong kết quả. |
| `thean_ous_filter_summary(array $f)` | Mô tả ngắn các bộ lọc đang áp dụng. |
| `thean_ous_highlight(string $text, array $tokens)` | Escape + bọc <mark> các token khớp. |
| `thean_ous_customer_name(WC_Order $order)` | — |
| `thean_ous_address_line(WC_Order $order)` | — |
| `thean_ous_status_badge(WC_Order $order)` | — |
| `thean_ous_short_date(WC_Order $order)` | — |
| `thean_ous_creator_url(int $order_id)` | — |
| `thean_ous_order_actions(WC_Order $order)` | Các action của đơn giống cột "Thao tác" ở danh sách: filter woocommerce_admin_order_actions (add_custom_actions ở orders-admin.php, print_pxk...). |
| `thean_ous_action_meta(string $slug)` | Nhóm + nhãn ngắn cho từng action (dùng chung với cột Thao tác). Nhóm quyết định màu nút. |
| `thean_ous_action_groups()` | — |
| `thean_ous_button(string $group, string $label, string $url, string $class = '', bool $blank = false, strin…)` | — |
| `thean_ous_flow_buttons(WC_Order $order)` | Nút Tạm giữ / Tiếp tục / Hủy (popup lý do — assets/order-flow-popup.js → task order_flow của order-workspace.php). |
| `thean_ous_next_actions(WC_Order $order, string $step, bool $proof = false)` | Cột "Thao tác tiếp theo": nút đúng bước tiếp theo của đơn (bước tính bằng thean_ops_step_compute → thean_ow_flow, cùng logic bảng điều phối). Lấy từ các action sẵn có (woocommer… |
| `thean_ous_invoice_url(WC_Order $order)` | Link xem hóa đơn của đơn — dùng lại bản xem trước hóa đơn của trang Tạo đơn (AJAX order_creator_invoice_preview, WC_Email_Customer_Invoice). '' khi theme không có module tạo đơn… |
| `thean_ous_render_actions(WC_Order $order, bool $compact)` | — |
| `thean_ous_load_orders(array $ids)` | Đọc gộp các đơn của 1 trang bằng 1 lần wc_get_orders (HPOS: read_multiple; legacy: WP_Query + prime cache post/meta/items). Giữ đúng thứ tự $ids; đơn nào không trả về thì wc_get… |
| `thean_ous_step_badge(WC_Order $order, array $flow)` | Nhãn "📍 bước của flow" ở dòng kết quả: bước tính trực tiếp (cùng logic board), lý do khi đơn không vào board, và cảnh báo khi meta bước board đang lưu khác (dữ liệu cũ / cache)… |
| `thean_ous_ship_code_short(string $code)` | Mã vận đơn GHTK dạng "S12734516.BO.SGP120-V44.1363613511" → chỉ lấy phần cuối "1363613511" (mã tra cứu ngắn). Mã khác định dạng giữ nguyên. |
| `thean_ous_render_rows(array $ids, array $tokens, array $hints = [], ?array $orders = null, array $flows = [])` | — |
| `thean_ous_render_address_block(WC_Order $order, string $type)` | — |
| `thean_ous_export_images(WC_Order $order)` | URL ảnh xuất kho của đơn (meta warehouse_export_images, mỗi dòng 1 URL). Đọc meta đơn (HPOS) trước, trống thì đọc postmeta — đơn cũ / luồng ghi thẳng update_post_meta chưa sync… |
| `thean_ous_render_preview(WC_Order $order)` | — |
| `thean_ous_preview_html(WC_Order $order)` | HTML xem nhanh, cache trong object cache (Redis) THEAN_OUS_PREVIEW_TTL giây. Khóa gồm thời điểm sửa đơn + version cache orders (đổi khi lưu đơn) + quyền / thiết bị (nút thao tác… |
| `thean_ous_board_sql(string $range = 'auto')` | FROM … WHERE board: đơn có bước (≠ none) thuộc luồng vận hành; đơn đóng chỉ còn tab Hoàn hàng; bỏ đơn gộp Shopee. Đơn nội bộ CÓ trong phạm vi này — tách / chọn ở thean_ous_board… |
| `thean_ous_board_tab_sql(string $tab, string $range = 'auto')` | Điều kiện SQL chọn đơn của 1 tab: tab "Thiếu / chưa xác nhận ảnh xuất kho" theo cờ THEAN_OPS_PROOF_META (gồm cả đơn đang giao — bước chính vẫn là Đang giao), các tab khác theo b… |
| `thean_ous_proof_since_sql(array $t)` | Tab "Thiếu / chưa xác nhận ảnh xuất kho" chỉ xét đơn tạo trong THEAN_OPS_PROOF_DAYS ngày (thean_ops_missing_proof). Cờ lưu sẵn không tự hết hạn khi đơn quá 60 ngày → lọc thẳng b… |
| `thean_ous_board_in_tab(array $res, string $tab)` | Đơn (kết quả thean_ops_step_compute) có thuộc tab không — cùng quy tắc thean_ous_board_tab_sql. |
| `thean_ous_board_cache_key(string $part, string $range = 'auto')` | Cache dữ liệu dùng chung, khóa gồm cấu hình lọc và revision; HTML luôn render theo user hiện tại. Làm tròn giây trong mốc tuổi chỉ cho khóa cache: SQL vẫn dùng mốc chính xác, TT… |
| `thean_ous_timing_header(array $timings)` | Server-Timing hiển thị trong DevTools, không chứa dữ liệu khách hàng. |
| `thean_ous_board_stats(string $range = 'auto')` | Số đơn mỗi tab (GROUP BY theo bước + đếm riêng tab "Thiếu / chưa xác nhận ảnh xuất kho" theo cờ). |
| `thean_ous_board_counts(string $range = 'auto')` | — |
| `thean_ous_board_tabs(string $range = 'auto')` | Labels + group totals share the same aggregate query/cache as tab counts. |
| `thean_ous_board_page_ids(string $tab, int $offset, int $limit, string $range = 'auto', int $before_id = 0)` | ID đơn trong tab từ vị trí $offset, tối đa $limit đơn, mới nhất trước. |
| `thean_ous_ajax_board()` | — |
| `thean_ous_board_payload(array $req)` | Dữ liệu board (dùng chung AJAX thean_order_usearch_board + trang /quan-ly-don in sẵn trang đầu). $req = tham số đã unslash: range, tab, refresh, all, before_id, offset, limit. T… |
| `thean_ous_board_memberships(array $ids, string $range)` | Membership của đúng các đơn vừa đổi, kể cả tab chứng từ chồng lên bước chính. |
| `thean_ous_ajax_rows()` | — |
| `thean_ous_bulk_status(WC_Order $order, string $status, string $note)` | Đổi trạng thái 1 đơn (thao tác hàng loạt). Trả [thành công?, thông báo]. |
| `thean_ous_ajax_bulk()` | — |
| `thean_ous_per_page($raw)` | Số đơn / trang kết quả tìm (ô chọn ở phân trang) — chỉ các mức cho phép, khác thì mặc định. |
| `thean_ous_ajax_search()` | — |
| `thean_ous_ajax_preview()` | — |
| `thean_ous_ajax_flush()` | — |
| `thean_ous_ajax_alerts()` | — |
| `thean_ous_is_list_ui()` | Đang ở màn danh sách đơn (không phải màn sửa/tạo đơn của HPOS) và có quyền. |
| `thean_ous_alerts_off()` | — |
| `thean_ous_chrome_off()` | — |
| `thean_ous_build_ver()` | Phiên bản giao diện bảng = mtime JS + PHP render dòng. Tab admin mở từ trước khi deploy vẫn chạy JS cũ (tiêu đề/colgroup cũ) nhưng AJAX trả dòng theo PHP mới → lệch cột. JS so '… |
| `thean_ous_ui_config()` | Config cho assets/order-universal-search.js (window.theanOusCfg). |
| `thean_ous_render_workflow_guide()` | Hướng dẫn tại chỗ, chỉ đọc; nhãn lấy cùng nguồn với các tab board. |
| `thean_ous_enqueue_assets()` | — |
| `thean_ous_logo_html()` | Logo site ở đầu thanh tabs — như thanh ERP (/erp): logo theme → ảnh đầu email WooCommerce → biểu tượng site → tên site. Theanmarket dùng luôn thean_erp_get_nav_logo_html() nếu m… |
| `thean_ous_render_ui()` | — |

### `woocommerce/orders/order-vat-request.php`

| Hàm | Mô tả |
|---|---|
| `thean_vat_request_enabled()` | Có thể nhập VAT (plugin eim đang chạy + quyền)? |
| `thean_vat_request_locked(WC_Order $order)` | Đơn đã vào lô / đã xuất HĐ → khóa sửa thông tin người mua (như khung HĐ VAT của eim). |
| `thean_vat_request_proof_ids(WC_Order $order)` | — |
| `thean_vat_request_data(WC_Order $order)` | Dữ liệu cho popup. |
| `thean_vat_request_ajax_order()` | Kiểm tra AJAX chung: nonce + quyền + đơn. |
| `thean_vat_request_parse_ai(string $text)` | Bóc JSON từ trả lời của AI (có thể bọc ```json). |
| `thean_vat_request_button(WC_Order $order, string $class = 'thean-ous-btn thean-ous-btn--view')` | Nút mở popup (dùng ở ô tìm đơn / board / bảng điều phối). |
| `thean_vat_request_enqueue(bool $reload)` | Nạp popup (assets/order-vat-request.js). Trang sửa đơn: reload = true. |

### `woocommerce/orders/order-workspace.php`

| Hàm | Mô tả |
|---|---|
| `thean_ow_key(string $key)` | Tên field trong bảng điều phối → meta key thật của hithean (order-metabox.php, cùng bảng với filter eim_export_meta_map ở integrations/eim-order-shipper.php). Key không có trong… |
| `thean_ow_meta(WC_Order $order, string $key)` | Meta đơn theo tên field bảng điều phối. |
| `thean_ow_meta_list(WC_Order $order, string $key)` | Field select của metabox lưu nhiều dòng meta cùng key (Meta Box multiple) → gom thành mảng. |
| `thean_ow_metabox_fields()` | Định nghĩa field metabox "Thông tin bổ sung" (order-metabox.php), key theo id. |
| `thean_ow_field_options(string $field_id)` | Options lấy thẳng từ định nghĩa metabox (order-metabox.php) để luôn đồng bộ. |
| `thean_ow_set_list(WC_Order $order, string $key, array $values)` | Ghi field nhiều giá trị theo dạng Meta Box (mỗi giá trị 1 dòng meta) — gộp luôn dữ liệu cũ dạng 1 dòng mảng (update_order_meta_field ở order-auto-tasks.php). Chưa save(). |
| `thean_ow_update_list(WC_Order $order, string $key, array $add, array $remove)` | Thêm / gỡ giá trị của field nhiều giá trị rồi save(). |
| `thean_ow_metabox_values(WC_Order $order)` | Giá trị hiện tại của các field metabox để JS gán lại vào form sửa đơn (không reload). Bỏ qua field ảnh/file/clone: không sửa từ bảng điều phối, gán lại dễ sai cấu trúc. |
| `thean_ow_metabox_id()` | id DOM của metabox "Thông tin bổ sung" (Meta Box không khai id → sanitize_title(title)). |
| `thean_ow_editable_fields()` | Field cho phép sửa nhanh từ bảng điều phối: key => multiple? |
| `thean_ow_delivery_statuses()` | Trạng thái giao hàng được chuyển nhanh (cùng bộ trạng thái của order-statuses.php). |
| `thean_ow_delivery_status_notes()` | Mô tả ngắn từng trạng thái trong modal "Chuyển trạng thái đơn". |
| `thean_ow_handling_groups()` | Nhóm nhãn "Trạng thái xử lý nội bộ" để chọn bằng chip; nhãn không thuộc nhóm nào vào "Khác / nhãn cũ". |
| `thean_ow_field_hints()` | Ghi chú "hệ thống sẽ tự cập nhật" dưới các trường giao hàng. Nguồn logic: order-auto-tasks.php (đổi trạng thái), warehouse-export-workflow.php (mã vận đơn AI). Đổi logic ở đó th… |
| `thean_ow_payment_state(WC_Order $order)` | — |
| `thean_ow_cod_confirm_state(WC_Order $order, array $handling)` | Đơn COD cần khách xác nhận khi: - TẠM GIỮ: chờ khách xác nhận qua kênh chat (channel = chat). - ĐANG XỬ LÝ và (khách tự đặt trên web hoặc được gắn nhãn thủ công "Cần gọi xác nhậ… |
| `thean_ow_to_bacs_statuses()` | Trạng thái cho phép đổi đơn COD sang chờ CK (chưa xuất kho / giao). |
| `thean_ow_cod_confirm_results()` | Kết quả xác nhận đơn COD (gọi / chat): [thêm vào handling_status, gỡ khỏi handling_status, ghi chú]. |
| `thean_ow_export_images(WC_Order $order)` | — |
| `thean_ow_ai_state(WC_Order $order)` | — |
| `thean_ow_closed_statuses()` | Trạng thái đơn đã đóng — không tạm giữ / hủy / đổi tiếp. |
| `thean_ow_order_flows()` | Tạm giữ / hủy / tiếp tục: [trạng thái đích, cần lý do?, ghi chú]. |
| `thean_ow_preorder_blocked_statuses()` | Trạng thái không còn chuyển sang Đặt trước được (đang giao / đã giao / đã đóng). |
| `thean_ow_preorder_confirm_results()` | Xác nhận giao đơn đặt trước với khách: [giá trị _thean_preorder_confirm (rỗng = xong), ghi chú]. |
| `thean_ow_hold_restore_statuses()` | Trạng thái giao / xong: tạm giữ từ đây thì tiếp tục sẽ trả về đúng trạng thái này. |
| `thean_ow_resume_status(WC_Order $order)` | Trạng thái khi tiếp tục sau tạm giữ: đang giao / đã giao / đã xong → trạng thái trước khi tạm giữ; còn lại COD → Chuẩn bị giao VC, CK đã xác nhận → Đã CK. Rỗng = chưa tiếp tục đ… |
| `thean_ow_return_next_status(string $status)` | — |
| `thean_ow_short_codes(string $codes)` | — |
| `thean_ow_badge(string $label, string $tone = 'neutral')` | — |
| `thean_ow_row(string $label, string $value_html)` | — |
| `thean_ow_hint(string $key)` | Ghi chú tự động dưới field: 1 dòng ngắn + "Xem thêm" mở modal (nội dung trong config JS). |
| `thean_ow_pill(string $field, string $value, string $label, bool $checked)` | 1 chip chọn (checkbox) — nhiều chip cùng data-field gom thành mảng khi lưu. |
| `thean_ow_pills(string $field, array $options, array $selected)` | — |
| `thean_ow_handling_picker(array $selected)` | Chọn nhãn xử lý nội bộ: chip chia nhóm + ô lọc. |
| `thean_ow_date_field(string $field, string $label, string $value)` | — |
| `thean_ow_choice(string $title, array $selected, string $panel_html, string $empty = 'Chưa chọn', string $…)` | Nhóm hơn 3 lựa chọn: nút tóm tắt giá trị đang chọn, bấm mở modal (JS chuyển panel vào modal rồi trả về chỗ cũ khi đóng, nên input vẫn nằm trong form để lưu). $selected: [[label,… |
| `thean_ow_action_menu(string $button_label, string $title, string $items_html, string $button_class = 'button')` | Hơn 3 thao tác cùng loại: 1 nút mở modal liệt kê thao tác (nút .tow-act thật nằm trong panel). |
| `thean_ow_action_opt(string $label, string $desc, array $attrs, bool $current = false)` | 1 dòng thao tác trong modal: tiêu đề + mô tả ngắn. $attrs: data-* cho .tow-act. |
| `thean_ow_pill_tone(string $label)` | — |
| `thean_ow_user_name($user_id)` | — |
| `thean_ow_gallery(array $urls)` | — |
| `thean_ow_print_links(WC_Order $order)` | Phiếu in khi soạn hàng: GHTK / Nhanh. |
| `thean_ow_orders_search_url(string $search)` | Danh sách đơn tìm theo SĐT / email — HPOS (wc-orders) hoặc legacy (edit.php). |
| `thean_render_order_workspace($order_arg)` | — |
| `thean_ow_is_fast_delivery(WC_Order $order)` | Nhận diện giao nhanh cả sau khi đã đổi trạng thái sang Đã giao. |
| `thean_ow_is_dispatched(WC_Order $order)` | Giao nhanh là hình thức giao; phải có xác nhận xuất hoặc nhãn bàn giao mới coi là đã giao đi. |
| `thean_ow_flow(WC_Order $order)` | Trạng thái luồng xử lý của đơn (không render, không ghi): các bước, bước cần làm tiếp và gợi ý. Dùng chung cho bảng điều phối (thean_ow_render_inner) và board "Cần xử lý" của ô… |
| `thean_ow_render_inner(WC_Order $order)` | Phần bảng thay được qua AJAX (task `render`) — không gồm modal/toast/config. |
| `thean_ow_vat_state(WC_Order $order)` | Trạng thái xuất HĐ VAT (đọc thẳng meta eim — plugin vn-einvoice-manager). Không phụ thuộc eim đã nạp: Freesoul (eos-deactivate-plugins) có thể tắt eim ở trang sửa đơn mà tab vẫn… |
| `thean_ow_box_vat(WC_Order $order, array $vat)` | Tab VAT: tình trạng HĐ, số HĐ, người mua (MST / công ty / địa chỉ / email), yêu cầu xuất VAT + ảnh tin nhắn khách. |
| `thean_ow_box_open(string $id, string $title, string $badge_html = '', bool $open = true)` | — |
| `thean_ow_box_close()` | — |
| `thean_ow_box_cod_confirm(WC_Order $order, $order_no, array $state, bool $locked)` | — |
| `thean_ow_transfer_qr(WC_Order $order, $order_no, string $title = 'Mã QR chuyển khoản')` | Mã QR chuyển khoản (SePay) + nút sao chép link QR / tin nhắn gửi khách. |
| `thean_ow_payment_form(WC_Order $order, bool $is_cod, string $only_payer = '')` | Form xác nhận thanh toán (AJAX confirm_order_payment). $only_payer = 'customer': chỉ khách CK — dùng ở tab Xác nhận của đơn COD (radio đổi tên để không chung nhóm với form ở box… |
| `thean_ow_box_payment(WC_Order $order, $order_no, array $payment, bool $locked, bool $open)` | — |
| `thean_ow_box_picking(WC_Order $order)` | — |
| `thean_ow_box_export(WC_Order $order, array $images, int $confirmed_by, array $ai)` | — |
| `thean_ow_ghtk_button(WC_Order $order)` | Nút "Đăng đơn GHTK" (bước Giao hàng): mở + cuộn tới khung GHTK của plugin devvn-woo-ghtk trên trang sửa đơn (order-workspace.js tìm khung theo tiêu đề — không phụ thuộc mã nguồn… |
| `thean_ow_box_delivery(WC_Order $order, array $shippers, array $handling, string $ship_code, string $export_date…)` | — |
| `thean_ow_box_return(WC_Order $order, string $return_status, string $return_code, string $compensate, string $…)` | — |
| `thean_ow_box_hdsd(WC_Order $order)` | — |
| `thean_ow_box_customer(WC_Order $order)` | — |
| `thean_ow_box_preorder(WC_Order $order, string $note, string $confirm, bool $locked)` | Đơn đặt trước: đang chờ đến hẹn (tiếp tục / sửa ghi chú) hoặc cần nhắn/gọi khách xác nhận giao. |
| `thean_ow_box_hold_cancel(WC_Order $order, bool $locked)` | — |
| `thean_ow_box_tools(WC_Order $order, $order_no)` | — |
| `thean_ow_extra_actions(WC_Order $order)` | Thao tác ở cột Thao tác / xem nhanh của danh sách đơn (woocommerce_admin_order_actions) mà bảng điều phối chưa có (VD YC hoàn tiền form Lark, tra vận đơn…). Bỏ các action đã có… |
| `thean_ow_skip_export_proof(WC_Order $order, string $reason)` | Đánh dấu bỏ qua "thiếu chứng từ xuất kho" (đơn đã giao / xong mà thiếu ảnh / xác nhận xuất kho — tab "Thiếu chứng từ XK" của board, order-ops-step.php). Trả [thành công?, thông… |
| `thean_ow_apply_flow(WC_Order $order, string $flow, string $reason)` | Tạm giữ / Tiếp tục / Hủy / Đặt trước 1 đơn (task order_flow). Trả [thành công?, thông báo]. Dùng chung cho AJAX bảng điều phối và thao tác hàng loạt của ô tìm đơn / board (order… |
| `thean_ow_ajax()` | — |
| `thean_ow_save_fields(WC_Order $order, array $fields)` | Ghi field whitelist; multi field lưu nhiều dòng meta như Meta Box. Trả về mô tả thay đổi. |

### `woocommerce/orders/orders-admin.php`

| Hàm | Mô tả |
|---|---|
| `set_ip_for_customer($vars)` | — |
| `hithean_order_admin_action_url(string $action, int $order_id, array $args = [])` | — |
| `add_custom_actions($actions, $order)` | — |
| `hithean_order_admin_is_list_screen()` | — |
| `hithean_order_admin_export_checklist()` | — |
| `hithean_order_admin_render_return_action_modals()` | — |
| `hithean_order_admin_open_orders_in_new_tab()` | Danh sách đơn (legacy + HPOS): CHỈ bấm vào ô cột "Đơn hàng" mới mở trang chi tiết đơn, và mở ở TAB MỚI. Bấm các ô khác trên dòng không mở đơn (chặn handler click dòng của WooCom… |

### `woocommerce/orders/payment-hub-link.php`

Popup widget hub [eim_payment_hub] dùng chung cho mọi màn xác nhận thanh toán của theme.

| Hàm | Mô tả |
|---|---|
| `child_theme_hub_pay_enabled()` | — |
| `child_theme_payment_hub_link_script()` | — |
| `child_theme_payment_hub_toast_script()` | — |

### `woocommerce/orders/shortcode-order-status-change.php`

| Hàm | Mô tả |
|---|---|
| `wp_bulk_order_status_form()` | — |

### `woocommerce/orders/warehouse-export-lark-content.php`

| Hàm | Mô tả |
|---|---|
| `hithean_export_lark_images_hash(array $urls)` | Evidence identity is independent of metadata whitespace and array ordering. |
| `hithean_export_lark_current_images(int $order_id)` | — |
| `hithean_export_lark_current_pass(int $order_id)` | — |
| `hithean_export_lark_clean_text($value, int $limit = 600)` | — |
| `hithean_export_lark_staff(int $order_id)` | This workflow assigns warehouse staff through export logins and uploader IDs. |
| `hithean_export_lark_ai_details(array $review)` | — |
| `hithean_export_lark_review_url(int $order_id, string $hash)` | — |
| `hithean_export_lark_button(string $label, string $url, string $type = 'default')` | — |
| `hithean_export_lark_order_elements(WC_Order $order, array $review)` | AI text is plain_text: model output cannot inject mentions, links or card markup. |
| `hithean_export_lark_card(string $title, array $elements, string $tone = 'orange')` | — |

### `woocommerce/orders/warehouse-export-lark-follow-up.php`

| Hàm | Mô tả |
|---|---|
| `hithean_export_lark_reminder_notice()` | — |
| `hithean_export_lark_schedule_reminder(int $order_id)` | — |
| `hithean_export_lark_stop_reminders(int $order_id, string $state = 'completed')` | — |
| `hithean_export_lark_user_can_stop_reminders(int $user_id)` | — |
| `hithean_export_lark_acknowledge(int $order_id, string $hash, string $nonce)` | Acknowledgment records staff work; it never changes AI/shipping state. |

### `woocommerce/orders/warehouse-export-lark.php`

| Hàm | Mô tả |
|---|---|
| `hithean_export_lark_settings()` | Cài đặt Lark của xuất kho. Webhook + bật/tắt nằm ở kênh "kho" (core/lark.php, Cài đặt ERP → Lark); option hithean_export_lark_settings chỉ còn only_alerts + stop_reminder_user_ids. |
| `hithean_export_lark_sanitize_webhook($value)` | Tên cũ — kiểm webhook ở core/lark.php. |
| `hithean_export_lark_response_error($response)` | — |
| `hithean_export_lark_send($message)` | — |
| `hithean_export_lark_lock(string $key)` | Short option locks serialize enrollment and prevent overlapping send workers. |
| `hithean_export_lark_status(int $order_id, string $key, string $state, string $error = '', array $extra = [])` | — |
| `hithean_export_lark_alert_hash(int $order_id, array $review)` | Send once per AI review, including after upgrading the alert format. |
| `hithean_export_lark_queue_alert(int $order_id, array $review)` | — |
| `hithean_export_lark_queue_notification(int $order_id, int $attempt = 0)` | Confirmation batches have a fixed deadline, never extended by later orders. |

### `woocommerce/orders/warehouse-export-workflow.php`

| Hàm | Mô tả |
|---|---|
| `hithean_export_ai_schedule_check(int $order_id, int $requested_by = 0)` | Background work for warehouse-export evidence. |
| `hithean_export_ship_codes_split($value)` | Mã vận đơn AI bóc từ ảnh xuất kho → tự append vào "Mã giao vận" (order_ship_code) thay cho nhập tay. Nhiều mã cách nhau ", " — cùng bộ tách token [\s,;\|] mà eim reconcile dùng đ… |
| `hithean_export_ship_codes_sanitize($codes, WC_Order $order)` | — |
| `hithean_export_ship_code_key(string $code)` | Phần định danh để so trùng: segment sau dấu chấm cuối (staff hay nhập mã ngắn). |
| `hithean_export_append_ship_codes(int $order_id, array $codes, string $trigger)` | Append mã mới (bỏ trùng) vào order_ship_code, ghi note + log để card hiển thị. Trả về danh sách mã thực sự được thêm. |
| `hithean_export_ship_codes_toast_suffix(int $order_id)` | Hậu tố toast cho request vừa append mã (vd. AJAX xác nhận xuất kho). |
| `hithean_export_save_ai_ship_codes(int $order_id, string $trigger)` | Mã AI đã đọc được ở lần kiểm tra gần nhất → append (idempotent nhờ so trùng). |
| `hithean_export_record_uploader(int $order_id, int $user_id)` | Ghi nhận tài khoản đã upload ảnh xuất kho (nhiều người → giữ đủ, bỏ trùng). |
| `hithean_export_save_uploaders_as_exporters(int $order_id, string $trigger)` | Người upload ảnh → "Xuất kho bởi" (order_export_by). Meta Box select multiple lưu mỗi user_login một meta row → add_post_meta từng người còn thiếu. |
| `hithean_export_lists_version()` | — |
| `hithean_export_lists_bump()` | Đánh dấu dữ liệu 2 danh sách xuất kho đã đổi (option không autoload, ghi tối đa 1 lần/giây). |
| `hithean_export_lists_bump_on_meta($meta_id, $object_id, $meta_key)` | — |


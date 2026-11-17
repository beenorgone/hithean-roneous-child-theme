<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `shortcodes`

> Shortcode: xuất kho / hoàn hàng / đơn đã giao, so sánh SP (+AI), protein calculator, landing ANC, thông tin công ty, nhúng Google Docs/Sheets…

25 file · ~16.400 dòng · 271 hàm (30% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `ajax_confirm_export` | shortcode-order-export-confirm.php |
| AJAX | `ajax_load_order_shipped` | shortcode-order-shipped.php |
| AJAX | `ajax_order_shipped_preview_order` | shortcode-order-shipped.php |
| AJAX | `ajax_upload_images` | shortcode-order-export-confirm.php |
| AJAX | `attach_return_order` | shortcode-order-return-manager.php |
| AJAX | `get_greenspark_banner` | shortcode-greenspark-banner.php |
| AJAX | `hithean_advisor_ask` | shortcode-product-advisor.php |
| AJAX | `hithean_cflow_delete` | shortcode-consult-flows.php |
| AJAX | `hithean_cflow_import` | shortcode-consult-flows.php |
| AJAX | `hithean_cflow_list` | shortcode-consult-flows.php |
| AJAX | `hithean_cflow_save` | shortcode-consult-flows.php |
| AJAX | `hithean_consult_export` | shortcode-consult-faqs.php |
| AJAX | `hithean_consult_import` | shortcode-consult-faqs.php |
| AJAX | `hithean_consult_ref_save` | shortcode-consult-faqs.php |
| AJAX | `hithean_export_ai_check` | shortcode-order-export-confirm.php |
| AJAX | `hithean_export_bulk_assign` | export-bulk-web.php |
| AJAX | `hithean_export_bulk_discard` | export-bulk-web.php |
| AJAX | `hithean_export_bulk_group` | export-bulk-web.php |
| AJAX | `hithean_export_bulk_read` | export-bulk-web.php |
| AJAX | `hithean_export_bulk_upload` | export-bulk-web.php |
| AJAX | `hithean_export_list_refresh` | shortcode-order-export-confirm.php |
| AJAX | `hithean_faq_delete` | shortcode-consult-faqs.php |
| AJAX | `hithean_faq_list` | shortcode-consult-faqs.php |
| AJAX | `hithean_faq_save` | shortcode-consult-faqs.php |
| AJAX | `hithean_faq_suggest` | shortcode-consult-faqs.php |
| AJAX | `hithean_faq_used` | shortcode-consult-faqs.php |
| AJAX | `load_return_board` | shortcode-order-return-manager.php |
| AJAX | `load_return_orders` | shortcode-order-return-manager.php |
| AJAX | `ost_confirm_export_image` | shortcode-order-shipped.php |
| AJAX | `ost_set_order_shipper` | shortcode-order-shipped.php |
| AJAX | `ost_upload_export_images` | shortcode-order-shipped.php |
| AJAX | `process_return_order` | shortcode-order-return-manager.php |
| AJAX | `product_info_search` | shortcode-product-info-search.php |
| AJAX | `return_lookup_order` | shortcode-order-return-manager.php |
| AJAX | `thean_export_images_list` | shortcode-order-export-confirm.php |
| AJAX | `tpc_product_compare_ai_generate` | product-compare-ai.php |
| AJAX | `tpc_product_compare_ai_list` | product-compare-ai.php |
| AJAX | `tpc_product_compare_ai_public` | product-compare-ai.php |
| AJAX | `tpc_product_compare_ai_publish` | product-compare-ai.php |
| AJAX | `tpc_product_compare_ai_save` | product-compare-ai.php |
| AJAX | `tpc_product_compare_browse` | shortcode-product-compare.php |
| AJAX | `tpc_product_compare_get_product` | shortcode-product-compare.php |
| AJAX | `tpc_product_compare_search` | shortcode-product-compare.php |
| AJAX | `update_return_code` | shortcode-order-return-manager.php |
| AJAX | `upload_return_images` | shortcode-order-return-manager.php |
| AJAX công khai | `get_greenspark_banner` | shortcode-greenspark-banner.php |
| AJAX công khai | `product_info_search` | shortcode-product-info-search.php |
| AJAX công khai | `tpc_product_compare_ai_public` | product-compare-ai.php |
| AJAX công khai | `tpc_product_compare_browse` | shortcode-product-compare.php |
| AJAX công khai | `tpc_product_compare_get_product` | shortcode-product-compare.php |
| AJAX công khai | `tpc_product_compare_search` | shortcode-product-compare.php |
| CPT | `diem-ban` | pos-post-type.php |
| cron | `hithean_anc_offer_expired` | shortcode-anc-new-partner-offer.php |
| shortcode | `[anc_new_partner_offer]` | shortcode-anc-new-partner-offer.php |
| shortcode | `[anc_new_partner_offer_else]` | shortcode-anc-new-partner-offer.php |
| shortcode | `[anc_product_card]` | shortcode-anc-product-cards.php |
| shortcode | `[anc_product_switcher]` | shortcode-anc-product-cards.php |
| shortcode | `[b2b_pos_proof]` | shortcode-pos.php |
| shortcode | `[blog_posts_slider]` | shortcode-posts.php |
| shortcode | `[certifications]` | shortcode-certifications.php |
| shortcode | `[company_info]` | shortcode-company-info.php |
| shortcode | `[consult_faqs]` | shortcode-consult-faqs.php |
| shortcode | `[consult_flows]` | shortcode-consult-flows.php |
| shortcode | `[field_content]` | shortcode-field-content.php |
| shortcode | `[google_doc_editable]` | shortcode-google-docs.php |
| shortcode | `[google_sheet_editable]` | shortcode-google-docs.php, shortcode-google-sheet.php |
| shortcode | `[greenspark_banner]` | shortcode-greenspark-banner.php |
| shortcode | `[list_unconfirmed_exports]` | shortcode-order-export-confirm.php |
| shortcode | `[list_uploaded_not_shipped_exports]` | shortcode-order-export-confirm.php |
| shortcode | `[order_return_management]` | shortcode-order-return-manager.php |
| shortcode | `[order_shipped_table]` | shortcode-order-shipped.php |
| shortcode | `[pdf_embed]` | shortcode-embed.php |
| shortcode | `[pos]` | shortcode-pos.php |
| shortcode | `[product_advisor_ai]` | shortcode-product-advisor.php |
| shortcode | `[product_compare]` | shortcode-product-compare.php |
| shortcode | `[product_info_search]` | shortcode-product-info-search.php |
| shortcode | `[protein_calculator]` | shortcode-protein-calculator.php |
| shortcode | `[recipes-slider]` | shortcode-recipes.php |
| shortcode | `[recipes]` | shortcode-recipes.php |
| shortcode | `[upload_export_images_form]` | shortcode-order-export-confirm.php |
| shortcode | `[wc_product_field]` | shortcode-wc-product-field.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `hithean_anc_offer_config` | shortcode-anc-new-partner-offer.php |
| `hithean_export_ai_check_completed` | shortcode-order-export-confirm.php |
| `hithean_export_ai_config` | shortcode-order-export-confirm.php |
| `hithean_warehouse_export_confirmed` | shortcode-order-export-confirm.php, shortcode-order-shipped.php |
| `hpa_ai_config` | shortcode-product-advisor.php |
| `ivar_certifications_config` | shortcode-certifications.php |
| `litespeed_purge_post` | shortcode-anc-new-partner-offer.php |
| `pis_default_product_ids` | shortcode-product-info-search.php |
| `the_content` | shortcode-product-compare.php, shortcode-product-info-search.php |
| `tpc_compare_ai_config` | product-compare-ai.php |
| `tpc_compare_browse_per_category` | shortcode-product-compare.php |

## Hook khu này lắng nghe

`hithean_anc_offer_expired` · `init` · `mb_relationships_init` · `rwmb_meta_boxes` · `wp_enqueue_scripts` · `wp_footer`

## File

### `shortcodes/export-bulk-web.php`

Up ảnh xuất kho nhiều đơn website 1 lần — AI tự tách theo đơn. Nạp cùng shortcodes/shortcode-order-export-confirm.php (form [upload_export_images_form] → Nhiều đơn). Port từ the…

| Hàm | Mô tả |
|---|---|
| `hithean_export_bulk_closed_statuses()` | Trạng thái đơn không nhận ảnh xuất kho nữa. |
| `hithean_export_bulk_order_problem(int $order_id)` | Đơn có nhận ảnh xuất kho không. @return string '' nếu được, ngược lại là lý do. |
| `hithean_export_bulk_resolve_ref(string $ref)` | Mã AI đọc được → ID đơn (0 = không phải mã đơn). "#10276**" / "10276**" → 10276 · "10276.1" → 10276 · mã vận đơn → tra order_ship_code (chỉ nhận khi đúng 1 đơn). |
| `hithean_export_bulk_group_by_key(array $images)` | Gom ảnh theo khóa (hàm thuần). Ảnh key '' (không thấy mã) theo ảnh liền trước có khóa, đầu lô theo ảnh liền sau. |
| `hithean_export_bulk_id_clean(string $batch)` | — |
| `hithean_export_bulk_attachments(string $batch)` | — |
| `hithean_export_bulk_image_for_client(int $attachment_id, string $reason = '')` | — |
| `hithean_export_bulk_attach(int $order_id, array $attachment_ids, int $by_sequence = 0)` | Gắn ảnh lô vào đơn: thêm URL vào warehouse_export_images, đổi parent ảnh, xóa kết quả AI cũ. |
| `hithean_export_bulk_package_for_client(int $order_id, array $result, int $image_count, int $by_sequence)` | — |
| `hithean_export_bulk_guard()` | — |
| `hithean_export_bulk_request_ids(string $batch)` | — |

### `shortcodes/pos-post-type.php`

| Hàm | Mô tả |
|---|---|
| `point_of_sale_post_type()` | — |
| `point_of_sale_meta_boxes($meta_boxes)` | — |

### `shortcodes/product-compare-ai.php`

| Hàm | Mô tả |
|---|---|
| `tpc_compare_ai_table_name()` | — |
| `tpc_compare_ai_can_manage()` | — |
| `tpc_compare_ai_normalize_product_ids($raw_ids)` | — |
| `tpc_compare_ai_comparison_key(array $product_ids)` | — |
| `tpc_compare_ai_maybe_install_table()` | — |
| `tpc_compare_ai_table_is_ready()` | — |
| `tpc_compare_ai_verify_ajax(bool $manage = false)` | — |
| `tpc_compare_ai_clean_text($value, int $max_length = 6000)` | — |
| `tpc_compare_ai_format_text(string $text)` | — |
| `tpc_compare_ai_format_version(array $row, bool $include_private = false)` | — |
| `tpc_compare_ai_read_public_version(array $product_ids)` | — |
| `tpc_compare_ai_invalidate_public_cache(string $comparison_key)` | — |
| `tpc_compare_ai_nutrition_label_sources(int $product_id)` | Returns only images deliberately entered in the product's nutrition-label field. Product galleries are deliberately excluded: they frequently contain lifestyle and pack-shot ima… |
| `tpc_compare_ai_nutrition_documents(array $snapshot)` | Build provider documents from the explicit nutrition-label sources in the snapshot. The limits protect PHP memory and the outbound request payload. |
| `tpc_compare_ai_product_snapshot(array $product_ids, array $fields)` | — |
| `tpc_compare_ai_config()` | — |
| `tpc_compare_ai_generate_copy(array $snapshot)` | — |
| `tpc_compare_ai_request_ids()` | — |
| `tpc_compare_ai_ajax_public()` | — |
| `tpc_compare_ai_ajax_generate()` | — |
| `tpc_compare_ai_ajax_list()` | — |
| `tpc_compare_ai_ajax_save()` | — |
| `tpc_compare_ai_ajax_publish()` | — |

### `shortcodes/shortcode-anc-new-partner-offer.php`

| Hàm | Mô tả |
|---|---|
| `hithean_anc_offer_config()` | Cấu hình DUY NHẤT của chương trình. Số đơn khai báo tay, không đọc từ max_orders bên ERP ivarvietnam — đổi bên ERP thì sửa cả ở đây. start rỗng = áp dụng ngay (lead cuối tháng 9… |
| `hithean_anc_offer_end()` | — |
| `hithean_anc_offer_now()` | — |
| `hithean_anc_offer_is_active()` | — |
| `hithean_anc_offer_fill(string $content)` | — |
| `hithean_anc_offer_shortcode($atts, $content = '')` | — |
| `hithean_anc_offer_else_shortcode($atts, $content = '')` | — |
| `hithean_anc_offer_schedule_purge()` | Trang được page-cache sẽ giữ nguyên HTML ưu đãi sau hạn chót. Hẹn một cron ngay sau hạn để purge cache của trang này. Cron được nạp qua 'cron_hooks' trong module-loader. |
| `hithean_anc_offer_purge_cache($post_id = 0)` | — |

### `shortcodes/shortcode-anc-product-cards.php`

| Hàm | Mô tả |
|---|---|
| `hithean_anc_bool($value, bool $default = false)` | — |
| `hithean_anc_resolve_product(array $atts)` | — |
| `hithean_anc_parse_list($value)` | — |
| `hithean_anc_sanitize_css_color($value)` | — |
| `hithean_anc_card_meta_from_atts(array $atts)` | — |
| `hithean_anc_extract_card_metas(string $content)` | — |
| `hithean_anc_render_thumbnav(array $cards, string $aria_label)` | Menu ảnh sản phẩm (sticky khi cuộn trong section) — thay cho chips tên. Nút giữ class/data-card như chip cũ để an-new-chapter.js dùng chung logic chọn card. |
| `hithean_anc_product_switcher_shortcode($atts, $content = '')` | — |
| `hithean_anc_product_cta_text(WC_Product $product, string $stock_status)` | — |
| `hithean_anc_product_price_html(WC_Product $product)` | — |
| `hithean_anc_product_proof_markup(WC_Product $product, array $atts)` | Per-card proof options: auto (product's IVAR meta/cache), off, or shortcode:registered_tag. Custom shortcodes receive product_id and sku. The site's existing proof visibility se… |
| `hithean_anc_product_gallery_html(WC_Product $product)` | — |
| `hithean_anc_product_card_shortcode($atts)` | — |

### `shortcodes/shortcode-certifications.php`

Shortcode: [certifications] Khối "Hệ thống sản xuất & chứng nhận" (port từ section #anc-qc của landing anc). Self-contained: CSS + JS inline, prefix `cert-`, dùng lại được cho w…

| Hàm | Mô tả |
|---|---|
| `ivar_certifications_config()` | — |
| `ivar_cert_modal_body(array $m)` | Dựng phần thân modal (dùng chung cho modal đơn lẫn modal gộp). |
| `ivar_certifications_shortcode($atts, $content = null)` | — |
| `ivar_cert_assets()` | CSS + JS inline, in một lần cho cả trang. |

### `shortcodes/shortcode-company-info.php`

| Hàm | Mô tả |
|---|---|
| `hithean_company_info_shortcode($atts)` | [company_info field="hotline"] — in ra 1 trường thông tin doanh nghiệp (khai báo ở Cài đặt ERP > Thông tin doanh nghiệp), dùng trong nội dung bài viết. |

### `shortcodes/shortcode-consult-faqs.php`

| Hàm | Mô tả |
|---|---|
| `hcf_faq_sanitize(array $raw)` | — |
| `hcf_faq_store_normalize($raw)` | — |
| `hcf_faq_store_upsert(array $store, array $raw, string $now, string $by, bool $can_update)` | Thêm / sửa FAQ (hàm thuần). Câu hỏi trùng (bỏ dấu) với FAQ khác → cập nhật FAQ đó nếu $can_update, ngược lại báo trùng. |
| `hcf_faq_tokens(string $text)` | Chữ có nghĩa của câu (bỏ dấu, bỏ từ xưng hô / đệm hay gặp trong chat). |
| `hcf_faq_match(array $faqs, string $text, array $skus = [], int $limit = 3, float $min = 0.45)` | FAQ khớp câu hỏi (hàm thuần): độ giống Dice trên chữ có nghĩa (câu khách vs câu hỏi FAQ), + 0.5 nếu cụm từ khóa xuất hiện (1 từ khóa là đủ khớp), + 0.2 nếu trùng SKU. Giữ điểm ≥… |
| `hcf_faq_list(array $store)` | Danh sách FAQ: dùng nhiều trước, rồi mới cập nhật trước. |
| `hcf_faq_read()` | — |
| `hcf_faq_write(array $store, ?array $before = null, string $action = 'save')` | — |
| `hcf_faq_public(array $faq)` | Gọn cho trình duyệt (không gửi lịch sử). |
| `hcf_faq_ajax_list()` | — |
| `hcf_faq_ajax_suggest()` | — |
| `hcf_faq_ajax_save()` | — |
| `hcf_faq_ajax_delete()` | — |
| `hcf_faq_ajax_used()` | — |
| `hcf_reference()` | {text, updated, by} — chính sách ship / đổi trả / ưu đãi đang chạy… AI tư vấn luôn đọc (≤ 20.000 ký tự). |
| `hcf_reference_save(string $text)` | — |
| `hcf_reference_ajax_save()` | — |
| `hcf_bundle_build(array $flows, array $faqs, string $reference, string $site, string $now, string $scope =…)` | Gói JSON mang sang site khác (hàm thuần): không có ID / lượt dùng / người sửa của site nguồn. $scope: all \| flows \| faqs. |
| `hcf_bundle_parse($data)` | Tách gói đã giải mã (hàm thuần) → [flows[], faqs[], reference]. Nhận: gói chuẩn, file cũ chỉ có {flows} / {items}, hoặc mảng trơn (phần tử có `steps` = kịch bản, có `q` = FAQ). |
| `hcf_reference_merge(string $current, string $incoming, string $label)` | Gộp tài liệu tham khảo nhập vào (hàm thuần): trống → lấy luôn; đã chứa → giữ nguyên; khác → nối thêm có tiêu đề nguồn. |
| `hcf_bundle_ajax_export()` | — |
| `hcf_bundle_ajax_import()` | — |
| `hcf_faq_shortcode()` | — |

### `shortcodes/shortcode-consult-flows.php`

| Hàm | Mô tả |
|---|---|
| `hcf_can_view()` | Xem / copy kịch bản: nhân viên xử lý đơn. |
| `hcf_can_manage()` | Thêm / sửa / xóa / nhập kịch bản. |
| `hcf_norm(string $text)` | Chuỗi so khớp: chữ thường, bỏ dấu tiếng Việt, chỉ giữ a-z0-9 cách nhau 1 khoảng trắng. |
| `hcf_clean_text($value, int $max = 4000, bool $multiline = true)` | Làm sạch 1 chuỗi văn bản thuần (giữ xuống dòng), cắt độ dài. |
| `hcf_clean_list($value, int $max_items = 40, int $max_len = 80)` | Danh sách chuỗi ngắn (từ khóa / SKU) từ mảng hoặc chuỗi phân tách bằng dấu phẩy / xuống dòng. |
| `hcf_sanitize_flow(array $raw)` | Chuẩn hóa dữ liệu 1 kịch bản (từ form / file nhập). Thiếu tiêu đề hoặc không có bước nào có nội dung → steps rỗng (nơi lưu tự báo lỗi). Tối đa 30 bước. |
| `hcf_store_normalize($raw)` | Kho rỗng / chuẩn hóa kho đọc từ option. |
| `hcf_store_upsert(array $store, array $raw, string $now, string $by)` | Thêm / sửa 1 kịch bản trong kho (hàm thuần). id = 0 → thêm mới. |
| `hcf_store_list(array $store, bool $include_drafts)` | Danh sách kịch bản đã sắp: nhóm (trống cuối) → tiêu đề, bỏ dấu. Nháp chỉ khi $include_drafts. |
| `hcf_store_read()` | — |
| `hcf_store_write(array $store, ?array $before = null, string $action = 'save')` | Ghi kho + đẩy bản cũ vào lịch sử (≤ 50). |
| `hcf_current_user_label()` | — |
| `hcf_store_import(array $store, array $items, string $now, string $by)` | Nhập nhiều kịch bản (hàm thuần): trùng tiêu đề (bỏ dấu) → cập nhật, còn lại thêm mới. |
| `hcf_get_flows(bool $include_drafts = false)` | Mọi kịch bản (nháp chỉ cho người quản lý). |
| `hcf_save_flow(array $raw)` | Lưu (thêm / sửa) 1 kịch bản. @return int\|WP_Error ID |
| `hcf_match(array $flows, string $text, array $skus = [], int $limit = 3)` | Chấm điểm kịch bản theo câu hỏi + SKU: cụm từ khóa xuất hiện nguyên cụm (+2 + số chữ), tiêu đề nằm trong câu (+3), SKU trùng (+3 mỗi mã). Trả tối đa $limit kịch bản điểm > 0, ca… |
| `hcf_starter_flows()` | Kịch bản mẫu chung (không gắn SP) — tạo khi người quản lý bấm "Tạo kịch bản mẫu" lúc danh sách trống. |
| `hcf_ajax_guard(bool $manage)` | — |
| `hcf_ajax_list()` | — |
| `hcf_ajax_save()` | — |
| `hcf_ajax_delete()` | — |
| `hcf_ajax_import()` | — |
| `hcf_shortcode()` | — |
| `hcf_assets()` | CSS + JS của [consult_flows] (in 1 lần). |

### `shortcodes/shortcode-embed.php`

| Hàm | Mô tả |
|---|---|
| `wr_shortcode_pdf_embed($atts = [])` | — |

### `shortcodes/shortcode-field-content.php`

| Hàm | Mô tả |
|---|---|
| `display_custom_field_content_shortcode($atts)` | — |

### `shortcodes/shortcode-google-docs.php`

| Hàm | Mô tả |
|---|---|
| `render_google_embed_helper($atts, $type = 'sheet')` | CORE HELPER FUNCTION Handles the logic for both Sheets and Docs to avoid code duplication. |
| `custom_embed_editable_google_sheet($atts)` | Shortcode: [google_sheet_editable] |
| `custom_embed_editable_google_doc($atts)` | Shortcode: [google_doc_editable] |

### `shortcodes/shortcode-google-sheet.php`

| Hàm | Mô tả |
|---|---|
| `custom_embed_editable_google_sheet($atts)` | Shortcode hiển thị Google Sheet (Đã sửa lỗi hiển thị trắng) Cách dùng: [google_sheet_editable id="YOUR_ID"] |

### `shortcodes/shortcode-greenspark-banner.php`

| Hàm | Mô tả |
|---|---|
| `get_greenspark_banner()` | Fetch Greenspark Widget via Secure AJAX |
| `greenspark_banner_shortcode()` | Register Shortcode for Greenspark Banner |

### `shortcodes/shortcode-order-export-confirm.php`

| Hàm | Mô tả |
|---|---|
| `hithean_upload_internal_order_image(array $file, int $parent_post_id, string $target_filename, int $max_dimension = 1000)` | Store internal order evidence images without generating every registered theme/WooCommerce thumbnail size. These images are admin proof only. |
| `hithean_get_export_upload_checklist()` | — |
| `hithean_render_export_image_gallery(array $urls)` | — |
| `hithean_export_ai_config()` | AI review is advisory only; warehouse confirmation stays manual. |
| `hithean_export_ai_clean_text($value, int $limit = 500)` | — |
| `hithean_export_ai_clean_list($value, int $max_items = 8)` | — |
| `hithean_export_ai_masked_order_reference(WC_Order $order)` | — |
| `hithean_export_ai_is_expected_masked_reference(string $reference, WC_Order $order)` | — |
| `hithean_export_ai_is_shipment_reference(string $reference, WC_Order $order)` | One order may ship in several parcels; each shipping code is the order ID plus a ".N" parcel index (10276.1, 10276.2 … all belong to order 10276). |
| `hithean_export_ai_result_pill(string $label, string $value, string $tone)` | — |
| `hithean_export_ai_order_note(array $result)` | — |
| `hithean_export_ai_documents(int $order_id, array $urls)` | — |
| `hithean_export_ai_check_order(WC_Order $order, int $requested_by = 0)` | — |
| `hithean_render_export_ai_check(int $order_id)` | — |
| `hithean_render_export_ai_ship_codes_status(int $order_id, array $codes)` | Mã vận đơn AI bóc từ ảnh — luôn hiện đủ, kể cả mã đã có sẵn trong "Mã giao vận", kèm trạng thái từng mã so với trường đó và giá trị hiện tại của trường. |
| `hithean_render_export_ship_codes_notice(int $order_id)` | Thông báo trên card: mã vận đơn đã tự lưu vào "Mã giao vận" từ ảnh. |
| `hithean_export_ai_is_confirmable(int $order_id)` | — |
| `hithean_render_export_ai_bulk_controls(string $scope)` | — |
| `shortcode_upload_export_images_form()` | — |
| `hithean_export_live_wrap(string $scope, string $html)` | Khung "live" của 2 danh sách: JS gọi hithean_export_list_refresh để thay HTML khung khi có thay đổi (upload / xác nhận / AI, và tự kiểm mỗi 45s) — không cần tải lại trang. Port… |
| `hithean_export_live_render(array $scopes)` | HTML mới của các danh sách cho AJAX refresh. @param string[] $scopes |
| `hithean_export_restore_confirmed_from_notes(int $order_id)` | Khôi phục export_confirmed_by từ ghi chú "Đã xác nhận (ảnh) xuất kho bởi <tên>" khi meta đã mất (field disabled của metabox từng bị Meta Box xóa mỗi lần lưu trang sửa đơn). Trả… |
| `shortcode_list_unconfirmed_exports()` | — |
| `shortcode_list_uploaded_not_shipped_exports()` | — |
| `hithean_export_confirm_assets()` | — |

### `shortcodes/shortcode-order-return-manager.php`

| Hàm | Mô tả |
|---|---|
| `hithean_upload_internal_order_image(array $file, int $parent_post_id, string $target_filename, int $max_dimension = 1000)` | Store internal order evidence images without generating every registered theme/WooCommerce thumbnail size. These images are admin proof only. (Bản copy có guard để module return… |
| `hithean_return_hpos_enabled()` | Có bật HPOS (custom order tables) không? Quyết định query bảng meta nào: wc_orders_meta (HPOS) hay postmeta (legacy). |
| `hithean_return_range_options()` | Khoảng thời gian tải board hoàn hàng (theo ngày tạo đơn). Mặc định 60 ngày qua. |
| `hithean_return_range($range)` | — |
| `hithean_return_range_label(string $range)` | — |
| `hithean_return_range_sql(string $range, string $column)` | Điều kiện SQL lọc theo ngày tạo đơn (cột GMT), '' khi chọn "Tất cả". |
| `hithean_return_get_order_ids(string $type, string $range = '60')` | Lấy danh sách order ID theo return_status. Query trực tiếp bảng meta đúng theo storage — KHÔNG dùng wc_get_orders()+meta_query vì meta_query bị data store bỏ qua ở một số phiên… |
| `hithean_return_pending_statuses()` | Danh sách trạng thái hoàn "cần xử lý" — dùng cho form gắn đơn phát sinh. |
| `hithean_return_next_status(string $status)` | — |
| `hithean_return_max_upload_files()` | — |
| `hithean_return_max_upload_bytes()` | — |
| `hithean_return_allowed_filters()` | — |
| `hithean_return_board_columns()` | — |
| `hithean_return_column_for_status(string $status)` | — |
| `hithean_return_status_next_action(string $status)` | — |
| `hithean_return_format_code_for_display($code)` | — |
| `hithean_return_clear_cache()` | — |
| `hithean_return_now_mysql()` | — |
| `hithean_return_touch_meta(WC_Order $order, string $event)` | — |
| `hithean_return_append_code(WC_Order $order, string $code)` | Thêm mã vận đơn hoàn vào return_code (nối bằng ", ", bỏ trùng). Trả về true nếu có thêm mới. |
| `hithean_return_upload_files(string $field, int $order_id, string $prefix)` | Upload ảnh từ $_FILES[$field] (jpg/png, ≤ hithean_return_max_upload_files() ảnh, mỗi ảnh ≤ 5MB) vào đơn. Trả về danh sách URL, hoặc WP_Error nếu vượt giới hạn. |
| `hithean_return_append_images(WC_Order $order, string $meta_key, array $urls)` | Nối URL ảnh vào meta dạng mỗi dòng 1 URL (issue_report_images / issue_result_images). |
| `hithean_return_order_date_value(WC_Order $order, string $meta_key)` | — |
| `hithean_return_order_has_issue(WC_Order $order)` | — |
| `hithean_return_order_matches_filter(WC_Order $order, string $filter)` | — |
| `hithean_return_get_board_order_ids(string $filter, string $range = '60')` | — |
| `hithean_return_get_received_order_ids(int $limit = 51, int $offset = 0, string $range = '60')` | — |
| `hithean_return_order_priority(WC_Order $order)` | — |
| `hithean_return_order_items_summary(WC_Order $order, int $limit = 2)` | — |
| `hithean_return_render_summary_cards(array $counts)` | — |
| `hithean_return_render_order_card(WC_Order $order)` | — |
| `hithean_return_render_board(array $orders)` | — |
| `hithean_return_render_history_rows(array $orders)` | — |
| `hithean_return_render_history_table(array $orders)` | — |
| `hithean_return_render_order_preview(WC_Order $order)` | — |
| `hithean_return_normalize_code($token)` | — |
| `hithean_return_resolve_code($token)` | — |
| `hithean_return_phone_core($phone)` | — |
| `hithean_return_find_ids_by_phone($phone)` | — |
| `hithean_return_find_ids_by_email($email)` | — |
| `hithean_return_search_orders($raw)` | Tìm đơn hợp nhất: tách token, dispatch theo loại (email / SĐT / mã đơn). |
| `hithean_return_render_search_results(array $orders)` | Render bảng kết quả tìm đơn — mỗi đơn chưa có return_status thì hiện form gắn (chọn trạng thái + mã vận đơn hoàn + upload ảnh sự cố). Đơn đã có thì chặn (chống ghi đè). |

### `shortcodes/shortcode-order-shipped.php`

| Hàm | Mô tả |
|---|---|
| `ost_get_shippers()` | — |
| `ost_get_payment_label($method)` | — |
| `ost_format_ship_code_for_display($ship_code)` | — |
| `ost_get_order_images($meta_value)` | — |
| `ost_render_image_thumbs($image_urls, $order_id = 0)` | — |
| `ost_render_order_links($ids)` | — |
| `ost_is_self_shipper($shipper)` | — |
| `ost_get_reconciliation_columns()` | — |
| `ost_get_reconciliation_issues($order, $shipper, $ship_code, $export_date, $images, $confirmed_user_id)` | — |
| `ost_get_reconciliation_bucket($issues)` | — |
| `ost_init_reconciliation_stats()` | — |
| `ost_render_issue_badges($issues)` | — |
| `ost_upload_internal_order_image(array $file, int $parent_post_id, string $target_filename, int $max_dimension = 1000)` | — |
| `ost_resolve_search_to_order_ids($search)` | — |
| `ost_get_orders_data($from_date, $to_date, $filter_shipper, $search_ids = null)` | CORE LOGIC - HITHEAN VERSION Mapping lại các Meta Key |
| `ost_get_single_order_card_payload($order_id)` | — |
| `ost_render_single_row($data)` | — |
| `ost_render_kanban_card($data)` | — |
| `ost_render_reconciliation_workspace($stats, $kanban_cards)` | — |
| `ost_render_lazy_table_controls()` | — |
| `ost_render_report_table($summary_data)` | — |
| `ajax_load_order_shipped()` | — |
| `ost_ajax_upload_export_images()` | — |
| `ost_ajax_confirm_export_image()` | — |
| `ost_ajax_set_order_shipper()` | — |
| `ost_ajax_preview_order()` | — |

### `shortcodes/shortcode-pos.php`

| Hàm | Mô tả |
|---|---|
| `display_points_of_sale_shortcode($atts)` | — |
| `hithean_b2b_pos_proof_shortcode($atts)` | — |

### `shortcodes/shortcode-posts.php`

| Hàm | Mô tả |
|---|---|
| `display_blog_posts_slider($atts)` | — |

### `shortcodes/shortcode-product-advisor.php`

| Hàm | Mô tả |
|---|---|
| `hpa_can_use()` | — |
| `hpa_catalogue()` | Danh mục rút gọn SP đang bán: [{id, name, sku, skus[] (gồm SKU biến thể), price, stock, url}] — cache 30 phút (file chỉ nạp ở công cụ / AJAX nên không nghe save_post; dữ liệu ch… |
| `hpa_detect_products(string $text, array $catalogue, int $limit = HPA_MAX_DETAIL)` | Nhận ra SP trong câu hỏi (hàm thuần): SKU (cha hoặc biến thể) xuất hiện nguyên từ; hoặc tên SP — ≥ 2 chữ có nghĩa (≥ 3 ký tự, không phải chữ chung) và phủ ≥ 60% các chữ đó. Trả… |
| `hpa_ids_from_skus(array $skus, array $catalogue)` | SKU (cha hoặc biến thể) → ID SP cha, theo danh mục. |
| `hpa_plain($html, int $max)` | Văn bản thuần từ HTML / shortcode metabox, cắt độ dài. |
| `hpa_product_snapshot(int $product_id)` | Dữ liệu chi tiết 1 SP cho AI (catalogue + metabox product_info_*), không có đường dẫn file / dữ liệu nội bộ. |
| `hpa_config()` | — |
| `hpa_system_prompt(string $length)` | — |
| `hpa_parse_reply(array $data)` | Đọc JSON AI trả về (hàm thuần): ép kiểu, cắt độ dài, bỏ phần tử rỗng. |
| `hpa_call_ai(array $ctx)` | Gọi AI 1 lượt với ngữ cảnh đã gom. @return array\|WP_Error |
| `hpa_label_documents(array $product_ids)` | Ảnh nhãn thành phần của các SP (tái dùng logic AI so sánh SP: chỉ ảnh trong Media Library khai ở metabox, ≤ 3MB, ≤ 8 ảnh). |
| `hpa_ajax_ask()` | — |
| `hpa_shortcode()` | — |

### `shortcodes/shortcode-product-compare.php`

| Hàm | Mô tả |
|---|---|
| `tpc_compare_ajax_nonce()` | — |
| `tpc_compare_icon_svg($key)` | Icon SVG nét (stroke) dùng cho các nút của shortcode, màu theo currentColor. |
| `tpc_compare_button_inner($icon, $label)` | Nội dung nút: icon + nhãn (nhãn bọc span để JS đổi chữ mà không mất icon). |
| `tpc_compare_format_price_text($amount)` | — |
| `tpc_compare_variation_label_short($variation, $parent_name)` | — |
| `tpc_compare_stock_display($wc_product)` | — |
| `tpc_compare_get_price_groups($product, $product_title)` | — |
| `tpc_compare_get_variants($product, $product_id, $product_title)` | — |
| `tpc_compare_default_labels()` | — |
| `tpc_compare_humanize_field_label($field_key)` | — |
| `tpc_compare_parse_fields($raw_fields)` | — |
| `tpc_compare_parse_fields_from_json($raw_fields)` | — |
| `tpc_compare_sanitize_utf8($value)` | — |
| `tpc_compare_json_encode_for_script($value, $fallback = '[]')` | — |
| `tpc_compare_format_field_value($value)` | — |
| `tpc_compare_get_field_value($product, $product_id, $field_key)` | — |
| `tpc_compare_build_product_payload($product_id, array $fields)` | — |
| `tpc_compare_resolve_product_token($token)` | Đổi 1 token (ID hoặc SKU) thành ID sản phẩm cha. Trả về 0 nếu không khớp. Token toàn số được ưu tiên hiểu là ID; nếu không phải ID sản phẩm thì thử như SKU. SKU/ID của biến thể… |
| `tpc_compare_normalize_product_ids($raw_ids)` | Nhận danh sách ID và/hoặc SKU (ngăn cách bởi dấu phẩy, khoảng trắng hoặc "-") và trả về mảng ID sản phẩm. Vì SKU có thể chứa "-", mỗi đoạn được thử khớp nguyên khối trước, sau đ… |
| `tpc_compare_get_product_ids_from_query()` | — |
| `tpc_compare_verify_ajax_request()` | — |
| `tpc_compare_cache_group()` | — |
| `tpc_compare_get_category_term_ids($category_id)` | ID danh mục kèm toàn bộ danh mục con (có cache). |
| `tpc_compare_default_browse_categories()` | Danh sách slug danh mục mặc định cho dropdown khi bấm vào ô tìm kiếm. |
| `tpc_compare_parse_category_slugs($raw_slugs)` | — |
| `tpc_compare_get_category_groups(array $slugs)` | Sản phẩm (đã publish, có ảnh, có giá) theo từng nhóm danh mục, giữ thứ tự slug truyền vào. Trong mỗi nhóm: bán chạy nhất lên trước (total_sales), cùng lượt bán thì theo tên A→Z. |
| `tpc_compare_browse_products_ajax()` | — |
| `tpc_compare_search_products_ajax()` | — |
| `tpc_compare_get_product_ajax()` | — |
| `tpc_product_compare_shortcode($atts)` | — |

### `shortcodes/shortcode-product-info-search.php`

| Hàm | Mô tả |
|---|---|
| `pis_format_price_text($amount)` | — |
| `pis_variation_label_short($variation, $parent_name)` | — |
| `pis_stock_display($wc_product)` | — |
| `pis_get_price_lines_for_product($product, $product_title)` | — |
| `pis_register_scripts()` | — |
| `ajax_product_info_search()` | — |
| `product_info_search_shortcode($atts)` | — |

### `shortcodes/shortcode-protein-calculator.php`

| Hàm | Mô tả |
|---|---|
| `render_protein_calculator($atts)` | — |

### `shortcodes/shortcode-recipes.php`

| Hàm | Mô tả |
|---|---|
| `display_recipes_by_products($atts)` | — |
| `display_recipes_slider($atts)` | — |

### `shortcodes/shortcode-wc-product-field.php`

| Hàm | Mô tả |
|---|---|
| `child_theme_wc_product_field_resolve_product(array $atts)` | — |
| `child_theme_wc_product_field_shortcode($atts)` | — |
| `child_theme_wc_product_field_price_block_html(WC_Product $product)` | Dựng khối giá: nếu đang sale → giá sale + giá gốc gạch ngang + tag %; nếu không sale → chỉ hiện giá bán (không gạch ngang, không tag). |
| `child_theme_wc_product_field_gallery_html(WC_Product $product)` | Dựng markup gallery (.anc-gallery) từ ảnh đại diện + album sản phẩm. Trùng cấu trúc với gallery tĩnh trên landing page để initGalleries() hoạt động. |


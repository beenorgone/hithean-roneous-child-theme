# Hi The An — Roneous Child Theme

WordPress child theme (parent: `roneous`) cho hithean.com — cửa hàng bán sản phẩm do IVAR sản xuất
(An New Chapter, Yeast Hero…). **Không có kho riêng**: đơn được xuất từ kho Theanmarket (kho dùng chung),
xem `docs/erp-mesh.md`. Nhiều module được port qua lại với theme theanmarket
(`../theanmarket-wr-nitro-child-theme`) — khi port, xem mục "Khác biệt với theanmarket".

## Nạp code

```
functions.php                 ← define HITHEAN_THEME_DIR, require bootstrap
custom-functions/bootstrap.php← nạp core/* rồi core/module-loader.php; filter eim (shipper, meta xuất kho)
custom-functions/core/admin.php        ← admin_enqueue_scripts: nạp file theo screen (danh sách/sửa đơn…)
custom-functions/core/module-loader.php← TOÀN BỘ logic what-loads-when
```

`module-loader.php`:
- `$general_includes` — nạp mọi request.
- `tpc_loader_modules()` — mỗi module `['id', 'file', 'paths'|'path_groups'|'paths_filter', 'condition', 'shortcodes', 'ajax_actions', 'admin', 'cron_hooks']`.
  Front nạp ở `wp` khi khớp path / conditional tag / trang có shortcode (dò chuỗi, không dùng `has_shortcode`);
  AJAX nạp ở `init` priority 1 theo `ajax_actions`; `admin => true` nạp ngay khi include (không trong AJAX).
- Path group (`tpc_loader_path_groups()`): `tools` (/tien-ich, /tien-ich-admin), `compare`, `protein`, `anc_landing`.
- **Thêm AJAX action mới thì phải khai vào `ajax_actions` của module**, nếu không handler không được nạp.

## Cấu trúc `custom-functions/`

```
core/          admin, module-loader, template-router, enqueue, ai-settings + ai-providers (Cài đặt ERP → AI),
               email-overrides, upload-guard, public-file-guard, vn-address, theme-code-cache, company-info-settings
woocommerce/
  orders/      orders-admin (cột Thao tác + modal thao tác nhanh hithean-order-*), order-actions-column,
               order-search (filter tìm mặc định của WC), order-universal-search (ô tìm đơn sticky — xem dưới),
               order-workspace (bảng điều phối ở trang sửa đơn), edit-order-page, order-metabox (field Meta Box order_*),
               order-status, order-number, order-auto-tasks, order-tracking, warehouse-export-workflow,
               order-creator/ (trang /tao-don/), payment-hub-link, hpos-order-check, order-admin-error-solver
  checkout/    bank-accounts (TK nhận tiền: BACS + eim; hithean_payment_qr_url…), checkout-page, thank-you-page,
               shortcode-bank-transfer-confirmation, shortcode-payment-pending-confirm
  products/    product-page, product-metabox, sales-count (+ -admin), product-navigation, add-to-cart-popup,
               product-archive-footer-menu, proof-embed-cache, product-nutrition-label…
shortcodes/    order-export-confirm (+ export-bulk-web), order-shipped, order-return-manager, product-compare(-ai),
               protein-calculator, anc-product-cards, anc-new-partner-offer, company-info, google-docs/sheet…
marketing/     offers/ (ưu đãi), lucky-wheel/, popup-widget, cro-landing-agent (chạy theo lịch, không chạy ở front)
auth/          email-login (OTP), firebase-sms-login, email-issue, email-tasks
integrations/  erp-mesh-adapter (plugin mcp-site-link), plugin-devvn-ghtk-tweaks
admin/         shortcodes-guide, blogpost-metabox, plugins-page, editor-tools/
```

Ngoài ra: `pages/` (landing tĩnh: an-new-chapter*, doi-tac-yeast-hero, uu-dai, home), `templates/`, `plans/`, `docs/`, `TIL/`.

## Khác biệt với theanmarket (đọc khi port)

| | theanmarket | hithean |
|---|---|---|
| Meta vận hành đơn | `ship_code`, `shipper`, `export_date`, `paid_date`, `bank_account_received`, `handling_status` | Tiền tố `order_`: `order_ship_code`, `order_shipper` (select **nhiều giá trị**, mã `self/ghtk/ahamove/viettel`), `order_ship_date`, `order_paid_date`, `order_bank_account_received`, `order_handling_status`, `order_export_by`. Đơn cũ còn key không tiền tố → đọc `order_*` trước rồi fallback |
| Nút thao tác nhanh danh sách đơn | `thean-order-*`, modal `.thean-quick-modal` | `hithean-order-*`, modal `#hithean-order-modal` / `.hithean-return-modal` |
| Hàm màn danh sách đơn | `thean_order_admin_is_list_screen()` | `hithean_order_admin_is_list_screen()` |
| QR thanh toán | Sepay cứng trong orders-admin | `hithean_payment_qr_url()` + `hithean_primary_bank_account()` (bank-accounts.php, luôn nạp) |
| Nạp file | `bootstrap.php` (`child_theme_require_files`, `theanmarket_is_ajax_action`) | `core/admin.php` + `tpc_loader_modules()` |
| Kho | Có kho, trừ tồn | Không kho; plugin `mcp-site-link` ghi nhật ký phát sinh, Theanmarket trừ tồn hộ |

Mã đơn hiển thị giống nhau: ID + hậu tố (`thean_change_woocommerce_order_number()` ở order-number.php).
Plugin hóa đơn VAT (eim) đọc meta xuất kho qua filter `eim_export_meta_map` / `eim_order_shipper_label` trong bootstrap.php.

## Khóa đơn cũ (`woocommerce/orders/order-age-lock.php`, dùng chung theanmarket)

Đơn cũ = tạo **trước ngày mốc** chọn ở Cài đặt ERP → Xử lý đơn → "Khóa đơn cũ" (option `thean_order_age_lock_cutoff`; trống = hơn 365 ngày —
`thean_order_lock_cutoff_ts()`, thông báo dùng `thean_order_lock_label()`; board dùng cùng mốc). Người dùng chỉ admin (thao tác tay) đổi được trạng thái; **mọi tiến trình tự động** (cron, WP-CLI, REST — kể cả
hub eim đổi trạng thái / đối soát, Action Scheduler, request không đăng nhập như webhook) bị chặn ở `woocommerce_before_order_object_save`
và ghi chú vào đơn. `order-auto-tasks.php` không chạy dây chuyền trên đơn cũ. Tạm nới có thời hạn (1 giờ … 3 ngày) ở Cài đặt ERP →
Xử lý đơn → "Khóa đơn cũ" (option `thean_order_age_lock_relax`). Board "Tải đơn cần xử lý" luôn bỏ đơn cũ (theo mốc). Test: `php tests/order-age-lock.php`.

## Màu

- Thương hiệu: **xanh dương đậm `#0047ba`** (`--default-color-dark-blue` ở `css/custom.css`), navy `#001f3f`,
  xanh lá `#00843d`, vàng `#fbb917`.
- `admin.css` (Material) ép mọi `.wp-core-ui .button` / `.button-primary`: chữ in hoa, `margin-bottom:10px`,
  màu `--md-primary`, tất cả `!important`. Component admin riêng nên dùng class nút riêng, không gắn `.button`.

## Ô tìm đơn (`woocommerce/orders/order-universal-search.php`)

- **Bảng danh sách của Woo tắt mặc định** (`order-list-lazy.php`, nạp sớm ở đầu `core/admin.php` vì WC truy vấn danh sách
  trước `admin_enqueue_scripts`): không chạy truy vấn đơn (legacy `posts_pre_query`, HPOS `woocommerce_hpos_pre_query`).
  Menu ⋯ trên thanh tìm → "⤓ Tải danh sách Woo" mở lại với `?thean_wc_list=1` (JS gắn tiếp vào phân trang / tab / sắp xếp), không nhớ cho lần sau.
  Bảng cũng hiện khi có `?s=`, lọc khách / tháng, hoặc kết quả thao tác hàng loạt.
  Cùng file: nút "Thêm đơn hàng" của danh sách trỏ sang `/tao-don/` (tab mới) khi có module tạo đơn (`thean_order_creator_url()`).

Port từ theanmarket. Thanh tìm sticky ở danh sách đơn admin, **thay ô tìm mặc định** (ẩn `p.search-box`).
- UI nạp qua `core/admin.php`; AJAX `thean_order_usearch`, `thean_order_usearch_preview`, `thean_order_usearch_alerts`
  khai ở module `order_universal_search` trong module-loader.
- Gõ tự do (≥3 ký tự): ID / mã đơn, tên, SĐT, email, địa chỉ, tên SP, mã vận đơn. Lệnh lọc kết hợp (AND), có dropdown gợi ý:
  `/tt` trạng thái (lặp lại = OR, gõ không dấu được), `/sdt` (≥4 số), `/id`, `/sp`, `/kh`, `/vd`, `/email`.
- HPOS (`wc_orders`, `wc_order_addresses`, `wc_orders_meta`) + legacy (`postmeta`, `_billing/_shipping_address_index`).
  Mỗi nguồn lấy ≤1000 ứng viên, giao các tập, lọc cuối theo loại đơn/trạng thái, giữ ≤500 ID.
- 10 đơn/trang, "Xem thêm" chỉ cắt từ danh sách ID đã cache (transient 3 phút, khóa theo truy vấn + version cache
  `orders` của WC) — không chạy lại LIKE.
- Bảng 4 cột; mobile dạng thẻ. Nút theo nhóm màu: Xem (Xem nhanh / Chỉnh → `/tao-don/?order_id=` / Chi tiết),
  Thanh toán, Kho, Hoàn, Khác. Nút thao tác nhanh giữ class `.wc-action-button-hithean-order-*` để dùng modal của orders-admin.php.
- Vị trí sticky tính bằng JS theo `#wpadminbar` + `.woocommerce-layout__header` (header WC Admin cố định, render muộn).
- "Ẩn thông báo": ô tích trong menu ⋯ của thanh tìm (chấm vàng trên nút ⋯ khi đang ẩn) ẩn mọi `.notice` / `.update-nag` ở danh sách đơn, **mặc định bật cho mọi user**; bỏ tích lưu `'0'` vào user meta
  `thean_ous_alerts_off` (nhớ cho phiên sau, mọi thiết bị); class `thean-ous-alerts-off` gắn ở `admin_body_class` để không chớp.
- CSS/JS: `orders/assets/order-universal-search.{css,js}` — **giống hệt bản theanmarket**, sửa thì chép sang cả 2 site;
  khác biệt truyền qua `window.theanOusCfg` (`thean_ous_ui_config()`, vd `blockingModals`). Màu: biến `--ous-*` đầu file CSS.
- Board "📋 Tải đơn cần xử lý" — hiện ngay trên trang dưới thanh tìm, không phải modal (AJAX `thean_order_usearch_board`): tab Xác nhận COD / Chờ thanh toán / Lấy hàng / Xuất kho / Chờ giao /
  Đang giao / Thu COD / Hoàn hàng. Bước của mỗi đơn **lưu sẵn ở meta `_thean_ops_step`** (`woocommerce/orders/order-ops-step.php`,
  nạp mọi request): tính bằng `thean_ow_flow()` (order-workspace.php — cùng logic bảng điều phối) khi đơn được lưu / meta liên quan
  bị ghi thẳng (`*_post_meta` lẫn `*_order_meta` — `save_meta_data()` trên HPOS chỉ phát hook sau; luồng cũ ghi `update_post_meta`
  → `thean_ops_order_cache_bust()` bỏ đơn khỏi OrderCache (Redis) để lần đọc sau kéo post meta sang HPOS — sync on read).
  Meta lưu **bước thật** cả với đơn nội bộ / đơn cũ (`thean_ops_step_compute($order, false)`); board lọc 2 loại đó bằng SQL theo cài đặt hiện tại.
  Tab "Thiếu chứng từ XK" lọc thêm ngày tạo ≤ `THEAN_OPS_PROOF_DAYS` bằng SQL (`thean_ous_proof_since_sql()`); cron 10 phút quét 50 đơn mở, và 10 đơn đang hiện trên board luôn được tính lại (tự sửa). Board chỉ đếm (GROUP BY)
  + phân trang SQL. Mở board / Làm mới / sau thao tác: tải sẵn 5 đơn đầu của **mọi tab** trong 1 request (`all=1`, `THEAN_OUS_BOARD_FIRST`); tab đang xem tự tải thêm cho đủ 20
  (`THEAN_OUS_BOARD_MORE`) → đổi tab
  lấy từ bộ nhớ đệm (1 phút, `BOARD_TTL`); lượt tự sửa làm đơn đổi tab thì server lấy lại danh sách trong cùng request, không gọi server; "Xem thêm" trong tab tải tiếp 20 đơn theo `offset` / `limit`. Loại khỏi luồng: Hủy / Thất bại / Hoàn tiền / Hoàn thành / Chưa ghi nhận / Đã ghi nhận
  (`thean_ops_excluded_statuses()`), đơn gộp Shopee, **đơn nội bộ** (cờ `_eim_internal`; lọc theo domain email ở Cài đặt ERP → Xử lý đơn → "Đơn nội bộ"
  **bật mặc định** — tắt bằng ô tích = option `thean_internal_domains_off` = '1', `thean_internal_domains_enabled()`; khớp **đúng** domain:
  khai `thean.com` thì `a@hn.thean.com` vẫn vào board, muốn loại subdomain phải khai riêng; danh sách domain ở
  option `thean_internal_email_domains`, trống = dùng danh sách của eim) — board lọc thẳng bằng SQL
  nên đổi domain có hiệu lực ngay; đơn đóng còn hoàn dở vẫn vào tab Hoàn hàng.
  **Đổi luồng vận hành → tăng `THEAN_OPS_STEP_VERSION`** để mọi đơn mở được tính lại.
- Nút "🧹 Xoá cache" (thanh tìm, AJAX `thean_order_usearch_flush` — khai ở module-loader): xoá cache trình duyệt, đổi version cache
  `orders` của WC (bỏ danh sách ID khớp + HTML xem nhanh), tính lại bước 50 đơn mở / lượt (≤ `THEAN_OUS_FLUSH_MAX` = 2000 đơn mới nhất).
- Dòng kết quả có nhãn "📍 bước flow" (`thean_ous_step_badge()`: bước tính trực tiếp, lý do ngoài board — nội bộ / đơn cũ / đã đóng / gộp Shopee)
  + cảnh báo "⚠ Board đang xếp: …" khi meta `_thean_ops_step` lệch; trên board chỉ hiện khi lệch.
- "Ẩn thanh admin + menu trái" (menu ⋯): ẩn `#wpadminbar` + `#adminmenumain` ở danh sách đơn, **mặc định bật**; bỏ tích lưu `'0'` vào user meta
  `thean_ous_chrome_off` (AJAX `thean_order_usearch_alerts` với `what=chrome`), class `thean-ous-chrome-off` ở `admin_body_class`.
  Popup Thao tác / Xem nhanh / Hóa đơn có z-index trên `#wpadminbar` (99999) để không bị che đầu.
- Mobile (≤782px): board có thanh ‹ [chọn bước ▾] › trên dải tab (‹ › nhảy tab kề có đơn); mỗi đơn chỉ hiện nút "⚡ Thao tác"
  (ô `td.thean-ous__c-mact`, cột Thao tác tiếp theo + Thao tác ẩn) → popup `#thean-ous-acts` 2 nhóm: "Thao tác tiếp theo" (bản sao cột
  tiếp theo) và "Tổng quan" (`<template data-thean-ous-acts-all>` = `thean_ous_render_actions($order, false)`). Nút trong popup dùng
  chung handler toàn trang (modal thao tác nhanh, popup luồng, VAT…).
- Tab "Thiếu chứng từ XK" lọc theo **cờ** `_thean_ops_proof` (không theo bước): đơn **Đang giao** thiếu chứng từ nằm ở cả tab
  "Đang giao" (bước chính, để theo dõi — không về "Lấy hàng") lẫn "Thiếu chứng từ XK"; đơn Đã giao / Giao 1 phần / Hoàn thành tạo trong `THEAN_OPS_PROOF_DAYS` (60) ngày mà thiếu ảnh xuất kho
  hoặc xác nhận xuất kho (`thean_ops_missing_proof()`, ưu tiên trên mọi bước khác). Nút "⏭️ Bỏ qua" (đơn lẻ + hàng loạt) → meta
  `_thean_export_proof_skip`, task `export_proof_skip` (`thean_ow_skip_export_proof()`) — **chỉ Administrator** (`thean_can_skip_export_proof()`).
- "🔁 Đổi trạng thái" tùy ý (từng đơn + hàng loạt): chỉ người được chỉ định ở Cài đặt ERP → Xử lý đơn → "Quyền đổi trạng thái tùy ý"
  (option `thean_status_change_users`; trống = mọi Administrator) — `thean_can_change_any_status()`, server kiểm tra lại.
- GHTK (phương án A): bước Chờ giao có "🚚 Đăng GHTK" (board → trang sửa đơn `#step-ghtk`; bảng điều phối: nút trong box Giao hàng)
  → order-workspace.js mở + cuộn tới khung GHTK của plugin devvn-woo-ghtk (tìm postbox theo tiêu đề, không phụ thuộc mã plugin).
- Đơn đặt trước: trạng thái `pre-order` (đăng ký ở order-status.php, cùng slug theanmarket) có tab "Đặt trước". Nút
  "Chuyển sang đặt trước" (popup ghi chú bắt buộc — `assets/order-flow-popup.js`, dùng chung) ở bảng điều phối, cột Thao tác, ô tìm,
  board → task `order_flow` flow `preorder` (ghi `_thean_preorder_note`). "Tiếp tục" từ Đặt trước → gắn `_thean_preorder_confirm`,
  đơn vào tab "Xác nhận khách" (đổi tên từ "Xác nhận COD") tới khi ghi nhận "Khách đã xác nhận giao" (task `preorder_confirm`).
- Dòng kết quả / board: cột **"Thao tác tiếp theo"** (gợi ý + nút đúng bước — `thean_ous_next_actions()`, so đuôi slug action nên
  chạy cả `hithean-order-*`; bước chưa có thao tác nhanh → mở trang sửa đơn `#step-<bước>`) và nút Tạm giữ / Tiếp tục / Hủy (popup lý do,
  `assets/order-flow-popup.js` → `thean_ow_apply_flow()` của order-workspace.php). Ô chọn từng đơn + chọn tất cả → thanh thao tác
  hàng loạt (Tạm giữ / Tiếp tục / Đặt trước / Hủy / Đổi trạng thái — AJAX `thean_order_usearch_bulk`, ≤50 đơn / lần).
- "🧾 Nhập VAT" (`order-vat-request.php` + `assets/order-vat-request.js`, nạp mọi request; nút ở cột Thao tác, xem nhanh, box Khách hàng
  của bảng điều phối): **bắt buộc up ảnh chụp tin nhắn thực tế của khách** (≤5, meta `_thean_vat_proof_ids`) rồi mới nhập MST / công ty /
  địa chỉ / email — "🤖 Bóc tách bằng AI" (feature `order_vat_ai_extract`). Lưu meta eim `_eim_buyer_*` (chốt tay) + `_eim_vat_request`
  {at, by, proof, note}; eim gửi kèm khi hub đồng bộ (`eim_order_vat_request`) và hub tự đưa vào "VAT chờ khớp"
  (`eim_pending_vat_from_site_request`). Đơn đã vào lô / đã xuất HĐ thì khóa.
- "🧾 Xem hóa đơn" (cột Thao tác + xem nhanh): popup iframe dùng bản xem trước hóa đơn của trang Tạo đơn (AJAX
  `order_creator_invoice_preview`, `WC_Email_Customer_Invoice`), có In / Mở tab mới; chỉ hiện khi có module tạo đơn.
- Tải: 10 đơn/trang đọc gộp 1 lần `wc_get_orders(['post__in' => …])`; HTML xem nhanh cache Redis (`wp_cache`, nhóm
  `thean_ous`) 2 phút; mỗi user ≤4 lượt tìm thật (không trúng cache) / 30s, lượt thứ 5 khóa 2 phút (HTTP 429, `retry_after`).

## Test

Chạy thuần PHP, không cần WordPress: `php tests/<file>.php`
- `order-universal-search.php`, `export-bulk-web.php`, `lucky-wheel-coupon-lookup.php`, `offers-test.php`,
  `offers-structured-test.php`, `landing-template-test.php`.

## Quy ước

- Commit & push thẳng `main` (như theanmarket).
- Comment / commit message bằng tiếng Việt, kiểu `feat(orders): …`, `fix(…)`.
- `custom-functions/firebase_credentials.json` không commit (.gitignore).

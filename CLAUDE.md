# Hi The An — Roneous Child Theme

WordPress child theme (parent: `roneous`) cho hithean.com — cửa hàng bán sản phẩm do IVAR sản xuất
(An New Chapter, Yeast Hero…). **Không có kho riêng**: đơn được xuất từ kho Theanmarket (kho dùng chung),
xem `docs/erp-mesh.md`. Nhiều module được port qua lại với theme theanmarket
(`../theanmarket-wr-nitro-child-theme`) — khi port, xem mục "Khác biệt với theanmarket".

> `AGENTS.md` là bản chép của file này, sinh bởi `tools/docs-sync.php` — **sửa CLAUDE.md, đừng sửa AGENTS.md**.

## Đọc gì, khi nào

| Cần | Đọc |
|---|---|
| Request đi đâu, tpc_loader, **bảng route**, tầng, module, thêm tính năng ở đâu | [`docs/architecture.md`](docs/architecture.md) |
| Tìm hàm / AJAX / hook / shortcode / cron của một khu | [`docs/code-map/`](docs/code-map/README.md) (sinh tự động) |
| Mục lục docs + cơ chế tự cập nhật | [`docs/README.md`](docs/README.md) |

Tài liệu tự đồng bộ với code (cùng cơ chế theanmarket / ivarvietnam): bật hook một lần mỗi bản clone
`git config core.hooksPath .githooks`; kiểm `php tools/docs-sync.php --check`. Thêm route mới → thêm dòng vào bảng
`<!-- ROUTES -->` ở `docs/architecture.md` (máy kiểm, thiếu là chặn commit).

## Nạp code

```
functions.php                 ← define HITHEAN_THEME_DIR, require bootstrap
custom-functions/bootstrap.php← CHỈ nạp file: core/* hạ tầng rồi core/module-loader.php (không viết hook ở đây)
custom-functions/core/admin.php        ← admin_enqueue_scripts: nạp file theo screen (danh sách/sửa đơn…)
custom-functions/core/module-loader.php← TOÀN BỘ logic what-loads-when
```

`module-loader.php`:
- `$general_includes` — nạp mọi request (thiếu file → `error_log` khi `WP_DEBUG`). Hook/filter mới luôn chạy → file riêng + khai ở đây.
- `tpc_loader_modules()` — mỗi module `['id', 'file', 'paths'|'path_groups'|'paths_filter', 'condition', 'shortcodes', 'ajax_actions', 'admin', 'cron_hooks']`.
  Front nạp ở `wp` khi khớp path / conditional tag / trang có shortcode (dò chuỗi, không dùng `has_shortcode`);
  AJAX nạp ở `init` priority 1 theo `ajax_actions`; `admin => true` nạp ngay khi include (không trong AJAX).
- Path group (`tpc_loader_path_groups()`): `tools` (/tien-ich, /tien-ich-admin), `compare`, `protein`, `anc_landing`.
- **Thêm AJAX action mới thì phải khai vào `ajax_actions` của module**, nếu không handler không được nạp.

## Cấu trúc `custom-functions/`

```
core/          admin, module-loader, template-router, enqueue, ai-settings + ai-providers (Cài đặt ERP → AI),
               email-overrides, upload-guard, public-file-guard, site-guards (CSP cho hub nhúng /tien-ich-admin), vn-address, theme-code-cache, company-info-settings
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
marketing/     offers/ (ưu đãi), lucky-wheel/, popup-widget, cro-landing-agent (chạy theo lịch, không chạy ở front),
               site-footer-menu + my-offers (thanh menu đáy toàn site + "Ưu đãi của tôi" — xem dưới)
auth/          email-login (OTP), firebase-sms-login, email-issue, email-tasks
integrations/  erp-mesh-adapter (plugin mcp-site-link), eim-order-shipper (filter eim: shipper, mã VĐ, meta xuất kho), plugin-devvn-ghtk-tweaks
admin/         shortcodes-guide, blogpost-metabox, plugins-page, editor-tools/
```

Ngoài ra: `pages/` (landing tĩnh: an-new-chapter*, doi-tac-yeast-hero, uu-dai, home), `templates/`, `plans/`, `docs/`, `TIL/`.

## Khác biệt với theanmarket (đọc khi port)

| | theanmarket | hithean |
|---|---|---|
| Meta vận hành đơn | `ship_code`, `shipper`, `export_date`, `paid_date`, `bank_account_received`, `handling_status` | Tiền tố `order_`: `order_ship_code`, `order_shipper` (select **nhiều giá trị**, mã `self/ghtk/ahamove/viettel`), `order_ship_date`, `order_paid_date`, `order_bank_account_received`, `order_handling_status`, `order_export_by`. Đơn cũ còn key không tiền tố → đọc `order_*` trước rồi fallback |
| Nút thao tác nhanh danh sách đơn | `thean-order-*` | `hithean-order-*`. 4 popup (thanh toán / khách XN COD / ảnh xuất kho / xác nhận XK) **dùng chung file** `orders/order-quick-modals.php` (`.thean-quick-modal`, giống hệt 2 site — khác biệt qua `thean_qm_site()`); popup hoàn hàng vẫn riêng (`.hithean-return-modal`) |
| Hàm màn danh sách đơn | `thean_order_admin_is_list_screen()` | `hithean_order_admin_is_list_screen()` |
| QR thanh toán | `thean_payment_qr_url()` + `thean_primary_bank_account()` (payment-bank-accounts.php) | `hithean_payment_qr_url()` + `hithean_primary_bank_account()` (bank-accounts.php, luôn nạp) |
| Nạp file | Cùng cấu trúc: `core/module-loader.php` + `core/admin.php`; thêm `core/request.php` (`child_theme_is_ajax_action`) + `erp/core/erp-loader.php` (gate ERP). AJAX ngoài tpc nạp bằng `child_theme_is_ajax_action([...])`; test `tests/bootstrap-ajax.php` | `core/admin.php` + `tpc_loader_modules()` |
| Kho | Có kho, trừ tồn | Không kho; plugin `mcp-site-link` ghi nhật ký phát sinh, Theanmarket trừ tồn hộ |

Mã đơn hiển thị giống nhau: ID + hậu tố (`thean_change_woocommerce_order_number()` ở order-number.php).
**Popup thao tác nhanh** (`orders/order-quick-modals.php`, chép y nguyên sang theanmarket): 4 modal riêng, khung lặp lại gom ở
`thean_qm_open()` / `thean_qm_close()`; TK nhận tiền lấy từ `thean_qm_bank_accounts()` (hithean_payment_bank_accounts → eim → BACS, luôn có
"tiền mặt") — **không viết cứng số TK**. Popup "📦 Ảnh xuất kho" (nút "Ảnh XK" luôn hiện ở cột Thao tác): ảnh đã up + AI + trạng thái xác nhận
(AJAX `thean_export_images_list`, cùng tên 2 site) + up thêm (popup không đóng, tải lại ảnh) + nút "✓ Xác nhận xuất kho".
Plugin hóa đơn VAT (eim) đọc meta xuất kho qua filter `eim_export_meta_map` / `eim_order_shipper_label` ở `integrations/eim-order-shipper.php`.
Nhãn đơn vị vận chuyển: **nguồn duy nhất** `hithean_shipper_labels()` / `hithean_order_shipper_names()` (`orders/order-shipper.php`) — metabox, eim, ô tìm đơn, trang xác nhận TT đều đọc từ đây.

## Thanh menu đáy toàn site & ưu đãi

- `marketing/site-footer-menu.php` (module `site_footer_menu`, điều kiện `tpc_cond_site_footer_menu()`): Trang chủ · Sản phẩm ·
  **Giỏ hàng (giữa, mở popup giỏ add-to-cart-popup)** · Nhận ưu đãi · Ưu đãi của tôi. "Nhận ưu đãi" mở popup "Ưu đãi tiêu biểu"
  (khung `.hithean-offers-dialog` dùng chung với popup trang SP, AJAX `hithean_sfm_featured` → `hithean_sfm_featured_cards()`:
  1 thẻ / chương trình, nhiều SP trước) + thẻ vòng quay tĩnh + nút "Xem toàn bộ ưu đãi" → /uu-dai/; nút nổi vòng quay bị ẩn
  (body `sfm-wheel-in-offers`) như trang SP. Ở trang chủ, /shop + mọi trang **trừ** trang SP, danh mục (đã có thanh `apfm`),
  giỏ / thanh toán / cảm ơn, landing `anc_landing`, /tao-don, /tien-ich-admin. /uu-dai/ chỉ in panel (nút ở nav trang).
  JS tự bỏ thanh khi trang có thanh đáy khác (`.anc-sticky-menu`, `#apfm-root`, `.pcn`, `.ho-modebar`).
- "Ưu đãi của tôi" (`marketing/my-offers.php`): mã vòng quay (cùng hash định danh lucky-wheel) + coupon giới hạn email, còn hạn / còn lượt.
  Đăng nhập (user, email, billing, SĐT login SMS) hoặc mã vòng quay của phiên → hiện mã đầy đủ + Sao chép / Dùng cho giỏ.
  Khách tự nhập email/SĐT (kèm tài khoản gắn với nó) → **mã bị che**; email → "Gửi mã về email", SĐT → đăng nhập SMS.
  Liên hệ đã tra nhớ theo phiên (cookie `hithean_mo` → transient) + tài khoản (user meta `hithean_my_offers_contacts`).
  Nội dung tải AJAX khi mở (trang có thể cache; nonce lấy từ phản hồi load). Đăng nhập từ panel → trang tài khoản `?hmo_return=` →
  quay lại trang cũ `#uu-dai-cua-toi` (tự mở panel). Thêm action AJAX → khai cả ở module-loader lẫn (nếu cần state vòng quay)
  `thean_lw_is_feature_ajax_request()`.
- **Thẻ ưu đãi dùng chung** `.hithean-offer-card` + khung popup `.hithean-offers-dialog` (`marketing/assets/offer-card.css`, handle `hithean-offer-card`; PHP
  `hithean_offer_card_html()` ở `offers/offer-card.php`): popup "Ưu đãi đang áp dụng" trang SP (product-purchase.js), /uu-dai/ chế độ
  "Theo sản phẩm" (`hithean_offer_cards_from_rows()` — chip / ảnh / bậc giá / thứ tự khớp popup) và panel "Ưu đãi của tôi". Sửa kiểu thẻ ở 1 chỗ. Màu theo loại: lớp `.is-tone-{addon|tiers|shipping|discount|wheel|coupon|other}` (PHP: `tone` → `group` → `kind`; JS trang SP: `card.tone || card.kind`).
  Link `…/san-pham/x/#uu-dai` mở sẵn popup ưu đãi ở trang SP.
- **Thứ tự chương trình ưu đãi** (`marketing/offers/order.php`, nguồn duy nhất): nhóm discount → tiers → gift (add-on / quà tặng) → wheel → other →
  shipping là **mặc định** (giảm giá trước, quà tặng tiếp, free ship cuối); admin đổi ở Ưu đãi sản phẩm → Cấu hình & xuất bản → "Thứ tự hiển thị
  chương trình ưu đãi" (↑ ↓, option `hithean_offers_settings()['order']`, `hithean_offers_order()`). Áp cho `hithean_offer_cards_from_rows()` (popup
  danh sách SP, /uu-dai/ chế độ SP), /uu-dai/ chế độ chương trình (còn hàng → **ghim** → nhóm), "Ưu đãi tiêu biểu" (nhóm → số SP), popup trang SP
  (`config.offerOrder` → `sortCards()` ở product-purchase.js). Dòng snapshot → nhóm: `hithean_offers_row_group()`. Test: `php tests/offers-order.php`.
- **Nút ưu đãi trên thẻ SP ở danh sách** (`marketing/offers/loop-button.php` + `marketing/assets/loop-offers.{css,js}`, nạp mọi request): mỗi SP 1 nút
  `[data-hlo]`, vị trí chọn ở Ưu đãi sản phẩm → Cấu hình & xuất bản (a = nhãn góc ảnh, b = dải dưới giá, c = nhãn cạnh giá;
  `hithean_offers_settings()['loop_button']` máy tính — **mặc định c**, `['loop_button_mobile']` điện thoại ≤767px — **mặc định b**; 2 vị trí khác nhau
  thì in cả hai nút, CSS ẩn theo bề rộng `.hlo-d` / `.hlo-m` vì trang cache). Bấm → popup `.hithean-offers-dialog`, AJAX `hithean_loop_offers` (khai ở module `site_footer_menu` để có
  `hithean_sfm_featured_cards()`): ưu đãi của SP từ snapshot, SP chưa có ưu đãi riêng → ưu đãi tiêu biểu. Chỉ hiện khi snapshot công khai
  (`hithean_loop_offers_enabled()`); khi hiện thì khung vàng `product-addon-promo` không in nữa. Test: `php tests/loop-offers-button.php`.
  Dải b: lề 10px (điện thoại 5px), tối đa 400px, căn giữa; chữ "Ưu đãi" là `.hlo__label` riêng, không co / không cắt — chỉ phần nội dung `.hlo__text` bị cắt "…".
- **Giá ưu đãi trên thẻ SP ở danh sách** (`products/product-list-page.php` → `hithean_loop_rule_price_html()`, filter `woocommerce_get_price_html` @9999,
  chỉ trong lúc in giá của thẻ — cờ `$GLOBALS['hithean_loop_price_scope']` bật / tắt quanh `woocommerce_after_shop_loop_item_title`): SP thuộc rule
  Discount Rules giảm **ngay từ 1 sản phẩm** hiện giá gốc gạch + giá ưu đãi như Sale thông thường (SP biến thể: khoảng giá). Giá do plugin tính
  (cùng đường trang chi tiết: `hithean_product_purchase_rule_price()` — nạp lazy product-purchase.php); rule theo giỏ hàng / quà tặng / BOGO giữ nguyên
  giá. Cùng dữ liệu (`hithean_loop_rule_price_data()`): in nhãn Sale `span.onsale` trên ảnh (`hithean_loop_rule_sale_flash()` — cùng kiểu nhãn NEW, SP đang
  Sale thông thường thì WooCommerce tự in) + thêm lớp `sale` cho thẻ (`woocommerce_post_class`, để nhãn NEW xếp dưới nhãn Sale; không áp cho SP chính của
  trang chi tiết). Không đổi `is_on_sale()`. Test: `php tests/loop-rule-price.php`.
- **Thông báo Lark khi /uu-dai/ đổi chương trình** (`marketing/offers/lark-notify.php`): nghe `update_option_hithean_offers_snapshot`
  (mọi đường đổi snapshot: duyệt / loại / ghim, quét tuần, mốc hết hạn, khôi phục snapshot) → cron `hithean_offers_lark_flush` sau 5 phút,
  so tóm tắt theo chương trình (`hithean_offers_lark_programs()` / `_diff()` / `_message()` — hàm thuần) với mốc đã báo (option
  `hithean_offers_lark_state`) → 1 tin "post": mới / thay đổi (nội dung, thời gian, SP thêm-bỏ) / ngừng (hết hạn, gỡ). Lần bật đầu chỉ ghi mốc;
  lỗi gửi giữ mốc, thử lại ≤3 lần. Gửi qua kênh Lark **`uu_dai` — nhóm riêng**, không dùng nhóm kho (Cài đặt ERP → Lark; cùng tab có
  @Tất cả = option `hithean_offers_lark_settings.mention_all` + gửi thử danh sách). Test: `php tests/offers-lark-notify.php`.
- /uu-dai/: vừa vào hỏi nhanh (dialog `data-ho-ask`) xem theo sản phẩm hay chương trình; bỏ qua khi URL có `?xem=san-pham|chuong-trinh`
  hoặc đã chọn trong phiên (sessionStorage `ho-mode`).

## Lark (`core/lark.php` — GIỐNG HỆT hithean ↔ theanmarket, sửa thì chép sang cả 2 site, kèm `tests/lark-core.php`)

Mọi thông báo Lark đi qua **kênh đặt tên**: tính năng khai `child_theme_lark_register_channel('kho', [label, description, fallback])`
rồi gửi `child_theme_lark_send($key, $payload)` / `_text()` / `_notify()` (thẻ cùng dạng `erp_lark_notify()` của ivar: title, body, status, url,
actions, fields) — **không tự giữ webhook, không tự `wp_remote_post` sang Lark**. Kênh hiện có: `kho` (xuất kho — `warehouse-export-lark.php`), `uu_dai` (ưu đãi /uu-dai/, nhóm riêng), `cro` (báo cáo CRO landing).

- Cấu hình ở option `erp_lark_settings['channels']` = [{key, label, type webhook|chat_id, value, active}] — **cùng định dạng bộ Lark ERP
  của ivarvietnam** (`erp/integrations/lark/`): chép bộ đó sang (nâng cấp lên Lark App) là đọc được ngay; kênh `chat_id` tự gửi qua
  `erp_lark_api_post()` khi bộ đó có mặt.
- Kiểm webhook chặt (`child_theme_lark_sanitize_webhook()`), không theo redirect, HTTP 200 kèm mã lỗi Lark = lỗi, nhật ký
  `child_theme_lark_log` không chứa URL. Kênh có `fallback` để trống webhook thì dùng webhook kênh dự phòng.
- Cài đặt: **Cài đặt ERP → tab Lark** (`child_theme_lark_settings_tab()`): bật / webhook / gửi thử / lần gửi gần nhất từng kênh; ô riêng của
  tính năng qua hook `child_theme_lark_settings_fields` + `child_theme_lark_settings_save`; bật kênh → `child_theme_lark_channel_enabled`.
- Webhook riêng cũ của từng tính năng tự chuyển sang kênh 1 lần (`child_theme_lark_migrate()`, cờ `child_theme_lark_migrated`).
- **Chỉ nạp ở wp-admin (gồm admin-ajax / admin-post) + trang ERP / công cụ nội bộ** — `child_theme_lark_should_preload()` ở
  `core/module-loader.php`; trang khách KHÔNG nạp. Loader có sẵn 2 hàm nhẹ: `child_theme_lark_register_channel()` (khai kênh lúc include,
  không cần core) và `child_theme_lark_load()` — code tính năng chạy được ngoài admin (cron, REST, form trang công cụ) **gọi
  `child_theme_lark_load()` trước khi dùng hàm Lark** (đặt trong hàm settings / send của tính năng). Không gọi hàm Lark từ hook chạy mọi
  request (`init`, `wp`…) — đặt lịch / kiểm trạng thái kênh ở `admin_init`. Test tính năng dùng shim như loader và kiểm include không kéo core.


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
  Ẩn với **mọi user**, kể cả khi URL có `?s=`, lọc khách / tháng hay kết quả thao tác hàng loạt — chỉ hiện khi có `?thean_wc_list=1`.
  Board "Tải đơn cần xử lý" tự tải khi mở trang (`boardAutoload` trong `thean_ous_ui_config()`), trừ lúc đang mở bảng Woo.
  **Thứ tự vận hành board**: `thean_ops_step_flow()` (order-ops-step.php) — 'main' đánh số 1→6 + mũi tên, 'side' = ngoại lệ
  (Sự cố / Thiếu CT XK / Hoàn / Đặt trước / Đơn nội bộ) sau vạch tách; cùng nguồn cho select mobile + sơ đồ bấm được ở 📖 Quy trình. Thêm tab → thêm vào đây.
  **Link thẳng tới tab**: `?thean_board=<tab>` (key của `thean_ops_step_tabs()`: confirm, payment, picking, export, delivery,
  shipping, cod, proof, return, preorder) luôn mở board ở tab đó; URL trên thanh địa chỉ đi theo tab đang xem (`history.replaceState`),
  nút "🔗 Link tab" sao chép link. PHP: `thean_ops_board_url($tab)` (order-ops-step.php, nạp mọi request).
  Cùng file: nút "Thêm đơn hàng" của danh sách trỏ sang `/tao-don/` (tab mới) khi có module tạo đơn (`thean_order_creator_url()`).

Port từ theanmarket. Thanh tìm sticky ở danh sách đơn admin, **thay ô tìm mặc định** (ẩn `p.search-box`).
- UI nạp qua `core/admin.php`; AJAX `thean_order_usearch`, `thean_order_usearch_preview`, `thean_order_usearch_alerts`
  khai ở module `order_universal_search` trong module-loader.
- Gõ tự do (≥3 ký tự): ID / mã đơn, tên, SĐT, email, địa chỉ, tên SP, mã vận đơn. Lệnh lọc kết hợp (AND), có dropdown gợi ý:
  `/tt` trạng thái (lặp lại = OR, gõ không dấu được), `/nhom` nhóm trạng thái (`thean_ous_status_groups()`, gộp OR với /tt), `/sdt` (≥4 số), `/id`, `/sp`, `/kh`, `/vd`, `/email`.
- HPOS (`wc_orders`, `wc_order_addresses`, `wc_orders_meta`) + legacy (`postmeta`, `_billing/_shipping_address_index`).
  Mỗi nguồn lấy ≤1000 ứng viên, giao các tập, lọc cuối theo loại đơn/trạng thái, giữ ≤500 ID.
- Nút "Trạng thái ▾" cạnh Ngày tạo: chọn nhóm / từng trạng thái → ghép `/nhom` + `/tt` vào truy vấn (ô tìm trống = liệt kê đơn theo trạng thái).
- Kết quả phân trang số 10/20/50 đơn/trang (`THEAN_OUS_PER_PAGE_OPTIONS`, nhớ ở localStorage; board vẫn "Xem thêm"), mỗi trang chỉ cắt từ danh sách ID đã cache (transient 3 phút, khóa theo truy vấn + version cache
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
  Meta lưu **bước thật** cả với đơn nội bộ / đơn cũ (`thean_ops_step_compute($order, false)`); board lọc đơn cũ + tách đơn nội bộ sang tab "Đơn nội bộ" bằng SQL theo cài đặt hiện tại.
  Tab "Thiếu chứng từ XK" lọc thêm ngày tạo ≤ `THEAN_OPS_PROOF_DAYS` bằng SQL (`thean_ous_proof_since_sql()`); cron 10 phút quét 50 đơn mở, và 10 đơn đang hiện trên board luôn được tính lại (tự sửa). Board chỉ đếm (GROUP BY)
  + phân trang SQL. Mở board / Làm mới / sau thao tác: tải sẵn 5 đơn đầu của **mọi tab** trong 1 request (`all=1`, `THEAN_OUS_BOARD_FIRST`); tab đang xem tự tải thêm cho đủ 20
  (`THEAN_OUS_BOARD_MORE`) → đổi tab
  lấy từ bộ nhớ đệm (1 phút, `BOARD_TTL`); lượt tự sửa làm đơn đổi tab thì server lấy lại danh sách trong cùng request, không gọi server; "Xem thêm" trong tab tải tiếp 20 đơn theo `offset` / `limit`. Đơn nội bộ → tab ngoại lệ riêng (không loại). Loại khỏi luồng: Hủy / Thất bại / Hoàn tiền / Hoàn thành / Chưa ghi nhận / Đã ghi nhận
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

## Sự cố giao hàng & mẫu ghi chú

- **Luồng ngoại lệ Đơn nội bộ** (tab `internal` của board, nhóm 'side' — order-ops-step.php / order-universal-search.php, GIỐNG HỆT hithean ↔ theanmarket): đơn nội bộ (cờ `_eim_internal` / domain email nội bộ ở Cài đặt ERP → Xử lý đơn) KHÔNG còn bị loại khỏi board mà chỉ hiện ở tab "Đơn nội bộ" (mọi bước, nút thao tác theo bước thật, gợi ý "🏢 Nội bộ · …"); tab luồng khách loại chúng bằng `thean_ops_internal_sql($t)` trong `thean_ous_board_tab_sql()`, tab nội bộ chọn bằng `thean_ops_internal_sql($t, false)`. `thean_ops_step_compute()` trả thêm `internal`; đơn gộp Shopee vẫn ngoài board. Nút **🙅 Không phải nội bộ** (`order-internal-flow.php`, popup kind `notinternal` ở order-flow-popup.js → task `not_internal_preview` / `not_internal` của thean_order_workspace): tick chọn ✉️ bỏ email nội bộ khỏi billing (thay email nhập tay / email thật của khách gắn) và 👤 tạo / gắn tài khoản khách theo SĐT (billing → shipping, 0/84/+84) rồi email thật — bỏ qua tài khoản nhân viên / email nội bộ, không có thì tạo khách role customer (email thật hoặc `84SĐT@sms.<domain>`); luôn gỡ `_eim_internal` + ghi `_thean_not_internal`=1 → `thean_order_is_internal()`, SQL board và eim (filter `eim_is_internal_order`) coi là đơn khách.
- **Trang Tạo đơn — danh tính đơn** (`order_creator_guard_operator_identity()` + filter `woocommerce_checkout_customer_id` ở create-order-for-customer.php, cả 2 site): khách vãng lai không mạo danh nên `create_order()` từng gán đơn cho nhân viên đang thao tác → lần sửa sau điền email nhân viên vào đơn. Nay ép customer_id = khách đích (`order_creator_state()['customer_id']`), email người mua trùng email nhân viên (khi đơn không phải của chính nhân viên đó) → thay bằng email khách / để trống + ghi chú đơn. Khách vãng lai: tính giỏ dưới user 0 (`wp_set_current_user(0)`), không dưới role nhân viên — giá theo role / rule ưu đãi theo vai trò không áp nhầm. **Quà Woo Discount Rules khi nạp lại đơn**: `order_creator_load_order` BỎ dòng quà WDR tự thêm (BOGO mua X tặng X/Y — `order_creator_order_item_is_wdr_gift()`: cờ `_oc_auto_gift` ghi lúc lưu, khóa meta wdr+free, hoặc dòng 0đ có meta wdr) — nạp đơn = dựng lại giỏ khách chọn, rule tự thêm quà lại 1 lần; snapshot gắn `auto_gift` → UI hiện dòng 🎁 chỉ đọc, không gửi lên; `populate_cart` bỏ item `auto_gift`. Không để quà thành hàng mua (rule tặng chồng mỗi lần lưu). Tương tự **giảm giá WDR**: phí âm WDR (cờ `_oc_auto_fee` ghi lúc lưu khi phí không khớp phí tay `state['fees']`, đơn cũ: phí âm trùng tiêu đề / nhãn rule — `order_creator_wdr_labels()`) và coupon ảo WDR (không có coupon thật + trùng nhãn rule; mã thật đã xóa như Anxu vẫn giữ) KHÔNG nạp lại vào phí / mã → rule tự áp lại 1 lần (`auto_discounts` trả về UI).
- **Bảng điều phối (order-workspace.php) có đủ nút của dòng đơn /quan-ly-don** (GIỐNG HỆT hithean ↔ theanmarket): box 🖨️ Chứng từ & tiện ích thêm 🧾 Xem hóa đơn (`thean_ous_invoice_url`, tab mới), 📝 Ghi chú đơn (popup `note`), thao tác danh sách đơn còn thiếu (`thean_ow_extra_actions()` — action `woocommerce_admin_order_actions` trừ loại đã có bước / box riêng, so đuôi slug: VD YC hoàn tiền, tra vận đơn) và 🔁 Đổi trạng thái tùy ý (task `any_status`, chỉ `thean_can_change_any_status()`); box Xuất kho có ⏭️ Bỏ qua thiếu chứng từ (popup `skipproof`, chỉ admin); box Khách hàng có 🙅 Không phải nội bộ khi đơn nội bộ. Box Chứng từ nạp lười order-universal-search.php (trang sửa legacy / AJAX render không nạp). Thêm nút mới ở dòng đơn /quan-ly-don → thêm cả vào bảng điều phối.
- **Sự cố giao hàng + mẫu ghi chú** (`woocommerce/orders/order-flow-notes.php`, nạp từ order-ops-step.php — GIỐNG HỆT hithean ↔ theanmarket): đơn bước Chờ giao VC / Đang giao gắn cờ `_thean_delivery_issue` (nút ⚠️ Báo sự cố, BẮT BUỘC ghi chú) → bước/tab `issue` "Sự cố giao hàng" (nhóm ngoại lệ); ✅ Xử lý xong sự cố gỡ cờ, tự gỡ khi đơn Đã giao / Hoàn thành / Hủy / Thất bại / Hoàn tiền. Popup thao tác (`assets/order-flow-popup.js`) hiện chip mẫu ghi chú theo kind từ option `thean_flow_note_templates` (Cài đặt ERP → Xử lý đơn → "Mẫu ghi chú thao tác", 1 mẫu/dòng). Nút 📝 Ghi chú (nội bộ / gửi khách) ở mọi dòng đơn ô tìm / board / `/quan-ly-don` → task `add_note`; sự cố → task `delivery_issue` (AJAX thean_order_workspace). Test: `php tests/order-flow-notes.php`. Tab "Sự cố giao hàng" (đỏ), "Thiếu chứng từ XK" (cam), "Chờ thu COD" (vàng đậm) trên board có viền + chữ nổi bật (CSS theo `[data-thean-ous-tab="issue|proof|cod"]`).

## Test

Chạy thuần PHP, không cần WordPress: `php tests/<file>.php`
- `order-universal-search.php`, `export-bulk-web.php`, `lucky-wheel-coupon-lookup.php`, `offers-test.php`, `offers-lark-notify.php`, `lark-core.php`, `warehouse-export-lark.php`,
  `offers-structured-test.php`, `landing-template-test.php`.
- `my-offers.php`, `loop-offers-button.php`, `loop-rule-price.php`, `offers-order.php`; UI thanh đáy / "Ưu đãi của tôi": `node tests/site-footer-menu-ui.cjs` (cần jsdom).
- Popup thao tác nhanh (order-quick-modals.php): `node tests/order-quick-modals-ui.cjs` (cần jsdom + php).
- `create-order-ai-address.php` (quy đổi địa chỉ AI tạo đơn); UI popup "Chỉnh đơn" chế độ AI: `node tests/create-order-ai-ui.cjs` (cần jsdom).

## Quy ước

- Commit & push thẳng `main` (như theanmarket).
- Comment / commit message bằng tiếng Việt, kiểu `feat(orders): …`, `fix(…)`.
- `custom-functions/firebase_credentials.json` không commit (.gitignore).

## Tab 🏦 Sepay ở `/quan-ly-don`

`thean_order_portal_render_sepay_tab()` (order-portal.php, giống hệt bản theanmarket; chỉ khi có plugin eim + `manage_woocommerce`):
iframe widget hub mở bằng tab `sepay_all` = Hàng đợi Sepay hiện đủ mọi giao dịch, không lọc theo site (cần eim ≥ 0.16.96 ở hub). URL mang token
dùng 1 lần → `order-workspace-tabs.js` gọi AJAX `child_theme_hub_sepay_url` (payment-hub-link.php) lúc mở tab / "↻ Tải lại"; widget báo
`eim:payment-confirmed` → quay lại tab Đơn hàng thì board tự làm mới.

## Board tab Hoàn hàng ở `/quan-ly-don`

Khung `[order_return_management]` (shortcodes/shortcode-order-return-manager.php) ngay dưới danh sách đơn hoàn:
`thean_order_portal_render_returns_panel()` (order-portal.php, giống hệt bản theanmarket), iframe công cụ `?thean_order_tool=returns`
(`thean_order_return_tools()` ở order-product-tools.php); chỉ `manage_woocommerce`, iframe tải lần đầu mở tab; xử lý hoàn trong khung → board tự Làm mới.

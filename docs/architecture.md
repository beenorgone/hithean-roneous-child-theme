---
audience: dev
owner: người phụ trách kỹ thuật theme
reviewed: 2026-11-12
applies_to: [custom-functions/bootstrap.php, functions.php, custom-functions/core/module-loader.php, custom-functions/core/admin.php, custom-functions/core/template-router.php]
---

# Kiến trúc theme — đọc trước khi sửa `custom-functions/`

> Các khối có `<!-- AUTO:… -->` do `tools/docs-sync.php` sinh lại — đừng sửa tay.
> Bảng route (`<!-- ROUTES -->`) viết tay nhưng **được máy kiểm**: thêm route vào code mà quên ghi ở
> đây thì `php tools/docs-sync.php --check` đỏ (git hook chặn commit).

Đọc kèm: [`code-map/`](code-map/README.md) (tìm hàm, AJAX, hook) · [`README.md`](README.md) (mục lục docs) ·
[`erp-mesh.md`](erp-mesh.md) (kho dùng chung với Theanmarket). Cấu trúc nạp giống theanmarket (`core/module-loader.php`).

---

## 1. Đường đi của một request

```
functions.php                               define HITHEAN_THEME_DIR
  └─ custom-functions/bootstrap.php         ← CHỈ require, không logic
       core/template-router.php             single-* / archive-* / template-* trong singles/ archives/ templates/;
                                            landing pages/{slug}/{slug}.html qua template-landing-page.php
       core/enqueue.php · core/admin.php · core/email-overrides.php · core/upload-guard.php · core/public-file-guard.php
       core/module-loader.php               TOÀN BỘ what-loads-when
            1) $general_includes (mọi request; thiếu file → error_log khi WP_DEBUG) + ai-settings (wp-admin)
            2) social-display — trang chủ, trang SP, page landing chỉ định (filter hithean_social_display_page_slugs)
            3) editor tools — bài có <table> / ivar-content-button
            4) tpc_loader_modules() — module theo path / conditional tag / shortcode trong nội dung / admin / AJAX / cron
```

- `core/admin.php` — `load_custom_admin_files()` (admin_enqueue_scripts): nạp file theo màn danh sách / sửa đơn,
  order-list-lazy, order-navigation.
- Hook lẻ không đặt trong loader: `core/site-guards.php` (CSP cho hub nhúng `/tien-ich-admin`),
  `integrations/eim-order-shipper.php` (shipper / mã vận đơn / meta xuất kho cho hóa đơn eim).

## 2. tpc_loader

Mỗi module `['id', 'file', 'paths'|'path_groups'|'paths_filter', 'condition', 'shortcodes', 'ajax_actions', 'admin', 'cron_hooks']`.

| Khóa | Nạp khi | Thời điểm |
|---|---|---|
| `paths` / `path_groups` | đường dẫn khớp (frontend) | `wp` (PHP_INT_MAX − 1) |
| `condition` | callable trả true (`tpc_cond_is_product`, `is_front_page`…) | `wp` |
| `shortcodes` | trang đơn có shortcode trong nội dung (dò chuỗi, không dùng `has_shortcode`) | `wp` |
| `admin` | wp-admin, không AJAX | ngay khi include |
| `ajax_actions` | admin-ajax có action khớp | `init` priority 1 |
| `cron_hooks` | request WP-Cron | `init` priority 1 |

**Thêm AJAX action mới thì phải khai vào `ajax_actions` của module**, nếu không handler không được nạp (WP trả "0").

## 3. Route frontend

Viết tay, máy kiểm (`docs-sync --check`): mọi `paths` / `tpc_loader_path_groups()` của module-loader và
`define('…ROUTE…')` phải có dòng ở đây.

<!-- ROUTES -->
| Route | Nạp gì | Ghi chú |
|---|---|---|
| `/tao-don/` | `order-creator/create-order-for-customer.php` (`ORDER_CREATOR_ROUTE`, luôn nạp) | Tạo đơn hộ khách, full-page |
| `/quan-ly-don/` | tpc `order_portal` | Quản lý đơn ngoài wp-admin |
| `/tien-ich/` | nhóm `tools` | So sánh SP, protein calculator |
| `/tien-ich-admin/` | nhóm `tools` | Công cụ nội bộ; hub IVAR nhúng iframe (CSP ở site-guards) |
| `/tra-cuu/`, `/so-sanh/`, `/tra-cuu/so-sanh/` | nhóm `compare` | So sánh sản phẩm |
| `/protein-calculator/`, `/tien-ich/protein-calculator/` | nhóm `protein` | Tính protein |
| `/an-new-chapter/`, `/an-new-chapter-b2b/`, `/an-new-chapter-b2b-organic/`, `/an-new-chapter-affiliate/` | nhóm `protein`, `anc_landing` (+ `anc_new_partner_offer` ở `/an-new-chapter-b2b/`) | Landing ANC (`pages/{slug}/`) |
| `/doi-tac-yeast-hero/` | nhóm `anc_landing` | Landing đối tác Yeast Hero |
<!-- /ROUTES -->

## 4. Tầng

<!-- AUTO:layers -->
| Thư mục | File PHP | Dòng |
|---|---:|---:|
| `(file gốc)` | 2 | ~700 |
| `admin/` | 4 | ~800 |
| `auth/` | 4 | ~500 |
| `core/` | 20 | ~4.500 |
| `integrations/` | 3 | 90 |
| `marketing/` | 20 | ~5.500 |
| `shortcodes/` | 22 | ~15.000 |
| `woocommerce/checkout/` | 5 | ~2.600 |
| `woocommerce/orders/` | 37 | ~15.500 |
| `woocommerce/products/` | 17 | ~7.900 |
<!-- /AUTO:layers -->

## 5. Module

<!-- AUTO:modules -->
- [`marketing/lucky-wheel/`](code-map/marketing-lucky-wheel.md) — Vòng quay may mắn: cài đặt, widget, dọn mã.
- [`marketing/offers/`](code-map/marketing-offers.md) — Trang ưu đãi /uu-dai/: lưu trữ, bóc tách AI, thẻ ưu đãi, structured data, worker nền.
- [`woocommerce/orders/order-creator/`](code-map/woocommerce-orders-order-creator.md) — Trang Tạo đơn hộ khách /tao-don/.
<!-- /AUTO:modules -->

## 6. Thêm tính năng ở đâu

| Việc | Đặt ở |
|---|---|
| Hook chạy toàn site, nhẹ | file riêng + `$general_includes` (module-loader). Không truy vấn nặng / gọi ngoài ở thân file |
| Trang / công cụ / shortcode riêng | mục mới trong `tpc_loader_modules()` (`paths`, `condition`, `shortcodes`, `ajax_actions`) |
| Gửi thông báo Lark (nhóm chat) | Khai kênh `child_theme_lark_register_channel()` + gửi `child_theme_lark_send()` ở `core/lark.php` (giống hệt 2 site); cài đặt ở Cài đặt ERP → Lark |
| Màn wp-admin cụ thể | `load_custom_admin_files()` (core/admin.php) theo screen |
| Landing tĩnh | `pages/{slug}/{slug}.html` + page dùng `template-landing-page.php`; module riêng → `anc_landing` / `paths` |
| Port từ theanmarket | đọc bảng "Khác biệt với theanmarket" trong CLAUDE.md (meta `order_*`, hàm `hithean_*`) |

Luật chung: nonce + capability cho mọi `admin_post_*` / `wp_ajax_*`; sanitize vào, escape ra; meta đơn hàng dùng API
HPOS (`$order->get_meta`, `wc_get_orders`); không sửa `custom-functions/vendor/`.

<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `integrations`

> Adapter ngoài theme: MCP liên site (mcp-site-link), hóa đơn eim (shipper / mã vận đơn / meta xuất kho), GHTK.

4 file · ~200 dòng · 5 hàm (40% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| REST | `ivar-recruit/v1/refresh` | ivar-recruitment.php |
| cron | `hithean_recruit_refresh` | ivar-recruitment.php |
| shortcode | `[ivar_tuyen_dung]` | ivar-recruitment.php |

## Hook khu này lắng nghe

`devvn_ghtk_create_order_products` · `devvn_ghtk_shipping_methob` · `eim_export_meta_map` · `eim_order_shipper_label` · `eim_order_shipper_tracking_code` · `erp_mesh_activity_hooks` · `erp_mesh_order_export_confirmed` · `hithean_recruit_refresh` · `init` · `rest_api_init`

## File

### `integrations/eim-order-shipper.php`

### `integrations/erp-mesh-adapter.php`

Hithean policy for the mcp-site-link plugin. Group, peers and transport are configured in wp-config.

### `integrations/ivar-recruitment.php`

Landing Tuyển dụng /tuyen-dung/ — nội dung soạn ở ERP ivarvietnam (/hr/?view=recruit), hithean chỉ hiển thị.

| Hàm | Mô tả |
|---|---|
| `hithean_recruit_cache()` | — |
| `hithean_recruit_allowed_html()` | Thẻ / thuộc tính được phép trong HTML landing nhận từ IVAR. |
| `hithean_recruit_fetch()` | Lấy bản mới từ IVAR. true = đã có bản mới nhất; WP_Error = lỗi (bản cũ giữ nguyên). |

### `integrations/plugin-devvn-ghtk-tweaks.php`

| Hàm | Mô tả |
|---|---|
| `devvn_custom_ghtk_shipping_methob($methob)` | — |
| `custom_devvn_ghtk_create_order_products($products, $main_class, $order)` | — |


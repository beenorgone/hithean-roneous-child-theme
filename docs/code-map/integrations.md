<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `integrations`

> Adapter ngoài theme: MCP liên site (mcp-site-link), hóa đơn eim (shipper / mã vận đơn / meta xuất kho), GHTK.

3 file · 90 dòng · 2 hàm (0% có docblock)

[← Mục lục bản đồ code](README.md)

## Hook khu này lắng nghe

`devvn_ghtk_create_order_products` · `devvn_ghtk_shipping_methob` · `eim_export_meta_map` · `eim_order_shipper_label` · `eim_order_shipper_tracking_code` · `erp_mesh_activity_hooks` · `erp_mesh_order_export_confirmed`

## File

### `integrations/eim-order-shipper.php`

### `integrations/erp-mesh-adapter.php`

Hithean policy for the mcp-site-link plugin. Group, peers and transport are configured in wp-config.

### `integrations/plugin-devvn-ghtk-tweaks.php`

| Hàm | Mô tả |
|---|---|
| `devvn_custom_ghtk_shipping_methob($methob)` | — |
| `custom_devvn_ghtk_create_order_products($products, $main_class, $order)` | — |


# MCP ERP liên site

Lõi kết nối (server MCP, auth, peer client, đọc đơn, nhật ký phát sinh) nằm trong plugin
**MCP Site Link** (`mcp-site-link`, repo riêng). Cấu hình nhóm site, cách triển khai và
toàn bộ contract: xem README và `docs/DEPLOY.md` của plugin.

Theme này chỉ giữ adapter `custom-functions/integrations/erp-mesh-adapter.php`, adapter chỉ chạy khi plugin bật.

## Vai trò của Hithean trong nhóm

Hithean là **cửa hàng không kho**: bán hàng do IVAR sản xuất, đơn được xuất từ kho
Theanmarket (kho dùng chung). Trong `ERP_MESH_GROUP`, Hithean là `from` của quan hệ
`fulfill` sang Theanmarket. Vì vậy plugin tự bật **nhật ký phát sinh** (`{prefix}erp_mesh_activity`) ở site này:
- nhật ký bắt cả đơn cũ vừa xác nhận xuất hoặc vừa hoàn kho;
- mỗi request ghi gộp một câu;
- dòng cũ hơn 120 ngày tự được xoá.

Event index chỉ bắt đầu ghi sau khi plugin bật. Đơn xuất trước thời điểm đó phải đối chiếu
riêng ở lần chạy đầu.

## Adapter

- `erp_mesh_order_export_confirmed`: chỉ đơn đã xác nhận xuất qua workflow kho (`export_confirmed_by`).
- `erp_mesh_activity_hooks`: thêm `hithean_warehouse_export_confirmed`, vì xác nhận xuất có thể không
  lưu lại đối tượng đơn.
- Hoàn tiền không chứng minh hàng đã về kho. Chỉ `_restock_refunded_items` do WooCommerce ghi khi
  hoàn kho thực tế mới làm Theanmarket cộng lại tồn.

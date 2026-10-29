# MCP ERP liên site và đối soát nội bộ

## Điểm vào và phạm vi nạp

| Site | MCP endpoint | Công cụ |
|---|---|---|
| IVAR | `/wp-json/mcp/ivar-erp-records` | Orders + các records IVAR đang có |
| Hithean | `/wp-json/mcp/hithean-erp` | Orders |
| Theanmarket | `/wp-json/mcp/theanmarket-erp` | Orders + preview nhập/xuất liên site |

Các Ability chung: `erp-mesh/orders-list`, `orders-get`, `peer-orders`. Tên MCP
tương ứng thay `/` bằng `-`, ví dụ `erp-mesh-orders-get`. Tasks vẫn thuộc IVAR.
Theanmarket thêm `erp-mesh/preview-import` và `erp-mesh/preview-export`.
Không có MCP tool chốt chứng từ/cập nhật tồn; chốt tại UI bằng tài khoản quản lý.

Khách mua hàng **không nạp bất kỳ file mesh nào**: trang chủ, sản phẩm, giỏ hàng,
checkout, tài khoản và Store API `/wc/store/*` đều nằm ngoài gate, kể cả shopper
đã đăng nhập. Gate nhỏ nằm ngay trong bootstrap/module-loader của theme.

`erp-mesh-loader.php` chỉ nạp ở admin, MCP, cron, WP-CLI, WC REST quản trị
`/wc/v1..3` hoặc request dùng Application Password. Dispatcher chỉ đăng ký hook;
workflow đầy đủ chỉ nạp ở MCP, trang `thean-mesh`, POST `thean_mesh_action`, hoặc
khi **đúng** hook cron `thean_mesh_collect_daily` chạy. Cron thông thường không
nạp workflow. Các thay đổi đơn ở đường quản trị chỉ nạp event index khi thực sự
có phát sinh. Guard nhỏ bảo vệ đơn gộp chỉ nằm trên các đường quản trị này.

## Cấu hình máy chủ

1. Cả ba site cần WordPress Abilities API và plugin WordPress MCP Adapter đang
   hoạt động. Dùng phiên bản Adapter hỗ trợ custom server và HTTP transport.
2. Sau khi đưa code lên mỗi site, mở wp-admin bằng tài khoản quản lý một lần để
   tạo role `erp_mesh_reader` và bảng event index InnoDB. Hoặc quản trị viên chạy
   WP-CLI với đúng `--path` để gọi `erp_mesh_install_infrastructure()`.
3. Tạo user tích hợp riêng cho từng chiều kết nối trên **site đích**, gán role
   `erp_mesh_reader`, tạo Application Password riêng. Role chỉ có `read` và
   `erp_mesh_read_orders`, không có quyền sửa đơn. Trên IVAR còn phải cấp quyền
   ERP/record qua ACL hiện có và duyệt credential scope `erp_records` ở App
   Password Guard. Không mở rộng approval Tasks cũ.
4. Nếu một agent cần đọc peer qua site hiện tại, cấp thêm capability
   `erp_mesh_read_peers` cho riêng user đó. Preview qua MCP cần
   `erp_mesh_preview_operations`; preview nhập còn yêu cầu quyền đọc seller.
   UI/chốt luôn yêu cầu `manage_woocommerce` và quyền tạo PO/IR của module.
5. Khai báo `ERP_MESH_PEERS` trong wp-config hoặc secret configuration riêng của
   **site gọi**. Ví dụ Theanmarket cần hai peer IVAR và Hithean:

```php
define('ERP_MESH_PEERS', [
    'ivar' => [
        'endpoint' => 'https://ivarvietnam.com/wp-json/mcp/ivar-erp-records',
        'username' => getenv('ERP_PEER_IVAR_USER'),
        'password' => getenv('ERP_PEER_IVAR_APP_PASSWORD'),
    ],
    'hithean' => [
        'endpoint' => 'https://hithean.com/wp-json/mcp/hithean-erp',
        'username' => getenv('ERP_PEER_HITHEAN_USER'),
        'password' => getenv('ERP_PEER_HITHEAN_APP_PASSWORD'),
    ],
]);
```

Hithean/IVAR có thể khai báo peer `theanmarket` với endpoint Theanmarket ở bảng
trên. Mỗi kết nối có credential độc lập; thu hồi ở site đích. Không đưa secrets
vào theme, Git, JS, tham số MCP hoặc chat. Plugin bảo mật của site phải cho phép
Application Password của user đã duyệt; theme không tự tắt các chặn đó.

Client chỉ gọi `orders-list`/`orders-get` đã định sẵn, dùng HTTPS, không theo
redirect, giới hạn timeout/body, bắt tay MCP và kiểm tra JSON-RPC/session.
Không nhận URL/tool tùy ý. Dedicated integration users bị giới hạn vào endpoint
MCP riêng; WordPress capabilities và ACL tiếp tục được kiểm ở execute callback.

## Luồng IVAR → PO + phiếu nhập Theanmarket

Mở **WooCommerce → Đối soát liên site** hoặc thẻ tương ứng tại `/erp/`.
Nhập ID đơn bán IVAR và chọn NCC IVAR trong danh mục seller Theanmarket.
Hệ thống lấy đơn nguồn, khớp SKU chính xác hoặc mapping đã khai, hiển thị hệ số
quy đổi, số lượng, giá, tổng và phần thuế/phí bổ sung. Không ghép theo tên gần
giống. Thiếu mapping, hàng đã hoàn, MO hoặc đơn không hợp lệ thì dừng để sửa.

Sau khi kiểm preview và chốt:

- Tạo đúng một PO `sourcing_order`, trạng thái `draft`, với schema CSV Theanmarket.
- Tạo đúng một `inventory_receipt`, liên kết `inventory_receipt_sourcing_order`,
  trạng thái `pending`; sản phẩm/số lượng đọc ngược qua domain phải khớp PO.
- Chưa cộng tồn. Người nhận kiểm LOT, HSD, kho và số lượng thực nhận, sau đó duyệt
  qua workflow phiếu nhập hiện có. Phiên bản này lập bộ chứng từ nhập đầy đủ,
  không tự chia nhiều đợt nhận cho cùng một SO.

Giá dòng PO lấy từ tiền hàng sau giảm giá / số lượng đã quy đổi; chênh giữa tổng
đơn và tổng dòng hàng được ghi thành chi phí bổ sung **một lần**. Không thêm VAT
lần hai. Sổ giữ source ID, preview, người chốt và hai ID chứng từ để chạy lại
không tạo trùng. Không tự sửa đơn IVAR; liên kết nguồn lưu tại sổ Theanmarket.

## Luồng Hithean → đơn gộp trừ tồn 0đ

Chọn kỳ tối đa 7 ngày, tối đa 500 đơn mới/phát sinh. Khi chạy lần đầu, chọn kỳ
chưa trừ tồn thủ công ở Theanmarket. Collector kết hợp ngày tạo và event index,
nên bắt cả đơn tạo từ lâu nhưng vừa xác nhận xuất. Event index bắt đầu ghi sau
khi provision; lần đầu cần đối chiếu riêng các đơn cũ trước thời điểm đó.

Chỉ tính đơn đã xác nhận xuất qua workflow Hithean (`export_confirmed_by`).
Đơn giao một phần cần xử lý riêng vì chưa có hợp đồng số lượng xuất từng dòng.
Hoàn tiền không tự chứng minh hàng đã về kho: chỉ `_restock_refunded_items` do
WooCommerce ghi khi hoàn kho thực tế mới tạo chênh lệch cộng lại. Module hoàn
ảnh hiện tại chỉ cập nhật trạng thái/ảnh; phải xác nhận hoàn kho thực tế tại
nguồn để dữ liệu tồn được phản ánh.

Preview hiển thị chênh lệch so với lần chốt trước, mapping và bảng kê từng đơn.
Chốt tạo **một đơn WooCommerce `da-ghi-nhan` cho batch**, mọi subtotal/total/tax
bằng 0, `_eim_internal=1`. Dòng âm biểu diễn điều chỉnh hoàn kho, vẫn 0đ.
Không dùng `_reduced_stock`: stock được cập nhật đúng một lần bởi adapter và
sổ nguồn. Hủy/refund của WooCommerce không được tự cộng/trừ tồn đơn gộp.
Guard chặn sửa, đổi trạng thái, refund, xóa dòng, trash/delete đơn gộp trên các
đường quản trị; chỉnh sửa phải qua lần đối soát tiếp theo.

Lịch hàng ngày mặc định tắt. Người có quyền chọn ngày bắt đầu rồi bật; lịch chỉ
tạo preview, không tự chốt. Mốc tiến sau khi lưu preview thành công, có chồng
một ngày và bắt kịp dần sau gián đoạn. Mỗi lượt còn kiểm tra luân phiên 20 đơn
đã đối soát cũ để bắt hoàn kho muộn. WP-Cron cần lượt truy cập hoặc system cron.
Nếu user bật lịch mất quyền quản lý, collector dừng. Lỗi không tiến mốc.

## An toàn khi chốt và giới hạn

- Preview bất biến, tối đa 100 bản đang chờ; hết 24 giờ cần đọc lại nguồn.
  Bỏ/hết hạn vẫn giữ lịch sử và có thể lập preview mới từ cùng dữ liệu.
- Chốt đọc lại toàn bộ source IDs, mapping và trạng thái đã áp dụng. Nguồn đổi,
  mất quyền, bị xóa hoặc thiếu dữ liệu thì từ chối, không chốt một phần.
- Named lock chặn hai batch cùng chốt. InnoDB transaction bao trùm chứng từ,
  tồn và sổ đối soát. Khóa stock theo owner, kiểm tồn gộp cho biến thể dùng tồn
  chung. Không đủ tồn khi không cho backorder thì dừng.
- Mọi bảng tham gia phải InnoDB; có kiểm cả HPOS và nhật ký EIM nếu hoạt động.
  Lỗi rollback dữ liệu trong giao dịch và dọn cache liên quan. Plugin bên ngoài
  có thể có side effect ngoài DB; cần kiểm tra trên staging trước production.
- Không trả tên, email, số điện thoại, địa chỉ, ghi chú, ảnh hay raw meta. Tên
  hàng từ catalogue, không lấy free-form tên dòng khách nhập. Output orders có
  JSON Schema đóng; dữ liệu remote còn được whitelist trước khi lưu manifest.

## Xác minh và phát hành

`php tests/test-erp-mesh.php` chạy tại mỗi theme. Tại IVAR thêm
`php tests/test-erp-mesh-loader.php` (đối chiếu ba repo cạnh nhau). Tại Theanmarket
chạy `php tests/cross-site-operations.php`, `php tests/shopee-export.php` và lint
các file thay đổi. Workflow test là failure harness có rollback mô phỏng, không
phải bằng chứng transaction production đã chạy.

Trước bật thật: sao lưu DB, deploy đủ bộ file/loader trên cả ba site, provision
hạ tầng, khai peer và credential ngoài Git; kiểm authenticated discovery,
orders-get/list và đúng vai trò. Trên staging thử thiếu quyền, credential sai,
timeout, mapping sai, preview cũ, chốt hai lần, hoàn kho và lỗi giữa chừng.
Chốt một bộ chứng từ mẫu rồi đối chiếu field/stock/journal trước khi bật lịch.
Không coi việc endpoint trả 401/403 hoặc plugin đã cài là kết nối thành công.

Thư mục mesh và dispatcher giống nhau ở ba repo; source domain adapter cũng
giống nhau. Thay đổi shared contract cần cập nhật đủ ba bản và chạy kiểm tra
đối chiếu loader. Adapter nghiệp vụ và UI chỉ ở Theanmarket.

Suggested commit message:

```text
feat(mcp): add Hithean operational order server for ERP reconciliation

- Expose permissioned order projections and allowlisted MCP peer reads
- Track warehouse confirmations and late order or restock activity
- Keep mesh modules out of shopper pages and WooCommerce Store API traffic
- Add scoped integration-user authentication and protocol checks
```

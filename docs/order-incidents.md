# Sự cố đơn hàng

`/quan-ly-don/` ở Hithean và Theanmarket dùng chung `order-issues.php` và `assets/order-issues.{js,css}`. Hai site xét dữ liệu riêng. Mọi sự cố mới dùng meta WooCommerce; không thêm bảng hay database.

## Nguồn dữ liệu

- Registry `thean_order_issue_types` trong code khai nhãn, detector/provider, hướng dẫn, chế độ chặn và thao tác được phép. Không có trình cấu hình loại sự cố trên UI.
- Mỗi instance mới là một meta `_thean_issue_{id}`: `id`, `type`, `status` (`open|working|resolved`), `mode` (`warn|review|resolve`), mô tả, bằng chứng ID đơn/lý do, dấu SHA-256 và người/thời gian thao tác. Một đơn có nhiều instance; không lưu cả danh sách vào một mảng. Instance thủ công UUID; instance tự động cố định theo loại/đơn.
- `_thean_incidents_open`, `_thean_incidents_blocked`, `_thean_incidents_types`, `_thean_incidents_group` là chỉ mục lọc có thể dựng lại. Chặn luôn đọc lại nguồn, không tin chỉ mục.
- Sự cố giao hàng dùng nguyên meta và API của `order-flow-notes.php`; adapter đưa vào popup/bộ lọc chung. Điều kiện báo/gỡ và hành vi giao hàng giữ nguyên; tab chung đổi thành Sự cố và có nhóm Sự cố giao hàng. Không sao chép sang instance khác.
- Ghi chú riêng tư của đơn giữ nhật ký; popup hiển thị các ghi chú sự cố trong 50 ghi chú gần nhất. Instance và ghi chú được giữ cùng đơn. Không sao chép email/SĐT vào instance, options hoặc log.

## Nhận diện đơn gần nhau

Từ hai đơn được tạo cách nhau không quá 24 giờ, khớp tài khoản khách ID >0, SĐT thanh toán/giao hàng hoặc email thanh toán. Chuẩn hóa SĐT bỏ phân cách, quy đầu số Việt Nam `0/84/+84/0084`; email bỏ khoảng trắng ngoài và không phân biệt hoa thường. Không khớp tên, thông tin trống, hoặc nối chuỗi qua đơn trung gian. Không giới hạn đơn liên quan theo trang/tab/bộ lọc đang xem.

Bỏ đơn hủy, thất bại, hoàn tiền, nháp/thùng rác, chứng từ `chua-ghi-nhan`/`da-ghi-nhan`, loại khác `shop_order` và đơn gộp Shopee `_spx_date`. Hoàn thành vẫn được xét khi chưa hủy/hoàn tiền.

Xác nhận **Đã kiểm tra đơn này** lưu theo từng đơn và bằng chứng hiện tại. Thêm/bớt đơn liên quan hoặc đổi danh tính/ngày tạo sẽ yêu cầu kiểm tra lại; chuyển bước vận hành bình thường không làm mất xác nhận. Điều kiện phát hiện không còn thì tự kết thúc instance; tái xuất hiện sẽ mở lại.

## Hai cảnh báo quá 7 ngày

- **7 ngày chưa giao xong:** quá 7 × 24 giờ từ ngày tạo, chưa Đã giao/Hoàn thành, không Đặt trước và không có tag thiếu sản phẩm. Giao một phần vẫn tính là chưa giao xong. Các loại đơn bị loại ở trên cũng không được cảnh báo.
- **7 ngày GHTK chưa giao xong:** quá 7 ngày từ mốc bắt đầu Đang giao đã ghi, xác định đơn vị GHTK và có căn cứ đã bàn giao (đang giao/giao một phần, tạm giữ từ bước đang giao hoặc tag Đã nhập kho vận chuyển). Chỉ chọn GHTK/tạo vận đơn không đủ. Không áp dụng ngoại lệ thiếu SP của cảnh báo thứ nhất vào cảnh báo GHTK.
- Mốc GHTK ở Hithean: `order_export_date`, rồi `export_date` của đơn cũ; Theanmarket: `export_date`. Không dùng `order_ship_date` nhập tay hoặc ngày tạo vận đơn. Ngày không hợp lệ/không có thì giữ là chưa biết, không đoán. Ngày chỉ có phần ngày được hiểu ở múi giờ website.
- `order-issues-site.php` sở hữu ánh xạ field và danh mục tag thiếu SP; metabox và detector cùng đọc danh mục đó. Hithean dùng `Thiếu SP`; Theanmarket dùng các giá trị `Thiếu sản phẩm`, `Thiếu sản phẩm, chờ mua`, `Thiếu sản phẩm, đã đặt mua`.
- Hai loại này **chỉ cảnh báo**, không chặn giao hàng. Nhận xử lý không xóa cảnh báo; khi đơn không còn thỏa điều kiện thì tự kết thúc. Popup hiển thị mốc tính và số ngày, dấu bằng chứng không đổi mỗi ngày.

## Nhóm trong flow Sự cố

Tab **Sự cố** là hàng đợi song song với bước vận hành; một đơn có thể có mặt ở bước chính và tab Sự cố. Mỗi đơn hiện đúng một lần trong tab Sự cố, theo **nhóm chính**: ưu tiên loại đang chặn trước, sau đó thứ tự GHTK quá hạn → đơn quá hạn → sự cố giao hàng → đơn gần nhau → thủ công. Khi lọc một loại cụ thể, đơn được nhóm theo loại đang lọc. Popup/nhãn vẫn hiển thị tất cả sự cố; xác nhận một sự cố có thể khiến đơn chuyển nhóm.

Số đếm và phân trang tính theo đơn duy nhất; header nhóm không phải đơn. Mỗi nhóm có màu và nút Xem thêm khi chưa tải đủ; nhóm rỗng được ẩn. Bộ lọc ngày, mốc khóa đơn cũ và tách đơn nội bộ vẫn áp dụng. Cập nhật một dòng giữ lựa chọn, scroll và các dòng khác. Nhãn Sự cố đặt ở phần thông tin đơn luôn thấy cả trên mobile.

## Thao tác và chặn

Người có `edit_shop_orders` được xem; nhân viên có `manage_woocommerce` báo sự cố thủ công, chọn chỉ cảnh báo hoặc chặn tới khi xử lý xong. Mô tả và kết quả xử lý bắt buộc; nhận xử lý không tự gỡ chặn. Một sự cố còn chặn thì đơn vẫn bị chặn. Administrator không có ngoại lệ.

Chặn trước thao tác thủ công tiến tới: xác nhận khách, tiếp tục đơn, xác nhận xuất kho, đăng GHTK qua filter chuẩn bị sản phẩm và đổi trạng thái tiến tới (cả native save/bulk/workspace). Không tự phát lại thao tác sau khi xác nhận. Cho xem/sửa thông tin, ghi nhận tiền, bổ sung ảnh, tạm giữ, hủy/hoàn và đặt trước. Cron, REST, WP-CLI và webhook giữ hành vi tự động hiện có theo `thean_order_change_source()`.

Popup Material dùng native dialog; mọi nội dung động dựng bằng textContent. Nhãn không thuộc WordPress notice nên không bị tùy chọn Ẩn thông báo che. Bộ lọc Sự cố áp dụng đồng thời cho board và kết quả tìm kiếm, giữ bước vận hành. URL `thean_issue=open|blocked|nearby_orders|order_overdue|ghtk_overdue|delivery|manual` giữ lựa chọn khi chia sẻ.

## Tải dữ liệu và khởi tạo

Module được general loader nạp mọi request để AJAX `thean_order_issues`, cron và guard luôn sẵn sàng. Endpoint kiểm nonce rồi quyền, khóa MySQL theo prefix/đơn, đọc lại dữ liệu và đối chiếu token trước cập nhật. Request cũ nhận HTTP 409; lỗi đọc/khóa không được xem như không có sự cố.

Một truy vấn lấy ứng viên cho lô đơn theo các khoảng ngày đã gộp, tối đa 5.000 ứng viên. Lô rộng vượt ngưỡng được thử lại từng đơn; một đơn vẫn vượt ngưỡng báo rõ và chặn thao tác cần kiểm tra. HPOS và legacy có adapter riêng; không có truy vấn tìm liên quan từng dòng trong lô thông thường.

Cron `thean_order_issues_changed` kiểm đơn vừa lưu và các đơn liên quan cũ/mới; hook meta bắt thay đổi giao hàng hoặc danh tính qua đường legacy. Cron `thean_order_issues_scan` quét khởi tạo 20 đơn/lượt từ mốc khóa đơn cũ, rồi tiếp tục quét các đơn chưa giao xong theo chu kỳ một giờ để bắt mốc quá 7 ngày dù không có ai lưu đơn. Các lô cách nhau 10 giây; khóa MySQL bảo vệ cursor khi nhiều worker chạy cùng lúc. Options chỉ giữ version/cursor; không chứa hàng đợi ID hoặc thông tin khách. Khởi tạo chưa xong hiện thông báo, bộ lọc có thể chưa đầy đủ. Đọc dòng và thao tác bị chặn luôn kiểm tra lại đơn trực tiếp.

## Kiểm tra

Chạy `php tests/run.php` (tự gom test PHP/CJS/Python). Bộ chuyên biệt: `php tests/order-issues.php`, `node tests/order-issues-ui.cjs`, cùng các test universal-search, workflow, portal và HPOS. Test parity giữ byte của module/CSS/JS chung. Fixture/DOM test không chứng minh kết nối WordPress, khóa MySQL hoặc plugin GHTK trên production; cần nghiệm thu đăng nhập trên cả hai site sau deploy.

Thêm detector hoặc thay đổi điều kiện cần quét lại lịch sử: tăng `THEAN_ISSUE_SCAN_VERSION`; cursor sẽ tự khởi tạo lại.

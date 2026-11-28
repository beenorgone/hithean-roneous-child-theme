# Chặn khách đặt hàng tại checkout

Danh sách của site: `custom-functions/woocommerce/checkout/checkout-blocklist-policy.php`.
Logic dùng chung Hithean ↔ Theanmarket: `checkout-blocklist.php` cùng thư mục; sửa ở Hithean rồi chép nguyên file
và `tests/checkout-blocklist.php` sang Theanmarket. Test kiểm tra hai bản logic giống nhau khi có cả hai checkout.

Loader nạp policy trước guard trong `$general_includes`, bao gồm request `wc-ajax=checkout`.
Hook `woocommerce_after_checkout_validation` kiểm dữ liệu billing đã được WooCommerce xử lý, thêm lỗi trước khi
WooCommerce tạo đơn / thanh toán. Khớp **SĐT hoặc email** là chặn; SĐT bỏ dấu phân cách và đổi đầu `+84` / `84` /
`0084` thành `0`, email bỏ khoảng trắng đầu cuối và so không phân biệt hoa thường. Không truy vấn DB hay ghi log PII.
Danh sách Theanmarket giữ cả các liên hệ đã chặn trước đây; Hithean chỉ thêm liên hệ được yêu cầu.

Phạm vi: Classic Checkout (gồm AJAX). Đây không phải khóa tài khoản hay chặn tạo đơn hộ khách từ admin,
REST API hoặc Checkout Blocks / Store API.

Kiểm tra: `php tests/checkout-blocklist.php`. Chạy ở cả hai repo; kiểm tra chuẩn hóa, từng liên hệ độc lập,
giữ danh sách cũ và vị trí loader luôn nạp. Các kiểm tra này không tạo đơn thật và không chứng minh đã triển khai live.

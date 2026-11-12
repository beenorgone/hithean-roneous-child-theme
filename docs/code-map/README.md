<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# Bản đồ code

Mỗi khu một trang: điểm vào (AJAX, admin-post, REST, shortcode, CPT, cron), hook phát ra và lắng nghe, danh sách hàm kèm dòng đầu docblock. Dùng để **tìm chỗ sửa** trước khi đọc code — không thay được việc đọc code.

Cột *docblock* thấp = khu khó đọc với người mới và với AI: viết docblock cho hàm public là cách rẻ nhất để trang này có ích hơn.

| Khu | File | Dòng | Hàm | Docblock | Mô tả |
|---|---:|---:|---:|---:|---|
| [`admin`](admin.md) | 4 | ~800 | 19 | 5% | wp-admin: hướng dẫn shortcode, metabox bài viết, trang plugins, editor tools. |
| [`auth`](auth.md) | 4 | ~500 | 7 | 14% | Đăng nhập OTP email / SMS Firebase, email lỗi, email tác vụ. |
| [`bootstrap`](bootstrap.md) | 2 | ~700 | 12 | 8% | Điểm vào: bootstrap.php chỉ require core/* rồi core/module-loader.php; shortcode-social-display.php (social fan/marquee… |
| [`core`](core.md) | 20 | ~4.500 | 141 | 38% | Hạ tầng theme: module loader (what-loads-when), nạp theo màn admin, template router, enqueue, guard upload/file/CSP, AI… |
| [`integrations`](integrations.md) | 3 | 90 | 2 | 0% | Adapter ngoài theme: MCP liên site (mcp-site-link), hóa đơn eim (shipper / mã vận đơn / meta xuất kho), GHTK. |
| [`marketing`](marketing.md) | 6 | ~1.600 | 105 | 20% | Marketing: popup, thanh menu đáy + "Ưu đãi của tôi", vòng quay may mắn, CRO landing agent (chạy theo lịch). |
| [`marketing/lucky-wheel`](marketing-lucky-wheel.md) | 3 | ~1.900 | 79 | 3% | Vòng quay may mắn: cài đặt, widget, dọn mã. |
| [`marketing/offers`](marketing-offers.md) | 11 | ~2.000 | 97 | 52% | Trang ưu đãi /uu-dai/: lưu trữ, bóc tách AI, thẻ ưu đãi, structured data, worker nền. |
| [`shortcodes`](shortcodes.md) | 22 | ~15.000 | 211 | 23% | Shortcode: xuất kho / hoàn hàng / đơn đã giao, so sánh SP (+AI), protein calculator, landing ANC, thông tin công ty, nh… |
| [`woocommerce/checkout`](woocommerce-checkout.md) | 5 | ~2.600 | 59 | 17% | Checkout: TK nhận tiền (BACS + eim), trang thanh toán, trang cảm ơn, xác nhận chuyển khoản / chờ thanh toán. |
| [`woocommerce/orders`](woocommerce-orders.md) | 33 | ~10.300 | 387 | 61% | Đơn hàng: trạng thái, số đơn, khóa đơn cũ, bước vận hành + board "Cần xử lý", bảng điều phối, tìm đơn, /quan-ly-don, HP… |
| [`woocommerce/orders/order-creator`](woocommerce-orders-order-creator.md) | 2 | ~4.300 | 91 | 55% | Trang Tạo đơn hộ khách /tao-don/. |
| [`woocommerce/products`](woocommerce-products.md) | 17 | ~7.900 | 197 | 31% | Sản phẩm: trang SP, mua hàng, lượt bán, tab, taxonomy, nhãn dinh dưỡng, popup giỏ hàng, nhúng hồ sơ IVAR. |

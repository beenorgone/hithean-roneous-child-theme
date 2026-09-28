# Ưu đãi đại lý mới (2 đơn đầu) trên `an-new-chapter-b2b` — tháng 10/2026

> Prompt/plan cho agent. Nguồn chính sách: module B2B bên ERP ivarvietnam
> (tab "Khách hàng mới" của bảng giá đại lý, `ivar_b2b_*_new_customer_*`).
> Trạng thái: chưa code — cần chốt mục "Câu hỏi mở" trước.

# Bối cảnh

Trang `an-new-chapter-b2b` (nguồn: `pages/an-new-chapter-b2b/an-new-chapter-b2b.html`, CSS riêng:
`pages/an-new-chapter/an-new-chapter-b2b.css`, JS chung: `pages/an-new-chapter/an-new-chapter.js`)
là landing mời hợp tác phân phối The An Organics. Mọi CTA đang mở modal `#anc-register`
(iframe `https://ivarvietnam.com/dang-ky-dai-ly/?embed=1&src=hithean`).

ERP bên ivarvietnam vừa ra **chính sách Ưu đãi Khách hàng mới** trong bảng giá đại lý:
đại lý mới được hưởng giá ưu đãi riêng cho **2 đơn hàng đầu tiên**. Từ đơn thứ 3 sẽ áp dụng
bảng giá thường kỳ. Bảng giá ưu đãi được gửi riêng (file "ƯU ĐÃI KHÁCH HÀNG MỚI") sau khi
đối tác đăng ký.

**Điều kiện thời gian:** chỉ áp dụng cho đối tác **đăng ký từ 01/10/2026 đến hết 31/10/2026**
(giờ Việt Nam).

# Mục tiêu

Cập nhật trang để lead B2B hiểu ngay 4 ý sau, và có thêm lý do để đăng ký **ngay trong tháng 10**:
1. Có chính sách ưu đãi riêng cho đại lý mới.
2. Ưu đãi áp dụng cho **2 đơn đầu tiên**.
3. Đăng ký xong sẽ nhận bảng giá ưu đãi **trong vòng 24 giờ**.
4. Chỉ dành cho đối tác đăng ký trong **tháng 10/2026**.

# Ràng buộc nội dung (bắt buộc)

- **KHÔNG** hiển thị mức chiết khấu, % giảm, giá cụ thể hay ví dụ "tiết kiệm X đồng".
  Không dùng từ gợi ra con số, kiểu "giảm sâu nhất", "giá gốc".
- Được phép nói: "mức giá ưu đãi riêng", "giá nhập tốt hơn cho 2 đơn đầu",
  "chính sách chi tiết gửi kèm bảng giá sau khi đăng ký".
- **KHÔNG** hứa miễn phí vận chuyển hay quỹ hỗ trợ. Hai mục này thay đổi theo từng đợt cấu hình,
  nên chỉ nói "điều kiện chi tiết ghi trong bảng giá ưu đãi".
- FAQ "Có công khai giá sỉ trên landing page không?" vẫn giữ tinh thần **không công khai giá**.
  Chỉ bổ sung ý về ưu đãi đại lý mới.
- Giọng văn: tiếng Việt, chuyên nghiệp, ngắn và ấm, giống các section hiện có. Không giật tít,
  không dùng quá 1 emoji mỗi khối.
- Số "2" (số đơn) và mốc ngày chỉ khai báo **ở một chỗ** (xem phần kỹ thuật), không rải cứng
  khắp HTML.

# Thay đổi cần làm

## 1. Hero (`#anc-hero`)
- Thêm một badge/ribbon nổi bật phía trên hoặc ngay dưới `.anc-hero-ctas`, ví dụ:
  **"Ưu đãi đại lý mới · Giá nhập riêng cho 2 đơn đầu · Đăng ký trong tháng 10/2026"**
- Đổi CTA chính `Nhận bảng giá đại lý →` thành **"Nhận bảng giá ưu đãi trong 24h →"**. Giữ
  nguyên `href="#anc-register"` và `data-modal-open="anc-register"`.
- Thêm 1 dòng microcopy dưới CTA: "Bảng giá ưu đãi gửi qua email/Zalo trong vòng 24 giờ
  sau khi đăng ký."

## 2. Section mới `#anc-new-partner-offer`
Đặt ngay sau `#anc-b2b-model`, trước `[certifications]`. Dùng lại class sẵn có
(`anc-b2b-section`, `anc-b2b-inner`, `anc-section-title`, `anc-fade-in`, `anc-b2b-card`).
Cấu trúc gợi ý:
- Tiêu đề: **"ƯU ĐÃI DÀNH CHO ĐẠI LÝ MỚI"**
- Phụ đề: "Chương trình dành cho đối tác đăng ký từ 01/10 – 31/10/2026. Giúp bạn làm quen
  với sản phẩm và thử phản hồi thị trường với chi phí nhập phù hợp hơn."
- 3 thẻ dạng bước:
  1. **Đăng ký trong tháng 10** – "Điền thông tin kênh bán và sản phẩm quan tâm, chỉ mất 2 phút."
  2. **Nhận bảng giá ưu đãi trong 24h** – "Bảng giá riêng cho đại lý mới kèm điều kiện áp dụng,
     gửi thẳng cho bạn."
  3. **Áp dụng cho 2 đơn đầu** – "Hai đơn nhập đầu tiên hưởng giá ưu đãi. Từ đơn thứ 3 áp dụng
     bảng giá đại lý thường kỳ."
- CTA: "Đăng ký nhận ưu đãi →" (mở modal `#anc-register`).
- Dòng chú thích nhỏ: "Mức ưu đãi cụ thể và điều kiện áp dụng được ghi trong bảng giá gửi
  riêng cho từng đối tác."
- Mặt thị giác phải khác các section trắng xung quanh, ví dụ nền nhạt có viền trái nhấn màu
  thương hiệu. Style viết trong `an-new-chapter-b2b.css`, dùng biến màu có sẵn, không hardcode
  màu mới nếu đã có token.

## 3. Quy trình hợp tác (`#anc-b2b-flow`)
- Thẻ **"Nhận chính sách"**: thêm ý "Đại lý mới đăng ký trong tháng 10/2026 nhận thêm bảng giá
  ưu đãi cho 2 đơn đầu, gửi trong 24 giờ."
- Thẻ **"Nhập hàng"**: thêm "2 đơn đầu áp dụng giá ưu đãi đại lý mới."

## 4. Modal đăng ký (`#anc-register`)
- Đoạn mô tả hiện ghi "Team The An Organics sẽ liên hệ trong 24-48 giờ làm việc". Sửa để
  **không mâu thuẫn** với cam kết 24h:
  "Điền thông tin bên dưới để nhận bảng giá đại lý và **bảng giá ưu đãi cho 2 đơn đầu** (áp dụng
  cho đối tác đăng ký trong tháng 10/2026). Bảng giá ưu đãi được gửi trong vòng 24 giờ, sau đó
  team sẽ liên hệ tư vấn danh mục phù hợp."
- Thêm một highlight nhỏ phía trên iframe: "🎁 Ưu đãi đại lý mới – 2 đơn đầu".

## 5. Section Shopee trial (`#anc-shopee-trial`)
- Sửa link phụ thành "Sau khi trải nghiệm, đăng ký để nhận giá ưu đãi 2 đơn đầu →".

## 6. FAQ (`#anc-faq`, nhóm "Hợp tác phân phối") và JSON-LD `FAQPage`
- Sửa câu **"Sau khi đăng ký thì bước tiếp theo là gì?"**: nêu rõ bảng giá ưu đãi gửi trong 24 giờ,
  sau đó team liên hệ xác nhận kênh bán, MOQ, lô nhập đầu.
- Thêm câu mới **"Ưu đãi đại lý mới áp dụng thế nào?"**: "Đối tác đăng ký từ 01/10 đến 31/10/2026
  được áp dụng giá nhập ưu đãi riêng cho 2 đơn hàng đầu tiên. Từ đơn thứ 3 áp dụng bảng giá đại lý
  thường kỳ. Mức ưu đãi và điều kiện chi tiết được gửi kèm bảng giá trong vòng 24 giờ sau khi
  đăng ký."
- Thêm câu **"Tôi đã là đại lý thì có được áp dụng không?"**: "Chương trình dành cho đối tác lần đầu
  hợp tác. Đại lý hiện hữu vui lòng liên hệ sales để được tư vấn chính sách phù hợp."
- Cập nhật block JSON-LD `FAQPage` **khớp từng chữ** với nội dung FAQ hiển thị.

## 7. CTA band cuối (`#anc-cta-band`)
- Đổi nút thành "NHẬN BẢNG GIÁ ƯU ĐÃI TRONG 24H →". Thêm 1 dòng: "Ưu đãi 2 đơn đầu cho đại lý
  mới đăng ký trong tháng 10/2026."

# Yêu cầu kỹ thuật: tự bật/tắt theo thời gian

Trang chạy tiếp sau tháng 10, nên mọi khối ưu đãi phải **tự ẩn sau 31/10/2026 23:59
(Asia/Ho_Chi_Minh)** mà không cần sửa lại HTML:
- Tạo một shortcode bao nội dung, ví dụ `[anc_new_partner_offer]...[/anc_new_partner_offer]`,
  đặt trong `custom-functions/shortcodes/` và nạp theo pattern có sẵn của `module-loader.php`
  (điều kiện theo slug `an-new-chapter-b2b` như các shortcode `anc_*` khác).
  Shortcode nhận tham số hoặc hằng cấu hình ở **một chỗ duy nhất**: `start="2026-10-01"`,
  `end="2026-10-31"`, `orders="2"`. Nội dung bên trong có thể dùng placeholder `{orders}`,
  `{end_date}` để không rải cứng số.
- So sánh thời gian bằng `current_datetime()` / `wp_timezone()`, **không** dùng `time()` trần
  hay `date()` theo UTC.
- Hành vi theo giai đoạn:
  - **Trước 01/10**: hiển thị dạng "Sắp mở. Ưu đãi đại lý mới áp dụng cho đăng ký từ 01/10/2026".
    Nếu đơn giản hơn thì hiển thị luôn nội dung chính (xem mục Câu hỏi mở).
  - **01/10 → 31/10**: hiển thị đầy đủ.
  - **Sau 31/10**: không render gì. CTA/hero/modal/FAQ quay lại copy cũ, tức là mỗi chỗ sửa ở
    mục 1, 3, 4, 5, 6, 7 cần có nhánh "copy thường". Hỗ trợ `[anc_new_partner_offer else]...`
    hoặc một shortcode cặp đôi để khai báo nội dung thay thế.
- JSON-LD FAQ cũng phải qua cùng điều kiện, để sau 31/10 Google không còn đọc câu FAQ ưu đãi.
- Kiểm tra cache: nếu trang được page-cache, ghi chú cần purge cache lúc 00:00 ngày 01/10 và
  01/11. Nếu có cơ chế tự purge theo cron thì đề xuất luôn.
- Nếu trang nhận `?order=` để A/B sắp xếp section (xem `initSectionReorder()` trong
  `an-new-chapter.js`), thêm key `new-partner-offer` vào danh sách được phép.

# Kiểm tra trước khi xong
- Mở trang ở 3 mốc giả lập (sửa tạm ngày qua filter hoặc tham số debug chỉ dành cho admin):
  30/09, 15/10, 01/11. Xác nhận đúng nội dung từng giai đoạn.
- Grep toàn trang, **không** còn chỗ nào ghi "24-48 giờ" mâu thuẫn với "24 giờ" trong giai đoạn
  ưu đãi.
- Grep bảo đảm không có ký tự `%`, "giảm", "chiết khấu" đi kèm con số trong các khối mới.
- Mobile 375px: badge hero không vỡ dòng xấu, section mới xếp 1 cột, CTA đủ vùng chạm.
- Không đổi ID/anchor sẵn có (`#anc-register`, `#anc-products`, `#anc-b2b-model`…).

# Câu hỏi mở (hỏi lại tôi, đừng tự quyết)
- Lead đăng ký **cuối tháng 9** có được tính không? Nếu có thì bỏ mốc `start`, chỉ giữ `end`.
- Kênh gửi bảng giá ưu đãi: chỉ email, hay cả Zalo? (Ảnh hưởng microcopy ở hero và modal.)

# Ghi chú vận hành (phía ERP ivarvietnam, ngoài phạm vi repo này)
- **Cam kết 24 giờ phải làm tay.** Bảng giá khách hàng mới không tự gửi khi có người đăng ký.
  Sales phải tích "Kèm bảng giá KH mới" cho từng đại lý khi gửi email báo giá. Trong tháng 10
  cần có người trực đơn đăng ký hằng ngày.
- Chính sách Khách hàng mới trong ERP phải **đang bật** và ít nhất một sản phẩm có mức giá khách
  hàng mới, nếu không file ưu đãi sẽ âm thầm không được đính kèm.
- Số đơn (2) trên trang này khai báo tay, không đọc từ `max_orders` của ERP. Đổi bên ERP thì
  phải sửa cả shortcode ở đây.

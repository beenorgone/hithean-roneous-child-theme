# Kịch bản tư vấn mẫu — sửa trước khi nhập

Gói gồm **10 kịch bản nháp**. [Mở JSON để sửa](../custom-functions/shortcodes/assets/consult-starter.json). Không có dữ liệu khách hoặc chính sách bán hàng được bịa sẵn.

## Biến động

| Biến | Dữ liệu trên site đang mở |
|---|---|
| `{ten}` | Tên khách nhập ở thanh công cụ; trống dùng anh/chị |
| `{sp:SKU:title}` | Tên sản phẩm/biến thể theo SKU |
| `{sp:SKU:link}` | Link sản phẩm/biến thể |
| `{sp:SKU:gia}` | Giá hiện tại; SKU cha nhiều giá trả khoảng giá |
| `{nhom:slug:title}` / `{nhom:slug:link}` | Nhóm sản phẩm (product_tag) |
| `{danh_muc:slug:title}` / `{danh_muc:slug:link}` | Danh mục (product_cat) |
| `{thuong_hieu:slug:title}` / `{thuong_hieu:slug:link}` | Thương hiệu (thuong-hieu), nếu site có |

Dùng **SKU/slug thật**, không phải ID hoặc tên hiển thị. Cùng SKU/slug ở hai site có thể cho tên/link/giá khác nhau.
Kịch bản chỉ lưu token; xem trước và copy sẽ tra tại site đích. Copy luôn tra lại; biến sai hoặc thiếu dữ liệu sẽ chặn copy.
Không thực thi shortcode/PHP hoặc tra option tùy ý. Giá báo trên mẫu cần kiểm tra tổng tiền, phí giao và ưu đãi khi tạo đơn.

## Cách sửa và nhập vào cả hai site

1. Mở JSON bằng trình soạn thảo. Tìm/thay `SKU_CAN_SUA`, `SKU_A_CAN_SUA`, `SKU_B_CAN_SUA`,
   `nhom-can-sua`, `danh-muc-can-sua`, `thuong-hieu-can-sua` bằng dữ liệu thật. Sửa cả mảng `skus` nếu có.
2. Sửa lời thoại, bước, từ khóa và ghi chú theo nghiệp vụ. Giữ `status: "draft"` cho tới khi duyệt.
3. Ở mỗi site: SP - Tư vấn → Kịch bản → **Nhập** → chọn JSON. Nhập cùng gói ở Hithean và Theanmarket.
   Nếu SKU/slug khác giữa hai site, dùng hai bản JSON đã sửa riêng cho mỗi site.
4. Mở từng kịch bản, kiểm tra tên/link/giá và copy thử. Trong màn Sửa có công cụ **Chèn biến / Xem thử câu mẫu**.
5. Sau khi duyệt, đổi Trạng thái sang **Đang dùng** rồi Lưu ở từng site. AI bỏ qua kịch bản nháp.

Nhập lại cùng tiêu đề (so khớp không dấu) sẽ cập nhật bản có sẵn. Đổi tiêu đề sẽ tạo bản mới.
Có thể tải lại gói từ nút **Kịch bản mẫu để sửa** ngay trong tab Kịch bản. Gói mẫu không tự nhập hoặc tự bật kịch bản.

## Nội dung đề xuất

### 01 · Giới thiệu một sản phẩm

Nhóm: Giới thiệu sản phẩm · Trạng thái: Nháp

**Hỏi nhu cầu**

```text
Dạ {ten}, mình đang tìm sản phẩm cho nhu cầu nào và dùng cho ai ạ?
```

Ghi chú nhân viên: Hỏi đủ nhu cầu trước khi đề xuất.

**Gửi thông tin**

```text
Dạ em gửi mình {sp:SKU_CAN_SUA:title}:
{sp:SKU_CAN_SUA:link}
Giá hiện tại: {sp:SKU_CAN_SUA:gia}.
```

Ghi chú nhân viên: Sửa SKU_CAN_SUA thành SKU thật có trên từng site. Không thêm công dụng chưa có trong thông tin sản phẩm.

### 02 · So sánh hai lựa chọn

Nhóm: So sánh · Trạng thái: Nháp

**Xác định tiêu chí**

```text
Dạ {ten}, mình ưu tiên tiêu chí nào để em đối chiếu hai lựa chọn cho sát nhu cầu ạ?
```

**Gửi hai lựa chọn**

```text
Em gửi mình thông tin để xem cùng em nhé:
1. {sp:SKU_A_CAN_SUA:title} — {sp:SKU_A_CAN_SUA:gia}
{sp:SKU_A_CAN_SUA:link}
2. {sp:SKU_B_CAN_SUA:title} — {sp:SKU_B_CAN_SUA:gia}
{sp:SKU_B_CAN_SUA:link}
```

Ghi chú nhân viên: Dùng công cụ So sánh SP để kiểm tra thành phần, quy cách; không tự khẳng định loại nào tốt hơn.

### 03 · Khách hỏi một nhóm sản phẩm

Nhóm: Dẫn danh mục · Trạng thái: Nháp

**Gửi nhóm**

```text
Dạ {ten}, em gửi nhóm {nhom:nhom-can-sua:title} để mình xem các lựa chọn hiện có:
{nhom:nhom-can-sua:link}
```

Ghi chú nhân viên: Nhóm sản phẩm = product_tag. Thay nhom-can-sua bằng slug thật; không dùng tên nhóm thay slug.

**Thu hẹp lựa chọn**

```text
Mình ưu tiên loại, quy cách hay khoảng giá nào để em chọn vài sản phẩm phù hợp cho mình ạ?
```

### 04 · Khách muốn xem danh mục

Nhóm: Dẫn danh mục · Trạng thái: Nháp

**Gửi danh mục**

```text
Dạ {ten}, mình xem danh mục {danh_muc:danh-muc-can-sua:title} tại đây ạ:
{danh_muc:danh-muc-can-sua:link}
```

Ghi chú nhân viên: Danh mục = product_cat. Kiểm tra slug trên cả hai site.

**Hỏi lựa chọn**

```text
Mình đang quan tâm sản phẩm nào trong danh mục để em gửi thông tin chi tiết ạ?
```

### 05 · Khách hỏi thương hiệu

Nhóm: Dẫn danh mục · Trạng thái: Nháp

**Gửi thương hiệu**

```text
Dạ {ten}, em gửi trang {thuong_hieu:thuong-hieu-can-sua:title} trên website bên em:
{thuong_hieu:thuong-hieu-can-sua:link}
```

Ghi chú nhân viên: Dùng khi cả hai site có taxonomy thuong-hieu và đúng slug. Không tự nhận là đại lý/nhà phân phối nếu chưa có căn cứ.

### 06 · Khách hỏi giá / chê giá cao

Nhóm: Giá bán · Trạng thái: Nháp

**Xác nhận đúng sản phẩm**

```text
Dạ {ten}, mình đang hỏi {sp:SKU_CAN_SUA:title} đúng không ạ? Giá hiện tại trên website là {sp:SKU_CAN_SUA:gia}:
{sp:SKU_CAN_SUA:link}
```

Ghi chú nhân viên: Giá biến thể cần SKU đúng quy cách; SKU cha có nhiều giá sẽ trả khoảng giá.

**Hỏi ngân sách**

```text
Nếu mình có khoảng ngân sách dự kiến, em sẽ kiểm tra thêm lựa chọn để mình cân nhắc ạ.
```

Ghi chú nhân viên: Không tự hứa giảm giá, tặng quà hoặc miễn phí vận chuyển.

### 07 · Khách hỏi cách dùng / thành phần

Nhóm: Thông tin sản phẩm · Trạng thái: Nháp

**Xác nhận quy cách**

```text
Dạ {ten}, em gửi thông tin của {sp:SKU_CAN_SUA:title}:
{sp:SKU_CAN_SUA:link}
Mình cho em biết quy cách đang xem hoặc gửi ảnh nhãn để em đối chiếu đúng hướng dẫn nhé ạ.
```

Ghi chú nhân viên: Đọc HDSD/thành phần đã lưu và nhãn thật trước khi trả lời; không tự thêm liều dùng.

**Kiểm tra thông tin**

```text
Em kiểm tra đúng thông tin trên nhãn rồi gửi lại mình phần mình đang hỏi ạ.
```

### 08 · Khách hỏi còn hàng / quy cách

Nhóm: Trước đặt hàng · Trạng thái: Nháp

**Gửi đúng sản phẩm**

```text
Dạ {ten}, đây là {sp:SKU_CAN_SUA:title}:
{sp:SKU_CAN_SUA:link}
Mình cần quy cách nào và số lượng bao nhiêu để em kiểm tra tồn trước khi lên đơn ạ?
```

Ghi chú nhân viên: Link/giá không thay thế xác nhận tồn. Không cam kết còn hàng khi chưa kiểm tra.

### 09 · Chốt thông tin đơn hàng

Nhóm: Tạo đơn · Trạng thái: Nháp

**Xác nhận lựa chọn**

```text
Dạ {ten}, em xác nhận mình đang chọn {sp:SKU_CAN_SUA:title}, giá hiện tại {sp:SKU_CAN_SUA:gia}:
{sp:SKU_CAN_SUA:link}
Mình lấy số lượng bao nhiêu ạ?
```

Ghi chú nhân viên: Tổng tiền/phí ship/ưu đãi phải kiểm tra lại trong Tạo đơn.

**Xin thông tin giao**

```text
Mình gửi em tên người nhận, số điện thoại và địa chỉ giao hàng để em kiểm tra và lên đơn cho mình nhé ạ.
```

Ghi chú nhân viên: Chỉ thu thông tin cần thiết cho đơn hàng qua kênh làm việc được phép.

### 10 · Khách cần hỗ trợ sau mua

Nhóm: Sau bán hàng · Trạng thái: Nháp

**Tiếp nhận**

```text
Dạ {ten}, mình gửi em mã đơn và mô tả vấn đề đang gặp; nếu liên quan sản phẩm, mình gửi thêm ảnh để em kiểm tra đúng trường hợp nhé ạ.
```

**Chuyển xử lý**

```text
Em đã ghi nhận thông tin. Em sẽ kiểm tra đơn và chính sách áp dụng rồi báo lại mình phương án xử lý ạ.
```

Ghi chú nhân viên: Không tự hứa đổi trả/hoàn tiền hay thời hạn khi chưa xác nhận chính sách và quyền xử lý.

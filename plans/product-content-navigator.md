# Product Content Navigator — Hithean

## Mục tiêu

Tạo thanh điều hướng nội dung cho trang sản phẩm WooCommerce nhằm giúp khách đi nhanh tới thông tin cần ra quyết định mua (mô tả, thành phần, cách dùng, FAQ, thương hiệu và chính sách). Thiết kế giữ toàn bộ nội dung tab mở sẵn, không reload trang, không dùng click để ẩn/mở tab.

Mục tiêu UX/CRO:

- Giảm thời gian cuộn tìm thông tin trên trang sản phẩm dài.
- Làm rõ bước tiếp theo bằng CTA mua hàng và nút "Chi tiết SP" trên mobile.
- Giữ Lucky Wheel và sticky add-to-cart đang có, không thay đổi style hay logic khuyến mãi của chúng.
- Cho phép quản trị viên chủ động đưa tab toàn cục hoặc tab theo category, tag, thương hiệu và sản phẩm vào navigator.

## Hiện trạng cần tôn trọng

- `custom-functions/woocommerce/products/product-page.php` build toàn bộ WooCommerce tabs bằng filter `woocommerce_product_tabs`.
- CPT `product-tab` đã có phạm vi toàn cục, `product_cat`, `product_tag`, thương hiệu `thuong-hieu` và sản phẩm riêng.
- `css/custom.css` đang ẩn `ul.tabs` và ép tất cả `.woocommerce-tabs .panel` hiển thị; đây là hành vi phù hợp cho navigator kiểu cuộn.
- Sticky add-to-cart mobile nằm trong `product-page.php` và CSS hiện có; Lucky Wheel được tải độc lập từ `custom-functions/marketing/lucky-wheel.php`.
- Không dùng lại template accordion của Theanmarket vì Roneous đang render tabs chuẩn WooCommerce.

## Phạm vi dữ liệu và Settings

Mở rộng metabox **Cài đặt Tab** của CPT `product-tab` trong `custom-functions/woocommerce/products/product-tab-post-type.php`:

| Field | Meta key | Kiểu | Hành vi |
|---|---|---|---|
| Hiển thị trong Chi tiết SP | `product_tab_show_in_navigator` | checkbox | Chỉ tab được bật mới xuất hiện trong navigator. |
| Nhãn navigator | `product_tab_navigator_label` | text | Nhãn ngắn, ví dụ `Cách sử dụng`; rỗng thì dùng tiêu đề tab. |
| Icon navigator | `product_tab_navigator_icon` | select | Chọn từ whitelist icon theme, không nhận class tùy ý. |

Phạm vi hiển thị không cần tạo dữ liệu mới. Navigator tái sử dụng đúng các setting hiện có:

| Phạm vi | Cấu hình đang có |
|---|---|
| Toàn cục | `product_tab_global_tab` |
| Theo category | taxonomy `product_cat` của CPT `product-tab` |
| Theo tag | taxonomy `product_tag` của CPT `product-tab` |
| Theo thương hiệu | `product_tab_thuong_hieu` |
| Theo sản phẩm | `product_tab_products` |

Các tab chuẩn có thể dùng map mặc định nếu tồn tại panel: Mô tả, Thành phần, Câu hỏi thường gặp, Cách sử dụng và Thương hiệu. Reviews vẫn tuân theo quyết định hiện tại của theme (đang bị loại khỏi WooCommerce tabs), không tự thêm lại.

## Thiết kế kỹ thuật

### 1. Module độc lập và nạp có điều kiện

Tạo:

- `custom-functions/woocommerce/products/product-navigation.php`
- `custom-functions/woocommerce/products/assets/product-navigation.js`
- `custom-functions/woocommerce/products/assets/product-navigation.css`

Đăng ký module trong `custom-functions/core/module-loader.php` với điều kiện `tpc_cond_is_product`. Module tự dừng sớm khi không phải sản phẩm hoặc khi chỉ có dưới hai điểm điều hướng.

Nạp CSS và JS chỉ trên trang sản phẩm. Dùng version `filemtime`, đưa JavaScript xuống footer và `defer`; không thêm thư viện mới.

### 2. Một nguồn dữ liệu, không truy vấn tab lần hai

Trong hook render sau khi WooCommerce đã hoàn tất filter tabs:

1. Lấy mảng tabs cuối cùng từ `apply_filters('woocommerce_product_tabs', [])` hoặc một filter nội bộ nhận chính mảng đó.
2. Chuẩn hóa thành các item: `key`, `target_id`, `label`, `icon`, `priority`.
3. Kiểm tra panel thật sự tồn tại trên DOM trước khi JS kích hoạt item.
4. Loại key/ID trùng, tab rỗng và tab không được bật trong setting navigator.
5. Sắp xếp ổn định theo `priority`, sau đó theo nhãn.

Không tạo thêm `WP_Query`, transient hay AJAX cho navigator. Nhờ đó không làm tăng truy vấn ngoài các truy vấn `product-tab` hiện hữu. Khi quản trị viên cập nhật Tab Sản Phẩm, cache trang/CDN hiện có vẫn là nơi cần purge theo quy trình publish của site.

### 3. Markup và accessibility

Render một root duy nhất ở `wp_footer`:

- Desktop: thanh `nav` có danh sách nút điều hướng và CTA mua hàng.
- Mobile: cụm hai nút, Lucky Wheel bên trái và `Chi tiết SP` bên phải; nút Chi tiết SP mở bottom-sheet/drawer.
- Mỗi item là `button type="button"`, có `data-target`, icon `aria-hidden="true"` và nhãn text thật cho screen reader.
- Drawer có `aria-expanded`, `aria-controls`, `role="dialog"`, `aria-modal`, nút đóng, backdrop, Escape và focus return.

Không can thiệp HTML heading/panel của tab chuẩn, không khôi phục link tab cũ, không thêm accordion hay icon +/-.

### 4. UX desktop

- Thanh cố định sát đáy viewport từ đầu, full chiều rộng site/viewport; không bị giới hạn bởi vùng WooCommerce tabs.
- Danh sách căn giữa, không có thanh cuộn ngang. Nhãn dài được rút gọn bằng setting và có `title`/`aria-label` đầy đủ.
- CTA mua hàng nằm cùng thanh nhưng không ép sát mép phải. Padding ngang gọn, giữ các style button sẵn có của theme.
- Với sản phẩm WooCommerce, CTA gọi luồng add-to-cart hợp lệ hiện có; với sản phẩm external, CTA mở `.ecom-buy-trigger`. Không tạo checkout flow mới.

### 5. UX mobile

- Section chứa Lucky Wheel và Chi tiết SP rộng `100vw`, căn giữa, `gap: 10px`, bảo đảm không tràn từ 320px.
- Giữ đầy đủ text `Nhận ưu đãi`; font-size hai nút bằng nhau và theo kích thước `Chi tiết SP`.
- Không style lại background, border hay radius gốc của Lucky Wheel. Nếu cần token nội bộ, định nghĩa `--lw-green` ở scope navigator, không ghi đè design của Lucky Wheel.
- Nút Chi tiết SP dùng icon cùng hệ icon tab, không có border-radius riêng.
- Bottom-sheet nền hơi trong, có blur nhẹ và icon tương ứng từng tab; chiều rộng luôn bị giới hạn trong viewport.
- Tính offset với sticky add-to-cart hiện có để các lớp không che nhau. Khi breakpoint đổi, trả Lucky Wheel về vị trí DOM ban đầu.

### 6. Cuộn và trạng thái active

- Click item cuộn mượt tới panel với offset gồm header và navigator.
- Dùng `IntersectionObserver` để đổi active item khi khách tự cuộn.
- Trong lúc chương trình cuộn, tạm khóa cập nhật active; hủy animation nếu có `wheel`, `touchstart` hoặc thao tác kéo để tránh cảm giác giật/rối.
- Drawer mobile tự đóng sau khi chọn mục.
- Không thay đổi hash URL và không reload trang.
- Tôn trọng `prefers-reduced-motion`.

## Styling tab heading và nội dung

Chỉ bổ sung CSS có scope `.single-product` trong `css/custom.css` hoặc asset mới:

- Heading tab vẫn là nội dung tĩnh; icon đặt trước `span` nếu cần nhận diện nội dung.
- Chỉ styling icon/span heading, không biến heading thành link.
- Tắt pseudo-element +/- của Roneous nếu theme chèn vào heading.
- Giữ `.panel` hiển thị đầy đủ; không thêm click-to-hide hay expand/collapse.

## Tốc độ, cache và bảo mật

- Không nhận selector, URL hoặc class icon tự do từ admin/frontend; icon đi qua allowlist.
- Escape label, ID, class và URL khi render; dữ liệu JS dùng `wp_json_encode`.
- Kiểm tra capability/nonce và sanitize cho các field Meta Box mới theo cơ chế Meta Box hiện hữu.
- Không đưa HTML nội dung tab, coupon state hay nonce Lucky Wheel vào payload navigator.
- Event delegation, passive listener cho wheel/touch, một `IntersectionObserver` cho toàn trang.
- Không cache theo người dùng vì Lucky Wheel có nonce/trạng thái riêng; navigator tĩnh và thân thiện với page cache/CDN.

## Kế hoạch triển khai theo commit

1. `feat(product-tabs): add navigator settings to product tabs`
   - Thêm ba field setting, whitelist icon và hướng dẫn admin.
2. `feat(product): add content navigator data and assets`
   - Tạo module, enqueue có điều kiện, build item từ tabs cuối cùng và render markup.
3. `feat(product): add responsive navigator interactions`
   - Cuộn mượt, active state, drawer mobile, xử lý Lucky Wheel và CTA.
4. `style(product): align content navigation with Hithean UI`
   - Desktop/mobile layout, offsets, icon heading và chống tràn viewport.
5. `test(product): verify navigator scopes and interactions`
   - Bổ sung/checklist test và xử lý regressions.

## Kiểm thử nghiệm thu

- PHP lint, JavaScript syntax check và `git diff --check`.
- Tạo mẫu Tab Sản Phẩm: global, category, tag, thương hiệu, sản phẩm riêng; xác nhận đúng sản phẩm mới thấy đúng menu.
- Kiểm tra setting tắt navigator: tab vẫn render trong nội dung nhưng không có menu.
- Kiểm tra tab rỗng, priority trùng, icon không hợp lệ, tab bị xoá và product không có Lucky Wheel.
- Viewport: 320px, 390px, 768px, 1366px, 1920px.
- Kiểm tra keyboard, Escape, focus, screen reader labels và reduced motion.
- Kiểm tra Lucky Wheel không đổi style và sticky ATC không chồng lớp.
- Sau deploy: purge page/CDN cache có chủ đích, kiểm tra một sản phẩm đại diện ở từng phạm vi và xác nhận asset version mới được tải.

## Tiêu chí hoàn thành

- Khách có thể đi từ navigator đến đúng nội dung mà không reload hoặc làm ẩn nội dung khác.
- Mobile luôn thấy hai CTA gọn, căn giữa và không tràn viewport.
- Desktop có thanh cố định đáy, full site width, không có horizontal scrollbar.
- Quản trị viên có thể kiểm soát navigator cho tab toàn cục, category, tag, thương hiệu và sản phẩm bằng UI hiện có cộng setting mới.
- Không tăng truy vấn tab chỉ để render navigator, không ảnh hưởng Lucky Wheel, sticky ATC hoặc luồng mua hiện hữu.

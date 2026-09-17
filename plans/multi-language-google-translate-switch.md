# Multi-language toàn site — nút "Switch to English" (Google Translate)

## 0. Phạm vi & giới hạn cần thống nhất trước

Đây là **dịch máy client-side qua Google Website Translator**, không phải hệ
thống đa ngôn ngữ thật (Polylang/WPML). Cần rõ ràng với người duyệt nội dung:

- Không có URL riêng `/en/...`, không `hreflang`, Google Search Console không
  index bản tiếng Anh như một trang riêng → **không có giá trị SEO đa ngôn ngữ**,
  chỉ phục vụ trải nghiệm cho khách đọc được tiếng Anh.
- Nội dung sinh ra sau khi trang đã load bằng AJAX (thông báo lỗi checkout,
  toast, kết quả tra cứu/so sánh sản phẩm...) **sẽ không được dịch lại** — Google
  Translate chỉ quét DOM tại thời điểm khởi tạo.
- Nếu sau này cần SEO tiếng Anh thật (bài viết, trang sản phẩm có URL /en/ được
  Google index), đây phải nâng cấp lên Polylang — không nằm trong plan này.

Nếu các giới hạn trên chấp nhận được, làm theo các bước dưới.

## 1. Kiến trúc

- **Widget**: Google Website Translator cổ điển
  (`translate.google.com/translate_a/element.js`), khởi tạo ẩn
  (`autoDisplay: false`), không hiện dropdown xấu mặc định của Google.
- **Trạng thái ngôn ngữ**: cookie `googtrans` (chuẩn của widget) —
  `/vi/en` để dịch sang Anh, xoá cookie (hoặc `/vi/vi`) để về tiếng Việt gốc.
  Cookie khiến lựa chọn giữ nguyên khi chuyển trang mà không cần bấm lại.
- **Nút bấm**: 1 nút "Eng" / "Vi" tự đổi label theo cookie hiện tại, render qua
  action `hithean_top_bar_after`. Action này được gọi từ trong module-group
  cart/search hiện có ở **cả hai** file layout header thật sự đang chạy —
  `templates/header/layout-center-standard.php` và
  `templates/header/layout-custom.php` (theo `roneous_get_header_layout()` ở
  parent theme, chọn 1 trong 2 tuỳ cấu hình Customizer). File
  `templates/header/menu-top-bar.php` là template part **không được gọi ở đâu
  cả** (kiểm tra bằng grep `get_template_part.*menu-top-bar` không ra kết quả)
  — lần đầu viết plan đã đặt hook nhầm vào file chết này nên nút không hiện;
  đã sửa lại đúng vị trí.

## 2. File cần tạo/sửa

| File | Việc làm |
|---|---|
| `custom-functions/core/language-switcher.php` (mới) | Enqueue JS/CSS, render nút qua hook, không phụ thuộc plugin nào |
| `js/language-switcher.js` (mới) | Đọc/ghi cookie `googtrans`, đồng bộ label nút, load Google script khi cần |
| `css/language-switcher.css` (mới, hoặc gộp vào `css/custom.css`) | Style nút + ẩn UI mặc định của Google (banner iframe, `body{top:40px}`) |
| `custom-functions/core/module-loader.php` | Thêm 1 dòng vào `$general_includes` (nạp mọi request vì nút hiển thị toàn site) |
| `templates/header/layout-center-standard.php`, `templates/header/layout-custom.php` | Thêm `do_action('hithean_top_bar_after')` trong module-group cart/search, thay vì hard-code nút trực tiếp, để dễ bật/tắt qua filter sau này |

Đặt file mới trong `custom-functions/core/` (không phải `marketing/`) vì đây là
hạ tầng UI toàn site, không phải chiến dịch marketing.

## 3. Cơ chế kỹ thuật

1. Render (ẩn) `<div id="google_translate_element" style="display:none"></div>`
   trong footer qua `wp_footer`.
2. Enqueue script Google **chỉ khi cần** — lười tải (lazy): chỉ inject
   `translate.google.com/translate_a/element.js` khi:
   - cookie `googtrans` đã tồn tại (khách quay lại, đang ở chế độ English), hoặc
   - khách vừa bấm nút lần đầu (JS tự chèn `<script>` rồi mới set cookie + reload).
   → tránh việc mọi khách Việt đều tải thêm 1 script ngoài không cần thiết, giữ
   Core Web Vitals sạch cho pageview mặc định.
3. `googleTranslateElementInit()`:
   ```js
   new google.translate.TranslateElement({
     pageLanguage: 'vi',
     includedLanguages: 'en',
     autoDisplay: false,
     layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
   }, 'google_translate_element');
   ```
4. Nút bấm:
   - "Switch to English" → set cookie `googtrans=/vi/en; path=/`
     (+ domain gốc nếu site có subdomain dùng chung) → `location.reload()`.
   - "Về Tiếng Việt" → xoá cookie `googtrans` (set `expires` quá khứ) →
     `location.reload()`.
   - Trên trang đã có cookie `/vi/en`, JS phải tự đổi label nút thành
     "Về Tiếng Việt" khi render (đọc cookie lúc `DOMContentLoaded`, không đợi
     Google script load xong, để tránh nút "giật" label).
5. CSS bắt buộc để không vỡ layout:
   ```css
   .goog-te-banner-frame.skiptranslate { display: none !important; }
   body { top: 0 !important; }
   .goog-text-highlight { background: none !important; box-shadow: none !important; }
   ```

## 4. Rà soát rủi ro trước khi bật thật (bắt buộc)

- Grep toàn bộ `js/` và JS inline trong theme tìm chỗ **so khớp theo text tiếng
  Việt hiển thị** (vd. so sánh `innerText`/`textContent` với chuỗi cứng) — đây
  là chỗ Google Translate sẽ làm hỏng logic vì nó thay text trong DOM. Class
  name / data-attribute / id thì an toàn, không bị ảnh hưởng.
- Kiểm tra riêng: `js/popup-widget.js`, `js/swiper-init.js`, và mọi JS trong
  `custom-functions/shortcodes/` có xử lý theo nội dung text.
- Test tay các luồng có AJAX (biết trước sẽ không dịch phần AJAX, chỉ cần xác
  nhận không **crash**, không phải là dịch được): giỏ hàng, checkout, tra cứu
  so sánh sản phẩm, lucky wheel, popup.

## 5. Các bước triển khai

1. Tạo `custom-functions/core/language-switcher.php` (hook `wp_footer` để in
   div ẩn + nút fallback nếu top-bar không tồn tại ở theme layout nào đó;
   hook `wp_enqueue_scripts` để enqueue JS/CSS).
2. Thêm `'custom-functions/core/language-switcher.php'` vào
   `$general_includes` trong `module-loader.php` (nạp mọi request, chi phí
   thấp vì bản thân file chỉ đăng ký hook, không tải Google script ngay).
3. Thêm `do_action('hithean_top_bar_after')` vào cuối
   `templates/header/menu-top-bar.php`, hook function render nút vào đó.
4. Viết `js/language-switcher.js` theo cơ chế mục 3.
5. Viết CSS mục 3.5, thêm style nút khớp theme (dùng biến màu có sẵn trong
   `admin.css`/`css/custom.css` nếu có, ví dụ `--default-color-green-dark`).
6. Chạy rà soát rủi ro (mục 4) — sửa trước bất kỳ đoạn JS nào so khớp theo text.
7. Test thủ công: desktop + mobile, ở trang chủ, trang sản phẩm, giỏ hàng,
   checkout, và các landing page `an-new-chapter*`. Xác nhận:
   - Nút đổi label đúng theo trạng thái.
   - Cookie giữ trạng thái khi chuyển trang.
   - Không có layout shift do banner Google.
   - Bấm "Về Tiếng Việt" trả về đúng bản gốc, không cần xoá cookie tay.
8. Deploy production, theo dõi Search Console (đảm bảo Google không index
   nhầm bản `?_x_tr_sl=vi&_x_tr_tl=en` — nếu Search Console báo phát hiện URL
   dạng này, thêm rule chặn qua `robots.txt`/`noindex` cho query param đó).

## 6. Không làm trong lần này

- Không dịch tay bất kỳ chuỗi nào trong theme (100% máy dịch).
- Không tạo URL `/en/` riêng biệt, không `hreflang`.
- Không cài Polylang/WPML.

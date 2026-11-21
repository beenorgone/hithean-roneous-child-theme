# Chuẩn code dùng chung — mọi repo

> **Bản đồng bộ — không sửa ở đây.** Bản gốc: `ivarvietnam-wr-nitro-child-theme/docs/coding-standards.md`;
> sửa ở đó rồi chạy `php tools/coding-standards-sync.php` trong repo gốc. Luật riêng của repo này: `CLAUDE.md`.

Dành cho người **và** AI (Claude, Codex…). Áp cho mọi repo trong nhóm site IVAR / Theanmarket / Hithean: theme,
plugin WordPress và app ngoài (Python…). Mỗi luật đến từ một lỗi có thật; ví dụ ghi kèm là ví dụ thật.

**Đọc nhanh:** sửa dữ liệu / bảng → mục 1 + 2 (có checklist 2.9); thêm module / AJAX → mục 3; viết test → mục 4;
chuỗi, số, ngày → mục 5; giao diện → mục 6; commit → mục 7.

**Áp dụng theo tầng:** file này là luật chung. Luật riêng của từng repo (tên hàm, route, công cụ) nằm ở `CLAUDE.md` /
`docs/coding-practices.md` của repo đó và **chỉ được bổ sung**, không được nói ngược file này. Thấy hai nơi nói khác
nhau → sửa nơi sai, đừng để cả hai.

Hai nguyên tắc bao trùm — mọi mục sau là hệ quả:

> **1. Một sự thật, một chỗ (single source of truth).** Mỗi dữ liệu, luật, danh mục, cấu hình, hằng số, tài liệu có
> đúng **một** nơi sở hữu; mọi nơi khác đọc qua nó. Hai bản = sớm muộn lệch nhau, và không ai biết bản nào đúng.
>
> **2. Sai lầm không kêu.** Lỗi ở hệ thống này hiếm khi fatal: một con số khác đi, một nút không ăn, một dòng log mất.
> Trước khi làm, trả lời: *nếu chỗ này sai, ai sẽ báo?* Chưa có câu trả lời thì tạo nó trước (test, cảnh báo, log).
> Không nuốt lỗi: trả `WP_Error` / mã HTTP có lý do, `error_log` kèm ngữ cảnh, hoặc báo người dùng.

---

## 1. Một sự thật, một chỗ

| Loại | Một chỗ là | Không được |
|---|---|---|
| Dữ liệu nghiệp vụ | bảng / meta của module sở hữu, đọc ghi qua hàm của tầng domain | `get_post_meta()` / `SELECT` thẳng dữ liệu của module khác |
| Danh mục, trạng thái, phân loại | một hàm khai (vd `ivar_po_types()`) | chép mảng giá trị sang file thứ hai, `strpos($type, 'service')` |
| Luật tính toán | một hàm thuần | cùng một luật viết ở PHP **và** JS; nếu buộc phải có, sinh JS từ PHP hoặc gửi kết quả đã tính |
| Cấu hình (TK nhận tiền, hạn mức, kênh báo) | một option / màn cài đặt | viết cứng trong code, "bản dự phòng" của slug / URL |
| Thực thể được nhiều nơi ghi | một bảng — **mở rộng bằng cột** | bảng song song trỏ ngược (`intake_report` trỏ `reconcile_reports` → đã gỡ) |
| Việc làm từ nhiều đường vào (tải tay, API, cron, job nền) | **một hàm** mà mọi đường đi qua (vd lưu file đối soát + tính SHA; khoá cache bảng đối soát) | mỗi đường tự làm một bản — đường mới sẽ thiếu bước |
| Cấu hình khác nhau theo site | `wp-config.php` / option của site | viết tên site, domain, ID trong code |
| Code dùng chung giữa nhiều repo | một bản gốc + công cụ đồng bộ hoặc test so khớp; bản chép ghi rõ "GIỐNG HỆT …, sửa ở …" | chép tay rồi sửa lệch từng nơi |
| Tài liệu / quy tắc | một bản gốc, nơi khác nhận bản sinh / đồng bộ | sửa tay bản sinh (`AGENTS.md`, `docs/code-map/`, file này ở repo nhận) |

- **Trước khi thêm** bảng, cột, option, danh mục, hàm tiện ích: tìm cái đã có. Mở rộng cái chung, đừng dựng cái riêng
  cho tính năng mới (đơn phụ PO từng tự đặt phân loại riêng; KOL suýt có bảng thực thể song song với nhà cung cấp).
- **Gom hai bản thì giữ nguyên hành vi**: tìm vì sao hai nơi khác nhau trước khi thống nhất (`ivar_po_status()` cố ý
  không mặc định `draft` vì nơi gọi phân biệt "rỗng" với "draft").
- **Đọc dữ liệu thật trước khi kết luận về dữ liệu.** Đọc code chỉ là suy đoán: từng "sửa" bộ lọc `cancelled` trong
  khi DB lưu `canceled` và bộ lọc vốn đúng. Viết script chỉ-đọc, chạy trên dữ liệu thật, rồi mới sửa.

## 2. Cơ sở dữ liệu

### 2.1 Chọn chỗ lưu

| Dữ liệu | Lưu ở | Lý do |
|---|---|---|
| Cấu hình nhỏ, admin đổi, đọc nhiều | option (`autoload` chỉ khi mọi request cần) | rẻ, có cache |
| Thuộc tính của **một** bản ghi, chỉ đọc theo bản ghi đó | meta | đúng chỗ, xoá theo bản ghi |
| Nhật ký, sự kiện, sổ cái, hàng đợi, bất cứ thứ gì **tăng theo thời gian** hoặc **nhiều nơi ghi cùng lúc** | **bảng riêng, mỗi sự kiện một dòng** | không mất dòng, index được, dọn được |
| Dữ liệu ERP mới có truy vấn / báo cáo / dữ liệu nhạy cảm | bảng riêng `{prefix}<tiền tố>_*` | CPT + postmeta: N+1, không index, lộ qua REST/search |
| Cache có thể mất | transient | — không bao giờ là nơi duy nhất giữ dữ liệu |

**Cấm:** mảng trong một option mà nhiều request cùng thêm phần tử (nhật ký vòng, chỉ mục, hàng đợi). Đọc-sửa-ghi cả
mảng → hai request song song làm mất một dòng mà không ai biết. Đã gặp 3 lần trong một tính năng (log intake, chỉ mục
file eim, nhật ký guard).

### 2.2 Ghi an toàn khi chạy song song

- Bản ghi duy nhất theo khoá tự nhiên → `UNIQUE` / `PRIMARY KEY` + `INSERT … ON DUPLICATE KEY UPDATE`; không
  "SELECT xem có chưa rồi INSERT".
- Khoá loại trừ → MySQL `GET_LOCK(name, 0)` / `RELEASE_LOCK`. Tên ≤ 64 ký tự, kèm `$wpdb->prefix` (khoá dùng chung
  cả máy chủ MySQL), vd `'mod_' . md5($wpdb->prefix . '|' . $key)`. Không khoá bằng `add_option` — request chết giữa
  chừng để khoá treo và dòng rác.
- Hạn mức kiểu "đếm rồi mới ghi" là **hạn mức mềm** (song song có thể vượt một chút) — ghi rõ trong docblock.
- Job nền và giao diện của **cùng một người** không dùng chung khoá cache / transient. Job dựng lô từng ghi đè bảng
  "cần xử lý" người đó đang làm dở trên trang; nay job có khoá riêng theo việc (`…_job_{id}`) qua **một** hàm lấy khoá.

### 2.3 Không lưu thừa (mục 1 áp vào DB)

- Dữ liệu đã có ở bảng sở hữu thì không chép sang meta / option / bảng khác "cho tiện" (tên file gốc vừa ở log vừa ở
  postmeta → đã bỏ bản postmeta).
- Job nền giữ **ID**, đọc lại nguồn lúc chạy; không chép cả danh sách đơn vào option.
- Không để dữ liệu cá nhân (SĐT, email, địa chỉ khách) trong `wp_options` hay log tự do.

### 2.4 Schema và migration

- Mỗi bảng có **số phiên bản** (option) + hàm cài đặt bằng `dbDelta` (đúng cú pháp: `PRIMARY KEY  (id)` hai dấu
  cách, mỗi `KEY` một dòng). Ghi lý do mỗi phiên bản cạnh hằng số.
- Migration **idempotent**, **tự đủ** (không dựa vào file khác đã nạp lúc nâng cấp), **chép dữ liệu cũ trước, xoá
  sau**; dữ liệu không chuyển được thì để lại và ghi log, không xoá.
- Backfill có giới hạn (số dòng, cỡ file); việc nặng chạy cron / theo lô, không trên request của người dùng.
- Hàm cài đặt bảng nhớ kết quả trong request (`static`), không kiểm "bảng có chưa" lặp lại nhiều lần một request.
- Ghi vào bảng thất bại → `error_log` kèm tên bảng + lỗi MySQL; không nuốt lỗi.

### 2.5 Index và truy vấn

- Viết ra các truy vấn sẽ chạy **trước** khi khai index; index khớp `WHERE` rồi `ORDER BY`
  (vd `(user_id, event, created_at)` cho hạn mức, `(source, user_id, id)` cho lịch sử).
- Không N+1: nạp meta theo lô (`update_meta_cache('post', $ids)`), truy vấn theo tập `IN (…)`.
- Mọi `SELECT` có `LIMIT`; mọi `DELETE` dọn dẹp có `LIMIT` (chạy lặp được).

### 2.6 Ranh giới giữa plugin / repo

- Không đọc / ghi bảng nội bộ của plugin khác (vd EIM ↔ CDH ↔ theme). Trao đổi qua hàm API công khai, REST, hoặc
  filter / action có tên trung tính (`app_intake_quota_check`, `app_intake_accepted`).
- Bên trả lời vắng mặt → **mặc định từ chối** việc có rủi ro (luồng tự động), hoặc lùi về trần cứng của chính mình
  (hạn mức); ghi rõ hành vi khi vắng trong docblock.
- Bảng chung có chủ sở hữu duy nhất; nơi khác chỉ đọc qua API của chủ.
- Tự động hoá có rủi ro (đổi trạng thái đơn, dựng lô, gửi dữ liệu ra ngoài) **mặc định tắt**, bật theo từng người /
  từng luồng, mỗi lần bật tắt có cảnh báo và người duyệt.

### 2.7 Chống trùng theo nội dung

- File: SHA-256 nội dung, tính ở **một hàm chung** mà mọi đường vào (tải tay, dán, API, form) đều đi qua, lưu ở bảng /
  meta của thực thể sở hữu. Ảnh bị nén → lưu cả dấu của file **gốc**.
- Tên file, ngày, kích thước chỉ là tín hiệu phụ (đổi tên là lọt).
- Quyết định chặn hay cảnh báo là nghiệp vụ — hỏi, ghi vào tài liệu domain (đối soát: tải tay trùng → vẫn chạy + cảnh báo;
  API trùng → trả `duplicate`).

### 2.8 Tăng trưởng, dọn dẹp, thời gian

- Mọi bảng nhật ký có chính sách giữ (cron dọn, số tháng ghi trong docblock). Không dọn chung sổ sách kế toán — chỉ
  dọn đúng loại dòng của tính năng mình.
- Một bảng một múi giờ (local `current_time('mysql')` **hoặc** UTC), ghi rõ ở schema; so sánh bằng cùng hàm.

### 2.9 Checklist trước khi merge tính năng có dữ liệu

1. Dữ liệu này đã có chỗ sở hữu chưa? (mục 1)
2. Có thứ gì tăng theo thời gian nằm trong option / mảng không? (2.1)
3. Hai request cùng lúc có làm mất / nhân đôi dữ liệu không? (2.2)
4. Có bản chép nào của dữ liệu đã có không? (2.3)
5. Migration chép trước xoá sau, chạy lại được, có giới hạn? (2.4)
6. Truy vấn chính có index khớp, có `LIMIT`, không N+1? (2.5)
7. Có đụng bảng của plugin / repo khác không? (2.6)
8. Ai dọn dữ liệu này sau 12 tháng? (2.8)
9. Mọi đường ghi (tay, API, cron, job) có đi qua cùng một hàm không? (mục 1)
10. Tự động hoá có rủi ro đã mặc định tắt chưa? (2.6)

## 3. Phụ thuộc và nạp code

- `function_exists()` là **phụ thuộc có thật**, không phải phòng thủ. Chỉ dùng khi phụ thuộc thật sự tuỳ chọn và
  chấp nhận tính năng lặng lẽ biến mất khi thiếu; bắt buộc thì nạp chắc chắn hoặc đưa hàm xuống tầng luôn nạp.
- Hai module gọi nhau (A ⇄ B) → tách phần chung xuống một **seam** luôn nạp, không gọi ngược ai (A → S ← B).
- Không `require` theo đường dẫn ở giữa logic ("chưa có hàm X thì require file Y"): đổi tên file là hỏng im lặng.
  Khai điều kiện nạp ở bootstrap.
- Điều kiện nạp khai theo route / action / hook cụ thể; `is_admin()` không phải điều kiện. Mọi `wp_ajax_*`,
  `admin_post_*`, route REST, hook cron phải có đường nạp — có test đối chiếu.

## 4. Test

- Test **đối chiếu ngược**: rút sự thật từ mã nguồn (route, action, meta key) rồi so với khai báo. Test chép lại bảng
  khai báo chỉ nói lại điều code đã nói.
- Hàm tính toán là hàm thuần (mảng vào, mảng ra), test chạy không cần WordPress / DB.
- Mỗi lỗi im lặng đã sửa → một test giữ chặt, docblock đầu file test ghi "lỗi giữ chặt".
- Sau khi di chuyển code: `php -l` / `node --check` / trình phân tích độc lập — so byte chỉ chứng minh "cắt đúng cái
  mình khai".
- Phân tích mã nguồn bằng tokenizer, không regex nhiều dòng.
- Test đỏ thì nghi cả hai phía: khẳng định trong test cũng có thể sai.

## 5. Tiếng Việt, số, ngày

- Regex trên chuỗi tiếng Việt luôn có cờ `/u`; `preg_split('/\R/')` không `/u` cắt vỡ ký tự (byte `0x85` trong `ễ`)
  → `esc_html()` trả rỗng. Dùng `preg_split('/\r\n|\n|\r/', …)`.
- Đổi hoa / thường, cắt chuỗi: `mb_*`, có nhánh dự phòng khi thiếu mbstring. So khớp tên cột / văn bản nhập: chuẩn hoá
  NFC + bỏ dấu trước khi so (file Shopee dùng NFD).
- Số người dùng nhập (`"2,5"`, `"1.234.567"`) qua **một** hàm parse của repo; không `(float)` / `(int)` thẳng.
  `(int)` của mảng không rỗng là `1`.
- Quy đổi đơn vị chặt: không nhận ra thì trả `null`, không đoán mặc định.
- Ngày kiểu VN (`31/12/2026`) parse tường minh; `strtotime` trả `false`.

## 6. Hiển thị, giao diện, bảo mật

- Che / định dạng ở nơi hiển thị; giá trị lưu, gửi server, ghi vết giữ nguyên.
- Truyền dữ liệu PHP → JS bằng `wp_json_encode()` (`wp_add_inline_script`), không `wp_localize_script()` (ép mọi thứ
  thành chuỗi: `'0'` là truthy).
- Chèn chuỗi người dùng vào DOM bằng `textContent`, không `innerHTML`.
- Không `alert` / `confirm` / `prompt` trong ứng dụng; dùng hộp thoại của repo.
- SQL luôn qua `$wpdb->prepare()`; mọi output qua `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post` đúng ngữ cảnh.
- AJAX / REST: kiểm nonce → kiểm quyền → logic qua tầng domain → trả JSON. Endpoint cho máy (App Password) mặc định
  từ chối, mở theo phạm vi được duyệt.
- POST có HTML (`<svg>`, `<script>`) bị Wordfence chặn 403 → gửi base64.

## 7. Git và làm việc chung thư mục

- Stage **từng file**, không `git add -A` / thư mục (trừ worktree riêng do chính mình tạo). Thư mục dùng chung có
  thể chứa việc dở của phiên khác — kiểm `git status` trước commit và trước push.
- Không `git stash` trần; không force-push nhánh chung.
- Không commit bí mật: token, mật khẩu, file credentials (`firebase_credentials.json`…), `.env` — để ở wp-config /
  option / `.gitignore`.
- Commit message dạng `feat(scope): …` / `fix(…)` / `refactor(…)`, nói **vì sao**, nhất là chỗ cố ý không làm.
- Mỗi repo một quy ước nhánh (ghi ở `CLAUDE.md` của repo); không tự đoán.

## 8. Tài liệu đi cùng code

- Thay đổi chưa xong nếu tài liệu còn nói khác code. Đổi luật nghiệp vụ → sửa tài liệu domain trong cùng commit.
- Hàm public mới có docblock, dòng đầu là một câu nói hàm làm gì. Module mới có docblock đầu file chính.
- File sinh tự động (code-map, `AGENTS.md`, khối `AUTO`, file này ở repo nhận) không sửa tay.

## 9. Khi làm việc dưới danh nghĩa AI

- Báo cáo trung thực: test đỏ thì nói đỏ kèm output; bước nào bỏ qua thì nói; chỉ mới lint thì không nói "đã chạy".
- Không tin báo cáo của agent khác hay của chính mình ở phiên trước — đối chiếu lại bằng số đo.
- Hỏi khi quyết định là của nghiệp vụ (chặn hay cảnh báo, giữ bao lâu, hạn mức bao nhiêu); tự quyết khi có mặc định
  hợp lý và nói rõ đã chọn gì.

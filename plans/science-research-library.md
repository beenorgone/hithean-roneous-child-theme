# Thư viện nghiên cứu khoa học — Hithean

> Trạng thái: **đã triển khai đợt 1–4** (24/11/2026) — code ở `custom-functions/woocommerce/products/research/`. Chưa làm: tìm gợi ý theo từ khoá, theo dõi tự động, giọng đọc, cron dọn thẻ lưu trữ.
> Quyết định của chủ sản phẩm: nhập nguồn = **dán link → bóc tách → duyệt rồi đăng**; hiện ở **trang SP + danh mục + landing**;
> tóm tắt AI **VI/EN được duyệt sẵn** (không sinh lúc khách bấm); "đọc bằng AI" = **văn bản** (chưa có giọng đọc);
> nghiên cứu gắn danh mục **tự xuống các SP của danh mục**, SP được loại trừ / ghim riêng; quản lý **ngay trên Hithean**.

## 1. Mục tiêu

Khách mở trang SP / danh mục / landing thấy nút **"🔬 Hồ sơ khoa học liên quan · N tài liệu"** cùng kiểu với nút
"Hồ sơ pháp lý & Kiểm nghiệm" và "Ưu đãi đang áp dụng". Bấm → popup thẻ nghiên cứu; mỗi thẻ có
**Đọc tóm tắt AI** (chọn VI / EN, kèm disclaimer) và **Xem nguồn gốc ↗**. Cuối trang có section
**"Hồ sơ khoa học liên quan"** dạng slider cùng bộ thẻ.

Nhân sự dán link (YouTube, DOI / tạp chí, báo, link Google Scholar) → hệ thống bóc metadata + nội dung →
AI soạn tóm tắt VI/EN → người duyệt sửa → đăng. Mọi bước nằm trên một bảng Kanban.

## 2. Giới hạn nguồn (thiết kế quanh, không né)

| Nguồn | Lấy được | Không lấy được → xử lý |
|---|---|---|
| Tạp chí / DOI | Crossref `api.crossref.org/works/{doi}` (tiêu đề, tác giả, tạp chí, năm, abstract nếu có); PubMed / Europe PMC khi có PMID; thẻ `citation_*`, JSON-LD `ScholarlyArticle` trên trang | Full text sau paywall → trạng thái **Cần bổ sung**: dán abstract / tải PDF (AI đọc PDF qua `theme_ai_call_provider_with_documents`) |
| Google Scholar | Chỉ đọc link: `scholar_url?url=` → lấy URL thật; có DOI thì đi đường DOI | **Không scrape Scholar** (chặn tự động). Link cluster / tìm kiếm → Cần bổ sung: "dán link DOI hoặc trang xuất bản" |
| YouTube | oEmbed (tiêu đề, kênh, thumbnail — không cần key); mô tả qua YouTube Data API v3 `videos.list` khi đã khai key | Phụ đề: API captions cần quyền chủ kênh → nhân sự **dán transcript**; không có thì AI **không** được tóm tắt từ tiêu đề |
| Báo / trang tin | `og:*`, JSON-LD `Article`/`NewsArticle`, thân bài trong `<article>` (cắt 20.000 ký tự) | Trang chặn / JS-only → Cần bổ sung: dán nội dung |

Luật cứng: **thiếu nội dung gốc thì không gọi AI** (AI chỉ tóm tắt văn bản đã có, không suy từ tiêu đề).

## 3. Dữ liệu

Bảng riêng (chuẩn 2.1: dữ liệu ERP có truy vấn / báo cáo, nhiều nơi ghi → không dùng CPT / option).

### `{prefix}hithean_research` — một dòng / một nguồn

| Cột | Ghi chú |
|---|---|
| `id` bigint PK | |
| `url_hash` char(64) **UNIQUE** | SHA-256 của URL chuẩn hoá (bỏ `utm_*`, `fbclid`, fragment; YouTube → `youtube:{videoId}`; DOI → `doi:{doi lower}`) — chống trùng theo nội dung (2.7), dán lại link cũ mở thẻ cũ |
| `source_type` varchar(16) | `journal` · `video` · `news` · `other` |
| `url`, `canonical_url` text | |
| `doi` varchar(190), `pmid` varchar(20), `video_id` varchar(20) | KEY `doi` |
| `title`, `authors`, `publisher` (tạp chí / kênh / báo) text, `published_on` date | |
| `thumb_id` bigint | ảnh tải về Media (không hotlink); trống → icon theo loại |
| `evidence` varchar(20) | `meta_analysis` · `rct` · `observational` · `review` · `animal_lab` · `expert_media` — AI đề xuất, người duyệt chốt |
| `topic` varchar(190) | thành phần / chủ đề (vd "Probiotic L. reuteri") — hiển thị "Nghiên cứu về **thành phần**" |
| `source_text` longtext | nội dung bóc / dán (chỉ admin thấy, ≤ 60.000 ký tự) |
| `source_note` varchar(255) | lỗi bóc tách gần nhất |
| `summary_draft` longtext JSON | `{vi:{headline,summary,points[],limits}, en:{…}, evidence, model, source_hash, at}` |
| `summary_public` longtext JSON | bản đã duyệt — **front chỉ đọc cột này** |
| `status` varchar(16) | cột Kanban, xem §5 |
| `created_by`, `reviewed_by` bigint; `created_at`, `updated_at`, `published_at` datetime (giờ local `current_time('mysql')`) | |

Index: `UNIQUE url_hash`, `KEY status_updated (status, updated_at)`, `KEY doi (doi)`.

### `{prefix}hithean_research_links` — gắn nghiên cứu vào trang

| Cột | |
|---|---|
| `research_id`, `target_type` (`product` · `product_cat` · `page`), `target_id`, `mode` (`include` · `exclude` · `pin`), `sort` int | PK (`research_id`,`target_type`,`target_id`); KEY `target (target_type, target_id, mode)` |

**Hàm thuần duy nhất** `hithean_research_resolve(array $links, int $product_id, array $cat_ids): array` —
SP nhận: `pin` của SP (đầu danh sách) + `include` của SP + `include` của mọi danh mục SP thuộc (kể cả danh mục cha)
− `exclude` của SP; chỉ `published`; sắp: pin → `sort` → `evidence` mạnh trước → mới trước; tối đa 12.
Trang danh mục: include của danh mục + danh mục con trực tiếp. Landing: include theo page ID.
Mọi nơi hiển thị (nút, popup, slider, shortcode, metabox) gọi hàm này — không tự query.

Schema: hằng `HITHEAN_RESEARCH_DB_VERSION` + `dbDelta`, cài ở `admin_init` (nhớ `static` trong request).

## 4. Bóc tách — một hàm, mọi đường vào

`hithean_research_ingest(string $url, int $user_id): array|WP_Error` — dán link ở bảng, dán từ metabox SP, nút "Bóc lại"
đều đi qua đây (chuẩn mục 1: việc nhiều đường vào = một hàm).

1. Chuẩn hoá URL + tính `url_hash` → `INSERT … ON DUPLICATE KEY UPDATE` (không SELECT-rồi-INSERT).
2. Nhận dạng loại: regex YouTube (`/u`) → `video`; DOI trong URL hoặc `citation_doi` → `journal`; Scholar → gỡ `url=`.
3. Adapter (mỗi adapter là **parser thuần** nhận chuỗi HTML/JSON → mảng, test được không cần mạng):
   `hithean_research_parse_html_meta()`, `_parse_crossref()`, `_parse_pubmed()`, `_parse_oembed()`, `_youtube_id()`, `_extract_article_text()`.
4. Mạng: `wp_safe_remote_get` (chặn IP nội bộ), timeout 10s, ≤ 2 MB, ≤ 3 redirect, UA chung `HitheanResearchBot/1.0`
   (không chứa email / tên). Chỉ chạy trong request admin; lỗi → `source_note` + trạng thái **Cần bổ sung**, không nuốt lỗi.
5. Thumbnail: `media_sideload_image` một lần (chỉ host trong allowlist: `i.ytimg.com`, og:image cùng domain nguồn).

## 5. Kanban quản trị

Trang **Sản phẩm → Hồ sơ khoa học** (`edit.php?post_type=product&page=hithean-research`). Material, mỗi cột ghi rõ việc tiếp theo:

| Cột (`status`) | Thẻ ở đây khi | Hành động tiếp theo trên thẻ |
|---|---|---|
| 📥 Mới dán `inbox` | vừa dán, đang bóc | tự chạy bóc tách → sang cột kế |
| ✋ Cần bổ sung `needs_input` | thiếu nội dung gốc | **Dán nội dung / transcript** · **Tải PDF** · Bóc lại |
| ✨ Bản nháp AI `draft` | có `source_text`, chưa có / đã có nháp | **Tạo tóm tắt AI** · Sửa VI/EN · chọn mức bằng chứng · gắn SP/danh mục |
| 👀 Chờ duyệt `review` | người soạn bấm "Gửi duyệt" | **Xem trước** (đúng thẻ front) · **Duyệt & đăng** · Trả lại |
| ✅ Đã đăng `published` | `summary_public` có | Sửa (sửa vào draft, đăng lại) · Gỡ |
| 🗄 Lưu trữ `archived` | gỡ / không dùng | Khôi phục |

- Ô dán link trên đầu bảng (dán nhiều dòng = nhiều link, ≤ 20 / lần).
- Ngăn kéo sửa thẻ: metadata, nội dung gốc, tóm tắt VI | EN song song, mức bằng chứng, chủ đề, **gắn trang**
  (tìm SP — WC product search; chọn danh mục; chọn landing), **xem trước** dùng chính hàm render front.
- Cảnh báo trên thẻ: "Bản nháp cũ hơn nội dung gốc" khi `source_hash` lệch; "Chưa gắn trang nào".
- Quyền: `edit_products` dán link / soạn / gửi duyệt; **`manage_woocommerce` duyệt & đăng, gỡ, xoá**.
- Metabox **"Hồ sơ khoa học"** ở trang sửa SP + màn sửa danh mục: danh sách đã resolve, nút 📌 Ghim / 🚫 Ẩn ở trang này, dán link nhanh.

AJAX (khai đủ trong `ajax_actions` của module, kiểm nonce → quyền → domain → JSON):
`hithean_research_list`, `_ingest`, `_save`, `_ai`, `_status`, `_links`, `_targets`, `_upload_text`.

## 6. AI tóm tắt

- Feature mới `research_summary_ai` trong `theme_ai_feature_registry()` (Cài đặt ERP → AI chọn provider/model), gọi qua
  `theme_ai_feature_call()`; PDF qua `theme_ai_call_provider_with_documents()`; parse `theme_ai_parse_json_object()`.
- Một lượt sinh cả VI và EN (JSON). Prompt bắt buộc: chỉ dùng văn bản cung cấp; nêu thiết kế nghiên cứu, cỡ mẫu,
  kết quả chính, **giới hạn**; nói rõ nghiên cứu về **thành phần / chủ đề**, không phải về sản phẩm; cấm từ "chữa", "điều trị",
  "phòng bệnh" gắn với sản phẩm; thiếu dữ liệu → trả `insufficient: true` (thẻ về Cần bổ sung).
- Kết quả vào `summary_draft` — **không bao giờ tự đăng**. Đăng = sao `summary_draft` → `summary_public`.

## 7. Giao diện khách

### Nút + popup
- Trang SP: in ở `hithean_before_product_chat_ctas` priority 12 (sau khối Hồ sơ & Kiểm nghiệm), class
  `hithean-offers-trigger hithean-research-trigger` — cùng khung nút hiện có. Chữ: **Hồ sơ khoa học liên quan** (tiêu đề popup dùng cùng chữ) ·
  "N tài liệu · Tạp chí · Video". Không có tài liệu đã đăng → không in.
- Danh mục: dưới mô tả danh mục (`woocommerce_archive_description` priority 20). Landing: shortcode
  `[hithean_research page="current" layout="button|slider|both"]`, chèn vào `pages/*` cần dùng.
- Popup dùng khung `.hithean-offers-dialog` (native `<dialog>`, đóng Esc / nền). Thẻ: ảnh / icon loại, chip loại
  (Tạp chí / Video / Báo), chip mức bằng chứng, tiêu đề, nguồn · năm, chủ đề; nút **Đọc tóm tắt AI** mở phần tóm tắt
  ngay trong thẻ với nút gạt **VI | EN** và disclaimer; nút **Xem nguồn gốc ↗** (`rel="nofollow noopener"`, tab mới).
- Video: thumbnail + nút ▶ → mới tải iframe `youtube-nocookie.com` (facade, không tải YouTube khi chưa bấm).
- Ngôn ngữ mặc định: JS đọc cookie `googtrans` (cùng tín hiệu `hithean_language_switcher_is_english()`) vì trang bị cache;
  khối tóm tắt gắn `translate="no"` + class `notranslate` để Google Translate không dịch chồng bản đã duyệt.
- Disclaimer — **một hàm** `hithean_research_disclaimer(string $lang, string $topic)`, nguyên văn do chủ sản phẩm chốt (24/11/2026):
  VI "Nghiên cứu được AI tổng hợp và chuyển ngữ tự động phần tóm tắt nhằm giúp người đọc có kênh tham chiếu có giá trị về
  {chủ đề}. Chúng tôi không soạn thảo và không chịu trách nhiệm cho các thông tin được thể hiện trong nội dung do AI tạo ra."
  EN "This study summary was compiled and automatically translated by AI to give readers a valuable reference on {topic}.
  We did not author and are not responsible for the information presented in this AI-generated content."
  `{chủ đề}` = cột `topic` của thẻ; trống → "sức khỏe và dinh dưỡng" / "health and nutrition".
  Disclaimer cũng in trong ô xem trước ở admin (cùng hàm).

### Section "Hồ sơ khoa học liên quan"
- Trang SP: `woocommerce_after_single_product_summary` priority 15 (trước SP liên quan). Danh mục: sau lưới SP.
- Slider CSS `scroll-snap` + 2 nút ‹ ›, không thư viện; mobile vuốt, thẻ ~85% bề rộng. Bấm thẻ → mở popup tại thẻ đó.
- Dùng chung một hàm render thẻ cho popup, slider, xem trước admin (một sự thật cho giao diện thẻ).

### Hiệu năng / cache
- Render server-side vào HTML (WP Rocket cache được, không AJAX lúc xem); tóm tắt nằm trong `<template>` từng thẻ,
  tối đa 12 thẻ / trang. CSS/JS chỉ enqueue khi trang có ≥ 1 tài liệu, `defer`, version `filemtime`.
- Đăng / gỡ / sửa link / đổi bản đăng → xoá cache WP Rocket của SP, danh mục, landing liên quan (theo mẫu
  `hithean_proof_cache_purge_landings()`); đổi danh mục → SP con lấy theo khi trang cache được dựng lại.

## 8. Tệp & nạp code

```
custom-functions/woocommerce/products/research/
  schema.php      bảng + version + dbDelta
  repository.php  CRUD, links, hithean_research_resolve() (thuần), purge cache
  extract.php     hithean_research_ingest() + parser thuần
  ai.php          tóm tắt VI/EN
  render.php      hithean_research_card_html(), disclaimer, popup, slider, shortcode
  public.php      hook trang SP / danh mục, enqueue
  admin.php       trang Kanban, metabox SP / danh mục, AJAX
  assets/research.{css,js}, assets/research-admin.{css,js}
```
`module-loader.php`: module `research_public` (condition SP | danh mục, path group `anc_landing`, shortcode `hithean_research`),
module `research_admin` (`admin => true` + `ajax_actions`). Thêm dòng route vào bảng `<!-- ROUTES -->` ở `docs/architecture.md`,
mục ngắn trong `CLAUDE.md`.

## 9. Test (`php tests/run.php` gom tự động)
- `tests/research-extract.php`: chuẩn hoá URL / hash, YouTube ID (watch, youtu.be, shorts, embed), DOI trong URL & meta,
  Scholar `scholar_url`, parse HTML meta / JSON-LD / Crossref / oEmbed từ fixture.
- `tests/research-resolve.php`: include danh mục cha → SP, exclude, pin đứng đầu, chỉ published, giới hạn 12.
- `tests/research-render.php`: escape, disclaimer 2 ngữ, không in khi rỗng, `translate="no"`.
- `node tests/research-ui.cjs` (jsdom): mở popup, gạt VI/EN, facade video, slider.
- Test đối chiếu: mọi `wp_ajax_hithean_research_*` có trong `ajax_actions`.

## 10. Lộ trình

| Đợt | Nội dung | Xong khi |
|---|---|---|
| 1 | Bảng + `ingest` (DOI/Crossref, HTML meta, YouTube oEmbed, Scholar link) + Kanban + dán nội dung tay | Dán 10 link thật, thẻ về đúng cột |
| 2 | AI VI/EN + sửa + duyệt/đăng + xem trước | Đăng được thẻ có tóm tắt 2 ngữ |
| 3 | Nút + popup + slider trang SP, metabox ghim/ẩn | Hiện trên 1 SP thật, cache đúng |
| 4 | Danh mục + shortcode landing + purge cache | Danh mục truyền xuống SP, landing ANC dùng được |
| Sau | Tìm gợi ý theo từ khoá (Crossref / PubMed — không Scholar), theo dõi tự động, giọng đọc | Chưa làm |

## 11. Đối chiếu chuẩn (`docs/coding-standards.md`)
1. Chỗ sở hữu: bảng `hithean_research*`; không chép sang meta SP (metabox đọc qua `resolve`).
2. Không có gì tăng theo thời gian nằm trong option.
3. Song song: `url_hash` UNIQUE + upsert; link PK kép.
4. Không bản chép: front đọc `summary_public`, admin đọc `summary_draft` — hai trạng thái khác nhau, không phải bản sao.
5. Không có migration dữ liệu cũ.
6. Truy vấn front: links theo `(target_type, target_id)` với `IN` danh mục → 1 truy vấn + 1 truy vấn nghiên cứu `IN (…)`, có `LIMIT`.
7. Không đụng bảng plugin khác; Crossref / PubMed / YouTube qua API công khai.
8. Dọn dẹp: thẻ `archived` > 12 tháng không link → cron xoá (LIMIT 50 / lượt); `source_text` giữ vì cần tạo lại tóm tắt.
9. Mọi đường ghi qua `ingest` / `hithean_research_save()`.
10. Tự động hoá rủi ro: AI không tự đăng; chưa có theo dõi tự động.

# Social Review Manager — phương án và brief review cho Claude

Ngày: 2026-11-21. Trạng thái: **đã review (Claude, 21/11); quyết định hiện hành ở mục 11.7–11.8**; chính sách quyền dùng ảnh và người duyệt còn mở; chưa triển khai manager/importer.

## 1. Yêu cầu và phần đã làm

- Bổ sung shop The An Organics vào section đăng ký Aff của `/an-new-chapter-affiliate/`.
- URL do người dùng cung cấp: `https://shopee.vn/theanorganics?tab=shop`.
- Đã sửa local `pages/an-new-chapter-affiliate/an-new-chapter-affiliate.html`: thêm thẻ đầu tiên, logo hiện có, link Shopee và nhãn truy cập rõ tên shop. Chưa commit/push/deploy; chưa xác minh giao diện live sau sửa.
- Lên phương án lấy bài Facebook dạng link bên dưới vào “Ai đang dùng The An Organics”, biết người nào review sản phẩm nào.
- Nâng shortcode thành hệ thống dùng chung nhiều sản phẩm, landing, thương hiệu và mục đích; tham khảo đúng Content Publishing/TikTok Research đã dùng, không dựng pipeline thu thập mới tách rời.

Bài mẫu: https://web.facebook.com/nhatkycuavy/posts/pfbid029Qqm7tNwDw8xsZrEHoBif8WquqEkkcKGTvKE8Dn1fNRy9yhZmbcMfjND2enCmYvvl

## 2. Hiện trạng đã kiểm tra bằng source

| Thành phần | Source và hành vi hiện có |
|---|---|
| Content Publishing | `/home/beenorgone/Documents/Drives/gDriveProjects/Working/content-publishing/main.py`, `cmd_tiktok()`: lệnh `tiktok --port 8788` mở server Node tại `~/Documents/Tools/tiktok-research/server.mjs`. Đây là entry point, không phải scraper Python khác. |
| TikTok Research | `/home/beenorgone/Documents/Tools/tiktok-research/server.mjs` + `public/index.html`; bind `127.0.0.1`; đọc cấu hình từ `.env` tool hoặc Content Publishing. Không đưa secrets vào plan/log. |
| Thu thập hàng loạt | `POST /api/search`: Apify `clockworks~tiktok-scraper`, gộp `hashtags`, `searchQueries`, `postURLs`; giới hạn kết quả 1–100, lọc country, caption/hashtag, min views; dedup video ID. |
| Chuẩn hóa và duyệt | `normalize()`: ID, author, caption (đang cắt 200 ký tự), cover, URL, hashtags và metrics. Gallery tích chọn, preview bốn layout; `/api/download` lưu ảnh + `_index.json`. Đây là dữ liệu provenance có thể tái sử dụng. |
| Upload WP | `POST /api/upload`: site hithean/theanmarket → Media REST → cập nhật alt/caption + `_social_display=1`, `_social_display_url`. Upload hiện có thể đưa ảnh vào shortcode ngay, không có draft review riêng. |
| Hiển thị | `custom-functions/shortcode-social-display.php`: 4 shortcode `social_fan` / `social_marquee` / `social_collage` / `social_coverflow` + `social_display` (responsive desktop/mobile); fallback query attachment `_social_display=1`; link nguồn; TikTok lightbox. Chưa có product filter, tác giả có cấu trúc, trạng thái duyệt, collection. |
| Loader | `custom-functions/core/module-loader.php`: shortcode nạp tại `template_redirect` cho các page allowlist/trang SP/home; hai meta attachment đăng ký ở `init` cho REST. Có bản định nghĩa guard tương tự cuối file shortcode; khi sửa cần tránh lệch hai nơi. |

Điểm cần sửa khi tái sử dụng: phản hồi POST cập nhật meta sau upload hiện chưa được kiểm tra; có thể báo thành công dù chưa gắn meta. Một lần retry có thể upload trùng. Proxy ảnh nhận URL chưa có kiểm tra host/IP/redirect/kích thước; body chưa có trần. Giữ phạm vi sửa trong pipeline social khi mở rộng.

## 3. Phương án Facebook: tái sử dụng pipeline, thêm adapter

Giữ Content Publishing làm điểm mở công cụ; phát triển TikTok Research thành màn hình Social Research/Import, vẫn giữ lệnh `tiktok` tương thích. Thêm selector nền tảng, dán nhiều URL, preview, chọn, gắn sản phẩm, gửi nháp. Không bắt WordPress chạy scraper trong request khách truy cập.

### Ba đường lấy dữ liệu

1. **Apify Facebook adapter** cho caption/author/media/metrics: ứng viên `apify/facebook-posts-scraper`, tái dùng lớp gọi Apify và mapping chuẩn. Tài liệu provider mô tả page/profile scraping; **chưa xác minh actor xử lý được direct post URL dạng pfbid này**. Bước spike phải thử link mẫu và kiểm tra schema/build hiện hành trước khi chốt input. Không mặc nhiên quét toàn profile để tìm một link; chỉ làm khi chọn rõ phạm vi, số bài và ngân sách. Không dùng actor TikTok cho Facebook.
2. **Meta oEmbed** cho nhúng bài công khai và fallback xem nguồn. Ngày 21/11 đã GET `https://graph.facebook.com/v27.0/oembed_post` với URL mẫu (đổi host `web` → `www`), `omitscript=true`: **HTTP 200**, trả `html` dạng `<div class="fb-post" data-href="..." data-width="552"></div>`. Không có author, thumbnail hay caption trong phản hồi. Đây là bằng chứng endpoint nhận link, **chưa chứng minh SDK render được nội dung bài trong browser**.
3. **Nhập thủ công/capture có duyệt** khi scraper thiếu dữ liệu hoặc bị chặn: giữ URL, cho nhập excerpt/author và chọn ảnh được phép dùng; embed hoặc link nguồn vẫn hoạt động khi chưa có ảnh. Không tự bịa nội dung/tác giả/sản phẩm từ slug `nhatkycuavy`.

Theo tài liệu Meta hiện hành, oEmbed public có tokenless access từ 15/06/2026; các field author/thumbnail đã bỏ. Đọc full post qua Graph API có yêu cầu quyền khác, không coi là API đọc tùy ý bài cá nhân. Chưa chạy Apify Facebook, chưa phát sinh upload/download bộ ảnh hay publish bài mẫu trong công việc này.

### Luồng import đề xuất

`Dán URL / tìm nguồn → xếp hàng lấy dữ liệu → preview → chọn review + sản phẩm → gửi nháp WP → duyệt công khai`.

- Batch khởi điểm ≤50 URL, concurrency 2, job bền vững có run/dataset ID, timeout/backoff, kết quả riêng từng URL; dừng/hủy không xóa kết quả đã nhận. Giới hạn chi phí/kết quả/timeout trước khi gọi actor, không quét tự động ngoài phạm vi đã chọn.
- Canonical URL: parse hostname chính xác, chỉ nhận Facebook/TikTok được hỗ trợ; bỏ tracking, chuẩn hóa `web/m/www`, giữ pfbid/story ID; không hạ chữ thường path/ID tùy tiện.
- Lưu caption đầy đủ cho biên tập, tạo excerpt riêng; không dùng giá trị 200 ký tự đã cắt làm dữ liệu gốc.
- Gợi ý sản phẩm theo keyword/AI hiện có nếu hữu ích, nhưng người duyệt xác nhận product IDs; một bài có nhiều sản phẩm. Không tự gán tất cả sản phẩm thương hiệu.
- Dữ liệu lấy được chưa phải quyền tái sử dụng ảnh/nội dung: ghi trạng thái cho phép hiển thị và nguồn xác nhận; thiếu thì giữ draft hoặc chỉ link/embed phù hợp.

## 4. Mô hình dữ liệu đề xuất

**Đề xuất CPT `social_review`** (một record = một bài nguồn), media là attachments của record. Không nâng attachment thành review độc lập vì một bài có thể có nhiều ảnh, nhiều sản phẩm và trạng thái duyệt riêng.

| Nhóm | Dữ liệu |
|---|---|
| Nguồn | platform, canonical URL, external post ID, imported_at, provider/build/run/dataset IDs, content hash |
| Tác giả | stable platform author ID/profile URL, display name; không gộp người chỉ vì trùng tên |
| Nội dung | original text (nội bộ), excerpt biên tập, ngày bài, ảnh bìa + attachment IDs có thứ tự, format image/video/text/embed |
| Liên kết | nhiều WooCommerce product IDs, quy tắc variation → parent; collection/campaign/use case, brand scope khi cần |
| Duyệt | draft/pending/publish + trạng thái import/error/needs-data riêng, người duyệt, thời điểm, quyền sử dụng, nguồn lỗi/ẩn |
| Hiển thị | featured/order, vị trí/collection được phép dùng, nguồn mở ngoài hoặc embed khi bấm |

Quan hệ sản phẩm đề xuất dùng taxonomy riêng với term trỏ product ID (WP term relationships hỗ trợ truy vấn nhiều-nhiều), không dùng LIKE trên serialized product IDs. Claude cần đánh giá tradeoff với relation table có index trước khi chốt. Collection là taxonomy riêng; brand filter phải dùng phạm vi được duyệt, không suy ra review mọi SKU của brand.

Dedup theo `(platform, external_post_id)`; fallback canonical URL hash. Khi cùng bài xuất hiện nhiều batch thì cập nhật record cũ, không upload lại ảnh. Cần idempotency/lock phía server chống hai request đồng thời; kiểm tra trước bằng query thôi chưa đủ. Scrape lại cập nhật snapshot nguồn/metrics, không ghi đè excerpt, sản phẩm hay trạng thái công khai đã được biên tập.

## 5. UI quản lý và truy vấn “ai đã review”

- Material, xanh thương hiệu `#0047ba`; bảng Kanban: **Mới nhập → Cần bổ sung → Chờ duyệt → Đang hiển thị → Đã ẩn**. Lỗi import hiển thị riêng với nút thử lại và nguyên nhân. Mỗi thẻ chỉ rõ hành động tiếp theo.
- Card: tác giả, nền tảng, excerpt, cover, chip sản phẩm, nguồn, lỗi/quyền sử dụng; chọn nhiều để gắn sản phẩm/collection và gửi duyệt. Publish yêu cầu đủ dữ liệu và quyền.
- Bộ lọc sản phẩm/nền tảng/collection/trạng thái/tác giả; view bảng nhóm **Sản phẩm → tác giả → bài review**. Đếm số người theo stable author identity; số bài tách riêng, tác giả không rõ không tự tính thành người mới.
- Trang sản phẩm có view review liên quan; landing tổng dùng collection. Cho shortcode generator preview để chọn đúng phạm vi và tránh dùng nhầm tất cả media.

## 6. Shortcode và tương thích

Giữ bốn renderer sẵn có; tách data provider review khỏi renderer. Ví dụ API **đề xuất**, chưa dùng được trên site:

```text
[social_reviews products="6238,6241" collection="the-an-organics" desktop="collage" mobile="coverflow" limit="12"]
[social_reviews products="current" desktop="collage" mobile="coverflow"]
[social_reviews collection="the-an-organics" heading="Ai đang dùng The An Organics"]
```

- Trong `products` nhiều ID là OR; các chiều products/collection/platform là AND. `current` chỉ hợp lệ trên trang SP; scope lỗi/rỗng không được fallback sang mọi review.
- Chỉ render review published + quyền hiển thị phù hợp; không có kết quả thì ẩn section hoặc empty state được cấu hình, không lấy review SP khác bù vào.
- Giữ `[social_display]` và `_social_display*` cũ hoạt động trong giai đoạn chuyển đổi. Với dữ liệu mới, **không set `_social_display=1` lúc import nháp**, tránh rò review chưa duyệt qua query attachment cũ.
- Migration dry-run: nhóm attachments theo canonical source URL thành một review; giữ nguyên attachments và links, không đoán sản phẩm/tác giả. Bản cũ chưa phân loại đưa vào collection legacy; không tự coi là review mọi sản phẩm. Có preview, xác nhận apply, checkpoint và chạy lại không trùng.
- Chuyển landing ANC/affiliate sang collection có kiểm chứng sau khi đủ dữ liệu; không sửa shortcode trước làm section hiện tại biến mất. Cân nhắc layout card có tên/excerpt thay vì ép mọi bài Facebook vào ảnh dọc 9:16.
- Link card Facebook phải có nhãn “Xem bài viết”; không áp lightbox TikTok/nhãn “Xem video” cho mọi nền tảng. Embed chỉ tải lúc người dùng mở, SDK một lần; luôn có link nguồn khi embed bị chặn.

## 7. Loader, cache, an toàn và hiệu năng

- Register CPT/taxonomy/meta ở `init` cho admin/REST; renderer/assets nạp có điều kiện. Rà loader shortcode để page có shortcode mới dùng được ngoài allowlist, gồm HTML landing trên disk. Khai AJAX/cron ở module loader nếu bổ sung; không nhét business logic vào loader.
- Import qua endpoint có capability riêng hoặc quyền biên tập phù hợp và REST auth; Application Password ở backend local, không đưa browser. Nonce cho admin, kiểm object permission cho record/media và product IDs.
- Local server giữ loopback; kiểm Origin/Host + token phiên chống request từ web ngoài; request body/URL count/asset bytes/time có trần. Giữ creds trong `.env`, không chuyển vào repo.
- Remote fetch chống SSRF: HTTPS, allowlist hostname nguồn/CDN theo provider, kiểm DNS/private/link-local IP và từng redirect, MIME thực + giới hạn kích thước; không nhận arbitrary URL proxy. Escape output, sanitize excerpt; không thực thi script/HTML thu thập tùy ý. Dựng embed từ URL đã kiểm, không eval HTML provider.
- Chặn copy upload sai MIME; kiểm cả bước upload và POST meta; partial failure lưu attachment ID để retry tiếp, không báo import xong trước khi review record được lưu. Phân biệt đã thu thập/đã tải ảnh/đã gửi nháp/đã publish.
- Query product/collection có index, limit tối đa 50; `no_found_rows`, prefetch meta/media, ảnh responsive lazy-load. Cache theo scope + version; invalidate khi đổi nguồn/sản phẩm/collection/ảnh/trạng thái và purge page cache liên quan để unpublish có hiệu lực. Không gọi Apify/oEmbed trên request front.

## 8. Trình tự triển khai và nghiệm thu

1. **Review plan + spike Facebook**: Claude review thiết kế, kiểm khả năng actor với URL mẫu/schema hiện hành. Chạy thử nhỏ với giới hạn rõ ràng khi bắt đầu triển khai; báo field thực sự lấy được và fallback. Thử render oEmbed browser để xác nhận riêng.
2. **WordPress manager**: model, quyền, API import nháp, Kanban/bảng nhóm, product relation, migration preview. Kiểm draft không lọt qua shortcode cũ.
3. **Mở rộng công cụ local**: tách provider TikTok/Facebook + shared normalize/job/preview/import; giữ luồng TikTok đang dùng, Content Publishing alias. Không cần đổi repo ngoài hithean trước khi review chốt.
4. **Shortcode mới + migration có duyệt**: reuse renderer, scope/query/cache, cập nhật landing khi collection sẵn sàng.

Kiểm tra trọng yếu: một post nhiều ảnh/nhiều SP chỉ một record; hai batch cùng bài không trùng; URL `web/www` dedup đúng; draft/rejected không public; sửa scrape không ghi đè biên tập; không đủ quyền bị từ chối; SSRF/redirect/oversize bị chặn; một URL lỗi không mất batch; POST meta lỗi không báo thành công; current product không lấy nhầm review; unpublish làm sạch cache; TikTok flow cũ còn chạy; mobile/desktop thẻ Aff 5 shop không tràn và link đúng.

Chưa cần crawl toàn Facebook profile, auto-publish, analytics dashboard lớn, tự tải toàn bộ video hoặc port sang các theme khác trong MVP.

## 9. Nguồn tham khảo đã đọc

- [Meta oEmbed Post](https://developers.facebook.com/docs/graph-api/reference/oembed-post): tokenless, fields, tham số `omitscript` và endpoint. Test HTTP 200 nêu trên thực hiện từ máy local, không lưu secrets.
- [Meta Post permissions](https://developers.facebook.com/docs/graph-api/reference/v2.9/post): giới hạn đọc post bằng Graph API (trang tự hiển thị tài liệu version hiện hành khi tra cứu).
- [Apify Facebook Posts Scraper](https://apify.com/apify/facebook-posts-scraper): ứng viên adapter, chưa coi mô tả provider là chứng minh chạy được bài mẫu.
- [Apify actor input](https://apify.com/apify/facebook-posts-scraper/input-schema): phải kiểm schema thực khi spike; không tự đoán `postURLs` của TikTok áp được Facebook.

## 10. Handoff Claude: yêu cầu review

Review và sửa rõ trong plan này: reused code boundaries, CPT/product relation và dedup atomic, state/publication permissions, migration không lộ draft/không mất section cũ, tính khả thi pfbid scrape, chi phí/job limit, rủi ro fetch/import/cache và tiêu chí nghiệm thu. Nêu blockers/quyết định còn cần trước khi code. Kiểm thẻ shop đã thêm trong checkout nguồn. **Chỉ review plan, không triển khai manager, chạy batch trả phí, upload dữ liệu, commit, push hay deploy.** Trả kết quả review cho người dùng tại agent Claude.

Suggested English commit message (cho thay đổi hiện tại, khi người dùng yêu cầu commit):

```text
Add The An Organics affiliate shop and plan social review management

- Add the supplied The An Organics Shopee shop to the affiliate registration section.
- Document reuse of Content Publishing and TikTok Research for Facebook batch imports.
- Propose product-scoped social reviews, approval, migration, and cache safeguards.
```

## 11. Kết quả review (Claude, 21/11/2026)

Đã đối chiếu source: các khẳng định ở mục 2 **đúng** (loader `template_redirect` + allowlist slug ở `module-loader.php:166`; meta đăng ký có guard
`function_exists` ở cả 2 nơi; `server.mjs` POST meta không kiểm `r.ok` (dòng ~236), `fetchImage()` không kiểm host/kích thước, `readBody()` không trần).
Hướng tổng thể (CPT + taxonomy, draft không đụng `_social_display`, tách provider khỏi renderer) **giữ nguyên**. Sửa / bổ sung dưới đây có ưu tiên hơn mục 3–8 khi mâu thuẫn.

### 11.1 Rủi ro quan trọng plan chưa nêu

1. **Landing affiliate đang hiển thị mọi attachment `_social_display=1`** (`[social_display … limit="23"]`, không scope, dòng 143 HTML). Mọi upload
   TikTok mới qua tool hiện tại đều lên trang này ngay — đây là lỗ "không duyệt" đang tồn tại, không chỉ rủi ro tương lai. Bước 2 nên sửa luôn:
   tool upload mặc định **không** set `_social_display=1` (thêm ô tích "hiện ngay ở shortcode cũ", mặc định tắt).
2. **pfbid không phải ID ổn định để dedup.** Cùng một bài Facebook có thể tới dưới dạng `/posts/pfbid…`, `/posts/<số>`, `permalink.php?story_fbid=…&id=…`,
   `/share/p/<mã>` (link chia sẻ, phải theo redirect mới ra bài), `/photo/?fbid=…`. Khóa dedup = **ID số do provider trả về** (`postId` / `story_fbid`);
   URL canonical chỉ là khóa tạm cho bản ghi nhập tay, và khi provider trả ID thì gộp bản ghi tạm vào. Link `share/p` resolve ở tool local (theo redirect có
   allowlist host `facebook.com`/`fb.watch`), không resolve trong WP.
3. **Draft không lộ qua shortcode ≠ ảnh draft là riêng tư.** File trong `wp-content/uploads` công khai theo URL; attachment có thể hiện ở
   `/?attachment_id=` và sitemap SEO. Ghi rõ: ảnh chưa có quyền sử dụng thì **không upload lên WP** (giữ ở tool local), chỉ lưu URL nguồn.
   Upload ảnh chỉ khi trạng thái quyền = đã cho phép.
4. **CPT phải không công khai**: `public => false`, `publicly_queryable => false`, `exclude_from_search`, `has_archive => false`, `rewrite => false`;
   kiểm sitemap WP core / plugin SEO không liệt kê CPT + 2 taxonomy (`public => false` cho taxonomy). Thêm vào tiêu chí nghiệm thu.
5. **Bỏ liên kết sản phẩm phải purge trang SP cũ.** Purge theo tập (product IDs trước ∪ sau khi lưu) bằng `rocket_clean_post()`; đổi collection /
   publish / unpublish dùng landing → `rocket_clean_post()` các page dùng collection đó (lưu danh sách page khi render lần đầu không đáng tin vì trang cache —
   MVP: `rocket_clean_domain()` khi đổi collection, chấp nhận vì hiếm).
6. **Loader**: shortcode mới trên landing HTML ở đĩa (`pages/{slug}/{slug}.html`, `template-router.php:93`) không nằm trong `post_content` → dò chuỗi
   shortcode của `tpc_loader_modules()` không thấy. Khai module `social_reviews` bằng `paths` (landing) + `condition` `is_product()`; đồng thời
   gộp `hithean_social_display_allowed()` cũ vào module này thay vì thêm một allowlist thứ hai. Đăng ký CPT/taxonomy là hook luôn chạy → file riêng
   trong `$general_includes` (theo CLAUDE.md), renderer nạp có điều kiện.
7. `products="current"` phải lấy `get_queried_object_id()` của trang SP, **không** `global $product` (bị đổi trong vòng lặp SP liên quan / upsell).

### 11.2 Chốt thiết kế dữ liệu

- **Quan hệ SP: taxonomy ẩn `sr_product`** (term slug `p-<product_id>`, tạo lazy khi gắn). Không cần relation table: quy mô dự kiến vài trăm review,
  `tax_query` dùng index `term_relationships`; relation table thêm migration/schema riêng mà không được lợi gì ở quy mô này. Variation → parent chuẩn hóa
  lúc lưu (lưu parent; nếu cần biến thể, thêm meta phụ, không thêm term). Xóa SP → term giữ lại, renderer bỏ SP không còn publish.
- **Tác giả: taxonomy ẩn `sr_author`**, slug = `<platform>-<author_id ổn định>`, tên hiển thị ở term name. Truy vấn "ai đã review SP X" = đếm term
  `sr_author` giao `sr_product` → đúng yêu cầu "số người" tách "số bài". Tác giả chưa có ID ổn định → không gán term (đếm vào "chưa xác định").
- **Collection: taxonomy ẩn `sr_collection`**; tạo sẵn `legacy` cho migration.
- **Một nguồn sự thật cho hiển thị = `post_status`**: `publish` = Đang hiển thị; `draft` = Mới nhập / Cần bổ sung; `pending` = Chờ duyệt;
  custom status `sr_hidden` = Đã ẩn (không dùng trash để giữ lịch sử). Cột Kanban "Mới nhập" vs "Cần bổ sung" suy ra từ dữ liệu thiếu (sản phẩm,
  quyền, excerpt), **không** lưu meta stage riêng → tránh 2 trường lệch nhau. Lỗi import là thuộc tính job của tool local, không phải trạng thái review.
- **Quyền**: `capability_type => ['social_review','social_reviews']` + `map_meta_cap`; cấp `edit_*` cho shop_manager, `publish_social_reviews`
  chỉ người duyệt (admin + người được chọn). Endpoint import chỉ tạo `draft`, server từ chối đặt `publish` qua import.
- **Dedup nguyên tử**: meta `_sr_source_key` = `sha1(platform|post_id)`; trước khi insert lấy khóa `$wpdb->get_var("SELECT GET_LOCK('sr_<key>', 5)")`
  → query → insert/update → `RELEASE_LOCK`. Không có khóa (timeout) → trả 409 để tool retry, không insert. Upload ảnh **sau** khi đã có review ID,
  attachment gắn `post_parent` + meta `_sr_asset_hash` (hash URL ảnh nguồn) → retry không upload trùng.
- **Scrape lại**: chỉ ghi nhóm "Nguồn" (snapshot text gốc, metrics, provider run ID, `_sr_scraped_at`); không bao giờ ghi excerpt, term SP/collection,
  status, ảnh bìa đã chọn.

### 11.3 Phạm vi & chi phí: đề xuất đổi thứ tự MVP

Khối lượng thực tế là **tuyển chọn** (vài chục bài / tháng), không phải thu thập đại trà. Vì vậy:

1. **MVP = nhập tay có hỗ trợ + oEmbed**, không phụ thuộc Apify: dán URL → canonical → (tùy chọn) dán caption/tên tác giả/ảnh chụp có quyền → gắn SP →
   draft. Hiển thị Facebook dạng **thẻ riêng** (tên, excerpt, ảnh nếu có quyền, nút "Xem bài viết"), embed SDK chỉ khi bấm. Lý do: oEmbed bài trang
   cá nhân thường render "nội dung không khả dụng" khi chủ bài giới hạn đối tượng; FB SDK nặng (~200 KB+, cookie bên thứ ba) không nên tải sẵn.
2. **Spike Apify** chạy song song nhưng **không chặn MVP**: 1 run, `resultsLimit ≤ 5`, đặt trần chi phí ở run options (`maxTotalChargeUsd`, cần xác nhận
   tên tham số trong schema hiện hành), chỉ link mẫu. Tiêu chí go: trả được post ID số + author ID + caption đầy đủ + ảnh cho link pfbid **và** link
   `share/p`. Không đạt → giữ nhập tay, không quét profile.
3. TikTok giữ pipeline hiện tại; chỉ thêm đường "gửi nháp review" song song với upload cũ.

### 11.4 Quyết định cần người dùng chốt trước khi code

1. **Đồng ý MVP nhập tay + oEmbed trước, Apify sau spike?** (khuyến nghị: có).
2. **Chính sách quyền sử dụng** cho bài trang cá nhân: chỉ link/embed khi chưa xin phép, ảnh + trích dẫn chỉ khi tác giả đồng ý (ghi người xác nhận + ngày)?
   Ai là người duyệt publish?
3. **Trình bày Facebook**: thẻ riêng (khuyến nghị) hay nhúng bài đầy đủ?
4. **Nơi đặt màn quản lý**: wp-admin (menu riêng, khuyến nghị — có sẵn quyền / REST / Media) hay hub `/tien-ich-admin`?
5. Có sửa ngay lỗ ở 11.1-1 (tool không tự hiện upload mới ở landing) trong bước 2 không?

### 11.5 Tiêu chí nghiệm thu bổ sung

- Cùng bài qua 3 dạng URL (pfbid / share/p / story_fbid) → 1 record khi provider trả ID; 2 request import đồng thời cùng bài → 1 record (test GET_LOCK).
- CPT + taxonomy không có trong sitemap, `/?post_type=social_review`, search; attachment ảnh chưa có quyền không tồn tại trên WP.
- Bỏ SP A khỏi review → trang SP A hết review sau purge; unpublish → landing hết review.
- Upload TikTok qua tool cũ không còn tự hiện ở landing affiliate (nếu chốt 11.4-5); `[social_display]` cũ vẫn ra đúng 23 media hiện có.
- `products="current"` trên trang SP có block SP liên quan vẫn đúng SP chính.

### 11.6 Thẻ shop The An Organics (đã kiểm checkout nguồn)

Đúng: thẻ đầu, logo `logo-thean-w200-no-paddings.png` (PNG đen nền trong suốt, hiển thị tốt trên nền trắng của `.anc-aff-partner`),
link `https://shopee.vn/theanorganics?tab=shop`, `rel="noopener"`, `aria-label` rõ. Mobile ≤920px lưới 1 cột → không tràn.
Lưu ý nhỏ: desktop lưới `repeat(4, …)` nay có 5 thẻ → hàng 2 lẻ 1 thẻ (CitySupp). Nếu muốn gọn: đổi sang `repeat(5, …)` ≥1100px hoặc
`repeat(auto-fit, minmax(150px, 1fr))`. Chưa sửa — chờ người dùng chọn. Chưa commit / deploy.

### 11.7 Quyết định người dùng 21/11: bỏ CPT, 1 review = 1 attachment

Người dùng chốt: **review không có ảnh thì bỏ qua; bài nhiều ảnh chỉ chọn 1 ảnh đại diện để hiển thị.** Mỗi review luôn có đúng 1 ảnh →
lý do chính để dùng CPT (0 hoặc nhiều ảnh / bài) không còn. **Thay mục 4 và 11.2 bằng mô hình dưới; phần còn lại của 11 giữ nguyên.**

- **Record = attachment ảnh đại diện** (giữ hướng hiện tại của tool TikTok + `[social_display]`). Không có CPT `social_review`.
- **Taxonomy `sr_product` / `sr_author` / `sr_collection` đăng ký cho `attachment`** (`public => false`, `show_in_rest` có auth,
  `update_count_callback => '_update_generic_term_count'` vì attachment ở status `inherit`). Truy vấn "ai review SP X" giữ nguyên như 11.2.
- **Trạng thái = meta `_sr_status`** (`draft` | `pending` | `publish` | `hidden`) vì attachment không có post_status dùng được. Một nguồn sự thật:
  renderer mới chỉ lấy `_sr_status = publish`. Kanban "Mới nhập / Cần bổ sung" vẫn suy ra từ dữ liệu thiếu. Quyền đặt `publish` kiểm ở
  `auth_callback` của meta + endpoint duyệt (`publish_social_reviews` hoặc `manage_woocommerce`), import chỉ ghi `draft`.
- **Tương thích cũ**: `_social_display = 1` hiện có = đã publish (migration ghi `_sr_status = publish`, gắn collection `legacy`, không đoán SP/tác giả).
  Ảnh nhập mới không set `_social_display` (giữ khuyến nghị 11.1-1).
- **Dedup**: meta `_sr_source_key` = `sha1(platform|post_id)` trên attachment + `GET_LOCK` như 11.2. Nhập lại cùng bài → cập nhật meta nguồn,
  **không upload ảnh mới**. Muốn đổi ảnh đại diện → thao tác riêng "Đổi ảnh" (upload ảnh mới, chuyển meta + term sang, gỡ meta/term khỏi ảnh cũ
  trong cùng khóa; ảnh cũ không xóa tự động).
- **Chọn ảnh đại diện ở tool local** (preview nhiều ảnh của bài → chọn 1 → chỉ upload ảnh đó). Bài không lấy được ảnh nào → bỏ qua, ghi lý do
  ở kết quả job, không tạo record.
- **Phải tự làm** (CPT cho sẵn, attachment thì không): màn quản lý riêng trong wp-admin (Kanban/bảng, đằng nào cũng cần), không có revisions →
  ghi `_sr_reviewed_by` / `_sr_reviewed_at` khi duyệt. Kiểm trang attachment công khai đã tắt (`wp_attachment_pages_enabled`) hoặc redirect
  về trang SP/landing, và sitemap SEO không liệt kê attachment.
- **Riêng tư**: ảnh đã upload luôn có URL công khai (với CPT cũng vậy) → vẫn chỉ upload ảnh đã có quyền dùng (11.1-3).
- Nghiệm thu thay 11.5 dòng 2: attachment draft không ra ở `[social_display]`, `[social_reviews]`, trang attachment, sitemap.

### 11.8 Quyết định người dùng 21/11 (trả lời 11.4)

1. **MVP nhập tay trước**; Apify chỉ spike sau, không chặn.
2. Chính sách quyền dùng ảnh + người duyệt: **còn mở** (đã giải thích lại cho người dùng, chờ chọn).
3. **Thẻ riêng** (ảnh đại diện + tên tác giả + excerpt) + link "Xem bài viết" sang bài gốc. **Không nhúng FB SDK / oEmbed** → bỏ phần embed ở
   mục 3 (đường 2), mục 6 và 7; oEmbed chỉ còn có thể dùng ở tool local để kiểm link công khai (không bắt buộc).
4. **Màn quản lý đặt trong ERP front (nhóm `tools`, `/tien-ich-admin`), không tải wp-admin**: render bằng module loader (path group `tools`),
   thao tác qua AJAX khai ở `ajax_actions`, kiểm nonce + capability; upload ảnh qua `media_handle_upload` trong AJAX, không dùng giao diện Media.
   Tool local vẫn gửi nháp qua REST (Application Password). Thay mọi chỗ "wp-admin" ở 11.2 / 11.7 bằng màn ERP này.
5. **Không sửa** việc tool TikTok cũ set `_social_display=1` (upload cũ vẫn hiện ngay ở `[social_display]`). Bỏ 11.1-1 và dòng nghiệm thu tương ứng ở 11.5.
   Hệ quả: `[social_display]` cũ không có bước duyệt; bước duyệt chỉ áp cho review nhập qua luồng mới và `[social_reviews]`.

**Ảnh cũ (`_social_display=1`) → gắn nhóm sản phẩm Yeast Hero** (thay "collection `legacy`, không đoán SP" ở 11.7). Thiết kế: review được gắn
**nhóm sản phẩm** (term `product_tag` / nhóm Yeast Hero, lưu ở taxonomy `sr_group`) ngoài từng SP; `products="current"` khớp khi SP hiện tại thuộc
nhóm đó → SP Yeast Hero mới tự có review cũ, không phải gắn lại từng ID. Cần xác nhận slug nhóm Yeast Hero thực tế trước khi chạy migration (dry-run
liệt kê số ảnh + SP thuộc nhóm). Ảnh cũ migrate thành `_sr_status = publish`, tác giả để trống.

## 12. Đối chiếu chuẩn code chung (24/11/2026)

Áp dụng `docs/coding-standards.md`; mục 11.7–11.8 có ưu tiên hơn các phương án cũ phía trên. Commit hiện tại chỉ gồm thẻ shop và tài liệu; các yêu cầu dưới đây dành cho triển khai, chưa phải chức năng đã có.

Checklist 2.9:

1. **Nơi sở hữu (mục 1):** attachment là review theo quyết định người dùng; `_sr_status` là trạng thái duy nhất của luồng mới. Giữ luồng `_social_display` cũ theo 11.8; không tạo CPT hoặc bản sao review song song.
2. **Dữ liệu tăng theo thời gian (2.1):** job/import log không lưu bằng mảng option. Tool local dùng kho bền vững có từng job/item; nếu cần log trên WP thì dùng bảng riêng có phiên bản và chính sách giữ.
3. **Song song (2.2):** mọi đường ghi review qua một hàm domain. Khóa MySQL phải gồm `$wpdb->prefix`, tên ≤64 ký tự, dùng `GET_LOCK(name, 0)` và giải phóng trong `finally`; khóa bận trả lỗi retry. Quy tắc này thay ví dụ khóa chưa có prefix/timeout 5 ở 11.2. Nếu tạo bảng khóa tự nhiên thì dùng UNIQUE và upsert, không query-rồi-insert thiếu khóa.
4. **Không lưu thừa (2.3):** sản phẩm đọc từ WooCommerce; quan hệ review nằm ở taxonomy; không sao chép danh mục/thuộc tính SP vào review để truy vấn. Snapshot bài nguồn và excerpt biên tập có mục đích khác nhau, không ghi đè nhau.
5. **Migration (2.4):** dry-run, checkpoint, giới hạn lô, chạy lại được, giữ ảnh/meta cũ; xác minh nhóm Yeast Hero thực tế trước apply. Không xóa nguồn khi chuyển lỗi.
6. **Query/index (2.5):** ghi rõ truy vấn theo SP/nhóm/tác giả/trạng thái trước triển khai; dùng quan hệ taxonomy có index, LIMIT, prefetch meta/media. Kiểm chi phí lọc `_sr_status` trên dữ liệu thật, không suy ra hiệu năng chỉ từ code.
7. **Ranh giới (2.6):** WooCommerce qua API công khai; local tool → WP qua API import có quyền và chỉ tạo nháp; không đọc/ghi bảng nội bộ plugin khác.
8. **Dọn dữ liệu (2.8):** trước triển khai chốt thời hạn giữ log/job và múi giờ; cleanup theo lô có LIMIT, không xóa ảnh/review đang dùng. Chưa có quyết định retention của người dùng.
9. **Một đường ghi (mục 1):** nhập tay ERP, REST local, retry và migration dùng chung domain service cho validation, dedup, trạng thái, quan hệ và cache invalidation. Hash file dùng SHA-256 nội dung tại một hàm chung (2.7); hash URL ảnh chỉ là tín hiệu phụ, thay đề xuất `_sr_asset_hash` chỉ dựa URL ở 11.2.
10. **Tự động hóa (2.6):** không auto-publish ở luồng mới; scrape trả phí chỉ chạy theo phạm vi được chọn. Không thay luồng TikTok cũ tự hiện theo quyết định 11.8; ngoại lệ legacy này phải hiển thị rõ trong UI/tài liệu.

Mục 3/6: khai đủ đường nạp AJAX/REST, kiểm nonce/quyền, escape output, không nuốt lỗi upload/meta. Mục 4: test chống trùng, draft leakage, scope sản phẩm/nhóm và cache; đổi helper dùng chung phải chạy toàn bộ test. Mục 7/8: stage file cụ thể, giữ việc khác, không secrets, không sửa tài liệu sinh tự động. Phương án chưa áp dụng ngoại lệ chuẩn dữ liệu nào ngoài việc bảo toàn hành vi legacy do người dùng yêu cầu ở 11.8.

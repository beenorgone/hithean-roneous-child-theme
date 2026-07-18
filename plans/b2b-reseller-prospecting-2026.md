# B2B Reseller Prospecting Pipeline — hithean.com (2026)

Mục tiêu: tự động tìm các **nhà bán lẻ / reseller** ngành thực phẩm bổ sung – thể thao (giống `shopee.vn/onfit_official` + `onfit.vn`) để chào sỉ sản phẩm hithean (protein thuần chay, siêu thực phẩm bổ sung). Tối giản human-in-the-loop: mục tiêu ~90% tự động, người chỉ duyệt danh sách gửi.

**Kiến trúc cốt lõi: 1 engine, N Search Job.** Người dùng nhập nhiều bộ tiêu chí (nhóm lead) khác nhau; mỗi job chạy độc lập qua cùng pipeline 4 tầng với keyword, vùng, ICP, ngưỡng điểm, kênh & góc chào riêng.

Benchmark ICP mẫu: **onfit** — retailer đa kênh (Shopee shop + website riêng), multi-brand supplement.

---

## 0. Nguyên tắc thiết kế

- **Engine tách khỏi tiêu chí**: pipeline không hard-code ICP. Mọi tiêu chí nằm trong Search Job (config) → thêm nhóm lead mới = tạo job mới, không sửa code.
- **Ưu tiên nguồn dữ liệu ổn định** (API chính thức) làm xương sống; nguồn scrape (Shopee/TikTok) làm bổ sung vì dễ vỡ.
- **1 cổng duyệt duy nhất** ở tầng Outreach, duyệt theo batch — không auto-gửi cold message.
- **Idempotent & dedupe toàn cục**: shop định danh bằng `platform + shop_id`; 1 shop có thể khớp nhiều job nhưng chỉ tồn tại 1 record; chống gửi trùng ở mức shop.
- **Mọi tầng ghi vào CRM** (CPT `b2b_prospect`) → theo dõi như sales pipeline.

---

## 1. Search Jobs — nhiều nhóm lead chạy độc lập ★

Đây là lớp điều khiển người dùng nhập vào. Mỗi **Search Job** = 1 nhóm lead có bộ tiêu chí riêng. Ví dụ 4 job chạy song song:

| Job | Nhóm lead | Keyword chính | Góc chào (pitch base) | Kênh |
|---|---|---|---|---|
| A | Retailer supplement đa kênh (kiểu onfit) | whey, protein, pre-workout, EAA | Lấp gap vegan+superfood, không đụng whey họ đang bán | Zalo/Call |
| B | Cửa hàng thực phẩm chay / eatclean | thực phẩm chay, healthy food, eatclean | Protein thuần chay nội địa, kiểm nghiệm đầy đủ | Email/Zalo |
| C | Shop đồ chạy bộ / endurance | chạy bộ, marathon, trail | Superfood greens + phục hồi thuần chay cho runner | Zalo |
| D | Phòng gym / PT bán lẻ | phòng gym, personal trainer, CLB thể hình | Phân phối cho hội viên, biên sỉ tốt | Call/Zalo |

### 1.1 CPT `b2b_search_job` — schema

| Field | Key | Kiểu | Ý nghĩa |
|---|---|---|---|
| Tên nhóm lead | `sj_name` | text | vd "Retailer supplement đa kênh" |
| Trạng thái | `sj_status` | select (draft/active/paused) | Chỉ `active` mới chạy theo lịch |
| Keyword seed | `sj_keywords` | textarea (mỗi dòng 1 từ) | Đầu vào tầng Discover |
| Nguồn dữ liệu | `sj_sources` | checkbox (gmaps/serpapi/shopee/tiktok/lazada) | Bật/tắt nguồn cho job này |
| Vùng địa lý | `sj_regions` | text/multiselect | Tỉnh/thành; mặc định toàn quốc |
| **Mô tả ICP** | `sj_icp_desc` | textarea | Khách lý tưởng — nhồi vào prompt AI scoring |
| Tín hiệu ưu tiên | `sj_positive` | textarea | vd "multi-brand", "thiếu vegan", "có web riêng" |
| Tín hiệu loại trừ | `sj_negative` | textarea | vd "brand tự SX", "dropship TQ", "bỏ hoang" |
| Ngưỡng điểm | `sj_min_score` | number 0–100 | Chỉ lead ≥ ngưỡng mới vào hàng chờ outreach |
| Kênh ưu tiên | `sj_channel` | select | Zalo/Email/Call/Chat |
| Góc chào gốc | `sj_pitch_base` | textarea | Value prop cho nhóm này → nhồi vào prompt soạn tin |
| Lịch chạy | `sj_schedule` | select (manual/weekly/biweekly) | Cron cadence |
| Lần chạy cuối | `sj_last_run` | datetime | Auto |
| Thống kê | `sj_stats` | json | discovered/enriched/scored/contacted của job |

### 1.2 Cách nhập (UI)
- Trang admin ERP: **danh sách jobs** (card mỗi job: tên, trạng thái, số lead, nút Chạy ngay / Tạm dừng).
- Form tạo/sửa job = đúng các field trên. Người dùng chỉ điền text, không cần code.
- Nút **"Nhân bản job"** để tạo nhóm mới từ nhóm cũ (chỉ đổi keyword + pitch).

### 1.3 Job chạy độc lập như thế nào
- Mỗi job có **queue riêng** và cấu hình riêng ở cả 4 tầng:
  - Discover dùng `sj_keywords` + `sj_sources` + `sj_regions` của job.
  - Score nhồi `sj_icp_desc` + `sj_positive/negative` vào prompt → **cùng 1 shop có thể được chấm khác điểm ở 2 job khác nhau**.
  - Outreach dùng `sj_pitch_base` + `sj_channel` của job → tin chào khác nhau theo nhóm.
- Chạy song song, không chặn nhau; lỗi 1 job không ảnh hưởng job khác.

---

## 2. ICP — tín hiệu nhận diện (mặc định, job override được)

"Tương tự onfit" = **reseller**, KHÔNG phải người mua lẻ. Tín hiệu:

| Tín hiệu | Trọng số | Ý nghĩa |
|---|---|---|
| Bán whey/protein/vitamin/superfood | Cao | Cùng ngành, tệp khách trùng |
| Có sàn **+ website riêng** | Cao | Retailer thực thụ, dễ nhập sỉ |
| Multi-brand store | Cao | Sẵn sàng thêm SKU mới |
| **Thiếu** dòng thuần chay / superfood | Rất cao | Khoảng trống = góc chào hithean |
| Doanh số / review / follower cao | Trung | Đối tác chất lượng |
| Contact công khai | Trung | Chi phí sales thấp |

Trọng số này là **mặc định**; mỗi job tinh chỉnh qua `sj_positive/negative` + `sj_icp_desc`.

---

## 3. Kiến trúc pipeline 4 tầng

```
┌─ SEARCH JOBS (người nhập tiêu chí) ─────────────────────────┐
│  Job A   Job B   Job C   Job D   … (mỗi job = 1 bộ tiêu chí) │
└──────────┬──────────────────────────────────────────────────┘
           │ mỗi job chạy độc lập qua cùng engine ▼
[1] DISCOVER → [2] ENRICH → [3] SCORE → [4] OUTREACH
 (auto 100%)   (auto ~60%)  (auto,AI)  (soạn auto · 1 cổng duyệt)
           │        │           │            │
           └────────┴───────────┴────────────┘
        Ghi vào CPT b2b_prospect (CRM, tag theo job)
```

---

## 4. Data model — CPT `b2b_prospect`

Đăng ký theo pattern ERP hithean. Meta fields:

| Field | Key | Kiểu | Nguồn |
|---|---|---|---|
| **Thuộc job(s)** | `bp_matched_jobs` | relation (multi) | Discover |
| Tên shop | `bp_shop_name` | text | Discover |
| Nền tảng | `bp_platform` | select | Discover |
| Shop ID (định danh) | `bp_platform_shop_id` | text | Discover |
| URL gian hàng | `bp_shop_url` | url | Discover |
| Website riêng | `bp_website` | url | Enrich |
| Danh mục / Brand đang bán | `bp_categories` / `bp_brands` | csv | Discover |
| Có dòng vegan? | `bp_has_vegan` | bool | Score |
| Follower / doanh số | `bp_scale_metric` | number | Discover |
| SĐT / Email / Zalo / Địa chỉ | `bp_phone` / `bp_email` / `bp_zalo` / `bp_address` | — | Enrich |
| **Điểm fit theo job** | `bp_scores` | json `{job_id: score}` | Score |
| Lý do + góc chào theo job | `bp_pitch` | json `{job_id: {reason, angle, channel}}` | Score |
| Trạng thái pipeline | `bp_stage` | select | mọi tầng |
| Ngày liên hệ cuối | `bp_last_contact` | date | Outreach |

**Dedupe:** unique theo `bp_platform + bp_platform_shop_id`. Nếu shop khớp job mới → thêm job vào `bp_matched_jobs` + chấm điểm riêng cho job đó, **không tạo record trùng**.

**Stages:** `new → enriched → scored → queued (chờ duyệt) → contacted → replied → negotiating → won / lost / disqualified`.

**Chống gửi trùng:** 1 shop khớp nhiều job → chỉ gửi outreach của job có `bp_score` cao nhất trong cửa sổ X ngày (throttle mức shop, không mức job).

---

## 5. Tầng 1 — DISCOVER (auto 100%)

Chạy per-job với `sj_keywords` × `sj_regions` × `sj_sources`.

### 5.1 Nguồn & độ ổn định

| Nguồn | Cách | Ổn định |
|---|---|---|
| **Google Maps Places API** | Text Search keyword × tỉnh → cửa hàng có SĐT | ★★★★★ xương sống |
| **SerpAPI / Google Search** | Dork `site:shopee.vn <kw>`, `"đại lý" whey`… | ★★★★ |
| **Shopee** | API nội bộ search_items → shop_detail | ★★☆ bổ sung |
| **TikTok / Lazada** | Search seller theo keyword | ★★ phase sau |

Output mỗi shop → upsert CPT với `bp_stage=new`, gắn `bp_matched_jobs += job`.

### 5.2 Chống bot Shopee
Cookie + proxy VN + rate-limit 1 req/3–5s; fallback SerpAPI khi API đổi. **Shopee là nice-to-have** — Gmaps+SerpAPI đã đủ phủ retailer có web riêng.

---

## 6. Tầng 2 — ENRICH (auto ~60%)

Tìm contact: scrape website → regex `0\d{9}`/email/`zalo.me` → đối chiếu Gmaps. Thiếu contact → flag `needs_manual_contact` (không im lặng bỏ qua).

---

## 7. Tầng 3 — SCORE (AI, auto, per-job)

`claude-haiku-4-5` cho scoring hàng loạt. Prompt **nhồi tiêu chí của job**:

```
Bạn là chuyên viên phát triển kênh B2B của hithean. Đánh giá 1 nhà bán lẻ cho nhóm lead:
"{sj_name}". Khách lý tưởng: {sj_icp_desc}
Tín hiệu ưu tiên: {sj_positive}  |  Loại trừ: {sj_negative}

Dữ liệu shop: {bp_shop_name}, nền tảng {bp_platform}, web {bp_website},
danh mục {bp_categories}, brand {bp_brands}, quy mô {bp_scale_metric}

Trả JSON: { "fit_score":0-100, "has_vegan":bool,
  "fit_reason":"1 câu", "pitch_angle":"1-2 câu cụ thể cho shop này",
  "recommended_channel":"zalo|email|call|marketplace_chat" }
```

Ghi vào `bp_scores[job_id]` + `bp_pitch[job_id]`; `bp_stage=scored` nếu ≥ `sj_min_score`.

---

## 8. Tầng 4 — OUTREACH (soạn auto · 1 cổng duyệt batch)

`claude-sonnet-5` soạn tin per-job dùng `sj_pitch_base` + `sj_channel` + link catalog B2B.

| Kênh | Định dạng | Ràng buộc |
|---|---|---|
| Zalo OA / ZNS | Tin ngắn + link | ZNS cần template duyệt; xem memory `zalo-mini-app` |
| Email | Subject + HTML | Có unsubscribe |
| Gọi điện | Script + talking points | Không auto-gọi, xuất cho sales |
| Chat sàn | Tin ngắn | ⚠️ Auto-gửi vi phạm TOS → chỉ xuất draft |

**Cổng duyệt (human gate DUY NHẤT):** trang admin list lead `scored`, **lọc theo job**, sort điểm desc, preview tin (sửa inline). Nút "Duyệt & gửi batch" → Zalo/Email auto gửi; Call/Chat xuất danh sách việc. Sau gửi: `bp_stage=contacted`.

---

## 9. Tự động hóa & lịch chạy

- Script per-job trong `content-publishing/` hoặc WP-CLI: `wp hithean prospect:run --job=<id>`.
- Cron đọc mọi job `sj_status=active` theo `sj_schedule` riêng → chạy độc lập → đẩy hàng chờ → báo "job X có N lead chờ duyệt".
- Idempotent qua `platform+shop_id`; re-score khi danh mục shop đổi.

---

## 10. Lộ trình

| Phase | Nội dung | Kiểm chứng |
|---|---|---|
| P1 — Tuần 1 | CPT `b2b_search_job` + `b2b_prospect` + Discover từ Google Maps | 1 job chạy ra 200–500 lead có SĐT |
| P2 — Tuần 2 | Form nhập job (UI) + Enrich + đa nguồn (SerpAPI/Shopee) | Tạo được ≥3 job khác tiêu chí |
| P3 — Tuần 3 | AI Scoring per-job + ranked | Cùng shop chấm khác điểm ở 2 job |
| P4 — Tuần 4 | Soạn outreach 4 kênh + trang duyệt lọc theo job | Gửi thử 20 lead top của 1 job |
| P5 — Tuần 5+ | Cron per-job + TikTok/Lazada + dashboard | Nhiều job chạy nền song song |

**MVP = P1 với 1 job (Google Maps).**

---

## 11. Rủi ro & human-in-the-loop còn lại

| Rủi ro | Xử lý |
|---|---|
| Shopee đổi API / chống bot | Dựa Gmaps+SerpAPI; Shopee bổ sung |
| ~40% shop không lộ contact | Flag `needs_manual_contact`, tìm tay |
| Cold outreach bị chặn spam | Cổng duyệt batch bắt buộc |
| 1 shop khớp nhiều job → gửi trùng | Throttle mức shop, gửi job điểm cao nhất |
| Chi phí AI khi scale nhiều job | Haiku cho scoring, Sonnet chỉ soạn tin |

**Human còn lại:** (1) nhập/chỉnh tiêu chí job, (2) tìm contact ~40% lead thiếu, (3) duyệt batch outreach, (4) gọi/chat theo script. Discover/Enrich/Score/soạn tin = tự động.

---

## Phụ lục — Tận dụng hạ tầng sẵn có

| Có sẵn | Dùng vào |
|---|---|
| `custom-plugins` AI agent (Claude API) | Score + soạn tin |
| ERP CPT pattern (`custom-functions/erp/`) | `b2b_search_job` + `b2b_prospect` + trang duyệt |
| Kế hoạch Zalo OA (memory `zalo-mini-app`) | Kênh Zalo/ZNS |
| `content-publishing/` | Scraper + cron per-job |
| Module `/b2b/` (tham chiếu ivar) | Link catalog sỉ trong tin chào |

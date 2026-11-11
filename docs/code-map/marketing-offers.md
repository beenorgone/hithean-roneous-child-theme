<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `marketing/offers`

> Trang ưu đãi /uu-dai/: lưu trữ, bóc tách AI, thẻ ưu đãi, structured data, worker nền.

9 file · ~1.700 dòng · 83 hàm (41% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `hithean_offers_status` | admin.php |
| admin-post | `hithean_offers_action` | admin.php |
| cron | `hithean_offers_boundary` | store.php |
| cron | `hithean_offers_lark_flush` | lark-notify.php |
| cron | `hithean_offers_work` | worker.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `hithean_offers_cache_purged` | store.php |

## Hook khu này lắng nghe

`add_option_hithean_offers_snapshot` · `admin_enqueue_scripts` · `admin_menu` · `hithean_offers_lark_flush` · `template_redirect` · `update_option_hithean_offers_snapshot`

## File

### `marketing/offers/admin.php`

| Hàm | Mô tả |
|---|---|
| `hithean_offers_form_start(string $action, array $hidden = [])` | — |
| `hithean_offers_admin_title(array $row, $product)` | Human-readable labels also cover existing addon records without re-syncing sources. |
| `hithean_offers_admin()` | — |
| `hithean_offers_progress(array $run)` | — |

### `marketing/offers/cli.php`

Class: `Hithean_Offers_CLI` — Collect and inspect product offers using the same background queue as admin.

### `marketing/offers/extract.php`

| Hàm | Mô tả |
|---|---|
| `hithean_offers_text(string $text)` | — |
| `hithean_offers_dates(string $text)` | No year inference. Only dates actually present in the source are accepted. |
| `hithean_offers_candidate(string $text, string $kind, bool $safe, string $scope = 'product', string $reason = '', ?…)` | — |
| `hithean_offers_parse(string $html)` | The expected product title + summary distinguish a product from a soft error page. |
| `hithean_offers_node_text(DOMNode $node)` | — |
| `hithean_offers_ai(array $snippets)` | AI selects evidence, never controls dates, scope, publication, tools or URLs. |

### `marketing/offers/lark-notify.php`

Thông báo nhóm Lark khi trang /uu-dai/ đổi chương trình ưu đãi.

| Hàm | Mô tả |
|---|---|
| `hithean_offers_lark_settings()` | — |
| `hithean_offers_lark_webhook()` | Webhook dùng thật: riêng của ưu đãi, trống thì webhook nhóm xuất kho. '' = chưa có webhook hợp lệ. |
| `hithean_offers_lark_webhook_for(string $own)` | — |
| `hithean_offers_lark_programs(array $items)` | Snapshot (dòng đã bung theo sản phẩm) → tóm tắt theo chương trình: [id => name, kind, family, start, end, products [id => tên]]. Hàm thuần — có test. |
| `hithean_offers_lark_diff(array $old, array $new)` | So 2 tóm tắt → ['added' => [...], 'changed' => [[old, new, changes[]]], 'ended' => [...]]. Hàm thuần — có test. |
| `hithean_offers_lark_is_empty(array $diff)` | — |
| `hithean_offers_lark_date(int $ts)` | — |
| `hithean_offers_lark_period(array $program)` | — |
| `hithean_offers_lark_names(array $products, int $max = 4)` | Tối đa $max tên SP, còn lại "+N SP". |
| `hithean_offers_lark_message(array $diff, array $ctx)` | Nội dung tin (Lark "post": tiêu đề + các dòng chữ / link). Hàm thuần — có test. $ctx: url (trang /uu-dai/), admin_url, total_programs, total_products, preview (trang chưa công k… |
| `hithean_offers_lark_current()` | — |
| `hithean_offers_lark_context(array $programs, bool $test = false)` | — |
| `hithean_offers_lark_post(array $message)` | POST tin lên webhook. '' = thành công, ngược lại là lỗi (không chứa URL webhook). |
| `hithean_offers_lark_schedule()` | Snapshot vừa đổi → hẹn gửi sau HITHEAN_OFFERS_LARK_DELAY (gom các thay đổi liền nhau). |
| `hithean_offers_lark_flush()` | So snapshot hiện tại với mốc đã báo, gửi 1 tin nếu khác. Thành công → dời mốc. |
| `hithean_offers_lark_test()` | Nút "Gửi thử": gửi danh sách đang áp dụng như "chương trình mới", không đổi mốc. |
| `hithean_offers_lark_reset_baseline()` | Bật lần đầu (hoặc đổi webhook): ghi mốc = snapshot hiện tại để tin đầu chỉ chứa thay đổi về sau. |

### `marketing/offers/offer-card.php`

Thẻ ưu đãi dùng chung (.hithean-offer-card, CSS: marketing/assets/offer-card.css). Cùng cấu trúc với thẻ popup "Ưu đãi đang áp dụng" ở trang sản phẩm (product-purchase.js → rend…

| Hàm | Mô tả |
|---|---|
| `hithean_offer_card_icon(string $icon)` | — |
| `hithean_offer_card_html(array $card)` | $card: badge, title, price_html?, media ['src'\|'icon'], tiers [[label, range, price_html]], extra (text), notes [string], until (timestamp hết hiệu lực — JS trang /uu-dai/ ẩn th… |
| `hithean_offer_card_wdr_tiers(array $config)` | Bậc giá Discount Rules (bulk) → danh sách bậc như bảng của plugin trên trang sản phẩm. |
| `hithean_offer_cards_from_rows(array $rows)` | Các ưu đãi (dòng snapshot) của MỘT sản phẩm → thẻ theo đúng kiểu popup trang sản phẩm. $rows: các dòng snapshot đã gộp theo sản phẩm (hithean_offers_shortcode → $catalog). |

### `marketing/offers/public.php`

| Hàm | Mô tả |
|---|---|
| `hithean_offers_is_page()` | — |
| `hithean_offers_enqueue()` | — |
| `hithean_offers_date_label(array $row)` | — |
| `hithean_offers_logo()` | — |
| `hithean_offers_group_label(array $row)` | Names a shared quantity/shipping rule after its category filters; an unfiltered rule covers the whole shop. |
| `hithean_offers_group_dialog(array $row, string $id)` | Compact product list for a group card; prices are read live so the modal never shows a stale price. |
| `hithean_offers_gift_strips(array $offers)` | Dòng "Mua [ảnh SP] TẶNG [ảnh quà]" — mỗi quà tặng kèm khác nhau một dòng. |
| `hithean_offers_card(array $row)` | — |
| `hithean_offers_product_card(array $offers)` | Compact product preview; full offers use the shared product-page dialog and card styles. |
| `hithean_offers_visible_items()` | Active snapshot rows; preview mode limits them to managers. |
| `hithean_offers_shortcode()` | — |

### `marketing/offers/store.php`

| Hàm | Mô tả |
|---|---|
| `hithean_offers_lock(string $name, int $ttl = 600)` | Atomic option locks, including compare-and-delete for an expired owner. |
| `hithean_offers_unlock(string $name, string $token)` | — |
| `hithean_offers_records(int $product_id = 0)` | — |
| `hithean_offers_save(array $data)` | — |
| `hithean_offers_audit(int $id, string $action)` | — |
| `hithean_offers_status(array $row, ?int $now = null)` | — |
| `hithean_offers_reconcile(int $product_id, array $candidates, string $source)` | Per-product reconciliation only after a valid, complete page read. |
| `hithean_offers_publish()` | A single option replacement is the public snapshot commit. |
| `hithean_offers_gift_strip(array $row, $product)` | Quà tặng kèm (Product Addon, loại product): ảnh nhỏ SP mua + SP tặng cho dòng "Mua A TẶNG B" trên card. |
| `hithean_offers_banner_id(int $id)` | Banner chương trình: meta riêng (ID ảnh) nên đồng bộ nguồn ghi đè `_ho_data` không làm mất. |
| `hithean_offers_banner_src(int $id)` | [url, rộng, cao] của banner (cỡ large — giữ nguyên tỉ lệ ảnh gốc). |
| `hithean_offers_set_banner(int $id, int $attachment)` | — |
| `hithean_offers_purge()` | — |
| `hithean_offers_cleanup()` | — |
| `hithean_offers_record_lock_name(array $row)` | — |
| `hithean_offers_upsert_source(array $source, string $run_id)` | A source ID is stable across catalog membership changes; its terms hash gates approval. |
| `hithean_offers_finish_structured(array $run)` | — |
| `hithean_offers_review_date(string $value, bool $end)` | — |
| `hithean_offers_review(int $id, string $key, string $action, array $fields)` | One decision on the canonical rule controls every associated product. |

### `marketing/offers/structured.php`

Read-only source adapters. One stored offer per source rule, many product links.

| Hàm | Mô tả |
|---|---|
| `hithean_offers_json($value)` | — |
| `hithean_offers_fingerprint(array $data)` | — |
| `hithean_offers_money($amount)` | — |
| `hithean_offers_plugin_active(string $directory)` | — |
| `hithean_offers_catalog(array $ids)` | — |
| `hithean_offers_wdr_date($timestamp)` | WDR stores wall-clock timestamps, formatted with gmdate in its own Rule helper. |
| `hithean_offers_source(string $source_id, string $family, array $config, array $ids, string $title, string $kind…)` | — |
| `hithean_offers_wdr_rows()` | — |
| `hithean_offers_wdr_match(array $rule, $product)` | Match product filters only; cart/customer eligibility is recorded separately, never simulated. |
| `hithean_offers_wdr_text(array $raw)` | Public text is generated from values, not internal rule titles or private coupon names. |
| `hithean_offers_wdr_sources(array $rows, array $products)` | — |
| `hithean_offers_addon_schedule(int $id, array $choice)` | Ngày bắt đầu / kết thúc khai thật trong plugin Product Addon (ô chung của sản phẩm + cột ngày của từng dòng). Null khi plugin cũ / không khai ngày nào → đoán từ chữ như cũ. |
| `hithean_offers_product_sources($product)` | — |
| `hithean_offers_wheel_summary()` | — |
| `hithean_offers_wheel_sources(array $products)` | — |
| `hithean_offers_sync_task($task, array $run)` | — |

### `marketing/offers/worker.php`

| Hàm | Mô tả |
|---|---|
| `hithean_offers_start(int $product_id = 0)` | — |
| `hithean_offers_allowed_url(string $url)` | — |
| `hithean_offers_fetch(string $url)` | — |
| `hithean_offers_scan_product(int $id)` | — |
| `hithean_offers_work()` | — |
| `hithean_offers_stop_requested(string $run_id)` | — |


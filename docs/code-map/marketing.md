<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `marketing`

> Marketing: popup, thanh menu đáy + "Ưu đãi của tôi", vòng quay may mắn, CRO landing agent (chạy theo lịch).

6 file · ~1.600 dòng · 106 hàm (20% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `hithean_sfm_featured` | site-footer-menu.php |
| AJAX công khai | `hithean_sfm_featured` | site-footer-menu.php |
| CPT | `hithean_offer` | offers.php |
| admin-post | `hithean_cro_action` | cro-landing-agent.php |
| cron | `hithean_cro_landing_weekly` | cro-landing-agent.php |
| cron | `hithean_offers_weekly` | offers.php |
| shortcode | `[hithean_offers]` | offers.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `hithean_cro_capability` | cro-landing-agent.php |
| `hithean_cro_paid_order_statuses` | cro-landing-agent.php |
| `hithean_enable_popup_widget_on_product_taxonomy` | popup-widget.php |
| `hithean_enable_popup_widget_on_shop` | popup-widget.php |
| `hithean_enqueue_popup_widget` | popup-widget.php |
| `hithean_sfm_has_bar` | site-footer-menu.php |
| `hithean_sfm_items` | site-footer-menu.php |

## Hook khu này lắng nghe

`admin_enqueue_scripts` · `admin_menu` · `body_class` · `hithean_cro_landing_weekly` · `hithean_offers_boundary` · `hithean_offers_weekly` · `hithean_offers_work` · `init` ×3 · `switch_theme` · `template_redirect` · `wp_enqueue_scripts` ×4 · `wp_footer` · `⟨'wp_ajax_hithean_my_offers_' . $hithean_mo_action⟩` · `⟨'wp_ajax_nopriv_hithean_my_offers_' . $hithean_mo_action⟩`

## File

### `marketing/cro-landing-agent.php`

Hithean CRO Landing Agent.

| Hàm | Mô tả |
|---|---|
| `hithean_cro_capability()` | — |
| `hithean_cro_can_manage()` | — |
| `hithean_cro_reports_table()` | — |
| `hithean_cro_cards_table()` | — |
| `hithean_cro_defaults()` | — |
| `hithean_cro_settings()` | — |
| `hithean_cro_paths()` | — |
| `hithean_cro_lark_webhook(string $url)` | — |
| `hithean_cro_install()` | — |
| `hithean_cro_next_monday_nine()` | — |
| `hithean_cro_schedule()` | — |
| `hithean_cro_b64url(string $value)` | — |
| `hithean_cro_service_account()` | — |
| `hithean_cro_ga4_token()` | — |
| `hithean_cro_ga4_report(string $start, string $end, array $metrics, string $event = '')` | — |
| `hithean_cro_rows(string $start, string $end)` | — |
| `hithean_cro_wc_summary(string $start, string $end)` | — |
| `hithean_cro_percent(float $numerator, float $denominator)` | — |
| `hithean_cro_insights(array $current, array $previous)` | — |
| `hithean_cro_insight(string $path, string $type, string $title, string $evidence, string $hypothesis, string $…)` | — |
| `hithean_cro_optional_ai(array $insights, array $rows)` | — |
| `hithean_cro_create_cards(int $report_id, array $insights)` | — |
| `hithean_cro_period()` | — |
| `hithean_cro_acquire_lock()` | — |
| `hithean_cro_release_lock()` | — |
| `hithean_cro_previous_rows(string $start)` | — |
| `hithean_cro_run(bool $force = false)` | — |
| `hithean_cro_send_lark(int $report_id)` | — |
| `hithean_cro_admin_menu()` | — |
| `hithean_cro_admin_assets(string $hook)` | — |
| `hithean_cro_admin_post()` | — |
| `hithean_cro_render_admin()` | — |
| `hithean_cro_form_open(string $action)` | — |
| `hithean_cro_render_settings(array $s)` | — |
| `hithean_cro_render_overview(?array $report)` | — |
| `hithean_cro_render_board(string $table)` | — |

### `marketing/lucky-wheel.php`

| Hàm | Mô tả |
|---|---|
| `thean_lw_default_settings()` | — |
| `thean_lw_default_rewards()` | — |
| `thean_lw_default_trigger_rules()` | — |
| `thean_lw_sanitize_css_value(string $value)` | — |
| `thean_lw_normalize_trigger_rule(array $rule)` | — |
| `thean_lw_get_trigger_rules()` | — |
| `thean_lw_url_pattern_matches(string $pattern, string $path)` | — |
| `thean_lw_feature_dir()` | — |
| `thean_lw_feature_url()` | — |
| `thean_lw_asset(string $basename)` | — |
| `thean_lw_can_manage()` | — |
| `thean_lw_get_settings()` | — |
| `thean_lw_is_enabled()` | — |
| `thean_lw_offer_slugs()` | — |
| `thean_lw_coupon_hold_hours()` | — |
| `thean_lw_coupon_ttl()` | — |
| `thean_lw_cleanup_skip_used_coupons()` | — |
| `thean_lw_sheets_webhook_url()` | — |
| `thean_lw_sheets_webhook_secret()` | — |
| `thean_lw_trigger_config()` | — |
| `thean_lw_reward_wheel_label(array $reward)` | — |
| `thean_lw_normalize_positive_ids($raw)` | — |
| `thean_lw_normalize_term_slugs($raw)` | — |
| `thean_lw_normalize_reward(array $reward)` | — |
| `thean_lw_rewards()` | — |
| `thean_lw_active_rewards()` | — |
| `thean_lw_is_feature_ajax_request()` | — |

### `marketing/my-offers.php`

"Ưu đãi của tôi" — mã ưu đãi riêng của khách (panel của thanh menu đáy, site-footer-menu.php).

| Hàm | Mô tả |
|---|---|
| `hithean_mo_normalize_contact(string $raw)` | — |
| `hithean_mo_identity(string $type, string $value)` | Cùng công thức thean_lw_claim_identity_hash() (lucky-wheel/ui.php) — ui.php chỉ nạp khi vòng quay bật. |
| `hithean_mo_phone_variants(string $phone)` | — |
| `hithean_mo_find_users(array $contact)` | Tài khoản gắn với email / SĐT (email tài khoản, billing email/phone, user_login SĐT của đăng nhập SMS). |
| `hithean_mo_user_identities(int $user_id)` | Định danh của 1 tài khoản: user + email tài khoản / billing + SĐT billing / SĐT đăng nhập SMS. |
| `hithean_mo_contact_identities(array $contact)` | Định danh mở rộng của 1 liên hệ khách nhập: chính nó + tài khoản gắn với nó. |
| `hithean_mo_mask(array $contact)` | — |
| `hithean_mo_session_key(bool $create)` | — |
| `hithean_mo_clean_contacts($list)` | — |
| `hithean_mo_get_contacts()` | Liên hệ đã nhớ. Đăng nhập: gộp phần của phiên vào user meta (rồi xóa ở phiên). |
| `hithean_mo_save_contacts(array $contacts)` | — |
| `hithean_mo_coupon_ids(array $identities, int $limit = 30)` | ID coupon khớp định danh: mã vòng quay (hash / user / liên hệ cũ) + coupon giới hạn email. Mới nhất trước. |
| `hithean_mo_coupon_usable(WC_Coupon $coupon)` | Coupon còn dùng được (còn hạn, còn lượt). |
| `hithean_mo_coupon_title(WC_Coupon $coupon)` | — |
| `hithean_mo_mask_code(string $code)` | — |
| `hithean_mo_coupon_card(WC_Coupon $coupon, bool $verified, string $via, bool $can_email)` | Thẻ ưu đãi (cùng kiểu thẻ trang SP / /uu-dai/ — offer-card.php). $verified: hiện mã đầy đủ + Sao chép / Áp dụng. $via: liên hệ (đã che) mà mã được tìm thấy qua — chỉ hiện với mã… |
| `hithean_mo_session_wheel_code()` | Mã vòng quay vừa nhận trong phiên này (state của lucky-wheel) — chỉ khi module vòng quay đã nạp. |
| `hithean_mo_collect()` | Toàn bộ dữ liệu panel. Trả mã ĐÃ XÁC MINH (codes) để AJAX apply kiểm lại. |
| `hithean_mo_rate_ok(string $bucket, int $limit, int $ttl)` | — |
| `hithean_mo_check_nonce()` | — |
| `hithean_mo_response(string $message = '')` | — |
| `hithean_mo_ajax_load()` | — |
| `hithean_mo_ajax_lookup()` | — |
| `hithean_mo_ajax_forget()` | — |
| `hithean_mo_ajax_send()` | Gửi mã đầy đủ về chính các email khách đã tra (chỉ mã khớp email đó) — người giữ hộp thư mới đọc được. |
| `hithean_mo_ajax_apply()` | Áp mã vào giỏ — chỉ mã đã xác minh của chính người gọi. (lucky-wheel.php nạp ui.php cho action này để chạy luật 1 mã vòng quay / đơn.) |

### `marketing/offers.php`

Structured offers index. Expensive work only runs in background/CLI.

| Hàm | Mô tả |
|---|---|
| `hithean_offers_settings()` | — |
| `hithean_offers_sync_schedule()` | Migrate the former daily event without changing saved publication/automation switches. |

### `marketing/popup-widget.php`

| Hàm | Mô tả |
|---|---|
| `hithean_should_enqueue_popup_widget()` | — |
| `popup_widget_enqueue_scripts()` | — |

### `marketing/site-footer-menu.php`

Thanh menu dính đáy toàn site (trang chủ, /shop + các trang KHÔNG phải: sản phẩm, danh mục, giỏ / thanh toán / cảm ơn, trang đã có thanh đáy riêng). Điều kiện nạp: tpc_cond_site…

| Hàm | Mô tả |
|---|---|
| `hithean_sfm_my_offers_enabled()` | — |
| `hithean_sfm_has_bar()` | Trang có thanh đáy riêng → chỉ in panel "Ưu đãi của tôi". |
| `hithean_sfm_on_offers_page()` | Đang ở trang /uu-dai/ (hoặc trang có shortcode [hithean_offers]). |
| `hithean_sfm_offers_url()` | — |
| `hithean_sfm_items()` | — |
| `hithean_sfm_icon(string $key)` | — |
| `hithean_sfm_featured_cards(int $limit = 6)` | "Ưu đãi tiêu biểu" (popup nút "Nhận ưu đãi"): 1 thẻ / chương trình từ snapshot /uu-dai/ — ưu tiên chương trình áp dụng nhiều sản phẩm, rồi quà tặng → mua nhiều → giá ưu đãi → vậ… |
| `hithean_sfm_wheel_card()` | Thẻ vòng quay (in sẵn ở footer, chỉ trang có vòng quay) — cùng thẻ vòng quay của popup trang SP. |
| `hithean_sfm_featured_ajax()` | AJAX: nội dung popup "Ưu đãi tiêu biểu" — tải khi mở (trang có thể cache, snapshot đổi theo lịch). Chỉ đọc, không nonce. |
| `hithean_sfm_login_url()` | Trang đăng nhập (tài khoản) — quay lại đúng trang đang xem + mở panel sau khi đăng nhập. |
| `hithean_sfm_render_bar()` | — |
| `hithean_sfm_render_offers()` | Popup "Ưu đãi tiêu biểu": khung giống hệt popup "Ưu đãi đang áp dụng" trang SP (product-purchase.js → makeDialog). |
| `hithean_sfm_render_panel()` | Khung panel: nội dung riêng của khách nạp bằng AJAX (hithean_my_offers_load). Nút đăng nhập giống trang thanh toán. |


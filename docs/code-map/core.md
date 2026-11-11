<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `core`

> Hạ tầng theme: module loader (what-loads-when), nạp theo màn admin, template router, enqueue, guard upload/file/CSP, AI settings/providers, địa chỉ VN, thông tin công ty, admin chrome.

20 file · ~4.500 dòng · 141 hàm (38% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| admin-post | `hithean_company_info_export` | company-info-settings.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `child_theme_lark_channel_enabled` | lark.php |
| `child_theme_lark_settings_fields` | lark.php |
| `child_theme_lark_settings_save` | lark.php |
| `hithean_enqueue_swiper_slider` | swiper-slider.php |
| `hithean_sfm_enabled` | module-loader.php |
| `hithean_social_display_page_slugs` | module-loader.php |
| `litespeed_purge_all` | theme-code-cache.php |
| `mfg_revision_meta_keys` | cpt-revisions.php |
| `mfg_revision_taxonomies` | cpt-revisions.php |
| `theme_ai_fallback_chain` | ai-providers.php |
| `theme_ai_feature_registry` | ai-settings.php |
| `theme_ai_limit_cooldown` | ai-providers.php |
| `theme_vn_address_cities_dirs` | vn-address.php |
| `theme_vn_address_province_aliases` | vn-address.php |
| `tpc_embed_allow_origins` | site-guards.php |
| `tpc_loader_modules` | module-loader.php |
| `tpc_loader_path_groups` | module-loader.php |
| `⟨$module['paths_filter']⟩` | module-loader.php |

## Hook khu này lắng nghe

`_wp_post_revision_fields` · `admin_enqueue_scripts` ×2 · `admin_init` ×5 · `admin_menu` · `admin_notices` · `after_setup_theme` ×2 · `after_switch_theme` · `edit_user_profile_update` · `get_user_option_admin_color` · `hithean_top_bar_after` · `init` ×7 · `nocache_headers` · `personal_options_update` · `postmeta_form_keys` · `register_post_type_args` · `save_post` · `send_headers` · `template_include` · `template_redirect` · `user_register` · `woocommerce_email` · `woocommerce_email_order_details` · `wp` ×2 · `wp_enqueue_scripts` ×4 · `wp_footer` · `wp_handle_upload_prefilter` · `wp_login` · `wp_restore_post_revision` · `wp_save_post_revision_check_for_changes` · `⟨'_wp_post_revision_field_' . $field⟩` · `⟨'_wp_post_revision_field_' . $key⟩`

## File

### `core/admin-chrome/admin-chrome.php`

Browser-local navigation toggle for standard WordPress backend screens.

| Hàm | Mô tả |
|---|---|
| `hithean_admin_chrome_enqueue()` | — |

### `core/admin.php`

| Hàm | Mô tả |
|---|---|
| `load_custom_admin_files()` | — |
| `child_theme_get_default_admin_color_scheme()` | — |
| `child_theme_set_user_admin_color_scheme(int $user_id)` | — |
| `child_theme_force_default_admin_color_scheme($color = '')` | — |
| `child_theme_apply_default_admin_color_to_current_user()` | — |
| `child_theme_apply_default_admin_color_on_login($user_login, $user)` | — |
| `child_theme_apply_default_admin_color_to_new_user($user_id)` | — |
| `child_theme_migrate_default_admin_color_scheme()` | — |

### `core/ai-providers.php`

| Hàm | Mô tả |
|---|---|
| `theme_ai_get_api_key(string $provider)` | AI provider abstraction dùng chung cho các feature trong theme. Hỗ trợ Gemini, OpenAI (Chat Completions / Responses), Claude (Anthropic), Qwen (Alibaba Cloud Model Studio / Dash… |
| `theme_ai_get_model(string $provider)` | Model mặc định theo provider. Feature nên truyền $model riêng khi gọi; đây chỉ là fallback khi feature không chỉ định. |
| `theme_ai_providers()` | Danh sách provider hợp lệ (dùng chung cho settings + resolve). |
| `theme_ai_resolve_provider(string $requested)` | 'auto' → chọn provider đầu tiên đang có API key (ưu tiên claude → gemini → openai). |
| `theme_ai_call_provider(string $provider, string $system, array $messages, int $max_tokens = 2000, string $model…)` | Entry point chat text. Trả về text trả lời hoặc WP_Error. |
| `theme_ai_gemini_thinking_config(string $model)` | thinkingConfig tương thích theo đời model Gemini: 2.x nhận thinkingBudget (0 = tắt hẳn); 3.x nhận thinkingLevel (không tắt hẳn được — dùng mức thấp nhất; bản pro không có 'minim… |
| `theme_ai_gemini_apply_options(array $payload, array $options)` | Chỉ thêm các built-in tool Gemini đã được allowlist rõ ràng. Dùng object rỗng để wp_json_encode tạo đúng `google_search: {}` theo REST API. |
| `theme_ai_call_gemini(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000,…)` | — |
| `theme_ai_call_openai(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)` | — |
| `theme_ai_call_claude(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)` | — |
| `theme_ai_call_provider_with_documents(string $provider, string $system, string $prompt, array $documents, int $max_tokens = 200…)` | Entry point cho extraction từ tập tin (ảnh / PDF): tự chọn provider như theme_ai_call_provider nhưng gửi kèm documents. |
| `theme_ai_read_document(array $document, string $provider)` | — |
| `theme_ai_call_claude_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $ma…)` | — |
| `theme_ai_call_gemini_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $ma…)` | — |
| `theme_ai_call_openai_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $ma…)` | — |
| `theme_ai_qwen_endpoint()` | — |
| `theme_ai_qwen_vision_model()` | Model đọc ảnh — model text (qwen-plus...) không nhận ảnh. |
| `theme_ai_qwen_request(string $api_key, array $payload, int $timeout)` | — |
| `theme_ai_call_qwen(string $api_key, string $model, string $system, array $messages, int $max_tokens = 2000)` | — |
| `theme_ai_call_qwen_with_documents(string $api_key, string $model, string $system, string $prompt, array $documents, int $ma…)` | Chỉ hỗ trợ ảnh (qwen-vl-*); PDF trả lỗi để fallback/feature xử lý. |
| `theme_ai_extra_api_keys(string $provider)` | Key phụ cho cùng provider (VD nhiều key Gemini free) trong wp-config.php: define('GEMINI_API_KEYS_EXTRA', ['key2', 'key3']); // hoặc chuỗi 'key2,key3' Tương tự QWEN_API_KEYS_EXT… |
| `theme_ai_fallback_chain(string $primary, string $model = '')` | Danh sách [provider, model, api_key] thử lần lượt: provider chính (key chính → key phụ), sau đó các provider khác đang có key. Key trùng nhau chỉ thử 1 lần (VD gemini_billing ch… |
| `theme_ai_is_limit_error($error)` | Lỗi do key hết quota / bị rate limit / provider quá tải → nên chuyển sang key khác. |
| `theme_ai_cooldown_transient(array $entry)` | — |
| `theme_ai_call_with_fallback(string $provider, string $model, callable $call, ?array &$used = null)` | Gọi AI có fallback: key bị limit → đánh dấu nghỉ một lúc (transient) và thử key/provider kế tiếp. Key đang nghỉ được bỏ qua ở các request sau để không tốn thêm 1 lượt gọi hỏng;… |
| `theme_ai_feature_call(array $cfg, callable $call, ?array &$used = null)` | Entry point cho feature: $cfg = ['provider' => ..., 'model' => ..., 'pinned' => bool]. pinned = feature tự chọn chính xác provider (constant riêng / setting riêng) → gọi đúng pr… |
| `theme_ai_parse_json_object(string $text)` | Bóc JSON object đầu tiên từ text AI trả về (chịu được ```json fence, chú thích thừa). |
| `theme_ai_escape_json_string_controls(string $json)` | Escape ký tự điều khiển (\n, \r, \t...) nằm TRONG chuỗi JSON; giữ nguyên whitespace giữa các token. |

### `core/ai-settings.php`

| Hàm | Mô tả |
|---|---|
| `theme_ai_feature_registry()` | Registry các tính năng AI của site. Feature mới thêm 1 entry ở đây (hoặc qua filter 'theme_ai_feature_registry') là tự xuất hiện trên trang settings. |
| `theme_erp_settings()` | — |
| `theme_ai_feature_enabled(string $feature)` | — |
| `theme_ai_default_provider()` | — |
| `theme_ai_default_model()` | — |
| `theme_erp_settings_sanitize($input)` | — |
| `hithean_pcn_render_settings_tab()` | — |
| `theme_erp_settings_render_page()` | — |

### `core/backend-optimize.php`

### `core/company-info-settings.php`

| Hàm | Mô tả |
|---|---|
| `hithean_company_info_fields()` | — |
| `hithean_company_info_default_settings()` | — |
| `hithean_company_info_get_settings()` | — |
| `hithean_company_info_custom_map()` | key => value phẳng của các cặp tuỳ chỉnh (mục "Thông tin tuỳ chỉnh"). |
| `hithean_company_info_get(string $field)` | — |
| `hithean_company_info_sanitize_settings($input)` | — |
| `hithean_company_info_export_url()` | — |
| `hithean_company_info_handle_import()` | Xử lý import JSON (upload file hoặc dán trực tiếp) — chấp nhận và ghi đè toàn bộ field đã biết, field lạ/JSON hỏng bị bỏ qua (sanitize callback lọc). Trả về mảng settings đã lưu… |
| `hithean_company_info_render_settings_tab()` | — |

### `core/cpt-revisions.php`

| Hàm | Mô tả |
|---|---|
| `hithean_revision_meta_keys()` | Meta keys cần snapshot vào post revisions. |
| `hithean_revision_taxonomies()` | Product taxonomies cần snapshot cùng revision. |
| `hithean_revision_term_meta_key(string $taxonomy)` | — |
| `hithean_revision_get_meta_value(int $post_id, string $key)` | — |
| `hithean_revision_get_term_value(int $post_id, string $taxonomy)` | — |
| `hithean_revision_copy_meta_to_revision(int $post_id, int $revision_id)` | — |
| `hithean_save_post_meta_revision(int $post_id)` | — |
| `enable_revisions_for_all_custom_post_types_with_meta()` | Kích hoạt revisions và theo dõi meta cho mọi CPT (bao gồm không public). |

### `core/email-overrides.php`

| Hàm | Mô tả |
|---|---|
| `hroneous_force_email_order_details_template($emails)` | Force WooCommerce invoice/order-details emails to use child-theme override. This avoids fallback to plugin/default template when other hooks interfere. |
| `hroneous_render_custom_email_order_details($order, $sent_to_admin, $plain_text, $email)` | — |

### `core/enqueue.php`

| Hàm | Mô tả |
|---|---|
| `roneous_child_enqueue_styles()` | — |
| `hithean_enqueue_consolidated_fonts()` | — |
| `roneous_child_language_setup()` | — |
| `add_pragma_no_cache_header($headers)` | — |
| `custom_theme_menus()` | — |

### `core/functional.php`

| Hàm | Mô tả |
|---|---|
| `ct_get_order_item_display_name($item)` | — |

### `core/language-switcher.php`

| Hàm | Mô tả |
|---|---|
| `hithean_language_switcher_is_english()` | Nút "Eng" / "Vi" toàn site, dịch bằng Google Website Translator (client-side). Xem plans/multi-language-google-translate-switch.md cho quyết định kiến trúc và giới hạn đã biết (… |
| `hithean_language_switcher_enqueue()` | — |
| `hithean_language_switcher_markup(string $extra_class = '')` | Toggle 2 nhánh "VI \| EN", khớp kiểu segmented control của theme (xem css/language-switcher.css). Dùng chung cho cả bản render trong nav (desktop) và bản fixed cho mobile — xem 2… |

### `core/lark.php`

Lark — kết nối dùng chung cho mọi thông báo của site (GIỐNG HỆT hithean ↔ theanmarket, sửa thì chép sang cả 2).

| Hàm | Mô tả |
|---|---|
| `child_theme_lark_register_channel(string $key, array $def)` | — |
| `child_theme_lark_load()` | — |
| `child_theme_lark_channel_defs()` | — |
| `child_theme_lark_settings()` | — |
| `child_theme_lark_channel_config(string $key)` | Cấu hình lưu của 1 kênh (chưa xét dự phòng). |
| `child_theme_lark_save_channel(array $channel)` | Ghi cấu hình 1 kênh, giữ nguyên các khóa khác của erp_lark_settings (app_id, event_map… của bộ ERP). |
| `child_theme_lark_sanitize_webhook($value)` | Webhook bot hợp lệ hoặc ''. |
| `child_theme_lark_sanitize_chat_id($value)` | chat_id Lark App (oc_…) hợp lệ hoặc ''. |
| `child_theme_lark_app_available()` | App API có mặt (bộ Lark ERP — phương án C). |
| `child_theme_lark_target(string $key)` | Đích gửi thật của kênh: ['type' => webhook\|chat_id, 'value' => …, 'via' => key kênh cung cấp đích] hoặc error. Kênh tắt → không gửi; bật mà để trống đích → dùng đích của kênh dự… |
| `child_theme_lark_label(string $key)` | — |
| `child_theme_lark_ready(string $key)` | Kênh bật và có đích hợp lệ. |
| `child_theme_lark_response_error($response)` | Phản hồi Lark → '' (thành công) hoặc lỗi không chứa URL webhook. |
| `child_theme_lark_send(string $key, array $payload)` | Gửi 1 tin (payload đúng dạng webhook Lark: msg_type + content \| card) vào kênh. '' = Lark đã nhận, ngược lại là lỗi. |
| `child_theme_lark_text(string $key, string $text)` | — |
| `child_theme_lark_build_card(array $data)` | Thẻ Lark từ dữ liệu cùng dạng erp_lark_notify() của bộ ERP: title, body (Lark markdown), status info\|success\|warning\|danger\|neutral, url, actions [[label, url, type]], fields [[… |
| `child_theme_lark_notify(string $key, array $data)` | — |
| `child_theme_lark_log(string $key, string $error, string $type, string $via)` | Nhật ký gửi: lần gần nhất mỗi kênh + 30 lượt gần nhất. Không lưu URL / nội dung tin. |
| `child_theme_lark_last(string $key)` | — |
| `child_theme_lark_migrate()` | Chuyển webhook riêng của từng tính năng (trước khi có kênh) sang kênh, 1 lần. Không đè kênh đã có. kho ← *_export_lark_settings (enabled + webhook_url); shopee_ai ← spx_ai_alert… |
| `child_theme_lark_settings_tab(string $page_url)` | Tab Cài đặt ERP → Lark: kênh của mọi tính năng + ô riêng của tính năng (hook) + gửi thử + nhật ký. |

### `core/module-loader.php`

| Hàm | Mô tả |
|---|---|
| `child_theme_lark_register_channel(string $key, array $def)` | — |
| `child_theme_lark_load()` | — |
| `child_theme_lark_should_preload()` | — |
| `hithean_social_display_allowed()` | Social display shortcodes ([social_fan] / [social_marquee] / [social_collage] / [social_coverflow]) chỉ nạp ở: trang chủ, page "an-new-chapter", page "ve-chung-toi", và trang sả… |
| `social_display_register_media_meta()` | — |
| `child_theme_should_load_post_editor_tools()` | — |
| `tpc_loader_require_relative($relative_path)` | — |
| `tpc_loader_normalize_path($path)` | — |
| `tpc_loader_current_path()` | — |
| `tpc_loader_path_groups()` | — |
| `tpc_loader_expand_module_paths(array $module)` | — |
| `tpc_loader_front_path_matches(array $module)` | — |
| `tpc_loader_current_ajax_action()` | — |
| `tpc_loader_modules()` | — |
| `tpc_cond_is_product()` | — |
| `tpc_cond_is_product_taxonomy()` | — |
| `tpc_cond_is_checkout()` | — |
| `tpc_cond_site_footer_menu()` | Thanh menu đáy toàn site: trang chủ + mọi trang trừ trang sản phẩm, cửa hàng / danh mục (đã có thanh riêng), giỏ / thanh toán / cảm ơn (flow mua hàng), landing ANC / Yeast Hero… |
| `tpc_loader_front_module_matches(array $module)` | — |
| `tpc_loader_content_has_shortcode($content, $shortcode)` | Phát hiện shortcode trong nội dung mà KHÔNG dùng has_shortcode(). has_shortcode() yêu cầu shortcode đã được đăng ký, nhưng file đăng ký lại chính là file ta đang quyết định có n… |
| `tpc_loader_load_front_modules()` | — |
| `tpc_loader_load_admin_modules()` | Module gắn cờ 'admin' được nạp ngay ở include-time (không qua hook) để kịp đăng ký admin_menu/admin_init. Bỏ qua trong admin-ajax (đã có ajax loader lo). |
| `tpc_loader_load_ajax_modules()` | — |
| `tpc_loader_load_cron_modules()` | Module có 'cron_hooks' được nạp trong request WP-Cron (ngoài ngữ cảnh 'wp') để callback của event đã hẹn tồn tại khi cron chạy. |

### `core/public-file-guard.php`

| Hàm | Mô tả |
|---|---|
| `hithean_is_sensitive_wp_content_log_request()` | — |
| `hithean_block_sensitive_wp_content_logs()` | — |
| `hithean_wp_content_log_htaccess_rules()` | — |
| `hithean_ensure_wp_content_log_htaccess()` | — |
| `hithean_admin_notice_wp_content_log_htaccess()` | — |

### `core/site-guards.php`

### `core/swiper-slider.php`

| Hàm | Mô tả |
|---|---|
| `hithean_should_enqueue_swiper_slider()` | — |
| `enqueue_swiper_slider()` | — |

### `core/template-router.php`

| Hàm | Mô tả |
|---|---|
| `hithean_locate_grouped_template($template_name)` | Grouped template router: cho phép đặt single-*.php trong singles/, archive-*.php trong archives/, template-*.php trong templates/. |
| `hithean_load_grouped_template($template)` | — |
| `hithean_landing_page_html_path($post = null)` | File HTML chứa nội dung của page dùng template "Landing Page" (pages/{slug}/{slug}.html). Trả '' nếu page không dùng template đó. |
| `hithean_singular_raw_content($post = null)` | Nội dung thô của trang đơn, dùng để dò shortcode/markup khi nạp module hoặc enqueue asset có điều kiện. Page landing render từ file HTML trong theme (không phải post_content) nê… |

### `core/theme-code-cache.php`

| Hàm | Mô tả |
|---|---|
| `thean_theme_git_version()` | — |
| `thean_theme_code_version()` | — |
| `thean_theme_purge_cache()` | — |
| `thean_theme_maybe_purge_code_cache()` | — |

### `core/upload-guard.php`

| Hàm | Mô tả |
|---|---|
| `hithean_upload_guard_check_content($file)` | Chặn upload file chứa mã PHP / có extension thực thi trên server. |

### `core/vn-address.php`

| Hàm | Mô tả |
|---|---|
| `theme_vn_address_cities_dir()` | Thư mục cities chứa data ĐỊNH DẠNG MỚI (2 cấp, 34 tỉnh). Trong plugin gốc, cities/ gốc là bộ CŨ (63 tỉnh, 3 cấp — nhận diện bằng file xa_phuong_thitran.php), bộ mới nằm ở cities… |
| `theme_vn_address_provinces()` | — |
| `theme_vn_address_all_wards()` | Toàn bộ phường/xã theo tỉnh. Đọc JSON (nhẹ hơn parse PHP array 500KB), fallback quan_huyen.php nếu JSON thiếu. |
| `theme_vn_address_wards(string $matp)` | — |
| `theme_vn_address_normalize(string $text)` | Chuẩn hóa chuỗi địa danh để so khớp: bỏ dấu, thường hóa, bỏ tiền tố đơn vị hành chính ở đầu chuỗi (Thành phố/Tỉnh/Phường/Xã/TP./P./X./TT.), bỏ ký tự không phải chữ-số. |
| `theme_vn_address_match_province(string $text)` | So khớp text tự do (AI/người gõ) với danh mục tỉnh/thành. |
| `theme_vn_address_match_ward(string $matp, string $text)` | So khớp text tự do với danh mục phường/xã của một tỉnh. So cả bản đầy đủ ("Phường Ba Đình") lẫn bản bỏ tiền tố ("Ba Đình"). |


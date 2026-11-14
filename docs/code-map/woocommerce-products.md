<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `woocommerce/products`

> Sản phẩm: trang SP, mua hàng, lượt bán, tab, taxonomy, nhãn dinh dưỡng, popup giỏ hàng, nhúng hồ sơ IVAR.

18 file · ~8.200 dòng · 209 hàm (33% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `hithean_atc_popup` | add-to-cart-popup.php |
| AJAX | `hithean_atc_popup_qty` | add-to-cart-popup.php |
| AJAX | `hithean_atc_popup_remove` | add-to-cart-popup.php |
| AJAX | `hithean_atc_popup_view` | add-to-cart-popup.php |
| AJAX | `hithean_product_linking_search` | product-linking.php |
| AJAX | `hithean_product_purchase_quote` | product-purchase.php |
| AJAX | `hithean_sc_extract` | sales-count.php |
| AJAX | `hithean_sc_preview_shortcode` | sales-count.php |
| AJAX công khai | `hithean_atc_popup` | add-to-cart-popup.php |
| AJAX công khai | `hithean_atc_popup_qty` | add-to-cart-popup.php |
| AJAX công khai | `hithean_atc_popup_remove` | add-to-cart-popup.php |
| AJAX công khai | `hithean_atc_popup_view` | add-to-cart-popup.php |
| AJAX công khai | `hithean_product_purchase_quote` | product-purchase.php |
| CPT | `cong-thuc` | recipe-post-type.php |
| CPT | `product-tab` | product-tab-post-type.php |
| admin-post | `hithean_product_linking_cleanup_legacy_meta` | product-linking.php |
| admin-post | `hithean_proof_cache_refresh` | proof-embed-cache.php |
| admin-post | `hithean_sc_capture` | sales-count.php |
| admin-post | `hithean_sc_capture_save` | sales-count.php |
| admin-post công khai | `hithean_sc_capture` | sales-count.php |
| cron | `hithean_proof_cache_refresh` | proof-embed-cache.php |
| cron | `hithean_proof_cache_sweep` | proof-embed-cache.php |
| shortcode | `[hithean_sales_count]` | sales-count.php |
| shortcode | `[product_nutrition_label]` | product-nutrition-label.php |
| taxonomy | `bo-suu-tap` | recipe-post-type.php |

## Hook do khu này phát (điểm mở rộng)

Module khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.

| Hook | File |
|---|---|
| `advanced_woo_discount_rules_get_product_discount_price_from_custom_price` | product-list-page.php, product-purchase.php |
| `hithean_apfm_items` | product-archive-footer-menu.php |
| `hithean_ecom_buy_button_inner` | product-page.php |
| `hithean_ecom_card_after_info` | product-page.php |
| `hithean_ecom_modal_after_desc` | product-page.php |
| `hithean_proof_cache_ttl` | proof-embed-cache.php |
| `hithean_sc_ai_config` | sales-count-admin.php |
| `woocommerce_add_to_cart_fragments` | add-to-cart-popup.php |
| `woocommerce_cart_item_name` | add-to-cart-popup.php |
| `woocommerce_sale_flash` | product-list-page.php |
| `woocommerce_single_product_summary` | product-purchase.php |
| `woocommerce_update_cart_validation` | add-to-cart-popup.php |

## Hook khu này lắng nghe

`add_option_hithean_oos_loop_rules` · `admin_enqueue_scripts` ×2 · `admin_footer` · `admin_head` · `admin_init` ×3 · `admin_menu` ×2 · `admin_notices` · `all_admin_notices` · `body_class` ×2 · `edit_form_after_title` · `hithean_after_product_chat_cta` · `hithean_before_product_chat_ctas` ×2 · `hithean_ecom_buy_button_inner` · `hithean_ecom_modal_after_desc` · `hithean_proof_cache_refresh` · `hithean_proof_cache_sweep` · `init` ×5 · `ivar_shortcodes_guide` · `mb_relationships_init` · `post_row_actions` · `posts_clauses` · `pre_option_woocommerce_cart_redirect_after_add` · `rwmb_meta_boxes` ×4 · `save_post_product` ×2 · `shortcode_atts_products` · `template_redirect` · `theme_ai_feature_registry` · `update_option_hithean_oos_loop_mode` · `update_option_hithean_oos_loop_rules` · `woocommerce_add_to_cart` · `woocommerce_add_to_cart_fragments` · `woocommerce_add_to_cart_redirect` · `woocommerce_after_shop_loop_item` ×2 · `woocommerce_after_shop_loop_item_title` ×6 · `woocommerce_before_add_to_cart_button` ×2 · `woocommerce_before_shop_loop_item_title` · `woocommerce_get_price_html` ×2 · `woocommerce_loop_add_to_cart_args` · `woocommerce_loop_add_to_cart_link` · `woocommerce_post_class` · `woocommerce_product_tabs` ×3 · `woocommerce_related_products` · `woocommerce_shortcode_products_query` · `woocommerce_single_product_summary` ×2 · `wp_enqueue_scripts` ×7 · `wp_footer` ×7 · `wp_get_attachment_image_attributes` · `wp_head` ×3 · `⟨"created_{$tax}"⟩` · `⟨"edited_{$tax}"⟩` · `⟨"{$tax}_add_form_fields"⟩` · `⟨"{$tax}_edit_form_fields"⟩`

## File

### `woocommerce/products/add-to-cart-popup.php`

| Hàm | Mô tả |
|---|---|
| `hithean_atc_popup_view_ajax()` | — |
| `hithean_atc_popup_remove_ajax()` | — |
| `hithean_atc_popup_qty_ajax()` | — |
| `hithean_atc_popup_item_max_qty(WC_Product $product)` | Số lượng tối đa của 1 dòng giỏ: 0 = không giới hạn. |
| `hithean_atc_popup_cart_payload()` | Nội dung popup chế độ xem + số lượng + cart hash (JS dùng hash để biết bản lưu tạm còn đúng không). |
| `hithean_atc_cart_icon_svg()` | Icon giỏ hàng dùng chung cho nút mở popup (sticky trang SP + sticky footer menu archive). |
| `hithean_atc_cart_count_html()` | Số lượng SP trong giỏ cho badge nút giỏ hàng; khóa fragment theo class → WC / popup thay khi giỏ đổi. |
| `hithean_atc_popup_ajax()` | — |
| `hithean_atc_popup_fragments()` | — |
| `hithean_atc_popup_render_cart(string $added_key)` | — |
| `hithean_atc_popup_loop_button_args($args, $product)` | — |
| `hithean_atc_popup_is_enabled()` | — |
| `hithean_atc_popup_enqueue_assets()` | — |
| `hithean_atc_popup_render_modal()` | — |

### `woocommerce/products/out-of-stock-loop.php`

| Hàm | Mô tả |
|---|---|
| `hithean_oos_loop_modes()` | — |
| `hithean_oos_loop_sanitize_mode($value)` | — |
| `hithean_oos_loop_mode()` | — |
| `hithean_oos_loop_normalize_path(string $value)` | Hàm thuần: URL / đường dẫn → khóa so khớp ('' = trang chủ), bỏ query, /page/N/, giữ `*` cuối. |
| `hithean_oos_loop_mode_for_path(string $path, string $default, array $rules)` | Hàm thuần: chế độ cho đường dẫn — khớp đúng trước, rồi nhánh `*` dài nhất, không khớp → mặc định. |
| `hithean_oos_loop_sanitize_rules($input)` | — |
| `hithean_oos_loop_rules()` | — |
| `hithean_oos_loop_current_mode()` | Chế độ của trang đang xem (chế độ chung + ngoại lệ theo đường dẫn). |
| `hithean_oos_loop_attr_mode($value)` | Thuộc tính het_hang của shortcode: an / hide → 'hide', cuoi / last → 'last', khác → ''. |
| `hithean_oos_loop_clauses(array $clauses, string $mode, string $posts_table, string $postmeta_table)` | Hàm thuần: thêm JOIN `_stock_status` + xếp cuối (last) hoặc lọc bỏ (hide) SP 'outofstock'. SP không có meta `_stock_status` coi như còn hàng. |
| `hithean_oos_loop_is_target_query($query)` | Query này có phải danh sách SP ở front (catalog chính hoặc shortcode Woo đã gắn cờ) không. |
| `hithean_oos_loop_render_settings()` | Form ở Cài đặt ERP → WooCommerce. |

### `woocommerce/products/product-archive-footer-menu.php`

| Hàm | Mô tả |
|---|---|
| `hithean_apfm_icon_svg(string $key)` | — |
| `hithean_apfm_get_items()` | Item của thanh menu. type: 'scroll' (cuộn tới grid), 'link' (URL), 'compare' (mở modal). Có thể thêm/bớt qua filter 'hithean_apfm_items'. |
| `hithean_apfm_get_page_products()` | Danh sách gọn các sản phẩm đang hiển thị trên trang (main query) — không truy vấn thêm. |
| `hithean_apfm_enqueue_assets()` | — |
| `hithean_apfm_render()` | — |
| `hithean_apfm_render_compare_modal()` | — |

### `woocommerce/products/product-linking.php`

| Hàm | Mô tả |
|---|---|
| `hithean_product_linking_parse_csv($csv_data)` | Product linking is configured globally by CSV. CSV rows: group,product_id,label_1,value_1,label_2,value_2 Header row is optional. |
| `hithean_product_linking_encode_csv_row(array $row)` | — |
| `hithean_product_linking_get_global_csv_data()` | — |
| `hithean_product_linking_cache_version()` | — |
| `hithean_product_linking_get_all_global_rows()` | — |
| `hithean_product_linking_get_global_index()` | — |
| `hithean_product_linking_clear_all_cache()` | — |
| `hithean_product_linking_sanitize_global_csv($input)` | — |
| `hithean_product_linking_register_settings()` | — |
| `hithean_product_linking_register_settings_page()` | — |
| `hithean_product_linking_render_settings_page()` | — |
| `hithean_product_linking_legacy_meta_keys()` | — |
| `hithean_product_linking_cleanup_legacy_meta()` | — |
| `hithean_product_linking_ajax_product_search()` | — |
| `hithean_product_linking_render_global_builder_tool()` | — |
| `hithean_product_linking_clear_cache($post_id)` | — |
| `hithean_product_linking_get_group_rows_by_product($product_id)` | — |
| `hithean_product_linking_find_group_ids($product_id)` | — |
| `hithean_product_linking_get_options($product_id)` | — |
| `hithean_product_linking_get_option_labels($options)` | — |
| `hithean_product_linking_get_current_label($options, $product_id, $field)` | — |
| `hithean_product_linking_render_group($options, $product_id, $field, $label)` | — |
| `hithean_product_linking_render()` | — |
| `hithean_product_linking_enqueue_assets()` | — |

### `woocommerce/products/product-list-page.php`

| Hàm | Mô tả |
|---|---|
| `hithean_is_product_listing_context()` | — |
| `hithean_dequeue_cart_fragments_off_conversion_pages()` | — |
| `hithean_prioritize_first_catalog_product_image(array $attr, WP_Post $attachment, $size)` | — |
| `product_list_display_loop_coming_soon_text()` | Show a coming soon label below title when a product has no price. |
| `product_list_display_loop_out_of_stock_text()` | Show out-of-stock text under price on shop/archive loop cards. |
| `display_loop_product_subheading()` | Show product subheading under product title on shop/archive loop cards. |
| `hithean_loop_rule_price_ranges(array $pairs)` | Gộp [giá gốc, giá cuối] của từng lựa chọn mua (SP đơn: 1 cặp; SP biến thể: mỗi biến thể 1 cặp) thành [gốc thấp nhất, gốc cao nhất, cuối thấp nhất, cuối cao nhất]; null khi không… |
| `hithean_loop_rule_price_pair(WC_Product $product)` | [giá gốc, giá khi mua 1, có rule không] của một sản phẩm / biến thể; giá cuối = giá rule nếu rule áp dụng từ 1 sản phẩm, không thì giá đang bán. |
| `hithean_loop_rule_price_data(WC_Product $product)` | Khoảng giá [gốc thấp, gốc cao, ưu đãi thấp, ưu đãi cao] khi sản phẩm có rule giảm ngay từ 1 sản phẩm; null khi không có (nhớ theo request). |
| `hithean_loop_rule_price_html($html, $product)` | Thay HTML giá trên thẻ sản phẩm ở danh sách khi rule giảm giá áp dụng ngay từ 1 sản phẩm. |
| `hithean_loop_rule_sale_flash()` | Nhãn Sale trên ảnh cho sản phẩm có rule giảm từ 1 sản phẩm — cùng span.onsale (kiểu nhãn NEW, css/custom.css) và cùng chữ với nhãn Sale thông thường. SP đang Sale thông thường đ… |
| `hithean_loop_rule_sale_class($classes, $product)` | Thẻ sản phẩm đó nhận lớp "sale" như Sale thông thường (nhãn NEW tự xuống dưới nhãn Sale). Không áp cho sản phẩm chính của trang chi tiết. |
| `hithean_hide_loop_add_to_cart_when_out_of_stock($html, $product)` | Out-of-stock loop cards: no add-to-cart / "Read more" button — only "Xem chi tiết" remains. |
| `hithean_get_loop_addon_promo_lines(int $product_id)` | Active promo lines of a product from the product-addon plugin (sale message or ON discount/coupon/product rules). |
| `hithean_display_loop_addon_promo()` | Show active product-addon program under price, above loop action buttons. |
| `hithean_product_taxonomy_inline_styles()` | — |

### `woocommerce/products/product-metabox.php`

| Hàm | Mô tả |
|---|---|
| `hithean_product_additional_info_metabox($meta_boxes)` | — |
| `hithean_product_proof_embed_sanitize($value, $field)` | — |
| `hithean_ecom_modal_default_desc()` | — |
| `hithean_product_ecom_channels_metabox($meta_boxes)` | — |
| `hithean_render_ecom_channel_builder_tool()` | — |

### `woocommerce/products/product-navigation-settings.php`

| Hàm | Mô tả |
|---|---|
| `hithean_pcn_default_settings()` | — |
| `hithean_pcn_proof_embed_visibility_options()` | — |
| `hithean_pcn_can_view_proof_embeds()` | Người xem hiện tại có được thấy nút nhúng Kiểm nghiệm / Hồ sơ pháp lý không. |
| `hithean_pcn_get_settings()` | — |
| `hithean_pcn_menu_scope_taxonomy_map()` | — |
| `hithean_pcn_is_safe_fragment(string $value)` | — |
| `hithean_pcn_sanitize_menu_url(string $value)` | — |
| `hithean_pcn_sanitize_settings($input)` | — |
| `hithean_pcn_external_menu_icon_svg()` | — |
| `hithean_pcn_menu_matches_product(array $item, int $product_id)` | — |
| `hithean_pcn_get_product_menus(int $product_id)` | — |
| `hithean_pcn_parse_proof_embed($raw, string $kind)` | Only run the two script snippets generated by IVAR. Never echo arbitrary HTML from a product meta field into the sticky menu. |
| `hithean_pcn_proof_embed_script($raw, string $kind)` | Client-side fallback, used until the server-side cache has a copy. |
| `hithean_pcn_proof_embed_snippet($raw, string $kind)` | The snippet exactly as IVAR generates it — what the product field stores. The field must never store the data-no-minify variant: the parser above is the reader too (an older bui… |

### `woocommerce/products/product-navigation.php`

| Hàm | Mô tả |
|---|---|
| `hithean_pcn_capture_tabs($tabs)` | — |
| `hithean_pcn_tabs_snapshot($set = null)` | — |
| `hithean_pcn_default_tab_map()` | Map mặc định cho các tab chuẩn không xuất phát từ CPT product-tab (tồn tại panel là đủ điều kiện vào navigator, không cần setting riêng). |
| `hithean_pcn_build_items()` | Chuẩn hoá mảng tabs đã bắt được thành danh sách item cho navigator. Không truy vấn thêm — chỉ đọc lại các key đã có sẵn trong $tabs. |
| `hithean_pcn_item_icon_svg(string $icon_key)` | 'external' không nằm trong whitelist icon của Tab Sản Phẩm (chỉ dùng cho "Menu bổ sung" loại URL, không phải lựa chọn admin nào có thể set) nên xử lý riêng ở đây thay vì đưa vào… |
| `hithean_pcn_get_items()` | — |
| `hithean_pcn_enqueue_assets()` | — |
| `hithean_pcn_render()` | — |

### `woocommerce/products/product-nutrition-label.php`

| Hàm | Mô tả |
|---|---|
| `hithean_product_nutrition_label_items(int $product_id)` | — |
| `hithean_render_product_nutrition_label($product_id = 0)` | — |
| `hithean_product_nutrition_label_shortcode(array $atts = [])` | — |
| `hithean_product_nutrition_label_register_guide_page()` | — |
| `hithean_product_nutrition_label_render_guide_page()` | — |
| `hithean_product_nutrition_label_assets()` | — |
| `hithean_product_nutrition_label_enqueue_front_assets()` | — |
| `hithean_product_nutrition_label_admin_assets(string $hook)` | — |

### `woocommerce/products/product-page.php`

| Hàm | Mô tả |
|---|---|
| `hithean_append_sale_price_date_notice_to_price_html($price_html, $product)` | — |
| `hithean_get_sale_price_date_range(WC_Product $product)` | — |
| `hithean_wc_datetime_to_timestamp($datetime)` | — |
| `hithean_format_sale_price_date_notice(int $from, int $to)` | — |
| `hithean_onsale_badge_position_css()` | — |
| `hithean_sale_price_date_notice_css()` | — |
| `generate_slug($string)` | — |
| `hithean_pcn_tab_nav_meta_fields(int $post_id)` | — |
| `add_custom_product_tabs($tabs)` | — |
| `get_product_assigned_tabs($product_id)` | — |
| `display_custom_product_field_tab_content($key, $tab)` | — |
| `display_product_tab_content($key, $tab)` | — |
| `display_thuong_hieu_tab_content()` | — |
| `hithean_sticky_atc_js()` | — |
| `hithean_ecom_get_channels(int $product_id)` | — |
| `hithean_ecom_button_should_show(int $product_id)` | — |
| `hithean_render_ecom_buy_button()` | — |
| `hithean_ecom_buy_modal_js()` | — |
| `hithean_render_product_trust_ctas(bool $with_nutrition)` | — |
| `unset_tabs($tabs)` | — |

### `woocommerce/products/product-purchase.php`

| Hàm | Mô tả |
|---|---|
| `hithean_product_purchase_body_class(array $classes)` | — |
| `hithean_product_purchase_enqueue()` | — |
| `hithean_product_purchase_offer_fragment()` | Lightweight product-context response: native Woo/plugin summary hooks, without gallery, header, footer, recommendations or the full-page script payload. Never shared-cache cart… |
| `hithean_product_purchase_offer_media(int $product_id)` | Ảnh nhỏ góc phải thẻ trong popup ưu đãi: ảnh sản phẩm đang xem + ảnh sản phẩm add-on (dòng add-on loại "product" — tặng/mua kèm) theo tên dòng. Chỉ đọc dữ liệu qua hàm của plugi… |
| `hithean_product_purchase_offers_url()` | Link "Xem mọi ưu đãi" trong popup ưu đãi: chỉ khi trang /uu-dai/ đã công khai (không ở chế độ xem trước). |
| `hithean_product_purchase_render_quote()` | — |
| `hithean_product_purchase_ajax_quote()` | Only published products belonging to this page can be quoted. The action reads a single product and the current plugin context; it never adds items, applies coupons, writes rule… |
| `hithean_product_purchase_rule_price($details, int $quantity)` | Accept only a fully matched, uniform product-price discount. Cart adjustments, BOGO/set/mixed lines must be calculated by the native cart, not flattened. |
| `hithean_product_purchase_build_quote(WC_Product $product, int $quantity)` | — |

### `woocommerce/products/product-tab-post-type.php`

| Hàm | Mô tả |
|---|---|
| `product_tab_post_type()` | — |
| `hithean_product_tab_is_admin_editor_screen()` | — |
| `hithean_product_tab_guide_sections()` | — |
| `hithean_product_tab_render_guide_items(array $items, bool $ordered = false)` | — |
| `hithean_product_tab_render_admin_guide()` | — |
| `hithean_product_tab_print_admin_guide_styles()` | — |
| `hithean_product_tab_print_admin_guide_script()` | — |
| `hithean_product_tab_icon_whitelist()` | — |
| `hithean_product_tab_icon_options()` | — |
| `hithean_product_tab_icon_svg(string $key)` | — |
| `product_tab_meta_boxes($meta_boxes)` | — |

### `woocommerce/products/product-taxonomies.php`

| Hàm | Mô tả |
|---|---|
| `tao_term_thumbnail_id($term = null)` | — |
| `tao_term_thumbnail_html($term = null, $size = 'large', $attr = [])` | — |

### `woocommerce/products/proof-embed-cache.php`

Server-side cache for the IVAR proof widgets (Kiểm nghiệm + Hồ sơ pháp lý).

| Hàm | Mô tả |
|---|---|
| `hithean_proof_cache_meta_keys()` | kind => product meta key holding the IVAR snippet. |
| `hithean_proof_cache_ttl()` | — |
| `hithean_proof_cache_sources(int $product_id)` | Valid snippets of a product: kind => ['src', 'value', 'key']. |
| `hithean_proof_cache_get(int $product_id)` | — |
| `hithean_proof_cache_ivar_url($url, string $fallback)` | Only ever load IVAR assets from ivarvietnam.com over https. |
| `hithean_proof_cache_fetch(string $kind, array $embed)` | Fetch one widget from IVAR. Returns the new cache entry, or null when IVAR could not be reached / answered with an error (caller keeps the old copy). |
| `hithean_proof_cache_purge_landings(int $product_id)` | Purge landing pages whose HTML source contains a card for this product. |
| `hithean_proof_cache_refresh(int $product_id)` | Re-fetch every widget of a product; purge its page cache if the HTML changed. |
| `hithean_proof_cache_schedule(int $product_id)` | — |
| `hithean_proof_cache_is_stale(array $entry)` | — |
| `hithean_proof_cache_page_assets(?array $add = null)` | Which IVAR binders / fonts the current page needs, filled while rendering. |
| `hithean_proof_cache_skin_css()` | Hithean skin for the IVAR popups (assets/proof-widget-skin.css), inlined per shadow root. |
| `hithean_proof_cache_host(string $kind, array $entry)` | — |
| `hithean_proof_cache_markup(int $product_id)` | kind => markup for a product: cached HTML when available, the script snippet otherwise. Nothing for a widget IVAR says is not public. |
| `hithean_proof_cache_refresh_url(int $product_id)` | — |
| `hithean_proof_cache_status_text(int $product_id)` | "Kiểm nghiệm: đã cập nhật · Hồ sơ pháp lý: …" — '' when nothing is cached. |
| `hithean_proof_cache_metabox_html()` | Meta Box custom_html callback, under the 2 snippet fields (product-metabox.php). A link, not a button: a button would submit the post form. It refreshes from the SAVED snippets,… |

### `woocommerce/products/recipe-post-type.php`

| Hàm | Mô tả |
|---|---|
| `recipe_post_type()` | — |
| `recipe_post_meta_boxes($meta_boxes)` | — |
| `register_bo_suu_tap_taxonomy()` | — |

### `woocommerce/products/sales-count-admin.php`

| Hàm | Mô tả |
|---|---|
| `hithean_sc_sanitize(array $input)` | — |
| `hithean_sc_settings_rev()` | Dấu phiên bản dữ liệu đã lưu — phát hiện bookmarklet ghi trong lúc tab đang mở. |
| `hithean_sc_save_error(?string $set = null)` | Lỗi lần lưu tab gần nhất (in ở đầu tab). |
| `hithean_sc_update_settings(array $settings)` | — |
| `hithean_sc_save_from_post()` | Gọi từ theme_erp_settings_render_page() khi POST tab "luot-ban" (nonce đã kiểm). false = không lưu vì dữ liệu đã đổi sau khi mở trang (bookmarklet vừa ghi số mới). |
| `hithean_sc_bookmarklet()` | — |
| `hithean_sc_find_link_source(array $groups, string $url, string $group_id = '')` | Nhãn hiệu + vị trí của nguồn link khớp $url (cùng khóa so khớp). |
| `hithean_sc_capture_head(string $title)` | — |
| `hithean_sc_capture_page()` | — |
| `hithean_sc_capture_save()` | — |
| `hithean_sc_resolve_link(string $url)` | Link rút gọn (s.shopee.vn, shp.ee...) → link đầy đủ, theo tối đa 5 lần redirect. |
| `hithean_sc_find_number(array $data, string $key)` | Tìm key đầu tiên (đệ quy) có giá trị số. |
| `hithean_sc_extract_link(string $url)` | — |
| `hithean_sc_extract_text_regex(string $text)` | Regex "Đã bán 2,3k" / "2.3k sold" trong text — fallback khi không dùng được AI. |
| `hithean_sc_ai_config()` | — |
| `hithean_sc_extract_ai(string $text, ?array $image)` | AI đọc ảnh/text → danh sách ứng viên. |
| `hithean_sc_ajax_extract()` | — |
| `hithean_sc_ajax_preview_shortcode()` | Xem trước shortcode ở tab Lượt bán: chạy [hithean_sales_count] thật với dữ liệu đã lưu. |
| `hithean_sc_render_settings_tab()` | — |

### `woocommerce/products/sales-count.php`

| Hàm | Mô tả |
|---|---|
| `hithean_sc_positions()` | — |
| `hithean_sc_default_settings()` | — |
| `hithean_sc_settings()` | — |
| `hithean_sc_template(array $group, string $position)` | Câu hiển thị cho 1 vị trí: câu riêng của nhãn hiệu (nếu có) > câu chung trong settings. VD câu riêng: "{brand} đã bán {count} trên Shopee 3 tháng gần nhất (đại lý)". |
| `hithean_sc_position_enabled(string $position)` | — |
| `hithean_sc_parse_number(string $raw)` | "2,3k" / "2.3k" / "10k+" / "1,2tr" / "1.2M" / "2.345" / "2,345" → int. Có hậu tố (k, tr, m, nghìn...) thì dấu phẩy/chấm là phần thập phân; không có hậu tố thì dấu phẩy/chấm là p… |
| `hithean_sc_format_count(int $count, string $format = 'short')` | Kiểu Shopee: 999 → "999", 2345 → "2,3k", 1234567 → "1,2tr" (làm tròn xuống, không nói quá). |
| `hithean_sc_shopee_ids(string $url)` | [shop_id, item_id] từ link sản phẩm Shopee đầy đủ (không resolve link rút gọn). |
| `hithean_sc_link_platform(string $url)` | Sàn của 1 link: 'tiktok' nếu host TikTok, còn lại 'shopee'. |
| `hithean_sc_source_platform(array $source)` | — |
| `hithean_sc_link_key(string $url)` | Khóa so khớp 1 link (nguồn trùng link trong nhóm — bookmarklet thay số thay vì thêm mới). |
| `hithean_sc_group_product_ids(array $group)` | ID sản phẩm thuộc nhóm: chọn tay + theo thương hiệu. |
| `hithean_sc_group_totals()` | Tổng từng nhóm: ['group_id' => ['shopee' => int, 'web' => int, 'total' => int, 'updated' => 'Y-m-d']]. 'updated' = ngày cập nhật mới nhất trong các nguồn — tháng của số liệu cho… |
| `hithean_sc_get_group(string $group_id)` | — |
| `hithean_sc_group_for_product(int $product_id)` | Nhóm đầu tiên chứa sản phẩm (chọn tay hoặc cùng thương hiệu). |
| `hithean_sc_render_line(array $group, string $template, string $context, string $format = 'short', ?int $count =…)` | HTML 1 dòng lượt bán; '' nếu không có nhóm / dưới ngưỡng. Template: {count} {brand} {shopee} {tiktok} {san} (Shopee + TikTok) {web} {thang} {nam} — các biến số được in đậm. {tha… |
| `hithean_sc_print_styles()` | — |
| `hithean_sc_current_product_id()` | — |
| `hithean_sc_echo_position(string $position, int $product_id = 0)` | — |
| `hithean_sc_require_admin()` | — |

### `woocommerce/products/woocommerce-settings.php`

| Hàm | Mô tả |
|---|---|
| `custom_button_after_add_to_cart()` | — |


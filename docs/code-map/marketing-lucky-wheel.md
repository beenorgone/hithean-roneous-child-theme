<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `marketing/lucky-wheel`

> Vòng quay may mắn: cài đặt, widget, dọn mã.

3 file · ~2.000 dòng · 79 hàm (3% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `thean_lw_apply_coupon` | ui.php |
| AJAX | `thean_lw_claim` | ui.php |
| AJAX | `thean_lw_spin` | ui.php |
| AJAX | `thean_lw_status` | ui.php |
| AJAX công khai | `thean_lw_apply_coupon` | ui.php |
| AJAX công khai | `thean_lw_claim` | ui.php |
| AJAX công khai | `thean_lw_spin` | ui.php |
| AJAX công khai | `thean_lw_status` | ui.php |
| admin-post | `thean_lw_manual_cleanup` | admin.php |
| admin-post | `thean_lw_manual_truncate_log` | admin.php |
| admin-post | `thean_lw_view_log` | admin.php |
| cron | `thean_lw_daily_cleanup` | cleanup.php |

## Hook khu này lắng nghe

`admin_init` ×2 · `admin_menu` · `init` · `switch_theme` · `thean_lw_daily_cleanup` · `woocommerce_applied_coupon` · `woocommerce_before_calculate_totals` · `woocommerce_cart_calculate_fees` · `woocommerce_coupon_is_valid` · `woocommerce_removed_coupon` · `wp_enqueue_scripts` · `wp_footer`

## File

### `marketing/lucky-wheel/admin.php`

| Hàm | Mô tả |
|---|---|
| `thean_lw_seed_default_settings()` | — |
| `thean_lw_register_settings()` | — |
| `thean_lw_register_admin_menu()` | — |
| `thean_lw_sanitize_settings($input)` | — |
| `thean_lw_handle_manual_cleanup()` | — |
| `thean_lw_handle_manual_truncate_log()` | — |
| `thean_lw_handle_view_log()` | — |
| `thean_lw_render_admin_page()` | — |

### `marketing/lucky-wheel/cleanup.php`

| Hàm | Mô tả |
|---|---|
| `thean_lw_schedule_cleanup_cron()` | — |
| `thean_lw_unschedule_cleanup_cron()` | — |
| `thean_lw_run_daily_cleanup()` | — |
| `thean_lw_cleanup_logger()` | — |
| `thean_lw_format_log_timestamp(int $timestamp)` | — |
| `thean_lw_log_expired_coupon(WC_Coupon $coupon, int $expired_at)` | — |
| `thean_lw_run_expired_coupon_cleanup()` | Deletes Lucky Wheel coupons whose expiry date is more than THEAN_LW_CLEANUP_GRACE_DAYS days in the past, logging each one first. |
| `thean_lw_parse_log_line_timestamp(string $line)` | — |
| `thean_lw_parse_log_line_expired_at(string $line)` | — |
| `thean_lw_prune_cleanup_log()` | "Truncate" here means keeping a rolling THEAN_LW_LOG_TRUNCATE_DAYS window based on each logged coupon's own expired_at date — not when the log line was written (which trails exp… |
| `thean_lw_cleanup_log_file_path()` | — |

### `marketing/lucky-wheel/ui.php`

| Hàm | Mô tả |
|---|---|
| `thean_lw_boot_ui()` | — |
| `thean_lw_is_eligible_page()` | — |
| `thean_lw_page_context()` | — |
| `thean_lw_enqueue_assets()` | — |
| `thean_lw_render_widget()` | — |
| `thean_lw_ajax_status()` | — |
| `thean_lw_ajax_spin()` | — |
| `thean_lw_ajax_claim()` | — |
| `thean_lw_ajax_apply_coupon()` | — |
| `thean_lw_validate_single_lucky_wheel_coupon($valid, $coupon, $discount)` | — |
| `thean_lw_coupon_reward($coupon)` | — |
| `thean_lw_is_advanced_reward(array $reward)` | — |
| `thean_lw_cart_matches_advanced_reward(array $reward)` | — |
| `thean_lw_advanced_reward_error_message(array $reward)` | — |
| `thean_lw_cart_item_product_ids(array $cart_item)` | — |
| `thean_lw_cart_buy_qty(array $reward)` | — |
| `thean_lw_cart_taxonomy_qty(array $reward)` | — |
| `thean_lw_handle_applied_coupon(string $coupon_code)` | — |
| `thean_lw_handle_removed_coupon(string $coupon_code)` | — |
| `thean_lw_sync_bogo_cart_items($cart)` | — |
| `thean_lw_cart_is_usable($cart)` | — |
| `thean_lw_applied_lucky_wheel_rewards($cart)` | — |
| `thean_lw_ensure_bogo_gift_item($cart, string $coupon_code, array $reward)` | — |
| `thean_lw_remove_bogo_gift_items(string $coupon_code = '')` | — |
| `thean_lw_apply_advanced_reward_fees($cart)` | — |
| `thean_lw_advanced_reward_discount_amount($cart, array $reward, string $coupon_code)` | — |
| `thean_lw_cart_shipping_total($cart)` | — |
| `thean_lw_bogo_gift_discount_amount($cart, array $reward, string $coupon_code)` | — |
| `thean_lw_taxonomy_matching_subtotal($cart, array $reward)` | — |
| `thean_lw_cart_has_other_lucky_wheel_coupon(string $coupon_code)` | — |
| `thean_lw_format_coupon_code(string $coupon_code)` | — |
| `thean_lw_is_lucky_wheel_coupon($coupon)` | — |
| `thean_lw_verify_ajax()` | — |
| `thean_lw_session_id()` | — |
| `thean_lw_state_key()` | — |
| `thean_lw_default_state()` | — |
| `thean_lw_get_state()` | — |
| `thean_lw_save_state(array $state)` | — |
| `thean_lw_public_state()` | — |
| `thean_lw_public_reward(array $reward)` | — |
| `thean_lw_pick_reward()` | — |
| `thean_lw_reward_by_id(string $reward_id)` | — |
| `thean_lw_sign_token(string $token)` | — |
| `thean_lw_verify_token(string $signed_token)` | — |
| `thean_lw_normalize_contact(string $raw)` | — |
| `thean_lw_claim_identities(array $contact)` | — |
| `thean_lw_claim_identity_hash(string $type, string $value)` | — |
| `thean_lw_claim_lock_key(array $identity)` | — |
| `thean_lw_claim_cooldown_remaining(array $identities)` | — |
| `thean_lw_active_claim_coupon_remaining(array $identities)` | — |
| `thean_lw_latest_claim_time(array $identity)` | — |
| `thean_lw_claim_coupon_ids(array $identities, int $limit = 1, array $date_query = [])` | — |
| `thean_lw_set_claim_locks(array $identities)` | — |
| `thean_lw_format_duration(int $seconds)` | — |
| `thean_lw_create_coupon(array $reward, array $contact, array $claim_identities = [])` | — |
| `thean_lw_send_to_sheets(array $payload)` | — |
| `thean_lw_rate_limit(string $key, int $limit, int $ttl)` | — |
| `thean_lw_pass_interaction_gate(array $state, int $min_age_seconds)` | — |
| `thean_lw_client_ip_hash()` | — |
| `thean_lw_client_ip()` | — |


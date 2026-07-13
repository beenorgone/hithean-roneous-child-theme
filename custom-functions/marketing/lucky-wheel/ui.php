<?php

if (!defined('ABSPATH')) {
    exit;
}

function thean_lw_boot_ui(): void
{
    add_action('wp_enqueue_scripts', 'thean_lw_enqueue_assets');
    add_action('wp_footer', 'thean_lw_render_widget');

    add_action('wp_ajax_thean_lw_status', 'thean_lw_ajax_status');
    add_action('wp_ajax_nopriv_thean_lw_status', 'thean_lw_ajax_status');
    add_action('wp_ajax_thean_lw_spin', 'thean_lw_ajax_spin');
    add_action('wp_ajax_nopriv_thean_lw_spin', 'thean_lw_ajax_spin');
    add_action('wp_ajax_thean_lw_claim', 'thean_lw_ajax_claim');
    add_action('wp_ajax_nopriv_thean_lw_claim', 'thean_lw_ajax_claim');
    add_action('wp_ajax_thean_lw_apply_coupon', 'thean_lw_ajax_apply_coupon');
    add_action('wp_ajax_nopriv_thean_lw_apply_coupon', 'thean_lw_ajax_apply_coupon');

    add_filter('woocommerce_coupon_is_valid', 'thean_lw_validate_single_lucky_wheel_coupon', 10, 3);
    add_action('woocommerce_applied_coupon', 'thean_lw_handle_applied_coupon', 10, 1);
    add_action('woocommerce_removed_coupon', 'thean_lw_handle_removed_coupon', 10, 1);
    add_action('woocommerce_before_calculate_totals', 'thean_lw_sync_bogo_cart_items', 20, 1);
    add_action('woocommerce_cart_calculate_fees', 'thean_lw_apply_advanced_reward_fees', 30, 1);
}
thean_lw_boot_ui();

function thean_lw_is_eligible_page(): bool
{
    if (is_admin()) {
        return false;
    }

    if (is_front_page() || is_home()) {
        return true;
    }

    if (function_exists('is_product') && is_product()) {
        return true;
    }

    if (function_exists('is_cart') && is_cart()) {
        return true;
    }

    $object = get_queried_object();
    $post_name = is_object($object) && !empty($object->post_name) ? sanitize_title($object->post_name) : '';

    return in_array($post_name, thean_lw_offer_slugs(), true);
}

function thean_lw_page_context(): string
{
    if (function_exists('is_cart') && is_cart()) {
        return 'cart';
    }

    if (function_exists('is_product') && is_product()) {
        return 'product';
    }

    if (is_front_page() || is_home()) {
        return 'home';
    }

    return 'offer';
}

function thean_lw_enqueue_assets(): void
{
    if (!thean_lw_is_eligible_page()) {
        return;
    }

    $css_asset = thean_lw_asset('lucky-wheel.css');
    $js_asset = thean_lw_asset('lucky-wheel.js');

    if (!is_file($css_asset['path']) || !is_file($js_asset['path'])) {
        return;
    }

    wp_enqueue_style('thean-lucky-wheel', $css_asset['url'], [], $css_asset['version']);
    wp_enqueue_script('thean-lucky-wheel', $js_asset['url'], [], $js_asset['version'], true);
    wp_script_add_data('thean-lucky-wheel', 'defer', true);
    wp_localize_script('thean-lucky-wheel', 'TheanLuckyWheel', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(THEAN_LW_NONCE_ACTION),
        'context' => thean_lw_page_context(),
        'maxSpins' => THEAN_LW_MAX_SPINS,
        'couponHoldHours' => thean_lw_coupon_hold_hours(),
        'isCart' => function_exists('is_cart') && is_cart(),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/gio-hang'),
        'checkoutUrl' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/thanh-toan'),
        'currentUrl' => home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')),
    ]);
}

function thean_lw_render_widget(): void
{
    if (!thean_lw_is_eligible_page()) {
        return;
    }

    $trigger = thean_lw_trigger_config();
    $segments = thean_lw_active_rewards();

    $css_vars = [];
    foreach (['top' => '--lw-t-top', 'bottom' => '--lw-t-bottom', 'left' => '--lw-t-left', 'right' => '--lw-t-right'] as $key => $var) {
        if (!empty($trigger[$key])) {
            $css_vars[$var] = $trigger[$key];
        }
    }
    foreach (['mobile_top' => '--lw-t-top-m', 'mobile_bottom' => '--lw-t-bottom-m', 'mobile_left' => '--lw-t-left-m', 'mobile_right' => '--lw-t-right-m'] as $key => $var) {
        if (!empty($trigger[$key])) {
            $css_vars[$var] = $trigger[$key];
        }
    }
    if (!empty($trigger['mobile_left']) || !empty($trigger['mobile_right'])) {
        $css_vars['--lw-t-transform-m'] = 'none';
        if (!empty($trigger['mobile_right']) && empty($trigger['mobile_left'])) {
            $css_vars['--lw-t-left-m'] = 'auto';
        }
    }
    $inline_style = '';
    if (!empty($css_vars)) {
        $parts = [];
        foreach ($css_vars as $prop => $val) {
            $parts[] = $prop . ':' . $val;
        }
        $inline_style = implode(';', $parts);
    }
    ?>
    <div
        id="thean-lw-root"
        class="thean-lw"
        data-context="<?php echo esc_attr(thean_lw_page_context()); ?>"
        data-vertical="<?php echo esc_attr($trigger['vertical']); ?>"
        data-horizontal="<?php echo esc_attr($trigger['horizontal']); ?>"
        data-display="<?php echo esc_attr($trigger['display']); ?>"
        data-segments="<?php echo esc_attr((string) count($segments)); ?>"
        <?php if ($inline_style !== '') : ?>style="<?php echo esc_attr($inline_style); ?>"<?php endif; ?>
    >
        <button class="thean-lw-trigger <?php echo esc_attr($trigger['custom_class']); ?>" type="button" aria-haspopup="dialog">
            <span class="thean-lw-trigger__icon">%</span>
            <span class="thean-lw-trigger__text">Nhận ưu đãi</span>
        </button>

        <div class="thean-lw-modal" role="dialog" aria-modal="true" aria-labelledby="thean-lw-title" hidden>
            <div class="thean-lw-backdrop" data-thean-lw-close></div>
            <div class="thean-lw-panel">
                <button class="thean-lw-close" type="button" data-thean-lw-close aria-label="Đóng">×</button>
                <div class="thean-lw-grid">
                    <div class="thean-lw-wheel-wrap">
                        <div class="thean-lw-pointer" aria-hidden="true"></div>
                        <div class="thean-lw-wheel" aria-hidden="true" style="--segments: <?php echo esc_attr((string) count($segments)); ?>;">
                            <?php foreach ($segments as $index => $segment) : ?>
                                <span class="thean-lw-wheel__label" style="--segment-index: <?php echo esc_attr((string) $index); ?>;"><?php echo esc_html(thean_lw_reward_wheel_label($segment)); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="thean-lw-actions thean-lw-actions--wheel">
                            <button class="thean-lw-btn thean-lw-btn--primary" type="button" data-thean-lw-spin disabled>Quay ngay</button>
                        </div>
                    </div>
                    <div class="thean-lw-content">
                        <p class="thean-lw-kicker">Ưu đãi riêng cho lượt ghé này</p>
                        <h2 id="thean-lw-title">Quay để giữ mã trong <?php echo esc_html((string) thean_lw_coupon_hold_hours()); ?> giờ</h2>
                        <p class="thean-lw-spins" data-thean-lw-spins>Đang tải...</p>
                        <div class="thean-lw-result-list" data-thean-lw-result-list hidden></div>
                        <form class="thean-lw-form" data-thean-lw-form hidden>
                            <label for="thean-lw-contact">Email hoặc số điện thoại</label>
                            <input id="thean-lw-contact" name="contact" type="text" inputmode="email" autocomplete="email tel" placeholder="email@example.com hoặc 09..." required>
                            <input class="thean-lw-hp" name="website" type="text" tabindex="-1" autocomplete="off">
                            <button class="thean-lw-btn thean-lw-btn--primary" type="submit">Nhận mã ưu đãi</button>
                            <p class="thean-lw-form-note">Mã chỉ được tạo sau bước này, có hiệu lực trong <?php echo esc_html((string) thean_lw_coupon_hold_hours()); ?> giờ và mỗi email/tài khoản/số điện thoại chỉ nhận mã mới sau 48 giờ.</p>
                        </form>
                        <div class="thean-lw-coupon" data-thean-lw-coupon hidden></div>
                        <p class="thean-lw-message" data-thean-lw-message role="status"></p>
                        <div class="thean-lw-footer-actions">
                            <button class="thean-lw-btn thean-lw-btn--secondary thean-lw-btn--dismiss" type="button" data-thean-lw-dismiss>Tắt vòng quay</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function thean_lw_ajax_status(): void
{
    thean_lw_verify_ajax();

    $state = thean_lw_get_state();
    if (empty($state['started_at'])) {
        $state['started_at'] = time();
        $state['last_seen_at'] = time();
        thean_lw_save_state($state);
    } else {
        $state['last_seen_at'] = time();
        thean_lw_save_state($state);
    }

    wp_send_json_success(thean_lw_public_state());
}

function thean_lw_ajax_spin(): void
{
    thean_lw_verify_ajax();

    $state = thean_lw_get_state();
    if (!empty($state['coupon_code'])) {
        wp_send_json_error(['message' => 'Bạn đã nhận mã ưu đãi trong phiên này.'], 409);
    }

    if ((int) $state['spins_used'] >= THEAN_LW_MAX_SPINS) {
        wp_send_json_error(['message' => 'Bạn đã dùng hết lượt quay.'], 429);
    }

    if (!thean_lw_pass_interaction_gate($state, 2)) {
        wp_send_json_error(['message' => 'Vui lòng tương tác thêm một chút trước khi quay.'], 429);
    }

    if (!thean_lw_rate_limit('spin_session_' . thean_lw_session_id(), 6, HOUR_IN_SECONDS) || !thean_lw_rate_limit('spin_ip_' . thean_lw_client_ip_hash(), 24, HOUR_IN_SECONDS)) {
        wp_send_json_error(['message' => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.'], 429);
    }

    if (!empty($state['last_spin_at']) && (time() - (int) $state['last_spin_at']) < 3) {
        wp_send_json_error(['message' => 'Mỗi lượt quay cần cách nhau vài giây.'], 429);
    }

    $reward = thean_lw_pick_reward();
    if (!$reward) {
        wp_send_json_error(['message' => 'Chưa có phần thưởng khả dụng.'], 500);
    }

    $token = wp_generate_password(24, false, false);
    $state['spins_used'] = (int) $state['spins_used'] + 1;
    $state['last_spin_at'] = time();
    $state['selected_token'] = $token;
    $state['prizes'][$token] = [
        'reward_id' => $reward['id'],
        'label' => $reward['label'],
        'segment_index' => (int) $reward['segment_index'],
        'created_at' => time(),
        'claimed' => false,
    ];
    thean_lw_save_state($state);

    wp_send_json_success([
        'spins_used' => (int) $state['spins_used'],
        'spins_left' => max(0, THEAN_LW_MAX_SPINS - (int) $state['spins_used']),
        'max_spins' => THEAN_LW_MAX_SPINS,
        'prize' => thean_lw_public_reward($reward),
        'claim_token' => thean_lw_sign_token($token),
    ]);
}

function thean_lw_ajax_claim(): void
{
    thean_lw_verify_ajax();

    $honeypot = isset($_POST['website']) ? trim((string) wp_unslash($_POST['website'])) : '';
    if ($honeypot !== '') {
        wp_send_json_error(['message' => 'Yêu cầu không hợp lệ.'], 400);
    }

    $signed_token = isset($_POST['claim_token']) ? sanitize_text_field(wp_unslash((string) $_POST['claim_token'])) : '';
    $token = thean_lw_verify_token($signed_token);
    if ($token === '') {
        wp_send_json_error(['message' => 'Ưu đãi không hợp lệ hoặc đã hết hạn.'], 400);
    }

    $contact = thean_lw_normalize_contact(isset($_POST['contact']) ? wp_unslash((string) $_POST['contact']) : '');
    if (!$contact) {
        wp_send_json_error(['message' => 'Vui lòng nhập email hoặc số điện thoại hợp lệ.'], 400);
    }

    $state = thean_lw_get_state();
    if (empty($state['prizes'][$token])) {
        wp_send_json_error(['message' => 'Không tìm thấy ưu đãi đã chọn.'], 400);
    }

    if (!thean_lw_pass_interaction_gate($state, 4)) {
        wp_send_json_error(['message' => 'Phiên này trông chưa đủ tin cậy để tạo mã.'], 429);
    }

    if (!empty($state['coupon_code'])) {
        wp_send_json_success(thean_lw_public_state());
    }

    $claim_identities = thean_lw_claim_identities($contact);
    $active_coupon_remaining = thean_lw_active_claim_coupon_remaining($claim_identities);
    if ($active_coupon_remaining > 0) {
        wp_send_json_error([
            'message' => 'Email, tài khoản hoặc số điện thoại này đang có mã Lucky Wheel chưa hết hạn. Vui lòng dùng mã hiện tại hoặc thử lại sau ' . thean_lw_format_duration($active_coupon_remaining) . '.',
        ], 429);
    }

    $cooldown_remaining = thean_lw_claim_cooldown_remaining($claim_identities);
    if ($cooldown_remaining > 0) {
        wp_send_json_error([
            'message' => 'Mỗi email, tài khoản hoặc số điện thoại chỉ được nhận mã mới sau 48 giờ. Vui lòng thử lại sau ' . thean_lw_format_duration($cooldown_remaining) . '.',
        ], 429);
    }

    $contact_hash = thean_lw_claim_identity_hash($contact['type'], $contact['value']);
    if (
        !thean_lw_rate_limit('claim_session_' . thean_lw_session_id(), 2, DAY_IN_SECONDS) ||
        !thean_lw_rate_limit('claim_ip_' . thean_lw_client_ip_hash(), 5, HOUR_IN_SECONDS) ||
        !thean_lw_rate_limit('claim_contact_' . $contact_hash, 2, DAY_IN_SECONDS)
    ) {
        wp_send_json_error(['message' => 'Bạn đã yêu cầu quá nhiều mã ưu đãi. Vui lòng thử lại sau.'], 429);
    }

    $reward = thean_lw_reward_by_id((string) $state['prizes'][$token]['reward_id']);
    if (!$reward) {
        wp_send_json_error(['message' => 'Không tìm thấy cấu hình ưu đãi.'], 500);
    }

    $coupon = thean_lw_create_coupon($reward, $contact, $claim_identities);
    if (is_wp_error($coupon)) {
        wp_send_json_error(['message' => $coupon->get_error_message()], 500);
    }
    thean_lw_set_claim_locks($claim_identities);

    $state['prizes'][$token]['claimed'] = true;
    $state['selected_token'] = $token;
    $state['coupon_id'] = $coupon['id'];
    $state['coupon_code'] = $coupon['code'];
    $state['coupon_expires'] = $coupon['expires'];
    thean_lw_save_state($state);

    thean_lw_send_to_sheets([
        'site' => home_url('/'),
        'context' => sanitize_key((string) ($_POST['context'] ?? '')),
        'source_url' => esc_url_raw((string) ($_POST['source_url'] ?? '')),
        'contact_type' => $contact['type'],
        'contact_value' => $contact['value'],
        'reward_id' => $reward['id'],
        'reward_label' => $reward['label'],
        'coupon_code' => $coupon['code'],
        'coupon_expires_at' => gmdate('c', $coupon['expires']),
        'session_hash' => hash('sha256', thean_lw_session_id()),
        'created_at' => gmdate('c'),
    ]);

    wp_send_json_success(thean_lw_public_state());
}

function thean_lw_ajax_apply_coupon(): void
{
    thean_lw_verify_ajax();

    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(['message' => 'Giỏ hàng chưa sẵn sàng.'], 400);
    }

    $code = (string) (thean_lw_get_state()['coupon_code'] ?? '');
    if ($code === '') {
        wp_send_json_error(['message' => 'Chưa có mã ưu đãi để áp dụng.'], 400);
    }

    if (thean_lw_cart_has_other_lucky_wheel_coupon($code)) {
        wp_send_json_error(['message' => 'Mỗi đơn hàng chỉ được dùng 1 mã Lucky Wheel.'], 409);
    }

    if (!WC()->cart->has_discount($code)) {
        WC()->cart->apply_coupon($code);
        WC()->cart->calculate_totals();
    }

    wp_send_json_success([
        'message' => 'Đã áp dụng mã ưu đãi.',
        'cart_url' => wc_get_cart_url(),
        'checkout_url' => wc_get_checkout_url(),
    ]);
}

function thean_lw_validate_single_lucky_wheel_coupon($valid, $coupon, $discount)
{
    if (!$valid || !thean_lw_is_lucky_wheel_coupon($coupon)) {
        return $valid;
    }

    if (thean_lw_cart_has_other_lucky_wheel_coupon($coupon->get_code())) {
        throw new Exception('Mỗi đơn hàng chỉ được dùng 1 mã Lucky Wheel.');
    }

    $reward = thean_lw_coupon_reward($coupon);
    if ($reward && !thean_lw_cart_matches_advanced_reward($reward)) {
        throw new Exception(thean_lw_advanced_reward_error_message($reward));
    }

    return $valid;
}

function thean_lw_coupon_reward($coupon): ?array
{
    if (!class_exists('WC_Coupon')) {
        return null;
    }

    if (!$coupon instanceof WC_Coupon) {
        $coupon = new WC_Coupon((string) $coupon);
    }

    $raw_config = (string) $coupon->get_meta('_thean_lw_reward_config', true);
    if ($raw_config !== '') {
        $decoded = json_decode($raw_config, true);
        if (is_array($decoded)) {
            $normalized = thean_lw_normalize_reward($decoded);
            if ($normalized !== null) {
                return $normalized;
            }
        }
    }

    $reward_id = (string) $coupon->get_meta('_thean_lw_reward_id', true);
    if ($reward_id === '') {
        return null;
    }

    foreach (thean_lw_rewards() as $reward) {
        if ((string) $reward['id'] === $reward_id) {
            return $reward;
        }
    }

    return null;
}

function thean_lw_is_advanced_reward(array $reward): bool
{
    return in_array((string) ($reward['type'] ?? ''), ['shipping_cap', 'buy_x_get_y', 'taxonomy_quantity_discount'], true);
}

function thean_lw_cart_matches_advanced_reward(array $reward): bool
{
    if (!thean_lw_is_advanced_reward($reward) || !function_exists('WC') || !WC()->cart) {
        return true;
    }

    if ((float) ($reward['min_cart'] ?? 0) > 0 && (float) WC()->cart->get_subtotal() < (float) $reward['min_cart']) {
        return false;
    }

    if ($reward['type'] === 'buy_x_get_y') {
        return thean_lw_cart_buy_qty($reward) >= (int) $reward['buy_qty'];
    }

    if ($reward['type'] === 'taxonomy_quantity_discount') {
        return thean_lw_cart_taxonomy_qty($reward) >= (int) $reward['min_qty'];
    }

    return true;
}

function thean_lw_advanced_reward_error_message(array $reward): string
{
    if ((float) ($reward['min_cart'] ?? 0) > 0 && function_exists('WC') && WC()->cart && (float) WC()->cart->get_subtotal() < (float) $reward['min_cart']) {
        return 'Giỏ hàng chưa đạt giá trị tối thiểu để dùng mã Lucky Wheel này.';
    }

    if (($reward['type'] ?? '') === 'buy_x_get_y') {
        return 'Giỏ hàng chưa có đủ sản phẩm mua kèm để nhận quà Lucky Wheel.';
    }

    if (($reward['type'] ?? '') === 'taxonomy_quantity_discount') {
        return 'Giỏ hàng chưa có đủ số lượng sản phẩm trong nhóm áp dụng mã Lucky Wheel.';
    }

    return 'Giỏ hàng chưa đủ điều kiện áp dụng mã Lucky Wheel này.';
}

function thean_lw_cart_item_product_ids(array $cart_item): array
{
    $ids = [];
    $product_id = absint($cart_item['product_id'] ?? 0);
    $variation_id = absint($cart_item['variation_id'] ?? 0);

    if ($product_id > 0) {
        $ids[] = $product_id;
    }
    if ($variation_id > 0) {
        $ids[] = $variation_id;
    }

    return array_values(array_unique($ids));
}

function thean_lw_cart_buy_qty(array $reward): int
{
    if (!function_exists('WC') || !WC()->cart) {
        return 0;
    }

    $target_ids = array_map('absint', (array) ($reward['buy_product_ids'] ?? []));
    $quantity = 0;

    foreach (WC()->cart->get_cart() as $cart_item) {
        if (!empty($cart_item['thean_lw_bogo_gift'])) {
            continue;
        }

        if (!array_intersect($target_ids, thean_lw_cart_item_product_ids($cart_item))) {
            continue;
        }

        $quantity += (int) ($cart_item['quantity'] ?? 0);
    }

    return $quantity;
}

function thean_lw_cart_taxonomy_qty(array $reward): int
{
    if (!function_exists('WC') || !WC()->cart) {
        return 0;
    }

    $taxonomy = (string) ($reward['taxonomy'] ?? 'product_cat');
    $term_slugs = array_filter(array_map('sanitize_title', (array) ($reward['term_slugs'] ?? [])));
    if (!in_array($taxonomy, ['product_cat', 'product_tag'], true) || empty($term_slugs)) {
        return 0;
    }

    $quantity = 0;
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (!empty($cart_item['thean_lw_bogo_gift'])) {
            continue;
        }

        $product_id = absint($cart_item['product_id'] ?? 0);
        if ($product_id <= 0 || !has_term($term_slugs, $taxonomy, $product_id)) {
            continue;
        }

        $quantity += (int) ($cart_item['quantity'] ?? 0);
    }

    return $quantity;
}

function thean_lw_handle_applied_coupon(string $coupon_code): void
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $coupon = new WC_Coupon($coupon_code);
    $reward = thean_lw_coupon_reward($coupon);
    if ($reward && $reward['type'] === 'buy_x_get_y') {
        thean_lw_sync_bogo_cart_items(WC()->cart);
    }
}

function thean_lw_handle_removed_coupon(string $coupon_code): void
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    thean_lw_remove_bogo_gift_items(thean_lw_format_coupon_code($coupon_code));
}

function thean_lw_sync_bogo_cart_items($cart): void
{
    static $syncing = false;

    if ($syncing || !thean_lw_cart_is_usable($cart)) {
        return;
    }

    $syncing = true;

    foreach (thean_lw_applied_lucky_wheel_rewards($cart) as $entry) {
        $reward = $entry['reward'];
        if (($reward['type'] ?? '') !== 'buy_x_get_y') {
            continue;
        }

        $coupon_code = (string) $entry['code'];
        if (thean_lw_cart_buy_qty($reward) < (int) $reward['buy_qty']) {
            $cart->remove_coupon($coupon_code);
            thean_lw_remove_bogo_gift_items(thean_lw_format_coupon_code($coupon_code));
            continue;
        }

        thean_lw_ensure_bogo_gift_item($cart, $coupon_code, $reward);
    }

    $syncing = false;
}

function thean_lw_cart_is_usable($cart): bool
{
    return is_object($cart) && method_exists($cart, 'get_cart') && method_exists($cart, 'get_applied_coupons');
}

function thean_lw_applied_lucky_wheel_rewards($cart): array
{
    $entries = [];
    if (!thean_lw_cart_is_usable($cart) || !class_exists('WC_Coupon')) {
        return $entries;
    }

    foreach ($cart->get_applied_coupons() as $coupon_code) {
        $coupon = new WC_Coupon((string) $coupon_code);
        if (!thean_lw_is_lucky_wheel_coupon($coupon)) {
            continue;
        }

        $reward = thean_lw_coupon_reward($coupon);
        if (!$reward) {
            continue;
        }

        $entries[] = [
            'code' => thean_lw_format_coupon_code((string) $coupon_code),
            'coupon' => $coupon,
            'reward' => $reward,
        ];
    }

    return $entries;
}

function thean_lw_ensure_bogo_gift_item($cart, string $coupon_code, array $reward): void
{
    $gift_qty = max(1, (int) ($reward['gift_qty'] ?? 1));
    $coupon_code = thean_lw_format_coupon_code($coupon_code);
    $existing_qty = 0;

    foreach ($cart->get_cart() as $cart_item) {
        if (empty($cart_item['thean_lw_bogo_gift']) || (string) ($cart_item['thean_lw_coupon_code'] ?? '') !== $coupon_code) {
            continue;
        }

        $existing_qty += (int) ($cart_item['quantity'] ?? 0);
    }

    if ($existing_qty >= $gift_qty) {
        return;
    }

    $product = wc_get_product(absint($reward['gift_product_id']));
    if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
        return;
    }

    $product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
    $cart->add_to_cart($product_id, $gift_qty - $existing_qty, $variation_id, [], [
        'thean_lw_bogo_gift' => true,
        'thean_lw_coupon_code' => $coupon_code,
        'thean_lw_reward_id' => (string) $reward['id'],
    ]);
}

function thean_lw_remove_bogo_gift_items(string $coupon_code = ''): void
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $coupon_code = thean_lw_format_coupon_code($coupon_code);
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        if (empty($cart_item['thean_lw_bogo_gift'])) {
            continue;
        }

        if ($coupon_code !== '' && (string) ($cart_item['thean_lw_coupon_code'] ?? '') !== $coupon_code) {
            continue;
        }

        WC()->cart->remove_cart_item($cart_item_key);
    }
}

function thean_lw_apply_advanced_reward_fees($cart): void
{
    if (!thean_lw_cart_is_usable($cart) || is_admin() && !wp_doing_ajax()) {
        return;
    }

    foreach (thean_lw_applied_lucky_wheel_rewards($cart) as $entry) {
        $reward = $entry['reward'];
        if (!thean_lw_is_advanced_reward($reward) || !thean_lw_cart_matches_advanced_reward($reward)) {
            continue;
        }

        $amount = thean_lw_advanced_reward_discount_amount($cart, $reward, (string) $entry['code']);
        if ($amount <= 0) {
            continue;
        }

        $cart->add_fee(sprintf('Lucky Wheel - %s', (string) $reward['label']), -1 * $amount, false);
    }
}

function thean_lw_advanced_reward_discount_amount($cart, array $reward, string $coupon_code): float
{
    $amount = 0.0;

    if ($reward['type'] === 'shipping_cap') {
        $amount = min((float) $reward['amount'], thean_lw_cart_shipping_total($cart));
    } elseif ($reward['type'] === 'buy_x_get_y') {
        $amount = thean_lw_bogo_gift_discount_amount($cart, $reward, $coupon_code);
    } elseif ($reward['type'] === 'taxonomy_quantity_discount') {
        $subtotal = thean_lw_taxonomy_matching_subtotal($cart, $reward);
        if ((string) $reward['discount_mode'] === 'percent') {
            $amount = $subtotal * ((float) $reward['amount'] / 100);
        } else {
            $amount = min((float) $reward['amount'], $subtotal);
        }
    }

    $max_value = (float) ($reward['max_value'] ?? 0);
    if ($max_value > 0) {
        $amount = min($amount, $max_value);
    }

    return max(0.0, (float) wc_format_decimal($amount, wc_get_price_decimals()));
}

function thean_lw_cart_shipping_total($cart): float
{
    if (method_exists($cart, 'get_shipping_total')) {
        return max(0.0, (float) $cart->get_shipping_total());
    }

    return max(0.0, (float) ($cart->shipping_total ?? 0));
}

function thean_lw_bogo_gift_discount_amount($cart, array $reward, string $coupon_code): float
{
    $coupon_code = thean_lw_format_coupon_code($coupon_code);
    $remaining_qty = max(1, (int) ($reward['gift_qty'] ?? 1));
    $amount = 0.0;

    foreach ($cart->get_cart() as $cart_item) {
        if ($remaining_qty <= 0 || empty($cart_item['thean_lw_bogo_gift']) || (string) ($cart_item['thean_lw_coupon_code'] ?? '') !== $coupon_code) {
            continue;
        }

        $quantity = min($remaining_qty, (int) ($cart_item['quantity'] ?? 0));
        $line_subtotal = (float) ($cart_item['line_subtotal'] ?? 0);
        $item_qty = max(1, (int) ($cart_item['quantity'] ?? 1));
        $amount += ($line_subtotal / $item_qty) * $quantity;
        $remaining_qty -= $quantity;
    }

    return $amount;
}

function thean_lw_taxonomy_matching_subtotal($cart, array $reward): float
{
    $taxonomy = (string) ($reward['taxonomy'] ?? 'product_cat');
    $term_slugs = array_filter(array_map('sanitize_title', (array) ($reward['term_slugs'] ?? [])));
    $subtotal = 0.0;

    if (!in_array($taxonomy, ['product_cat', 'product_tag'], true) || empty($term_slugs)) {
        return 0.0;
    }

    foreach ($cart->get_cart() as $cart_item) {
        if (!empty($cart_item['thean_lw_bogo_gift'])) {
            continue;
        }

        $product_id = absint($cart_item['product_id'] ?? 0);
        if ($product_id <= 0 || !has_term($term_slugs, $taxonomy, $product_id)) {
            continue;
        }

        $subtotal += (float) ($cart_item['line_subtotal'] ?? 0);
    }

    return $subtotal;
}

function thean_lw_cart_has_other_lucky_wheel_coupon(string $coupon_code): bool
{
    if (!function_exists('WC') || !WC()->cart) {
        return false;
    }

    $current_code = thean_lw_format_coupon_code($coupon_code);

    foreach (WC()->cart->get_applied_coupons() as $applied_code) {
        $applied_code = thean_lw_format_coupon_code((string) $applied_code);
        if ($applied_code === $current_code) {
            continue;
        }

        if (thean_lw_is_lucky_wheel_coupon($applied_code)) {
            return true;
        }
    }

    return false;
}

function thean_lw_format_coupon_code(string $coupon_code): string
{
    if (function_exists('wc_format_coupon_code')) {
        return wc_format_coupon_code($coupon_code);
    }

    return strtolower(trim($coupon_code));
}

function thean_lw_is_lucky_wheel_coupon($coupon): bool
{
    if (!class_exists('WC_Coupon')) {
        return false;
    }

    if (!$coupon instanceof WC_Coupon) {
        $coupon = new WC_Coupon((string) $coupon);
    }

    $code = strtoupper((string) $coupon->get_code());

    return strpos($code, 'LW-') === 0 || (string) $coupon->get_meta('_thean_lw_reward_id', true) !== '';
}

function thean_lw_verify_ajax(): void
{
    if (!check_ajax_referer(THEAN_LW_NONCE_ACTION, 'nonce', false)) {
        wp_send_json_error(['message' => 'Phiên bảo mật không hợp lệ. Vui lòng tải lại trang.'], 403);
    }

    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        wp_send_json_error(['message' => 'Yêu cầu không hợp lệ.'], 403);
    }
}

function thean_lw_session_id(): string
{
    static $session_id = null;

    if ($session_id !== null) {
        return $session_id;
    }

    $session_id = isset($_COOKIE[THEAN_LW_SESSION_COOKIE]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[THEAN_LW_SESSION_COOKIE])) : '';

    if ($session_id === '') {
        $session_id = wp_generate_password(24, false, false);
        if (!headers_sent()) {
            setcookie(THEAN_LW_SESSION_COOKIE, $session_id, time() + (2 * DAY_IN_SECONDS), COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[THEAN_LW_SESSION_COOKIE] = $session_id;
    }

    return $session_id;
}

function thean_lw_state_key(): string
{
    return THEAN_LW_STATE_PREFIX . hash('sha256', thean_lw_session_id());
}

function thean_lw_default_state(): array
{
    return [
        'started_at' => 0,
        'last_seen_at' => 0,
        'last_spin_at' => 0,
        'spins_used' => 0,
        'prizes' => [],
        'selected_token' => '',
        'coupon_id' => 0,
        'coupon_code' => '',
        'coupon_expires' => 0,
    ];
}

function thean_lw_get_state(): array
{
    $state = get_transient(thean_lw_state_key());
    if (!is_array($state)) {
        $state = thean_lw_default_state();
    }

    return array_merge(thean_lw_default_state(), $state);
}

function thean_lw_save_state(array $state): void
{
    set_transient(thean_lw_state_key(), $state, 2 * DAY_IN_SECONDS);
}

function thean_lw_public_state(): array
{
    $state = thean_lw_get_state();
    $prizes = [];

    foreach ($state['prizes'] as $token => $prize) {
        $prizes[] = [
            'label' => (string) ($prize['label'] ?? ''),
            'claim_token' => thean_lw_sign_token((string) $token),
            'claimed' => !empty($prize['claimed']),
            'selected' => ((string) $state['selected_token'] === (string) $token),
            'segment_index' => (int) ($prize['segment_index'] ?? 0),
        ];
    }

    return [
        'max_spins' => THEAN_LW_MAX_SPINS,
        'spins_used' => (int) $state['spins_used'],
        'spins_left' => max(0, THEAN_LW_MAX_SPINS - (int) $state['spins_used']),
        'prizes' => $prizes,
        'coupon_code' => (string) $state['coupon_code'],
        'coupon_expires' => (int) $state['coupon_expires'],
    ];
}

function thean_lw_public_reward(array $reward): array
{
    return [
        'id' => $reward['id'],
        'label' => $reward['label'],
        'type' => $reward['type'],
        'amount' => $reward['amount'],
        'min_cart' => $reward['min_cart'],
        'segment_index' => (int) $reward['segment_index'],
    ];
}

function thean_lw_pick_reward(): ?array
{
    $pool = thean_lw_active_rewards();
    $total = 0;

    foreach ($pool as $reward) {
        $total += (int) $reward['frequency'];
    }

    if ($total <= 0 || empty($pool)) {
        return null;
    }

    $roll = wp_rand(1, $total);
    $cursor = 0;

    foreach ($pool as $index => $reward) {
        $cursor += (int) $reward['frequency'];
        if ($roll <= $cursor) {
            $reward['segment_index'] = $index;
            return $reward;
        }
    }

    $pool[0]['segment_index'] = 0;
    return $pool[0];
}

function thean_lw_reward_by_id(string $reward_id): ?array
{
    foreach (thean_lw_active_rewards() as $index => $reward) {
        if ((string) $reward['id'] === $reward_id) {
            $reward['segment_index'] = $index;
            return $reward;
        }
    }

    return null;
}

function thean_lw_sign_token(string $token): string
{
    return $token . '.' . hash_hmac('sha256', $token, wp_salt('thean_lw'));
}

function thean_lw_verify_token(string $signed_token): string
{
    $parts = explode('.', $signed_token, 2);
    if (count($parts) !== 2) {
        return '';
    }

    [$token, $signature] = $parts;
    $expected = hash_hmac('sha256', $token, wp_salt('thean_lw'));

    return hash_equals($expected, $signature) ? $token : '';
}

function thean_lw_normalize_contact(string $raw)
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }

    if (is_email($raw)) {
        return [
            'type' => 'email',
            'value' => strtolower(sanitize_email($raw)),
        ];
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if (strpos($digits, '84') === 0) {
        $digits = '0' . substr($digits, 2);
    }

    if (preg_match('/^0\d{9,10}$/', $digits)) {
        return [
            'type' => 'phone',
            'value' => $digits,
        ];
    }

    return false;
}

function thean_lw_claim_identities(array $contact): array
{
    $identities = [
        [
            'type' => $contact['type'],
            'value' => $contact['value'],
            'hash' => thean_lw_claim_identity_hash($contact['type'], $contact['value']),
        ],
    ];

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $identities[] = [
            'type' => 'user',
            'value' => (string) $user_id,
            'hash' => thean_lw_claim_identity_hash('user', (string) $user_id),
        ];

        $user = get_userdata($user_id);
        if ($user && is_email($user->user_email)) {
            $email = strtolower(sanitize_email($user->user_email));
            $identities[] = [
                'type' => 'email',
                'value' => $email,
                'hash' => thean_lw_claim_identity_hash('email', $email),
            ];
        }

        $billing_phone = thean_lw_normalize_contact((string) get_user_meta($user_id, 'billing_phone', true));
        if ($billing_phone && $billing_phone['type'] === 'phone') {
            $identities[] = [
                'type' => 'phone',
                'value' => $billing_phone['value'],
                'hash' => thean_lw_claim_identity_hash('phone', $billing_phone['value']),
            ];
        }
    }

    $unique = [];
    foreach ($identities as $identity) {
        $unique[$identity['hash']] = $identity;
    }

    return array_values($unique);
}

function thean_lw_claim_identity_hash(string $type, string $value): string
{
    return hash('sha256', sanitize_key($type) . ':' . strtolower(trim($value)));
}

function thean_lw_claim_lock_key(array $identity): string
{
    return 'thean_lw_claim_lock_' . md5((string) $identity['hash']);
}

function thean_lw_claim_cooldown_remaining(array $identities): int
{
    $now = time();
    $remaining = 0;

    foreach ($identities as $identity) {
        $expires = (int) get_transient(thean_lw_claim_lock_key($identity));
        if ($expires > $now) {
            $remaining = max($remaining, $expires - $now);
            continue;
        }

        $created_at = thean_lw_latest_claim_time($identity);
        if ($created_at > 0) {
            $remaining = max($remaining, max(0, ($created_at + THEAN_LW_CLAIM_COOLDOWN) - $now));
        }
    }

    return $remaining;
}

function thean_lw_active_claim_coupon_remaining(array $identities): int
{
    if (!class_exists('WC_Coupon')) {
        return 0;
    }

    $remaining = 0;

    foreach (thean_lw_claim_coupon_ids($identities, 20) as $coupon_id) {
        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id() || !$coupon->get_code()) {
            continue;
        }

        $expires = $coupon->get_date_expires();
        if (!$expires) {
            $remaining = max($remaining, THEAN_LW_CLAIM_COOLDOWN);
            continue;
        }

        $expires_at = $expires->getTimestamp();
        if ($expires_at > time()) {
            $remaining = max($remaining, $expires_at - time());
        }
    }

    return $remaining;
}

function thean_lw_latest_claim_time(array $identity): int
{
    $coupon_ids = thean_lw_claim_coupon_ids([$identity], 1, [
        [
            'column' => 'post_date_gmt',
            'after' => gmdate('Y-m-d H:i:s', time() - THEAN_LW_CLAIM_COOLDOWN),
            'inclusive' => true,
        ],
    ]);

    if (empty($coupon_ids[0])) {
        return 0;
    }

    return (int) get_post_time('U', true, (int) $coupon_ids[0]);
}

function thean_lw_claim_coupon_ids(array $identities, int $limit = 1, array $date_query = []): array
{
    static $cache = [];

    $meta_query = [
        'relation' => 'OR',
    ];

    foreach ($identities as $identity) {
        $meta_query[] = [
            'key' => '_thean_lw_identity_hash',
            'value' => (string) $identity['hash'],
        ];

        if ($identity['type'] === 'user') {
            $meta_query[] = [
                'key' => '_thean_lw_user_id',
                'value' => (string) $identity['value'],
            ];
        } elseif (in_array($identity['type'], ['email', 'phone'], true)) {
            $meta_query[] = [
                'relation' => 'AND',
                [
                    'key' => '_thean_lw_contact_type',
                    'value' => (string) $identity['type'],
                ],
                [
                    'key' => '_thean_lw_contact_value',
                    'value' => (string) $identity['value'],
                ],
            ];
        }
    }

    $args = [
        'post_type' => 'shop_coupon',
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => max(1, $limit),
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_thean_lw_reward_id',
                'compare' => 'EXISTS',
            ],
            $meta_query,
        ],
        'no_found_rows' => true,
        'suppress_filters' => true,
    ];

    if ($date_query) {
        $args['date_query'] = $date_query;
    }

    $cache_key = md5(wp_json_encode([
        'identities' => array_map(static function ($identity) {
            return [
                'type' => (string) ($identity['type'] ?? ''),
                'value' => (string) ($identity['value'] ?? ''),
                'hash' => (string) ($identity['hash'] ?? ''),
            ];
        }, $identities),
        'limit' => $limit,
        'date_query' => $date_query,
    ]));

    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $cache[$cache_key] = array_map('intval', get_posts($args));
    return $cache[$cache_key];
}

function thean_lw_set_claim_locks(array $identities): void
{
    $expires = time() + THEAN_LW_CLAIM_COOLDOWN;

    foreach ($identities as $identity) {
        set_transient(thean_lw_claim_lock_key($identity), $expires, THEAN_LW_CLAIM_COOLDOWN);
    }
}

function thean_lw_format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = (int) ceil($seconds / HOUR_IN_SECONDS);
    if ($hours <= 1) {
        return 'khoảng 1 giờ';
    }

    return 'khoảng ' . $hours . ' giờ';
}

function thean_lw_create_coupon(array $reward, array $contact, array $claim_identities = [])
{
    if (!class_exists('WC_Coupon')) {
        return new WP_Error('missing_wc', 'WooCommerce chưa sẵn sàng để tạo coupon.');
    }

    $code = 'LW-' . strtoupper(wp_generate_password(8, false, false));
    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_description(sprintf(
        'Lucky Wheel - %s: %s - IP: %s',
        $contact['type'] === 'email' ? 'email' : 'phone',
        $contact['value'],
        thean_lw_client_ip()
    ));
    $coupon->set_usage_limit(1);
    $coupon->set_usage_limit_per_user(1);
    $coupon->set_individual_use(true);
    $coupon->set_date_expires((new WC_DateTime())->setTimestamp(time() + thean_lw_coupon_ttl()));
    $coupon->update_meta_data('_thean_lw_reward_id', $reward['id']);
    $coupon->update_meta_data('_thean_lw_reward_type', $reward['type']);
    $coupon->update_meta_data('_thean_lw_reward_config', wp_json_encode($reward, JSON_UNESCAPED_UNICODE));
    $coupon->update_meta_data('_thean_lw_contact_type', $contact['type']);
    $coupon->update_meta_data('_thean_lw_contact_value', $contact['value']);
    $coupon->update_meta_data('_thean_lw_claimed_at', time());

    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $coupon->update_meta_data('_thean_lw_user_id', (string) $user_id);
    }

    foreach ($claim_identities as $identity) {
        $coupon->add_meta_data('_thean_lw_identity_hash', (string) $identity['hash'], false);
    }

    if ((float) $reward['min_cart'] > 0) {
        $coupon->set_minimum_amount((string) $reward['min_cart']);
    }

    if ($contact['type'] === 'email') {
        $coupon->set_email_restrictions([$contact['value']]);
    }

    switch ($reward['type']) {
        case 'percent':
            $coupon->set_discount_type('percent');
            $coupon->set_amount((string) $reward['amount']);
            break;
        case 'fixed_cart':
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount((string) $reward['amount']);
            break;
        case 'free_shipping':
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount('0');
            $coupon->set_free_shipping(true);
            break;
        case 'shipping_cap':
        case 'buy_x_get_y':
        case 'taxonomy_quantity_discount':
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount('0');
            break;
    }

    $coupon_id = $coupon->save();
    if (!$coupon_id) {
        return new WP_Error('coupon_failed', 'Không thể tạo mã ưu đãi.');
    }

    return [
        'id' => $coupon_id,
        'code' => $code,
        'expires' => time() + thean_lw_coupon_ttl(),
    ];
}

function thean_lw_send_to_sheets(array $payload): void
{
    $url = thean_lw_sheets_webhook_url();
    if ($url === '') {
        return;
    }

    $args = [
        'timeout' => 0.01,
        'blocking' => false,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ];

    $secret = thean_lw_sheets_webhook_secret();
    if ($secret !== '') {
        $args['headers']['X-Thean-LW-Secret'] = $secret;
    }

    wp_remote_post($url, $args);
}

function thean_lw_rate_limit(string $key, int $limit, int $ttl): bool
{
    $transient_key = 'thean_lw_rl_' . md5($key);
    $count = (int) get_transient($transient_key);

    if ($count >= $limit) {
        return false;
    }

    set_transient($transient_key, $count + 1, $ttl);
    return true;
}

function thean_lw_pass_interaction_gate(array $state, int $min_age_seconds): bool
{
    $started_at = (int) ($state['started_at'] ?? 0);
    if ($started_at <= 0) {
        return false;
    }

    return (time() - $started_at) >= $min_age_seconds;
}

function thean_lw_client_ip_hash(): string
{
    return hash('sha256', thean_lw_client_ip());
}

function thean_lw_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

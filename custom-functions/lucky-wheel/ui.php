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
    ?>
    <div
        id="thean-lw-root"
        class="thean-lw"
        data-context="<?php echo esc_attr(thean_lw_page_context()); ?>"
        data-vertical="<?php echo esc_attr($trigger['vertical']); ?>"
        data-horizontal="<?php echo esc_attr($trigger['horizontal']); ?>"
        data-display="<?php echo esc_attr($trigger['display']); ?>"
        data-segments="<?php echo esc_attr((string) count($segments)); ?>"
    >
        <button class="thean-lw-trigger <?php echo esc_attr($trigger['custom_class']); ?>" type="button" aria-haspopup="dialog" disabled>
            <span class="thean-lw-trigger__icon">%</span>
            <span class="thean-lw-trigger__text">Nhận ưu đãi hôm nay</span>
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
                            <button class="thean-lw-btn thean-lw-btn--secondary" type="button" data-thean-lw-save hidden>Chọn ưu đãi</button>
                        </div>
                    </div>
                    <div class="thean-lw-content">
                        <p class="thean-lw-kicker">Ưu đãi riêng cho lượt ghé này</p>
                        <h2 id="thean-lw-title">Quay để giữ mã trong <?php echo esc_html((string) thean_lw_coupon_hold_hours()); ?> giờ</h2>
                        <p class="thean-lw-copy">Tối đa 3 lượt quay. Chọn 1 ưu đãi rồi nhập email hoặc số điện thoại để nhận mã.</p>
                        <p class="thean-lw-spins" data-thean-lw-spins>Đang tải...</p>
                        <div class="thean-lw-result-list" data-thean-lw-result-list hidden></div>
                        <form class="thean-lw-form" data-thean-lw-form hidden>
                            <label for="thean-lw-contact">Email hoặc số điện thoại</label>
                            <input id="thean-lw-contact" name="contact" type="text" inputmode="email" autocomplete="email tel" placeholder="email@example.com hoặc 09..." required>
                            <input class="thean-lw-hp" name="website" type="text" tabindex="-1" autocomplete="off">
                            <button class="thean-lw-btn thean-lw-btn--primary" type="submit">Nhận mã ưu đãi</button>
                            <p class="thean-lw-form-note">Mã chỉ được tạo sau bước này và có hiệu lực trong <?php echo esc_html((string) thean_lw_coupon_hold_hours()); ?> giờ.</p>
                        </form>
                        <div class="thean-lw-coupon" data-thean-lw-coupon hidden></div>
                        <p class="thean-lw-message" data-thean-lw-message role="status"></p>
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

    $contact_hash = hash('sha256', $contact['type'] . ':' . $contact['value']);
    if (
        !thean_lw_rate_limit('claim_session_' . thean_lw_session_id(), 2, DAY_IN_SECONDS) ||
        !thean_lw_rate_limit('claim_ip_' . thean_lw_client_ip_hash(), 5, HOUR_IN_SECONDS) ||
        !thean_lw_rate_limit('claim_contact_' . $contact_hash, 2, DAY_IN_SECONDS)
    ) {
        wp_send_json_error(['message' => 'Bạn đã yêu cầu quá nhiều mã ưu đãi. Vui lòng thử lại sau.'], 429);
    }

    if (!empty($state['coupon_code'])) {
        wp_send_json_success(thean_lw_public_state());
    }

    $reward = thean_lw_reward_by_id((string) $state['prizes'][$token]['reward_id']);
    if (!$reward) {
        wp_send_json_error(['message' => 'Không tìm thấy cấu hình ưu đãi.'], 500);
    }

    $coupon = thean_lw_create_coupon($reward, $contact);
    if (is_wp_error($coupon)) {
        wp_send_json_error(['message' => $coupon->get_error_message()], 500);
    }

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
            'value' => sanitize_email($raw),
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

function thean_lw_create_coupon(array $reward, array $contact)
{
    if (!class_exists('WC_Coupon')) {
        return new WP_Error('missing_wc', 'WooCommerce chưa sẵn sàng để tạo coupon.');
    }

    $code = 'LW-' . strtoupper(wp_generate_password(8, false, false));
    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_description('Lucky Wheel');
    $coupon->set_usage_limit(1);
    $coupon->set_usage_limit_per_user(1);
    $coupon->set_individual_use(true);
    $coupon->set_date_expires((new WC_DateTime())->setTimestamp(time() + thean_lw_coupon_ttl()));
    $coupon->update_meta_data('_thean_lw_reward_id', $reward['id']);
    $coupon->update_meta_data('_thean_lw_contact_type', $contact['type']);
    $coupon->update_meta_data('_thean_lw_contact_value', $contact['value']);

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
        'timeout' => 5,
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
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    return hash('sha256', $ip);
}

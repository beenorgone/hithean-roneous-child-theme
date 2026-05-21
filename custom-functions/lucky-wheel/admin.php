<?php

if (!defined('ABSPATH')) {
    exit;
}

function thean_lw_seed_default_settings(): void
{
    if (get_option(THEAN_LW_OPTION_KEY, null) === null) {
        add_option(THEAN_LW_OPTION_KEY, thean_lw_default_settings(), '', false);
    }
}
add_action('admin_init', 'thean_lw_seed_default_settings', 1);

function thean_lw_register_settings(): void
{
    register_setting('thean_lw_settings_group', THEAN_LW_OPTION_KEY, 'thean_lw_sanitize_settings');
}
add_action('admin_init', 'thean_lw_register_settings');

function thean_lw_register_admin_menu(): void
{
    add_menu_page(
        'Lucky Wheel',
        'Lucky Wheel',
        'read',
        'thean-lucky-wheel',
        'thean_lw_render_admin_page',
        'dashicons-megaphone',
        58
    );
}
add_action('admin_menu', 'thean_lw_register_admin_menu', 20);

function thean_lw_sanitize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $decoded = json_decode(wp_unslash((string) ($input['rewards_json'] ?? '')), true);
    $rewards = [];

    if (is_array($decoded)) {
        foreach ($decoded as $reward) {
            if (!is_array($reward)) {
                continue;
            }

            $normalized = thean_lw_normalize_reward($reward);
            if ($normalized !== null) {
                $rewards[] = $normalized;
            }
        }
    }

    if (empty($rewards)) {
        add_settings_error(THEAN_LW_OPTION_KEY, 'invalid_rewards', 'Rewards JSON không hợp lệ. Đã giữ cấu hình mặc định.');
        $rewards = thean_lw_default_rewards();
    }

    $decoded_rules = json_decode(wp_unslash((string) ($input['trigger_rules'] ?? '')), true);
    $trigger_rules = [];
    if (is_array($decoded_rules)) {
        foreach ($decoded_rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $normalized = thean_lw_normalize_trigger_rule($rule);
            if ($normalized !== null) {
                $trigger_rules[] = $normalized;
            }
        }
    }
    if (empty($trigger_rules)) {
        add_settings_error(THEAN_LW_OPTION_KEY, 'invalid_trigger_rules', 'Trigger Rules JSON không hợp lệ. Đã dùng cấu hình mặc định.');
        $trigger_rules = thean_lw_default_trigger_rules();
    }

    $hold_hours = trim((string) ($input['coupon_hold_hours'] ?? ''));
    if ($hold_hours !== '') {
        $hold_hours = (string) max(1, min(168, (int) $hold_hours));
    }

    return [
        'enabled' => empty($input['enabled']) ? 0 : 1,
        'offer_slugs' => sanitize_textarea_field((string) ($input['offer_slugs'] ?? '')),
        'trigger_rules' => wp_json_encode($trigger_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        'coupon_hold_hours' => $hold_hours,
        'sheets_webhook_url' => esc_url_raw((string) ($input['sheets_webhook_url'] ?? '')),
        'sheets_webhook_secret' => sanitize_text_field((string) ($input['sheets_webhook_secret'] ?? '')),
        'rewards_json' => wp_json_encode($rewards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ];
}

function thean_lw_render_admin_page(): void
{
    if (!thean_lw_can_manage()) {
        wp_die(esc_html__('Xin lỗi, bạn không được phép truy cập vào trang này.', 'theanmarket'));
    }

    $settings = thean_lw_get_settings();
    ?>
    <div class="wrap">
        <h1>Lucky Wheel</h1>
        <p>Cấu hình vòng quay ưu đãi cho website.</p>

        <form method="post" action="options.php">
            <?php settings_fields('thean_lw_settings_group'); ?>
            <?php settings_errors(THEAN_LW_OPTION_KEY); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Bật tính năng</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                            Bật Lucky Wheel trên frontend
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Slug trang ưu đãi</th>
                    <td>
                        <textarea class="large-text" rows="4" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[offer_slugs]"><?php echo esc_textarea((string) $settings['offer_slugs']); ?></textarea>
                        <p class="description">Mỗi dòng một slug. Ví dụ: <code>uu-dai</code>, <code>khuyen-mai</code>, <code>sale</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Vị trí nút theo URL</th>
                    <td>
                        <textarea class="large-text code" rows="12" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[trigger_rules]"><?php echo esc_textarea((string) $settings['trigger_rules']); ?></textarea>
                        <p class="description">
                            Mảng JSON các rule theo thứ tự ưu tiên — rule đầu tiên khớp URL sẽ được dùng.<br>
                            Fields: <code>url_pattern</code>, <code>vertical</code> (<code>top</code>/<code>bottom</code>), <code>horizontal</code> (<code>left</code>/<code>right</code>), <code>display</code> (<code>icon_text</code>/<code>icon_only</code>/<code>text_only</code>), <code>custom_class</code>.<br>
                            <code>url_pattern</code> hỗ trợ wildcard <code>*</code>. Ví dụ: <code>/gio-hang*</code> khớp trang giỏ hàng, <code>*</code> là catch-all.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Số giờ giữ mã</th>
                    <td>
                        <input type="number" min="1" max="168" step="1" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[coupon_hold_hours]" value="<?php echo esc_attr((string) $settings['coupon_hold_hours']); ?>">
                        <p class="description">Để trống để dùng mặc định tối ưu cho e-commerce: <strong>24 giờ</strong>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Google Sheets webhook</th>
                    <td>
                        <input class="large-text" type="url" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[sheets_webhook_url]" value="<?php echo esc_attr((string) $settings['sheets_webhook_url']); ?>" placeholder="https://script.google.com/macros/s/.../exec">
                        <p class="description">Dán URL Web App của Google Apps Script để tự động append lead vào file Google Sheets bạn chỉ định.</p>
                        <input class="regular-text" type="text" style="margin-top:10px" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[sheets_webhook_secret]" value="<?php echo esc_attr((string) $settings['sheets_webhook_secret']); ?>" placeholder="Webhook secret (optional)">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rewards JSON</th>
                    <td>
                        <textarea class="large-text code" rows="18" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[rewards_json]"><?php echo esc_textarea((string) $settings['rewards_json']); ?></textarea>
                        <p class="description">
                            Field hỗ trợ: <code>id</code>, <code>label</code>, <code>type</code>, <code>amount</code>, <code>frequency</code>, <code>min_cart</code>, <code>active</code>.
                            <br>
                            <code>type</code> nhận các giá trị: <code>percent</code>, <code>fixed_cart</code>, <code>free_shipping</code>.
                            <br>
                            <code>frequency</code> là tần suất tương đối. Giá trị càng cao thì phần thưởng càng xuất hiện nhiều.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Lưu cấu hình'); ?>
        </form>
    </div>
    <?php
}

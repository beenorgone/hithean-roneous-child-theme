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

    return [
        'enabled' => empty($input['enabled']) ? 0 : 1,
        'offer_slugs' => sanitize_textarea_field((string) ($input['offer_slugs'] ?? '')),
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

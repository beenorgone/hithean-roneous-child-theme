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
        'cleanup_skip_used' => empty($input['cleanup_skip_used']) ? 0 : 1,
        'sheets_webhook_url' => esc_url_raw((string) ($input['sheets_webhook_url'] ?? '')),
        'sheets_webhook_secret' => sanitize_text_field((string) ($input['sheets_webhook_secret'] ?? '')),
        'rewards_json' => wp_json_encode($rewards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ];
}

function thean_lw_handle_manual_cleanup(): void
{
    if (!thean_lw_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'theanmarket'));
    }

    check_admin_referer('thean_lw_manual_cleanup');

    $deleted = thean_lw_run_expired_coupon_cleanup();

    wp_safe_redirect(add_query_arg([
        'page' => 'thean-lucky-wheel',
        'thean_lw_cleanup_result' => $deleted,
    ], admin_url('admin.php')));
    exit;
}
add_action('admin_post_thean_lw_manual_cleanup', 'thean_lw_handle_manual_cleanup');

function thean_lw_handle_manual_truncate_log(): void
{
    if (!thean_lw_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'theanmarket'));
    }

    check_admin_referer('thean_lw_manual_truncate_log');

    $removed = thean_lw_prune_cleanup_log();

    wp_safe_redirect(add_query_arg([
        'page' => 'thean-lucky-wheel',
        'thean_lw_truncate_result' => $removed,
    ], admin_url('admin.php')));
    exit;
}
add_action('admin_post_thean_lw_manual_truncate_log', 'thean_lw_handle_manual_truncate_log');

function thean_lw_handle_view_log(): void
{
    if (!thean_lw_can_manage()) {
        wp_die(esc_html__('Bạn không có quyền thực hiện thao tác này.', 'theanmarket'));
    }

    check_admin_referer('thean_lw_view_log');

    $path = thean_lw_cleanup_log_file_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        wp_die(esc_html__('Chưa có file log.', 'theanmarket'));
    }

    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
add_action('admin_post_thean_lw_view_log', 'thean_lw_handle_view_log');

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

        <?php if (isset($_GET['thean_lw_cleanup_result'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Đã dọn <strong><?php echo esc_html((string) absint($_GET['thean_lw_cleanup_result'])); ?></strong> mã Lucky Wheel hết hạn quá <?php echo esc_html((string) THEAN_LW_CLEANUP_GRACE_DAYS); ?> ngày.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['thean_lw_truncate_result'])) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Đã truncate log — xóa <strong><?php echo esc_html((string) absint($_GET['thean_lw_truncate_result'])); ?></strong> dòng log cũ hơn <?php echo esc_html((string) THEAN_LW_LOG_TRUNCATE_DAYS); ?> ngày.</p>
            </div>
        <?php endif; ?>

        <div class="postbox" style="padding: 12px 16px; margin-bottom: 20px;">
            <h2>Bảo trì coupon hết hạn</h2>
            <p class="description">
                Tự động chạy hàng ngày (WP-Cron): xóa các mã Lucky Wheel đã hết hạn quá <strong><?php echo esc_html((string) THEAN_LW_CLEANUP_GRACE_DAYS); ?> ngày</strong> (log lại thông tin trước khi xóa),
                và truncate file log đó — chỉ giữ lại các dòng log của coupon <strong>hết hạn trong vòng <?php echo esc_html((string) THEAN_LW_LOG_TRUNCATE_DAYS); ?> ngày gần nhất</strong> (tính theo ngày coupon hết hạn, không phải ngày ghi log); dòng log của coupon hết hạn lâu hơn sẽ bị xóa khỏi log.
            </p>
            <p>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(THEAN_LW_OPTION_KEY); ?>[cleanup_skip_used]" value="1" form="thean_lw_settings_form" <?php checked(thean_lw_cleanup_skip_used_coupons()); ?>>
                    Bỏ qua (không xóa) các coupon đã được áp dụng vào đơn hàng, dù đã hết hạn quá <?php echo esc_html((string) THEAN_LW_CLEANUP_GRACE_DAYS); ?> ngày
                </label>
                <br><span class="description">Kiểm tra dựa trên số lần sử dụng thực tế của coupon (<code>usage_count</code>). Tắt để xóa luôn cả coupon đã dùng. Cần bấm "Lưu cấu hình" ở form bên dưới để áp dụng.</span>
            </p>
            <p class="description">
                Lần truncate log gần nhất:
                <strong>
                    <?php
                    $last_truncate = (int) get_option(THEAN_LW_LOG_LAST_TRUNCATE_OPTION, 0);
                    echo esc_html($last_truncate > 0 ? wp_date('d/m/Y H:i', $last_truncate) : 'Chưa từng chạy');
                    ?>
                </strong>
                <?php $log_path = thean_lw_cleanup_log_file_path(); ?>
                <?php if ($log_path !== '' && file_exists($log_path)) : ?>
                    — Log file: <code><?php echo esc_html(basename($log_path)); ?></code> (<?php echo esc_html(size_format((int) filesize($log_path))); ?>)
                <?php endif; ?>
            </p>
            <p>
                <a
                    class="button"
                    href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=thean_lw_manual_cleanup'), 'thean_lw_manual_cleanup')); ?>"
                    onclick="return confirm('Xóa ngay các mã Lucky Wheel đã hết hạn quá <?php echo esc_js((string) THEAN_LW_CLEANUP_GRACE_DAYS); ?> ngày?');"
                >Xóa coupon hết hạn ngay</a>
                <a
                    class="button"
                    href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=thean_lw_manual_truncate_log'), 'thean_lw_manual_truncate_log')); ?>"
                    onclick="return confirm('Truncate log ngay — chỉ giữ lại <?php echo esc_js((string) THEAN_LW_LOG_TRUNCATE_DAYS); ?> ngày dữ liệu gần nhất?');"
                >Truncate log ngay</a>
                <?php if ($log_path !== '' && file_exists($log_path)) : ?>
                    <a
                        class="button"
                        target="_blank"
                        rel="noopener"
                        href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=thean_lw_view_log'), 'thean_lw_view_log')); ?>"
                    >Xem log</a>
                <?php else : ?>
                    <button type="button" class="button" disabled>Xem log</button>
                <?php endif; ?>
            </p>
        </div>

        <form id="thean_lw_settings_form" method="post" action="options.php">
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
                            <strong>Vị trí cơ bản:</strong> <code>vertical</code> (<code>top</code>/<code>bottom</code>), <code>horizontal</code> (<code>left</code>/<code>right</code>), <code>display</code> (<code>icon_text</code>/<code>icon_only</code>/<code>text_only</code>), <code>custom_class</code>.<br>
                            <strong>Offset desktop</strong> (tùy chọn, giá trị CSS): <code>top</code>, <code>bottom</code>, <code>left</code>, <code>right</code>. Ví dụ: <code>"bottom": "80px"</code>.<br>
                            <strong>Offset mobile ≤720px</strong> (tùy chọn): <code>mobile_top</code>, <code>mobile_bottom</code>, <code>mobile_left</code>, <code>mobile_right</code>. Khi set <code>mobile_left</code>/<code>mobile_right</code>, centering transform tự động tắt.<br>
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
                            Field hỗ trợ: <code>id</code>, <code>label</code>, <code>type</code>, <code>amount</code>, <code>frequency</code>, <code>min_cart</code>, <code>max_value</code>, <code>wheel_label</code>, <code>active</code>.
                            <br>
                            <code>type</code> nhận các giá trị: <code>percent</code>, <code>fixed_cart</code>, <code>free_shipping</code>, <code>shipping_cap</code>, <code>buy_x_get_y</code>, <code>taxonomy_quantity_discount</code>.
                            <br>
                            <code>frequency</code> là tần suất tương đối. Giá trị càng cao thì phần thưởng càng xuất hiện nhiều.
                            <br>
                            Ví dụ nâng cao:
                            <code>{"type":"shipping_cap","amount":30000}</code>,
                            <code>{"type":"buy_x_get_y","buy_product_ids":[123],"buy_qty":1,"gift_product_id":456,"gift_qty":1,"max_value":50000}</code>,
                            <code>{"type":"taxonomy_quantity_discount","taxonomy":"product_cat","term_slugs":["tra"],"min_qty":3,"discount_mode":"percent","amount":10,"max_value":50000}</code>.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Lưu cấu hình'); ?>
        </form>
    </div>
    <?php
}

<?php
defined('ABSPATH') || exit;

/**
 * Cài đặt ERP (per-site) — Settings → Cài đặt ERP.
 * Hiện có mục AI:
 *  - Bật/tắt từng tính năng AI đã phát triển trong site (registry bên dưới)
 *  - Provider & model mặc định — feature không chỉ định riêng sẽ dùng mặc định này
 *
 * File chỉ load khi cần:
 *  - Admin: bootstrap/module-loader require để hiện trang settings
 *  - Frontend/AJAX: feature tự require_once trước khi kiểm tra bật/tắt
 *
 * Ưu tiên cấu hình: constant wp-config (VD ORDER_CREATOR_AI_MODEL) > trang settings > mặc định.
 * API key luôn nằm trong wp-config.php (CLAUDE_API_KEY / GEMINI_API_KEY / OPENAI_API_KEY),
 * không lưu qua trang settings.
 */

/**
 * Registry các tính năng AI của site. Feature mới thêm 1 entry ở đây
 * (hoặc qua filter 'theme_ai_feature_registry') là tự xuất hiện trên trang settings.
 */
function theme_ai_feature_registry(): array
{
    return apply_filters('theme_ai_feature_registry', [
        'order_creator_ai_extract_customer' => [
            'label'       => 'Tạo đơn hộ khách — AI bóc tách khách hàng',
            'description' => 'Nút "✨ Nhập khách hàng bằng AI" trong popup Khách hàng mới (trang /tao-don/): dán text/ảnh, AI tự điền thông tin khách.',
        ],
    ]);
}

function theme_erp_settings(): array
{
    $defaults = [
        'ai_provider' => 'auto',
        'ai_model'    => '',
        'ai_features' => [], // feature_key => '1'|'0'; chưa có key = bật
    ];

    $saved = get_option('theme_erp_settings', []);

    return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
}

function theme_ai_feature_enabled(string $feature): bool
{
    $features = (array) (theme_erp_settings()['ai_features'] ?? []);
    if (!array_key_exists($feature, $features)) {
        return true; // chưa lưu settings lần nào → mặc định bật
    }

    return !empty($features[$feature]);
}

function theme_ai_default_provider(): string
{
    $provider = sanitize_key((string) (theme_erp_settings()['ai_provider'] ?? 'auto'));

    return in_array($provider, ['claude', 'gemini', 'gemini_billing', 'openai'], true) ? $provider : 'auto';
}

function theme_ai_default_model(): string
{
    return trim((string) (theme_erp_settings()['ai_model'] ?? ''));
}

// ================================================================
// TRANG SETTINGS (admin only)
// ================================================================

if (is_admin()) {
    add_action('admin_menu', function (): void {
        add_options_page('Cài đặt ERP', 'Cài đặt ERP', 'manage_options', 'theme-erp-settings', 'theme_erp_settings_render_page');
    });

    add_action('admin_init', function (): void {
        register_setting('theme_erp_settings_group', 'theme_erp_settings', [
            'type'              => 'array',
            'sanitize_callback' => 'theme_erp_settings_sanitize',
        ]);
    });
}

function theme_erp_settings_sanitize($input): array
{
    $input = is_array($input) ? $input : [];
    $out   = [];

    $provider           = sanitize_key((string) ($input['ai_provider'] ?? 'auto'));
    $out['ai_provider'] = in_array($provider, ['claude', 'gemini', 'gemini_billing', 'openai', 'auto'], true) ? $provider : 'auto';
    $out['ai_model']    = sanitize_text_field((string) ($input['ai_model'] ?? ''));

    // Checkbox không tick sẽ không gửi lên → ghi rõ '0' để phân biệt "tắt" với "chưa cấu hình".
    $checked            = (array) ($input['ai_features'] ?? []);
    $out['ai_features'] = [];
    foreach (array_keys(theme_ai_feature_registry()) as $key) {
        $out['ai_features'][$key] = !empty($checked[$key]) ? '1' : '0';
    }

    return $out;
}

function theme_erp_settings_render_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = theme_erp_settings();

    $key_status = [];
    foreach (['CLAUDE_API_KEY' => 'Claude', 'GEMINI_API_KEY' => 'Gemini (free)', 'GEMINI_API_KEY_BILLING' => 'Gemini (billing)', 'OPENAI_API_KEY' => 'OpenAI'] as $const => $label) {
        $key_status[$label] = (defined($const) && constant($const)) || getenv($const);
    }
    ?>
    <div class="wrap">
        <h1>Cài đặt ERP</h1>
        <form method="post" action="options.php">
            <?php settings_fields('theme_erp_settings_group'); ?>

            <h2>AI</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">API key (wp-config.php)</th>
                    <td>
                        <?php foreach ($key_status as $label => $has_key) : ?>
                            <span style="margin-right:16px;"><?php echo $has_key ? '🟢' : '⚪'; ?> <?php echo esc_html($label); ?></span>
                        <?php endforeach; ?>
                        <p class="description">Key khai báo trong wp-config.php: <code>CLAUDE_API_KEY</code> / <code>GEMINI_API_KEY</code> / <code>OPENAI_API_KEY</code>. Trang này không lưu key.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="theme-erp-ai-provider">Nhà cung cấp mặc định</label></th>
                    <td>
                        <select id="theme-erp-ai-provider" name="theme_erp_settings[ai_provider]">
                            <?php foreach (['auto' => 'Tự động (theo API key có sẵn: Claude → Gemini → OpenAI)', 'claude' => 'Claude (Anthropic)', 'gemini' => 'Gemini free (GEMINI_API_KEY — tính năng đơn giản)', 'gemini_billing' => 'Gemini billing (GEMINI_API_KEY_BILLING — trả phí, mặc định gemini-2.5-flash)', 'openai' => 'OpenAI'] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['ai_provider'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="theme-erp-ai-model">Model mặc định</label></th>
                    <td>
                        <input type="text" class="regular-text" id="theme-erp-ai-model" name="theme_erp_settings[ai_model]" value="<?php echo esc_attr($settings['ai_model']); ?>" placeholder="VD: claude-haiku-4-5">
                        <p class="description">Bỏ trống = mặc định theo provider (Claude: <code>claude-opus-4-8</code>, Gemini: <code>gemini-3.1-flash-lite</code>, OpenAI: <code>gpt-4o-mini</code>).<br>Feature vẫn override được bằng constant riêng trong wp-config, VD <code>ORDER_CREATOR_AI_MODEL</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Tính năng AI</th>
                    <td>
                        <fieldset>
                            <?php foreach (theme_ai_feature_registry() as $key => $feature) : ?>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="checkbox" name="theme_erp_settings[ai_features][<?php echo esc_attr($key); ?>]" value="1" <?php checked(theme_ai_feature_enabled($key)); ?>>
                                    <strong><?php echo esc_html($feature['label']); ?></strong>
                                    <?php if (!empty($feature['description'])) : ?>
                                        <br><span class="description" style="margin-left:24px;"><?php echo esc_html($feature['description']); ?></span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">Tắt tính năng nào thì nút/CTA tương ứng ẩn khỏi giao diện và AJAX bị chặn.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

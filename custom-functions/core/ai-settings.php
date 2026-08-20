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

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'ai';
    $page_url   = admin_url('options-general.php?page=theme-erp-settings');
    $saved      = false;

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'theme_erp_settings_save_' . $active_tab)
    ) {
        if ($active_tab === 'xu-ly-don') {
            update_option(
                'hithean_export_upload_checklist',
                sanitize_textarea_field(wp_unslash($_POST['hithean_export_upload_checklist'] ?? ''))
            );
            $saved = true;
        }

        if ($active_tab === 'nut-ecom') {
            $categories = array_map('absint', (array) ($_POST['ecom_categories'] ?? []));
            $tags       = array_map('absint', (array) ($_POST['ecom_tags'] ?? []));

            $keywords_raw = sanitize_textarea_field(wp_unslash($_POST['ecom_keywords'] ?? ''));
            $keywords     = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $keywords_raw))));

            $product_ids_raw = sanitize_text_field(wp_unslash($_POST['ecom_product_ids'] ?? ''));
            $product_ids     = array_values(array_filter(array_map('absint', preg_split('/[\s,]+/', $product_ids_raw))));

            update_option('hithean_ecom_button_rules', [
                'enabled'     => isset($_POST['ecom_enabled']),
                'categories'  => array_values(array_filter($categories)),
                'tags'        => array_values(array_filter($tags)),
                'keywords'    => $keywords,
                'product_ids' => $product_ids,
            ]);
            $saved = true;
        }
    }

    $tabs = [
        'ai'        => 'AI',
        'xu-ly-don' => 'Xử lý đơn',
        'nut-ecom'  => 'Nút Shopee/TikTok',
    ];

    $settings = theme_erp_settings();

    $key_status = [];
    foreach (['CLAUDE_API_KEY' => 'Claude', 'GEMINI_API_KEY' => 'Gemini (free)', 'GEMINI_API_KEY_BILLING' => 'Gemini (billing)', 'OPENAI_API_KEY' => 'OpenAI'] as $const => $label) {
        $key_status[$label] = (defined($const) && constant($const)) || getenv($const);
    }
    ?>
    <div class="wrap">
        <h1>Cài đặt ERP</h1>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt.</p></div>
        <?php endif; ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <?php foreach ($tabs as $slug => $label): ?>
                <a href="<?php echo esc_url($page_url . '&tab=' . $slug); ?>"
                   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($active_tab === 'ai'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('theme_erp_settings_group'); ?>

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
        <?php endif; ?>

        <?php if ($active_tab === 'xu-ly-don'): ?>
            <form method="post">
                <?php wp_nonce_field('theme_erp_settings_save_xu-ly-don'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="hithean_export_upload_checklist">Checklist Upload Ảnh Xuất Kho</label>
                        </th>
                        <td>
                            <textarea id="hithean_export_upload_checklist"
                                      name="hithean_export_upload_checklist"
                                      rows="10"
                                      style="width:100%;max-width:600px;font-size:13px;line-height:1.7;"><?php
                                $default = implode("\n", [
                                    'Ảnh chụp đầy đủ sản phẩm',
                                    'Số lượng khớp với đơn',
                                    'Hàng không bị hư hỏng, rò rỉ, móp méo',
                                    'Ảnh chụp rõ mã đơn hoặc tên khách hàng',
                                    'Nhãn hàng The An Organics đã dán TEM NIÊM PHONG',
                                ]);
                                echo esc_textarea(get_option('hithean_export_upload_checklist', $default));
                            ?></textarea>
                            <p class="description">Mỗi dòng là một mục kiểm tra. Popup sẽ hiện khi nhấn <em>Upload ảnh xuất kho</em>. Để trống để không hiện popup.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Lưu cài đặt'); ?>
            </form>
        <?php endif; ?>

        <?php if ($active_tab === 'nut-ecom'): ?>
            <?php
            $rules = get_option('hithean_ecom_button_rules', []);
            $rules = wp_parse_args($rules, [
                'enabled'     => false,
                'categories'  => [],
                'tags'        => [],
                'keywords'    => [],
                'product_ids' => [],
            ]);

            $product_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            $product_tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
            ?>
            <form method="post">
                <?php wp_nonce_field('theme_erp_settings_save_nut-ecom'); ?>
                <p class="description" style="max-width:700px;">
                    Nút "Mua tại Shopee" chỉ hiện trên trang sản phẩm khớp <strong>bất kỳ</strong> điều kiện
                    nào bên dưới (category, tag, từ khóa trong tên sản phẩm, hoặc ID cụ thể) —
                    <strong>và</strong> sản phẩm đó phải có ít nhất 1 dòng ở metabox
                    "Kênh bán TMĐT (Shopee / TikTok)".
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Bật tính năng</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ecom_enabled" value="1" <?php checked($rules['enabled']); ?> />
                                Hiện nút "Mua tại Shopee" theo rule bên dưới
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Danh mục sản phẩm</th>
                        <td>
                            <div style="max-height:200px;overflow:auto;border:1px solid #dcdcde;padding:8px;max-width:500px;">
                                <?php if (!is_wp_error($product_cats)): foreach ($product_cats as $term): ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="ecom_categories[]" value="<?php echo esc_attr($term->term_id); ?>" <?php checked(in_array((int) $term->term_id, array_map('intval', $rules['categories']), true)); ?> />
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                <?php endforeach; endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tag sản phẩm</th>
                        <td>
                            <div style="max-height:200px;overflow:auto;border:1px solid #dcdcde;padding:8px;max-width:500px;">
                                <?php if (!is_wp_error($product_tags)): foreach ($product_tags as $term): ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="ecom_tags[]" value="<?php echo esc_attr($term->term_id); ?>" <?php checked(in_array((int) $term->term_id, array_map('intval', $rules['tags']), true)); ?> />
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                <?php endforeach; endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ecom_keywords">Từ khóa trong tên sản phẩm</label>
                        </th>
                        <td>
                            <textarea id="ecom_keywords" name="ecom_keywords" rows="6" style="width:100%;max-width:500px;"><?php echo esc_textarea(implode("\n", $rules['keywords'])); ?></textarea>
                            <p class="description">Mỗi dòng 1 từ khóa. So khớp không phân biệt hoa/thường với tên sản phẩm.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ecom_product_ids">ID sản phẩm cụ thể</label>
                        </th>
                        <td>
                            <textarea id="ecom_product_ids" name="ecom_product_ids" rows="3" style="width:100%;max-width:500px;" placeholder="123, 456, 789"><?php echo esc_textarea(implode(', ', $rules['product_ids'])); ?></textarea>
                            <p class="description">Phân tách bằng dấu phẩy hoặc xuống dòng.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Lưu cài đặt'); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

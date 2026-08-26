<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  THÔNG TIN DOANH NGHIỆP — SETTINGS
  Option nhỏ, không autoload theo mặc định của WP (add_option ... false ở dưới).
  File luôn nạp (không điều kiện) vì cả trang admin (Cài đặt ERP > Thông tin
  doanh nghiệp) lẫn shortcode [company_info] (chỉ nạp khi bài viết có shortcode)
  đều cần các hàm getter ở đây.
\*---------------------------------------*/

const HITHEAN_COMPANY_INFO_OPTION = 'hithean_company_info';

function hithean_company_info_fields(): array
{
    return [
        'company_name'     => 'Tên công ty',
        'hotline'          => 'Hotline chính',
        'hotline_2'        => 'Hotline phụ',
        'email_sales'      => 'Email kinh doanh',
        'email_accounting' => 'Email kế toán',
        'email_support'    => 'Email hỗ trợ chung',
        'address'          => 'Địa chỉ',
        'working_hours'    => 'Giờ làm việc',
        'tax_code'         => 'Mã số thuế',
        'zalo'             => 'Link Zalo',
        'fanpage'          => 'Link Fanpage',
    ];
}

function hithean_company_info_default_settings(): array
{
    return array_fill_keys(array_keys(hithean_company_info_fields()), '');
}

function hithean_company_info_get_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $saved    = get_option(HITHEAN_COMPANY_INFO_OPTION, []);
    $settings = is_array($saved)
        ? array_replace(hithean_company_info_default_settings(), $saved)
        : hithean_company_info_default_settings();

    return $settings;
}

function hithean_company_info_get(string $field): string
{
    return (string) (hithean_company_info_get_settings()[$field] ?? '');
}

function hithean_company_info_sanitize_settings($input): array
{
    $input = is_array($input) ? $input : [];
    $out   = [];

    foreach (array_keys(hithean_company_info_fields()) as $key) {
        $value = trim((string) ($input[$key] ?? ''));

        if (in_array($key, ['zalo', 'fanpage'], true)) {
            $out[$key] = $value !== '' ? esc_url_raw($value, ['http', 'https']) : '';
            continue;
        }

        $out[$key] = sanitize_text_field($value);
    }

    return $out;
}

if (is_admin()) {
    add_action('admin_init', function (): void {
        if (get_option(HITHEAN_COMPANY_INFO_OPTION, null) === null) {
            add_option(HITHEAN_COMPANY_INFO_OPTION, hithean_company_info_default_settings(), '', false);
        }

        register_setting('hithean_company_info_group', HITHEAN_COMPANY_INFO_OPTION, [
            'type'              => 'array',
            'sanitize_callback' => 'hithean_company_info_sanitize_settings',
        ]);
    });

    // Export ra file JSON — dùng để migrate settings sang site khác.
    add_action('admin_post_hithean_company_info_export', function (): void {
        if (!current_user_can('manage_options')) {
            wp_die('Không có quyền.');
        }
        check_admin_referer('hithean_company_info_export');

        $settings = hithean_company_info_get_settings();

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="company-info-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    });
}

function hithean_company_info_export_url(): string
{
    return wp_nonce_url(
        admin_url('admin-post.php?action=hithean_company_info_export'),
        'hithean_company_info_export'
    );
}

/**
 * Xử lý import JSON (upload file hoặc dán trực tiếp) — chấp nhận và ghi đè
 * toàn bộ field đã biết, field lạ/JSON hỏng bị bỏ qua (sanitize callback lọc).
 * Trả về mảng settings đã lưu nếu import thành công, null nếu không có gì để làm hoặc lỗi.
 */
function hithean_company_info_handle_import(): ?array
{
    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST' ||
        !isset($_POST['hithean_company_info_import_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hithean_company_info_import_nonce'])), 'hithean_company_info_import')
    ) {
        return null;
    }

    $json = '';

    if (!empty($_FILES['hithean_company_info_import_file']['tmp_name']) && is_uploaded_file($_FILES['hithean_company_info_import_file']['tmp_name'])) {
        $json = (string) file_get_contents($_FILES['hithean_company_info_import_file']['tmp_name']);
    } elseif (!empty($_POST['hithean_company_info_import_json'])) {
        $json = wp_unslash($_POST['hithean_company_info_import_json']);
    }

    $json = trim((string) $json);
    if ($json === '') {
        return null;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return false;
    }

    $sanitized = hithean_company_info_sanitize_settings($decoded);
    update_option(HITHEAN_COMPANY_INFO_OPTION, $sanitized);

    return $sanitized;
}

function hithean_company_info_render_settings_tab(): void
{
    $imported = hithean_company_info_handle_import();
    $settings = $imported !== null && $imported !== false ? $imported : hithean_company_info_get_settings();
    ?>
    <?php if ($imported === false): ?>
        <div class="notice notice-error"><p>❌ File/nội dung JSON không hợp lệ, chưa import được.</p></div>
    <?php elseif (is_array($imported)): ?>
        <div class="notice notice-success is-dismissible"><p>✅ Đã import thông tin doanh nghiệp.</p></div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields('hithean_company_info_group'); ?>
        <h2>Thông tin doanh nghiệp</h2>
        <p class="description">Khai báo các thông số dùng chung của doanh nghiệp. Gọi lại trong nội dung bài viết bằng shortcode, ví dụ <code>[company_info field="hotline"]</code>.</p>
        <table class="form-table" role="presentation">
            <tbody>
                <?php foreach (hithean_company_info_fields() as $key => $label): ?>
                    <tr>
                        <th scope="row"><label for="hithean-company-info-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                        <td>
                            <input type="text" class="regular-text"
                                   id="hithean-company-info-<?php echo esc_attr($key); ?>"
                                   name="<?php echo esc_attr(HITHEAN_COMPANY_INFO_OPTION); ?>[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($settings[$key]); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php submit_button('Lưu thông tin doanh nghiệp'); ?>
    </form>

    <hr>
    <h2>Import / Export</h2>
    <p class="description">Dùng để migrate thông tin doanh nghiệp sang site khác (cùng theme).</p>
    <p>
        <a href="<?php echo esc_url(hithean_company_info_export_url()); ?>" class="button">⬇️ Export JSON</a>
    </p>
    <form method="post" enctype="multipart/form-data" style="max-width:600px;">
        <?php wp_nonce_field('hithean_company_info_import', 'hithean_company_info_import_nonce'); ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="hithean-company-info-import-file">Chọn file JSON</label></th>
                    <td><input type="file" id="hithean-company-info-import-file" name="hithean_company_info_import_file" accept=".json,application/json"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hithean-company-info-import-json">Hoặc dán nội dung JSON</label></th>
                    <td><textarea id="hithean-company-info-import-json" name="hithean_company_info_import_json" rows="6" style="width:100%;font-family:monospace;font-size:12px;" placeholder='{"hotline":"0909xxxxxx","email_sales":"sales@..."}'></textarea></td>
                </tr>
            </tbody>
        </table>
        <?php submit_button('⬆️ Import JSON', 'secondary'); ?>
    </form>
    <?php
}

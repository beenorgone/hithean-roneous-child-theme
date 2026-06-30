<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'Admin Tools — HITHEAN',
        'Admin Tools',
        'manage_woocommerce',
        'hithean-admin-tools',
        'hithean_render_admin_tools_page',
        'dashicons-admin-tools',
        80
    );
}, 60);

function hithean_render_admin_tools_page()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Không có quyền truy cập.');
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'xu-ly-don';
    $page_url   = admin_url('admin.php?page=hithean-admin-tools');
    $saved      = false;

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'hithean_admin_tools_save_' . $active_tab)
    ) {
        if ($active_tab === 'xu-ly-don') {
            update_option(
                'hithean_export_upload_checklist',
                sanitize_textarea_field(wp_unslash($_POST['hithean_export_upload_checklist'] ?? ''))
            );
            $saved = true;
        }
    }

    $tabs = ['xu-ly-don' => 'Xử lý đơn'];
    ?>
    <div class="wrap">
        <h1>Admin Tools — HITHEAN</h1>
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

        <?php if ($active_tab === 'xu-ly-don'): ?>
            <form method="post">
                <?php wp_nonce_field('hithean_admin_tools_save_xu-ly-don'); ?>
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
    </div>
    <?php
}

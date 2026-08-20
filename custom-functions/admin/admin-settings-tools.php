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
        'xu-ly-don' => 'Xử lý đơn',
        'nut-ecom'  => 'Nút Shopee/TikTok',
    ];
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
                <?php wp_nonce_field('hithean_admin_tools_save_nut-ecom'); ?>
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

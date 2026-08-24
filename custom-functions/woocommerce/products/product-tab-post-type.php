<?php
if (!defined('ABSPATH')) exit;

// Add custom post type for product tabs
function product_tab_post_type()
{
    $labels = [
        'name'                     => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'singular_name'            => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'add_new'                  => esc_html__('Thêm Mới', 'hithean.com'),
        'add_new_item'             => esc_html__('Thêm Tab Sản Phẩm Mới', 'hithean.com'),
        'edit_item'                => esc_html__('Sửa Tab Sản Phẩm', 'hithean.com'),
        'new_item'                 => esc_html__('Tab Sản Phẩm Mới', 'hithean.com'),
        'view_item'                => esc_html__('Xem Tab Sản Phẩm', 'hithean.com'),
        'view_items'               => esc_html__('Xem Các Tab Sản Phẩm', 'hithean.com'),
        'search_items'             => esc_html__('Tìm Tab Sản Phẩm', 'hithean.com'),
        'not_found'                => esc_html__('Không Tìm Thấy Tab Sản Phẩm Nào.', 'hithean.com'),
        'not_found_in_trash'       => esc_html__('Không Tìm Thấy Tab Sản Phẩm Nào Trong Thùng Rác.', 'hithean.com'),
        'all_items'                => esc_html__('Tất Cả Các Tab Sản Phẩm', 'hithean.com'),
        'archives'                 => esc_html__('Lưu Trữ Tab Sản Phẩm', 'hithean.com'),
        'attributes'               => esc_html__('Thuộc Tính Tab Sản Phẩm', 'hithean.com'),
        'insert_into_item'         => esc_html__('Chèn Vào Tab Sản Phẩm', 'hithean.com'),
        'uploaded_to_this_item'    => esc_html__('Được Tải Lên Tab Sản Phẩm Này', 'hithean.com'),
        'featured_image'           => esc_html__('Ảnh Đại Diện', 'hithean.com'),
        'set_featured_image'       => esc_html__('Đặt Ảnh Đại Diện', 'hithean.com'),
        'remove_featured_image'    => esc_html__('Gỡ Ảnh Đại Diện', 'hithean.com'),
        'use_featured_image'       => esc_html__('Sử Dụng Làm Ảnh Đại Diện', 'hithean.com'),
        'menu_name'                => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'filter_items_list'        => esc_html__('Lọc Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'items_list_navigation'    => esc_html__('Điều Hướng Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'items_list'               => esc_html__('Danh Sách Tab Sản Phẩm', 'hithean.com'),
        'item_published'           => esc_html__('Tab Sản Phẩm Đã Được Đăng.', 'hithean.com'),
        'item_published_privately' => esc_html__('Tab Sản Phẩm Được Đăng Riêng Tư.', 'hithean.com'),
        'item_reverted_to_draft'   => esc_html__('Tab Sản Phẩm Được Chuyển Về Bản Nháp.', 'hithean.com'),
        'item_scheduled'           => esc_html__('Tab Sản Phẩm Đã Được Lên Lịch.', 'hithean.com'),
        'item_updated'             => esc_html__('Tab Sản Phẩm Đã Được Cập Nhật.', 'hithean.com'),
        'text_domain'              => esc_html__('hithean.com', 'hithean.com'),
    ];

    $args = [
        'label'               => esc_html__('Tab Sản Phẩm', 'hithean.com'),
        'labels'              => $labels,
        'public'              => true,
        'hierarchical'        => false,
        'exclude_from_search' => false,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_nav_menus'   => true,
        'show_in_admin_bar'   => true,
        'show_in_rest'        => true,
        'query_var'           => true,
        'can_export'          => true,
        'delete_with_user'    => false,
        'has_archive'         => true,
        'rest_base'           => '',
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-welcome-add-page',
        'capability_type'     => 'post',
        'supports'            => ['title', 'editor', 'custom-fields', 'revisions'],
        'taxonomies'          => ['product_cat', 'product_tag'],
        'rewrite'             => [
            'with_front' => false,
        ],
    ];

    register_post_type('product-tab', $args);
}
add_action('init', 'product_tab_post_type');

function hithean_product_tab_is_admin_editor_screen(): bool
{
    if (!is_admin()) {
        return false;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && (string) $screen->post_type === 'product-tab' && in_array((string) $screen->base, ['post', 'post-new'], true)) {
        return true;
    }

    global $pagenow;
    if (!in_array((string) $pagenow, ['post.php', 'post-new.php'], true)) {
        return false;
    }

    $post_type = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : '';
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    if ($post_id > 0 && get_post_type($post_id) === 'product-tab') {
        return true;
    }

    return $post_type === 'product-tab';
}

function hithean_product_tab_guide_sections(): array
{
    return [
        'usage' => [
            'label' => 'HDSD',
            'lead'  => 'Tab Sản Phẩm tạo nội dung bổ sung hiển thị trong khu vực tab của trang chi tiết sản phẩm WooCommerce.',
            'items' => [
                'Nhập tiêu đề ngắn, rõ nghĩa. Tiêu đề này là tên tab khách hàng nhìn thấy ngoài trang sản phẩm.',
                'Soạn nội dung chính trong editor. Có thể dùng đoạn văn, bảng, hình ảnh, shortcode và link nội bộ nếu cần.',
                'Bật "Tab Toàn Cục" khi nội dung phải hiện trên mọi sản phẩm, ví dụ chính sách bảo quản, hướng dẫn chung hoặc cam kết chất lượng.',
                'Không bật "Tab Toàn Cục" nếu tab chỉ dành cho một nhóm sản phẩm, một thương hiệu hoặc vài sản phẩm cụ thể.',
                'Chọn "Dùng Tab cho Sản Phẩm" để gắn tab cho từng sản phẩm riêng lẻ.',
                'Chọn "Dùng Tab cho Thương Hiệu" để tab tự hiện trên sản phẩm thuộc thương hiệu đó.',
                'Dùng taxonomy Product Categories hoặc Product Tags ở cột bên phải nếu muốn tab hiện theo danh mục hoặc tag sản phẩm.',
                'Đặt "Độ Ưu Tiên" để điều khiển vị trí tab. Số nhỏ hơn sẽ đứng trước số lớn hơn.',
                'Bật "Hiển thị trong Chi tiết SP" nếu muốn tab này xuất hiện trong thanh điều hướng (Product Content Navigator) ở đáy trang sản phẩm.',
                'Đặt "Nhãn navigator" ngắn gọn nếu tiêu đề tab quá dài để hiện trên nút điều hướng; để trống sẽ dùng nguyên tiêu đề tab.',
                'Chọn "Icon navigator" phù hợp nội dung; icon này dùng cho cả nút điều hướng và tiêu đề tab.',
                'Bấm Publish / Update, sau đó mở trang sản phẩm liên quan để kiểm tra thứ tự, nội dung và hiển thị mobile.',
            ],
        ],
        'logic' => [
            'label' => 'Logic',
            'items' => [
                'Frontend lấy các post type product-tab đang publish và đưa vào filter woocommerce_product_tabs.',
                'Tab toàn cục được lấy bằng meta product_tab_global_tab = 1 và hiển thị cho tất cả sản phẩm.',
                'Tab theo danh mục / tag được lấy khi product-tab có cùng product_cat hoặc product_tag với sản phẩm hiện tại.',
                'Tab theo sản phẩm cụ thể được lấy từ meta product_tab_products, so với ID sản phẩm hiện tại.',
                'Tab theo thương hiệu được lấy từ meta product_tab_thuong_hieu, so với taxonomy thuong-hieu của sản phẩm.',
                'Nội dung tab được render qua wpautop(do_shortcode(...)), nên shortcode sẽ chạy ở frontend.',
                'Thứ tự tab dùng product_tab_priority. Nếu nhiều tab có cùng priority, WooCommerce sẽ tự sắp theo thứ tự mảng nhận được.',
            ],
        ],
        'flow' => [
            'label' => 'Flow',
            'ordered' => true,
            'items' => [
                'Xác định phạm vi: toàn cục, danh mục / tag, thương hiệu hay sản phẩm cụ thể.',
                'Tạo tab mới hoặc mở tab cũ cần chỉnh.',
                'Nhập tiêu đề và nội dung.',
                'Cấu hình phạm vi áp dụng và priority.',
                'Publish / Update.',
                'Mở trang sản phẩm đại diện để kiểm tra tab có xuất hiện đúng nơi, đúng thứ tự và đúng nội dung.',
                'Nếu có cache trang / CDN / plugin cache, xóa cache sau khi cập nhật nội dung quan trọng.',
            ],
        ],
        'data' => [
            'label' => 'Dữ liệu',
            'items' => [
                'CPT: product-tab.',
                'Meta: product_tab_global_tab, product_tab_priority, product_tab_products, product_tab_thuong_hieu.',
                'Meta navigator: product_tab_show_in_navigator, product_tab_navigator_label, product_tab_navigator_icon.',
                'Taxonomy áp dụng: product_cat, product_tag, thuong-hieu.',
                'Frontend hook: woocommerce_product_tabs.',
                'File render frontend: custom-functions/woocommerce/products/product-page.php.',
                'File Product Content Navigator: custom-functions/woocommerce/products/product-navigation.php.',
            ],
            'warnings' => [
                'Không dùng tab toàn cục cho nội dung chỉ đúng với một dòng sản phẩm, vì nó sẽ hiện trên toàn bộ catalog.',
                'Không nhồi quá nhiều shortcode nặng trong tab, vì tab được build khi tải trang sản phẩm và có thể ảnh hưởng tốc độ.',
                'Kiểm tra kỹ nội dung pháp lý, claim sức khỏe, hướng dẫn sử dụng và thành phần trước khi publish.',
                'Nếu tab không hiện, kiểm tra trạng thái publish, phạm vi gắn sản phẩm / thương hiệu / taxonomy và cache frontend.',
                'Priority trùng nhau có thể làm thứ tự thực tế khó đoán. Dùng khoảng cách 5 hoặc 10 để dễ chèn thêm tab sau này.',
            ],
        ],
        'code' => [
            'label' => 'Code',
            'items' => [
                'custom-functions/woocommerce/products/product-tab-post-type.php: đăng ký CPT product-tab, metabox cấu hình và modal hướng dẫn.',
                'custom-functions/woocommerce/products/product-page.php: build danh sách tab ngoài trang sản phẩm WooCommerce.',
                'Hàm chính: product_tab_post_type, product_tab_meta_boxes, add_custom_product_tabs, display_product_tab_content.',
            ],
        ],
    ];
}

function hithean_product_tab_render_guide_items(array $items, bool $ordered = false): void
{
    $items = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $items), static fn($item) => $item !== ''));
    if (empty($items)) {
        return;
    }

    echo $ordered ? '<ol class="hithean-product-tab-guide__list">' : '<ul class="hithean-product-tab-guide__list">';
    foreach ($items as $item) {
        echo '<li>' . esc_html($item) . '</li>';
    }
    echo $ordered ? '</ol>' : '</ul>';
}

function hithean_product_tab_render_admin_guide(): void
{
    static $rendered = false;
    if ($rendered || !hithean_product_tab_is_admin_editor_screen()) {
        return;
    }

    $rendered = true;
    $sections = hithean_product_tab_guide_sections();

    echo '<div class="hithean-product-tab-guide-notice notice notice-info inline">';
    echo '<div class="hithean-product-tab-guide-notice__content">';
    echo '<strong>Setup Tab Sản Phẩm</strong>';
    echo '<span>Đọc nhanh cách chọn phạm vi, priority và kiểm tra tab ngoài trang sản phẩm.</span>';
    echo '</div>';
    echo '<button type="button" class="hithean-product-tab-guide-button" data-hithean-product-tab-guide-open aria-haspopup="dialog">';
    echo '<span class="hithean-product-tab-guide-button__icon">?</span>';
    echo '<span>Hướng dẫn setup</span>';
    echo '</button>';
    echo '</div>';

    echo '<div class="hithean-product-tab-guide-modal" data-hithean-product-tab-guide-modal hidden>';
    echo '<div class="hithean-product-tab-guide-modal__backdrop" data-hithean-product-tab-guide-close></div>';
    echo '<div class="hithean-product-tab-guide-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hithean-product-tab-guide-title">';
    echo '<button type="button" class="hithean-product-tab-guide-modal__close" data-hithean-product-tab-guide-close aria-label="Đóng hướng dẫn">×</button>';
    echo '<header class="hithean-product-tab-guide-modal__header">';
    echo '<p>Product Tab Guide</p>';
    echo '<h2 id="hithean-product-tab-guide-title">Hướng dẫn setup Tab Sản Phẩm</h2>';
    echo '<span>Dùng cho màn hình tạo / chỉnh Tab Sản Phẩm trong admin.</span>';
    echo '</header>';
    echo '<div class="hithean-product-tab-guide-tabs" role="tablist" aria-label="Hướng dẫn setup Tab Sản Phẩm">';

    $first = true;
    foreach ($sections as $key => $section) {
        echo '<button type="button" class="hithean-product-tab-guide-tab' . ($first ? ' is-active' : '') . '" data-hithean-product-tab-guide-tab="' . esc_attr((string) $key) . '">' . esc_html((string) ($section['label'] ?? $key)) . '</button>';
        $first = false;
    }
    echo '</div>';

    $first = true;
    foreach ($sections as $key => $section) {
        echo '<section class="hithean-product-tab-guide-section' . ($first ? ' is-active' : '') . '" data-hithean-product-tab-guide-section="' . esc_attr((string) $key) . '">';
        if (!empty($section['lead'])) {
            echo '<p class="hithean-product-tab-guide-lead">' . esc_html((string) $section['lead']) . '</p>';
        }
        hithean_product_tab_render_guide_items((array) ($section['items'] ?? []), !empty($section['ordered']));
        if (!empty($section['warnings'])) {
            echo '<h3>Cảnh báo thường gặp</h3>';
            hithean_product_tab_render_guide_items((array) $section['warnings']);
        }
        echo '</section>';
        $first = false;
    }

    echo '</div>';
    echo '</div>';
}
add_action('edit_form_after_title', 'hithean_product_tab_render_admin_guide');
add_action('all_admin_notices', 'hithean_product_tab_render_admin_guide');

function hithean_product_tab_print_admin_guide_styles(): void
{
    if (!hithean_product_tab_is_admin_editor_screen()) {
        return;
    }
    ?>
    <style id="hithean-product-tab-guide-styles">
        .hithean-product-tab-guide-notice {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 12px 0 16px;
            padding: 12px 14px;
            border-left-color: #0f766e;
            background: #fff;
        }

        .hithean-product-tab-guide-notice__content {
            display: flex;
            flex-direction: column;
            gap: 3px;
            color: #1d2327;
        }

        .hithean-product-tab-guide-notice__content span {
            color: #646970;
        }

        .hithean-product-tab-guide-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 32px;
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #334155;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }

        .hithean-product-tab-guide-button:hover,
        .hithean-product-tab-guide-button:focus {
            border-color: #0f766e;
            color: #0f766e;
            outline: none;
        }

        .hithean-product-tab-guide-button__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #f0fdfa;
            color: #0f766e;
        }

        .hithean-product-tab-guide-modal[hidden] {
            display: none !important;
        }

        .hithean-product-tab-guide-modal {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
        }

        .hithean-product-tab-guide-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.58);
        }

        .hithean-product-tab-guide-modal__dialog {
            position: relative;
            width: min(980px, 100%);
            max-height: min(760px, calc(100vh - 44px));
            overflow: auto;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.32);
        }

        .hithean-product-tab-guide-modal__close {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #334155;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }

        .hithean-product-tab-guide-modal__close:hover,
        .hithean-product-tab-guide-modal__close:focus {
            border-color: #ef4444;
            color: #b91c1c;
            outline: none;
        }

        .hithean-product-tab-guide-modal__header {
            padding: 22px 64px 14px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .hithean-product-tab-guide-modal__header p {
            margin: 0 0 5px;
            color: #0f766e;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .hithean-product-tab-guide-modal__header h2 {
            margin: 0;
            color: #0f172a;
            font-size: 22px;
            line-height: 1.25;
        }

        .hithean-product-tab-guide-modal__header span {
            display: block;
            margin-top: 6px;
            color: #64748b;
        }

        .hithean-product-tab-guide-tabs {
            display: flex;
            gap: 6px;
            padding: 10px 16px 0;
            overflow-x: auto;
            border-bottom: 1px solid #e2e8f0;
        }

        .hithean-product-tab-guide-tab {
            flex: 0 0 auto;
            padding: 9px 12px;
            border: 0;
            border-bottom: 3px solid transparent;
            background: transparent;
            color: #475569;
            font: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }

        .hithean-product-tab-guide-tab:hover,
        .hithean-product-tab-guide-tab:focus,
        .hithean-product-tab-guide-tab.is-active {
            border-bottom-color: #0f766e;
            color: #0f766e;
            outline: none;
        }

        .hithean-product-tab-guide-section {
            display: none;
            padding: 20px 24px 26px;
        }

        .hithean-product-tab-guide-section.is-active {
            display: block;
        }

        .hithean-product-tab-guide-section h3 {
            margin: 18px 0 8px;
            color: #0f172a;
            font-size: 15px;
        }

        .hithean-product-tab-guide-lead {
            margin: 0 0 12px;
            color: #334155;
            font-size: 15px;
            line-height: 1.6;
        }

        .hithean-product-tab-guide__list {
            margin: 0;
            padding-left: 22px;
            color: #334155;
            line-height: 1.6;
        }

        .hithean-product-tab-guide__list li {
            margin: 7px 0;
        }

        body.hithean-product-tab-guide-open {
            overflow: hidden;
        }

        @media (max-width: 782px) {
            .hithean-product-tab-guide-notice {
                align-items: flex-start;
                flex-direction: column;
            }

            .hithean-product-tab-guide-modal {
                padding: 10px;
            }

            .hithean-product-tab-guide-modal__dialog {
                max-height: calc(100vh - 20px);
            }

            .hithean-product-tab-guide-modal__header,
            .hithean-product-tab-guide-section {
                padding-left: 16px;
                padding-right: 16px;
            }
        }
    </style>
    <?php
}
add_action('admin_head', 'hithean_product_tab_print_admin_guide_styles');

function hithean_product_tab_print_admin_guide_script(): void
{
    if (!hithean_product_tab_is_admin_editor_screen()) {
        return;
    }
    ?>
    <script id="hithean-product-tab-guide-script">
        (function () {
            var modal = document.querySelector('[data-hithean-product-tab-guide-modal]');
            var activeTrigger = null;

            function openGuide(trigger) {
                if (!modal) return;
                activeTrigger = trigger || null;
                modal.hidden = false;
                document.body.classList.add('hithean-product-tab-guide-open');
                var closeButton = modal.querySelector('[data-hithean-product-tab-guide-close]');
                if (closeButton) window.setTimeout(function () { closeButton.focus(); }, 0);
            }

            function closeGuide() {
                if (!modal || modal.hidden) return;
                modal.hidden = true;
                document.body.classList.remove('hithean-product-tab-guide-open');
                if (activeTrigger && typeof activeTrigger.focus === 'function') activeTrigger.focus();
                activeTrigger = null;
            }

            function activateTab(tab) {
                if (!modal || !tab) return;
                var target = tab.getAttribute('data-hithean-product-tab-guide-tab') || '';
                modal.querySelectorAll('[data-hithean-product-tab-guide-tab]').forEach(function (item) {
                    item.classList.toggle('is-active', item === tab);
                });
                modal.querySelectorAll('[data-hithean-product-tab-guide-section]').forEach(function (section) {
                    section.classList.toggle('is-active', section.getAttribute('data-hithean-product-tab-guide-section') === target);
                });
            }

            document.addEventListener('click', function (event) {
                var openButton = event.target.closest('[data-hithean-product-tab-guide-open]');
                if (openButton) {
                    openGuide(openButton);
                    return;
                }

                if (event.target.closest('[data-hithean-product-tab-guide-close]')) {
                    closeGuide();
                    return;
                }

                var tab = event.target.closest('[data-hithean-product-tab-guide-tab]');
                if (tab) activateTab(tab);
            });

            document.addEventListener('keydown', function (event) {
                if (!modal || modal.hidden || event.key !== 'Escape') return;
                event.preventDefault();
                closeGuide();
            });
        })();
    </script>
    <?php
}
add_action('admin_footer', 'hithean_product_tab_print_admin_guide_script');


/*---------------------------------------*\
  ICON WHITELIST — dùng chung cho field "Icon navigator" (admin)
  và Product Content Navigator (frontend). Không nhận class/SVG tự do.
\*---------------------------------------*/

function hithean_product_tab_icon_whitelist(): array
{
    return [
        'default'     => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 14.5a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5Zm1-4.63V13a1 1 0 0 1-2 0v-2a1 1 0 0 1 1-1c1.1 0 2-.72 2-1.6 0-.88-.9-1.6-2-1.6s-2 .72-2 1.6a1 1 0 0 1-2 0c0-1.99 1.79-3.6 4-3.6s4 1.61 4 3.6c0 1.44-1 2.7-2 3.27Z',
        'description' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V8h4.5L14 3.5ZM8 12h8v1.5H8V12Zm0 4h8v1.5H8V16Zm0-8h4v1.5H8V8Z',
        'ingredients' => 'M9 2h6v2.1c1.98.9 3.4 2.72 3.4 4.9v9a2 2 0 0 1-2 2H7.6a2 2 0 0 1-2-2v-9c0-2.18 1.42-4 3.4-4.9V2Zm-1.5 8v8.5a.5.5 0 0 0 .5.5h8a.5.5 0 0 0 .5-.5V10h-9Z',
        'usage'       => 'M12 2a5 5 0 0 1 5 5v1h1a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h1V7a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3v1h6V7a3 3 0 0 0-3-3Zm0 9a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z',
        'faq'         => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm.9 14.6h-1.8v-1.8h1.8v1.8Zm1.86-6.8c-.4.55-.98.94-1.36 1.28-.4.34-.5.5-.5 1.02h-1.8c0-1.06.4-1.6.9-2.06.4-.36.86-.68 1.14-1.06.24-.32.36-.66.36-1.02 0-.83-.72-1.46-1.66-1.46-.9 0-1.6.6-1.7 1.5H8.34c.1-1.98 1.66-3.2 3.54-3.2 1.98 0 3.52 1.28 3.52 3.1 0 .78-.28 1.36-.66 1.9Z',
        'brand'       => 'M12 2 2 7v2l10 5 10-5V7L12 2Zm0 9L4 7l8-4 8 4-8 4Zm-8 2.4V17l8 4 8-4v-3.6l-8 4-8-4Z',
        'legal'       => 'M12 2 3 6v6c0 5 3.8 8.7 9 10 5.2-1.3 9-5 9-10V6l-9-4Zm-1 13.4-3.4-3.4 1.4-1.4L11 12.6l4-4 1.4 1.4-5.4 5.4Z',
        'label'       => 'M3 12 12 3h6a2 2 0 0 1 2 2v6l-9 9a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8Zm12.5-3.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
    ];
}

function hithean_product_tab_icon_options(): array
{
    return [
        'default'     => 'Mặc định',
        'description' => 'Mô tả',
        'ingredients' => 'Thành phần',
        'usage'       => 'Cách dùng',
        'faq'         => 'Câu hỏi',
        'brand'       => 'Thương hiệu',
        'legal'       => 'Hồ sơ / Pháp lý',
        'label'       => 'Nhãn phụ',
    ];
}

function hithean_product_tab_icon_svg(string $key): string
{
    $icons = hithean_product_tab_icon_whitelist();
    if (!isset($icons[$key])) {
        $key = 'default';
    }

    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" focusable="false"><path d="' . esc_attr($icons[$key]) . '"/></svg>';
}

// Define custom fields with Metabox for product_tab
add_filter('rwmb_meta_boxes', 'product_tab_meta_boxes');
function product_tab_meta_boxes($meta_boxes)
{
    $prefix = 'product_tab_';

    $meta_boxes[] = [
        'title'      => 'Cài đặt Tab',
        'id'         => 'product_tab_metabox',
        'post_types' => 'product-tab',
        'context'    => 'advanced',
        'priority'   => 'default',
        'autosave'   => true,
        'fields'     => [
            [
                'id'      => $prefix . 'global_tab',
                'name'    => 'Tab Toàn Cục',
                'type'    => 'checkbox',
            ],
            [
                'id'   => $prefix . 'priority',
                'name' => 'Độ Ưu Tiên',
                'type' => 'number',
                'std'  => 60,
            ],
            [
                'id'   => $prefix . 'products',
                'name' => 'Dùng Tab cho Sản Phẩm',
                'type' => 'post',
                'post_type' => 'product',
                'field_type' => 'select_advanced',
                'multiple' => true,
            ],
            [
                'id'   => $prefix . 'thuong_hieu',
                'name' => 'Dùng Tab cho Thương Hiệu',
                'type' => 'taxonomy_advanced',
                'taxonomy' => 'thuong-hieu', // Product Brands
                'multiple' => true, // Allow multiple
            ],
            [
                'id'   => $prefix . 'show_in_navigator',
                'name' => 'Hiển thị trong Chi tiết SP',
                'desc' => 'Bật để tab này xuất hiện trong thanh điều hướng nội dung (Product Content Navigator) trên trang sản phẩm.',
                'type' => 'checkbox',
            ],
            [
                'id'   => $prefix . 'navigator_label',
                'name' => 'Nhãn navigator',
                'desc' => 'Nhãn ngắn hiển thị trên nút điều hướng, ví dụ "Cách sử dụng". Để trống sẽ dùng tiêu đề tab.',
                'type' => 'text',
            ],
            [
                'id'      => $prefix . 'navigator_icon',
                'name'    => 'Icon navigator',
                'desc'    => 'Chọn icon hiển thị cho nút điều hướng và tiêu đề tab.',
                'type'    => 'select',
                'options' => hithean_product_tab_icon_options(),
                'std'     => 'default',
            ],
        ],
    ];

    return $meta_boxes;
}

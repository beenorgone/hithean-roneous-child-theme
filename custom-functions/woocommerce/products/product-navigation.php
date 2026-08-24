<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  PRODUCT CONTENT NAVIGATOR
  Thanh điều hướng nội dung trang sản phẩm — không reload, không ẩn tab.
  Nạp có điều kiện qua tpc_loader (condition: tpc_cond_is_product) trong
  custom-functions/core/module-loader.php.
\*---------------------------------------*/

/**
 * Bắt mảng tabs cuối cùng NGAY TRONG lượt apply_filters('woocommerce_product_tabs', [])
 * mà WooCommerce đã tự chạy khi render tabs.php — không apply_filters lại lần hai
 * (tránh nhân đôi các WP_Query trong add_custom_product_tabs()).
 * Priority 99 chạy sau add_custom_product_tabs (10) và unset_tabs (98).
 */
add_filter('woocommerce_product_tabs', 'hithean_pcn_capture_tabs', 99);

function hithean_pcn_capture_tabs($tabs)
{
    hithean_pcn_tabs_snapshot(is_array($tabs) ? $tabs : []);
    return $tabs;
}

function hithean_pcn_tabs_snapshot($set = null)
{
    static $snapshot = null;

    if ($set !== null) {
        $snapshot = $set;
    }

    return $snapshot;
}

/**
 * Map mặc định cho các tab chuẩn không xuất phát từ CPT product-tab
 * (tồn tại panel là đủ điều kiện vào navigator, không cần setting riêng).
 */
function hithean_pcn_default_tab_map(): array
{
    return [
        'description'                          => ['icon' => 'description'],
        sanitize_title('Thành phần')            => ['icon' => 'ingredients'],
        sanitize_title('Câu hỏi thường gặp')    => ['icon' => 'faq'],
        sanitize_title('Hướng dẫn sử dụng')     => ['icon' => 'usage'],
        sanitize_title('Nhãn phụ')              => ['icon' => 'label'],
        sanitize_title('Hồ sơ sản phẩm')        => ['icon' => 'legal'],
        'thuong-hieu'                           => ['icon' => 'brand'],
    ];
}

/**
 * Chuẩn hoá mảng tabs đã bắt được thành danh sách item cho navigator.
 * Không truy vấn thêm — chỉ đọc lại các key đã có sẵn trong $tabs.
 */
function hithean_pcn_build_items(): array
{
    $tabs        = hithean_pcn_tabs_snapshot();
    $default_map = hithean_pcn_default_tab_map();
    $icons       = hithean_product_tab_icon_whitelist();
    $items       = [];
    $seen_target = [];

    if (is_array($tabs) && !empty($tabs)) {
        foreach ($tabs as $key => $tab) {
            $key = (string) $key;
            if ($key === '' || !is_array($tab)) {
                continue;
            }

            $target = 'tab-' . $key;
            if (isset($seen_target[$target])) {
                continue;
            }

            $label    = '';
            $icon_key = 'default';

            if (array_key_exists('pcn_nav_show', $tab)) {
                // Tab xuất phát từ CPT product-tab — chỉ vào navigator khi được bật.
                if (empty($tab['pcn_nav_show'])) {
                    continue;
                }
                $label    = trim((string) ($tab['pcn_nav_label'] ?? ''));
                $icon_key = (string) ($tab['pcn_nav_icon'] ?? 'default');
            } elseif (isset($default_map[$key])) {
                $icon_key = (string) $default_map[$key]['icon'];
            } else {
                continue;
            }

            if ($label === '') {
                $label = trim((string) ($tab['title'] ?? ''));
            }
            if ($label === '') {
                continue;
            }

            if (!isset($icons[$icon_key])) {
                $icon_key = 'default';
            }

            $seen_target[$target] = true;
            $items[] = [
                'type'     => 'internal',
                'key'      => $key,
                'target'   => $target,
                'label'    => $label,
                'icon'     => $icon_key,
                'priority' => isset($tab['priority']) ? (int) $tab['priority'] : 50,
            ];
        }
    }

    // "Menu bổ sung" — cấu hình ở Cài đặt ERP > WooCommerce, áp dụng theo scope
    // (toàn cục / category / tag / thương hiệu). Luôn xếp sau tab theo thứ tự
    // khai báo (priority tăng dần từ 1000) trừ khi trùng target với tab đã có.
    $product_id = (int) get_the_ID();
    if ($product_id > 0 && function_exists('hithean_pcn_get_product_menus')) {
        $menu_priority = 1000;
        $seen_href     = [];

        foreach (hithean_pcn_get_product_menus($product_id) as $menu) {
            $label = trim((string) ($menu['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            if (($menu['destination_type'] ?? '') === 'internal') {
                $target = ltrim((string) ($menu['destination'] ?? ''), '#');
                if ($target === '' || isset($seen_target[$target])) {
                    continue;
                }
                $seen_target[$target] = true;
                $items[] = [
                    'type'     => 'internal',
                    'key'      => 'menu-' . $target,
                    'target'   => $target,
                    'label'    => $label,
                    'icon'     => 'default',
                    'priority' => $menu_priority++,
                ];
            } else {
                $href = (string) ($menu['destination'] ?? '');
                if ($href === '' || isset($seen_href[$href])) {
                    continue;
                }
                $seen_href[$href] = true;
                $items[] = [
                    'type'     => 'external',
                    'key'      => 'menu-ext-' . md5($href),
                    'href'     => $href,
                    'label'    => $label,
                    'icon'     => 'external',
                    'priority' => $menu_priority++,
                ];
            }
        }
    }

    usort($items, function ($a, $b) {
        if ($a['priority'] === $b['priority']) {
            return strnatcasecmp($a['label'], $b['label']);
        }
        return $a['priority'] <=> $b['priority'];
    });

    return $items;
}

/**
 * 'external' không nằm trong whitelist icon của Tab Sản Phẩm (chỉ dùng cho
 * "Menu bổ sung" loại URL, không phải lựa chọn admin nào có thể set) nên xử
 * lý riêng ở đây thay vì đưa vào hithean_product_tab_icon_whitelist().
 */
function hithean_pcn_item_icon_svg(string $icon_key): string
{
    if ($icon_key === 'external') {
        return hithean_pcn_external_menu_icon_svg();
    }

    return hithean_product_tab_icon_svg($icon_key);
}

function hithean_pcn_get_items(): array
{
    static $items = null;

    if ($items === null) {
        $items = hithean_pcn_build_items();
    }

    return $items;
}

/*---------------------------------------*\
  ENQUEUE ASSETS — chỉ trên trang sản phẩm.
  Lưu ý: wp_enqueue_scripts chạy ở <head>, TRƯỚC khi WooCommerce build và
  apply_filters('woocommerce_product_tabs', []) trong nội dung trang (tabs.php),
  nên hithean_pcn_get_items() chưa có dữ liệu tại thời điểm này — không thể
  gate enqueue theo số lượng item mà không apply_filters thêm một lần (vi phạm
  yêu cầu "không truy vấn tab lần hai"). Vì vậy asset được nạp bất cứ khi nào
  là trang sản phẩm; hithean_pcn_render() ở wp_footer mới quyết định có in
  markup hay không (< 2 item thì không render gì, JS/CSS coi như không hoạt động).
\*---------------------------------------*/

add_action('wp_enqueue_scripts', 'hithean_pcn_enqueue_assets', 20);

function hithean_pcn_enqueue_assets()
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }

    $css_rel = '/custom-functions/woocommerce/products/assets/product-navigation.css';
    $js_rel  = '/custom-functions/woocommerce/products/assets/product-navigation.js';
    $css_path = get_stylesheet_directory() . $css_rel;
    $js_path  = get_stylesheet_directory() . $js_rel;

    wp_enqueue_style(
        'hithean-product-navigation',
        get_stylesheet_directory_uri() . $css_rel,
        ['hithean-custom-style'],
        is_file($css_path) ? (string) filemtime($css_path) : thean_theme_code_version()
    );

    wp_enqueue_script(
        'hithean-product-navigation',
        get_stylesheet_directory_uri() . $js_rel,
        [],
        is_file($js_path) ? (string) filemtime($js_path) : thean_theme_code_version(),
        true
    );
    wp_script_add_data('hithean-product-navigation', 'strategy', 'defer');
}

/*---------------------------------------*\
  RENDER — root duy nhất ở wp_footer.
\*---------------------------------------*/

add_action('wp_footer', 'hithean_pcn_render', 25);

function hithean_pcn_render()
{
    if (!function_exists('is_product') || !is_product()) {
        return;
    }

    $items = hithean_pcn_get_items();
    if (count($items) < 2) {
        return;
    }

    global $product;
    if (!$product instanceof WC_Product) {
        $product = wc_get_product(get_the_ID());
    }
    if (!$product instanceof WC_Product) {
        return;
    }

    $show_cta     = $product->is_purchasable();
    $cta_label    = $show_cta ? $product->single_add_to_cart_text() : '';
    $product_type = $product->get_type();
    $desktop_mode = hithean_pcn_get_settings()['desktop_mode'];
    ?>
    <nav class="pcn" id="pcn-root" data-desktop-mode="<?php echo esc_attr($desktop_mode); ?>" aria-label="<?php esc_attr_e('Điều hướng nội dung sản phẩm', 'hithean.com'); ?>">
        <div class="pcn__bar">
            <ul class="pcn__list" role="list">
                <?php foreach ($items as $item) : ?>
                    <li class="pcn__item">
                        <?php if ($item['type'] === 'external') : ?>
                            <a class="pcn__link" href="<?php echo esc_url($item['href']); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr($item['label']); ?>">
                                <span class="pcn__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                <span class="pcn__label"><?php echo esc_html($item['label']); ?></span>
                            </a>
                        <?php else : ?>
                            <button type="button" class="pcn__link" data-target="<?php echo esc_attr($item['target']); ?>" title="<?php echo esc_attr($item['label']); ?>" aria-label="<?php echo esc_attr($item['label']); ?>">
                                <span class="pcn__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                <span class="pcn__label"><?php echo esc_html($item['label']); ?></span>
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($show_cta) : ?>
                <button type="button" class="pcn__cta" data-pcn-cta data-product-type="<?php echo esc_attr($product_type); ?>">
                    <?php echo esc_html($cta_label); ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="pcn__floating">
            <div class="pcn__floating-anchor">
                <button type="button" class="pcn__floating-toggle" data-pcn-desktop-popover-toggle aria-haspopup="true" aria-controls="pcn-popover-desktop" aria-expanded="false">
                    <span class="pcn__floating-toggle-icon" aria-hidden="true"><?php echo hithean_product_tab_icon_svg('description'); ?></span>
                    <span><?php esc_html_e('Chi tiết SP', 'hithean.com'); ?></span>
                </button>
                <div class="pcn-popover" id="pcn-popover-desktop" data-pcn-popover hidden>
                    <div class="pcn-popover__header">
                        <h2 class="pcn-popover__title"><?php esc_html_e('Chi tiết sản phẩm', 'hithean.com'); ?></h2>
                        <button type="button" class="pcn-popover__close" data-pcn-popover-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>
                    </div>
                    <ul class="pcn-popover__list" role="list">
                        <?php foreach ($items as $item) : ?>
                            <li>
                                <?php if ($item['type'] === 'external') : ?>
                                    <a class="pcn-popover__item" href="<?php echo esc_url($item['href']); ?>" target="_blank" rel="noopener">
                                        <span class="pcn-popover__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                        <span class="pcn-popover__label"><?php echo esc_html($item['label']); ?></span>
                                    </a>
                                <?php else : ?>
                                    <button type="button" class="pcn-popover__item" data-target="<?php echo esc_attr($item['target']); ?>">
                                        <span class="pcn-popover__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                        <span class="pcn-popover__label"><?php echo esc_html($item['label']); ?></span>
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($show_cta) : ?>
                        <button type="button" class="pcn-popover__cta" data-pcn-cta data-product-type="<?php echo esc_attr($product_type); ?>">
                            <?php echo esc_html($cta_label); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="pcn-mobile" id="pcn-mobile-root">
        <div class="pcn-popover-backdrop" data-pcn-popover-close hidden></div>
        <div class="pcn-mobile__cluster" data-pcn-lw-slot>
            <button type="button" class="pcn-mobile__detail" data-pcn-popover-toggle aria-haspopup="true" aria-controls="pcn-popover" aria-expanded="false">
                <span class="pcn-mobile__detail-icon" aria-hidden="true"><?php echo hithean_product_tab_icon_svg('description'); ?></span>
                <span class="pcn-mobile__detail-label"><?php esc_html_e('Chi tiết SP', 'hithean.com'); ?></span>
            </button>
            <div class="pcn-popover" id="pcn-popover" data-pcn-popover hidden>
                <div class="pcn-popover__header">
                    <h2 class="pcn-popover__title"><?php esc_html_e('Chi tiết sản phẩm', 'hithean.com'); ?></h2>
                    <button type="button" class="pcn-popover__close" data-pcn-popover-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>
                </div>
                <ul class="pcn-popover__list" role="list">
                    <?php foreach ($items as $item) : ?>
                        <li>
                            <?php if ($item['type'] === 'external') : ?>
                                <a class="pcn-popover__item" href="<?php echo esc_url($item['href']); ?>" target="_blank" rel="noopener">
                                    <span class="pcn-popover__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                    <span class="pcn-popover__label"><?php echo esc_html($item['label']); ?></span>
                                </a>
                            <?php else : ?>
                                <button type="button" class="pcn-popover__item" data-target="<?php echo esc_attr($item['target']); ?>">
                                    <span class="pcn-popover__icon" aria-hidden="true"><?php echo hithean_pcn_item_icon_svg($item['icon']); ?></span>
                                    <span class="pcn-popover__label"><?php echo esc_html($item['label']); ?></span>
                                </button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

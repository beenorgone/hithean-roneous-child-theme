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
    $tabs = hithean_pcn_tabs_snapshot();
    if (!is_array($tabs) || empty($tabs)) {
        return [];
    }

    $default_map = hithean_pcn_default_tab_map();
    $icons       = hithean_product_tab_icon_whitelist();
    $items       = [];
    $seen        = [];

    foreach ($tabs as $key => $tab) {
        $key = (string) $key;
        if ($key === '' || !is_array($tab)) {
            continue;
        }

        $target = 'tab-' . $key;
        if (isset($seen[$target])) {
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

        $seen[$target] = true;
        $items[] = [
            'key'      => $key,
            'target'   => $target,
            'label'    => $label,
            'icon'     => $icon_key,
            'priority' => isset($tab['priority']) ? (int) $tab['priority'] : 50,
        ];
    }

    usort($items, function ($a, $b) {
        if ($a['priority'] === $b['priority']) {
            return strnatcasecmp($a['label'], $b['label']);
        }
        return $a['priority'] <=> $b['priority'];
    });

    return $items;
}

function hithean_pcn_get_items(): array
{
    static $items = null;

    if ($items === null) {
        $items = hithean_pcn_build_items();
    }

    return $items;
}

/**
 * Dùng bởi product-page.php để in icon trước tiêu đề panel (nếu tab đó có mặt
 * trong navigator). Trả về chuỗi rỗng nếu module chưa nạp hoặc tab không có icon.
 */
function hithean_pcn_heading_icon_html(string $key): string
{
    foreach (hithean_pcn_get_items() as $item) {
        if ($item['key'] === $key) {
            return '<span class="pcn-heading-icon" aria-hidden="true">' . hithean_product_tab_icon_svg($item['icon']) . '</span>';
        }
    }

    return '';
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

    $show_cta = $product->is_purchasable();
    ?>
    <nav class="pcn" id="pcn-root" aria-label="<?php esc_attr_e('Điều hướng nội dung sản phẩm', 'hithean.com'); ?>">
        <div class="pcn__bar">
            <ul class="pcn__list" role="list">
                <?php foreach ($items as $item) : ?>
                    <li class="pcn__item">
                        <button type="button" class="pcn__link" data-target="<?php echo esc_attr($item['target']); ?>" title="<?php echo esc_attr($item['label']); ?>" aria-label="<?php echo esc_attr($item['label']); ?>">
                            <span class="pcn__icon" aria-hidden="true"><?php echo hithean_product_tab_icon_svg($item['icon']); ?></span>
                            <span class="pcn__label"><?php echo esc_html($item['label']); ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($show_cta) : ?>
                <button type="button" class="pcn__cta" data-pcn-cta data-product-type="<?php echo esc_attr($product->get_type()); ?>">
                    <?php echo esc_html($product->single_add_to_cart_text()); ?>
                </button>
            <?php endif; ?>
        </div>
    </nav>

    <div class="pcn-mobile" id="pcn-mobile-root">
        <div class="pcn-mobile__cluster" data-pcn-lw-slot>
            <button type="button" class="pcn-mobile__detail" data-pcn-drawer-open aria-haspopup="dialog" aria-controls="pcn-drawer" aria-expanded="false">
                <span class="pcn-mobile__detail-icon" aria-hidden="true"><?php echo hithean_product_tab_icon_svg('description'); ?></span>
                <span class="pcn-mobile__detail-label"><?php esc_html_e('Chi tiết SP', 'hithean.com'); ?></span>
            </button>
        </div>
    </div>

    <div class="pcn-drawer" id="pcn-drawer" role="dialog" aria-modal="true" aria-labelledby="pcn-drawer-title" hidden>
        <div class="pcn-drawer__backdrop" data-pcn-drawer-close></div>
        <div class="pcn-drawer__panel">
            <div class="pcn-drawer__header">
                <h2 id="pcn-drawer-title" class="pcn-drawer__title"><?php esc_html_e('Chi tiết sản phẩm', 'hithean.com'); ?></h2>
                <button type="button" class="pcn-drawer__close" data-pcn-drawer-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>
            </div>
            <ul class="pcn-drawer__list" role="list">
                <?php foreach ($items as $item) : ?>
                    <li>
                        <button type="button" class="pcn-drawer__item" data-target="<?php echo esc_attr($item['target']); ?>">
                            <span class="pcn-drawer__icon" aria-hidden="true"><?php echo hithean_product_tab_icon_svg($item['icon']); ?></span>
                            <span class="pcn-drawer__label"><?php echo esc_html($item['label']); ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

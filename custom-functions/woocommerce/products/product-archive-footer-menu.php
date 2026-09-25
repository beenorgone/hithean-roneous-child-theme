<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  PRODUCT ARCHIVE STICKY FOOTER MENU
  Thanh menu dính đáy cho trang category / tag / taxonomy sản phẩm tùy chỉnh.
  Mặc định: Sản phẩm · Chat với The An · So sánh sản phẩm (modal dùng
  shortcode [product_compare] + danh sách gọn các sản phẩm đang hiển thị).
  Nạp có điều kiện qua tpc_loader (condition: tpc_cond_is_product_taxonomy)
  trong custom-functions/core/module-loader.php.
\*---------------------------------------*/

const HITHEAN_APFM_CHAT_URL = 'https://m.me/61558663706094';

function hithean_apfm_icon_svg(string $key): string
{
    $paths = [
        'products' => 'M4 4h7v7H4V4Zm2 2v3h3V6H6Zm7-2h7v7h-7V4Zm2 2v3h3V6h-3ZM4 13h7v7H4v-7Zm2 2v3h3v-3H6Zm7-2h7v7h-7v-7Zm2 2v3h3v-3h-3Z',
        'chat'     => 'M12 2C6.48 2 2 6.13 2 11.22c0 2.9 1.45 5.48 3.72 7.17V22l3.4-1.87c.91.25 1.88.39 2.88.39 5.52 0 10-4.13 10-9.3S17.52 2 12 2Zm1 12.5-2.55-2.72-4.97 2.72 5.47-5.8 2.6 2.72 4.9-2.72-5.45 5.8Z',
        'compare'  => 'M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v2h2V1h-2v2Zm0 15H5l5-6v6Zm9-15h-5v2h5v13l-5-6v9h5a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Z',
    ];

    if (!isset($paths[$key])) {
        return '';
    }

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" focusable="false" aria-hidden="true"><path d="' . esc_attr($paths[$key]) . '"/></svg>';
}

/**
 * Item của thanh menu. type: 'scroll' (cuộn tới grid), 'link' (URL), 'compare' (mở modal).
 * Có thể thêm/bớt qua filter 'hithean_apfm_items'.
 */
function hithean_apfm_get_items(): array
{
    $items = [
        [
            'key'   => 'products',
            'type'  => 'scroll',
            'label' => 'Sản phẩm',
            'icon'  => 'products',
        ],
        [
            'key'   => 'chat',
            'type'  => 'link',
            'label' => 'Chat với The An',
            'icon'  => 'chat',
            'href'  => HITHEAN_APFM_CHAT_URL,
        ],
    ];

    if (shortcode_exists('product_compare')) {
        $items[] = [
            'key'   => 'compare',
            'type'  => 'compare',
            'label' => 'So sánh sản phẩm',
            'icon'  => 'compare',
        ];
    }

    return (array) apply_filters('hithean_apfm_items', $items);
}

/**
 * Danh sách gọn các sản phẩm đang hiển thị trên trang (main query) — không truy vấn thêm.
 */
function hithean_apfm_get_page_products(): array
{
    global $wp_query;

    $products = [];
    foreach ((array) ($wp_query->posts ?? []) as $post) {
        $product = wc_get_product($post);
        if (!$product instanceof WC_Product || !$product->is_visible()) {
            continue;
        }

        $products[] = [
            'id'    => $product->get_id(),
            'title' => $product->get_name(),
            'thumb' => (string) get_the_post_thumbnail_url($product->get_id(), 'woocommerce_gallery_thumbnail'),
            'price' => $product->get_price_html(),
        ];
    }

    return $products;
}

add_filter('body_class', function (array $classes): array {
    $classes[] = 'has-apfm';
    return $classes;
});

add_action('wp_enqueue_scripts', 'hithean_apfm_enqueue_assets', 20);

function hithean_apfm_enqueue_assets()
{
    $base = '/custom-functions/woocommerce/products/assets/product-archive-footer-menu';
    $dir  = get_stylesheet_directory();
    $uri  = get_stylesheet_directory_uri();

    wp_enqueue_style(
        'hithean-apfm',
        $uri . $base . '.css',
        [],
        is_file($dir . $base . '.css') ? (string) filemtime($dir . $base . '.css') : thean_theme_code_version()
    );

    wp_enqueue_script(
        'hithean-apfm',
        $uri . $base . '.js',
        [],
        is_file($dir . $base . '.js') ? (string) filemtime($dir . $base . '.js') : thean_theme_code_version(),
        true
    );
    wp_script_add_data('hithean-apfm', 'strategy', 'defer');
}

add_action('wp_footer', 'hithean_apfm_render', 25);

function hithean_apfm_render()
{
    $items = hithean_apfm_get_items();
    if (empty($items)) {
        return;
    }

    $has_compare = false;
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'compare') {
            $has_compare = true;
        }
    }
    ?>
    <nav class="apfm" id="apfm-root" aria-label="<?php esc_attr_e('Menu danh mục sản phẩm', 'hithean.com'); ?>">
        <ul class="apfm__list" role="list">
            <?php foreach ($items as $item) :
                $label = (string) ($item['label'] ?? '');
                $icon  = hithean_apfm_icon_svg((string) ($item['icon'] ?? ''));
                $type  = (string) ($item['type'] ?? '');
                if ($label === '') {
                    continue;
                }
                ?>
                <li class="apfm__item apfm__item--<?php echo esc_attr($item['key'] ?? $type); ?>">
                    <?php if ($type === 'link') : ?>
                        <a class="apfm__link" href="<?php echo esc_url($item['href'] ?? ''); ?>" target="_blank" rel="noopener">
                            <span class="apfm__icon"><?php echo $icon; ?></span>
                            <span class="apfm__label"><?php echo esc_html($label); ?></span>
                        </a>
                    <?php else : ?>
                        <button type="button" class="apfm__link" data-apfm-action="<?php echo esc_attr($type); ?>"<?php echo $type === 'compare' ? ' aria-haspopup="dialog" aria-controls="apfm-compare"' : ''; ?>>
                            <span class="apfm__icon"><?php echo $icon; ?></span>
                            <span class="apfm__label"><?php echo esc_html($label); ?></span>
                            <?php if ($type === 'compare') : ?>
                                <span class="apfm__badge" data-apfm-compare-count hidden>0</span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <?php
    if ($has_compare) {
        hithean_apfm_render_compare_modal();
    }
}

function hithean_apfm_render_compare_modal()
{
    $products = hithean_apfm_get_page_products();
    ?>
    <div class="apfm-modal" id="apfm-compare" role="dialog" aria-modal="true" aria-labelledby="apfm-compare-title" hidden>
        <div class="apfm-modal__backdrop" data-apfm-close></div>
        <div class="apfm-modal__panel">
            <div class="apfm-modal__header">
                <h2 class="apfm-modal__title" id="apfm-compare-title"><?php esc_html_e('So sánh sản phẩm', 'hithean.com'); ?></h2>
                <button type="button" class="apfm-modal__close" data-apfm-close aria-label="<?php esc_attr_e('Đóng', 'hithean.com'); ?>">&times;</button>
            </div>
            <div class="apfm-modal__body">
                <?php if (!empty($products)) : ?>
                    <p class="apfm-modal__hint"><?php esc_html_e('Bấm vào sản phẩm để thêm vào bảng so sánh:', 'hithean.com'); ?></p>
                    <ul class="apfm-picks" role="list">
                        <?php foreach ($products as $item) : ?>
                            <li>
                                <button type="button" class="apfm-pick" data-apfm-pick="<?php echo esc_attr($item['id']); ?>" data-label="<?php echo esc_attr($item['title']); ?>" aria-pressed="false">
                                    <?php if ($item['thumb'] !== '') : ?>
                                        <img class="apfm-pick__thumb" src="<?php echo esc_url($item['thumb']); ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
                                    <?php else : ?>
                                        <span class="apfm-pick__thumb"></span>
                                    <?php endif; ?>
                                    <span class="apfm-pick__text">
                                        <span class="apfm-pick__title"><?php echo esc_html($item['title']); ?></span>
                                        <?php if ($item['price'] !== '') : ?>
                                            <span class="apfm-pick__price"><?php echo wp_kses_post($item['price']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="apfm-pick__state" aria-hidden="true"></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="apfm-compare">
                    <?php echo do_shortcode('[product_compare number="3"]'); ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

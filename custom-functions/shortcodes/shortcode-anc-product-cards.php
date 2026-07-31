<?php
if (!defined('ABSPATH')) exit;

/*
 * Shortcodes for AN New Chapter product panels.
 *
 * [anc_product_switcher] renders the section + switcher shell and generates
 * chips from nested [anc_product_card] shortcodes.
 * [anc_product_card] renders one .anc-pf-panel compatible with an-new-chapter.js.
 */

add_shortcode('anc_product_switcher', 'hithean_anc_product_switcher_shortcode');
add_shortcode('anc_product_card', 'hithean_anc_product_card_shortcode');

function hithean_anc_bool($value, bool $default = false): bool
{
    if ($value === '' || $value === null) {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function hithean_anc_resolve_product(array $atts): ?WC_Product
{
    if (function_exists('child_theme_wc_product_field_resolve_product')) {
        return child_theme_wc_product_field_resolve_product($atts);
    }

    if (!function_exists('wc_get_product')) {
        return null;
    }

    if (!empty($atts['id'])) {
        $product = wc_get_product((int) $atts['id']);
        return $product instanceof WC_Product ? $product : null;
    }

    if (!empty($atts['sku']) && function_exists('wc_get_product_id_by_sku')) {
        $id = wc_get_product_id_by_sku((string) $atts['sku']);
        $product = $id ? wc_get_product($id) : null;
        return $product instanceof WC_Product ? $product : null;
    }

    if (!empty($atts['slug'])) {
        $post = get_page_by_path((string) $atts['slug'], OBJECT, 'product');
        $product = $post ? wc_get_product($post->ID) : null;
        return $product instanceof WC_Product ? $product : null;
    }

    return null;
}

function hithean_anc_parse_list($value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    // Raw JSON arrays contain closing brackets, which are fragile inside WP shortcode attrs.
    // Keep JSON fallback for programmatic use, but prefer pipe-delimited text in page HTML.
    if ($value[0] === '[') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(static function ($item): string {
                return is_scalar($item) ? trim((string) $item) : '';
            }, $decoded), static fn($item): bool => $item !== ''));
        }
    }

    $items = preg_split('/\s*\|\s*/', $value);
    if (!is_array($items)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $items), static fn($item): bool => $item !== ''));
}

function hithean_anc_sanitize_css_color($value): string
{
    $value = trim((string) $value);
    if ($value === '' || preg_match('/[;{}]/', $value)) {
        return '';
    }

    if (preg_match('/^(var\(--[a-z0-9_-]+\)|#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\)|[a-z]+)$/i', $value)) {
        return $value;
    }

    return '';
}

function hithean_anc_card_meta_from_atts(array $atts): array
{
    $card = sanitize_key($atts['card'] ?? '');
    if ($card === '') {
        $card = sanitize_key($atts['slug'] ?? ('product-' . absint($atts['id'] ?? 0)));
    }

    $label = trim((string) ($atts['label'] ?? ''));
    if ($label === '') {
        $label = $card;
    }

    return [
        'card'   => $card,
        'label'  => sanitize_text_field($label),
        'active' => hithean_anc_bool($atts['active'] ?? '', false),
    ];
}

function hithean_anc_extract_card_metas(string $content): array
{
    if ($content === '' || strpos($content, '[anc_product_card') === false) {
        return [];
    }

    $regex = get_shortcode_regex(['anc_product_card']);
    if (!preg_match_all('/' . $regex . '/s', $content, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $cards = [];
    foreach ($matches as $match) {
        $atts = shortcode_parse_atts($match[3] ?? '');
        if (!is_array($atts)) {
            continue;
        }

        $meta = hithean_anc_card_meta_from_atts($atts);
        if ($meta['card'] !== '') {
            $cards[] = $meta;
        }
    }

    if ($cards && !array_filter($cards, static fn($card) => !empty($card['active']))) {
        $cards[0]['active'] = true;
    }

    return $cards;
}

function hithean_anc_render_chips(array $cards, string $aria_label, string $extra_class = ''): string
{
    if (!$cards) {
        return '';
    }

    $classes = trim('anc-pf-chips ' . $extra_class);
    $html = '<div class="' . esc_attr($classes) . '" role="tablist" aria-label="' . esc_attr($aria_label) . '">';

    foreach ($cards as $card) {
        $active = !empty($card['active']);
        $classes = 'anc-pf-chip' . ($active ? ' is-active' : '');
        $html .= '<button type="button" class="' . esc_attr($classes) . '" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" data-card="' . esc_attr($card['card']) . '">' . esc_html($card['label']) . '</button>';
    }

    return $html . '</div>';
}

function hithean_anc_product_switcher_shortcode($atts, $content = ''): string
{
    $atts = shortcode_atts([
        'id'           => '',
        'title'        => '',
        'subtitle'     => '',
        'story'        => '',
        'aria_label'   => 'Chọn sản phẩm',
        'class'        => '',
        'bottom_chips' => '1',
        'fade_in'      => '1',
    ], $atts, 'anc_product_switcher');

    $cards = hithean_anc_extract_card_metas((string) $content);
    $switcher_classes = trim('anc-pf-switcher ' . (hithean_anc_bool($atts['fade_in'], true) ? 'anc-fade-in ' : '') . sanitize_html_class((string) $atts['class']));

    ob_start();
    ?>
    <section<?php echo $atts['id'] !== '' ? ' id="' . esc_attr(sanitize_html_class((string) $atts['id'])) . '"' : ''; ?>>
        <div class="<?php echo esc_attr($switcher_classes); ?>" data-product-switcher>
            <?php if ($atts['title'] !== '') : ?>
                <h2 class="anc-section-title"><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>
            <?php if ($atts['subtitle'] !== '') : ?>
                <p class="anc-section-subtitle"><?php echo wp_kses_post($atts['subtitle']); ?></p>
            <?php endif; ?>
            <?php if ($atts['story'] !== '') : ?>
                <p class="anc-organic-story"><?php echo wp_kses_post($atts['story']); ?></p>
            <?php endif; ?>

            <?php echo hithean_anc_render_chips($cards, (string) $atts['aria_label']); ?>

            <div class="anc-pf-panels">
                <?php echo do_shortcode((string) $content); ?>
            </div>

            <?php if (hithean_anc_bool($atts['bottom_chips'], true)) : ?>
                <?php echo hithean_anc_render_chips($cards, (string) $atts['aria_label'], 'anc-pf-chips--bottom'); ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}

function hithean_anc_product_cta_text(WC_Product $product, string $stock_status): string
{
    if ($stock_status === 'coming_soon') {
        return 'Sắp ra mắt';
    }

    if ($stock_status === 'out_of_stock') {
        return 'Hết hàng — Xem chi tiết';
    }

    if ($stock_status === 'in_stock') {
        return 'Xem & Mua ngay';
    }

    if ($product->is_in_stock()) {
        return 'Xem & Mua ngay';
    }

    if (has_term(['new', 'NEW'], 'product_tag', $product->get_id())) {
        return 'Sắp ra mắt';
    }

    return 'Hết hàng — Xem chi tiết';
}

function hithean_anc_product_price_html(WC_Product $product): string
{
    if (function_exists('child_theme_wc_product_field_price_block_html')) {
        return child_theme_wc_product_field_price_block_html($product);
    }

    return '<span class="anc-price-sale">' . wp_kses_post(wc_price((float) $product->get_price())) . '</span>';
}

function hithean_anc_product_gallery_html(WC_Product $product): string
{
    if (function_exists('child_theme_wc_product_field_gallery_html')) {
        return child_theme_wc_product_field_gallery_html($product);
    }

    return '';
}

function hithean_anc_product_card_shortcode($atts): string
{
    $atts = shortcode_atts([
        'id'              => '',
        'sku'             => '',
        'slug'            => '',
        'card'            => '',
        'label'           => '',
        'active'          => '0',
        'bg'              => '',
        'accent'          => '',
        'badge'           => '',
        'intro'           => '',
        'pills_json'      => '',
        'pills'           => '',
        'highlights_json' => '',
        'highlights'      => '',
        'cta_text'        => '',
        'cta_url'         => '',
        'cta_suffix'      => ' →',
        'show_cta'        => '1',
        'secondary_cta_text'  => '',
        'secondary_cta_url'   => '',
        'secondary_cta_class' => 'button--light-blue anc-b2b-product-cta',
        'note'            => '',
        'stock_status'    => 'auto',
        'show_gallery'    => '1',
        'show_nutrition'  => '1',
        'show_price'      => '1',
        'show_calc'       => '0',
        'calc_text'       => 'Chọn sản phẩm protein/đạm phù hợp chế độ ăn - tập luyện của bạn',
        'calc_button'     => 'Bắt đầu →',
        'calc_modal'      => 'modal-calculator',
    ], $atts, 'anc_product_card');

    $product = hithean_anc_resolve_product($atts);
    if (!$product instanceof WC_Product) {
        return '';
    }

    $meta = hithean_anc_card_meta_from_atts($atts);
    $active = !empty($meta['active']);
    $stock_status = strtolower((string) $atts['stock_status']);
    if (!in_array($stock_status, ['auto', 'in_stock', 'out_of_stock', 'coming_soon'], true)) {
        $stock_status = 'auto';
    }

    $cta_text = trim((string) $atts['cta_text']);
    if ($cta_text === '') {
        $cta_text = hithean_anc_product_cta_text($product, $stock_status);
    }

    $cta_url = trim((string) $atts['cta_url']);
    if ($cta_url === '') {
        $cta_url = (string) get_permalink($product->get_id());
    }

    $pills = hithean_anc_parse_list($atts['pills'] !== '' ? $atts['pills'] : $atts['pills_json']);
    $highlights = hithean_anc_parse_list($atts['highlights'] !== '' ? $atts['highlights'] : $atts['highlights_json']);
    $accent = hithean_anc_sanitize_css_color($atts['accent']);
    $style = $accent !== '' ? '--card-accent: ' . $accent . ';' : '';

    ob_start();
    ?>
    <div class="anc-pf anc-pf-panel<?php echo $active ? ' is-active' : ''; ?>" data-card="<?php echo esc_attr($meta['card']); ?>" role="tabpanel" data-section-bg="<?php echo esc_url((string) $atts['bg']); ?>"<?php echo $active ? '' : ' hidden'; ?><?php echo $style !== '' ? ' style="' . esc_attr($style) . '"' : ''; ?>>
        <div class="anc-pf-visual">
            <?php if (hithean_anc_bool($atts['show_gallery'], true)) : ?>
                <?php echo hithean_anc_product_gallery_html($product); ?>
            <?php endif; ?>
            <div class="anc-pf-controls">
                <button type="button" class="anc-pf-nav anc-pf-nav--prev" aria-label="Sản phẩm trước">‹</button>
                <?php if (hithean_anc_bool($atts['show_nutrition'], true)) : ?>
                    <?php echo do_shortcode('[product_nutrition_label product_id="' . absint($product->get_id()) . '"]'); ?>
                <?php endif; ?>
                <button type="button" class="anc-pf-nav anc-pf-nav--next" aria-label="Sản phẩm kế tiếp">›</button>
            </div>
        </div>
        <div class="anc-pf-details">
            <?php if ($atts['badge'] !== '') : ?>
                <span class="anc-pf-badge"><?php echo wp_kses_post($atts['badge']); ?></span>
            <?php endif; ?>
            <h3 class="anc-pf-name"><?php echo esc_html($product->get_name()); ?></h3>
            <?php if ($pills) : ?>
                <div class="anc-pf-pills">
                    <?php foreach ($pills as $pill) : ?>
                        <span class="anc-stat-pill"><?php echo wp_kses_post($pill); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($atts['intro'] !== '') : ?>
                <p class="anc-pf-intro"><?php echo wp_kses_post($atts['intro']); ?></p>
            <?php endif; ?>
            <?php if ($highlights) : ?>
                <ul class="anc-product-highlights">
                    <?php foreach ($highlights as $highlight) : ?>
                        <li><?php echo wp_kses_post($highlight); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (hithean_anc_bool($atts['show_price'], true)) : ?>
                <div class="anc-product-price"><?php echo hithean_anc_product_price_html($product); ?></div>
            <?php endif; ?>
            <?php if (hithean_anc_bool($atts['show_cta'], true)) : ?>
                <a href="<?php echo esc_url($cta_url); ?>" class="button--dark-blue anc-pf-cta"><?php echo esc_html($cta_text . (string) $atts['cta_suffix']); ?></a>
            <?php endif; ?>
            <?php if ($atts['secondary_cta_text'] !== '') : ?>
                <a href="<?php echo esc_url((string) $atts['secondary_cta_url']); ?>" class="<?php echo esc_attr((string) $atts['secondary_cta_class']); ?>"><?php echo esc_html($atts['secondary_cta_text']); ?></a>
            <?php endif; ?>
            <?php if ($atts['note'] !== '') : ?>
                <div class="anc-b2b-product-note"><?php echo wp_kses_post($atts['note']); ?></div>
            <?php endif; ?>
            <?php if (hithean_anc_bool($atts['show_calc'], false)) : ?>
                <div class="anc-pf-calc">
                    <span class="anc-pf-calc-text"><?php echo wp_kses_post($atts['calc_text']); ?></span>
                    <button type="button" class="button--light-blue anc-pf-calc-btn" data-modal-open="<?php echo esc_attr(sanitize_key((string) $atts['calc_modal'])); ?>"><?php echo esc_html($atts['calc_button']); ?></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

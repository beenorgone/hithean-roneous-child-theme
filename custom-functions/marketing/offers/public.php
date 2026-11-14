<?php
defined('ABSPATH') || exit;

function hithean_offers_is_page(): bool {
    return is_page('uu-dai') || (is_singular() && has_shortcode(hithean_singular_raw_content(), 'hithean_offers'));
}
function hithean_offers_enqueue(): void {
    if (!hithean_offers_is_page()) { return; }
    foreach (['css', 'js'] as $ext) {
        $path = '/pages/uu-dai/uu-dai.' . $ext;
        if ($ext === 'css') { wp_enqueue_style('hithean-offers', get_stylesheet_directory_uri() . $path, ['hithean-custom-style', 'hithean-offer-card'], filemtime(HITHEAN_THEME_DIR . $path)); }
        else { wp_enqueue_script('hithean-offers', get_stylesheet_directory_uri() . $path, [], filemtime(HITHEAN_THEME_DIR . $path), true); wp_script_add_data('hithean-offers', 'strategy', 'defer'); }
    }
}
// Snapshot data is cached; time-sensitive HTML is rendered fresh, even if cron is late.
add_action('template_redirect', static function (): void {
    if (!hithean_offers_is_page()) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
});
function hithean_offers_date_label(array $row): string {
    if (!empty($row['end'])) {
        $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
        return wp_date('H:i:s', $row['end'], $tz) === '23:59:59'
            ? 'Đến hết ' . wp_date('d/m/Y', $row['end'], $tz)
            : 'Đến ' . wp_date('H:i d/m/Y', $row['end'], $tz);
    }
    return '';
}
function hithean_offers_logo(): string {
    // Same source as the theme header; the custom logo covers sites without the parent helper.
    $logo = function_exists('roneous_get_logo') ? (roneous_get_logo()['logo'] ?? '') : '';
    if (!$logo && ($id = (int) get_theme_mod('custom_logo'))) { $logo = (string) wp_get_attachment_image_url($id, 'full'); }
    $name = get_bloginfo('name');
    $inner = $logo ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($name) . '">' : 'the an<span>ORGANICS</span>';
    return '<a class="ho-wordmark' . ($logo ? ' ho-logo' : '') . '" href="' . esc_url(home_url('/')) . '" aria-label="' . esc_attr($name . ' — Trang chủ') . '">' . $inner . '</a>';
}
/** Names a shared quantity/shipping rule after its category filters; an unfiltered rule covers the whole shop. */
function hithean_offers_group_label(array $row): array {
    $filters = $row['source_config']['filters'] ?? [];
    $filters = is_array($filters) ? $filters : (json_decode((string) $filters, true) ?: []);
    $site_wide = !array_filter($filters, static fn($filter) => ($filter['type'] ?? '') !== 'all_products');
    $terms = [];
    foreach ($filters as $filter) {
        if (($filter['type'] ?? '') !== 'product_category' || ($filter['method'] ?? 'in_list') !== 'in_list') { continue; }
        foreach ((array) ($filter['value'] ?? []) as $term_id) { $term = get_term((int) $term_id, 'product_cat'); if ($term && !is_wp_error($term)) { $terms[] = $term; } }
    }
    $link = count($terms) === 1 ? get_term_link($terms[0]) : '';
    return ['site_wide' => $site_wide, 'name' => ($row['kind'] === 'shipping' ? 'Miễn phí vận chuyển' : 'Mua nhiều giảm giá') . ($terms ? ': ' . implode(', ', wp_list_pluck($terms, 'name')) : ''),
        'url' => $link && !is_wp_error($link) ? $link : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/san-pham/'))];
}
/** Compact product list for a group card; prices are read live so the modal never shows a stale price. */
function hithean_offers_group_dialog(array $row, string $id): string {
    ob_start(); ?>
    <dialog class="ho-group-dialog" id="<?php echo esc_attr($id); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title"><div class="ho-dialog-head"><div><h3 id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($row['product_name']); ?></h3><p><?php echo count($row['group']); ?> sản phẩm áp dụng</p></div><button type="button" class="ho-dialog-close" data-ho-close aria-label="Đóng">×</button></div>
    <ul class="ho-group-list"><?php foreach ($row['group'] as $item) : $product = wc_get_product($item['product_id']); ?>
    <li><a href="<?php echo esc_url($item['url']); ?>"><img src="<?php echo esc_url($item['image']); ?>" alt="" width="64" height="64" loading="lazy" decoding="async"><span class="ho-group-item"><strong><?php echo esc_html($item['product_name']); ?></strong><?php if ($product) : ?><span class="ho-group-price"><?php echo wp_kses_post($product->get_price_html()); ?></span><?php endif; ?></span><span class="ho-group-stock<?php echo $item['in_stock'] ? '' : ' is-out'; ?>"><?php echo $item['in_stock'] ? 'Còn hàng' : 'Tạm hết'; ?></span></a></li>
    <?php endforeach; ?></ul></dialog>
    <?php return (string) ob_get_clean();
}
/** Dòng "Mua [ảnh SP] TẶNG [ảnh quà]" — mỗi quà tặng kèm khác nhau một dòng. */
function hithean_offers_gift_strips(array $offers): string {
    $html = ''; $seen = [];
    foreach ($offers as $offer) {
        $strip = $offer['gift_strip'] ?? [];
        if (!$strip) { continue; }
        $sig = $strip['buy']['id'] . ':' . $strip['buy']['qty'] . ':' . implode(',', wp_list_pluck($strip['gifts'], 'id'));
        if (isset($seen[$sig])) { continue; }
        $seen[$sig] = true;
        $thumb = static fn(array $item): string => '<a class="ho-gift-thumb" href="' . esc_url($item['url']) . '" title="' . esc_attr($item['name']) . '"><img src="' . esc_url($item['image']) . '" alt="' . esc_attr($item['name']) . '" width="56" height="56" loading="lazy" decoding="async">' . ($item['qty'] > 1 ? '<span class="ho-gift-qty">×' . (int) $item['qty'] . '</span>' : '') . '</a>';
        $names = esc_attr('Mua ' . ($strip['buy']['qty'] > 1 ? $strip['buy']['qty'] . ' ' : '') . $strip['buy']['name'] . ' tặng ' . implode(', ', wp_list_pluck($strip['gifts'], 'name')));
        $html .= '<p class="ho-gift-strip" aria-label="' . $names . '"><span class="ho-gift-word">Mua</span>' . $thumb($strip['buy']) . '<span class="ho-gift-word ho-gift-word-accent">Tặng</span>' . implode('<span class="ho-gift-plus" aria-hidden="true">+</span>', array_map($thumb, $strip['gifts'])) . '</p>';
    }
    return $html;
}
function hithean_offers_card(array $row): string {
    $kinds = ['gift' => 'Quà tặng', 'discount' => 'Giá ưu đãi', 'shipping' => 'Ưu đãi vận chuyển', 'quantity' => 'Mua nhiều giảm giá', 'other' => 'Ưu đãi'];
    $offers = $row['offers'] ?? [$row];
    $until = max(array_map(static fn($offer) => min($offer['end'] ? $offer['end'] + 1 : PHP_INT_MAX, $offer['verified_at'] + HITHEAN_OFFERS_FRESHNESS), $offers));
    ob_start(); ?>
    <?php $group = $row['group'] ?? []; $site_wide = !empty($row['site_wide']); $dialog = $group && !$site_wide ? 'ho-group-' . substr(md5((string) $row['source_id']), 0, 10) : ''; ?>
    <article class="ho-card<?php echo $group ? ' ho-card-group' : ''; ?>" data-ho-card<?php echo $group ? ' data-ho-names="' . esc_attr(implode(' ', wp_list_pluck($group, 'product_name'))) . '"' : ''; ?> data-ho-kind="<?php echo esc_attr($row['kind']); ?>" data-ho-until="<?php echo (int) $until; ?>" data-ho-search="<?php echo esc_attr($row['product_name'] . ' ' . $row['title']); ?>">
        <a class="ho-card-visual<?php echo !empty($row['banner']) ? ' ho-has-banner' : ''; ?>" href="<?php echo esc_url($row['url']); ?>" tabindex="-1" aria-hidden="true"><span class="ho-badge"><?php echo count($offers) > 1 ? count($offers) . ' ưu đãi' : esc_html($kinds[$row['kind']] ?? 'Ưu đãi'); ?></span><?php if (!empty($row['banner'])) : ?><img class="ho-banner" src="<?php echo esc_url($row['banner']); ?>" alt=""<?php echo !empty($row['banner_w']) ? ' width="' . (int) $row['banner_w'] . '" height="' . (int) $row['banner_h'] . '"' : ''; ?> loading="lazy" decoding="async"><?php elseif (!empty($row['program']) || $site_wide) : ?><span class="ho-wheel-art">✳<span><?php echo $row['kind'] === 'shipping' ? '0₫' : '%'; ?></span></span><?php elseif ($group) : ?><span class="ho-group-art" data-count="<?php echo min(3, count($group)); ?>"><?php foreach (array_slice($group, 0, 3) as $item) : ?><img src="<?php echo esc_url($item['image']); ?>" alt="" width="240" height="240" loading="lazy" decoding="async"><?php endforeach; ?></span><?php else : ?><img src="<?php echo esc_url($row['image']); ?>" alt="" width="480" height="480" loading="lazy" decoding="async"><?php endif; ?></a>
        <div class="ho-card-body"><?php echo hithean_offers_gift_strips($offers); ?><h3><a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['product_name']); ?></a></h3><ul class="ho-benefits"><?php foreach ($offers as $offer) : $offer_until = min($offer['end'] ? $offer['end'] + 1 : PHP_INT_MAX, $offer['verified_at'] + HITHEAN_OFFERS_FRESHNESS); ?>
        <li data-ho-rule data-ho-kind="<?php echo esc_attr($offer['kind']); ?>" data-ho-until="<?php echo (int) $offer_until; ?>"><p class="ho-benefit"><?php echo esc_html($offer['title']); ?></p>
        <?php if (!empty($offer['condition_note'])) : ?><p class="ho-date"><?php echo esc_html($offer['condition_note']); ?></p><?php endif; ?>
        <?php if ($offer['end']) : ?><p class="ho-date"><?php echo esc_html(hithean_offers_date_label($offer)); ?></p><?php endif; ?></li>
        <?php endforeach; ?></ul>
        <?php if ($site_wide) : ?><p class="ho-group-products">Áp dụng cho mọi sản phẩm</p><?php elseif ($group) : $names = wp_list_pluck($group, 'product_name'); ?><p class="ho-group-products">Áp dụng cho <?php echo count($names); ?> sản phẩm: <?php echo esc_html(implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : '')); ?></p><?php endif; ?>
        <?php if (!$row['in_stock']) : ?><p class="ho-stock">Tạm hết hàng</p><?php endif; ?>
        <a class="ho-button <?php echo $row['in_stock'] ? 'ho-button-dark' : 'ho-button-quiet'; ?>" href="<?php echo esc_url($row['url']); ?>"<?php echo !empty($row['program']) ? ' data-ho-wheel-open' : ''; ?><?php echo $dialog ? ' data-ho-open="' . esc_attr($dialog) . '" aria-haspopup="dialog"' : ''; ?>><?php echo !empty($row['program']) ? 'Khám phá vòng quay' : ($site_wide ? 'Mua sắm ngay' : ($group ? 'Xem nhóm sản phẩm' : (!$row['in_stock'] ? 'Xem sản phẩm' : ($row['variable'] ? 'Chọn phân loại' : 'Xem sản phẩm & ưu đãi')))); ?><span aria-hidden="true">↗</span></a></div>
        <?php if ($dialog) { echo hithean_offers_group_dialog($row, $dialog); } ?>
    </article>
    <?php return (string) ob_get_clean();
}
/**
 * Compact product preview; full offers use the shared product-page dialog and card styles.
 */
function hithean_offers_product_card(array $offers): string {
    $row = $offers[0];
    $cards = hithean_offer_cards_from_rows($offers);
    $product = !empty($row['product_id']) ? wc_get_product((int) $row['product_id']) : null;
    $price = $product ? $product->get_price_html() : '';
    $until = max(array_map(static fn($card) => (int) $card['until'], $cards) ?: [0]);
    $url = (string) $row['url'];
    $dialog = wp_unique_id('ho-product-offers-');
    ob_start(); ?>
    <article data-ho-product-card class="ho-card ho-card-product" data-ho-card data-ho-kind="<?php echo esc_attr($row['kind']); ?>" data-ho-until="<?php echo (int) $until; ?>" data-ho-search="<?php echo esc_attr($row['product_name']); ?>">
        <a class="ho-card-visual" href="<?php echo esc_url($url . '#uu-dai'); ?>" data-ho-open="<?php echo esc_attr($dialog); ?>" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($dialog); ?>" tabindex="-1" aria-hidden="true"><span class="ho-badge"><?php echo count($cards) > 1 ? count($cards) . ' ưu đãi' : esc_html($cards[0]['badge'] ?? 'Ưu đãi'); ?></span><img src="<?php echo esc_url($row['image']); ?>" alt="" width="480" height="480" loading="lazy" decoding="async"></a>
        <div class="ho-card-body"><h3><a href="<?php echo esc_url($url . '#uu-dai'); ?>" data-ho-open="<?php echo esc_attr($dialog); ?>" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($dialog); ?>"><?php echo esc_html($row['product_name']); ?></a></h3>
        <?php if ($price !== '') : ?><p class="ho-product-price"><?php echo wp_kses_post($price); ?></p><?php endif; ?>
        <?php if (!$row['in_stock']) : ?><p class="ho-stock">Tạm hết hàng</p><?php endif; ?>
        <a class="ho-button ho-button-dark ho-offer-preview" href="<?php echo esc_url($url . '#uu-dai'); ?>" data-ho-open="<?php echo esc_attr($dialog); ?>" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($dialog); ?>"><span><strong data-ho-preview-count>Xem <?php echo count($cards); ?> ưu đãi</strong><small data-ho-preview-title><?php echo esc_html($cards[0]['title'] ?? 'Khám phá ưu đãi đang áp dụng'); ?></small></span><span aria-hidden="true">↗</span></a></div>
        <dialog class="hithean-offers-dialog ho-product-dialog" id="<?php echo esc_attr($dialog); ?>" aria-labelledby="<?php echo esc_attr($dialog); ?>-title">
            <div class="hithean-offers-dialog__head"><div><h3 id="<?php echo esc_attr($dialog); ?>-title">Ưu đãi đang áp dụng</h3><p><?php echo esc_html($row['product_name']); ?></p></div><button type="button" class="hithean-offers-dialog__close" data-ho-close aria-label="Đóng">×</button></div>
            <ul class="hithean-offers-dialog__list"><?php foreach ($cards as $card) { echo hithean_offer_card_html($card); } ?></ul>
            <div class="hithean-offers-dialog__foot"><a class="hithean-offer-card__action" href="<?php echo esc_url($url); ?>"><?php echo $row['variable'] ? 'Chọn phân loại & mua hàng' : 'Xem sản phẩm & mua hàng'; ?><span aria-hidden="true"> ↗</span></a></div>
        </dialog>
    </article>
    <?php return (string) ob_get_clean();
}
/** Active snapshot rows; preview mode limits them to managers. */
function hithean_offers_visible_items(): array {
    if (hithean_offers_settings()['preview'] && !current_user_can('manage_woocommerce')) { return []; }
    $snapshot = (array) get_option(HITHEAN_OFFERS_SNAPSHOT, []);
    $products = [];
    $items = [];
    foreach ($snapshot['items'] ?? [] as $row) {
        if (hithean_offers_status($row) !== 'active') { continue; }
        $id = (int) ($row['product_id'] ?? 0);
        if ($id) {
            if (!array_key_exists($id, $products)) { $products[$id] = wc_get_product($id); }
            $product = $products[$id];
            if (!hithean_offers_product_available($product)) { continue; }
            $row['in_stock'] = $product->is_in_stock();
        }
        $items[] = $row;
    }
    return $items;
}
function hithean_offers_shortcode(): string {
    $preview = hithean_offers_settings()['preview'];
    $items = hithean_offers_visible_items();
    usort($items, static fn($a, $b) => $b['in_stock'] <=> $a['in_stock']);
    // Product mode shows one card per product with every rule that applies to it, shared programs included.
    $catalog = [];
    foreach ($items as $row) {
        if (empty($row['product_id']) || empty($row['product_name'])) { continue; }
        if (($row['family'] ?? '') === 'wheel') {
            if (($row['source_id'] ?? '') !== 'wheel:program') { continue; }
            $row['title'] = 'Vòng quay may mắn'; $row['condition_note'] = '';
        }
        $catalog[$row['product_id']][] = $row;
    }
    // A quantity or shipping rule shared by several products is one group card, not a benefit repeated per product.
    $members = [];
    foreach ($items as $row) { if (in_array($row['kind'], ['quantity', 'shipping'], true) && ($row['family'] ?? '') === 'wdr') { $members[$row['source_id']][$row['product_id']] = $row; } }
    $members = array_filter($members, static fn($group) => count($group) > 1);
    $grouped = [];
    foreach ($items as $row) {
        $wheel = ($row['family'] ?? '') === 'wheel';
        $group = $members[$row['source_id'] ?? ''] ?? [];
        $id = $wheel ? 'wheel:program' : ($group ? 'group:' . $row['source_id'] : $row['product_id']);
        if ($group) {
            if (isset($grouped[$id])) { continue; }
            $label = hithean_offers_group_label($row);
            $row['group'] = array_values($group);
            $row['product_name'] = $label['name']; $row['url'] = $label['url']; $row['site_wide'] = $label['site_wide'];
            // Partial-variation notes are per product; the shop pages carry those details.
            $row['condition_note'] = '';
            $row['in_stock'] = $label['site_wide'] || array_filter($group, static fn($item) => $item['in_stock']); $row['variable'] = false;
        }
        if ($wheel) {
            // The wheel is one site-wide opportunity, not one benefit per prize/product.
            if (isset($grouped[$id])) { continue; }
            $row['program'] = true;
            $row['product_name'] = 'Vòng quay may mắn';
            $row['title'] = ($row['source_id'] ?? '') === 'wheel:program' ? $row['title'] : hithean_offers_wheel_summary();
            $row['kind'] = 'other';
            $row['condition_note'] = '';
            $row['url'] = home_url('/#thean-lw-root');
            $row['in_stock'] = true; $row['variable'] = false;
        }
        if (!isset($grouped[$id])) { $grouped[$id] = $row; $grouped[$id]['offers'] = []; }
        $grouped[$id]['offers'][] = $row;
        // Card gộp nhiều ưu đãi: lấy banner đầu tiên được đặt.
        if (empty($grouped[$id]['banner']) && !empty($row['banner'])) { foreach (['banner', 'banner_w', 'banner_h'] as $key) { $grouped[$id][$key] = $row[$key] ?? 0; } }
    }
    // Thứ tự thẻ (offers/order.php): còn hàng → ghim nổi bật → nhóm ưu đãi (mặc định giảm giá → quà tặng → vận chuyển); cùng nhóm giữ thứ tự snapshot.
    foreach ($grouped as &$card) { $card['offers'] = hithean_offers_sort_rows($card['offers']); }
    unset($card);
    $products = array_values($grouped);
    $card_key = static fn(array $card): array => [!empty($card['in_stock']) ? 0 : 1, array_filter(array_column($card['offers'], 'pinned')) ? 0 : 1, hithean_offers_rows_rank($card['offers'])];
    usort($products, static fn($a, $b) => $card_key($a) <=> $card_key($b));
    uasort($catalog, static fn($a, $b) => $card_key(['in_stock' => $a[0]['in_stock'] ?? false, 'offers' => $a]) <=> $card_key(['in_stock' => $b[0]['in_stock'] ?? false, 'offers' => $b]));
    $images = [];
    foreach ($products as $row) { if (!empty($row['image']) && !isset($images[$row['product_id']])) { $images[$row['product_id']] = $row; } if (count($images) === 3) { break; } }
    $count = count($products);
    ob_start(); ?>
    <div class="ho-page ho-banners-<?php echo hithean_offers_settings()['banner_layout'] === 'grid' ? 'grid' : 'full'; ?>" data-ho-page data-ho-mode="programs">
        <nav class="ho-nav" aria-label="Điều hướng ưu đãi"><?php echo hithean_offers_logo(); ?><div><a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/san-pham/')); ?>">Khám phá sản phẩm</a><?php if (function_exists('hithean_sfm_my_offers_enabled') && hithean_sfm_my_offers_enabled()) : ?><button type="button" class="ho-nav-mine" data-hmo-open aria-haspopup="dialog">Ưu đãi của tôi</button><?php endif; ?><a class="ho-nav-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>">Giỏ hàng <span aria-hidden="true">↗</span></a></div></nav>
        <?php if ($preview && current_user_can('manage_woocommerce')) : ?><p class="ho-preview" role="status">Bản xem trước dành cho quản trị viên. Bật hiển thị công khai trong WooCommerce → Ưu đãi sản phẩm khi đã kiểm tra nguồn.</p><?php endif; ?>
        <main>
        <section class="ho-hero" aria-labelledby="ho-heading"><div class="ho-hero-copy"><p class="ho-eyebrow"><span></span> ĐIỀU LÀNH, THÊM MỘT CHÚT</p><h1 id="ho-heading">Chọn điều lành.<br><em>Nhận niềm vui.</em></h1><p class="ho-lead">Khám phá những ưu đãi dành cho sản phẩm bạn yêu thích — quà tặng, giá tốt và những lựa chọn đáng để ghé xem.</p><a class="ho-button ho-button-dark ho-hero-cta" href="#ho-offers">Khám phá ưu đãi <span aria-hidden="true">↓</span></a><p class="ho-hero-note">Dành cho khách mua tại website The An</p></div>
        <div class="ho-scene" aria-hidden="true"><span class="ho-orbit"></span><span class="ho-scene-caption">Một lựa chọn tốt.<br>Thêm một niềm vui.</span><?php if ($images) : $index = 0; foreach ($images as $row) : ?><div class="ho-scene-product ho-scene-product-<?php echo ++$index; ?>"><img src="<?php echo esc_url($row['image']); ?>" width="400" height="400" alt="" <?php echo $index === 1 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>></div><?php endforeach; else : ?><span class="ho-scene-flower">✳</span><?php endif; ?><span class="ho-scene-seal"><span>A LITTLE</span><strong>MORE JOY</strong><span>THE AN ORGANICS</span></span></div></section>
        <div class="ho-ribbon"><span>Chọn sản phẩm yêu thích</span><i aria-hidden="true">✳</i><span>Xem rõ điều kiện</span><i aria-hidden="true">✳</i><span>Nhận ưu đãi khi mua</span></div>
        <section class="ho-offers-section" id="ho-offers" aria-labelledby="ho-offers-heading"><div class="ho-section-head"><div><p class="ho-eyebrow">DÀNH CHO LẦN GHÉ NÀY</p><h2 id="ho-offers-heading">Ưu đãi đang áp dụng<span class="ho-heading-dot">.</span></h2></div><p>Chọn điều bạn thích.<br>Điều kiện được ghi ngay trên từng ưu đãi.</p></div>
        <?php if ($count) : ?>
        <div class="ho-toolbar"><div class="ho-filters" role="group" aria-label="Lọc loại ưu đãi"><?php foreach (['all' => 'Tất cả', 'discount' => 'Giảm giá', 'gift' => 'Quà tặng', 'quantity' => 'Mua nhiều giảm giá', 'shipping' => 'Ưu đãi vận chuyển'] as $key => $label) : ?><button type="button" data-ho-filter="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $key === 'all' ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button><?php endforeach; ?></div><label class="ho-search"><span class="screen-reader-text">Tìm sản phẩm</span><input type="search" data-ho-search-input placeholder="Tìm sản phẩm…" autocomplete="off"></label></div>
        <p class="ho-result" data-ho-result role="status" aria-live="polite"><?php echo (int) $count; ?> sản phẩm / chương trình có ưu đãi</p>
        <div data-ho-panel="programs"><div class="ho-grid"><?php foreach ($products as $row) { echo hithean_offers_card($row); } ?></div>
        <p class="ho-empty" data-ho-empty hidden>Chưa có ưu đãi phù hợp. Thử tên sản phẩm khác hoặc chọn “Tất cả”.</p></div>
        <div data-ho-panel="products" hidden><div class="ho-grid ho-product-grid"><?php foreach ($catalog as $offers) { echo hithean_offers_product_card($offers); } ?></div>
        <p class="ho-empty" data-ho-products-empty hidden>Không tìm thấy sản phẩm phù hợp.</p></div>
        <?php else : ?><div class="ho-empty"><span aria-hidden="true">✳</span><h3>Hẹn bạn ở ưu đãi tiếp theo</h3><p>Các chương trình mới sẽ xuất hiện tại đây sau khi được cập nhật.</p><a class="ho-button ho-button-dark" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">Khám phá sản phẩm <span aria-hidden="true">↗</span></a></div><?php endif; ?>
        </section>
        <section class="ho-how" aria-labelledby="ho-how-heading"><div><p class="ho-eyebrow">TỪ LỰA CHỌN ĐẾN NIỀM VUI</p><h2 id="ho-how-heading">Nhận ưu đãi,<br>thật nhẹ nhàng.</h2></div><ol><li><span>01</span><div><h3>Chọn sản phẩm</h3><p>Tìm món bạn yêu thích trong các chương trình đang áp dụng.</p></div></li><li><span>02</span><div><h3>Xem điều kiện</h3><p>Kiểm tra số lượng, phân loại, giá trị đơn và thời hạn tại trang sản phẩm.</p></div></li><li><span>03</span><div><h3>Kiểm tra giỏ hàng</h3><p>Xác nhận ưu đãi thực tế trước khi hoàn tất đặt hàng.</p></div></li></ol></section>
        <p class="ho-terms">Mỗi chương trình có điều kiện áp dụng riêng. Khả năng kết hợp ưu đãi và giá trị cuối cùng được xác nhận trong giỏ hàng. Ưu đãi trên website có thể khác các kênh mua hàng khác.</p>
        </main>
        <?php if ($count) : ?><?php /* Đổi chế độ xem: nút "Theo SP ⇄ Theo CT" trên thanh menu đáy toàn site (site-footer-menu.php). */ ?>
        <dialog class="ho-ask" data-ho-ask aria-labelledby="ho-ask-title"><div class="ho-ask-inner">
            <p class="ho-eyebrow"><span></span> CHÀO BẠN</p>
            <h2 id="ho-ask-title">Bạn muốn xem ưu đãi theo cách nào?</h2>
            <div class="ho-ask-options">
                <button type="button" class="ho-ask-option" data-ho-ask-pick="products"><span class="ho-ask-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span><span class="ho-ask-text"><strong>Theo sản phẩm</strong><small>Đã biết mình cần gì? Xem mọi ưu đãi của từng sản phẩm (<?php echo count($catalog); ?> sản phẩm).</small></span><span class="ho-ask-arrow" aria-hidden="true">→</span></button>
                <button type="button" class="ho-ask-option" data-ho-ask-pick="programs"><span class="ho-ask-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></span><span class="ho-ask-text"><strong>Theo chương trình</strong><small>Quà tặng, giảm giá, mua nhiều, vận chuyển… (<?php echo (int) $count; ?> chương trình).</small></span><span class="ho-ask-arrow" aria-hidden="true">→</span></button>
            </div>
            <button type="button" class="ho-ask-later" data-ho-ask-pick="">Để sau, tôi xem trang trước</button>
        </div></dialog><?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
}

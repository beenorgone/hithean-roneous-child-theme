<?php
defined('ABSPATH') || exit;

function hithean_offers_is_page(): bool {
    return is_page('uu-dai') || (is_singular() && has_shortcode(hithean_singular_raw_content(), 'hithean_offers'));
}
function hithean_offers_enqueue(): void {
    if (!hithean_offers_is_page()) { return; }
    foreach (['css', 'js'] as $ext) {
        $path = '/pages/uu-dai/uu-dai.' . $ext;
        if ($ext === 'css') { wp_enqueue_style('hithean-offers', get_stylesheet_directory_uri() . $path, ['hithean-custom-style'], filemtime(HITHEAN_THEME_DIR . $path)); }
        else { wp_enqueue_script('hithean-offers', get_stylesheet_directory_uri() . $path, [], filemtime(HITHEAN_THEME_DIR . $path), true); wp_script_add_data('hithean-offers', 'defer', true); }
    }
}
// Snapshot data is cached; time-sensitive HTML is rendered fresh, even if cron is late.
add_action('template_redirect', static function (): void {
    if (!hithean_offers_is_page()) { return; }
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();
});
function hithean_offers_date_label(array $row): string {
    if (!empty($row['end'])) { return 'Đến hết ' . wp_date('d/m/Y', $row['end'], new DateTimeZone('Asia/Ho_Chi_Minh')); }
    return '';
}
function hithean_offers_card(array $row): string {
    $kinds = ['gift' => 'Quà tặng', 'discount' => 'Giá ưu đãi', 'shipping' => 'Vận chuyển', 'quantity' => 'Mua nhiều', 'other' => 'Ưu đãi'];
    $until = min($row['end'] ? $row['end'] + 1 : PHP_INT_MAX, $row['verified_at'] + HITHEAN_OFFERS_FRESHNESS);
    ob_start(); ?>
    <article class="ho-card" data-ho-card data-ho-kind="<?php echo esc_attr($row['kind']); ?>" data-ho-until="<?php echo (int) $until; ?>" data-ho-search="<?php echo esc_attr($row['product_name'] . ' ' . $row['title']); ?>">
        <a class="ho-card-visual" href="<?php echo esc_url($row['url']); ?>" tabindex="-1" aria-hidden="true"><span class="ho-badge"><?php echo esc_html($kinds[$row['kind']] ?? 'Ưu đãi'); ?></span><img src="<?php echo esc_url($row['image']); ?>" alt="" width="480" height="480" loading="lazy" decoding="async"></a>
        <div class="ho-card-body"><h3><a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['product_name']); ?></a></h3><p class="ho-benefit"><?php echo esc_html($row['title']); ?></p>
        <?php if ($row['end']) : ?><p class="ho-date"><?php echo esc_html(hithean_offers_date_label($row)); ?></p><?php endif; ?>
        <?php if (!$row['in_stock']) : ?><p class="ho-stock">Tạm hết hàng</p><?php endif; ?>
        <a class="ho-button <?php echo $row['in_stock'] ? 'ho-button-dark' : 'ho-button-quiet'; ?>" href="<?php echo esc_url($row['url']); ?>"><?php echo !$row['in_stock'] ? 'Xem sản phẩm' : ($row['variable'] ? 'Chọn phân loại' : 'Xem sản phẩm & ưu đãi'); ?><span aria-hidden="true">↗</span></a></div>
    </article>
    <?php return (string) ob_get_clean();
}
function hithean_offers_shortcode(): string {
    $settings = hithean_offers_settings();
    $preview = $settings['preview'];
    $snapshot = (array) get_option(HITHEAN_OFFERS_SNAPSHOT, []);
    $items = (!$preview || current_user_can('manage_woocommerce')) ? array_values(array_filter($snapshot['items'] ?? [], static fn($row) => hithean_offers_status($row) === 'active')) : [];
    $products = []; $orders = [];
    foreach ($items as $row) {
        if ($row['scope'] === 'order') { $key = hash('sha256', wp_json_encode([$row['title'], $row['start'], $row['end']])); $orders[$key][] = $row; }
        else { $products[] = $row; }
    }
    $images = [];
    foreach ($products as $row) { if (!isset($images[$row['product_id']])) { $images[$row['product_id']] = $row; } if (count($images) === 3) { break; } }
    $count = count($products) + count($orders);
    ob_start(); ?>
    <div class="ho-page" data-ho-page>
        <nav class="ho-nav" aria-label="Điều hướng ưu đãi"><a class="ho-wordmark" href="<?php echo esc_url(home_url('/')); ?>" aria-label="The An — Trang chủ">the an<span>ORGANICS</span></a><div><a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/san-pham/')); ?>">Khám phá sản phẩm</a><a class="ho-nav-cart" href="<?php echo esc_url(wc_get_cart_url()); ?>">Giỏ hàng <span aria-hidden="true">↗</span></a></div></nav>
        <?php if ($preview && current_user_can('manage_woocommerce')) : ?><p class="ho-preview" role="status">Bản xem trước dành cho quản trị viên. Bật hiển thị công khai trong WooCommerce → Ưu đãi sản phẩm khi đã kiểm tra nguồn.</p><?php endif; ?>
        <main>
        <section class="ho-hero" aria-labelledby="ho-heading"><div class="ho-hero-copy"><p class="ho-eyebrow"><span></span> ĐIỀU LÀNH, THÊM MỘT CHÚT</p><h1 id="ho-heading">Chọn điều lành.<br><em>Nhận thêm niềm vui.</em></h1><p class="ho-lead">Khám phá những ưu đãi dành cho sản phẩm bạn yêu thích — quà tặng, giá tốt và những lựa chọn đáng để ghé xem.</p><a class="ho-button ho-button-dark ho-hero-cta" href="#ho-offers">Khám phá ưu đãi <span aria-hidden="true">↓</span></a><p class="ho-hero-note">Dành cho khách mua tại website The An</p></div>
        <div class="ho-scene" aria-hidden="true"><span class="ho-orbit"></span><span class="ho-scene-caption">Một lựa chọn tốt.<br>Thêm một niềm vui.</span><?php if ($images) : $index = 0; foreach ($images as $row) : ?><div class="ho-scene-product ho-scene-product-<?php echo ++$index; ?>"><img src="<?php echo esc_url($row['image']); ?>" width="400" height="400" alt="" <?php echo $index === 1 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>></div><?php endforeach; else : ?><span class="ho-scene-flower">✳</span><?php endif; ?><span class="ho-scene-seal">A LITTLE<br><strong>MORE JOY</strong><br>THE AN ORGANICS</span></div></section>
        <div class="ho-ribbon"><span>Chọn sản phẩm yêu thích</span><i aria-hidden="true">✳</i><span>Xem rõ điều kiện</span><i aria-hidden="true">✳</i><span>Nhận ưu đãi khi mua</span></div>
        <section class="ho-offers-section" id="ho-offers" aria-labelledby="ho-offers-heading"><div class="ho-section-head"><div><p class="ho-eyebrow">DÀNH CHO LẦN GHÉ NÀY</p><h2 id="ho-offers-heading">Ưu đãi đang áp dụng<span class="ho-heading-dot">.</span></h2></div><p>Chọn điều bạn thích.<br>Điều kiện được ghi ngay trên từng ưu đãi.</p></div>
        <?php if ($count) : ?>
        <div class="ho-toolbar"><div class="ho-filters" role="group" aria-label="Lọc loại ưu đãi"><?php foreach (['all' => 'Tất cả', 'discount' => 'Giảm giá', 'gift' => 'Quà tặng', 'quantity' => 'Mua nhiều', 'shipping' => 'Vận chuyển'] as $key => $label) : ?><button type="button" data-ho-filter="<?php echo esc_attr($key); ?>" aria-pressed="<?php echo $key === 'all' ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button><?php endforeach; ?></div><label class="ho-search"><span class="screen-reader-text">Tìm sản phẩm</span><input type="search" data-ho-search-input placeholder="Tìm sản phẩm…" autocomplete="off"></label></div>
        <p class="ho-result" data-ho-result role="status" aria-live="polite"><?php echo (int) $count; ?> ưu đãi</p>
        <div class="ho-grid"><?php foreach ($products as $row) { echo hithean_offers_card($row); } ?></div>
        <?php if ($orders) : ?><div class="ho-order-group"><h3>Thêm ưu đãi cho đơn hàng</h3><p>Áp dụng khi đơn hàng đáp ứng đủ điều kiện được công bố.</p><div class="ho-order-grid"><?php foreach ($orders as $group) : $row = $group[0]; $until = min(array_map(static fn($r) => min($r['end'] ? $r['end'] + 1 : PHP_INT_MAX, $r['verified_at'] + HITHEAN_OFFERS_FRESHNESS), $group)); ?><article class="ho-order-card" data-ho-card data-ho-kind="<?php echo esc_attr($row['kind']); ?>" data-ho-search="<?php echo esc_attr($row['title'] . ' ' . implode(' ', array_column($group, 'product_name'))); ?>" data-ho-until="<?php echo (int) $until; ?>"><span class="ho-order-icon" aria-hidden="true">↗</span><h4><?php echo esc_html($row['title']); ?></h4><?php if ($row['end']) : ?><p><?php echo esc_html(hithean_offers_date_label($row)); ?></p><?php endif; ?><details><summary>Xem sản phẩm có chương trình</summary><ul><?php foreach ($group as $product) : ?><li><a href="<?php echo esc_url($product['url']); ?>"><?php echo esc_html($product['product_name']); ?></a></li><?php endforeach; ?></ul></details></article><?php endforeach; ?></div></div><?php endif; ?>
        <p class="ho-empty" data-ho-empty hidden>Chưa có ưu đãi phù hợp. Thử tên sản phẩm khác hoặc chọn “Tất cả”.</p>
        <?php else : ?><div class="ho-empty"><span aria-hidden="true">✳</span><h3>Hẹn bạn ở ưu đãi tiếp theo</h3><p>Các chương trình mới sẽ xuất hiện tại đây sau khi được cập nhật.</p><a class="ho-button ho-button-dark" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">Khám phá sản phẩm <span aria-hidden="true">↗</span></a></div><?php endif; ?>
        </section>
        <section class="ho-how" aria-labelledby="ho-how-heading"><div><p class="ho-eyebrow">TỪ LỰA CHỌN ĐẾN NIỀM VUI</p><h2 id="ho-how-heading">Nhận ưu đãi,<br>thật nhẹ nhàng.</h2></div><ol><li><span>01</span><div><h3>Chọn sản phẩm</h3><p>Tìm món bạn yêu thích trong các chương trình đang áp dụng.</p></div></li><li><span>02</span><div><h3>Xem điều kiện</h3><p>Kiểm tra số lượng, phân loại, giá trị đơn và thời hạn tại trang sản phẩm.</p></div></li><li><span>03</span><div><h3>Kiểm tra giỏ hàng</h3><p>Xác nhận ưu đãi thực tế trước khi hoàn tất đặt hàng.</p></div></li></ol></section>
        <p class="ho-terms">Mỗi chương trình có điều kiện áp dụng riêng. Khả năng kết hợp ưu đãi và giá trị cuối cùng được xác nhận trong giỏ hàng. Ưu đãi trên website có thể khác các kênh mua hàng khác.</p>
        </main><footer class="ho-footer"><a class="ho-wordmark" href="<?php echo esc_url(home_url('/')); ?>">the an<span>ORGANICS</span></a><p>Thêm điều lành cho mỗi ngày.</p><a href="#ho-heading">Về đầu trang ↑</a></footer>
    </div>
    <?php return (string) ob_get_clean();
}

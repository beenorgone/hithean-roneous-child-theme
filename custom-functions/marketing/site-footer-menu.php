<?php
/**
 * Thanh menu dính đáy toàn site (trang chủ + các trang KHÔNG phải: sản phẩm, danh mục / cửa hàng, giỏ / thanh toán /
 * cảm ơn, trang đã có thanh đáy riêng). Điều kiện nạp: tpc_cond_site_footer_menu() trong module-loader.
 *   Trang chủ · Sản phẩm · Nhận ưu đãi (/uu-dai/) · Ưu đãi của tôi (panel — my-offers.php).
 * Trang /uu-dai/ có thanh đổi chế độ riêng → không in thanh, chỉ in panel (nút "Ưu đãi của tôi" ở nav của trang).
 * Kiểu thanh giống thanh đáy trang danh mục (product-archive-footer-menu.css). CSS/JS: marketing/assets/site-footer-menu.*
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/my-offers.php';

function hithean_sfm_my_offers_enabled(): bool {
    return class_exists('WooCommerce');
}

/** Trang có thanh đáy riêng → chỉ in panel "Ưu đãi của tôi". */
function hithean_sfm_has_bar(): bool {
    $own_bar = function_exists('hithean_offers_is_page') && hithean_offers_is_page();
    return (bool) apply_filters('hithean_sfm_has_bar', !$own_bar);
}

function hithean_sfm_offers_url(): string {
    $page = get_page_by_path('uu-dai');
    return $page && $page->post_status === 'publish' ? (string) get_permalink($page) : '';
}

function hithean_sfm_items(): array {
    $items = [
        ['key' => 'home', 'label' => 'Trang chủ', 'href' => home_url('/'), 'current' => is_front_page()],
        ['key' => 'products', 'label' => 'Sản phẩm', 'href' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/san-pham/'), 'current' => false],
    ];
    if (($offers = hithean_sfm_offers_url()) !== '') {
        $items[] = ['key' => 'offers', 'label' => 'Nhận ưu đãi', 'href' => $offers, 'current' => false];
    }
    if (hithean_sfm_my_offers_enabled()) {
        $items[] = ['key' => 'mine', 'label' => 'Ưu đãi của tôi', 'href' => '', 'current' => false];
    }
    return (array) apply_filters('hithean_sfm_items', $items);
}

function hithean_sfm_icon(string $key): string {
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'products' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'offers' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'mine' => '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M9 9.5h.01M15 14.5h.01M15.5 9 8.5 15"/>',
    ];
    return isset($paths[$key]) ? '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$key] . '</svg>' : '';
}

/** Trang đăng nhập (tài khoản) — quay lại đúng trang đang xem + mở panel sau khi đăng nhập. */
function hithean_sfm_login_url(): string {
    $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
    $back = home_url(add_query_arg([], $GLOBALS['wp']->request ?? ''));
    return add_query_arg('hmo_return', rawurlencode(wp_make_link_relative($back)), $account);
}

/*
 * Đăng nhập SMS (firebase-sms-login) / email OTP ở trang tài khoản tải lại chính URL đó; form mật khẩu của Woo
 * chuyển về referer — đều giữ ?hmo_return → vừa đăng nhập xong là đưa về trang cũ + mở "Ưu đãi của tôi".
 */
add_action('template_redirect', static function (): void {
    if (empty($_GET['hmo_return']) || !is_user_logged_in()) { return; }
    $target = '/' . ltrim(rawurldecode((string) wp_unslash($_GET['hmo_return'])), '/');
    if (strpos($target, '//') === 0) { return; }
    wp_safe_redirect(home_url($target) . '#uu-dai-cua-toi');
    exit;
});

add_filter('body_class', static function (array $classes): array {
    if (hithean_sfm_has_bar()) { $classes[] = 'has-sfm'; }
    return $classes;
});

add_action('wp_enqueue_scripts', static function (): void {
    $base = '/custom-functions/marketing/assets/site-footer-menu';
    $ver = static fn(string $ext): string => (string) @filemtime(HITHEAN_THEME_DIR . $base . '.' . $ext);
    wp_enqueue_style('hithean-sfm', get_stylesheet_directory_uri() . $base . '.css', ['hithean-offer-card'], $ver('css'));
    wp_enqueue_script('hithean-sfm', get_stylesheet_directory_uri() . $base . '.js', [], $ver('js'), true);
    wp_script_add_data('hithean-sfm', 'strategy', 'defer');
    // Không nhúng nonce / dữ liệu riêng: trang có thể bị cache — mọi thứ của khách tải qua AJAX khi mở panel.
    wp_localize_script('hithean-sfm', 'hitheanSfm', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'loginUrl' => hithean_sfm_login_url(),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/gio-hang/'),
    ]);
}, 25);

add_action('wp_footer', static function (): void {
    if (hithean_sfm_has_bar()) { hithean_sfm_render_bar(); }
    if (hithean_sfm_my_offers_enabled()) { hithean_sfm_render_panel(); }
}, 25);

function hithean_sfm_render_bar(): void {
    $items = hithean_sfm_items();
    if (!$items) { return; }
    ?>
    <?php // div thay vì <nav>: JS/CSS theme cha nhắm vào mọi `nav` (offcanvas, sticky header) — như apfm. ?>
    <div class="sfm" id="sfm-root" role="navigation" aria-label="Menu nhanh">
        <ul class="sfm__list" role="list">
            <?php foreach ($items as $item) :
                $key = (string) $item['key']; ?>
                <li class="sfm__item sfm__item--<?php echo esc_attr($key); ?>">
                    <?php if ($key === 'mine') : ?>
                        <button type="button" class="sfm__link" data-hmo-open aria-haspopup="dialog" aria-controls="hmo-dialog">
                            <span class="sfm__icon"><?php echo hithean_sfm_icon($key); ?><span class="sfm__badge" data-hmo-badge hidden></span></span>
                            <span class="sfm__label"><?php echo esc_html($item['label']); ?></span>
                        </button>
                    <?php else : ?>
                        <a class="sfm__link<?php echo !empty($item['current']) ? ' is-current' : ''; ?>" href="<?php echo esc_url($item['href']); ?>"<?php echo !empty($item['current']) ? ' aria-current="page"' : ''; ?>>
                            <span class="sfm__icon"><?php echo hithean_sfm_icon($key); ?></span>
                            <span class="sfm__label"><?php echo esc_html($item['label']); ?></span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/** Khung panel: nội dung riêng của khách nạp bằng AJAX (hithean_my_offers_load). Nút đăng nhập giống trang thanh toán. */
function hithean_sfm_render_panel(): void {
    $offers_url = hithean_sfm_offers_url();
    ?>
    <dialog class="hmo" id="hmo-dialog" aria-labelledby="hmo-title">
        <div class="hmo__head">
            <div><h3 id="hmo-title">Ưu đãi của tôi</h3><p data-hmo-meta>Mã giảm giá & quà dành riêng cho bạn</p></div>
            <button type="button" class="hmo__close" data-hmo-close aria-label="Đóng">×</button>
        </div>
        <div class="hmo__body">
            <p class="hmo__status" data-hmo-status role="status" aria-live="polite"></p>
            <div class="hmo__contacts" data-hmo-contacts hidden></div>
            <div class="hmo__loading" data-hmo-loading>Đang tải ưu đãi…</div>
            <ul class="hithean-offer-list hmo__list" data-hmo-list hidden></ul>
            <div class="hmo__empty" data-hmo-empty hidden>
                <strong>Chưa có mã ưu đãi riêng còn hạn</strong>
                <p>Quay vòng quay may mắn hoặc xem các chương trình đang áp dụng cho mọi khách.</p>
            </div>
            <section class="hmo__auth" data-hmo-auth hidden>
                <h4 data-hmo-auth-title>Đăng nhập để xem & dùng ngay mã của bạn</h4>
                <div class="hmo__login">
                    <?php if (shortcode_exists('zalo_login_btn')) : ?><div class="hmo__zalo"><?php echo do_shortcode('[zalo_login_btn]'); ?></div><?php endif; ?>
                    <a class="hmo__btn hmo__btn--primary" data-hmo-login-link href="<?php echo esc_url(hithean_sfm_login_url()); ?>">Đăng nhập bằng SĐT (OTP) / Email</a>
                </div>
                <p class="hmo__or"><span>hoặc kiểm tra nhanh</span></p>
                <form class="hmo__lookup" data-hmo-lookup novalidate>
                    <label class="screen-reader-text" for="hmo-contact">Email hoặc số điện thoại</label>
                    <input id="hmo-contact" name="contact" type="text" inputmode="email" autocomplete="email" placeholder="Email hoặc số điện thoại đã dùng" required>
                    <button type="submit" class="hmo__btn">Kiểm tra</button>
                </form>
                <p class="hmo__hint">Hệ thống tự tìm ưu đãi theo email / SĐT và tài khoản gắn với chúng. Mã sẽ được ẩn một phần cho tới khi bạn đăng nhập hoặc nhận qua email.</p>
            </section>
        </div>
        <div class="hmo__foot">
            <?php if ($offers_url !== '') : ?><a href="<?php echo esc_url($offers_url); ?>">Xem ưu đãi chung đang áp dụng ↗</a><?php endif; ?>
            <?php if (function_exists('thean_lw_is_eligible_page') && thean_lw_is_eligible_page()) : ?><button type="button" class="hmo__link" data-hmo-wheel>🎡 Quay vòng quay may mắn</button><?php endif; ?>
        </div>
    </dialog>
    <?php
}

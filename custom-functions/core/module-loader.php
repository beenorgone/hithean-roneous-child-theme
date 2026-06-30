<?php
if (!defined('ABSPATH')) exit;

/**
 * Module loader — TOÀN BỘ logic "what-loads-when":
 *  - $general_includes: nạp mọi request.
 *  - tpc_loader_*: nạp có điều kiện theo path / conditional tag / shortcode / admin / ajax.
 *  - social-display + editor-tools: nạp theo ngữ cảnh nội dung.
 *
 * Path module dựa trên hằng HITHEAN_THEME_DIR (thư mục theme), không dùng __DIR__
 * vì file này nằm trong core/.
 */

// =====================================================================
// 1) General includes — nạp mọi request
// =====================================================================
$general_includes = [
    'custom-functions/backend-optimize.php',
    'custom-functions/functional.php',
    'custom-functions/theme-code-cache.php',
    'custom-functions/woocommerce-settings.php',
    'custom-functions/swiper-slider.php',
    'custom-functions/popup-widget.php',
    'custom-functions/shortcode-field-content.php',
    'custom-functions/shortcode-greenspark-banner.php',
    // product-page.php, product-linking.php → conditional (xem tpc_loader_modules)
    'custom-functions/product-nutrition-label.php',
    'custom-functions/product-list-page.php',
    'custom-functions/pos-post-type.php',
    // 'custom-functions/recipe-post-type.php',
    'custom-functions/shortcode-recipes.php',
    'custom-functions/shortcode-posts.php',
    'custom-functions/shortcode-pos.php',
    // shortcode-bank-transfer-confirmation, shortcode-payment-pending-confirm,
    // shortcode-order-shipped, shortcode-order-export-confirm → conditional (tpc_loader_modules)
    'custom-functions/shortcode-google-docs.php',
    'custom-functions/shortcode-embed.php',
    'custom-functions/admin-settings-tools.php',
    'custom-functions/product-tab-post-type.php',
    'custom-functions/blogpost-metabox.php',
    'custom-functions/product-metabox.php',

    /* WOO UI */
    'custom-functions/lucky-wheel.php',

    /* ORDER */
    'custom-functions/order-metabox.php',
    'custom-functions/order-number.php',
    'custom-functions/order-status.php',
    'custom-functions/order-tracking.php',
    'custom-functions/order-creator/create-order-for-customer.php', // Trang /tao-don/ — tạo đơn hộ khách

    // checkout-page.php → conditional (tpc_loader_modules)
    'custom-functions/order-auto-tasks.php',
    'custom-functions/thank-you-page.php', // To ensure order admin page, thank you page, and emails can use this
    'custom-functions/firebase-sms-login.php',
    'custom-functions/email-issue.php',
    'custom-functions/email-login.php',
    'custom-functions/email-tasks.php',
    'custom-functions/product-taxonomies.php',

    /* Plugins */
    'custom-functions/plugin-devvn-ghtk-tweaks.php',
];

foreach ($general_includes as $include) {
    if (file_exists(HITHEAN_THEME_DIR . '/' . $include)) {
        require_once(HITHEAN_THEME_DIR . '/' . $include);
    }
}

// meta-key-rename-tool.php: công cụ admin 1-lần, đã TẮT (bật lại bằng cách bỏ comment).
// if (is_admin() && file_exists(HITHEAN_THEME_DIR . '/custom-functions/meta-key-rename-tool.php')) {
//     require_once(HITHEAN_THEME_DIR . '/custom-functions/meta-key-rename-tool.php');
// }

// =====================================================================
// 2) Social display shortcodes — nạp theo trang
// =====================================================================
/**
 * Social display shortcodes ([social_fan] / [social_marquee] / [social_collage] / [social_coverflow])
 * chỉ nạp ở: trang chủ, page "an-new-chapter", page "ve-chung-toi", và trang sản phẩm.
 *
 * Thêm/bớt page (theo slug) qua filter nếu cần:
 *   add_filter('hithean_social_display_page_slugs', fn($slugs) => [...$slugs, 'lien-he']);
 */
function hithean_social_display_allowed(): bool
{
    // Trang chủ.
    if (is_front_page()) {
        return true;
    }

    // Trang sản phẩm đơn.
    if (function_exists('is_product') && is_product()) {
        return true;
    }

    // Các page chỉ định (theo slug).
    $page_slugs = (array) apply_filters('hithean_social_display_page_slugs', [
        'an-new-chapter',
        've-chung-toi',
    ]);
    if (!empty($page_slugs) && is_page($page_slugs)) {
        return true;
    }

    return false;
}

add_action('template_redirect', function (): void {
    $file = HITHEAN_THEME_DIR . '/custom-functions/shortcode-social-display.php';
    if (hithean_social_display_allowed() && file_exists($file)) {
        require_once($file);
    }
});

/**
 * Đăng ký meta attachment cho 4 shortcode social — LUÔN nạp (kể cả REST upload
 * từ tool TikTok Research), vì file shortcode ở trên chỉ nạp có điều kiện.
 * Định nghĩa cùng tên + guard nên khi file shortcode có nạp cũng không trùng.
 */
if (!function_exists('social_display_register_media_meta')) {
    function social_display_register_media_meta(): void
    {
        foreach (['_social_display', '_social_display_url'] as $meta_key) {
            register_post_meta('attachment', $meta_key, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => static function (): bool {
                    return current_user_can('upload_files');
                },
            ]);
        }
    }
    add_action('init', 'social_display_register_media_meta');
}

// =====================================================================
// 3) Editor tools — nạp theo nội dung bài viết
// =====================================================================
function child_theme_should_load_post_editor_tools(): bool
{
    if (!is_admin()) {
        return false;
    }

    $admin_page = isset($_SERVER['PHP_SELF']) ? basename((string) wp_unslash($_SERVER['PHP_SELF'])) : '';
    if (!in_array($admin_page, ['post.php', 'post-new.php'], true)) {
        return false;
    }

    if ($admin_page === 'post-new.php') {
        return true;
    }

    $post_id = 0;
    if (isset($_GET['post'])) {
        $post_id = absint($_GET['post']);
    } elseif (isset($_POST['post_ID'])) {
        $post_id = absint($_POST['post_ID']);
    }

    return $post_id > 0;
}

/*
if (child_theme_should_load_post_editor_tools()) {
    require_once(HITHEAN_THEME_DIR . '/custom-functions/editor-tools/editor-tools.php');
}
*/

add_action('wp', function (): void {
    if (!is_singular()) {
        return;
    }

    $post = get_post();
    if (!$post instanceof WP_Post) {
        return;
    }

    $post_content = (string) $post->post_content;
    if (stripos($post_content, '<table') === false && strpos($post_content, 'ivar-content-button') === false) {
        return;
    }

    require_once(HITHEAN_THEME_DIR . '/custom-functions/editor-tools/editor-tools.php');
});

// =====================================================================
// 4) tpc_loader — conditional module system
// =====================================================================
function tpc_loader_require_relative($relative_path)
{
    $path = HITHEAN_THEME_DIR . '/' . ltrim((string) $relative_path, '/');
    if (is_file($path)) {
        require_once $path;
    }
}

function tpc_loader_normalize_path($path)
{
    $path = '/' . ltrim((string) $path, '/');
    return untrailingslashit($path);
}

function tpc_loader_current_path()
{
    static $current_path = null;

    if ($current_path !== null) {
        return $current_path;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $parsed_path = $request_uri ? wp_parse_url(home_url($request_uri), PHP_URL_PATH) : '';
    $current_path = tpc_loader_normalize_path((string) $parsed_path);

    return $current_path;
}

function tpc_loader_path_groups()
{
    return apply_filters('tpc_loader_path_groups', [
        'tools' => [
            '/tien-ich',
            '/tien-ich-admin',
        ],
        'compare' => [
            '/tra-cuu',
            '/so-sanh',
            '/tra-cuu/so-sanh',
        ],
        'protein' => [
            '/protein-calculator',
            '/tien-ich/protein-calculator',
            '/an-new-chapter',
        ],
        'anc_landing' => [
            '/an-new-chapter',
        ],
    ]);
}

function tpc_loader_expand_module_paths(array $module)
{
    $paths = isset($module['paths']) ? (array) $module['paths'] : [];
    $groups = isset($module['path_groups']) ? (array) $module['path_groups'] : [];
    $group_paths = [];
    $group_map = tpc_loader_path_groups();

    foreach ($groups as $group) {
        if (isset($group_map[$group])) {
            $group_paths = array_merge($group_paths, (array) $group_map[$group]);
        }
    }

    $all_paths = array_merge($paths, $group_paths);

    if (!empty($module['paths_filter']) && is_string($module['paths_filter'])) {
        $all_paths = (array) apply_filters($module['paths_filter'], $all_paths, $module);
    }

    return array_values(array_unique(array_filter(array_map('tpc_loader_normalize_path', $all_paths))));
}

function tpc_loader_front_path_matches(array $module)
{
    if (is_admin()) {
        return false;
    }

    $allowed_paths = tpc_loader_expand_module_paths($module);
    if (empty($allowed_paths)) {
        return false;
    }

    return in_array(tpc_loader_current_path(), $allowed_paths, true);
}

function tpc_loader_current_ajax_action()
{
    if (!wp_doing_ajax()) {
        return '';
    }

    return isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
}

function tpc_loader_modules()
{
    static $modules = null;

    if ($modules !== null) {
        return $modules;
    }

    $modules = [
        [
            'id' => 'product_compare',
            'file' => 'custom-functions/shortcode-product-compare.php',
            'path_groups' => ['tools', 'compare'],
            'paths_filter' => 'tpc_compare_allowed_paths',
            'ajax_actions' => [
                'tpc_product_compare_search',
                'tpc_product_compare_get_product',
            ],
        ],
        [
            'id' => 'protein_calculator',
            'file' => 'custom-functions/shortcode-protein-calculator.php',
            'path_groups' => ['tools', 'protein'],
            'ajax_actions' => [
                'protein_calculator_get_products',
            ],
        ],
        [
            'id' => 'wc_product_field',
            'file' => 'custom-functions/shortcode-wc-product-field.php',
            'path_groups' => ['anc_landing'],
        ],

        /* ===== Chuyển từ $general_includes sang conditional (Phase 2) =====
         * 'condition'  : callable, đánh giá ở hook 'wp' (front) để gate theo conditional tag.
         * 'shortcodes' : load nếu is_singular() và post_content có shortcode tương ứng.
         * 'admin'      : load ở include-time khi is_admin() (giữ kịp hook admin_menu/admin_init).
         * 'ajax_actions': load khi admin-ajax có action khớp.
         */
        [
            'id' => 'product_page',
            'file' => 'custom-functions/product-page.php',
            'condition' => 'tpc_cond_is_product',
        ],
        [
            'id' => 'checkout_page',
            'file' => 'custom-functions/checkout-page.php',
            'condition' => 'tpc_cond_is_checkout',
        ],
        [
            'id' => 'product_linking',
            'file' => 'custom-functions/product-linking.php',
            'admin' => true,
            'condition' => 'tpc_cond_is_product',
            'ajax_actions' => ['hithean_product_linking_search'],
        ],
        [
            'id' => 'bank_transfer_confirm',
            'file' => 'custom-functions/shortcode-bank-transfer-confirmation.php',
            'shortcodes' => ['order_paid_confirmation'],
            'ajax_actions' => ['confirm_order_payment', 'search_order_ajax'],
        ],
        [
            'id' => 'payment_pending_confirm',
            'file' => 'custom-functions/shortcode-payment-pending-confirm.php',
            'shortcodes' => ['order_payment_pending_confirm'],
            'ajax_actions' => ['oppc_confirm_payment', 'oppc_load_orders'],
        ],
        [
            'id' => 'order_shipped',
            'file' => 'custom-functions/shortcode-order-shipped.php',
            'shortcodes' => ['order_shipped_table'],
            'ajax_actions' => ['ajax_load_order_shipped'],
        ],
        [
            'id' => 'order_export_confirm',
            'file' => 'custom-functions/shortcode-order-export-confirm.php',
            'shortcodes' => ['upload_export_images_form', 'list_unconfirmed_exports', 'list_uploaded_not_shipped_exports'],
            'ajax_actions' => ['ajax_confirm_export', 'ajax_upload_images'],
        ],
    ];

    return apply_filters('tpc_loader_modules', $modules);
}

/* Conditional-tag helpers cho tpc_loader (đánh giá ở hook 'wp'). */
function tpc_cond_is_product()
{
    return function_exists('is_product') && is_product();
}

function tpc_cond_is_checkout()
{
    return function_exists('is_checkout') && is_checkout();
}

function tpc_loader_front_module_matches(array $module)
{
    if (is_admin()) {
        return false;
    }

    // 1) Khớp theo URL path.
    if (tpc_loader_front_path_matches($module)) {
        return true;
    }

    // 2) Khớp theo conditional tag (is_product / is_checkout ...).
    if (!empty($module['condition']) && is_callable($module['condition']) && call_user_func($module['condition'])) {
        return true;
    }

    // 3) Khớp khi trang đơn có chứa shortcode tương ứng.
    if (!empty($module['shortcodes']) && is_singular()) {
        $post = get_post();
        if ($post instanceof WP_Post && $post->post_content !== '') {
            foreach ((array) $module['shortcodes'] as $shortcode) {
                if (tpc_loader_content_has_shortcode($post->post_content, $shortcode)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Phát hiện shortcode trong nội dung mà KHÔNG dùng has_shortcode().
 * has_shortcode() yêu cầu shortcode đã được đăng ký, nhưng file đăng ký lại
 * chính là file ta đang quyết định có nạp hay không → sẽ không bao giờ nạp.
 */
function tpc_loader_content_has_shortcode($content, $shortcode)
{
    if ($content === '' || strpos($content, '[') === false) {
        return false;
    }

    return (bool) preg_match('/\[\s*' . preg_quote($shortcode, '/') . '(?=[\s\]\/])/', $content);
}

function tpc_loader_load_front_modules()
{
    foreach (tpc_loader_modules() as $module) {
        if (!empty($module['file']) && tpc_loader_front_module_matches($module)) {
            tpc_loader_require_relative($module['file']);
        }
    }
}
add_action('wp', 'tpc_loader_load_front_modules', PHP_INT_MAX - 1);

/**
 * Module gắn cờ 'admin' được nạp ngay ở include-time (không qua hook) để kịp
 * đăng ký admin_menu/admin_init. Bỏ qua trong admin-ajax (đã có ajax loader lo).
 */
function tpc_loader_load_admin_modules()
{
    if (!is_admin() || wp_doing_ajax()) {
        return;
    }

    foreach (tpc_loader_modules() as $module) {
        if (!empty($module['file']) && !empty($module['admin'])) {
            tpc_loader_require_relative($module['file']);
        }
    }
}
tpc_loader_load_admin_modules();

function tpc_loader_load_ajax_modules()
{
    $action = tpc_loader_current_ajax_action();
    if ($action === '') {
        return;
    }

    foreach (tpc_loader_modules() as $module) {
        $ajax_actions = isset($module['ajax_actions']) ? (array) $module['ajax_actions'] : [];
        if (!empty($module['file']) && in_array($action, $ajax_actions, true)) {
            tpc_loader_require_relative($module['file']);
        }
    }
}
add_action('init', 'tpc_loader_load_ajax_modules', 1);

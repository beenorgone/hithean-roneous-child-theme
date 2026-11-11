<?php
if (!defined('ABSPATH')) exit;

/** Fixed registry: request values never become shortcode names or include paths. */
function thean_order_product_tools(): array
{
    return [
        'info' => ['label' => 'Tra thông tin & HDSD sản phẩm', 'shortcode' => 'product_info_search', 'file' => 'shortcode-product-info-search.php'],
        'compare' => ['label' => 'So sánh sản phẩm', 'shortcode' => 'product_compare', 'file' => 'shortcode-product-compare.php'],
        'protein' => ['label' => 'Tính protein', 'shortcode' => 'protein_calculator', 'file' => 'shortcode-protein-calculator.php'],
    ];
}

/** Export lists come first; uploads are a separate, final step. */
function thean_order_export_tools(): array
{
    return [
        'export_review' => ['label' => 'Ảnh chờ duyệt', 'shortcode' => 'list_unconfirmed_exports', 'file' => 'shortcode-order-export-confirm.php'],
        'export_waiting' => ['label' => 'Đơn đã upload ảnh nhưng chưa giao đi', 'shortcode' => 'list_uploaded_not_shipped_exports', 'file' => 'shortcode-order-export-confirm.php'],
        'export_upload' => ['label' => 'Up ảnh', 'shortcode' => 'upload_export_images_form', 'file' => 'shortcode-order-export-confirm.php'],
    ];
}

/**
 * Module Xuất kho Shopee có ở site này không (theanmarket có, hithean không). Có → khu Xuất kho thêm bộ chọn
 * loại đơn Website / Shopee, truyền vào iframe bằng ?thean_export_kind=all|web|shopee (shortcode đọc thean_export_kind()).
 */
function thean_order_export_has_shopee(): bool
{
    return is_file(dirname(__DIR__) . '/shipping/shopee-export/shopee-export.php');
}

/** Board hoàn hàng (shortcode ở shipping/) — /quan-ly-don nhúng dưới tab Hoàn hàng của board. */
function thean_order_return_tools(): array
{
    return [
        'returns' => ['label' => 'Quản lý hoàn hàng', 'shortcode' => 'order_return_management', 'file' => 'shortcode-order-return-manager.php'],
    ];
}

function thean_order_workspace_tools(): array
{
    return array_merge(thean_order_product_tools(), thean_order_export_tools(), thean_order_return_tools());
}

function thean_order_product_tool_url(string $tool): string
{
    if (!isset(thean_order_workspace_tools()[$tool])) return '';
    return wp_nonce_url(add_query_arg(['thean_order_tool' => $tool], home_url('/')), 'thean_order_product_tool');
}

/** Isolated document keeps the existing shortcodes' CSS, IDs and inline JS out of the order board. */
function thean_order_product_tool_response(): void
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
        wp_die('Không có quyền sử dụng công cụ.', '', ['response' => 403]);
    }
    check_admin_referer('thean_order_product_tool');
    $raw = $_GET['thean_order_tool'] ?? '';
    $key = is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';
    $tool = thean_order_workspace_tools()[$key] ?? null;
    $is_export = isset(thean_order_export_tools()[$key]);
    $is_return = isset(thean_order_return_tools()[$key]);
    if (!$tool) wp_die('Công cụ không hợp lệ.', '', ['response' => 400]);
    if ($is_return && !current_user_can('manage_woocommerce')) wp_die('Không có quyền quản lý hoàn hàng.', '', ['response' => 403]);
    if ($is_export && (!current_user_can('manage_woocommerce') || ($key === 'export_upload' && !current_user_can('upload_files')))) {
        wp_die('Không có quyền sử dụng công cụ xuất kho.', '', ['response' => 403]);
    }
    if (!shortcode_exists($tool['shortcode'])) {
        $base = dirname(__DIR__, 2);
        // Theanmarket: woocommerce/products; Hithean: shortcodes.
        foreach ([$base . ($is_export || $is_return ? '/woocommerce/shipping/' : '/woocommerce/products/'), $base . '/shortcodes/'] as $dir) {
            if (is_file($dir . $tool['file'])) {
                require_once $dir . $tool['file'];
                break;
            }
        }
    }
    if (!shortcode_exists($tool['shortcode'])) wp_die('Công cụ chưa sẵn sàng.', '', ['response' => 503]);
    if ($is_export || $is_return) {
        $spx = dirname(__DIR__, 2) . '/woocommerce/shipping/shopee-export/shopee-export.php';
        if (!function_exists('thean_spx_render_embed') && is_file($spx)) require_once $spx; // same dependency as the existing Theanmarket export tools page
    }
    wp_enqueue_script('jquery');
    if ($key === 'info') {
        if (function_exists('en_product_info_search_scripts')) en_product_info_search_scripts();
        elseif (function_exists('pis_register_scripts')) pis_register_scripts();
    }
    // Render first: shortcodes can enqueue their own dependencies.
    $content = do_shortcode('[' . $tool['shortcode'] . ']');
    nocache_headers();
    send_frame_options_header(); // same-origin authenticated embedding only
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    header('X-Robots-Tag: noindex, nofollow');
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($tool['label']); ?></title>
        <?php wp_print_styles(); wp_print_head_scripts(); ?>
        <style>
            :root { --default-color-green:#0047ba; }
            * { box-sizing:border-box; }
            body { margin:0; padding:16px; background:#fff; color:#1e293b; font:14px/1.6 system-ui,sans-serif; }
            button,input,select,textarea { font:inherit; }
            button { cursor:pointer; }
            a { color:#0047ba; }
            img { max-width:100%; height:auto; }
            .button { display:inline-block; padding:8px 12px; border:1px solid #b9cdf0; border-radius:8px; background:#eef4ff; color:#0047ba; }
        </style>
    </head>
    <body>
        <main><?php echo $content; // Trusted registered shortcode, not request-supplied markup. ?></main>
        <?php
        wp_print_footer_scripts();
        if ($is_export) {
            foreach (['thean_export_confirm_assets', 'hithean_export_confirm_assets'] as $render) {
                if (function_exists($render)) { $render(); break; }
            }
        }
        ?>
    </body>
    </html>
    <?php
    exit;
}

function thean_order_render_product_workspace(): void
{
    thean_order_render_tool_workspace('products');
}

function thean_order_render_export_workspace(): void
{
    if (current_user_can('manage_woocommerce')) thean_order_render_tool_workspace('exports');
}

function thean_order_render_tool_workspace(string $scope): void
{
    if (!in_array($scope, ['products', 'exports'], true)) return;
    $is_export = $scope === 'exports';
    $tools = $is_export ? thean_order_export_tools() : thean_order_product_tools();
    $first = array_key_first($tools);
    $prefix = $is_export ? 'export' : 'product';
    $title = $is_export ? 'Xuất kho' : 'Sản phẩm';
    ?>
    <section class="thean-ous-products" id="thean-ous-<?php echo esc_attr($scope); ?>-panel" data-tool-workspace="<?php echo esc_attr($scope); ?>" role="tabpanel" aria-labelledby="thean-workspace-<?php echo esc_attr($scope); ?>" hidden>
        <div class="thean-ous-board__head">
            <h2><?php echo esc_html($title); ?></h2>
            <?php if ($is_export && thean_order_export_has_shopee()) : ?>
                <div class="thean-export-kind" role="group" aria-label="Loại đơn">
                    <?php foreach (['all' => 'Tất cả', 'web' => '📋 Website', 'shopee' => '🛍️ Shopee'] as $kind => $kind_label) : ?>
                        <button type="button" data-export-kind="<?php echo esc_attr($kind); ?>" aria-pressed="<?php echo $kind === 'all' ? 'true' : 'false'; ?>"><?php echo esc_html($kind_label); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-workflow-guide="<?php echo esc_attr($scope); ?>" aria-haspopup="dialog" aria-controls="thean-ous-workflow-guide">📖 Hướng dẫn</button>
        </div>
        <div class="thean-workspace-tabs" role="tablist" aria-label="<?php echo esc_attr($title); ?>">
            <?php foreach ($tools as $key => $tool) : ?>
                <button type="button" id="thean-<?php echo esc_attr($prefix); ?>-tab-<?php echo esc_attr($key); ?>" role="tab" aria-controls="thean-<?php echo esc_attr($prefix); ?>-<?php echo esc_attr($key); ?>" aria-selected="<?php echo $key === $first ? 'true' : 'false'; ?>" tabindex="<?php echo $key === $first ? '0' : '-1'; ?>" data-product-tool="<?php echo esc_attr($key); ?>"><?php echo esc_html($tool['label']); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($tools as $key => $tool) : $url = thean_order_product_tool_url($key); ?>
            <section id="thean-<?php echo esc_attr($prefix); ?>-<?php echo esc_attr($key); ?>" role="tabpanel" aria-labelledby="thean-<?php echo esc_attr($prefix); ?>-tab-<?php echo esc_attr($key); ?>" data-product-panel="<?php echo esc_attr($key); ?>" <?php echo $key !== $first ? 'hidden' : ''; ?>>
                <p class="thean-product-status" role="status" data-product-status>Chọn công cụ để bắt đầu.</p>
                <p><button type="button" class="thean-ous__more-btn" data-product-reload="<?php echo esc_attr($key); ?>">↻ Tải lại công cụ</button> <a class="thean-ous__more-btn" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Mở riêng ↗</a></p>
                <iframe class="thean-product-frame" title="<?php echo esc_attr($tool['label']); ?>" data-src="<?php echo esc_url($url); ?>" allow="clipboard-write"></iframe>
            </section>
        <?php endforeach; ?>
    </section>
    <?php
}

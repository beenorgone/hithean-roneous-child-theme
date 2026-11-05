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

function thean_order_product_tool_url(string $tool): string
{
    if (!isset(thean_order_product_tools()[$tool])) return '';
    return wp_nonce_url(add_query_arg(['thean_order_tool' => $tool], home_url('/')), 'thean_order_product_tool');
}

/** Isolated document keeps the existing shortcodes' CSS, IDs and inline JS out of the order board. */
function thean_order_product_tool_response(): void
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
        wp_die('Không có quyền sử dụng công cụ sản phẩm.', '', ['response' => 403]);
    }
    check_admin_referer('thean_order_product_tool');
    $raw = $_GET['thean_order_tool'] ?? '';
    $key = is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';
    $tool = thean_order_product_tools()[$key] ?? null;
    if (!$tool) wp_die('Công cụ không hợp lệ.', '', ['response' => 400]);
    if (!shortcode_exists($tool['shortcode'])) {
        $base = dirname(__DIR__, 2);
        // Theanmarket: woocommerce/products; Hithean: shortcodes.
        foreach ([$base . '/woocommerce/products/', $base . '/shortcodes/'] as $dir) {
            if (is_file($dir . $tool['file'])) {
                require_once $dir . $tool['file'];
                break;
            }
        }
    }
    if (!shortcode_exists($tool['shortcode'])) wp_die('Công cụ chưa sẵn sàng.', '', ['response' => 503]);
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
        <?php wp_print_footer_scripts(); ?>
    </body>
    </html>
    <?php
    exit;
}

function thean_order_render_product_workspace(): void
{
    $tools = thean_order_product_tools();
    ?>
    <section class="thean-ous-products" id="thean-ous-products-panel" role="tabpanel" aria-labelledby="thean-workspace-products" hidden>
        <div class="thean-ous-board__head">
            <h2>Sản phẩm</h2>
            <button type="button" class="thean-ous__more-btn" data-thean-ous-workflow-guide="products" aria-haspopup="dialog" aria-controls="thean-ous-workflow-guide">📖 Hướng dẫn</button>
        </div>
        <div class="thean-workspace-tabs" role="tablist" aria-label="Công cụ sản phẩm">
            <?php foreach ($tools as $key => $tool) : ?>
                <button type="button" id="thean-product-tab-<?php echo esc_attr($key); ?>" role="tab" aria-controls="thean-product-<?php echo esc_attr($key); ?>" aria-selected="<?php echo $key === 'info' ? 'true' : 'false'; ?>" tabindex="<?php echo $key === 'info' ? '0' : '-1'; ?>" data-product-tool="<?php echo esc_attr($key); ?>"><?php echo esc_html($tool['label']); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($tools as $key => $tool) : $url = thean_order_product_tool_url($key); ?>
            <section id="thean-product-<?php echo esc_attr($key); ?>" role="tabpanel" aria-labelledby="thean-product-tab-<?php echo esc_attr($key); ?>" data-product-panel="<?php echo esc_attr($key); ?>" <?php echo $key !== 'info' ? 'hidden' : ''; ?>>
                <p class="thean-product-status" role="status" data-product-status>Chọn công cụ để bắt đầu.</p>
                <p><button type="button" class="thean-ous__more-btn" data-product-reload="<?php echo esc_attr($key); ?>">↻ Tải lại công cụ</button> <a class="thean-ous__more-btn" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Mở riêng ↗</a></p>
                <iframe class="thean-product-frame" title="<?php echo esc_attr($tool['label']); ?>" data-src="<?php echo esc_url($url); ?>" allow="clipboard-write"></iframe>
            </section>
        <?php endforeach; ?>
    </section>
    <?php
}

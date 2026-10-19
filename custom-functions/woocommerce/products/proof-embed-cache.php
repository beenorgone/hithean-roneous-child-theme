<?php
/**
 * Server-side cache for the IVAR proof widgets (Kiểm nghiệm + Hồ sơ pháp lý).
 *
 * The product meta holds the script snippet IVAR generates. Instead of letting
 * every visitor's browser run it (2 cross-site fetches per view, layout jump,
 * widget gone whenever ivarvietnam.com is slow, and broken by WP Rocket minify),
 * hithean fetches the rendered HTML here, keeps it in post meta and prints it
 * straight into the page inside a declarative shadow DOM — so it is part of the
 * WP Rocket page cache. The browser only loads IVAR's static CSS + binder JS.
 *
 * - Rendering never waits on HTTP: a missing copy falls back to the script
 *   snippet and schedules a fetch; a stale copy is still printed while it refreshes.
 * - A failed fetch keeps the last good copy (post meta, not a transient).
 * - Snippet edited in admin → its hash changes → the old copy is ignored.
 * - Cached pages never re-render, so a sweep refreshes stale copies on cron and
 *   purges the product page and affected ANC landing pages when HTML changes.
 *
 * Snippet parsing / allowlist: hithean_pcn_parse_proof_embed() in
 * product-navigation-settings.php.
 */

defined('ABSPATH') || exit;

const HITHEAN_PROOF_CACHE_META        = '_hithean_proof_cache';
const HITHEAN_PROOF_CACHE_REFRESH     = 'hithean_proof_cache_refresh';
const HITHEAN_PROOF_CACHE_SWEEP       = 'hithean_proof_cache_sweep';
const HITHEAN_PROOF_CACHE_RETRY       = 15 * MINUTE_IN_SECONDS;
const HITHEAN_PROOF_CACHE_SWEEP_LIMIT = 40;
const HITHEAN_PROOF_LEGAL_REST        = 'https://ivarvietnam.com/wp-json/ivar/v1/product-legal';

/** kind => product meta key holding the IVAR snippet. */
function hithean_proof_cache_meta_keys(): array
{
    return [
        'qc'    => 'product_info_kiem_nghiem',
        'legal' => 'product_info_ho_so_phap_ly',
    ];
}

function hithean_proof_cache_ttl(): int
{
    return max(5 * MINUTE_IN_SECONDS, (int) apply_filters('hithean_proof_cache_ttl', 6 * HOUR_IN_SECONDS));
}

/** Valid snippets of a product: kind => ['src', 'value', 'key']. */
function hithean_proof_cache_sources(int $product_id): array
{
    $sources = [];
    foreach (hithean_proof_cache_meta_keys() as $kind => $meta_key) {
        $embed = hithean_pcn_parse_proof_embed(get_post_meta($product_id, $meta_key, true), $kind);
        if ($embed) {
            $embed['key'] = md5($embed['src'] . '|' . $embed['value']);
            $sources[$kind] = $embed;
        }
    }

    return $sources;
}

function hithean_proof_cache_get(int $product_id): array
{
    $cache = get_post_meta($product_id, HITHEAN_PROOF_CACHE_META, true);

    return is_array($cache) ? $cache : [];
}

/** Only ever load IVAR assets from ivarvietnam.com over https. */
function hithean_proof_cache_ivar_url($url, string $fallback): string
{
    $url = is_string($url) ? $url : '';

    return preg_match('~^https://ivarvietnam\.com/[^\s"<>]*$~i', $url) ? $url : $fallback;
}

/**
 * Fetch one widget from IVAR. Returns the new cache entry, or null when IVAR
 * could not be reached / answered with an error (caller keeps the old copy).
 */
function hithean_proof_cache_fetch(string $kind, array $embed): ?array
{
    $url = $kind === 'qc'
        ? $embed['value']
        : add_query_arg('sku', rawurlencode($embed['value']), HITHEAN_PROOF_LEGAL_REST);

    $response = wp_remote_get($url, [
        'timeout'     => 5,
        'redirection' => 2,
        'headers'     => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
        return null;
    }

    $code  = (int) wp_remote_retrieve_response_code($response);
    $entry = ['status' => 'empty', 'key' => $embed['key'], 'checked_at' => time(), 'failed' => false];

    // 404 (legal: no public record) / 403 (qc: bad or expired signature, nothing
    // published) = nothing to show. IVAR answers both the same way on purpose.
    if ($code === 404 || $code === 403) {
        return $entry;
    }
    if ($code !== 200) {
        return null;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return null;
    }
    $html = isset($data['html']) && is_string($data['html']) ? trim($data['html']) : '';
    if ($html === '') {
        return $entry;
    }

    // Asset folder of the allowlisted embed.js — fallback when IVAR omits a URL.
    $dir = preg_replace('~[^/]+$~', '', $embed['src']);
    $css = $kind === 'qc' ? 'qc-widget.css' : 'legal-widget.css';
    $js  = $kind === 'qc' ? 'qc-widget.js' : 'legal-widget.js';

    // Lazyload plugins (WP Rocket here) rewrite <img> in the whole page, template
    // included, and their script cannot see into a shadow root.
    $html = preg_replace('~<img(?![^>]*\bdata-no-lazy=)~i', '<img data-no-lazy="1"', $html);

    return [
        'status'     => 'ok',
        'key'        => $embed['key'],
        'html'       => $html,
        'css'        => hithean_proof_cache_ivar_url($data['css'] ?? '', $dir . $css),
        'js'         => hithean_proof_cache_ivar_url($data['js'] ?? '', $dir . $js),
        'font'       => hithean_proof_cache_ivar_url($data['font'] ?? '', ''),
        'hash'       => md5($html),
        'checked_at' => time(),
        'failed'     => false,
    ];
}

/** Purge landing pages whose HTML source contains a card for this product. */
function hithean_proof_cache_purge_landings(int $product_id): void
{
    if (!function_exists('rocket_clean_post')) {
        return;
    }

    foreach (glob(get_stylesheet_directory() . '/pages/*/*.html') ?: [] as $file) {
        $source = file_get_contents($file);
        if (!is_string($source)
            || strpos($source, '[anc_product_card id="' . $product_id . '"') === false) {
            continue;
        }

        $page = get_page_by_path(basename($file, '.html'));
        if ($page instanceof WP_Post) {
            rocket_clean_post($page->ID);
        }
    }
}

/** Re-fetch every widget of a product; purge its page cache if the HTML changed. */
function hithean_proof_cache_refresh(int $product_id): void
{
    if (get_post_type($product_id) !== 'product') {
        return;
    }

    $sources = hithean_proof_cache_sources($product_id);
    $old     = hithean_proof_cache_get($product_id);
    $new     = [];

    foreach ($sources as $kind => $embed) {
        $entry = hithean_proof_cache_fetch($kind, $embed);
        if ($entry === null) {
            // Keep the last good copy of the same snippet; retry sooner.
            $previous = isset($old[$kind]) && ($old[$kind]['key'] ?? '') === $embed['key'] ? $old[$kind] : null;
            if ($previous === null) {
                continue;
            }
            $entry = array_merge($previous, ['checked_at' => time(), 'failed' => true]);
        }
        $new[$kind] = $entry;
    }

    if ($new) {
        update_post_meta($product_id, HITHEAN_PROOF_CACHE_META, $new);
    } else {
        delete_post_meta($product_id, HITHEAN_PROOF_CACHE_META);
    }

    $signature = static function (array $cache): string {
        $parts = [];
        foreach ($cache as $kind => $entry) {
            $parts[] = $kind . ':' . ($entry['status'] ?? '') . ':' . ($entry['hash'] ?? '') . ':' . ($entry['css'] ?? '') . ':' . ($entry['js'] ?? '');
        }
        sort($parts);

        return implode('|', $parts);
    };
    if ($signature($old) !== $signature($new)) {
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($product_id);
        }
        hithean_proof_cache_purge_landings($product_id);
    }
}
add_action(HITHEAN_PROOF_CACHE_REFRESH, static function ($product_id): void {
    hithean_proof_cache_refresh((int) $product_id);
});

function hithean_proof_cache_schedule(int $product_id): void
{
    if (!wp_next_scheduled(HITHEAN_PROOF_CACHE_REFRESH, [$product_id])) {
        wp_schedule_single_event(time(), HITHEAN_PROOF_CACHE_REFRESH, [$product_id]);
    }
}

function hithean_proof_cache_is_stale(array $entry): bool
{
    $age = time() - (int) ($entry['checked_at'] ?? 0);

    return $age > (!empty($entry['failed']) ? HITHEAN_PROOF_CACHE_RETRY : hithean_proof_cache_ttl());
}

/* ---------- Render ---------- */

/** Which IVAR binders / fonts the current page needs, filled while rendering. */
function hithean_proof_cache_page_assets(?array $add = null): array
{
    static $assets = ['js' => [], 'font' => []];
    if ($add) {
        foreach (['js', 'font'] as $type) {
            if (!empty($add[$type])) {
                $assets[$type][$add[$type]] = true;
            }
        }
    }

    return $assets;
}

/** Hithean skin for the IVAR popups (assets/proof-widget-skin.css), inlined per shadow root. */
function hithean_proof_cache_skin_css(): string
{
    static $css = null;
    if ($css === null) {
        $css = (string) @file_get_contents(__DIR__ . '/assets/proof-widget-skin.css');
        $css = trim((string) preg_replace(['~/\*.*?\*/~s', '~\s+~'], ['', ' '], $css));
    }

    return $css;
}

function hithean_proof_cache_host(string $kind, array $entry): string
{
    hithean_proof_cache_page_assets(['js' => $entry['js'], 'font' => $entry['font'] ?? '']);

    // Same host classes + inline styles as qc-embed.js / legal-embed.js, so the
    // existing CSS and product-navigation.js detection keep working.
    $class = $kind === 'qc' ? 'ivar-qc-widget-host' : 'ivar-legal-host';
    $style = 'display:inline-block;max-width:100%' . ($kind === 'qc' ? ';vertical-align:middle' : '');

    return '<span class="' . $class . '" data-hithean-proof="' . esc_attr($kind) . '" style="' . $style . '">'
        . '<template shadowrootmode="open"><link rel="stylesheet" href="' . esc_url($entry['css']) . '" data-no-minify="1">' // keep WP Rocket from copying it to hithean's cache
        . '<style>' . hithean_proof_cache_skin_css() . '</style>' // after IVAR's CSS: same specificity, later wins
        . $entry['html'] // phpcs:ignore -- rendered by IVAR, fetched over https from the allowlisted host.
        . '</template></span>';
}

/**
 * kind => markup for a product: cached HTML when available, the script snippet
 * otherwise. Nothing for a widget IVAR says is not public.
 */
function hithean_proof_cache_markup(int $product_id): array
{
    $sources = hithean_proof_cache_sources($product_id);
    if (!$sources) {
        return [];
    }

    $cache  = hithean_proof_cache_get($product_id);
    $out    = [];
    $stale  = false;
    foreach ($sources as $kind => $embed) {
        $entry = isset($cache[$kind]) && ($cache[$kind]['key'] ?? '') === $embed['key'] ? $cache[$kind] : null;
        if ($entry === null) {
            $stale = true;
            $out[$kind] = hithean_pcn_proof_embed_script(get_post_meta($product_id, hithean_proof_cache_meta_keys()[$kind], true), $kind);
            continue;
        }
        if (hithean_proof_cache_is_stale($entry)) {
            $stale = true;
        }
        if (($entry['status'] ?? '') === 'ok') {
            $out[$kind] = hithean_proof_cache_host($kind, $entry);
        }
    }
    if ($stale) {
        hithean_proof_cache_schedule($product_id);
    }

    return array_filter($out);
}

/**
 * Binders + fonts for cached widgets. Printed in the footer because the hosts
 * are rendered during the page body. nowprocket / data-no-minify: keep WP Rocket
 * from delaying (first tap would be lost) or copying them.
 */
add_action('wp_footer', static function (): void {
    $assets = hithean_proof_cache_page_assets();
    if (!$assets['js']) {
        return;
    }
    foreach (array_keys($assets['font']) as $font) {
        echo '<link rel="stylesheet" href="' . esc_url($font) . '" data-ivar-qc-font="' . esc_attr($font) . '">' . "\n";
    }
    foreach (array_keys($assets['js']) as $src) {
        echo '<script src="' . esc_url($src) . '" defer nowprocket data-no-minify="1"></script>' . "\n";
    }
    ?>
    <script nowprocket data-no-minify="1">
    (function () {
        function bind() {
            document.querySelectorAll('[data-hithean-proof]').forEach(function (host) {
                if (host.hasAttribute('data-hithean-proof-bound')) return;
                // Browsers without declarative shadow DOM leave the <template> in place.
                var tpl = host.querySelector(':scope > template');
                if (!host.shadowRoot && tpl) {
                    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
                    root.appendChild(tpl.content.cloneNode(true));
                    tpl.remove();
                }
                var scope = host.shadowRoot || host;
                var fn = host.getAttribute('data-hithean-proof') === 'qc' ? window.ivarQcWidgetBind : window.ivarLegalBind;
                if (typeof fn !== 'function') return;
                fn(scope);
                host.setAttribute('data-hithean-proof-bound', '1');
            });
        }
        // Binders are deferred: they run before DOMContentLoaded.
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
        else bind();
        window.addEventListener('load', bind);
    })();
    </script>
    <?php
}, 30);

/* ---------- Refresh triggers ---------- */

add_action('save_post_product', static function (int $product_id): void {
    if (wp_is_post_revision($product_id) || wp_is_post_autosave($product_id)) {
        return;
    }
    hithean_proof_cache_schedule($product_id);
});

/** Products whose page is cached never re-render: refresh stale copies on cron. */
add_action(HITHEAN_PROOF_CACHE_SWEEP, static function (): void {
    $keys  = array_values(hithean_proof_cache_meta_keys());
    $query = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'fields'                 => 'ids',
        'posts_per_page'         => -1,
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            'relation' => 'OR',
            ['key' => $keys[0], 'value' => '', 'compare' => '!='],
            ['key' => $keys[1], 'value' => '', 'compare' => '!='],
        ],
    ]);

    $done = 0;
    foreach ($query->posts as $product_id) {
        $product_id = (int) $product_id;
        $sources    = hithean_proof_cache_sources($product_id);
        if (!$sources) {
            continue;
        }
        $cache = hithean_proof_cache_get($product_id);
        $due   = false;
        foreach ($sources as $kind => $embed) {
            $entry = $cache[$kind] ?? null;
            if (!$entry || ($entry['key'] ?? '') !== $embed['key'] || hithean_proof_cache_is_stale($entry)) {
                $due = true;
                break;
            }
        }
        if (!$due) {
            continue;
        }
        hithean_proof_cache_refresh($product_id);
        if (++$done >= HITHEAN_PROOF_CACHE_SWEEP_LIMIT) {
            break;
        }
    }
});

add_action('init', static function (): void {
    if (!wp_next_scheduled(HITHEAN_PROOF_CACHE_SWEEP)) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', HITHEAN_PROOF_CACHE_SWEEP);
    }
});

/* ---------- Manual refresh (admin) ---------- */

function hithean_proof_cache_refresh_url(int $product_id): string
{
    return wp_nonce_url(admin_url('admin-post.php?action=hithean_proof_cache_refresh&product_id=' . $product_id), 'hithean_proof_cache_refresh_' . $product_id);
}

/** "Kiểm nghiệm: đã cập nhật · Hồ sơ pháp lý: …" — '' when nothing is cached. */
function hithean_proof_cache_status_text(int $product_id): string
{
    $parts = [];
    foreach (hithean_proof_cache_get($product_id) as $kind => $entry) {
        $label   = $kind === 'qc' ? 'Kiểm nghiệm' : 'Hồ sơ pháp lý';
        $state   = !empty($entry['failed']) ? 'lỗi kết nối, giữ bản cũ' : (($entry['status'] ?? '') === 'ok' ? 'đã cập nhật' : 'không có nội dung công khai');
        $parts[] = $label . ': ' . $state;
    }

    return implode(' · ', $parts);
}

add_filter('post_row_actions', static function (array $actions, WP_Post $post): array {
    if ($post->post_type !== 'product' || !current_user_can('edit_post', $post->ID) || !hithean_proof_cache_sources($post->ID)) {
        return $actions;
    }
    $actions['hithean_proof_cache'] = '<a href="' . esc_url(hithean_proof_cache_refresh_url($post->ID)) . '">Làm mới QC/pháp lý</a>';

    return $actions;
}, 10, 2);

/**
 * Meta Box custom_html callback, under the 2 snippet fields (product-metabox.php).
 * A link, not a button: a button would submit the post form. It refreshes from
 * the SAVED snippets, so unsaved edits there are not used (and are lost on leave).
 */
function hithean_proof_cache_metabox_html(): string
{
    $product_id = (int) get_the_ID();
    if (!$product_id || !current_user_can('edit_post', $product_id)) {
        return '';
    }
    if (!hithean_proof_cache_sources($product_id)) {
        return '<p class="description">Nút <strong>Làm mới QC/pháp lý</strong> hiện sau khi lưu sản phẩm với mã nhúng IVAR hợp lệ ở 2 ô trên.</p>';
    }

    $status = hithean_proof_cache_status_text($product_id);
    $times  = array_filter(array_map(static function (array $entry): int {
        return (int) ($entry['checked_at'] ?? 0);
    }, hithean_proof_cache_get($product_id)));
    if ($times) {
        $status .= ($status !== '' ? ' — ' : '') . 'lần lấy gần nhất ' . wp_date('H:i d/m/Y', max($times));
    }

    return '<p><a class="button" href="' . esc_url(hithean_proof_cache_refresh_url($product_id)) . '">Làm mới QC/pháp lý</a> '
        . '<span class="description">' . esc_html($status !== '' ? $status : 'Chưa có bản lưu sẵn, trang đang dùng mã nhúng.') . '</span></p>'
        . '<p class="description">Lấy lại nội dung từ ivarvietnam.com theo mã nhúng <strong>đã lưu</strong>. Sửa mã thì bấm Cập nhật trước.</p>';
}

add_action('admin_post_hithean_proof_cache_refresh', static function (): void {
    $product_id = absint($_GET['product_id'] ?? 0);
    check_admin_referer('hithean_proof_cache_refresh_' . $product_id);
    if (!$product_id || !current_user_can('edit_post', $product_id)) {
        wp_die('Không có quyền.', 403);
    }
    hithean_proof_cache_refresh($product_id);
    wp_safe_redirect(add_query_arg('hithean_proof_refreshed', $product_id, wp_get_referer() ?: admin_url('edit.php?post_type=product')));
    exit;
});

add_action('admin_notices', static function (): void {
    $product_id = absint($_GET['hithean_proof_refreshed'] ?? 0);
    if (!$product_id) {
        return;
    }
    $message = hithean_proof_cache_status_text($product_id) ?: 'Không lấy được dữ liệu từ ivarvietnam.com.';
    echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(get_the_title($product_id) . ' — ' . $message) . '</p></div>';
});

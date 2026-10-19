<?php
if (!defined('ABSPATH')) exit;

/*---------------------------------------*\
  LƯỢT BÁN — ADMIN (Cài đặt ERP → tab "Lượt bán") + AJAX bóc tách nguồn.
  Chỉ require từ sales-count.php (hithean_sc_require_admin) khi render tab,
  lưu tab hoặc gọi AJAX — không nạp trên front.

  Bóc tách 1 lần dán (textarea) hoặc 1 ảnh:
    - Mỗi dòng là link Shopee  → gọi API web Shopee lấy historical_sold.
      Shopee hay chặn request từ server: khi đó vẫn trả về dòng (count = null)
      để admin tự gõ số, link vẫn được giữ để so khớp store trong modal.
    - Link TikTok Shop         → không lấy tự động được (chống bot), chỉ giữ link
      + gắn sàn 'tiktok', admin gõ số hoặc dùng ảnh.
    - Ảnh chụp màn hình        → AI (feature sales_count_ai_extract).
    - Text copy từ Shopee/TikTok → AI; lỗi/tắt AI thì regex "Đã bán X".
  Mỗi nguồn gắn sàn (shopee | tiktok) để tách {shopee} / {tiktok} khi hiển thị.
  Kết quả chỉ là ứng viên: admin chọn, sửa số rồi mới thêm vào nhóm và Lưu.
\*---------------------------------------*/

const HITHEAN_SC_SOURCE_TYPES = ['link', 'image', 'text', 'manual'];

/*---------------------------------------*\
  LƯU
\*---------------------------------------*/

function hithean_sc_sanitize(array $input): array
{
    $defaults = hithean_sc_default_settings();
    $out      = ['display' => [], 'templates' => [], 'groups' => []];

    foreach (array_keys(hithean_sc_positions()) as $position) {
        $out['display'][$position]   = !empty($input['display'][$position]) ? '1' : '0';
        $template                    = sanitize_text_field((string) ($input['templates'][$position] ?? ''));
        $out['templates'][$position] = $template !== '' ? mb_substr($template, 0, 160) : $defaults['templates'][$position];
    }
    $out['min_count'] = absint($input['min_count'] ?? 0);

    $used_ids = [];
    foreach (array_slice((array) ($input['groups'] ?? []), 0, 50) as $group) {
        $name = sanitize_text_field((string) ($group['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $id = sanitize_title((string) ($group['id'] ?? '')) ?: sanitize_title($name);
        $base = $id;
        for ($i = 2; isset($used_ids[$id]); $i++) {
            $id = $base . '-' . $i;
        }
        $used_ids[$id] = true;

        $sources = [];
        foreach (array_slice((array) ($group['sources'] ?? []), 0, 100) as $source) {
            $type = in_array($source['type'] ?? '', HITHEAN_SC_SOURCE_TYPES, true) ? $source['type'] : 'manual';
            $ref  = (string) ($source['ref'] ?? '');
            $sources[] = [
                'type'     => $type,
                'platform' => hithean_sc_source_platform((array) $source),
                'label'   => mb_substr(sanitize_text_field((string) ($source['label'] ?? '')), 0, 120),
                'ref'     => $type === 'link' ? esc_url_raw($ref) : mb_substr(sanitize_text_field($ref), 0, 200),
                'count'   => absint($source['count'] ?? 0),
                'updated' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source['updated'] ?? '')) ? $source['updated'] : current_time('Y-m-d'),
            ];
        }

        $templates = [];
        foreach (array_keys(hithean_sc_positions()) as $position) {
            $own = sanitize_text_field((string) ($group['templates'][$position] ?? ''));
            if ($own !== '') {
                $templates[$position] = mb_substr($own, 0, 160);
            }
        }

        $out['groups'][] = [
            'id'          => $id,
            'templates'   => $templates,
            'name'        => mb_substr($name, 0, 120),
            'brand_term'  => absint($group['brand_term'] ?? 0),
            'product_ids' => array_values(array_unique(array_filter(array_map('absint', (array) ($group['product_ids'] ?? []))))),
            'include_web' => !empty($group['include_web']) ? '1' : '0',
            'sources'     => $sources,
        ];
    }

    return $out;
}

/** Gọi từ theme_erp_settings_render_page() khi POST tab "luot-ban" (nonce đã kiểm). */
function hithean_sc_save_from_post(): void
{
    $display   = (array) wp_unslash($_POST['hithean_sc_display'] ?? []);
    $templates = (array) wp_unslash($_POST['hithean_sc_templates'] ?? []);
    $groups    = json_decode((string) wp_unslash($_POST['hithean_sc_groups_json'] ?? '[]'), true);

    update_option(HITHEAN_SC_OPTION, hithean_sc_sanitize([
        'display'   => $display,
        'templates' => $templates,
        'min_count' => $_POST['hithean_sc_min_count'] ?? 0,
        'groups'    => is_array($groups) ? $groups : [],
    ]));
    delete_transient(HITHEAN_SC_TOTALS_CACHE);
}

/*---------------------------------------*\
  BÓC TÁCH
\*---------------------------------------*/

/** Link rút gọn (s.shopee.vn, shp.ee...) → link đầy đủ, theo tối đa 5 lần redirect. */
function hithean_sc_resolve_link(string $url): string
{
    for ($i = 0; $i < 5 && !hithean_sc_shopee_ids($url); $i++) {
        $response = wp_remote_head($url, ['timeout' => 10, 'redirection' => 0, 'user-agent' => 'Mozilla/5.0']);
        $location = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'location');
        if ($location === '') {
            break;
        }
        $url = WP_Http::make_absolute_url($location, $url);
    }

    return $url;
}

/** Tìm key đầu tiên (đệ quy) có giá trị số. */
function hithean_sc_find_number(array $data, string $key): ?int
{
    foreach ($data as $k => $v) {
        if ($k === $key && is_numeric($v)) {
            return (int) $v;
        }
        if (is_array($v)) {
            $found = hithean_sc_find_number($v, $key);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * @return array{type:string,label:string,ref:string,count:?int,note:string}
 */
function hithean_sc_extract_link(string $url): array
{
    $url  = hithean_sc_resolve_link(esc_url_raw($url));
    $item = ['type' => 'link', 'platform' => hithean_sc_link_platform($url), 'label' => '', 'ref' => $url, 'count' => null, 'note' => ''];
    $ids  = hithean_sc_shopee_ids($url);

    if ($item['platform'] === 'tiktok') {
        $item['note'] = 'TikTok Shop không cho lấy số tự động — gõ số "Đã bán", hoặc dán ảnh chụp trang sản phẩm.';
        return $item;
    }

    if (!$ids) {
        $item['note'] = 'Không nhận ra link sản phẩm Shopee — nhập số tay.';
        return $item;
    }

    [$shop_id, $item_id] = $ids;
    $endpoints = [
        'https://shopee.vn/api/v4/item/get?itemid=' . $item_id . '&shopid=' . $shop_id,
        'https://shopee.vn/api/v4/pdp/get_pc?item_id=' . $item_id . '&shop_id=' . $shop_id,
    ];

    foreach ($endpoints as $endpoint) {
        $response = wp_remote_get($endpoint, [
            'timeout' => 12,
            'headers' => [
                'User-Agent'         => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
                'Referer'            => $url,
                'Accept'             => 'application/json',
                'X-API-SOURCE'       => 'pc',
                'X-Shopee-Language'  => 'vi',
                'X-Requested-With'   => 'XMLHttpRequest',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            continue;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            continue;
        }

        $sold = hithean_sc_find_number($data, 'historical_sold') ?? hithean_sc_find_number($data, 'global_sold');
        if ($sold !== null) {
            $item['count'] = $sold;
            $name          = $data['data']['name'] ?? $data['data']['item']['title'] ?? $data['data']['item']['name'] ?? '';
            $item['label'] = is_string($name) ? mb_substr(sanitize_text_field($name), 0, 120) : '';
            return $item;
        }
    }

    $item['note'] = 'Shopee chặn lấy số tự động — gõ số "Đã bán" vào ô, hoặc dán ảnh chụp trang sản phẩm.';
    return $item;
}

/** Regex "Đã bán 2,3k" / "2.3k sold" trong text — fallback khi không dùng được AI. */
function hithean_sc_extract_text_regex(string $text): array
{
    $items = [];
    $lines = preg_split('/\R/u', $text);
    foreach ($lines as $index => $line) {
        if (!preg_match_all('/(?:đã\s*bán|da\s*ban|sold)\s*:?\s*([\d][\d.,]*\s*(?:k|tr|triệu|nghìn|ngàn|m)?\+?)|([\d][\d.,]*\s*(?:k|tr|triệu|nghìn|ngàn|m)?\+?)\s*(?:đã\s*bán|sold)/iu', $line, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $m) {
            $raw   = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
            $count = hithean_sc_parse_number($raw);
            if ($count === null) {
                continue;
            }
            // Nhãn: dòng chữ gần nhất phía trên (thường là tên sản phẩm).
            $label = '';
            for ($j = $index - 1; $j >= 0 && $label === ''; $j--) {
                $candidate = trim($lines[$j]);
                if (mb_strlen($candidate) > 8 && !preg_match('/^[\d\s.,₫đ%-]+$/u', $candidate) && !preg_match('/đã\s*bán|da\s*ban|sold/iu', $candidate)) {
                    $label = $candidate;
                }
            }
            $items[] = ['type' => 'text', 'platform' => '', 'label' => mb_substr($label, 0, 120), 'ref' => $raw, 'count' => $count, 'note' => ''];
        }
    }

    return $items;
}

function hithean_sc_ai_config(): array
{
    require_once HITHEAN_THEME_DIR . '/custom-functions/core/ai-settings.php';
    $provider = theme_ai_default_provider();
    if (defined('SALES_COUNT_AI_PROVIDER') && SALES_COUNT_AI_PROVIDER) {
        $provider = (string) SALES_COUNT_AI_PROVIDER;
    }

    return apply_filters('hithean_sc_ai_config', [
        'provider' => $provider === 'auto' ? 'gemini' : $provider,
        'model'    => defined('SALES_COUNT_AI_MODEL') ? (string) SALES_COUNT_AI_MODEL : theme_ai_default_model(),
        'pinned'   => defined('SALES_COUNT_AI_PROVIDER') && SALES_COUNT_AI_PROVIDER,
    ]);
}

/**
 * AI đọc ảnh/text → danh sách ứng viên.
 *
 * @param array|null $image ['path' => tmp file, 'mime_type' => ...]
 * @return array|WP_Error
 */
function hithean_sc_extract_ai(string $text, ?array $image)
{
    require_once HITHEAN_THEME_DIR . '/custom-functions/core/ai-settings.php';
    if (!theme_ai_feature_enabled('sales_count_ai_extract')) {
        return new WP_Error('hithean_sc_ai_disabled', 'Tính năng AI "Lượt bán" đang tắt (Cài đặt ERP → AI).');
    }
    require_once HITHEAN_THEME_DIR . '/custom-functions/core/ai-providers.php';

    $system = 'Bạn trích xuất số lượt bán từ ảnh chụp hoặc text copy trên sàn TMĐT (Shopee, TikTok Shop). '
        . 'Tìm mọi chỉ số "Đã bán", "sold", "đã bán/tháng"... gắn với từng sản phẩm hoặc phân loại. '
        . 'Chỉ lấy số nhìn/đọc thấy rõ, không suy đoán, không cộng dồn. Bỏ qua số lượt đánh giá, lượt thích, tồn kho, giá. '
        . 'Nếu số rút gọn (k = nghìn, tr = triệu, có dấu +) thì giữ nguyên văn ở sold_text và quy đổi ra số nguyên ở sold. '
        . 'platform = "tiktok" nếu giao diện/nội dung là TikTok Shop, "shopee" nếu là Shopee, "" nếu không rõ. '
        . 'Trả về DUY NHẤT JSON: {"items":[{"name":"tên sản phẩm/shop ngắn gọn","platform":"shopee","sold_text":"2,3k","sold":2300}]}. '
        . 'Không tìm thấy thì trả {"items":[]}.';
    $prompt = $image ? 'Đọc số lượt bán trong ảnh.' . ($text !== '' ? "\nGhi chú kèm theo: " . $text : '') : "Text cần đọc:\n" . mb_substr($text, 0, 12000);
    $documents = $image ? [$image] : [];

    $raw = theme_ai_feature_call(hithean_sc_ai_config(), static function (string $provider, string $model, string $api_key) use ($system, $prompt, $documents) {
        return $documents
            ? theme_ai_call_provider_with_documents($provider, $system, $prompt, $documents, 1500, 90, $model, ['api_key' => $api_key])
            : theme_ai_call_provider($provider, $system, [['role' => 'user', 'content' => $prompt]], 1500, $model, ['api_key' => $api_key]);
    });
    if (is_wp_error($raw)) {
        return $raw;
    }

    $data = theme_ai_parse_json_object($raw);
    if (is_wp_error($data)) {
        return $data;
    }

    $items = [];
    foreach ((array) ($data['items'] ?? []) as $row) {
        $sold_text = sanitize_text_field((string) ($row['sold_text'] ?? ''));
        // Ưu tiên tự quy đổi từ nguyên văn — không tin phép tính của model.
        $count = $sold_text !== '' ? hithean_sc_parse_number($sold_text) : null;
        $count = $count ?? (isset($row['sold']) && is_numeric($row['sold']) ? (int) $row['sold'] : null);
        if ($count === null) {
            continue;
        }
        $platform = strtolower((string) ($row['platform'] ?? ''));
        $items[]  = [
            'type'     => $image ? 'image' : 'text',
            'platform' => in_array($platform, ['shopee', 'tiktok'], true) ? $platform : '',
            'label' => mb_substr(sanitize_text_field((string) ($row['name'] ?? '')), 0, 120),
            'ref'   => $sold_text,
            'count' => $count,
            'note'  => '',
        ];
    }

    return $items;
}

function hithean_sc_ajax_extract(): void
{
    if (!current_user_can('manage_options') || !check_ajax_referer('hithean_sc_extract', 'nonce', false)) {
        wp_send_json_error(['message' => 'Không có quyền hoặc phiên đã hết hạn, tải lại trang.'], 403);
    }

    $text  = trim(sanitize_textarea_field(wp_unslash($_POST['text'] ?? '')));
    $image = null;

    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $file = $_FILES['image'];
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || (int) $file['size'] > 8 * MB_IN_BYTES) {
            wp_send_json_error(['message' => 'Ảnh phải là JPG/PNG/WebP/GIF, tối đa 8MB.']);
        }
        $image = ['path' => $file['tmp_name'], 'mime_type' => $mime, 'title' => 'shopee-screenshot'];
    }

    if ($image === null && $text === '') {
        wp_send_json_error(['message' => 'Dán link / text hoặc chọn ảnh trước khi bóc tách.']);
    }

    // Mọi dòng đều là URL → chế độ link.
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text))));
    if ($image === null && $lines && count(array_filter($lines, static fn($l) => (bool) preg_match('#^https?://\S+$#i', $l))) === count($lines)) {
        $items = array_map('hithean_sc_extract_link', array_slice($lines, 0, 20));
        wp_send_json_success(['items' => $items]);
    }

    $items = hithean_sc_extract_ai($text, $image);
    if (is_wp_error($items)) {
        if ($image !== null) {
            wp_send_json_error(['message' => 'AI không đọc được ảnh: ' . $items->get_error_message()]);
        }
        $fallback = hithean_sc_extract_text_regex($text);
        wp_send_json_success(['items' => $fallback, 'notice' => 'AI lỗi (' . $items->get_error_message() . ') — đã dùng bộ lọc "Đã bán X" thay thế.']);
    }

    if (!$items && $image === null) {
        $items = hithean_sc_extract_text_regex($text);
    }

    wp_send_json_success(['items' => $items, 'notice' => $items ? '' : 'Không tìm thấy số "Đã bán" nào.']);
}

/*---------------------------------------*\
  TAB SETTINGS
\*---------------------------------------*/

function hithean_sc_render_settings_tab(): void
{
    $settings = hithean_sc_settings();
    $totals   = hithean_sc_group_totals();

    $products = array_map(static function (WP_Post $post): array {
        return ['id' => $post->ID, 'name' => $post->post_title . ($post->post_status === 'private' ? ' (riêng tư)' : '')];
    }, get_posts([
        'post_type'      => 'product',
        'post_status'    => ['publish', 'private'],
        'posts_per_page' => 1000,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]));

    $brands = get_terms(['taxonomy' => HITHEAN_SC_BRAND_TAXONOMY, 'hide_empty' => false]);
    $brands = is_wp_error($brands) ? [] : array_map(static fn($t) => ['id' => (int) $t->term_id, 'name' => $t->name], $brands);
    ?>
    <form method="post" id="hithean-sc-form">
        <?php wp_nonce_field('theme_erp_settings_save_luot-ban'); ?>
        <input type="hidden" name="hithean_sc_groups_json" id="hithean-sc-groups-json" value="">

        <h2>Hiển thị</h2>
        <p class="description" style="max-width:760px;">Lượt bán của sản phẩm = tổng lượt bán của <strong>nhãn hiệu</strong> chứa nó (nguồn Shopee + TikTok Shop + lượt bán website nếu bật). Câu hiển thị dùng biến <code>{count}</code>, <code>{brand}</code>, <code>{shopee}</code>, <code>{tiktok}</code>, <code>{san}</code> (Shopee + TikTok), <code>{web}</code>. Shortcode <code>[hithean_sales_count]</code> in được ở bất kỳ đâu — xem Shortcodes Guide.</p>
        <table class="form-table" role="presentation">
            <?php foreach (hithean_sc_positions() as $position => $label) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td>
                        <label style="margin-right:12px;"><input type="checkbox" name="hithean_sc_display[<?php echo esc_attr($position); ?>]" value="1" <?php checked(!empty($settings['display'][$position])); ?>> Bật</label>
                        <input type="text" class="regular-text" name="hithean_sc_templates[<?php echo esc_attr($position); ?>]" value="<?php echo esc_attr((string) $settings['templates'][$position]); ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><label for="hithean-sc-min">Chỉ hiện khi tổng ≥</label></th>
                <td><input type="number" min="0" id="hithean-sc-min" name="hithean_sc_min_count" value="<?php echo esc_attr((string) absint($settings['min_count'])); ?>" style="width:120px;"> <span class="description">0 = luôn hiện (khi có số).</span></td>
            </tr>
        </table>

        <h2>Nhãn hiệu</h2>
        <p class="description" style="max-width:760px;">Mỗi nhãn hiệu gồm các sản phẩm chọn tay và/hoặc toàn bộ sản phẩm của 1 thương hiệu. Sản phẩm nằm ở nhiều nhãn hiệu thì lấy nhãn hiệu đầu tiên. Thêm nguồn Shopee/TikTok bằng cách dán link (mỗi dòng 1 link), dán text copy từ sàn, hoặc chọn/dán ảnh chụp màn hình → <em>Bóc tách</em> → tích các dòng cần lấy → <em>Thêm vào nhãn hiệu</em> → <em>Lưu</em>.</p>
        <div id="hithean-sc-groups"></div>
        <p><button type="button" class="button" id="hithean-sc-add-group">+ Thêm nhãn hiệu</button></p>

        <?php submit_button('Lưu cài đặt'); ?>
    </form>

    <style>
    .hithean-sc-group { max-width:1100px; margin:0 0 16px; padding:16px; background:#fff; border:1px solid #dcdcde; }
    .hithean-sc-group__head { display:grid; grid-template-columns:2fr 1fr 1fr auto; gap:12px; align-items:end; }
    .hithean-sc-group label > span { display:block; margin-bottom:4px; font-weight:600; }
    .hithean-sc-group input[type=text], .hithean-sc-group select { width:100%; }
    .hithean-sc-group__products { margin-top:12px; }
    .hithean-sc-group__products select { height:140px; }
    .hithean-sc-total { margin:12px 0; padding:8px 12px; background:#f6f7f7; }
    .hithean-sc-total strong { color:#ee4d2d; font-size:15px; }
    .hithean-sc-sources td, .hithean-sc-sources th { padding:6px 8px; vertical-align:middle; }
    .hithean-sc-sources input[type=number] { width:110px; }
    .hithean-sc-type { display:inline-block; padding:1px 8px; border-radius:10px; background:#f0f0f1; font-size:11px; }
    .hithean-sc-type--link { background:#fde8e3; color:#b4381c; }
    .hithean-sc-type--image { background:#e5f0fa; color:#135e96; }
    .hithean-sc-type--text { background:#edfaef; color:#1a7431; }
    .hithean-sc-extract { margin-top:12px; padding:12px; border:1px dashed #c3c4c7; }
    .hithean-sc-extract textarea { width:100%; min-height:70px; }
    .hithean-sc-extract__bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:8px; }
    .hithean-sc-extract__msg { color:#646970; }
    .hithean-sc-extract__msg.is-error { color:#b32d2e; }
    .hithean-sc-note { color:#b32d2e; font-size:12px; }
    .hithean-sc-tpl { margin:0 0 12px; }
    .hithean-sc-tpl summary { cursor:pointer; font-weight:600; }
    .hithean-sc-tpl > label { display:block; max-width:720px; margin-top:8px; }
    .hithean-sc-tpl__preview { margin-top:2px; color:#646970; font-size:12px; }
    @media (max-width:782px) { .hithean-sc-group__head { grid-template-columns:1fr; } }
    </style>

    <script>
    (function () {
        var form = document.getElementById('hithean-sc-form');
        var wrap = document.getElementById('hithean-sc-groups');
        var state = <?php echo wp_json_encode(array_values($settings['groups'])); ?>;
        var totals = <?php echo wp_json_encode($totals); ?>;
        var products = <?php echo wp_json_encode($products); ?>;
        var brands = <?php echo wp_json_encode($brands); ?>;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('hithean_sc_extract')); ?>;
        var typeLabels = {link: 'Link', image: 'Ảnh', text: 'Text', manual: 'Nhập tay'};
        var positions = <?php echo wp_json_encode(hithean_sc_positions()); ?>;
        var globalTpl = {};
        var tplUpdaters = []; // cập nhật dòng xem trước câu riêng khi câu chung đổi
        Object.keys(positions).forEach(function (key) {
            var input = form.querySelector('[name="hithean_sc_templates[' + key + ']"]');
            globalTpl[key] = input ? input.value : '';
            if (input) input.addEventListener('input', function () {
                globalTpl[key] = input.value;
                tplUpdaters.forEach(function (fn) { fn(); });
            });
        });
        var today = new Date().toISOString().slice(0, 10);

        function el(tag, attrs, text) {
            var node = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (k) {
                if (k === 'class') node.className = attrs[k];
                else if (k in node && typeof attrs[k] !== 'string') node[k] = attrs[k];
                else node.setAttribute(k, attrs[k]);
            });
            if (text !== undefined) node.textContent = text;
            return node;
        }
        function labeled(text, control) {
            var l = el('label');
            l.appendChild(el('span', {}, text));
            l.appendChild(control);
            return l;
        }
        function fmt(n) {
            return Number(n || 0).toLocaleString('vi-VN');
        }
        // Giống hithean_sc_format_count(): 4812 → "4,8k" (làm tròn xuống).
        function short(n) {
            n = parseInt(n, 10) || 0;
            if (n < 1000) return String(n);
            var base = n >= 1e6 ? 1e6 : 1e3;
            return String(Math.floor(n / base * 10) / 10).replace('.', ',') + (n >= 1e6 ? 'tr' : 'k');
        }
        function groupTotal(group) {
            return platformSum(group, '') + (group.include_web === '1' ? ((totals[group.id] || {}).web || 0) : 0);
        }
        function previewLine(group, tpl) {
            var web = group.include_web === '1' ? ((totals[group.id] || {}).web || 0) : 0;
            return tpl.replace(/\{count\}/g, short(groupTotal(group))).replace(/\{brand\}/g, group.name || '')
                .replace(/\{shopee\}/g, short(platformSum(group, 'shopee'))).replace(/\{tiktok\}/g, short(platformSum(group, 'tiktok')))
                .replace(/\{san\}/g, short(platformSum(group, ''))).replace(/\{web\}/g, short(web));
        }
        // platform '' = cả 2 sàn.
        function platformSum(group, platform) {
            return (group.sources || []).reduce(function (sum, src) {
                var p = src.platform === 'tiktok' ? 'tiktok' : 'shopee';
                return sum + (!platform || p === platform ? (parseInt(src.count, 10) || 0) : 0);
            }, 0);
        }
        function platformSelect(value, onChange) {
            var select = el('select');
            [['shopee', 'Shopee'], ['tiktok', 'TikTok']].forEach(function (o) {
                var opt = el('option', {value: o[0]}, o[1]);
                if (value === o[0]) opt.selected = true;
                select.appendChild(opt);
            });
            select.addEventListener('change', function () { onChange(select.value); });
            return select;
        }

        function renderTotal(group, box) {
            var web = (totals[group.id] || {}).web || 0;
            var ecom = platformSum(group, '');
            box.innerHTML = '';
            box.appendChild(document.createTextNode('Shopee: ' + fmt(platformSum(group, 'shopee')) + ' + TikTok: ' + fmt(platformSum(group, 'tiktok'))));
            if (group.include_web === '1') {
                box.appendChild(document.createTextNode(' + Website: ' + fmt(web) + (group.id ? '' : ' (tính sau khi lưu)')));
            }
            box.appendChild(document.createTextNode(' = '));
            box.appendChild(el('strong', {}, fmt(ecom + (group.include_web === '1' ? web : 0))));
            if (box._onTotal) box._onTotal();
        }

        function renderTemplates(group, totalBox) {
            group.templates = group.templates && !Array.isArray(group.templates) ? group.templates : {};
            var details = el('details', {class: 'hithean-sc-tpl'});
            var own = Object.keys(group.templates).filter(function (k) { return group.templates[k]; }).length;
            details.appendChild(el('summary', {}, 'Câu hiển thị riêng cho nhãn hiệu này' + (own ? ' (' + own + ' vị trí)' : '')));
            details.appendChild(el('p', {class: 'description'}, 'Bỏ trống = dùng câu chung ở mục Hiển thị. Biến: {count} {brand} {shopee} {tiktok} {san} (Shopee + TikTok) {web}. VD: {brand} đã bán {count} trên Shopee/TikTok 3 tháng gần nhất (đại lý)'));
            var previews = [];
            Object.keys(positions).forEach(function (key) {
                var input = el('input', {type: 'text', value: group.templates[key] || '', placeholder: globalTpl[key]});
                var out = el('div', {class: 'hithean-sc-tpl__preview'});
                function update() {
                    input.placeholder = globalTpl[key];
                    out.textContent = '→ ' + previewLine(group, input.value || globalTpl[key]);
                }
                input.addEventListener('input', function () { group.templates[key] = input.value; update(); });
                previews.push(update);
                update();
                var row = labeled(positions[key], input);
                row.appendChild(out);
                details.appendChild(row);
            });
            totalBox._onTotal = function () { previews.forEach(function (fn) { fn(); }); };
            tplUpdaters.push(totalBox._onTotal);
            return details;
        }

        function renderSources(group, tbody, totalBox) {
            tbody.innerHTML = '';
            if (!group.sources.length) {
                var empty = el('tr');
                empty.appendChild(el('td', {colspan: '7'}, 'Chưa có nguồn nào.'));
                tbody.appendChild(empty);
            }
            group.sources.forEach(function (src, i) {
                var tr = el('tr');
                var type = el('td');
                type.appendChild(el('span', {class: 'hithean-sc-type hithean-sc-type--' + src.type}, typeLabels[src.type] || src.type));
                tr.appendChild(type);

                src.platform = src.platform === 'tiktok' ? 'tiktok' : 'shopee';
                var platformTd = el('td');
                platformTd.appendChild(platformSelect(src.platform, function (v) {
                    src.platform = v;
                    renderTotal(group, totalBox);
                }));
                tr.appendChild(platformTd);

                var label = el('input', {type: 'text', value: src.label || '', placeholder: 'Tên store / ghi chú'});
                label.addEventListener('input', function () { src.label = label.value; });
                var labelTd = el('td');
                labelTd.appendChild(label);
                tr.appendChild(labelTd);

                var refTd = el('td');
                if (src.type === 'link' && src.ref) {
                    var a = el('a', {href: src.ref, target: '_blank', rel: 'noopener'}, src.ref.replace(/^https?:\/\/(www\.)?/, '').slice(0, 48) + (src.ref.length > 60 ? '…' : ''));
                    refTd.appendChild(a);
                } else {
                    refTd.textContent = src.ref || '—';
                }
                if (src.note) refTd.appendChild(el('div', {class: 'hithean-sc-note'}, src.note));
                tr.appendChild(refTd);

                var count = el('input', {type: 'number', min: '0', value: src.count == null ? '' : src.count, placeholder: 'Đã bán'});
                count.addEventListener('input', function () {
                    src.count = count.value === '' ? 0 : parseInt(count.value, 10) || 0;
                    src.updated = today;
                    renderTotal(group, totalBox);
                });
                var countTd = el('td');
                countTd.appendChild(count);
                tr.appendChild(countTd);

                tr.appendChild(el('td', {}, src.updated || ''));

                var actions = el('td');
                if (src.type === 'link' && src.ref) {
                    var refresh = el('button', {type: 'button', class: 'button button-small'}, 'Làm mới');
                    refresh.addEventListener('click', function () {
                        refresh.disabled = true;
                        refresh.textContent = 'Đang lấy…';
                        extract({text: src.ref}).then(function (res) {
                            var item = (res.items || [])[0];
                            if (item && item.count != null) {
                                src.count = item.count;
                                src.updated = today;
                                src.note = '';
                            } else {
                                src.note = item && item.note ? item.note : 'Không lấy được số.';
                            }
                        }).catch(function (err) {
                            src.note = err.message;
                        }).then(function () {
                            renderSources(group, tbody, totalBox);
                            renderTotal(group, totalBox);
                        });
                    });
                    actions.appendChild(refresh);
                    actions.appendChild(document.createTextNode(' '));
                }
                var remove = el('button', {type: 'button', class: 'button-link-delete'}, 'Xóa');
                remove.addEventListener('click', function () {
                    group.sources.splice(i, 1);
                    renderSources(group, tbody, totalBox);
                    renderTotal(group, totalBox);
                });
                actions.appendChild(remove);
                tr.appendChild(actions);
                tbody.appendChild(tr);
            });
        }

        function extract(payload) {
            var body = new FormData();
            body.append('action', 'hithean_sc_extract');
            body.append('nonce', nonce);
            body.append('text', payload.text || '');
            if (payload.image) body.append('image', payload.image, payload.image.name || 'paste.png');
            return fetch(ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function (r) { return r.json().catch(function () { throw new Error('Máy chủ trả về lỗi (HTTP ' + r.status + ').'); }); })
                .then(function (json) {
                    if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Bóc tách thất bại.');
                    return json.data;
                });
        }

        function renderExtractor(group, tbody, totalBox) {
            var box = el('div', {class: 'hithean-sc-extract'});
            box.appendChild(el('strong', {}, 'Thêm nguồn Shopee/TikTok'));
            var textarea = el('textarea', {placeholder: 'Dán link Shopee/TikTok (mỗi dòng 1 link), text copy từ trang Shopee/TikTok, hoặc dán (Ctrl+V) ảnh chụp màn hình vào đây'});
            var file = el('input', {type: 'file', accept: 'image/*'});
            var pasted = null;
            var go = el('button', {type: 'button', class: 'button button-primary'}, 'Bóc tách');
            var manual = el('button', {type: 'button', class: 'button'}, '+ Nhập tay');
            // Sàn gán cho dòng AI/regex không nhận ra sàn, và cho dòng nhập tay.
            var defaultPlatform = platformSelect('shopee', function () {});
            defaultPlatform.title = 'Sàn mặc định';
            var msg = el('span', {class: 'hithean-sc-extract__msg'});
            var results = el('div');

            textarea.addEventListener('paste', function (e) {
                var items = (e.clipboardData && e.clipboardData.items) || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image/') === 0) {
                        pasted = items[i].getAsFile();
                        msg.className = 'hithean-sc-extract__msg';
                        msg.textContent = '🖼️ Đã nhận ảnh dán từ clipboard (' + Math.round(pasted.size / 1024) + ' KB).';
                        e.preventDefault();
                        return;
                    }
                }
            });
            file.addEventListener('change', function () { pasted = null; });

            manual.addEventListener('click', function () {
                group.sources.push({type: 'manual', platform: defaultPlatform.value, label: '', ref: '', count: 0, updated: today});
                renderSources(group, tbody, totalBox);
            });

            go.addEventListener('click', function () {
                var image = pasted || (file.files && file.files[0]) || null;
                go.disabled = true;
                msg.className = 'hithean-sc-extract__msg';
                msg.textContent = image ? 'AI đang đọc ảnh…' : 'Đang bóc tách…';
                results.innerHTML = '';
                extract({text: textarea.value, image: image}).then(function (data) {
                    var items = data.items || [];
                    msg.textContent = data.notice || ('Tìm thấy ' + items.length + ' dòng — kiểm tra lại số rồi thêm vào nhãn hiệu.');
                    if (!items.length) return;
                    var table = el('table', {class: 'widefat striped hithean-sc-sources', style: 'margin-top:8px'});
                    var tb = el('tbody');
                    items.forEach(function (item) {
                        var tr = el('tr');
                        var pick = el('input', {type: 'checkbox', checked: true});
                        var platform = item.platform || defaultPlatform.value;
                        var platformPick = platformSelect(platform, function (v) { platform = v; });
                        var label = el('input', {type: 'text', value: item.label || ''});
                        var count = el('input', {type: 'number', min: '0', value: item.count == null ? '' : item.count, placeholder: 'Gõ số'});
                        [pick, platformPick, label].forEach(function (c) { var td = el('td'); td.appendChild(c); tr.appendChild(td); });
                        var ref = el('td', {}, item.type === 'link' ? item.ref : (item.ref || ''));
                        if (item.note) ref.appendChild(el('div', {class: 'hithean-sc-note'}, item.note));
                        tr.appendChild(ref);
                        var countTd = el('td');
                        countTd.appendChild(count);
                        tr.appendChild(countTd);
                        tr._read = function () {
                            return pick.checked ? {type: item.type, platform: platform, label: label.value, ref: item.ref || '', count: parseInt(count.value, 10) || 0, updated: today} : null;
                        };
                        tb.appendChild(tr);
                    });
                    table.appendChild(tb);
                    var add = el('button', {type: 'button', class: 'button button-primary', style: 'margin-top:8px'}, 'Thêm vào nhãn hiệu');
                    add.addEventListener('click', function () {
                        Array.prototype.forEach.call(tb.children, function (tr) {
                            var src = tr._read();
                            if (src) group.sources.push(src);
                        });
                        results.innerHTML = '';
                        textarea.value = '';
                        file.value = '';
                        pasted = null;
                        msg.textContent = 'Đã thêm — nhớ bấm Lưu cài đặt.';
                        renderSources(group, tbody, totalBox);
                        renderTotal(group, totalBox);
                    });
                    results.appendChild(table);
                    results.appendChild(add);
                }).catch(function (err) {
                    msg.className = 'hithean-sc-extract__msg is-error';
                    msg.textContent = err.message;
                }).then(function () {
                    go.disabled = false;
                });
            });

            box.appendChild(textarea);
            var bar = el('div', {class: 'hithean-sc-extract__bar'});
            var defaultLabel = el('label', {}, 'Sàn mặc định: ');
            defaultLabel.appendChild(defaultPlatform);
            [file, defaultLabel, go, manual, msg].forEach(function (n) { bar.appendChild(n); });
            box.appendChild(bar);
            box.appendChild(results);
            return box;
        }

        function renderGroup(group, index) {
            group.sources = group.sources || [];
            group.product_ids = (group.product_ids || []).map(String);
            var card = el('div', {class: 'hithean-sc-group'});
            var head = el('div', {class: 'hithean-sc-group__head'});

            var name = el('input', {type: 'text', value: group.name || '', placeholder: 'VD: Yeast Hero Protein'});
            name.addEventListener('input', function () {
                group.name = name.value;
                if (totalBox._onTotal) totalBox._onTotal();
            });
            head.appendChild(labeled('Tên nhãn hiệu', name));

            var slug = el('input', {type: 'text', value: group.id || '', placeholder: 'tự tạo từ tên'});
            slug.addEventListener('input', function () { group.id = slug.value; });
            head.appendChild(labeled('Slug (shortcode group="")', slug));

            var brand = el('select');
            brand.appendChild(el('option', {value: '0'}, '— Không —'));
            brands.forEach(function (b) {
                var o = el('option', {value: String(b.id)}, b.name);
                if (String(group.brand_term) === String(b.id)) o.selected = true;
                brand.appendChild(o);
            });
            brand.addEventListener('change', function () { group.brand_term = brand.value; });
            head.appendChild(labeled('Gồm cả SP thương hiệu', brand));

            var remove = el('button', {type: 'button', class: 'button-link-delete'}, 'Xóa nhãn hiệu');
            remove.addEventListener('click', function () {
                if (!window.confirm('Xóa nhãn hiệu "' + (group.name || '') + '" và toàn bộ nguồn?')) return;
                state.splice(index, 1);
                render();
            });
            head.appendChild(remove);
            card.appendChild(head);

            var productsWrap = el('div', {class: 'hithean-sc-group__products'});
            var filter = el('input', {type: 'search', placeholder: 'Lọc sản phẩm…', style: 'width:100%;margin-bottom:4px'});
            var select = el('select', {multiple: true});
            products.forEach(function (p) {
                var o = el('option', {value: String(p.id)}, p.name + ' (#' + p.id + ')');
                if (group.product_ids.indexOf(String(p.id)) !== -1) o.selected = true;
                select.appendChild(o);
            });
            var picked = el('p', {class: 'description'});
            function syncPicked() {
                group.product_ids = Array.prototype.filter.call(select.options, function (o) { return o.selected; }).map(function (o) { return o.value; });
                picked.textContent = 'Đã chọn ' + group.product_ids.length + ' sản phẩm (giữ Ctrl/Cmd để chọn nhiều).';
            }
            select.addEventListener('change', syncPicked);
            filter.addEventListener('input', function () {
                var q = filter.value.toLowerCase();
                Array.prototype.forEach.call(select.options, function (o) { o.hidden = q && o.textContent.toLowerCase().indexOf(q) === -1; });
            });
            syncPicked();
            productsWrap.appendChild(el('strong', {}, 'Sản phẩm trong nhãn hiệu'));
            productsWrap.appendChild(filter);
            productsWrap.appendChild(select);
            productsWrap.appendChild(picked);
            card.appendChild(productsWrap);

            var totalBox = el('div', {class: 'hithean-sc-total'});
            var web = el('input', {type: 'checkbox', checked: group.include_web === '1'});
            web.addEventListener('change', function () {
                group.include_web = web.checked ? '1' : '0';
                renderTotal(group, totalBox);
            });
            var webLabel = el('label');
            webLabel.appendChild(web);
            webLabel.appendChild(document.createTextNode(' Cộng lượt bán website (WooCommerce total_sales của các sản phẩm trên)'));
            card.appendChild(webLabel);
            card.appendChild(totalBox);
            card.appendChild(renderTemplates(group, totalBox));

            var table = el('table', {class: 'widefat striped hithean-sc-sources'});
            var thead = el('thead');
            var hr = el('tr');
            ['Loại', 'Sàn', 'Store / ghi chú', 'Nguồn', 'Đã bán', 'Cập nhật', ''].forEach(function (t) { hr.appendChild(el('th', {}, t)); });
            thead.appendChild(hr);
            var tbody = el('tbody');
            table.appendChild(thead);
            table.appendChild(tbody);
            card.appendChild(table);
            card.appendChild(renderExtractor(group, tbody, totalBox));

            renderSources(group, tbody, totalBox);
            renderTotal(group, totalBox);
            return card;
        }

        function render() {
            wrap.innerHTML = '';
            tplUpdaters = [];
            if (!state.length) wrap.appendChild(el('p', {}, 'Chưa có nhãn hiệu nào.'));
            state.forEach(function (group, i) { wrap.appendChild(renderGroup(group, i)); });
        }

        document.getElementById('hithean-sc-add-group').addEventListener('click', function () {
            state.push({id: '', name: '', brand_term: 0, product_ids: [], include_web: '1', sources: []});
            render();
        });

        form.addEventListener('submit', function () {
            document.getElementById('hithean-sc-groups-json').value = JSON.stringify(state.map(function (g) {
                return {
                    id: g.id, name: g.name, templates: g.templates || {}, brand_term: g.brand_term, product_ids: g.product_ids, include_web: g.include_web,
                    sources: g.sources.map(function (s) { return {type: s.type, platform: s.platform, label: s.label, ref: s.ref, count: s.count, updated: s.updated}; })
                };
            }));
        });

        render();
    }());
    </script>
    <?php
}

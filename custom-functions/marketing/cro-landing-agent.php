<?php
/**
 * Hithean CRO Landing Agent.
 *
 * Collects aggregate GA4 landing-page data weekly, reconciles it with WooCommerce,
 * creates evidence-led recommendations, and keeps their execution in a small Kanban.
 * No customer/order-level data is persisted or sent to GA4, Lark, or an AI provider.
 */
defined('ABSPATH') || exit;

define('HITHEAN_CRO_DB_VERSION', '1');
define('HITHEAN_CRO_OPTION', 'hithean_cro_landing_settings');
define('HITHEAN_CRO_CRON_HOOK', 'hithean_cro_landing_weekly');

function hithean_cro_capability(): string { return apply_filters('hithean_cro_capability', 'manage_woocommerce'); }
function hithean_cro_can_manage(): bool { return current_user_can(hithean_cro_capability()); }
function hithean_cro_reports_table(): string { global $wpdb; return $wpdb->prefix . 'hithean_cro_reports'; }
function hithean_cro_cards_table(): string { global $wpdb; return $wpdb->prefix . 'hithean_cro_cards'; }

function hithean_cro_defaults(): array {
    return [
        'property_id' => '',
        'lark_webhook' => '',
        'landing_paths' => "/\nan-new-chapter\nanc-huu-co\nanc-phan-phoi\nan-new-chapter-b2b\nan-new-chapter-b2b-organic\nan-new-chapter-affiliate",
        'ai_enabled' => '1',
        'ai_provider' => 'auto',
        'ai_model' => '',
    ];
}
function hithean_cro_settings(): array {
    $saved = get_option(HITHEAN_CRO_OPTION, []);
    return array_merge(hithean_cro_defaults(), is_array($saved) ? $saved : []);
}
function hithean_cro_paths(): array {
    $raw = (string) hithean_cro_settings()['landing_paths'];
    $paths = preg_split('/[\r\n,]+/', $raw);
    $out = [];
    foreach ((array) $paths as $path) {
        $path = trim((string) $path);
        if ($path === '') { continue; }
        $parsed = wp_parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $path;
        $path = '/' . trim($path, '/') ;
        $out[] = $path === '/' ? '/' : untrailingslashit($path);
    }
    return array_values(array_unique($out));
}
function hithean_cro_lark_webhook(string $url): string {
    $url = esc_url_raw($url);
    if ($url === '') { return ''; }
    $parts = wp_parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    // Incoming bots are hosted by Lark/Feishu. Restricting this prevents an
    // administrator form from becoming an internal-network request primitive.
    if ($scheme !== 'https' || !preg_match('/(^|\\.)(larksuite\\.com|larkoffice\\.com|feishu\\.cn)$/', $host)) { return ''; }
    return $url;
}

function hithean_cro_install(): void {
    if (get_option('hithean_cro_db_version') === HITHEAN_CRO_DB_VERSION) { return; }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $reports = hithean_cro_reports_table();
    $cards = hithean_cro_cards_table();
    dbDelta("CREATE TABLE $reports (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        period_start date NOT NULL,
        period_end date NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'complete',
        ga4_json longtext NOT NULL,
        wc_json longtext NOT NULL,
        insights_json longtext NOT NULL,
        lark_status varchar(20) NOT NULL DEFAULT 'pending',
        lark_error text NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY period_range (period_start,period_end),
        KEY created_at (created_at)
    ) $charset;");
    dbDelta("CREATE TABLE $cards (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        report_id bigint(20) unsigned NOT NULL,
        fingerprint char(64) NOT NULL,
        landing_path varchar(255) NOT NULL,
        title varchar(255) NOT NULL,
        evidence text NOT NULL,
        hypothesis text NOT NULL,
        metric varchar(255) NOT NULL,
        priority varchar(10) NOT NULL DEFAULT 'medium',
        board_status varchar(24) NOT NULL DEFAULT 'new',
        owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY fingerprint_open (fingerprint,board_status),
        KEY board_status (board_status),
        KEY report_id (report_id)
    ) $charset;");
    update_option('hithean_cro_db_version', HITHEAN_CRO_DB_VERSION, false);
}
add_action('init', 'hithean_cro_install', 2);

function hithean_cro_next_monday_nine(): int {
    $tz = wp_timezone();
    $now = new DateTimeImmutable('now', $tz);
    $next = $now->setTime(9, 0);
    $weekday = (int) $next->format('N');
    $days = $weekday === 1 && $now < $next ? 0 : (8 - $weekday);
    return $next->modify('+' . $days . ' days')->getTimestamp();
}
function hithean_cro_schedule(): void {
    if (!wp_next_scheduled(HITHEAN_CRO_CRON_HOOK)) {
        wp_schedule_event(hithean_cro_next_monday_nine(), 'weekly', HITHEAN_CRO_CRON_HOOK);
    }
}
add_action('init', 'hithean_cro_schedule', 20);

function hithean_cro_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function hithean_cro_service_account(): array {
    $value = defined('HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON') ? (string) HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON : (string) getenv('HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON');
    if ($value === '') { return []; }
    if (is_file($value) && is_readable($value)) { $value = (string) file_get_contents($value); }
    $json = json_decode($value, true);
    return is_array($json) ? $json : [];
}
function hithean_cro_ga4_token() {
    $account = hithean_cro_service_account();
    if (empty($account['client_email']) || empty($account['private_key']) || !function_exists('openssl_sign')) {
        return new WP_Error('hithean_cro_ga4_credentials', 'Thiếu hoặc không đọc được service-account GA4. Khai báo HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON trong wp-config hoặc environment.');
    }
    $cached = get_transient('hithean_cro_ga4_token');
    if (is_array($cached) && !empty($cached['token'])) { return (string) $cached['token']; }
    $now = time();
    $header = hithean_cro_b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = hithean_cro_b64url(wp_json_encode([
        'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claims;
    if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
        return new WP_Error('hithean_cro_ga4_sign', 'Không thể ký JWT cho GA4 service account.');
    }
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 20,
        'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . hithean_cro_b64url($signature)],
    ]);
    if (is_wp_error($response)) { return $response; }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ((int) wp_remote_retrieve_response_code($response) !== 200 || empty($data['access_token'])) {
        return new WP_Error('hithean_cro_ga4_auth', (string) ($data['error_description'] ?? $data['error']['message'] ?? 'GA4 authentication failed.'));
    }
    set_transient('hithean_cro_ga4_token', ['token' => (string) $data['access_token']], max(60, ((int) ($data['expires_in'] ?? 3600)) - 120));
    return (string) $data['access_token'];
}
function hithean_cro_ga4_report(string $start, string $end, array $metrics, string $event = '') {
    $settings = hithean_cro_settings();
    $property = preg_replace('/\D+/', '', (string) $settings['property_id']);
    if ($property === '') { return new WP_Error('hithean_cro_ga4_property', 'Chưa cấu hình GA4 Property ID.'); }
    $token = hithean_cro_ga4_token();
    if (is_wp_error($token)) { return $token; }
    $payload = [
        'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
        'dimensions' => [['name' => 'landingPagePlusQueryString']],
        'metrics' => array_map(static fn($metric) => ['name' => $metric], $metrics),
        'limit' => 100000,
    ];
    if ($event !== '') { $payload['dimensionFilter'] = ['filter' => ['fieldName' => 'eventName', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $event]]]; }
    $response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $property . ':runReport', [
        'timeout' => 35,
        'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode($payload),
    ]);
    if (is_wp_error($response)) { return $response; }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ((int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
        return new WP_Error('hithean_cro_ga4_query', (string) ($data['error']['message'] ?? 'GA4 report request failed.'));
    }
    return is_array($data) ? $data : [];
}
function hithean_cro_rows(string $start, string $end): array {
    $base = hithean_cro_ga4_report($start, $end, ['sessions', 'totalUsers', 'engagementRate', 'userEngagementDuration']);
    if (is_wp_error($base)) { return $base; }
    $events = [];
    foreach (['add_to_cart', 'begin_checkout', 'purchase'] as $event) {
        $report = hithean_cro_ga4_report($start, $end, $event === 'purchase' ? ['eventCount', 'purchaseRevenue'] : ['eventCount'], $event);
        if (is_wp_error($report)) { return $report; }
        $events[$event] = $report;
    }
    $wanted = array_fill_keys(hithean_cro_paths(), true);
    $rows = [];
    foreach ((array) ($base['rows'] ?? []) as $row) {
        $raw = (string) ($row['dimensionValues'][0]['value'] ?? '');
        $path = wp_parse_url($raw, PHP_URL_PATH) ?: $raw;
        $path = '/' . trim((string) $path, '/'); $path = $path === '/' ? '/' : untrailingslashit($path);
        if (!isset($wanted[$path])) { continue; }
        $m = (array) ($row['metricValues'] ?? []);
        $rows[$path] = ['path' => $path, 'sessions' => (int) ($m[0]['value'] ?? 0), 'users' => (int) ($m[1]['value'] ?? 0), 'engagement_rate' => (float) ($m[2]['value'] ?? 0), 'engagement_seconds' => (float) ($m[3]['value'] ?? 0), 'add_to_cart' => 0, 'begin_checkout' => 0, 'purchase' => 0, 'purchase_revenue' => 0.0];
    }
    foreach ($wanted as $path => $_) { if (!isset($rows[$path])) { $rows[$path] = ['path' => $path, 'sessions' => 0, 'users' => 0, 'engagement_rate' => 0, 'engagement_seconds' => 0, 'add_to_cart' => 0, 'begin_checkout' => 0, 'purchase' => 0, 'purchase_revenue' => 0.0]; } }
    foreach ($events as $event => $report) foreach ((array) ($report['rows'] ?? []) as $row) {
        $raw = (string) ($row['dimensionValues'][0]['value'] ?? ''); $path = wp_parse_url($raw, PHP_URL_PATH) ?: $raw;
        $path = '/' . trim((string) $path, '/'); $path = $path === '/' ? '/' : untrailingslashit($path);
        if (!isset($rows[$path])) { continue; }
        $values = (array) ($row['metricValues'] ?? []); $rows[$path][$event] = (int) ($values[0]['value'] ?? 0);
        if ($event === 'purchase') { $rows[$path]['purchase_revenue'] = (float) ($values[1]['value'] ?? 0); }
    }
    return array_values($rows);
}

function hithean_cro_wc_summary(string $start, string $end): array {
    if (!function_exists('wc_get_orders')) { return ['available' => false, 'orders' => 0, 'revenue' => 0.0, 'refunds' => 0.0]; }
    $statuses = apply_filters('hithean_cro_paid_order_statuses', ['processing', 'completed', 'received-payment']);
    $page = 1; $orders = 0; $revenue = 0.0; $refunds = 0.0;
    do {
        $result = wc_get_orders(['status' => $statuses, 'date_created' => $start . '...' . $end . ' 23:59:59', 'limit' => 100, 'page' => $page, 'paginate' => true, 'return' => 'objects']);
        foreach ((array) ($result->orders ?? []) as $order) { $orders++; $revenue += (float) $order->get_total(); $refunds += abs((float) $order->get_total_refunded()); }
        $page++;
    } while (!empty($result->max_num_pages) && $page <= (int) $result->max_num_pages);
    return ['available' => true, 'orders' => $orders, 'revenue' => round($revenue, 2), 'refunds' => round($refunds, 2)];
}

function hithean_cro_percent(float $numerator, float $denominator): float { return $denominator > 0 ? $numerator / $denominator : 0.0; }
function hithean_cro_insights(array $current, array $previous): array {
    $previous_map = []; foreach ($previous as $row) { $previous_map[$row['path']] = $row; }
    $insights = [];
    foreach ($current as $row) {
        $sessions = (int) $row['sessions']; if ($sessions < 100) { continue; }
        $path = (string) $row['path']; $prior = $previous_map[$path] ?? [];
        $atc_rate = hithean_cro_percent((float) $row['add_to_cart'], $sessions);
        $checkout_rate = hithean_cro_percent((float) $row['begin_checkout'], max(1, (int) $row['add_to_cart']));
        $purchase_rate = hithean_cro_percent((float) $row['purchase'], $sessions);
        $purchase_complete_rate = hithean_cro_percent((float) $row['purchase'], max(1, (int) $row['begin_checkout']));
        $prior_sessions = (int) ($prior['sessions'] ?? 0);
        if ((float) $row['engagement_rate'] < 0.45) {
            $insights[] = hithean_cro_insight($path, 'engagement', 'Củng cố thông điệp và CTA ở màn hình đầu', sprintf('Engagement rate %.1f%% trên %d sessions, dưới ngưỡng 45%%.', $row['engagement_rate'] * 100, $sessions), 'Đối chiếu promise quảng cáo với headline, nêu lợi ích cụ thể và đặt CTA chính cùng bằng chứng tin cậy trước khi cuộn.', 'Engagement rate và add-to-cart rate', 'high');
        }
        if ($atc_rate < 0.05) {
            $insights[] = hithean_cro_insight($path, 'atc', 'Tăng khả năng chuyển từ landing sang giỏ hàng', sprintf('Add-to-cart rate %.1f%% (%d/%d), dưới ngưỡng 5%%.', $atc_rate * 100, $row['add_to_cart'], $sessions), 'Làm rõ lợi ích, giá trị/giá, trạng thái hàng và CTA; đưa trust proof sát CTA thay vì thêm pop-up.', 'Add-to-cart rate', 'high');
        }
        if ($row['add_to_cart'] >= 5 && $checkout_rate < 0.45) {
            $insights[] = hithean_cro_insight($path, 'checkout', 'Giảm friction từ giỏ hàng sang checkout', sprintf('Chỉ %.1f%% lượt add-to-cart tiếp tục begin_checkout (%d/%d).', $checkout_rate * 100, $row['begin_checkout'], $row['add_to_cart']), 'Kiểm tra phí giao hàng, mã giảm giá, stock và CTA giỏ hàng trên mobile trước khi thử thay đổi copy.', 'Begin-checkout / add-to-cart', 'medium');
        }
        if ($row['begin_checkout'] >= 5 && $purchase_complete_rate < 0.50) {
            $insights[] = hithean_cro_insight($path, 'purchase', 'Tăng hoàn tất checkout', sprintf('Purchase / begin_checkout là %.1f%% (%d/%d).', $purchase_complete_rate * 100, $row['purchase'], $row['begin_checkout']), 'Rà soát lỗi checkout mobile, phương thức thanh toán, phí hiển thị muộn và trust/reassurance gần nút đặt hàng.', 'Purchase / begin_checkout', 'high');
        }
        if ($prior_sessions >= 100 && hithean_cro_percent($sessions - $prior_sessions, $prior_sessions) <= -0.30) {
            $insights[] = hithean_cro_insight($path, 'traffic_drop', 'Kiểm tra giảm traffic trước khi thay CRO', sprintf('Sessions giảm %.1f%% tuần qua (%d so với %d).', hithean_cro_percent($sessions - $prior_sessions, $prior_sessions) * 100, $sessions, $prior_sessions), 'Kiểm tra media/SEO/tracking và landing URL trước; đây chưa phải bằng chứng để đổi UX.', 'Sessions', 'medium');
        }
        if ($row['purchase'] < 5) {
            $insights[] = hithean_cro_insight($path, 'low_purchase_coverage', 'Bổ sung mẫu dữ liệu trước khi kết luận purchase rate', sprintf('Landing có %d purchase trong kỳ; chưa đạt ngưỡng 5 purchase.', $row['purchase']), 'Ưu tiên chỉ số đầu funnel và tiếp tục đo; không kết luận uplift hay nguyên nhân doanh thu.', 'Purchase count', 'low', false);
        }
    }
    usort($insights, static fn($a, $b) => ['high'=>3,'medium'=>2,'low'=>1][$b['priority']] <=> ['high'=>3,'medium'=>2,'low'=>1][$a['priority']]);
    return array_slice($insights, 0, 5);
}
function hithean_cro_insight(string $path, string $type, string $title, string $evidence, string $hypothesis, string $metric, string $priority, bool $card = true): array {
    return compact('path', 'type', 'title', 'evidence', 'hypothesis', 'metric', 'priority', 'card');
}

function hithean_cro_optional_ai(array $insights, array $rows): array {
    $settings = hithean_cro_settings();
    if (empty($settings['ai_enabled']) || empty($insights)) { return $insights; }
    $provider_file = HITHEAN_THEME_DIR . '/custom-functions/core/ai-providers.php';
    if (!file_exists($provider_file)) { return $insights; }
    require_once $provider_file;
    $safe_data = wp_json_encode(['insights' => $insights, 'landing_metrics' => $rows]);
    $reply = theme_ai_call_provider((string) $settings['ai_provider'], 'Bạn là CRO analyst cho e-commerce Việt Nam. Chỉ viết lại ngắn gọn giả thuyết và thử nghiệm từ số liệu tổng hợp được cung cấp. Không bịa số liệu, không khẳng định nhân quả, không đề xuất dark pattern. Trả JSON array có tối đa 5 object: index (0-based), hypothesis, test.', [['role' => 'user', 'content' => $safe_data]], 1100, (string) $settings['ai_model']);
    if (is_wp_error($reply)) { return $insights; }
    $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $reply)), true);
    if (!is_array($json)) { return $insights; }
    foreach ($json as $item) { $i = isset($item['index']) ? (int) $item['index'] : -1; if (!isset($insights[$i])) { continue; } if (!empty($item['hypothesis'])) { $insights[$i]['hypothesis'] = sanitize_textarea_field((string) $item['hypothesis']); } if (!empty($item['test'])) { $insights[$i]['test'] = sanitize_textarea_field((string) $item['test']); } }
    return $insights;
}

function hithean_cro_create_cards(int $report_id, array $insights): void {
    global $wpdb; $table = hithean_cro_cards_table(); $now = current_time('mysql', true);
    foreach ($insights as $item) {
        if (empty($item['card'])) { continue; }
        $fingerprint = hash('sha256', $item['path'] . '|' . $item['type']);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE fingerprint = %s AND board_status NOT IN ('measured') LIMIT 1", $fingerprint));
        if ($exists) { continue; }
        $wpdb->insert($table, ['report_id' => $report_id, 'fingerprint' => $fingerprint, 'landing_path' => $item['path'], 'title' => $item['title'], 'evidence' => $item['evidence'], 'hypothesis' => trim($item['hypothesis'] . (!empty($item['test']) ? "\nThử nghiệm: " . $item['test'] : '')), 'metric' => $item['metric'], 'priority' => $item['priority'], 'board_status' => 'new', 'created_at' => $now, 'updated_at' => $now], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
    }
}

function hithean_cro_period(): array {
    $tz = wp_timezone(); $today = new DateTimeImmutable('today', $tz); $end = $today->modify('last sunday');
    return [$end->modify('-6 days')->format('Y-m-d'), $end->format('Y-m-d')];
}
function hithean_cro_acquire_lock(): bool {
    $key = 'hithean_cro_run_lock';
    $locked_at = (int) get_option($key, 0);
    // A crashed process must not block the following scheduled run forever.
    if ($locked_at > 0 && $locked_at < (time() - 20 * MINUTE_IN_SECONDS)) {
        delete_option($key);
    }
    // add_option is atomic at the database layer, unlike a transient get/set pair.
    return add_option($key, time(), '', 'no');
}
function hithean_cro_release_lock(): void { delete_option('hithean_cro_run_lock'); }
function hithean_cro_previous_rows(string $start): array {
    global $wpdb; $table = hithean_cro_reports_table();
    $json = $wpdb->get_var($wpdb->prepare("SELECT ga4_json FROM $table WHERE period_end < %s AND status = 'complete' ORDER BY period_end DESC LIMIT 1", $start));
    $data = json_decode((string) $json, true); return is_array($data['rows'] ?? null) ? $data['rows'] : [];
}
function hithean_cro_run(bool $force = false) {
    if (!hithean_cro_acquire_lock()) { return new WP_Error('hithean_cro_locked', 'CRO report đang chạy.'); }
    try {
        [$start, $end] = hithean_cro_period(); global $wpdb; $reports = hithean_cro_reports_table();
        if (!$force && $wpdb->get_var($wpdb->prepare("SELECT id FROM $reports WHERE period_start=%s AND period_end=%s", $start, $end))) { return new WP_Error('hithean_cro_exists', 'Báo cáo của kỳ này đã tồn tại.'); }
        $rows = hithean_cro_rows($start, $end); if (is_wp_error($rows)) { return $rows; }
        $previous = hithean_cro_previous_rows($start); $wc = hithean_cro_wc_summary($start, $end);
        $insights = hithean_cro_optional_ai(hithean_cro_insights($rows, $previous), $rows);
        $now = current_time('mysql', true);
        if ($force) { $wpdb->delete($reports, ['period_start' => $start, 'period_end' => $end], ['%s','%s']); }
        $wpdb->insert($reports, ['period_start'=>$start, 'period_end'=>$end, 'status'=>'complete', 'ga4_json'=>wp_json_encode(['rows'=>$rows,'previous_rows'=>$previous]), 'wc_json'=>wp_json_encode($wc), 'insights_json'=>wp_json_encode($insights), 'lark_status'=>'pending', 'created_at'=>$now], ['%s','%s','%s','%s','%s','%s','%s','%s']);
        $id = (int) $wpdb->insert_id; if (!$id) { return new WP_Error('hithean_cro_save', 'Không lưu được báo cáo CRO.'); }
        hithean_cro_create_cards($id, $insights); hithean_cro_send_lark($id);
        return $id;
    } finally { hithean_cro_release_lock(); }
}
add_action(HITHEAN_CRO_CRON_HOOK, static function (): void { hithean_cro_run(false); });

function hithean_cro_send_lark(int $report_id): bool {
    global $wpdb; $reports = hithean_cro_reports_table(); $report = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reports WHERE id=%d", $report_id), ARRAY_A); if (!$report) { return false; }
    $url = hithean_cro_lark_webhook((string) hithean_cro_settings()['lark_webhook']); if ($url === '') { return false; }
    $insights = json_decode((string) $report['insights_json'], true) ?: [];
    $lines = []; foreach (array_slice($insights, 0, 3) as $item) { $lines[] = '• ' . $item['path'] . ': ' . $item['title']; }
    $link = admin_url('admin.php?page=hithean-cro-landing&report=' . $report_id);
    $text = "Báo cáo CRO landing " . $report['period_start'] . ' → ' . $report['period_end'] . "\n" . ($lines ? implode("\n", $lines) : 'Chưa có landing đạt ngưỡng dữ liệu để ưu tiên.') . "\nXem dashboard: " . $link;
    $response = wp_remote_post($url, ['timeout'=>15, 'headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode(['msg_type'=>'text','content'=>['text'=>$text]])]);
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 300) { $error = is_wp_error($response) ? $response->get_error_message() : 'Webhook HTTP ' . wp_remote_retrieve_response_code($response); $wpdb->update($reports, ['lark_status'=>'failed','lark_error'=>sanitize_text_field($error)], ['id'=>$report_id], ['%s','%s'], ['%d']); return false; }
    $wpdb->update($reports, ['lark_status'=>'sent','lark_error'=>null], ['id'=>$report_id], ['%s','%s'], ['%d']); return true;
}

function hithean_cro_admin_menu(): void {
    add_menu_page('CRO Landing', 'CRO Landing', hithean_cro_capability(), 'hithean-cro-landing', 'hithean_cro_render_admin', 'dashicons-chart-area', 58);
}
add_action('admin_menu', 'hithean_cro_admin_menu');
function hithean_cro_admin_assets(string $hook): void {
    if ($hook !== 'toplevel_page_hithean-cro-landing') { return; }
    wp_register_style('hithean-cro-admin', false, [], HITHEAN_CRO_DB_VERSION);
    wp_enqueue_style('hithean-cro-admin');
    wp_add_inline_style('hithean-cro-admin', '.hcro{max-width:1420px}.hcro-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px}.hcro-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;box-shadow:0 1px 2px #0000000d}.hcro-number{font-size:26px;font-weight:700;color:#1a73e8}.hcro-board{display:grid;grid-template-columns:repeat(6,minmax(220px,1fr));gap:12px;overflow:auto;padding-bottom:12px}.hcro-column{background:#f6f8fc;border-radius:8px;padding:10px;min-height:220px}.hcro-column h3{font-size:13px;margin:4px 0 12px}.hcro-task{background:#fff;border-left:4px solid #f9ab00;border-radius:5px;padding:10px;margin-bottom:10px;box-shadow:0 1px 2px #0002}.hcro-task.high{border-left-color:#d93025}.hcro-task.low{border-left-color:#34a853}.hcro-muted{color:#646970}.hcro-table td,.hcro-table th{vertical-align:top}.hcro-settings{max-width:780px}.hcro-settings textarea{width:100%;min-height:150px}.hcro-alert{padding:10px 12px;background:#fff8e1;border-left:4px solid #f9ab00;margin:14px 0}');
}
add_action('admin_enqueue_scripts', 'hithean_cro_admin_assets');

function hithean_cro_admin_post(): void {
    if (!hithean_cro_can_manage()) { wp_die('Bạn không có quyền.'); } check_admin_referer('hithean_cro_action');
    $action = sanitize_key((string) ($_POST['cro_action'] ?? ''));
    if ($action === 'save_settings') { $old = hithean_cro_settings(); $old['property_id'] = sanitize_text_field((string) ($_POST['property_id'] ?? '')); $old['lark_webhook'] = hithean_cro_lark_webhook((string) ($_POST['lark_webhook'] ?? '')); $old['landing_paths'] = sanitize_textarea_field((string) ($_POST['landing_paths'] ?? '')); $old['ai_enabled'] = !empty($_POST['ai_enabled']) ? '1' : '0'; $old['ai_provider'] = in_array(sanitize_key((string) ($_POST['ai_provider'] ?? 'auto')), ['auto','claude','gemini','gemini_billing','openai'], true) ? sanitize_key((string) $_POST['ai_provider']) : 'auto'; $old['ai_model'] = sanitize_text_field((string) ($_POST['ai_model'] ?? '')); update_option(HITHEAN_CRO_OPTION, $old, false); $notice = 'saved'; }
    elseif ($action === 'run') { $result = hithean_cro_run(true); $notice = is_wp_error($result) ? 'error:' . rawurlencode($result->get_error_message()) : 'ran'; }
    elseif ($action === 'lark_test') { $notice = hithean_cro_send_lark((int) ($_POST['report_id'] ?? 0)) ? 'lark_sent' : 'error:' . rawurlencode('Không gửi được Lark; kiểm tra webhook hoặc báo cáo.'); }
    elseif ($action === 'move_card') { global $wpdb; $status = sanitize_key((string) ($_POST['board_status'] ?? '')); $valid = ['new','reviewing','approved','doing','deployed','measured']; if (in_array($status, $valid, true)) { $wpdb->update(hithean_cro_cards_table(), ['board_status'=>$status,'updated_at'=>current_time('mysql',true)], ['id'=>(int) ($_POST['card_id'] ?? 0)], ['%s','%s'], ['%d']); } $notice = 'moved'; }
    wp_safe_redirect(admin_url('admin.php?page=hithean-cro-landing&notice=' . $notice)); exit;
}
add_action('admin_post_hithean_cro_action', 'hithean_cro_admin_post');

function hithean_cro_render_admin(): void {
    if (!hithean_cro_can_manage()) { wp_die('Bạn không có quyền.'); }
    global $wpdb; $reports = hithean_cro_reports_table(); $cards = hithean_cro_cards_table(); $report_id = (int) ($_GET['report'] ?? 0);
    $report = $report_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $reports WHERE id=%d", $report_id), ARRAY_A) : $wpdb->get_row("SELECT * FROM $reports ORDER BY period_end DESC LIMIT 1", ARRAY_A);
    $settings = hithean_cro_settings(); $page = sanitize_key((string) ($_GET['view'] ?? 'overview')); $notice = (string) ($_GET['notice'] ?? '');
    echo '<div class="wrap hcro"><h1>CRO Landing Agent</h1><nav class="nav-tab-wrapper"><a class="nav-tab ' . ($page==='overview'?'nav-tab-active':'') . '" href="?page=hithean-cro-landing">Tổng quan</a><a class="nav-tab ' . ($page==='board'?'nav-tab-active':'') . '" href="?page=hithean-cro-landing&view=board">Kanban</a><a class="nav-tab ' . ($page==='settings'?'nav-tab-active':'') . '" href="?page=hithean-cro-landing&view=settings">Cài đặt</a></nav>';
    if ($notice) { echo '<div class="notice notice-' . (str_starts_with($notice, 'error:')?'error':'success') . '"><p>' . esc_html(str_starts_with($notice,'error:') ? rawurldecode(substr($notice,6)) : 'Đã cập nhật.') . '</p></div>'; }
    if ($page === 'settings') { hithean_cro_render_settings($settings); echo '</div>'; return; }
    if ($page === 'board') { hithean_cro_render_board($cards); echo '</div>'; return; }
    hithean_cro_render_overview($report); echo '</div>';
}
function hithean_cro_form_open(string $action): void { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="hithean_cro_action"><input type="hidden" name="cro_action" value="' . esc_attr($action) . '">'; wp_nonce_field('hithean_cro_action'); }
function hithean_cro_render_settings(array $s): void {
    echo '<div class="hcro-settings"><h2>Cấu hình nguồn dữ liệu</h2><p class="description">Service account không lưu trong WordPress. Khai báo <code>HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON</code> trong wp-config/environment, là JSON hoặc đường dẫn file JSON chỉ web user đọc được.</p>'; hithean_cro_form_open('save_settings');
    echo '<table class="form-table"><tr><th>GA4 Property ID</th><td><input class="regular-text" name="property_id" value="' . esc_attr($s['property_id']) . '" required></td></tr><tr><th>Lark group webhook</th><td><input class="large-text" type="url" name="lark_webhook" value="' . esc_attr($s['lark_webhook']) . '"></td></tr><tr><th>Landing URL / slug</th><td><textarea name="landing_paths">' . esc_textarea($s['landing_paths']) . '</textarea><p class="description">Mỗi dòng một path hoặc URL. Chỉ các landing này được truy vấn và hiển thị.</p></td></tr><tr><th>AI enrichment</th><td><label><input type="checkbox" name="ai_enabled" value="1" ' . checked($s['ai_enabled'], '1', false) . '> Viết lại giả thuyết thử nghiệm từ số liệu aggregate; không gửi PII.</label><p><select name="ai_provider">'; foreach (['auto'=>'Tự động','claude'=>'Claude','gemini'=>'Gemini','gemini_billing'=>'Gemini billing','openai'=>'OpenAI'] as $k=>$v) { echo '<option value="' . esc_attr($k) . '" ' . selected($s['ai_provider'],$k,false) . '>' . esc_html($v) . '</option>'; } echo '</select> <input class="regular-text" name="ai_model" placeholder="Model override (tuỳ chọn)" value="' . esc_attr($s['ai_model']) . '"></p></td></tr></table><p class="submit"><button class="button button-primary">Lưu cấu hình</button></p></form></div>';
}
function hithean_cro_render_overview(?array $report): void {
    hithean_cro_form_open('run'); echo '<p><button class="button button-primary">Chạy lại tuần hoàn chỉnh gần nhất</button> <span class="description">Cron tự chạy Thứ Hai 09:00; chỉ dùng nút này sau khi kiểm tra cấu hình.</span></p></form>';
    if (!$report) { echo '<div class="hcro-alert">Chưa có báo cáo. Cấu hình GA4 service account, Property ID, danh sách landing rồi chạy thử.</div>'; return; }
    $ga4 = json_decode((string) $report['ga4_json'], true) ?: []; $rows = $ga4['rows'] ?? []; $wc = json_decode((string) $report['wc_json'], true) ?: []; $insights = json_decode((string) $report['insights_json'], true) ?: [];
    $sessions = array_sum(array_column($rows, 'sessions')); $purchases = array_sum(array_column($rows, 'purchase')); $revenue = array_sum(array_column($rows, 'purchase_revenue'));
    echo '<h2>Kỳ ' . esc_html($report['period_start']) . ' → ' . esc_html($report['period_end']) . '</h2><div class="hcro-grid"><div class="hcro-card"><div class="hcro-muted">GA4 sessions</div><div class="hcro-number">' . number_format_i18n($sessions) . '</div></div><div class="hcro-card"><div class="hcro-muted">GA4 purchases</div><div class="hcro-number">' . number_format_i18n($purchases) . '</div></div><div class="hcro-card"><div class="hcro-muted">GA4 purchase revenue</div><div class="hcro-number">' . wp_kses_post(wc_price($revenue)) . '</div></div><div class="hcro-card"><div class="hcro-muted">WooCommerce đối soát</div><div class="hcro-number">' . number_format_i18n((int) ($wc['orders'] ?? 0)) . ' đơn</div><div class="hcro-muted">' . wp_kses_post(wc_price((float) ($wc['revenue'] ?? 0))) . '</div></div></div>';
    echo '<h2>Funnel theo landing</h2><table class="widefat striped hcro-table"><thead><tr><th>Landing</th><th>Sessions</th><th>Engagement</th><th>ATC</th><th>Checkout</th><th>Purchase</th><th>Revenue</th></tr></thead><tbody>'; foreach ($rows as $row) { echo '<tr><td><code>' . esc_html($row['path']) . '</code></td><td>' . number_format_i18n($row['sessions']) . '</td><td>' . number_format_i18n($row['engagement_rate']*100,1) . '%</td><td>' . number_format_i18n($row['add_to_cart']) . '</td><td>' . number_format_i18n($row['begin_checkout']) . '</td><td>' . number_format_i18n($row['purchase']) . '</td><td>' . wp_kses_post(wc_price($row['purchase_revenue'])) . '</td></tr>'; } echo '</tbody></table>';
    echo '<h2>Đề xuất ưu tiên</h2>'; if (!$insights) { echo '<p class="hcro-muted">Chưa có landing đạt ngưỡng dữ liệu (100 sessions / 5 purchase cho kết luận conversion).</p>'; } foreach ($insights as $item) { echo '<div class="hcro-card"><strong>' . esc_html($item['title']) . '</strong> <span class="hcro-muted">' . esc_html($item['path']) . '</span><p><b>Bằng chứng:</b> ' . esc_html($item['evidence']) . '</p><p><b>Giả thuyết:</b> ' . esc_html($item['hypothesis']) . '</p><p class="hcro-muted">Đo lại bằng: ' . esc_html($item['metric']) . '</p></div>'; }
    if ($report['lark_status'] === 'failed') { echo '<div class="hcro-alert">Gửi Lark lỗi: ' . esc_html($report['lark_error']) . '</div>'; } hithean_cro_form_open('lark_test'); echo '<input type="hidden" name="report_id" value="' . (int)$report['id'] . '"><p><button class="button">Gửi lại Lark</button></p></form>';
}
function hithean_cro_render_board(string $table): void {
    global $wpdb; $items = $wpdb->get_results("SELECT * FROM $table ORDER BY FIELD(board_status,'new','reviewing','approved','doing','deployed','measured'), FIELD(priority,'high','medium','low'), updated_at DESC", ARRAY_A); $states=['new'=>'Mới','reviewing'=>'Đang xem','approved'=>'Đã duyệt','doing'=>'Đang làm','deployed'=>'Đã triển khai','measured'=>'Đo kết quả']; $group=[]; foreach($items as $item){$group[$item['board_status']][]=$item;}
    echo '<p class="description">Chuyển card qua từng trạng thái để luôn thấy bước tiếp theo. Chỉ đánh giá tác động sau “Đã triển khai” và kỳ đo mới.</p><div class="hcro-board">'; foreach($states as $state=>$label){ echo '<section class="hcro-column"><h3>' . esc_html($label) . ' (' . count($group[$state] ?? []) . ')</h3>'; foreach($group[$state] ?? [] as $item){ echo '<article class="hcro-task ' . esc_attr($item['priority']) . '"><strong>' . esc_html($item['title']) . '</strong><p><code>' . esc_html($item['landing_path']) . '</code></p><p>' . esc_html($item['evidence']) . '</p><p class="hcro-muted">Đo: ' . esc_html($item['metric']) . '</p>'; hithean_cro_form_open('move_card'); echo '<input type="hidden" name="card_id" value="' . (int)$item['id'] . '"><select name="board_status">'; foreach($states as $v=>$l){echo '<option value="'.esc_attr($v).'" '.selected($state,$v,false).'>'.esc_html($l).'</option>';} echo '</select> <button class="button button-small">Cập nhật</button></form></article>'; } echo '</section>'; } echo '</div>';
}

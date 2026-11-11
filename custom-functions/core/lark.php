<?php
/**
 * Lark — kết nối dùng chung cho mọi thông báo của site (GIỐNG HỆT hithean ↔ theanmarket, sửa thì chép sang cả 2).
 *
 * - Kênh đặt tên: mỗi tính năng tự khai kênh của mình bằng child_theme_lark_register_channel('kho', [...]) rồi gửi
 *   child_theme_lark_send('kho', $payload) / child_theme_lark_text() / child_theme_lark_notify(). Không tính năng nào tự
 *   giữ webhook hay tự wp_remote_post sang Lark nữa.
 * - CHỈ nạp sẵn ở wp-admin + trang ERP / công cụ nội bộ (core/module-loader.php → child_theme_lark_should_preload()).
 *   Code tính năng chạy được ở nơi khác (cron, REST…) phải gọi child_theme_lark_load() trước khi dùng hàm của file này.
 * - Cấu hình kênh lưu ở option `erp_lark_settings['channels']` = [{key, label, type webhook|chat_id, value, active}] —
 *   CÙNG định dạng bộ Lark ERP của ivarvietnam (erp/integrations/lark/), nên chép bộ đó sang sau này (phương án C)
 *   đọc được ngay cấu hình đang có, và kênh type chat_id tự đi qua Lark App (erp_lark_api_post) khi bộ đó có mặt.
 * - Kiểm webhook chặt (https, host Lark/Feishu, đúng /open-apis/bot/v2/hook/<token>, không user/pass/port lạ), không theo
 *   redirect; Lark trả HTTP 200 kèm mã lỗi trong body cũng tính là lỗi; nhật ký gửi KHÔNG chứa URL (URL là khóa bí mật).
 * - Kênh có `fallback`: để trống webhook thì gửi vào webhook của kênh dự phòng (vd lỗi AI Shopee → nhóm kho).
 * - Cài đặt: Cài đặt ERP → tab "Lark" (child_theme_lark_settings_tab()). Tính năng thêm ô riêng bằng hook
 *   `child_theme_lark_settings_fields` (in) + `child_theme_lark_settings_save` (lưu, nhận $_POST đã unslash).
 *   Bật kênh từ tắt → do_action('child_theme_lark_channel_enabled', $key).
 * - Chuyển cấu hình cũ (webhook riêng của từng tính năng) sang kênh: child_theme_lark_migrate(), tự chạy 1 lần.
 * Test: php tests/lark-core.php.
 */
defined('ABSPATH') || exit;

const CHILD_THEME_LARK_MIGRATION = 1;

/*
 * child_theme_lark_register_channel() + child_theme_lark_load() do core/module-loader.php định nghĩa (luôn nạp, rất nhẹ)
 * để file tính năng khai kênh / nạp Lark đúng lúc gửi mà không kéo file này vào mọi request. Bản dưới chỉ dùng khi
 * chạy file này độc lập (test). $def: label, description, fallback (key kênh dự phòng), default_active.
 */
if (!function_exists('child_theme_lark_register_channel')) {
    function child_theme_lark_register_channel(string $key, array $def): void {
        $GLOBALS['child_theme_lark_channel_defs'][$key] = $def + ['label' => $key, 'description' => '', 'fallback' => '', 'default_active' => false];
    }
}
if (!function_exists('child_theme_lark_load')) {
    function child_theme_lark_load(): bool { return true; }
}

function child_theme_lark_channel_defs(): array {
    return (array) ($GLOBALS['child_theme_lark_channel_defs'] ?? []);
}

function child_theme_lark_settings(): array {
    $saved = get_option('erp_lark_settings', []);
    $settings = array_merge(['channels' => []], is_array($saved) ? $saved : []);
    $settings['channels'] = array_values(array_filter((array) $settings['channels'], static fn($c) => is_array($c) && !empty($c['key'])));
    return $settings;
}

/** Cấu hình lưu của 1 kênh (chưa xét dự phòng). */
function child_theme_lark_channel_config(string $key): array {
    child_theme_lark_migrate();
    foreach (child_theme_lark_settings()['channels'] as $channel) {
        if ($channel['key'] === $key) {
            return array_merge(['label' => '', 'type' => 'webhook', 'value' => '', 'active' => false], $channel);
        }
    }
    $def = child_theme_lark_channel_defs()[$key] ?? [];
    return ['key' => $key, 'label' => (string) ($def['label'] ?? $key), 'type' => 'webhook', 'value' => '', 'active' => !empty($def['default_active'])];
}

/** Ghi cấu hình 1 kênh, giữ nguyên các khóa khác của erp_lark_settings (app_id, event_map… của bộ ERP). */
function child_theme_lark_save_channel(array $channel): void {
    $settings = child_theme_lark_settings();
    $found = false;
    foreach ($settings['channels'] as $i => $existing) {
        if ($existing['key'] === $channel['key']) { $settings['channels'][$i] = array_merge($existing, $channel); $found = true; }
    }
    if (!$found) { $settings['channels'][] = $channel; }
    update_option('erp_lark_settings', $settings, false);
}

/** Webhook bot hợp lệ hoặc ''. */
function child_theme_lark_sanitize_webhook($value): string {
    $url = trim((string) $value);
    $parts = parse_url($url);
    if (!is_array($parts)) { return ''; }
    $host = strtolower((string) ($parts['host'] ?? ''));
    return ($parts['scheme'] ?? '') === 'https'
        && preg_match('/(^|\.)(larksuite\.com|larkoffice\.com|feishu\.cn)$/', $host)
        && empty($parts['user']) && empty($parts['pass'])
        && (!isset($parts['port']) || (int) $parts['port'] === 443)
        && preg_match('~^/open-apis/bot/v2/hook/[a-zA-Z0-9_-]+$~', (string) ($parts['path'] ?? ''))
        && empty($parts['query']) && empty($parts['fragment']) ? $url : '';
}

/** chat_id Lark App (oc_…) hợp lệ hoặc ''. */
function child_theme_lark_sanitize_chat_id($value): string {
    $value = trim((string) $value);
    return preg_match('/^oc_[A-Za-z0-9]+$/', $value) ? $value : '';
}

/** App API có mặt (bộ Lark ERP — phương án C). */
function child_theme_lark_app_available(): bool {
    return function_exists('erp_lark_api_post');
}

/**
 * Đích gửi thật của kênh: ['type' => webhook|chat_id, 'value' => …, 'via' => key kênh cung cấp đích] hoặc error.
 * Kênh tắt → không gửi; bật mà để trống đích → dùng đích của kênh dự phòng (không xét bật/tắt của kênh đó).
 */
function child_theme_lark_target(string $key): array {
    $channel = child_theme_lark_channel_config($key);
    if (empty($channel['active'])) { return ['error' => 'Kênh Lark "' . child_theme_lark_label($key) . '" đang tắt.']; }
    $seen = [];
    for ($k = $key; $k !== '' && !isset($seen[$k]); $k = (string) (child_theme_lark_channel_defs()[$k]['fallback'] ?? '')) {
        $seen[$k] = true;
        $c = $k === $key ? $channel : child_theme_lark_channel_config($k);
        if (($c['type'] ?? 'webhook') === 'chat_id') {
            $chat = child_theme_lark_sanitize_chat_id($c['value'] ?? '');
            if ($chat !== '') { return ['type' => 'chat_id', 'value' => $chat, 'via' => $k]; }
        } else {
            $hook = child_theme_lark_sanitize_webhook($c['value'] ?? '');
            if ($hook !== '') { return ['type' => 'webhook', 'value' => $hook, 'via' => $k]; }
        }
    }
    return ['error' => 'Kênh Lark "' . child_theme_lark_label($key) . '" chưa có webhook hợp lệ.'];
}

function child_theme_lark_label(string $key): string {
    $def = child_theme_lark_channel_defs()[$key] ?? [];
    return (string) ($def['label'] ?? $key);
}

/** Kênh bật và có đích hợp lệ. */
function child_theme_lark_ready(string $key): bool {
    return !isset(child_theme_lark_target($key)['error']);
}

/** Phản hồi Lark → '' (thành công) hoặc lỗi không chứa URL webhook. */
function child_theme_lark_response_error($response): string {
    if (is_wp_error($response)) { return 'Không kết nối được Lark; kiểm tra mạng máy chủ và thử lại.'; }
    $http = (int) wp_remote_retrieve_response_code($response);
    if ($http < 200 || $http >= 300) { return 'Webhook HTTP ' . $http; }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($body) || (!isset($body['code']) && !isset($body['StatusCode']))) { return 'Lark trả phản hồi không hợp lệ.'; }
    foreach (['code', 'StatusCode'] as $field) {
        if (isset($body[$field]) && (!is_numeric($body[$field]) || (float) $body[$field] !== 0.0)) {
            $code = is_numeric($body[$field]) ? (int) $body[$field] : 'invalid';
            return 'Lark từ chối tin nhắn (mã ' . $code . '); kiểm tra webhook, từ khóa, chữ ký và IP được phép của bot.';
        }
    }
    return '';
}

/**
 * Gửi 1 tin (payload đúng dạng webhook Lark: msg_type + content | card) vào kênh. '' = Lark đã nhận, ngược lại là lỗi.
 */
function child_theme_lark_send(string $key, array $payload): string {
    $target = child_theme_lark_target($key);
    if (isset($target['error'])) { return $target['error']; }
    $type = (string) ($payload['msg_type'] ?? '');
    if ($type === '') { return 'Tin Lark thiếu msg_type.'; }
    if ($target['type'] === 'chat_id') {
        if (!child_theme_lark_app_available()) { $error = 'Kênh dùng chat_id cần bộ Lark App (erp/integrations/lark) — chưa cài.'; }
        else {
            $content = $type === 'interactive' ? ($payload['card'] ?? []) : ($payload['content'] ?? []);
            $result = erp_lark_api_post('/im/v1/messages?receive_id_type=chat_id', ['receive_id' => $target['value'], 'msg_type' => $type, 'content' => wp_json_encode($content)]);
            $error = !empty($result['ok']) ? '' : 'Lark App từ chối tin nhắn (mã ' . (int) ($result['code'] ?? -1) . ').';
        }
    } else {
        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || strlen($body) > 19000) { $error = 'Nội dung Lark vượt giới hạn hoặc không hợp lệ.'; }
        else {
            $error = child_theme_lark_response_error(wp_remote_post($target['value'], [
                'timeout' => 8, 'redirection' => 0,
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'body' => $body,
            ]));
        }
    }
    child_theme_lark_log($key, $error, $type, $target['via']);
    return $error;
}

function child_theme_lark_text(string $key, string $text): string {
    return child_theme_lark_send($key, ['msg_type' => 'text', 'content' => ['text' => $text]]);
}

/**
 * Thẻ Lark từ dữ liệu cùng dạng erp_lark_notify() của bộ ERP: title, body (Lark markdown), status
 * info|success|warning|danger|neutral, url, actions [[label, url, type]], fields [[label, value]].
 */
function child_theme_lark_build_card(array $data): array {
    if (function_exists('erp_lark_build_card')) { return erp_lark_build_card($data); }
    $templates = ['info' => 'blue', 'success' => 'green', 'warning' => 'orange', 'danger' => 'red', 'neutral' => 'grey'];
    $elements = [];
    if (($data['body'] ?? '') !== '') { $elements[] = ['tag' => 'div', 'text' => ['tag' => 'lark_md', 'content' => (string) $data['body']]]; }
    $fields = [];
    foreach ((array) ($data['fields'] ?? []) as $field) {
        if (is_array($field) && isset($field['label'])) {
            $fields[] = ['is_short' => true, 'text' => ['tag' => 'lark_md', 'content' => '**' . $field['label'] . "**\n" . ($field['value'] ?? '')]];
        }
    }
    if ($fields) { $elements[] = ['tag' => 'div', 'fields' => $fields]; }
    $buttons = [];
    foreach ((array) ($data['actions'] ?? []) as $action) {
        if (!empty($action['label']) && !empty($action['url'])) {
            $buttons[] = ['tag' => 'button', 'text' => ['tag' => 'plain_text', 'content' => (string) $action['label']], 'url' => (string) $action['url'],
                'type' => in_array($action['type'] ?? '', ['primary', 'danger'], true) ? $action['type'] : 'default'];
        }
    }
    if (!$buttons && !empty($data['url'])) { $buttons[] = ['tag' => 'button', 'text' => ['tag' => 'plain_text', 'content' => 'Xem chi tiết'], 'url' => (string) $data['url'], 'type' => 'default']; }
    if ($buttons) { $elements[] = ['tag' => 'hr']; $elements[] = ['tag' => 'action', 'actions' => $buttons]; }
    return ['config' => ['wide_screen_mode' => true],
        'header' => ['title' => ['tag' => 'plain_text', 'content' => (string) ($data['title'] ?? 'Thông báo')], 'template' => $templates[$data['status'] ?? 'info'] ?? 'blue'],
        'elements' => $elements];
}

function child_theme_lark_notify(string $key, array $data): string {
    return child_theme_lark_send($key, ['msg_type' => 'interactive', 'card' => child_theme_lark_build_card($data)]);
}

/** Nhật ký gửi: lần gần nhất mỗi kênh + 30 lượt gần nhất. Không lưu URL / nội dung tin. */
function child_theme_lark_log(string $key, string $error, string $type, string $via): void {
    $log = get_option('child_theme_lark_log', []);
    $log = is_array($log) ? $log : [];
    $entry = ['state' => $error === '' ? 'sent' : 'failed', 'error' => $error, 'at' => time(), 'msg_type' => $type, 'via' => $via];
    $log['last'][$key] = $entry;
    $log['recent'] = array_slice(array_merge([['key' => $key] + $entry], (array) ($log['recent'] ?? [])), 0, 30);
    update_option('child_theme_lark_log', $log, false);
}

function child_theme_lark_last(string $key): array {
    $log = get_option('child_theme_lark_log', []);
    return is_array($log) && is_array($log['last'][$key] ?? null) ? $log['last'][$key] : [];
}

/**
 * Chuyển webhook riêng của từng tính năng (trước khi có kênh) sang kênh, 1 lần. Không đè kênh đã có.
 * kho ← *_export_lark_settings (enabled + webhook_url); shopee_ai ← spx_ai_alerts (dùng nhóm kho);
 * uu_dai ← *_offers_lark_settings (nhóm riêng: chỉ bật khi có webhook riêng); cro ← hithean_cro_landing_settings.lark_webhook.
 */
function child_theme_lark_migrate(): void {
    if (!empty($GLOBALS['child_theme_lark_migrating']) || (int) get_option('child_theme_lark_migrated', 0) >= CHILD_THEME_LARK_MIGRATION) { return; }
    $GLOBALS['child_theme_lark_migrating'] = true;
    try {
        $settings = child_theme_lark_settings();
        $have = array_column($settings['channels'], 'key');
        $add = static function (string $key, string $value, bool $active) use (&$settings, &$have): void {
            if (in_array($key, $have, true)) { return; }
            $settings['channels'][] = ['key' => $key, 'label' => child_theme_lark_label($key), 'type' => 'webhook',
                'value' => child_theme_lark_sanitize_webhook($value), 'active' => $active];
            $have[] = $key;
        };
        foreach (['hithean_export_lark_settings', 'thean_export_lark_settings'] as $option) {
            $old = get_option($option, null);
            if (!is_array($old)) { continue; }
            $hook = (string) ($old['webhook_url'] ?? '');
            $add('kho', $hook, !empty($old['enabled']) && $old['enabled'] !== '0' && child_theme_lark_sanitize_webhook($hook) !== '');
            if (array_key_exists('spx_ai_alerts', $old)) { $add('shopee_ai', '', $old['spx_ai_alerts'] !== '0'); }
        }
        foreach (['hithean_offers_lark_settings', 'thean_offers_lark_settings'] as $option) {
            $old = get_option($option, null);
            if (!is_array($old)) { continue; }
            $hook = child_theme_lark_sanitize_webhook($old['webhook_url'] ?? '');
            $add('uu_dai', $hook, !empty($old['enabled']) && $hook !== '');
        }
        $cro = get_option('hithean_cro_landing_settings', null);
        if (is_array($cro) && !empty($cro['lark_webhook'])) {
            $hook = child_theme_lark_sanitize_webhook($cro['lark_webhook']);
            $add('cro', $hook, $hook !== '');
        }
        update_option('erp_lark_settings', $settings, false);
        update_option('child_theme_lark_migrated', CHILD_THEME_LARK_MIGRATION, true); // autoload: không tốn truy vấn mỗi request
    } finally { unset($GLOBALS['child_theme_lark_migrating']); }
}

/** Tab Cài đặt ERP → Lark: kênh của mọi tính năng + ô riêng của tính năng (hook) + gửi thử + nhật ký. */
function child_theme_lark_settings_tab(string $page_url): void {
    if (!current_user_can('manage_woocommerce')) { return; }
    $defs = child_theme_lark_channel_defs();
    $notices = [];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['child_theme_lark_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['child_theme_lark_nonce'])), 'child_theme_lark_save')) {
        $posted = wp_unslash($_POST);
        foreach ($defs as $key => $def) {
            $row = (array) ($posted['child_theme_lark'][$key] ?? []);
            $old = child_theme_lark_channel_config($key);
            $type = ($row['type'] ?? 'webhook') === 'chat_id' ? 'chat_id' : 'webhook';
            $raw = trim((string) ($row['value'] ?? ''));
            $value = $type === 'chat_id' ? child_theme_lark_sanitize_chat_id($raw) : child_theme_lark_sanitize_webhook($raw);
            if ($raw !== '' && $value === '') {
                $notices[] = ['error', '"' . $def['label'] . '": ' . ($type === 'chat_id' ? 'chat_id cần dạng oc_…' : 'webhook cần dạng https://open.larksuite.com/open-apis/bot/v2/hook/<token>') . ' — giữ cấu hình cũ.'];
                continue;
            }
            $next = ['key' => $key, 'label' => $def['label'], 'type' => $type, 'value' => $value, 'active' => !empty($row['active'])];
            child_theme_lark_save_channel($next);
            if ($next['active'] && empty($old['active'])) { do_action('child_theme_lark_channel_enabled', $key); }
        }
        do_action('child_theme_lark_settings_save', $posted);
        $notices[] = ['success', '✅ Đã lưu cài đặt Lark.'];
        // Gửi thử chạy SAU khi lưu: webhook vừa dán được dùng ngay.
        $test = sanitize_key((string) ($posted['child_theme_lark_test'] ?? ''));
        if ($test !== '' && isset($defs[$test])) {
            $error = child_theme_lark_notify($test, ['title' => '✅ Kết nối Lark — ' . child_theme_lark_label($test),
                'body' => 'Tin gửi thử từ ' . home_url('/') . '. Kênh này nhận: ' . ($defs[$test]['description'] ?: child_theme_lark_label($test)) . '.', 'status' => 'success']);
            $notices[] = $error === '' ? ['success', 'Đã gửi thử kênh "' . child_theme_lark_label($test) . '".'] : ['error', 'Gửi thử "' . child_theme_lark_label($test) . '": ' . $error];
        }
    }
    foreach ($notices as [$kind, $text]) { echo '<div class="notice notice-' . esc_attr($kind) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>'; }
    echo '<form method="post" action="' . esc_url($page_url) . '">';
    wp_nonce_field('child_theme_lark_save', 'child_theme_lark_nonce');
    echo '<h2>Kênh thông báo Lark</h2><p class="description">Mỗi kênh là 1 nhóm Lark (Custom Bot → webhook). Kênh có "dùng nhóm …" để trống webhook thì gửi vào nhóm đó.'
        . (child_theme_lark_app_available() ? ' Có Lark App: kênh có thể dùng chat_id (oc_…).' : '') . '</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Kênh</th><th>Bật</th><th>Webhook' . (child_theme_lark_app_available() ? ' / chat_id' : '') . '</th><th>Lần gửi gần nhất</th><th></th></tr></thead><tbody>';
    foreach ($defs as $key => $def) {
        $c = child_theme_lark_channel_config($key); $last = child_theme_lark_last($key);
        $fallback = $def['fallback'] !== '' ? ' <em>(trống = dùng nhóm "' . esc_html(child_theme_lark_label($def['fallback'])) . '")</em>' : '';
        echo '<tr><td><strong>' . esc_html($def['label']) . '</strong><br><small>' . esc_html($def['description']) . '</small></td>'
            . '<td><input type="checkbox" name="child_theme_lark[' . esc_attr($key) . '][active]" value="1" ' . checked(!empty($c['active']), true, false) . '></td><td>';
        if (child_theme_lark_app_available()) {
            echo '<select name="child_theme_lark[' . esc_attr($key) . '][type]"><option value="webhook">Webhook</option><option value="chat_id" ' . selected($c['type'], 'chat_id', false) . '>chat_id (App)</option></select> ';
        }
        echo '<input type="text" class="large-text" style="max-width:520px" name="child_theme_lark[' . esc_attr($key) . '][value]" value="' . esc_attr((string) $c['value']) . '" placeholder="https://open.larksuite.com/open-apis/bot/v2/hook/...">' . $fallback . '</td><td>';
        if ($last) { echo esc_html(($last['state'] === 'sent' ? '✅ ' : '❌ ') . wp_date('d/m H:i', (int) $last['at'])) . ($last['error'] !== '' ? '<br><small>' . esc_html($last['error']) . '</small>' : ''); }
        else { echo '—'; }
        echo '</td><td><button class="button" name="child_theme_lark_test" value="' . esc_attr($key) . '">Gửi thử</button></td></tr>';
    }
    echo '</tbody></table>';
    do_action('child_theme_lark_settings_fields');
    echo '<p><button class="button button-primary">Lưu cài đặt Lark</button></p></form>';
}

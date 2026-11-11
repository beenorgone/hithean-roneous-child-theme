<?php
/**
 * Thông báo nhóm Lark khi trang /uu-dai/ đổi chương trình ưu đãi.
 *
 * Nguồn sự kiện duy nhất: option snapshot công khai (HITHEAN_OFFERS_SNAPSHOT) đổi — bao phủ duyệt / loại / ghim / banner,
 * lượt đồng bộ tuần, mốc hết hạn (hithean_offers_boundary) và "Khôi phục snapshot trước".
 * Gom 5 phút (HITHEAN_OFFERS_LARK_DELAY): mọi thay đổi trong khoảng đó thành 1 tin, so với "mốc đã báo"
 * (option hithean_offers_lark_state) chứ không so từng lần publish → duyệt 5 thẻ liên tiếp = 1 tin.
 * Lần đầu bật chỉ ghi mốc, không gửi cả danh sách. Gửi lỗi giữ mốc cũ, thử lại tối đa 3 lần.
 * Gửi qua kênh Lark "uu_dai" (core/lark.php) — NHÓM RIÊNG, không dùng nhóm kho. Bật/tắt + webhook: Cài đặt ERP → Lark;
 * cùng tab có "Nhắc @Tất cả" (option hithean_offers_lark_settings.mention_all) + "Gửi thử danh sách ưu đãi".
 */
defined('ABSPATH') || exit;

const HITHEAN_OFFERS_LARK_DELAY = 5 * 60;
const HITHEAN_OFFERS_LARK_RETRIES = 3;
const HITHEAN_OFFERS_LARK_MAX_LINES = 15; // dòng / nhóm, giữ tin dưới giới hạn ~20KB của Lark

child_theme_lark_register_channel('uu_dai', [
    'label' => 'Ưu đãi (nhân sự)',
    'description' => 'Trang /uu-dai/ đổi chương trình: mới / sửa nội dung, thời gian, sản phẩm / hết hạn, gỡ — nhóm riêng',
]);

/** enabled = kênh "uu_dai" bật + có webhook; mention_all lưu ở option riêng của ưu đãi. */
function hithean_offers_lark_settings(): array {
    child_theme_lark_load(); // cron / admin: nạp core Lark đúng lúc
    $saved = get_option('hithean_offers_lark_settings', []);
    $saved = is_array($saved) ? $saved : [];
    return ['enabled' => child_theme_lark_ready('uu_dai'), 'mention_all' => !empty($saved['mention_all'])];
}

/**
 * Snapshot (dòng đã bung theo sản phẩm) → tóm tắt theo chương trình: [id => name, kind, family, start, end, products [id => tên]].
 * Hàm thuần — có test.
 */
function hithean_offers_lark_programs(array $items): array {
    $programs = [];
    foreach ($items as $row) {
        if (!is_array($row)) { continue; }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') { continue; }
        $name = trim((string) ($row['source_title'] ?? '')) ?: trim((string) ($row['title'] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name);
        if (mb_strlen($name) > 160) { $name = rtrim(mb_substr($name, 0, 157)) . '…'; }
        $programs[$id] = $programs[$id] ?? [
            'name' => $name !== '' ? $name : 'Ưu đãi #' . $id,
            'title' => (string) ($row['title'] ?? ''),
            'kind' => (string) ($row['kind'] ?? 'other'), 'family' => (string) ($row['family'] ?? ''),
            'start' => (int) ($row['start'] ?? 0), 'end' => (int) ($row['end'] ?? 0), 'products' => [],
        ];
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid) { $programs[$id]['products'][$pid] = (string) ($row['product_name'] ?? ('SP #' . $pid)); }
    }
    foreach ($programs as &$program) { ksort($program['products']); }
    unset($program);
    ksort($programs);
    return $programs;
}

/** So 2 tóm tắt → ['added' => [...], 'changed' => [[old, new, changes[]]], 'ended' => [...]]. Hàm thuần — có test. */
function hithean_offers_lark_diff(array $old, array $new): array {
    $diff = ['added' => [], 'changed' => [], 'ended' => []];
    foreach ($new as $id => $program) {
        if (!isset($old[$id])) { $diff['added'][$id] = $program; continue; }
        $before = $old[$id]; $changes = [];
        if ($before['title'] !== $program['title']) { $changes[] = 'nội dung'; }
        if ($before['start'] !== $program['start'] || $before['end'] !== $program['end']) { $changes[] = 'thời gian'; }
        $plus = array_diff_key($program['products'], $before['products']);
        $minus = array_diff_key($before['products'], $program['products']);
        if ($changes || $plus || $minus) {
            $diff['changed'][$id] = ['old' => $before, 'new' => $program, 'fields' => $changes, 'plus' => $plus, 'minus' => $minus];
        }
    }
    foreach ($old as $id => $program) {
        if (!isset($new[$id])) { $diff['ended'][$id] = $program; }
    }
    return $diff;
}

function hithean_offers_lark_is_empty(array $diff): bool {
    return !$diff['added'] && !$diff['changed'] && !$diff['ended'];
}

function hithean_offers_lark_date(int $ts): string {
    return $ts ? wp_date('d/m/Y', $ts) : '';
}

function hithean_offers_lark_period(array $program): string {
    $start = hithean_offers_lark_date($program['start']); $end = hithean_offers_lark_date($program['end']);
    if ($start && $end) { return $start . ' → ' . $end; }
    if ($end) { return 'đến hết ' . $end; }
    if ($start) { return 'từ ' . $start . ', chưa có ngày kết thúc'; }
    return 'không thời hạn';
}

/** Tối đa $max tên SP, còn lại "+N SP". */
function hithean_offers_lark_names(array $products, int $max = 4): string {
    $names = array_values($products);
    $text = implode(', ', array_slice($names, 0, $max));
    return count($names) > $max ? $text . ' +' . (count($names) - $max) . ' SP' : $text;
}

/**
 * Nội dung tin (Lark "post": tiêu đề + các dòng chữ / link). Hàm thuần — có test.
 * $ctx: url (trang /uu-dai/), admin_url, total_programs, total_products, preview (trang chưa công khai), now, mention_all, test.
 */
function hithean_offers_lark_message(array $diff, array $ctx): array {
    $lines = [];
    $line = static function (string $text) use (&$lines): void { $lines[] = [['tag' => 'text', 'text' => $text]]; };
    $now = (int) ($ctx['now'] ?? time());
    $max = HITHEAN_OFFERS_LARK_MAX_LINES;
    $more = static function (array $group) use ($line, $max): void {
        if (count($group) > $max) { $line('  … và ' . (count($group) - $max) . ' chương trình khác (xem ở trang quản lý ưu đãi).'); }
    };
    if (!empty($ctx['mention_all'])) { $lines[] = [['tag' => 'at', 'user_id' => 'all']]; }
    if (!empty($ctx['test'])) { $line('🧪 Tin gửi thử — danh sách chương trình đang hiện trên trang.'); }
    if (!empty($ctx['preview'])) { $line('⚠️ Trang /uu-dai/ đang ở chế độ xem trước — khách chưa thấy thay đổi này.'); }

    if ($diff['added']) {
        $line('🆕 Chương trình mới (' . count($diff['added']) . ')');
        foreach (array_slice($diff['added'], 0, $max, true) as $p) {
            $line('• ' . $p['name'] . ' — ' . hithean_offers_lark_period($p) . ' · ' . count($p['products']) . ' SP: ' . hithean_offers_lark_names($p['products']));
        }
        $more($diff['added']);
    }
    if ($diff['changed']) {
        $line('✏️ Thay đổi (' . count($diff['changed']) . ')');
        foreach (array_slice($diff['changed'], 0, $max, true) as $c) {
            $parts = [];
            if (in_array('nội dung', $c['fields'], true)) { $parts[] = 'nội dung mới: ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $c['new']['title']), 0, 200, '…'); }
            if (in_array('thời gian', $c['fields'], true)) { $parts[] = 'thời gian ' . hithean_offers_lark_period($c['old']) . ' ⇒ ' . hithean_offers_lark_period($c['new']); }
            if ($c['plus']) { $parts[] = 'thêm ' . count($c['plus']) . ' SP: ' . hithean_offers_lark_names($c['plus']); }
            if ($c['minus']) { $parts[] = 'bỏ ' . count($c['minus']) . ' SP: ' . hithean_offers_lark_names($c['minus']); }
            $line('• ' . $c['new']['name'] . ' — ' . implode('; ', $parts));
        }
        $more($diff['changed']);
    }
    if ($diff['ended']) {
        $line('⛔ Ngừng hiện trên trang (' . count($diff['ended']) . ')');
        foreach (array_slice($diff['ended'], 0, $max, true) as $p) {
            $why = $p['end'] && $p['end'] < $now ? 'hết hạn ' . hithean_offers_lark_date($p['end']) : 'đã gỡ / tạm ẩn';
            $line('• ' . $p['name'] . ' — ' . $why . ' · ' . count($p['products']) . ' SP: ' . hithean_offers_lark_names($p['products']));
        }
        $more($diff['ended']);
    }
    $line('Đang áp dụng: ' . (int) ($ctx['total_programs'] ?? 0) . ' chương trình trên ' . (int) ($ctx['total_products'] ?? 0) . ' sản phẩm.');
    $links = [];
    if (!empty($ctx['url'])) { $links[] = ['tag' => 'a', 'text' => '👉 Xem trang ưu đãi', 'href' => $ctx['url']]; }
    if (!empty($ctx['admin_url'])) {
        if ($links) { $links[] = ['tag' => 'text', 'text' => '  ·  ']; }
        $links[] = ['tag' => 'a', 'text' => 'Quản lý ưu đãi', 'href' => $ctx['admin_url']];
    }
    if ($links) { $lines[] = $links; }

    $title = '🎁 Cập nhật chương trình ưu đãi — ' . wp_date('H:i d/m/Y', $now);
    return ['msg_type' => 'post', 'content' => ['post' => ['vi_vn' => ['title' => $title, 'content' => $lines]]]];
}

function hithean_offers_lark_current(): array {
    $snapshot = (array) get_option(HITHEAN_OFFERS_SNAPSHOT, []);
    return hithean_offers_lark_programs((array) ($snapshot['items'] ?? []));
}

function hithean_offers_lark_context(array $programs, bool $test = false): array {
    $products = [];
    foreach ($programs as $p) { $products += $p['products']; }
    $settings = hithean_offers_lark_settings();
    return ['url' => home_url('/uu-dai/'), 'admin_url' => admin_url('admin.php?page=hithean-offers'),
        'total_programs' => count($programs), 'total_products' => count($products),
        'preview' => !empty(hithean_offers_settings()['preview']), 'now' => time(),
        'mention_all' => !empty($settings['mention_all']), 'test' => $test];
}

/** Gửi qua kênh "uu_dai". '' = thành công. */
function hithean_offers_lark_post(array $message): string {
    child_theme_lark_load();
    return child_theme_lark_send('uu_dai', $message);
}

/** Snapshot vừa đổi → hẹn gửi sau HITHEAN_OFFERS_LARK_DELAY (gom các thay đổi liền nhau). */
function hithean_offers_lark_schedule(): void {
    if (empty(hithean_offers_lark_settings()['enabled'])) { return; }
    if (!wp_next_scheduled('hithean_offers_lark_flush')) {
        wp_schedule_single_event(time() + HITHEAN_OFFERS_LARK_DELAY, 'hithean_offers_lark_flush');
    }
}

/** So snapshot hiện tại với mốc đã báo, gửi 1 tin nếu khác. Thành công → dời mốc. */
function hithean_offers_lark_flush(): void {
    $settings = hithean_offers_lark_settings();
    if (empty($settings['enabled'])) { return; }
    $token = hithean_offers_lock('lark', 60);
    if (!$token) { wp_schedule_single_event(time() + 90, 'hithean_offers_lark_flush'); return; }
    try {
        $state = (array) get_option('hithean_offers_lark_state', []);
        $current = hithean_offers_lark_current();
        if (!isset($state['programs']) || !is_array($state['programs'])) {
            // Lần đầu: chỉ ghi mốc, không gửi cả danh sách đang có.
            update_option('hithean_offers_lark_state', ['programs' => $current, 'at' => time(), 'attempts' => 0, 'error' => ''], false);
            return;
        }
        $diff = hithean_offers_lark_diff($state['programs'], $current);
        if (hithean_offers_lark_is_empty($diff)) { return; }
        $error = hithean_offers_lark_post(hithean_offers_lark_message($diff, hithean_offers_lark_context($current)));
        if ($error === '') {
            update_option('hithean_offers_lark_state', ['programs' => $current, 'at' => time(), 'attempts' => 0, 'error' => '', 'sent_at' => time()], false);
            return;
        }
        $attempts = (int) ($state['attempts'] ?? 0) + 1;
        $state['attempts'] = $attempts; $state['error'] = $error; $state['failed_at'] = time();
        if ($attempts >= HITHEAN_OFFERS_LARK_RETRIES) {
            // Bỏ lượt này để không kẹt: dời mốc, ghi lỗi cho trang quản lý.
            $state['programs'] = $current; $state['attempts'] = 0;
        } else {
            wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS * $attempts, 'hithean_offers_lark_flush');
        }
        update_option('hithean_offers_lark_state', $state, false);
    } finally { hithean_offers_unlock('lark', $token); }
}

/** Nút "Gửi thử": gửi danh sách đang áp dụng như "chương trình mới", không đổi mốc. */
function hithean_offers_lark_test(): string {
    $current = hithean_offers_lark_current();
    $diff = ['added' => $current, 'changed' => [], 'ended' => []];
    return hithean_offers_lark_post(hithean_offers_lark_message($diff, hithean_offers_lark_context($current, true)));
}

/** Bật kênh từ tắt: ghi mốc = snapshot hiện tại để tin đầu chỉ chứa thay đổi về sau. */
function hithean_offers_lark_reset_baseline(): void {
    update_option('hithean_offers_lark_state', ['programs' => hithean_offers_lark_current(), 'at' => time(), 'attempts' => 0, 'error' => ''], false);
}

add_action('add_option_' . HITHEAN_OFFERS_SNAPSHOT, 'hithean_offers_lark_schedule');
add_action('update_option_' . HITHEAN_OFFERS_SNAPSHOT, static function ($old, $new): void {
    $items = static fn($snapshot): array => is_array($snapshot) && is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
    if ($items($old) !== $items($new)) { hithean_offers_lark_schedule(); }
}, 10, 2);
add_action('hithean_offers_lark_flush', 'hithean_offers_lark_flush');
// Vừa bật kênh ở Cài đặt ERP → Lark: lấy danh sách hiện tại làm mốc, tin đầu tiên chỉ báo thay đổi từ giờ.
add_action('child_theme_lark_channel_enabled', static function (string $key): void {
    if ($key === 'uu_dai') { hithean_offers_lark_reset_baseline(); }
});
add_action('child_theme_lark_settings_fields', static function (): void {
    $lark = hithean_offers_lark_settings(); $state = (array) get_option('hithean_offers_lark_state', []);
    echo '<h3>Ưu đãi</h3><p><label><input type="checkbox" name="hithean_offers_lark_mention_all" value="1" ' . checked($lark['mention_all'], true, false) . '> Tin ưu đãi nhắc @Tất cả</label></p>'
        . '<p><button class="button" name="hithean_offers_lark_test" value="1">Gửi thử danh sách ưu đãi đang hiện</button></p>';
    if (!empty($state['sent_at'])) { echo '<p class="description">Tin ưu đãi gần nhất: ' . esc_html(wp_date('d/m/Y H:i', (int) $state['sent_at'])) . '</p>'; }
    if (!empty($state['error'])) { echo '<p class="description" style="color:#b32d2e">Lỗi lúc ' . esc_html(wp_date('d/m/Y H:i', (int) ($state['failed_at'] ?? 0))) . ': ' . esc_html($state['error']) . '</p>'; }
});
add_action('child_theme_lark_settings_save', static function (array $posted): void {
    update_option('hithean_offers_lark_settings', ['mention_all' => !empty($posted['hithean_offers_lark_mention_all'])], false);
    if (!empty($posted['hithean_offers_lark_test'])) {
        $error = hithean_offers_lark_test();
        echo '<div class="notice notice-' . ($error === '' ? 'success' : 'error') . '"><p>' . esc_html($error === '' ? 'Đã gửi thử danh sách ưu đãi vào nhóm Lark.' : 'Gửi thử ưu đãi: ' . $error) . '</p></div>';
    }
});

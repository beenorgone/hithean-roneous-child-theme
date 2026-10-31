<?php
if (!defined('ABSPATH')) exit;

/**
 * 🔒 Khóa đổi trạng thái đơn cũ: tạo trước NGÀY MỐC chọn ở Cài đặt ERP → Xử lý đơn → "Khóa đơn cũ"
 * (option thean_order_age_lock_cutoff, Y-m-d); để trống = tạo hơn THEAN_ORDER_STATUS_LOCK_DAYS (365) ngày.
 * Board "Tải đơn cần xử lý" dùng cùng mốc này (thean_order_lock_cutoff_ts).
 *
 * - Tiến trình tự động (cron, WP-CLI, REST — VD hub eim đối soát / đổi trạng thái, Action Scheduler, request không
 *   đăng nhập như webhook / IPN) KHÔNG BAO GIỜ được đổi, kể cả khi chạy bằng tài khoản admin.
 * - Người dùng: chỉ admin (manage_options) thao tác tay được đổi.
 * Chặn ở tầng save của WC_Order (woocommerce_before_order_object_save) nên áp dụng cho mọi nơi đổi trạng thái; lần bị chặn
 * do tiến trình tự động ghi ghi chú riêng tư vào đơn. Dây chuyền tự động (order-auto-tasks.php) bỏ qua đơn cũ.
 * Tạm nới: Cài đặt ERP → Xử lý đơn → "Khóa đơn cũ" (option thean_order_age_lock_relax = [until, by]) tắt khóa tới hết hạn
 * (1 giờ … 3 ngày) — cả người dùng lẫn tiến trình tự động đổi được, dây chuyền tự động chạy lại.
 * Dùng chung theanmarket + hithean; nạp mọi request.
 */

const THEAN_ORDER_STATUS_LOCK_DAYS = 365;

/** Ngày mốc khóa chọn ở Cài đặt ERP (Y-m-d) — '' = dùng mặc định THEAN_ORDER_STATUS_LOCK_DAYS ngày. */
function thean_order_lock_cutoff_date(): string
{
    $date = (string) get_option('thean_order_age_lock_cutoff', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
}

/** Mốc khóa (timestamp): đơn tạo TRƯỚC mốc là đơn cũ. Ngày mốc tính từ 00:00 theo múi giờ site. */
function thean_order_lock_cutoff_ts(): int
{
    $date = thean_order_lock_cutoff_date();
    if ($date !== '') {
        try {
            return (new DateTimeImmutable($date . ' 00:00:00', function_exists('wp_timezone') ? wp_timezone() : null))->getTimestamp();
        } catch (Exception $e) {
            // ngày hỏng → mặc định
        }
    }
    return time() - THEAN_ORDER_STATUS_LOCK_DAYS * DAY_IN_SECONDS;
}

/** Mô tả mốc cho thông báo: "tạo trước 01/01/2025" | "tạo hơn 365 ngày". */
function thean_order_lock_label(): string
{
    $date = thean_order_lock_cutoff_date();
    return $date !== '' ? 'tạo trước ' . date('d/m/Y', strtotime($date)) : 'tạo hơn ' . THEAN_ORDER_STATUS_LOCK_DAYS . ' ngày';
}

/** Khóa đang tạm nới tới bao giờ (timestamp; 0 = không nới). */
function thean_order_age_lock_relaxed_until(): int
{
    $relax = (array) get_option('thean_order_age_lock_relax', []);
    $until = (int) ($relax['until'] ?? 0);
    return $until > time() ? $until : 0;
}

/** Đơn cũ (tạo trước mốc khóa)? */
function thean_order_is_old($order): bool
{
    if (!$order instanceof WC_Order || !$order->get_id()) {
        return false;
    }
    $created = $order->get_date_created();
    return $created && $created->getTimestamp() < thean_order_lock_cutoff_ts();
}

/** Request hiện tại là tiến trình tự động (không phải người thao tác tay)? Trả nguồn ('' = người dùng). */
function thean_order_change_source(): string
{
    if (wp_doing_cron()) {
        return 'cron';
    }
    if (defined('WP_CLI') && WP_CLI) {
        return 'WP-CLI';
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return 'REST API';
    }
    if (did_action('action_scheduler_before_execute') > did_action('action_scheduler_after_execute')) {
        return 'Action Scheduler';
    }
    if (!is_user_logged_in()) {
        return 'request không đăng nhập (webhook / IPN)';
    }
    return '';
}

/**
 * Đơn bị khóa đổi trạng thái trong request này? Đơn cũ + (tiến trình tự động, hoặc người dùng không phải admin).
 * Các màn hình gọi hàm này để ẩn / chặn nút đổi trạng thái.
 */
function thean_order_status_is_locked($order): bool
{
    if (!thean_order_is_old($order) || thean_order_age_lock_relaxed_until()) {
        return false;
    }
    return thean_order_change_source() !== '' || !current_user_can('manage_options');
}

add_action('woocommerce_before_order_object_save', function ($order) {
    if (!$order instanceof WC_Order) {
        return;
    }
    $changes = $order->get_changes();
    if (!array_key_exists('status', $changes)) {
        return;
    }
    $original = $order->get_data(); // giá trị trước khi thay đổi (changes nằm riêng)
    if ($changes['status'] === $original['status'] || !thean_order_status_is_locked($order)) {
        return;
    }

    // Khôi phục trạng thái cũ + các mốc ngày WC tự set theo trạng thái mới
    $order->set_status($original['status']);
    foreach (['date_paid', 'date_completed'] as $prop) {
        if (array_key_exists($prop, $changes)) {
            $order->{"set_$prop"}($original[$prop]);
        }
    }

    // Hủy status transition để không bắn hook / ghi note "đổi trạng thái"
    \Closure::bind(function () {
        $this->status_transition = false;
    }, $order, WC_Order::class)();

    $source = thean_order_change_source();
    if ($source !== '') {
        // Tiến trình tự động: để lại dấu vết trên đơn (1 lần / đơn / request).
        static $noted = [];
        if (empty($noted[$order->get_id()])) {
            $noted[$order->get_id()] = true;
            $order->add_order_note(sprintf(
                '⛔ Chặn %s đổi trạng thái %s → %s: đơn %s, tiến trình tự động không được đổi.',
                $source,
                wc_get_order_status_name($original['status']),
                wc_get_order_status_name($changes['status']),
                thean_order_lock_label()
            ), false, false);
        }
        return;
    }

    $user_id = get_current_user_id();
    if ($user_id) {
        $key = 'thean_order_status_locked_' . $user_id;
        $blocked = (array) get_transient($key);
        $blocked[] = $order->get_order_number();
        set_transient($key, array_values(array_unique(array_filter($blocked))), 5 * MINUTE_IN_SECONDS);
    }
}, 1);

add_action('admin_notices', function () {
    $key = 'thean_order_status_locked_' . get_current_user_id();
    $blocked = get_transient($key);
    if (empty($blocked)) {
        return;
    }
    delete_transient($key);

    printf(
        '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
        esc_html(sprintf(
            'Không thể đổi trạng thái đơn %s: đơn %s, chỉ admin được phép thay đổi.',
            '#' . implode(', #', $blocked),
            thean_order_lock_label()
        ))
    );
});

/**
 * Mục "Khóa đơn cũ" trong Cài đặt ERP → Xử lý đơn (gọi từ thean_order_ops_settings_section, order-ops-step.php):
 * tạm nới khóa có thời hạn / khóa lại ngay.
 */
function thean_order_age_lock_settings_section(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $durations = [HOUR_IN_SECONDS => '1 giờ', 4 * HOUR_IN_SECONDS => '4 giờ', DAY_IN_SECONDS => '1 ngày', 3 * DAY_IN_SECONDS => '3 ngày'];
    $notice = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['thean_age_lock_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['thean_age_lock_nonce'])), 'thean_age_lock_save')
        && isset($_POST['thean_age_lock_cutoff_save'])) {
        $date = sanitize_text_field(wp_unslash($_POST['thean_age_lock_cutoff'] ?? ''));
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            update_option('thean_order_age_lock_cutoff', $date, false);
            $notice = $date === '' ? '✅ Mốc khóa: mặc định ' . THEAN_ORDER_STATUS_LOCK_DAYS . ' ngày gần nhất.' : '✅ Mốc khóa: đơn tạo trước ' . date('d/m/Y', strtotime($date)) . '.';
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['thean_age_lock_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['thean_age_lock_nonce'])), 'thean_age_lock_save')) {
        $seconds = absint($_POST['thean_age_lock_relax'] ?? 0);
        if (isset($durations[$seconds])) {
            update_option('thean_order_age_lock_relax', ['until' => time() + $seconds, 'by' => get_current_user_id()], false);
            $notice = '🔓 Đã tạm nới khóa đơn cũ trong ' . $durations[$seconds] . '.';
        } else {
            delete_option('thean_order_age_lock_relax');
            $notice = '🔒 Đã khóa lại đơn cũ.';
        }
    }
    $until = thean_order_age_lock_relaxed_until();
    $by    = $until ? get_userdata((int) (((array) get_option('thean_order_age_lock_relax', []))['by'] ?? 0)) : null;
    ?>
    <?php if ($notice) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
    <form method="post">
        <?php wp_nonce_field('thean_age_lock_save', 'thean_age_lock_nonce'); ?>
        <h2>Khóa đơn cũ (<?php echo esc_html(thean_order_lock_label()); ?>)</h2>
        <table class="form-table" role="presentation"><tbody><tr>
            <th scope="row"><label for="thean-age-lock-cutoff">Ngày mốc khóa</label></th>
            <td>
                <input type="date" id="thean-age-lock-cutoff" name="thean_age_lock_cutoff" value="<?php echo esc_attr(thean_order_lock_cutoff_date()); ?>">
                <button type="submit" class="button" name="thean_age_lock_cutoff_save" value="1">Lưu mốc</button>
                <p class="description">Đơn tạo <strong>trước</strong> ngày này bị khóa đổi trạng thái và không vào board "Tải đơn cần xử lý".
                    Để trống = <?php echo (int) THEAN_ORDER_STATUS_LOCK_DAYS; ?> ngày gần nhất (mốc trượt theo ngày).</p>
            </td>
        </tr><tr>
            <th scope="row">Trạng thái khóa</th>
            <td>
                <?php if ($until) : ?>
                    <p><strong style="color:#b45309">🔓 Đang tạm nới</strong> tới <?php echo esc_html(wp_date('H:i d/m/Y', $until)); ?>
                        (còn <?php echo esc_html(human_time_diff(time(), $until)); ?>)<?php echo $by ? ' — bật bởi ' . esc_html($by->display_name) : ''; ?>.</p>
                    <p><button type="submit" class="button button-primary" name="thean_age_lock_relax" value="0">🔒 Khóa lại ngay</button></p>
                <?php else : ?>
                    <p><strong style="color:#087443">🔒 Đang khóa</strong>: tiến trình tự động (cron, REST / hub eim, Action Scheduler, webhook) không được đổi trạng thái
                        đơn cũ; người dùng chỉ admin đổi được.</p>
                    <p>Tạm nới (mọi người dùng quản lý đơn + tiến trình tự động đổi được, dây chuyền tự động chạy lại) trong:
                        <?php foreach ($durations as $sec => $label) : ?>
                            <button type="submit" class="button" name="thean_age_lock_relax" value="<?php echo (int) $sec; ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
                <p class="description">Hết hạn tự khóa lại. Board "Tải đơn cần xử lý" luôn bỏ đơn cũ (theo mốc trên), kể cả khi đang nới.</p>
            </td>
        </tr></tbody></table>
    </form>
    <?php
}

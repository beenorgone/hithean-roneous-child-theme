<?php
/**
 * Run with php tests/order-age-lock.php. No WordPress bootstrap needed —
 * khóa đổi trạng thái đơn tạo >365 ngày (order-age-lock.php): người dùng / tiến trình tự động / tạm nới.
 */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
function add_action(...$a) {}
$GLOBALS['ctx'] = ['cron' => false, 'logged' => true, 'caps' => [], 'options' => [], 'actions' => []];
function wp_doing_cron() { return $GLOBALS['ctx']['cron']; }
function is_user_logged_in() { return $GLOBALS['ctx']['logged']; }
function current_user_can($cap) { return in_array($cap, $GLOBALS['ctx']['caps'], true); }
function get_option($k, $d = false) { return $GLOBALS['ctx']['options'][$k] ?? $d; }
function did_action($h) { return $GLOBALS['ctx']['actions'][$h] ?? 0; }

class FakeDate { public function __construct(public int $ts) {} public function getTimestamp(): int { return $this->ts; } }
class WC_Order
{
    public function __construct(private int $id, private int $created) {}
    public function get_id() { return $this->id; }
    public function get_date_created() { return new FakeDate($this->created); }
}

require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-age-lock.php';

$checks = 0;
function check($cond, string $msg): void
{
    global $checks;
    $checks++;
    if (!$cond) throw new RuntimeException('FAIL: ' . $msg);
}
function ctx(array $over): void { $GLOBALS['ctx'] = array_merge(['cron' => false, 'logged' => true, 'caps' => [], 'options' => [], 'actions' => []], $over); }

$old = new WC_Order(1, time() - 400 * DAY_IN_SECONDS);
$new = new WC_Order(2, time() - 30 * DAY_IN_SECONDS);

ctx(['caps' => ['manage_options']]);
check(!thean_order_status_is_locked($old), 'admin thao tác tay đổi được đơn cũ');
check(!thean_order_status_is_locked($new), 'đơn mới không khóa');

ctx(['caps' => ['manage_woocommerce']]);
check(thean_order_status_is_locked($old), 'quản lý cửa hàng không đổi được đơn cũ');

ctx(['caps' => ['manage_options'], 'cron' => true]);
check(thean_order_status_is_locked($old) && thean_order_change_source() === 'cron', 'cron bị chặn kể cả admin');
check(!thean_order_status_is_locked($new), 'cron vẫn đổi được đơn mới');

ctx(['caps' => ['manage_options'], 'actions' => ['action_scheduler_before_execute' => 1]]);
check(thean_order_status_is_locked($old), 'Action Scheduler bị chặn');

ctx(['logged' => false]);
check(thean_order_status_is_locked($old) && strpos(thean_order_change_source(), 'webhook') !== false, 'webhook (không đăng nhập) bị chặn');

ctx(['caps' => ['manage_options'], 'cron' => true, 'options' => ['thean_order_age_lock_relax' => ['until' => time() + 3600, 'by' => 1]]]);
check(!thean_order_status_is_locked($old) && thean_order_age_lock_relaxed_until() > time(), 'tạm nới: cron đổi được');
ctx(['caps' => ['manage_woocommerce'], 'options' => ['thean_order_age_lock_relax' => ['until' => time() - 5]]]);
check(thean_order_status_is_locked($old) && thean_order_age_lock_relaxed_until() === 0, 'hết hạn nới → khóa lại');

// Ngày mốc chọn ở Cài đặt ERP thay cho 365 ngày.
$before = new WC_Order(3, strtotime('2025-12-31 12:00:00'));
$after  = new WC_Order(4, strtotime('2026-01-02 12:00:00'));
ctx(['caps' => ['manage_woocommerce'], 'options' => ['thean_order_age_lock_cutoff' => '2026-01-01']]);
check(thean_order_is_old($before) && !thean_order_is_old($after), 'mốc ngày: tạo trước mốc là đơn cũ');
check(thean_order_lock_label() === 'tạo trước 01/01/2026', 'nhãn theo ngày mốc');
ctx(['caps' => ['manage_woocommerce'], 'options' => ['thean_order_age_lock_cutoff' => 'hỏng']]);
check(thean_order_lock_cutoff_date() === '' && thean_order_lock_label() === 'tạo hơn 365 ngày' && thean_order_is_old($old) && !thean_order_is_old($new), 'mốc hỏng / trống → mặc định 365 ngày');

echo "OK ({$checks} checks)\n";

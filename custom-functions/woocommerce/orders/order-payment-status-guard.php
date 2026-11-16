<?php
if (!defined('ABSPATH')) exit;

/** Manual delivery/completion requires payment confirmation; administrators may override. */
function hithean_order_payment_status_error(WC_Order $order, string $target): string
{
    $context = function_exists('order_creator_state') ? order_creator_state() : [];
    $creating = !empty($context['active']) && !empty($context['operator_id']);
    $admin = $creating ? !empty($context['is_admin']) : current_user_can('manage_options');
    if (!in_array($target, ['completed', 'delivered'], true)
        || $admin || (!$creating && thean_order_change_source() !== '')) {
        return '';
    }
    if (!function_exists('thean_ow_payment_state')) require_once __DIR__ . '/order-workspace.php';
    // The pending target must never count as payment evidence (notably completed).
    $original = $order->get_data();
    $payment_order = clone $order;
    $payment_order->set_status($original['status']);
    if (thean_ow_payment_state($payment_order, true)['done']) return '';
    return 'Chưa xác nhận thanh toán. Vui lòng kiểm tra khoản tiền đã nhận và thực hiện Xác nhận thanh toán trước khi chuyển đơn sang Đã giao hoặc Đã hoàn thành. Nếu cần xử lý ngoại lệ, hãy liên hệ Administrator.';
}

/** Protect every WC save, including native editing and bulk actions, before transition hooks run. */
function hithean_order_guard_unpaid_status($order): void
{
    if (!$order instanceof WC_Order) return;
    $changes = $order->get_changes();
    $original = $order->get_data();
    if (!isset($changes['status']) || $changes['status'] === $original['status']) return;
    $message = hithean_order_payment_status_error($order, $changes['status']);
    if ($message === '') return;
    $order->set_status($original['status']);
    foreach (['date_paid', 'date_completed'] as $prop) {
        if (array_key_exists($prop, $changes)) $order->{"set_$prop"}($original[$prop]);
    }
    \Closure::bind(function () { $this->status_transition = false; }, $order, WC_Order::class)();
    // Redirecting native forms need a pending popup; AJAX callers display the error immediately.
    if (!wp_doing_ajax()) {
        set_transient('hithean_payment_status_block_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS);
    }
    throw new WC_Data_Exception('hithean_payment_required', $message);
}
add_action('woocommerce_before_order_object_save', 'hithean_order_guard_unpaid_status', 2);

/** Load the guidance dialog only for staff; redirects from native saves consume the pending message. */
function hithean_order_payment_guard_assets(): void
{
    if (!current_user_can('manage_woocommerce') || current_user_can('manage_options')) return;
    $file = '/custom-functions/woocommerce/orders/assets/order-payment-status-guard.js';
    wp_enqueue_script('hithean-order-payment-guard', get_stylesheet_directory_uri() . $file, [], filemtime(get_stylesheet_directory() . $file), true);
}
add_action('admin_enqueue_scripts', 'hithean_order_payment_guard_assets');
add_action('wp_enqueue_scripts', 'hithean_order_payment_guard_assets');

/** Show blocked native form submissions after redirects or shortcode rendering. */
function hithean_order_payment_guard_pending(): void
{
    if (!wp_script_is('hithean-order-payment-guard', 'enqueued')) return;
    $key = 'hithean_payment_status_block_' . get_current_user_id();
    $message = get_transient($key);
    if ($message) {
        delete_transient($key);
        wp_add_inline_script('hithean-order-payment-guard', 'window.hitheanPaymentStatusMessage=' . wp_json_encode($message) . ';', 'before');
    }
}
add_action('admin_footer', 'hithean_order_payment_guard_pending', 1);
add_action('wp_footer', 'hithean_order_payment_guard_pending', 1);

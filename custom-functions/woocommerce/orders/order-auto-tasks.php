<?php
if (!defined('ABSPATH')) exit;

/**
 * Gán đơn guest cho user nếu trùng email/phone
 */
add_action('woocommerce_thankyou', 'link_guest_order_to_user', 10, 1);
function link_guest_order_to_user($order_id)
{
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;

    if ($order->get_customer_id() == 0) {
        $email = sanitize_email($order->get_billing_email());
        $phone = preg_replace('/\D/', '', (string)$order->get_billing_phone());

        if ($email && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $order->set_customer_id($user->ID); // HPOS-safe
                $order->save();
                $order->add_order_note("Tự động gán khách hàng {$email} vào đơn hàng");
            }
        }
    }
}

/**
 * Hook riêng cho từng trạng thái thay vì dùng order_status_changed
 */
add_action('woocommerce_order_status_processing', 'status_change_handle_processing_status', 10, 3);
add_action('woocommerce_order_status_on-hold', 'status_change_handle_on_hold_status', 10, 3);
add_action('woocommerce_order_status_packaging', 'status_change_handle_packaging_status', 10, 3);
add_action('woocommerce_order_status_shipping', 'status_change_handle_shipping_status', 10, 3);
add_action('woocommerce_order_status_received-payment', 'status_change_handle_received_payment_status', 10, 3);

/**
 * Helpers
 */
function update_order_meta_field($order, $key, $values_to_add = null, $values_to_remove = null)
{
    $current = $order->get_meta($key, true);
    if (!is_array($current)) {
        $current = $current ? [$current] : [];
    }
    if ($values_to_add) {
        $current = array_unique(array_merge($current, $values_to_add));
    }
    if ($values_to_remove) {
        $current = array_diff($current, $values_to_remove);
    }
    $order->update_meta_data($key, $current);
    $order->save_meta_data();
}

function count_customer_orders($customer_id)
{
    if (!$customer_id) return 0;
    $q = wc_get_orders([
        'paginate'    => true,
        'limit'       => 1,
        'customer_id' => $customer_id,
        'status'      => array_keys(wc_get_order_statuses()),
        'return'      => 'ids',
    ]);
    return (int)($q->total ?? 0);
}

function is_hanoi_quick($order)
{
    $state = strtoupper((string)$order->get_shipping_state());
    $city  = strtoupper((string)$order->get_shipping_city());
    $handling = $order->get_meta('order_handling_status', true);
    $text = is_array($handling) ? implode(' ', $handling) : (string)$handling;

    $has_quick = preg_match('/GIAO\s*NHANH/i', $text);
    $is_hanoi  = strpos($state, 'HANOI') !== false || strpos($city, 'HANOI') !== false || strpos($city, 'HA NOI') !== false || strpos($city, 'HÀ NỘI') !== false;

    return $has_quick && $is_hanoi;
}

function order_auto_tasks_is_expired_final_order($order, $status_transition = null)
{
    if (!$order instanceof WC_Order) {
        return false;
    }

    $cutoff = current_time('timestamp', true) - (365 * DAY_IN_SECONDS);
    $completed_date = $order->get_date_completed();
    if ($completed_date && $completed_date->getTimestamp() <= $cutoff) {
        return true;
    }

    $final_statuses = ['completed', 'delivered', 'failed', 'cancelled'];
    $old_status = is_array($status_transition) && !empty($status_transition['from'])
        ? (string)$status_transition['from']
        : '';

    if (!in_array($old_status, $final_statuses, true) && !in_array($order->get_status(), $final_statuses, true)) {
        return false;
    }

    $final_date = $order->get_date_created();
    if (!$final_date) {
        return false;
    }

    return $final_date->getTimestamp() <= $cutoff;
}

/**
 * Các handler cho từng trạng thái
 */
function status_change_handle_processing_status($order_id, $order = null, $status_transition = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order) return;
    if (order_auto_tasks_is_expired_final_order($order, $status_transition)) return;

    if ($order->get_payment_method() === 'cod') {
        $count = count_customer_orders($order->get_customer_id());
        if ($order->get_customer_id() && $count > 1) {
            $order->update_status('packaging', 'Khách hàng quay lại. Không cần gọi xác nhận đơn.');
        } else {
            update_order_meta_field($order, 'order_handling_status', ['Cần gọi xác nhận đơn']);
        }
    }
}

function status_change_handle_on_hold_status($order_id, $order = null, $status_transition = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order) return;
    if (order_auto_tasks_is_expired_final_order($order, $status_transition)) return;

    if ($order->get_payment_method() === 'bacs') {
        $paid_date = $order->get_meta('order_paid_date', true);
        $bank      = $order->get_meta('order_bank_account_received', true);

        if (empty($paid_date) && empty($bank)) {
            update_order_meta_field($order, 'order_handling_status', ['Chờ thanh toán']);
        }
    }
}

function status_change_handle_packaging_status($order_id, $order = null, $status_transition = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order) return;
    if (order_auto_tasks_is_expired_final_order($order, $status_transition)) return;

    if ($order->get_payment_method() === 'cod') {
        update_order_meta_field($order, 'order_handling_status', null, ['Chờ khách phản hồi']);
    } elseif ($order->get_payment_method() === 'bacs') {
        $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
        if (isset($gateways['cod'])) {
            $order->set_payment_method($gateways['cod']);
            $order->save();
            $order->add_order_note('Tự động đổi sang COD khi đơn ở trạng thái Đóng gói.');
        }
    }

    if (is_hanoi_quick($order)) {
        $order->update_status('local-shipping', 'Chuyển sang Giao nhanh (Hà Nội).');
    }
}

function status_change_handle_shipping_status($order_id, $order = null, $status_transition = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order) return;
    if (order_auto_tasks_is_expired_final_order($order, $status_transition)) return;

    if (!$order->get_meta('order_export_date', true)) {
        $date = date_i18n('Y-m-d');
        $order->update_meta_data('order_export_date', $date);
        $order->save_meta_data();
        $order->add_order_note("Chuyển trạng thái Đang giao hàng ngày {$date}");

        update_order_meta_field($order, 'order_handling_status', null, ['Chờ in vận đơn', 'Đã in vận đơn, chờ xử lý', 'Chờ vận chuyển xác nhận']);
    }
}

function status_change_handle_received_payment_status($order_id, $order = null, $status_transition = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order) return;
    if (order_auto_tasks_is_expired_final_order($order, $status_transition)) return;

    if (is_hanoi_quick($order)) {
        $order->update_status('local-shipping', 'Chuyển sang Giao nhanh (Hà Nội) sau khi xác nhận thanh toán.');
    }

    $paid_date = $order->get_meta('order_paid_date', true);
    $bank      = $order->get_meta('order_bank_account_received', true);

    if (!empty($paid_date) && !empty($bank) && function_exists('send_transfer_confirmation_email')) {
        send_transfer_confirmation_email($order);
    }
}

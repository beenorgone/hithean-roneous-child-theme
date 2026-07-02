<?php
if (!defined('ABSPATH')) exit;

// [bulk_change_order_status_form]
add_shortcode('bulk_change_order_status_form', 'wp_bulk_order_status_form');
function wp_bulk_order_status_form()
{
    if (!current_user_can('manage_woocommerce')) {
        return '';
    }

    if (!function_exists('wc_get_order_statuses') || !function_exists('wc_get_order')) {
        return '';
    }

    $results = [];

    // Handle form submission
    if (!empty($_POST['bulk_order_ids']) && !empty($_POST['bulk_new_status']) && check_admin_referer('bulk_change_order_status_action')) {
        $order_ids_raw = sanitize_text_field(wp_unslash($_POST['bulk_order_ids']));
        $status_raw = sanitize_key(wp_unslash($_POST['bulk_new_status']));

        $order_ids = array_filter(array_map('absint', preg_split('/[\s,]+/', $order_ids_raw)));
        $valid_statuses = array_keys(wc_get_order_statuses());
        $new_status = 'wc-' . str_replace('wc-', '', $status_raw);

        if (in_array($new_status, $valid_statuses, true)) {
            foreach ($order_ids as $id) {
                $order = wc_get_order($id);
                if ($order) {
                    $order->update_status(str_replace('wc-', '', $new_status), __('Bulk status update via shortcode', 'woocommerce'));
                    $results[] = sprintf(__('Order #%d updated.', 'woocommerce'), $id);
                } else {
                    $results[] = sprintf(__('Order #%d not found.', 'woocommerce'), $id);
                }
            }
        } else {
            $results[] = __('Invalid status.', 'woocommerce');
        }
    }

    ob_start();
?>
    <h2>Chuyển trạng thái đơn</h2>
    <?php if (!empty($results)): ?>
        <div><strong><?php echo wp_kses_post(implode('<br>', array_map('esc_html', $results))); ?></strong></div>
    <?php endif; ?>
    <form method="post">
        <?php wp_nonce_field('bulk_change_order_status_action'); ?>
        <label for="bulk_order_ids">Mã đơn hàng (ngăn cách dấu phẩy):</label><br>
        <input type="text" name="bulk_order_ids" style="width: 100%; max-width: 500px; border-radius: 5px; border: 2px solid var(--default-color-green) !important;" placeholder="1234,5678,9012" required><br><br>
        <label for="bulk_new_status">Chọn trạng thái:</label><br>
        <select name="bulk_new_status" style="width: 100%; max-width: 500px; border-radius: 5px; border: 2px solid var(--default-color-green) !important;" required>
            <?php foreach (wc_get_order_statuses() as $status_key => $status_label): ?>
                <option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></option>
            <?php endforeach; ?>
        </select><br><br>
        <button type="submit" class="button--green">Đổi trạng thái đơn</button>
    </form>
<?php
    return ob_get_clean();
}

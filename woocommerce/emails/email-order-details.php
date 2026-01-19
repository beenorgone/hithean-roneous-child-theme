<?php
/**
 * Order details table shown in emails.
 * @version 3.7.0
 */

defined('ABSPATH') || exit;

$text_align = is_rtl() ? 'right' : 'left';

do_action('woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email); ?>

<style>
#addresses td address { padding-right: 20px; }
#template_container, #template_header_image, #template_footer { zoom: 1.5; line-height: 150%; }
#header_wrapper { text-align: center; }
* { font-family: "Be Vietnam" !important; }
#body_content_inner table:first-child { box-shadow: rgb(50 50 80 / 0%) 0px 50px 50px -20px, rgb(0 0 0 / 30%) 0px 30px 60px -30px; width: 100%; }
table, thead, tbody, td, th { border: 0; line-height: 1.2; }
#body_content_inner table:first-child th, #body_content_inner table:first-child td { padding: 10px; }
a, a:hover, a:visited { color: #0047ba; }
#body_content_inner table:first-child tfoot tr:nth-child(3) { text-transform: uppercase; font-weight: 700; font-size: 150%; }
.awdr-you-saved-text { display: none; }
</style>

<h2>
    <?php
    if ($sent_to_admin) {
        $before = '<a class="link" href="' . esc_url($order->get_edit_order_url()) . '">';
        $after  = '</a>';
    } else {
        $before = '';
        $after  = '';
    }
    /* translators: %s: Order ID. */
    echo wp_kses_post($before . sprintf(__('[Order #%s]', 'woocommerce') . $after . ' (<time datetime="%s">%s</time>)', $order->get_order_number(), $order->get_date_created()->format('c'), wc_format_datetime($order->get_date_created())));
    ?>
</h2>

<div style="margin-bottom: 40px;">
    <table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
        <thead>
            <tr>
                <th class="td" scope="col" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('Product', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('Giá gốc', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('Giá áp dụng', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('SL', 'woocommerce'); ?></th>
                <th class="td" scope="col" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('Thành Tiền', 'woocommerce'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            echo wc_get_email_order_items( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                $order,
                array(
                    'show_sku'      => $sent_to_admin,
                    'show_image'    => false,
                    'image_size'    => array(32, 32),
                    'plain_text'    => $plain_text,
                    'sent_to_admin' => $sent_to_admin,
                )
            );
            ?>
        </tbody>
        <tfoot>
            <?php
            if ($order->get_customer_note()) {
            ?>
            <tr>
                <th class="td" scope="row" colspan="2" style="text-align:<?php echo esc_attr($text_align); ?>;"><?php esc_html_e('Note:', 'woocommerce'); ?></th>
                <td class="td" scope="row" colspan="3" style="text-algin: right;"><?php echo wp_kses_post(nl2br(wptexturize($order->get_customer_note()))); ?></td>
            </tr>
            <?php
            }
            ?>

            <?php
            $item_totals = $order->get_order_item_totals();

            if ($item_totals) {
                $i = 0;
                foreach ($item_totals as $total) {
                    $i++;
            ?>
            <tr class="total">
                <th class="td" scope="row" colspan="2" style="text-align:<?php echo esc_attr($text_align); ?>; <?php echo (1 === $i) ? 'border-top-width: 4px;' : ''; ?>"><?php echo wp_kses_post($total['label']); ?></th>
                <td class="td" scope="row" colspan="3" style="text-align: right; <?php echo (1 === $i) ? 'border-top-width: 4px;' : ''; ?>"><?php echo wp_kses_post($total['value']); ?></td>
            </tr>
            <?php
                }
            }
            ?>
        </tfoot>
    </table>
</div>

<?php 
// Hook này sẽ gọi code xử lý QR từ functions.php mà không gây lỗi
do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email); 
?>
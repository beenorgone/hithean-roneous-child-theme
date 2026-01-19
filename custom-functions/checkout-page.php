<?php
add_action('woocommerce_before_checkout_form', 'add_custom_content_before_checkout', 1);

function add_custom_content_before_checkout() {
    if (!is_user_logged_in()) {
        //echo '<div class="login-notice" style="font-size: 20px;"><em>Đăng nhập / đăng ký để tích điểm khi hoàn thành đơn hàng</em></div>';
        //Check if devvn-zalo-oa active
        if (is_plugin_active('devvn-zalo-oa/devvn-zalo-oa.php')) {
            // Output the Zalo login button for guests
            echo do_shortcode('[zalo_login_btn]');
        }
	echo do_shortcode('[firebase_sms_login]');
//        echo do_shortcode('[email_otp_login]');
    }
}

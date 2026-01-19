<?php
add_action('init', function () {
    if (!session_id()) session_start();
});

add_shortcode('email_otp_login', function () {
    ob_start();
    $email = $_SESSION['otp_email'] ?? '';
?>

    <style>
        .email-login-widget {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .email-otp-login-form,
        .email-otp-verify-form {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            justify-content: center;
            align-items: center;
        }

        .otp-message {
            margin: 10px;
            color: var(--default-color-dark-blue);
        }

        .email-otp-login-form-title {
            margin-bottom: 10px;
        }

        .email-otp-login-form input,
        .email-otp-verify-form input {
            height: 45px;
            line-height: 45px;
            border-radius: 2px;
            padding: 0 10px;
            width: 250px;
        }

        .email-otp-login-form button,
        .email-otp-verify-form button {
            width: 100%;
            max-width: 250px;
        }
    </style>

    <div class="email-login-widget">
        <p style="font-weight: 700; font-size: 18px; margin-top: 20px;" class="email-otp-login-form-title">Đăng nhập bằng Email</p>
        <form method="post" class="email-otp-login-form">
            <input type="email" name="otp_email" value="<?php echo esc_attr($email); ?>" placeholder="Nhập email nhận OTP" required>
            <button class="button--light-blue" type="submit" name="send_email_otp" style="width: 250px !important;">Gửi mã OTP</button>
        </form>

        <?php if (!empty($_SESSION['otp_message'])): ?>
            <p style="color:var(--default-color-dark-blue);"><?php echo $_SESSION['otp_message'];
                                                            unset($_SESSION['otp_message']); ?></p>
        <?php endif; ?>

        <?php if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent'] === true): ?>
            <form method="post" class="email-otp-verify-form">
                <input type="text" name="otp_code" placeholder="Nhập mã OTP" required>
                <button type="submit" name="verify_email_otp" class="button" style="width: 250px !important;">Xác minh & Đăng nhập</button>
            </form>
    </div>
<?php endif;
        return ob_get_clean();
    });

    add_action('init', function () {
        if (isset($_POST['send_email_otp'])) {
            $email = sanitize_email($_POST['otp_email']);
            $key = 'otp_lock_' . md5($email);

            // Check if sending is locked (anti-spam)
            if (get_transient($key)) {
                $_SESSION['otp_message'] = "Bạn vừa gửi OTP, vui lòng thử lại sau vài phút.";
                return;
            }

            $otp = rand(100000, 999999);
            $_SESSION['otp_code'] = $otp;
            $_SESSION['otp_email'] = $email;
            $_SESSION['otp_sent'] = true;
            $_SESSION['otp_attempt'] = 0;

            // Gửi email
            wp_mail($email, 'Mã OTP đăng nhập', "Mã OTP của bạn là: $otp");

            // Lock sending for 3 phút
            set_transient($key, true, 180); // 180s = 3 phút

            $_SESSION['otp_message'] = "Mã OTP đã được gửi đến email.";
        }

        if (isset($_POST['verify_email_otp'])) {
            $user_otp = sanitize_text_field($_POST['otp_code']);
            $email = $_SESSION['otp_email'] ?? '';
            $max_attempt = 3;

            // Quá số lần thử sai
            if ($_SESSION['otp_attempt'] >= $max_attempt) {
                $_SESSION['otp_message'] = "Bạn đã thử sai quá nhiều lần. Vui lòng gửi lại mã mới.";
                unset($_SESSION['otp_sent'], $_SESSION['otp_code']);
                return;
            }

            // Kiểm tra OTP
            if ($user_otp == ($_SESSION['otp_code'] ?? '')) {
                $user = get_user_by('email', $email);
                if (!$user) {
                    $username = sanitize_user(current(explode('@', $email)));
                    $user_id = wp_create_user($username, wp_generate_password(), $email);
                    wp_update_user(['ID' => $user_id, 'role' => 'customer']);
                } else {
                    $user_id = $user->ID;
                }

                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id);
                if (function_exists('wc_set_customer_auth_cookie')) {
                    wc_set_customer_auth_cookie($user_id);
                }

                unset($_SESSION['otp_sent'], $_SESSION['otp_code']);
                // ✅ Xử lý redirect linh hoạt
                $current_url = $_SERVER['REQUEST_URI'];
                if (strpos($current_url, '/checkout') !== false) {
                    wp_redirect($current_url);
                } else {
                    wp_redirect(home_url('/tai-khoan/'));
                }
                exit;
            } else {
                $_SESSION['otp_attempt'] += 1;
                $_SESSION['otp_message'] = "Mã OTP không đúng. Lần thử: {$_SESSION['otp_attempt']}/$max_attempt";
            }
        }
    });


<?php
/*
Plugin Name: Firebase SMS Login (Enhanced)
Description: Đăng nhập bằng Firebase OTP SMS, shortcode [firebase_sms_login], kiểm tra billing phone
Version: 1.5
Author: WP Creator
*/

add_action('wp_footer', function () {
    if (is_user_logged_in()) return;
    if (!is_account_page() && !is_checkout()) return;
?>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-auth-compat.js"></script>
    <script>
        const firebaseConfig = {
            apiKey: "<?php echo FIREBASE_API_KEY; ?>",
            authDomain: "the-an-sms.firebaseapp.com ",
            projectId: "the-an-sms",
        };
        firebase.initializeApp(firebaseConfig);
    </script>
<?php
});

add_shortcode('firebase_sms_login', function () {
    if (is_user_logged_in()) return '';

    ob_start(); ?>
    <style>
        #firebase-login-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        #firebase-login-box #phone-input-box,
        #firebase-login-box #otp-box {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            justify-content: center;
            align-items: center;
        }

        #firebase-login-box #phone-input-box input,
        #firebase-login-box #otp-box input {
            height: 45px;
            line-height: 45px;
            border-radius: 2px;
            padding: 0 10px;
            width: 250px;
            margin: 0;
        }

        #firebase-login-box #phone-input-box button,
        #firebase-login-box #otp-box button {
            width: 100%;
            max-width: 250px;
        }
    </style>
    <div id="firebase-login-box">
        <p style="font-weight: 700; font-size: 18px; margin-top: 20px; margin-bottom: 10px;" class="">Đăng nhập bằng số điện thoại</p>
        <div id="phone-input-box">
            <input type="text" id="phone" value="" placeholder="Nhập số điện thoại nhận OTP" />
            <button class="button--light-blue" id="send-otp-btn" style="width: 250px !important;">Gửi mã OTP</button>
        </div>
        <div id="recaptcha-container"></div>
        <div id="otp-box" style="display:none;">
            <input type="text" id="otp" placeholder="Mã OTP">
            <button class="button" id="verify-otp-btn" style="width: 250px !important;">Xác minh</button>
        </div>
        <div id="firebase-message" style="margin-top:10px;color:var(--default-color-pink);"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const auth = firebase.auth();
            const messageBox = document.getElementById("firebase-message");

            window.recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', {
                size: 'invisible'
            });

            const sanitizePhone = (raw) => {
                raw = raw.replace(/[^0-9+]/g, '');
                if (raw.startsWith('0')) return '+84' + raw.substring(1);
                if (!raw.startsWith('+84')) return '+84' + raw;
                return raw;
            };

            document.getElementById('send-otp-btn').addEventListener('click', function() {
                let phone = sanitizePhone(document.getElementById('phone').value.trim());
                messageBox.textContent = "";

                auth.signInWithPhoneNumber(phone, window.recaptchaVerifier)
                    .then(confirmationResult => {
                        window.confirmationResult = confirmationResult;
                        document.getElementById("otp-box").style.display = "flex";
                        messageBox.style.color = "green";
                        messageBox.textContent = "Mã OTP đã được gửi.";
                    })
                    .catch(error => {
                        messageBox.style.color = "red";
                        messageBox.textContent = "Lỗi gửi OTP: " + error.message;
                        console.error("❌ Lỗi gửi OTP:", error.message);
                    });
            });

            document.getElementById('verify-otp-btn').addEventListener('click', function() {
                const code = document.getElementById('otp').value;
                const rawPhone = sanitizePhone(document.getElementById('phone').value.trim());
                messageBox.textContent = "";

                if (!window.confirmationResult) {
                    messageBox.textContent = "Vui lòng gửi mã OTP trước.";
                    return;
                }

                window.confirmationResult.confirm(code)
                    .then(result => result.user.getIdToken())
                    .then(token => {
                        return fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=custom_firebase_login&idToken=' + encodeURIComponent(token) + '&rawPhone=' + encodeURIComponent(rawPhone)
                        });
                    })
                    .then(res => res.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            if (data.success) {
                                messageBox.style.color = "green";
                                messageBox.textContent = "Đăng nhập thành công!";
                                setTimeout(() => window.location.reload(), 1000);
                            } else {
                                console.error("❌ Lỗi đăng nhập:", data.message);
                                messageBox.style.color = "red";
                                messageBox.textContent = "Lỗi: " + data.message;
                            }
                        } catch (e) {
                            console.error("❌ Lỗi parse JSON:", e.message);
                            messageBox.style.color = "red";
                            messageBox.textContent = "Lỗi phân tích phản hồi máy chủ.";
                        }
                    })
                    .catch(error => {
                        console.error("❌ Lỗi xác minh OTP:", error.message);
                        messageBox.style.color = "red";
                        messageBox.textContent = "Mã OTP không hợp lệ: " + error.message;
                    });
            });
        });
    </script>
<?php return ob_get_clean();
});

add_action('wp_ajax_custom_firebase_login', 'custom_firebase_login_handler');
add_action('wp_ajax_nopriv_custom_firebase_login', 'custom_firebase_login_handler');

function custom_firebase_login_handler()
{
    $token = $_POST['idToken'] ?? '';
    $rawPhone = $_POST['rawPhone'] ?? '';
    if (empty($token)) {
        wp_send_json(['success' => false, 'message' => 'Thiếu idToken từ client'], 400);
    }

    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $factory = (new \Kreait\Firebase\Factory)->withServiceAccount(__DIR__ . '/firebase_credentials.json');
        $auth = $factory->createAuth();

        $verified = $auth->verifyIdToken($token);
        $phone = $verified->claims()->get('phone_number');
        if (!$phone) throw new Exception('Không tìm thấy số điện thoại trong token');

        // Xử lý nếu user đã login
        if (is_user_logged_in() && $rawPhone) {
            $searchPhones = [$rawPhone, ltrim($rawPhone, '+'), '0' . substr($rawPhone, -9)];
            $matched_user = false;

            foreach ($searchPhones as $ph) {
                $users = get_users([
                    'meta_key' => 'billing_phone',
                    'meta_value' => $ph,
                    'number' => 1,
                    'count_total' => false,
                ]);
                if (!empty($users)) {
                    $matched_user = $users[0];
                    break;
                }
            }

            if ($matched_user) {
                wp_set_current_user($matched_user->ID);
                wp_set_auth_cookie($matched_user->ID);
                if (function_exists('wc_set_customer_auth_cookie')) {
                    wc_set_customer_auth_cookie($matched_user->ID);
                }
                wp_send_json(['success' => true]);
            }
        }

        // Nếu chưa login hoặc không khớp, tạo tài khoản mới
        $user = get_user_by('login', $phone);
        if (!$user) {
            $user_id = wp_create_user($phone, wp_generate_password(), $phone . '@sms.hithean.com');
            wp_update_user(['ID' => $user_id, 'role' => 'customer']);
        } else {
            $user_id = $user->ID;
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        if (function_exists('wc_set_customer_auth_cookie')) {
            wc_set_customer_auth_cookie($user_id);
        }

        wp_send_json(['success' => true]);
    } catch (Exception $e) {
        wp_send_json(['success' => false, 'message' => 'Lỗi: ' . $e->getMessage()], 400);
    }
}

<?php
if (!defined('ABSPATH')) exit;
/*
Plugin Name: Firebase SMS Login (Enhanced)
Description: Đăng nhập bằng Firebase OTP SMS, shortcode [firebase_sms_login], kiểm tra billing phone
Version: 1.5
Author: WP Creator
*/

/**
 * Đường dẫn tới file Firebase service account.
 * Đặt NGOÀI webroot (cạnh/trên wp-config.php) để không web-accessible.
 *   define('FIREBASE_CREDENTIALS_PATH', '/duong/dan/tuyet-doi/firebase_credentials.json');
 */
function hithean_firebase_credentials_path()
{
    $paths = [];

    if (defined('FIREBASE_CREDENTIALS_PATH') && FIREBASE_CREDENTIALS_PATH) {
        $paths[] = (string) FIREBASE_CREDENTIALS_PATH;
    }

    // Trên webroot 1 cấp, ví dụ /var/www/hithean.com/firebase_credentials.json
    $paths[] = dirname(ABSPATH) . '/firebase_credentials.json';
    // Legacy fallback nếu môi trường cũ vẫn để trong webroot
    $paths[] = ABSPATH . 'firebase_credentials.json';

    foreach ($paths as $path) {
        if ($path && is_readable($path)) {
            return $path;
        }
    }

    return '';
}

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
    if (empty($token)) {
        wp_send_json(['success' => false, 'message' => 'Thiếu idToken từ client'], 400);
    }

    try {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            error_log('Firebase SMS Login error: Composer autoload file is missing.');
            wp_send_json(['success' => false, 'message' => 'Không thể đăng nhập bằng SMS lúc này. Vui lòng thử lại sau.'], 503);
        }
        require_once $autoload;

        $sa_path = hithean_firebase_credentials_path();
        if ($sa_path === '') {
            error_log('Firebase SMS Login error: Firebase credentials file is missing.');
            wp_send_json(['success' => false, 'message' => 'Không thể đăng nhập bằng SMS lúc này. Vui lòng thử lại sau.'], 503);
        }

        $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($sa_path);
        $auth = $factory->createAuth();

        $verified = $auth->verifyIdToken($token);

        // Chỉ tin SĐT lấy từ claim đã được Firebase xác thực.
        // KHÔNG dùng rawPhone (client tự gửi) để định danh user → tránh chiếm tài khoản.
        $phone = $verified->claims()->get('phone_number');
        if (!$phone) throw new Exception('Không tìm thấy số điện thoại trong token');

        // Tìm user theo số đã xác thực, nếu chưa có thì tạo mới.
        $user = get_user_by('login', $phone);
        if (!$user) {
            $user_id = wp_create_user($phone, wp_generate_password(), $phone . '@sms.hithean.com');
            if (is_wp_error($user_id)) {
                error_log('Firebase SMS Login error: Cannot create SMS user - ' . $user_id->get_error_message());
                wp_send_json(['success' => false, 'message' => 'Không thể tạo tài khoản bằng SMS lúc này. Vui lòng thử lại sau.'], 500);
            }
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
        error_log('Firebase SMS Login error: ' . $e->getMessage());
        wp_send_json(['success' => false, 'message' => 'Không thể xác thực SMS lúc này. Vui lòng thử lại sau.'], 400);
    }
}

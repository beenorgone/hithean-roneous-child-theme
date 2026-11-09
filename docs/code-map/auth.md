<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->

# `auth`

> Đăng nhập OTP email / SMS Firebase, email lỗi, email tác vụ.

4 file · ~500 dòng · 7 hàm (14% có docblock)

[← Mục lục bản đồ code](README.md)

## Điểm vào

| Loại | Tên | File |
|---|---|---|
| AJAX | `custom_firebase_login` | firebase-sms-login.php |
| AJAX công khai | `custom_firebase_login` | firebase-sms-login.php |
| shortcode | `[email_otp_login]` | email-login.php |
| shortcode | `[firebase_sms_login]` | firebase-sms-login.php |

## Hook khu này lắng nghe

`after_setup_theme` · `init` · `pre_wp_mail` · `woocommerce_thankyou` · `wp_footer` · `wp_mail`

## File

### `auth/email-issue.php`

### `auth/email-login.php`

| Hàm | Mô tả |
|---|---|
| `roneous_child_maybe_start_email_login_session()` | — |

### `auth/email-tasks.php`

| Hàm | Mô tả |
|---|---|
| `hithean_normalize_mail_recipients($recipients)` | — |
| `hithean_mail_targets_blocked_domain($recipients)` | — |
| `hithean_prevent_blocked_domain_mail($return, $atts)` | — |
| `prevent_invalid_or_blocked_emails($args)` | — |

### `auth/firebase-sms-login.php`

| Hàm | Mô tả |
|---|---|
| `hithean_firebase_credentials_path()` | Đường dẫn tới file Firebase service account. Đặt NGOÀI webroot (cạnh/trên wp-config.php) để không web-accessible. define('FIREBASE_CREDENTIALS_PATH', '/duong/dan/tuyet-doi/fireb… |
| `custom_firebase_login_handler()` | — |


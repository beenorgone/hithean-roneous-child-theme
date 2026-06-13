# TIL: WordPress AJAX — admin-ajax.php vs Frontend Proxy

**Ngày:** 2026-06-13  
**Tag:** `wordpress` `ajax`

---

## Core concept

`admin_url('admin-ajax.php')` → `/wp-admin/admin-ajax.php` — bị block với non-admin users ở tầng server.

**Giải pháp:** Tạo một frontend proxy URL, dùng `init` hook để intercept `?action=` và dispatch `wp_ajax_*` handlers:

```php
add_action('init', function () {
    if (is_admin()) return;
    $action = sanitize_key($_REQUEST['action'] ?? '');
    $hook = is_user_logged_in() ? 'wp_ajax_' . $action : 'wp_ajax_nopriv_' . $action;
    if ($action && has_action($hook)) {
        if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
        do_action($hook);
        exit;
    }
}, 20);
```

Dùng `home_url('/some-frontend-page/')` thay cho `admin_url('admin-ajax.php')` khi non-admin users cần gọi AJAX.

> Chi tiết đầy đủ: xem TIL trong `ivarvietnam-wr-nitro-child-theme`.

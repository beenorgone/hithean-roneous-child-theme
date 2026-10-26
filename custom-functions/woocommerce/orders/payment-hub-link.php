<?php
/**
 * Link "Mở công cụ xác nhận ở hub" cho mọi form xác nhận thanh toán của theme.
 *
 * Khách chuyển khoản giờ chỉ xác nhận ở công cụ Xác nhận thanh toán của plugin
 * eim tại hub (nơi có giao dịch Sepay để gắn) — AJAX `confirm_order_payment` /
 * `oppc_confirm_payment` trả lỗi kèm `eim_url` (xem btc_eim_payment_gate()). Các
 * form (shortcode, danh sách đơn, workspace, tạo đơn, SO…) hiện lỗi bằng
 * textContent nên link không bấm được — script này bắt phản hồi đó (fetch lẫn
 * jQuery) và hiện thông báo nổi có nút mở link, khỏi sửa từng form.
 *
 * @package Child_Theme
 */

if (!defined('ABSPATH')) {
    exit;
}

function child_theme_payment_hub_link_script(): void
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return;
    }
    ?>
    <script>
    (function () {
        if (window.__payHubLink) { return; }
        window.__payHubLink = true;
        var ACTIONS = ['confirm_order_payment', 'oppc_confirm_payment'];

        function actionOf(body) {
            try {
                if (!body) { return ''; }
                if (typeof body.get === 'function') { return String(body.get('action') || ''); }
                if (typeof body === 'string') { return new URLSearchParams(body).get('action') || ''; }
            } catch (e) {}
            return '';
        }
        function toast(url, message) {
            var old = document.getElementById('pay-hub-link');
            if (old) { old.remove(); }
            var box = document.createElement('div');
            box.id = 'pay-hub-link';
            box.setAttribute('role', 'alert');
            box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:999999;max-width:min(420px,calc(100vw - 32px));padding:12px 14px;border-radius:10px;background:#fff;color:#1f2937;border:1px solid #fecaca;border-left:5px solid #dc2626;box-shadow:0 12px 36px rgba(15,23,42,.25);font:13px/1.5 system-ui,sans-serif';
            var p = document.createElement('div');
            p.textContent = String(message || '').replace(/\s*(Mở:|:)\s*https?:\/\/\S+$/, '');
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;margin-top:8px;justify-content:flex-end';
            var a = document.createElement('a');
            a.href = url; a.target = '_blank'; a.rel = 'noopener';
            a.textContent = 'Mở công cụ xác nhận ở hub ↗';
            a.style.cssText = 'padding:6px 10px;border-radius:6px;background:#0f766e;color:#fff;text-decoration:none;font-weight:600';
            var x = document.createElement('button');
            x.type = 'button'; x.textContent = 'Đóng';
            x.style.cssText = 'padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer';
            x.addEventListener('click', function () { box.remove(); });
            row.appendChild(x); row.appendChild(a);
            box.appendChild(p); box.appendChild(row);
            document.body.appendChild(box);
        }
        function inspect(json) {
            var d = json && json.data;
            if (json && json.success === false && d && d.eim_url) { toast(d.eim_url, d.message); }
        }

        if (window.fetch) {
            var orig = window.fetch;
            window.fetch = function (input, init) {
                var p = orig.apply(this, arguments);
                if (ACTIONS.indexOf(actionOf(init && init.body)) === -1) { return p; }
                return p.then(function (res) {
                    res.clone().json().then(inspect).catch(function () {});
                    return res;
                });
            };
        }
        if (window.jQuery) {
            jQuery(document).ajaxComplete(function (e, xhr, settings) {
                var data = settings && settings.data;
                var action = (data && typeof data === 'object' && !data.get) ? String(data.action || '') : actionOf(data);
                if (ACTIONS.indexOf(action) !== -1) { inspect(xhr.responseJSON); }
            });
        }
    })();
    </script>
    <?php
}
add_action('wp_footer', 'child_theme_payment_hub_link_script', 99);
add_action('admin_footer', 'child_theme_payment_hub_link_script', 99);

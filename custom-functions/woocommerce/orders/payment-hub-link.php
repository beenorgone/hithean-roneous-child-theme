<?php
/**
 * Popup widget hub [eim_payment_hub] dùng chung cho mọi màn xác nhận thanh toán của theme.
 *
 * Plugin eim đang chạy thì xác nhận thanh toán đi qua công cụ Xác nhận thanh toán của hub
 * (khớp giao dịch hàng đợi Sepay, mỗi giao dịch dùng 1 lần). Các màn (tạo đơn…) gọi:
 *
 *   window.hubPay.open({ orderId, payer, onDone(confirmed) })
 *
 * → AJAX `child_theme_hub_pay_url` ký URL iframe (token ngắn hạn, dùng 1 lần — tạo lúc
 * bấm, không in sẵn vào trang) → popup iframe: đơn tra sẵn, người thanh toán chọn sẵn.
 * Widget báo xác nhận xong (postMessage `eim:payment-confirmed`) → tự đóng, gọi onDone(true).
 *
 * window.hubPay.enabled = false khi plugin eim không chạy → màn gọi dùng form cũ.
 *
 * Site này 2 form cũ (shortcode-bank-transfer-confirmation / payment-pending-confirm) còn
 * đi qua cổng eim: khách CK trả lỗi kèm `eim_url` → child_theme_payment_hub_toast_script()
 * bắt phản hồi đó và hiện thông báo nổi có nút mở công cụ ở hub.
 *
 * @package Child_Theme
 */

if (!defined('ABSPATH')) {
    exit;
}

function child_theme_hub_pay_enabled(): bool
{
    return function_exists('eim_pay_embed_iframe_url') && function_exists('eim_payment_confirm_one');
}

add_action('wp_ajax_child_theme_hub_pay_url', function (): void {
    check_ajax_referer('child_theme_hub_pay', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    if (!child_theme_hub_pay_enabled()) {
        wp_send_json_error(['message' => 'Chưa bật plugin eim (công cụ xác nhận thanh toán ở hub).']);
    }
    $order = wc_get_order(absint($_POST['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }
    $payer = sanitize_key(wp_unslash($_POST['payer'] ?? ''));
    if (!in_array($payer, ['customer', 'shipper', 'self'], true)) {
        $payer = $order->get_payment_method() === 'cod' ? 'shipper' : 'customer';
    }
    $url = eim_pay_embed_iframe_url('confirm', (string) $order->get_id(), $payer);
    if (is_wp_error($url)) {
        wp_send_json_error(['message' => $url->get_error_message()]);
    }
    nocache_headers();
    wp_send_json_success(['url' => $url]);
});

function child_theme_payment_hub_link_script(): void
{
    static $printed = false;
    if ($printed || !is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $printed = true;
    $cfg = [
        'enabled' => child_theme_hub_pay_enabled(),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('child_theme_hub_pay'),
    ];
    ?>
    <script>
    (function () {
        if (window.hubPay) { return; }
        var CFG = <?php echo wp_json_encode($cfg); ?>;
        var box = null, frame = null, state = null;

        function build() {
            box = document.createElement('div');
            box.id = 'hub-pay';
            box.hidden = true;
            box.style.cssText = 'position:fixed;inset:0;z-index:100100;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45)';
            box.innerHTML = '<div role="dialog" aria-modal="true" aria-label="Xác nhận thanh toán" style="display:flex;flex-direction:column;width:100%;max-width:1100px;height:calc(100vh - 32px);background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.25);font:13px/1.5 system-ui,sans-serif">'
                + '<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid #eef2f7"><strong style="flex:1;font-size:15px">🏦 Xác nhận thanh toán · Hàng đợi Sepay (hub)</strong>'
                + '<button type="button" data-hub-pay-close style="padding:6px 12px;border:1px solid var(--erp-color-primary, #0f766e);border-radius:6px;background:var(--erp-color-primary, #0f766e);color:#fff;font-weight:600;cursor:pointer">Xong — cập nhật đơn</button></div>'
                + '<iframe title="Xác nhận thanh toán (hub)" referrerpolicy="no-referrer" style="flex:1;display:block;width:100%;border:0"></iframe></div>';
            frame = box.querySelector('iframe');
            box.addEventListener('click', function (e) {
                if (e.target === box || e.target.closest('[data-hub-pay-close]')) { close(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !box.hidden) { close(); }
            });
            window.addEventListener('message', function (e) {
                if (!state || e.source !== frame.contentWindow || !e.data || e.data.type !== 'eim:payment-confirmed') { return; }
                state.confirmed = true;
                setTimeout(close, 900);
            });
            document.body.appendChild(box);
        }

        function close() {
            if (!box || box.hidden) { return; }
            box.hidden = true;
            frame.src = 'about:blank';
            document.documentElement.style.overflow = '';
            var s = state;
            state = null;
            if (s && s.onDone) { s.onDone(!!s.confirmed); }
        }

        function open(opts) {
            opts = opts || {};
            if (!CFG.enabled) { return Promise.reject(new Error('Chưa bật plugin eim.')); }
            if (!box) { build(); }
            var fd = new FormData();
            fd.append('action', 'child_theme_hub_pay_url');
            fd.append('nonce', CFG.nonce);
            fd.append('order_id', opts.orderId || '');
            fd.append('payer', opts.payer || '');
            return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) {
                        throw new Error((res && res.data && res.data.message) || 'Không mở được công cụ xác nhận ở hub.');
                    }
                    state = { onDone: opts.onDone || null, confirmed: false };
                    frame.src = res.data.url;
                    box.hidden = false;
                    document.documentElement.style.overflow = 'hidden';
                });
        }

        window.hubPay = { enabled: !!CFG.enabled, open: open, close: close };
    })();
    </script>
    <?php
}
add_action('wp_footer', 'child_theme_payment_hub_link_script', 5);
add_action('admin_footer', 'child_theme_payment_hub_link_script', 5);

function child_theme_payment_hub_toast_script(): void
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
add_action('wp_footer', 'child_theme_payment_hub_toast_script', 99);
add_action('admin_footer', 'child_theme_payment_hub_toast_script', 99);

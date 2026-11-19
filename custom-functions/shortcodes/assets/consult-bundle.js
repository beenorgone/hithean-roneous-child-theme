/* Xuất / nhập gói tư vấn (thean-consult-bundle: kịch bản + FAQ + tài liệu tham khảo) — dùng chung tab Kịch bản & FAQ,
   mang sang site khác có cùng module. Server: hithean_consult_export / hithean_consult_import (shortcode-consult-faqs.php). */
(function () {
    'use strict';
    window.hcfBundleTools = function (root, cfg, scope, onImported, status) {
        function post(action, data) {
            var body = new FormData();
            body.append('action', action); body.append('nonce', cfg.nonce);
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
            return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
                .then(function (res) { if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi không xác định'); return res.data; });
        }
        function download(bundle, which) {
            var name = { all: 'tron-bo-tu-van', flows: 'kich-ban-tu-van', faqs: 'faq-tu-van' }[which] || 'tu-van';
            var blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name + '-' + (cfg.site || 'site') + '-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        }
        root.querySelectorAll('[data-hcb-export]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var which = btn.getAttribute('data-hcb-export') || scope;
                btn.disabled = true;
                post('hithean_consult_export', { scope: which }).then(function (d) { download(d.bundle, which); status('Đã tải file — nhập trong Thư viện tư vấn của site khác.'); })
                    .catch(function (err) { status(err.message, true); }).finally(function () { btn.disabled = false; });
            });
        });
        root.querySelectorAll('[data-hcb-import]').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                status('Đang nhập…');
                file.text().then(function (text) { return post('hithean_consult_import', { data: text }); })
                    .then(function (d) { onImported(d); status(d.message); })
                    .catch(function (err) { status(err.message, true); })
                    .finally(function () { input.value = ''; });
            });
        });
    };
})();

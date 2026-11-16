(function () {
    'use strict';
    var dialog, previousFocus;
    window.hitheanPaymentStatusDialog = function (message) {
        if (String(message).indexOf('Chưa xác nhận thanh toán.') === -1) return false;
        if (!dialog) {
            var style = document.createElement('style');
            style.textContent = '.hithean-payment-guard{box-sizing:border-box;width:min(480px,calc(100% - 32px));max-height:85dvh;overflow:auto;border:0;border-radius:20px;padding:24px;color:#001f3f;background:#fff;box-shadow:0 12px 48px #001f3f55;font:16px/1.6 sans-serif;z-index:2147483647}.hithean-payment-guard::backdrop{background:#001f3f88}.hithean-payment-guard h2{font-size:21px;line-height:1.4;margin:0 0 16px}.hithean-payment-guard button{display:block;margin:20px 0 0 auto;border:0;border-radius:24px;background:#0047ba;color:#fff;padding:12px 24px;font:inherit;cursor:pointer}.hithean-payment-guard button:focus-visible{outline:3px solid #fbb917;outline-offset:3px}';
            document.head.appendChild(style);
            dialog = document.createElement('dialog');
            dialog.className = 'hithean-payment-guard';
            dialog.setAttribute('aria-labelledby', 'hithean-payment-guard-title');
            dialog.setAttribute('aria-describedby', 'hithean-payment-guard-message');
            dialog.innerHTML = '<h2 id="hithean-payment-guard-title">Cần xác nhận thanh toán trước</h2><p id="hithean-payment-guard-message"></p><ol><li>Kiểm tra khoản tiền thực tế đã nhận.</li><li>Mở mục Thanh toán của đơn và thực hiện Xác nhận thanh toán.</li><li>Sau khi xác nhận đủ tiền, chuyển đơn sang Đã giao hoặc Đã hoàn thành.</li></ol><button type="button">Đã hiểu — kiểm tra thanh toán</button>';
            document.body.appendChild(dialog);
            dialog.querySelector('button').addEventListener('click', function () { dialog.close(); });
            dialog.addEventListener('close', function () { if (previousFocus && previousFocus.isConnected) previousFocus.focus(); });
        }
        dialog.querySelector('p').textContent = message;
        if (!dialog.open) {
            previousFocus = document.activeElement;
            dialog.showModal();
        }
        return true;
    };
    if (window.hitheanPaymentStatusMessage) window.hitheanPaymentStatusDialog(window.hitheanPaymentStatusMessage);
})();

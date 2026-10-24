(function () {
    'use strict';
    const board = document.querySelector('[data-ho-board]');
    if (board) {
        board.addEventListener('click', function (event) {
            const opener = event.target.closest('[data-ho-open]');
            if (opener) {
                const dialog = document.getElementById(opener.dataset.hoOpen);
                if (dialog && !dialog.open) {
                    dialog.showModal();
                    document.body.classList.add('ho-modal-open');
                }
            }
            const closer = event.target.closest('[data-ho-close]');
            if (closer) { closer.closest('dialog').close(); }
        });
        board.querySelectorAll('.ho-offer-dialog').forEach(function (dialog) {
            dialog.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab') { return; }
                const controls = Array.from(dialog.querySelectorAll('button, [href], input, select, textarea, summary, [tabindex]'))
                    .filter(control => !control.disabled && control.tabIndex >= 0 && control.getClientRects().length);
                const first = controls[0]; const last = controls[controls.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
            dialog.addEventListener('close', function () { document.body.classList.remove('ho-modal-open'); });
            dialog.addEventListener('click', function (event) {
                if (event.target !== dialog) { return; }
                const rect = dialog.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) { dialog.close(); }
            });
        });
    }
    document.querySelectorAll('[data-ho-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            board.classList.toggle('is-table', button.dataset.hoView === 'table');
            document.querySelectorAll('[data-ho-view]').forEach(function (item) { item.setAttribute('aria-pressed', String(item === button)); });
        });
    });
    const progress = document.querySelector('[data-ho-progress]');
    const labels = {queued: 'Chờ xử lý', running: 'Đang quét', complete: 'Hoàn tất', partial: 'Hoàn tất một phần', stopped: 'Đã dừng'};
    let lastStatus = '';
    async function poll() {
        if (document.hidden || !progress) { return; }
        try {
            const params = new URLSearchParams({action: 'hithean_offers_status', nonce: HitheanOffersAdmin.nonce});
            const response = await fetch(HitheanOffersAdmin.url + '?' + params, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) { return; }
            const json = await response.json();
            if (!json.success || !json.data.status) { return; }
            const run = json.data;
            progress.textContent = (labels[run.status] || run.status) + ': ' + run.cursor + '/' + run.total + ' bước · ' + run.failed + ' lỗi';
            if (lastStatus === 'running' && !['queued', 'running'].includes(run.status)) { progress.textContent += ' · Tải lại trang để xem danh sách mới.'; }
            lastStatus = run.status;
        } catch (_) { /* Keep the existing status when offline. */ }
    }
    window.setInterval(poll, 10000);
})();

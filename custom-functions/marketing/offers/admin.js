(function () {
    'use strict';
    const board = document.querySelector('[data-ho-board]');
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
            progress.textContent = (labels[run.status] || run.status) + ': ' + run.cursor + '/' + run.total + ' sản phẩm · ' + run.failed + ' lỗi';
            if (lastStatus === 'running' && !['queued', 'running'].includes(run.status)) { progress.textContent += ' · Tải lại trang để xem danh sách mới.'; }
            lastStatus = run.status;
        } catch (_) { /* Keep the existing status when offline. */ }
    }
    window.setInterval(poll, 10000);
})();

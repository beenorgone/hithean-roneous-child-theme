(function () {
    'use strict';
    function mount(box) {
        if (box.dataset.missingMounted) return;
        box.dataset.missingMounted = '1';
        var days = box.querySelector('[data-missing-days]'), status = box.querySelector('[data-missing-status]');
        var message = box.querySelector('[data-missing-message]'), results = box.querySelector('[data-missing-results]');
        var prev = box.querySelector('[data-missing-prev]'), next = box.querySelector('[data-missing-next]');
        var page = 1, serial = 0, controller, timer;
        function say(text, error) { message.textContent = text; message.classList.toggle('is-error', !!error); }
        async function load(wantedPage) {
            var request = ++serial;
            if (controller) controller.abort();
            controller = new AbortController();
            var fd = new FormData();
            fd.append('action', 'thean_export_missing_images'); fd.append('nonce', box.dataset.nonce);
            fd.append('days', days.value); fd.append('status', status.value); fd.append('page', String(wantedPage));
            prev.disabled = next.disabled = true;
            results.textContent = ""; box.setAttribute('aria-busy', 'true'); say('Đang tải danh sách…');
            try {
                var response = await fetch(box.dataset.url, {method:'POST', body:fd, credentials:'same-origin', signal:controller.signal});
                var json;
                try { json = await response.json(); } catch (ignore) { throw new Error('Không đọc được danh sách (HTTP ' + response.status + '). Hãy tải lại trang hoặc bấm Làm mới.'); }
                if (!response.ok || !json.success) throw new Error((json.data && json.data.message) || 'Không tải được danh sách. Hãy tải lại trang.');
                if (request !== serial) return;
                page = json.data.page;
                results.innerHTML = json.data.html;
                box.querySelector('[data-missing-page]').textContent = 'Trang ' + page;
                prev.disabled = page <= 1; next.disabled = !json.data.has_more;
                say(json.data.count ? 'Đang hiển thị ' + json.data.count + ' đơn — ' + json.data.days + ' ngày qua. Chọn “Upload ảnh” để điền mã đơn vào form.' : 'Không có đơn phù hợp.');
            } catch (error) {
                if (request === serial && error.name !== 'AbortError') {
                    prev.disabled = page <= 1;
                    say(error.message || 'Lỗi kết nối. Bấm Làm mới để thử lại.', true);
                }
            } finally { if (request === serial) box.removeAttribute('aria-busy'); }
        }
        days.addEventListener('change', function () { load(1); });
        status.addEventListener('change', function () { load(1); });
        box.querySelector('[data-missing-refresh]').addEventListener('click', function () { load(1); });
        prev.addEventListener('click', function () { load(Math.max(1, page - 1)); });
        next.addEventListener('click', function () { load(page + 1); });
        box.addEventListener('click', function (event) {
            var button = event.target.closest('[data-missing-upload]');
            if (!button) return;
            var form = document.getElementById('upload-export-form'), input = form && form.querySelector('[name="ueif_order_id"]');
            if (!input) return;
            var images = form.querySelector('[type="file"]');
            if (images && images.files.length && input.value !== button.dataset.missingUpload) {
                say('Bạn đang chọn ảnh. Hãy hoàn tất upload hoặc bỏ chọn ảnh trước khi đổi đơn.', true); return;
            }
            var bulk = form.querySelector('#ueif_bulk');
            if (bulk) { bulk.checked = false; bulk.dispatchEvent(new Event('change')); }
            var web = form.querySelector('[name="ueif_kind"][value="web"]');
            if (web) { web.checked = true; web.dispatchEvent(new Event('change')); }
            input.value = button.dataset.missingUpload;
            input.dispatchEvent(new Event('input', {bubbles:true}));
            form.scrollIntoView({behavior:'smooth', block:'start'}); input.focus({preventScroll:true});
            say('Đã chọn đơn #' + input.value + '. Chọn ảnh và bấm Upload ảnh xuất kho.');
        });
        function refresh() { clearTimeout(timer); timer = setTimeout(function () { load(1); }, 350); }
        document.addEventListener('thean:export-images-updated', refresh);
        document.addEventListener('thean:order-updated', refresh);
        load(1);
    }
    function init() { document.querySelectorAll('[data-ueif-missing]').forEach(mount); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

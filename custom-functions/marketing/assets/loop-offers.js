/**
 * Nút ưu đãi trên thẻ sản phẩm ở danh sách ([data-hlo] — marketing/offers/loop-button.php).
 * Bấm → popup .hithean-offers-dialog (khung dùng chung offer-card.css), nội dung tải AJAX hithean_loop_offers, nhớ theo sản phẩm.
 * Nút vị trí a / c nằm trong link sản phẩm nên phải chặn điều hướng.
 */
(function () {
    'use strict';
    var config = window.hitheanLoopOffers || {};
    if (!config.ajax || typeof HTMLDialogElement === 'undefined') { return; }
    var dialog = null, cache = {}, current = '';

    function build() {
        dialog = document.createElement('dialog');
        dialog.className = 'hithean-offers-dialog hlo-dialog';
        dialog.setAttribute('aria-labelledby', 'hlo-dialog-title');
        dialog.innerHTML = '<div class="hithean-offers-dialog__head"><div><h3 id="hlo-dialog-title">Ưu đãi đang áp dụng</h3><p data-hlo-meta></p></div>'
            + '<button type="button" class="hithean-offers-dialog__close" data-hlo-close aria-label="Đóng">×</button></div>'
            + '<ul class="hithean-offers-dialog__list" data-hlo-list></ul>'
            + '<div class="hithean-offers-dialog__foot"><a class="hithean-offer-card__action" data-hlo-buy href="#"></a>'
            + '<a class="hithean-offers-dialog__all" data-hlo-all href="#" hidden>Xem toàn bộ ưu đãi</a></div>';
        document.body.appendChild(dialog);
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog || event.target.closest('[data-hlo-close]')) { dialog.close(); }
        });
        dialog.addEventListener('close', function () { document.documentElement.classList.remove('hithean-offers-open'); });
    }

    function message(text) {
        var item = document.createElement('li');
        item.className = 'hlo-dialog__loading';
        item.textContent = text;
        var list = dialog.querySelector('[data-hlo-list]');
        list.innerHTML = '';
        list.appendChild(item);
    }

    function render(data) {
        dialog.querySelector('[data-hlo-meta]').textContent = data.meta || '';
        if (data.html) { dialog.querySelector('[data-hlo-list]').innerHTML = data.html; } else { message(data.meta || 'Hiện chưa có chương trình ưu đãi nào.'); }
        var buy = dialog.querySelector('[data-hlo-buy]');
        buy.hidden = !data.url;
        buy.href = data.url ? data.url + (data.own ? '#uu-dai' : '') : '#';
        buy.textContent = (data.buy || 'Xem sản phẩm & mua hàng') + ' ↗';
        var all = dialog.querySelector('[data-hlo-all]');
        all.hidden = !data.all;
        if (data.all) { all.href = data.all; }
    }

    function open(id) {
        if (!dialog) { build(); }
        current = id;
        dialog.querySelector('[data-hlo-meta]').textContent = '';
        dialog.querySelector('[data-hlo-buy]').hidden = true;
        dialog.querySelector('[data-hlo-all]').hidden = true;
        message('Đang tải ưu đãi…');
        if (!dialog.open) { dialog.showModal(); }
        document.documentElement.classList.add('hithean-offers-open');
        if (cache[id]) { render(cache[id]); return; }
        fetch(config.ajax + '?action=hithean_loop_offers&product_id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json || !json.success) { throw new Error('load'); }
                cache[id] = json.data;
                if (current === id) { render(json.data); }
            })
            .catch(function () { if (current === id) { message('Không tải được ưu đãi. Vui lòng thử lại.'); } });
    }

    function trigger(event) {
        var button = event.target.closest && event.target.closest('[data-hlo]');
        if (!button) { return; }
        if (event.type === 'keydown') {
            if (button.tagName === 'BUTTON' || (event.key !== 'Enter' && event.key !== ' ')) { return; }
        }
        event.preventDefault();
        event.stopPropagation();
        open(button.getAttribute('data-hlo'));
    }
    // Capture: chạy trước handler của link sản phẩm / theme.
    document.addEventListener('click', trigger, true);
    document.addEventListener('keydown', trigger, true);
})();

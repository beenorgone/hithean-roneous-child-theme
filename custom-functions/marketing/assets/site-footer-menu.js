(function () {
    'use strict';

    /*
     * Thanh menu đáy toàn site + panel "Ưu đãi của tôi" (site-footer-menu.php / my-offers.php).
     * Nội dung panel luôn tải qua AJAX khi mở (trang có thể bị cache) — nonce cho thao tác ghi lấy từ phản hồi load.
     * Mở thẳng panel: link có #uu-dai-cua-toi (vd. quay lại sau khi đăng nhập), hoặc bất kỳ nút [data-hmo-open].
     */
    var cfg = window.hitheanSfm || {};
    var bar = document.getElementById('sfm-root');
    var dialog = document.getElementById('hmo-dialog');
    var offersDialog = document.getElementById('sfm-offers-dialog');

    // Portal ra thẳng <body>: tổ tiên có transform làm lệch position:fixed (như apfm / product-navigation).
    [bar, dialog, offersDialog].forEach(function (el) {
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    // Trang đã có thanh đáy riêng (landing ANC, thanh danh mục, thanh trang SP…) → bỏ thanh này, giữ panel.
    if (bar && document.querySelector('.anc-sticky-menu, .anc-pf-thumbnav, #apfm-root, .pcn')) {
        bar.remove();
        bar = null;
        document.body.classList.remove('has-sfm');
        if (offersDialog) { offersDialog.remove(); offersDialog = null; }
    }

    /*
     * "Nhận ưu đãi" → popup "Ưu đãi tiêu biểu" (khung + thẻ y hệt popup ưu đãi trang SP). Danh sách tải AJAX lần mở đầu.
     * Như trang SP: nút nổi của vòng quay được gộp vào popup này (thẻ "Quay ngay") → ẩn nút nổi để không chồng lên thanh.
     */
    function lwTrigger() { return document.querySelector('#thean-lw-root .thean-lw-trigger'); }
    if (offersDialog) {
        var offersList = offersDialog.querySelector('[data-sfm-offers-list]');
        var offersLoaded = false;
        var offersOpener = null;
        if (offersDialog.querySelector('[data-sfm-wheel]')) document.body.classList.add('sfm-wheel-in-offers');
        var loadOffers = function () {
            if (offersLoaded) return;
            offersLoaded = true;
            var body = new URLSearchParams();
            body.set('action', 'hithean_sfm_featured');
            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    var data = (json && json.success && json.data) || {};
                    var loadingRow = offersList.querySelector('[data-sfm-offers-loading]');
                    if (loadingRow) loadingRow.remove();
                    offersList.insertAdjacentHTML('beforeend', data.html || '');
                    var total = offersList.querySelectorAll('.hithean-offer-card').length;
                    var meta = offersDialog.querySelector('[data-sfm-offers-meta]');
                    if (meta) meta.textContent = total ? total + ' chương trình nổi bật đang áp dụng' : 'Xem toàn bộ chương trình tại trang ưu đãi';
                    if (!total) offersList.insertAdjacentHTML('beforeend', '<li class="sfm-offers__loading">Chưa có ưu đãi tiêu biểu — xem toàn bộ tại trang ưu đãi.</li>');
                })
                .catch(function () {
                    offersLoaded = false;
                    var loadingRow = offersList.querySelector('[data-sfm-offers-loading]');
                    if (loadingRow) loadingRow.textContent = 'Không tải được ưu đãi. Bấm "Xem toàn bộ ưu đãi" để xem tại trang ưu đãi.';
                });
        };
        var closeOffers = function () {
            if (offersDialog.open && typeof offersDialog.close === 'function') offersDialog.close();
            else offersDialog.removeAttribute('open');
        };
        offersDialog.addEventListener('close', function () {
            document.documentElement.classList.remove('hithean-offers-open');
            if (offersOpener && document.contains(offersOpener)) offersOpener.focus({ preventScroll: true });
        });
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest && event.target.closest('[data-sfm-offers-open]');
            if (!trigger) return;
            event.preventDefault();
            offersOpener = trigger;
            if (!offersDialog.open) {
                if (typeof offersDialog.showModal === 'function') offersDialog.showModal();
                else offersDialog.setAttribute('open', '');
            }
            document.documentElement.classList.add('hithean-offers-open');
            loadOffers();
        });
        offersDialog.addEventListener('click', function (event) {
            var target = event.target;
            if (target === offersDialog) {
                var rect = offersDialog.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeOffers();
                return;
            }
            if (target.closest('[data-sfm-offers-close]')) { closeOffers(); return; }
            if (target.closest('[data-sfm-wheel]')) {
                var wheel = lwTrigger();
                closeOffers();
                if (wheel) wheel.click();
            }
        });
        // Tải trước khi khách chạm / rê vào nút (không gọi AJAX ở mọi lượt xem trang).
        ['pointerenter', 'touchstart', 'focusin'].forEach(function (type) {
            document.addEventListener(type, function (event) {
                if (event.target.closest && event.target.closest('[data-sfm-offers-open]')) loadOffers();
            }, { passive: true, capture: true });
        });
    }

    var STORE = 'hmo-count';
    function storeGet() { try { return window.localStorage.getItem(STORE); } catch (e) { return null; } }
    function storeSet(value) { try { window.localStorage.setItem(STORE, String(value)); } catch (e) {} }
    function setBadge(count) {
        document.querySelectorAll('[data-hmo-badge]').forEach(function (badge) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.hidden = !(count > 0);
        });
    }
    setBadge(Number(storeGet()) || 0);

    if (!dialog) return;

    var $ = function (selector) { return dialog.querySelector(selector); };
    var list = $('[data-hmo-list]');
    var loading = $('[data-hmo-loading]');
    var empty = $('[data-hmo-empty]');
    var auth = $('[data-hmo-auth]');
    var status = $('[data-hmo-status]');
    var contactsBox = $('[data-hmo-contacts]');
    var meta = $('[data-hmo-meta]');
    var form = $('[data-hmo-lookup]');
    var nonce = '';
    var opener = null;
    var busy = false;

    function toast(message) {
        var node = document.createElement('div');
        node.className = 'hmo-toast';
        node.setAttribute('role', 'status');
        node.textContent = message;
        document.body.appendChild(node);
        window.setTimeout(function () { node.remove(); }, 3200);
    }
    function say(message, isError) {
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
    }
    function post(action, data) {
        var body = new URLSearchParams();
        body.set('action', 'hithean_my_offers_' + action);
        if (nonce) body.set('nonce', nonce);
        Object.keys(data || {}).forEach(function (key) { body.set(key, data[key]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) { return res.json().catch(function () { return { success: false, data: {} }; }); })
            .then(function (json) {
                if (json && json.success) return json.data || {};
                var error = new Error((json && json.data && json.data.message) || 'Có lỗi xảy ra, vui lòng thử lại.');
                error.data = (json && json.data) || {};
                throw error;
            });
    }

    function render(data) {
        nonce = data.nonce || nonce;
        loading.hidden = true;
        list.innerHTML = data.html || '';
        list.hidden = !data.count;
        var contacts = data.contacts || [];
        var known = data.loggedIn || contacts.length > 0;
        empty.hidden = !!data.count || !known;
        meta.textContent = data.loggedIn ? ('Xin chào' + (data.name ? ', ' + data.name : '') + ' — mã dành riêng cho bạn') : 'Mã giảm giá & quà dành riêng cho bạn';
        // Liên hệ đã tra trong phiên / tài khoản.
        contactsBox.textContent = '';
        if (contacts.length) {
            var label = document.createElement('span');
            label.textContent = 'Đang xem theo:';
            contactsBox.appendChild(label);
            contacts.forEach(function (contact) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'hmo__chip';
                chip.dataset.hmoForget = String(contact.index);
                chip.setAttribute('aria-label', 'Bỏ ' + contact.label);
                chip.textContent = contact.label;
                var x = document.createElement('i');
                x.setAttribute('aria-hidden', 'true');
                x.textContent = '×';
                chip.appendChild(x);
                contactsBox.appendChild(chip);
            });
        }
        contactsBox.hidden = !contacts.length;
        // Khách: luôn có lối đăng nhập + ô kiểm tra nhanh (thêm liên hệ khác).
        auth.hidden = !!data.loggedIn;
        $('[data-hmo-auth-title]').textContent = contacts.length
            ? 'Đăng nhập để xem mã đầy đủ & dùng ngay'
            : 'Đăng nhập để xem & dùng ngay mã của bạn';
        say(data.message || '', false);
        storeSet(data.count || 0);
        setBadge(data.count || 0);
    }

    function load() {
        loading.hidden = false;
        list.hidden = true;
        empty.hidden = true;
        say('');
        post('load').then(render).catch(function (error) {
            loading.hidden = true;
            auth.hidden = false;
            say(error.message, true);
        });
    }

    function open(trigger) {
        opener = trigger || null;
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        document.documentElement.classList.add('hmo-open');
        load();
    }
    function close() {
        if (dialog.open && typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }
    dialog.addEventListener('close', function () {
        document.documentElement.classList.remove('hmo-open');
        if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest && event.target.closest('[data-hmo-open]');
        if (!trigger) return;
        event.preventDefault();
        open(trigger);
    });

    function copy(code) {
        var done = function () { toast('Đã sao chép mã ' + code); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code).then(done, function () { window.prompt('Sao chép mã:', code); });
        } else {
            window.prompt('Sao chép mã:', code);
        }
    }

    dialog.addEventListener('click', function (event) {
        var target = event.target;
        if (target === dialog) {
            // Bấm nền mờ ngoài popup = đóng.
            var rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
            return;
        }
        if (target.closest('[data-hmo-close]')) { close(); return; }
        var copyBtn = target.closest('[data-hmo-copy]');
        if (copyBtn) { copy(copyBtn.dataset.hmoCopy); return; }
        var applyBtn = target.closest('[data-hmo-apply]');
        if (applyBtn && !busy) {
            busy = true;
            applyBtn.disabled = true;
            post('apply', { code: applyBtn.dataset.hmoApply }).then(function (data) {
                toast(data.message);
                if (data.cartUrl) window.setTimeout(function () { window.location.href = data.cartUrl; }, 700);
            }).catch(function (error) {
                if (error.data && error.data.copy) copy(error.data.copy);
                say(error.message, true);
            }).then(function () { busy = false; applyBtn.disabled = false; });
            return;
        }
        var sendBtn = target.closest('[data-hmo-send]');
        if (sendBtn && !busy) {
            busy = true;
            sendBtn.disabled = true;
            post('send').then(function (data) { say(data.message, false); toast(data.message); })
                .catch(function (error) { say(error.message, true); })
                .then(function () { busy = false; sendBtn.disabled = false; });
            return;
        }
        if (target.closest('[data-hmo-login]')) { window.location.href = cfg.loginUrl; return; }
        var forget = target.closest('[data-hmo-forget]');
        if (forget && !busy) {
            busy = true;
            post('forget', { index: forget.dataset.hmoForget }).then(render)
                .catch(function (error) { say(error.message, true); })
                .then(function () { busy = false; });
            return;
        }
        if (target.closest('[data-hmo-wheel]')) {
            var wheel = lwTrigger();
            close();
            if (wheel) wheel.click();
        }
    });

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var input = form.querySelector('input[name="contact"]');
            var value = input.value.trim();
            if (!value) { say('Nhập email hoặc số điện thoại bạn đã dùng khi nhận ưu đãi / đặt hàng.', true); input.focus(); return; }
            if (busy) return;
            busy = true;
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            say('Đang tìm ưu đãi…', false);
            post('lookup', { contact: value }).then(function (data) {
                input.value = '';
                render(data);
            }).catch(function (error) { say(error.message, true); })
                .then(function () { busy = false; button.disabled = false; });
        });
    }

    // Quay lại sau đăng nhập (site-footer-menu.php → #uu-dai-cua-toi) hoặc link chia sẻ thẳng.
    if (window.location.hash === '#uu-dai-cua-toi') {
        try { window.history.replaceState(null, '', window.location.pathname + window.location.search); } catch (e) {}
        open(null);
    }
})();

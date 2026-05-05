(function () {
    'use strict';

    const root = document.getElementById('thean-lw-root');
    if (!root || typeof TheanLuckyWheel === 'undefined') return;

    const modal = root.querySelector('.thean-lw-modal');
    const trigger = root.querySelector('.thean-lw-trigger');
    const spinBtn = root.querySelector('[data-thean-lw-spin]');
    const saveBtn = root.querySelector('[data-thean-lw-save]');
    const form = root.querySelector('[data-thean-lw-form]');
    const result = root.querySelector('[data-thean-lw-result]');
    const coupon = root.querySelector('[data-thean-lw-coupon]');
    const spins = root.querySelector('[data-thean-lw-spins]');
    const message = root.querySelector('[data-thean-lw-message]');
    const wheel = root.querySelector('.thean-lw-wheel');

    let currentToken = '';
    let lastState = null;
    let openedAutomatically = window.sessionStorage.getItem('thean_lw_auto_opened') === '1';

    function post(action, data) {
        const body = new URLSearchParams(Object.assign({ action, nonce: TheanLuckyWheel.nonce }, data || {}));
        return fetch(TheanLuckyWheel.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!json.success) {
                    throw new Error((json.data && json.data.message) || 'Không xử lý được yêu cầu.');
                }
                return json.data;
            });
        });
    }

    function setMessage(text) {
        message.textContent = text || '';
    }

    function openModal() {
        modal.hidden = false;
        document.documentElement.classList.add('thean-lw-open');
    }

    function closeModal() {
        modal.hidden = true;
        document.documentElement.classList.remove('thean-lw-open');
    }

    function contextTriggerText() {
        if (TheanLuckyWheel.context === 'cart') return 'Giữ ưu đãi 24h';
        if (TheanLuckyWheel.context === 'product') return 'Quay ưu đãi cho đơn này';
        return 'Nhận ưu đãi hôm nay';
    }

    function renderCoupon(state) {
        if (!state || !state.coupon_code) {
            coupon.hidden = true;
            return;
        }

        const expires = Number(state.coupon_expires || 0) * 1000;
        coupon.hidden = false;
        coupon.innerHTML = [
            '<strong>Mã ưu đãi của bạn</strong>',
            '<span class="thean-lw-code">' + escapeHtml(state.coupon_code) + '</span>',
            '<p data-thean-lw-countdown></p>',
            '<div class="thean-lw-actions">',
            '<button class="button thean-lw-secondary" type="button" data-thean-lw-copy>Sao chép mã</button>',
            TheanLuckyWheel.isCart ? '<button class="button" type="button" data-thean-lw-apply>Áp dụng vào giỏ</button>' : '<a class="button" href="' + escapeAttr(TheanLuckyWheel.cartUrl) + '">Dùng trong giỏ hàng</a>',
            '</div>'
        ].join('');

        const copyBtn = coupon.querySelector('[data-thean-lw-copy]');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                navigator.clipboard && navigator.clipboard.writeText(state.coupon_code);
                setMessage('Đã sao chép mã.');
            });
        }

        const applyBtn = coupon.querySelector('[data-thean-lw-apply]');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                applyBtn.disabled = true;
                post('thean_lw_apply_coupon').then(function (data) {
                    setMessage(data.message || 'Đã áp dụng mã.');
                    window.location.href = data.checkout_url || TheanLuckyWheel.checkoutUrl;
                }).catch(function (error) {
                    setMessage(error.message);
                    applyBtn.disabled = false;
                });
            });
        }

        updateCountdown(expires);
    }

    function updateCountdown(expires) {
        const target = coupon.querySelector('[data-thean-lw-countdown]');
        if (!target) return;

        function tick() {
            const diff = Math.max(0, expires - Date.now());
            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            target.textContent = diff > 0 ? 'Mã có hiệu lực trong ' + hours + ' giờ ' + minutes + ' phút.' : 'Mã ưu đãi đã hết hạn.';
        }

        tick();
        window.setInterval(tick, 60000);
    }

    function renderState(state) {
        lastState = state;
        trigger.querySelector('.thean-lw-trigger__text').textContent = contextTriggerText();
        spins.textContent = 'Còn ' + state.spins_left + '/' + state.max_spins + ' lượt quay';

        if (state.coupon_code) {
            result.hidden = true;
            form.hidden = true;
            spinBtn.hidden = true;
            saveBtn.hidden = true;
            renderCoupon(state);
            return;
        }

        renderCoupon(state);
        spinBtn.hidden = state.spins_left <= 0;
        saveBtn.hidden = true;

        if (state.prizes && state.prizes.length) {
            const latest = state.prizes[state.prizes.length - 1];
            currentToken = latest.claim_token;
            result.hidden = false;
            result.innerHTML = '<span>Ưu đãi đã quay được</span>' + state.prizes.map(function (prize, index) {
                const selected = prize.claim_token === currentToken ? ' aria-pressed="true"' : '';
                return '<button class="thean-lw-prize-choice" type="button" data-token="' + escapeAttr(prize.claim_token) + '"' + selected + '>' + escapeHtml(prize.label) + '</button>';
            }).join('');
            result.querySelectorAll('[data-token]').forEach(function (button) {
                button.addEventListener('click', function () {
                    currentToken = button.getAttribute('data-token') || '';
                    result.querySelectorAll('[data-token]').forEach(function (node) {
                        node.setAttribute('aria-pressed', node === button ? 'true' : 'false');
                    });
                    form.hidden = false;
                    saveBtn.hidden = true;
                    setMessage('Đã chọn ưu đãi. Nhập email hoặc số điện thoại để nhận mã.');
                });
            });
            saveBtn.hidden = false;
        } else {
            result.hidden = true;
        }

        if (state.spins_left <= 0 && state.prizes && state.prizes.length) {
            form.hidden = false;
            spinBtn.hidden = true;
            saveBtn.hidden = true;
        }
    }

    function spin() {
        spinBtn.disabled = true;
        saveBtn.hidden = true;
        setMessage('');
        root.classList.add('is-spinning');
        if (wheel) {
            wheel.style.transform = 'rotate(' + (720 + Math.floor(Math.random() * 360)) + 'deg)';
        }

        post('thean_lw_spin').then(function (data) {
            currentToken = data.claim_token;
            window.setTimeout(function () {
                root.classList.remove('is-spinning');
                result.hidden = false;
                result.innerHTML = '<span>Bạn vừa quay được</span><strong>' + escapeHtml(data.prize.label) + '</strong>';
                spins.textContent = 'Còn ' + data.spins_left + '/' + TheanLuckyWheel.maxSpins + ' lượt quay';
                saveBtn.hidden = false;
                spinBtn.hidden = data.spins_left <= 0;
                if (data.spins_left <= 0) {
                    form.hidden = false;
                    saveBtn.hidden = true;
                }
                spinBtn.disabled = false;
            }, 900);
        }).catch(function (error) {
            root.classList.remove('is-spinning');
            setMessage(error.message);
            spinBtn.disabled = false;
            if (lastState && lastState.prizes && lastState.prizes.length) {
                form.hidden = false;
            }
        });
    }

    function claim(event) {
        event.preventDefault();
        const contact = form.querySelector('[name="contact"]').value;
        const website = form.querySelector('[name="website"]').value;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        setMessage('');

        post('thean_lw_claim', {
            claim_token: currentToken,
            contact: contact,
            website: website
        }).then(function (state) {
            renderState(state);
            setMessage('Mã ưu đãi đã được tạo.');
            submit.disabled = false;
        }).catch(function (error) {
            setMessage(error.message);
            submit.disabled = false;
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    trigger.addEventListener('click', openModal);
    spinBtn.addEventListener('click', spin);
    saveBtn.addEventListener('click', function () {
        form.hidden = false;
        saveBtn.hidden = true;
        setMessage('Nhập email hoặc số điện thoại để nhận mã.');
    });
    form.addEventListener('submit', claim);
    root.querySelectorAll('[data-thean-lw-close]').forEach(function (node) {
        node.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });

    post('thean_lw_status').then(function (state) {
        renderState(state);
        if (!openedAutomatically && !state.coupon_code && TheanLuckyWheel.context !== 'cart') {
            window.sessionStorage.setItem('thean_lw_auto_opened', '1');
            openedAutomatically = true;
            window.setTimeout(openModal, TheanLuckyWheel.context === 'offer' ? 900 : 2200);
        }
    }).catch(function () {
        spins.textContent = '';
    });
})();

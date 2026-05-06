(function () {
    'use strict';

    const root = document.getElementById('thean-lw-root');
    if (!root || typeof TheanLuckyWheel === 'undefined') return;

    const modal = root.querySelector('.thean-lw-modal');
    const trigger = root.querySelector('.thean-lw-trigger');
    const spinBtn = root.querySelector('[data-thean-lw-spin]');
    const saveBtn = root.querySelector('[data-thean-lw-save]');
    const form = root.querySelector('[data-thean-lw-form]');
    const list = root.querySelector('[data-thean-lw-result-list]');
    const coupon = root.querySelector('[data-thean-lw-coupon]');
    const spins = root.querySelector('[data-thean-lw-spins]');
    const message = root.querySelector('[data-thean-lw-message]');
    const wheel = root.querySelector('.thean-lw-wheel');

    if (!modal || !trigger || !spinBtn || !saveBtn || !form || !list || !coupon || !spins || !message) {
        return;
    }

    let currentToken = '';
    let currentSegmentIndex = 0;
    let lastState = null;
    let countdownTimer = null;
    let openedAutomatically = window.sessionStorage.getItem('thean_lw_auto_opened') === '1';
    let formUnlocked = false;

    function post(action, data) {
        const body = new URLSearchParams(Object.assign({ action: action, nonce: TheanLuckyWheel.nonce }, data || {}));
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
        if (!modal) return;
        modal.hidden = false;
        document.documentElement.classList.add('thean-lw-open');
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        document.documentElement.classList.remove('thean-lw-open');
    }

    function contextTriggerText() {
        return 'Vòng quay may mắn';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    function renderCoupon(state) {
        if (!state || !state.coupon_code) {
            coupon.hidden = true;
            coupon.innerHTML = '';
            return;
        }

        coupon.hidden = false;
        coupon.innerHTML = [
            '<strong>Mã ưu đãi của bạn</strong>',
            '<span class="thean-lw-code">' + escapeHtml(state.coupon_code) + '</span>',
            '<p class="thean-lw-coupon__time" data-thean-lw-countdown></p>',
            '<div class="thean-lw-actions">',
            '<button class="thean-lw-btn thean-lw-btn--secondary" type="button" data-thean-lw-copy>Sao chép mã</button>',
            TheanLuckyWheel.isCart ? '<button class="thean-lw-btn thean-lw-btn--primary" type="button" data-thean-lw-apply>Áp dụng vào giỏ</button>' : '<a class="thean-lw-btn thean-lw-btn--primary" href="' + escapeAttr(TheanLuckyWheel.cartUrl) + '">Dùng trong giỏ hàng</a>',
            '</div>'
        ].join('');

        const copyBtn = coupon.querySelector('[data-thean-lw-copy]');
        const applyBtn = coupon.querySelector('[data-thean-lw-apply]');

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(state.coupon_code);
                }
                setMessage('Đã sao chép mã ưu đãi.');
            });
        }

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

        updateCountdown(Number(state.coupon_expires || 0) * 1000);
    }

    function updateCountdown(expiresAt) {
        const target = coupon.querySelector('[data-thean-lw-countdown]');
        if (!target) return;

        if (countdownTimer) {
            window.clearInterval(countdownTimer);
        }

        function tick() {
            const diff = Math.max(0, expiresAt - Date.now());
            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            target.textContent = diff > 0
                ? 'Mã có hiệu lực trong ' + hours + ' giờ ' + minutes + ' phút.'
                : 'Mã ưu đãi đã hết hạn.';
        }

        tick();
        countdownTimer = window.setInterval(tick, 60000);
    }

    function spinToSegment(segmentIndex) {
        if (!wheel) return;

        const segmentCount = Math.max(1, Number(root.getAttribute('data-segments') || 1));
        const segmentAngle = 360 / segmentCount;
        const targetAngle = 360 - ((segmentIndex * segmentAngle) + (segmentAngle / 2));
        const extraTurns = 5 * 360;
        const finalAngle = extraTurns + targetAngle;

        wheel.style.setProperty('--lw-final-rotation', finalAngle + 'deg');
        root.classList.add('is-spinning');
    }

    function stopSpin() {
        root.classList.remove('is-spinning');
    }

    function renderPrizeList(prizes) {
        if (!prizes || !prizes.length) {
            list.hidden = true;
            list.innerHTML = '';
            return;
        }

        list.hidden = false;
        list.innerHTML = [
            '<p class="thean-lw-result-list__title">Các ưu đãi bạn đã quay được</p>',
            prizes.map(function (prize, index) {
                const selected = prize.claim_token === currentToken || (!currentToken && prize.selected);
                return [
                    '<button class="thean-lw-prize-choice" type="button" data-token="', escapeAttr(prize.claim_token), '" data-segment-index="', escapeAttr(prize.segment_index), '" aria-pressed="', selected ? 'true' : 'false', '" title="', escapeAttr(prize.label), '">',
                    '<span class="thean-lw-prize-choice__index">Lượt ', String(index + 1), '</span>',
                    '<strong>', escapeHtml(prize.label), '</strong>',
                    prize.claimed ? '<span class="thean-lw-prize-choice__meta">Đã dùng để tạo mã</span>' : '<span class="thean-lw-prize-choice__meta">Chọn ưu đãi này</span>',
                    '</button>'
                ].join('');
            }).join('')
        ].join('');

        list.querySelectorAll('[data-token]').forEach(function (node) {
            node.addEventListener('click', function () {
                currentToken = node.getAttribute('data-token') || '';
                currentSegmentIndex = Number(node.getAttribute('data-segment-index') || 0);
                updateSelectedPrize();
                formUnlocked = true;
                form.hidden = false;
                saveBtn.hidden = true;
                spinToSegment(currentSegmentIndex);
                window.setTimeout(stopSpin, 2600);
                setMessage('Đã chọn ưu đãi. Nhập email hoặc số điện thoại để nhận mã.');
            });
        });
    }

    function updateSelectedPrize() {
        list.querySelectorAll('[data-token]').forEach(function (node) {
            node.setAttribute('aria-pressed', node.getAttribute('data-token') === currentToken ? 'true' : 'false');
        });
    }

    function renderState(state) {
        lastState = state;
        root.setAttribute('data-has-results', state.prizes && state.prizes.length ? '1' : '0');
        const triggerText = trigger.querySelector('.thean-lw-trigger__text');
        if (triggerText) {
            triggerText.textContent = contextTriggerText();
        }
        spins.textContent = 'Còn ' + state.spins_left + '/' + state.max_spins + ' lượt quay';

        renderCoupon(state);

        if (state.coupon_code) {
            spinBtn.hidden = true;
            saveBtn.hidden = true;
            form.hidden = true;
        } else {
            spinBtn.hidden = state.spins_left <= 0;
            saveBtn.hidden = !(state.prizes && state.prizes.length);
            form.hidden = !(formUnlocked && currentToken);
        }

        if (state.prizes && state.prizes.length) {
            const selected = state.prizes.find(function (prize) { return prize.selected; }) || state.prizes[state.prizes.length - 1];
            currentToken = currentToken || selected.claim_token;
            currentSegmentIndex = Number(selected.segment_index || 0);
            renderPrizeList(state.prizes);
            updateSelectedPrize();
        } else {
            currentToken = '';
            currentSegmentIndex = 0;
            renderPrizeList([]);
        }
    }

    function spin() {
        spinBtn.disabled = true;
        saveBtn.hidden = true;
        setMessage('');

        post('thean_lw_spin').then(function (data) {
            currentToken = data.claim_token;
            currentSegmentIndex = Number(data.prize.segment_index || 0);
            formUnlocked = false;
            spinToSegment(currentSegmentIndex);

            window.setTimeout(function () {
                stopSpin();
                if (lastState && Array.isArray(lastState.prizes)) {
                    lastState.prizes = lastState.prizes.concat([{
                        label: data.prize.label,
                        claim_token: data.claim_token,
                        claimed: false,
                        selected: true,
                        segment_index: currentSegmentIndex
                    }]);
                    lastState.spins_used = data.spins_used;
                    lastState.spins_left = data.spins_left;
                    lastState.max_spins = data.max_spins;
                    renderState(lastState);
                }

                saveBtn.hidden = false;
                spinBtn.disabled = false;
                if (data.spins_left <= 0) {
                    form.hidden = false;
                    saveBtn.hidden = true;
                }
                setMessage('Đã thêm một kết quả mới. Bạn có thể quay tiếp hoặc chọn 1 ưu đãi để lưu.');
            }, 2600);
        }).catch(function (error) {
            stopSpin();
            spinBtn.disabled = false;
            setMessage(error.message);
        });
    }

    function claim(event) {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        const contactField = form.querySelector('[name="contact"]');
        const honeypotField = form.querySelector('[name="website"]');
        const contact = contactField ? contactField.value : '';
        const website = honeypotField ? honeypotField.value : '';

        if (!submit) {
            setMessage('Biểu mẫu chưa sẵn sàng. Vui lòng tải lại trang.');
            return;
        }

        if (!currentToken) {
            setMessage('Hãy chọn một ưu đãi trước khi nhận mã.');
            return;
        }

        submit.disabled = true;
        setMessage('');

        post('thean_lw_claim', {
            claim_token: currentToken,
            contact: contact,
            website: website,
            context: TheanLuckyWheel.context,
            source_url: TheanLuckyWheel.currentUrl
        }).then(function (state) {
            renderState(state);
            setMessage('Mã ưu đãi đã được tạo.');
            submit.disabled = false;
        }).catch(function (error) {
            setMessage(error.message);
            submit.disabled = false;
        });
    }

    trigger.addEventListener('click', openModal);
    spinBtn.addEventListener('click', spin);
    saveBtn.addEventListener('click', function () {
        if (!currentToken) {
            setMessage('Hãy chọn một ưu đãi trước.');
            return;
        }

        formUnlocked = true;
        form.hidden = false;
        saveBtn.hidden = true;
        setMessage('Nhập email hoặc số điện thoại để nhận mã.');
    });
    form.addEventListener('submit', claim);

    root.querySelectorAll('[data-thean-lw-close]').forEach(function (node) {
        node.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    post('thean_lw_status').then(function (state) {
        renderState(state);
        if (!openedAutomatically && !state.coupon_code && TheanLuckyWheel.context !== 'cart') {
            window.sessionStorage.setItem('thean_lw_auto_opened', '1');
            openedAutomatically = true;
            window.setTimeout(openModal, TheanLuckyWheel.context === 'offer' ? 900 : 2200);
        }
    }).catch(function () {
        if (spins) {
            spins.textContent = '';
        }
    });
})();

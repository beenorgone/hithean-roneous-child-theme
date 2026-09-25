(function () {
    'use strict';

    var nav = document.getElementById('apfm-root');
    if (!nav) {
        return;
    }

    var modal = document.getElementById('apfm-compare');

    // Portal ra thẳng <body>: tổ tiên có transform sẽ làm lệch position:fixed (xem product-navigation.js).
    [nav, modal].forEach(function (el) {
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Sản phẩm: cuộn tới grid ---------- */

    function scrollToProducts() {
        var target = document.getElementById('main-content') || document.querySelector('.hithean-product-grid');
        if (!target) {
            return;
        }

        var headerOffset = 90;
        var top = target.getBoundingClientRect().top + window.pageYOffset - headerOffset;
        window.scrollTo({ top: Math.max(0, top), behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    /* ---------- So sánh: modal ---------- */

    var compareRoot = modal ? modal.querySelector('.tpc-compare-root') : null;
    var picks = modal ? Array.prototype.slice.call(modal.querySelectorAll('[data-apfm-pick]')) : [];
    var countBadge = nav.querySelector('[data-apfm-compare-count]');
    var lastFocus = null;

    function syncPicks(ids, max) {
        ids = (ids || []).map(String);
        var isFull = typeof max === 'number' && ids.length >= max;

        picks.forEach(function (btn) {
            var selected = ids.indexOf(String(btn.getAttribute('data-apfm-pick'))) !== -1;
            btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
            btn.setAttribute('aria-disabled', !selected && isFull ? 'true' : 'false');
        });

        if (countBadge) {
            countBadge.textContent = String(ids.length);
            countBadge.hidden = ids.length === 0;
        }
    }

    function openModal() {
        if (!modal) {
            return;
        }
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('apfm-modal-open');
        var closeBtn = modal.querySelector('.apfm-modal__close');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeModal() {
        if (!modal || modal.hidden) {
            return;
        }
        modal.hidden = true;
        document.body.classList.remove('apfm-modal-open');
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    if (modal && compareRoot) {
        compareRoot.addEventListener('tpc:selection-change', function (event) {
            var detail = event.detail || {};
            syncPicks(detail.ids, detail.max);
        });

        if (typeof compareRoot.tpcGetSelectedIds === 'function') {
            syncPicks(compareRoot.tpcGetSelectedIds());
        }

        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-apfm-close]')) {
                event.preventDefault();
                closeModal();
                return;
            }

            var pick = event.target.closest('[data-apfm-pick]');
            if (!pick) {
                return;
            }

            event.preventDefault();
            var id = pick.getAttribute('data-apfm-pick');
            var type = pick.getAttribute('aria-pressed') === 'true' ? 'tpc:remove-product' : 'tpc:add-product';
            compareRoot.dispatchEvent(new CustomEvent(type, {
                detail: { id: id, label: pick.getAttribute('data-label') || '' }
            }));
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    }

    nav.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-apfm-action]');
        if (!btn) {
            return;
        }

        var action = btn.getAttribute('data-apfm-action');
        if (action === 'scroll') {
            event.preventDefault();
            scrollToProducts();
        } else if (action === 'compare') {
            event.preventDefault();
            openModal();
        }
    });
})();

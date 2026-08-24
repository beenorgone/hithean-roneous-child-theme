(function () {
    'use strict';

    var root = document.getElementById('pcn-root');
    var mobileRoot = document.getElementById('pcn-mobile-root');
    if (!root && !mobileRoot) {
        return;
    }

    /*
     * Nếu bất kỳ tổ tiên nào giữa <body> và các root này có transform/filter/
     * perspective/contain (rất hay gặp ở theme dùng transform để trượt menu
     * mobile), nó tạo containing block mới cho mọi phần tử position:fixed bên
     * trong — khiến navigator/drawer bị định vị lệch theo tổ tiên đó thay vì
     * viewport thật (biểu hiện: bị đẩy lệch xuống dưới, gần như mất hẳn).
     * Portal cả 3 root ra thẳng con của <body> để luôn thoát khỏi rủi ro này,
     * bất kể theme cha in wp_footer() ở đâu trong markup.
     */
    [root, mobileRoot, document.getElementById('pcn-drawer')].forEach(function (el) {
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    var reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    function prefersReducedMotion() {
        return reduceMotionQuery.matches;
    }

    /* ---------- Loại item không có panel thật trên DOM ---------- */
    function collectValidButtons(listEl) {
        if (!listEl) return [];
        var buttons = Array.prototype.slice.call(listEl.querySelectorAll('[data-target]'));
        var valid = [];
        buttons.forEach(function (btn) {
            var panel = document.getElementById(btn.getAttribute('data-target'));
            if (panel) {
                valid.push(btn);
            } else {
                var li = btn.closest('li');
                if (li) li.remove();
            }
        });
        return valid;
    }

    var desktopButtons = collectValidButtons(document.querySelector('.pcn__list'));
    var drawerButtons = collectValidButtons(document.querySelector('.pcn-drawer__list'));

    if (root && !desktopButtons.length) {
        root.remove();
    }

    /* ---------- Fixed/sticky header detection (không hardcode selector) ---------- */
    function detectFixedHeaderHeight() {
        var el = document.elementFromPoint(Math.floor(window.innerWidth / 2), 5);
        var hops = 0;
        while (el && el !== document.body && hops < 20) {
            var style = window.getComputedStyle(el);
            if (style.position === 'fixed' || style.position === 'sticky') {
                var rect = el.getBoundingClientRect();
                if (rect.top <= 0 && rect.bottom > 0) {
                    return rect.bottom;
                }
            }
            el = el.parentElement;
            hops++;
        }
        return 0;
    }

    function computeScrollOffset() {
        var offset = detectFixedHeaderHeight();
        if (root) {
            offset += root.getBoundingClientRect().height;
        }
        return offset + 12;
    }

    /* ---------- Scroll mượt + khoá active state trong lúc cuộn ---------- */
    var isProgrammaticScroll = false;
    var scrollLockTimer = null;

    function lockProgrammaticScroll() {
        isProgrammaticScroll = true;
        window.clearTimeout(scrollLockTimer);
        scrollLockTimer = window.setTimeout(function () {
            isProgrammaticScroll = false;
        }, 900);
    }

    function unlockProgrammaticScroll() {
        isProgrammaticScroll = false;
        window.clearTimeout(scrollLockTimer);
    }

    ['wheel', 'touchstart', 'pointerdown'].forEach(function (evtName) {
        window.addEventListener(evtName, function () {
            if (isProgrammaticScroll) unlockProgrammaticScroll();
        }, { passive: true });
    });

    function setActiveTarget(id) {
        desktopButtons.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-target') === id);
        });
        drawerButtons.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-target') === id);
        });
    }

    function scrollToTarget(id) {
        var panel = document.getElementById(id);
        if (!panel) return;
        lockProgrammaticScroll();
        var top = panel.getBoundingClientRect().top + window.pageYOffset - computeScrollOffset();
        window.scrollTo({ top: Math.max(top, 0), behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-target]');
        if (!btn) return;
        var id = btn.getAttribute('data-target');
        scrollToTarget(id);
        setActiveTarget(id);
        closeDrawer();
    });

    /* ---------- IntersectionObserver cho active state khi tự cuộn ---------- */
    if ('IntersectionObserver' in window && desktopButtons.length) {
        var panels = desktopButtons
            .map(function (btn) { return document.getElementById(btn.getAttribute('data-target')); })
            .filter(Boolean);

        var observer = new IntersectionObserver(function (entries) {
            if (isProgrammaticScroll || !panels.length) return;
            var visible = entries.filter(function (entry) { return entry.isIntersecting; });
            if (!visible.length) return;
            visible.sort(function (a, b) { return b.intersectionRatio - a.intersectionRatio; });
            setActiveTarget(visible[0].target.id);
        }, {
            rootMargin: '-' + Math.max(computeScrollOffset(), 80) + 'px 0px -55% 0px',
            threshold: [0, 0.25, 0.5, 0.75, 1],
        });

        panels.forEach(function (panel) { observer.observe(panel); });
    }

    /* ---------- Drawer mobile ---------- */
    var drawer = document.getElementById('pcn-drawer');
    var drawerOpenBtn = document.querySelector('[data-pcn-drawer-open]');
    var lastFocusedBeforeDrawer = null;

    function onDrawerKeydown(e) {
        if (e.key === 'Escape') closeDrawer();
    }

    function openDrawer() {
        if (!drawer || !drawer.hidden) return;
        lastFocusedBeforeDrawer = document.activeElement;
        drawer.hidden = false;
        if (drawerOpenBtn) drawerOpenBtn.setAttribute('aria-expanded', 'true');
        var closeBtn = drawer.querySelector('[data-pcn-drawer-close]');
        if (closeBtn) closeBtn.focus();
        document.addEventListener('keydown', onDrawerKeydown);
    }

    function closeDrawer() {
        if (!drawer || drawer.hidden) return;
        drawer.hidden = true;
        if (drawerOpenBtn) drawerOpenBtn.setAttribute('aria-expanded', 'false');
        document.removeEventListener('keydown', onDrawerKeydown);
        if (lastFocusedBeforeDrawer && typeof lastFocusedBeforeDrawer.focus === 'function') {
            lastFocusedBeforeDrawer.focus();
        }
        lastFocusedBeforeDrawer = null;
    }

    if (drawerOpenBtn) drawerOpenBtn.addEventListener('click', openDrawer);
    if (drawer) {
        drawer.querySelectorAll('[data-pcn-drawer-close]').forEach(function (el) {
            el.addEventListener('click', closeDrawer);
        });
    }

    /* ---------- CTA mua hàng (desktop bar) ---------- */
    document.addEventListener('click', function (e) {
        var cta = e.target.closest('[data-pcn-cta]');
        if (!cta) return;

        if (cta.getAttribute('data-product-type') === 'external') {
            var ecomBtn = document.querySelector('.ecom-buy-trigger');
            if (ecomBtn) {
                ecomBtn.click();
                return;
            }
        }

        var addBtn = document.querySelector('form.cart button.single_add_to_cart_button, form.cart .single_add_to_cart_button');
        if (addBtn) {
            addBtn.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' });
            addBtn.click();
        }
    });

    /* ---------- Lucky Wheel: gộp vào cụm mobile, trả về DOM gốc khi đổi breakpoint ---------- */
    var lwRoot = document.getElementById('thean-lw-root');
    var cluster = document.querySelector('[data-pcn-lw-slot]');

    if (lwRoot && cluster) {
        var lwOriginalParent = null;
        var lwOriginalNext = null;
        var lwSlotted = false;

        var slotLuckyWheel = function () {
            if (lwSlotted) return;
            lwOriginalParent = lwRoot.parentNode;
            lwOriginalNext = lwRoot.nextSibling;
            cluster.insertBefore(lwRoot, cluster.firstChild);
            lwRoot.classList.add('pcn-lw-slotted');
            lwSlotted = true;
        };

        var unslotLuckyWheel = function () {
            if (!lwSlotted) return;
            if (lwOriginalParent) {
                lwOriginalParent.insertBefore(lwRoot, lwOriginalNext);
            }
            lwRoot.classList.remove('pcn-lw-slotted');
            lwSlotted = false;
        };

        var mobileQuery = window.matchMedia('(max-width: 767px)');
        var handleBreakpointChange = function (e) {
            if (e.matches) slotLuckyWheel(); else unslotLuckyWheel();
        };

        handleBreakpointChange(mobileQuery);
        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', handleBreakpointChange);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(handleBreakpointChange);
        }
    }
})();

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
    [root, mobileRoot].forEach(function (el) {
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    var reduceMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    function prefersReducedMotion() {
        return reduceMotionQuery.matches;
    }

    /* ---------- Loại item không có panel thật trên DOM ----------
       Item nội bộ ("tab" hoặc "Menu bổ sung" loại anchor) đều dùng data-target;
       item "Menu bổ sung" loại URL là thẻ <a href> thường, không có data-target
       nên không đi qua hàm này (không cần kiểm tra tồn tại). Gom TẤT CẢ nút
       data-target trên toàn trang (thanh desktop + popover desktop + popover
       mobile) vào một danh sách duy nhất để đồng bộ active-state. */
    function collectValidButtons(scopeEl) {
        if (!scopeEl) return [];
        var buttons = Array.prototype.slice.call(scopeEl.querySelectorAll('[data-target]'));
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

    var stickyBarList = document.querySelector('.pcn__list');
    var stickyBarButtons = collectValidButtons(stickyBarList);
    var allTargetButtons = stickyBarButtons.concat(
        collectValidButtons(document.querySelector('#pcn-popover-desktop .pcn-popover__list')),
        collectValidButtons(document.querySelector('#pcn-popover .pcn-popover__list'))
    );

    // .pcn__list và cả 2 popover list luôn render CÙNG một tập item (PHP lặp
    // lại 3 lần) — item ngoại (URL, không có data-target) không bị lọc bởi
    // collectValidButtons nên vẫn còn trong DOM. Dùng .pcn__list làm đại diện:
    // hết <li> ở đây nghĩa là mọi item (nội bộ lẫn ngoại) đều không hợp lệ/rỗng.
    if (root && stickyBarList && !stickyBarList.children.length) {
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
        // Chỉ cộng chiều cao phần tử che PHÍA TRÊN viewport (header fixed/sticky).
        // #pcn-root (thanh desktop) và cụm mobile đều neo ở bottom, không che top
        // nên KHÔNG được cộng vào đây — cộng nhầm khiến điểm cuộn bị đẩy lên cao
        // hơn vị trí thật của tab một khoảng đúng bằng chiều cao thanh đó.
        return detectFixedHeaderHeight() + 16;
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
        allTargetButtons.forEach(function (btn) {
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

    /* ---------- Popover "Chi tiết SP" — dùng chung cho mobile và desktop
       floating_toc. Popover neo tuyệt đối bên trong container position:relative
       đã nằm đúng vị trí (xem product-navigation.css) thay vì overlay
       position:fixed;inset:0 riêng cho từng cái — chỉ đổi cơ chế định vị,
       giao diện (backdrop, header, close, grid item) giữ như bottom-sheet cũ.
       Chỉ instance mobile có backdrop dim (nested trong .pcn-mobile, cùng
       containing block) — instance desktop không cần, đóng bằng click-outside
       là đủ cho một popover neo góc nhỏ. */
    function createPopoverController(toggle, popover, backdrop) {
        if (!toggle || !popover) return null;
        var lastFocused = null;

        function onKeydown(e) {
            if (e.key === 'Escape') close();
        }

        function onOutsideClick(e) {
            if (popover.hidden) return;
            if (popover.contains(e.target) || toggle.contains(e.target)) return;
            close();
        }

        function open() {
            if (!popover.hidden) return;
            lastFocused = document.activeElement;
            popover.hidden = false;
            if (backdrop) backdrop.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            var closeBtn = popover.querySelector('[data-pcn-popover-close]');
            if (closeBtn) closeBtn.focus();
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('click', onOutsideClick);
        }

        function close() {
            if (popover.hidden) return;
            popover.hidden = true;
            if (backdrop) backdrop.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            document.removeEventListener('keydown', onKeydown);
            document.removeEventListener('click', onOutsideClick);
            if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
            lastFocused = null;
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (popover.hidden) open(); else close();
        });
        popover.querySelectorAll('[data-pcn-popover-close]').forEach(function (el) {
            el.addEventListener('click', close);
        });
        if (backdrop) backdrop.addEventListener('click', close);

        return { close: close };
    }

    var popoverControllers = [
        createPopoverController(
            document.querySelector('[data-pcn-popover-toggle]'),
            document.getElementById('pcn-popover'),
            document.querySelector('.pcn-popover-backdrop')
        ),
        createPopoverController(
            document.querySelector('[data-pcn-desktop-popover-toggle]'),
            document.getElementById('pcn-popover-desktop'),
            null
        ),
    ].filter(Boolean);

    function closeAllPopovers() {
        popoverControllers.forEach(function (controller) { controller.close(); });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-target]');
        if (!btn) return;
        var id = btn.getAttribute('data-target');
        scrollToTarget(id);
        setActiveTarget(id);
        closeAllPopovers();
    });

    /* ---------- IntersectionObserver cho active state khi tự cuộn ---------- */
    if ('IntersectionObserver' in window && stickyBarButtons.length) {
        var panels = stickyBarButtons
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

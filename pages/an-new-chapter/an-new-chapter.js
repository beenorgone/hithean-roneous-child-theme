/**
 * AN NEW CHAPTER — Landing Page Scripts
 * - Scroll-reveal animations (IntersectionObserver)
 * - Protein calculator nằm trong shortcode [protein_calculator] (custom-functions/shortcode-protein-calculator.php)
 */
(function () {
    'use strict';

    /* ============================================================
       SECTION REORDER (?order=trust,hero,products,... — bỏ tiền tố "anc-")
       Dùng để A/B test thứ tự section thủ công, không ảnh hưởng DOM gốc/SEO.
       ============================================================ */

    function initSectionReorder() {
        var params = new URLSearchParams(window.location.search);
        var order  = params.get('order');
        if (!order || params.get('preview_key') !== 'anc2026') return;

        var main = document.getElementById('anc-main');
        if (!main) return;

        var ids = order.split(',').map(function (s) { return 'anc-' + s.trim(); });
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el) main.appendChild(el);
        });

        var badge = document.createElement('div');
        badge.textContent = 'Preview order: ' + order + ' (click để ẩn)';
        badge.style.cssText = 'position:fixed;bottom:12px;left:12px;z-index:9999;' +
            'background:rgba(13,40,35,0.92);color:#fff;font:12px monospace;' +
            'padding:8px 12px;border-radius:6px;max-width:90vw;overflow:auto;cursor:pointer;';
        badge.addEventListener('click', function () { badge.remove(); });
        document.body.appendChild(badge);
    }

    /* ============================================================
       MODALS (trust bar details, protein calculator)
       ============================================================ */

    function openModal(modal) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('anc-modal-locked');
    }

    function closeModal(modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('anc-modal-locked');
    }

    function initModals() {
        document.querySelectorAll('[data-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (modal) {
                    e.preventDefault();
                    openModal(modal);
                }
            });
        });

        document.querySelectorAll('a[href="#anc-register"]:not([data-modal-open])').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                var modal = document.getElementById('anc-register');
                if (modal && modal.classList.contains('anc-modal')) {
                    e.preventDefault();
                    openModal(modal);
                }
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(function (closer) {
            closer.addEventListener('click', function () {
                var modal = closer.closest('.anc-modal');
                if (modal) closeModal(modal);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var openEl = document.querySelector('.anc-modal.is-open');
            if (openEl) closeModal(openEl);
        });
    }

    /* ============================================================
       SCROLL ANIMATIONS (IntersectionObserver)
       ============================================================ */

    function initScrollAnimations() {
        if (!window.IntersectionObserver) return;

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
        );

        var elements = document.querySelectorAll('.anc-fade-in, .anc-fade-in-children');
        elements.forEach(function (el) {
            observer.observe(el);
        });
    }

    /* ============================================================
       SMOOTH SCROLL cho anchor links trong hero
       ============================================================ */

    function initSmoothScroll() {
        var anchorLinks = document.querySelectorAll('#anc-hero a[href^="#"]');
        anchorLinks.forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (e.defaultPrevented || this.hasAttribute('data-modal-open')) return;

                var targetId = this.getAttribute('href').slice(1);
                var target   = document.getElementById(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    /* ============================================================
       PRODUCT GALLERY (WooCommerce-style thumbnail switching)
       ============================================================ */

    function initGalleries() {
        var galleries = document.querySelectorAll('.anc-gallery');
        galleries.forEach(function (gallery) {
            var mainImg = gallery.querySelector('.anc-gallery-main');
            var thumbs  = gallery.querySelectorAll('.anc-gallery-thumb');
            if (!mainImg || !thumbs.length) return;
            thumbs.forEach(function (thumb) {
                thumb.addEventListener('click', function () {
                    var newSrc = this.getAttribute('data-src');
                    if (!newSrc || mainImg.getAttribute('src') === newSrc) return;
                    mainImg.style.opacity = '0';
                    var that = this;
                    setTimeout(function () {
                        mainImg.src = newSrc;
                        mainImg.style.opacity = '1';
                    }, 160);
                    thumbs.forEach(function (t) { t.classList.remove('is-active'); });
                    that.classList.add('is-active');
                });
            });
        });
    }

    /* ============================================================
       GALLERY LIGHTBOX (bấm ảnh chính .anc-gallery-main → popup xem cả album)
       ============================================================ */

    function initGalleryLightbox() {
        var galleries = document.querySelectorAll('.anc-gallery');
        if (!galleries.length) return;

        var box = null, img, counter, items = [], index = 0, lastFocus = null;

        function show(i) {
            index = (i + items.length) % items.length;
            img.src = items[index];
            counter.textContent = items.length > 1 ? (index + 1) + ' / ' + items.length : '';
        }

        function close() {
            box.classList.remove('is-open');
            box.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('anc-modal-locked');
            if (lastFocus) lastFocus.focus();
        }

        function build() {
            box = document.createElement('div');
            box.className = 'anc-lightbox';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            box.setAttribute('aria-label', 'Thư viện ảnh sản phẩm');
            box.setAttribute('aria-hidden', 'true');
            box.innerHTML =
                '<button type="button" class="anc-lightbox-close" aria-label="Đóng">×</button>' +
                '<button type="button" class="anc-lightbox-nav anc-lightbox-nav--prev" aria-label="Ảnh trước">‹</button>' +
                '<figure class="anc-lightbox-figure"><img class="anc-lightbox-img" alt="" />' +
                '<figcaption class="anc-lightbox-counter"></figcaption></figure>' +
                '<button type="button" class="anc-lightbox-nav anc-lightbox-nav--next" aria-label="Ảnh kế tiếp">›</button>';
            document.body.appendChild(box);
            img = box.querySelector('.anc-lightbox-img');
            counter = box.querySelector('.anc-lightbox-counter');

            box.addEventListener('click', function (e) {
                if (e.target.closest('.anc-lightbox-nav--prev')) { show(index - 1); return; }
                if (e.target.closest('.anc-lightbox-nav--next')) { show(index + 1); return; }
                // Bấm nền tối hoặc nút × thì đóng; bấm vào ảnh thì giữ nguyên.
                if (e.target === box || e.target.closest('.anc-lightbox-close') || e.target.classList.contains('anc-lightbox-figure')) close();
            });

            document.addEventListener('keydown', function (e) {
                if (!box.classList.contains('is-open')) return;
                if (e.key === 'Escape') close();
                else if (e.key === 'ArrowLeft' && items.length > 1) show(index - 1);
                else if (e.key === 'ArrowRight' && items.length > 1) show(index + 1);
            });

            var swipeX = null, swipeY = null;
            box.addEventListener('touchstart', function (e) {
                var t = e.changedTouches[0];
                swipeX = t.clientX; swipeY = t.clientY;
            }, { passive: true });
            box.addEventListener('touchend', function (e) {
                if (swipeX === null || items.length < 2) return;
                var t = e.changedTouches[0];
                var dx = t.clientX - swipeX, dy = t.clientY - swipeY;
                swipeX = swipeY = null;
                if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.25) {
                    show(index + (dx < 0 ? 1 : -1));
                }
            }, { passive: true });
        }

        function open(gallery, mainImg) {
            if (!box) build();
            items = Array.prototype.map.call(gallery.querySelectorAll('.anc-gallery-thumb'), function (t) {
                return t.getAttribute('data-src');
            }).filter(Boolean);
            if (!items.length) items = [mainImg.getAttribute('src')];

            img.alt = mainImg.getAttribute('alt') || '';
            box.classList.toggle('is-single', items.length < 2);
            var start = items.indexOf(mainImg.getAttribute('src'));
            show(start < 0 ? 0 : start);

            lastFocus = document.activeElement;
            box.classList.add('is-open');
            box.setAttribute('aria-hidden', 'false');
            document.body.classList.add('anc-modal-locked');
            box.querySelector('.anc-lightbox-close').focus();
        }

        galleries.forEach(function (gallery) {
            var mainImg = gallery.querySelector('.anc-gallery-main');
            if (!mainImg) return;
            mainImg.setAttribute('role', 'button');
            mainImg.setAttribute('tabindex', '0');
            mainImg.setAttribute('aria-label', 'Phóng to ảnh ' + (mainImg.getAttribute('alt') || 'sản phẩm'));
            mainImg.addEventListener('click', function () { open(gallery, mainImg); });
            mainImg.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(gallery, mainImg);
                }
            });
        });
    }

    /* ============================================================
       PRODUCT SWITCHER (menu ảnh sticky / chips → đổi card sản phẩm)
       ============================================================ */

    // Khớp breakpoint CSS: dưới 680px các sticky menu chuyển thành footer cố định.
    var mqMobile = window.matchMedia('(max-width: 679px)');

    function initProductSwitcher() {
        var switchers = document.querySelectorAll('[data-product-switcher]');
        switchers.forEach(function (root) {
            var chips   = root.querySelectorAll('.anc-pf-chip');
            var panels  = root.querySelectorAll('.anc-pf-panel');
            var section = root.closest('section');
            if (!chips.length || !panels.length) return;

            // Đổi background section theo data-section-bg của sản phẩm đang chọn.
            // Cross-fade 2 lớp: nạp ảnh mới vào lớp đang ẩn rồi toggle is-bg-b →
            // CSS lo phần fade opacity, ảnh cũ tan trực tiếp vào ảnh mới.
            function applySectionBg(card, animate) {
                if (!section) return;
                var panel = root.querySelector('.anc-pf-panel[data-card="' + card + '"]');
                var bg = panel ? panel.getAttribute('data-section-bg') : '';
                var val = bg ? "url('" + bg + "')" : 'none';
                var showingB = section.classList.contains('is-bg-b');

                if (!animate) {
                    // Lúc tải: đặt thẳng vào lớp đang hiển thị, không fade.
                    section.style.setProperty(showingB ? '--anc-bg-b' : '--anc-bg-a', val);
                    return;
                }
                // Nạp ảnh mới vào lớp đang ẩn, rồi lật sang lớp đó.
                section.style.setProperty(showingB ? '--anc-bg-a' : '--anc-bg-b', val);
                section.classList.toggle('is-bg-b');
            }

            function selectCard(card) {
                chips.forEach(function (c) {
                    var active = c.getAttribute('data-card') === card;
                    c.classList.toggle('is-active', active);
                    c.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach(function (p) {
                    var match = p.getAttribute('data-card') === card;
                    p.classList.toggle('is-active', match);
                    if (match) { p.removeAttribute('hidden'); }
                    else { p.setAttribute('hidden', ''); }
                });
                applySectionBg(card, true);
            }

            var panelsWrap = root.querySelector('.anc-pf-panels');
            var thumbnav   = root.querySelector('.anc-pf-thumbnav');

            // Chọn từ menu ảnh sticky khi đang cuộn giữa card → đưa đầu card mới
            // lên ngay dưới menu, tránh rơi vào giữa nội dung sản phẩm khác.
            function revealPanels() {
                if (!thumbnav || !panelsWrap) return;
                var offset = thumbnav.getBoundingClientRect().bottom + 12;
                var top = panelsWrap.getBoundingClientRect().top;
                if (top < offset) {
                    window.scrollBy({ top: top - offset, behavior: 'smooth' });
                }
            }

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (chip.classList.contains('is-active')) return;
                    selectCard(chip.getAttribute('data-card'));
                    revealPanels();
                });
            });

            // Sticky chỉ nhả ở mép dưới switcher nên menu sẽ trượt đè lên cuối
            // card trước khi ra khỏi màn hình → ẩn hẳn khi đã cuộn qua hết card.
            if (thumbnav && panelsWrap) {
                var ticking = false;
                var updateThumbnav = function () {
                    ticking = false;
                    var r = panelsWrap.getBoundingClientRect();
                    if (mqMobile.matches) {
                        // Mobile: footer cố định, chỉ hiện khi vùng card đang chiếm màn hình.
                        var vh = window.innerHeight;
                        var show = r.top < vh - thumbnav.offsetHeight && r.bottom > vh * 0.5;
                        thumbnav.classList.toggle('is-floating', show);
                        thumbnav.classList.toggle('is-hidden', !show);
                        return;
                    }
                    thumbnav.classList.remove('is-floating');
                    var past = r.bottom < thumbnav.getBoundingClientRect().bottom + 40;
                    thumbnav.classList.toggle('is-hidden', past);
                };
                window.addEventListener('scroll', function () {
                    if (ticking) return;
                    ticking = true;
                    window.requestAnimationFrame(updateThumbnav);
                }, { passive: true });
                window.addEventListener('resize', updateThumbnav);
                updateThumbnav();
            }

            // Điều hướng trái/phải: thứ tự sản phẩm theo DOM của panels, cuộn vòng.
            var order = Array.prototype.map.call(panels, function (p) {
                return p.getAttribute('data-card');
            });

            function step(dir) {
                var activePanel = root.querySelector('.anc-pf-panel.is-active');
                var cur = activePanel ? order.indexOf(activePanel.getAttribute('data-card')) : 0;
                var next = (cur + dir + order.length) % order.length;
                selectCard(order[next]);
            }

            root.querySelectorAll('.anc-pf-nav--prev').forEach(function (b) {
                b.addEventListener('click', function () { step(-1); });
            });
            root.querySelectorAll('.anc-pf-nav--next').forEach(function (b) {
                b.addEventListener('click', function () { step(1); });
            });

            // Vuốt trái/phải trên vùng card (mobile).
            if (panelsWrap) {
                var swipeX = null, swipeY = null;
                panelsWrap.addEventListener('touchstart', function (e) {
                    var t = e.changedTouches[0];
                    swipeX = t.clientX; swipeY = t.clientY;
                }, { passive: true });
                panelsWrap.addEventListener('touchend', function (e) {
                    if (swipeX === null) return;
                    var t = e.changedTouches[0];
                    var dx = t.clientX - swipeX, dy = t.clientY - swipeY;
                    swipeX = swipeY = null;
                    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.25) {
                        step(dx < 0 ? 1 : -1);
                    }
                }, { passive: true });
            }

            // Áp background của sản phẩm đang active lúc tải trang (không fade).
            var initial = root.querySelector('.anc-pf-panel.is-active');
            if (initial) { applySectionBg(initial.getAttribute('data-card'), false); }
        });
    }

    /* ============================================================
       STICKY MENU mặc định ([data-anc-sticky-menu])
       Hiện sau khi cuộn qua hero; ẩn khi section slide sản phẩm đang chiếm
       đầu màn hình (menu ảnh sản phẩm của section đó thay chỗ).
       ============================================================ */

    function initStickyMenu() {
        var menu = document.querySelector('[data-anc-sticky-menu]');
        if (!menu) return;

        var hero = document.querySelector('#anc-hero, [id$="-hero"]');
        var productSections = Array.prototype.map.call(
            document.querySelectorAll('[data-product-switcher]'),
            function (root) { return root.closest('section') || root; }
        );
        var links = Array.prototype.filter.call(
            menu.querySelectorAll('a[href^="#"]:not([data-modal-open])'),
            function (a) { return document.getElementById(a.getAttribute('href').slice(1)); }
        );

        function zoneTop() {
            return menu.getBoundingClientRect().height + 24;
        }

        function update() {
            ticking = false;
            var line = zoneTop();
            var pastHero = !hero || hero.getBoundingClientRect().bottom < line;
            var inProducts = productSections.some(function (sec) {
                var r = sec.getBoundingClientRect();
                return r.top < line && r.bottom > line;
            });
            // Mobile: menu ảnh sản phẩm cũng nằm ở footer → nhường chỗ khi nó đang hiện.
            var productNavShown = mqMobile.matches && !!document.querySelector('.anc-pf-thumbnav.is-floating');
            var visible = pastHero && !inProducts && !productNavShown;
            menu.classList.toggle('is-visible', visible);
            menu.setAttribute('aria-hidden', visible ? 'false' : 'true');

            links.forEach(function (a) {
                var r = document.getElementById(a.getAttribute('href').slice(1)).getBoundingClientRect();
                a.classList.toggle('is-current', r.top < line && r.bottom > line);
            });
        }

        var ticking = false;
        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }, { passive: true });
        window.addEventListener('resize', update);
        update();

        // Cuộn tới section, chừa chỗ cho chính menu để tiêu đề không bị che.
        links.forEach(function (a) {
            a.addEventListener('click', function (e) {
                var target = document.getElementById(a.getAttribute('href').slice(1));
                if (!target) return;
                e.preventDefault();
                var offset = mqMobile.matches ? 16 : zoneTop() - 8;
                var y = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: y, behavior: 'smooth' });
            });
        });
    }

    /* ============================================================
       LAZY MAP (load Google Maps iframe on details open)
       ============================================================ */

    function initLazyMaps() {
        var mapDetails = document.querySelectorAll('[data-lazy-map]');
        mapDetails.forEach(function (el) {
            el.addEventListener('toggle', function () {
                if (!this.open) return;
                var iframe = this.querySelector('iframe[data-src]');
                if (iframe) {
                    iframe.setAttribute('src', iframe.getAttribute('data-src'));
                    iframe.removeAttribute('data-src');
                }
            });
        });
    }

    /* ============================================================
       OFFER COUNTDOWN ([data-anc-countdown="<ISO hạn chót>"])
       Hết giờ thì ẩn đồng hồ; nội dung ưu đãi do shortcode tự tắt phía server.
       ============================================================ */

    function initOfferCountdown() {
        var timers = document.querySelectorAll('[data-anc-countdown]');
        if (!timers.length) return;

        function pad(n) { return n < 10 ? '0' + n : String(n); }

        function tick() {
            var now = Date.now();
            var running = false;

            timers.forEach(function (el) {
                var left = Math.floor((Date.parse(el.getAttribute('data-anc-countdown')) - now) / 1000);
                if (isNaN(left) || left <= 0) {
                    el.hidden = true;
                    return;
                }
                running = true;
                var values = {
                    d: Math.floor(left / 86400),
                    h: pad(Math.floor(left % 86400 / 3600)),
                    m: pad(Math.floor(left % 3600 / 60)),
                    s: pad(left % 60)
                };
                el.querySelectorAll('[data-unit]').forEach(function (unit) {
                    unit.textContent = values[unit.getAttribute('data-unit')];
                });
            });

            if (!running) clearInterval(timer);
        }

        var timer = setInterval(tick, 1000);
        tick();
    }

    /* ============================================================
       INIT
       ============================================================ */

    function init() {
        initSectionReorder();
        initModals();
        initScrollAnimations();
        initSmoothScroll();
        initGalleries();
        initGalleryLightbox();
        initProductSwitcher();
        initStickyMenu();
        initLazyMaps();
        initOfferCountdown();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

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
            trigger.addEventListener('click', function () {
                var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (modal) openModal(modal);
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
       PRODUCT SWITCHER (chips → đổi card sản phẩm trong #anc-products)
       ============================================================ */

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

            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (chip.classList.contains('is-active')) return;
                    selectCard(chip.getAttribute('data-card'));
                });
            });

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
            var panelsWrap = root.querySelector('.anc-pf-panels');
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
       HERO PRODUCT COVERFLOW (Swiper — nạp toàn cục qua swiper-slider.php)
       ============================================================ */

    function initHeroCoverflow() {
        var el = document.querySelector('.anc-hero-coverflow');
        if (!el || el.dataset.swiperInit) return;

        function build() {
            if (!window.Swiper) return false;
            el.dataset.swiperInit = '1';
            var reduce = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            new window.Swiper(el, {
                effect: 'coverflow',
                grabCursor: true,
                centeredSlides: true,
                slidesPerView: 'auto',
                loop: true,
                speed: 700,
                a11y: false,
                slideToClickedSlide: true,
                coverflowEffect: {
                    rotate: 26,
                    stretch: -28,
                    depth: 90,
                    modifier: 1,
                    slideShadows: false
                },
                autoplay: reduce ? false : {
                    delay: 2600,
                    disableOnInteraction: false,
                    pauseOnMouseEnter: true
                }
            });
            return true;
        }

        // Swiper là script footer — nếu chưa sẵn sàng lúc DOMContentLoaded thì đợi window load.
        if (!build()) {
            window.addEventListener('load', build, { once: true });
        }
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
        initProductSwitcher();
        initHeroCoverflow();
        initLazyMaps();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

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
        var order = new URLSearchParams(window.location.search).get('order');
        if (!order) return;

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
       INIT
       ============================================================ */

    function init() {
        initSectionReorder();
        initScrollAnimations();
        initSmoothScroll();
        initGalleries();
        initLazyMaps();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

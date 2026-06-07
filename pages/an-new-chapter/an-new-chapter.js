/**
 * AN NEW CHAPTER — Landing Page Scripts
 * - Protein calculator (pure JS, no AJAX)
 * - Scroll-reveal animations (IntersectionObserver)
 */
(function () {
    'use strict';

    /* ============================================================
       PROTEIN CALCULATOR
       ============================================================ */

    var ACTIVITY_FACTOR = {
        sedentary: 1.00,
        light:     1.05,
        moderate:  1.10,
        active:    1.15,
        very:      1.20,
    };

    // gram protein / kg cân nặng theo mục tiêu
    var GOAL_FACTOR = {
        lose:     1.6,
        maintain: 1.8,
        gain:     2.2,
    };

    var SUGGESTION = {
        name: 'Yeast Hero Matcha Bơ',
        desc: '22g protein thuần chay · 5g chất xơ · <1g đường · 160 kcal · Bơ vườn rừng & Matcha hữu cơ',
        url:  'https://hithean.com/san-pham/protein/yeast-hero-protein-powder-avocado-matcha/',
        img:  'https://hithean.com/wp-content/uploads/2026/04/Label-sachet-Yeast-Hero-Protein-Avocado-Matcha-front-and-back-1.png',
    };

    function calcProtein(weight, activity, goal) {
        var g = ACTIVITY_FACTOR[activity] || 1;
        var q = GOAL_FACTOR[goal] || 1.8;
        return Math.round(weight * q * g);
    }

    function buildSuggestion(grams) {
        var servings = Math.ceil(grams / 22);
        var imgHtml = SUGGESTION.img
            ? '<img class="anc-suggestion-img" src="' + SUGGESTION.img + '" alt="' + SUGGESTION.name + '" />'
            : '<div class="anc-suggestion-img-placeholder">🌿</div>';

        return (
            '<span class="anc-suggestion-label">GỢI Ý SẢN PHẨM PHÙ HỢP</span>' +
            '<a href="' + SUGGESTION.url + '" target="_blank" rel="noopener" class="anc-suggestion-card">' +
                imgHtml +
                '<div class="anc-suggestion-info">' +
                    '<div class="anc-suggestion-name">' + SUGGESTION.name + '</div>' +
                    '<div class="anc-suggestion-desc">' + SUGGESTION.desc + ' · ~' + servings + ' khẩu phần/ngày</div>' +
                '</div>' +
                '<span class="anc-suggestion-arrow">→</span>' +
            '</a>'
        );
    }

    function initCalculator() {
        var form      = document.getElementById('anc-calc-form');
        var result    = document.getElementById('anc-calc-result');
        var resultNum = document.getElementById('anc-result-num');
        var container = document.getElementById('anc-suggestion-container');

        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var weight   = parseFloat(document.getElementById('anc-weight').value);
            var activity = document.getElementById('anc-activity').value;
            var goal     = document.getElementById('anc-goal').value;

            if (!weight || weight < 30 || weight > 200) {
                var weightInput = document.getElementById('anc-weight');
                weightInput.style.borderColor = 'var(--default-color-red)';
                weightInput.focus();
                return;
            }

            document.getElementById('anc-weight').style.borderColor = '';

            var grams = calcProtein(weight, activity, goal);
            resultNum.textContent = grams;
            container.innerHTML   = buildSuggestion(grams);

            // Hiển thị result với animation
            result.classList.remove('visible');
            // Force reflow để re-trigger animation
            void result.offsetHeight;
            result.classList.add('visible');

            result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        // Reset border khi user gõ lại
        var weightInput = document.getElementById('anc-weight');
        if (weightInput) {
            weightInput.addEventListener('input', function () {
                this.style.borderColor = '';
            });
        }
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
        initCalculator();
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

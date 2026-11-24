/**
 * Hồ sơ khoa học — trang khách (HTML do research-core.php in sẵn):
 * nút [data-hr-trigger] mở <dialog>, "Đọc tóm tắt AI" bung <template> theo ngôn ngữ, gạt VI/EN (nhớ trong phiên),
 * lọc theo loại, slider ‹ ›, thẻ slider mở popup tại thẻ đó, video YouTube chỉ tải iframe khi bấm ▶.
 * Ngôn ngữ mặc định: EN khi khách đang bật bản dịch Google (cookie googtrans …/en), ngược lại VI.
 */
(function () {
    'use strict';
    var KEY = 'hr-lang';

    function defaultLang() {
        try {
            var saved = sessionStorage.getItem(KEY);
            if (saved === 'vi' || saved === 'en') return saved;
        } catch (e) { /* bỏ qua */ }
        return /(?:^|;\s*)googtrans=[^;]*\/en/.test(document.cookie) ? 'en' : 'vi';
    }
    var lang = defaultLang();

    function paint(card) {
        var box = card.querySelector('[data-hr-summary]');
        var tpl = card.querySelector('template[data-hr-tpl="' + lang + '"]');
        if (!box || !tpl) return;
        box.textContent = ''; // không dùng replaceChildren: trình duyệt cũ (Safari < 14) chưa có
        box.appendChild(tpl.content.cloneNode(true));
        card.querySelectorAll('[data-hr-lang]').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b.getAttribute('data-hr-lang') === lang));
        });
    }

    function setLang(next) {
        lang = next;
        try { sessionStorage.setItem(KEY, next); } catch (e) { /* bỏ qua */ }
        document.querySelectorAll('.hr-card [data-hr-ai]:not([hidden])').forEach(function (ai) {
            paint(ai.closest('.hr-card'));
        });
    }

    function toggleRead(card, open) {
        var ai = card.querySelector('[data-hr-ai]');
        var btn = card.querySelector('[data-hr-read]');
        if (!ai || !btn) return;
        var show = typeof open === 'boolean' ? open : ai.hidden;
        ai.hidden = !show;
        btn.setAttribute('aria-expanded', String(show));
        btn.textContent = show ? 'Ẩn tóm tắt' : '✨ Đọc tóm tắt AI';
        if (show) paint(card);
    }

    function openDialog(id, researchId) {
        var dialog = document.getElementById(id);
        if (!dialog) return;
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') dialog.showModal();
            else dialog.setAttribute('open', '');
        }
        if (researchId) {
            var card = dialog.querySelector('.hr-card[data-hr-id="' + researchId + '"]');
            if (card) {
                card.hidden = false;
                toggleRead(card, true);
                card.scrollIntoView({ block: 'start' });
            }
        }
    }

    function closeDialog(dialog) {
        dialog.querySelectorAll('iframe[data-hr-player]').forEach(function (f) { f.remove(); }); // dừng video
        dialog.querySelectorAll('.hr-card__play[hidden]').forEach(function (b) { b.hidden = false; });
        if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    }

    function playVideo(button) {
        var id = button.getAttribute('data-hr-video');
        if (!/^[A-Za-z0-9_-]{11}$/.test(id || '')) return;
        var frame = document.createElement('iframe');
        frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
        frame.title = 'Video';
        frame.allow = 'autoplay; encrypted-media; picture-in-picture';
        frame.allowFullscreen = true;
        frame.setAttribute('data-hr-player', '');
        button.hidden = true;
        button.parentNode.appendChild(frame);
    }

    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof Element)) return;
        var el;
        if ((el = t.closest('[data-hr-trigger]'))) { e.preventDefault(); openDialog(el.getAttribute('data-hr-trigger')); return; }
        if ((el = t.closest('[data-hr-close]'))) { closeDialog(el.closest('dialog')); return; }
        if (t.matches('dialog.hithean-research-dialog')) { closeDialog(t); return; } // bấm nền
        if ((el = t.closest('[data-hr-read]'))) { toggleRead(el.closest('.hr-card')); return; }
        if ((el = t.closest('[data-hr-lang]'))) { setLang(el.getAttribute('data-hr-lang')); return; }
        if ((el = t.closest('[data-hr-video]'))) { playVideo(el); return; }
        if ((el = t.closest('[data-hr-filter]'))) {
            var dialog = el.closest('dialog');
            var f = el.getAttribute('data-hr-filter');
            dialog.querySelectorAll('[data-hr-filter]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === el)); });
            dialog.querySelectorAll('.hr-card').forEach(function (c) { c.hidden = !(f === 'all' || c.getAttribute('data-hr-type') === f); });
            return;
        }
        if ((el = t.closest('[data-hr-step]'))) {
            var track = el.closest('.hithean-research-slider').querySelector('[data-hr-track]');
            track.scrollBy({ left: Number(el.getAttribute('data-hr-step')) * track.clientWidth * 0.9 });
            return;
        }
        if ((el = t.closest('[data-hr-open]'))) {
            openDialog(el.closest('[data-hr-slider]').getAttribute('data-hr-slider'), el.getAttribute('data-hr-open'));
        }
    });

    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if ((e.key === 'Enter' || e.key === ' ') && t instanceof Element && t.matches('[data-hr-open]')) {
            e.preventDefault();
            t.click();
        }
    });

    document.addEventListener('cancel', function (e) {
        if (e.target instanceof Element && e.target.matches('dialog.hithean-research-dialog')) {
            e.preventDefault();
            closeDialog(e.target);
        }
    }, true);
})();

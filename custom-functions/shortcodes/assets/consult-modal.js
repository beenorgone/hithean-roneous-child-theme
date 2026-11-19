/* Shared mobile form dialog. Move the original controls so drafts/listeners survive close and resize. */
(function () {
    'use strict';
    if (window.hcfMobileForm) return;
    var nextId = 0;
    window.hcfMobileForm = function (content, options) {
        if (!content || !content.parentNode || !window.matchMedia) return null;
        var dialog = document.createElement('dialog');
        if (typeof dialog.showModal !== 'function') return null; // Progressive fallback: keep the inline form.
        var media = window.matchMedia('(max-width: 760px)');
        var anchor = document.createComment('mobile form location');
        var launcher = document.createElement('div');
        launcher.className = 'hcf-mobile-launcher';
        var button = document.createElement('button');
        button.type = 'button'; button.className = 'hcf-btn hcf-btn--pri'; button.textContent = options.cta || options.title;
        var id = 'hcf-mobile-dialog-' + (++nextId);
        button.setAttribute('aria-haspopup', 'dialog'); button.setAttribute('aria-controls', id); button.setAttribute('aria-expanded', 'false');
        launcher.appendChild(button);
        content.before(anchor, launcher);
        dialog.id = id; dialog.className = 'hcf hcf-mobile-dialog'; dialog.setAttribute('aria-labelledby', id + '-title');
        var head = document.createElement('header'); head.className = 'hcf-mobile-dialog__head';
        var title = document.createElement('h2'); title.id = id + '-title'; title.textContent = options.title;
        var close = document.createElement('button'); close.type = 'button'; close.className = 'hcf-btn'; close.textContent = 'Đóng'; close.setAttribute('aria-label', 'Đóng ' + options.title);
        head.append(title, close);
        var body = document.createElement('div'); body.className = 'hcf-mobile-dialog__body';
        var feedback = document.createElement('p'); feedback.className = 'hcf-status'; feedback.setAttribute('role', 'status');
        dialog.append(head, body, feedback);
        document.body.appendChild(dialog);
        var opener = null, destroyed = false;
        var originalRoot = content.closest('.hpa, .hcf');
        var sourceStatus = options.status || (originalRoot && originalRoot.querySelector('.hcf-status'));
        function syncStatus() {
            if (!sourceStatus) return;
            feedback.textContent = sourceStatus.textContent;
            feedback.classList.toggle('is-error', sourceStatus.classList.contains('is-error'));
        }
        if (sourceStatus) sourceStatus.addEventListener('hcf:status', syncStatus);
        function unlock() {
            button.setAttribute('aria-expanded', 'false');
            if (!document.querySelector('.hcf-mobile-dialog[open]')) document.documentElement.classList.remove('hcf-mobile-open');
        }
        function onClose() {
            unlock();
            if (opener && opener.isConnected) opener.focus({ preventScroll: true });
            opener = null;
        }
        function hide() { if (dialog.open) dialog.close(); }
        function open() {
            if (!media.matches || destroyed || dialog.open) return;
            opener = document.activeElement;
            if (!opener || opener === document.body || opener === document.documentElement) opener = button;
            if (content.tagName === 'DETAILS') content.open = true;
            syncStatus();
            dialog.showModal(); button.setAttribute('aria-expanded', 'true');
            document.documentElement.classList.add('hcf-mobile-open');
            var first = content.querySelector('textarea:not([readonly]), input:not([type="hidden"]), select, button');
            if (first) first.focus({ preventScroll: true });
        }
        function resize() {
            launcher.hidden = !media.matches;
            if (media.matches) body.appendChild(content);
            else { hide(); anchor.after(content); }
        }
        button.addEventListener('click', open);
        close.addEventListener('click', hide);
        dialog.addEventListener('close', onClose);
        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) return;
            var rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) hide();
        });
        if (media.addEventListener) media.addEventListener('change', resize);
        else media.addListener(resize);
        resize();
        if (options.autoOpen) open();
        return {
            open: open,
            close: hide,
            destroy: function () {
                destroyed = true; hide(); unlock();
                if (sourceStatus) sourceStatus.removeEventListener('hcf:status', syncStatus);
                if (media.removeEventListener) media.removeEventListener('change', resize);
                else media.removeListener(resize);
                anchor.after(content); launcher.remove(); dialog.remove(); anchor.remove();
            }
        };
    };
})();

(function () {
    'use strict';

    var root = document.documentElement;
    var storageKey = 'hithean-admin-chrome-hidden';
    var className = 'hithean-admin-chrome-hidden';
    var hidden = false;
    try {
        hidden = window.localStorage.getItem(storageKey) === '1';
    } catch (error) {
        // The toggle also works when browser storage is disabled.
    }
    root.classList.toggle(className, hidden);

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'hithean-admin-chrome-toggle';
        button.setAttribute('aria-controls', 'wpadminbar adminmenumain');

        function render() {
            var label = hidden ? 'Hiện thanh quản trị và thông báo' : 'Ẩn thanh quản trị và thông báo';
            button.textContent = hidden ? 'Hiện' : 'Ẩn';
            button.title = label;
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-expanded', String(!hidden));
            root.classList.toggle(className, hidden);
        }

        button.addEventListener('click', function () {
            hidden = !hidden;
            render();
            try {
                window.localStorage.setItem(storageKey, hidden ? '1' : '0');
            } catch (error) {
                // Keep the current page usable without persistence.
            }
            window.dispatchEvent(new Event('resize'));
        });

        render();
        document.body.appendChild(button);
    });
}());

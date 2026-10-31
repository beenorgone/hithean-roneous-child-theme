/* Ô tìm đơn ở danh sách đơn admin — order-universal-search.php. Dùng chung theanmarket + hithean;
 * khác biệt giữa 2 site truyền qua window.theanOusCfg (blockingModals…). */
(function () {
    var cfg = window.theanOusCfg || {};
    if (!document.getElementById('thean-ous-bar')) return;
    var bar = document.getElementById('thean-ous-bar');
    var input = bar.elements.q;
    var suggest = document.getElementById('thean-ous-suggest');
    var results = document.getElementById('thean-ous-results');
    var preview = document.getElementById('thean-ous-preview');
    var searchCtl = null, previewCtl = null, timer = 0;
    var blockedUntil = 0; // server khóa tìm kiếm (quá nhiều lượt) — không tự gửi tới khi hết hạn
    var state = { q: '', page: 0, loading: false };
    var cache = new Map(); // "q|page" → {at, data}; 60s, tránh gọi lại khi mở/đóng modal
    var items = [], active = -1;
    var statusCmds = ['tt'].concat(Object.keys(cfg.aliases).filter(function (k) { return cfg.aliases[k] === 'tt'; }));

    // Đặt thanh tìm ở đầu trang danh sách, ngay dưới tiêu đề.
    var anchor = document.querySelector('.wrap .wp-header-end') || document.querySelector('.wrap h1');
    if (anchor) anchor.parentNode.insertBefore(bar, anchor.nextSibling);
    bar.hidden = false;

    function stickyTop() {
        var top = 0;
        ['#wpadminbar', '.woocommerce-layout__header'].forEach(function (sel) {
            var el = document.querySelector(sel);
            if (!el || getComputedStyle(el).position !== 'fixed') return;
            var r = el.getBoundingClientRect();
            if (r.height && r.top < 1 + top) top = Math.max(top, Math.round(r.bottom));
        });
        bar.style.top = (top + 6) + 'px';
    }
    stickyTop();
    [300, 1000, 2500].forEach(function (ms) { setTimeout(stickyTop, ms); });
    window.addEventListener('load', stickyTop);
    window.addEventListener('resize', function () { clearTimeout(stickyTop.t); stickyTop.t = setTimeout(stickyTop, 150); });
    try { var s = new URLSearchParams(location.search).get('s'); if (s) input.value = s; } catch (e) {}

    /* ---------- Ẩn thông báo (lưu user meta) ---------- */
    var alertBox = bar.elements.alerts_off;
    var alertCount = bar.querySelector('[data-thean-ous-alert-count]');
    function countAlerts() {
        var n = document.querySelectorAll('#wpbody-content .notice, #wpbody-content .update-nag').length;
        alertCount.textContent = n;
        alertCount.hidden = !(alertBox.checked && n);
        alertCount.title = n + ' thông báo đang ẩn';
    }
    alertBox.checked = !!cfg.alertsOff;
    countAlerts();
    setTimeout(countAlerts, 1500); // notice do JS thêm muộn
    alertBox.addEventListener('change', function () {
        document.body.classList.toggle('thean-ous-alerts-off', alertBox.checked);
        countAlerts();
        var fd = new FormData();
        fd.append('action', 'thean_order_usearch_alerts');
        fd.append('nonce', cfg.nonce);
        if (alertBox.checked) fd.append('off', '1');
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
    });

    function post(action, data, ctl) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, signal: ctl.signal })
            .then(function (r) { return r.json(); });
    }
    function message(res, fallback) { return (res && res.data && res.data.message) || fallback; }
    function setOpen(modal, open) {
        if (modal === results && open) {
            // Chừa chỗ cho thanh tìm (đang nổi trên modal) để vẫn thấy mình gõ gì.
            results.style.paddingTop = Math.round(bar.getBoundingClientRect().bottom + 8) + 'px';
        }
        modal.hidden = !open;
        document.body.classList.toggle('thean-ous-open', !results.hidden || !preview.hidden);
    }
    function body(modal) { return modal.querySelector('.thean-ous-modal__body'); }
    function notice(el, text) {
        var p = document.createElement('p');
        p.className = 'thean-ous__loading';
        p.textContent = text;
        el.replaceChildren(p);
    }
    function norm(str) {
        return String(str || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }

    /* ---------- Gợi ý lệnh ---------- */
    function closeSuggest() { suggest.hidden = true; items = []; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function renderSuggest(head, list) {
        items = list; active = list.length ? 0 : -1;
        if (!list.length) { closeSuggest(); return; }
        suggest.replaceChildren();
        var h = document.createElement('div'); h.className = 'thean-ous-suggest__head'; h.textContent = head; suggest.appendChild(h);
        list.forEach(function (it, i) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'thean-ous-suggest__item' + (i === 0 ? ' is-active' : ''); b.setAttribute('role', 'option');
            var c = document.createElement('code'); c.textContent = it.code;
            var t = document.createElement('span'); t.textContent = it.text;
            b.append(c, t);
            b.addEventListener('mousedown', function (e) { e.preventDefault(); apply(i); });
            suggest.appendChild(b);
        });
        suggest.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function moveActive(step) {
        if (!items.length) return;
        active = (active + step + items.length) % items.length;
        suggest.querySelectorAll('.thean-ous-suggest__item').forEach(function (el, i) {
            el.classList.toggle('is-active', i === active);
            if (i === active) el.scrollIntoView({ block: 'nearest' });
        });
    }
    function apply(i) {
        var it = items[i]; if (!it) return;
        var pos = input.selectionStart, before = input.value.slice(0, pos), after = input.value.slice(pos);
        before = before.slice(0, before.length - it.replace.length) + it.insert;
        input.value = before + after.replace(/^\S*/, '');
        input.setSelectionRange(before.length, before.length);
        input.focus();
        updateSuggest();
        if (it.run) schedule(0);
    }
    function updateSuggest() {
        var before = input.value.slice(0, input.selectionStart);
        var m = before.match(/(?:^|\s)\/([a-z]+)\s+(\S*)$/i);
        if (m && statusCmds.indexOf(m[1].toLowerCase()) !== -1) {
            var n = norm(m[2]);
            renderSuggest('Trạng thái', cfg.statuses.filter(function (s) {
                return !n || s.slug.indexOf(n) !== -1 || s.norm.indexOf(n) !== -1;
            }).map(function (s) { return { code: s.slug, text: s.name, replace: m[2], insert: s.slug + ' ', run: true }; }));
            return;
        }
        var c = before.match(/(?:^|\s)(\/[a-z]*)$/i);
        if (c || input.value.trim() === '') {
            var typed = c ? c[1].slice(1).toLowerCase() : '';
            renderSuggest('Lệnh lọc — kết hợp được nhiều lệnh', cfg.commands.filter(function (cmd) {
                return cmd.cmd.indexOf(typed) === 0;
            }).map(function (cmd) { return { code: '/' + cmd.cmd, text: cmd.label + ' — ' + cmd.desc + ' (vd: ' + cmd.example + ')', replace: c ? c[1] : '', insert: '/' + cmd.cmd + ' ' }; }));
            return;
        }
        closeSuggest();
    }

    /* ---------- Tìm + phân trang ---------- */
    function ready(q) {
        if (q.length < 3) return false;
        // Lệnh chưa có tham số ("/sp", "/tt ") → chờ gõ tiếp.
        return !/(?:^|\s)\/[a-z]*\s*$/i.test(q);
    }
    function schedule(delay) {
        clearTimeout(timer);
        var q = input.value.trim();
        if (!ready(q) || Date.now() < blockedUntil) return;
        timer = setTimeout(function () { search(q, 1); }, delay);
    }
    function shell() {
        var wrap = document.createElement('div');
        wrap.innerHTML = '<table class="thean-ous__table"><colgroup><col class="c-order"><col class="c-cus"><col class="c-items"><col class="c-acts"></colgroup>'
            + '<thead><tr><th>Đơn · Trạng thái · Tổng</th><th>Khách hàng · Giao hàng</th><th>Sản phẩm</th><th>Thao tác</th></tr></thead><tbody></tbody></table>'
            + '<div class="thean-ous__more" hidden><button type="button" class="thean-ous__more-btn" data-thean-ous-more></button></div>';
        body(results).replaceChildren(wrap);
        return wrap;
    }
    function render(data, page) {
        var status = results.querySelector('.thean-ous-modal__status');
        var chips = results.querySelector('.thean-ous-chips');
        chips.replaceChildren();
        (data.summary || []).forEach(function (txt) { var s = document.createElement('span'); s.textContent = txt; chips.appendChild(s); });
        status.textContent = '— ' + (data.capped ? cfg.cap + '+' : data.total) + ' đơn' + (data.total ? ', đang hiện ' + data.shown : '');
        if (!data.total) { notice(body(results), 'Không tìm thấy đơn nào khớp.'); return; }
        var tbody = page === 1 ? shell().querySelector('tbody') : body(results).querySelector('tbody');
        tbody.insertAdjacentHTML('beforeend', data.rows);
        var more = body(results).querySelector('.thean-ous__more');
        more.hidden = !data.has_more;
        if (data.has_more) more.querySelector('button').textContent = 'Xem thêm ' + Math.min(cfg.perPage, data.total - data.shown) + ' đơn (còn ' + (data.total - data.shown) + ')';
    }
    function search(q, page) {
        var key = q + '|' + page, hit = cache.get(key);
        if (page === 1 && q === state.q && state.page >= 1 && !results.hidden) return;
        if (searchCtl) searchCtl.abort();
        state.q = q;
        setOpen(results, true);
        if (hit && Date.now() - hit.at < 60000) { state.page = page; render(hit.data, page); return; }
        searchCtl = new AbortController();
        state.loading = true;
        var moreBtn = body(results).querySelector('[data-thean-ous-more]');
        if (page === 1) { results.querySelector('.thean-ous-modal__status').textContent = '— đang tìm…'; notice(body(results), 'Đang tìm “' + q + '”…'); }
        else if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Đang tải…'; }
        post('thean_order_usearch', { q: q, page: page }, searchCtl).then(function (res) {
            if (!res.success) {
                if (res.data && res.data.retry_after) blockedUntil = Date.now() + res.data.retry_after * 1000;
                throw new Error(message(res, 'Không tìm được.'));
            }
            cache.set(key, { at: Date.now(), data: res.data });
            state.page = page;
            render(res.data, page);
        }).catch(function (err) {
            if (err.name === 'AbortError') return;
            state.page = 0;
            results.querySelector('.thean-ous-modal__status').textContent = '';
            results.querySelector('.thean-ous-chips').replaceChildren();
            notice(body(results), err.message || 'Lỗi kết nối.');
        }).finally(function () { state.loading = false; if (moreBtn) moreBtn.disabled = false; });
    }

    function openPreview(orderId) {
        if (previewCtl) previewCtl.abort();
        previewCtl = new AbortController();
        notice(body(preview), 'Đang tải đơn #' + orderId + '…');
        setOpen(preview, true);
        post('thean_order_usearch_preview', { order_id: orderId }, previewCtl).then(function (res) {
            if (!res.success) throw new Error(message(res, 'Không tải được đơn.'));
            body(preview).innerHTML = res.data.html;
        }).catch(function (err) {
            if (err.name !== 'AbortError') notice(body(preview), err.message || 'Lỗi kết nối.');
        });
    }

    bar.addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(timer);
        closeSuggest();
        var q = input.value.trim();
        if (q.length < 2) return;
        if (Date.now() < blockedUntil) {
            setOpen(results, true);
            results.querySelector('.thean-ous-modal__status').textContent = '';
            notice(body(results), 'Tìm kiếm đang tạm khóa vì tìm quá nhanh. Thử lại sau ' + Math.ceil((blockedUntil - Date.now()) / 1000) + ' giây.');
            return;
        }
        cache.forEach(function (v, k) { if (k.indexOf(q + '|') === 0) cache.delete(k); }); // Enter = tải mới
        state.page = 0;
        search(q, 1);
    });
    input.addEventListener('input', function () { updateSuggest(); schedule(800); });
    input.addEventListener('click', updateSuggest);
    input.addEventListener('focus', function () {
        updateSuggest();
        if (input.value.trim() === state.q && state.page && results.hidden) setOpen(results, true);
    });
    input.addEventListener('blur', function () { setTimeout(closeSuggest, 120); });
    input.addEventListener('keydown', function (e) {
        if (suggest.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
        else if ((e.key === 'Enter' || e.key === 'Tab') && active >= 0) { e.preventDefault(); apply(active); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSuggest(); }
    });

    results.addEventListener('click', function (e) {
        if (e.target.closest('[data-thean-ous-more]')) { if (!state.loading) search(state.q, state.page + 1); return; }
        var btn = e.target.closest('[data-thean-ous-preview]');
        if (btn) { e.preventDefault(); openPreview(btn.getAttribute('data-thean-ous-preview')); return; }
        if (e.target.closest('a, button, input, select, textarea')) return;
        var row = e.target.closest('tr[data-order-id]');
        if (row) openPreview(row.getAttribute('data-order-id'));
    });
    results.addEventListener('keydown', function (e) {
        var row = e.target.closest('tr[data-order-id]');
        if (row && e.target === row && e.key === 'Enter') openPreview(row.getAttribute('data-order-id'));
    });

    document.addEventListener('click', function (e) {
        var close = e.target.closest('[data-thean-ous-close]');
        if (close) setOpen(close.closest('.thean-ous-modal'), false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (!suggest.hidden) return; // ô gợi ý tự đóng (handler của input)
            // Modal thao tác nhanh (orders-admin.php) nằm trên cùng — để nó tự đóng trước.
            if (cfg.blockingModals && document.querySelector(cfg.blockingModals)) return;
            if (!preview.hidden) setOpen(preview, false);
            else if (!results.hidden) setOpen(results, false);
            return;
        }
        var tag = (e.target.tagName || '').toLowerCase();
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(input|textarea|select)$/.test(tag) && !e.target.isContentEditable) {
            e.preventDefault();
            input.focus();
            input.select();
        }
    }, true); // capture: chạy trước handler Escape của modal thao tác nhanh
}());

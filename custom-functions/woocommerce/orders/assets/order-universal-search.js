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
    var board = document.getElementById('thean-ous-board');
    var searchCtl = null, previewCtl = null, boardCtl = null, timer = 0;
    var boardState = { tab: '', page: 0, loading: false };
    var boardCache = new Map(); // "tab|page" → {at, data}; 30s
    var blockedUntil = 0; // server khóa tìm kiếm (quá nhiều lượt) — không tự gửi tới khi hết hạn
    var state = { q: '', page: 0, loading: false };
    var cache = new Map(); // "q|page" → {at, data}; 60s, tránh gọi lại khi mở/đóng modal
    var items = [], active = -1;
    var statusCmds = ['tt'].concat(Object.keys(cfg.aliases).filter(function (k) { return cfg.aliases[k] === 'tt'; }));

    // Đặt thanh tìm ở đầu trang danh sách, ngay dưới tiêu đề.
    var anchor = document.querySelector('.wrap .wp-header-end') || document.querySelector('.wrap h1');
    if (anchor) anchor.parentNode.insertBefore(bar, anchor.nextSibling);
    bar.hidden = false;

    // Board "Cần xử lý" nằm ngay dưới thanh tìm (trên trang, không phải modal).
    bar.parentNode.insertBefore(board, bar.nextSibling);

    /* ---------- Menu ⋯: bật / tắt bảng danh sách của Woo (order-list-lazy.php — mặc định tắt, không nhớ) ---------- */
    var menu = bar.querySelector('[data-thean-ous-menu]');
    var menuBtn = menu.querySelector('.thean-ous-menu__btn');
    var menuList = menu.querySelector('.thean-ous-menu__list');
    function setMenu(open) { menuList.hidden = !open; menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    (function () {
        var wc = cfg.wcList;
        if (!wc) return;
        var link = document.createElement('a');
        link.setAttribute('role', 'menuitem');
        link.className = 'thean-ous-menu__item';
        if (wc.off) {
            var url = new URL(location.href);
            url.searchParams.set('thean_wc_list', '1');
            link.href = url.toString();
            link.textContent = '⤓ Tải danh sách Woo';
            link.title = 'Tải lại trang kèm bảng danh sách đơn của WooCommerce (lần vào sau lại tắt)';
        } else {
            link.href = wc.hideUrl;
            link.textContent = 'Ẩn danh sách Woo';
            // Giữ bảng khi phân trang / đổi tab trạng thái / sắp xếp / lọc.
            document.querySelectorAll('.wrap .subsubsub a, .wrap .tablenav a, .wrap .wp-list-table thead a, .wrap .wp-list-table tfoot a').forEach(function (a) {
                try { var u = new URL(a.href); u.searchParams.set('thean_wc_list', '1'); a.href = u.toString(); } catch (e) {}
            });
            document.querySelectorAll('#posts-filter, #wc-orders-filter').forEach(function (form) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'thean_wc_list'; input.value = '1';
                form.appendChild(input);
            });
        }
        menuList.appendChild(link);
        menu.hidden = false;
    }());
    menuBtn.addEventListener('click', function () { setMenu(menuList.hidden); });
    document.addEventListener('click', function (e) { if (!menuList.hidden && !menu.contains(e.target)) setMenu(false); });

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
    function shell(modal) {
        var wrap = document.createElement('div');
        var statusOpts = (cfg.statuses || []).map(function (st) { return '<option value="' + st.slug + '">' + st.name.replace(/</g, '&lt;') + '</option>'; }).join('');
        wrap.innerHTML = '<div class="thean-ous-bulk" hidden><strong data-bulk-count></strong>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-ous-bulk="hold">⏸️ Tạm giữ</button>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-ous-bulk="resume">▶️ Tiếp tục</button>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-ous-bulk="preorder">📅 Đặt trước</button>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--danger" data-thean-ous-bulk="cancel">✖️ Hủy</button>'
            + '<span class="thean-ous-bulk__status"><select data-bulk-status aria-label="Trạng thái mới"><option value="">Đổi trạng thái…</option>' + statusOpts + '</select>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--view" data-thean-ous-bulk="status">Áp dụng</button></span>'
            + '<button type="button" class="thean-ous-bulk__clear" data-thean-ous-bulk-clear>Bỏ chọn</button></div>'
            + '<div class="thean-ous-bulk-result" hidden></div>'
            + '<table class="thean-ous__table"><colgroup><col class="c-check"><col class="c-order"><col class="c-cus"><col class="c-items"><col class="c-next"><col class="c-acts"></colgroup>'
            + '<thead><tr><th class="thean-ous__c-check"><input type="checkbox" data-thean-ous-check-all aria-label="Chọn tất cả đơn đang hiện"></th><th>Đơn · Trạng thái · Tổng</th><th>Khách hàng · Giao hàng</th><th>Sản phẩm</th><th>Thao tác tiếp theo</th><th>Thao tác</th></tr></thead><tbody></tbody></table>'
            + '<div class="thean-ous__more" hidden><button type="button" class="thean-ous__more-btn" data-thean-ous-more></button></div>';
        body(modal).replaceChildren(wrap);
        selection(modal).clear();
        return wrap;
    }
    function fillTable(modal, data, page) {
        var tbody = page === 1 ? shell(modal).querySelector('tbody') : body(modal).querySelector('tbody');
        tbody.insertAdjacentHTML('beforeend', data.rows);
        syncBulk(modal);
        var more = body(modal).querySelector('.thean-ous__more');
        more.hidden = !data.has_more;
        if (data.has_more) more.querySelector('button').textContent = 'Xem thêm ' + Math.min(cfg.perPage, data.total - data.shown) + ' đơn (còn ' + (data.total - data.shown) + ')';
    }
    function render(data, page) {
        var status = results.querySelector('.thean-ous-modal__status');
        var chips = results.querySelector('.thean-ous-chips');
        chips.replaceChildren();
        (data.summary || []).forEach(function (txt) { var s = document.createElement('span'); s.textContent = txt; chips.appendChild(s); });
        status.textContent = '— ' + (data.capped ? cfg.cap + '+' : data.total) + ' đơn' + (data.total ? ', đang hiện ' + data.shown : '');
        if (!data.total) { notice(body(results), 'Không tìm thấy đơn nào khớp.'); return; }
        fillTable(results, data, page);
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

    /* ---------- Chọn nhiều đơn + thao tác hàng loạt (kết quả tìm + board) ---------- */
    var selected = { results: new Set(), board: new Set() };
    function selection(modal) { return modal === board ? selected.board : selected.results; }
    function syncBulk(modal) {
        var set = selection(modal);
        var boxes = body(modal).querySelectorAll('[data-thean-ous-check]');
        boxes.forEach(function (box) { box.checked = set.has(box.value); box.closest('tr').classList.toggle('is-selected', box.checked); });
        var all = body(modal).querySelector('[data-thean-ous-check-all]');
        if (all) {
            var on = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
            all.checked = boxes.length > 0 && on === boxes.length;
            all.indeterminate = on > 0 && on < boxes.length;
        }
        var bar = body(modal).querySelector('.thean-ous-bulk');
        if (bar) {
            bar.hidden = !set.size;
            bar.querySelector('[data-bulk-count]').textContent = set.size + ' đơn đã chọn';
        }
    }
    function onCheck(modal, e) {
        var set = selection(modal);
        if (e.target.matches('[data-thean-ous-check-all]')) {
            body(modal).querySelectorAll('[data-thean-ous-check]').forEach(function (box) {
                if (e.target.checked) set.add(box.value); else set.delete(box.value);
            });
        } else if (e.target.matches('[data-thean-ous-check]')) {
            if (e.target.checked) set.add(e.target.value); else set.delete(e.target.value);
        } else {
            return;
        }
        syncBulk(modal);
    }
    function toast(text, isError) {
        var t = document.createElement('div');
        t.className = 'thean-ous-toast' + (isError ? ' is-error' : '');
        t.textContent = text;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 5000);
    }
    function refresh(modal) {
        if (modal === board) { boardCache.clear(); loadBoard(boardState.tab, 1, false); return; }
        cache.clear();
        state.page = 0;
        if (state.q) search(state.q, 1);
    }
    function runBulk(modal, op) {
        var ids = Array.from(selection(modal));
        if (!ids.length) return;
        if (ids.length > 50) { toast('Mỗi lần tối đa 50 đơn — bỏ bớt đơn đã chọn.', true); return; }
        if (!window.theanFlow) { toast('Chưa tải được popup thao tác — tải lại trang.', true); return; }
        var statusSel = body(modal).querySelector('[data-bulk-status]');
        var status = op === 'status' ? statusSel.value : '';
        if (op === 'status' && !status) { toast('Chọn trạng thái mới trước.', true); return; }
        var kind = window.theanFlow.kinds[op];
        var spec = op === 'status'
            ? { title: '🔁 Đổi trạng thái ' + ids.length + ' đơn → ' + statusSel.options[statusSel.selectedIndex].text, help: 'Ghi chú (không bắt buộc) sẽ ghi vào từng đơn.', placeholder: 'Ghi chú', required: false, buttons: [['status', 'Đổi trạng thái ' + ids.length + ' đơn', 'primary']] }
            : Object.assign({}, kind, { title: kind.title + ' — ' + ids.length + ' đơn', buttons: [[op, kind.buttons[0][1] + ' (' + ids.length + ' đơn)', kind.buttons[0][2]]] });
        window.theanFlow.prompt(spec, function (note) {
            return post('thean_order_usearch_bulk', { op: op, ids: ids.join(','), reason: note, status: status }, new AbortController()).then(function (res) {
                if (!res.success) throw new Error(message(res, 'Không thực hiện được.'));
                var d = res.data;
                var lines = [d.message].concat((d.failed || []).map(function (f) { return '#' + f.id + ': ' + f.message; }));
                selection(modal).clear();
                refresh(modal);
                // Kết quả (kể cả đơn lỗi) hiện trên đầu bảng sau khi tải lại.
                setTimeout(function () {
                    var box = body(modal).querySelector('.thean-ous-bulk-result');
                    if (box) { box.textContent = lines.join(' · '); box.hidden = false; box.classList.toggle('is-error', (d.failed || []).length > 0); }
                }, 800);
                toast(d.message, (d.failed || []).length > 0);
                document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { bulk: true } }));
            });
        });
    }
    function onBulkClick(modal, e) {
        var btn = e.target.closest('[data-thean-ous-bulk]');
        if (btn) { runBulk(modal, btn.getAttribute('data-thean-ous-bulk')); return true; }
        if (e.target.closest('[data-thean-ous-bulk-clear]')) { selection(modal).clear(); syncBulk(modal); return true; }
        return false;
    }

    /* ---------- Board "Cần xử lý" (tab theo bước của bảng điều phối) ---------- */
    function rememberTab(tab) { try { localStorage.setItem('theanOusBoardTab', tab); } catch (e) {} }
    function savedTab() { try { return localStorage.getItem('theanOusBoardTab') || ''; } catch (e) { return ''; } }
    function renderTabs(tabs, current) {
        var nav = board.querySelector('.thean-ous-tabs');
        nav.replaceChildren();
        tabs.forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'thean-ous-tab' + (t.key === current ? ' is-active' : '') + (t.count ? '' : ' is-empty');
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', t.key === current ? 'true' : 'false');
            b.dataset.theanOusTab = t.key;
            var label = document.createElement('span'); label.textContent = t.label;
            var count = document.createElement('b'); count.textContent = t.count;
            b.append(label, count);
            nav.appendChild(b);
        });
        var on = nav.querySelector('.is-active');
        if (on) on.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    function renderBoard(data, page) {
        boardState.tab = data.tab;
        boardState.page = page;
        rememberTab(data.tab);
        renderTabs(data.tabs, data.tab);
        board.querySelector('.thean-ous-modal__status').textContent = '— cập nhật ' + data.at + (data.pending ? ' · còn đơn chưa được phân bước, bấm Làm mới để tính tiếp' : '');
        if (!data.total) { notice(body(board), 'Không có đơn nào ở bước này 🎉'); return; }
        fillTable(board, data, page);
    }
    function loadBoard(tab, page, refresh) {
        var key = tab + '|' + page, hit = boardCache.get(key);
        board.hidden = false;
        if (!refresh && tab && hit && Date.now() - hit.at < 30000) { renderBoard(hit.data, page); return; }
        if (refresh) boardCache.clear();
        if (boardCtl) boardCtl.abort();
        boardCtl = new AbortController();
        boardState.loading = true;
        var moreBtn = body(board).querySelector('[data-thean-ous-more]');
        if (page === 1) notice(body(board), 'Đang tải đơn cần xử lý…');
        else if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Đang tải…'; }
        var data = { tab: tab, page: page };
        if (refresh) data.refresh = 1;
        post('thean_order_usearch_board', data, boardCtl).then(function (res) {
            if (!res.success) {
                if (res.data && res.data.retry_after) blockedUntil = Date.now() + res.data.retry_after * 1000;
                throw new Error(message(res, 'Không tải được board.'));
            }
            boardCache.set(res.data.tab + '|' + page, { at: Date.now(), data: res.data });
            renderBoard(res.data, page);
        }).catch(function (err) {
            if (err.name !== 'AbortError') notice(body(board), err.message || 'Lỗi kết nối.');
        }).finally(function () { boardState.loading = false; if (moreBtn) moreBtn.disabled = false; });
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

    // Bảng đơn (kết quả tìm + board): Xem nhanh / bấm dòng → modal xem nhanh.
    function tableClick(e) {
        var btn = e.target.closest('[data-thean-ous-preview]');
        if (btn) { e.preventDefault(); openPreview(btn.getAttribute('data-thean-ous-preview')); return; }
        if (e.target.closest('a, button, input, select, textarea')) return;
        var row = e.target.closest('tr[data-order-id]');
        if (row) openPreview(row.getAttribute('data-order-id'));
    }
    function tableKey(e) {
        var row = e.target.closest('tr[data-order-id]');
        if (row && e.target === row && e.key === 'Enter') openPreview(row.getAttribute('data-order-id'));
    }
    results.addEventListener('click', function (e) {
        if (e.target.closest('[data-thean-ous-more]')) { if (!state.loading) search(state.q, state.page + 1); return; }
        if (onBulkClick(results, e)) return;
        tableClick(e);
    });
    results.addEventListener('change', function (e) { onCheck(results, e); });
    board.addEventListener('change', function (e) { onCheck(board, e); });
    board.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-thean-ous-tab]');
        if (tab) { if (!boardState.loading || tab.dataset.theanOusTab !== boardState.tab) loadBoard(tab.dataset.theanOusTab, 1, false); return; }
        if (e.target.closest('[data-thean-ous-board-refresh]')) { loadBoard(boardState.tab, 1, true); return; }
        if (e.target.closest('[data-thean-ous-board-close]')) { board.hidden = true; return; }
        if (onBulkClick(board, e)) return;
        if (e.target.closest('[data-thean-ous-more]')) { if (!boardState.loading) loadBoard(boardState.tab, boardState.page + 1, false); return; }
        tableClick(e);
    });
    // Popup thao tác (order-flow-popup.js) báo đơn đã đổi → board tính lại tab đang xem.
    document.addEventListener('thean:order-updated', function (e) {
        if (e.detail && e.detail.bulk) return; // runBulk đã tự làm mới
        if (!board.hidden) { boardCache.clear(); loadBoard(boardState.tab, 1, false); }
        cache.clear(); // kết quả tìm: lần mở / tìm sau lấy dữ liệu mới
    });
    results.addEventListener('keydown', tableKey);
    board.addEventListener('keydown', tableKey);
    bar.querySelector('[data-thean-ous-board]').addEventListener('click', function () {
        closeSuggest();
        loadBoard(boardState.tab || savedTab(), 1, false);
    });

    document.addEventListener('click', function (e) {
        var close = e.target.closest('[data-thean-ous-close]');
        if (close) setOpen(close.closest('.thean-ous-modal'), false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (!suggest.hidden) return; // ô gợi ý tự đóng (handler của input)
            if (!menuList.hidden) { setMenu(false); return; }
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

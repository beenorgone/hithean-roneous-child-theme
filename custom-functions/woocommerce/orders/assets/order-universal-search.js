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
    var invoice = document.getElementById('thean-ous-invoice');
    var searchCtl = null, previewCtl = null, boardCtl = null, timer = 0;
    var boardState = { tab: '', page: 0, shown: 0, loading: false };
    var boardCache = new Map(); // "tab|page" → {at, data}; trang 1 của mọi tab nạp sẵn khi mở board (BOARD_TTL)
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
    function setMenu(open) {
        menuList.hidden = !open;
        menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) return;
        // Mặc định canh mép phải nút ⋯; trên mobile nút rớt xuống đầu dòng → menu tràn mép trái màn hình. Đo rồi canh lại.
        menuList.style.left = '';
        menuList.style.right = '';
        var r = menuList.getBoundingClientRect(), b = menuBtn.getBoundingClientRect(), pad = 8;
        if (r.left < pad) {
            menuList.style.right = 'auto';
            menuList.style.left = Math.round(pad - b.left) + 'px';
            r = menuList.getBoundingClientRect();
            if (r.right > window.innerWidth - pad) menuList.style.left = Math.round(pad - b.left - (r.right - window.innerWidth + pad)) + 'px';
        }
    }
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
        var dot = bar.querySelector('[data-thean-ous-alert-dot]');
        if (dot) { dot.hidden = alertCount.hidden; dot.title = alertCount.title; }
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

    /* ---------- Ẩn thanh admin + menu trái (menu ⋯, mặc định bật, lưu user meta — what=chrome) ---------- */
    var chromeBox = bar.elements.chrome_off;
    if (chromeBox) {
        chromeBox.checked = cfg.chromeOff !== false;
        chromeBox.addEventListener('change', function () {
            document.body.classList.toggle('thean-ous-chrome-off', chromeBox.checked);
            stickyTop();
            var fd = new FormData();
            fd.append('action', 'thean_order_usearch_alerts');
            fd.append('nonce', cfg.nonce);
            fd.append('what', 'chrome');
            if (chromeBox.checked) fd.append('off', '1');
            fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
        });
    }

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
        if (modal === invoice && !open) invoice.querySelector('iframe').src = 'about:blank';
        document.body.classList.toggle('thean-ous-open', !results.hidden || !preview.hidden || !invoice.hidden || (typeof acts !== 'undefined' && !acts.hidden));
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
            + (cfg.canSkipProof && modal === board && boardState.tab === 'proof' ? '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-ous-bulk="skipproof">⏭️ Bỏ qua thiếu CT</button>' : '')
            + (cfg.canChangeStatus ? '<span class="thean-ous-bulk__status"><select data-bulk-status aria-label="Trạng thái mới"><option value="">Đổi trạng thái…</option>' + statusOpts + '</select>'
                + '<button type="button" class="thean-ous-btn thean-ous-btn--view" data-thean-ous-bulk="status">Áp dụng</button></span>' : '')
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
        var step = modal === board ? BOARD_MORE : cfg.perPage;
        if (data.has_more) more.querySelector('button').textContent = 'Xem thêm ' + Math.min(step, data.total - data.shown) + ' đơn (còn ' + (data.total - data.shown) + ')';
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
        var status = op === 'status' && statusSel ? statusSel.value : '';
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
    // Xem hóa đơn (bản xem trước của trang Tạo đơn — AJAX order_creator_invoice_preview) trong popup, In / Mở tab mới.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-thean-ous-invoice]');
        if (btn) {
            e.preventDefault();
            var url = btn.getAttribute('data-thean-ous-invoice');
            invoice.querySelector('#thean-ous-invoice-title').textContent = '🧾 Hóa đơn đơn #' + (btn.getAttribute('data-order-id') || '');
            invoice.querySelector('[data-thean-ous-invoice-open]').href = url;
            invoice.querySelector('iframe').src = url;
            setOpen(invoice, true);
            return;
        }
        if (e.target.closest('[data-thean-ous-invoice-print]')) {
            try { invoice.querySelector('iframe').contentWindow.print(); } catch (err) { toast('Không in được — dùng "Mở tab mới".', true); }
        }
    });

    // Đổi trạng thái tùy ý 1 đơn (chỉ người được chỉ định — cfg.canChangeStatus; server kiểm tra lại).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-thean-ous-status]');
        if (!btn || !cfg.canChangeStatus) return;
        e.preventDefault();
        if (!window.theanFlow) { toast('Chưa tải được popup thao tác — tải lại trang.', true); return; }
        var id = btn.getAttribute('data-order-id');
        var current = btn.getAttribute('data-thean-ous-status');
        var host = btn.closest('#thean-ous-board') ? board : (btn.closest('#thean-ous-results') ? results : null);
        window.theanFlow.prompt({
            title: '🔁 Đổi trạng thái — đơn #' + id,
            help: 'Chuyển sang trạng thái bất kỳ (ngoài luồng thông thường). Ghi chú (không bắt buộc) lưu vào đơn.',
            placeholder: 'Ghi chú',
            required: false,
            select: { label: 'Trạng thái mới…', options: (cfg.statuses || []).filter(function (st) { return st.slug !== current; }).map(function (st) { return [st.slug, st.name]; }) },
            buttons: [['status', 'Đổi trạng thái', 'primary']]
        }, function (note, key, status) {
            return post('thean_order_usearch_bulk', { op: 'status', ids: id, status: status, reason: note }, new AbortController()).then(function (res) {
                if (!res.success) throw new Error(message(res, 'Không đổi được trạng thái.'));
                var failed = (res.data.failed || [])[0];
                if (failed) throw new Error(failed.message);
                toast('Đơn #' + id + ': đã đổi trạng thái.');
                if (host) refresh(host);
                document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: id, bulk: !!host } }));
            });
        });
    });

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
        renderTabNav(tabs, current);
    }
    // Mobile: dải tab tràn ngang → tab khuất không thấy. Thanh ‹ [chọn bước ▾] › chuyển qua mọi tab (CSS chỉ hiện ở màn hẹp).
    function renderTabNav(tabs, current) {
        var nav = board.querySelector('.thean-ous-tabnav');
        if (!nav) {
            nav = document.createElement('div');
            nav.className = 'thean-ous-tabnav';
            nav.innerHTML = '<button type="button" class="thean-ous-tabnav__btn" data-dir="-1" aria-label="Bước trước">‹</button>'
                + '<select class="thean-ous-tabnav__select" aria-label="Chọn bước" data-thean-ous-tabnav></select>'
                + '<button type="button" class="thean-ous-tabnav__btn" data-dir="1" aria-label="Bước sau">›</button>';
            var strip = board.querySelector('.thean-ous-tabs');
            strip.parentNode.insertBefore(nav, strip);
        }
        var select = nav.querySelector('select');
        select.replaceChildren();
        var idx = 0;
        tabs.forEach(function (t, i) {
            var o = document.createElement('option');
            o.value = t.key;
            o.textContent = t.label + ' (' + t.count + ')';
            if (t.key === current) { o.selected = true; idx = i; }
            select.appendChild(o);
        });
        // ‹ › nhảy sang tab kề có đơn (bỏ tab trống); không còn thì giữ tab kề bất kỳ.
        nav.querySelectorAll('.thean-ous-tabnav__btn').forEach(function (b) {
            var dir = +b.dataset.dir, target = null;
            for (var i = idx + dir; i >= 0 && i < tabs.length; i += dir) {
                if (tabs[i].count) { target = tabs[i]; break; }
            }
            if (!target) target = tabs[idx + dir] || null;
            b.disabled = !target;
            if (target) { b.dataset.theanOusTab = target.key; b.title = target.label + ' (' + target.count + ')'; }
            else delete b.dataset.theanOusTab;
        });
    }
    function renderBoard(data, page) {
        boardState.tab = data.tab;
        boardState.page = page;
        boardState.shown = data.shown || 0;
        rememberTab(data.tab);
        renderTabs(data.tabs, data.tab);
        board.querySelector('.thean-ous-modal__status').textContent = '— cập nhật ' + data.at + (data.pending ? ' · còn đơn chưa được phân bước, bấm Làm mới để tính tiếp' : '');
        if (!data.total) { notice(body(board), 'Không có đơn nào ở bước này 🎉'); return; }
        fillTable(board, data, page);
        // Lần tải đầu chỉ 5 đơn / tab cho nhẹ — tab đang xem tự tải thêm cho đủ BOARD_MORE (20) đơn.
        if (page === 1 && data.has_more && data.shown < BOARD_MORE) {
            loadBoard(data.tab, 2, false, BOARD_MORE - data.shown);
        }
    }
    // Trang 1 (mở board / Làm mới / sau thao tác): server trả luôn 5 đơn đầu của MỌI tab (all = 1) → đổi tab lấy từ
    // boardCache, không gọi lại server. "Xem thêm" trong 1 tab tải tiếp từ vị trí đang hiện (offset).
    var BOARD_TTL = 60000; // 1 phút: đơn mới vào tab (đặt hàng, đổi trạng thái ở nơi khác) hiện ra khi đổi tab mà không cần Làm mới
    var BOARD_MORE = 20; // = THEAN_OUS_BOARD_MORE
    function loadBoard(tab, page, refresh, limit) {
        var key = tab + '|' + page, hit = boardCache.get(key);
        board.hidden = false;
        if (!refresh && tab && hit && Date.now() - hit.at < BOARD_TTL) { renderBoard(hit.data, page); return; }
        if (page === 1) boardCache.clear();
        if (boardCtl) boardCtl.abort();
        boardCtl = new AbortController();
        boardState.loading = true;
        var moreBtn = body(board).querySelector('[data-thean-ous-more]');
        if (page === 1) notice(body(board), 'Đang tải đơn cần xử lý…');
        else if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Đang tải…'; }
        var data = { tab: tab };
        if (page === 1) data.all = 1;
        else { data.offset = boardState.shown; data.limit = limit || BOARD_MORE; }
        post('thean_order_usearch_board', data, boardCtl).then(function (res) {
            if (!res.success) {
                if (res.data && res.data.retry_after) blockedUntil = Date.now() + res.data.retry_after * 1000;
                throw new Error(message(res, 'Không tải được board.'));
            }
            var d = res.data, at = Date.now();
            if (d.panes) {
                // Tab không có đơn cũng có pane rỗng → bấm vào không phải gọi server.
                (d.tabs || []).forEach(function (t) {
                    var pane = d.panes[t.key] || { tab: t.key, rows: '', total: 0, shown: 0, page: 1, has_more: false };
                    boardCache.set(t.key + '|1', { at: at, data: Object.assign({}, pane, { tabs: d.tabs, pending: d.pending, at: d.at }) });
                });
            } else {
                boardCache.set(d.tab + '|' + page, { at: at, data: d });
            }
            // Đã đổi sang tab khác trong lúc tải thêm → chỉ lưu cache, không chèn vào tab đang xem.
            if (page > 1 && d.tab !== boardState.tab) return;
            renderBoard(d, page);
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

    /* ---------- Mobile: mọi thao tác của 1 đơn gom vào nút "⚡ Thao tác" → popup ---------- */
    // Nhóm "Tiếp theo" = bản sao cột Thao tác tiếp theo; "Tổng quan" = <template data-thean-ous-acts-all> (mọi action, theo nhóm).
    // Nút trong popup dùng chung handler toàn trang (modal thao tác nhanh, popup luồng, VAT, hóa đơn…).
    var acts = document.createElement('div');
    acts.className = 'thean-ous-modal thean-ous-modal--acts';
    acts.id = 'thean-ous-acts';
    acts.hidden = true;
    acts.innerHTML = '<div class="thean-ous-modal__backdrop" data-thean-ous-close></div>'
        + '<div class="thean-ous-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="thean-ous-acts-title">'
        + '<button type="button" class="thean-ous-modal__close" data-thean-ous-close aria-label="Đóng">&times;</button>'
        + '<h2 class="thean-ous-modal__title" id="thean-ous-acts-title"></h2><div class="thean-ous-acts-pop__meta"></div>'
        + '<div class="thean-ous-modal__body"></div></div>';
    document.body.appendChild(acts);
    function openActs(row) {
        var id = row.getAttribute('data-order-id');
        var head = row.querySelector('.thean-ous__order-head');
        var title = acts.querySelector('.thean-ous-modal__title');
        title.replaceChildren(document.createTextNode('⚡ Thao tác đơn #' + id + ' '));
        var badge = head && head.querySelector('mark.order-status');
        if (badge) title.appendChild(badge.cloneNode(true));
        var cus = row.querySelector('.thean-ous__c-cus strong');
        acts.querySelector('.thean-ous-acts-pop__meta').textContent = cus ? cus.textContent.trim() : '';
        var out = document.createDocumentFragment();
        function section(label, nodes, cls) {
            if (!nodes.length) return;
            var sec = document.createElement('section');
            sec.className = 'thean-ous-acts-pop__sec ' + cls;
            var h = document.createElement('h3'); h.textContent = label;
            sec.appendChild(h);
            nodes.forEach(function (n) { sec.appendChild(n); });
            out.appendChild(sec);
        }
        var next = row.querySelector('.thean-ous__c-next');
        section('👉 Thao tác tiếp theo', next ? Array.prototype.map.call(next.children, function (n) { return n.cloneNode(true); }) : [], 'is-next');
        var all = row.querySelector('template[data-thean-ous-acts-all]');
        var quick = document.createElement('div');
        quick.className = 'thean-ous-acts__g';
        quick.innerHTML = '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-ous-preview="' + id + '">Xem nhanh</button>';
        section('📋 Tổng quan', [quick].concat(all ? [all.content.cloneNode(true)] : []), 'is-all');
        body(acts).replaceChildren(out);
        acts.dataset.orderId = id;
        setOpen(acts, true);
    }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-thean-ous-acts]');
        if (btn) { e.preventDefault(); var row = btn.closest('tr[data-order-id]'); if (row) openActs(row); return; }
        if (acts.hidden || !acts.contains(e.target)) return;
        var pv = e.target.closest('[data-thean-ous-preview]');
        if (pv) { e.preventDefault(); setOpen(acts, false); openPreview(pv.getAttribute('data-thean-ous-preview')); }
    });
    // Đơn vừa đổi (popup luồng / thao tác nhanh) → đóng popup thao tác, board tự tải lại.
    document.addEventListener('thean:order-updated', function () { if (!acts.hidden) setOpen(acts, false); });

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
    board.addEventListener('change', function (e) {
        if (e.target.matches('[data-thean-ous-tabnav]')) { loadBoard(e.target.value, 1, false); return; }
        onCheck(board, e);
    });
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

    /* ---------- 🧹 Xoá cache: bỏ cache trình duyệt + server (version cache orders), tính lại bước flow các đơn mở ---------- */
    var flushBtn = bar.querySelector('[data-thean-ous-flush]');
    if (flushBtn) flushBtn.addEventListener('click', function () {
        if (flushBtn.disabled) return;
        var label = flushBtn.querySelector('span'), orig = label.textContent;
        flushBtn.disabled = true;
        cache.clear();
        boardCache.clear();
        var ctl = new AbortController();
        (function step(offset) {
            label.textContent = offset ? 'Đang tính… ' + offset : 'Đang xoá…';
            post('thean_order_usearch_flush', { offset: offset }, ctl).then(function (res) {
                if (!res.success) throw new Error(message(res, 'Không xoá được cache.'));
                var d = res.data;
                label.textContent = 'Đang tính… ' + d.done + '/' + d.total;
                if (!d.finished) { step(d.done); return; }
                flushBtn.disabled = false;
                label.textContent = orig;
                toast('🧹 Đã xoá cache, tính lại bước cho ' + d.done + ' đơn đang mở.');
                if (!board.hidden) loadBoard(boardState.tab, 1, true);
                if (!results.hidden && state.q) { state.page = 0; search(state.q, 1); }
            }).catch(function (err) {
                flushBtn.disabled = false;
                label.textContent = orig;
                toast(err.message || 'Lỗi kết nối.', true);
            });
        }(0));
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
            if (!invoice.hidden) setOpen(invoice, false);
            else if (!preview.hidden) setOpen(preview, false);
            else if (!acts.hidden) setOpen(acts, false);
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

/* Ô tìm đơn ở danh sách đơn admin — order-universal-search.php. Dùng chung theanmarket + hithean;
 * khác biệt giữa 2 site truyền qua window.theanOusCfg (blockingModals…). */
(function () {
    var cfg = window.theanOusCfg || {};
    if (!document.getElementById('thean-ous-bar')) return;
    var bar = document.getElementById('thean-ous-bar');
    var input = bar.elements.q;
    var rangeSelect = bar.elements.range;
    var currentRange = 'auto';
    try {
        var linkedRange = new URLSearchParams(location.search).get('thean_range');
        if (['auto', '7', '30', '90', 'all'].indexOf(linkedRange) !== -1) currentRange = linkedRange;
    } catch (e) {}
    if (rangeSelect) rangeSelect.value = currentRange;
    var suggest = document.getElementById('thean-ous-suggest');
    var results = document.getElementById('thean-ous-results');
    var preview = document.getElementById('thean-ous-preview');
    var board = document.getElementById('thean-ous-board');
    var invoice = document.getElementById('thean-ous-invoice');
    var guide = document.getElementById('thean-ous-workflow-guide'), guideOpener = null;
    var searchCtl = null, previewCtl = null, boardCtl = null, timer = 0;
    var boardState = { tab: '', page: 0, shown: 0, cursor: 0, loading: false };
    var boardCache = new Map(); // "tab|page" → {at, data}; chỉ giữ các tab đã xem (BOARD_TTL)
    var blockedUntil = 0; // server khóa tìm kiếm (quá nhiều lượt) — không tự gửi tới khi hết hạn
    var state = { q: '', page: 0, shown: 0, cursor: 0, loading: false, per: cfg.perPage || 10 };
    try { var savedPer = parseInt(localStorage.getItem('theanOusPer'), 10); if ((cfg.perPageOptions || []).indexOf(savedPer) !== -1) state.per = savedPer; } catch (e) {}
    var rowUpdates = Promise.resolve(), rowEpoch = 0, rowScope = 0;
    var rowPatches = new Map(), lastPatchTabs = null;
    var activeBoardData = null, activeSearchData = null;
    var cache = new Map(); // "q|page" → {at, data}; 60s, tránh gọi lại khi mở/đóng modal
    var items = [], active = -1;
    var statusCmds = ['tt'].concat(Object.keys(cfg.aliases).filter(function (k) { return cfg.aliases[k] === 'tt'; }));
    var groupCmds = ['nhom'].concat(Object.keys(cfg.aliases).filter(function (k) { return cfg.aliases[k] === 'nhom'; }));
    var stSel = []; // slug trạng thái chọn ở nút "Trạng thái" — ghép vào truy vấn thành /nhom + /tt (currentQuery)

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
            .then(function (r) { return r.json(); })
            .then(function (res) { checkVer(res); return res; });
    }
    // Code đã deploy bản mới trong lúc tab đang mở → dòng (PHP mới) lệch cột với tiêu đề (JS cũ): nhắc tải lại.
    var staleShown = false;
    function checkVer(res) {
        var v = res && res.data && res.data.ver;
        if (!v || !cfg.ver || v === cfg.ver || staleShown) return;
        staleShown = true;
        var el = document.createElement('div');
        el.className = 'thean-ous-stale';
        el.setAttribute('role', 'alert');
        el.innerHTML = '⚠️ Công cụ tìm đơn vừa được cập nhật — bảng có thể lệch cột. <button type="button" class="thean-ous-btn thean-ous-btn--primary">Tải lại trang</button>';
        el.querySelector('button').addEventListener('click', function () { location.reload(); });
        document.body.appendChild(el);
    }
    function message(res, fallback) { return (res && res.data && res.data.message) || fallback; }
    function setOpen(modal, open) {
        if (modal === results && open) {
            // Chừa chỗ cho thanh tìm (đang nổi trên modal) để vẫn thấy mình gõ gì.
            results.style.paddingTop = Math.round((bar.querySelector('.thean-ous-bar__sticky') || bar).getBoundingClientRect().bottom + 8) + 'px'; // /quan-ly-don: form là display:contents
        }
        if (modal === guide && open) guideOpener = document.activeElement;
        modal.hidden = !open;
        if (modal === guide) {
            if (open) {
                guide.querySelector('.thean-ous-modal__dialog').scrollTop = 0;
                guide.querySelector('button[data-thean-ous-close]').focus({ preventScroll: true });
            }
            else if (guideOpener && guideOpener.isConnected) guideOpener.focus({ preventScroll: true });
        }
        if (modal === invoice && !open) invoice.querySelector('iframe').src = 'about:blank';
        document.body.classList.toggle('thean-ous-open', !results.hidden || !preview.hidden || !invoice.hidden || (typeof acts !== 'undefined' && !acts.hidden) || (guide && !guide.hidden));
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
        if (m && groupCmds.indexOf(m[1].toLowerCase()) !== -1) {
            var g = norm(m[2]);
            renderSuggest('Nhóm trạng thái', (cfg.statusGroups || []).filter(function (gr) {
                return !g || gr.key.indexOf(g) !== -1 || norm(gr.label).indexOf(g) !== -1;
            }).map(function (gr) { return { code: gr.key, text: gr.label + ' (' + gr.slugs.length + ' trạng thái)', replace: m[2], insert: gr.key + ' ', run: true }; }));
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
    // Ô tìm + bộ lọc trạng thái → 1 truy vấn (nhóm chọn đủ → /nhom, còn lại → /tt). Cache, giới hạn lượt, cập nhật dòng đều theo chuỗi này.
    function statusTokens() {
        var left = stSel.slice(), out = [];
        (cfg.statusGroups || []).forEach(function (gr) {
            if (gr.slugs.length > 1 && gr.slugs.every(function (sl) { return left.indexOf(sl) !== -1; })) {
                out.push('/nhom ' + gr.key);
                left = left.filter(function (sl) { return gr.slugs.indexOf(sl) === -1; });
            }
        });
        return out.concat(left.map(function (sl) { return '/tt ' + sl; })).join(' ');
    }
    function currentQuery() {
        return (input.value.trim() + ' ' + statusTokens()).trim();
    }
    // Không tìm tức thì khi gõ / chọn gợi ý / đổi bộ lọc — chỉ tìm khi Enter hoặc bấm "Tìm" (submit của thanh tìm).
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
            + '<div class="thean-ous__more" hidden><button type="button" class="thean-ous__more-btn" data-thean-ous-more></button></div>'
            + (modal === results ? '<nav class="thean-ous-pager" aria-label="Phân trang kết quả" hidden></nav>' : '');
        body(modal).replaceChildren(wrap);
        selection(modal).clear();
        return wrap;
    }
    function renderPager(data) {
        var nav = body(results).querySelector('.thean-ous-pager');
        if (!nav) return;
        var per = data.per || state.per, pages = Math.max(1, Math.ceil((data.total || 0) / per)), cur = Math.min(data.page || 1, pages);
        nav.hidden = !data.total;
        if (!data.total) return;
        var nums = [], i;
        for (i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - cur) <= 2) nums.push(i);
        var html = '<button type="button" class="thean-ous-pager__btn" data-thean-ous-page="' + (cur - 1) + '"' + (cur <= 1 ? ' disabled' : '') + ' aria-label="Trang trước">‹</button>';
        nums.forEach(function (n, k) {
            if (k && n - nums[k - 1] > 1) html += '<span class="thean-ous-pager__gap">…</span>';
            html += '<button type="button" class="thean-ous-pager__btn' + (n === cur ? ' is-current' : '') + '" data-thean-ous-page="' + n + '"' + (n === cur ? ' aria-current="page"' : '') + '>' + n + '</button>';
        });
        html += '<button type="button" class="thean-ous-pager__btn" data-thean-ous-page="' + (cur + 1) + '"' + (cur >= pages ? ' disabled' : '') + ' aria-label="Trang sau">›</button>';
        var from = (cur - 1) * per + 1, to = Math.min(data.total, cur * per);
        html += '<span class="thean-ous-pager__info">' + from + '–' + to + ' / ' + (data.capped ? cfg.cap + '+' : data.total) + ' đơn</span>';
        html += '<label class="thean-ous-pager__per"><select data-thean-ous-per aria-label="Số đơn mỗi trang">'
            + (cfg.perPageOptions || [per]).map(function (o) { return '<option value="' + o + '"' + (o === per ? ' selected' : '') + '>' + o + ' / trang</option>'; }).join('')
            + '</select></label>';
        nav.innerHTML = html;
    }
    function updateMore(modal, data) {
        if (modal === results) { // kết quả tìm: phân trang số, không "Xem thêm"
            var mr = body(results).querySelector('.thean-ous__more');
            if (mr) mr.hidden = true;
            renderPager(data);
            return;
        }
        var more = body(modal).querySelector('.thean-ous__more');
        if (!more) return;
        more.hidden = !data.has_more;
        var step = modal === board ? BOARD_MORE : cfg.perPage;
        if (data.has_more) more.querySelector('button').textContent = 'Xem thêm ' + Math.max(1, Math.min(step, data.total - data.shown)) + ' đơn (còn ' + Math.max(0, data.total - data.shown) + ')';
    }
    // Groups are views of the loaded rows, never extra orders or pagination cursors.
    function boardRowsHtml(tbody) {
        return Array.from(tbody.querySelectorAll('tr[data-order-id]')).map(function (row) { return row.outerHTML; }).join('');
    }
    function groupBoardRows(modal, data) {
        if (modal !== board) return;
        var tab = (data.tabs || []).find(function (item) { return item.key === data.tab; });
        var groups = tab && tab.groups;
        var tbody = body(modal).querySelector('tbody');
        if (!tbody || !groups || !groups.length) return;
        var rows = Array.from(tbody.querySelectorAll('tr[data-order-id]'));
        var anchor = tbody.firstElementChild;
        function place(node) {
            if (node === anchor) anchor = anchor.nextElementSibling;
            else tbody.insertBefore(node, anchor);
        }
        groups.forEach(function (group) {
            var members = rows.filter(function (row) { return row.dataset.opsStep === group.key; });
            var header = Array.from(tbody.querySelectorAll('[data-ops-group-header]')).find(function (row) { return row.dataset.opsGroupHeader === group.key; });
            if (!header) {
                header = document.createElement('tr');
                header.dataset.opsGroupHeader = group.key;
                header.className = 'thean-ous-group';
                var cell = document.createElement('th'); cell.colSpan = 6; header.appendChild(cell);
            }
            header.firstElementChild.textContent = group.label + ' — đang hiện ' + members.length + ' / ' + group.count + ' đơn'
                + (!members.length && group.count ? ' · Bấm Xem thêm để tải các đơn còn lại.' : '');
            place(header);
            members.forEach(place);
        });
    }
    function fillTable(modal, data, page) {
        var tbody = page === 1 ? shell(modal).querySelector('tbody') : body(modal).querySelector('tbody');
        var incoming = document.createElement('tbody');
        incoming.innerHTML = data.rows;
        Array.from(incoming.children).forEach(function (row) {
            if (!tbody.querySelector('tr[data-order-id="' + row.dataset.orderId + '"]')) tbody.appendChild(row);
        });
        groupBoardRows(modal, data);
        data.shown = tbody.querySelectorAll('tr[data-order-id]').length;
        if (typeof data.exhausted === 'boolean') data.has_more = !data.exhausted && data.shown < data.total;
        syncBulk(modal);
        updateMore(modal, data);
    }
    function render(data, page) {
        activeSearchData = data;
        var status = results.querySelector('.thean-ous-modal__status');
        var chips = results.querySelector('.thean-ous-chips');
        chips.replaceChildren();
        (data.summary || []).forEach(function (txt) { var s = document.createElement('span'); s.textContent = txt; chips.appendChild(s); });
        var pages = Math.max(1, Math.ceil((data.total || 0) / (data.per || state.per)));
        status.textContent = '— ' + (data.capped ? cfg.cap + '+' : data.total) + ' đơn' + (data.total && pages > 1 ? ' · trang ' + (data.page || 1) + '/' + pages : '');
        if (!data.total) {
            notice(body(results), 'Không tìm thấy đơn nào khớp trong khoảng đang chọn.');
            if (currentRange !== 'all') {
                var expand = document.createElement('button');
                expand.type = 'button';
                expand.className = 'thean-ous-btn thean-ous-btn--primary';
                expand.textContent = 'Tìm trong toàn bộ thời gian';
                expand.dataset.theanOusAllDates = '1';
                body(results).appendChild(expand);
            }
            return;
        }
        fillTable(results, data, 1); // mỗi trang = bảng mới
        state.shown = data.shown;
        if (page > 1 || data.page > 1) { var top = body(results); if (top.scrollTo) top.scrollTo({ top: 0 }); }
    }
    function search(q, page) {
        var key = q + '|' + page + '|' + state.per, hit = cache.get(key);
        if (page === 1 && q === state.q && state.page >= 1 && !results.hidden) return;
        if (searchCtl) searchCtl.abort();
        var ctl = new AbortController();
        searchCtl = ctl;
        var fresh = q !== state.q || page === 1; // truy vấn mới / trang đầu → báo "đang tìm"; chỉ đổi trang thì giữ bảng cũ tới khi có trang mới
        state.q = q;
        state.loading = false;
        setOpen(results, true);
        if (hit && Date.now() - hit.at < 60000) { state.page = page; render(hit.data, page); return; }
        state.loading = true;
        var moreBtn = null, pager = body(results).querySelector('.thean-ous-pager');
        if (fresh || !pager) { results.querySelector('.thean-ous-modal__status').textContent = '— đang tìm…'; notice(body(results), 'Đang tìm “' + q + '”…'); }
        else {
            results.querySelector('.thean-ous-modal__status').textContent = '— đang tải trang ' + page + '…';
            if (pager) pager.querySelectorAll('button, select').forEach(function (el) { el.disabled = true; });
        }
        var epoch = rowEpoch;
        post('thean_order_usearch', { q: q, page: page, per: state.per, range: currentRange }, ctl).then(async function (res) {
            await rowUpdates;
            if (searchCtl !== ctl) return;
            if (!res.success) {
                if (res.data && res.data.retry_after) blockedUntil = Date.now() + res.data.retry_after * 1000;
                throw new Error(message(res, 'Không tìm được.'));
            }
            if (epoch !== rowEpoch) res.data.rows = overlayRows(res.data.rows, null, q, true);
            cache.set(key, { at: Date.now(), data: res.data });
            state.page = page;
            render(res.data, page);
        }).catch(function (err) {
            if (searchCtl !== ctl || err.name === 'AbortError') return;
            state.page = 0;
            results.querySelector('.thean-ous-modal__status').textContent = '';
            results.querySelector('.thean-ous-chips').replaceChildren();
            notice(body(results), err.message || 'Lỗi kết nối.');
        }).finally(function () { if (searchCtl !== ctl) return; state.loading = false; if (moreBtn) moreBtn.disabled = false; });
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
    function toast(text, isError, retry) {
        var t = document.createElement('div');
        t.className = 'thean-ous-toast' + (isError ? ' is-error' : '');
        t.textContent = text;
        if (retry) {
            var button = document.createElement('button');
            button.type = 'button'; button.className = 'thean-ous-btn'; button.textContent = 'Thử đồng bộ lại';
            button.addEventListener('click', function () { t.remove(); retry(); });
            t.appendChild(button);
        }
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, retry ? 15000 : 5000);
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
                return refreshOrders(d.done || []).then(function () {
                    var box = body(modal).querySelector('.thean-ous-bulk-result');
                    if (box) { box.textContent = lines.join(' · '); box.hidden = false; box.classList.toggle('is-error', (d.failed || []).length > 0); }
                    toast(d.message, (d.failed || []).length > 0);
                    document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderIds: d.done || [], synced: true } }));
                });
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
                return refreshOrders([id]).then(function () {
                    document.dispatchEvent(new CustomEvent('thean:order-updated', { detail: { orderId: id, synced: true } }));
                });
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
    // Cookie = bản sao tab đã xem để /quan-ly-don in sẵn đúng tab đó (thean_ous_portal_board_initial).
    function rememberTab(tab) {
        try { localStorage.setItem('theanOusBoardTab', tab); } catch (e) {}
        try { document.cookie = 'thean_ous_tab=' + encodeURIComponent(tab) + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); } catch (e) {}
    }
    function savedTab() { try { return localStorage.getItem('theanOusBoardTab') || ''; } catch (e) { return ''; } }
    // Link thẳng tới 1 tab: ?thean_board=<tab> (confirm, payment, picking, export, delivery, shipping, cod, proof,
    // return, preorder — thean_ops_step_tabs()). URL trên thanh địa chỉ đi theo tab đang xem → sao chép là gửi được.
    var URL_TAB = 'thean_board';
    function urlTab() { try { return new URLSearchParams(location.search).get(URL_TAB) || ''; } catch (e) { return ''; } }
    function tabUrl(tab) {
        var u = new URL(location.href);
        if (tab) u.searchParams.set(URL_TAB, tab); else u.searchParams.delete(URL_TAB);
        u.hash = '';
        return u.toString();
    }
    function syncUrlTab(tab) {
        try { if (urlTab() !== tab) history.replaceState(history.state, '', tabUrl(tab)); } catch (e) {}
    }
    function renderTabs(tabs, current) {
        var nav = board.querySelector('.thean-ous-tabs');
        nav.replaceChildren();
        // Thứ tự vận hành (thean_ops_step_flow): luồng chính đánh số + mũi tên, rồi vạch tách nhóm ngoại lệ / song song.
        var mainTotal = tabs.filter(function (t) { return t.lane === 'main'; }).length, sideStarted = false;
        tabs.forEach(function (t, i) {
            if (t.lane === 'side' && !sideStarted) {
                sideStarted = true;
                var sep = document.createElement('span');
                sep.className = 'thean-ous-tabs__sep';
                sep.textContent = 'Ngoại lệ / song song';
                nav.appendChild(sep);
            } else if (t.lane === 'main' && i > 0) {
                var arrow = document.createElement('span');
                arrow.className = 'thean-ous-tabs__arrow';
                arrow.setAttribute('aria-hidden', 'true');
                arrow.textContent = '→';
                nav.appendChild(arrow);
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'thean-ous-tab' + (t.key === current ? ' is-active' : '') + (t.count ? '' : ' is-empty');
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', t.key === current ? 'true' : 'false');
            b.dataset.theanOusTab = t.key;
            if (t.lane === 'main' && t.no) {
                b.classList.add('thean-ous-tab--main');
                b.title = 'Bước ' + t.no + '/' + mainTotal + ' của luồng chính';
                var no = document.createElement('i'); no.className = 'thean-ous-tab__no'; no.textContent = t.no;
                b.appendChild(no);
            } else if (t.lane === 'side') {
                b.classList.add('thean-ous-tab--side');
                b.title = 'Ngoại lệ / song song — không phải bước kế tiếp của luồng chính';
            }
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
        var idx = 0, groups = {};
        tabs.forEach(function (t, i) {
            var o = document.createElement('option');
            o.value = t.key;
            o.textContent = (t.lane === 'main' && t.no ? t.no + '. ' : '') + t.label + ' (' + t.count + ')';
            if (t.key === current) { o.selected = true; idx = i; }
            var lane = t.lane === 'side' ? 'side' : 'main';
            if (!groups[lane]) {
                groups[lane] = document.createElement('optgroup');
                groups[lane].label = lane === 'main' ? 'Luồng chính' : 'Ngoại lệ / song song';
                select.appendChild(groups[lane]);
            }
            groups[lane].appendChild(o);
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
    function patchRow(tbody, patch, belongs, html, append) {
        var id = String(patch.id), previous = tbody.querySelector('tr[data-order-id="' + id + '"]');
        if (!belongs || !html) { if (previous) previous.remove(); return; }
        if (!previous && !append) return;
        var incoming = document.createElement('tbody');
        incoming.innerHTML = html;
        var next = incoming.firstElementChild;
        if (!next || next.dataset.orderId !== id) return;
        if (previous) {
            var focused = previous.contains(document.activeElement);
            previous.replaceWith(next);
            if (focused) next.focus({ preventScroll: true });
        } else tbody.appendChild(next);
    }
    function overlayRows(html, tab, query, force) {
        var tbody = document.createElement('tbody');
        tbody.innerHTML = html;
        rowPatches.forEach(function (entry, id) {
            if (!force && Date.now() - entry.at > BOARD_TTL) { rowPatches.delete(id); return; }
            var patch = entry.patch;
            if (tab !== null) patchRow(tbody, patch, patch.tabs.indexOf(tab) !== -1, patch.rows, true);
            else if (entry.q === query) patchRow(tbody, patch, patch.search_match, patch.search_rows, false);
        });
        return tbody.innerHTML;
    }
    function rememberBoard(data) {
        var tbody = body(board).querySelector('tbody');
        boardCache.set(data.tab + '|1', { at: Date.now(), data: Object.assign({}, data, {
            rows: tbody ? boardRowsHtml(tbody) : '', shown: boardState.shown, page: boardState.page
        }) });
    }
    function applyRowUpdates(data) {
        lastPatchTabs = data.tabs;
        (data.orders || []).forEach(function (patch) {
            rowPatches.set(String(patch.id), { at: Date.now(), patch: patch, q: data.q });
            selection(board).delete(String(patch.id));
            selection(results).delete(String(patch.id));
        });
        var totals = {};
        data.tabs.forEach(function (tab) { totals[tab.key] = tab.count; });
        boardCache.forEach(function (entry, key) {
            if (!key.endsWith('|1')) { boardCache.delete(key); return; }
            var pane = entry.data;
            pane.rows = overlayRows(pane.rows, pane.tab, '');
            var parsed = document.createElement('tbody'); parsed.innerHTML = pane.rows;
            pane.shown = parsed.querySelectorAll('tr[data-order-id]').length;
            pane.total = totals[pane.tab] || 0;
            pane.tabs = data.tabs;
            pane.at = data.at;
            if (pane.exhausted && pane.shown < pane.total) { pane.exhausted = false; pane.next_cursor = 0; }
            pane.has_more = pane.shown < pane.total;
        });
        if (activeBoardData) {
            var tbody = body(board).querySelector('tbody');
            var insert = data.orders.some(function (patch) { return patch.tabs.indexOf(boardState.tab) !== -1 && patch.rows; });
            if (!tbody && insert) tbody = shell(board).querySelector('tbody');
            if (tbody) data.orders.forEach(function (patch) {
                patchRow(tbody, patch, patch.tabs.indexOf(boardState.tab) !== -1, patch.rows, true);
            });
            activeBoardData.tabs = data.tabs;
            activeBoardData.total = totals[boardState.tab] || 0;
            activeBoardData.at = data.at;
            groupBoardRows(board, activeBoardData);
            boardState.shown = tbody ? tbody.querySelectorAll('tr[data-order-id]').length : 0;
            activeBoardData.shown = boardState.shown;
            if (activeBoardData.exhausted && boardState.shown < activeBoardData.total) {
                activeBoardData.exhausted = false; activeBoardData.next_cursor = 0; boardState.cursor = 0;
            }
            activeBoardData.has_more = boardState.shown < activeBoardData.total;
            renderTabs(data.tabs, boardState.tab);
            board.querySelector('.thean-ous-modal__status').textContent = '— ' + (activeBoardData.range_label || '') + ' · cập nhật ' + data.at;
            updateMore(board, activeBoardData);
            syncBulk(board);
            rememberBoard(activeBoardData);
        }
        var searchBody = body(results).querySelector('tbody');
        if (searchBody && data.q === state.q && activeSearchData) {
            data.orders.forEach(function (patch) {
                var existed = searchBody.querySelector('tr[data-order-id="' + patch.id + '"]');
                if (existed && !patch.search_match) activeSearchData.total = Math.max(0, activeSearchData.total - 1);
                patchRow(searchBody, patch, patch.search_match, patch.search_rows, false);
            });
            state.shown = searchBody.children.length;
            activeSearchData.shown = state.shown;
            activeSearchData.has_more = state.shown < activeSearchData.total && !activeSearchData.exhausted;
            results.querySelector('.thean-ous-modal__status').textContent = '— đang hiện ' + state.shown + ' đơn · cập nhật ' + data.at;
            updateMore(results, activeSearchData);
            syncBulk(results);
        }
        cache.clear();
        if (data.q !== state.q && state.q && !results.hidden) {
            Promise.resolve().then(function () { refreshOrders(data.orders.map(function (patch) { return patch.id; })); });
        }
        if (!preview.hidden && data.orders.some(function (patch) { return String(patch.id) === preview.dataset.orderId; })) openPreview(preview.dataset.orderId);
    }
    function refreshOrders(ids) {
        ids = Array.from(new Set(ids.map(Number).filter(function (id) { return Number.isSafeInteger(id) && id > 0; })));
        if (!ids.length) return Promise.resolve();
        var range = currentRange, scope = rowScope, query = state.q || '';
        rowEpoch++;
        rowUpdates = rowUpdates.then(async function () {
            for (var i = 0; i < ids.length; i += 50) {
                var ctl = new AbortController();
                var timeout = setTimeout(function () { ctl.abort(); }, 15000);
                var res;
                try { res = await post('thean_order_usearch_rows', { ids: ids.slice(i, i + 50).join(','), range: range, q: query }, ctl); }
                finally { clearTimeout(timeout); }
                if (!res.success) throw new Error(message(res, 'Không cập nhật được dòng đơn.'));
                if (scope === rowScope && range === currentRange) applyRowUpdates(res.data);
            }
        }).catch(function (err) {
            if (scope === rowScope) toast('Đơn đã lưu; chưa đồng bộ được giao diện: ' + (err.message || 'Lỗi kết nối.'), true, function () { refreshOrders(ids); });
        });
        return rowUpdates;
    }
    function renderBoard(data, page) {
        data.rows = overlayRows(data.rows, data.tab, '');
        activeBoardData = data;
        boardState.tab = data.tab;
        boardState.page = page === 1 ? (data.page || 1) : page;
        boardState.cursor = page === 1 ? (data.next_cursor || 0) : (data.next_cursor || boardState.cursor);
        boardState.shown = data.shown || 0;
        rememberTab(data.tab);
        syncUrlTab(data.tab);
        renderTabs(data.tabs, data.tab);
        board.querySelector('.thean-ous-modal__status').textContent = '— ' + (data.range_label || '') + ' · cập nhật ' + data.at + (data.pending ? ' · đang chờ kiểm tra phân bước ở nền' : '');
        if (!data.total) { notice(body(board), data.pending ? 'Chưa có đơn ở bước này. Hệ thống đang chờ kiểm tra phân bước ở nền; bấm Làm mới sau ít giây.' : 'Không có đơn nào ở bước này 🎉'); return; }
        fillTable(board, data, page);
        boardState.shown = data.shown;
        rememberBoard(data);
        // Lượt đầu kết thúc tại đây; chỉ đọc thêm khi người dùng bấm "Xem thêm".
        if (page === 1 && window.performance && performance.mark && !performance.getEntriesByName('thean-board-first-visible').length) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    if (performance.getEntriesByName('thean-board-first-visible').length) return;
                    performance.mark('thean-board-rendered');
                    performance.measure('thean-board-first-visible', { start: 0, end: 'thean-board-rendered' });
                });
            });
        }
    }
    // Chỉ tải 10 đơn của tab đang xem; cache ngắn cho các tab đã mở.
    // "Xem thêm" tiếp tục từ số dòng thực tế đang hiển thị.
    var BOARD_TTL = 10000; // 10 giây: đơn mới vào tab (đặt hàng, đổi trạng thái ở nơi khác) hiện ra khi đổi tab mà không cần Làm mới
    var BOARD_MORE = 20; // = THEAN_OUS_BOARD_MORE
    function loadBoard(tab, page, refresh, limit) {
        if (tab === 'payment') tab = 'confirm'; // old bookmarks/saved tab
        if (tab === 'cod_fast') tab = 'cod';
        var key = tab + '|' + page, hit = boardCache.get(key);
        board.hidden = false;
        if (boardCtl) boardCtl.abort();
        var ctl = new AbortController();
        boardCtl = ctl;
        boardState.loading = false;
        if (refresh) { rowPatches.clear(); lastPatchTabs = null; }
        if (!refresh && page === 1 && tab && hit && Date.now() - hit.at < BOARD_TTL) { renderBoard(hit.data, page); return; }
        if (page === 1) {
            // Giữ cache tab khác; bỏ các trang cũ của tab sắp tải lại.
            boardCache.forEach(function (_, k) { if (k.indexOf(tab + '|') === 0) boardCache.delete(k); });
        }
        boardState.loading = true;
        var moreBtn = body(board).querySelector('[data-thean-ous-more]');
        if (page === 1) notice(body(board), 'Đang tải đơn cần xử lý…');
        else if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = 'Đang tải…'; }
        var data = { tab: tab, range: currentRange, before_id: page === 1 ? 0 : boardState.cursor, offset: page === 1 || !boardState.cursor ? 0 : boardState.shown, limit: page === 1 ? 10 : (limit || BOARD_MORE) };
        if (refresh) data.refresh = 1;
        var epoch = rowEpoch;
        post('thean_order_usearch_board', data, ctl).then(async function (res) {
            await rowUpdates;
            if (boardCtl !== ctl) return;
            if (!res.success) {
                if (res.data && res.data.retry_after) blockedUntil = Date.now() + res.data.retry_after * 1000;
                throw new Error(message(res, 'Không tải được board.'));
            }
            var d = res.data, at = Date.now();
            if (epoch !== rowEpoch && lastPatchTabs) {
                d.rows = overlayRows(d.rows, d.tab, '', true);
                d.tabs = lastPatchTabs;
                var updated = d.tabs.find(function (t) { return t.key === d.tab; });
                if (updated) d.total = updated.count;
            }
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
            if (boardCtl === ctl && err.name !== 'AbortError') notice(body(board), err.message || 'Lỗi kết nối.');
        }).finally(function () {
            if (boardCtl !== ctl) return;
            boardState.loading = false;
            if (moreBtn) { moreBtn.disabled = false; if (moreBtn.textContent === 'Đang tải…') moreBtn.textContent = 'Xem thêm'; }
        });
    }

    function changeRange() {
        currentRange = rangeSelect.value;
        rowScope++; rowEpoch++;
        rowPatches.clear(); lastPatchTabs = null; activeBoardData = null; activeSearchData = null;
        clearTimeout(timer);
        if (searchCtl) searchCtl.abort();
        searchCtl = null;
        state.loading = false;
        state.page = 0;
        cache.clear();
        boardCache.clear();
        try {
            var url = new URL(location.href);
            if (currentRange === 'auto') url.searchParams.delete('thean_range');
            else url.searchParams.set('thean_range', currentRange);
            history.replaceState(history.state, '', url.toString());
        } catch (e) {}
        if (!board.hidden) loadBoard(boardState.tab || savedTab(), 1, false);
        var q = currentQuery();
        if (!results.hidden) {
            if (ready(q)) search(q, 1);
            else setOpen(results, false);
        }
    }
    if (rangeSelect) rangeSelect.addEventListener('change', changeRange);

    function openPreview(orderId) {
        preview.dataset.orderId = String(orderId);
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
        var q = currentQuery();
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
    input.addEventListener('input', updateSuggest);
    input.addEventListener('click', updateSuggest);
    input.addEventListener('focus', function () {
        updateSuggest();
        if (currentQuery() === state.q && state.page && results.hidden) setOpen(results, true);
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
        quick.innerHTML = '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-ous-preview="' + id + '"><span class="thean-ous-btn__icon" aria-hidden="true">👁</span> Xem nhanh</button>';
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
    // Đơn vừa đổi (popup luồng / thao tác nhanh) → đóng popup thao tác, cập nhật các dòng đơn liên quan.
    document.addEventListener('thean:order-updated', function () { if (!acts.hidden) setOpen(acts, false); });
    var portalBoardPending = !!cfg.standalone && new URLSearchParams(location.search).get('thean_workspace') === 'create';
    document.addEventListener('thean:workspace-changed', function (e) {
        if (e.detail.workspace === 'orders' && portalBoardPending) { portalBoardPending = false; loadBoard(urlTab() || savedTab(), 1, false); }
        if (e.detail.workspace !== 'orders') [results, preview, invoice, acts].forEach(function (modal) { if (!modal.hidden) setOpen(modal, false); });
    });

    // Bảng đơn (kết quả tìm + board): chỉ nút "Xem nhanh" mở modal xem nhanh (bấm vào dòng không mở — dễ bấm nhầm khi chọn chữ / thao tác).
    function tableClick(e) {
        var btn = e.target.closest('[data-thean-ous-preview]');
        if (btn) { e.preventDefault(); openPreview(btn.getAttribute('data-thean-ous-preview')); }
    }
    function tableKey(e) {
        var row = e.target.closest('tr[data-order-id]');
        if (row && e.target === row && e.key === 'Enter') openPreview(row.getAttribute('data-order-id'));
    }
    results.addEventListener('click', function (e) {
        if (e.target.closest('[data-thean-ous-all-dates]')) { rangeSelect.value = 'all'; changeRange(); return; }
        var pg = e.target.closest('[data-thean-ous-page]');
        if (pg) { var n = parseInt(pg.dataset.theanOusPage, 10); if (n >= 1 && n !== state.page && !pg.disabled) search(state.q, n); return; }
        if (onBulkClick(results, e)) return;
        tableClick(e);
    });
    results.addEventListener('change', function (e) {
        if (e.target.matches('[data-thean-ous-per]')) {
            state.per = parseInt(e.target.value, 10) || cfg.perPage;
            try { localStorage.setItem('theanOusPer', String(state.per)); } catch (err) {}
            state.page = 0;
            search(state.q, 1);
            return;
        }
        onCheck(results, e);
    });

    /* ---------- Nút "Trạng thái": chọn nhóm / từng trạng thái → ghép /nhom, /tt vào truy vấn ---------- */
    var stf = bar.querySelector('[data-thean-ous-stf]');
    if (stf) (function () {
        var btn = stf.querySelector('.thean-ous-stf__btn'), panel = stf.querySelector('.thean-ous-stf__panel');
        var countEl = stf.querySelector('[data-thean-ous-stf-count]');
        var names = {};
        (cfg.statuses || []).forEach(function (st) { names[st.slug] = st.name; });
        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        panel.innerHTML = '<div class="thean-ous-stf__groups">' + (cfg.statusGroups || []).map(function (gr) {
            return '<fieldset class="thean-ous-stf__group"><legend><label><input type="checkbox" data-stf-group="' + esc(gr.key) + '"> ' + esc(gr.label) + '</label></legend>'
                + gr.slugs.map(function (sl) { return '<label class="thean-ous-stf__item"><input type="checkbox" value="' + esc(sl) + '" data-stf-status> ' + esc(names[sl] || sl) + '</label>'; }).join('')
                + '</fieldset>';
        }).join('') + '</div>'
            + '<div class="thean-ous-stf__foot"><button type="button" class="thean-ous-btn" data-stf-clear>Bỏ chọn</button>'
            + '<span class="thean-ous-stf__hint">Để trống ô tìm = liệt kê mọi đơn ở trạng thái đã chọn (theo khoảng Ngày tạo).</span>'
            + '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-stf-apply>Lọc</button></div>';
        function sync() {
            panel.querySelectorAll('[data-stf-status]').forEach(function (box) { box.checked = stSel.indexOf(box.value) !== -1; });
            panel.querySelectorAll('[data-stf-group]').forEach(function (box) {
                var gr = (cfg.statusGroups || []).find(function (g) { return g.key === box.dataset.stfGroup; });
                var on = gr ? gr.slugs.filter(function (sl) { return stSel.indexOf(sl) !== -1; }).length : 0;
                box.checked = !!gr && on === gr.slugs.length;
                box.indeterminate = on > 0 && !box.checked;
            });
            countEl.textContent = String(stSel.length);
            countEl.hidden = !stSel.length;
            btn.classList.toggle('is-active', stSel.length > 0);
        }
        function open(on) {
            panel.hidden = !on;
            btn.setAttribute('aria-expanded', String(on));
        }
        function run() {
            clearTimeout(timer);
            var q = currentQuery();
            if (q === state.q && state.page && !results.hidden) return;
            if (!q) { state.q = ''; state.page = 0; setOpen(results, false); }
            // Còn điều kiện lọc → chờ Enter / bấm "Tìm".
        }
        btn.addEventListener('click', function () { open(panel.hidden); });
        document.addEventListener('click', function (e) { if (!panel.hidden && !stf.contains(e.target)) open(false); });
        panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); open(false); btn.focus(); } });
        panel.addEventListener('change', function (e) {
            var box = e.target;
            if (box.matches('[data-stf-group]')) {
                var gr = (cfg.statusGroups || []).find(function (g) { return g.key === box.dataset.stfGroup; });
                if (gr) stSel = box.checked ? stSel.concat(gr.slugs.filter(function (sl) { return stSel.indexOf(sl) === -1; })) : stSel.filter(function (sl) { return gr.slugs.indexOf(sl) === -1; });
            } else if (box.matches('[data-stf-status]')) {
                stSel = box.checked ? stSel.concat([box.value]) : stSel.filter(function (sl) { return sl !== box.value; });
            }
            sync();
            run();
        });
        panel.addEventListener('click', function (e) {
            if (e.target.closest('[data-stf-clear]')) { stSel = []; sync(); run(); return; }
            if (e.target.closest('[data-stf-apply]')) {
                open(false);
                var q = currentQuery();
                if (!q) return;
                clearTimeout(timer);
                state.page = 0;
                search(q, 1);
            }
        });
        sync();
    })();
    board.addEventListener('change', function (e) {
        if (e.target.matches('[data-thean-ous-tabnav]')) { loadBoard(e.target.value, 1, false); return; }
        onCheck(board, e);
    });
    board.addEventListener('click', function (e) {
        var tab = e.target.closest('[data-thean-ous-tab]');
        if (tab) { if (!boardState.loading || tab.dataset.theanOusTab !== boardState.tab) loadBoard(tab.dataset.theanOusTab, 1, false); return; }
        if (e.target.closest('[data-thean-ous-board-refresh]')) { loadBoard(boardState.tab, 1, true); return; }
        if (e.target.closest('[data-thean-ous-board-close]')) { board.hidden = true; syncUrlTab(''); return; }
        if (e.target.closest('[data-thean-ous-board-link]')) { copyTabLink(); return; }
        if (onBulkClick(board, e)) return;
        if (e.target.closest('[data-thean-ous-more]')) { if (!boardState.loading) loadBoard(boardState.tab, boardState.page + 1, false); return; }
        tableClick(e);
    });
    // Mutation thành công: chỉ đọc lại những đơn vừa đổi, giữ nguyên các dòng khác.
    document.addEventListener('thean:order-updated', function (e) {
        var detail = e.detail || {};
        if (detail.synced) return;
        refreshOrders(detail.orderIds || (detail.orderId ? [detail.orderId] : []));
    });
    results.addEventListener('keydown', tableKey);
    board.addEventListener('keydown', tableKey);
    bar.querySelector('[data-thean-ous-board]').addEventListener('click', function () {
        closeSuggest();
        loadBoard(boardState.tab || savedTab(), 1, false);
    });
    function copyTabLink() {
        var link = tabUrl(boardState.tab);
        var done = function () { toast('🔗 Đã sao chép link tab đang xem.'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link).then(done, function () { window.prompt('Sao chép link tab:', link); });
        } else {
            window.prompt('Sao chép link tab:', link);
        }
    }
    // ?thean_board=<tab>: luôn mở board đúng tab đó. Không có → cfg.boardAutoload: tải board luôn (tab đã xem lần
    // trước), trừ khi người dùng vừa chủ động mở bảng Woo (?thean_wc_list=1).
    // /quan-ly-don in sẵn trang đầu board (cfg.boardInitial): dùng khi đúng tab + khoảng ngày client định mở, không thì gọi AJAX như cũ.
    function seedInitialBoard(want) {
        var init = cfg.boardInitial;
        cfg.boardInitial = null; // chỉ dùng 1 lần, dữ liệu theo thời điểm tải trang
        if (!init || !init.data || !init.data.tab || init.range !== currentRange || init.requested !== want) return want;
        boardCache.set(init.data.tab + '|1', { at: Date.now(), data: init.data });
        return init.data.tab;
    }
    if (!portalBoardPending && urlTab()) loadBoard(seedInitialBoard(urlTab()), 1, false);
    else if (!portalBoardPending && cfg.boardAutoload && !(cfg.wcList && !cfg.wcList.off)) loadBoard(seedInitialBoard(savedTab()), 1, false);

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
        var guideButton = e.target.closest('[data-thean-ous-workflow-guide]');
        if (guideButton) {
            var scope = guideButton.dataset.theanOusWorkflowGuide || 'orders';
            var titles = { orders: 'Quy trình xử lý đơn', shopee: 'Hướng dẫn xử lý đơn Shopee', products: 'Hướng dẫn công cụ sản phẩm', exports: 'Hướng dẫn xuất kho' };
            if (!titles[scope]) scope = 'orders';
            guide.querySelector('#thean-ous-workflow-title').textContent = titles[scope];
            guide.querySelectorAll('[data-workflow-guide-scope]').forEach(function (section) { section.hidden = section.dataset.workflowGuideScope !== scope; });
            setOpen(guide, true); return;
        }
        var guideTab = e.target.closest('[data-thean-ous-guide-tab]');
        if (guideTab) { setOpen(guide, false); loadBoard(guideTab.dataset.theanOusGuideTab, 1, false); board.scrollIntoView({ block: 'start' }); return; }
        var close = e.target.closest('[data-thean-ous-close]');
        if (close) setOpen(close.closest('.thean-ous-modal'), false);
    });
    document.addEventListener('keydown', function (e) {
        if (guide && !guide.hidden) {
            if (e.key === 'Escape') {
                e.preventDefault(); e.stopImmediatePropagation(); setOpen(guide, false);
            } else if (e.key === 'Tab') {
                var nodes = guide.querySelectorAll('button, a[href], [tabindex="0"]');
                var first = nodes[0], last = nodes[nodes.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
            return;
        }
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
            if (window.TheanOrderWorkspace) window.TheanOrderWorkspace.show('orders');
            input.focus();
            input.select();
        }
    }, true); // capture: chạy trước handler Escape của modal thao tác nhanh
}());

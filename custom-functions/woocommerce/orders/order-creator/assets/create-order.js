/* global OrderCreator */
(function () {
    'use strict';

    var CFG = window.OrderCreator || {};
    var SET = CFG.settings || {};
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var customerModalEditId = 0;

    var state = {
        editOrderId: Number(CFG.editOrderId) || 0,
        customer: null,        // {id, name, ...}
        items: [],             // [{product_id, variation_id, name, qty, manual_price, line_discount}]
        coupons: [],           // [code]
        couponStatus: {},      // { code: {applied, message, discount, detail} }
        _couponNotified: {},   // { code: true } — đã hiện popup lỗi cho mã này
        fees: [],              // [{name, amount}]
        shipping_method: '',
        lastOrder: null,
        lastSnap: null,        // kết quả tính lại gần nhất (thành tiền từng dòng cho form AI)
        aiPending: [],         // dòng AI chưa chắc: [{source, qty, reason, candidates, choice}]
        aiSource: '',          // nội dung đã dán để lên đơn bằng AI (lưu vào đơn)
        aiMatches: [],         // tài khoản trùng SĐT/email khi AI chưa chọn được 1 khách
        aiUnmatched: {},       // tỉnh/phường AI đọc được nhưng không khớp danh mục
        aiGuest: null,         // khách cũ chưa có tài khoản: đơn gần nhất cùng SĐT/email
        aiCreateCustomer: true, // khách mới: tạo tài khoản trước khi tạo đơn
        customerCoupons: []    // mã còn hạn gắn với SĐT/email/tài khoản khách
    };
    var couponSearchTimer = 0;
    var couponSearchSeq = 0;
    var couponSuggestions = [];
    var couponActiveIndex = -1;

    // ---------- helpers ----------
    function money(n) {
        n = Math.round(Number(n) || 0);
        return n.toLocaleString('vi-VN') + ' ' + (CFG.currencySymbol || '₫');
    }

    function post(action, params, nonce) {
        var n = nonce || CFG.nonce;
        var fd = new FormData();
        fd.append('action', action);
        // Gửi nonce dưới cả 3 tên để tương thích mọi check_ajax_referer
        // (order_creator_*, confirm_order_payment...).
        fd.append('nonce', n);
        fd.append('_wpnonce', n);
        fd.append('_ajax_nonce', n);
        Object.keys(params || {}).forEach(function (k) {
            if (Array.isArray(params[k])) { params[k].forEach(function (v) { fd.append(k, v); }); }
            else { fd.append(k, params[k]); }
        });
        return fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) {
                return r.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (e) {
                        data = null;
                    }
                    if (data && typeof data === 'object') {
                        return data;
                    }
                    text = (text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    throw new Error(text || ('HTTP ' + r.status));
                });
            });
    }

    function buildPayload() {
        var c = state.customer || {};
        return {
            order_id: state.editOrderId || 0,
            customer_id: c.id || 0,
            items: state.items.map(function (it) {
                return {
                    product_id: it.product_id,
                    variation_id: it.variation_id || 0,
                    qty: it.qty,
                    manual_price: it.manual_price === '' || it.manual_price == null ? '' : it.manual_price,
                    line_discount: it.line_discount || 0
                };
            }),
            coupons: state.coupons.slice(),
            fees: state.fees.slice(),
            shipping_method: state.shipping_method,
            shipping_cost: $('#oc-shipping-cost').value.trim(),
            shipping_title: $('#oc-shipping-title-choice').value || $('#oc-shipping-title').value.trim(),
            // Address defaults are persisted only by the explicit confirmation action.
            save_customer_addresses: false,
            order_meta: collectOrderMeta(),
            billing: collectBilling(),
            shipping: collectShipping(),
            payment_method: $('#oc-payment').value,
            status: $('#oc-status').value,
            order_date: $('#oc-order-date').value,
            customer_note: $('#oc-customer-note').value,
            internal_note: $('#oc-internal-note').value,
            suppress_email: $('#oc-suppress-email').checked,
            ai_source: state.aiSource || ''
        };
    }

    function collectOrderMeta() {
        var values = {};
        Object.keys(CFG.orderFieldDefs || {}).forEach(function (key) {
            var el = $('#oc-order-' + key);
            if (!el) { return; }
            if (CFG.orderFieldDefs[key].type === 'checkboxes') {
                values[key] = Array.prototype.map.call(el.querySelectorAll('input:checked'), function (input) { return input.value; });
                return;
            }
            values[key] = el.multiple ? Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }) : el.value;
        });
        return values;
    }

    function fillOrderMeta(values) {
        values = values || {};
        Object.keys(CFG.orderFieldDefs || {}).forEach(function (key) {
            var el = $('#oc-order-' + key);
            if (!el) { return; }
            var value = values[key];
            if (CFG.orderFieldDefs[key].type === 'checkboxes') {
                var checkedValues = Array.isArray(value) ? value : (value ? [value] : []);
                Array.prototype.forEach.call(el.querySelectorAll('input'), function (input) { input.checked = checkedValues.indexOf(input.value) !== -1; });
                return;
            }
            if (el.multiple) {
                Array.prototype.forEach.call(el.options, function (o) { o.selected = Array.isArray(value) && value.indexOf(o.value) !== -1; });
            } else {
                el.value = value || '';
            }
        });
    }

    function nameSplit(full) {
        full = full.trim();
        return { first_name: full, last_name: '' };
    }
    function fmtName(addr) { addr = addr || {}; return ((addr.last_name || '') + ' ' + (addr.first_name || '')).trim(); }

    function collectBilling() {
        var c = state.customer || {};
        var base = c.billing ? Object.assign({}, c.billing) : {};
        var nm = nameSplit($('#oc-bill-name').value);
        base.first_name = nm.first_name;
        base.last_name = nm.last_name;
        base.phone = $('#oc-bill-phone').value.trim();
        base.email = $('#oc-bill-email').value.trim() || base.email || c.email || '';
        base.address_1 = $('#oc-bill-address').value.trim();
        base.state = $('#oc-bill-state').value.trim();
        base.city = $('#oc-bill-city').value.trim();
        return base;
    }

    function collectShipping() {
        if (!$('#oc-ship-diff').checked) { return collectBilling(); }
        var c = state.customer || {};
        var base = c.shipping ? Object.assign({}, c.shipping) : {};
        var nm = nameSplit($('#oc-ship-name').value);
        base.first_name = nm.first_name;
        base.last_name = nm.last_name;
        base.phone = $('#oc-ship-phone').value.trim();
        base.address_1 = $('#oc-ship-address').value.trim();
        base.state = $('#oc-ship-state').value.trim();
        base.city = $('#oc-ship-city').value.trim();
        base.email = base.email || (c.billing && c.billing.email) || c.email || '';
        return base;
    }

    function addrEqual(a, b) {
        a = a || {}; b = b || {};
        return (a.address_1 || '') === (b.address_1 || '')
            && (a.city || '') === (b.city || '')
            && (a.state || '') === (b.state || '')
            && (a.phone || '') === (b.phone || '')
            && fmtName(a) === fmtName(b);
    }

    function fillBilling(b) {
        b = b || {};
        $('#oc-bill-name').value = fmtName(b);
        $('#oc-bill-phone').value = b.phone || '';
        $('#oc-bill-email').value = b.email || '';
        $('#oc-bill-address').value = b.address_1 || '';
        return vnSetAddress($('#oc-bill-state'), $('#oc-bill-city'), b.state || '', b.city || '');
    }
    function fillShipping(s) {
        s = s || {};
        $('#oc-ship-name').value = fmtName(s);
        $('#oc-ship-phone').value = s.phone || '';
        $('#oc-ship-address').value = s.address_1 || '';
        vnSetAddress($('#oc-ship-state'), $('#oc-ship-city'), s.state || '', s.city || '');
    }
    function setShipDiff(on) { $('#oc-ship-diff').checked = !!on; $('#oc-ship-fields').hidden = !on; }

    function copyBillingToShipping() {
        var billing = collectBilling();
        fillShipping({
            first_name: billing.first_name,
            last_name: billing.last_name,
            phone: billing.phone,
            address_1: billing.address_1,
            state: billing.state || '',
            city: billing.city || ''
        });
    }

    function loadShippingFromOrder() {
        var input = $('#oc-ship-load-order');
        var button = $('#oc-ship-load-btn');
        var status = $('#oc-ship-load-status');
        var orderId = input.value.trim().replace(/^#/, '');
        if (!orderId) { status.textContent = 'Nhập mã đơn cần tải.'; return; }
        button.disabled = true;
        status.textContent = 'Đang tải địa chỉ giao hàng...';
        post('order_creator_load_order', { order_id: orderId }).then(function (res) {
            button.disabled = false;
            if (!res.success) {
                status.textContent = '❌ ' + ((res.data && res.data.message) || 'Không tìm thấy đơn hàng.');
                return;
            }
            var shipping = res.data.shipping || {};
            if (!(shipping.address_1 || shipping.city || shipping.phone || shipping.first_name || shipping.last_name)) {
                shipping = res.data.billing || {};
            }
            setShipDiff(true);
            fillShipping(shipping);
            status.textContent = '✓ Đã tải địa chỉ giao hàng từ đơn #' + (res.data.order_number || orderId) + '.';
            recalc();
        }).catch(function () {
            button.disabled = false;
            status.textContent = '❌ Không thể tải địa chỉ giao hàng.';
        });
    }

    function applyAddresses(billing, shipping) {
        fillBilling(billing);
        var hasShip = shipping && (shipping.address_1 || shipping.city || shipping.phone);
        var diff = hasShip && !addrEqual(billing, shipping);
        setShipDiff(diff);
        fillShipping(diff ? shipping : {});
    }

    // ---------- init selects ----------
    function fillSelect(el, list, valueKey, labelKey, selected) {
        el.innerHTML = '';
        list.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[valueKey];
            opt.textContent = o[labelKey];
            if (selected && o[valueKey] === selected) opt.selected = true;
            el.appendChild(opt);
        });
    }

    function gatewayExists(id) {
        return !!id && (CFG.gateways || []).some(function (gateway) { return gateway.id === id; });
    }

    function setPaymentMethod(id) {
        if (gatewayExists(id)) { $('#oc-payment').value = id; }
    }

    function applyCustomerPaymentDefault(customer) {
        if (!customer || state.editOrderId) { return; }
        setPaymentMethod(customer.default_payment_method || '');
    }

    function initSelects() {
        fillSelect($('#oc-status'), CFG.statuses || [], 'key', 'label', 'on-hold');
        fillSelect($('#oc-payment'), CFG.gateways || [], 'id', 'title', 'cod');
        if ($('#oc-ai-order')) {
            fillSelect($('#oc-ai-order-status'), CFG.statuses || [], 'key', 'label', 'on-hold');
            fillSelect($('#oc-ai-payment'), CFG.gateways || [], 'id', 'title', 'cod');
        }
        var bank = $('#oc-pay-bank');
        (CFG.bankAccounts || []).forEach(function (b) {
            var o = document.createElement('option'); o.value = b; o.textContent = b; bank.appendChild(o);
        });
    }

    // ---------- product search ----------
    var prodTimer;
    function onProductSearch() {
        clearTimeout(prodTimer);
        var term = $('#oc-product-search').value.trim();
        var box = $('#oc-product-results');
        if (term.length < 2) { box.hidden = true; return; }
        prodTimer = setTimeout(function () {
            post('order_creator_search_products', { term: term }).then(function (res) {
                if (!res.success) { return; }
                renderProductResults(res.data.products || [], box);
            });
        }, 300);
    }

    function onProductFocus() {
        var input = $('#oc-product-search');
        if (input.value.trim() !== '') { return; }
        var dp = SET.default_products || [];
        if (dp.length) { renderProductResults(dp, $('#oc-product-results')); }
    }

    function productRowData(p, v) {
        return {
            pid: p.id,
            vid: v ? v.id : 0,
            label: v ? (p.name + ' — ' + v.label) : (p.name + (p.sku ? ' (' + p.sku + ')' : '')),
            price: v ? v.price : p.price,
            image: v ? (v.image || p.image) : p.image,
            stock: v ? v.stock : p.stock,
            hidden: !!p.hidden
        };
    }

    function renderProductResults(products, box) {
        box = box || $('#oc-product-results');
        box.innerHTML = '';
        if (!products.length) { box.innerHTML = '<div class="oc-result-empty">Không tìm thấy.</div>'; box.hidden = false; return; }
        var hiddenShown = false;
        products.forEach(function (p) {
            if (p.hidden && !hiddenShown && SET.group_hidden_last) {
                var sep = document.createElement('div');
                sep.className = 'oc-result-sep';
                sep.textContent = 'Sản phẩm ẩn / riêng tư';
                box.appendChild(sep);
                hiddenShown = true;
            }
            if (p.type === 'variable' && p.variations.length) {
                p.variations.forEach(function (v) { addResultRow(box, productRowData(p, v)); });
            } else {
                addResultRow(box, productRowData(p));
            }
        });
        appendResultMultiBar(box);
        box.hidden = false;
    }

    // Thanh "chọn tất cả + thêm đã chọn" để thêm nhiều SP một lượt.
    function appendResultMultiBar(box) {
        var bar = document.createElement('div');
        bar.className = 'oc-result-actions-bar';
        bar.innerHTML =
            '<label class="oc-result-selall"><input type="checkbox" class="oc-result-selall-cb"> Chọn tất cả</label>' +
            '<button type="button" class="oc-btn oc-btn--primary oc-result-add-selected">Thêm đã chọn</button>';
        bar.querySelector('.oc-result-selall-cb').addEventListener('change', function () {
            var on = this.checked;
            box.querySelectorAll('.oc-result-check').forEach(function (cb) { cb.checked = on; });
        });
        bar.querySelector('.oc-result-add-selected').addEventListener('click', function () {
            var checked = box.querySelectorAll('.oc-result-check:checked');
            if (!checked.length) { return; }
            checked.forEach(function (cb) { addItemQuiet(Number(cb.dataset.pid), Number(cb.dataset.vid) || 0, cb.dataset.label, cb.dataset.price); });
            renderLines();
            recalc();
            box.hidden = true;
            $('#oc-product-search').value = '';
        });
        box.appendChild(bar);
    }

    function addResultRow(box, d) {
        var row = document.createElement('div');
        row.className = 'oc-result-row' + (d.hidden ? ' is-hidden' : '');
        var img = (SET.show_image_stock && d.image) ? '<img class="oc-thumb" src="' + d.image + '" alt="">' : '';
        var stock = (SET.show_image_stock && d.stock) ? '<small>' + d.stock + '</small>' : '';
        row.innerHTML = img + '<span>' + d.label + stock + '</span><b>' + money(d.price) + '</b>';
        var check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'oc-result-check';
        check.dataset.pid = d.pid;
        check.dataset.vid = d.vid || 0;
        check.dataset.label = d.label;
        check.dataset.price = d.price;
        check.addEventListener('click', function (e) { e.stopPropagation(); });
        row.insertBefore(check, row.firstChild);
        row.addEventListener('click', function (e) {
            if (e.target === check) { return; }
            addItem(d.pid, d.vid, d.label, d.price);
            box.hidden = true;
            $('#oc-product-search').value = '';
        });
        box.appendChild(row);
    }

    function addItemQuiet(pid, vid, name, price) {
        var existing = state.items.find(function (it) { return it.product_id === pid && it.variation_id === (vid || 0); });
        if (existing) { existing.qty += 1; }
        else { state.items.push({ product_id: pid, variation_id: vid || 0, name: name, unit_price: Number(price), qty: 1, manual_price: '', line_discount: 0 }); }
    }

    function addItem(pid, vid, name, price) {
        addItemQuiet(pid, vid, name, price);
        renderLines();
        recalc();
    }

    // ---------- lines table ----------
    function renderLines(snapshot) {
        renderManualLines(snapshot);
        aiRenderItems();
    }

    function renderManualLines(snapshot) {
        var body = $('#oc-lines-body');
        body.innerHTML = '';
        if (!state.items.length) {
            body.innerHTML = '<tr class="oc-empty"><td colspan="7">Chưa có sản phẩm.</td></tr>';
            return;
        }
        state.items.forEach(function (it, idx) {
            var line = snapshot ? (snapshot.lines || []).find(function (l) { return l.product_id === it.product_id && l.variation_id === (it.variation_id || 0); }) : null;
            var unitPrice = line && line.unit_price != null ? line.unit_price : it.unit_price;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + it.name + '</td>' +
                '<td class="oc-col-num">' + (Number.isFinite(Number(unitPrice)) ? money(unitPrice) : '—') + '</td>' +
                '<td class="oc-col-num"><input type="number" class="oc-mini" data-f="manual_price" data-i="' + idx + '" value="' + (it.manual_price === '' ? '' : it.manual_price) + '" placeholder="—"></td>' +
                '<td class="oc-col-num"><input type="number" class="oc-mini" data-f="line_discount" data-i="' + idx + '" value="' + (it.line_discount || '') + '" placeholder="0"></td>' +
                '<td class="oc-col-num"><input type="number" class="oc-mini" data-f="qty" data-i="' + idx + '" min="1" value="' + it.qty + '"></td>' +
                '<td class="oc-col-num">' + (line ? money(line.line_total) : '—') + '</td>' +
                '<td><button type="button" class="oc-x" data-rm="' + idx + '">&times;</button></td>';
            body.appendChild(tr);
        });
    }

    function onLineInput(e) {
        var el = e.target;
        if (el.dataset.rm != null) {
            state.items.splice(Number(el.dataset.rm), 1);
            renderLines(); recalc(); return;
        }
        if (el.dataset.f != null) {
            var i = Number(el.dataset.i), f = el.dataset.f;
            if (f === 'qty') state.items[i].qty = Math.max(1, Number(el.value) || 1);
            else if (f === 'manual_price') state.items[i].manual_price = el.value === '' ? '' : Number(el.value);
            else if (f === 'line_discount') state.items[i].line_discount = Number(el.value) || 0;
        }
    }

    // ---------- coupons / fees ----------
    function renderChips(listEl, arr, labelFn, removeFn) {
        listEl.innerHTML = '';
        arr.forEach(function (item, idx) {
            var li = document.createElement('li');
            li.className = 'oc-chip';
            li.innerHTML = '<span>' + labelFn(item) + '</span><button type="button">&times;</button>';
            li.querySelector('button').addEventListener('click', function () { removeFn(idx); });
            listEl.appendChild(li);
        });
    }

    function renderCoupons() {
        ['#oc-coupon-list', '#oc-ai-coupon-list'].forEach(function (sel) {
            var listEl = $(sel);
            if (listEl) { renderCouponList(listEl); }
        });
        renderCustomerCoupons();
    }

    function renderCouponList(listEl) {
        listEl.innerHTML = '';
        state.coupons.forEach(function (code, idx) {
            var st = state.couponStatus[code] || null;
            var li = document.createElement('li');
            li.className = 'oc-chip oc-coupon-chip';
            var mark = '';
            if (st) {
                li.classList.add(st.applied ? 'is-applied' : 'is-failed');
                mark = st.applied
                    ? '<span class="oc-coupon-mark oc-coupon-mark--ok" title="Áp dụng thành công">✓</span>'
                    : '<span class="oc-coupon-mark oc-coupon-mark--fail" title="Áp dụng thất bại">!</span>';
            }
            var detailLink = (st && st.detail)
                ? '<a href="#" class="oc-coupon-detail">Xem chi tiết</a>'
                : '';
            li.innerHTML = mark + '<span class="oc-coupon-code">' + code + '</span>' + detailLink +
                '<button type="button" class="oc-chip-x" aria-label="Gỡ mã">&times;</button>';
            li.querySelector('.oc-chip-x').addEventListener('click', function () {
                state.coupons.splice(idx, 1);
                delete state.couponStatus[code];
                delete state._couponNotified[code];
                renderCoupons();
                recalc();
            });
            var dl = li.querySelector('.oc-coupon-detail');
            if (dl) { dl.addEventListener('click', function (e) { e.preventDefault(); showCouponDetail(st.detail); }); }
            listEl.appendChild(li);
        });
    }

    // Cập nhật trạng thái mã từ kết quả tính lại; tự bật popup lỗi cho mã vừa thất bại.
    function applyCouponStatus(snap) {
        var list = (snap && snap.coupon_status) || [];
        var seen = {};
        list.forEach(function (s) {
            seen[s.code] = true;
            state.couponStatus[s.code] = s;
            if (!s.applied) {
                if (!state._couponNotified[s.code]) {
                    state._couponNotified[s.code] = true;
                    showCouponFail(s);
                }
            } else {
                delete state._couponNotified[s.code];
            }
        });
        // Mã không còn trong kết quả (vd: chưa có sản phẩm) → bỏ trạng thái cũ.
        Object.keys(state.couponStatus).forEach(function (code) {
            if (!seen[code]) { delete state.couponStatus[code]; }
        });
        renderCoupons();
    }

    function showCouponFail(s) {
        $('#oc-coupon-modal-title').textContent = 'Không áp dụng được mã "' + s.code + '"';
        var body = $('#oc-coupon-modal-body');
        var msg = s.message || 'Mã ưu đãi không áp dụng được cho đơn này.';
        var html = '<p class="oc-coupon-failmsg">' + escapeHtml(msg) + '</p>';
        if (s.detail) { html += couponDetailHtml(s.detail); }
        body.innerHTML = html;
        setCouponModalEdit(s.detail);
        openModal('#oc-coupon-modal');
    }

    function showCouponDetail(detail) {
        if (!detail) { return; }
        $('#oc-coupon-modal-title').textContent = 'Chi tiết mã "' + detail.code + '"';
        $('#oc-coupon-modal-body').innerHTML = couponDetailHtml(detail);
        setCouponModalEdit(detail);
        openModal('#oc-coupon-modal');
    }

    function statusRows(rows) {
        var html = '<dl class="oc-status-list">';
        rows.forEach(function (row) {
            html += '<div><dt>' + escapeHtml(row.label) + '</dt><dd>' + escapeHtml(row.value || '') + '</dd></div>';
        });
        html += '</dl>';
        return html;
    }

    function showCustomerConfirmModal(data, isError) {
        data = data || {};
        var title = $('#oc-customer-confirm-title');
        var body = $('#oc-customer-confirm-body');
        var edit = $('#oc-customer-confirm-edit');
        title.textContent = isError ? 'Không chuyển được trạng thái đơn' : 'Khách đã xác nhận';

        var rows = [];
        rows.push({ label: 'Đơn hàng', value: data.order_number ? '#' + data.order_number : (data.order_id ? '#' + data.order_id : '') });
        if (data.previous_status_label) { rows.push({ label: 'Trạng thái trước', value: data.previous_status_label }); }
        if (data.target_status_label) { rows.push({ label: 'Trạng thái cần chuyển', value: data.target_status_label }); }
        if (data.status_label) { rows.push({ label: 'Trạng thái hiện tại', value: data.status_label }); }
        if (data.confirmed_at) { rows.push({ label: 'Thời gian xác nhận', value: data.confirmed_at }); }

        body.innerHTML = '<p class="' + (isError ? 'oc-status-message oc-status-message--error' : 'oc-status-message oc-status-message--ok') + '">'
            + escapeHtml(data.message || (isError ? 'Không chuyển được trạng thái đơn.' : 'Đã cập nhật trạng thái đơn.'))
            + '</p>' + statusRows(rows)
            + (data.reason ? '<p class="oc-muted">' + escapeHtml(data.reason) + '</p>' : '');

        if (edit && data.edit_url) {
            edit.href = data.edit_url;
            edit.hidden = false;
        } else if (edit) {
            edit.hidden = true;
        }
        openModal('#oc-customer-confirm-modal');
    }

    function setCouponModalEdit(detail) {
        var edit = $('#oc-coupon-modal-edit');
        if (detail && detail.edit_url) {
            edit.href = detail.edit_url;
            edit.hidden = false;
        } else {
            edit.hidden = true;
        }
    }

    function couponDetailHtml(detail) {
        var html = '';
        if (detail.description) { html += '<p class="oc-coupon-desc">' + escapeHtml(detail.description) + '</p>'; }
        var rows = detail.rows || [];
        if (rows.length) {
            html += '<table class="oc-coupon-table"><tbody>';
            rows.forEach(function (r) {
                html += '<tr><th>' + escapeHtml(r.label) + '</th><td>' + escapeHtml(r.value) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        return html;
    }

    function hideCouponSuggestions() {
        var box = $('#oc-coupon-results');
        if (box) {
            box.hidden = true;
            box.innerHTML = '';
        }
        couponSuggestions = [];
        couponActiveIndex = -1;
    }

    function renderCouponSuggestions(items) {
        var box = $('#oc-coupon-results');
        if (!box) { return; }
        box.innerHTML = '';
        couponSuggestions = items || [];
        couponActiveIndex = couponSuggestions.length ? 0 : -1;
        if (!couponSuggestions.length) {
            box.innerHTML = '<div class="oc-result-empty">Không tìm thấy mã phù hợp.</div>';
            box.hidden = false;
            return;
        }
        couponSuggestions.forEach(function (coupon, idx) {
            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'oc-result-row oc-coupon-result' + (idx === couponActiveIndex ? ' is-selected' : '');
            row.innerHTML = '<span><strong>' + escapeHtml(coupon.code) + '</strong>' +
                (coupon.description ? '<small>' + escapeHtml(coupon.description) + '</small>' : '') +
                '</span><small>' + escapeHtml([coupon.type, coupon.expires ? 'HSD ' + coupon.expires : ''].filter(Boolean).join(' · ')) + '</small>';
            row.addEventListener('mousedown', function (e) {
                e.preventDefault();
                chooseCouponSuggestion(idx);
            });
            box.appendChild(row);
        });
        box.hidden = false;
    }

    function chooseCouponSuggestion(idx) {
        var coupon = couponSuggestions[idx];
        if (!coupon || !coupon.code) { return; }
        $('#oc-coupon-input').value = coupon.code;
        hideCouponSuggestions();
    }

    function moveCouponSuggestion(delta) {
        if (!couponSuggestions.length) { return; }
        couponActiveIndex = (couponActiveIndex + delta + couponSuggestions.length) % couponSuggestions.length;
        var rows = $('#oc-coupon-results').querySelectorAll('.oc-coupon-result');
        Array.prototype.forEach.call(rows, function (row, idx) {
            row.classList.toggle('is-selected', idx === couponActiveIndex);
        });
    }

    function onCouponSearch() {
        var term = $('#oc-coupon-input').value.trim();
        clearTimeout(couponSearchTimer);
        if (term.length < 2) {
            hideCouponSuggestions();
            return;
        }
        couponSearchTimer = setTimeout(function () {
            var seq = ++couponSearchSeq;
            post('order_creator_search_coupons', { term: term }).then(function (res) {
                if (seq !== couponSearchSeq) { return; }
                renderCouponSuggestions(res && res.success && res.data ? (res.data.coupons || []) : []);
            }).catch(hideCouponSuggestions);
        }, 220);
    }

    function onCouponKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            moveCouponSuggestion(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            moveCouponSuggestion(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (!$('#oc-coupon-results').hidden && couponActiveIndex >= 0) {
                chooseCouponSuggestion(couponActiveIndex);
                return;
            }
            addCoupon($('#oc-coupon-input').value);
            $('#oc-coupon-input').value = '';
            hideCouponSuggestions();
        } else if (e.key === 'Escape') {
            hideCouponSuggestions();
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function addCoupon(code) {
        code = (code || '').trim().toLowerCase();
        if (!code || state.coupons.indexOf(code) !== -1) return;
        state.coupons.push(code);
        delete state._couponNotified[code];
        renderCoupons();
        recalc();
    }

    // Đưa phí đang gõ dở (chưa bấm "Thêm") vào danh sách. Trả về true nếu có thêm.
    function flushPendingFee() {
        var amount = Number($('#oc-fee-amount').value);
        if (!amount) { return false; }
        state.fees.push({ name: $('#oc-fee-name').value.trim(), amount: amount });
        $('#oc-fee-name').value = ''; $('#oc-fee-amount').value = '';
        renderFees();
        return true;
    }

    function addFee() {
        if (flushPendingFee()) { recalc(); }
    }

    function renderFees() {
        renderChips($('#oc-fee-list'), state.fees,
            function (f) { return (f.name || 'Điều chỉnh') + ': ' + money(f.amount); },
            function (i) { state.fees.splice(i, 1); renderFees(); recalc(); });
    }

    // ---------- customer ----------
    var custTimer;
    function onCustomerSearch() {
        clearTimeout(custTimer);
        var term = $('#oc-customer-search').value.trim();
        var box = $('#oc-customer-results');
        if (term.length < 2) { box.hidden = true; return; }
        custTimer = setTimeout(function () {
            post('order_creator_search_customers', { term: term }).then(function (res) {
                if (!res.success) return;
                renderCustomerResults(res.data.customers || []);
            });
        }, 300);
    }

    function renderCustomerResults(customers) {
        var box = $('#oc-customer-results');
        box.innerHTML = '';
        if (!customers.length) { box.innerHTML = '<div class="oc-result-empty">Không tìm thấy.</div>'; box.hidden = false; return; }
        customers.forEach(function (c) {
            var row = document.createElement('div');
            row.className = 'oc-result-row';
            row.innerHTML = '<span>' + c.name + '<small>' + (c.phone || c.email || '') + '</small></span>';
            row.addEventListener('click', function () { selectCustomer(c); box.hidden = true; });
            box.appendChild(row);
        });
        box.hidden = false;
    }

    function selectCustomer(c, keepShip) {
        state.customer = c;
        $('#oc-customer-search').value = c.name;
        var card = $('#oc-customer-card');
        card.hidden = false;
        card.innerHTML =
            '<div><b>' + c.name + '</b> (#' + c.id + ')</div>' +
            '<div class="oc-muted">' + (c.phone || '') + ' · ' + (c.email || '') + '</div>' +
            (c.roles && c.roles.length ? '<div class="oc-muted">Role: ' + c.roles.join(', ') + '</div>' : '');
        renderCustomerInfo(c);
        var editBtn = $('#oc-customer-edit'); if (editBtn) { editBtn.hidden = false; }
        $('#oc-customer-history').hidden = false;
        $('#oc-customer-products').hidden = false;
        if (!keepShip) {
            applyAddresses(c.billing || {}, c.shipping || {});
            applyCustomerPaymentDefault(c);
        }
        updateSaveAddressAction();
        recalc();
    }

    function updateSaveAddressAction() {
        var checkbox = $('#oc-save-customer-addresses');
        var button = $('#oc-save-customer-addresses-confirm');
        var status = $('#oc-save-customer-addresses-status');
        if (!checkbox || !button) { return; }
        button.hidden = !checkbox.checked;
        button.disabled = !state.customer;
        if (!checkbox.checked && status) { status.textContent = ''; }
    }

    function saveCustomerAddresses() {
        if (!state.customer || !state.customer.id) {
            $('#oc-save-customer-addresses-status').textContent = 'Chọn khách hàng trước khi lưu địa chỉ.';
            return;
        }
        var button = $('#oc-save-customer-addresses-confirm');
        var status = $('#oc-save-customer-addresses-status');
        button.disabled = true;
        status.textContent = 'Đang lưu địa chỉ...';
        post('order_creator_save_customer_addresses', {
            payload: JSON.stringify({
                customer_id: state.customer.id,
                save_customer_addresses: true,
                billing: collectBilling(),
                shipping: collectShipping()
            })
        }).then(function (res) {
            if (!res.success) {
                status.textContent = '❌ ' + ((res.data && res.data.message) || 'Không thể lưu địa chỉ.');
                button.disabled = false;
                return;
            }
            $('#oc-save-customer-addresses').checked = false;
            state.customer = res.data.customer;
            selectCustomer(res.data.customer, true);
            status.textContent = '✓ Đã lưu làm địa chỉ mặc định của khách.';
        }).catch(function () {
            status.textContent = '❌ Không thể lưu địa chỉ.';
            button.disabled = false;
        });
    }

    function loadCustomerHistory(mode) {
        if (!state.customer) { return; }
        $('#oc-history-title').textContent = mode === 'products' ? 'Sản phẩm đã đặt' : 'Lịch sử đặt hàng';
        $('#oc-history-content').textContent = 'Đang tải...';
        openModal('#oc-history-modal');
        post('order_creator_customer_history', { customer_id: state.customer.id }).then(function (res) {
            if (!res.success) { $('#oc-history-content').textContent = 'Không tải được dữ liệu.'; return; }
            var data = res.data;
            if (mode === 'products') {
                var html = '<div class="oc-history-products">';
                (data.products || []).forEach(function (p, i) {
                    html += '<label><input type="checkbox" data-product="' + i + '"> ' + p.name + ' <small>×' + p.qty + '</small></label>';
                });
                html += '</div><button type="button" class="oc-btn oc-btn--primary" id="oc-history-add">Thêm vào đơn</button>';
                $('#oc-history-content').innerHTML = html || 'Chưa có sản phẩm.';
                $('#oc-history-add').addEventListener('click', function () {
                    $('#oc-history-content').querySelectorAll('input:checked').forEach(function (el) {
                        var p = data.products[Number(el.dataset.product)];
                        addItem(p.product_id, p.variation_id, p.name);
                    });
                    closeModals();
                });
                return;
            }
            var orders = data.orders || [];
            if (!orders.length) { $('#oc-history-content').textContent = 'Chưa có đơn hàng.'; return; }
            var list = document.createElement('div');
            list.className = 'oc-history-list';
            orders.forEach(function (o) {
                var article = document.createElement('article');

                var meta = document.createElement('div');
                meta.className = 'oc-history-meta';
                var number = document.createElement('b');
                number.textContent = '#' + (o.number || o.id || '');
                meta.appendChild(number);
                meta.appendChild(document.createTextNode(' · ' + (o.date || '') + ' · ' + money(o.total || 0)));
                article.appendChild(meta);

                var address = document.createElement('small');
                address.className = 'oc-history-address';
                address.textContent = (o.address_lines && o.address_lines.length) ? o.address_lines.join('\n') : (o.address || '');
                article.appendChild(address);

                var items = document.createElement('div');
                items.className = 'oc-history-items';
                items.textContent = (o.items || []).map(function (i) { return (i.name || '') + ' ×' + i.qty; }).join('\n');
                article.appendChild(items);

                var actions = document.createElement('div');
                actions.className = 'oc-history-actions';
                var editInline = document.createElement('button');
                editInline.type = 'button';
                editInline.className = 'oc-btn oc-btn--primary';
                editInline.textContent = 'Chỉnh trên trang tạo đơn';
                editInline.addEventListener('click', function () {
                    if (o.id) { closeModals(); loadOrder(o.id); }
                });
                var editAdmin = document.createElement('a');
                editAdmin.className = 'oc-btn oc-btn--ghost';
                editAdmin.textContent = 'Xem đơn';
                editAdmin.href = o.edit_url || '#';
                editAdmin.target = '_blank';
                editAdmin.rel = 'noopener';
                actions.appendChild(editInline);
                actions.appendChild(editAdmin);
                article.appendChild(actions);

                list.appendChild(article);
            });
            $('#oc-history-content').replaceChildren(list);
        });
    }

    function renderCustomerInfo(customer) {
        var box = $('#oc-customer-info');
        var defs = CFG.customerInfoFieldDefs || {};
        var selected = SET.customer_info_fields || [];
        var values = customer.customer_info || {};
        box.innerHTML = '';
        selected.forEach(function (key) {
            if (!defs[key]) { return; }
            var value = values[key] || '';
            if (key.indexOf('spending_') === 0) { value = money(value || 0); }
            var row = document.createElement('div');
            row.className = 'oc-customer-info__row';
            var label = document.createElement('b'); label.textContent = defs[key].label + ': ';
            row.appendChild(label);
            if ((key === 'chat_link' || key === 'facebook') && value && /^https?:\/\//i.test(value)) {
                var link = document.createElement('a');
                link.className = 'oc-btn oc-btn--ghost oc-customer-info__link';
                link.href = value;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = 'Xem tại đây';
                row.appendChild(link);
            } else {
                var text = document.createElement('span'); text.textContent = value || '—';
                row.appendChild(text);
            }
            box.appendChild(row);
        });
        box.hidden = !box.children.length;
    }

    // ---------- order search / copy ----------
    var orderTimer;
    function onOrderSearch() {
        clearTimeout(orderTimer);
        var term = $('#oc-order-search').value.trim();
        var box = $('#oc-order-results');
        if (term.length < 2) { box.hidden = true; return; }
        orderTimer = setTimeout(function () {
            post('order_creator_search_orders', { term: term }).then(function (res) {
                if (!res.success) { return; }
                renderOrderResults(res.data.orders || []);
            });
        }, 300);
    }

    function renderOrderResults(orders) {
        var box = $('#oc-order-results');
        box.innerHTML = '';
        if (!orders.length) { box.innerHTML = '<div class="oc-result-empty">Không tìm thấy đơn.</div>'; box.hidden = false; return; }
        orders.forEach(function (order) {
            var row = document.createElement('div');
            row.className = 'oc-result-row oc-order-result';
            var showConfirm = order.payment_method === 'cod' && ['on-hold', 'processing'].indexOf(order.status_slug) !== -1;
            row.innerHTML =
                '<span><b>#' + order.number + '</b> — ' + order.customer +
                '<small>' + (order.phone || '') + ' · ' + order.date + ' · ' + order.status + '</small></span>' +
                '<div class="oc-order-result__actions">' +
                '<button type="button" class="oc-btn oc-btn--ghost" data-act="view">Xem đơn</button>' +
                '<button type="button" class="oc-btn oc-btn--ghost" data-act="invoice">Xem hóa đơn</button>' +
                '<button type="button" class="oc-btn oc-btn--ghost" data-act="edit">Chỉnh sửa</button>' +
                '<button type="button" class="oc-btn oc-btn--ghost" data-act="copy">Copy</button>' +
                (showConfirm ? '<button type="button" class="oc-btn oc-btn--primary" data-act="confirm">Khách đã xác nhận</button>' : '') +
                '<button type="button" class="oc-btn oc-btn--primary" data-act="pay">Xác nhận thanh toán</button>' +
                '</div>';
            row.querySelector('[data-act="view"]').addEventListener('click', function () { if (order.edit_url) { window.open(order.edit_url, '_blank', 'noopener'); } });
            row.querySelector('[data-act="invoice"]').addEventListener('click', function () { if (order.invoice_url) { openInvoice(order.invoice_url); box.hidden = true; } });
            row.querySelector('[data-act="edit"]').addEventListener('click', function () { loadOrder(order.id); box.hidden = true; });
            row.querySelector('[data-act="copy"]').addEventListener('click', function () { loadOrder(order.id, true); box.hidden = true; });
            if (showConfirm) {
                row.querySelector('[data-act="confirm"]').addEventListener('click', function (e) {
                    confirmCustomerForSearchRow(order, e.currentTarget);
                });
            }
            row.querySelector('[data-act="pay"]').addEventListener('click', function () {
                openPayModal({
                    order_id: order.id,
                    order_number: order.number,
                    phone: order.phone,
                    total: order.total,
                    payment_total: order.payment_total,
                    edit_url: order.edit_url
                });
                box.hidden = true;
            });
            box.appendChild(row);
        });
        box.hidden = false;
    }

    // ---------- load existing order (chỉnh sửa) ----------
    function loadOrder(orderId, isCopy, readOnly) {
        return post('order_creator_load_order', { order_id: orderId }).then(function (res) {
            if (!res.success) { alert((res.data && res.data.message) || 'Không nạp được đơn.'); return; }
            var o = res.data;
            if (readOnly) {
                state.editOrderId = 0;
                state.lastOrder = {
                    order_id: o.order_id,
                    order_number: o.order_number,
                    total: o.total,
                    edit_url: o.edit_url,
                    invoice_url: o.invoice_url,
                    payment_total: o.payment_total,
                    phone: o.phone
                };
                setEditorDisabled(true);
                renderResult(state.lastOrder, false, '↩️ Đã hủy chỉnh sửa đơn ');
                return;
            }
            state.editOrderId = isCopy ? 0 : o.order_id;
            setEditorDisabled(false);
            state.aiPending = [];
            state.aiMatches = [];
            state.aiUnmatched = {};
            state.aiGuest = null;
            state.aiSource = o.ai_source || '';
            state.aiCreateCustomer = !!isCopy; // sửa đơn khách lẻ: không tự tạo tài khoản
            state.items = (o.items || []).map(function (it) {
                return { product_id: it.product_id, variation_id: it.variation_id || 0, name: it.name, qty: it.qty, manual_price: '', line_discount: 0 };
            });
            state.coupons = (o.coupons || []).slice();
            state.couponStatus = {};
            state._couponNotified = {};
            state.fees = (o.fees || []).slice();
            state.shipping_method = o.shipping_method || '';
            $('#oc-shipping-cost').value = o.shipping_cost || '';
            var title = o.shipping_title || '';
            var choice = $('#oc-shipping-title-choice');
            choice.value = Array.from(choice.options).some(function (option) { return option.value === title; }) ? title : '';
            $('#oc-shipping-title').value = choice.value ? '' : title;
            syncShippingTitleField();

            // fields đơn
            if (o.status && !isCopy) $('#oc-status').value = o.status;
            if (o.payment_method) $('#oc-payment').value = o.payment_method;
            if (o.order_date && !isCopy) $('#oc-order-date').value = o.order_date;
            $('#oc-customer-note').value = o.customer_note || '';
            $('#oc-save-customer-addresses').checked = false;
            updateSaveAddressAction();
            fillOrderMeta(o.order_meta);

            // địa chỉ đặt hàng + giao hàng CỦA ĐƠN (không lấy từ khách)
            applyAddresses(o.billing || {}, o.shipping || {});

            if (o.customer) { selectCustomer(o.customer, true); }

            renderCoupons(); renderFees(); renderLines();
            if (isCopy) {
                $('#oc-status').value = 'on-hold';
                $('#oc-order-date').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                markCopyMode(o.order_number);
            } else {
                markEditMode(o.order_number);
            }
            aiRenderReview();
            doRecalc();
        });
    }

    // Chế độ thanh nút: 'create' | 'edit' | 'result' — quyết định nút nào hiện + thứ tự.
    function setActionMode(mode) {
        var bar = $('#oc-create-actions');
        if (!bar) { return; }
        bar.classList.remove('mode-create', 'mode-edit', 'mode-result');
        bar.classList.add('mode-' + mode);
    }

    function markEditMode(orderNumber) {
        document.querySelector('.oc-topbar h1').textContent = 'Chỉnh sửa đơn #' + orderNumber;
        $('#oc-create').textContent = 'Cập nhật';
        setActionMode('edit');
        document.title = 'Chỉnh sửa đơn #' + orderNumber;
    }

    function markCopyMode(orderNumber) {
        document.querySelector('.oc-topbar h1').textContent = 'Tạo bản sao từ đơn #' + orderNumber;
        $('#oc-create').textContent = 'Tạo đơn';
        setActionMode('create');
        document.title = 'Tạo bản sao đơn #' + orderNumber;
    }

    function setEditorDisabled(disabled) {
        document.querySelectorAll('#oc-pane-create .oc-grid input, #oc-pane-create .oc-grid select, #oc-pane-create .oc-grid textarea, #oc-pane-create .oc-grid button, #oc-ai-order input, #oc-ai-order select, #oc-ai-order textarea, #oc-ai-order button').forEach(function (el) {
            if (['oc-customer-edit', 'oc-customer-history', 'oc-customer-products'].indexOf(el.id) !== -1) { return; }
            el.disabled = disabled;
        });
    }

    function cancelEdit() {
        if (!state.editOrderId || !window.confirm('Hủy chỉnh sửa và bỏ các thay đổi chưa lưu?')) { return; }
        var btn = $('#oc-cancel-edit');
        btn.disabled = true;
        btn.textContent = 'Đang hủy...';
        loadOrder(state.editOrderId, false, true).then(function () {
            btn.disabled = false;
            btn.textContent = 'Hủy chỉnh sửa';
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Hủy chỉnh sửa';
        });
    }

    function clearCart() {
        if (!state.items.length && !state.coupons.length && !state.fees.length && !$('#oc-shipping-cost').value) { return; }
        if (!window.confirm('Xóa toàn bộ sản phẩm, mã ưu đãi, phí và phí ship điều chỉnh?')) { return; }
        state.items = [];
        state.coupons = [];
        state.couponStatus = {};
        state._couponNotified = {};
        state.fees = [];
        state.shipping_method = '';
        $('#oc-shipping-cost').value = '';
        $('#oc-shipping-title-choice').value = '';
        $('#oc-shipping-title').value = '';
        syncShippingTitleField();
        $('#oc-shipping-method').innerHTML = '<option value="">— Tính lại để xem —</option>';
        renderCoupons();
        renderFees();
        renderLines();
        updateTotals(null);
    }

    function startNewOrder() {
        state.editOrderId = 0;
        state.customer = null;
        state.items = [];
        state.coupons = [];
        state.couponStatus = {};
        state._couponNotified = {};
        state.fees = [];
        state.shipping_method = '';
        state.lastOrder = null;
        state.lastSnap = null;
        state.customerCoupons = [];
        setEditorDisabled(false);

        document.querySelectorAll('#oc-pane-create .oc-grid input, #oc-pane-create .oc-grid select, #oc-pane-create .oc-grid textarea').forEach(function (el) {
            if (el.type === 'checkbox') { el.checked = false; }
            else if (el.tagName === 'SELECT') { el.selectedIndex = 0; }
            else { el.value = ''; }
        });
        $('#oc-suppress-email').checked = true;
        $('#oc-status').value = 'on-hold';
        $('#oc-order-date').value = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        $('#oc-shipping-method').innerHTML = '<option value="">— Tính lại để xem —</option>';
        syncShippingTitleField();
        $('#oc-customer-card').hidden = true;
        $('#oc-customer-card').innerHTML = '';
        $('#oc-customer-edit').hidden = true;
        $('#oc-result').hidden = true;
        updateSaveAddressAction();
        document.querySelector('.oc-topbar h1').textContent = 'Tạo đơn hàng';
        document.title = 'Tạo đơn hàng';
        $('#oc-create').textContent = 'Tạo đơn';
        setActionMode('create');
        renderCoupons();
        renderFees();
        renderLines();
        updateTotals(null);
        aiReset();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    var CUST_FIELDS = ['first_name', 'last_name', 'email', 'username', 'phone', 'address_1', 'address_2', 'city', 'state']
        .concat(Object.keys(CFG.customerCustomFieldDefs || {}));

    function populateRoles() {
        var sel = $('#oc-cust-role');
        if (sel.options.length) { return; }
        (CFG.roles || []).forEach(function (r) {
            var o = document.createElement('option'); o.value = r.value; o.textContent = r.label; sel.appendChild(o);
        });
        sel.value = 'customer';
    }

    function applyCustomerFieldVisibility() {
        var allowed = SET.customer_fields || [];
        document.querySelectorAll('#oc-customer-modal .oc-cust-field').forEach(function (el) {
            var key = el.getAttribute('data-cust');
            if (key === 'load_order') { return; }
            el.style.display = (allowed.indexOf(key) !== -1) ? '' : 'none';
        });
    }

    // ---------- Địa chỉ hành chính VN mới: state = tỉnh/thành (mã), city = phường/xã (tên) ----------
    var vnWardsCache = {};

    function vnLoadWards(matp) {
        if (!matp) { return Promise.resolve([]); }
        if (vnWardsCache[matp]) { return Promise.resolve(vnWardsCache[matp]); }
        return post('order_creator_vn_wards', { matp: matp }).then(function (res) {
            var wards = (res.success && res.data && res.data.wards) || [];
            vnWardsCache[matp] = wards;
            return wards;
        });
    }

    function vnFillWardSelect(sel, wards, selected) {
        if (!sel) { return; }
        sel.innerHTML = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = wards.length ? '— Chọn Phường/Xã —' : '— Chọn Tỉnh/Thành trước —';
        sel.appendChild(empty);
        wards.forEach(function (w) {
            var o = document.createElement('option');
            o.value = w.code; o.textContent = w.name; // value = MÃ phường/xã, hiển thị tên
            sel.appendChild(o);
        });
        if (selected) {
            sel.value = selected;
            if (sel.value !== selected) {
                // Dữ liệu cũ có thể lưu TÊN phường/xã → map sang mã theo tên.
                var byName = '';
                Array.prototype.forEach.call(sel.options, function (o) {
                    if (!byName && o.value && o.textContent === selected) { byName = o.value; }
                });
                if (byName) {
                    sel.value = byName;
                } else { // không còn trong danh mục → giữ lại, không làm mất dữ liệu
                    var legacy = document.createElement('option');
                    legacy.value = selected; legacy.textContent = selected + ' (cũ)';
                    sel.appendChild(legacy);
                    sel.value = selected;
                }
            }
        }
        vnComboSync(sel);
    }

    function vnSetAddress(stateSel, citySel, state, city) {
        if (!stateSel || !citySel) { return Promise.resolve(); }
        stateSel.value = state || '';
        if ((state || '') !== '' && stateSel.value !== state) { // mã/tên tỉnh cũ không còn trong danh mục
            var legacy = document.createElement('option');
            legacy.value = state; legacy.textContent = state + ' (cũ)';
            stateSel.appendChild(legacy);
            stateSel.value = state;
        }
        vnComboSync(stateSel);
        return vnLoadWards(stateSel.value).then(function (wards) {
            vnFillWardSelect(citySel, wards, city || '');
        });
    }

    function vnBindStateCascade(stateSel, citySel) {
        if (!stateSel || !citySel) { return; }
        stateSel.addEventListener('change', function () {
            vnLoadWards(this.value).then(function (wards) { vnFillWardSelect(citySel, wards, ''); });
        });
    }

    // ----- Combobox tìm kiếm cho select địa chỉ: gõ để lọc (không dấu), chọn xong
    // ghi vào select gốc + bắn 'change' nên cascade/recalc chạy như chọn tay. -----
    function vnComboSync(sel) {
        if (sel && sel._vnComboSync) { sel._vnComboSync(); }
    }

    function vnStrip(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\u0111/g, 'd');
    }

    function vnMakeSearchable(sel, placeholder) {
        if (!sel || sel._vnComboSync) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'oc-combo';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'oc-combo__input';
        input.placeholder = placeholder || '';
        input.autocomplete = 'off';
        var list = document.createElement('div');
        list.className = 'oc-combo__list';
        list.hidden = true;
        wrap.appendChild(input);
        wrap.appendChild(list);

        function syncFromSelect() {
            var o = sel.options[sel.selectedIndex];
            input.value = (sel.value && o) ? o.textContent : '';
        }
        function pick(value) {
            sel.value = value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            list.hidden = true;
            syncFromSelect();
        }
        function render(filter) {
            list.innerHTML = '';
            var q = vnStrip(filter);
            var shown = 0;
            Array.prototype.forEach.call(sel.options, function (o) {
                if (!o.value) { return; }
                if (q && vnStrip(o.textContent).indexOf(q) === -1) { return; }
                var item = document.createElement('div');
                item.className = 'oc-combo__item' + (o.value === sel.value ? ' is-selected' : '');
                item.textContent = o.textContent;
                item.dataset.value = o.value;
                list.appendChild(item);
                shown++;
            });
            if (!shown) {
                var empty = document.createElement('div');
                empty.className = 'oc-combo__empty';
                empty.textContent = 'Không có kết quả';
                list.appendChild(empty);
            }
        }
        input.addEventListener('focus', function () { render(''); list.hidden = false; input.select(); });
        input.addEventListener('input', function () { render(input.value); list.hidden = false; });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var first = list.querySelector('.oc-combo__item');
                if (!list.hidden && first) { pick(first.dataset.value); }
                e.preventDefault();
            } else if (e.key === 'Escape') {
                list.hidden = true;
                syncFromSelect();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { list.hidden = true; syncFromSelect(); }, 150);
        });
        list.addEventListener('mousedown', function (e) {
            var item = e.target.closest('.oc-combo__item');
            if (item) { e.preventDefault(); pick(item.dataset.value); }
        });
        sel._vnComboSync = syncFromSelect;
        syncFromSelect();
    }

    function custSetAddress(state, city) {
        return vnSetAddress($('#oc-cust-state'), $('#oc-cust-city'), state, city);
    }

    function openCustomerModal(mode) {
        customerModalEditId = 0;
        $('#oc-new-result').textContent = '';
        aiResetPanel();
        populateRoles();
        applyCustomerFieldVisibility();
        CUST_FIELDS.forEach(function (f) { $('#oc-cust-' + f).value = ''; });
        vnFillWardSelect($('#oc-cust-city'), [], '');
        vnComboSync($('#oc-cust-state'));
        $('#oc-cust-username').disabled = false;
        $('#oc-cust-copy-shipping').checked = true;
        if (mode === 'edit' && state.customer) {
            customerModalEditId = state.customer.id;
            $('#oc-cust-modal-title').textContent = 'Chỉnh thông tin khách: ' + state.customer.name;
            var b = state.customer.billing || {};
            var s = state.customer.shipping || {};
            $('#oc-cust-first_name').value = b.first_name || '';
            $('#oc-cust-last_name').value = b.last_name || '';
            $('#oc-cust-email').value = b.email || state.customer.email || '';
            $('#oc-cust-username').value = state.customer.username || '';
            $('#oc-cust-username').disabled = true;
            $('#oc-cust-phone').value = b.phone || state.customer.phone || '';
            $('#oc-cust-address_1').value = b.address_1 || '';
            $('#oc-cust-address_2').value = b.address_2 || '';
            custSetAddress(b.state || '', b.city || '');
            $('#oc-cust-copy-shipping').checked = !s.address_1 || addrEqual(b, s);
            $('#oc-cust-role').value = (state.customer.roles && state.customer.roles[0]) || 'customer';
            var custom = state.customer.custom_fields || {};
            Object.keys(CFG.customerCustomFieldDefs || {}).forEach(function (key) {
                $('#oc-cust-' + key).value = custom[key] || '';
            });
            $('#oc-new-save').textContent = 'Lưu thay đổi';
        } else {
            $('#oc-cust-modal-title').textContent = 'Khách hàng mới';
            $('#oc-cust-role').value = 'customer';
            $('#oc-new-save').textContent = 'Lưu khách hàng';
        }
        openModal('#oc-customer-modal');
    }

    function loadCustomerFromOrder() {
        var oid = $('#oc-cust-load-order').value.trim();
        if (!oid) { return; }
        post('order_creator_load_order', { order_id: oid }).then(function (res) {
            if (!res.success) { $('#oc-new-result').textContent = '❌ ' + ((res.data && res.data.message) || 'Không nạp được đơn.'); return; }
            var b = res.data.billing || {};
            $('#oc-cust-first_name').value = b.first_name || '';
            $('#oc-cust-last_name').value = b.last_name || '';
            $('#oc-cust-email').value = b.email || '';
            $('#oc-cust-phone').value = b.phone || '';
            $('#oc-cust-address_1').value = b.address_1 || '';
            $('#oc-cust-address_2').value = b.address_2 || '';
            custSetAddress(b.state || '', b.city || '');
        });
    }

    function createCustomer() {
        var payload = { user_id: customerModalEditId || 0, copy_to_shipping: $('#oc-cust-copy-shipping').checked ? 1 : 0, role: $('#oc-cust-role').value };
        CUST_FIELDS.forEach(function (f) { payload[f] = $('#oc-cust-' + f).value.trim(); });
        var btn = $('#oc-new-save'); btn.disabled = true;
        post('order_creator_create_customer', { payload: JSON.stringify(payload) }).then(function (res) {
            btn.disabled = false;
            if (!res.success) { $('#oc-new-result').textContent = '❌ ' + ((res.data && res.data.message) || 'Lỗi tạo khách.'); return; }
            selectCustomer(res.data.customer, !!customerModalEditId);
            $('#oc-new-result').textContent = '';
            closeModals();
        }).catch(function (err) {
            btn.disabled = false;
            $('#oc-new-result').textContent = '❌ Không thể lưu khách hàng' + (err && err.message ? ': ' + err.message : '.');
        });
    }

    // ---------- AI bóc tách khách hàng ----------
    var aiCustImage = null;

    function aiSetImage(file) {
        if (!file) { return; }
        if (file.size > 5 * 1024 * 1024) { $('#oc-cust-ai-status').textContent = '❌ Ảnh vượt quá 5MB.'; return; }
        aiCustImage = file;
        var img = $('#oc-cust-ai-preview-img');
        if (img.src) { URL.revokeObjectURL(img.src); }
        img.src = URL.createObjectURL(file);
        $('#oc-cust-ai-preview').hidden = false;
        $('#oc-cust-ai-status').textContent = '';
    }

    function aiClearImage() {
        aiCustImage = null;
        var img = $('#oc-cust-ai-preview-img');
        if (img && img.src) { URL.revokeObjectURL(img.src); img.removeAttribute('src'); }
        $('#oc-cust-ai-preview').hidden = true;
        $('#oc-cust-ai-image').value = '';
    }

    function aiResetPanel() {
        var panel = $('#oc-cust-ai-panel');
        if (!panel) { return; }
        panel.hidden = true;
        $('#oc-cust-ai-toggle').setAttribute('aria-expanded', 'false');
        $('#oc-cust-ai-text').value = '';
        $('#oc-cust-ai-convert-address').checked = true;
        $('#oc-cust-ai-status').textContent = '';
        aiClearImage();
    }

    function aiExtractCustomer() {
        var text = $('#oc-cust-ai-text').value.trim();
        if (!text && !aiCustImage) { $('#oc-cust-ai-status').textContent = 'Dán dữ liệu hoặc chọn ảnh trước.'; return; }
        var btn = $('#oc-cust-ai-run');
        var convertInput = $('#oc-cust-ai-convert-address');
        var convertOldAddress = convertInput.checked;
        btn.disabled = true;
        convertInput.disabled = true;
        $('#oc-cust-ai-status').textContent = convertOldAddress
            ? '⏳ Đang bóc tách và chuyển địa chỉ sang định dạng 2026...'
            : '⏳ Đang bóc tách bằng AI...';
        var params = { text: text, convert_old_address: convertOldAddress ? 1 : 0 };
        if (aiCustImage) { params.image = aiCustImage; }
        post('order_creator_ai_extract_customer', params).then(function (res) {
            btn.disabled = false;
            convertInput.disabled = false;
            if (!res.success) { $('#oc-cust-ai-status').textContent = '❌ ' + ((res.data && res.data.message) || 'Bóc tách thất bại.'); return; }
            var f = (res.data && res.data.fields) || {};
            var un = (res.data && res.data.unmatched) || {};
            var webSearchEnabled = !!(res.data && res.data.web_search_enabled);
            var filled = 0;
            ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2'].forEach(function (k) {
                var el = $('#oc-cust-' + k);
                if (el && f[k]) { el.value = f[k]; filled++; }
            });
            if (f.state) { filled++; }
            if (f.city) { filled++; }
            custSetAddress(f.state || '', f.city || '').then(function () {
                var msg = filled
                    ? '✅ Đã điền ' + filled + ' trường' + (convertOldAddress ? ' theo địa chỉ hành chính 2026' : '') + (webSearchEnabled ? ' (Google Search đã bật)' : '') + ' — kiểm tra lại trước khi lưu.'
                    : 'Không tìm thấy thông tin khách trong dữ liệu.';
                var warn = [];
                if (un.state) { warn.push('Tỉnh/Thành "' + un.state + '"'); }
                if (un.city) { warn.push('Phường/Xã "' + un.city + '"'); }
                if (warn.length) { msg += ' ⚠️ Không khớp danh mục: ' + warn.join(', ') + ' — vui lòng chọn tay.'; }
                $('#oc-cust-ai-status').textContent = msg;
            });
        }).catch(function () {
            btn.disabled = false;
            convertInput.disabled = false;
            $('#oc-cust-ai-status').textContent = '❌ Không gọi được AI, thử lại sau.';
        });
    }

    // ---------- mã ưu đãi của khách (Lucky Wheel / gắn email / gắn SĐT) ----------
    var custCouponSeq = 0;

    function refreshCustomerCoupons() {
        var payload = buildPayload();
        var seq = ++custCouponSeq;
        if (!payload.customer_id && !payload.billing.phone && !payload.billing.email) {
            state.customerCoupons = [];
            renderCustomerCoupons();
            return;
        }
        post('order_creator_customer_coupons', { payload: JSON.stringify(payload) }).then(function (res) {
            if (seq !== custCouponSeq || !res.success) { return; }
            state.customerCoupons = res.data.coupons || [];
            renderCustomerCoupons();
        }).catch(function () { /* gợi ý phụ, bỏ qua lỗi mạng */ });
    }

    function customerCouponStatus(c) {
        if (state.coupons.indexOf(c.code) !== -1) {
            var st = state.couponStatus[c.code];
            if (!st) { return { cls: '', text: 'Đã thêm vào đơn' }; }
            return st.applied ? { cls: 'is-ok', text: '✓ Đang áp dụng' } : { cls: 'is-fail', text: '! ' + (st.message || 'Không áp dụng được') };
        }
        if (c.applicable === true) { return { cls: 'is-ok', text: 'Áp dụng được cho giỏ hiện tại' }; }
        if (c.applicable === false) { return { cls: 'is-fail', text: c.message || 'Không áp dụng được cho giỏ hiện tại' }; }
        return { cls: '', text: 'Thêm sản phẩm để kiểm tra điều kiện' };
    }

    function renderCustomerCoupons() {
        var list = state.customerCoupons || [];
        ['#oc-customer-coupons', '#oc-ai-customer-coupons'].forEach(function (sel) {
            var box = $(sel);
            if (!box) { return; }
            box.hidden = !list.length;
            box.innerHTML = '';
            if (!list.length) { return; }
            var head = document.createElement('div');
            head.className = 'oc-customer-coupons__head';
            head.textContent = '🎟️ Mã ưu đãi của khách (' + list.length + ')';
            box.appendChild(head);
            list.forEach(function (c) {
                var inOrder = state.coupons.indexOf(c.code) !== -1;
                var st = customerCouponStatus(c);
                var row = document.createElement('div');
                row.className = 'oc-cc-row ' + st.cls;
                var meta = [c.value, c.expires ? 'HSD ' + c.expires : ''].filter(Boolean).join(' · ');
                row.innerHTML =
                    '<div class="oc-cc-main">' +
                        '<div><b class="oc-cc-code">' + escapeHtml(c.code) + '</b> <span class="oc-cc-src">' + escapeHtml(c.source) + '</span></div>' +
                        (meta ? '<small>' + escapeHtml(meta) + '</small>' : '') +
                        (c.description ? '<small class="oc-muted">' + escapeHtml(c.description) + '</small>' : '') +
                        '<small class="oc-cc-status">' + escapeHtml(st.text) + '</small>' +
                    '</div>' +
                    (inOrder ? '' : '<button type="button" class="oc-btn oc-btn--ghost oc-btn--sm">' + (c.applicable === false ? 'Thử áp dụng' : 'Áp dụng') + '</button>');
                var btn = row.querySelector('button');
                if (btn) { btn.addEventListener('click', function () { addCoupon(c.code); }); }
                box.appendChild(row);
            });
        });
    }

    // ---------- chế độ AI tạo đơn ----------
    var aiImages = [];
    var aiTextLabel = '';

    function storedMode() {
        try { return window.localStorage.getItem('oc_create_mode') === 'ai' ? 'ai' : 'manual'; } catch (e) { return 'manual'; }
    }

    function currentMode() { return document.body.classList.contains('oc-mode-ai') ? 'ai' : 'manual'; }

    function setMode(mode) {
        if (!$('#oc-ai-order')) { return; }
        mode = mode === 'ai' ? 'ai' : 'manual';
        document.body.classList.toggle('oc-mode-ai', mode === 'ai');
        $('#oc-ai-order').hidden = mode !== 'ai';
        document.querySelectorAll('.oc-mode-btn').forEach(function (b) {
            var on = b.dataset.mode === mode;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        try { window.localStorage.setItem('oc_create_mode', mode); } catch (e) { /* trình duyệt chặn storage */ }
        if (mode === 'ai') { aiRenderReview(); }
    }

    function aiStatus(msg) { var el = $('#oc-ai-status'); if (el) { el.textContent = msg; } }

    function aiAddImages(files) {
        Array.prototype.forEach.call(files || [], function (file) {
            if (!file || !/^image\//.test(file.type)) { return; }
            if (aiImages.length >= 4) { aiStatus('Tối đa 4 ảnh mỗi lần bóc tách.'); return; }
            if (file.size > 5 * 1024 * 1024) { aiStatus('❌ Ảnh "' + (file.name || '') + '" vượt quá 5MB.'); return; }
            aiImages.push({ file: file, url: URL.createObjectURL(file) });
        });
        aiRenderThumbs();
    }

    function aiRenderThumbs() {
        var box = $('#oc-ai-thumbs');
        box.innerHTML = '';
        box.hidden = !aiImages.length;
        aiImages.forEach(function (img, i) {
            var fig = document.createElement('figure');
            fig.innerHTML = '<img alt="Ảnh ' + (i + 1) + '"><button type="button" class="oc-x" aria-label="Bỏ ảnh">&times;</button>';
            fig.querySelector('img').src = img.url;
            fig.querySelector('button').addEventListener('click', function () {
                URL.revokeObjectURL(img.url);
                aiImages.splice(i, 1);
                aiRenderThumbs();
            });
            box.appendChild(fig);
        });
    }

    function aiClearImages() {
        aiImages.forEach(function (img) { URL.revokeObjectURL(img.url); });
        aiImages = [];
        $('#oc-ai-images').value = '';
        aiRenderThumbs();
    }

    // Gộp 1 sản phẩm vào danh sách dòng (cùng SP/biến thể thì cộng SL); giữ giá sửa/giảm của dòng cũ khi AI sửa đơn.
    function aiMergeItem(list, entry, qty, prev) {
        var vid = entry.variation_id || 0;
        var existing = list.find(function (it) { return it.product_id === entry.product_id && (it.variation_id || 0) === vid; });
        if (existing) { existing.qty += qty; return; }
        list.push({
            product_id: entry.product_id,
            variation_id: vid,
            name: entry.name,
            unit_price: Number(entry.price),
            qty: qty,
            manual_price: prev ? prev.manual_price : '',
            line_discount: prev ? prev.line_discount : 0
        });
    }

    function aiFlattenProducts(products) {
        var out = [];
        (products || []).forEach(function (p) {
            if (p.type === 'variable' && p.variations && p.variations.length) {
                p.variations.forEach(function (v) {
                    out.push({ product_id: p.id, variation_id: v.id, name: p.name + ' — ' + v.label, sku: v.sku, price: v.price });
                });
            } else {
                out.push({ product_id: p.id, variation_id: 0, name: p.name, sku: p.sku, price: p.price });
            }
        });
        return out;
    }

    function aiEntryLabel(e) { return e.name + (e.sku ? ' (' + e.sku + ')' : '') + ' — ' + money(e.price); }

    // Ô tìm SP dùng chung cho "Thêm sản phẩm" và "Tìm SP khác" của dòng cần xác nhận.
    function aiBindProductSearch(input, box, onPick) {
        var timer = 0;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var term = input.value.trim();
            if (term.length < 2) { box.hidden = true; return; }
            timer = setTimeout(function () {
                post('order_creator_search_products', { term: term }).then(function (res) {
                    if (!res.success) { return; }
                    var entries = aiFlattenProducts(res.data.products);
                    box.innerHTML = entries.length ? '' : '<div class="oc-result-empty">Không tìm thấy.</div>';
                    entries.forEach(function (e) {
                        var row = document.createElement('div');
                        row.className = 'oc-result-row';
                        row.innerHTML = '<span>' + escapeHtml(e.name + (e.sku ? ' (' + e.sku + ')' : '')) + '</span><b>' + money(e.price) + '</b>';
                        row.addEventListener('click', function () {
                            box.hidden = true;
                            input.value = '';
                            onPick(e);
                        });
                        box.appendChild(row);
                    });
                    box.hidden = false;
                });
            }, 300);
        });
        input.addEventListener('blur', function () { setTimeout(function () { box.hidden = true; }, 200); });
    }

    function aiRenderPending() {
        var box = $('#oc-ai-pending');
        if (!box) { return; }
        box.innerHTML = '';
        state.aiPending.forEach(function (p, i) {
            var row = document.createElement('div');
            row.className = 'oc-ai-item is-pending';
            var options = '<option value="-1">— Chọn sản phẩm —</option>' + p.candidates.map(function (c, ci) {
                return '<option value="' + ci + '"' + (ci === p.choice ? ' selected' : '') + '>' + escapeHtml(aiEntryLabel(c)) + '</option>';
            }).join('');
            row.innerHTML =
                '<div class="oc-ai-item__main">' +
                    '<span class="oc-ai-badge">Cần xác nhận</span> <span>AI đọc: “' + escapeHtml(p.source || '?') + '”</span>' +
                    (p.reason ? '<small class="oc-ai-item__reason">' + escapeHtml(p.reason) + '</small>' : '') +
                '</div>' +
                '<div class="oc-ai-item__pick">' +
                    '<select data-pf="choice">' + options + '</select>' +
                    '<div class="oc-ai-item__search"><input type="text" placeholder="Hoặc tìm sản phẩm khác..."><div class="oc-search-results" hidden></div></div>' +
                '</div>' +
                '<label class="oc-ai-item__qty">SL <input type="number" class="oc-mini" min="1" data-pf="qty" value="' + p.qty + '"></label>' +
                '<div class="oc-ai-item__acts">' +
                    '<button type="button" class="oc-btn oc-btn--primary oc-btn--sm" data-pa="confirm">Xác nhận</button>' +
                    '<button type="button" class="oc-x" data-pa="drop" aria-label="Bỏ dòng">&times;</button>' +
                '</div>';
            row.querySelector('[data-pf="choice"]').addEventListener('change', function () { p.choice = Number(this.value); });
            row.querySelector('[data-pf="qty"]').addEventListener('input', function () { p.qty = Math.max(1, Math.round(Number(this.value) || 1)); });
            aiBindProductSearch(row.querySelector('.oc-ai-item__search input'), row.querySelector('.oc-ai-item__search .oc-search-results'), function (e) {
                p.candidates.unshift(e);
                p.choice = 0;
                aiRenderPending();
            });
            row.querySelector('[data-pa="confirm"]').addEventListener('click', function () {
                var entry = p.candidates[p.choice];
                if (!entry) { alert('Chọn sản phẩm cho dòng "' + (p.source || '') + '".'); return; }
                state.aiPending.splice(i, 1);
                aiMergeItem(state.items, entry, p.qty, null);
                aiRenderPending();
                renderLines();
                recalc();
            });
            row.querySelector('[data-pa="drop"]').addEventListener('click', function () {
                state.aiPending.splice(i, 1);
                aiRenderPending();
            });
            box.appendChild(row);
        });
    }

    function aiRenderItems() {
        var box = $('#oc-ai-lines');
        if (!box) { return; }
        var snap = state.lastSnap;
        box.innerHTML = '';
        if (!state.items.length && !state.aiPending.length) {
            box.innerHTML = '<div class="oc-muted">Chưa có sản phẩm.</div>';
            return;
        }
        state.items.forEach(function (it, idx) {
            var line = snap ? (snap.lines || []).find(function (l) { return l.product_id === it.product_id && l.variation_id === (it.variation_id || 0); }) : null;
            var row = document.createElement('div');
            row.className = 'oc-ai-item';
            row.innerHTML =
                '<div class="oc-ai-item__main"><span class="oc-ai-badge is-ok">✓</span> ' + escapeHtml(it.name) + '</div>' +
                '<label class="oc-ai-item__qty">SL <input type="number" class="oc-mini" min="1" value="' + it.qty + '"></label>' +
                '<b class="oc-ai-item__total">' + (line ? money(line.line_total) : '—') + '</b>' +
                '<div class="oc-ai-item__acts"><button type="button" class="oc-x" aria-label="Bỏ sản phẩm">&times;</button></div>';
            var qty = row.querySelector('input');
            qty.addEventListener('input', function () { it.qty = Math.max(1, Number(qty.value) || 1); });
            qty.addEventListener('change', function () { renderManualLines(state.lastSnap); recalc(); });
            row.querySelector('.oc-x').addEventListener('click', function () {
                state.items.splice(idx, 1);
                renderLines();
                recalc();
            });
            box.appendChild(row);
        });
    }

    function selectedText(sel) {
        return sel && sel.value && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].textContent : '';
    }

    function clearSelectedCustomer() {
        state.customer = null;
        $('#oc-customer-search').value = '';
        $('#oc-customer-card').hidden = true;
        $('#oc-customer-card').innerHTML = '';
        $('#oc-customer-info').hidden = true;
        $('#oc-customer-info').innerHTML = '';
        ['#oc-customer-edit', '#oc-customer-history', '#oc-customer-products'].forEach(function (sel) { var el = $(sel); if (el) { el.hidden = true; } });
        updateSaveAddressAction();
    }

    function aiRenderCustomer() {
        var box = $('#oc-ai-customer');
        if (!box) { return; }
        var html = '';
        if (state.customer) {
            html += '<div class="oc-ai-cust-head"><span>👤 <b>' + escapeHtml(state.customer.name) + '</b> (#' + state.customer.id + ') — tài khoản có sẵn</span>' +
                '<button type="button" class="oc-btn oc-btn--ghost oc-btn--sm" data-ai-act="cust-clear">Đổi khách</button></div>';
        } else if (state.aiMatches.length) {
            html += '<div class="oc-ai-warn">' + (state.aiMatches.length === 1 ? 'Tìm thấy tài khoản khớp SĐT/email — bấm để chọn:' : 'Có ' + state.aiMatches.length + ' tài khoản trùng SĐT/email — chọn đúng khách:') + '</div><div class="oc-ai-matches">' +
                state.aiMatches.map(function (c, i) {
                    return '<button type="button" class="oc-btn oc-btn--ghost oc-btn--sm" data-ai-match="' + i + '">' + escapeHtml(c.name + ' #' + c.id + (c.phone ? ' · ' + c.phone : '') + (c.email ? ' · ' + c.email : '')) + '</button>';
                }).join('') + '</div>';
        } else {
            if (state.aiGuest) {
                html += '<div class="oc-ai-guest">🕘 Khách cũ chưa có tài khoản — ' + state.aiGuest.count + ' đơn trước, gần nhất <b>#' + escapeHtml(state.aiGuest.order_number) + '</b>' + (state.aiGuest.date ? ' (' + escapeHtml(state.aiGuest.date) + ')' : '') +
                    ' <button type="button" class="oc-btn oc-btn--ghost oc-btn--sm" data-ai-act="guest-address">Dùng địa chỉ đơn này</button></div>';
            }
            html += '<label class="oc-ai-newcust"><input type="checkbox" data-ai-act="create-customer"' + (state.aiCreateCustomer ? ' checked' : '') + '> ' + (state.aiGuest ? 'Tạo tài khoản cho khách khi tạo đơn' : 'Khách mới — tạo tài khoản khách hàng khi tạo đơn') + '</label>' +
                '<small class="oc-muted">Khách đã có tài khoản với SĐT khác? Chuyển sang giao diện thủ công để tìm khách.</small>';
        }
        var field = function (label, id, span) {
            return '<div' + (span ? ' class="oc-form-span"' : '') + '><label>' + label + '</label><input type="text" data-bind="' + id + '" value="' + escapeHtml($('#' + id).value) + '"></div>';
        };
        var area = [selectedText($('#oc-bill-city')), selectedText($('#oc-bill-state'))].filter(Boolean).join(', ');
        var warn = [];
        if (state.aiUnmatched.state) { warn.push('Tỉnh/Thành "' + state.aiUnmatched.state + '"'); }
        if (state.aiUnmatched.city) { warn.push('Phường/Xã "' + state.aiUnmatched.city + '"'); }
        html += '<div class="oc-form-grid oc-ai-cust-fields">' +
            field('Họ tên', 'oc-bill-name') + field('SĐT', 'oc-bill-phone') +
            field('Email', 'oc-bill-email') + field('Địa chỉ', 'oc-bill-address', true) +
            '<div class="oc-form-span oc-ai-area">' +
                '<span>' + (area ? '📍 ' + escapeHtml(area) : '⚠️ Chưa có Tỉnh/Thành, Phường/Xã') + '</span>' +
                ($('#oc-ship-diff').checked ? '<small class="oc-muted">Giao đến địa chỉ khác: ' + escapeHtml([$('#oc-ship-name').value, $('#oc-ship-address').value].filter(Boolean).join(' — ')) + '</small>' : '') +
                (warn.length ? '<small class="oc-ai-warn">⚠️ Không khớp danh mục: ' + escapeHtml(warn.join(', ')) + ' — chọn lại trên giao diện thủ công.</small>' : '') +
                '<button type="button" class="oc-btn oc-btn--ghost oc-btn--sm" data-mode-switch="manual">Sửa Tỉnh/Phường, địa chỉ giao</button>' +
            '</div>' +
        '</div>';
        box.innerHTML = html;
    }

    function aiSyncFromManual() {
        document.querySelectorAll('#oc-ai-order [data-sync]').forEach(function (el) {
            var src = $('#' + el.dataset.sync);
            if (src) { el.value = src.value; }
        });
    }

    function aiRenderReview() {
        if (!$('#oc-ai-order')) { return; }
        var editing = !!(state.items.length || state.aiPending.length);
        $('#oc-ai-review').hidden = !(editing || state.customer || state.aiSource || state.editOrderId);
        $('#oc-ai-source').hidden = !state.aiSource;
        $('#oc-ai-source-text').textContent = state.aiSource;
        $('#oc-ai-rerun').hidden = !editing;
        $('#oc-ai-run').textContent = editing ? 'Sửa đơn bằng AI' : 'Bóc tách & lên đơn';
        $('#oc-ai-text-label').textContent = editing
            ? 'Yêu cầu chỉnh sửa (vd: "thêm 1 hộp X, bỏ Y, đổi SL Z thành 3") — có thể dán thêm tin nhắn / ảnh mới'
            : aiTextLabel;
        $('#oc-ai-input-title').textContent = state.editOrderId ? '✨ Sửa đơn bằng AI' : '✨ AI tạo đơn';
        aiSyncFromManual();
        aiRenderCustomer();
        aiRenderPending();
        aiRenderItems();
        renderCoupons();
    }

    function aiReset() {
        state.aiPending = [];
        state.aiSource = '';
        state.aiMatches = [];
        state.aiUnmatched = {};
        state.aiGuest = null;
        state.aiCreateCustomer = true;
        if (!$('#oc-ai-order')) { return; }
        $('#oc-ai-text').value = '';
        aiClearImages();
        aiStatus('');
        aiRenderReview();
    }

    // "Bóc tách lại từ đầu": bỏ dữ liệu đơn đang dựng (giữ đơn đang sửa nếu có).
    function aiClearOrderData() {
        state.items = [];
        state.coupons = [];
        state.couponStatus = {};
        state._couponNotified = {};
        state.fees = [];
        state.aiPending = [];
        state.aiSource = '';
        state.aiMatches = [];
        state.aiUnmatched = {};
        state.aiGuest = null;
        clearSelectedCustomer();
        fillBilling({});
        setShipDiff(false);
        $('#oc-customer-note').value = '';
        $('#oc-internal-note').value = '';
        renderFees();
    }

    function nonEmpty(obj) {
        var out = {};
        Object.keys(obj).forEach(function (k) { if (obj[k]) { out[k] = obj[k]; } });
        return out;
    }

    function aiApplyResult(d, text, isEdit) {
        // Sản phẩm: dòng chắc chắn vào giỏ, dòng nghi ngờ chờ xác nhận.
        var prevItems = state.items;
        var items = [];
        var pending = isEdit ? state.aiPending.slice() : [];
        (d.items || []).forEach(function (it) {
            if (it.status === 'ok' && it.product) {
                var prev = prevItems.find(function (p) { return p.product_id === it.product.product_id && (p.variation_id || 0) === (it.product.variation_id || 0); });
                aiMergeItem(items, it.product, it.qty || 1, prev);
            } else {
                var candidates = it.candidates || [];
                pending.push({ source: it.source, qty: it.qty || 1, reason: it.reason, candidates: candidates, choice: candidates.length ? 0 : -1 });
            }
        });
        state.items = items;
        state.aiPending = pending;

        // Khách hàng: khớp đúng 1 tài khoản → chọn; nhiều → nhân viên chọn; không có → khách mới.
        var c = d.customer || {};
        var f = c.fields || {};
        var aiBilling = nonEmpty({ first_name: f.first_name, last_name: f.last_name, phone: f.phone, email: f.email, address_1: f.address_1, state: f.state, city: f.city });
        var hasAddr = !!(aiBilling.address_1 || aiBilling.state);
        var matches = c.matches || [];
        state.aiUnmatched = c.unmatched || {};
        var billingDone;
        // Sửa đơn: chỉ tra lại khách khi AI đọc được SĐT/email mới.
        if (isEdit && (state.customer || !(aiBilling.phone || aiBilling.email))) {
            billingDone = Object.keys(aiBilling).length ? fillBilling(Object.assign(collectBilling(), aiBilling)) : Promise.resolve();
        } else {
            var base = isEdit ? collectBilling() : {};
            billingDone = aiUseFoundCustomer({ matches: matches, guest: c.guest || null }, Object.assign(base, aiBilling), hasAddr);
        }

        (d.coupon_codes || []).forEach(function (code) {
            code = String(code).toLowerCase();
            if (state.coupons.indexOf(code) === -1) { state.coupons.push(code); }
        });
        if (d.payment_method) { setPaymentMethod(d.payment_method); }
        if (d.customer_note) { $('#oc-customer-note').value = d.customer_note; }
        if (d.internal_note) {
            var note = $('#oc-internal-note');
            note.value = note.value ? note.value + '\n' + d.internal_note : d.internal_note;
        }

        var used = text || ('[' + aiImages.length + ' ảnh]');
        state.aiSource = state.aiSource ? state.aiSource + '\n--- Chỉnh sửa ---\n' + used : used;
        $('#oc-ai-text').value = '';
        aiClearImages();

        var msg = '✅ ' + (isEdit ? 'Đã sửa đơn' : 'Đã lên đơn') + ': ' + items.length + ' sản phẩm';
        if (pending.length) { msg += ', ' + pending.length + ' dòng cần xác nhận'; }
        if (state.customer && matches.length === 1) { msg += '. Khách cũ: ' + state.customer.name + ' (#' + state.customer.id + ')'; }
        else if (state.aiMatches.length) { msg += '. Có ' + state.aiMatches.length + ' tài khoản trùng SĐT/email, chọn khách'; }
        else if (state.aiGuest) { msg += '. Khách cũ chưa có tài khoản (' + state.aiGuest.count + ' đơn)'; }
        if ((d.unknown_coupons || []).length) { msg += '. ⚠️ Không tìm thấy mã: ' + d.unknown_coupons.join(', '); }
        if (d.web_search_enabled) { msg += ' (Google Search đã bật cho địa chỉ)'; }
        aiStatus(msg + ' — kiểm tra lại trước khi tạo đơn.');

        renderFees();
        renderLines();
        billingDone.then(function () {
            aiRenderReview();
            doRecalc();
            $('#oc-ai-review').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    /**
     * Áp kết quả tìm khách cũ: đúng 1 tài khoản → chọn (giữ địa chỉ AI đọc nếu có, không thì địa chỉ hồ sơ);
     * nhiều → chờ nhân viên chọn; chỉ có đơn khách lẻ → điền địa chỉ đơn gần nhất cho trường AI thiếu.
     */
    function aiUseFoundCustomer(found, aiBilling, hasAddr) {
        var matches = found.matches || [];
        state.aiGuest = null;
        if (matches.length === 1) {
            var m = matches[0];
            state.aiMatches = [];
            selectCustomer(m, true);
            applyCustomerPaymentDefault(m);
            if (!hasAddr) {
                applyAddresses(Object.assign({}, m.billing || {}, nonEmpty({ phone: aiBilling.phone, email: aiBilling.email })), m.shipping || {});
                return Promise.resolve();
            }
            var b = Object.assign({}, m.billing || {}, aiBilling);
            if (m.billing && m.billing.first_name) { b.first_name = m.billing.first_name; b.last_name = m.billing.last_name; }
            setShipDiff(false);
            return fillBilling(b);
        }
        clearSelectedCustomer();
        state.aiMatches = matches;
        state.aiGuest = matches.length ? null : (found.guest || null);
        var billing = aiBilling;
        if (state.aiGuest) {
            billing = Object.assign({}, nonEmpty(state.aiGuest.billing || {}), hasAddr ? aiBilling : nonEmpty({ first_name: aiBilling.first_name, phone: aiBilling.phone, email: aiBilling.email }));
        }
        setShipDiff(false);
        return fillBilling(billing);
    }

    // Nhân viên sửa SĐT/email trong form AI → tra lại khách cũ (không gọi AI).
    var aiLookupSeq = 0;
    function aiLookupCustomer() {
        if (state.customer) { return; }
        var phone = $('#oc-bill-phone').value.trim();
        var email = $('#oc-bill-email').value.trim();
        if ((phone.replace(/\D/g, '').length < 9) && !email) { return; }
        var seq = ++aiLookupSeq;
        aiStatus('🔎 Đang tìm khách cũ theo SĐT/email...');
        post('order_creator_match_customer', { phone: phone, email: email }).then(function (res) {
            if (seq !== aiLookupSeq || !res.success || state.customer) { return; }
            var d = res.data || {};
            var current = collectBilling();
            var hasAddr = !!(current.address_1 || current.state);
            aiUseFoundCustomer(d, nonEmpty({ first_name: current.first_name, phone: current.phone, email: current.email, address_1: current.address_1, state: current.state, city: current.city }), hasAddr).then(function () {
                aiStatus(state.customer ? '✅ Tìm thấy khách cũ: ' + state.customer.name + ' (#' + state.customer.id + ')'
                    : state.aiMatches.length ? 'Có ' + state.aiMatches.length + ' tài khoản trùng — chọn đúng khách.'
                    : state.aiGuest ? 'Khách cũ chưa có tài khoản (' + state.aiGuest.count + ' đơn).'
                    : 'Không tìm thấy khách cũ — sẽ là khách mới.');
                aiRenderCustomer();
                recalc();
            });
        }).catch(function () { aiStatus(''); });
    }

    function aiRun(fresh) {
        var text = $('#oc-ai-text').value.trim();
        if (!text && !aiImages.length) { aiStatus('Dán tin nhắn hoặc chọn ảnh trước.'); return; }
        if (fresh) {
            if (!window.confirm('Bỏ khách, sản phẩm, mã ưu đãi đang có và bóc tách lại từ đầu?')) { return; }
            aiClearOrderData();
            renderCoupons();
            renderLines();
        }
        var isEdit = !!(state.items.length || state.aiPending.length);
        var params = {
            text: text,
            convert_old_address: $('#oc-ai-convert-address').checked ? 1 : 0,
            'images[]': aiImages.map(function (img) { return img.file; })
        };
        if (isEdit) {
            params.current = JSON.stringify({
                items: state.items.map(function (it) { return { product_id: it.product_id, variation_id: it.variation_id || 0, name: it.name, qty: it.qty }; })
            });
        }
        var buttons = [$('#oc-ai-run'), $('#oc-ai-rerun')];
        buttons.forEach(function (b) { b.disabled = true; });
        aiStatus(isEdit ? '⏳ AI đang sửa đơn...' : '⏳ AI đang đọc đơn (khách, sản phẩm, mã ưu đãi)...');
        post('order_creator_ai_extract_order', params).then(function (res) {
            buttons.forEach(function (b) { b.disabled = false; });
            if (!res.success) { aiStatus('❌ ' + ((res.data && res.data.message) || 'Bóc tách thất bại.')); return; }
            aiApplyResult(res.data, text, isEdit);
        }).catch(function (err) {
            buttons.forEach(function (b) { b.disabled = false; });
            aiStatus('❌ Không gọi được AI' + (err && err.message ? ': ' + err.message : ', thử lại sau.'));
        });
    }

    // Khách mới ở chế độ AI: tạo tài khoản ngay trước khi tạo đơn để đơn gắn đúng khách.
    function aiEnsureCustomer() {
        if (currentMode() !== 'ai' || state.customer) { return Promise.resolve(); }
        if (state.aiMatches.length) {
            return Promise.reject(new Error('Có ' + state.aiMatches.length + ' tài khoản trùng SĐT/email — chọn đúng khách trong mục Khách hàng trước khi tạo đơn.'));
        }
        if (!state.aiCreateCustomer) { return Promise.resolve(); }
        var b = collectBilling();
        if (!b.phone && !b.email) { return Promise.resolve(); }
        if (!b.first_name) { return Promise.reject(new Error('Thiếu họ tên khách để tạo tài khoản.')); }
        var payload = {
            user_id: 0, copy_to_shipping: 1, role: 'customer', username: '',
            first_name: b.first_name, last_name: b.last_name || '', email: b.email || '', phone: b.phone || '',
            address_1: b.address_1 || '', address_2: '', state: b.state || '', city: b.city || ''
        };
        return post('order_creator_create_customer', { payload: JSON.stringify(payload) }).then(function (res) {
            if (!res.success) {
                throw new Error('Không tạo được khách hàng mới: ' + ((res.data && res.data.message) || 'lỗi không xác định') + '\nBỏ tích "Khách mới — tạo tài khoản" để tạo đơn khách lẻ.');
            }
            selectCustomer(res.data.customer, true);
            aiRenderCustomer();
        });
    }

    function aiBind() {
        aiTextLabel = $('#oc-ai-text-label').textContent;
        document.querySelectorAll('.oc-mode-btn').forEach(function (b) {
            b.addEventListener('click', function () { setMode(b.dataset.mode); });
        });
        // Nút "Sửa trên giao diện thủ công" (kể cả nút render động trong form AI).
        $('#oc-ai-order').addEventListener('click', function (e) {
            var sw = e.target.closest('[data-mode-switch]');
            if (sw) {
                setMode(sw.dataset.modeSwitch);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
        $('#oc-ai-run').addEventListener('click', function () { aiRun(false); });
        $('#oc-ai-rerun').addEventListener('click', function () { aiRun(true); });
        $('#oc-ai-images').addEventListener('change', function () { aiAddImages(this.files); this.value = ''; });
        $('#oc-ai-text').addEventListener('paste', function (e) {
            var items = (e.clipboardData && e.clipboardData.items) || [];
            var files = [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) { files.push(items[i].getAsFile()); }
            }
            if (files.length) { e.preventDefault(); aiAddImages(files); }
        });
        $('#oc-ai-text').addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); aiRun(false); }
        });

        var cust = $('#oc-ai-customer');
        cust.addEventListener('click', function (e) {
            var match = e.target.closest('[data-ai-match]');
            if (match) {
                var c = state.aiMatches[Number(match.dataset.aiMatch)];
                state.aiMatches = [];
                selectCustomer(c, true);
                applyCustomerPaymentDefault(c);
                aiRenderCustomer();
                return;
            }
            if (e.target.closest('[data-ai-act="guest-address"]') && state.aiGuest) {
                fillBilling(Object.assign(collectBilling(), nonEmpty(state.aiGuest.billing || {}))).then(function () { aiRenderCustomer(); recalc(); });
                return;
            }
            if (e.target.closest('[data-ai-act="cust-clear"]')) {
                clearSelectedCustomer();
                state.aiCreateCustomer = false;
                aiRenderCustomer();
                recalc();
            }
        });
        cust.addEventListener('change', function (e) {
            if (e.target.dataset.aiAct === 'create-customer') { state.aiCreateCustomer = e.target.checked; return; }
            if (e.target.dataset.bind) {
                if (e.target.dataset.bind === 'oc-bill-phone' || e.target.dataset.bind === 'oc-bill-email') { aiLookupCustomer(); }
                recalc();
            }
        });
        cust.addEventListener('input', function (e) {
            var id = e.target.dataset.bind;
            if (id) { $('#' + id).value = e.target.value; }
        });

        document.querySelectorAll('#oc-ai-order [data-sync]').forEach(function (el) {
            el.addEventListener('change', function () { $('#' + el.dataset.sync).value = el.value; });
            el.addEventListener('input', function () { $('#' + el.dataset.sync).value = el.value; });
        });

        aiBindProductSearch($('#oc-ai-product-search'), $('#oc-ai-product-results'), function (e) {
            aiMergeItem(state.items, e, 1, null);
            renderLines();
            recalc();
        });
        var addAiCoupon = function () {
            addCoupon($('#oc-ai-coupon-input').value);
            $('#oc-ai-coupon-input').value = '';
        };
        $('#oc-ai-coupon-add').addEventListener('click', addAiCoupon);
        $('#oc-ai-coupon-input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addAiCoupon(); } });
    }

    // ---------- recalc ----------
    var recalcTimer;
    function recalc() {
        clearTimeout(recalcTimer);
        recalcTimer = setTimeout(doRecalc, 250);
    }

    function doRecalc() {
        if (!state.items.length) { state.lastSnap = null; renderLines(); updateTotals(null); refreshCustomerCoupons(); return; }
        post('order_creator_recalculate', { payload: JSON.stringify(buildPayload()) }).then(function (res) {
            if (!res.success) { console.warn(res.data && res.data.message); return; }
            state.lastSnap = res.data;
            custCouponSeq++; // bỏ kết quả gợi ý mã (giỏ trống) về muộn
            state.customerCoupons = res.data.customer_coupons || [];
            renderLines(res.data);
            updateTotals(res.data);
            updateShippingOptions(res.data);
            applyCouponStatus(res.data);
        });
    }

    function updateTotals(snap) {
        var keys = ['subtotal', 'discount_total', 'fee_total', 'shipping_total', 'tax_total', 'total'];
        keys.forEach(function (k) {
            document.querySelectorAll('[data-total="' + k + '"]').forEach(function (el) { el.textContent = money(snap ? snap[k] : 0); });
        });
    }

    function updateShippingOptions(snap) {
        var sel = $('#oc-shipping-method');
        var rates = (snap && snap.shipping_rates) || [];
        if (snap && snap.chosen_shipping && snap.chosen_shipping !== state.shipping_method) {
            state.shipping_method = snap.chosen_shipping;
        }
        var current = state.shipping_method || (snap && snap.chosen_shipping) || '';
        sel.innerHTML = '';
        var configured = CFG.shippingMethods || [];
        var ratesById = {};
        rates.forEach(function (r) { ratesById[r.id] = r; });

        if (!configured.length && !rates.length) { sel.innerHTML = '<option value="">(không cần vận chuyển)</option>'; return; }
        (configured.length ? configured : rates).forEach(function (method) {
            var r = ratesById[method.id];
            var o = document.createElement('option');
            o.value = method.id;
            o.textContent = method.label + (r ? ' — ' + money(r.cost) : ' — chưa áp dụng cho địa chỉ này');
            o.disabled = !r;
            if (method.id === current) o.selected = true;
            sel.appendChild(o);
        });
        rates.forEach(function (r) {
            if (configured.some(function (method) { return method.id === r.id; })) { return; }
            var o = document.createElement('option');
            o.value = r.id; o.textContent = r.label + ' — ' + money(r.cost);
            if (r.id === current) o.selected = true;
            sel.appendChild(o);
        });
        syncShippingTitleField();
    }

    function syncShippingTitleField() {
        var input = $('#oc-shipping-title');
        var choice = $('#oc-shipping-title-choice');
        var shippingCost = $('#oc-shipping-cost').value.trim();
        if (shippingCost !== '' && Number(shippingCost) === 0) {
            choice.value = 'Miễn phí vận chuyển';
            input.value = '';
            input.disabled = true;
            return;
        }
        if (choice.value === 'Miễn phí vận chuyển') { choice.value = ''; }
        input.disabled = false;
    }

    // ---------- create order ----------
    function createOrder(isDraft) {
        if (state.aiPending.length) {
            alert('Còn ' + state.aiPending.length + ' dòng sản phẩm AI cần xác nhận.');
            setMode('ai');
            $('#oc-ai-items').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (!state.items.length) { alert('Chưa có sản phẩm.'); return; }
        var btn = isDraft ? $('#oc-draft') : $('#oc-create');
        btn.disabled = true;
        aiEnsureCustomer().then(function () {
            btn.disabled = false;
            submitOrder(isDraft);
        }).catch(function (err) {
            btn.disabled = false;
            alert((err && err.message) || 'Không chuẩn bị được khách hàng cho đơn.');
        });
    }

    function submitOrder(isDraft) {
        flushPendingFee();
        var payload = buildPayload();
        payload.draft = !!isDraft;
        var btn = isDraft ? $('#oc-draft') : $('#oc-create');
        var label = isDraft ? 'Lưu nháp' : (state.editOrderId ? 'Cập nhật' : 'Tạo đơn');
        btn.disabled = true; btn.textContent = isDraft ? 'Đang lưu...' : (state.editOrderId ? 'Đang cập nhật...' : 'Đang tạo...');
        post('order_creator_create_order', { payload: JSON.stringify(payload) }).then(function (res) {
            btn.disabled = false; btn.textContent = label;
            if (!res.success) { alert((res.data && res.data.message) || 'Lỗi tạo đơn.'); return; }
            state.lastOrder = res.data;
            setEditorDisabled(true);
            renderResult(res.data, isDraft);
        }).catch(function () { btn.disabled = false; btn.textContent = label; });
    }

    function renderResult(o, isDraft, heading) {
        var box = $('#oc-result');
        box.hidden = false;
        var headText = heading || (isDraft ? '📝 Đã lưu nháp đơn ' : (state.editOrderId ? '✅ Đã cập nhật đơn ' : '✅ Đã tạo đơn '));
        box.innerHTML = '<div class="oc-result-head">' + headText + '<b>#' + o.order_number + '</b> — ' + money(o.total) + '</div>';
        $('#oc-view-order').href = o.edit_url || '#';
        $('#oc-print-pxk').href = CFG.ajaxUrl + '?action=inhoadon_ghtk&order_id=' + encodeURIComponent(o.order_id);
        syncCustomerConfirmedButton(o, isDraft);
        setActionMode('result');
        box.scrollIntoView({ behavior: 'smooth' });
    }

    function syncCustomerConfirmedButton(o, isDraft) {
        var btn = $('#oc-customer-confirmed');
        if (!btn) { return; }
        var show = !isDraft && o && o.payment_method === 'cod' && ['on-hold', 'processing'].indexOf(o.status) !== -1;
        btn.hidden = !show;
        btn.style.display = show ? '' : 'none';
        btn.disabled = false;
        btn.textContent = 'Khách đã xác nhận';
    }

    function customerConfirmed() {
        var o = state.lastOrder;
        if (!o || !o.order_id) { return; }
        var btn = $('#oc-customer-confirmed');
        btn.disabled = true;
        btn.textContent = 'Đang chuyển...';
        post('order_creator_customer_confirmed', { order_id: o.order_id }).then(function (res) {
            var data = (res && res.data) || {};
            data.order_id = data.order_id || o.order_id;
            data.order_number = data.order_number || o.order_number;
            data.edit_url = data.edit_url || o.edit_url;
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = 'Khách đã xác nhận';
                showCustomerConfirmModal(data, true);
                return;
            }
            state.lastOrder = Object.assign({}, state.lastOrder, data);
            renderResult(state.lastOrder, false, '✅ Khách đã xác nhận, đơn chuyển sang ' + (data.status_label || data.status) + ' ');
            showCustomerConfirmModal(Object.assign({ message: 'Đã xác nhận COD và cập nhật trạng thái đơn.' }, data), false);
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Khách đã xác nhận';
            showCustomerConfirmModal({
                order_id: o.order_id,
                order_number: o.order_number,
                edit_url: o.edit_url,
                message: 'Không gọi được máy chủ. Vui lòng thử lại hoặc kiểm tra trạng thái đơn trong quản trị.'
            }, true);
        });
    }

    function editInline() {
        var o = state.lastOrder;
        if (!o) { return; }
        $('#oc-result').hidden = true;
        loadOrder(o.order_id);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // "Copy" — tạo bản sao từ đơn vừa tạo (result) hoặc đơn đang sửa (edit).
    function copyCurrent() {
        var bar = $('#oc-create-actions');
        var resultMode = bar && bar.classList.contains('mode-result');
        var id = resultMode ? (state.lastOrder && state.lastOrder.order_id) : state.editOrderId;
        if (!id) { return; }
        $('#oc-result').hidden = true;
        loadOrder(id, true);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ---------- invoice modal ----------
    function openInvoice(url) {
        $('#oc-invoice-msg').textContent = '';
        $('#oc-invoice-frame').src = url;
        openModal('#oc-invoice-modal');
    }

    function invoiceCanvas() {
        var frame = $('#oc-invoice-frame');
        var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
        if (!doc) { return Promise.reject('Không đọc được hóa đơn.'); }
        var images = Array.prototype.slice.call(doc.images || []);
        var ready = images.map(function (img) {
            if (img.complete) { return Promise.resolve(); }
            return new Promise(function (resolve) {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            });
        });
        return Promise.all(ready).then(function () {
            return html2canvas(doc.body, { scale: 2, useCORS: true, backgroundColor: '#ffffff' });
        });
    }

    function invoiceFileName() {
        return 'hoa-don-' + (state.lastOrder ? state.lastOrder.order_number : Date.now()) + '.jpg';
    }

    function invoiceToJpg() {
        if (typeof html2canvas === 'undefined') { alert('Thư viện tạo ảnh chưa tải được.'); return; }
        $('#oc-invoice-msg').textContent = 'Đang tạo ảnh...';
        invoiceCanvas().then(function (canvas) {
            var a = document.createElement('a');
            a.href = canvas.toDataURL('image/jpeg', 0.92);
            a.download = invoiceFileName();
            a.click();
            $('#oc-invoice-msg').textContent = '';
        }).catch(function (e) { $('#oc-invoice-msg').textContent = 'Lỗi tạo ảnh: ' + e; });
    }

    function invoiceCopy() {
        if (typeof html2canvas === 'undefined') { alert('Thư viện tạo ảnh chưa tải được.'); return; }
        if (!navigator.clipboard || !window.ClipboardItem) { $('#oc-invoice-msg').textContent = 'Trình duyệt không hỗ trợ copy ảnh — hãy dùng Xuất JPG.'; return; }
        $('#oc-invoice-msg').textContent = 'Đang chuẩn bị ảnh...';
        invoiceCanvas().then(function (canvas) {
            canvas.toBlob(function (blob) {
                navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]).then(function () {
                    $('#oc-invoice-msg').textContent = '✅ Đã copy ảnh hóa đơn — dán (Ctrl/Cmd+V) vào chat để gửi khách.';
                }).catch(function () { $('#oc-invoice-msg').textContent = 'Không copy được ảnh — hãy dùng Xuất JPG.'; });
            }, 'image/png');
        }).catch(function (e) { $('#oc-invoice-msg').textContent = 'Lỗi: ' + e; });
    }

    // ---------- tabs ----------
    function switchTab(tab) {
        document.querySelectorAll('.oc-tab[data-tab]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === tab); });
        $('#oc-pane-create').hidden = (tab !== 'create');
        var sp = $('#oc-pane-settings'); if (sp) { sp.hidden = (tab !== 'settings'); }
        var hp = $('#oc-pane-hdsd'); if (hp) { hp.hidden = (tab !== 'hdsd'); }
        var ca = $('#oc-create-actions'); if (ca) { ca.style.visibility = (tab === 'create') ? '' : 'hidden'; }
        if (tab === 'settings') { initSettingsPane(); }
    }

    // ---------- settings pane ----------
    var settingsProducts = [];
    var settingsInited = false;
    function initSettingsPane() {
        if (settingsInited) { return; }
        settingsInited = true;
        $('#oc-set-image-stock').checked = !!SET.show_image_stock;
        $('#oc-set-hidden-last').checked = !!SET.group_hidden_last;
        $('#oc-set-hide-manual-price').checked = !!SET.hide_manual_price;
        $('#oc-set-hide-line-discount').checked = !!SET.hide_line_discount;
        settingsProducts = (SET.default_products || []).slice();
        renderSettingsProducts();

        var grid = $('#oc-set-customer-fields'); grid.innerHTML = '';
        var defs = CFG.customerFieldDefs || {};
        Object.keys(defs).forEach(function (key) {
            var checked = (SET.customer_fields || []).indexOf(key) !== -1;
            var lbl = document.createElement('label');
            lbl.innerHTML = '<input type="checkbox" value="' + key + '"' + (checked ? ' checked' : '') + '> ' + defs[key];
            grid.appendChild(lbl);
        });
        var infoGrid = $('#oc-set-customer-info-fields'); infoGrid.innerHTML = '';
        var infoDefs = CFG.customerInfoFieldDefs || {};
        Object.keys(infoDefs).forEach(function (key) {
            var checked = (SET.customer_info_fields || []).indexOf(key) !== -1;
            var lbl = document.createElement('label');
            lbl.innerHTML = '<input type="checkbox" value="' + key + '"' + (checked ? ' checked' : '') + '> ' + infoDefs[key].label;
            infoGrid.appendChild(lbl);
        });
        var orderGrid = $('#oc-set-order-fields'); orderGrid.innerHTML = '';
        var orderDefs = CFG.orderFieldDefs || {};
        Object.keys(orderDefs).forEach(function (key) {
            var checked = (SET.order_fields || []).indexOf(key) !== -1;
            var lbl = document.createElement('label');
            lbl.innerHTML = '<input type="checkbox" value="' + key + '"' + (checked ? ' checked' : '') + '> ' + orderDefs[key].label;
            orderGrid.appendChild(lbl);
        });

        var st;
        $('#oc-set-product-search').addEventListener('input', function () {
            clearTimeout(st);
            var term = this.value.trim();
            var box = $('#oc-set-product-results');
            if (term.length < 2) { box.hidden = true; return; }
            st = setTimeout(function () {
                post('order_creator_search_products', { term: term }).then(function (res) {
                    if (!res.success) { return; }
                    box.innerHTML = '';
                    (res.data.products || []).forEach(function (p) {
                        var row = document.createElement('div');
                        row.className = 'oc-result-row';
                        row.innerHTML = (p.image ? '<img class="oc-thumb" src="' + p.image + '">' : '') + '<span>' + p.name + '</span>';
                        row.addEventListener('click', function () {
                            if (!settingsProducts.find(function (x) { return x.id === p.id; })) { settingsProducts.push(p); }
                            renderSettingsProducts(); box.hidden = true; $('#oc-set-product-search').value = '';
                        });
                        box.appendChild(row);
                    });
                    box.hidden = false;
                });
            }, 300);
        });

        $('#oc-set-save').addEventListener('click', saveSettings);
    }

    function renderSettingsProducts() {
        renderChips($('#oc-set-default-products'), settingsProducts,
            function (p) { return p.name; },
            function (i) { settingsProducts.splice(i, 1); renderSettingsProducts(); });
    }

    function saveSettings() {
        var fields = [];
        $('#oc-set-customer-fields').querySelectorAll('input:checked').forEach(function (c) { fields.push(c.value); });
        var infoFields = [];
        $('#oc-set-customer-info-fields').querySelectorAll('input:checked').forEach(function (c) { infoFields.push(c.value); });
        var orderFields = [];
        $('#oc-set-order-fields').querySelectorAll('input:checked').forEach(function (c) { orderFields.push(c.value); });
        var payload = {
            default_products: settingsProducts.map(function (p) { return p.id; }),
            show_image_stock: $('#oc-set-image-stock').checked ? 1 : 0,
            group_hidden_last: $('#oc-set-hidden-last').checked ? 1 : 0,
            hide_manual_price: $('#oc-set-hide-manual-price').checked ? 1 : 0,
            hide_line_discount: $('#oc-set-hide-line-discount').checked ? 1 : 0,
            customer_fields: fields,
            customer_info_fields: infoFields,
            order_fields: orderFields
        };
        var btn = $('#oc-set-save'); btn.disabled = true;
        $('#oc-set-status').textContent = 'Đang lưu...';
        post('order_creator_save_settings', { payload: JSON.stringify(payload) }).then(function (res) {
            btn.disabled = false;
            if (!res.success) { $('#oc-set-status').textContent = '❌ ' + ((res.data && res.data.message) || 'Lỗi'); return; }
            SET.show_image_stock = !!res.data.settings.show_image_stock;
            SET.group_hidden_last = !!res.data.settings.group_hidden_last;
            SET.hide_manual_price = !!res.data.settings.hide_manual_price;
            SET.hide_line_discount = !!res.data.settings.hide_line_discount;
            document.body.classList.toggle('oc-hide-manual-price', SET.hide_manual_price);
            document.body.classList.toggle('oc-hide-line-discount', SET.hide_line_discount);
            SET.customer_fields = res.data.settings.customer_fields;
            SET.customer_info_fields = res.data.settings.customer_info_fields;
            SET.order_fields = res.data.settings.order_fields;
            SET.default_products = settingsProducts.slice();
            $('#oc-set-status').textContent = '✅ Đã lưu cài đặt';
        });
    }

    // ---------- payment modal ----------
    // Plugin eim chạy: bỏ qua modal này, mở thẳng widget hub (window.hubPay — payment-hub-link.php)
    // không truyền payer → widget bắt đầu ở bước chọn người trả. Mở hub lỗi → hiện modal kèm lỗi
    // để mở lại. Không chạy eim thì modal gọi confirm_order_payment như cũ.
    function hubPayOn() { return !!(window.hubPay && window.hubPay.enabled); }

    function openPayModal(o) {
        state.payTarget = o;
        if (hubPayOn()) {
            openHubPay(o).catch(function (e) {
                showPayModal(o);
                $('#oc-pay-result').textContent = '❌ ' + e.message;
            });
            return;
        }
        showPayModal(o);
    }

    function openHubPay(o, onDone) {
        if (state.hubPayBusy) { return Promise.resolve(); }
        state.hubPayBusy = true;
        return window.hubPay.open({ orderId: o.order_id, onDone: onDone })
            .then(function () { state.hubPayBusy = false; }, function (e) { state.hubPayBusy = false; throw e; });
    }

    function showPayModal(o) {
        $('#oc-pay-info').value = 'Đơn #' + o.order_number + ' | SĐT: ' + (o.phone || '') + ' | Tổng: ' + money(o.total);
        $('#oc-pay-amount').value = o.payment_total;
        $('#oc-pay-date').value = new Date().toISOString().split('T')[0];
        $('#oc-pay-payer').value = 'customer';
        $('#oc-pay-codnote').value = '';
        $('#oc-pay-result').textContent = '';
        var hub = hubPayOn();
        ['#oc-pay-bank', '#oc-pay-date', '#oc-pay-amount', '#oc-pay-payer'].forEach(function (sel) { $(sel).parentNode.hidden = hub; });
        $('#oc-pay-confirm').textContent = hub ? 'Mở công cụ xác nhận (hub)' : 'Xác nhận';
        togglePayCodNote();
        openModal('#oc-pay-modal');
    }

    function confirmPayment() {
        var target = state.payTarget || state.lastOrder;
        if (!target) return;
        var payer = $('#oc-pay-payer').value;
        var btn = $('#oc-pay-confirm');
        if (hubPayOn()) {
            btn.disabled = true;
            openHubPay(target, function (confirmed) {
                $('#oc-pay-result').textContent = confirmed ? '✅ Đã xác nhận thanh toán ở hub.' : 'Chưa xác nhận — mở lại công cụ nếu cần.';
            }).catch(function (e) { $('#oc-pay-result').textContent = '❌ ' + e.message; })
                .then(function () { btn.disabled = false; });
            return;
        }
        btn.disabled = true; btn.textContent = 'Đang xử lý...';
        post('confirm_order_payment', {
            order_ids: target.order_id,
            bank_account: $('#oc-pay-bank').value,
            paid_date: $('#oc-pay-date').value,
            amount_received: $('#oc-pay-amount').value,
            payer: payer,
            cod_note: $('#oc-pay-codnote').value
        }, CFG.confirmNonce).then(function (res) {
            btn.disabled = false; btn.textContent = 'Xác nhận';
            $('#oc-pay-result').textContent = res.success ? '✅ ' + (res.data.message || 'Đã xác nhận.') : '❌ ' + ((res.data && res.data.message) || 'Lỗi.');
        }).catch(function () { btn.disabled = false; btn.textContent = 'Xác nhận'; });
    }

    // Xác nhận COD trực tiếp từ 1 dòng kết quả Tìm đơn — không đụng state.lastOrder
    // (đơn đang mở trong form) để tránh phá dở luồng chỉnh sửa khác đang diễn ra.
    function confirmCustomerForSearchRow(order, btn) {
        btn.disabled = true;
        btn.textContent = 'Đang chuyển...';
        post('order_creator_customer_confirmed', { order_id: order.id }).then(function (res) {
            var data = (res && res.data) || {};
            data.order_id = data.order_id || order.id;
            data.order_number = data.order_number || order.number;
            data.edit_url = data.edit_url || order.edit_url;
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = 'Khách đã xác nhận';
                showCustomerConfirmModal(data, true);
                return;
            }
            btn.textContent = 'Đã xác nhận';
            showCustomerConfirmModal(Object.assign({ message: 'Đã xác nhận COD và cập nhật trạng thái đơn.' }, data), false);
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Khách đã xác nhận';
            showCustomerConfirmModal({
                order_id: order.id,
                order_number: order.number,
                edit_url: order.edit_url,
                message: 'Không gọi được máy chủ. Vui lòng thử lại hoặc kiểm tra trạng thái đơn trong quản trị.'
            }, true);
        });
    }

    function togglePayCodNote() {
        var v = $('#oc-pay-payer').value;
        $('#oc-pay-codnote-wrap').style.display = !hubPayOn() && (v === 'shipper' || v === 'self') ? 'block' : 'none';
    }

    // ---------- modals ----------
    function openModal(sel) { $(sel).hidden = false; document.body.classList.add('oc-modal-open'); }
    function closeModals() {
        document.querySelectorAll('.oc-modal').forEach(function (m) { m.hidden = true; });
        document.body.classList.remove('oc-modal-open');
    }

    // ---------- bind ----------
    function bind() {
        $('#oc-product-search').addEventListener('input', onProductSearch);
        $('#oc-product-search').addEventListener('focus', onProductFocus);
        $('#oc-order-search').addEventListener('input', onOrderSearch);
        $('#oc-customer-search').addEventListener('input', onCustomerSearch);
        $('#oc-save-customer-addresses').addEventListener('change', updateSaveAddressAction);
        $('#oc-save-customer-addresses-confirm').addEventListener('click', saveCustomerAddresses);
        $('#oc-lines-body').addEventListener('input', onLineInput);
        $('#oc-lines-body').addEventListener('change', function (e) { if (e.target.dataset.f) recalc(); });
        $('#oc-lines-body').addEventListener('click', onLineInput);
        $('#oc-coupon-input').addEventListener('input', onCouponSearch);
        $('#oc-coupon-input').addEventListener('keydown', onCouponKeydown);
        $('#oc-coupon-input').addEventListener('blur', function () { setTimeout(hideCouponSuggestions, 160); });
        $('#oc-coupon-add').addEventListener('click', function () { addCoupon($('#oc-coupon-input').value); $('#oc-coupon-input').value = ''; hideCouponSuggestions(); });
        $('#oc-fee-add').addEventListener('click', addFee);
        $('#oc-shipping-method').addEventListener('change', function () { state.shipping_method = this.value; recalc(); });
        $('#oc-shipping-title-choice').addEventListener('change', function () {
            if (this.value === 'Miễn phí vận chuyển') {
                $('#oc-shipping-cost').value = '0';
            } else if ($('#oc-shipping-cost').value.trim() !== '' && Number($('#oc-shipping-cost').value) === 0) {
                $('#oc-shipping-cost').value = '';
            }
            if (this.value) { $('#oc-shipping-title').value = ''; }
            syncShippingTitleField();
            recalc();
        });
        $('#oc-shipping-title').addEventListener('input', function () {
            if (this.value.trim()) { $('#oc-shipping-title-choice').value = ''; }
        });
        $('#oc-shipping-cost').addEventListener('input', syncShippingTitleField);
        $('#oc-shipping-cost').addEventListener('change', function () { syncShippingTitleField(); recalc(); });
        $('#oc-ship-diff').addEventListener('change', function () {
            $('#oc-ship-fields').hidden = !this.checked;
            if (this.checked) { copyBillingToShipping(); }
            recalc();
        });
        $('#oc-ship-load-btn').addEventListener('click', loadShippingFromOrder);
        ['oc-bill-state', 'oc-bill-city', 'oc-bill-address', 'oc-bill-phone', 'oc-bill-email', 'oc-ship-state', 'oc-ship-city', 'oc-ship-address'].forEach(function (id) {
            var el = $('#' + id); if (el) { el.addEventListener('change', recalc); }
        });
        $('#oc-customer-new-toggle').addEventListener('click', function () { openCustomerModal('new'); });
        var custEdit = $('#oc-customer-edit'); if (custEdit) { custEdit.addEventListener('click', function () { openCustomerModal('edit'); }); }
        $('#oc-customer-history').addEventListener('click', function () { loadCustomerHistory('orders'); });
        $('#oc-customer-products').addEventListener('click', function () { loadCustomerHistory('products'); });
        $('#oc-cust-load-btn').addEventListener('click', loadCustomerFromOrder);
        $('#oc-new-save').addEventListener('click', createCustomer);
        vnBindStateCascade($('#oc-cust-state'), $('#oc-cust-city'));
        vnBindStateCascade($('#oc-bill-state'), $('#oc-bill-city'));
        vnBindStateCascade($('#oc-ship-state'), $('#oc-ship-city'));
        ['oc-cust-state', 'oc-bill-state', 'oc-ship-state'].forEach(function (id) {
            vnMakeSearchable($('#' + id), 'Tỉnh/Thành — gõ để tìm');
        });
        ['oc-cust-city', 'oc-bill-city', 'oc-ship-city'].forEach(function (id) {
            vnMakeSearchable($('#' + id), 'Phường/Xã — gõ để tìm');
        });
        if ($('#oc-cust-ai-toggle')) { // panel AI chỉ render khi feature bật trong Cài đặt ERP
            $('#oc-cust-ai-toggle').addEventListener('click', function () {
                var panel = $('#oc-cust-ai-panel');
                panel.hidden = !panel.hidden;
                this.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                if (!panel.hidden) { $('#oc-cust-ai-text').focus(); }
            });
            $('#oc-cust-ai-run').addEventListener('click', aiExtractCustomer);
            $('#oc-cust-ai-image').addEventListener('change', function () { aiSetImage(this.files && this.files[0]); });
            $('#oc-cust-ai-remove-img').addEventListener('click', aiClearImage);
            $('#oc-cust-ai-text').addEventListener('paste', function (e) {
                var items = (e.clipboardData && e.clipboardData.items) || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type && items[i].type.indexOf('image/') === 0) {
                        aiSetImage(items[i].getAsFile());
                        e.preventDefault();
                        return;
                    }
                }
            });
        }
        if ($('#oc-ai-order')) { aiBind(); }
        document.querySelectorAll('.oc-tab[data-tab]').forEach(function (b) { b.addEventListener('click', function () { switchTab(b.dataset.tab); }); });
        $('#oc-invoice-jpg').addEventListener('click', invoiceToJpg);
        $('#oc-invoice-copy').addEventListener('click', invoiceCopy);
        $('#oc-cancel-edit').addEventListener('click', cancelEdit);
        $('#oc-clear-cart').addEventListener('click', clearCart);
        $('#oc-recalc').addEventListener('click', function () { flushPendingFee(); doRecalc(); });
        $('#oc-draft').addEventListener('click', function () { createOrder(true); });
        $('#oc-create').addEventListener('click', function () { createOrder(false); });
        $('#oc-copy-order').addEventListener('click', copyCurrent);
        $('#oc-new-order').addEventListener('click', startNewOrder);
        $('#oc-edit-inline').addEventListener('click', editInline);
        $('#oc-customer-confirmed').addEventListener('click', customerConfirmed);
        $('#oc-view-invoice').addEventListener('click', function () { if (state.lastOrder) { openInvoice(state.lastOrder.invoice_url); } });
        $('#oc-open-pay').addEventListener('click', function () { if (state.lastOrder) { openPayModal(state.lastOrder); } });
        document.querySelectorAll('.oc-section-nav a[data-jump]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(link.dataset.jump);
                if (target) {
                    document.querySelectorAll('.oc-section-nav a[data-jump]').forEach(function (item) { item.classList.remove('is-active'); });
                    link.classList.add('is-active');
                    link.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
        $('#oc-pay-confirm').addEventListener('click', confirmPayment);
        $('#oc-pay-payer').addEventListener('change', togglePayCodNote);
        document.querySelectorAll('[data-oc-close]').forEach(function (el) { el.addEventListener('click', closeModals); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });
    }

    if (!CFG.isAdmin) { document.body.classList.add('oc-no-admin'); }
    document.body.classList.toggle('oc-hide-manual-price', !!SET.hide_manual_price);
    document.body.classList.toggle('oc-hide-line-discount', !!SET.hide_line_discount);
    initSelects();
    bind();
    if ($('#oc-ai-order')) { setMode(storedMode()); }
    if (state.editOrderId) { loadOrder(state.editOrderId); }
})();

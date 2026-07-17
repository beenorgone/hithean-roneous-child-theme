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
        lastOrder: null
    };

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
        Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
        return fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
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
            shipping_title: $('#oc-shipping-cost').value.trim() ? $('#oc-shipping-title').value.trim() : '',
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
            suppress_email: $('#oc-suppress-email').checked
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
        vnSetAddress($('#oc-bill-state'), $('#oc-bill-city'), b.state || '', b.city || '');
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
        var listEl = $('#oc-coupon-list');
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
            row.innerHTML =
                '<span><b>#' + order.number + '</b> — ' + order.customer +
                '<small>' + (order.phone || '') + ' · ' + order.date + ' · ' + order.status + '</small></span>' +
                '<div class="oc-order-result__actions"><button type="button" class="oc-btn oc-btn--ghost">Xem đơn</button><button type="button" class="oc-btn oc-btn--ghost">Xem hóa đơn</button><button type="button" class="oc-btn oc-btn--ghost">Chỉnh sửa</button><button type="button" class="oc-btn oc-btn--ghost">Copy</button></div>';
            var buttons = row.querySelectorAll('button');
            buttons[0].addEventListener('click', function () { if (order.edit_url) { window.open(order.edit_url, '_blank', 'noopener'); } });
            buttons[1].addEventListener('click', function () { if (order.invoice_url) { openInvoice(order.invoice_url); box.hidden = true; } });
            buttons[2].addEventListener('click', function () { loadOrder(order.id); box.hidden = true; });
            buttons[3].addEventListener('click', function () { loadOrder(order.id, true); box.hidden = true; });
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
            state.items = (o.items || []).map(function (it) {
                return { product_id: it.product_id, variation_id: it.variation_id || 0, name: it.name, qty: it.qty, manual_price: '', line_discount: 0 };
            });
            state.coupons = (o.coupons || []).slice();
            state.couponStatus = {};
            state._couponNotified = {};
            state.fees = (o.fees || []).slice();
            state.shipping_method = o.shipping_method || '';
            $('#oc-shipping-cost').value = o.shipping_cost || '';
            $('#oc-shipping-title').value = o.shipping_title || '';
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
        document.querySelectorAll('#oc-pane-create .oc-grid input, #oc-pane-create .oc-grid select, #oc-pane-create .oc-grid textarea, #oc-pane-create .oc-grid button').forEach(function (el) {
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
            o.value = w.name; o.textContent = w.name;
            sel.appendChild(o);
        });
        if (selected) {
            sel.value = selected;
            if (sel.value !== selected) { // giá trị cũ không còn trong danh mục → giữ lại, không làm mất dữ liệu
                var legacy = document.createElement('option');
                legacy.value = selected; legacy.textContent = selected + ' (cũ)';
                sel.appendChild(legacy);
                sel.value = selected;
            }
        }
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
        }).catch(function () {
            btn.disabled = false;
            $('#oc-new-result').textContent = '❌ Không thể lưu khách hàng.';
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
        $('#oc-cust-ai-text').value = '';
        $('#oc-cust-ai-status').textContent = '';
        aiClearImage();
    }

    function aiExtractCustomer() {
        var text = $('#oc-cust-ai-text').value.trim();
        if (!text && !aiCustImage) { $('#oc-cust-ai-status').textContent = 'Dán dữ liệu hoặc chọn ảnh trước.'; return; }
        var btn = $('#oc-cust-ai-run');
        btn.disabled = true;
        $('#oc-cust-ai-status').textContent = '⏳ Đang bóc tách bằng AI...';
        var params = { text: text };
        if (aiCustImage) { params.image = aiCustImage; }
        post('order_creator_ai_extract_customer', params).then(function (res) {
            btn.disabled = false;
            if (!res.success) { $('#oc-cust-ai-status').textContent = '❌ ' + ((res.data && res.data.message) || 'Bóc tách thất bại.'); return; }
            var f = (res.data && res.data.fields) || {};
            var un = (res.data && res.data.unmatched) || {};
            var filled = 0;
            ['first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2'].forEach(function (k) {
                var el = $('#oc-cust-' + k);
                if (el && f[k]) { el.value = f[k]; filled++; }
            });
            if (f.state) { filled++; }
            if (f.city) { filled++; }
            custSetAddress(f.state || '', f.city || '').then(function () {
                var msg = filled
                    ? '✅ Đã điền ' + filled + ' trường — kiểm tra lại trước khi lưu.'
                    : 'Không tìm thấy thông tin khách trong dữ liệu.';
                var warn = [];
                if (un.state) { warn.push('Tỉnh/Thành "' + un.state + '"'); }
                if (un.city) { warn.push('Phường/Xã "' + un.city + '"'); }
                if (warn.length) { msg += ' ⚠️ Không khớp danh mục: ' + warn.join(', ') + ' — vui lòng chọn tay.'; }
                $('#oc-cust-ai-status').textContent = msg;
            });
        }).catch(function () {
            btn.disabled = false;
            $('#oc-cust-ai-status').textContent = '❌ Không gọi được AI, thử lại sau.';
        });
    }

    // ---------- recalc ----------
    var recalcTimer;
    function recalc() {
        clearTimeout(recalcTimer);
        recalcTimer = setTimeout(doRecalc, 250);
    }

    function doRecalc() {
        if (!state.items.length) { renderLines(); updateTotals(null); return; }
        post('order_creator_recalculate', { payload: JSON.stringify(buildPayload()) }).then(function (res) {
            if (!res.success) { console.warn(res.data && res.data.message); return; }
            renderLines(res.data);
            updateTotals(res.data);
            updateShippingOptions(res.data);
            applyCouponStatus(res.data);
        });
    }

    function updateTotals(snap) {
        var keys = ['subtotal', 'discount_total', 'fee_total', 'shipping_total', 'tax_total', 'total'];
        keys.forEach(function (k) {
            var el = $('#oc-totals [data-total="' + k + '"]');
            if (el) el.textContent = money(snap ? snap[k] : 0);
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

    function selectedShippingTitle() {
        var opt = $('#oc-shipping-method').selectedOptions[0];
        if (!opt || !opt.value) { return ''; }
        return opt.textContent.replace(/\s+—\s+.*$/, '').trim();
    }

    function syncShippingTitleField(fillWhenEmpty) {
        var input = $('#oc-shipping-title');
        var hasCustomFee = !!$('#oc-shipping-cost').value.trim();
        input.disabled = !hasCustomFee;
        if (!hasCustomFee) {
            input.value = '';
            input.placeholder = 'Bật khi nhập phí ship điều chỉnh';
            return;
        }
        input.placeholder = selectedShippingTitle() || 'Nhập tên hiển thị trên đơn';
        if ((fillWhenEmpty || !input.value.trim()) && selectedShippingTitle()) {
            input.value = selectedShippingTitle();
        }
    }

    // ---------- create order ----------
    function createOrder(isDraft) {
        if (!state.items.length) { alert('Chưa có sản phẩm.'); return; }
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
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = 'Khách đã xác nhận';
                alert((res.data && res.data.message) || 'Không chuyển được trạng thái đơn.');
                return;
            }
            state.lastOrder = Object.assign({}, state.lastOrder, res.data);
            renderResult(state.lastOrder, false, '✅ Khách đã xác nhận, đơn chuyển sang ' + (res.data.status_label || res.data.status) + ' ');
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Khách đã xác nhận';
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
        document.querySelectorAll('.oc-tab').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === tab); });
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

    // ---------- payment modal (reuse confirm_order_payment) ----------
    function openPayModal(o) {
        $('#oc-pay-info').value = 'Đơn #' + o.order_number + ' | SĐT: ' + (o.phone || '') + ' | Tổng: ' + money(o.total);
        $('#oc-pay-amount').value = o.payment_total;
        $('#oc-pay-date').value = new Date().toISOString().split('T')[0];
        $('#oc-pay-payer').value = 'customer';
        $('#oc-pay-codnote').value = '';
        $('#oc-pay-result').textContent = '';
        openModal('#oc-pay-modal');
    }

    function confirmPayment() {
        if (!state.lastOrder) return;
        var payer = $('#oc-pay-payer').value;
        var btn = $('#oc-pay-confirm');
        btn.disabled = true; btn.textContent = 'Đang xử lý...';
        post('confirm_order_payment', {
            order_ids: state.lastOrder.order_id,
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

    function togglePayCodNote() {
        var v = $('#oc-pay-payer').value;
        $('#oc-pay-codnote-wrap').style.display = (v === 'shipper' || v === 'self') ? 'block' : 'none';
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
        $('#oc-coupon-add').addEventListener('click', function () { addCoupon($('#oc-coupon-input').value); $('#oc-coupon-input').value = ''; });
        $('#oc-fee-add').addEventListener('click', addFee);
        $('#oc-shipping-method').addEventListener('change', function () { state.shipping_method = this.value; syncShippingTitleField(true); recalc(); });
        $('#oc-shipping-cost').addEventListener('change', function () { syncShippingTitleField(true); recalc(); });
        $('#oc-ship-diff').addEventListener('change', function () {
            $('#oc-ship-fields').hidden = !this.checked;
            if (this.checked) { copyBillingToShipping(); }
            recalc();
        });
        $('#oc-ship-load-btn').addEventListener('click', loadShippingFromOrder);
        ['oc-bill-state', 'oc-bill-city', 'oc-bill-address', 'oc-ship-state', 'oc-ship-city', 'oc-ship-address'].forEach(function (id) {
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
        if ($('#oc-cust-ai-toggle')) { // panel AI chỉ render khi feature bật trong Cài đặt ERP
            $('#oc-cust-ai-toggle').addEventListener('click', function () {
                var panel = $('#oc-cust-ai-panel');
                panel.hidden = !panel.hidden;
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
        document.querySelectorAll('.oc-tab').forEach(function (b) { b.addEventListener('click', function () { switchTab(b.dataset.tab); }); });
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
    if (state.editOrderId) { loadOrder(state.editOrderId); }
})();

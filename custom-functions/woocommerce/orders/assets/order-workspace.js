/* THEAN – Bảng điều phối đơn (trang sửa đơn). */
(function () {
    'use strict';

    var root = document.getElementById('thean-order-workspace');
    if (!root) return;

    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    var toastEl = root.querySelector('.tow-toast');
    var modalEl = root.querySelector('.tow-modal');
    var toastTimer, modalResolve, modalReturnFocus, modalNode, modalOnClose;
    var currentTab = '';
    var isTouch = window.matchMedia('(pointer: coarse)').matches;

    // Metabox "Thông tin bổ sung" (id theo Meta Box — order-metabox.php) ẩn mặc định, nút "Form đầy đủ" bật lại.
    function metabox() { return cfg.metabox ? document.getElementById(cfg.metabox) : null; }
    if (metabox()) metabox().classList.add('tow-metabox');
    document.body.classList.add('tow-hide-metabox');

    function inner() { return root.querySelector('.tow-inner'); }

    /** Bỏ thẻ HTML trong thông báo server (DOMParser không chạy script/onerror). */
    function plain(text) {
        text = String(text == null ? '' : text);
        if (text.indexOf('<') === -1) return text;
        return (new DOMParser().parseFromString(text, 'text/html').body.textContent || '').trim();
    }

    function toast(msg, isError) {
        toastEl.textContent = plain(msg);
        toastEl.classList.toggle('is-error', !!isError);
        toastEl.classList.add('is-show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-show'); }, isError ? 6000 : 3500);
    }

    // ---------- Modal (thay window.confirm + nội dung "Xem thêm") ----------

    /**
     * opts: { title, html | text | node, actions: [{ label, value, primary, danger }], onClose(value) } → Promise(value | false).
     * node: phần tử trong bảng được chuyển tạm vào modal, đóng modal thì trả về chỗ cũ.
     * onClose: chạy ngay khi đóng (trước khi trả node về), không đợi Promise.
     */
    function modal(opts) {
        if (modalResolve) { finishModal(false); modalResolve(false); }
        modalOnClose = opts.onClose || null;
        modalEl.querySelector('.tow-modal__title').textContent = opts.title || '';
        var body = modalEl.querySelector('.tow-modal__body');
        body.classList.toggle('is-node', !!opts.node);
        if (opts.node) {
            body.textContent = '';
            modalNode = { node: opts.node, mark: document.createComment('tow-modal') };
            opts.node.parentNode.insertBefore(modalNode.mark, opts.node);
            opts.node.hidden = false;
            body.appendChild(opts.node);
        } else if (opts.html) body.innerHTML = opts.html; else body.textContent = opts.text || '';
        var foot = modalEl.querySelector('.tow-modal__foot');
        foot.innerHTML = '';
        (opts.actions || [{ label: 'Đóng', value: false }]).forEach(function (a) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button' + (a.primary ? ' button-primary' : '') + (a.danger ? ' tow-btn-danger' : '');
            b.textContent = a.label;
            b.addEventListener('click', function () { closeModal(a.value); });
            foot.appendChild(b);
        });
        modalReturnFocus = document.activeElement;
        modalEl.hidden = false;
        document.body.classList.add('tow-modal-open');
        var focus = (!isTouch && body.querySelector('.tow-filter')) || foot.querySelector('.button-primary, .tow-btn-danger') || foot.querySelector('button');
        if (focus) focus.focus();
        return new Promise(function (resolve) { modalResolve = resolve; });
    }

    function restoreNode() {
        if (!modalNode) return;
        modalNode.node.hidden = true;
        modalNode.mark.replaceWith(modalNode.node);
        modalNode = null;
    }

    function finishModal(value) {
        var cb = modalOnClose;
        modalOnClose = null;
        if (cb) cb(value);
        restoreNode();
    }

    function closeModal(value) {
        if (modalEl.hidden) return;
        finishModal(value);
        modalEl.hidden = true;
        document.body.classList.remove('tow-modal-open');
        var resolve = modalResolve;
        modalResolve = null;
        if (resolve) resolve(value);
        if (modalReturnFocus && document.contains(modalReturnFocus)) modalReturnFocus.focus();
    }

    function confirmBox(question, danger) {
        return modal({
            title: danger ? '⚠️ Xác nhận thao tác' : 'Xác nhận',
            text: question,
            actions: [
                { label: 'Hủy', value: false },
                { label: danger ? 'Đồng ý' : 'Xác nhận', value: true, primary: !danger, danger: !!danger }
            ]
        });
    }

    modalEl.addEventListener('click', function (e) {
        if (e.target === modalEl || e.target.closest('[data-modal-close]')) closeModal(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modalEl.hidden) { e.preventDefault(); closeModal(false); }
    });

    // ---------- AJAX ----------

    function messageOf(json, fallback) {
        var d = json && json.data;
        if (typeof d === 'string') return d;
        if (d && d.message) return d.message;
        return fallback;
    }

    /** Gửi POST, trả về json.data (lỗi → throw Error với thông báo server). */
    function post(data, files, fileField) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
            else if (v && typeof v === 'object') Object.keys(v).forEach(function (f) {
                [].concat(v[f]).forEach(function (x) { fd.append(k + '[' + f + '][]', x); });
            });
            else fd.append(k, v == null ? '' : v);
        });
        (files || []).forEach(function (f) { fd.append(fileField + '[]', f); });
        return fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
            .then(function (json) {
                if (!json || !json.success) throw new Error(messageOf(json, 'Thao tác thất bại.'));
                return json.data;
            });
    }

    /**
     * Lấy HTML bảng mới + giá trị hiện tại của đơn, gán lại vào form sửa đơn
     * để bấm "Cập nhật" không ghi đè dữ liệu vừa lưu bằng giá trị cũ.
     */
    function refresh(prevTab, prevDone) {
        return post({ action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'render' })
            .then(function (data) {
                var tpl = document.createElement('template');
                tpl.innerHTML = data.html.trim();
                inner().replaceWith(tpl.content.firstElementChild);
                syncPage(data.sync || {});
                // Bước đang xem vừa xong → nhảy sang bước cần làm tiếp; còn lại giữ tab đang xem.
                var tab = prevTab && inner().querySelector('.tow-tab[data-tab="' + prevTab + '"]');
                var nowDone = tab && tab.classList.contains('is-done');
                init(tab && !(nowDone && !prevDone) ? prevTab : '');
            });
    }

    function syncPage(sync) {
        var $ = window.jQuery;
        var status = document.getElementById('order_status');
        if (status && sync.status) {
            var v = 'wc-' + sync.status;
            if (Array.prototype.some.call(status.options, function (o) { return o.value === v; })) {
                status.value = v;
                if ($) $(status).trigger('change.select2');
            }
        }
        var box = metabox();
        if (!box || !sync.fields) return;
        Object.keys(sync.fields).forEach(function (key) {
            var vals = [].concat(sync.fields[key]).map(String);
            var esc = window.CSS && CSS.escape ? CSS.escape(key) : key;
            box.querySelectorAll('[name="' + esc + '"], [name="' + esc + '[]"]').forEach(function (el) {
                if (el.type === 'hidden') return;
                if (el.tagName === 'SELECT') {
                    vals.forEach(function (x) {
                        if (x !== '' && !Array.prototype.some.call(el.options, function (o) { return o.value === x; })) el.add(new Option(x, x));
                    });
                    Array.prototype.forEach.call(el.options, function (o) { o.selected = vals.indexOf(o.value) !== -1; });
                    if ($) $(el).trigger('change.select2');
                } else if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = vals.indexOf(el.value) !== -1;
                } else {
                    el.value = vals.join(', ');
                }
            });
        });
    }

    // ---------- Thao tác ----------

    /** Lấy giá trị theo data-p trong form. */
    function val(form, key) {
        var el = form.querySelector('[data-p="' + key + '"]');
        if (!el) return '';
        if (el.type === 'radio') return (form.querySelector('[data-p="' + key + '"]:checked') || {}).value || '';
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        return el.value.trim();
    }

    function selectedFiles(form) {
        var input = form.querySelector('.tow-file__input');
        var files = input ? Array.prototype.slice.call(input.files || []) : [];
        var max = input ? parseInt(input.getAttribute('data-max'), 10) || 5 : 5;
        if (!files.length) throw new Error('Chưa chọn ảnh.');
        if (files.length > max) throw new Error('Tối đa ' + max + ' ảnh mỗi lần.');
        return files;
    }

    // Thao tác trả về dữ liệu POST (+file) cho endpoint tương ứng.
    var actions = {
        payment: function (form) {
            var payer = (form.querySelector('input[name="tow_payer"]:checked') || {}).value || form.getAttribute('data-payer') || 'customer';
            return {
                confirm: 'Xác nhận thanh toán cho đơn này?',
                data: {
                    action: 'confirm_order_payment', nonce: cfg.nonces.payment, order_ids: cfg.orderId, payer: payer,
                    bank_account: payer === 'customer' ? val(form, 'bank_account') : '',
                    paid_date: payer === 'customer' ? val(form, 'paid_date') : '',
                    amount_received: val(form, 'amount_received') || 0,
                    cod_note: payer === 'customer' ? '' : val(form, 'cod_note')
                }
            };
        },
        export_upload: function (form) {
            return {
                files: selectedFiles(form), fileField: 'ueif_images',
                data: { action: 'ajax_upload_images', nonce: cfg.nonces.exportUpload, ueif_order_id: cfg.orderId, ueif_ai_check: val(form, 'ai') }
            };
        },
        export_confirm: function () {
            return {
                confirm: 'Xác nhận đã xuất kho đơn này?',
                data: { action: 'ajax_confirm_export', nonce: cfg.nonces.exportConfirm, uexe_order_id: cfg.orderId }
            };
        },
        set_meta: function (form) {
            form.querySelectorAll('.tow-tags').forEach(function (t) { commitTag(t); });
            var fields = {};
            form.querySelectorAll('[data-field]').forEach(function (el) {
                var key = el.getAttribute('data-field');
                if (el.type === 'checkbox') {
                    fields[key] = fields[key] || [];
                    if (el.checked) fields[key].push(el.value);
                } else {
                    fields[key] = el.value.trim();
                }
            });
            // Bỏ chọn hết → gửi [''] để server xóa field.
            Object.keys(fields).forEach(function (k) { if (Array.isArray(fields[k]) && !fields[k].length) fields[k] = ['']; });
            return { data: { action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'set_meta', fields: fields } };
        },
        set_status: function (form, btn) {
            return {
                confirm: 'Chuyển trạng thái đơn sang "' + btn.textContent.trim() + '"?',
                data: { action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'set_status', status: btn.getAttribute('data-status') }
            };
        },
        return_request: function (form, btn) {
            return {
                confirm: 'Tạo yêu cầu hoàn hàng: ' + btn.getAttribute('data-status') + '?',
                data: { action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'return_request', status: btn.getAttribute('data-status') }
            };
        },
        cod_confirm: function (form, btn) {
            var result = btn.getAttribute('data-result');
            return {
                confirm: result === 'require' ? '' : btn.textContent.trim() + '?',
                data: { action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'cod_confirm', result: result }
            };
        },
        to_bacs: function () {
            return {
                confirm: 'Đổi đơn COD sang Chuyển khoản và chuyển về Tạm giữ chờ khách CK?',
                data: { action: 'thean_order_workspace', nonce: cfg.nonces.workspace, order_id: cfg.orderId, task: 'to_bacs' }
            };
        },
        return_process: function (form, btn) {
            return {
                confirm: btn.textContent.trim() + '?',
                data: { action: 'process_return_order', nonce: cfg.nonces.returnProcess, order_id: cfg.orderId, return_code: val(form, 'return_code'), return_note: val(form, 'return_note') }
            };
        },
        return_receive: function (form) {
            return {
                files: selectedFiles(form), fileField: 'images',
                data: { action: 'upload_return_images', nonce: cfg.nonces.returnUpload, order_id: cfg.orderId, has_issue: val(form, 'has_issue') ? 1 : 0 }
            };
        }
    };

    var busy = false;

    function run(btn) {
        if (busy) return;
        var name = btn.getAttribute('data-action');
        var form = btn.closest('.tow-form') || btn.closest('.tow-box');
        var req;
        try { req = actions[name](form, btn); } catch (e) { toast(e.message, true); return; }

        if (btn.hasAttribute('data-noconfirm')) req.confirm = '';
        (req.confirm ? confirmBox(req.confirm, btn.hasAttribute('data-danger')) : Promise.resolve(true)).then(function (ok) {
            if (!ok) return;
            var prevTab = currentTab;
            var tab = inner().querySelector('.tow-tab[data-tab="' + prevTab + '"]');
            var prevDone = !!(tab && tab.classList.contains('is-done'));
            busy = true;
            btn.classList.add('is-busy');
            root.classList.add('is-loading');
            post(req.data, req.files, req.fileField)
                .then(function (data) {
                    var msg = messageOf({ data: data }, 'Đã lưu.');
                    return refresh(prevTab, prevDone).then(
                        function () { toast(msg); },
                        function () {
                            // Không lấy được bảng mới → tải lại trang để form sửa đơn không giữ giá trị cũ.
                            toast(msg + ' — đang tải lại trang…');
                            window.onbeforeunload = null;
                            if (window.jQuery) window.jQuery(window).off('beforeunload');
                            setTimeout(function () { window.location.reload(); }, 900);
                        }
                    );
                })
                .catch(function (e) { toast(e.message, true); btn.classList.remove('is-busy'); })
                .then(function () { busy = false; root.classList.remove('is-loading'); });
        });
    }

    // ---------- Tab bước xử lý ----------

    function activate(key, scroll) {
        var el = inner();
        var tabs = el.querySelectorAll('.tow-tab');
        if (!el.querySelector('.tow-tab[data-tab="' + key + '"]')) return false;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === key;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        el.querySelectorAll('.tow-panel').forEach(function (p) {
            var on = p.getAttribute('data-step') === key;
            p.hidden = !on;
            if (on) p.querySelectorAll(':scope > details.tow-box').forEach(function (d) { d.open = true; });
        });
        currentTab = key;
        try { history.replaceState(null, '', '#step-' + key); } catch (e) { /* noop */ }
        var active = el.querySelector('.tow-tab.is-active');
        if (active) active.parentNode.scrollLeft = active.offsetLeft - 8;
        if (scroll) el.querySelector('.tow-steps').scrollIntoView({ behavior: 'smooth', block: 'start' });
        return true;
    }

    /** Đi tới bước (tab) hoặc box ở cột phụ. */
    function goTo(key) {
        if (activate(key, true)) return;
        var box = document.getElementById('tow-' + key);
        if (box) { box.open = true; box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }

    // ---------- Chip / tag / ngày ----------

    function shortCode(code) {
        var parts = code.split('.');
        return parts[parts.length - 1] || code;
    }

    function splitCodes(text) {
        return String(text || '').split(/[\s,;|]+/).filter(Boolean);
    }

    function renderTags(wrap) {
        var hidden = wrap.querySelector('input[type="hidden"]');
        var input = wrap.querySelector('.tow-tags__input');
        wrap.querySelectorAll('.tow-tag').forEach(function (t) { t.remove(); });
        splitCodes(hidden.value).forEach(function (code, i) {
            var tag = document.createElement('span');
            tag.className = 'tow-tag';
            tag.title = code;
            tag.textContent = shortCode(code);
            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'tow-tag__x';
            x.setAttribute('data-tag-index', i);
            x.setAttribute('aria-label', 'Xóa mã ' + code);
            x.textContent = '×';
            tag.appendChild(x);
            wrap.insertBefore(tag, input);
        });
    }

    /** Thêm mã đang gõ vào danh sách (bỏ trùng theo phần sau dấu chấm như warehouse-export-workflow). */
    function commitTag(wrap) {
        var hidden = wrap.querySelector('input[type="hidden"]');
        var input = wrap.querySelector('.tow-tags__input');
        var codes = splitCodes(hidden.value);
        var keys = codes.map(function (c) { return shortCode(c).toUpperCase(); });
        splitCodes(input.value).forEach(function (c) {
            if (keys.indexOf(shortCode(c).toUpperCase()) === -1) { codes.push(c); keys.push(shortCode(c).toUpperCase()); }
        });
        input.value = '';
        hidden.value = codes.join(', ');
        renderTags(wrap);
    }

    function removeTag(wrap, index) {
        var hidden = wrap.querySelector('input[type="hidden"]');
        var codes = splitCodes(hidden.value);
        codes.splice(index, 1);
        hidden.value = codes.join(', ');
        renderTags(wrap);
    }

    function localDate(offsetDays) {
        var d = new Date();
        d.setDate(d.getDate() + offsetDays);
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    function fold(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
    }

    function filterPicker(picker, query) {
        var q = fold(query.trim());
        picker.querySelectorAll('.tow-pgroup').forEach(function (g) {
            var any = false;
            g.querySelectorAll('.tow-pill').forEach(function (p) {
                var show = !q || fold(p.textContent).indexOf(q) !== -1;
                p.hidden = !show;
                any = any || show;
            });
            g.hidden = !any;
            if (q && any && g.tagName === 'DETAILS') g.open = true;
        });
    }

    function updatePickerCount(picker) {
        var n = picker.querySelectorAll('.tow-pill input:checked').length;
        var el = picker.querySelector('.tow-picker__count');
        if (el) el.textContent = n ? 'Đã chọn ' + n : '';
    }

    function syncPayer(form) {
        var payer = (form.querySelector('input[name="tow_payer"]:checked') || {}).value || form.getAttribute('data-payer');
        form.querySelectorAll('[data-for]').forEach(function (el) {
            el.hidden = el.getAttribute('data-for') === 'customer' ? payer !== 'customer' : payer === 'customer';
        });
    }

    // ---------- Xác nhận CK ở hub (khớp giao dịch hàng đợi Sepay) ----------

    /** Mở widget hub [eim_payment_hub] (popup dùng chung — payment-hub-link.php); đóng popup → render lại bảng. */
    function openHubPay(btn) {
        if (busy) return;
        if (!window.hubPay) { toast('Chưa tải được công cụ xác nhận ở hub — tải lại trang.', true); return; }
        var form = btn.closest('.tow-form');
        var payer = (form.querySelector('input[name="tow_payer"]:checked') || {}).value || form.getAttribute('data-payer') || '';
        var prevTab = currentTab;
        var tab = inner().querySelector('.tow-tab[data-tab="' + prevTab + '"]');
        var prevDone = !!(tab && tab.classList.contains('is-done'));
        busy = true;
        btn.classList.add('is-busy');
        window.hubPay.open({
            orderId: cfg.orderId,
            payer: payer,
            onDone: function () {
                root.classList.add('is-loading');
                refresh(prevTab, prevDone)
                    .catch(function (e) { toast(e.message, true); })
                    .then(function () { root.classList.remove('is-loading'); });
            }
        })
            .catch(function (e) { toast(e.message, true); })
            .then(function () { busy = false; btn.classList.remove('is-busy'); });
    }

    // ---------- Chọn trong modal (nhóm > 3 lựa chọn) ----------

    function badge(text, tone) {
        var b = document.createElement('span');
        b.className = 'tow-badge tow-badge--' + tone;
        b.textContent = text;
        return b;
    }

    /** Vẽ lại tóm tắt các lựa chọn đang tick trên nút mở modal. */
    function updateChoice(choice, panel) {
        var out = choice.querySelector('.tow-choice__value');
        var tone = choice.getAttribute('data-tone') || 'neutral';
        out.textContent = '';
        (panel || choice.querySelector('.tow-choice__panel')).querySelectorAll('input:checked').forEach(function (input) {
            var pill = input.closest('.tow-pill');
            var text = (pill ? pill.querySelector('span') : input.parentNode).textContent.trim();
            out.appendChild(badge(text, pill && pill.classList.contains('tow-pill--danger') ? 'danger' : tone));
        });
        if (!out.childNodes.length) {
            var empty = document.createElement('span');
            empty.className = 'tow-muted';
            empty.textContent = choice.getAttribute('data-empty') || 'Chưa chọn';
            out.appendChild(empty);
        }
    }

    function openChoice(choice) {
        var panel = choice.querySelector('.tow-choice__panel');
        var inputs = panel.querySelectorAll('input[type="checkbox"], input[type="radio"]');
        var before = Array.prototype.map.call(inputs, function (i) { return i.checked; });
        modal({
            title: choice.getAttribute('data-title'),
            node: panel,
            actions: [{ label: 'Hủy', value: false }, { label: 'Xong', value: true, primary: true }],
            onClose: function (ok) {
                // Hủy / Esc / bấm nền: trả lại lựa chọn như trước khi mở.
                if (!ok) inputs.forEach(function (i, n) { i.checked = before[n]; });
                var filter = panel.querySelector('.tow-filter');
                if (filter && filter.value) { filter.value = ''; filterPicker(filter.closest('.tow-picker'), ''); }
                panel.querySelectorAll('.tow-picker').forEach(updatePickerCount);
                updateChoice(choice, panel);
            }
        });
    }

    function openMenu(menu) {
        modal({ title: menu.getAttribute('data-title'), node: menu.querySelector('.tow-menu__panel'), actions: [{ label: 'Đóng', value: false }] });
    }

    // ---------- Copy ----------

    function copyText(text, okMsg) {
        var done = function () { toast(okMsg || 'Đã copy'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { toast('Không copy được', true); });
            return;
        }
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { toast('Không copy được', true); }
        ta.remove();
    }

    function hdsdText(body) {
        return body.getAttribute('data-title').toUpperCase() + '\n' + body.innerText.trim();
    }

    // ---------- Sự kiện (delegate trên root: bảng được thay HTML sau mỗi thao tác) ----------

    root.addEventListener('click', function (e) {
        if (e.target.closest('.tow-modal')) {
            // Thao tác chọn trong modal (VD chuyển trạng thái): đóng modal, trả nút về form rồi chạy.
            var act = e.target.closest('.tow-modal__body .tow-act');
            if (act) { e.preventDefault(); closeModal(true); run(act); }
            return;
        }

        var hubPay = e.target.closest('.tow-hub-pay');
        if (hubPay) { e.preventDefault(); openHubPay(hubPay); return; }

        var choice = e.target.closest('.tow-choice__open');
        if (choice) { e.preventDefault(); openChoice(choice.closest('.tow-choice')); return; }
        var menu = e.target.closest('.tow-menu__open');
        if (menu) { e.preventDefault(); openMenu(menu.closest('.tow-menu')); return; }

        var tab = e.target.closest('.tow-tab');
        if (tab) { activate(tab.getAttribute('data-tab')); return; }

        var go = e.target.closest('[data-goto]');
        if (go) { e.preventDefault(); goTo(go.getAttribute('data-goto')); return; }

        // Box của bước đang mở trong tab: không cho gập.
        var head = e.target.closest('.tow-panel > .tow-box > .tow-box__head');
        if (head) { e.preventDefault(); return; }

        var btn = e.target.closest('.tow-act');
        if (btn) { e.preventDefault(); run(btn); return; }

        var more = e.target.closest('.tow-more');
        if (more) {
            var hint = (cfg.hints || {})[more.getAttribute('data-hint')];
            if (hint) modal({ title: hint.title, html: '<ul class="tow-modal__list"><li>' + hint.items.join('</li><li>') + '</li></ul>' });
            return;
        }

        var x = e.target.closest('.tow-tag__x');
        if (x) { removeTag(x.closest('.tow-tags'), parseInt(x.getAttribute('data-tag-index'), 10)); return; }
        var tags = e.target.closest('.tow-tags');
        if (tags && e.target === tags) { tags.querySelector('.tow-tags__input').focus(); return; }

        var dateBtn = e.target.closest('[data-date-set]');
        if (dateBtn) {
            var input = dateBtn.closest('.tow-datefield').querySelector('input[type="date"]');
            var off = dateBtn.getAttribute('data-date-set');
            input.value = off === '' ? '' : localDate(parseInt(off, 10));
            return;
        }

        var copy = e.target.closest('[data-copy]');
        // Trên điện thoại link sms: mở app SMS; trên máy tính copy nội dung.
        if (copy && !(isTouch && copy.tagName === 'A')) {
            e.preventDefault();
            copyText(copy.getAttribute('data-copy'));
            return;
        }

        if (e.target.closest('.tow-copy')) {
            copyText(hdsdText(e.target.closest('.tow-hdsd').querySelector('.tow-hdsd__body')), 'Đã copy HDSD');
            return;
        }
        if (e.target.closest('.tow-copy-all')) {
            var all = Array.prototype.map.call(root.querySelectorAll('.tow-hdsd__body'), hdsdText).join('\n\n');
            copyText(all, 'Đã copy toàn bộ HDSD');
            return;
        }

        var toggle = e.target.closest('.tow-toggle-metabox');
        if (toggle) {
            var hidden = document.body.classList.toggle('tow-hide-metabox');
            toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            var mb = metabox();
            if (!hidden && mb) {
                mb.classList.remove('closed');
                mb.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });

    root.addEventListener('keydown', function (e) {
        if (e.target.matches('.tow-tags__input')) {
            if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commitTag(e.target.closest('.tow-tags')); }
            else if (e.key === 'Backspace' && !e.target.value) {
                var wrap = e.target.closest('.tow-tags');
                var n = splitCodes(wrap.querySelector('input[type="hidden"]').value).length;
                if (n) removeTag(wrap, n - 1);
            }
            return;
        }
        // Enter trong ô nhập của bảng điều phối không được submit form sửa đơn.
        if (e.key !== 'Enter' || !e.target.matches('input')) return;
        e.preventDefault();
        if (e.target.matches('.tow-filter')) return;
        var form = e.target.closest('.tow-form');
        var btn = form && form.querySelector('.tow-act');
        if (btn) run(btn);
    });

    root.addEventListener('focusout', function (e) {
        if (e.target.matches('.tow-tags__input') && e.target.value.trim()) commitTag(e.target.closest('.tow-tags'));
    });

    root.addEventListener('input', function (e) {
        if (e.target.matches('.tow-filter')) filterPicker(e.target.closest('.tow-picker'), e.target.value);
    });

    root.addEventListener('change', function (e) {
        var t = e.target;
        var payForm = t.closest('.tow-form[data-task="payment"]');
        if (payForm) syncPayer(payForm);

        // Upload: hiện số ảnh đã chọn.
        if (t.matches('.tow-file__input')) {
            var label = t.nextElementSibling;
            if (!label.hasAttribute('data-original')) label.setAttribute('data-original', label.textContent);
            label.textContent = t.files.length ? '🖼️ Đã chọn ' + t.files.length + ' ảnh' : label.getAttribute('data-original');
        }

        var picker = t.closest('.tow-picker');
        if (picker) updatePickerCount(picker);

        // Soạn hàng: tick đủ thì báo xong.
        var pick = t.closest('.tow-pick');
        if (pick) {
            var done = Array.prototype.every.call(pick.querySelectorAll('.tow-pick__chk'), function (b) { return b.checked; });
            pick.parentNode.querySelector('.tow-pick__done').hidden = !done;
        }
    });

    /** Khởi tạo phần bảng (lần đầu và sau mỗi lần render lại). */
    function init(preferTab) {
        var el = inner();
        el.querySelectorAll('.tow-form[data-task="payment"]').forEach(syncPayer);
        el.querySelectorAll('.tow-tags').forEach(renderTags);
        el.querySelectorAll('.tow-picker').forEach(updatePickerCount);
        var toggle = el.querySelector('.tow-toggle-metabox');
        if (toggle) toggle.setAttribute('aria-expanded', document.body.classList.contains('tow-hide-metabox') ? 'false' : 'true');

        if (!(preferTab && activate(preferTab))) activate(el.getAttribute('data-active'));
    }

    // Lần đầu: ưu tiên tab trên URL (F5 giữ tab), không có thì tab server chọn (bước cần làm).
    init((location.hash.match(/^#step-([\w-]+)$/) || [])[1] || '');
})();

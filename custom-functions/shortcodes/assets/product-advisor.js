/* [product_advisor_ai] — nhân viên dán câu hỏi khách → AI soạn câu trả lời từ dữ liệu SP (theo SKU) + kịch bản tư vấn. */
(function () {
    'use strict';
    var root = document.getElementById('hpa-root');
    var cfgEl = document.getElementById('hpa-cfg');
    if (!root || !cfgEl) return;
    var cfg = JSON.parse(cfgEl.textContent || '{}');
    var $ = function (sel) { return root.querySelector(sel); };
    var form = $('[data-hpa-form]'), out = $('[data-hpa-out]'), statusEl = $('[data-hpa-status]');
    var chipsEl = $('[data-hpa-chips]'), skuIn = $('[data-hpa-sku]'), askBtn = $('[data-hpa-ask]');
    var keepEl = $('[data-hpa-keep]'), turnsEl = $('[data-hpa-turns]');
    var qIn = form.elements.namedItem('question');
    var skus = [], history = [], last = null;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') node.textContent = attrs[k];
            else if (k === 'class') node.className = attrs[k];
            else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] !== false && attrs[k] != null) node.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return node;
    }
    function status(msg, err) { statusEl.textContent = msg || ''; statusEl.classList.toggle('is-error', !!err); }
    function copy(text, btn) {
        var done = function () {
            if (!btn) return;
            var old = btn.textContent;
            btn.classList.add('is-copied'); btn.textContent = '✓ Đã copy';
            setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, function () { legacy(text); done(); });
        else { legacy(text); done(); }
    }
    function legacy(text) {
        var ta = el('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text;
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) { /* bỏ qua */ }
        ta.remove();
    }

    // Danh mục cho ô chọn SP: "SKU — Tên".
    var dl = document.getElementById('hpa-cat');
    (cfg.catalogue || []).forEach(function (c) { dl.appendChild(el('option', { value: c.sku + ' — ' + c.name })); });
    function renderChips() {
        chipsEl.innerHTML = '';
        skus.forEach(function (s, i) {
            var c = (cfg.catalogue || []).find(function (x) { return x.sku === s; });
            chipsEl.appendChild(el('span', { class: 'hpa-chip', title: c ? c.name : s }, [s + (c ? ' · ' + c.name.slice(0, 40) : ''),
                el('button', { type: 'button', 'aria-label': 'Bỏ ' + s, text: '×', onclick: function () { skus.splice(i, 1); renderChips(); } })]));
        });
    }
    function addSku(raw) {
        var v = String(raw || '').split(' — ')[0].trim();
        if (!v) return;
        var lower = v.toLowerCase();
        var hit = (cfg.catalogue || []).find(function (c) { return c.sku.toLowerCase() === lower; })
            || (cfg.catalogue || []).find(function (c) { return c.name.toLowerCase().indexOf(lower) !== -1; });
        v = hit ? hit.sku : v.toUpperCase();
        if (skus.indexOf(v) === -1 && skus.length < 10) skus.push(v);
        renderChips();
    }
    skuIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); addSku(skuIn.value); skuIn.value = ''; }
    });
    skuIn.addEventListener('change', function () { if (skuIn.value.indexOf(' — ') !== -1) { addSku(skuIn.value); skuIn.value = ''; } });

    function renderTurns() { turnsEl.textContent = history.length ? '(' + history.length + ' lượt)' : ''; }
    $('[data-hpa-reset]').addEventListener('click', function () {
        history = []; skus = []; last = null; renderChips(); renderTurns();
        qIn.value = ''; out.hidden = true; out.innerHTML = ''; status(''); qIn.focus();
    });

    function post(action, data, nonce) {
        var body = new FormData();
        body.append('action', action); body.append('nonce', nonce || cfg.nonce);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { throw new Error('Phản hồi không hợp lệ (HTTP ' + r.status + ')'); }); })
            .then(function (res) { if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'Lỗi không xác định'); return res.data; });
    }

    function card(title, body, extra, mod) {
        return el('div', { class: 'hpa-card' + (mod ? ' hpa-card--' + mod : '') }, [el('h3', {}, [el('span', { text: title }), extra || null])].concat(body));
    }
    function copyList(items) {
        return el('ul', { class: 'hpa-list hpa-list--copy' }, items.map(function (t) {
            var li = el('li', { text: t, title: 'Bấm để copy' });
            li.addEventListener('click', function () { copy(t); li.style.color = '#00843d'; setTimeout(function () { li.style.color = ''; }, 900); });
            return li;
        }));
    }
    function prodChip(p) {
        return el('a', { class: 'hpa-prod' + (p.stock === 'hết hàng' ? ' is-oos' : ''), href: p.url, target: '_blank', rel: 'noopener', title: p.stock }, [p.sku + ' · ' + p.name.slice(0, 50) + (p.stock !== 'còn hàng' ? ' (' + p.stock + ')' : '')]);
    }

    function render(d, question) {
        out.innerHTML = '';
        var reply = el('textarea', { class: 'hcf-in hpa-reply', 'aria-label': 'Tin nhắn gửi khách (sửa được trước khi copy)' });
        reply.value = d.reply;
        var copyBtn = el('button', { type: 'button', class: 'hcf-btn hcf-btn--pri', text: '📋 Copy tin nhắn' });
        copyBtn.addEventListener('click', function () { copy(reply.value, copyBtn); });
        out.appendChild(card('💬 Tin nhắn gửi khách', [reply], copyBtn, 'reply'));
        if (d.follow_up_questions && d.follow_up_questions.length) out.appendChild(card('❓ Nên hỏi thêm khách (bấm để copy)', [copyList(d.follow_up_questions)]));
        if (d.staff_notes && d.staff_notes.length) out.appendChild(card('💡 Lưu ý cho nhân viên', [el('ul', { class: 'hpa-list' }, d.staff_notes.map(function (t) { return el('li', { text: t }); }))]));
        var src = [];
        if (d.products && d.products.length) src.push(el('p', {}, [el('b', { text: 'Dữ liệu SP đã dùng: ' })].concat(d.products.map(prodChip))));
        else src.push(el('p', { class: 'hpa-meta', text: 'Chưa nhận ra SP cụ thể — AI chỉ dùng danh mục rút gọn. Thêm SKU để có thành phần / HDSD chính xác.' }));
        if (d.suggested && d.suggested.length) src.push(el('p', {}, [el('b', { text: 'AI gợi ý thêm: ' })].concat(d.suggested.map(function (p) {
            return el('button', { type: 'button', class: 'hpa-prod', title: 'Thêm SP này vào ngữ cảnh rồi soạn lại', text: '＋ ' + p.sku + ' · ' + p.name.slice(0, 40), onclick: function () {
                addSku(p.sku); qIn.value = question; form.requestSubmit();
            } });
        }))));
        if (d.flows && d.flows.length) src.push(el('p', {}, [el('b', { text: 'Kịch bản liên quan: ' })].concat(d.flows.map(function (f) {
            return el('span', { class: 'hpa-prod', text: (f.used ? '✓ ' : '') + f.title });
        }))));
        src.push(el('p', { class: 'hpa-meta', text: [d.model, d.labels_read ? 'đọc ' + d.labels_read + ' ảnh nhãn' : '', d.cached ? 'kết quả lưu sẵn' : ''].filter(Boolean).join(' · ') }));
        var actions = [];
        if (cfg.manage) {
            var saveBtn = el('button', { type: 'button', class: 'hcf-btn', text: '➕ Lưu thành kịch bản (nháp)' });
            saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true;
                var flow = { title: question.split('\n')[0].slice(0, 120), group: 'Từ AI tư vấn', status: 'draft', keywords: [], skus: (d.products || []).map(function (p) { return p.sku; }),
                    steps: [{ title: 'Trả lời', say: reply.value, note: (d.staff_notes || []).join('\n') }].concat((d.follow_up_questions || []).length ? [{ title: 'Hỏi thêm', say: d.follow_up_questions.join('\n---\n') }] : []) };
                post('hithean_cflow_save', { flow: JSON.stringify(flow) }, cfg.flowNonce).then(function () {
                    saveBtn.textContent = '✓ Đã lưu nháp — sửa từ khóa ở tab Kịch bản tư vấn';
                }).catch(function (err) { status(err.message, true); saveBtn.disabled = false; });
            });
            actions.push(saveBtn);
        }
        out.appendChild(card('📚 Nguồn dữ liệu', src.concat(actions.length ? [el('p', {}, actions)] : [])));
        out.hidden = false;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (skuIn.value.trim()) { addSku(skuIn.value); skuIn.value = ''; }
        var question = qIn.value.trim();
        if (!question) return;
        // Câu trả lời trước (bản nhân viên đã sửa) vào ngữ cảnh khi hỏi câu mới.
        if (last && keepEl.checked && last.q !== question) {
            var edited = out.querySelector('.hpa-reply');
            history.push({ q: last.q, a: edited ? edited.value : last.a });
            history = history.slice(-3);
        }
        askBtn.disabled = true; status('AI đang đọc dữ liệu sản phẩm và soạn câu trả lời…');
        post('hithean_advisor_ask', {
            question: question, skus: skus.join(','), length: form.elements.namedItem('reply_length').value, labels: form.elements.namedItem('labels').checked ? '1' : '',
            history: JSON.stringify(keepEl.checked ? history : [])
        }).then(function (d) {
            last = { q: question, a: d.reply };
            render(d, question); renderTurns(); status('');
        }).catch(function (err) { status(err.message, true); }).finally(function () { askBtn.disabled = false; });
    });
    qIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit(); }
    });
})();

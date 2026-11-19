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
    var qIn = form.elements.namedItem('question'), extraIn = form.elements.namedItem('extra');
    var faqEl = $('[data-hpa-faq]'), faqTimer = 0, faqSeq = 0;
    var skus = [], history = [], last = null, questionEditor = null, referenceEditor = null, faqEditor = null;

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
    function status(msg, err) { statusEl.textContent = msg || ''; statusEl.classList.toggle('is-error', !!err); statusEl.dispatchEvent(new Event('hcf:status')); }
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
        if (faqEditor) { faqEditor.destroy(); faqEditor = null; }
        history = []; skus = []; last = null; renderChips(); renderTurns();
        qIn.value = ''; extraIn.value = ''; faqEl.hidden = true; faqEl.innerHTML = ''; out.hidden = true; out.innerHTML = ''; status(''); qIn.focus();
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
        if (faqEditor) { faqEditor.destroy(); faqEditor = null; }
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
        if (d.faqs && d.faqs.length) src.push(el('p', {}, [el('b', { text: 'FAQ đã lưu được dùng: ' })].concat(d.faqs.map(function (f) {
            return el('span', { class: 'hpa-prod', text: (cfg.library ? (f.type === 'flow' ? '🗂️ ' : '💬 ') : '❓ ') + f.q.slice(0, 60) });
        }))));
        if (d.flows && d.flows.length) src.push(el('p', {}, [el('b', { text: 'Kịch bản liên quan: ' })].concat(d.flows.map(function (f) {
            return el('span', { class: 'hpa-prod', text: (f.used ? '✓ ' : '') + f.title });
        }))));
        src.push(el('p', { class: 'hpa-meta', text: [d.model, d.labels_read ? 'đọc ' + d.labels_read + ' ảnh nhãn' : '', d.cached ? 'kết quả lưu sẵn' : ''].filter(Boolean).join(' · ') }));
        var actions = [faqSaver(question, reply, d)];
        if (cfg.manage && !cfg.library) {
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
        out.appendChild(card('📚 Nguồn dữ liệu', src.concat([el('div', { class: 'hpa-save' }, actions)])));
        out.hidden = false;
    }


    // ── FAQ đã lưu: gợi ý khi gõ câu hỏi (copy dùng luôn, không tốn lượt AI) + lưu câu trả lời AI vào FAQ ──
    function faqCard(f) {
        var btn = el('button', { type: 'button', class: 'hcf-btn hcf-btn--sm', text: '📋 Copy' });
        var box = el('div', { class: 'hcf-say', title: 'Bấm để copy' }, [el('p', {}, [el('b', { text: (cfg.library ? (f.type === 'flow' ? '🗂️ ' : '💬 ') : '❓ ') + f.q }), el('br'), f.a]), btn]);
        box.addEventListener('click', function () {
            if (cfg.library) {
                btn.disabled = true;
                post('hithean_library_copy', { key: f.key }, cfg.flowNonce).then(function (r) {
                    if (r.errors && r.errors.length) { status(r.errors.join(' '), true); return; }
                    copy(r.texts.join('\n\n').replace(/\{ten\}/g, 'anh/chị'), btn);
                }).catch(function (err) { status(err.message, true); }).finally(function () { btn.disabled = false; });
            } else {
                copy(f.a, btn);
                post('hithean_faq_used', { id: f.id }, cfg.flowNonce).catch(function () { /* không chặn */ });
            }
        });
        return box;
    }
    function suggestFaq() {
        var text = qIn.value.trim();
        if (text.length < 8) { faqEl.hidden = true; faqEl.innerHTML = ''; return; }
        var seq = ++faqSeq;
        post(cfg.library ? 'hithean_library_suggest' : 'hithean_faq_suggest', { q: text, skus: skus.join(',') }, cfg.flowNonce).then(function (d) {
            if (seq !== faqSeq) return;
            faqEl.innerHTML = '';
            if (!d.items || !d.items.length) { faqEl.hidden = true; return; }
            faqEl.appendChild(el('p', { class: 'hpa-lbl', text: (cfg.library ? '📚 Thư viện khớp (' : '📚 FAQ đã lưu khớp (') + d.items.length + ') — dùng ngay, không cần hỏi AI:' }));
            d.items.forEach(function (f) { faqEl.appendChild(faqCard(f)); });
            faqEl.hidden = false;
        }).catch(function () { /* gợi ý không bắt buộc */ });
    }
    if (cfg.library) {
        window.addEventListener('storage', function (event) { if (event.key === 'hcf-library-updated') suggestFaq(); });
        window.addEventListener('hcf:library-updated', function (event) { if (!event.detail || event.detail.source !== 'ai') suggestFaq(); });
    }
    qIn.addEventListener('input', function () { clearTimeout(faqTimer); faqTimer = setTimeout(suggestFaq, 500); });

    function faqSaver(question, reply, d) {
        var wrap = el('div', { class: 'hpa-faqsave' });
        var open = el('button', { type: 'button', class: 'hcf-btn hcf-btn--pri', text: cfg.library ? '💾 Lưu vào thư viện' : '💾 Lưu vào FAQ' });
        open.addEventListener('click', function () {
            open.remove();
            var q = el('textarea', { class: 'hcf-in hpa-q', rows: 2, 'aria-label': 'Câu hỏi FAQ' });
            q.value = question.split('\n').filter(Boolean).slice(-1)[0].slice(0, 300);
            var a = el('textarea', { class: 'hcf-in hpa-q', rows: 4, 'aria-label': 'Câu trả lời FAQ' });
            a.value = reply.value;
            var kind = el('select', { class: 'hcf-in', 'aria-label': 'Lưu thành' }, [el('option', { value: 'faq', text: 'Hỏi đáp nhanh' })].concat(cfg.manage ? [el('option', { value: 'flow', text: 'Kịch bản' })] : []));
            var save = el('button', { type: 'button', class: 'hcf-btn hcf-btn--pri', text: '💾 Lưu' });
            save.addEventListener('click', function () {
                save.disabled = true;
                var skus = (d.products || []).map(function (p) { return p.sku; });
                var entry = { id: 0, type: kind.value, title: q.value, group: 'Từ AI tư vấn', keywords: [], skus: skus, source: 'ai', status: cfg.manage ? 'draft' : 'publish', steps: [{ title: 'Trả lời', say: a.value, note: (d.staff_notes || []).join('\n') }] };
                if (kind.value === 'flow' && (d.follow_up_questions || []).length) entry.steps.push({ title: 'Hỏi thêm', say: d.follow_up_questions.join('\n---\n'), note: '' });
                post(cfg.library ? 'hithean_library_save' : 'hithean_faq_save', cfg.library ? { entry: JSON.stringify(entry) } : { faq: JSON.stringify({ q: q.value, a: a.value, source: 'ai', skus: skus }) }, cfg.flowNonce)
                    .then(function (r) { if (cfg.library) { try { localStorage.setItem('hcf-library-updated', Date.now() + ':' + Math.random()); } catch (e) { /* storage unavailable */ } window.dispatchEvent(new CustomEvent('hcf:library-updated', { detail: { source: 'ai' } })); } if (faqEditor) { faqEditor.destroy(); faqEditor = null; } wrap.innerHTML = ''; wrap.appendChild(el('p', { class: 'hpa-meta', text: (cfg.library ? '✓ Đã lưu vào thư viện: ' : (r.updated ? '✓ Đã cập nhật FAQ #' : '✓ Đã lưu FAQ #')) + r.id + (cfg.library ? ' — xem lại ở tab 📚 Thư viện.' : ' — lần sau gõ câu tương tự sẽ hiện gợi ý.') })); })
                    .catch(function (err) { status(err.message, true); save.disabled = false; });
            });
            if (cfg.library) { wrap.appendChild(el('label', {}, ['Dạng nội dung', kind])); wrap.appendChild(el('p', { class: 'hpa-meta', text: cfg.manage ? 'Lưu nháp để duyệt trong thư viện trước khi AI dùng lại.' : 'Bạn có thể thêm hỏi đáp. Nhờ quản lý chỉnh sửa hoặc tạo kịch bản.' })); }
            wrap.appendChild(el('p', { class: 'hpa-lbl', text: 'Câu hỏi (viết gọn, chung chung để lần sau dễ khớp):' }));
            wrap.appendChild(q);
            wrap.appendChild(el('p', { class: 'hpa-lbl', text: 'Câu trả lời:' }));
            wrap.appendChild(a);
            wrap.appendChild(save);
            if (window.hcfMobileForm) faqEditor = window.hcfMobileForm(wrap, { title: cfg.library ? 'Lưu vào thư viện' : 'Lưu vào FAQ', cta: cfg.library ? '💾 Tiếp tục lưu vào thư viện' : '💾 Tiếp tục lưu FAQ', autoOpen: true, status: statusEl });
            q.focus();
        });
        wrap.appendChild(open);
        return wrap;
    }

    // ── Tài liệu tham khảo chung ──
    var refText = root.querySelector('[data-hpa-ref-text]'), refMeta = root.querySelector('[data-hpa-ref-meta]'), refSave = root.querySelector('[data-hpa-ref-save]');
    function refInfo(r) { refMeta.textContent = r && r.updated ? 'Cập nhật ' + r.updated + (r.by ? ' · ' + r.by : '') + ' · ' + (r.text || '').length + '/20000 ký tự' : 'Chưa có tài liệu.'; }
    if (refText) {
        refText.value = (cfg.reference && cfg.reference.text) || '';
        refInfo(cfg.reference);
        if (refSave) refSave.addEventListener('click', function () {
            refSave.disabled = true;
            post('hithean_consult_ref_save', { text: refText.value }, cfg.flowNonce).then(function (d) { refInfo(d.reference); status('Đã lưu tài liệu tham khảo.'); if (referenceEditor) referenceEditor.close(); })
                .catch(function (err) { status(err.message, true); }).finally(function () { refSave.disabled = false; });
        });
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
            history: JSON.stringify(keepEl.checked ? history : []), extra: extraIn.value
        }).then(function (d) {
            last = { q: question, a: d.reply };
            render(d, question); renderTurns(); status('');
            if (questionEditor) questionEditor.close();
        }).catch(function (err) { status(err.message, true); }).finally(function () { askBtn.disabled = false; });
    });
    qIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit(); }
    });
    if (window.hcfMobileForm) {
        questionEditor = window.hcfMobileForm(form, { title: 'AI tư vấn', cta: '✨ Nhập câu hỏi để tư vấn', status: statusEl });
        var reference = root.querySelector('[data-hpa-ref]');
        if (reference) referenceEditor = window.hcfMobileForm(reference, { title: 'Tài liệu tham khảo chung', cta: '📚 Tài liệu tham khảo chung', status: statusEl });
    }
})();

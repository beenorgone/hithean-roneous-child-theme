/**
 * Hồ sơ khoa học — bảng Kanban (Sản phẩm → Hồ sơ khoa học) + metabox SP.
 * Cấu hình: window.hitheanResearch (research-admin.php → hithean_research_admin_config()).
 * Payload sửa gửi base64 JSON (Wordfence chặn POST có HTML). Chuỗi người dùng chèn DOM bằng textContent.
 * Không dùng alert / confirm: thao tác nguy hiểm bấm 2 lần.
 */
(function () {
    'use strict';
    var cfg = window.hitheanResearch;
    if (!cfg) return;

    /* ---------- tiện ích ---------- */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), v);
            else if (v === true) node.setAttribute(k, '');
            else node.setAttribute(k, v);
        });
        [].concat(children || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }
    function b64(obj) {
        var bytes = new TextEncoder().encode(JSON.stringify(obj));
        var bin = '';
        for (var i = 0; i < bytes.length; i += 0x8000) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        return btoa(bin);
    }
    function post(action, fields, payload) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        if (payload) body.append('payload', b64(payload));
        return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json().catch(function () { throw new Error('Máy chủ trả lỗi (HTTP ' + r.status + ').'); }); })
            .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Thao tác không thành công.');
                return json.data;
            });
    }
    function typeLabel(t) { return (cfg.types[t] || cfg.types.other).vi; }

    /* ---------- metabox SP ---------- */
    function initProductBox() {
        var box = document.getElementById('hithean-research');
        if (!box) return;
        box.addEventListener('click', function (e) {
            var t = e.target;
            if (!(t instanceof Element)) return;
            var root = t.closest('[data-hr-product]');
            if (!root) return;
            var product = root.getAttribute('data-hr-product');
            var status = root.querySelector('[data-hr-box-status]');
            if (t.matches('[data-hr-link]')) {
                t.disabled = true;
                post('hithean_research_link', { id: t.getAttribute('data-id'), product: product, mode: t.getAttribute('data-hr-link') })
                    .then(function (d) { root.outerHTML = d.html; })
                    .catch(function (err) { t.disabled = false; status.textContent = err.message; });
            } else if (t.matches('[data-hr-box-add]')) {
                var input = root.querySelector('[data-hr-box-url]');
                if (!input.value.trim()) { status.textContent = 'Dán link trước.'; return; }
                t.disabled = true;
                status.textContent = 'Đang bóc tách…';
                post('hithean_research_ingest', {}, { url: input.value.trim(), product: Number(product) })
                    .then(function (d) {
                        return post('hithean_research_link', { id: d.id, product: product, mode: 'include' }).then(function (r) {
                            root.outerHTML = r.html;
                            var s = document.querySelector('#hithean-research [data-hr-box-status]');
                            if (s) s.textContent = d.message + ' Tiếp tục soạn tóm tắt ở bảng Hồ sơ khoa học.';
                        });
                    })
                    .catch(function (err) { t.disabled = false; status.textContent = err.message; });
            }
        });
    }

    /* ---------- bảng Kanban ---------- */
    var board = document.getElementById('hr-board');
    var drawer = document.getElementById('hr-drawer');
    var items = [];
    var current = null; // chi tiết thẻ đang mở

    function nextStep(item) {
        switch (item.status) {
            case 'inbox': return 'Đang bóc tách…';
            case 'needs_input': return item.type === 'video' ? '→ Dán transcript' : '→ Dán nội dung / tải PDF';
            case 'draft': return item.hasDraft ? '→ Đọc lại, gửi duyệt' : '→ Tạo tóm tắt AI';
            case 'review': return cfg.canPublish ? '→ Xem trước, duyệt & đăng' : 'Chờ quản lý duyệt';
            case 'published': return item.unpublishedEdits ? '→ Có bản sửa chưa đăng' : (item.links ? 'Đang hiện ở ' + item.links + ' nơi' : '→ Gắn trang để khách thấy');
            default: return '';
        }
    }

    function renderBoard() {
        var q = (document.getElementById('hr-search').value || '').trim().toLowerCase();
        var shown = items.filter(function (it) {
            return !q || [it.title, it.publisher, it.topic].join(' ').toLowerCase().indexOf(q) !== -1;
        });
        document.getElementById('hr-count').textContent = shown.length + ' tài liệu';
        board.replaceChildren();
        Object.keys(cfg.statuses).forEach(function (key) {
            var col = shown.filter(function (it) { return it.status === key; });
            var list = el('div', { class: 'hr-col__list' });
            col.forEach(function (it) {
                var warns = [];
                if (it.note && (it.status === 'needs_input' || it.status === 'inbox')) warns.push(it.note);
                if (it.stale) warns.push('Bản nháp cũ hơn nội dung gốc — tạo lại tóm tắt?');
                if (!it.links && it.status !== 'archived') warns.push('Chưa gắn trang nào');
                list.appendChild(el('button', {
                    type: 'button', class: 'hr-kc hr-kc--' + key + (current && current.id === it.id ? ' is-active' : ''),
                    'data-id': it.id, onclick: function () { openItem(it.id); }
                }, [
                    el('span', { class: 'hr-kc__chips' }, [el('span', { class: 'hr-chip', text: typeLabel(it.type) }), it.year ? el('span', { class: 'hr-kc__year', text: it.year }) : null]),
                    el('b', { class: 'hr-kc__title', text: it.title || '(chưa có tiêu đề)' }),
                    it.publisher ? el('span', { class: 'hr-kc__meta', text: it.publisher }) : null,
                    warns.length ? el('span', { class: 'hr-kc__warn', text: '⚠ ' + warns.join(' · ') }) : null,
                    el('span', { class: 'hr-kc__next', text: nextStep(it) })
                ]));
            });
            if (!col.length) list.appendChild(el('p', { class: 'hr-col__empty', text: 'Trống' }));
            board.appendChild(el('section', { class: 'hr-col hr-col--' + key, 'aria-label': cfg.statuses[key].label }, [
                el('h2', { class: 'hr-col__head' }, [cfg.statuses[key].label, el('em', { text: String(col.length) })]),
                el('p', { class: 'hr-col__hint', text: cfg.statuses[key].hint }),
                list
            ]));
        });
    }

    function loadBoard() {
        return post('hithean_research_list').then(function (d) {
            items = d.items;
            renderBoard();
        }).catch(function (err) {
            board.replaceChildren(el('p', { class: 'hr-admin__error', text: err.message }));
        });
    }

    /* ---------- dán link ---------- */
    function ingestAll() {
        var area = document.getElementById('hr-paste');
        var status = document.getElementById('hr-paste-status');
        var btn = document.getElementById('hr-paste-go');
        var urls = area.value.split(/\s+/).map(function (s) { return s.trim(); }).filter(function (s) { return /^https?:\/\//i.test(s); });
        urls = urls.filter(function (u, i) { return urls.indexOf(u) === i; }).slice(0, 20);
        if (!urls.length) { status.textContent = 'Chưa có link hợp lệ (bắt đầu bằng http:// hoặc https://).'; return; }
        btn.disabled = true;
        var done = 0, errors = [], lastId = 0;
        // Tuần tự: mỗi link có thể gọi 2–3 nguồn ngoài, tránh dồn tải lên máy chủ.
        urls.reduce(function (chain, url) {
            return chain.then(function () {
                status.textContent = 'Đang bóc tách ' + (done + 1) + '/' + urls.length + '…';
                return post('hithean_research_ingest', {}, { url: url })
                    .then(function (d) { lastId = d.id; })
                    .catch(function (err) { errors.push(url + ': ' + err.message); })
                    .then(function () { done++; });
            });
        }, Promise.resolve()).then(function () {
            btn.disabled = false;
            area.value = errors.length ? errors.map(function (e) { return e.split(': ')[0]; }).join('\n') : '';
            status.textContent = 'Xong ' + (done - errors.length) + '/' + urls.length + (errors.length ? ' — lỗi: ' + errors.join(' | ') : '');
            return loadBoard().then(function () { if (urls.length === 1 && lastId) openItem(lastId); });
        });
    }

    /* ---------- ngăn sửa ---------- */
    function field(label, input, hint) {
        return el('label', { class: 'hr-field' }, [el('span', { class: 'hr-field__label', text: label }), input, hint ? el('small', { text: hint }) : null]);
    }
    function input(name, value, attrs) {
        return el('input', Object.assign({ type: 'text', name: name, value: value || '' }, attrs || {}));
    }
    function textarea(name, value, rows, attrs) {
        var t = el('textarea', Object.assign({ name: name, rows: rows || 3 }, attrs || {}));
        t.value = value || '';
        return t;
    }
    function select(name, options, value) {
        var s = el('select', { name: name });
        options.forEach(function (o) { s.appendChild(el('option', { value: o[0], text: o[1], selected: o[0] === value })); });
        return s;
    }

    function setMessage(text, isError) {
        var box = drawer.querySelector('[data-hr-msg]');
        if (box) { box.textContent = text || ''; box.classList.toggle('is-error', !!isError); }
    }

    function busy(on) {
        drawer.classList.toggle('is-busy', on);
        drawer.querySelectorAll('button').forEach(function (b) { if (!b.hasAttribute('data-hr-keep')) b.disabled = on; });
    }

    function collect() {
        var f = drawer.querySelector('form');
        var v = function (n) { var x = f.elements[n]; return x ? x.value : ''; };
        var draft = {};
        ['vi', 'en'].forEach(function (l) {
            draft[l] = {
                headline: v(l + '_headline'), summary: v(l + '_summary'),
                points: v(l + '_points').split('\n').map(function (s) { return s.replace(/^[-•*\s]+/, '').trim(); }).filter(Boolean),
                limits: v(l + '_limits')
            };
        });
        return {
            title: v('title'), type: v('type'), publisher: v('publisher'), publishedOn: v('publishedOn'), authors: v('authors'),
            doi: v('doi'), evidence: v('evidence'), topic: v('topic'), topicEn: v('topicEn'), sourceText: v('sourceText'),
            draft: draft, links: current.links.filter(function (l) { return l.mode === 'include' || l.isNew; })
                .map(function (l) { return { type: l.type, id: l.id }; })
        };
    }

    function run(promise, after) {
        busy(true);
        setMessage('Đang xử lý…');
        return promise.then(function (d) {
            busy(false);
            if (d.item) { current = d.item; renderDrawer(); }
            setMessage(d.message || 'Xong.');
            if (after) after(d);
            return loadBoard();
        }).catch(function (err) {
            busy(false);
            setMessage(err.message, true);
        });
    }

    function save() { return run(post('hithean_research_save', { id: current.id }, collect())); }

    /** Lưu rồi làm tiếp (AI / đổi cột) — không mất chữ vừa sửa. */
    function saveThen(action, fields) {
        busy(true);
        setMessage('Đang lưu…');
        return post('hithean_research_save', { id: current.id }, collect())
            .then(function () { return run(post(action, Object.assign({ id: current.id }, fields || {}))); })
            .catch(function (err) { busy(false); setMessage(err.message, true); });
    }

    function twoStep(btn, label, fn) {
        if (btn.getAttribute('data-armed') === '1') { fn(); return; }
        btn.setAttribute('data-armed', '1');
        var old = btn.textContent;
        btn.textContent = label;
        setTimeout(function () { btn.removeAttribute('data-armed'); btn.textContent = old; }, 4000);
    }

    function actionsFor(it) {
        var out = [];
        var btn = function (text, cls, onclick, title) { return el('button', { type: 'button', class: 'hr-abtn ' + (cls || ''), title: title, onclick: onclick, text: text }); };
        if (it.status === 'inbox' || it.status === 'needs_input') {
            out.push(btn('↻ Bóc lại', '', function () { run(post('hithean_research_extract', { id: it.id })); }));
        }
        if (it.status !== 'archived' && it.status !== 'inbox') {
            out.push(btn(it.draftMeta.at ? '✨ Tạo lại tóm tắt AI' : '✨ Tạo tóm tắt AI', it.draft.vi.headline ? '' : 'hr-abtn--primary', function (e) {
                var b = e.currentTarget;
                if (it.draft.vi.headline) twoStep(b, 'Bấm lần nữa: AI ghi đè bản nháp', function () { saveThen('hithean_research_ai'); });
                else saveThen('hithean_research_ai');
            }));
        }
        if (it.status === 'draft' || it.status === 'needs_input') {
            out.push(btn('Gửi duyệt →', it.draft.vi.headline ? 'hr-abtn--primary' : '', function () { saveThen('hithean_research_status', { to: 'review' }); }));
        }
        if ((it.status === 'review' || it.status === 'published') && cfg.canPublish) {
            out.push(btn(it.status === 'published' ? '✅ Đăng bản sửa' : '✅ Duyệt & đăng', 'hr-abtn--primary', function () { saveThen('hithean_research_status', { to: 'published' }); }));
        }
        if (it.status === 'review') {
            out.push(btn('← Trả về nháp', '', function () { saveThen('hithean_research_status', { to: 'draft' }); }));
        }
        if (it.status !== 'archived' && (it.status !== 'published' || cfg.canPublish)) {
            out.push(btn(it.status === 'published' ? 'Gỡ (lưu trữ)' : 'Lưu trữ', 'hr-abtn--ghost', function (e) {
                twoStep(e.currentTarget, 'Bấm lần nữa để ' + (it.status === 'published' ? 'gỡ khỏi trang khách' : 'lưu trữ'), function () {
                    run(post('hithean_research_status', { id: it.id, to: 'archived' }));
                });
            }));
        }
        if (it.status === 'archived') {
            out.push(btn('↩ Khôi phục', 'hr-abtn--primary', function () { run(post('hithean_research_status', { id: it.id, to: 'draft' })); }));
            if (cfg.canPublish) {
                out.push(btn('Xoá hẳn', 'hr-abtn--danger', function (e) {
                    twoStep(e.currentTarget, 'Bấm lần nữa để xoá vĩnh viễn', function () {
                        busy(true);
                        post('hithean_research_delete', { id: it.id }).then(function () { closeDrawer(); loadBoard(); })
                            .catch(function (err) { busy(false); setMessage(err.message, true); });
                    });
                }));
            }
        }
        return out;
    }

    function summaryEditor(lang, part) {
        var name = lang === 'vi' ? 'Tiếng Việt' : 'English';
        return el('fieldset', { class: 'hr-sum' }, [
            el('legend', { text: name }),
            field('Tiêu đề tóm tắt', input(lang + '_headline', part.headline, { maxlength: 200 })),
            field('Tóm tắt (2–4 câu)', textarea(lang + '_summary', part.summary, 5, { maxlength: 1500 })),
            field('Ý chính (mỗi dòng 1 ý, ≤ 6)', textarea(lang + '_points', (part.points || []).join('\n'), 4)),
            field('Giới hạn của nghiên cứu', textarea(lang + '_limits', part.limits, 2, { maxlength: 500 }))
        ]);
    }

    function linksEditor() {
        var list = el('ul', { class: 'hr-links' });
        function paint() {
            list.replaceChildren();
            current.links.forEach(function (l, i) {
                var modeText = { include: '', pin: ' · 📌 ghim ở SP', exclude: ' · 🚫 ẩn ở SP' }[l.mode] || '';
                list.appendChild(el('li', {}, [
                    el('span', { text: l.label + modeText }),
                    l.mode === 'include' || l.isNew ? el('button', { type: 'button', class: 'hr-x', 'aria-label': 'Bỏ ' + l.label, text: '×',
                        onclick: function () { current.links.splice(i, 1); paint(); } }) : el('small', { text: '(đổi ở metabox SP)' })
                ]));
            });
            if (!current.links.length) list.appendChild(el('li', { class: 'hr-links__empty', text: 'Chưa gắn — khách chưa thấy tài liệu này ở đâu.' }));
        }
        paint();
        var type = select('linkType', [['product', 'Sản phẩm'], ['product_cat', 'Danh mục'], ['page', 'Trang / landing']], 'product');
        var q = input('linkQ', '', { type: 'search', placeholder: 'Gõ tên để tìm…', autocomplete: 'off' });
        var results = el('ul', { class: 'hr-links__results', role: 'listbox' });
        var timer;
        function search() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                post('hithean_research_targets', { type: type.value, q: q.value.trim() }).then(function (d) {
                    results.replaceChildren();
                    d.items.forEach(function (t) {
                        var exists = current.links.some(function (l) { return l.type === t.type && l.id === t.id; });
                        results.appendChild(el('li', {}, [el('button', { type: 'button', disabled: exists, text: (exists ? '✓ ' : '+ ') + t.label, onclick: function () {
                            current.links.push({ type: t.type, id: t.id, mode: 'include', label: t.label, isNew: true });
                            results.replaceChildren(); q.value = ''; paint();
                        } })]));
                    });
                    if (!d.items.length) results.appendChild(el('li', { class: 'hr-links__empty', text: 'Không tìm thấy.' }));
                }).catch(function (err) { results.replaceChildren(el('li', { class: 'hr-links__empty', text: err.message })); });
            }, 300);
        }
        q.addEventListener('input', search);
        q.addEventListener('focus', search);
        type.addEventListener('change', search);
        return el('div', { class: 'hr-section' }, [
            el('h3', { text: 'Gắn vào trang' }),
            el('p', { class: 'hr-help', text: 'Gắn danh mục → tự hiện ở mọi SP trong danh mục. Ghim / ẩn ở từng SP: metabox "Hồ sơ khoa học" trong trang sửa SP. Trang / landing: cần đặt shortcode [hithean_research] trong nội dung trang. Lưu để áp dụng.' }),
            list, el('div', { class: 'hr-links__add' }, [type, q]), results
        ]);
    }

    function renderDrawer() {
        var it = current;
        var types = Object.keys(cfg.types).map(function (k) { return [k, cfg.types[k].vi]; });
        var levels = [['', '— Chọn —']].concat(Object.keys(cfg.evidence).map(function (k) { return [k, cfg.evidence[k].vi]; }));
        var textLen = (it.sourceText || '').length;
        var upload = el('input', { type: 'file', accept: 'application/pdf', class: 'hr-file' });
        upload.addEventListener('change', function () {
            if (!upload.files[0]) return;
            var body = new FormData();
            body.append('action', 'hithean_research_upload'); body.append('nonce', cfg.nonce); body.append('id', it.id); body.append('file', upload.files[0]);
            run(fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); })
                .then(function (j) { if (!j.success) throw new Error((j.data && j.data.message) || 'Tải lên lỗi.'); return j.data; }));
        });

        var form = el('form', { class: 'hr-form', onsubmit: function (e) { e.preventDefault(); save(); } }, [
            el('div', { class: 'hr-section' }, [
                el('h3', { text: 'Thông tin tài liệu' }),
                field('Tiêu đề', input('title', it.title, { maxlength: 500, required: true })),
                el('div', { class: 'hr-grid' }, [
                    field('Loại', select('type', types, it.type)),
                    field('Mức bằng chứng', select('evidence', levels, it.evidence), 'AI đề xuất, bạn chốt.'),
                    field('Nguồn (tạp chí / kênh / báo)', input('publisher', it.publisher, { maxlength: 250 })),
                    field('Ngày xuất bản', input('publishedOn', it.publishedOn, { type: 'date' })),
                    field('Chủ đề / thành phần (VI)', input('topic', it.topic, { maxlength: 180, placeholder: 'vd: beta-glucan từ nấm men' }), 'Chèn vào disclaimer và dòng "Về thành phần".'),
                    field('Chủ đề (EN)', input('topicEn', it.topicEn, { maxlength: 180, placeholder: 'e.g. yeast beta-glucan' })),
                    field('DOI', input('doi', it.doi, { maxlength: 190 })),
                    field('Tác giả', input('authors', it.authors))
                ])
            ]),
            el('div', { class: 'hr-section' }, [
                el('h3', { text: 'Nội dung gốc (AI chỉ đọc phần này)' }),
                it.note ? el('p', { class: 'hr-note', text: '⚠ ' + it.note }) : null,
                field('Abstract / thân bài / transcript', textarea('sourceText', it.sourceText, 8),
                    textLen + ' ký tự — cần tối thiểu ' + cfg.minText + ' (hoặc tải PDF). Chỉ nhân sự thấy.'),
                el('div', { class: 'hr-upload' }, [
                    el('span', { text: it.file ? 'PDF: ' : 'Hoặc tải PDF bài báo (≤ 15 MB): ' }),
                    it.file ? el('a', { href: it.file.url, target: '_blank', rel: 'noopener', text: it.file.name }) : null,
                    upload
                ])
            ]),
            el('div', { class: 'hr-section' }, [
                el('h3', { text: 'Tóm tắt cho khách' }),
                it.draftMeta.at ? el('p', { class: 'hr-help', text: 'AI soạn lúc ' + it.draftMeta.at + (it.draftMeta.model ? ' (' + it.draftMeta.model + ')' : '') + '.' }) : null,
                it.draftMeta.stale ? el('p', { class: 'hr-note', text: '⚠ Nội dung gốc đã đổi sau khi AI soạn — cân nhắc tạo lại tóm tắt.' }) : null,
                el('div', { class: 'hr-sums' }, [summaryEditor('vi', it.draft.vi), summaryEditor('en', it.draft.en)])
            ]),
            linksEditor(),
            el('div', { class: 'hr-section' }, [
                el('h3', { text: 'Xem trước (đúng thẻ khách thấy — bản nháp đã lưu)' }),
                (function () {
                    var box = el('div', { class: 'hr-preview' });
                    if (it.previewHtml) {
                        // HTML do máy chủ dựng & escape (hithean_research_card_html).
                        box.innerHTML = it.previewHtml;
                    } else {
                        box.appendChild(el('p', { class: 'hr-help', text: 'Chưa có tóm tắt đủ VI + EN để xem trước.' }));
                    }
                    return box;
                })()
            ])
        ]);

        drawer.replaceChildren(
            el('div', { class: 'hr-drawer__head' }, [
                el('div', {}, [
                    el('span', { class: 'hr-drawer__status hr-kc--' + it.status, text: cfg.statuses[it.status].label }),
                    el('h2', { id: 'hr-drawer-title', text: it.title || '(chưa có tiêu đề)' }),
                    el('a', { href: it.sourceUrl, target: '_blank', rel: 'noopener', class: 'hr-drawer__src', text: 'Mở nguồn gốc ↗' })
                ]),
                el('button', { type: 'button', class: 'hr-drawer__close', 'aria-label': 'Đóng', 'data-hr-keep': true, text: '×', onclick: closeDrawer })
            ]),
            el('div', { class: 'hr-drawer__actions' }, actionsFor(it)),
            el('p', { class: 'hr-drawer__msg', 'data-hr-msg': true, 'aria-live': 'polite' }),
            form,
            el('div', { class: 'hr-drawer__foot' }, [
                el('button', { type: 'button', class: 'hr-abtn hr-abtn--primary', text: '💾 Lưu', onclick: save }),
                el('span', { class: 'hr-help', text: it.status === 'published' ? 'Tài liệu đang đăng: sửa tóm tắt rồi bấm "Đăng bản sửa" để khách thấy.' : '' })
            ])
        );
        drawer.hidden = false;
        document.body.classList.add('hr-drawer-open');
        board.querySelectorAll('.hr-kc').forEach(function (c) { c.classList.toggle('is-active', Number(c.getAttribute('data-id')) === it.id); });
    }

    function openItem(id) {
        drawer.hidden = false;
        drawer.replaceChildren(el('p', { class: 'hr-admin__loading', text: 'Đang mở…' }));
        document.body.classList.add('hr-drawer-open');
        history.replaceState(null, '', '#hr-' + id);
        post('hithean_research_get', { id: id }).then(function (d) { current = d.item; renderDrawer(); })
            .catch(function (err) { drawer.replaceChildren(el('p', { class: 'hr-admin__error', text: err.message })); });
    }

    function closeDrawer() {
        drawer.hidden = true;
        current = null;
        document.body.classList.remove('hr-drawer-open');
        history.replaceState(null, '', location.pathname + location.search);
        renderBoard();
    }

    if (board) {
        document.getElementById('hr-paste-go').addEventListener('click', ingestAll);
        document.getElementById('hr-search').addEventListener('input', renderBoard);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !drawer.hidden) closeDrawer(); });
        loadBoard().then(function () {
            var m = /^#hr-(\d+)$/.exec(location.hash);
            if (m) openItem(Number(m[1]));
        });
    }
    initProductBox();
})();

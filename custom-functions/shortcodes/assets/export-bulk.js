/*
 * Up ảnh xuất kho nhiều đơn — AI tự tách (form [upload_export_images_form] → Nhiều đơn).
 * Port từ theanmarket (shopee-export/assets/spx-bulk.js); hithean luôn truyền prefix 'hithean_export_'.
 *
 * HitheanExportBulk.mount(container, { ajaxUrl, nonce, prefix, max, toast, needsAi, runAi, label, fromAssign, footnote })
 *   → { run(files): Promise }
 * Luồng: <prefix>bulk_upload (lô 6 ảnh) → <prefix>bulk_read (AI đọc mã đơn, 6 ảnh/lần)
 *        → <prefix>bulk_group (gắn ảnh vào đơn) → runAi(p) cho từng đơn (2 luồng).
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function chunk(list, size) {
        var out = [];
        for (var i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
        return out;
    }

    function mount(container, opts) {
        opts = opts || {};
        var max = opts.max || 60;
        var toast = opts.toast || function (msg, ok) { if (!ok) alert(msg); };
        var state = null;

        function api(action, data, files) {
            var fd = new FormData();
            fd.append('action', (opts.prefix || 'hithean_export_') + action);
            fd.append('nonce', opts.nonce);
            Object.keys(data || {}).forEach(function (k) {
                var v = data[k];
                if (v === undefined || v === null) return;
                fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
            });
            (files || []).forEach(function (f) { fd.append('images[]', f); });
            return fetch(opts.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { throw new Error('Lỗi máy chủ (' + r.status + ')'); }); })
                .then(function (j) {
                    if (!j || !j.success) throw new Error((j && j.data && j.data.message) || 'Có lỗi xảy ra');
                    return j.data;
                });
        }

        function progress(label, done, total) {
            var pct = total ? Math.round(done * 100 / total) : 0;
            container.innerHTML = '<div style="margin:8px 0 2px;font-size:13px;color:#475569;">' + esc(label) + ' ' + done + '/' + total + '</div>' +
                '<div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;"><i style="display:block;height:100%;width:' + pct + '%;background:#0047ba;transition:width .2s;"></i></div>';
        }

        function aiText(p) {
            if (p.status === 'skipped') return '<span style="color:#b45309;">' + esc(p.message || 'Bỏ qua') + '</span>';
            if (p.ai === 'running') return '<span style="color:#0047ba;">AI đang đọc…</span>';
            if (p.ai === 'error') return '<span style="color:#b91c1c;">AI lỗi: ' + esc(p.aiError) + '</span>';
            var o = p.aiOverall || (p.row && p.row.ai_overall);
            if (o === 'pass') return '<span style="color:#047857;font-weight:600;">AI ✓ đạt</span>';
            if (o === 'review') return '<span style="color:#b45309;font-weight:600;">AI cần xem lại</span>';
            return '';
        }

        function render() {
            var created = state.packages.filter(function (p) { return p.status === 'created'; }).length;
            var appended = state.packages.filter(function (p) { return p.status === 'appended'; }).length;
            var html = '<div style="margin-top:10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">' +
                '<b>AI đã tách ' + state.packages.length + ' kiện</b> từ ' + state.total + ' ảnh: ' + created + ' kiện mới, ' + appended + ' thêm ảnh vào kiện có sẵn.' +
                '<ul style="margin:8px 0 0;padding-left:18px;">';
            state.packages.forEach(function (p) {
                var code = opts.label ? opts.label(p) : esc((p.row && (p.row.order_sn || p.row.tracking)) || p.code || '?');
                html += '<li>' + code + ' · ' + (p.image_count || 0) + ' ảnh · ' + aiText(p) +
                    (p.by_sequence ? ' <span style="color:#b45309;">⚠️ ' + p.by_sequence + ' ảnh gán theo thứ tự chụp — kiểm lại</span>' : '') + '</li>';
            });
            html += '</ul><div style="font-size:12px;color:#64748b;margin-top:6px;">' + (opts.footnote || 'Các kiện ở trạng thái <b>Chờ xác nhận</b> — kiểm dòng hàng và bấm Xác nhận xuất kho để trừ tồn.') + '</div></div>';

            if (state.unassigned.length) {
                html += '<div style="margin-top:10px;padding:10px 12px;border:1px solid #fcd34d;border-radius:8px;background:#fffbeb;">' +
                    '<b>' + state.unassigned.length + ' ảnh không đọc được mã</b> — tích ảnh, gõ mã đơn / mã vận đơn rồi Gán; ảnh thừa thì Bỏ ảnh.' +
                    '<div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;">' +
                    state.unassigned.map(function (img) {
                        return '<label style="position:relative;display:inline-block;width:84px;vertical-align:top;" title="' + esc(img.reason || '') + '"><input type="checkbox" value="' + img.id + '" data-spxb-pick style="position:absolute;top:4px;left:4px;width:18px;height:18px;">' +
                            '<img src="' + esc(img.url) + '" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">' +
                            (img.reason ? '<small style="display:block;font-size:11px;line-height:1.2;color:#b45309;">' + esc(img.reason) + '</small>' : '') + '</label>';
                    }).join('') + '</div>' +
                    '<div style="display:flex;gap:6px;flex-wrap:wrap;"><input type="text" data-spxb-code placeholder="Mã đơn hoặc mã vận đơn" style="flex:1;min-width:180px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;">' +
                    '<button type="button" class="button button-primary" data-spxb="assign">Gán</button>' +
                    '<button type="button" class="button" data-spxb="discard">Bỏ ảnh</button></div></div>';
            }
            container.innerHTML = html;
        }

        // AI đọc riêng cho kiện chưa có kết quả từ lượt đọc theo lô (2 luồng).
        function runPendingAi() {
            var queue = state.packages.filter(function (p) {
                if (p.status === 'skipped' || p.ai) return false;
                return opts.needsAi ? opts.needsAi(p) : (p.row && p.row.status === 'draft' && !p.row.ai_overall);
            });
            var running = 0;
            return new Promise(function (resolve) {
                function next() {
                    if (!queue.length && !running) { resolve(); return; }
                    while (running < 2 && queue.length) {
                        var p = queue.shift();
                        running++;
                        p.ai = 'running';
                        render();
                        (opts.runAi ? opts.runAi(p).then(function (overall) {
                            this.aiOverall = overall;
                            this.ai = '';
                        }.bind(p)) : api('ai', { id: p.row.id }).then(function (d) {
                            this.row = d.row;
                            this.ai = '';
                            if (opts.onRow) opts.onRow(d.row);
                        }.bind(p))).catch(function (err) {
                            this.ai = 'error';
                            this.aiError = err.message;
                        }.bind(p)).then(function () {
                            running--;
                            render();
                            next();
                        });
                    }
                }
                next();
            });
        }

        container.addEventListener('click', function (e) {
            var b = e.target.closest('[data-spxb]');
            if (!b || !state) return;
            var ids = Array.prototype.slice.call(container.querySelectorAll('[data-spxb-pick]:checked')).map(function (c) { return parseInt(c.value, 10); });
            if (!ids.length) { toast('Tích ảnh trước', false); return; }
            var done = function () {
                state.unassigned = state.unassigned.filter(function (img) { return ids.indexOf(img.id) === -1; });
                render();
            };
            b.disabled = true;
            if (b.getAttribute('data-spxb') === 'assign') {
                var code = (container.querySelector('[data-spxb-code]').value || '').trim();
                if (!code) { b.disabled = false; toast('Nhập mã đơn hoặc mã vận đơn', false); return; }
                api('bulk_assign', { batch: state.batch, ids: ids, code: code }).then(function (d) {
                    var pkg = opts.fromAssign ? opts.fromAssign(d, code, ids) : { status: d.status, row: d.row, code: code, image_count: ids.length, by_sequence: 0 };
                    state.packages.push(pkg);
                    if (pkg.row && opts.onRow) opts.onRow(pkg.row);
                    done();
                    toast('Đã gán ' + ids.length + ' ảnh vào ' + ((pkg.row && pkg.row.order_sn) || pkg.code || code), true);
                    runPendingAi();
                }).catch(function (err) { b.disabled = false; toast(err.message, false); });
            } else {
                api('bulk_discard', { batch: state.batch, ids: ids }).then(function () {
                    done();
                    toast('Đã bỏ ' + ids.length + ' ảnh', true);
                }).catch(function (err) { b.disabled = false; toast(err.message, false); });
            }
        });

        function run(files) {
            files = Array.prototype.slice.call(files || []);
            if (!files.length) return Promise.reject(new Error('Chọn ảnh'));
            if (files.length > max) return Promise.reject(new Error('Tối đa ' + max + ' ảnh mỗi lô'));
            // Thứ tự chụp: ảnh không thấy tem đi theo ảnh liền trước.
            files.sort(function (a, b) { return (a.lastModified - b.lastModified) || a.name.localeCompare(b.name); });
            state = { batch: '', images: [], packages: [], unassigned: [], total: files.length };

            var done = 0;
            var upload = chunk(files.map(function (f, i) { return { file: f, seq: i }; }), 6).reduce(function (p, part) {
                return p.then(function () {
                    progress('Đang tải ảnh', done, files.length);
                    return api('bulk_upload', { batch: state.batch, seqs: part.map(function (x) { return x.seq; }) }, part.map(function (x) { return x.file; }))
                        .then(function (d) {
                            state.batch = d.batch;
                            state.images = state.images.concat(d.images);
                            done += part.length;
                            if (d.errors && d.errors.length) toast(d.errors.join('; '), false);
                        });
                });
            }, Promise.resolve());

            return upload.then(function () {
                progress('AI đang đọc tem', 0, state.images.length);
                var read = 0;
                return chunk(state.images.map(function (i) { return i.id; }), 6).reduce(function (p, ids) {
                    return p.then(function () {
                        return api('bulk_read', { batch: state.batch, ids: ids })
                            .catch(function (err) { toast('AI đọc tem: ' + err.message, false); })
                            .then(function () { read += ids.length; progress('AI đang đọc tem', read, state.images.length); });
                    });
                }, Promise.resolve());
            }).then(function () {
                progress('Đang tách kiện', 0, 1);
                return api('bulk_group', { batch: state.batch });
            }).then(function (d) {
                state.packages = d.packages;
                state.unassigned = d.unassigned;
                state.packages.forEach(function (p) { if (p.row && opts.onRow) opts.onRow(p.row); });
                render();
                return runPendingAi();
            }).then(function () {
                return state;
            }).catch(function (err) {
                container.innerHTML = '<div style="margin-top:8px;padding:8px 12px;border-radius:8px;background:#fef2f2;color:#b91c1c;">' + esc(err.message) +
                    (state.batch ? ' — thử lại sẽ tải lại cả lô từ đầu.' : '') + '</div>';
                throw err;
            });
        }

        return { run: run };
    }

    window.HitheanExportBulk = { mount: mount };
})();

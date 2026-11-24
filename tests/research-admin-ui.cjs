// Hồ sơ khoa học — bảng Kanban (research-admin.js) với máy chủ giả (cần jsdom).
// Lỗi giữ chặt: "Gửi duyệt" phải LƯU chữ đang sửa trước khi đổi cột; payload gửi base64 UTF-8 không vỡ tiếng Việt.
// Chạy: node tests/research-admin-ui.cjs
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const assert = require('node:assert/strict');

const script = fs.readFileSync('custom-functions/woocommerce/products/research/assets/research-admin.js', 'utf8');
const dom = new JSDOM(`<body><div id="hr-board"></div><aside id="hr-drawer" hidden></aside>
<textarea id="hr-paste"></textarea><button id="hr-paste-go"></button><span id="hr-paste-status"></span><input id="hr-search"><span id="hr-count"></span></body>`,
    { url: 'https://hithean.test/wp-admin/edit.php?post_type=product&page=hithean-research', runScripts: 'outside-only', pretendToBeVisual: true });
const w = dom.window;
if (!w.Element.prototype.replaceChildren) {
    w.Element.prototype.replaceChildren = function (...nodes) { this.textContent = ''; nodes.forEach(n => this.append(n)); };
}
w.TextEncoder = TextEncoder;
w.hitheanResearch = {
    ajax: '/ajax', nonce: 'n', canPublish: true, minText: 300, boardUrl: '/b',
    statuses: { inbox: { label: 'Mới', hint: '' }, needs_input: { label: 'Cần', hint: '' }, draft: { label: 'Nháp', hint: '' },
        review: { label: 'Duyệt', hint: '' }, published: { label: 'Đăng', hint: '' }, archived: { label: 'Lưu', hint: '' } },
    types: { journal: { vi: 'Tạp chí' }, video: { vi: 'Video' }, news: { vi: 'Báo' }, other: { vi: 'Tài liệu' } },
    evidence: { rct: { vi: 'Thử nghiệm ngẫu nhiên' } },
};
const detail = {
    id: 5, url: 'https://x', sourceUrl: 'https://doi.org/1', type: 'journal', status: 'draft', title: 'Beta-glucan', authors: '', publisher: 'J',
    publishedOn: '2024-01-01', doi: '', evidence: 'rct', topic: 'beta-glucan', topicEn: '', sourceText: 'x'.repeat(400), file: null, note: '',
    draft: { vi: { headline: 'Cũ', summary: 'Tóm tắt', points: ['a'], limits: '' }, en: { headline: 'Old', summary: 'Sum', points: [], limits: '' } },
    draftMeta: { model: '', at: '2026-11-24 10:00:00', stale: false }, links: [{ type: 'product_cat', id: 3, mode: 'include', label: 'Danh mục: Men' },
        { type: 'product', id: 9, mode: 'exclude', label: 'SP: A' }], publishedAt: '', previewHtml: '<article class="hr-card">preview</article>',
};
const calls = [];
w.fetch = (url, opts) => {
    const body = opts.body;
    const action = body.get('action');
    const payload = body.get('payload') ? JSON.parse(Buffer.from(body.get('payload'), 'base64').toString('utf8')) : null;
    calls.push({ action, payload, to: body.get('to'), id: body.get('id') });
    let data;
    if (action === 'hithean_research_list') {
        data = { items: [
            { id: 5, title: 'Beta-glucan', type: 'journal', status: 'draft', publisher: 'J', year: '2024', topic: '', note: '', hasText: true, hasDraft: true, stale: false, unpublishedEdits: false, links: 1 },
            { id: 6, title: 'Video', type: 'video', status: 'needs_input', publisher: '', year: '', topic: '', note: 'Dán transcript', hasText: false, hasDraft: false, stale: false, unpublishedEdits: false, links: 0 },
        ] };
    } else if (action === 'hithean_research_status') {
        data = { item: Object.assign({}, detail, { status: 'review' }), message: 'Đã gửi duyệt.' };
    } else {
        data = { item: detail, message: 'Đã lưu.' };
    }
    return Promise.resolve({ status: 200, json: () => Promise.resolve({ success: true, data }) });
};
w.eval(script);
const d = w.document;
const tick = () => new Promise(r => setTimeout(r, 20));

(async () => {
    await tick();
    assert.equal(d.querySelectorAll('.hr-col').length, 6, '6 cột Kanban');
    const needs = d.querySelector('.hr-col--needs_input .hr-kc');
    assert.match(needs.textContent, /Dán transcript/, 'Thẻ cần bổ sung ghi lý do');
    assert.match(needs.textContent, /→ Dán transcript/, 'Video: việc tiếp theo là dán transcript');
    assert.match(needs.textContent, /Chưa gắn trang nào/, 'Cảnh báo chưa gắn trang');
    assert.match(d.querySelector('.hr-col--draft .hr-kc').textContent, /→ Đọc lại, gửi duyệt/);

    d.querySelector('.hr-col--draft .hr-kc').click();
    await tick();
    const drawer = d.getElementById('hr-drawer');
    assert.equal(drawer.hidden, false, 'Mở ngăn sửa');
    assert.equal(w.location.hash, '#hr-5', 'URL trỏ thẳng tới thẻ');
    assert.ok(drawer.querySelector('.hr-preview .hr-card'), 'Có xem trước');
    assert.match(drawer.querySelector('.hr-links').textContent, /Danh mục: Men.*SP: A · 🚫 ẩn ở SP/s, 'Danh sách trang gắn + chế độ ở SP');

    const f = drawer.querySelector('form');
    f.elements.vi_headline.value = 'Tiêu đề mới — beta-glucan giảm cảm lạnh';
    f.elements.vi_points.value = '- Ý một\n\n• Ý hai';
    const send = [...drawer.querySelectorAll('.hr-drawer__actions button')].find(b => /Gửi duyệt/.test(b.textContent));
    send.click();
    await tick(); await tick();
    const save = calls.find(c => c.action === 'hithean_research_save');
    const status = calls.find(c => c.action === 'hithean_research_status');
    assert.ok(save && status && calls.indexOf(save) < calls.indexOf(status), 'Gửi duyệt: lưu trước rồi mới đổi cột');
    assert.equal(save.payload.draft.vi.headline, 'Tiêu đề mới — beta-glucan giảm cảm lạnh', 'Payload base64 giữ nguyên tiếng Việt');
    assert.deepEqual(save.payload.draft.vi.points, ['Ý một', 'Ý hai'], 'Ý chính: mỗi dòng 1 ý, bỏ gạch đầu dòng / dòng trống');
    assert.deepEqual(save.payload.links, [{ type: 'product_cat', id: 3 }], 'Chỉ gửi trang "include" — ghim / ẩn ở SP giữ nguyên');
    assert.equal(status.to, 'review');
    assert.match(drawer.querySelector('[data-hr-msg]').textContent, /Đã gửi duyệt/);

    // Khi đã ở "Chờ duyệt": quản lý thấy nút Duyệt & đăng.
    assert.ok([...drawer.querySelectorAll('.hr-drawer__actions button')].some(b => /Duyệt & đăng/.test(b.textContent)), 'Nút Duyệt & đăng');

    // Lưu trữ phải bấm 2 lần.
    const archive = [...drawer.querySelectorAll('.hr-drawer__actions button')].find(b => /Lưu trữ/.test(b.textContent));
    const before = calls.length;
    archive.click();
    await tick();
    assert.equal(calls.length, before, 'Bấm lần 1 chưa gửi');
    assert.match(archive.textContent, /Bấm lần nữa/);

    d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.equal(drawer.hidden, true, 'Esc đóng ngăn sửa');
    console.log('PASS research-admin-ui');
})().catch(e => { console.error(e); process.exit(1); });

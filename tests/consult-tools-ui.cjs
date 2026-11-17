/* UI tab Sản phẩm - Tư vấn: consult-flows.js + product-advisor.js (fetch giả lập). Chạy: node tests/consult-tools-ui.cjs (cần jsdom). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const assets = path.join(__dirname, '../custom-functions/shortcodes/assets');
const tick = () => new Promise((r) => setTimeout(r, 0));
let checks = 0;
const ok = (v, m) => { checks++; assert.ok(v, m); };

function boot(html, file, handler) {
    const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { url: 'https://example.test/?x=1', runScripts: 'outside-only' });
    const w = dom.window;
    const calls = [];
    w.fetch = (url, opts) => {
        const data = Object.fromEntries(opts.body.entries());
        calls.push(data);
        return Promise.resolve({ status: 200, json: () => Promise.resolve(handler(data)) });
    };
    w.copied = [];
    w.document.execCommand = () => { w.copied.push([...w.document.querySelectorAll('textarea')].pop().value); return true; };
    w.matchMedia = () => ({ matches: false });
    w.confirm = () => true;
    w.eval(fs.readFileSync(path.join(assets, file), 'utf8'));
    return { w, d: w.document, calls };
}

(async () => {
    // ── Kịch bản ──
    const flows = [
        { id: 1, title: 'Khách chê đắt', group: 'Phản đối', keywords: ['đắt'], skus: [], status: 'publish', steps: [{ title: 'Đồng cảm', say: 'Dạ em hiểu {ten} ạ\n---\nPhương án 2', note: 'Không giảm giá ngay' }] },
        { id: 2, title: 'Mẹ sau sinh', group: 'Mẹ & bé', keywords: ['cho con bú'], skus: ['ANC-PRE'], status: 'draft', steps: [{ title: 'Hỏi', say: 'Bé mấy tháng ạ?', note: '' }] },
    ];
    const html = fs.readFileSync(path.join(__dirname, '../custom-functions/shortcodes/shortcode-consult-flows.php'), 'utf8');
    ok(html.includes('data-hcf-list'), 'shortcode có khung danh sách');
    const flowsDom = `<div id="hcf-root"><input data-hcf-q><select data-hcf-group></select><input data-hcf-name><button data-hcf-new></button>
        <p data-hcf-status></p><nav data-hcf-list></nav><section data-hcf-detail></section></div>
        <script type="application/json" id="hcf-cfg">{"ajax":"/a","nonce":"n","manage":true,"site":"s"}</script>`;
    let saved = null;
    const f = boot(flowsDom, 'consult-flows.js', (data) => {
        if (data.action === 'hithean_cflow_save') { saved = JSON.parse(data.flow); return { success: true, data: { id: 3, flows: flows.concat([Object.assign({}, saved, { id: 3, keywords: [], skus: [] })]) } }; }
        return { success: true, data: { flows } };
    });
    await tick(); await tick();
    const list = f.d.querySelector('[data-hcf-list]');
    ok(list.querySelectorAll('button').length === 2 && list.textContent.includes('nháp'), 'liệt kê 2 kịch bản, đánh dấu nháp');
    ok(f.d.querySelector('[data-hcf-group]').options.length === 3, 'bộ lọc nhóm');
    f.d.querySelector('[data-hcf-q]').value = 'cho con bu';
    f.d.querySelector('[data-hcf-q]').dispatchEvent(new f.w.Event('input'));
    ok(list.querySelectorAll('button').length === 1 && list.textContent.includes('Mẹ sau sinh'), 'tìm không dấu theo từ khóa');
    f.d.querySelector('[data-hcf-q]').value = '';
    f.d.querySelector('[data-hcf-q]').dispatchEvent(new f.w.Event('input'));
    f.d.querySelector('[data-hcf-name]').value = 'chị Lan';
    f.d.querySelector('[data-hcf-name]').dispatchEvent(new f.w.Event('input'));
    [...list.querySelectorAll('button')].find((b) => b.textContent.includes('chê đắt')).click();
    const says = f.d.querySelectorAll('.hcf-say');
    ok(says.length === 2, 'câu mẫu tách phương án bằng ---');
    ok(says[0].textContent.includes('Dạ em hiểu chị Lan ạ'), 'thay {ten} bằng tên khách');
    ok(f.d.querySelector('.hcf-note').textContent.includes('Không giảm giá'), 'ghi chú nội bộ');
    says[0].click();
    ok(f.w.copied[0] === 'Dạ em hiểu chị Lan ạ', 'bấm câu mẫu → copy đã thay tên');
    ok(f.w.location.hash === '#kb-1', 'link kịch bản theo hash');
    f.d.querySelector('[data-hcf-new]').click();
    const form = f.d.querySelector('.hcf-form');
    form.querySelector('[name=title]').value = 'Khách hỏi ship';
    form.querySelector('.hcf-fstep textarea').value = 'Dạ phí ship…';
    form.dispatchEvent(new f.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    ok(saved && saved.title === 'Khách hỏi ship' && saved.steps[0].say === 'Dạ phí ship…' && saved.id === 0, 'form gửi kịch bản mới');
    ok(f.d.querySelector('[data-hcf-detail] h2').textContent === 'Khách hỏi ship', 'lưu xong mở kịch bản vừa tạo');

    // ── AI tư vấn ──
    const advDom = `<div id="hpa-root"><form data-hpa-form><textarea name="question"></textarea><div data-hpa-chips></div><input data-hpa-sku><datalist id="hpa-cat"></datalist>
        <select name="reply_length"><option value="medium" selected>Vừa</option></select><input type="checkbox" name="labels">
        <button type="submit" data-hpa-ask></button><input type="checkbox" data-hpa-keep checked><b data-hpa-turns></b><button type="button" data-hpa-reset></button></form>
        <p data-hpa-status></p><section data-hpa-out hidden></section></div>
        <script type="application/json" id="hpa-cfg">{"ajax":"/a","nonce":"n","flowNonce":"f","manage":true,"catalogue":[{"sku":"ANC-PRE","name":"Perfect Prenatal"},{"sku":"YH-MEN","name":"Yeast Hero Men"}]}</script>`;
    let n = 0;
    const a = boot(advDom, 'product-advisor.js', (data) => {
        if (data.action === 'hithean_cflow_save') return { success: true, data: { id: 9, flows: [] } };
        n++;
        return { success: true, data: { reply: 'Dạ chị dùng được ạ ' + n, follow_up_questions: ['Bé mấy tháng ạ?'], staff_notes: ['Kiểm tra hạn dùng'],
            products: [{ id: 10, sku: 'ANC-PRE', name: 'Perfect Prenatal', url: '/sp', stock: 'còn hàng' }], suggested: [{ id: 11, sku: 'YH-MEN', name: 'Yeast Hero Men', url: '/y', stock: 'còn hàng' }],
            flows: [{ id: 2, title: 'Mẹ sau sinh', used: true }], labels_read: 0, model: 'gemini' } };
    });
    ok(a.d.querySelectorAll('#hpa-cat option').length === 2, 'datalist danh mục SKU — tên');
    const sku = a.d.querySelector('[data-hpa-sku]');
    sku.value = 'prenatal';
    sku.dispatchEvent(new a.w.KeyboardEvent('keydown', { key: 'Enter' }));
    ok(a.d.querySelector('[data-hpa-chips]').textContent.includes('ANC-PRE'), 'gõ tên → chip SKU đúng');
    const formA = a.d.querySelector('[data-hpa-form]');
    formA.elements.namedItem('question').value = 'Chị cho con bú uống được không?';
    formA.dispatchEvent(new a.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    const ask1 = a.calls.find((c) => c.action === 'hithean_advisor_ask');
    ok(ask1.skus === 'ANC-PRE' && ask1.length === 'medium' && ask1.history === '[]', 'gửi câu hỏi + SKU + độ dài');
    ok(a.d.querySelector('.hpa-reply').value === 'Dạ chị dùng được ạ 1', 'hiện tin nhắn soạn sẵn');
    ok(!a.d.querySelector('[data-hpa-out]').hidden && a.d.querySelector('[data-hpa-out]').textContent.includes('✓ Mẹ sau sinh'), 'hiện kịch bản AI đã dùng');
    a.d.querySelector('.hpa-reply').value = 'Bản nhân viên sửa';
    formA.elements.namedItem('question').value = 'Uống ngày mấy viên?';
    formA.dispatchEvent(new a.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    const ask2 = a.calls.filter((c) => c.action === 'hithean_advisor_ask')[1];
    ok(JSON.parse(ask2.history)[0].a === 'Bản nhân viên sửa', 'giữ ngữ cảnh bằng bản đã sửa của nhân viên');
    const saveBtn = [...a.d.querySelectorAll('button')].find((b) => b.textContent.includes('Lưu thành kịch bản'));
    saveBtn.click();
    await tick(); await tick();
    const save = a.calls.find((c) => c.action === 'hithean_cflow_save');
    const flow = JSON.parse(save.flow);
    ok(save.nonce === 'f' && flow.status === 'draft' && flow.skus[0] === 'ANC-PRE' && flow.steps[0].say === 'Dạ chị dùng được ạ 2', 'lưu câu trả lời thành kịch bản nháp');
    a.d.querySelector('[data-hpa-reset]').click();
    ok(a.d.querySelector('[data-hpa-out]').hidden && a.d.querySelector('[data-hpa-chips]').textContent === '', 'hội thoại mới xóa ngữ cảnh');

    console.log('OK (' + checks + ' consult tools UI checks)');
})().catch((e) => { console.error(e); process.exit(1); });

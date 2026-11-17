/* UI tab Sản phẩm - Tư vấn: consult-flows.js + product-advisor.js (fetch giả lập). Chạy: node tests/consult-tools-ui.cjs (cần jsdom). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const assets = path.join(__dirname, '../custom-functions/shortcodes/assets');
const tick = () => new Promise((r) => setTimeout(r, 0));
let checks = 0;
const ok = (v, m) => { checks++; assert.ok(v, m); };

function boot(html, file, handler, pre) {
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
    (pre || []).forEach((f) => w.eval(fs.readFileSync(path.join(assets, f), 'utf8')));
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
    const advDom = `<div id="hpa-root"><form data-hpa-form><textarea name="question"></textarea><div data-hpa-faq hidden></div><textarea name="extra"></textarea><div data-hpa-chips></div><input data-hpa-sku><datalist id="hpa-cat"></datalist>
        <select name="reply_length"><option value="medium" selected>Vừa</option></select><input type="checkbox" name="labels">
        <button type="submit" data-hpa-ask></button><input type="checkbox" data-hpa-keep checked><b data-hpa-turns></b><button type="button" data-hpa-reset></button></form>
        <details data-hpa-ref><textarea data-hpa-ref-text></textarea><p data-hpa-ref-meta></p><button type="button" data-hpa-ref-save></button></details>
        <p data-hpa-status></p><section data-hpa-out hidden></section></div>
        <script type="application/json" id="hpa-cfg">{"ajax":"/a","nonce":"n","flowNonce":"f","manage":true,"reference":{"text":"Ship free 500k","updated":"2026-11-18","by":"QL"},"catalogue":[{"sku":"ANC-PRE","name":"Perfect Prenatal"},{"sku":"YH-MEN","name":"Yeast Hero Men"}]}</script>`;
    let n = 0;
    const a = boot(advDom, 'product-advisor.js', (data) => {
        if (data.action === 'hithean_cflow_save') return { success: true, data: { id: 9, flows: [] } };
        if (data.action === 'hithean_faq_suggest') return { success: true, data: { items: [{ id: 4, q: 'Cho con bú dùng được không?', a: 'Dạ dùng được ạ', skus: [] }] } };
        if (data.action === 'hithean_faq_save') return { success: true, data: { id: 5, updated: false, items: [] } };
        if (data.action === 'hithean_faq_used') return { success: true, data: {} };
        if (data.action === 'hithean_consult_ref_save') return { success: true, data: { reference: { text: data.text, updated: 'now', by: 'QL' } } };
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
    ok(a.d.querySelector('[data-hpa-ref-text]').value === 'Ship free 500k' && a.d.querySelector('[data-hpa-ref-meta]').textContent.includes('QL'), 'hiện tài liệu tham khảo chung');
    a.d.querySelector('[data-hpa-ref-text]').value = 'Ship free 600k';
    a.d.querySelector('[data-hpa-ref-save]').click();
    await tick(); await tick();
    ok(a.calls.find((c) => c.action === 'hithean_consult_ref_save').text === 'Ship free 600k', 'lưu tài liệu tham khảo chung');
    const formA = a.d.querySelector('[data-hpa-form]');
    formA.elements.namedItem('question').value = 'Chị cho con bú uống được không?';
    formA.elements.namedItem('question').dispatchEvent(new a.w.Event('input'));
    await new Promise((r) => setTimeout(r, 600)); await tick();
    const faqBox = a.d.querySelector('[data-hpa-faq]');
    ok(!faqBox.hidden && faqBox.textContent.includes('Dạ dùng được ạ'), 'gõ câu hỏi → gợi ý FAQ đã lưu');
    faqBox.querySelector('.hcf-say').click();
    await tick();
    ok(a.calls.some((c) => c.action === 'hithean_faq_used' && c.id === '4' && c.nonce === 'f'), 'copy FAQ → đếm lượt dùng');
    formA.elements.namedItem('extra').value = 'Khách dị ứng đậu nành';
    formA.dispatchEvent(new a.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    const ask1 = a.calls.find((c) => c.action === 'hithean_advisor_ask');
    ok(ask1.skus === 'ANC-PRE' && ask1.length === 'medium' && ask1.history === '[]' && ask1.extra === 'Khách dị ứng đậu nành', 'gửi câu hỏi + SKU + độ dài + tham khảo thêm');
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
    [...a.d.querySelectorAll('button')].find((b) => b.textContent.includes('Lưu vào FAQ')).click();
    const faqTa = a.d.querySelectorAll('.hpa-faqsave textarea');
    ok(faqTa.length === 2 && faqTa[0].value === 'Uống ngày mấy viên?' && faqTa[1].value === 'Dạ chị dùng được ạ 2', 'form lưu FAQ điền sẵn câu hỏi + câu trả lời');
    [...a.d.querySelectorAll('.hpa-faqsave button')].find((b) => b.textContent.includes('Lưu')).click();
    await tick(); await tick();
    const fsave = JSON.parse(a.calls.find((c) => c.action === 'hithean_faq_save').faq);
    ok(fsave.source === 'ai' && fsave.skus[0] === 'ANC-PRE' && a.d.querySelector('.hpa-faqsave').textContent.includes('#5'), 'lưu câu trả lời AI vào FAQ');
    a.d.querySelector('[data-hpa-reset]').click();
    ok(a.d.querySelector('[data-hpa-out]').hidden && a.d.querySelector('[data-hpa-chips]').textContent === '', 'hội thoại mới xóa ngữ cảnh');


    // ── FAQ tư vấn + gói xuất / nhập ──
    const faqDom = `<div id="hfq-root"><input data-hfq-q><input data-hfq-name><button data-hfq-new></button>
        <button data-hcb-export="faqs"></button><button data-hcb-export="all"></button><input type="file" data-hcb-import>
        <p data-hfq-status></p><div data-hfq-form-slot></div><div data-hfq-list></div></div>
        <script type="application/json" id="hfq-cfg">{"ajax":"/a","nonce":"n","manage":true,"site":"hithean"}</script>`;
    const faqs = [{ id: 1, q: 'Phí ship?', a: 'Dạ {ten} miễn phí ạ', skus: [], keywords: ['giao hàng'], uses: 3, source: 'ai' }, { id: 2, q: 'Đổi trả?', a: '7 ngày', skus: [], keywords: [], uses: 0 }];
    const q = boot(faqDom, 'consult-faqs.js', (data) => {
        if (data.action === 'hithean_consult_export') return { success: true, data: { bundle: { format: 'thean-consult-bundle', scope: data.scope } } };
        if (data.action === 'hithean_consult_import') return { success: true, data: { message: 'FAQ: thêm 1', items: faqs.concat([{ id: 3, q: 'Mới?', a: 'x', skus: [], keywords: [] }]), flows: [] } };
        if (data.action === 'hithean_faq_save') return { success: true, data: { id: 9, updated: false, items: faqs } };
        return { success: true, data: { items: faqs } };
    }, ['consult-bundle.js']);
    q.w.URL.createObjectURL = () => 'blob:x'; q.w.URL.revokeObjectURL = () => {};
    await tick(); await tick();
    const fl = q.d.querySelector('[data-hfq-list]');
    ok(fl.querySelectorAll('.hfq-item').length === 2 && fl.textContent.includes('từ AI') && fl.textContent.includes('dùng 3 lần'), 'liệt kê FAQ + nguồn + lượt dùng');
    q.d.querySelector('[data-hfq-name]').value = 'chị Mai';
    q.d.querySelector('[data-hfq-name]').dispatchEvent(new q.w.Event('input'));
    q.d.querySelector('[data-hfq-q]').value = 'giao hang';
    q.d.querySelector('[data-hfq-q]').dispatchEvent(new q.w.Event('input'));
    ok(fl.querySelectorAll('.hfq-item').length === 1, 'tìm FAQ không dấu (cả từ khóa)');
    fl.querySelector('.hcf-say').click();
    await tick();
    ok(q.w.copied.pop() === 'Dạ chị Mai miễn phí ạ' && q.calls.some((c) => c.action === 'hithean_faq_used'), 'copy FAQ thay {ten} + đếm lượt');
    q.d.querySelector('[data-hfq-new]').click();
    const fform = q.d.querySelector('[data-hfq-form-slot] form');
    const tas = fform.querySelectorAll('textarea'); tas[0].value = 'Có hóa đơn VAT không?'; tas[1].value = 'Dạ có ạ';
    fform.dispatchEvent(new q.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    ok(JSON.parse(q.calls.find((c) => c.action === 'hithean_faq_save').faq).q === 'Có hóa đơn VAT không?', 'thêm FAQ tay');
    q.d.querySelector('[data-hcb-export="all"]').click();
    await tick(); await tick();
    ok(q.calls.find((c) => c.action === 'hithean_consult_export').scope === 'all', 'xuất trọn bộ');
    const inp = q.d.querySelector('[data-hcb-import]');
    Object.defineProperty(inp, 'files', { value: [{ text: () => Promise.resolve('{"format":"thean-consult-bundle","faqs":[]}') }] });
    inp.dispatchEvent(new q.w.Event('change'));
    await tick(); await tick(); await tick();
    ok(q.calls.find((c) => c.action === 'hithean_consult_import').data.includes('thean-consult-bundle') && fl.querySelectorAll('.hfq-item').length >= 1 && q.d.querySelector('[data-hfq-status]').textContent === 'FAQ: thêm 1', 'nhập gói → tải lại danh sách');

    console.log('OK (' + checks + ' consult tools UI checks)');
})().catch((e) => { console.error(e); process.exit(1); });

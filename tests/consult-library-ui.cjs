/* Unified library clients, including the actual mobile helper and AI save/suggestion integration. */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const assets = path.join(__dirname, '../custom-functions/shortcodes/assets');
const tick = () => new Promise(r => setTimeout(r, 0));
let checks = 0;
const check = (v, m) => { assert.ok(v, m); checks++; };
const entries = [
 { id: 'faq-1', type: 'faq', title: 'Phí giao hàng?', group: 'Giao hàng', keywords: ['ship'], skus: [], status: 'publish', uses: 7, steps: [{ title: 'Trả lời', say: 'Theo địa chỉ {ten}', note: '' }] },
 { id: 'flow-1', type: 'flow', title: 'Tư vấn giao hàng', group: 'Giao hàng', keywords: ['ship'], skus: ['X'], status: 'draft', steps: [{ title: 'Hỏi', say: 'Địa chỉ?', note: '' }, { title: 'Kiểm tra', say: 'Em kiểm tra lại', note: 'Nội bộ' }] }
];
function boot(html, scripts, handler, mobile = false) {
 const dom = new JSDOM(html, { url: 'https://example.test', runScripts: 'outside-only' }), w = dom.window, d = w.document, calls = [], copied = [];
 const media = { matches: mobile, addEventListener() {}, removeEventListener() {} };
 w.matchMedia = () => media; w.confirm = () => true;
 w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
 w.HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
 w.HTMLElement.prototype.scrollIntoView = () => {};
 d.execCommand = () => { copied.push([...d.querySelectorAll('textarea')].pop().value); return true; };
 w.fetch = async (_, opts) => { const data = Object.fromEntries(opts.body.entries()); calls.push(data); return { status: 200, json: async () => handler(data) }; };
 scripts.forEach(s => w.eval(fs.readFileSync(path.join(assets, s), 'utf8')));
 return { w, d, calls, copied };
}
const library = manage => `<div class="hcf" id="hcf-root"><input data-hcf-q><select data-hcf-group></select><input data-hcf-name><select data-hcl-type><option value="">All</option><option value="faq">FAQ</option><option value="flow">Script</option></select><button data-hcf-new>New</button><p class="hcf-status" data-hcf-status></p><nav data-hcf-list></nav><section data-hcf-detail></section></div><script type="application/json" id="hcf-cfg">{"ajax":"/a","nonce":"n","manage":${manage},"library":true}</script>`;
(async () => {
 let saved, data = entries.slice();
 const f = boot(library(true), ['consult-modal.js', 'consult-flows.js'], p => {
  if (p.action === 'hithean_library_save') { saved = JSON.parse(p.entry); const item = Object.assign({}, saved, { id: saved.id || saved.type + '-2' }); data = data.filter(x => x.id !== item.id).concat(item); return { success: true, data: { id: item.id, flows: data } }; }
  if (p.action === 'hithean_library_copy') return { success: true, data: { texts: JSON.parse(p.texts), errors: [] } };
  return { success: true, data: { flows: data } };
 }, true);
 await tick(); await tick();
 check(f.calls[0].action === 'hithean_library_list' && f.d.querySelectorAll('[data-hcf-list] button').length === 2, 'one endpoint/list contains both typed records with colliding IDs');
 const type = f.d.querySelector('[data-hcl-type]'); type.value = 'faq'; type.dispatchEvent(new f.w.Event('change'));
 check(f.d.querySelectorAll('[data-hcf-list] button').length === 1 && f.d.querySelector('[data-hcf-list]').textContent.includes('7 lần'), 'type filter shows quick answers and preserved uses');
 f.d.querySelector('[data-hcf-list] button').click(); await tick();
 f.d.querySelector('.hcf-say').click(); await tick(); await tick();
 check(f.calls.find(c => c.action === 'hithean_library_copy').key === 'faq-1' && f.copied[0] === 'Theo địa chỉ anh/chị', 'common copy sends typed key and filled text');
 f.d.querySelector('[data-hcf-q]').value = 'theo dia chi'; f.d.querySelector('[data-hcf-q]').dispatchEvent(new f.w.Event('input'));
 check(f.d.querySelectorAll('[data-hcf-list] button').length === 1, 'common search includes answer text without accents');
 f.d.querySelector('[data-hcf-new]').click();
 const form = f.d.querySelector('.hcf-form'), kind = form.querySelector('[aria-label="Dạng nội dung mới"]');
 check(form.closest('dialog').open && kind.value === 'faq' && [...form.querySelectorAll('button')].find(b => b.textContent === '＋ Thêm bước').hidden, 'FAQ creation opens compact mobile editor with one step');
 form.querySelector('[name=title]').value = 'Nội dung mới'; form.querySelector('.hcf-fstep textarea').value = 'Câu trả lời mới';
 const beforeRefresh = f.calls.filter(c => c.action === 'hithean_library_list').length;
 const update = new f.w.Event('storage'); Object.defineProperty(update, 'key', { value: 'hcf-library-updated' }); f.w.dispatchEvent(update); await tick();
 check(f.d.querySelector('.hcf-form') === form && form.querySelector('[name=title]').value === 'Nội dung mới' && f.calls.filter(c => c.action === 'hithean_library_list').length === beforeRefresh, 'cross-frame refresh preserves in-progress mobile draft');
 kind.value = 'flow'; kind.dispatchEvent(new f.w.Event('change'));
 const more = [...form.querySelectorAll('button')].find(b => b.textContent === '＋ Thêm bước');
 check(!more.hidden, 'script kind enables adding steps in the same editor'); more.click();
 check(form.querySelectorAll('.hcf-fstep').length === 2, 'script may contain multiple steps');
 kind.value = 'faq'; kind.dispatchEvent(new f.w.Event('change'));
 form.dispatchEvent(new f.w.Event('submit', { cancelable: true })); await tick(); await tick();
 check(saved.type === 'faq' && saved.steps.length === 1 && saved.title === 'Nội dung mới', 'saving quick answer submits one common entry, never hidden extra steps');
 check(!f.d.querySelector('dialog[open]') && f.d.querySelector('[data-hcf-detail]').textContent.includes('Nội dung mới'), 'save closes mobile editor and opens saved typed entry');
 [...f.d.querySelectorAll('button')].find(b => b.textContent === '✏️ Sửa').click();
 check(f.d.querySelector('[aria-label="Dạng nội dung mới"]').disabled, 'existing entry kind is immutable to preserve identity/history');
 const staff = boot(library(false), ['consult-modal.js','consult-flows.js'], () => ({ success: true, data: { flows: [entries[0]] } }), true);
 await tick(); await tick(); staff.d.querySelector('[data-hcf-new]').click();
 check(staff.d.querySelector('[aria-label="Dạng nội dung mới"]').options.length === 1 && staff.d.querySelector('[name=status]').disabled, 'staff only creates quick answers under current publication contract');

 const advisor = `<div class="hpa" id="hpa-root"><form data-hpa-form><textarea name="question"></textarea><div data-hpa-faq hidden></div><textarea name="extra"></textarea><div data-hpa-chips></div><input data-hpa-sku><datalist id="hpa-cat"></datalist><select name="reply_length"><option value="medium">Medium</option></select><input type="checkbox" name="labels"><button data-hpa-ask>Ask</button><input type="checkbox" data-hpa-keep checked><b data-hpa-turns></b><button type="button" data-hpa-reset>Reset</button></form><p class="hcf-status" data-hpa-status></p><section data-hpa-out hidden></section></div><script type="application/json" id="hpa-cfg">{"ajax":"/a","nonce":"n","flowNonce":"f","manage":true,"library":true,"catalogue":[]}</script>`;
 let aiSaved;
 const a = boot(advisor, ['consult-modal.js','product-advisor.js'], p => {
  if (p.action === 'hithean_library_save') { aiSaved = JSON.parse(p.entry); return { success: true, data: { id: 'flow-2' } }; }
  if (p.action === 'hithean_library_suggest') return { success: true, data: { items: [{ key: 'flow-1', type: 'flow', q: 'Tư vấn giao hàng', a: 'Script reply' }, { key: 'faq-1', type: 'faq', q: 'Phí ship?', a: 'FAQ reply' }] } };
  if (p.action === 'hithean_library_copy') return { success: true, data: { texts: ['Fresh script reply'], errors: [] } };
  return { success: true, data: { reply: 'AI draft', products: [], flows: [], follow_up_questions: ['Hỏi thêm'], staff_notes: ['Nội bộ'] } };
 }, true);
 const aiForm = a.d.querySelector('[data-hpa-form]'); aiForm.elements.namedItem('question').value = 'Phí giao hàng bao nhiêu?';
 aiForm.elements.namedItem('question').dispatchEvent(new a.w.Event('input')); await new Promise(r => setTimeout(r, 600)); await tick();
 check(a.d.querySelector('[data-hpa-faq]').textContent.includes('Thư viện khớp (2)') && a.calls.some(c => c.action === 'hithean_library_suggest'), 'AI suggests both kinds through shared library lookup');
 a.d.querySelector('[data-hpa-faq] .hcf-say').click(); await tick(); await tick();
 check(a.calls.find(c => c.action === 'hithean_library_copy').key === 'flow-1' && a.copied[0] === 'Fresh script reply', 'suggestion copy refreshes shared library data');
 aiForm.dispatchEvent(new a.w.Event('submit', { cancelable: true })); await tick(); await tick();
 const saveButtons = [...a.d.querySelectorAll('button')].filter(b => /Lưu vào|Lưu thành kịch bản/.test(b.textContent));
 check(saveButtons.length === 1 && saveButtons[0].textContent === '💾 Lưu vào thư viện', 'AI exposes one shared save action');
 a.d.querySelector('.hpa-reply').value = 'Staff edited answer'; saveButtons[0].click();
 const saveKind = a.d.querySelector('[aria-label="Lưu thành"]');
 check(saveKind.options.length === 2 && saveKind.closest('dialog').open, 'common save popup offers quick answer or script'); saveKind.value = 'flow';
 [...saveKind.closest('.hpa-faqsave').querySelectorAll('button')].find(b => b.textContent === '💾 Lưu').click(); await tick(); await tick();
 check(aiSaved.type === 'flow' && aiSaved.status === 'draft' && aiSaved.steps[0].say === 'Staff edited answer' && aiSaved.steps.length === 2, 'AI common save retains staff edit/follow-ups and creates reviewed draft');
 check(!!a.w.localStorage.getItem('hcf-library-updated'), 'AI save notifies other tool frames without storing customer content');
 check(!a.d.querySelector('.hpa-faqsave').closest('dialog') && a.d.querySelector('.hpa-faqsave').textContent.includes('thư viện'), 'successful save closes popup and reports library location');
 console.log(`OK (${checks} unified library UI checks)`);
})().catch(e => { console.error(e); process.exit(1); });

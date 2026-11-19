/* Real consultation clients + shared modal helper, with native dialog and viewport shims. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const assets = path.join(__dirname, '../custom-functions/shortcodes/assets');
const tick = () => new Promise(r => setTimeout(r, 0));
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function boot(html, script, handler) {
    const dom = new JSDOM(html, { url: 'https://example.test', runScripts: 'outside-only' });
    const w = dom.window, d = w.document, listeners = new Set(), calls = [];
    const media = { matches: true, addEventListener: (_, fn) => listeners.add(fn), removeEventListener: (_, fn) => listeners.delete(fn) };
    w.matchMedia = () => media;
    w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    w.HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
    w.HTMLElement.prototype.scrollIntoView = () => {};
    w.fetch = async (_, opts) => { const data = Object.fromEntries(opts.body.entries()); calls.push(data); return { status: 200, json: async () => handler(data) }; };
    w.eval(fs.readFileSync(path.join(assets, 'consult-modal.js'), 'utf8'));
    if (script) w.eval(fs.readFileSync(path.join(assets, script), 'utf8'));
    return { w, d, calls, resize(mobile) { media.matches = mobile; listeners.forEach(fn => fn()); } };
}
(async () => {
    const x = boot('<div class="hpa"><form><textarea name="q"></textarea><button>Save</button></form><p class="hcf-status"></p></div>');
    const original = x.d.querySelector('form'), text = original.querySelector('textarea');
    const editor = x.w.hcfMobileForm(original, { title: 'Test form', cta: 'Open form' });
    const launch = x.d.querySelector('.hcf-mobile-launcher button'), dialog = x.d.querySelector('dialog');
    check(dialog.contains(original) && !dialog.open, 'mobile starts with a CTA and collapsed original form');
    launch.click(); text.value = 'Draft retained';
    check(dialog.open && launch.getAttribute('aria-expanded') === 'true' && x.d.activeElement === text, 'CTA opens dialog and focuses original input');
    x.d.querySelector('.hcf-status').textContent = 'Please fix the input'; x.d.querySelector('.hcf-status').dispatchEvent(new x.w.Event('hcf:status')); await tick();
    check(dialog.querySelector('[role=status]').textContent === 'Please fix the input', 'server feedback remains visible inside modal');
    dialog.querySelector('header button').click();
    check(!dialog.open && x.d.activeElement === launch && text.value === 'Draft retained', 'close restores CTA focus and preserves draft');
    launch.click(); x.resize(false);
    check(!dialog.open && original.parentNode.className === 'hpa' && text.value === 'Draft retained', 'desktop restores the same inline form and draft');
    x.resize(true); launch.click();
    check(dialog.contains(original) && text.value === 'Draft retained', 'returning to mobile keeps controls and values');
    editor.destroy();
    check(!x.d.querySelector('dialog') && !x.d.documentElement.classList.contains('hcf-mobile-open') && original.isConnected, 'destroy cleans up modal and scroll lock');

    const adv = `<div class="hpa" id="hpa-root"><form data-hpa-form><textarea name="question"></textarea><div data-hpa-faq hidden></div><textarea name="extra"></textarea><div data-hpa-chips></div><input data-hpa-sku><datalist id="hpa-cat"></datalist><select name="reply_length"><option value="medium">Vừa</option></select><input type="checkbox" name="labels"><button data-hpa-ask>Ask</button><input type="checkbox" data-hpa-keep checked><b data-hpa-turns></b><button type="button" data-hpa-reset>Reset</button></form><details data-hpa-ref><textarea data-hpa-ref-text></textarea><p data-hpa-ref-meta></p><button data-hpa-ref-save>Save</button></details><p class="hcf-status" data-hpa-status></p><section data-hpa-out hidden></section></div><script type="application/json" id="hpa-cfg">{"ajax":"/a","nonce":"n","flowNonce":"f","manage":true,"catalogue":[]}</script>`;
    let fail = true;
    const a = boot(adv, 'product-advisor.js', data => data.action === 'hithean_advisor_ask' && fail ? { success: false, data: { message: 'AI unavailable' } } : { success: true, data: data.action === 'hithean_faq_save' ? { id: 3 } : { reply: 'Draft response', products: [], suggested: [], flows: [] } });
    const form = a.d.querySelector('[data-hpa-form]'), questionDialog = form.closest('dialog');
    a.d.querySelector('.hcf-mobile-launcher button').click();
    form.elements.namedItem('question').value = 'A customer question';
    form.dispatchEvent(new a.w.Event('submit', { cancelable: true })); await tick(); await tick();
    check(questionDialog.open && questionDialog.querySelector('[role=status]').textContent === 'AI unavailable', 'AI failure keeps modal open with feedback');
    fail = false; form.dispatchEvent(new a.w.Event('submit', { cancelable: true })); await tick(); await tick();
    check(!questionDialog.open && !a.d.querySelector('[data-hpa-out]').hidden && a.d.querySelector('.hpa-reply').value === 'Draft response', 'AI success closes modal and shows editable result on page');
    [...a.d.querySelectorAll('button')].find(b => b.textContent.includes('Lưu vào FAQ')).click();
    const faqDraft = a.d.querySelector('.hpa-faqsave'), faqDialog = faqDraft.closest('dialog');
    check(faqDialog.open && faqDraft.querySelectorAll('textarea').length === 2, 'save AI response to FAQ opens a mobile modal');
    faqDialog.querySelector('header button').click();
    check(faqDraft.querySelector('textarea').value === 'A customer question', 'closing FAQ modal retains question draft');
    a.resize(false);
    check(form.parentNode.id === 'hpa-root' && form.elements.namedItem('question').value === 'A customer question', 'advisor desktop restores original input');

    const flowHtml = '<div class="hcf" id="hcf-root"><input data-hcf-q><select data-hcf-group></select><input data-hcf-name><button data-hcf-new>New</button><p class="hcf-status" data-hcf-status></p><nav data-hcf-list></nav><section data-hcf-detail></section></div><script type="application/json" id="hcf-cfg">{"ajax":"/a","nonce":"n","manage":true}</script>';
    const f = boot(flowHtml, 'consult-flows.js', data => ({ success: true, data: { id: 1, flows: data.action === 'hithean_cflow_save' ? [Object.assign(JSON.parse(data.flow), { id: 1 })] : [] } }));
    await tick(); await tick(); f.d.querySelector('[data-hcf-new]').click();
    const flowForm = f.d.querySelector('.hcf-form'), flowDialog = flowForm.closest('dialog');
    flowForm.querySelector('[name=title]').value = 'Draft script';
    check(flowDialog.open, 'new script CTA opens mobile modal');
    flowDialog.querySelector('header button').click(); f.d.querySelector('[data-hcf-new]').click();
    check(f.d.querySelector('.hcf-form') === flowForm && flowForm.querySelector('[name=title]').value === 'Draft script', 'new script CTA resumes closed draft without recreating form');
    flowForm.querySelector('.hcf-fstep textarea').value = 'Reply';
    flowForm.dispatchEvent(new f.w.Event('submit', { cancelable: true })); await tick(); await tick();
    check(!f.d.querySelector('dialog[open]') && f.d.querySelector('[data-hcf-detail]').textContent.includes('Draft script'), 'saving script closes editor and displays saved script');

    const faqHtml = '<div class="hcf" id="hfq-root"><input data-hfq-q><input data-hfq-name><button data-hfq-new>New</button><p class="hcf-status" data-hfq-status></p><div data-hfq-form-slot></div><div data-hfq-list></div></div><script type="application/json" id="hfq-cfg">{"ajax":"/a","nonce":"n","manage":true}</script>';
    const q = boot(faqHtml, 'consult-faqs.js', () => ({ success: true, data: { items: [] } }));
    await tick(); await tick(); q.d.querySelector('[data-hfq-new]').click();
    const faqForm = q.d.querySelector('form'); faqForm.querySelector('textarea').value = 'Draft FAQ';
    faqForm.closest('dialog').querySelector('header button').click(); q.d.querySelector('[data-hfq-new]').click();
    check(q.d.querySelector('form') === faqForm && faqForm.querySelector('textarea').value === 'Draft FAQ', 'new FAQ CTA reopens original draft');
    [...faqForm.querySelectorAll('button')].find(b => b.textContent === 'Hủy').click();
    check(!q.d.querySelector('dialog') && !q.d.documentElement.classList.contains('hcf-mobile-open'), 'explicit FAQ cancel clears form and modal state');
    console.log(`OK (${checks} mobile consultation checks)`);
})().catch(e => { console.error(e); process.exit(1); });

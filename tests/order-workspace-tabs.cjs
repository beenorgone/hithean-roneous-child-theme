// Run: node tests/order-workspace-tabs.cjs. Controlled DOM; no real tool requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const dir = path.join(__dirname, '../custom-functions/woocommerce/orders/assets');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
const dom = new JSDOM(`<!doctype html><body>
<div class="wrap"><form id="thean-ous-bar"><div role="tablist"><button role="tab" data-order-workspace="orders">Orders</button><button role="tab" data-order-workspace="products">Products</button><button role="tab" data-order-workspace="exports">Exports</button></div><input name="q"><div id="thean-ous-suggest" hidden></div></form><section id="thean-ous-board"><table><tbody><tr data-order-id="5"><td><input value="keep-selection"></td></tr></tbody></table></section></div>
<section id="thean-ous-orders-panel"></section>
<section id="thean-ous-products-panel" hidden><div role="tablist">${['info','compare','protein'].map(key => `<button role="tab" data-product-tool="${key}">${key}</button>`).join('')}</div>${['info','compare','protein'].map(key => `<section data-product-panel="${key}" ${key !== 'info' ? 'hidden' : ''}><p data-product-status></p><button data-product-reload="${key}">Reload</button><iframe data-src="https://example.test/tool?tool=${key}"></iframe></section>`).join('')}</section>
<section id="thean-ous-exports-panel" hidden><div role="tablist">${['export_review','export_waiting','export_upload'].map(key => `<button role="tab" data-product-tool="${key}">${key}</button>`).join('')}</div>${['export_review','export_waiting','export_upload'].map(key => `<section data-product-panel="${key}" ${key !== 'export_review' ? 'hidden' : ''}><p data-product-status></p><button data-product-reload="${key}">Reload</button><iframe data-src="https://example.test/tool?tool=${key}"></iframe></section>`).join('')}</section>
<nav id="thean-workspace-foot" hidden><button data-order-workspace="orders">Orders</button><button data-order-workspace="products">Products</button><button data-order-workspace="exports">Exports</button></nav>
<button id="guide-orders" data-thean-ous-workflow-guide>Guide</button><button id="guide-shopee" data-thean-ous-workflow-guide="shopee">Shopee guide</button><button id="guide-exports" data-thean-ous-workflow-guide="exports">Export guide</button><button id="guide-products" data-thean-ous-workflow-guide="products">Products guide</button>
<div id="thean-ous-workflow-guide" class="thean-ous-modal" hidden><div data-thean-ous-close></div><div class="thean-ous-modal__dialog" role="dialog"><button data-thean-ous-close>Close</button><h2 id="thean-ous-workflow-title"></h2>${['orders','shopee','products','exports'].map(scope => `<section data-workflow-guide-scope="${scope}">Guide ${scope}</section>`).join('')}<button data-thean-ous-close>Done</button></div></div>
<section id="results" hidden></section><section id="preview" hidden></section><section id="invoice" hidden><iframe></iframe></section><section id="acts" hidden></section>
</body>`, { url: 'https://example.test/wp-admin/edit.php?post_type=shop_order&thean_board=cod', runScripts: 'outside-only' });
const w = dom.window, d = w.document, errors = [];
w.addEventListener('error', event => errors.push(event.error || event.message));
w.eval(fs.readFileSync(path.join(dir, 'order-workspace-tabs.js'), 'utf8'));
const rows = d.querySelector('#thean-ous-board tr'), boardInput = rows.querySelector('input');
const frames = Array.from(d.querySelectorAll('[data-product-panel] iframe'));
check(frames.every(frame => !frame.getAttribute('src')), 'order-first navigation does not request product tools');
check(d.querySelector('#thean-ous-orders-panel').contains(rows), 'board belongs to order panel');
d.querySelector('#thean-workspace-foot [data-order-workspace="products"]').click();
check(d.querySelector('#thean-ous-orders-panel').hidden && !d.querySelector('#thean-ous-products-panel').hidden, 'sticky Products opens product workspace');
check(frames[0].src.includes('tool=info') && !frames[1].getAttribute('src') && !frames[2].getAttribute('src'), 'loads only the selected shortcode frame');
check(w.location.search.includes('thean_board=cod') && w.location.search.includes('thean_workspace=products'), 'product navigation preserves order tab URL');
const keepFrame = frames[0];keepFrame.dataset.inputState = 'keep-tool-state';
d.querySelector('[data-product-tool="compare"]').click();
check(frames[1].src.includes('tool=compare') && frames[0] === keepFrame && keepFrame.dataset.inputState === 'keep-tool-state', 'subtab change retains first iframe and state');
d.querySelector('[data-product-tool="compare"]').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
check(d.querySelector('[data-product-tool="protein"]').getAttribute('aria-selected') === 'true' && frames[2].src.includes('tool=protein'), 'keyboard moves between product subtabs');
d.querySelector('#thean-workspace-foot [data-order-workspace="orders"]').click();
check(!d.querySelector('#thean-ous-orders-panel').hidden && d.querySelector('#thean-ous-board tr') === rows && boardInput.value === 'keep-selection', 'return to orders preserves board nodes and entered state');
check(!w.location.search.includes('thean_workspace=') && w.location.search.includes('thean_board=cod'), 'return to orders removes product URL scope only');
w.TheanOrderWorkspace.show('products');
check(d.querySelector('[data-product-tool="protein"]').getAttribute('aria-selected') === 'true' && frames[0] === keepFrame, 'reopening Products remembers active tool');
// Export tools use their own selected key and preserve upload forms across tab changes.
const exportFrames = Array.from(d.querySelectorAll('#thean-ous-exports-panel iframe'));
check(exportFrames.every(frame => !frame.getAttribute('src')), 'exports have no requests before selection');
d.querySelector('#thean-workspace-foot [data-order-workspace="exports"]').click();
check(!d.getElementById('thean-ous-exports-panel').hidden && d.getElementById('thean-ous-products-panel').hidden && d.getElementById('thean-ous-orders-panel').hidden, 'sticky Export opens a separate workspace');
check(exportFrames[0].src.includes('export_review') && !exportFrames[1].getAttribute('src') && !exportFrames[2].getAttribute('src'), 'review loads by default without fetching uploads or waiting list');
check(w.location.search.includes('thean_workspace=exports') && w.location.search.includes('thean_export_tool=export_review') && !w.location.search.includes('thean_product_tool='), 'export URL scope is separate from product tool');
d.querySelector('[data-product-tool="export_review"]').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
check(d.querySelector('[data-product-tool="export_waiting"]').getAttribute('aria-selected') === 'true' && exportFrames[1].src.includes('export_waiting'), 'waiting list is second in keyboard order');
d.querySelector('[data-product-tool="export_upload"]').click();
const uploadFrame = exportFrames[2];
uploadFrame.contentDocument.open(); uploadFrame.contentDocument.write('<!doctype html><html><body></body></html>'); uploadFrame.contentDocument.close();
const draft = uploadFrame.contentDocument.createElement('input'); draft.type = 'file'; uploadFrame.contentDocument.body.appendChild(draft);
Object.defineProperty(draft, 'files', { value: [new w.File(['image'], 'export.jpg', { type: 'image/jpeg' })] });
d.querySelector('[data-product-tool="export_review"]').click();
let listRefreshes = 0; exportFrames[0].contentWindow.uexeRefreshLists = force => { if (force) listRefreshes++; };
w.TheanOrderWorkspace.show('orders'); w.TheanOrderWorkspace.show('exports');
check(listRefreshes === 1 && exportFrames[0].getAttribute('src'), 'returning to export list invokes read-only refresh rather than iframe reload');
d.querySelector('[data-product-tool="export_upload"]').click();
check(exportFrames[2] === uploadFrame && uploadFrame.contentDocument.querySelector('input[type="file"]') === draft && draft.files[0].name === 'export.jpg', 'switching subtabs retains selected file and original upload form');
w.TheanOrderWorkspace.show('products');
check(d.querySelector('[data-product-tool="protein"]').getAttribute('aria-selected') === 'true' && frames[0] === keepFrame, 'export navigation does not reset active product tool');
w.TheanOrderWorkspace.show('exports');
check(d.querySelector('[data-product-tool="export_upload"]').getAttribute('aria-selected') === 'true', 'each tool workspace retains its own selected subtab');
// Run production modal helpers/listeners on the same DOM.
const source = fs.readFileSync(path.join(dir, 'order-universal-search.js'), 'utf8');
const ctx = vm.createContext({ document: d, window: w });
Object.assign(ctx, { guide: d.getElementById('thean-ous-workflow-guide'), guideOpener: null, results: d.getElementById('results'), preview: d.getElementById('preview'), invoice: d.getElementById('invoice'), acts: d.getElementById('acts'), bar: d.getElementById('thean-ous-bar'), suggest: d.getElementById('thean-ous-suggest'), menuList: { hidden: true }, cfg: {}, input: d.querySelector('input[name="q"]') });
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
vm.runInContext(section('    function setOpen(', '    function body('), ctx);
const clickStart = source.lastIndexOf("    document.addEventListener('click', function (e) {\n        var guideButton");
vm.runInContext(source.slice(clickStart, source.lastIndexOf('}());')), ctx);
for (const scope of ['orders','shopee','products','exports']) {
 const button = d.getElementById('guide-' + scope);button.focus();button.click();
 const guide = ctx.guide;
 check(!guide.hidden && !guide.querySelector(`[data-workflow-guide-scope="${scope}"]`).hidden, `${scope} button opens the correct guide`);
 check(Array.from(guide.querySelectorAll('[data-workflow-guide-scope]')).filter(el => !el.hidden).length === 1, 'only selected guide is visible');
 const close = guide.querySelector('button[data-thean-ous-close]'), done = guide.querySelectorAll('button[data-thean-ous-close]')[1];
 check(d.activeElement === close, 'opening guide focuses close button');
 close.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
 check(d.activeElement === done, 'guide traps reverse keyboard focus');
 d.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
 check(guide.hidden && d.activeElement === button && !d.body.classList.contains('thean-ous-open'), 'Escape closes guide and restores opener focus');
}
check(errors.length === 0, 'no uncaught DOM events');
console.log(`OK (${checks} workspace and guide DOM checks)`);
dom.window.close();

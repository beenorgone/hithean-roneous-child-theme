// Run: node tests/order-ship-tab.cjs. Tab 🚚 Đối soát vận chuyển (trang nhúng hub): 2 tab con, mỗi tab con xin URL
// ký 1 lần qua AJAX child_theme_hub_ship_url kèm view, chỉ khi mở; tải lại xin token mới.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dir = path.join(__dirname, '../custom-functions/woocommerce/orders/assets');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
const sub = key => `<section data-product-panel="${key}" data-src-action="child_theme_hub_ship_url" data-src-view="${key}" data-src-ajax="https://shop.test/wp-admin/admin-ajax.php" data-src-nonce="n1" ${key !== 'std' ? 'hidden' : ''}><p data-product-status></p><button data-product-reload="${key}">Reload</button><iframe></iframe></section>`;
const dom = new JSDOM(`<!doctype html><body>
<form id="thean-ous-bar"><div role="tablist"><button type="button" role="tab" data-order-workspace="orders">Orders</button><button type="button" role="tab" data-order-workspace="products">Products</button><button type="button" role="tab" data-order-workspace="ship">Ship</button></div></form>
<section id="thean-ous-board"></section><section id="thean-ous-orders-panel"></section>
<section id="thean-ous-products-panel" hidden><div role="tablist"><button role="tab" data-product-tool="info">info</button></div><section data-product-panel="info"><p data-product-status></p><iframe data-src="https://shop.test/tool?tool=info"></iframe></section></section>
<section id="thean-ous-ship-panel" hidden><div role="tablist"><button role="tab" data-product-tool="std">Tiêu chuẩn</button><button role="tab" data-product-tool="ahamove">Ahamove</button></div>${sub('std')}${sub('ahamove')}</section>
<nav id="thean-workspace-foot" hidden><button data-order-workspace="orders">Orders</button></nav>
</body>`, { url: 'https://shop.test/quan-ly-don/', runScripts: 'outside-only' });
const w = dom.window, d = w.document, errors = [], calls = [];
w.addEventListener('error', event => errors.push(event.error || event.message));
let n = 0;
w.fetch = (url, opts) => {
    const fd = opts.body;
    calls.push({ url, action: fd.get('action'), view: fd.get('view'), nonce: fd.get('nonce') });
    n++;
    return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { url: `https://hub.test/eim-embed/shipping/?t=tok${n}` } }) });
};
w.eval(fs.readFileSync(path.join(dir, 'order-workspace-tabs.js'), 'utf8'));
const tick = () => new Promise(r => setTimeout(r, 0));
(async () => {
    const ship = d.getElementById('thean-ous-ship-panel');
    const frames = Array.from(ship.querySelectorAll('iframe'));
    check(calls.length === 0 && frames.every(f => !f.getAttribute('src')), 'không xin token khi chưa mở tab');
    d.querySelector('[data-order-workspace="ship"]').click();
    await tick(); await tick();
    check(!ship.hidden && d.getElementById('thean-ous-orders-panel').hidden, 'mở tab Đối soát vận chuyển');
    check(calls.length === 1 && calls[0].action === 'child_theme_hub_ship_url' && calls[0].view === 'std' && calls[0].nonce === 'n1', 'tab con Tiêu chuẩn mặc định, xin URL kèm view=std');
    check(frames[0].src === 'https://hub.test/eim-embed/shipping/?t=tok1' && !frames[1].getAttribute('src'), 'chỉ tải khung Tiêu chuẩn');
    check(w.location.search.includes('thean_workspace=ship') && w.location.search.includes('thean_ship_tool=std'), 'URL nhớ tab + tab con');
    d.querySelector('[data-product-tool="ahamove"]').click();
    await tick(); await tick();
    check(calls.length === 2 && calls[1].view === 'ahamove' && frames[1].src.endsWith('tok2'), 'tab con Ahamove xin URL view=ahamove');
    check(ship.querySelector('[data-product-panel="std"]').hidden && !ship.querySelector('[data-product-panel="ahamove"]').hidden, 'chỉ hiện tab con đang chọn');
    d.querySelector('[data-product-tool="std"]').click();
    await tick();
    check(calls.length === 2 && frames[0].src.endsWith('tok1'), 'quay lại tab con đã tải: giữ khung, không xin token mới');
    d.querySelector('[data-product-reload="std"]').click();
    await tick(); await tick();
    check(calls.length === 3 && calls[2].view === 'std' && frames[0].src.endsWith('tok3'), 'Tải lại xin token mới');
    w.TheanOrderWorkspace.show('orders');
    w.TheanOrderWorkspace.show('ship');
    await tick();
    check(calls.length === 3 && ship.querySelector('[data-product-tool="std"]').getAttribute('aria-selected') === 'true', 'đổi tab chính rồi quay lại: giữ tab con, không tải lại');
    // Mở thẳng bằng URL ?thean_workspace=ship&thean_ship_tool=ahamove.
    const dom2 = new JSDOM(dom.serialize(), { url: 'https://shop.test/quan-ly-don/?thean_workspace=ship&thean_ship_tool=ahamove', runScripts: 'outside-only' });
    const calls2 = [];
    dom2.window.fetch = (url, opts) => { calls2.push(opts.body.get('view')); return Promise.resolve({ json: () => Promise.resolve({ success: false, data: { message: 'Hub chưa bật nhúng' } }) }); };
    dom2.window.document.querySelectorAll('iframe').forEach(f => f.removeAttribute('src'));
    dom2.window.eval(fs.readFileSync(path.join(dir, 'order-workspace-tabs.js'), 'utf8'));
    await tick(); await tick();
    const st = dom2.window.document.querySelector('#thean-ous-ship-panel [data-product-panel="ahamove"] [data-product-status]');
    check(calls2.join() === 'ahamove', 'link sâu mở thẳng tab con Ahamove');
    check(!st.hidden && st.textContent.includes('Hub chưa bật nhúng'), 'lỗi từ hub hiện ở dòng trạng thái');
    dom2.window.close();
    // Tab con Shopee (module shopee-export cùng site): iframe data-src cố định, không gọi AJAX; site không có hub → tab con đầu = shopee.
    const dom3 = new JSDOM(`<!doctype html><body>
<form id="thean-ous-bar"><div role="tablist"><button type="button" role="tab" data-order-workspace="orders">Orders</button><button type="button" role="tab" data-order-workspace="ship">Ship</button></div></form>
<section id="thean-ous-board"></section><section id="thean-ous-orders-panel"></section>
<section id="thean-ous-products-panel" hidden></section>
<section id="thean-ous-ship-panel" data-default-tool="shopee" hidden><div role="tablist"><button type="button" role="tab" data-product-tool="shopee">Shopee</button></div><section data-product-panel="shopee"><p data-product-status></p><button type="button" data-product-reload="shopee">Reload</button><iframe data-src="https://shop.test/xuat-kho-shopee/?spx_only=reconcile"></iframe></section></section>
<nav id="thean-workspace-foot" hidden><button type="button" data-order-workspace="orders">Orders</button></nav>
</body>`, { url: 'https://shop.test/quan-ly-don/', runScripts: 'outside-only' });
    let fetched3 = 0;
    dom3.window.fetch = () => { fetched3++; return Promise.reject(new Error('no fetch')); };
    dom3.window.eval(fs.readFileSync(path.join(dir, 'order-workspace-tabs.js'), 'utf8'));
    const d3 = dom3.window.document, f3 = d3.querySelector('#thean-ous-ship-panel iframe');
    check(!f3.getAttribute('src'), 'Shopee: chưa mở tab thì chưa tải');
    d3.querySelector('[data-order-workspace="ship"]').click();
    await tick();
    check(f3.src === 'https://shop.test/xuat-kho-shopee/?spx_only=reconcile' && fetched3 === 0, 'Shopee: tab con mặc định theo data-default-tool, tải data-src, không xin token');
    check(dom3.window.location.search.includes('thean_ship_tool=shopee'), 'Shopee: URL nhớ tab con');
    dom3.window.close();
    check(errors.length === 0, 'không có lỗi DOM');
    console.log(`OK (${checks} ship tab checks)`);
    dom.window.close();
})().catch(e => { console.error(e); process.exit(1); });

/* UI thanh ưu đãi đầu trang (marketing/assets/offers-topbar.js). Chạy: node tests/offers-topbar-ui.cjs (cần jsdom). */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const js = fs.readFileSync(path.join(__dirname, '../custom-functions/marketing/assets/offers-topbar.js'), 'utf8');
let count = 0;
function ok(cond, msg) { count++; if (!cond) { console.error('FAIL: ' + msg); process.exit(1); } }
const html = `<!doctype html><html class="hpt-on"><body>
<div class="hpt" id="hithean-topbar" data-hpt data-hpt-key="abc"><div class="hpt__inner">
<div class="hpt__msgs"><p class="hpt__msg is-active" data-hpt-msg>A</p><p class="hpt__msg" data-hpt-msg aria-hidden="true">B</p></div>
<button data-hpt-open>Xem chi tiết</button><button data-hpt-close>×</button></div></div>
<dialog id="hpt-dialog"><p data-hpt-meta></p><button data-hpt-dialog-close>×</button><ul data-hpt-list></ul></dialog></body></html>`;

(async () => {
    const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://hithean.test/' });
    const w = dom.window;
    let fetched = 0;
    w.hitheanTopbar = { ajaxUrl: '/wp-admin/admin-ajax.php' };
    w.fetch = (url) => { fetched++; ok(url.includes('action=hithean_offers_topbar'), 'Gọi đúng action'); return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { meta: '2 chương trình đang áp dụng', html: '<li class="hithean-offer-card"><a class="hithean-offer-card__action" href="/san-pham/x/">Xem sản phẩm ↗</a></li>' } }) }); };
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
    let tick = null;
    w.setInterval = (fn) => { tick = fn; return 1; };
    w.eval(js);
    const d = w.document;
    ok(typeof tick === 'function', 'Nhiều dòng → bật xoay vòng');
    tick();
    const msgs = d.querySelectorAll('[data-hpt-msg]');
    ok(msgs[1].classList.contains('is-active') && !msgs[0].classList.contains('is-active') && msgs[0].getAttribute('aria-hidden') === 'true', 'Xoay sang dòng 2');
    d.getElementById('hithean-topbar').dispatchEvent(new w.Event('mouseenter')); tick();
    ok(msgs[1].classList.contains('is-active'), 'Rê chuột → dừng xoay');
    d.querySelector('[data-hpt-open]').click();
    ok(d.getElementById('hpt-dialog').hasAttribute('open'), 'Bấm "Xem chi tiết" mở popup');
    await new Promise((r) => setTimeout(r, 10));
    ok(d.querySelector('[data-hpt-list] .hithean-offer-card__action').getAttribute('href') === '/san-pham/x/' && d.querySelector('[data-hpt-meta]').textContent === '2 chương trình đang áp dụng', 'Popup hiện thẻ chương trình + CTA Xem sản phẩm');
    d.querySelector('[data-hpt-dialog-close]').click();
    d.querySelector('[data-hpt-open]').click();
    ok(fetched === 1, 'Mở lại không tải lại');
    d.querySelector('[data-hpt-close]').click();
    ok(!d.getElementById('hithean-topbar') && !d.documentElement.classList.contains('hpt-on') && w.sessionStorage.getItem('hpt-off') === 'abc', 'Đóng thanh: gỡ thanh, bỏ chừa chỗ, nhớ khóa nội dung trong phiên');
    // Ẩn nút CTA: bấm thông điệp mở popup.
    const dom2 = new JSDOM(`<!doctype html><html><body><div id="hithean-topbar" data-hpt-key="k"><div class="hpt__msgs"><p data-hpt-msg class="is-active"><button class="hpt__text hpt__text--open" data-hpt-open>Mua 2 tặng 1</button></p></div><button data-hpt-close>×</button></div>
<dialog id="hpt-dialog"><p data-hpt-meta></p><button data-hpt-dialog-close>×</button><ul data-hpt-list></ul></dialog></body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://hithean.test/' });
    const w2 = dom2.window;
    w2.hitheanTopbar = { ajaxUrl: '/wp-admin/admin-ajax.php' };
    w2.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: true, data: { meta: '1 chương trình', html: '<li>x</li>' } }) });
    w2.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w2.eval(js);
    w2.document.querySelector('.hpt__text--open').click();
    ok(w2.document.getElementById('hpt-dialog').hasAttribute('open'), 'Ẩn nút CTA: bấm thông điệp mở popup chương trình');
    console.log(`PASS: ${count} offers-topbar UI assertions`);
})();

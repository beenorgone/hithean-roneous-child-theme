// Run: node tests/loop-offers-ui.cjs (requires jsdom and jquery).
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const shared = fs.readFileSync('custom-functions/marketing/assets/product-offers.js', 'utf8');
const loop = fs.readFileSync('custom-functions/marketing/assets/loop-offers.js', 'utf8');
const summary = `<div class="summary"><h1 class="product_title">Cacao</h1><form class="cart"><input class="qty" name="quantity" value="1" min="1" max="24"><button class="single_add_to_cart_button">Mua</button></form>
<div data-product-addon-container><h4>Ưu đãi</h4><div data-choice-row data-mode="auto" data-min-qty="2"><span class="product_addon-title">Quà kèm</span><span class="product_addon-price">0 đ</span></div></div>
<table class="awdr-bulk-customizable-table"><tr class="bulk_table_row"><td class="wdr_bulk_range">2–5</td><td class="wdr_bulk_title">Giảm 10%</td><td class="wdr_table_discounted_price">90.000 đ</td></tr><tr class="bulk_table_row"><td class="wdr_bulk_range">6+</td><td class="wdr_bulk_title">Giảm 20%</td><td class="wdr_table_discounted_price">80.000 đ</td></tr></table>
<div class="awdr_discount_bar"><span class="awdr_discount_bar_content">Miễn phí vận chuyển từ 500.000 đ</span></div></div>`;
const config = { offerOrder: ['discount', 'tiers', 'gift', 'wheel', 'shipping'], offerMedia: { product: 'https://hithean.test/cacao.jpg' }, offersUrl: '/uu-dai/' };
const wheel = '<div id="thean-lw-root"><button class="thean-lw-trigger"><span class="thean-lw-trigger__text">Nhận ưu đãi</span></button></div>';
const page = `<div class="product-single"><div class="product">${summary}</div></div>${wheel}<script type="application/json" id="hithean-product-offers-config">${JSON.stringify(config)}</script><script>throw new Error('Fetched scripts must not execute');</script>`;
function environment(html) {
    const dom = new JSDOM(html, { url: 'https://hithean.test/danh-muc/cacao/', runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    w.jQuery = require('jquery')(w);
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
    w.eval(shared);
    return w;
}
const tick = () => new Promise(resolve => setTimeout(resolve, 10));
(async () => {
    const detail = environment(page);
    const api = detail.hitheanSetupProductOffers(detail.document.querySelector('.summary'), config);
    api.open();
    const expected = detail.document.querySelector('[data-offers-list]').innerHTML;
    assert.equal(detail.document.querySelectorAll('.hithean-offer-card').length, 4);
    const w = environment('<button data-hlo="7" data-hlo-url="https://hithean.test/sp/cacao/">Ưu đãi</button><button data-hlo="8" data-hlo-url="https://hithean.test/sp/empty/">Khác</button>');
    let requests = 0;
    w.fetch = async url => { requests++; return { ok: true, url, text: async () => url.includes('empty') ? page.replace(summary, '<div class="summary"></div>').replace(wheel, '') : page }; };
    w.eval(loop);
    const button = w.document.querySelector('[data-hlo="7"]');
    button.click(); await tick();
    assert.equal(w.document.querySelector('[data-offers-list]').innerHTML, expected, 'category and product detail render identical cards from the same plugin sources');
    assert.ok(w.document.querySelector('dialog[open]'));
    w.document.querySelector('[data-offers-tiers]').click();
    assert.ok(w.document.querySelector('.hithean-offers-qty').open, 'same quantity picker works in category');
    assert.equal(w.document.querySelectorAll('[data-qty-pick]').length, 2);
    w.document.querySelector('[data-qty-pick="6"]').click();
    assert.ok(w.document.querySelector('[data-qty-status]').textContent.includes('80.000 đ'));
    w.document.querySelector('.hithean-offers-qty').close();
    w.document.querySelector('[data-offers-close]').click();
    assert.equal(w.document.activeElement, button, 'focus returns to actual catalog button');
    assert.ok(!w.document.documentElement.classList.contains('hithean-offers-open'));
    button.click(); await tick();
    assert.equal(requests, 1, 'reopening uses bounded source cache');
    assert.equal(w.document.querySelectorAll('dialog').length, 2, 'old product instance is destroyed');
    w.document.querySelector('[data-hlo="8"]').click(); await tick();
    assert.ok(w.document.querySelector('dialog[open]').textContent.includes('Hiện chưa có chương trình ưu đãi nào cho sản phẩm này.'), 'no unrelated store offers for an empty product');
    assert.equal(w.document.querySelectorAll('.hithean-offer-card').length, 0);
    const failure = environment('<button data-hlo="7" data-hlo-url="/sp/cacao/">Ưu đãi</button>');
    failure.fetch = async () => { throw new Error('offline'); };
    failure.eval(loop); failure.document.querySelector('button').click(); await tick();
    assert.ok(failure.document.querySelector('dialog').textContent.includes('Không tải được ưu đãi'));
    assert.equal(failure.document.querySelector('dialog a').href, 'https://hithean.test/sp/cacao/#uu-dai');
    const race = environment('<button data-hlo="7" data-hlo-url="/sp/cacao/">Ưu đãi</button>');
    let resolve;
    race.fetch = () => new Promise(done => { resolve = done; });
    race.eval(loop); race.document.querySelector('button').click(); race.document.querySelector('dialog').close();
    resolve({ ok: true, text: async () => page }); await tick();
    assert.equal(race.document.querySelectorAll('dialog[open]').length, 0, 'closing during fetch cannot reopen popup');
    const compact = environment('<button data-hlo="7" data-hlo-url="/sp/cacao/">Ưu đãi</button>');
    let compactRequests = 0;
    compact.fetch = async url => {
        compactRequests++;
        assert.equal(new URL(url).searchParams.get('hithean_offer_fragment'), '1', 'requests lightweight product response');
        return { ok: true, url, text: async () => JSON.stringify({ success: true, data: { html: summary + wheel, config: { ...config, offersAvailable: true } } }) };
    };
    compact.eval(loop);
    assert.equal(compactRequests, 0, 'no bulk background loading on catalog open');
    compact.document.querySelector('button').dispatchEvent(new compact.Event('pointerdown', { bubbles: true }));
    compact.document.querySelector('button').click(); await tick();
    assert.equal(compactRequests, 1, 'intent prefetch and click share one request');
    assert.equal(compact.document.querySelector('[data-offers-list]').innerHTML, expected, 'compact response keeps exact product cards');
    const unavailable = environment('<button data-hlo="7" data-hlo-url="/sp/cacao/">Ưu đãi</button>');
    unavailable.fetch = async url => ({ ok: true, url, text: async () => JSON.stringify({ success: true, data: { html: '<div class="summary"></div>', config: { offersAvailable: false } } }) });
    unavailable.eval(loop); unavailable.document.querySelector('button').click(); await tick();
    assert.equal(unavailable.document.querySelectorAll('[data-hlo], dialog[open]').length, 0, 'fresh stock response removes stale cached catalog triggers');
    assert.equal(unavailable.document.documentElement.classList.contains('hithean-offers-open'), false);
    const legacy = environment('<button data-hlo="7" data-hlo-url="/sp/cacao/">Ưu đãi</button>');
    legacy.fetch = async url => ({ ok: true, url, text: async () => '<div class="product-single"><section><div class="product">' + summary + '</div></section></div>' + wheel + '<script>var hitheanProductPurchase = ' + JSON.stringify(config) + ';</script>' });
    legacy.eval(loop); legacy.document.querySelector('button').click(); await tick();
    assert.equal(legacy.document.querySelector('[data-offers-list]').innerHTML, expected, 'old cached markup and localized config do not fail');
    console.log('PASS: shared product/category cards, tier picker, focus, cache, empty state, failure and close-during-fetch');
})().catch(error => { console.error(error); process.exitCode = 1; });

// Run: node tests/site-footer-menu-ui.cjs (requires jsdom).
// Thanh menu đáy + panel "Ưu đãi của tôi" (custom-functions/marketing/assets/site-footer-menu.js).
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const php = fs.readFileSync(path.join(__dirname, '../custom-functions/marketing/site-footer-menu.php'), 'utf8');
// Lấy markup panel trong hithean_sfm_render_panel(), bỏ đoạn PHP.
const dialogMarkup = (open) => { const start = php.indexOf(open); return php.slice(start, php.indexOf('</dialog>', start) + 9).replace(/<\?php[\s\S]*?\?>/g, ''); };
const panel = dialogMarkup('<dialog class="hmo"');
// Popup "Ưu đãi tiêu biểu" (hithean_sfm_render_offers) + thẻ vòng quay tĩnh như khi trang có vòng quay.
const offers = dialogMarkup('<dialog class="hithean-offers-dialog sfm-offers"').replace('<ul class="hithean-offers-dialog__list" data-sfm-offers-list>', '$&<li class="hithean-offer-card"><button data-sfm-wheel>Quay ngay</button></li>');
const html = `<!DOCTYPE html><body class="has-sfm"><div id="sfm-root"><a href="/gio-hang/" class="sfm__cart" data-atc-cart-open></a><a href="/uu-dai/" data-sfm-offers-open>Nhận ưu đãi</a><button data-hmo-open aria-haspopup="dialog"><span data-hmo-badge hidden></span>Ưu đãi của tôi</button></div>${panel}${offers}<div id="thean-lw-root"><button class="thean-lw-trigger"></button></div></body>`;

function setup(extra, responses) {
    const dom = new JSDOM(html.replace('<body class="has-sfm">', '<body class="has-sfm">' + (extra || '')), { url: 'https://hithean.test/blog/', runScripts: 'outside-only' });
    const w = dom.window;
    w.hitheanSfm = { ajaxUrl: '/wp-admin/admin-ajax.php', loginUrl: '/tai-khoan/?hmo_return=%2Fblog%2F', cartUrl: '/gio-hang/' };
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
    const calls = [];
    w.fetch = async (url, opts) => {
        const action = opts.body.get('action');
        calls.push({ action, nonce: opts.body.get('nonce'), contact: opts.body.get('contact') });
        const reply = responses[action] || { success: true, data: {} };
        return { json: async () => reply };
    };
    w.eval(fs.readFileSync(path.join(__dirname, '../custom-functions/marketing/assets/site-footer-menu.js'), 'utf8'));
    return { w, d: w.document, calls };
}
const tick = () => new Promise((r) => setTimeout(r, 0));
function check(ok, msg) { if (!ok) throw Error(msg); console.log('PASS ' + msg); }

(async () => {
    // Khách chưa có gì: hiện đăng nhập + ô kiểm tra nhanh, không hiện "chưa có mã".
    const guest = { success: true, data: { loggedIn: false, contacts: [], count: 0, html: '', nonce: 'n1' } };
    const found = { success: true, data: { loggedIn: false, contacts: [{ index: 0, type: 'phone', label: '091•••••78' }], count: 1, nonce: 'n2',
        html: '<li class="hithean-offer-card"><button data-hmo-login>Đăng nhập bằng SĐT để xem mã</button></li>' } };
    let t = setup('', { hithean_my_offers_load: guest, hithean_my_offers_lookup: found });
    t.d.querySelector('[data-hmo-open]').click();
    await tick(); await tick();
    const dlg = t.d.getElementById('hmo-dialog');
    check(dlg.hasAttribute('open') && t.d.documentElement.classList.contains('hmo-open'), 'menu button opens the panel');
    check(t.calls[0].action === 'hithean_my_offers_load' && !t.calls[0].nonce, 'panel loads over AJAX without a page-embedded nonce');
    check(!t.d.querySelector('[data-hmo-auth]').hidden && t.d.querySelector('[data-hmo-empty]').hidden, 'new guest sees login + quick check, not an empty state');
    t.d.querySelector('#hmo-contact').value = '0912345678';
    t.d.querySelector('[data-hmo-lookup]').dispatchEvent(new t.w.Event('submit', { cancelable: true }));
    await tick(); await tick();
    check(t.calls[1].action === 'hithean_my_offers_lookup' && t.calls[1].contact === '0912345678' && t.calls[1].nonce === 'n1', 'quick check sends the contact');
    check(dlg.querySelectorAll('.hithean-offer-card').length === 1 && !t.d.querySelector('[data-hmo-contacts]').hidden && t.d.querySelector('.hmo__chip').textContent.includes('091•••••78'), 'found offers and the remembered contact are shown');
    check(t.d.querySelector('[data-hmo-auth-title]').textContent.includes('mã đầy đủ'), 'guest with masked codes is prompted to log in for full codes');
    check(t.d.querySelector('[data-hmo-badge]').textContent === '1' && !t.d.querySelector('[data-hmo-badge]').hidden, 'menu badge shows the offer count');

    // "Nhận ưu đãi": mở popup kiểu trang SP, tải thẻ tiêu biểu 1 lần, giữ thẻ vòng quay, có nút xem toàn bộ /uu-dai/.
    const featured = { success: true, data: { count: 2, html: '<li class="hithean-offer-card">A</li><li class="hithean-offer-card">B</li>' } };
    t = setup('', { hithean_sfm_featured: featured });
    check(t.d.body.classList.contains('sfm-wheel-in-offers'), 'floating wheel trigger is folded into the offers popup, as on product pages');
    const offersBtn = t.d.querySelector('[data-sfm-offers-open]');
    offersBtn.click();
    await tick(); await tick();
    const od = t.d.getElementById('sfm-offers-dialog');
    check(od.hasAttribute('open') && t.d.documentElement.classList.contains('hithean-offers-open') && t.calls[0].action === 'hithean_sfm_featured', '"Nhận ưu đãi" opens the featured-offers popup and loads it');
    check(od.querySelectorAll('.hithean-offer-card').length === 3 && !od.querySelector('[data-sfm-offers-loading]') && od.querySelector('[data-sfm-offers-meta]').textContent.startsWith('3 '), 'featured cards follow the wheel card');
    check(od.querySelector('.sfm-offers__all').getAttribute('href') !== null, 'popup has a "see all offers" button');
    od.querySelector('[data-sfm-offers-close]').click();
    offersBtn.click();
    await tick();
    check(t.calls.filter((c) => c.action === 'hithean_sfm_featured').length === 1, 'featured offers load only once');
    let spun = false;
    t.d.querySelector('.thean-lw-trigger').addEventListener('click', () => { spun = true; });
    od.querySelector('[data-sfm-wheel]').click();
    check(spun && !od.hasAttribute('open'), '"Quay ngay" closes the popup and opens the wheel');
    check(t.d.querySelector('#sfm-root .sfm__cart[data-atc-cart-open]') !== null, 'cart button opens the cart popup');

    // Đã đăng nhập: không hiện khối đăng nhập; trang có thanh đáy riêng → bỏ thanh này.
    const member = { success: true, data: { loggedIn: true, name: 'An', contacts: [], count: 0, html: '', nonce: 'n3' } };
    t = setup('<div class="anc-sticky-menu"></div>', { hithean_my_offers_load: member });
    check(!t.d.getElementById('sfm-root') && !t.d.body.classList.contains('has-sfm'), 'bar steps aside on pages with their own bottom bar');
    const opener = t.d.createElement('button'); opener.setAttribute('data-hmo-open', ''); t.d.body.appendChild(opener); opener.click();
    await tick(); await tick();
    check(t.d.querySelector('[data-hmo-auth]').hidden && !t.d.querySelector('[data-hmo-empty]').hidden && t.d.querySelector('[data-hmo-meta]').textContent.includes('An'), 'logged-in customer sees a greeting and an empty state instead of login');
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });

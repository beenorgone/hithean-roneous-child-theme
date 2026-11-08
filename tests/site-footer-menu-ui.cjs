// Run: node tests/site-footer-menu-ui.cjs (requires jsdom).
// Thanh menu đáy + panel "Ưu đãi của tôi" (custom-functions/marketing/assets/site-footer-menu.js).
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const php = fs.readFileSync(path.join(__dirname, '../custom-functions/marketing/site-footer-menu.php'), 'utf8');
// Lấy markup panel trong hithean_sfm_render_panel(), bỏ đoạn PHP.
const panel = php.slice(php.indexOf('<dialog class="hmo"'), php.indexOf('</dialog>') + 9).replace(/<\?php[\s\S]*?\?>/g, '');
const html = `<!DOCTYPE html><body class="has-sfm"><div id="sfm-root"><button data-hmo-open aria-haspopup="dialog"><span data-hmo-badge hidden></span>Ưu đãi của tôi</button></div>${panel}</body>`;

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
    check(t.d.querySelectorAll('.hithean-offer-card').length === 1 && !t.d.querySelector('[data-hmo-contacts]').hidden && t.d.querySelector('.hmo__chip').textContent.includes('091•••••78'), 'found offers and the remembered contact are shown');
    check(t.d.querySelector('[data-hmo-auth-title]').textContent.includes('mã đầy đủ'), 'guest with masked codes is prompted to log in for full codes');
    check(t.d.querySelector('[data-hmo-badge]').textContent === '1' && !t.d.querySelector('[data-hmo-badge]').hidden, 'menu badge shows the offer count');

    // Đã đăng nhập: không hiện khối đăng nhập; trang có thanh đáy riêng → bỏ thanh này.
    const member = { success: true, data: { loggedIn: true, name: 'An', contacts: [], count: 0, html: '', nonce: 'n3' } };
    t = setup('<div class="anc-sticky-menu"></div>', { hithean_my_offers_load: member });
    check(!t.d.getElementById('sfm-root') && !t.d.body.classList.contains('has-sfm'), 'bar steps aside on pages with their own bottom bar');
    const opener = t.d.createElement('button'); opener.setAttribute('data-hmo-open', ''); t.d.body.appendChild(opener); opener.click();
    await tick(); await tick();
    check(t.d.querySelector('[data-hmo-auth]').hidden && !t.d.querySelector('[data-hmo-empty]').hidden && t.d.querySelector('[data-hmo-meta]').textContent.includes('An'), 'logged-in customer sees a greeting and an empty state instead of login');
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });

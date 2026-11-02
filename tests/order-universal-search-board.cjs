// Run: node tests/order-universal-search-board.cjs
// Execute the production board functions with a controlled network/DOM boundary.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../custom-functions/woocommerce/orders/assets/order-universal-search.js'), 'utf8');
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function fixture() {
    const requests = [], rendered = [], notices = [];
    const more = { disabled: false, textContent: 'Xem thêm' };
    const status = { textContent: '' };
    const ctx = {
        Map, Date, AbortController, window: {},
        boardCache: new Map(), boardCtl: null,
        boardState: { tab: '', page: 0, shown: 0, loading: false },
        BOARD_TTL: 10000, BOARD_MORE: 20, blockedUntil: 0,
        board: { hidden: true, querySelector: () => status },
        body: () => ({ querySelector: () => more }),
        notice: (_, text) => notices.push(text),
        rememberTab() {}, syncUrlTab() {}, renderTabs() {},
        fillTable: (_, data, page) => rendered.push({ data, page }),
        message: (_, fallback) => fallback,
        post: (action, data, ctl) => new Promise((resolve, reject) => requests.push({ action, data, ctl, resolve, reject })),
    };
    vm.createContext(ctx);
    vm.runInContext(section('    function renderBoard(', '    // Chỉ tải 10 đơn'), ctx);
    vm.runInContext(section('    function loadBoard(', '\n    function openPreview('), ctx);
    return { ctx, requests, rendered, notices, more, status };
}
function response(tab, shown = 10, total = 40) {
    return { success: true, data: { tab, shown, total, has_more: shown < total, rows: 'rows', tabs: [{ key: tab, count: total }], at: '12:00:00' } };
}
async function settle() { for (let i = 0; i < 6; i++) await Promise.resolve(); }
(async () => {
    const f = fixture(), c = f.ctx;
    c.loadBoard('picking', 1, false);
    check(f.requests.length === 1 && f.requests[0].data.limit === 10 && f.requests[0].data.offset === 0 && !f.requests[0].data.all, 'first request loads only 10 rows of active tab');
    f.requests[0].resolve(response('picking'));
    await settle();
    check(f.rendered.length === 1 && f.requests.length === 1 && c.boardState.shown === 10, 'first render does not auto-fetch another page');
    c.loadBoard('shipping', 1, false);
    check(c.boardCache.has('picking|1'), 'switching tab retains previously viewed first page');
    f.requests[1].resolve(response('shipping'));
    await settle();
    c.loadBoard('picking', 1, false);
    check(f.requests.length === 2 && c.boardState.tab === 'picking', 'revisit uses fresh cache');
    c.boardCache.get('picking|1').at = Date.now() - 11000;
    c.loadBoard('picking', 1, false);
    check(f.requests.length === 3, 'expired first page reloads');
    f.requests[2].resolve(response('picking'));
    await settle();
    c.loadBoard('picking', 2, false);
    check(f.requests[3].data.offset === 10 && f.requests[3].data.limit === 20, 'load more starts at actual displayed offset');
    f.requests[3].resolve(response('picking', 30));
    await settle();
    check(c.boardState.shown === 30 && f.rendered.at(-1).page === 2, 'load more appends results');
    c.loadBoard('picking', 1, true);
    check(f.requests[4].data.refresh === 1 && !c.boardCache.has('picking|2'), 'explicit refresh bypasses server cache and removes obsolete pages');
    f.requests[4].resolve(response('picking'));
    await settle();

    const r = fixture();
    r.ctx.loadBoard('picking', 1, false);
    r.ctx.loadBoard('shipping', 1, false);
    check(r.requests[0].ctl.signal.aborted, 'switch aborts previous request');
    r.requests[0].resolve(response('picking'));
    await settle();
    check(!r.rendered.length && r.ctx.boardState.loading, 'late old success cannot render or reset current loading state');
    r.requests[1].resolve(response('shipping'));
    await settle();
    check(r.ctx.boardState.tab === 'shipping' && !r.ctx.boardState.loading, 'newest response wins');
    r.ctx.loadBoard('picking', 1, false);
    r.ctx.loadBoard('shipping', 1, false); // Cache hit must also cancel the active request.
    check(r.requests[2].ctl.signal.aborted && r.ctx.boardState.tab === 'shipping', 'cached switch cancels network work');
    const before = r.notices.length;
    r.requests[2].reject(new Error('late network failure'));
    await settle();
    check(r.notices.length === before && r.ctx.boardState.tab === 'shipping', 'late old error cannot replace cached tab');
    r.ctx.loadBoard('picking', 1, false);
    r.requests[3].reject(new Error('offline'));
    await settle();
    check(r.notices.at(-1) === 'offline' && !r.ctx.boardState.loading, 'current failure remains visible and retryable');
    console.log(`OK (${checks} checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });

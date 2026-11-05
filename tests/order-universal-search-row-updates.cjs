// Run: node tests/order-universal-search-row-updates.cjs
// Execute production functions against real DOM nodes, with a controlled network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(require('node:path').join(__dirname, '../custom-functions/woocommerce/orders/assets/order-universal-search.js'), 'utf8');
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
const row = (id, label = 'old') => `<tr data-order-id="${id}" tabindex="0"><td><input data-thean-ous-check value="${id}" type="checkbox"><span>${label}</span></td></tr>`;
const tabs = (picking, shipping = 0, proof = 0) => [{ key: 'picking', label: 'Picking', count: picking }, { key: 'shipping', label: 'Shipping', count: shipping }, { key: 'proof', label: 'Proof', count: proof }];
function page(tab, ids, counts, cursor = ids.at(-1) || 0, exhausted = false) {
    return { success: true, data: { tab, rows: ids.map(id => row(id)).join(''), total: counts.find(t => t.key === tab).count, shown: ids.length, tabs: counts, next_cursor: cursor, exhausted, has_more: !exhausted, at: '12:00', range_label: '30 ngày gần nhất' } };
}
function patch(id, members, counts, label = 'updated', match = false) {
    return { success: true, data: { orders: [{ id, tabs: members, rows: row(id, label), search_match: match, search_rows: match ? row(id, label) : '' }], tabs: counts, range: 'auto', q: '', at: '12:01' } };
}
function fixture() {
    const dom = new JSDOM('<section id="board"><div><div class="thean-ous-tabs"></div></div><span class="thean-ous-modal__status"></span><div class="thean-ous-modal__body"></div></section><section id="results" hidden><span class="thean-ous-modal__status"></span><div class="thean-ous-chips"></div><div class="thean-ous-modal__body"></div></section><section id="preview" hidden></section>');
    // The workspace jsdom predates these standard browser DOM methods.
    const proto = dom.window.Element.prototype;
    if (!proto.append) proto.append = function (...nodes) { nodes.forEach(n => this.appendChild(typeof n === 'string' ? this.ownerDocument.createTextNode(n) : n)); };
    if (!proto.replaceChildren) proto.replaceChildren = function (...nodes) { while (this.firstChild) this.removeChild(this.firstChild); this.append(...nodes); };
    if (!proto.replaceWith) proto.replaceWith = function (node) { this.parentNode.replaceChild(node, this); };
    dom.window.HTMLElement.prototype.scrollIntoView = function () {};
    const document = dom.window.document, requests = [], errors = [];
    const ctx = {
        document, window: {}, Map, Set, Promise, Number, Array, Date, AbortController, URL, setTimeout, clearTimeout,
        cfg: { statuses: [], perPage: 10, cap: 500 },
        board: document.getElementById('board'), results: document.getElementById('results'), preview: document.getElementById('preview'),
        body: el => el.querySelector('.thean-ous-modal__body'),
        boardState: { tab: '', page: 0, shown: 0, cursor: 0, loading: false }, state: { q: '', page: 0, shown: 0, cursor: 0, loading: false },
        boardCache: new Map(), cache: new Map(), selected: { board: new Set(), results: new Set() },
        boardCtl: null, searchCtl: null, currentRange: 'auto', BOARD_TTL: 10000, BOARD_MORE: 20,
        rowUpdates: Promise.resolve(), rowEpoch: 0, rowScope: 0, rowPatches: new Map(), lastPatchTabs: null,
        activeBoardData: null, activeSearchData: null, blockedUntil: 0,
        rememberTab() {}, syncUrlTab() {}, openPreview() {},
        message: (_, text) => text, toast: text => errors.push(text),
        notice(el, text) { el.textContent = text; },
        setOpen(el, open) { el.hidden = !open; },
        post: (action, data, ctl) => new Promise((resolve, reject) => requests.push({ action, data, ctl, resolve, reject })),
    };
    vm.createContext(ctx);
    for (const [start, end] of [
        ['    function selection(', '    function onCheck('],
        ['    function shell(', '    function search('],
        ['    function renderTabs(', '    function patchRow('],
        ['    function patchRow(', '    // Chỉ tải 10 đơn'],
        ['    function loadBoard(', '\n    function changeRange('],
    ]) vm.runInContext(section(start, end), ctx);
    return { ctx, requests, errors, dom };
}
async function settle() { for (let i = 0; i < 14; i++) await Promise.resolve(); }
const get = (f, id) => f.ctx.body(f.ctx.board).querySelector(`tr[data-order-id="${id}"]`);
(async () => {
    const f = fixture(), c = f.ctx;
    c.loadBoard('picking', 1, false);
    f.requests[0].resolve(page('picking', [100,99,98,97,96,95,94,93,92,91], tabs(12,1), 91));
    await settle();
    c.boardCache.set('shipping|1', { at: Date.now(), data: page('shipping', [88], tabs(12,1), 88, true).data });
    if (!get(f,99)) throw new Error(c.body(c.board).textContent);
    const untouched = get(f,99), table = untouched.closest('table');
    c.selected.board.add('99'); c.syncBulk(c.board); c.body(c.board).scrollTop = 123;
    let task = c.refreshOrders([98]); await settle();
    check(f.requests[1].action === 'thean_order_usearch_rows' && f.requests[1].data.ids === '98', 'single mutation requests only its order ID');
    f.requests[1].resolve(patch(98, ['shipping','proof'], tabs(11,2,1))); await task;
    check(!get(f,98) && get(f,99) === untouched && untouched.closest('table') === table, 'moving order removes only that row without rebuilding the tab');
    check(untouched.querySelector('input').checked && c.body(c.board).scrollTop === 123, 'unrelated selection and scroll position survive');
    let dest = c.boardCache.get('shipping|1').data.rows;
    check(dest.indexOf('data-order-id="88"') < dest.indexOf('data-order-id="98"'), 'new membership appends to destination after its existing rows');
    check(c.boardState.cursor === 91 && c.boardState.shown === 9, 'removal preserves scan cursor while adjusting displayed count');
    check(c.board.querySelector('[data-thean-ous-tab="shipping"] b').textContent === '2', 'tab counts update without reloading rows');
    c.loadBoard('shipping', 1, false);
    check(f.requests.length === 2 && get(f,98) && get(f,88), 'destination cache displays moved order without a full-tab request');
    const stable88 = get(f,88);
    task = c.refreshOrders([98]); await settle();
    f.requests[2].resolve(patch(98, ['shipping','proof'], tabs(11,2,1), 'second update')); await task;
    check(get(f,98).textContent.includes('second update') && get(f,88) === stable88, 'same-tab update replaces only changed row');
    check(c.body(c.board).querySelectorAll('tr[data-order-id="98"]').length === 1, 'repeated updates never duplicate a row');
    c.loadBoard('proof', 1, false);
    f.requests[3].resolve(page('proof', [98], tabs(11,2,1), 98, true)); await settle();
    check(get(f,98), 'overlapping proof membership is retained');
    task = c.refreshOrders([98]); await settle();
    f.requests[4].resolve(patch(98, [], tabs(11,1,0))); await task;
    check(!get(f,98) && !c.boardCache.get('shipping|1').data.rows.includes('data-order-id="98"'), 'leaving board removes order from all cached memberships');
    check(f.requests.filter(r => r.action === 'thean_order_usearch_board').length === 2, 'mutations do not request a whole board tab');

    const p = fixture(); p.ctx.loadBoard('picking',1,false);
    p.requests[0].resolve(page('picking',[100,99,98,97,96,95,94,93,92,91],tabs(30),91)); await settle();
    task = p.ctx.refreshOrders([70]); await settle();
    p.requests[1].resolve(patch(70,['picking'],tabs(31))); await task;
    check(get(p,70) && p.ctx.boardState.cursor === 91, 'older moved-in row does not change pagination cursor');
    const stable = get(p,99);
    p.ctx.loadBoard('picking',2,false);
    check(p.requests[2].data.before_id === 91, 'load more follows original cursor instead of appended order ID');
    p.requests[2].resolve(page('picking',[90,89,88,87,86,85,84,83,82,81],tabs(31),81)); await settle();
    check(get(p,99) === stable && p.ctx.boardState.shown === 21, 'load more preserves existing nodes and uses actual unique row count');
    p.ctx.loadBoard('picking',3,false);
    p.requests[3].resolve(page('picking',[80,79,78,77,76,75,74,73,72,70],tabs(31),70)); await settle();
    check(p.ctx.body(p.ctx.board).querySelectorAll('tr[data-order-id="70"]').length === 1, 'later SQL page deduplicates an appended destination order');
    check(p.ctx.boardState.shown === 30 && p.ctx.activeBoardData.has_more, 'duplicate page row does not hide remaining unseen orders');

    const r = fixture(); r.ctx.loadBoard('picking',1,false);
    task = r.ctx.refreshOrders([98]); await settle();
    r.requests[0].resolve(page('picking',[98],tabs(1),98,true));
    r.requests[1].resolve(patch(98,['shipping'],tabs(0,1))); await task; await settle();
    check(!get(r,98) && r.ctx.activeBoardData.total === 0, 'in-flight old list cannot restore moved order or stale tab count');
    task = r.ctx.refreshOrders([98]); await settle(); r.requests[2].reject(new Error('offline')); await task;
    check(r.errors.length === 1 && r.requests.length === 3, 'failed row sync reports error without silently reloading the tab');

    const b = fixture(); b.ctx.loadBoard('picking',1,false);
    b.requests[0].resolve(page('picking',[100,99],tabs(2),99,true)); await settle();
    task = b.ctx.refreshOrders([100,99,100]); await settle();
    check(b.requests[1].data.ids === '100,99', 'bulk patch reads only unique successful IDs');
    const response = patch(100,['shipping'],tabs(1,1));
    response.data.orders.push({ id:99, tabs:['picking'], rows:row(99,'bulk updated'), search_match:false, search_rows:'' });
    b.requests[1].resolve(response); await task;
    check(!get(b,100) && get(b,99).textContent.includes('bulk updated'), 'bulk updates patch only affected rows');
    const search = fixture();
    search.ctx.state.q = 'Whey'; search.ctx.results.hidden = false;
    const initial = page('picking',[100,99],tabs(2),99,true).data;
    initial.summary = [];
    search.ctx.render(initial,1);
    const search99 = search.ctx.body(search.ctx.results).querySelector('tr[data-order-id="99"]');
    search.ctx.selected.results.add('99'); search.ctx.syncBulk(search.ctx.results);
    task = search.ctx.refreshOrders([100]); await settle();
    check(search.requests[0].data.q === 'Whey', 'row sync includes current search query');
    const gone = patch(100, [], tabs(0)); gone.data.q = 'Whey';
    search.requests[0].resolve(gone); await task;
    check(!search.ctx.body(search.ctx.results).querySelector('tr[data-order-id="100"]') && search.ctx.body(search.ctx.results).querySelector('tr[data-order-id="99"]') === search99, 'search patches remove nonmatching row without rebuilding other results');
    check(search99.querySelector('input').checked, 'search preserves unrelated selection');
    const scope = fixture();
    task = scope.ctx.refreshOrders([100]); await settle();
    scope.ctx.rowScope++; scope.ctx.currentRange = '90';
    scope.requests[0].resolve(patch(100,['picking'],tabs(1))); await task;
    check(!scope.ctx.rowPatches.size, 'response for an obsolete date range is ignored');
    const many = fixture();
    task = many.ctx.refreshOrders(Array.from({ length: 51 }, (_, i) => i+1)); await settle();
    check(many.requests[0].data.ids.split(',').length === 50, 'large updates are split into bounded batches');
    const emptyPatch = { success:true, data: { orders:[], tabs:tabs(0), range:'auto', q:'', at:'12:00' } };
    many.requests[0].resolve(emptyPatch); await settle();
    check(many.requests[1].data.ids === '51', 'second batch contains only remaining changed order');
    many.requests[1].resolve(emptyPatch); await task;
    const empty = fixture(); empty.ctx.loadBoard('shipping',1,false);
    empty.requests[0].resolve(page('shipping',[],tabs(0,0),0,true)); await settle();
    task = empty.ctx.refreshOrders([70]); await settle();
    empty.requests[1].resolve(patch(70,['shipping'],tabs(0,3))); await task;
    check(get(empty,70) && empty.ctx.activeBoardData.has_more, 'previously empty tab can append a row and expose other newly counted orders');
    empty.ctx.loadBoard('shipping',2,false);
    check(empty.requests[2].data.before_id === 0 && empty.requests[2].data.offset === 0, 'rescan after an exhausted cursor starts at the top without shifting by appended rows');
    empty.requests[2].resolve(page('shipping',[90,80,70],tabs(0,3),70,true)); await settle();
    check(get(empty,90) && get(empty,80) && empty.ctx.boardState.shown === 3, 'rescan deduplicates moved row while keeping all unseen orders');
    const changing = fixture(); changing.ctx.state.q = 'old'; changing.ctx.results.hidden = false;
    task = changing.ctx.refreshOrders([100]); await settle();
    changing.ctx.state.q = 'new';
    const oldQueryPatch = patch(100,['picking'],tabs(1)); oldQueryPatch.data.q = 'old';
    changing.requests[0].resolve(oldQueryPatch); await task; await settle();
    check(changing.requests.length === 2 && changing.requests[1].data.ids === '100' && changing.requests[1].data.q === 'new', 'query change during sync schedules only a targeted read for the latest query');
    const newQueryPatch = patch(100,['picking'],tabs(1)); newQueryPatch.data.q = 'new';
    changing.requests[1].resolve(newQueryPatch); await changing.ctx.rowUpdates; await settle();
    check(changing.requests.length === 2, 'stable query does not create repeated synchronization requests');
    // Grouped customer tab: changes within the same tab must move between groups,
    // preserve unrelated nodes, and never count headings as orders.
    const g = fixture();
    const grouped = (confirm, payment) => [{ key: 'confirm', label: 'Customers', count: confirm + payment, groups: [
        { key: 'confirm', label: 'Đơn chờ xác nhận', count: confirm }, { key: 'payment', label: 'Đơn chờ thanh toán trước', count: payment }
    ] }];
    const gr = (id, step) => row(id).replace('tabindex="0"', `data-ops-step="${step}" tabindex="0"`);
    g.ctx.loadBoard('confirm', 1, false);
    const gp = page('confirm', [51,50], grouped(1,2), 50);
    gp.data.rows = gr(51,'payment') + gr(50,'confirm');
    g.requests[0].resolve(gp); await settle();
    const groupBody = g.ctx.body(g.ctx.board).querySelector('tbody');
    check(groupBody.querySelectorAll('[data-ops-group-header]').length === 2 && g.ctx.boardState.shown === 2, 'two group headings do not inflate loaded order count');
    check(groupBody.firstElementChild.dataset.opsGroupHeader === 'confirm' && get(g,50).previousElementSibling.dataset.opsGroupHeader === 'confirm', 'orders grouped by task rather than global ID order');
    const stable51 = get(g,51);
    task = g.ctx.refreshOrders([50]); await settle();
    const changed = patch(50,['confirm'],grouped(0,3)); changed.data.orders[0].rows = gr(50,'payment');
    g.requests[1].resolve(changed); await task;
    check(get(g,50).dataset.opsStep === 'payment' && get(g,51) === stable51, 'same-tab subgroup change preserves unrelated order node');
    check(groupBody.querySelector('[data-ops-group-header="confirm"]').textContent.includes('0 / 0') && groupBody.querySelector('[data-ops-group-header="payment"]').textContent.includes('2 / 3'), 'group counts update after mutation');
    check(!g.ctx.boardCache.get('confirm|1').data.rows.includes('data-ops-group-header') && g.ctx.boardState.shown === 2, 'cache and pagination contain orders only');
    g.ctx.loadBoard('confirm', 2, false);
    const gm = page('confirm',[49],grouped(0,3),49,true); gm.data.rows = gr(49,'payment');
    g.requests[2].resolve(gm); await settle();
    check(g.ctx.boardState.shown === 3 && groupBody.querySelectorAll('[data-ops-group-header]').length === 2 && get(g,51) === stable51, 'load more merges group rows without duplicating headers or rebuilding unrelated rows');
    g.ctx.loadBoard('payment', 1, false);
    check(g.ctx.boardState.tab === 'confirm' && g.requests.length === 3, 'legacy payment tab reuses merged customer cache');
    console.log(`OK (${checks} DOM and request checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });

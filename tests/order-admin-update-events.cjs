// Run: node tests/order-admin-update-events.cjs
// Verify successful legacy quick actions signal their saved order IDs.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
// Shared payment/export helpers now live in order-quick-modals.php; returns remain in orders-admin.php.
const source = ['order-quick-modals.php', 'orders-admin.php'].map(file =>
    fs.readFileSync(path.join(__dirname, '../custom-functions/woocommerce/orders', file), 'utf8')
).join('\n');
let checks = 0;
for (const match of source.matchAll(/<script>([\s\S]*?)<\/script>/g)) {
    new vm.Script(match[1].replace(/<\?php[\s\S]*?\?>/g, 'null'));
    checks++;
}
function requests() {
    const found = [];
    let offset = 0;
    while ((offset = source.indexOf('function request(', offset)) !== -1) {
        let end = source.indexOf('{', offset), depth = 0, quote = null, escaped = false;
        for (; end < source.length; end++) {
            const ch = source[end];
            if (quote) {
                if (escaped) escaped = false;
                else if (ch === '\\') escaped = true;
                else if (ch === quote) quote = null;
            } else if (ch === '"' || ch === "'") quote = ch;
            else if (ch === '{') depth++;
            else if (ch === '}' && --depth === 0) break;
        }
        found.push(source.slice(offset, end + 1)); offset = end + 1;
    }
    return found;
}
(async () => {
    const helpers = requests(); assert.equal(helpers.length, 2); checks++;
    for (const [index, code] of helpers.entries()) {
        const events = [];
        let response = { success: true };
        const ctx = { cfg: { ajaxUrl: '/test-only' },
            document: { dispatchEvent: e => events.push(e) },
            CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
            fetch: async () => ({ json: async () => response }),
        };
        vm.createContext(ctx); vm.runInContext(code, ctx);
        const fields = index === 0 ? ['order_id', 'order_ids', 'ueif_order_id', 'uexe_order_id'] : ['order_id'];
        for (const field of fields) {
            events.length = 0;
            const data = new FormData(); data.set(field, '123');
            const result = await ctx.request('test_action', data);
            assert.equal(result, response);
            assert.equal(events.length, 1);
            assert.equal(events[0].type, 'thean:order-updated');
            assert.equal(String(events[0].detail.orderId || events[0].detail.orderIds), '123');
            checks++;
        }
        events.length = 0; response = { success: false };
        const data = new FormData(); data.set('order_id', '123');
        await ctx.request('test_action', data);
        assert.equal(events.length, 0); checks++;
    }
    console.log(`OK (${checks} inline syntax and mutation-event checks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });

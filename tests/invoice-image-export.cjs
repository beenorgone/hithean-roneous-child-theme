// Guards invoice JPG/clipboard parity and prevents exporting a blank/loading iframe.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../custom-functions/woocommerce/orders/assets/invoice-image-export.js'), 'utf8');
async function main() {
    let rendered = 0, downloaded, written, scripts = [], immediate = false;
    const canvas = { toDataURL(type, quality) { assert.equal(type, 'image/jpeg'); assert.equal(quality, 0.92); return 'data:image/jpeg;base64,invoice'; }, toBlob(cb, type) { assert.equal(type, 'image/png'); cb({ type }); } };
    const doc = { readyState: 'complete', images: [{ complete: true, src: 'data:image/png;base64,QR' }], fonts: { ready: Promise.resolve() }, body: {}, querySelector: () => ({}) };
    const message = { textContent: '' }, frame = { contentDocument: doc };
    const window = { ClipboardItem: function (data) { this.data = data; } };
    const document = { head: { appendChild(script) { scripts.push(script); } }, createElement(tag) { return tag === 'a' ? { click() { downloaded = this; } } : { remove() {} }; } };
    const navigator = { clipboard: { write(items) { immediate = true; written = items; return items[0].data['image/png']; } } };
    vm.runInNewContext(source, { window, document, navigator, Promise, Error });
    const exporter = window.TheanInvoiceImage({ frame, message, libraryUrl: '/local/html2canvas.js', fileName: () => 'hoa-don-123.jpg' });
    exporter.preload(); exporter.preload();
    assert.equal(scripts.length, 1, 'preload shares one library request');
    scripts[0].onerror();
    await new Promise(setImmediate);
    exporter.preload(); assert.equal(scripts.length, 2, 'failed library load can retry');
    window.html2canvas = async (body, options) => { rendered++; assert.equal(body, doc.body); assert.equal(options.scale, 2); assert.equal(options.useCORS, true); return canvas; };
    scripts[1].onload();
    await exporter.download();
    assert.equal(downloaded.download, 'hoa-don-123.jpg');
    assert.equal(downloaded.href, 'data:image/jpeg;base64,invoice');
    const copying = exporter.copy();
    assert.ok(immediate, 'clipboard write runs synchronously within user gesture');
    await copying;
    assert.equal((await written[0].data['image/png']).type, 'image/png');
    assert.match(message.textContent, /Đã copy/);
    delete navigator.clipboard;
    await exporter.copy(); assert.match(message.textContent, /không hỗ trợ/);
    doc.querySelector = () => null;
    await exporter.download(); assert.match(message.textContent, /chưa tải xong/);
    assert.equal(rendered, 2, 'blank/error document never exported');
    doc.querySelector = () => ({}); doc.readyState = 'loading';
    await exporter.download(); assert.match(message.textContent, /chưa tải xong/);
    doc.readyState = 'complete';
    window.html2canvas = () => Promise.reject(new Error('capture failed'));
    await exporter.download(); assert.match(message.textContent, /capture failed/);
    navigator.clipboard = { write() { throw new Error('permission denied'); } };
    await exporter.copy(); assert.match(message.textContent, /permission denied/);
    // Execute the production board click handler against DOM controls.
    const { JSDOM } = require('jsdom');
    const dom = new JSDOM('<button data-thean-ous-invoice="/preview?order_id=42" data-order-id="42">View</button><section id="invoice"><h2 id="thean-ous-invoice-title"></h2><a data-thean-ous-invoice-open></a><button data-thean-ous-invoice-jpg>JPG</button><button data-thean-ous-invoice-copy>Copy</button><p data-thean-ous-invoice-msg></p><iframe></iframe></section>');
    const boardSource = fs.readFileSync(path.join(__dirname, '../custom-functions/woocommerce/orders/assets/order-universal-search.js'), 'utf8');
    const start = boardSource.indexOf('    var invoiceImage = null;');
    const end = boardSource.indexOf('    // Đổi trạng thái tùy ý', start);
    let calls = [], boardOptions;
    vm.runInNewContext(boardSource.slice(start, end), {
        document: dom.window.document, invoice: dom.window.document.getElementById('invoice'), cfg: { html2canvasUrl: '/local/library.js' },
        window: { TheanInvoiceImage(options) { boardOptions = options; return { preload() { calls.push('preload'); }, download() { calls.push('download'); }, copy() { calls.push('copy'); } }; } },
        setOpen(modal, value) { modal.hidden = !value; }, toast() {}
    });
    dom.window.document.querySelector('[data-thean-ous-invoice]').click();
    dom.window.document.querySelector('[data-thean-ous-invoice-jpg]').click();
    dom.window.document.querySelector('[data-thean-ous-invoice-copy]').click();
    assert.deepEqual(calls, ['preload', 'download', 'copy']);
    assert.equal(boardOptions.fileName(), 'hoa-don-42.jpg');
    assert.equal(boardOptions.libraryUrl, '/local/library.js');
    const shared = path.join(__dirname, '../custom-functions/woocommerce/orders/assets/invoice-image-export.js');
    assert.ok(fs.existsSync(shared));
    console.log('PASS invoice JPG, PNG clipboard, gesture, lazy loading/retry, error states and board controls');
}
main().catch(error => { console.error(error); process.exit(1); });

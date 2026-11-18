// Run: node tests/mobile-forms-ui.cjs (cần jsdom). Form trên mobile → nút + popup (orders/assets/mobile-forms.js, GIỐNG HỆT theanmarket).
const {JSDOM} = require('jsdom'); const fs = require('fs'); const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '../custom-functions/woocommerce/orders/assets/mobile-forms.js'), 'utf8');
const html = `<body><main data-mform-root>
  <h2>Upload ảnh đơn hàng</h2>
  <form id="upload-export-form"><input type="number" name="id"><input type="file" name="f"><div id="ueif-web-bulk-status"></div><button type="submit">Up</button></form>
  <form id="proteinCalcForm"><select id="g"><option>Nam</option></select><input type="number" id="age"><button type="submit">Tính</button></form>
  <form id="scan"><input id="code"><button type="submit">Mở kiện</button></form>
  <form id="confirm"><input type="hidden" name="x" value="1"><button type="submit">Xác nhận</button></form>
  <form id="bulk" hidden><input type="file"><button>Up</button></form>
  <div id="rec" data-mform data-mform-label="📋 Nhập chứng từ"><textarea id="t"></textarea><button type="button" id="run" data-mform-close>Đối soát</button></div>
  <div class="orm-modal"><div class="orm-modal-body"><form id="inmodal"><input><textarea></textarea></form></div></div>
  <div id="list"></div>
</main></body>`;
let mobile = true; const listeners = [];
const dom = new JSDOM(html, {runScripts: 'outside-only', url: 'https://example.test/?thean_order_tool=x'});
const w = dom.window, d = w.document;
w.matchMedia = () => ({get matches() { return mobile; }, addEventListener: (t, f) => listeners.push(f)});
if (!w.HTMLDialogElement || !w.HTMLDialogElement.prototype.showModal) {
  const P = (w.HTMLDialogElement || w.HTMLElement).prototype;
  P.showModal = function () { this.setAttribute('open', ''); };
  P.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
  if (!('open' in P)) Object.defineProperty(P, 'open', {get() { return this.hasAttribute('open'); }});
}
let submitted = 0;
d.addEventListener('submit', e => { e.preventDefault(); submitted++; });
w.eval(src);
const tick = () => new Promise(r => setTimeout(r, 30));
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) process.exitCode = 1; };
const trig = el => el.closest('dialog') && el.closest('dialog').previousElementSibling;
(async () => {
  const up = d.getElementById('upload-export-form'), pc = d.getElementById('proteinCalcForm');
  ok(up.closest('dialog.tmf-dialog') && trig(up).classList.contains('tmf-trigger'), 'form nhiều trường gói vào dialog + nút');
  ok(trig(up).textContent.includes('Upload ảnh xuất kho'), 'nhãn theo bảng LABELS');
  ok(up.parentElement.closest('main') === d.querySelector('main'), 'form vẫn ở trong vùng gốc (id/closest giữ nguyên)');
  ok(!d.getElementById('scan').closest('dialog'), 'form 1 ô (quét mã) giữ nguyên');
  ok(!d.getElementById('confirm').closest('dialog'), 'form chỉ có nút giữ nguyên');
  ok(!d.getElementById('inmodal').closest('dialog'), 'form trong modal sẵn có bị bỏ qua');
  ok(trig(d.getElementById('bulk')).hidden, 'form ẩn → nút ẩn');
  d.getElementById('bulk').hidden = false; await tick();
  ok(!trig(d.getElementById('bulk')).hidden, 'form hiện lại → nút hiện');

  trig(pc).click(); const dlg = pc.closest('dialog');
  ok(dlg.open, 'bấm nút mở popup');
  pc.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true})); await tick();
  ok(submitted === 1 && !dlg.open, 'submit: handler shortcode chạy rồi popup tự đóng');
  trig(up).click(); up.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true})); await tick();
  ok(up.closest('dialog').open, 'form có ô trạng thái: giữ popup để thấy tiến trình');
  up.closest('dialog').querySelector('.tmf-dialog__x').click();
  ok(!up.closest('dialog').open, 'nút ✕ đóng popup');

  const rec = d.getElementById('rec');
  ok(rec.closest('dialog') && trig(rec).textContent.includes('Nhập chứng từ'), 'khối [data-mform] opt-in');
  trig(rec).click(); d.getElementById('run').click(); await tick();
  ok(!rec.closest('dialog').open, 'data-mform-close đóng popup');

  d.getElementById('list').innerHTML = '<form class="attach-return-form"><select><option>a</option></select><input name="return_code"></form>';
  await tick();
  ok(!!d.querySelector('.attach-return-form').closest('dialog'), 'form render sau (AJAX) cũng được gom');

  mobile = false; listeners.forEach(f => f());
  ok(!d.querySelector('.tmf-dialog') && !d.querySelector('.tmf-trigger'), 'desktop: gỡ hết dialog / nút');
  ok(up.parentElement === d.querySelector('main') && up.previousElementSibling.tagName === 'H2', 'desktop: form về đúng chỗ cũ');
  mobile = true; listeners.forEach(f => f());
  ok(!!up.closest('dialog'), 'quay lại mobile: gom lại');
})();

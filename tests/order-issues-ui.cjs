// Regression: production incident dialog, safe text rendering and explicit mutations; no live WordPress.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<button data-order-issues="11">Sự cố</button>', { url: 'https://example.test/quan-ly-don/', runScripts: 'outside-only' });
const w = dom.window, doc = w.document;
// jsdom 11 lacks modern DOM/dialog methods supported by production browsers.
if (!w.Element.prototype.append) w.Element.prototype.append = function (...nodes) { nodes.forEach(n => this.appendChild(typeof n === 'string' ? doc.createTextNode(n) : n)); };
if (!w.Element.prototype.replaceChildren) w.Element.prototype.replaceChildren = function (...nodes) { while(this.firstChild) this.removeChild(this.firstChild); this.append(...nodes); };
w.HTMLDialogElement.prototype.showModal = function () { this.open = true; this.setAttribute('open', ''); };
w.HTMLDialogElement.prototype.close = function () { this.open = false; this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
let checks=0, requests=[], events=[], answer, deferred;
function check(ok, text) { assert.ok(ok,text); checks++; }
w.theanIssueCfg={url:'https://example.test/wp-admin/admin-ajax.php',nonce:'test'};
w.fetch = async (url,opt) => { requests.push(Object.fromEntries(Array.from(opt.body.entries()))); return { json: async () => deferred ? await deferred : answer }; };
doc.addEventListener('thean:order-updated',e=>events.push(e.detail));
w.eval(fs.readFileSync(path.join(__dirname,'../custom-functions/woocommerce/orders/assets/order-issues.js'),'utf8'));
const tick = () => new Promise(resolve=>setImmediate(resolve));
const record = { id:'auto_nearby_orders',type:'nearby_orders',label:'Khách đặt nhiều đơn',status:'open',description:'<img src=x onerror=alert(1)>',help:'Kiểm tra trước khi xuất kho',blocked:true,created_at:1700000000,created_by:7,token:'fresh',actions:['start','ack'],related:[{id:12,number:'12',at:'29/11/2026',status:'Đang xử lý',total:'100.000 ₫',url:'https://example.test/tao-don/?order_id=12',reasons:['Cùng SĐT']}] };
function ok(records,pending=false) { return { success:true,data:{id:11,records,pending} }; }
(async()=>{
    answer=ok([record],true);doc.querySelector('[data-order-issues]').click();await tick();
    let dialog=doc.querySelector('dialog');
    check(dialog.open,'opens native modal');
    check(requests[0].op==='read'&&requests[0].order_id==='11'&&requests[0].nonce==='test','authenticated read protocol');
    check(dialog.textContent.includes('Đang chặn')&&dialog.textContent.includes('đang kiểm tra')===false,'blocking visible');
    check(dialog.textContent.includes('bộ lọc có thể chưa đầy đủ'),'incomplete scan visible');
    check(!dialog.querySelector('img')&&dialog.textContent.includes('<img'),'customer description is text, never HTML');
    check(dialog.querySelector('a').href.includes('order_id=12')&&dialog.querySelector('a').rel==='noopener','related order safe link');
    answer=ok([{...record,status:'working',blocked:false,ack_at:1700000100,ack_by:7,actions:[]}]);
    Array.from(dialog.querySelectorAll('button')).find(n=>n.textContent==='Đã kiểm tra đơn này').click();await tick();
    check(requests[1].op==='ack'&&requests[1].token==='fresh','explicit review sends evidence token');
    check(events.length===1&&events[0].orderId===11,'successful mutation patches existing row');
    check(dialog.textContent.includes('Đã kiểm tra:')&&!dialog.textContent.includes('Đang chặn thực hiện đơn'),'reviewed state rendered');
    check(requests.length===2,'review never replays fulfillment');
    let form=dialog.querySelector('form');form.querySelector('textarea').value='Không giao trước khi gọi lại';form.querySelector('select').value='resolve';
    answer={success:false,data:{message:'Dữ liệu đã thay đổi'}};form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await tick();
    check(requests[2].op==='create'&&requests[2].mode==='resolve'&&requests[2].note==='Không giao trước khi gọi lại','manual report supports blocking');
    check(dialog.textContent.includes('Dữ liệu đã thay đổi')&&events.length===1,'server error visible, no false update');
    check(!form.querySelector('button').disabled,'error restores controls');
    dialog.close();
    let release;deferred=new Promise(resolve=>release=resolve);doc.querySelector('[data-order-issues]').click();await tick();dialog.close();
    release(ok([record]));await tick();
    check(!dialog.open&&!dialog.textContent.includes(record.description),'late response cannot reopen/repopulate closed dialog');
    deferred=null;answer=ok([{...record,type:'ghtk_overdue',label:'7 ngày GHTK chưa giao xong',blocked:false,since_at:1700000000,actions:['start']}]);answer.data.can_manage=false;
    doc.querySelector('[data-order-issues]').click();await tick();
    check(dialog.textContent.includes('Mốc tính:')&&dialog.textContent.includes('đã qua'),'overdue card shows source clock and elapsed days');
    check(!dialog.querySelector('form')&&!Array.from(dialog.querySelectorAll('button')).some(b=>b.textContent==='Nhận xử lý'),'read-only staff can inspect without mutation controls');
    console.log(`OK (${checks} incident UI checks)`);dom.window.close();
})().catch(error=>{console.error(error);process.exitCode=1;});

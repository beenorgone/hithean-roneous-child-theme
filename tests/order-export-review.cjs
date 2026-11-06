// Run production review UI against controlled DOM/requests. No live confirmation.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const fixture = JSON.parse(cp.execFileSync('php', [path.join(__dirname,'order-export-review.php'),'fixture'], { encoding:'utf8' }));
const script = fs.readFileSync(path.join(__dirname,'../custom-functions/woocommerce/orders/assets/order-export-review.js'),'utf8');
let checks=0;
function check(value, message) { assert.ok(value,message); checks++; }
function make() {
 const dom=new JSDOM('<!doctype html><body><a id="a" class="wc-action-button-thean-order-export-confirm" href="/wp-admin/admin.php?order_id=101">Confirm A</a><a id="b" class="wc-action-button-hithean-order-export-confirm" href="/wp-admin/admin.php?order_id=102">Confirm B</a>'+fixture.html+'</body>', { url:'https://example.test/wp-admin/admin.php?page=wc-orders&thean_board=export',runScripts:'outside-only' });
 const w=dom.window,d=w.document, requests=[],changes=[],errors=[]; let legacy=0;
 if (!w.Element.prototype.replaceChildren) w.Element.prototype.replaceChildren=function(...nodes){ while(this.firstChild)this.removeChild(this.firstChild);nodes.forEach(n=>this.appendChild(n)); };
 w.AbortController=AbortController;
 w.theanExportReviewCfg={ajaxUrl:'/mock',readNonce:'read-only-nonce',confirmNonce:'native-confirm-nonce'};
 w.fetch=(_,options)=>new Promise((resolve,reject)=>requests.push({options,action:options.body.get('action'),resolve:value=>resolve({json:async()=>value}),reject}));
 w.addEventListener('error',event=>errors.push(event.error||event.message));
 d.addEventListener('click',event=>{ if(event.target.closest('.wc-action-button-thean-order-export-confirm, .wc-action-button-hithean-order-export-confirm'))legacy++; });
 d.addEventListener('thean:order-updated',event=>changes.push(event.detail));
 w.eval(script);
 const modal=d.getElementById('thean-export-review-modal'),form=modal.querySelector('form'),submit=form.querySelector('[type="submit"]');
 return {dom,w,d,requests,changes,errors,modal,form,submit,legacy:()=>legacy,open(id='a'){const button=d.getElementById(id);button.focus();button.click();},send(){form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));},close(){modal.querySelector('button[data-export-review-close]').click();}};
}
async function settle(){for(let i=0;i<12;i++)await Promise.resolve();}
function data(id=101){const value=JSON.parse(JSON.stringify(fixture.data));value.order_id=id;value.number=id+'**';return value;}
(async()=>{
 const f=make();f.open();
 check(!f.modal.hidden&&f.requests.length===1&&f.requests[0].action==='thean_order_export_review','click opens modal and only reads evidence');
 check(f.legacy()===0,'capture prevents legacy generic modal/confirmation handler');
 check(f.submit.disabled&&f.changes.length===0,'loading never permits confirmation or emits mutation events');
 f.send();check(f.requests.length===1,'premature submit cannot confirm');
 const payload=data();payload.ai.reason='<img src=x onerror=alert(1)>';f.requests[0].resolve({success:true,data:payload});await settle();
 check(f.modal.querySelectorAll('.thean-export-review__thumbs button').length===2&&!f.submit.disabled,'photos and manual confirm button appear after successful read');
 check(f.modal.textContent.includes('Protein sample × 2')&&f.modal.textContent.includes('Ghi chú đã lưu trên đơn'),'items and saved AI note visible');
 check(f.modal.textContent.includes('<img src=x onerror=alert(1)>')&&!f.modal.querySelector('img[onerror]'),'AI content rendered as text, not executable HTML');
 f.modal.querySelector('[aria-label="Xem ảnh 2"]').click();
 check(f.modal.querySelector('.thean-export-review__hero').src.endsWith('two.jpg'),'photo selection changes full-size viewer');
 f.send();f.send();check(f.requests.length===2&&f.requests[1].action==='ajax_confirm_export','explicit submit calls native endpoint exactly once');
 check(f.requests[1].options.body.get('uexe_order_id')==='101'&&f.requests[1].options.body.get('nonce')==='native-confirm-nonce','existing confirmation payload and nonce retained');
 f.close();check(!f.modal.hidden,'busy mutation cannot be closed or rebound to another order');
 f.open('b');check(f.requests.length===2,'cannot change order during in-flight confirmation');
 f.requests[1].resolve({success:false,data:'Server rejected confirmation'});await settle();
 check(!f.modal.hidden&&!f.submit.disabled&&f.modal.textContent.includes('Server rejected'),'failed write stays open for explicit retry');
 check(f.changes.length===0,'failed confirmation does not emit changed-order event');
 f.send();f.requests[2].resolve({success:true,data:'Confirmed export'});await settle();
 check(f.modal.hidden&&f.changes.length===1&&f.changes[0].orderId===101,'successful confirmation closes modal and patches only saved order');
 check(!f.d.getElementById('a').hasAttribute('href'),'old action disabled after successful confirmation');
 check(f.errors.length===0,'no uncaught errors in normal review flow');f.dom.window.close();
 const r=make();r.open();r.open('b');
 check(r.requests[0].options.signal.aborted&&r.requests.length===2,'opening another order cancels old evidence request');
 r.requests[1].resolve({success:true,data:data(102)});await settle();r.requests[0].resolve({success:true,data:data(101)});await settle();
 check(r.modal.querySelector('[data-export-review-summary]').textContent.includes('102**')&&!r.modal.querySelector('[data-export-review-summary]').textContent.includes('101'),'late response cannot replace current order evidence');
 r.close();check(r.modal.hidden&&r.d.activeElement===r.d.getElementById('b'),'close restores focus to correct action');r.dom.window.close();
 const e=make();e.open();e.requests[0].resolve({success:false,data:{message:'Cannot read evidence'}});await settle();
 check(e.submit.disabled&&e.modal.textContent.includes('Cannot read evidence'),'failed evidence request cannot enable confirmation');
 e.modal.querySelector('[data-export-review-reload]').click();check(e.requests.length===2&&e.requests[1].action==='thean_order_export_review','retry repeats read only');
 const empty=data();empty.images=[];empty.can_confirm=false;empty.blocked='Đơn chưa có ảnh xuất kho.';e.requests[1].resolve({success:true,data:empty});await settle();
 e.send();check(e.submit.disabled&&e.requests.length===2&&e.modal.textContent.includes('Đơn chưa có ảnh'),'missing photos remain blocked');e.dom.window.close();
 const s=make();s.open();s.close();s.requests[0].resolve({success:true,data:data()});await settle();
 check(s.modal.hidden&&s.submit.disabled&&s.changes.length===0,'late response after closing cannot reopen or enable confirmation');
 s.open('b');s.requests[1].resolve({success:true,data:data(101)});await settle();
 check(s.submit.disabled&&s.modal.textContent.includes('không khớp'),'mismatched-order response rejected');
 s.modal.querySelector('button[data-export-review-close]').dispatchEvent(new s.w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
 check(s.modal.hidden,'Escape closes the review without a write');check(s.errors.length===0,'no uncaught errors in failure and race flows');s.dom.window.close();
 console.log(`OK (${checks} export-review DOM/request checks)`);
})().catch(error=>{console.error(error);process.exitCode=1;});

// Run: node tests/order-quick-modals-ui.cjs (cần jsdom + php). Popup thao tác nhanh (order-quick-modals.php, dùng chung theanmarket).
const {JSDOM}=require('jsdom'); const fs=require('fs');
const html=require('child_process').execFileSync('php',[require('path').join(__dirname,'order-quick-modals-render.php')]).toString();
const calls=[];
const dom=new JSDOM('<body><table><tr id="post-10867"><td><a class="wc-action-button-hithean-order-export-images" href="/wp-admin/admin.php?hithean_order_action=export-images&order_id=10867">x</a><a class="wc-action-button-hithean-order-export-confirm" href="/x?order_id=10867">c</a><a class="wc-action-button-hithean-order-payment" href="/x?order_id=10867&amount=500000">p</a></td></tr></table>'+html+'</body>',{runScripts:'outside-only',url:'https://hithean.com/wp-admin/admin.php'});
const w=dom.window; if(!w.Element.prototype.replaceChildren) w.Element.prototype.replaceChildren=function(...n){this.textContent='';this.append(...n);};
w.fetch=(u,o)=>{const a=o.body.get('action');calls.push(a);let d;
 if(a==='thean_export_images_list') d={success:true,data:{images:['https://x/a.jpg','https://x/b.jpg'],confirmed:false,ai:'Khớp'}};
 else if(a==='ajax_confirm_export') d={success:true,data:'✅ ok'}; else d={success:true,data:{}};
 return Promise.resolve({json:()=>Promise.resolve(d)});};
for(const s of w.document.querySelectorAll('script')) w.eval(s.textContent);
const d=w.document; const tick=()=>new Promise(r=>setTimeout(r,20));
(async()=>{
 const ok=(c,m)=>{console.log((c?'PASS ':'FAIL ')+m); if(!c) process.exitCode=1;};
 ok([...d.querySelectorAll('#thean-payment-form [name=bank_account] option')].map(o=>o.value).join('|')==='ACB 51364767|Vietinbank 113600098383|tiền mặt','bank options from code + tiền mặt');
 d.querySelector('.wc-action-button-hithean-order-payment').click(); await tick();
 ok(!d.getElementById('thean-payment-modal').hidden && d.querySelector('#thean-payment-form [name=amount_received]').value==='500000','payment modal opens with amount');
 d.querySelector('#thean-payment-modal [data-thean-close]').click();
 d.querySelector('.wc-action-button-hithean-order-export-images').click(); await tick(); await tick();
 const m=d.getElementById('thean-export-images-modal');
 ok(!m.hidden,'images modal opens'); ok(m.querySelectorAll('.thean-quick-modal__thumbs img').length===2,'2 thumbs shown');
 ok(m.querySelector('[data-thean-checklist]').textContent.includes('<b>SP</b>'),'checklist escaped as text');
 const cb=m.querySelector('[data-thean-export-confirm]'); ok(!cb.hidden,'confirm button visible');
 m.querySelector('form').dispatchEvent(new w.Event('submit',{cancelable:true})); await tick();
 ok(m.querySelector('.thean-quick-modal__notice').classList.contains('is-error'),'upload without file -> error');
 cb.click(); await tick(); await tick();
 ok(calls.includes('ajax_confirm_export') && m.hidden,'confirm export posts + closes');
 ok(d.querySelector('.wc-action-button-hithean-order-export-confirm').classList.contains('is-hithean-complete'),'row confirm button marked done');
})();

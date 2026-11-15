// Real shell + UI with a read-only mocked AJAX transport; no WordPress/database writes.
const {JSDOM}=require('jsdom');
const {execFileSync}=require('child_process');
const fs=require('fs'), path=require('path'), assert=require('assert/strict');
const html=execFileSync('php',[path.join(__dirname,'export-missing-images.php'),'--render']).toString();
const dom=new JSDOM('<form id="upload-export-form"><input name="ueif_order_id"><input type="file"><input id="ueif_bulk" type="checkbox" checked><input name="ueif_kind" value="web" type="radio"><input name="ueif_kind" value="shopee" type="radio" checked></form>'+html,{runScripts:'outside-only',url:'https://example.test/tools/'});
const w=dom.window,d=w.document, calls=[];
w.AbortController=AbortController;
w.HTMLElement.prototype.scrollIntoView=function(){};
const imageInput=d.querySelector('[type=file]');let selected=[];
Object.defineProperty(imageInput,'files',{get:()=>selected});
let mode='ok', pending;
w.fetch=async(url,opts)=>{
 const call=Object.fromEntries(opts.body.entries());calls.push(call);
 if(mode==='hold'){mode='ok';return new Promise(resolve=>{pending=resolve;});}
 if(mode==='error')return {ok:false,status:403,json:async()=>({success:false,data:{message:'Không có quyền hoặc phiên đã hết hạn.'}})};
 return response(call);
};
function response(call){return {ok:true,status:200,json:async()=>({success:true,data:{days:Number(call.days),status:call.status,page:Number(call.page),count:1,has_more:call.page==='1',html:'<article>Range '+call.days+'<button type="button" data-missing-upload="101">Upload ảnh</button></article>'}})};}
const tick=()=>new Promise(resolve=>setTimeout(resolve,10));let checks=0;
function ok(value,label){assert.ok(value,label);checks++;}
function change(el,value){el.value=value;el.dispatchEvent(new w.Event('change'));}
w.eval(fs.readFileSync(path.join(__dirname,'../custom-functions/woocommerce/orders/assets/export-missing-images.js'),'utf8'));
(async()=>{
 await tick();
 const box=d.querySelector('[data-ueif-missing]'),days=box.querySelector('[data-missing-days]'),status=box.querySelector('[data-missing-status]');
 ok(calls.length===1 && calls[0].days==='30' && calls[0].page==='1','initial load defaults to 30 days');
 ok(calls[0].nonce==='fixture-thean_export_missing_images' && calls[0].action==='thean_export_missing_images','scoped action and nonce sent');
 change(days,'60');await tick();ok(calls.at(-1).days==='60'&&calls.at(-1).page==='1','60-day filter starts at first page');
 change(days,'90');change(status,'wc-shipping');await tick();
 box.querySelector('[data-missing-next]').click();await tick();
 ok(calls.at(-1).days==='90'&&calls.at(-1).status==='wc-shipping'&&calls.at(-1).page==='2','pagination retains both filters');
 ok(box.querySelector('[data-missing-next]').disabled,'last page disables next');
 box.querySelector('[data-missing-prev]').click();await tick();ok(calls.at(-1).page==='1','previous page works');
 box.querySelector('[data-missing-upload]').click();
 ok(d.querySelector('[name=ueif_order_id]').value==='101'&&!d.querySelector('#ueif_bulk').checked&&d.querySelector('[name=ueif_kind][value=web]').checked,'upload action selects website order and single-order mode');
 selected=[new w.File(['fixture'],'selected.jpg')];d.querySelector('[name=ueif_order_id]').value='99';
 box.querySelector('[data-missing-upload]').click();
 ok(d.querySelector('[name=ueif_order_id]').value==='99'&&box.querySelector('[data-missing-message]').textContent.includes('bỏ chọn'),'cannot reassign already-selected evidence to another order');
 selected=[];
 d.dispatchEvent(new w.CustomEvent('thean:export-images-updated'));await new Promise(resolve=>setTimeout(resolve,380));
 ok(calls.at(-1).days==='90'&&calls.at(-1).status==='wc-shipping'&&calls.at(-1).page==='1','successful upload refresh keeps filters');
 mode='hold';change(days,'60');await tick();change(days,'90');await tick();pending(response({days:'60',page:'1',status:''}));await tick();
 ok(box.querySelector('[data-missing-results]').textContent.includes('Range 90'),'stale response cannot replace current filter results');
 mode='error';box.querySelector('[data-missing-refresh]').click();await tick();
 ok(box.querySelector('[data-missing-message]').classList.contains('is-error')&&days.value==='90'&&status.value==='wc-shipping','authorization error visible while filters stay selected');
 ok(!box.hasAttribute('aria-busy'),'loading state cleared after error');
 console.log(`OK (${checks} missing-image UI checks)`);w.close();
})().catch(error=>{console.error(error);w.close();process.exitCode=1;});

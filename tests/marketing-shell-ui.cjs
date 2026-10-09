const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const base=path.join(__dirname,'..'),expected=['overview','content','calendar','publishing','connections','library','webhooks','campaigns','inbox','insights','channels'];
const assets=['social-publishing','meta-connections','social-inbox','tour-advisor-inbox','tour-share-inbox'];
const source=f=>fs.readFileSync(path.join(base,f),'utf8');
const scripts=html=>[...html.matchAll(/<script src="([^" ]+)/g)].map(m=>m[1]);
const styles=html=>[...html.matchAll(/<link rel="stylesheet" href="([^" ]+)/g)].map(m=>m[1]);
const tick=()=>new Promise(r=>setTimeout(r,20));
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const ok=s=>console.log('PASS '+s);
let shell,dom;
(async()=>{
 // Validate actual entrypoint order and matching cache versions, not a hand-written import list.
 const live=source('index.html'),preview=source('preview.html'),sw=source('service-worker.js');
 for(const name of [...assets,'marketing-studio']){
  for(const kind of [scripts,styles]){
   const l=kind(live).find(x=>x.startsWith(name+'.')),p=kind(preview).find(x=>x.startsWith(name+'.'));
   assert(l,name);assert.equal(p,l,name+' entrypoint asset parity');assert(sw.includes("'./"+l+"'"),name+' cache version');
  }
 }
 for(const html of [live,preview])for(const asset of assets)assert(scripts(html).findIndex(x=>x.startsWith(asset+'.js'))<scripts(html).findIndex(x=>x.startsWith('marketing-studio.js')));
 ok('live and preview load every Marketing dependency before the studio, with matching PWA versions');

 // Load the shipped preview shell and navigate using real click handlers.
 shell=new JSDOM('<div id="app"></div>',{url:'file:///vta/preview.html',runScripts:'outside-only'});
 const pw=shell.window;pw.VTA_PREVIEW=true;pw.TextEncoder=TextEncoder;
 let network=0;pw.fetch=()=>{network++;throw Error('Unexpected preview network call');};
 const observers=[],Observer=pw.MutationObserver;pw.MutationObserver=class extends Observer{constructor(cb){super(cb);observers.push(this);}};
 for(const asset of scripts(preview))pw.eval(source(asset.split('?')[0]));
 await tick();(pw.document.querySelector('[data-nav="marketing"]')||pw.document.querySelector('[data-module="marketing"]')).click();await tick();
 let root=pw.document.querySelector('#workspace');
 assert.deepEqual([...root.querySelectorAll('.mk-tabs button')].map(b=>b.dataset.id),expected);
 for(const id of expected){
  root.querySelector('.mk-tabs [data-id="'+id+'"]').click();await tick();
  assert(root.querySelector('#mkBody').textContent.trim(),id+' must not be blank');
  assert.equal(root.querySelector('.mk-tabs [aria-current="page"]').dataset.id,id);
  assert(!root.textContent.includes('Unable to load this workspace'),id);
 }
 assert.equal(network,0);assert(!root.querySelector('[data-id="landing"]'));
 ok('shipped preview opens all eleven populated tabs offline without provider calls');
 observers.forEach(o=>o.disconnect());shell.window.close();shell=null;

 // Real-mode fixtures exercise controller behavior without accessing a provider or server.
 dom=new JSDOM('<main id="workspace" data-view="marketing"></main>',{url:'https://vta.example.invalid/',runScripts:'outside-only'});
 const w=dom.window,d=w.document;root=d.querySelector('main');w.VTA_I18N={language:'vi'};w.VTA_PREVIEW=true;
 for(const asset of scripts(live).filter(x=>[...assets,'marketing-model','marketing-demo','marketing-studio'].some(name=>x.startsWith(name+'.js'))))w.eval(source(asset.split('?')[0]));
 const fixture=await w.VTAMarketingDemo.request('marketing/studio');w.VTA_PREVIEW=false;w.VTA_I18N={language:'vi'};
 const requests=[],errors=[],failure=new Set();let pendingWebhook;
 const api={user:{company_id:10,id:9},request:async(route,opt)=>{
  requests.push([route,opt?.method||'GET']);
  assert(!opt?.method||opt.method==='GET','UI navigation must never publish or send');
  if(failure.has(route))throw Error('PRIVATE_SERVER_DIAGNOSTIC');
  if(route==='marketing/studio')return fixture;
  if(route==='marketing/webhooks'){
   if(pendingWebhook)return pendingWebhook;
   return {sources:[{source_code:'<img src=x onerror=bad()>',ready:true}],events:[],total_events:0};
  }
  if(route==='marketing/meta-connections')return {accounts:[{product:'FACEBOOK_PAGE',alias:'<img src=x>',entity_id:'123',recently_verified:true,health_status:'VERIFIED',enabled:true,required_scopes:[]}]};
  if(route==='marketing/social-publishing')return {accounts:[],jobs:[{id:8,provider:'FACEBOOK_PAGE',content_id:3,created_by:9,status:'DRAFT',scheduled_at:'2026-12-31 10:00:00'}]};
  if(route==='marketing/social-inbox')return {items:[]};
  if(route==='marketing/meta-replies/accounts')return {accounts:[]};
  throw Error('Unexpected route '+route);
 }};
 const options={api,esc,can:()=>true,toast:(m,error)=>{if(error)errors.push(m);},modal:()=>{},closeModal:()=>{},navigate:()=>{}};
 async function tab(id){root.querySelector('.mk-tabs [data-id="'+id+'"]').click();await tick();}
 await w.VTAMarketing(options);
 assert.equal(root.querySelector('.mk-tabs').getAttribute('aria-label'),'Không gian Marketing');
 assert(!root.textContent.includes('Chưa kết nối đăng bài'));ok('Vietnamese navigation and capability text reflect the current modules');

 await tab('channels');const fb=[...root.querySelectorAll('.mk-content')].find(x=>x.querySelector('h3').textContent==='Facebook');
 fb.querySelector('[data-mk=tab]').click();await tick();assert(root.textContent.includes('Trung tâm kết nối Meta'));
 assert(root.textContent.includes('Đã kiểm tra API chỉ đọc'));assert(!root.querySelector('img'));
 ok('Facebook channel opens real Meta status and escapes account labels');

 // API failure must not masquerade as an empty, disconnected or ready account list.
 const failures=[
  ['connections','marketing/meta-connections','[data-meta-action="refresh"]','trạng thái kết nối Meta','Trung tâm kết nối Meta'],
  ['publishing','marketing/social-publishing','[data-social-action="refresh"]','trạng thái đăng bài','Hàng chờ đăng'],
  ['inbox','marketing/social-inbox','[data-vta-inbox-retry]','hộp thư','Hộp thư chung'],
  ['webhooks','marketing/webhooks','[data-mk="refresh-webhooks"]','sự kiện website','Nguồn đã cấu hình']
 ];
 for(const [id,route,retry,message,recovered] of failures){
  failure.add(route);await tab(id);
  const host=root.querySelector(id==='connections'?'#mkMetaConnections':id==='publishing'?'#mkSocialPublishing':id==='inbox'?'#mkLiveWebsiteInbox':'#mkWebhookCenter');
  assert(host.querySelector('[role=status]'));assert(host.textContent.includes(message));assert(!host.textContent.includes('PRIVATE_SERVER_DIAGNOSTIC'));
  assert(!host.querySelector('form'));failure.delete(route);host.querySelector(retry).click();await tick();
  assert(host.textContent.includes(recovered));assert(!host.querySelector('[role=status]'));
 }
 assert(!root.querySelector('img'));ok('four controller errors show localized retry states and recover without false connection claims');

 // Missing assets produce a recoverable message instead of a blank tab.
 for(const [id,key] of [['connections','VTAMetaConnectionCenter'],['publishing','VTASocialPublisher'],['inbox','VTAWebsiteInbox']]){
  const controller=w[key];w[key]=undefined;await tab(id);assert(root.textContent.includes('Chưa tải được giao diện'));
  w[key]=controller;root.querySelector('[data-mk="retry-module"]').click();await tick();assert(!root.querySelector('[data-mk="retry-module"]'));
 }
 ok('missing controller files show a fallback and recover when the module becomes available');

 await tab('publishing');assert(!root.querySelector('[data-social-action="approve"]'),'author cannot approve own publishing job');
 await w.VTAMarketing({...options,can:()=>false,initialTab:'publishing'});await tick();
 assert(!root.querySelector('[data-social-form="create"]'));assert(!root.querySelector('[data-social-action="approve"]'));
 assert(!root.querySelector('[data-social-action="cancel"]'));ok('read-only accounts cannot create, approve or cancel jobs; self approval stays hidden');

 let resolve;pendingWebhook=new Promise(r=>resolve=r);await tab('webhooks');await tab('calendar');resolve({sources:[],events:[],total_events:0});await tick();pendingWebhook=null;
 assert(root.querySelector('.mk-calendar'));assert(!root.querySelector('#mkWebhookCenter'));
 ok('late webhook responses cannot replace a newer tab');
 assert(requests.every(x=>x[1]==='GET'));assert.equal(errors.length,3);
 ok('navigation, status checks and retry issue reads only; no delivery or provider action');
 dom.window.close();dom=null;
 console.log('NOTE: jsdom integration coverage; visual layout and device installation need browser review.');
})().catch(e=>{console.error(e);process.exitCode=1;shell?.window.close();dom?.window.close();});

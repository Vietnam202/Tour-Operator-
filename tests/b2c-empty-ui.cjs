'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../b2c-studio.js'),'utf8');
const tick=async()=>{for(let i=0;i<4;i++)await new Promise(r=>setImmediate(r));};
function harness({edit=true,request=async()=>({items:[]})}={}){
 const dom=new JSDOM('<main data-view="product"></main>',{url:'https://fixture.invalid/',runScripts:'outside-only'}),w=dom.window,d=w.document,root=d.querySelector('main'),calls=[],errors=[];
 w.eval(source);w.addEventListener('error',e=>{errors.push(e.error||e.message);e.preventDefault();});
 const options={root,can:p=>p==='b2c.view'||edit&&p==='b2c.edit',api:{request:async(route,opt={})=>{calls.push({route,opt});return request(route,opt);}},toast:message=>errors.push(message),onBack:()=>{root.innerHTML='Master';},modal:(title,html)=>{const m=d.createElement('section');m.dataset.modal='';m.innerHTML='<h2>'+title+'</h2>'+html;d.body.append(m);return m;}};
 return{w,d,root,calls,errors,mount:()=>w.VTAB2CStudio(options),close:()=>w.close()};
}
(async()=>{
 for(const edit of [true,false]){
  const h=harness({edit});try{
   await h.mount();assert(h.d.querySelector('[data-b2c-empty]').textContent.includes('Chưa có chương trình B2C'));
   assert.equal(!!h.d.querySelector('[data-create]'),edit);assert(!h.d.querySelector('[role="alert"]'));
   if(!edit)assert(h.root.textContent.includes('Nhờ người có quyền'));
   h.d.querySelector('[data-refresh]').click();await tick();assert.equal(h.calls.length,2);
   assert(h.calls.every(c=>!c.opt.method));assert.deepEqual(h.errors,[]);
  }finally{h.close();}
 }
 console.log('PASS B2C empty state, refresh and view-only permissions');
 let fail=true;const h=harness({request:async()=>fail?null:{items:[]}});try{
  await h.mount();assert(h.d.querySelector('[role="alert"]').textContent.includes('Dữ liệu kho B2C chưa đầy đủ'));
  fail=false;h.d.querySelector('[data-retry]').click();await tick();assert(h.d.querySelector('[data-b2c-empty]'));
  assert(!h.d.querySelector('[role="alert"]'));assert.deepEqual(h.errors,[]);
 }finally{h.close();}
 console.log('PASS malformed B2C response has actionable retry and recovers');
 for(const items of [[],[{id:1,title:'Archived Master',status:'ARCHIVED'}]]){
  const h=harness({request:async route=>route==='b2c'?{items:[]}:{items,total:items.length}});try{
   await h.mount();h.d.querySelector('[data-create]').click();await tick();
   assert(h.d.querySelector('[data-modal]').textContent.includes('Chưa có Master Tour khả dụng'));
   assert(!h.d.querySelector('form'));assert(!h.calls.some(c=>c.opt.method==='POST'));assert.deepEqual(h.errors,[]);
  }finally{h.close();}
 }
 console.log('PASS empty/archived Master library never submits an invalid clone');
 let reject;const pending=new Promise((_,r)=>{reject=r;});let call=0;
 const late=harness({request:async()=>++call===1?pending:{items:[]}});try{
  const first=late.mount();await tick();await late.mount();reject(Error('Old request failed'));await first;
  assert(late.d.querySelector('[data-b2c-empty]'));assert(!late.d.querySelector('[role="alert"]'));assert.deepEqual(late.errors,[]);
 }finally{late.close();}
 console.log('PASS stale failed B2C request cannot overwrite a newer workspace');
 let resolve;const masters=new Promise(r=>{resolve=r;});
 const away=harness({request:async route=>route==='b2c'?{items:[]}:masters});try{
  await away.mount();away.d.querySelector('[data-create]').click();await tick();away.d.querySelector('[data-master]').click();
  resolve({items:[{id:1,title:'Master',status:'ACTIVE'}],total:1});await tick();
  assert.equal(away.root.textContent,'Master');assert(!away.d.querySelector('[data-modal]'));assert.deepEqual(away.errors,[]);
 }finally{away.close();}
 console.log('PASS leaving B2C during Master fetch never opens a stale clone dialog');
})().catch(error=>{console.error(error);process.exitCode=1;});

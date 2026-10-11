'use strict';
// Behavioral smoke checks for the actual RC6.2 Smart Workflow topbar component.
// JSDOM is already installed by the existing unified workspace test dependencies.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../smart-enhancements.js'),'utf8');
const settle=async()=>{for(let n=0;n<8;n++)await new Promise(resolve=>setImmediate(resolve));};
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let passed=0;
function setup(perms,{preview=false,request}={}){
  const dom=new JSDOM('<div class="topbar"><div class="search-wrap"><input id="globalSearch"><div id="searchResults" class="search-results"></div></div><button id="notifyBtn">🔔</button></div>',
    {url:'https://vta.local/',runScripts:'outside-only'});
  const w=dom.window,d=w.document, calls=[],navigation=[],toasts=[];
  w.eval(source);
  const api={user:{id:1,company_id:1,permissions:perms},request:async(route,opt)=>{
    calls.push({route,opt});
    if(request)return request(route,opt);
    if(route==='smart/alerts')return {items:[]};
    if(route.startsWith('smart/search'))return {groups:{}};
    if(route==='quotes')return {items:[]};
    return {items:[]};
  }};
  const modal=(title,body,footer='')=>{
    d.querySelector('.modal-backdrop')?.remove();
    const el=d.createElement('div');
    el.className='modal-backdrop';
    el.innerHTML='<h2>'+title+'</h2>'+body+'<footer>'+footer+'</footer>';
    d.body.append(el);
    return el;
  };
  w.VTASmartEnhancements.mount({api,preview,can:p=>perms.includes(p),modal,toast:(...args)=>toasts.push(args),navigate:(...args)=>navigation.push(args)});
  return{w,d,calls,navigation,toasts,close(){w.close();}};
}
async function test(title,fn){
  await fn();
  passed++;
  console.log('PASS '+title);
}
(async()=>{
  await test('Search filters authorized records, escapes titles, and opens exact Customer 360',async()=>{
    const h=setup(['sales.view','booking.view','supplier.view'],{request:async route=>{
      if(route==='smart/alerts')return {items:[]};
      if(route.startsWith('smart/search'))return {groups:{customers:[{id:9,ref:'C-9',title:'<img src=x onerror=alert(1)>',detail:'Hà Nội'}],quotes:[{id:4,ref:'QT-4',title:'Hà Nội'}]}};
      return {items:[]};
    }});
    try{
      const box=h.d.querySelector('#globalSearch');
      box.value='hanoi';box.dispatchEvent(new h.w.Event('input',{bubbles:true}));
      await pause(325);await settle();
      assert.equal(h.d.querySelectorAll('#searchResults [role=option]').length,2);
      assert.equal(h.d.querySelector('#searchResults img'),null,'server labels never render as active HTML');
      h.d.querySelector('#searchResults [role=option]').click();
      assert.equal(h.navigation[0][0],'quote');
      assert.equal(h.navigation[0][1].quoteId,4);
      box.value='hanoi';box.dispatchEvent(new h.w.Event('input',{bubbles:true}));
      await pause(325);await settle();
      const customers=[...h.d.querySelectorAll('#searchResults [role=option]')].find(x=>x.textContent.includes('C-9'));
      assert(customers);customers.click();
      assert.equal(h.navigation[1][0],'customer-360');
      assert.equal(h.navigation[1][1].customerId,9);
    }finally{h.close();}
  });
  await test('Agency portal-only role never calls internal search/alerts despite mistaken extra permissions',async()=>{
    const h=setup(['b2b.portal','sales.view','booking.view','rate.view','task.view','quote.send','proposal.send'],{request:async()=>{throw Error('Forbidden call must never be made');}});
    try{
      assert(h.d.querySelector('#vtaQuickSend').hidden);
      const search=h.d.querySelector('#globalSearch');
      search.value='tour';search.dispatchEvent(new h.w.Event('input',{bubbles:true}));
      await pause(320);await settle();
      assert.equal(h.calls.length,0);
      assert.equal(h.d.querySelectorAll('#searchResults [role=option]').length,0);
    }finally{h.close();}
  });
  await test('Smart Alerts escape source labels and route using existing booking and control workspaces',async()=>{
    const h=setup(['task.view','operations.view','rate.view'],{request:async route=>{
      if(route==='smart/alerts')return {items:[
        {kind:'booking',id:6,title:'<img src=x onerror=alert(1)>',severity:'critical',detail:'2026-11-10'},
        {kind:'task',id:2,title:'Call supplier',severity:'warning',detail:'2026-10-11'}
      ]};
      return {items:[]};
    }});
    try{
      await settle();
      assert.equal(h.d.querySelector('#notifyBtn .vta-smart-count').textContent,'2');
      h.d.querySelector('#notifyBtn').click();
      await settle();
      assert.equal(h.d.querySelector('.modal-backdrop img'),null);
      const alertBtns=h.d.querySelectorAll('[data-smart-alert]');
      assert.equal(alertBtns.length,2);
      alertBtns[0].click();
      assert.equal(h.navigation[0][0],'booking');
      assert.equal(h.navigation[0][1].bookingId,6);
      h.d.querySelector('#notifyBtn').click();await settle();
      h.d.querySelector('#vtaSmartOpenControl').click();
      assert.equal(h.navigation[1][0],'control');
    }finally{h.close();}
  });
  await test('Preview never enables generation of public proposal links',async()=>{
    const h=setup(['sales.view','quote.send','proposal.send'],{preview:true,request:async route=>{
      if(route==='quotes')return{items:[{id:1,version_status:'SENT',quote_ref:'QT-1'}]};
      if(route==='tasks'||route==='bookings'||route==='rates'||route==='tour-library'||route==='suppliers'||route==='customers')return{items:[]};
      throw Error('Unexpected preview API route '+route);
    }});
    try{
      h.d.querySelector('#vtaQuickSend').click();await settle();
      const button=h.d.querySelector('#vtaSmartGenerate');
      assert(button && button.disabled);
      assert(!h.calls.some(x=>x.route.includes('proposal/links')));
    }finally{h.close();}
  });
  await test('Quick Send requires reviewed SENT snapshot, creates link but never auto-sends',async()=>{
    const h=setup(['sales.view','quote.send','proposal.send'],{request:async(route,opt)=>{
      if(route==='smart/alerts')return{items:[]};
      if(route==='quotes')return{items:[{id:1,quote_ref:'QT-1',title:'Vietnam Tour',version_status:'SENT'}]};
      if(route==='quotes/1')return{version:{id:31,version_status:'SENT'}};
      if(route==='quote-versions/31/proposal/links'&&opt?.method==='POST')return{url:'https://vta.local/api/index.php?route=public-proposals/test',expires_at:'2026-10-25'};
      throw Error('Unexpected API call '+route);
    }});
    try{
      h.d.querySelector('#vtaQuickSend').click();await settle();
      const gen=h.d.querySelector('#vtaSmartGenerate');
      assert(gen&&!gen.disabled);
      gen.click();await settle();
      assert.equal(h.calls.filter(x=>x.opt?.method==='POST').length,0,'review required before POST');
      h.d.querySelector('#vtaSmartReview').checked=true;
      gen.click();await settle();
      const writes=h.calls.filter(x=>x.opt?.method==='POST');
      assert.equal(writes.length,1);
      assert.equal(writes[0].route,'quote-versions/31/proposal/links');
      assert.equal(writes[0].opt.body.days,14);
      assert.equal(h.d.querySelectorAll('a[href^="mailto:"]').length,1);
      assert.equal(h.d.querySelectorAll('a[href^="https://wa.me/"]').length,1);
      assert(h.d.querySelector('#vtaSmartShareResult').textContent.includes('chưa gửi cho khách'));
    }finally{h.close();}
  });
  await test('Read-only Sales cannot issue a quote link or call endpoint',async()=>{
    const h=setup(['sales.view'],{request:async route=>{
      if(route==='smart/alerts')return {items:[]};
      throw Error('Unauthorized quote fetch: '+route);
    }});
    try{
      h.d.querySelector('#vtaQuickSend').click();await settle();
      assert.equal(h.calls.filter(x=>x.route==='quotes').length,0);
      assert(h.toasts.some(x=>String(x[0]).includes('Cần quyền')));
    }finally{h.close();}
  });
  console.log('PASS Smart Workflow P1 UI behavioral groups: '+passed);
})().catch(error=>{console.error(error);process.exitCode=1;});

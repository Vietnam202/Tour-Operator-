'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../tour-program-workspace.js'),'utf8'),copy=x=>JSON.parse(JSON.stringify(x));
const tick=async()=>{for(let i=0;i<8;i++)await new Promise(r=>setImmediate(r));};
const documentModel={schema:'VTA_DOC_2',title:'Tour document',content:{type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Copied prose'}]}]}};
function setup({editable=true,saveError=false}={}){
 const dom=new JSDOM('<main></main>',{url:'https://fixture.local',runScripts:'outside-only'}),w=dom.window;w.eval(source);
 const records=[{id:1,title:'Ha Noi master',tour_code:'VTA-SIC-01',destination:'Ha Noi',status:'ACTIVE',tour_type:'SIC'},{id:2,title:'Central draft',destination:'Hoi An',status:'DRAFT',tour_type:'PRIVATE'},{id:3,title:'Archived',status:'ARCHIVED',tour_type:'PRIVATE'}];
 let current={settings:{document:copy(documentModel)},links:[],costing_revision:5};const calls=[],adopted=[];let before=0;
 const modal=(title,html)=>{w.document.querySelector('.modal')?.remove();const m=w.document.createElement('div');m.className='modal';m.innerHTML=html;w.document.body.append(m);return m;};
 const api={request:async(route,opt={})=>{calls.push({route,opt:copy(opt)});if(saveError&&opt.method==='POST')throw Error('STALE_REVISION');if(route.startsWith('tour-library?')){const offset=Number(new URL('https://fixture.local/'+route).searchParams.get('offset'));return {items:records.slice(offset,offset+2),total:3};}if(route==='tour-library/1/document')return {program:records[0],settings:{document:copy(documentModel)},links:[],costing_revision:'master-hash',immutable:false};if(route.endsWith('apply-to-document'))return {...copy(current),costing_revision:7};if(route==='tour-library/from-quote')return {id:88};throw Error('Unexpected '+route);}};
 const options={api,modal,editable,can:()=>true,versionId:77,title:'Tour document',beforeApply:async()=>{before++;current.costing_revision=6;},getCurrent:()=>current,adopt:(next,previous)=>{adopted.push({next,previous});current=next;}};
 return {w,dom,options,calls,adopted,get before(){return before;},q:s=>w.document.querySelector(s),close:()=>w.close()};
}
(async()=>{
 let h=setup();try{
  await h.w.VTATourProgramWorkspace.picker(h.options);assert.equal(h.calls.filter(x=>x.route.startsWith('tour-library?')).length,2,'Loads remaining pages');assert.equal(h.w.document.querySelectorAll('[data-tpg-id]').length,2,'Archived excluded');
  const search=h.q('[data-tpg-search]');search.value='VTA-SIC';search.dispatchEvent(new h.w.Event('input'));assert.equal(h.w.document.querySelectorAll('[data-tpg-id]').length,1,'Code search');
  h.q('[data-tpg-id="1"]').click();await tick();h.q('[data-tpg-apply]').click();await tick();const mutation=h.calls.find(x=>x.opt.method==='POST');assert.deepEqual(mutation.opt.body,{version_id:77,expected_revision:6,program_revision:'master-hash'});assert.equal(h.before,1);assert.equal(h.adopted[0].previous.costing_revision,6);assert.equal(h.adopted[0].previous.settings.document.title,'Tour document');assert(!h.q('.modal'));
  console.log('PASS picker pagination, code search, archived exclusion, save-before-copy and revision-only API payload');
 }finally{h.close();}
 h=setup({editable:false});try{await h.w.VTATourProgramWorkspace.picker(h.options);h.q('[data-tpg-id="1"]').click();await tick();assert(h.q('[data-tpg-apply]').disabled);h.q('[data-tpg-apply]').click();await tick();assert(!h.calls.some(x=>x.opt.method==='POST'));console.log('PASS issued/read-only program browsing never writes');}finally{h.close();}
 h=setup({saveError:true});try{await h.w.VTATourProgramWorkspace.picker(h.options);h.q('[data-tpg-id="1"]').click();await tick();h.q('[data-tpg-apply]').click();await tick();assert(h.q('[data-tpg-error]').textContent.includes('STALE_REVISION'));assert.equal(h.adopted.length,0);assert(!h.q('[data-tpg-apply]').disabled);console.log('PASS failed copy keeps the old document and an actionable retry');}finally{h.close();}
 h=setup();try{h.options.beforeApply=async()=>{h.q('.modal').remove();};await h.w.VTATourProgramWorkspace.picker(h.options);h.q('[data-tpg-id="1"]').click();await tick();h.q('[data-tpg-apply]').click();await tick();assert(!h.calls.some(x=>x.opt.method==='POST'));console.log('PASS dismissed picker cannot apply after an asynchronous save');}finally{h.close();}
 h=setup();try{await h.w.VTATourProgramWorkspace.saveAs(h.options);const form=h.q('form');form.dispatchEvent(new h.w.Event('submit',{cancelable:true}));await tick();assert(!h.calls.some(x=>x.opt.method==='POST'));form.elements.reviewed.checked=true;form.elements.destination.value='Hanoi';form.dispatchEvent(new h.w.Event('submit',{cancelable:true}));await tick();const mutation=h.calls.find(x=>x.route==='tour-library/from-quote');assert.equal(mutation.opt.body.reviewed,true);assert.equal(mutation.opt.body.expected_revision,6);assert.equal(mutation.opt.body.version_id,77);assert(!('quote' in mutation.opt.body)&&!('pricing' in mutation.opt.body));console.log('PASS save-as requires reviewed content and never submits quote/customer/cost records');}finally{h.close();}
 const dom=new JSDOM('<main id="workspace" data-view="product"></main>',{url:'https://fixture.local',runScripts:'outside-only'});try{
  const w=dom.window;w.eval(source);w.eval(fs.readFileSync(path.join(__dirname,'../tour-library.js'),'utf8'));let resolve;
  const delayed=new Promise(r=>{resolve=r;}),program={id:1,title:'Word master',language:'en',status:'DRAFT',days:[],tags:[],has_document:true};
  const api={request:async route=>route==='tour-library'?{items:[program],total:1}:route==='tour-library/1'?{program}:delayed};
  w.mountVisualProposal=async(root,o)=>{await o.api.request('tour-library/1/document');root.innerHTML='<p>Late old editor</p>';};
  await w.VTATourLibrary({api,esc:x=>String(x??''),can:()=>true,navigate:()=>{},modal:()=>{},toast:()=>{}});w.document.querySelector('[data-tl-word]').click();await tick();
  const root=w.document.querySelector('#workspace');root.dataset.view='home';root.innerHTML='<p>Current workspace</p>';resolve({});await tick();assert.equal(root.textContent,'Current workspace');
  console.log('PASS a delayed master document response cannot replace a newer workspace');
 }finally{dom.window.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

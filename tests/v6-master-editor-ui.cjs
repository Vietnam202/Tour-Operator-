'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../tour-library.js'),'utf8');
const editorSource=fs.readFileSync(path.join(__dirname,'../document-editor.js'),'utf8');
const clone=v=>JSON.parse(JSON.stringify(v));
const item={id:1,title:'Vietnam Discovery',destination:'Hanoi, Ha Long',language:'en',tags:['NORTH'],day_count:2,status:'ACTIVE',days:[{day:1,title:'Hanoi',description:'Arrival'},{day:2,title:'Ha Long',description:'Cruise'}],included_text:'Transfer',excluded_text:'Flights',terms_text:'Review terms'};
const template={...clone(item),id:2,title:'Vietnam Template',tags:['VTA_TEMPLATE','NORTH'],status:'DRAFT',proposal:{overview:'Template overview',group_prices:[{hotel:'3',price:'100'}],private_prices:[{min:2,max:2,three:'200'}]}};
const rich={...clone(template),id:3,title:'Rich Template',has_document:true};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const tick=()=>new Promise(r=>setImmediate(r));
async function settle(){for(let k=0;k<7;k++)await tick();}
async function start(perms=['tour_library.view','tour_library.manage','product.manage']){
 const dom=new JSDOM('<main id="workspace" data-view="product"></main>',{url:'https://vta.invalid/',runScripts:'outside-only'});
 const w=dom.window,d=w.document,posts=[],notices=[];
 w.VTA_PREVIEW=false;w.confirm=()=>true;w.crypto.randomUUID=()=>`v6-test-${Date.now()}`;
 let next=100;
 const programs=[item,template,rich];
 const api={request:async(route,option={})=>{
  if(route==='tour-library'&&!option.method)return {items:clone(programs),total:programs.length};
  if(route==='tour-library'&&option.method==='POST'){posts.push(clone(option.body));const p={...clone(option.body),id:++next};programs.push(p);return {id:p.id};}
  const m=/^tour-library\/(\d+)$/.exec(route);
  if(m&&!option.method){const p=programs.find(x=>Number(x.id)===Number(m[1]));return {program:clone(p)};}
  throw Error('Unexpected route '+route);
 }};
 const f={api,esc,can:p=>perms.includes(p),navigate:()=>{},modal:()=>{throw Error('Unexpected dialog');},toast:(...args)=>notices.push(args)};
 let opened=null;w.VTATourProposalStudio={mount:(_root,opts)=>{opened=opts.program;}};
 w.eval(source);await w.VTATourLibrary(f);await settle();
 return {dom,w,d,posts,notices,opened:()=>opened};
}
(async()=>{
 const h=await start();
 assert(h.d.querySelector('[data-tl-tab="library"]'));
 assert.equal(h.d.querySelectorAll('[data-tl-card]').length,1,'Master view excludes content template');
 assert(h.d.querySelector('[data-tl-layout="gallery"]'));
 h.d.querySelector('[data-tl-layout="list"]').click();await settle();
 assert(h.d.querySelector('.v6-list-view'),'List changes only presentation');
 h.d.querySelector('[data-tl-tab="templates"]').click();await settle();
 assert.equal(h.d.querySelectorAll('[data-tl-card]').length,2,'Template Center lists company templates');
 assert.equal(h.d.querySelectorAll('[data-tl-use-template]').length,1,'Rich source cannot be silently cloned');
 h.d.querySelector('[data-tl-use-template]').click();await settle();
 assert.equal(h.posts.length,1,'Creates via existing company scoped Tour Library API');
 assert.equal(h.posts[0].status,'DRAFT');
 assert(!h.posts[0].tags.includes('VTA_TEMPLATE'));
 assert.equal(h.posts[0].source_type,'MANUAL');
 assert(!('group_prices' in h.posts[0].proposal),'Old unreviewed selling price removed');
 assert(!('private_prices' in h.posts[0].proposal),'Private price removed');
 assert.equal(template.proposal.group_prices[0].price,'100','Original template unchanged');
 assert(h.posts[0].creation_key,'POST has unique creation key');
 assert.equal(h.opened().title,h.posts[0].title,'New draft opens for editing');
 h.dom.window.close();
 const readOnly=await start(['tour_library.view']);
 readOnly.d.querySelector('[data-tl-tab="templates"]').click();await settle();
 assert(!readOnly.d.querySelector('[data-tl-use-template]'),'No edit action without manage permission');
 readOnly.dom.window.close();
 assert(editorSource.includes('v6-editor-layout')&&editorSource.includes('refreshV6Outline()'));
 assert(editorSource.includes('data-v6-insert="day"')&&editorSource.includes('data-v6-layout-picker'));
 assert(editorSource.includes('expected_revision:ctx.costing_revision'),'Authoritative server revision preserved');
 console.log('PASS V6 Gallery/List, Template Center, safe clone, permission and editor integration contracts');
})().catch(e=>{console.error(e);process.exitCode=1;});

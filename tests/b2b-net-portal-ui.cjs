'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const script=fs.readFileSync(path.join(__dirname,'../partner-hub.js'),'utf8');
const tick=async()=>{for(let i=0;i<24;i++)await new Promise(resolve=>setImmediate(resolve));};
const response=(data,ok=true)=>({ok,json:async()=>data});
async function harness({sales=false}={}){
 const dom=new JSDOM('<span id="account"></span><button id="logout" hidden></button><div id="hub"></div>',{url:'https://vta.test/partner.html',runScripts:'outside-only'});
 const w=dom.window,calls=[];let attempts=0,sequence=0;
 // jsdom lacks HTMLElement.innerText; a real browser exposes this API.
 Object.defineProperty(w.HTMLElement.prototype,'innerText',{configurable:true,get(){return this.textContent;},set(value){this.textContent=value;}});
 Object.defineProperty(w.crypto,'randomUUID',{configurable:true,value:()=>('retry-key-'+(++sequence)).padEnd(24,'x')});
 w.alert=()=>{};
 w.fetch=async(url,opts={})=>{
  const u=new URL(url,w.location.href),route=u.searchParams.get('route');
  const body=opts.body?JSON.parse(opts.body):null;
  calls.push({route,method:opts.method||'GET',body});
  if(route==='auth/me')return response({user:{id:sales?9:21,full_name:sales?'Assigned Sales':'Partner User',csrf:'fixture-token'}});
  if(route==='b2b/me')return response(sales?{admin:false,sales:true,agencies:[]}:{admin:false,agencies:[{id:10,name:'Agency A',brand_name:'Agency A'}]});
  if(route==='b2b/kpi')return response({agency:{id:10,brand_name:'Agency A <script>bad</script>'},published_tours:2,active_copies:2,
    net_requests:{REQUESTED:1,IN_REVIEW:1,CLOSED:0},booking_requests:{REQUESTED:1,IN_REVIEW:0,CONFIRMED:0,REJECTED:0}});
  if(route==='b2b/sales/net-requests')return response({items:[{id:72,agency_id:10,partner_tour_id:11,departure_date:'2027-01-10',paying_pax:6,foc_pax:1,hotel_level:'3',cruise_level:'5',mode:'PRIVATE',notes:'<script>private</script>',status:'IN_REVIEW'}]});
  if(route==='b2b/tours')return response({items:[]});
  if(route==='b2b/my-tours'&&(!opts.method||opts.method==='GET'))return response({items:[{id:11,agency_id:10,title:'Ha Long',agency:'Agency A',revision:1,publication_status:'PUBLISHED'}]});
  if(route==='b2b/my-tours/11'&&(!opts.method||opts.method==='GET'))return response({tour:{id:11,revision:1,agency:{id:10,name:'Agency A',brand_name:'Agency A'},markup_type:'PERCENT',markup_value:0,content:{title:'Ha Long',destination:'Ha Long',overview:'Trip',included_text:'Included',excluded_text:'Excluded',terms_text:'Terms',days:[{title:'Day 1',description:'Cruise',meals:'L',overnight:'Boat'}]}}});
  if(route==='b2b/my-tours/11'&&opts.method==='PUT')return response({revision:2});
  if(route==='b2b/net-requests'&&opts.method==='POST'){
   attempts++;
   return attempts===1?response({message:'Temporary gateway loss'},false):response({id:72,reused:attempts===2,status:'REQUESTED'},attempts===2);
  }
  throw Error('Unexpected mock endpoint '+route);
 };
 w.eval(script);await tick();
 return {dom,w,calls,root:w.document.getElementById('hub')};
}
(async()=>{
 const s=await harness({sales:true});
 assert(s.root.textContent.includes('My NET Tasks'),'sales-only login must display assigned queue');
 assert(!s.root.textContent.includes('Tour Library'),'Sales-only login must not inherit agency navigation');
 assert(s.root.textContent.includes('6 + 1 FOC'),'Sales sees reviewed request population');
 assert(!s.root.querySelector('script'),'Sales request notes must be escaped, not injected');
 assert(!s.calls.some(c=>c.route==='b2b/tours'),'Sales must not fetch partner catalogue');
 console.log('PASS Sales-only account sees scoped NET task UI without partner access');
 s.dom.window.close();
 const h=await harness();
 h.root.querySelector('[data-tab="kpi"]').click();await tick();
 assert(h.root.textContent.includes('Partner KPI'),'Partner users see their scoped activity dashboard');
 assert(h.calls.some(c=>c.route==='b2b/kpi'),'Partner KPI requires a server-authenticated request');
 assert(h.root.textContent.includes('Published tours available')&&h.root.textContent.includes('NET · In review'),
   'KPI reports publication and NET request statuses');
 assert(!h.root.querySelector('script'),'KPI brand labels must escape injected markup');
 assert(!h.root.textContent.includes('net_per_pax'),'KPI must not render supplier NET fields');
 console.log('PASS agency KPI renders scoped counts without guest or supplier details');
 h.root.querySelector('[data-tab="my"]').click();await tick();
 h.root.querySelector('[data-edit]').click();await tick();
 const form=h.root.querySelector('#requestNetForm');
 assert(form,'Partner NET request form loaded');
 form.elements.departure_date.value='2027-01-10';
 form.elements.foc.value='1';
 h.root.querySelector('#quoteForm').elements.hotel.value='3';
 h.root.querySelector('#quoteForm').elements.cruise.value='5';
 form.dispatchEvent(new h.w.Event('submit',{bubbles:true,cancelable:true}));await tick();
 const first=h.calls.filter(x=>x.route==='b2b/net-requests').at(-1);
 assert(first,'First NET attempt captured; calls='+JSON.stringify(h.calls.map(x=>x.route))+' status='+h.root.querySelector('[data-hub-status]')?.textContent);
 assert.equal(first.body.foc,1);
 assert.equal(first.body.hotel,'3');assert.equal(first.body.cruise,'5');
 assert(h.root.textContent.includes('Temporary gateway loss'),'Transient failure should remain visible');
 form.dispatchEvent(new h.w.Event('submit',{bubbles:true,cancelable:true}));await tick();
 const second=h.calls.filter(x=>x.route==='b2b/net-requests').at(-1);
 assert.equal(second.body.request_id,first.body.request_id,'Unchanged form must retain one idempotency key after uncertain response');
 assert(h.root.textContent.includes('no duplicate created'),'API deduplication feedback shown');
 console.log('PASS partner retries same NET request key after uncertain response');
 h.dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});

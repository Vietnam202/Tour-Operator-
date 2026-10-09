'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const clone=value=>JSON.parse(JSON.stringify(value));
const settle=async()=>{for(let i=0;i<20;i++)await new Promise(r=>setImmediate(r));};
async function harness(status='DRAFT',cost=true){
 const dom=new JSDOM('<main></main>',{url:'http://127.0.0.1/',runScripts:'outside-only'}),w=dom.window,host=w.document.querySelector('main'),calls=[];
 for(const file of ['cost-sheet.js','cost-sheet-studio.js'])w.eval(fs.readFileSync(path.join(__dirname,'..',file),'utf8'));
 const ctx={costing_revision:10,guests:{total_guests:12,paying_pax:10,foc:2},profile:{hotel_pax:12,cruise_pax:12},schedule:[],requirements:[{id:1,category:'HOTEL',service_name:'Hanoi Hotel',service_units:2,service_date:'2027-01-01',scope:{destination:'Hanoi',dates:['2027-01-01','2027-01-02']},metadata:{hotel_names:{3:'A',4:'B',5:'C'}},default_quantity_source:'HOTEL_PAX',requirement_state:'REQUIRED'}]};
 const data={supplier_choices:[{id:5,name:'Test supplier'}],items:[3,4,5].map(star=>({variant_id:star,variant_key:'private-'+star+'-'+star,costing_mode:'PRIVATE',hotel_level:String(star),cruise_level:star,pricing:{cost_total_vnd:'2400',cost_per_paying_pax:'240'},lines:[{requirement_id:1,line_kind:'SERVICE',active:true,unit_rate_vnd:'100',total_vnd:'2400',supplier_id:5,manual_reason:'Fixture contract',coverage_state:'PRICED',resolved_quantity:12}]}))};
 const api={request:async(route,opt={})=>{
  calls.push({route,...clone(opt)});
  if(opt.method){assert.equal(opt.body.expected_revision,ctx.costing_revision);ctx.costing_revision++;const item=data.items.find(p=>p.variant_id===3);item.pricing.cost_total_vnd='9876';item.pricing.cost_per_paying_pax='987.6';item.lines[0].total_vnd='9876';item.lines[0].unit_rate_vnd=opt.body.lines?.[3]?.unit_amount_original||'100';return {costing_revision:ctx.costing_revision};}
  if(route.endsWith('/options'))return clone(data);if(route.endsWith('/smart-costing/context'))return clone(ctx);if(route.endsWith('/price-matrix'))return {cells:[],config:{bands:[]}};return {settings:{},days:[],links:[]};
 }};
 await w.mountCostSheet(host,{api,quoteData:{quote:{id:1,quote_ref:'FIXTURE'},version:{id:12,version_status:status,version_no:1,tour_name:'Synthetic UI test'}},can:p=>p==='quote.view_cost'?cost:true,ctx:clone(ctx),data:clone(data),navigate:()=>{},toast:()=>{},refresh:()=>{},onProposal:()=>{}});await settle();return {w,host,calls,dom};
}
(async()=>{
 let h=await harness();try{
  assert.equal(h.host.querySelectorAll('[data-vta-summary]').length,3);assert.equal(h.host.querySelectorAll('[data-mix-cruise]').length,3);assert.equal(h.host.querySelector('[data-count]').value,'12');assert.equal(h.host.querySelector('[data-units]').value,'2');
  const input=h.host.querySelector('[data-vta-option-stay][data-index="0"] [data-option-rate]');input.value='123';input.dispatchEvent(new h.w.Event('change'));await h.w.__vtaCostBeforeLeave();await settle();
  const mutation=h.calls.find(c=>c.method);assert.equal(mutation.body.lines[3].unit_amount_original,'123');assert.deepEqual(mutation.body.variant_ids,[3]);assert.equal(mutation.body.lines[3].supplier_id,5);assert.equal(mutation.body.lines[3].manual_reason,'Fixture contract');assert(!('total' in mutation.body)&&!('pricing' in mutation.body));assert(h.host.querySelector('[data-vta-summary="0"] [data-total]').textContent.includes('9,876'));
  assert(h.host.querySelector('[data-vta-option-stay][data-index="0"] .vta-option-line-total b').textContent.includes('9,876'));
  console.log('PASS V5 costing: three editable hotel/cruise options, pax × nights, supplier evidence, server totals and queued save before leaving');
 }finally{h.dom.window.close();}
 h=await harness('SENT');try{assert([...h.host.querySelectorAll('input,select')].every(i=>i.disabled));assert(!h.host.querySelector('[data-add]'));assert(!h.calls.some(c=>c.method));console.log('PASS SENT costing is read-only, including supplier selectors');}finally{h.dom.window.close();}
 h=await harness('DRAFT',false);try{assert(!h.host.querySelector('.vta-direct-cost'));assert(!h.calls.some(c=>c.route.endsWith('/options')||c.route.endsWith('/price-matrix')));console.log('PASS no new costing UI or cost requests without quote.view_cost');}finally{h.dom.window.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

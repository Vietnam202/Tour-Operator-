const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../vta-cost-editor.js'),'utf8');
const settle=async()=>{for(let i=0;i<15;i++)await new Promise(r=>setImmediate(r));};
function fixture(star,cat,id){
  const rate=cat==='HOTEL'?star*100000:cat==='CRUISE'?star*500000:700000;
  return {id:id+star*100,requirement_id:id,line_kind:'SERVICE',active:true,quantity_source:cat==='TRANSPORT'?'CUSTOM_QTY':(cat==='HOTEL'?'HOTEL_PAX':'CRUISE_PAX'),
    resolved_quantity:cat==='TRANSPORT'?1:6,custom_quantity:cat==='TRANSPORT'?1:null,unit_rate_vnd:String(rate),
    total_vnd:String(rate*(cat==='TRANSPORT'?2:cat==='HOTEL'?12:6)),coverage_state:'PRICED',
    supplier_id:1,manual_reason:'Supplier rate contract',review_required:0};
}
async function harness({locked=false,cost=true}={}){
  const dom=new JSDOM('<main></main>',{url:'https://rc6.local/',runScripts:'outside-only'});
  const w=dom.window,root=w.document.querySelector('main'),calls=[];
  w.mountCostSheet=async host=>{host.textContent='Legacy view';};
  w.eval(source);
  const ctx={costing_revision:9,guests:{adults:6,children:0,infants:0,foc:0,total_guests:6,paying_pax:6},
    profile:{hotel_pax:6,cruise_pax:6,meal_pax:6,ticket_pax:6},
    schedule:[],requirements:[
      {id:10,category:'HOTEL',service_name:'Hanoi Hotel',requirement_state:'REQUIRED',service_units:2,default_quantity_source:'HOTEL_PAX',scope:{destination:'Hanoi'},metadata:{}},
      {id:11,category:'TRANSPORT',service_name:'Airport Transfer',requirement_state:'REQUIRED',service_units:2,default_quantity_source:'CUSTOM_QTY',scope:{destination:'Hanoi'},metadata:{}},
      {id:12,category:'CRUISE',service_name:'Halong Overnight',requirement_state:'REQUIRED',service_units:1,default_quantity_source:'CRUISE_PAX',scope:{destination:'Halong'},metadata:{}},
      {id:13,category:'HOTEL',service_name:'Sapa Hotel',requirement_state:'REQUIRED',service_units:1,default_quantity_source:'HOTEL_PAX',scope:{destination:'Sapa'},metadata:{}}
    ]};
  const d={items:[3,4,5].map(star=>({variant_id:star,variant_key:'private-'+star+'-'+star,
    costing_mode:'PRIVATE',hotel_level:star+'*',cruise_level:star,pricing:{cost_total_vnd:String(star*9000000)},
    lines:[fixture(star,'HOTEL',10),fixture(star,'TRANSPORT',11),fixture(star,'CRUISE',12),
      {...fixture(star,'HOTEL',13),unit_rate_vnd:String(star*120000)}]})),
    supplier_choices:[{id:1,name:'Supplier A'}]};
  d.items.push({...d.items[1],variant_id:44,variant_key:'custom-4-3',cruise_level:3});
  const api={request:async(route,opts={})=>{
    calls.push({route,...opts});
    if(route.endsWith('/options'))return structuredClone(d);
    if(route.endsWith('/smart-costing/context')&&(!opts.method||opts.method==='GET'))return structuredClone(ctx);
    if(route.endsWith('/smart-costing/validation'))return {valid:false,errors:[{code:'RATE_REVIEW_REQUIRED'}],warnings:[]};
    if(opts.method){
      if(opts.body?.line?.unit_amount_original==='999999')throw Error('Supplier unavailable');
      ctx.costing_revision++;
      const b=opts.body||{};
      if(route.endsWith('/smart-costing/context')){
        if(b.hotel_pax!==undefined)ctx.profile.hotel_pax=b.hotel_pax;
        if(b.cruise_pax!==undefined)ctx.profile.cruise_pax=b.cruise_pax;
        if(b.paying_pax!==undefined)ctx.guests.paying_pax=b.paying_pax;
      }
      if(b.action==='mix'){
        d.items.push({...d.items[0],variant_id:99,variant_key:'mixed-3-5',cruise_level:5,pricing:{cost_total_vnd:'36000000'}});
        return {costing_revision:ctx.costing_revision,variant_id:99};
      }
      if(b.action==='remove')ctx.requirements.find(x=>x.id===b.requirement_id).requirement_state='NOT_APPLICABLE';
      if(b.requirement?.id&&b.requirement.requirement_state==='REQUIRED')ctx.requirements.find(x=>x.id===b.requirement.id).requirement_state='REQUIRED';
      return {costing_revision:ctx.costing_revision};
    }
    throw Error('Unexpected URL '+route);
  }};
  const env={api,quoteData:{quote:{quote_ref:'Q-TEST'},version:{id:25,version_status:locked?'SENT':'DRAFT',version_no:1,tour_name:'Hanoi Sapa Halong',start_date:'2027-01-01'}},
    ctx,data:d,can:perm=>perm==='quote.view_cost'?cost:true,
    navigate:()=>{},toast:msg=>{calls.push({toast:msg});},refresh:async()=>{},onProposal:()=>{}};
  await w.mountCostSheet(root,env);
  return {dom,w,root,ctx,d,calls,env};
}
(async()=>{
  let h=await harness(),{root,w,calls}=h;
  assert.equal(root.querySelectorAll('[data-vta-row]').length,4);
  assert.equal(root.querySelectorAll('.vta-stay-line').length,3);
  assert.equal(root.querySelectorAll('[data-vta-summary]').length,3);
  assert.equal(root.querySelector('[data-vta-row="10"] [data-name]').value,'Hanoi Hotel');
  assert.equal(root.querySelector('[data-vta-row="13"] [data-destination]').value,'Sapa');
  assert.equal(root.querySelector('[data-vta-row="10"] [data-destination]').value,'Hanoi');
  const sapaRate=root.querySelector('[data-vta-row="13"] [data-rate="2"]');
  sapaRate.value='735000';sapaRate.dispatchEvent(new w.Event('change'));await settle();
  const sapaSave=calls.filter(c=>c.body?.requirement_id===13&&c.body?.lines).at(-1);
  assert.deepEqual(Array.from(sapaSave.body.variant_ids),[5]);
  assert.equal(sapaSave.body.lines[5].unit_amount_original,'735000');
  assert.equal(root.querySelector('[data-vta-row="10"] [data-name]').value,'Hanoi Hotel');
  console.log('PASS separate Hanoi and Sapa hotel rows with independent rates for each option');
  const hotel=root.querySelector('[data-vta-row="10"]');
  hotel.querySelector('[data-name]').value='Hanoi New Hotel';
  hotel.querySelector('[data-name]').dispatchEvent(new w.Event('change',{bubbles:true}));await settle();
  assert(calls.some(c=>c.body?.requirement?.id===10&&c.body.requirement.service_name==='Hanoi New Hotel'));
  const rate=root.querySelector('[data-vta-row="11"] [data-rate="0"]');
  rate.value='850000';rate.dispatchEvent(new w.Event('change',{bubbles:true}));await settle();
  const saved=calls.filter(c=>c.route.endsWith('/smart-costing/sheet')).at(-1);
  assert.equal(saved.body.shared,true);assert.equal(saved.body.line.unit_amount_original,'850000');
  assert.equal(saved.body.line.manual_reason,'Supplier rate contract');
  assert.equal(saved.body.variant_ids.length,3);
  console.log('PASS inline shared cost update writes supplier evidence and server-calculates totals');
  const pax=root.querySelector('[data-guest="hotel_pax"]');pax.value='5';pax.dispatchEvent(new w.Event('change'));await settle();
  assert(calls.some(c=>c.route.endsWith('/smart-costing/context')&&c.method==='PUT'&&c.body.hotel_pax===5));
  const previousCalls=calls.length;pax.value='7';pax.dispatchEvent(new w.Event('change'));await settle();
  assert.equal(calls.length,previousCalls);assert.equal(pax.value,'5');
  const firstDate=root.querySelector('[data-vta-row="10"] [data-service-date]');
  firstDate.value='2027-01-02';firstDate.dispatchEvent(new w.Event('change'));await settle();
  assert(calls.some(c=>c.body?.requirement?.id===10&&c.body.requirement.scope?.dates?.join(',')==='2027-01-02,2027-01-03'));
  console.log('PASS guest populations edit directly with quote revision');
  // First select the existing 4-star-hotel / 3-star-cruise draft, then attempt to duplicate Option B (4/4).
  let duplicate=root.querySelector('[data-mix-hotel="0"]');duplicate.value='4';
  duplicate.dispatchEvent(new w.Event('change'));await settle();
  assert.equal(root.querySelector('[data-vta-summary="0"] [data-mix-hotel]').value,'4');
  duplicate=root.querySelector('[data-mix-cruise="0"]');duplicate.value='4';
  duplicate.dispatchEvent(new w.Event('change'));await settle();
  assert.equal(root.querySelector('[data-vta-summary="0"] [data-mix-cruise]').value,'3');
  assert(root.querySelector('[data-vta-status]').textContent.includes('already selected'));
  duplicate=root.querySelector('[data-mix-hotel="0"]');duplicate.value='3';
  duplicate.dispatchEvent(new w.Event('change'));await settle();
  let mix=root.querySelector('[data-mix-cruise="0"]');mix.value='5';mix.dispatchEvent(new w.Event('change'));await settle();
  assert(calls.some(c=>c.body?.action==='mix'&&c.body.hotel_level===3&&c.body.cruise_level===5));
  assert(root.textContent.includes('Hotel 3★ / Cruise 5★'));
  assert.equal(JSON.parse(root.dataset.vtaCostVariants)[0],99);
  console.log('PASS independently mix hotel 3-star with cruise 5-star without changing other options');
  root.querySelector('[data-new-service]').value='TOUR';
  root.querySelector('[data-add]').click();await settle();
  assert(calls.some(c=>c.body?.requirement?.category==='TOUR'&&c.body.requirement.service_name==='Group Tour / SIC'));
  console.log('PASS + Add service category without editor popup');
  const hotelReview=root.querySelector('[data-vta-row="10"]');
  const proof=hotelReview.querySelector('[data-proof="0"]');
  proof.querySelector('[data-review-note]').value='Contract and nights reviewed';
  // Explicit review command remains separate from entry of a rate.
  const reviewButton=proof.querySelector('[data-review-action="0"]');
  reviewButton.hidden=false;reviewButton.click();await settle();
  assert(calls.some(c=>c.body?.action==='review'&&c.body.requirement_id===10&&
    c.body.review_reason==='Contract and nights reviewed'&&c.body.variant_ids.length===1));
  const unsaved=root.querySelector('[data-vta-row="11"] [data-rate="0"]');
  unsaved.value='999999';unsaved.dispatchEvent(new w.Event('change'));await settle();
  assert(root.querySelector('[data-vta-status]').textContent.includes('Save failed'));
  console.log('PASS explicit supplier review and failed autosave warning');
  const del=root.querySelector('[data-vta-row="11"]');
  del.querySelector('[data-remove]').click();
  assert.equal(del.querySelector('.vta-remove-confirm').hidden,false);
  del.querySelector('[data-remove-reason]').value='Transfer not required';
  del.querySelector('[data-confirm-remove]').click();await settle();
  assert(calls.some(c=>c.body?.action==='remove'&&c.body.requirement_id===11&&c.body.reason==='Transfer not required'));
  assert(root.querySelector('[data-undo]'));root.querySelector('[data-undo]').click();await settle();
  assert(calls.some(c=>c.body?.requirement?.id===11&&c.body.requirement.requirement_state==='REQUIRED'));
  console.log('PASS inline remove reason and undo backed by audit-safe requirement state');
  root.querySelector('[data-next]').click();assert.equal(root.dataset.sheetStep,'price');
  h.dom.window.close();
  h=await harness({locked:true});assert(!h.root.querySelector('[data-add]'));
  assert([...h.root.querySelectorAll('[data-rate]')].every(x=>x.disabled));
  console.log('PASS issued quotes remain read-only');h.dom.window.close();
  h=await harness({cost:false});assert.equal(h.root.textContent,'Legacy view');
  assert(!h.calls.some(c=>c.route.endsWith('/options')));
  console.log('PASS no finance access delegates to protected legacy view');h.dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});

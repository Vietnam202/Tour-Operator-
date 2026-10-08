const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../commercial-matrix.js'),'utf8');
async function harness({permission=true,status='DRAFT',generated=false}={}){
 const dom=new JSDOM('<main></main>',{url:'https://testing.invalid/',runScripts:'outside-only'});
 const w=dom.window,root=w.document.querySelector('main'),calls=[];
 w.eval(source);
 const option={variant_id:34,label:'Private · Hotel 3★ + Cruise 4★',costing_mode:'PRIVATE',
   hotel_level:'3*',cruise_level:4,is_offered:1,lines:[{id:701,line_kind:'SERVICE',formula_code:'TRANSFER_PACKAGE',service_name:'Transfer Hanoi',custom_quantity:1}]};
 const data={costing_revision:5,matrix:generated?{status:'DRAFT'}:null,cells:generated?[{id:1,variant_id:34,
   band_key:'2-2',min_pax:2,max_pax:2,channel:'B2B_AGENT',selling_currency:'USD',
   selling_per_pax:null,status:'SCENARIO_REVIEW_REQUIRED',result:{mode:'PRIVATE',hotel_level:'3*',cruise_level:4,
   label:'Hotel 3 / Cruise 4',scenarios:[]}}]:[],config:generated?{vehicle_bands:[{min:2,max:2,vehicle:'Sedan / 7-seater',reason:'Approved supplier contract'}]}:{}};
 const api={request:async(url,opt={})=>{
  calls.push({url,...opt});
  if(url.endsWith('/price-matrix'))return data;
  if(url==='commercial-policies')return {items:[{id:1,channel:'B2B_AGENT',name:'B2B 20% Markup',version_no:1,pricing_value:'20',policy_mode:'B2B_MARKUP',selling_currency:'USD'}]};
  if(url.endsWith('/options'))return {items:[option]};
  if(url.endsWith('/transport-options'))return {items:[{rate_version_id:44,product_name:'Vehicle 7 seats',supplier_name:'Synthetic supplier',capacity:7,amount:'850000',currency:'VND'}]};
  return {costing_revision:6};
 }};
 const ctx={api,quoteData:{quote:{quote_ref:'TEST-GROUP',id:1},version:{id:9,fx_rate:'25500',version_no:1,version_status:status}},
   can:name=>name==='quote.view_cost'?permission:true,refresh:async()=>{},modal:()=>{},closeModal:()=>{},toast:()=>{},onBack:()=>{},onProposal:()=>{}};
 await w.mountCommercialMatrix(root,ctx);return {dom,w,root,calls};
}
(async()=>{
 let h=await harness(),{root,w,calls}=h;
 assert.equal(root.querySelectorAll('[data-vehicle-band]').length,5);
 assert.deepEqual(Array.from(root.querySelectorAll('[data-vehicle-band]')).map(x=>x.dataset.vehicleBand),['2-2','3-4','5-9','10-14','15-20']);
 assert.equal(root.querySelector('[name=fx_rate]').value,'25500');
 assert.deepEqual(Array.from(root.querySelectorAll('[name=variant]:checked')).map(x=>x.value),['34']);
 assert(root.querySelector('[data-group-table]').textContent.includes('PRICE PER PERSON'));
 assert(root.querySelector('.vta-price-advanced'));
 console.log('PASS five editable vehicle bands and preferred Hotel 3★ / Cruise 4★');
 const first=root.querySelector('[data-vehicle-band="2-2"]');
 first.querySelector('[name=vehicle]').value='Sedan / 7-seater';
 first.querySelector('[name=vehicle_reason]').value='Approved vehicle with guest luggage';
 first.querySelector('[name=transport_701]').value='44';
 for(const field of ['vehicle','vehicle_reason','transport_701'])
  first.querySelector('[name='+field+']').dispatchEvent(new w.Event('change',{bubbles:true}));
 root.querySelector('[name=B2B_AGENT]').value='1';
 root.querySelector('[data-config]').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 await new Promise(resolve=>setImmediate(resolve));
 const save=calls.find(x=>x.url.endsWith('/price-matrix/generate'));
 assert(save,'Price matrix generate POST required');
 assert.equal(save.body.method,'SAFE_PRICE');assert.equal(save.body.expected_revision,5);
 assert.deepEqual(JSON.parse(JSON.stringify(save.body.bands.map(b=>[b.min,b.max]))),[[2,2],[3,4],[5,9],[10,14],[15,20]]);
 assert.equal(save.body.transport_rate_versions['701']['2-2'],44);
 assert.equal(save.body.vehicle_bands[0].reason,'Approved vehicle with guest luggage');
 assert.equal(save.body.fx_rate,'25500');assert.deepEqual(JSON.parse(JSON.stringify(save.body.variant_ids)),[34]);
 console.log('PASS submitted exact pax SAFE_PRICE, approved transfer price and FX snapshot');
 h.dom.window.close();h=await harness({generated:true});
 assert(h.root.querySelector('[data-group-table]').textContent.includes('Need Rate / Review'));
 assert(!h.root.querySelector('[data-group-table]').textContent.includes('USD 0.00'));
 console.log('PASS unresolved supplier rates display Need Rate, never a fake zero price');
 h.dom.window.close();h=await harness({permission:false});
 assert(!h.calls.some(c=>c.url.endsWith('/options')||c.url.endsWith('/transport-options')));
 assert(!h.root.querySelector('[data-vehicles]'));
 console.log('PASS finance permission prevents supplier vehicle-rate retrieval');
 h.dom.window.close();h=await harness({status:'SENT'});
 assert(!h.root.querySelector('[data-config]'));
 assert(!h.calls.some(c=>c.url.endsWith('/transport-options')));
 console.log('PASS SENT quote pricing cannot be regenerated or modify vehicle contracts');
 h.dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});

const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../tour-proposal-studio.js'),'utf8');
const program=()=>({id:8,title:'Hanoi – Halong 2D1N',destination:'Hanoi, Halong',language:'en',tags:[],status:'DRAFT',days:[{day:1,date:'',title:'Hanoi arrival',description:'Welcome to Hanoi',meals:'',overnight:'Hanoi',notes:''}],included_text:'Hotel',excluded_text:'Flights',terms_text:'Deposit 30%',source_type:'MANUAL',source_name:'',source_text:''});
const settle=()=>new Promise(r=>setImmediate(r));
async function run(){
 const dom=new JSDOM('<main id="workspace"></main>',{url:'https://localhost/',runScripts:'outside-only'});
 const w=dom.window,d=w.document,root=d.querySelector('#workspace'),calls=[],toasts=[];
 w.eval(source);w.confirm=()=>true;
 const api={request:async(route,opt)=>{calls.push({route,opt});return {ok:true,id:8,program:opt.body};}};
 w.VTATourProposalStudio.mount(root,{program:program(),api,toast:(...x)=>toasts.push(x),onSaved:async()=>{},onBack:async()=>{}});
 assert(root.querySelector('.vtps'));assert(root.querySelector('[data-private-row]'));assert.equal(root.querySelectorAll('[data-group-row]').length,3);
 // Edit content and persist it through the existing tour-library API.
 root.querySelector('[data-proposal="tour_code"]').value='VTA0602';
 root.querySelector('[data-proposal="overview"]').value='Northern Vietnam Journey';
 root.querySelector('[data-group-row="0"] [data-field="price"]').value='355';
 root.querySelector('[data-private-row="0"] [data-field="three"]').value='785';
 root.querySelector('[data-day-field="description"]').textContent='Edited day description';
 root.querySelector('[data-action="add-day"]').click();
 assert.equal(root.querySelectorAll('[data-day-index]').length,2);
 root.querySelector('[data-action="save"]').click();await settle();await settle();
 assert.equal(calls.length,1);
 assert.equal(calls[0].route,'tour-library/8');assert.equal(calls[0].opt.method,'PUT');
 assert.equal(calls[0].opt.body.proposal.tour_code,'VTA0602');
 assert.equal(calls[0].opt.body.proposal.group_prices[0].price,'355');
 assert.equal(calls[0].opt.body.proposal.private_prices[0].three,'785');
 assert.equal(calls[0].opt.body.days.length,2);
 assert.equal(calls[0].opt.body.days[0].description,'Edited day description');
 console.log('PASS studio render, group/private rates, add day and API save');
 // A view-only user cannot mutate data and can inspect existing program.
 w.VTATourProposalStudio.mount(root,{program:program(),api,canEdit:false,toast:(...x)=>toasts.push(x),onBack:async()=>{},onSaved:async()=>{}});
 assert.equal(root.querySelector('[data-action="save"]').hidden,true);
 assert.equal(root.querySelector('[data-meta="title"]').disabled,true);
 assert.equal(root.querySelector('[data-day-field="description"]').getAttribute('contenteditable'),'false');
 root.querySelector('[data-action="preview"]').click();await settle();
 assert.equal(calls.length,1);
 console.log('PASS view-only permissions and preview');
 w.close();
}
run().catch(e=>{console.error(e);process.exitCode=1;});

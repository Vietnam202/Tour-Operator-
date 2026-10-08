'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(path.join(__dirname,'../tour-proposal-studio.js'),'utf8');
const stylesheet=fs.readFileSync(path.join(__dirname,'../vta-ui-refresh.css'),'utf8');
const program=()=>({
 id:18,title:'Hanoi – Sapa – Halong 7D6N',destination:'Hanoi, Sapa, Halong',
 language:'en',tags:[],status:'DRAFT',source_type:'MANUAL',source_name:'',source_text:'',
 days:[{day:1,date:'',title:'Hanoi arrival',description:'Original Day 1',meals:'B',overnight:'Hanoi',notes:''}],
 included_text:'Hotel & meals',excluded_text:'Flights',terms_text:'Deposit 30%',
 proposal:{tour_code:'VTA-018',tour_type:'PRIVATE',overview:'Test overview',
  group_prices:[{hotel:'3',price:'',single:''},{hotel:'4',price:'',single:''},{hotel:'5',price:'',single:''}],
  private_prices:[{min:2,max:4,three:'225',four:'315',five:'425'}],
  hotels:[],policies:{children:'',payment:'',cancellation:'',notes:''}}
});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function main(){
 const dom=new JSDOM('<main id="host"></main>',{url:'https://vta.local/',runScripts:'outside-only'});
 const w=dom.window,host=w.document.querySelector('#host'),writes=[];
 w.eval(source);w.confirm=()=>true;
 const api={request:async(url,options)=>{writes.push({url,options});return{id:18};}};
 const mount=canEdit=>w.VTATourProposalStudio.mount(host,{
  program:program(),api,toast:()=>{},canEdit,onBack:async()=>{},onSaved:async()=>{}
 });
 mount(true);
 const picker=host.querySelector('[data-action="templates"]');
 const gallery=host.querySelector('#vtps-template-gallery');
 assert(picker&&gallery,'template gallery must be inside original V5 editor');
 assert.equal(gallery.hidden,true,'gallery closed by default');
 assert.equal(host.querySelectorAll('[data-vtps-template]').length,4);
 picker.click();
 assert.equal(gallery.hidden,false);
 assert.equal(picker.getAttribute('aria-expanded'),'true');
 const day=host.querySelector('[data-day-field="description"]');
 const input=host.querySelector('[data-proposal="overview"]');
 const rate=host.querySelector('[data-private-row] [data-field="four"]');
 day.textContent='Edited Day 1, unsaved';
 input.value='Edited overview, unsaved';
 rate.value='355';
 const sameInput=input;
 host.querySelector('[data-vtps-template="brochure"]').click();
 assert.strictEqual(host.querySelector('[data-proposal="overview"]'),sameInput,'layout changes must not remount or replace inputs');
 assert.equal(day.textContent,'Edited Day 1, unsaved');
 assert.equal(input.value,'Edited overview, unsaved');
 assert.equal(rate.value,'355');
 assert.equal(host.querySelector('.vtps').dataset.vtpsLayout,'brochure');
 assert.equal(host.querySelector('[data-vtps-template="brochure"]').getAttribute('aria-pressed'),'true');
 assert.equal(host.querySelector('[data-vtps-template="detailed"]').getAttribute('aria-pressed'),'false');
 assert.equal(writes.length,0,'template preview must not write to the server');
 assert(!host.querySelector('[data-action="save-template"]'),'no pretend custom template saving action');
 assert(!host.querySelector('[data-action="export-template"]'),'no pretend template export');
 host.querySelector('[data-action="preview"]').click();
 assert.equal(host.querySelector('.vtps').dataset.vtpsLayout,'brochure','template preview survives V5 re-render');
 assert.equal(host.querySelector('[data-day-field="description"]').textContent,'Edited Day 1, unsaved');
 host.querySelector('[data-action="preview"]').click();
 host.querySelector('[data-action="save"]').click();await tick();await tick();
 assert.equal(writes.length,1,'only original V5 save writes data');
 assert.equal(writes[0].options.body.days[0].description,'Edited Day 1, unsaved');
 assert.equal(writes[0].options.body.proposal.overview,'Edited overview, unsaved');
 assert.equal(writes[0].options.body.proposal.private_prices[0].four,'355');
 assert.equal(writes[0].options.body.proposal.presentation_template,undefined,'layout selection is session-only and not fake persistence');
 // Permissions: viewing template styles is permitted, editing V5 content remains restricted.
 mount(false);
 host.querySelector('[data-action="templates"]').click();
 host.querySelector('[data-vtps-template="quick"]').click();
 assert.equal(host.querySelector('.vtps').dataset.vtpsLayout,'quick');
 assert.equal(host.querySelector('[data-day-field="description"]').getAttribute('contenteditable'),'false');
 assert.equal(host.querySelector('[data-action="save"]').hidden,true);
 assert.equal(writes.length,1,'read-only viewer cannot write');
 // All four visuals are screen-only; original print and back-end cost logic unchanged.
 for(const id of ['detailed','brochure','quick','b2b']){
  assert(stylesheet.includes('vtps-layout="'+id+'"')||id==='detailed','visual layout CSS '+id);
 }
 assert(stylesheet.includes('.vtps .vtps-template-gallery[hidden]'),'gallery supports hidden state');
 assert(stylesheet.includes('@media screen'),'screen only');
 console.log('PASS: V5 four template previews, unsaved data/caret DOM preserved, permissions, no cost/API/export changes');
 dom.window.close();
}
main().catch(err=>{console.error(err);process.exitCode=1;});

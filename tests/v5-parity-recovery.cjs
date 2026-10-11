'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(require('node:path').join(__dirname,'../tour-proposal-studio.js'),'utf8');
const fixture=()=>({id:8,title:'Original',tags:[],status:'DRAFT',language:'vi',days:[{day:1,title:'Arrival',description:'Original text',meals:'B',overnight:'Hanoi'}],proposal:{private_prices:[{min:2,max:4,three:'100',four:'',five:''}],hotels:[{destination:'Hanoi',three:'Hotel A',four:'',five:''}]}});
const settle=()=>new Promise(r=>setImmediate(r));
function setup(options={}){
 const dom=new JSDOM('<main id="host"></main>',{url:'https://localhost/',runScripts:'outside-only'}),w=dom.window,host=w.document.querySelector('main'),calls=[],toasts=[];
 w.eval(source);w.confirm=()=>true;
 const mount=(extra={})=>w.VTATourProposalStudio.mount(host,{program:fixture(),canEdit:true,api:{request:async(route,opt)=>{calls.push({route,opt:JSON.parse(JSON.stringify(opt))});return options.request?options.request(route,opt):{id:19};}},toast:(...x)=>toasts.push(x),onSaved:async()=>{},onBack:async()=>{},...extra});
 mount();
 const click=action=>host.querySelector('[data-action="'+action+'"]').click();
 const input=(selector,value)=>{const e=host.querySelector(selector);e.value=value;e.dispatchEvent(new w.Event('input',{bubbles:true}));};
 const paste=text=>{click('paste');input('[data-vtps-paste-text]',text);click('paste-apply');};
 return {dom,w,host,calls,toasts,mount,click,input,paste};
}
const cases=[];
const test=(name,run)=>cases.push({name,run});
test('nonfinite, fractional, reversed and over-limit bands block both save paths',async()=>{
 const s=setup();try{for(const [min,max] of [['abc','4'],['Infinity','4'],['2.5','4'],['5','4'],['2','1001'],['','4']]){
 s.input('[data-field="min"]',min);s.input('[data-field="max"]',max);
 s.click('save-template');s.click('save');await settle();assert.equal(s.calls.length,0,`${min}..${max} must not call API`);
 assert.match(s.host.querySelector('[data-pricing-warnings]').textContent,/không hợp lệ/);
 }}finally{s.dom.window.close();}
});
test('overlap blocks original save and template clone before HTTP',async()=>{
 const s=setup();try{s.click('add-private');s.input('[data-private-row="1"] [data-field="min"]','4');s.input('[data-private-row="1"] [data-field="max"]','8');s.click('save');s.click('save-template');await settle();assert.equal(s.calls.length,0);}finally{s.dom.window.close();}
});
test('Vietnamese empty heading preserves body; leading whitespace and CRLF parse correctly',async()=>{
 const s=setup();try{s.paste('  Ngày 02:\r\nNội dung ngày hai\r\n  DAY 03 — Ha Long\r\nCruise body');
 assert.equal(s.host.querySelectorAll('[data-day-index]').length,3);
 assert.equal(s.host.querySelector('[data-day-index="1"] [data-day-field="title"]').textContent,'Tour Day');
 assert.equal(s.host.querySelector('[data-day-index="1"] [data-day-field="description"]').textContent,'Nội dung ngày hai');
 assert.equal(s.host.querySelector('[data-day-index="2"] [data-day-field="title"]').textContent,'Ha Long');
 s.paste('Day4: Compact heading\nBody');assert.equal(s.host.querySelector('[data-day-index="3"] [data-day-field="title"]').textContent,'Compact heading');
 }finally{s.dom.window.close();}
});
test('no-heading paste, 90-day rejection and cancel preserve unsaved data',async()=>{
 const s=setup();try{s.input('[data-proposal="overview"]','Existing');s.paste('Plain multiline\nsecond line');assert.equal(s.host.querySelector('[data-proposal="overview"]').value,'Existing\n\nPlain multiline\nsecond line');
 s.paste(Array.from({length:90},(_,i)=>`Day ${i+1}: Day\nBody`).join('\n'));assert.equal(s.host.querySelectorAll('[data-day-index]').length,1);assert(s.host.querySelector('[data-vtps-paste-text]'));
 s.click('paste-cancel');assert.equal(s.host.querySelector('[role="dialog"]'),null);assert.equal(s.host.querySelector('[data-day-field="description"]').textContent,'Original text');
 }finally{s.dom.window.close();}
});
test('overnight Summary and valid hotel removal stay in sync',async()=>{
 const s=setup();try{s.input('[data-day-field="overnight"]','Ha Long');assert.equal(s.host.querySelector('#vtps-summary tbody tr td:nth-child(4)').textContent,'Ha Long');
 assert.equal(s.host.querySelector('[data-remove-hotel]').dataset.removeHotel,'0');s.host.querySelector('[data-remove-hotel]').click();assert.equal(s.host.querySelectorAll('[data-hotel-row]').length,0);
 }finally{s.dom.window.close();}
});
test('lost template response reuses fresh creation key and blocks concurrent double-click',async()=>{
 let attempt=0;const s=setup({request:async()=>{if(++attempt===1)throw Error('Response lost');return {id:19};}});
 try{s.click('save-template');s.click('save-template');await settle();assert.equal(s.calls.length,1);s.click('save-template');await settle();assert.equal(s.calls.length,2);
 const [a,b]=s.calls.map(x=>x.opt.body);assert.match(a.creation_key,/^[A-Za-z0-9_-]{16,80}$/);assert.equal(a.creation_key,b.creation_key);assert.equal(a.id,undefined);assert.equal(a.import_token,undefined);assert.equal(a.status,'DRAFT');
 assert.equal(s.host.querySelector('[data-day-field="description"]').textContent,'Original text');
 }finally{s.dom.window.close();}
});
test('confirmed save followed by reopen failure remains operable and retries PUT',async()=>{
 const s=setup();try{s.mount({onSaved:async()=>{throw Error('View unavailable');}});s.click('save');await settle();await settle();s.click('save');await settle();await settle();assert.equal(s.calls.length,2);assert(s.calls.every(c=>c.route==='tour-library/19'||c.route==='tour-library/8'));assert.match(s.toasts.at(-1)[0],/Đã lưu/);
 }finally{s.dom.window.close();}
});
test('late save cannot close or overwrite a newly mounted editor',async()=>{
 let resolve;const s=setup({request:()=>new Promise(r=>resolve=r)});try{s.click('save');s.mount({program:{...fixture(),title:'New editor'}});resolve({id:19});await settle();await settle();assert(s.host.classList.contains('vtps-host'));assert.equal(s.host.querySelector('[data-meta="title"]').value,'New editor');assert.equal(s.toasts.length,0);}finally{s.dom.window.close();}
});
test('new program lost response retries the same POST creation key',async()=>{
 let attempt=0;const s=setup({request:async()=>{if(++attempt===1)throw Error('Response lost');return{id:19};}});
 try{s.mount({program:{...fixture(),id:undefined}});s.click('save');await settle();s.click('save');await settle();await settle();assert.equal(s.calls.length,2);assert(s.calls.every(c=>c.route==='tour-library'&&c.opt.method==='POST'));assert.match(s.calls[0].opt.body.creation_key,/^[A-Za-z0-9_-]{16,80}$/);assert.equal(s.calls[0].opt.body.creation_key,s.calls[1].opt.body.creation_key);}finally{s.dom.window.close();}
});
test('late template failure cannot notify a newly mounted editor',async()=>{
 let reject;const s=setup({request:()=>new Promise((_,r)=>reject=r)});
 try{s.click('save-template');s.mount({program:{...fixture(),title:'New editor'}});reject(Error('Old network failure'));await settle();assert.equal(s.toasts.length,0);assert.equal(s.host.querySelector('[data-meta="title"]').value,'New editor');}finally{s.dom.window.close();}
});
test('preview and read-only reject forged clicks without writes',async()=>{
 const s=setup();try{for(const readonly of [false,true]){s.mount({canEdit:!readonly});if(!readonly)s.click('preview');for(const action of ['save','save-template','paste','add-day']){const b=s.host.querySelector('[data-action="'+action+'"]');b.disabled=false;b.hidden=false;b.dispatchEvent(new s.w.MouseEvent('click',{bubbles:true}));}await settle();assert.equal(s.calls.length,0);assert.equal(s.host.querySelectorAll('[data-day-index]').length,1);assert.equal(s.host.querySelector('[role="dialog"]'),null);}}finally{s.dom.window.close();}
});
(async()=>{let failures=0;for(const c of cases){try{await c.run();console.log('PASS',c.name);}catch(e){failures++;console.error('FAIL',c.name,e.message);}}if(failures)process.exitCode=1;})();

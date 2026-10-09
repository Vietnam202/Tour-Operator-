'use strict';
const assert = require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const scripts=['marketing-model.js','marketing-demo.js','marketing-studio.js'];
const dom=new JSDOM('<main id="workspace" data-view="marketing"></main>',{url:'https://vta.example.invalid/preview.html',runScripts:'outside-only'});
const w=dom.window,root=w.document.querySelector('#workspace'),events=[],errors=[];
w.VTA_PREVIEW=true;
w.VTA_I18N={language:'vi'};
w.VTAPlusAssist={open:opts=>{events.push(opts);return true;}};
for(const name of scripts)w.eval(fs.readFileSync(path.join(__dirname,'..',name),'utf8'));
const escape=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
let dialog;
const options={
 api:{user:{company_id:1,id:1},request(){throw Error('Unexpected live API request')}},
 esc:escape,can:permission=>permission==='campaign.manage',
 toast:(msg,error)=>{if(error)errors.push(msg);},
 modal:(title,html)=>{
   dialog?.remove();dialog=w.document.createElement('div');dialog.className='modal-backdrop';dialog.innerHTML=html;w.document.body.appendChild(dialog);return dialog;
 },
 closeModal:()=>dialog?.remove(),
 navigate:async()=>{}
};
(async()=>{
 await w.VTAMarketing(options);
 const headline=root.querySelector('[data-mk="plus-marketing"]');
 assert(headline,'Marketing command center should expose Plus prompt to managers');
 headline.click();
 assert.equal(events.length,1);
 assert.equal(events[0].mode,'marketing');
 assert.equal(typeof events[0].onApply,'undefined','Header must never auto-write Marketing');
 const compose=root.querySelector('[data-mk="compose"]');
 assert(compose);
 compose.click();
 const open=dialog.querySelector('[data-plus-compose]');
 assert(open,'Composer should allow manual Plus handoff');
 const field=dialog.querySelector('textarea[name="body"]');
 const initial=field.value;
 open.click();
 assert.equal(events.length,2);
 assert.equal(events[1].mode,'marketing-base');
 assert.equal(typeof events[1].onApply,'function');
 assert.equal(field.value,initial,'Opening Plus must never overwrite draft');
 events[1].onApply('Verified VTA tour copy\n#vntraveladvisor');
 assert.equal(field.value,'Verified VTA tour copy\n#vntraveladvisor','Reviewed AI output can populate base copy');
 assert(!errors.length);
 assert.throws(()=>events[1].onApply('X'.repeat(12001)),/quá dài/);
 assert.equal(field.value,'Verified VTA tour copy\n#vntraveladvisor','Oversized output must not replace copy');
 dialog.remove();
 assert.throws(()=>events[1].onApply('Hidden content'),/Đã đóng/);
 // Re-mount with no edit permissions: neither header nor compose assistant may be exposed.
 await w.VTAMarketing({...options,can:()=>false});
 assert.equal(root.querySelector('[data-mk="plus-marketing"]'),null);
 assert.equal(root.querySelector('[data-mk="compose"]'),null);
 dom.window.close();
 console.log('PASS Marketing Plus: workspace button, composer apply, size limit, permissions and no automatic writes');
})().catch(e=>{console.error(e);process.exitCode=1;});

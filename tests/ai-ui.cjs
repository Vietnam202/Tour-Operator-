const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
const code=fs.readFileSync(path.join(__dirname,'../ai-chat.js'),'utf8');
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function setup({ready=true,preview=false}={}){
 const dom=new JSDOM('<main id="workspace" data-view="ai"></main>',{url:'https://localhost/',runScripts:'outside-only'}),w=dom.window;
 w.TextEncoder=TextEncoder;w.VTA_PREVIEW=preview;w.VTA_I18N={language:'vi'};w.eval(code);
 const root=w.document.querySelector('main'),calls=[],navigated=[],messages=[];
 const turns=[{id:1,prompt:'<img src=x onerror=bad()>',reply:'<script>bad()</script> Draft'}];
 let post=async()=>({ok:true,id:2});
 const api={user:{id:7},request:async(route,opt={})=>{calls.push({route,opt});if(opt.method==='POST')return post(route,opt);if(route==='ai/status')return {ready,daily_limit:30};if(route==='ai/threads')return {items:[{id:1,title:'<b>Private</b>',profile:'marketing'}]};if(route==='ai/campaigns')return {items:[{id:5,name:'India family'}]};return {thread:{id:1,title:'Private',profile:'marketing',context:{}},items:turns};}};
 let modalElement;
 const options={api,esc,can:p=>p==='campaign.manage',navigate:async v=>navigated.push(v),toast:x=>messages.push(x),closeModal:()=>modalElement?.remove(),modal:(title,body)=>{modalElement=w.document.createElement('div');modalElement.innerHTML='<button data-close>Close</button>'+body;w.document.body.append(modalElement);return modalElement;}};
 return {dom,w,root,calls,navigated,messages,options,render:()=>w.VTAChat(options),setPost:f=>post=f,get modal(){return modalElement;}};
}
const event={preventDefault(){}};
(async()=>{
 let h=setup();await h.render();assert(!h.root.querySelector('script, img, b'));assert(h.root.textContent.includes('<script>bad()</script>'));console.log('PASS user, assistant and title text escaped');
 h.root.querySelector('#aiMessage').value='Draft test';await h.root.querySelector('form').onsubmit(event);assert.equal(h.calls.filter(x=>x.opt.method==='POST').length,0);assert(!h.root.querySelector('#aiError').hidden);console.log('PASS consent required before AI request');
 h.root.querySelector('#aiConsent').checked=true;
 h.setPost(async()=>{throw Error('Synthetic timeout');});await h.root.querySelector('form').onsubmit(event);
 const first=h.calls.at(-1);assert.equal(first.route,'ai/threads/1/messages');assert.equal(h.root.querySelector('#aiMessage').value,'Draft test');assert.equal(h.root.querySelector('#aiSend').disabled,false);
 let resolvePost;h.setPost(()=>new Promise(r=>{resolvePost=r;}));const pending=h.root.querySelector('form').onsubmit(event);await Promise.resolve();const count=h.calls.length;await h.root.querySelector('form').onsubmit(event);assert.equal(h.calls.length,count);assert.equal(h.calls.at(-1).opt.body.request_key,first.opt.body.request_key);resolvePost({ok:true});await pending;console.log('PASS retry preserves text and key; duplicate submit blocked');
 h=setup();await h.render();h.root.querySelector('[data-draft-ai]').click();const form=h.modal.querySelector('form');form.elements.body.value='Reviewed content';await form.onsubmit({preventDefault(){},target:form});assert.equal(h.calls.at(-1).route,'marketing/content');assert.equal(h.calls.at(-1).opt.body.body,'Reviewed content');assert(!h.calls.some(x=>/decision|submit|publish|send/.test(x.route)));console.log('PASS marketing handoff creates editable draft without approval or delivery call');
 h=setup();await h.render();h.root.querySelector('#aiNew').click();const newForm=h.modal.querySelector('form');newForm.elements.title.value='Trip';let finishCreate;h.setPost(()=>new Promise(r=>{finishCreate=r;}));const newPromise=newForm.onsubmit({preventDefault(){},target:newForm});await Promise.resolve();assert(newForm.querySelector('button').disabled);assert(!h.modal.querySelector('[data-close]').disabled);finishCreate({id:2});await newPromise;console.log('PASS create disables submit and leaves close control usable');
 h=setup({ready:false});await h.render();assert(h.root.querySelector('#aiSend').disabled);console.log('PASS missing server configuration disables send');
 h=setup({preview:true});await h.render();assert.equal(h.calls.length,0);assert(h.root.textContent.includes('DEMO'));console.log('PASS preview explicitly labeled and makes no AI calls');
 h=setup();const oldRequest=h.options.api.request;let unblock;h.options.api.request=(r,o)=>r==='ai/status'?new Promise(resolve=>{unblock=()=>resolve({ready:true});}):oldRequest(r,o);const loading=h.render();h.root.dataset.view='modules';h.root.innerHTML='Modules';unblock();await loading;assert.equal(h.root.innerHTML,'Modules');console.log('PASS late response cannot replace another workspace');
 // Load real preview app to check integration and access to all modules on mobile.
 const dom=new JSDOM('<div id="app"></div>',{url:'https://localhost/',runScripts:'outside-only'}),w=dom.window;w.VTA_PREVIEW=true;w.VTA_VERSION='3.1.0-RC3';w.TextEncoder=TextEncoder;
 for(const file of ['ai-chat.js','platform.js','marketing.js','marketing-model.js','marketing-demo.js','marketing-studio.js','workspace-demo.js','workspace-centers.js','unified-workspace.js','app.js'])w.eval(fs.readFileSync(path.join(__dirname,'..',file),'utf8'));
 await new Promise(r=>setTimeout(r,30));assert.equal(w.document.querySelectorAll('.mobile-nav button').length,5);w.document.querySelector('.mobile-nav [data-nav="settings"]').click();await Promise.resolve();assert(w.document.querySelector('[data-uw-settings="0"]'));w.document.querySelector('[data-uw-settings="0"]').click();await Promise.resolve();assert(w.document.querySelector('[data-module="settings-admin"]'));assert(w.document.querySelector('[data-module="operations"]'));assert(w.document.querySelector('#installVta'));assert.equal(w.document.querySelector('[data-module="ai"]'),null);assert.equal(w.document.querySelector('.nav [data-nav="ai"]'),null);const activeWorkspace=w.document.querySelector('#workspace').innerHTML;w.document.querySelector('#askVtaAi').click();await Promise.resolve();assert(w.document.querySelector('#vtaAiRoot .ai-page'));assert.equal(w.document.querySelector('#workspace').innerHTML,activeWorkspace);assert(w.document.querySelector('[role="dialog"][aria-modal="true"]'));w.document.querySelector('#closeVtaAi').click();assert.equal(w.document.querySelector('#vtaAiOverlay'),null);console.log('PASS real preview shell exposes global AI drawer without an AI department or replacing the workspace');dom.window.close();
 console.log('NOTE: jsdom with simulated API. Real-device and live API tests remain required.');
})().catch(e=>{console.error(e);process.exitCode=1;});

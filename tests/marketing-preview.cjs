const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const dom=new JSDOM('<main id="workspace"></main>',{runScripts:'outside-only',url:'file:///D:/test/preview.html'}),w=dom.window,d=w.document;
w.VTA_PREVIEW=true;w.VTA_I18N={language:'vi'};let requests=0;
w.fetch=()=>{requests++;throw Error('Unexpected network request');};
w.eval(fs.readFileSync(path.join(__dirname,'../marketing.js'),'utf8'));
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
let m;const messages=[];
const options={api:{request(){requests++;throw Error('Unexpected backend request');}},esc,can:()=>true,toast:t=>messages.push(t),closeModal:()=>m?.remove(),modal:(title,body)=>{m=d.createElement('div');m.innerHTML=body;d.body.append(m);return m;},navigate:async v=>{if(v==='marketing')await w.VTAMarketing(options);}};
async function submit(){const form=m.querySelector('form');await form.onsubmit({preventDefault(){},target:form});}
(async()=>{
 await w.VTAMarketing(options);assert(d.querySelector('#workspace').textContent.includes('MARKETING PREVIEW 1'));assert.equal(d.querySelectorAll('[data-compose]').length,2);assert.equal(d.querySelectorAll('[data-copy]').length,3);console.log('PASS preview shows campaigns, content and clear demo label');
 d.querySelector('#marketingNew').click();m.querySelector('[name=name]').value='<img src=x onerror=bad()>';m.querySelector('[name=offer]').value='Private Vietnam draft';await submit();assert.equal(d.querySelectorAll('[data-compose]').length,3);assert(!d.querySelector('#workspace img'));console.log('PASS create campaign stays in memory and escapes user text');
 d.querySelector('[data-compose="3"]').click();assert.equal(m.querySelectorAll('option').length,7);m.querySelector('[name=channel]').value='TikTok';m.querySelector('[name=body]').value='Reviewed sample draft';await submit();assert(d.querySelector('[data-submit="4"]'));console.log('PASS seven channels and new draft creation');
 await d.querySelector('[data-submit="4"]').onclick();assert(d.querySelector('[data-approve="4"]'));assert(!d.querySelector('[data-submit="4"]'));await d.querySelector('[data-approve="4"]').onclick();assert(d.querySelector('[data-copy="4"]').closest('article').textContent.includes('APPROVED'));console.log('PASS simulated DRAFT to PENDING to APPROVED flow');
 d.querySelector('[data-revise="4"]').click();m.querySelector('[name=body]').value='Different revision';await submit();assert(d.querySelector('[data-submit="5"]'));assert(d.querySelector('[data-copy="4"]').closest('article').textContent.includes('Reviewed sample draft'));console.log('PASS revision preserves original approved content');
 await d.querySelector('[data-submit="5"]').onclick();await d.querySelector('[data-reject="5"]').onclick();assert(d.querySelector('[data-copy="5"]').closest('article').textContent.includes('REJECTED'));console.log('PASS simulated rejection');
 await w.VTAMarketing(options);assert.equal(d.querySelectorAll('[data-compose]').length,3);await d.querySelector('#marketingReset').onclick();assert.equal(d.querySelectorAll('[data-compose]').length,2);assert.equal(d.querySelectorAll('[data-copy]').length,3);console.log('PASS data survives navigation and reset restores samples');
 assert.equal(requests,0);assert.deepEqual(messages,[]);console.log('PASS complete demo flow makes no backend, AI or publishing calls');
 dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;dom.window.close();});

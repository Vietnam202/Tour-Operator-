const {chromium}=require('playwright');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
 const page=await browser.newPage();
 await page.setContent('<main id="workspace"></main>');
 await page.addScriptTag({path:path.join(__dirname,'../marketing.js')});
 await page.evaluate(()=>{
  window.calls=[];window.permissions=['campaign.manage','marketing.approve'];
  window.fixture={campaigns:[{id:1,name:'<img src=x onerror=alert(1)>',offer:'Vietnam family tour',market:'India'}],items:[{id:1,campaign_id:1,campaign_name:'Test',channel:'Facebook',body:'<script>alert(1)</script>',status:'DRAFT'},{id:2,campaign_id:1,campaign_name:'Test',channel:'TikTok',body:'Review me',status:'PENDING'},{id:3,campaign_id:1,campaign_name:'Test',channel:'Gmail',body:'Approved copy',status:'APPROVED'}]};
  window.VTA_I18N={language:'vi'};
  window.options={api:{request:async(route,opt)=>{calls.push({route,opt});return fixture;}},esc:v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])),modal:(title,html)=>{const e=document.createElement('section');e.id='modal';e.innerHTML=html;document.body.append(e);return e;},closeModal:()=>document.querySelector('#modal')?.remove(),toast:()=>{},navigate:async v=>{window.lastView=v;},can:p=>permissions.includes(p)};
 });
 await page.evaluate(()=>VTAMarketing(options));
 assert.equal(await page.locator('#workspace img, #workspace script').count(),0);console.log('PASS stored content escaped');
 assert.equal(await page.locator('[data-submit]').count(),1);assert.equal(await page.locator('[data-approve]').count(),1);console.log('PASS controls match draft/pending/approved states');
 await page.click('[data-submit="1"]');assert.equal(await page.evaluate(()=>calls.at(-1).route),'marketing/submit');
 await page.click('[data-approve="2"]');assert.deepEqual(await page.evaluate(()=>calls.at(-1).opt.body),{id:2,decision:'APPROVED'});console.log('PASS submit and approval request payloads');
 await page.click('[data-revise="3"]');assert.equal(await page.inputValue('textarea[name=body]'),'Approved copy');await page.fill('textarea[name=body]','New revision');await page.locator('#modal button').click();assert.equal(await page.evaluate(()=>calls.at(-1).route),'marketing/content');assert.equal(await page.evaluate(()=>fixture.items[2].body),'Approved copy');console.log('PASS revision creates new content and preserves original');
 await page.evaluate(()=>{permissions=[];return VTAMarketing(options);});assert.equal(await page.locator('[data-compose], [data-submit], [data-approve], [data-reject], #marketingNew').count(),0);console.log('PASS read-only UI hides write actions (server authorization separately required)');
 await page.click('#marketingLeads');assert.equal(await page.evaluate(()=>lastView),'leads');console.log('PASS shared Lead Hub navigation');
 await page.evaluate(()=>{window.VTA_PREVIEW=true;calls=[];return VTAMarketing(options);});assert.equal(await page.evaluate(()=>calls.length),0);console.log('PASS preview makes no API requests');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

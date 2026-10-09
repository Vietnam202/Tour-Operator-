const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),out=process.env.VTA_MARKETING_SCREENSHOTS;
const tabs=['overview','content','calendar','publishing','connections','library','webhooks','campaigns','inbox','insights','channels'];
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.png':'image/png'};
const server=http.createServer((req,res)=>{
 const file=path.resolve(base,'.'+new URL(req.url,'http://localhost').pathname);
 if(!file.startsWith(base+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}
 res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');res.end(fs.readFileSync(file));
});
let browser;
async function assertLayout(page,label){
 const size=await page.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth,studio:document.querySelector('.mk-studio').getBoundingClientRect().right,body:document.querySelector('#mkBody').getBoundingClientRect().right}));
 assert(size.document<=size.width+1,label+' page overflow '+JSON.stringify(size));
 assert(size.studio<=size.width+1&&size.body<=size.width+1,label+' workspace overflow');
 assert((await page.locator('#mkBody').innerText()).trim(),label+' blank body');
}
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
 browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const context=await browser.newContext(),errors=[],external=[];
 await context.addInitScript(()=>localStorage.setItem('vta.language','vi'));
 await context.route('**/*',route=>{if(route.request().url().startsWith(origin+'/'))return route.continue();external.push(route.request().url());return route.abort();});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(origin+'/preview.html');
 await page.waitForSelector('[data-nav="marketing"], [data-module="marketing"]');
 await page.locator('[data-nav="marketing"], [data-module="marketing"]').first().evaluate(b=>b.click());
 await page.waitForSelector('.mk-studio');
 assert.equal(await page.locator('.mk-hero h1').innerText(),'Trung tâm Marketing');
 for(const width of [1440,820,390,360]){
  await page.setViewportSize({width,height:width>820?1000:844});
  for(const tab of tabs){
   await page.locator('.mk-tabs [data-id="'+tab+'"]').click();
   await page.waitForFunction(id=>document.querySelector('.mk-tabs [aria-current="page"]').dataset.id===id,tab);
   await assertLayout(page,'preview '+width+' '+tab);
  }
  if(out&&(width===1440||width===390)){
   fs.mkdirSync(out,{recursive:true});await page.locator('.mk-tabs [data-id="overview"]').click();
   await page.screenshot({path:path.join(out,'marketing-'+width+'.png'),fullPage:true});
  }
 }
 console.log('PASS 44 preview tab layouts at 1440, 820, 390 and 360px; no page overflow or blank tabs');

 // Render real-mode account, form and queue surfaces with read-only fixtures.
 await page.evaluate(async()=>{
  const fixture=await window.VTAMarketingDemo.request('marketing/studio');window.VTA_PREVIEW=false;window.marketingTestRequests=[];
  const api={user:{company_id:10,id:9},request:async(route,opt)=>{
   window.marketingTestRequests.push([route,opt?.method||'GET']);
   if(route==='marketing/studio')return fixture;
   if(route==='marketing/social-publishing')return {accounts:[{alias:'vta_fb',name:'Facebook Page · VTA',provider:'FACEBOOK_PAGE',ready:true}],jobs:[{id:1,content_id:3,provider:'FACEBOOK_PAGE',account_alias:'vta_fb',scheduled_at:'2026-12-31 03:00:00',status:'APPROVED',created_by:2}]};
   if(route==='marketing/meta-connections')return {accounts:[{alias:'vta_fb',product:'FACEBOOK_PAGE',entity_id:'123',recently_verified:true,enabled:true,health_status:'VERIFIED',required_scopes:['pages_manage_posts']}]};
   if(route==='marketing/social-inbox')return {items:[]};
   if(route==='marketing/meta-replies/accounts')return {accounts:[]};
   if(route==='marketing/webhooks')return {sources:[],events:[],total_events:0};
   throw Error('Unexpected route '+route);
  }};
  await window.VTAMarketing({api,can:()=>true,esc:x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])),toast:()=>{},modal:()=>{},closeModal:()=>{},navigate:()=>{}});
 });
 for(const width of [1440,390,360]){
  await page.setViewportSize({width,height:1000});
  for(const tab of ['publishing','connections','inbox','webhooks']){
   await page.locator('.mk-tabs [data-id="'+tab+'"]').click();
   await page.waitForFunction(()=>!document.querySelector('#mkBody [role="status"]'));
   await assertLayout(page,'real fixture '+width+' '+tab);
  }
 }
 assert((await page.evaluate(()=>window.marketingTestRequests)).every(x=>x[1]==='GET'));
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);
 console.log('PASS 12 real-mode fixture layouts, read-only status requests, no JavaScript errors or external calls');
 console.log('NOTE: local Chromium and synthetic accounts; no live provider, staging or CyberPanel verification.');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{await browser?.close();server.close();});

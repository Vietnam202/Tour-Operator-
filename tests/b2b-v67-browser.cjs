'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),path=require('node:path'),http=require('node:http'),os=require('node:os');
const {chromium}=require('playwright');
const html=fs.readFileSync(path.resolve(__dirname,'../b2b-partner-edition.html'));
const example={ok:true,schema:'VTA_B2B_PARTNER_V67_PUBLIC_PREVIEW',
 tour:{id:17,publication_version:4,content:{title:'Approved Vietnam Journey 7D6N',destination:'Da Nang → Hoi An → Hanoi → Phu Quoc',tour_code:'PARTNER-77',tour_type:'SIC',
 overview:'Owner-approved 7 day journey',days:Array.from({length:7},(_,i)=>({title:'Day plan '+(i+1),description:'Approved day description '+(i+1),meals:'B/L',overnight:'City'})),
 hotels:[{destination:'Da Nang Hotel',three:'Approved 3 star property',four:'Approved 4 star property',five:'Approved 5 star property'}],
 included_text:'Airport transfer\nApproved sightseeing',excluded_text:'Flights\nTips',terms_text:'Subject to confirmation',policies:{payment:'To reconfirm'}}},
 agency:{id:9,name:'Authorized Partner',brand_name:'Authorized Partner Ltd',email:'reservations@example.invalid',whatsapp:'+000000000',has_logo:false}};
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://127.0.0.1');
 if(u.pathname==='/b2b-partner-edition.html'){res.writeHead(200,{'content-type':'text/html; charset=utf-8'});return res.end(html);}
 if(u.pathname==='/api/index.php'){
   const route=u.searchParams.get('route')||'';
   if(route==='b2b/my-tours/17/v67-preview'){res.writeHead(200,{'content-type':'application/json'});return res.end(JSON.stringify(example));}
   res.writeHead(403,{'content-type':'application/json'});return res.end(JSON.stringify({ok:false,error:'FORBIDDEN'}));
 }
 res.writeHead(404);res.end('not found');
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const port=server.address().port, browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  for(const width of [1366,390]){
   const page=await browser.newPage({viewport:{width,height:850},acceptDownloads:true});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto('http://127.0.0.1:'+port+'/b2b-partner-edition.html?tour_id=17');
   await page.locator('.partner-editor-grid').waitFor({state:'visible',timeout:15000});
   assert.match(await page.locator('[data-v67=title]').innerText(),/Approved Vietnam Journey/);
   assert.equal(await page.locator('[data-v67=days] .vta-b2b-day').count(),7);
   assert.match(await page.locator('[data-v67=hotels]').innerText(),/Approved 4 star property/);
   assert.equal(await page.locator('#in-agency').inputValue(),'Authorized Partner Ltd');
   await page.locator('#in-price3').fill('775');await page.locator('#in-price4').fill('935');
   await page.locator('#in-price5').fill('1295');
   assert.match(await page.locator('[data-price-tier="3"]').innerText(),/775 USD/);
   assert.equal(await page.locator('#partner-print-btn').isEnabled(),true);
   await page.evaluate(()=>{window.print=()=>{window.__printCalled=true;};});
   await page.locator('#partner-print-btn').click();
   assert.equal(await page.evaluate(()=>window.__printCalled),true,'PDF control did not invoke print');
   const [download]=await Promise.all([page.waitForEvent('download'),page.locator('#partner-save-btn').click()]);
   const target=path.join(os.tmpdir(),'v67-ci-'+width+'-'+Date.now()+'.html');await download.saveAs(target);
   const saved=fs.readFileSync(target,'utf8');
   assert(!saved.includes('id="vta67-loader"'),'saved editable HTML should open offline');
   assert(saved.includes('775'),'saved selling price should persist');
   assert(!saved.includes('VTA707'),'demo must not reappear');
   const offline=await browser.newPage({viewport:{width,height:800}});
   await offline.goto('file://'+target);
   assert.equal(await offline.locator('#in-agency').inputValue(),'Authorized Partner Ltd');
   assert.equal(await offline.locator('#in-price3').inputValue(),'775');
   await offline.close();fs.unlinkSync(target);
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+5);
   assert(!overflow,'horizontal viewport overflow at '+width);
   assert.deepEqual(errors,[],'runtime JS errors at '+width);
   await page.close();
   console.log('PASS V6.7 '+width+' authenticated mock, edit price, print, Save HTML + offline reopen, no overflow');
  }
  const deny=await browser.newPage();
  await deny.goto('http://127.0.0.1:'+port+'/b2b-partner-edition.html?tour_id=999');
  await deny.locator('.partner-help[role=alert]').waitFor();
  assert.equal(await deny.locator('.partner-editor-grid').evaluate(el=>getComputedStyle(el).visibility),'hidden');
  assert(!await deny.locator('body').innerText().then(x=>x.includes('Approved Vietnam Journey')));
  await deny.close();
  console.log('PASS blocked tour refuses to render any tour program');
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;server.close();});

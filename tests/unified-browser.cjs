'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),out=process.env.VTA_UNIFIED_SCREENSHOTS;
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml'};
const server=http.createServer((req,res)=>{const file=path.resolve(base,'.'+new URL(req.url,'http://localhost').pathname),ext=path.extname(file);if(!file.startsWith(base+path.sep)||!mime[ext]||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',mime[ext]);res.end(fs.readFileSync(file));});
let browser;
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
 browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 const context=await browser.newContext(),page=await context.newPage(),errors=[],external=[],apiCalls=[];
 await context.addInitScript(()=>localStorage.setItem('vta.language','vi'));
 await context.route('**/*',route=>{const url=route.request().url();if(url.startsWith(origin+'/')){if(url.includes('/api/'))apiCalls.push(url);return route.continue();}external.push(url);return route.abort();});
 page.on('pageerror',error=>errors.push(error.message));
 const loaded=async(view,selector)=>{await page.waitForFunction(v=>document.querySelector('#workspace')?.dataset.view===v,view);if(selector){try{await page.waitForSelector(selector,{timeout:10000});}catch(error){console.error('Workspace '+view+': '+await page.locator('#workspace').innerText());console.error('Page errors:',errors);throw error;}}assert(!await page.locator('#workspaceRetry').count(),'Workspace load failed: '+await page.locator('#workspace').innerText());};
 const layout=async label=>{const size=await page.evaluate(()=>({viewport:innerWidth,body:document.documentElement.scrollWidth,workspace:document.querySelector('#workspace').getBoundingClientRect().right}));assert(size.body<=size.viewport+1,label+' document overflow '+JSON.stringify(size));assert(size.workspace<=size.viewport+1,label+' workspace overflow');};
 const nav=async id=>{if(await page.locator('#workspaceMenu').isVisible()&&!await page.locator('.sidebar').isVisible())await page.locator('#workspaceMenu').click();await page.locator('.sidebar [data-nav="'+id+'"]').click();};
 const shot=async name=>{if(out){fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,name+'.jpg'),type:'jpeg',quality:78,fullPage:false});}};
 await page.goto(origin+'/preview.html');await loaded('home','.wsc-center');
 let layouts=0;
 for(const width of [1440,1024,820,390,360]){
  await page.setViewportSize({width,height:width>820?950:844});
  for(const [id,selector] of [['home','.wsc-center'],['sales','[data-uw-tab="partners"]'],['tours','[data-uw-search]'],['product','[data-tl-grid]'],['suppliers','[data-product-tab]'],['marketing','.mk-studio'],['reports','#reportPeriod'],['settings','[data-uw-settings]']]){await nav(id);await loaded(id,selector);await layout(width+' '+id);layouts++;}
  await nav('sales');await loaded('sales','[data-uw-tab="partners"]');await page.locator('[data-uw-tab="partners"]').click();await loaded('b2b','[data-uw-new-agency]');await layout(width+' B2B partners');layouts++;
  if(width===1440||width===390)await shot('unified-b2b-'+width);
  await page.locator('[data-uw-tab="rates"]').click();await page.waitForFunction(()=>document.querySelector('#workspace').textContent.includes('Bảng NET & phiên bản phát hành'));await layout(width+' B2B NET');layouts++;
  await nav('tours');await loaded('tours','[data-uw-open="quote:1"]');await page.locator('[data-uw-open="quote:1"]').click();await loaded('quote','[data-uw-tab="cost"]');await layout(width+' tour program');layouts++;
  assert.equal(await page.locator('[data-uw-tab]').count(),6,'Six shared tour workflow tabs');
  if(width===1440||width===390)await shot('unified-tour-'+width);
  await page.locator('[data-uw-tab="cost"]').click();await loaded('quote','#quoteBody');await layout(width+' tour cost');layouts++;
  await page.locator('[data-uw-tab="proposal"]').click();await loaded('quote','#quoteBody');await layout(width+' tour quotation');layouts++;
  await page.locator('[data-uw-tab="profile"]').click();await page.waitForSelector('.uw-profile');await layout(width+' quote profile');layouts++;
  await page.locator('[data-uw-tab="booking"]').click();await loaded('booking','#bookingBody');await layout(width+' linked booking');layouts++;
  if(width===1440||width===390)await shot('unified-booking-'+width);
  await page.locator('[data-uw-tab="operations"]').click();await loaded('booking','#bookingBody');await layout(width+' linked operations');layouts++;
  await page.locator('[data-uw-tab="profile"]').click();await page.waitForSelector('#uploadBookingDoc');await layout(width+' booking documents');layouts++;
  await nav('sales');await loaded('sales','[data-uw-tab="inquiries"]');await page.locator('[data-uw-tab="inquiries"]').click();await loaded('sales-list','#salesBody');await layout(width+' inquiry');layouts++;
 }
 // Exercise the real editor engine in editable integrated mode, independently
 // from the readonly issued-quote preview fixture used above.
 await page.evaluate(async()=>{
  const root=document.querySelector('#workspace');root.dataset.view='quote';
  window.editorSaveRequests=[];
  const ctx={settings:{document:{schema:'VTA_DOC_2',title:'Editable draft fixture',content:{type:'doc',content:[{type:'paragraph'}]}}},days:[],links:[],selling_options:[],immutable:false,costing_revision:7};
  await window.mountVisualProposal(root,{integrated:true,quoteData:{quote:{id:99},version:{id:99,version_status:'DRAFT',tour_name:'Fixture'}},api:{request:async(route,options)=>{if(options?.method==='PUT'){window.editorSaveRequests.push(options.body);ctx.settings=options.body.settings;}return JSON.parse(JSON.stringify(ctx));}},can:p=>['quote.edit','proposal.edit'].includes(p),toast:()=>{},onBack:()=>{}});
 });
 await page.locator('.tiptap').fill('Draft changes survive navigation');
 await page.evaluate(()=>window.__vtaDocumentBeforeLeave());
 assert((await page.evaluate(()=>window.editorSaveRequests)).some(request=>request.expected_revision===7&&JSON.stringify(request.settings.document.content).includes('Draft changes survive navigation')));
 assert(!await page.evaluate(()=>document.body.classList.contains('vta-doc-focus')),'Integrated editor retains application chrome');
 await layout('editable integrated Word editor');
 await page.evaluate(()=>window.__vtaDocumentDispose());
 assert.deepEqual(errors,[],'No JavaScript page errors');assert.deepEqual(external,[],'No external data requests');assert.deepEqual(apiCalls,[],'Preview never requests the live API');
 console.log('PASS '+layouts+' real Chromium layouts across 1440, 1024, 820, 390 and 360px, eight menu modules, B2B, tour editor/cost/quote/booking/operations/files and inquiry');
 console.log('PASS mobile drawer makes every module reachable; no page overflow, blank modules, live API or external calls');
 console.log('PASS real Word engine saves an editable draft through the before-leave guard without hiding the unified navigation');
 console.log('NOTE: sample preview data; live PHP/MariaDB and provider acceptance remain separate.');
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{await browser?.close();server.close();});

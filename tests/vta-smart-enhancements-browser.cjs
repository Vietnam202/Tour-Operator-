'use strict';
/** Playwright smoke of the shipped RC6.2 P1 preview at desktop and mobile.
 * No real backend, provider requests or publication may occur.
 */
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..'),out=process.env.VTA_SMART_SCREENSHOTS;
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml'};
const server=http.createServer((req,res)=>{
 const file=path.resolve(base,'.'+new URL(req.url,'http://localhost').pathname),ext=path.extname(file);
 if(!file.startsWith(base+path.sep)||!mime[ext]||!fs.existsSync(file)||!fs.statSync(file).isFile()){
  res.writeHead(404);res.end();return;
 }
 res.setHeader('Content-Type',mime[ext]);res.end(fs.readFileSync(file));
});
let browser;
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const origin='http://127.0.0.1:'+server.address().port;
 browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 const context=await browser.newContext(),page=await context.newPage(),errors=[],external=[],api=[];
 await context.addInitScript(()=>localStorage.setItem('vta.language','vi'));
 await context.route('**/*',route=>{
  const url=route.request().url();
  if(url.startsWith(origin+'/')){if(url.includes('/api/'))api.push(url);return route.continue();}
  external.push(url);return route.abort();
 });
 page.on('pageerror',error=>errors.push(error.message));
 await page.goto(origin+'/preview.html');
 await page.waitForSelector('#workspace[data-view="home"]');
 assert(await page.locator('#vtaQuickSend').count(),'Quick Send mounts on actual preview shell');
 // Search via the original topbar, never through a second dashboard.
 await page.locator('#globalSearch').fill('Hanoi');
 await page.waitForSelector('#searchResults.open [role="option"]',{timeout:10000});
 assert((await page.locator('#searchResults [role="option"]').count())>0,'Approved local search results appear');
 await page.keyboard.press('ArrowDown');
 assert(await page.locator('#globalSearch').getAttribute('aria-activedescendant'),'Keyboard arrow selection works');
 await page.keyboard.press('Escape');
 assert(!(await page.locator('#searchResults').evaluate(el=>el.classList.contains('open'))),'Escape closes results');
 await page.locator('#notifyBtn').click();
 await page.waitForSelector('.modal-backdrop');
 assert((await page.locator('.modal-backdrop').innerText()).includes('Smart Alerts'));
 await page.locator('.modal-backdrop [data-close]').first().click();
 await page.locator('#vtaQuickSend').click();
 await page.waitForSelector('#vtaSmartGenerate');
 assert(await page.locator('#vtaSmartGenerate').isDisabled(),'Preview cannot publish public proposal links');
 await page.locator('.modal-backdrop [data-close]').first().click();
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:width===1440?900:844});
  await page.waitForTimeout(80);
  const layout=await page.evaluate(()=>({viewport:innerWidth,document:document.documentElement.scrollWidth,header:document.querySelector('.topbar')?.getBoundingClientRect().right}));
  assert(layout.document<=layout.viewport+1,'Document overflows at '+width+': '+JSON.stringify(layout));
  assert(layout.header<=layout.viewport+1,'Toolbar overflows at '+width+': '+JSON.stringify(layout));
  if(out){fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,'smart-workflow-'+width+'.png'),fullPage:false});}
 }
 assert.deepEqual(errors,[],'No page JavaScript exceptions');
 assert.deepEqual(api,[],'Preview must not call live PHP API');
 assert.deepEqual(external,[],'Preview must not contact a provider');
 console.log('PASS Smart P1 Chromium topbar search, alerts, preview publication guard, PC/mobile layout and offline-only routing');
})().catch(err=>{console.error(err);process.exitCode=1;}).finally(async()=>{await browser?.close();server.close();});

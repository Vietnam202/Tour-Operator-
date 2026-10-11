'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'..'),types={'.html':'text/html','.js':'text/javascript','.css':'text/css','.json':'application/json','.svg':'image/svg+xml','.png':'image/png'};
const server=http.createServer((req,res)=>{const file=path.resolve(root,'.'+new URL(req.url,'http://localhost').pathname);if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);return res.end();}res.setHeader('Content-Type',types[path.extname(file)]||'application/octet-stream');res.end(fs.readFileSync(file));});
let browser;
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
 browser=await chromium.launch({executablePath:process.env.CHROME_PATH,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const page=await browser.newPage(),errors=[],external=[],api=[];
 page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',r=>{const u=r.request().url();if(!u.startsWith(origin+'/')){external.push(u);return r.abort();}if(u.includes('/api/'))api.push(u);return r.continue();});
 await page.goto(origin+'/preview.html');await page.waitForSelector('.wsc-center');assert.equal(await page.locator('[data-nav="marketing"]').count(),0);
 await page.locator('.sidebar [data-nav="product"]').click();await page.waitForSelector('[data-tl-grid]');await page.locator('.tl-head-more summary').click();await page.locator('[data-tl-b2c]').click();await page.waitForSelector('[data-create]');
 await page.locator('[data-create]').click();await page.locator('form select[name="master"]').waitFor();await page.locator('form button[type="submit"]').click();await page.waitForSelector('[data-actions]');
 for(const action of ['review','approve','publish']){await page.locator('[data-actions]').first().click();if(action==='approve')await page.locator('[data-reviewed]').check();await page.locator('[data-action="'+action+'"]').click();await page.waitForFunction(status=>document.querySelector('#workspace').textContent.includes(status),({review:'REVIEW',approve:'APPROVED',publish:'PUBLISHED'})[action]);}
 assert((await page.locator('#workspace').innerText()).includes('PUBLISHED'));
 for(const width of [1440,820,390,360]){await page.setViewportSize({width,height:900});const size=await page.evaluate(()=>({w:innerWidth,doc:document.documentElement.scrollWidth}));assert(size.doc<=size.w+1,'B2C overflow '+JSON.stringify(size));}
 await page.locator('[data-edit]').first().click();await page.waitForSelector('.tiptap');await page.locator('.tiptap').fill('Independent B2C browser edit');await page.evaluate(()=>window.__vtaDocumentBeforeLeave());await page.evaluate(()=>window.__vtaDocumentDispose());
 assert.deepEqual(errors,[]);assert.deepEqual(external,[]);assert.deepEqual(api,[]);console.log('PASS Chromium B2C navigation, copy, review/approve/publish, four screen widths, Word editing and preview network isolation');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{await browser?.close();server.close();});

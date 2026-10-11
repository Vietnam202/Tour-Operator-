'use strict';
// Real Chromium interaction against a synthetic local UI fixture, not staging auth UAT.
const assert=require('node:assert/strict'),http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=path.resolve(__dirname,'..');
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/tour-proposal-studio.css"></head><body><main id="host"></main><script src="/tour-proposal-studio.js"></script></body></html>');}
 if(!['/tour-proposal-studio.js','/tour-proposal-studio.css'].includes(url.pathname)){res.writeHead(404);return res.end();}
 res.setHeader('Content-Type',url.pathname.endsWith('.js')?'text/javascript; charset=utf-8':'text/css; charset=utf-8');res.end(fs.readFileSync(path.join(base,url.pathname)));
});
let browser;
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH,args:['--no-sandbox','--disable-dev-shm-usage']});
 for(const width of [1440,390]){
 const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:'+server.address().port);
 await page.evaluate(()=>{
 window.calls=[];window.toasts=[];window.attempt=0;
 window.VTATourProposalStudio.mount(document.querySelector('#host'),{
 program:{id:8,title:'VTA browser fixture',tags:[],status:'DRAFT',days:[{day:1,title:'Arrival',description:'Original',meals:'B',overnight:'Hanoi'}],proposal:{private_prices:[{min:2,max:4,three:'100',four:'',five:''}],hotels:[{destination:'Hanoi',three:'A',four:'',five:''}]}},
 api:{request:async(route,opt)=>{window.calls.push(JSON.parse(JSON.stringify({route,opt})));if(++window.attempt===1)throw Error('Synthetic lost response');return{id:19};}},
 toast:msg=>window.toasts.push(msg),onBack:async()=>{},onSaved:async()=>{}
 });
 });
 await page.locator('[data-action=paste]').click();
 await page.locator('[data-vtps-paste-text]').fill('  Ngày 2:\nBody preserved\nDay 3 — Ha Long\nCruise');
 await page.locator('[data-action=paste-apply]').click();
 assert.equal(await page.locator('[data-day-index]').count(),3);
 assert.equal(await page.locator('[data-day-index="1"] [data-day-field=description]').innerText(),'Body preserved');
 await page.locator('[data-day-index="1"] [data-day-field=overnight]').fill('Ha Long');
 assert.equal(await page.locator('#vtps-summary tbody tr').nth(1).locator('td').nth(3).innerText(),'Ha Long');
 await page.locator('[data-field=min]').fill('abc');
 await page.locator('[data-action=save-template]').click();assert.equal(await page.evaluate(()=>calls.length),0);
 await page.locator('[data-field=min]').fill('2');
 await page.locator('[data-action=save-template]').click();await page.waitForFunction(()=>toasts.includes('Synthetic lost response'));
 await page.locator('[data-action=save-template]').click();await page.waitForFunction(()=>calls.length===2&&toasts.some(x=>x.includes('Đã lưu bản mẫu')));
 assert.equal(await page.evaluate(()=>calls[0].opt.body.creation_key===calls[1].opt.body.creation_key),true);
 await page.locator('[data-remove-hotel]').click();assert.equal(await page.locator('[data-hotel-row]').count(),0);
 await page.locator('[data-action=templates]').click();await page.locator('[data-vtps-template=b2b]').click();assert.equal(await page.evaluate(()=>calls.length),2);
 await page.locator('[data-action=preview]').click();assert.equal(await page.locator('[data-action=paste]').isVisible(),false);assert.equal(await page.locator('[data-action=save-template]').isVisible(),false);
 assert.deepEqual(errors,[]);await page.close();console.log('PASS Chromium V5 parser/Summary/validation/retry/hotel/gallery/preview at '+width+'px (synthetic fixture)');
 }
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{await browser?.close();server.close();});

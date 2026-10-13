'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright');
const origin='http://127.0.0.1:18870';
assert.equal(process.env.VTA_FRESH_HTTP_URL,origin,'Disposable localhost only');
const password=process.env.VTA_FRESH_PASSWORD;assert(password&&password.length>=12);
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  for(const role of ['ADMIN','SALES','PRODUCT','OPERATIONS','FINANCE','MARKETING','PARTNER']){
   const ctx=await browser.newContext({viewport:{width:1440,height:900}}),page=await ctx.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.goto(origin+'/index.html');
   await page.locator('#loginForm [name=email]').fill(role.toLowerCase()+'@fixture.invalid');
   await page.locator('#loginForm [name=password]').fill(password);
   await page.locator('#loginForm button').click();await page.locator('.app-shell').waitFor();
   const me=await (await page.request.get(origin+'/api/index.php?route=auth/me')).json();
   assert.equal(me.user.role_code,role);const perms=me.user.permissions;
   assert.equal(await page.locator('.sidebar [data-nav=marketing]').count(),Number(perms.includes('lead.view')));
   for(const [route,permissions] of [['suppliers',['supplier.view']],['lead-hub/requests',['lead.view']],['tour-library',['tour_library.view','product.view','supplier.view']]]){
    const response=await page.request.get(origin+'/api/index.php?route='+encodeURIComponent(route));
    assert.equal(response.status(),permissions.some(p=>perms.includes(p))?200:403,role+' '+route);
   }
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:844});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),role+' horizontal overflow');
    if(process.env.VTA_FRESH_SCREENSHOTS){fs.mkdirSync(process.env.VTA_FRESH_SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.VTA_FRESH_SCREENSHOTS,'fresh-'+role+'-'+width+'.png')});}
   }
   assert.deepEqual(errors,[],role+' JS errors');await ctx.close();
   console.log('PASS fresh '+role+' real login, menu ACL, protected API access and desktop/mobile layout');
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});

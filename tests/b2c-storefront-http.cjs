'use strict';
const assert=require('node:assert/strict'),{chromium}=require('playwright');
const origin='http://127.0.0.1:18869';
if(process.env.VTA_V5_HTTP_URL!==origin||!process.env.VTA_V5_TEST_PASSWORD)throw Error('Disposable HTTP fixture required');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const context=await browser.newContext(),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.goto(origin+'/journeys.php?utm_source=native-browser&utm_campaign=fixture');
  const link=page.locator('.journey-card h2 a').first();assert(await link.count());const detail=await link.getAttribute('href');
  assert(new URL(detail,origin).searchParams.get('utm_source')==='native-browser','catalogue link preserves campaign attribution');
  await link.click();
  await page.locator('form[data-vta-b2c-inquiry]').waitFor();assert.equal(await page.locator('link[rel=canonical]').count(),1);
  for(const width of [1440,820,390,360]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'native page overflow '+width);}
  const image=await page.locator('.itinerary img').first().getAttribute('src');assert.equal((await context.request.get(origin+'/'+image)).status(),200);
  const data=await page.locator('form').evaluate(f=>({api:f.dataset.api,csrf:f.dataset.csrf}));
  const payload={consent:true,request_id:'native-http-fixture-0001',contact_name:'HTTP Visitor',email:'http@example.invalid',phone:'+84000000000',total_guests:2,travel_date:'2026-11-01',travel_date_end:'2026-11-08',message:'HTTP request',attribution:{utm_source:'native-http',landing_url:origin+'/'+detail}};
  const call=(body,token=data.csrf)=>context.request.post(origin+'/'+data.api,{data:body,headers:{'X-VTA-B2C-CSRF':token}});
  assert.equal((await call(payload,'wrong')).status(),403);
  assert.equal((await call({...payload,consent:false})).status(),422);
  assert.equal((await call(payload)).status(),200);assert.equal((await call(payload)).status(),200);
  assert.equal((await call({...payload,message:'different'})).status(),409);
  await page.locator('[name=contact_name]').fill('Browser Visitor');await page.locator('[name=email]').fill('browser@example.invalid');await page.locator('[name=phone]').fill('+84000000000');await page.locator('[name=travel_date]').fill('2026-11-01');await page.locator('[name=travel_date_end]').fill('2026-11-08');await page.locator('[name=message]').fill('Browser inquiry');await page.locator('[name=consent]').check();await page.locator('button[type=submit]').click();await page.waitForFunction(()=>document.querySelector('[role=status]').textContent.startsWith('Request accepted.'));
  const auth=await browser.newContext();const api=origin+'/api/index.php?route=';
  const login=await auth.request.post(api+'auth/login',{data:{email:'tester@example.invalid',password:process.env.VTA_V5_TEST_PASSWORD}});assert.equal(login.status(),200);const admin=(await login.json()).user;
  const leads=(await (await auth.request.get(api+'lead-hub/requests')).json()).items;
  assert.equal(leads.filter(x=>x.email==='http@example.invalid').length,1,'HTTP retries create one lead');const lead=leads.find(x=>x.email==='browser@example.invalid');assert(lead);assert.equal(lead.travel_date,'2026-11-01');assert.equal(JSON.parse(lead.attribution_json).utm_source,'native-browser');assert(lead.message.includes('2026-11-08'));
  const programs=(await (await auth.request.get(api+'b2c')).json()).items;const program=programs.find(p=>p.status==='PUBLISHED');assert(program);
  assert.equal((await auth.request.post(api+'b2c/'+program.id+'/withdraw',{data:{expected_revision:program.revision},headers:{'X-VTA-CSRF':admin.csrf}})).status(),200);
  assert.equal((await context.request.get(origin+'/'+detail)).status(),404);assert.equal((await context.request.get(origin+'/'+image)).status(),404);assert.equal((await call({...payload,request_id:'native-after-withdraw-0001'})).status(),404);
  assert.deepEqual(errors,[]);console.log('PASS native PHP browser/API/MariaDB: catalogue, four widths, CSRF, consent, idempotency, dates/UTM, Lead Hub, immediate withdrawal; fixture only');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

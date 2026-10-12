'use strict';
/**
 * REAL login cookies/CSRF and PHP+MariaDB B2B authorization, LOCAL FIXTURE ONLY.
 * All identities, company and master programs are synthetic. NOT staging UAT.
 */
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const origin='http://127.0.0.1:18869',password=process.env.VTA_V5_TEST_PASSWORD;
if(process.env.VTA_V5_HTTP_URL!==origin||!password||password.length<12)
  throw Error('Refusing B2B tests outside disposable local HTTP fixture');
const api=origin+'/api/index.php?route=';
function check(ok,message){assert(ok,message);console.log('PASS B2B REAL-AUTH '+message)}
async function makeSession(email){
 let cookie='',csrf='',who=null;
 async function request(route,method='GET',payload,token=csrf){
  const headers={'Accept':'application/json'};
  if(cookie)headers.Cookie=cookie;
  if(token)headers['X-VTA-CSRF']=token;
  if(payload!==undefined)headers['Content-Type']='application/json';
  const url=api+encodeURIComponent(route.split('?')[0])+(route.includes('?')?'&'+route.split('?').slice(1).join('?'):'');
  const result=await fetch(url,{method,headers,redirect:'error',signal:AbortSignal.timeout(12000),
    ...(payload===undefined?{}:{body:JSON.stringify(payload)})});
  const setCookie=result.headers.get('set-cookie');
  if(setCookie)cookie=setCookie.split(';')[0];
  const raw=await result.text();let data;
  try{data=JSON.parse(raw)}catch{data={raw}}
  return {status:result.status,data,headers:result.headers};
 }
 const login=await request('auth/login','POST',{email,password});
 assert.equal(login.status,200,'Login '+email+' '+JSON.stringify(login.data));
 assert(login.data.user?.csrf,'Session CSRF absent '+email);
 csrf=login.data.user.csrf;who=login.data.user;
 return {request,who,email,csrf};
}
async function expect(s,route,code,method='GET',body,csrf){
 const result=await s.request(route,method,body,csrf===undefined?s.csrf:csrf);
 assert.equal(result.status,code,s.email+' '+method+' '+route+' HTTP '+result.status+' '+JSON.stringify(result.data).slice(0,380));
 return result.data;
}
(async()=>{
 const anonymous=await fetch(api+'b2b/me');check(anonymous.status===401,'unauthenticated Partner Hub denies access');
 const [admin,a,b,foreign,viewer]=await Promise.all([
  makeSession('tester@example.invalid'),
  makeSession('partner-a@example.invalid'),makeSession('partner-b@example.invalid'),
  makeSession('other@example.invalid'),makeSession('viewer@example.invalid')
 ]);
 const meA=await expect(a,'b2b/me',200),meB=await expect(b,'b2b/me',200);
 assert.equal(meA.agencies.length,1);assert.equal(meB.agencies.length,1);
 const aid=Number(meA.agencies[0].id),bid=Number(meB.agencies[0].id);
 check(aid>0&&bid>0&&aid!==bid&&!meA.admin&&!meB.admin,'distinct PARTNER-role sessions have separate agency memberships');
 await expect(viewer,'b2b/me',403);
 await expect(a,'b2b/admin/agencies',403);
 await expect(b,'b2b/admin/agencies',403);
 await expect(foreign,'b2b/admin/agencies',200);
 await expect(a,'b2b/kpi?agency_id='+bid,404);
 await expect(b,'b2b/kpi?agency_id='+aid,404);
 await expect(b,'b2b/kpi?agency_id='+aid,404,'GET',undefined,'bogus');
 check(true,'real API enforces role and cross-agency KPI ACL');

 const listings=await expect(admin,'tour-library?status=ACTIVE&page_size=100',200);
 const master=(listings.items||[]).find(x=>x.status==='ACTIVE');
 assert(master,'Approved Master must exist in local fixture');
 const source=(await expect(admin,'tour-library/'+master.id,200)).program;
 const copy={...source,title:'B2B EXCLUSIVE synthetic tour',status:'ACTIVE',source_type:'MANUAL',
   source_name:'',source_text:'',source_url:'',creation_key:'b2b-two-agency-local-master-0001'};
 for(const key of ['id','import_token','has_source'])delete copy[key];
 const exclusive=(await expect(admin,'tour-library',201,'POST',copy)).id;
 assert(exclusive>0 && exclusive!==master.id);
 const pubCommon=await expect(admin,'b2b/admin/publish',201,'POST',{program_id:master.id,distribution_scope:'COMMON'});
 const pubExclusive=await expect(admin,'b2b/admin/publish',201,'POST',{program_id:exclusive,distribution_scope:'EXCLUSIVE',exclusive_agency_id:aid});
 check(pubCommon.id>0&&pubExclusive.id>0&&pubCommon.id!==pubExclusive.id,'admin creates COMMON and EXCLUSIVE published immutable snapshots');
 const listA=(await expect(a,'b2b/tours?agency_id='+aid,200)).items;
 const listB=(await expect(b,'b2b/tours?agency_id='+bid,200)).items;
 assert(listA.some(p=>Number(p.id)===Number(pubCommon.id))&&listA.some(p=>Number(p.id)===Number(pubExclusive.id)));
 assert(listB.some(p=>Number(p.id)===Number(pubCommon.id))&&!listB.some(p=>Number(p.id)===Number(pubExclusive.id)));
 await expect(a,'b2b/tours?agency_id='+bid,404);
 await expect(b,'b2b/tours?agency_id='+aid,404);
 await expect(b,'b2b/tours/'+pubExclusive.id+'?agency_id='+bid,404);
 await expect(b,'b2b/tours/'+pubExclusive.id+'?agency_id='+aid,404);
 await expect(foreign,'b2b/tours/'+pubExclusive.id+'?agency_id='+aid,404);
 await expect(b,'b2b/tours/'+pubExclusive.id+'/copy',404,'POST',{agency_id:bid});
 check(true,'COMMON visible to both; EXCLUSIVE detail, forged agency and copy denied to B/other company');

 await expect(a,'b2b/tours/'+pubCommon.id+'/copy',419,'POST',{agency_id:aid},'invalid-fixture-csrf');
 const aCopy=(await expect(a,'b2b/tours/'+pubExclusive.id+'/copy',201,'POST',{agency_id:aid})).id;
 const bCopy=(await expect(b,'b2b/tours/'+pubCommon.id+'/copy',201,'POST',{agency_id:bid})).id;
 assert(aCopy!==bCopy);
 await expect(b,'b2b/my-tours/'+aCopy,404);
 await expect(a,'b2b/my-tours/'+bCopy,404);
 const aWorking=(await expect(a,'b2b/my-tours/'+aCopy,200)).tour;
 const bWorking=(await expect(b,'b2b/my-tours/'+bCopy,200)).tour;
 await expect(a,'b2b/my-tours/'+aCopy,200,'PUT',{
   revision:aWorking.revision,markup_type:'PERCENT',markup_value:15,
   content:{...aWorking.content,title:'A independent white-label tour'}
 });
 const bAgain=(await expect(b,'b2b/my-tours/'+bCopy,200)).tour;
 assert.equal(bAgain.content.title,bWorking.content.title);
 check(true,'company/agency-scoped editable tour copy with per-revision isolation and CSRF');

 await expect(a,'b2b/agencies/'+bid+'/branding',404,'PUT',{brand_name:'Injected B'});
 await expect(b,'b2b/agencies/'+aid+'/branding',404,'PUT',{brand_name:'Injected A'});
 await expect(a,'b2b/agencies/'+aid+'/branding',200,'PUT',{
   brand_name:'Agency A Signature',brand_color:'#145678',email:'a@example.invalid',whatsapp:'+840000001'
 });
 const doc=await a.request('b2b/my-tours/'+aCopy+'/export/html?audience=itinerary');
 assert.equal(doc.status,200);
 // The output is HTML; request() intentionally carries a short non-JSON raw sample.
 assert(doc.data.raw?.includes('Agency A Signature'),JSON.stringify(doc.data));
 assert(!doc.data.raw.includes('Vietnam Travel Advisor'),'White-label HTML contains no VTA fallback');
 await expect(a,'b2b/my-tours/'+aCopy+'/quote?pax=6&mode=PRIVATE&hotel=3&cruise=5',200);
 await expect(a,'b2b/my-tours/'+aCopy+'/booking-request',409,'POST',{
   departure_date:'2027-03-10',pax:6,mode:'PRIVATE',hotel:'3',cruise:'5',guest_name:'Synthetic Guest'
 });
 check(true,'agency branding prints in itinerary; missing reviewed NET cannot create confirmed booking');

 const request={
   partner_tour_id:aCopy,request_id:'synthetic-net-retry-0001',departure_date:'2027-03-10',
   pax:6,foc:1,mode:'PRIVATE',hotel:'3',cruise:'5',notes:'Await approved Hotel3 Cruise5 NET'
 };
 const first=await expect(a,'b2b/net-requests',201,'POST',request);
 const replay=await expect(a,'b2b/net-requests',200,'POST',request);
 assert.equal(first.id,replay.id);assert.equal(replay.reused,true);
 await expect(a,'b2b/net-requests',409,'POST',{...request,foc:2});
 const statsA=await expect(a,'b2b/kpi?agency_id='+aid,200);
 const statsB=await expect(b,'b2b/kpi?agency_id='+bid,200);
 assert.equal(statsA.net_requests.REQUESTED,1);assert.equal(statsB.net_requests.REQUESTED,0);
 assert(statsA.active_copies>=1&&statsB.active_copies>=1);
 for(const stats of [statsA,statsB]){
   const serialized=JSON.stringify(stats);
   for(const banned of ['guest_name','guest_email','net_per_pax','supplier_id','profit','markup'])
     assert(!serialized.includes(banned),'KPI exposed '+banned);
 }
 const partnerAList=(await expect(a,'b2b/net-requests',200)).items;
 const partnerBList=(await expect(b,'b2b/net-requests',200)).items;
 assert(partnerAList.some(x=>Number(x.id)===Number(first.id)));
 assert(!partnerBList.some(x=>Number(x.id)===Number(first.id)));
 check(true,'real HTTP NET retry idempotency, changed FOC conflict and A/B private KPI/NET status');

 await expect(admin,'b2b/admin/publications/'+pubExclusive.id+'/revoke',200,'POST',{});
 await expect(a,'b2b/tours/'+pubExclusive.id+'?agency_id='+aid,404);
 await expect(a,'b2b/my-tours/'+aCopy,404);
 const after=(await expect(a,'b2b/kpi?agency_id='+aid,200));
 assert(after.active_copies<statsA.active_copies);
 check(true,'revocation removes previously copied EXCLUSIVE access and KPI counts');

 // Real browser sessions and viewport QA using localhost; not CyberPanel staging.
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  for(const [email,label,width] of [
   ['partner-a@example.invalid','Agency A',390],
   ['partner-b@example.invalid','Agency B',1440]
  ]){
   const context=await browser.newContext({viewport:{width,height:820}});
   const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(origin+'/partner.html');
   await page.locator('[name=email]').fill(email);
   await page.locator('[name=password]').fill(password);
   await page.locator('#hubLogin button').click();
   await page.locator('[data-tab=kpi]').waitFor({timeout:12000});
   await page.locator('[data-tab=kpi]').click();
   await page.getByRole('heading',{name:'Partner KPI'}).waitFor();
   assert(await page.locator('#hubAgency option').count()===1,label+' browser must have one agency');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+' responsive horizontal overflow');
   assert.deepEqual(errors,[],label+' page JavaScript errors');
   await context.close();
  }
  check(true,'real Chromium partner sessions render mobile and desktop KPI without cross-agency switcher');
 }finally{await browser.close();}
 console.log('B2B real-auth disposable PHP/MariaDB/browser ACCEPTANCE FIXTURE PASS; production NOT tested');
})().catch(err=>{console.error('FAIL B2B real-auth fixture:',err.stack||err);process.exitCode=1});

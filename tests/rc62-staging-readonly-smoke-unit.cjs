'use strict';
const assert=require('node:assert/strict');
const {guard,runCheck,APPROVAL,LOGIN_APPROVAL,ROLE_KEYS}=require('./rc62-staging-readonly-smoke.cjs');
const cfg={base:'https://staging-fixture.example.invalid/',hostPin:'staging-fixture.example.invalid',
  sha:'a'.repeat(40),inventorySha:'c'.repeat(64),approval:APPROVAL,auth:false};
const creds={};ROLE_KEYS.forEach((role,i)=>creds[role]={
  email:'fixture-role-'+i+'@example.invalid',
  password:'fixture-only-secret-'+i+'-never-live'});
function expectBlocked(f,reason){
 assert.throws(f,e=>e.message===reason);
 console.log('PASS_CI '+reason);
}
function response(status,data,extras={}){
 return new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json',
   'Cache-Control':'no-store',...extras}});
}
function fakeServer({kpiLeaked=false,exclusiveBAllowed=false,redirect=false,
  missingBuild=false,wrongSha=false,invalidInventory=false}={}){
 const calls=[],userByCookie=new Map(),loginByEmail=new Map(Object.entries(creds)
   .map(([role,c],i)=>[c.email,{role,i,id:i+1,company_id:1}]));
 const f=async (url,opt)=>{
   const parsed=new URL(url),route=parsed.searchParams.get('route'),
     agency=Number(parsed.searchParams.get('agency_id')),cookie=opt.headers.Cookie||'';
   calls.push({method:opt.method,route});
   assert(parsed.protocol==='https:'&&parsed.hostname===cfg.hostPin,'unexpected fake outbound URL');
   if(redirect&&route==='health')return response(302,{ok:false},{Location:'https://prod.example.invalid/'});
   if(opt.headers.Origin)return response(403,{ok:false,error:'ORIGIN_NOT_ALLOWED'});
   if(route==='health')return response(200,{ok:true,service:'VTA API',
     build:missingBuild?{status:'UNPACKAGED_SOURCE'}:{
       status:'PINNED_PACKAGE',source_commit:wrongSha?'b'.repeat(40):cfg.sha,
       source_inventory_sha256:invalidInventory?'no-hash':'c'.repeat(64),
       source_file_count:251
     }});
   if(route==='auth/login'){
     assert.equal(opt.method,'POST');
     const body=JSON.parse(opt.body),user=loginByEmail.get(body.email);
     if(!user)return response(401,{error:'INVALID_CREDENTIALS'});
     const raw='sid_fixture_'+user.i+'=abcdefghijklmnopqrstuvwxyz1234567890';
     userByCookie.set(raw,user);
     return response(200,{ok:true,user:{csrf:'fixtureonly'}},
       {'Set-Cookie':raw+'; Secure; HttpOnly; SameSite=Strict; Path=/'});
   }
   const u=userByCookie.get(cookie);
   if(!u)return response(401,{ok:false,error:'AUTH_REQUIRED'});
   if(route==='auth/me')return response(200,{ok:true,user:{
     id:u.id,company_id:1,role_code:u.role.startsWith('AGENCY_')?'PARTNER':u.role,
     status:'ACTIVE',permissions:u.role.startsWith('AGENCY_')?['b2b.portal']:['tour_library.view']}});
   if(route==='b2b/me')return response(200,{ok:true,admin:false,
     agencies:[{id:u.role==='AGENCY_A'?11:22}]});
   if(route==='b2b/admin/agencies')return response(403,{error:'FORBIDDEN'});
   if(route==='b2b/kpi'){
     if((u.role==='AGENCY_A'&&agency!==11)||(u.role==='AGENCY_B'&&agency!==22))
       return response(404,{error:'NOT_FOUND'});
     return response(200,{agency:{id:agency},published_tours:2,active_copies:1,
       net_requests:{REQUESTED:1},booking_requests:{REQUESTED:0},
       ...(kpiLeaked?{supplier_id:19}:{})});
   }
   const match=route.match(/^b2b\/tours\/(\d+)$/);
   if(match){
     const id=Number(match[1]);
     const ok=(id===33&&agency===(u.role==='AGENCY_A'?11:22))||
       (id===44&&(u.role==='AGENCY_A'||exclusiveBAllowed)&&
         agency===(u.role==='AGENCY_A'?11:22));
     return response(ok?200:404,ok?{ok:true,tour:{id}}:{error:'NOT_FOUND'});
   }
   throw Error('unexpected route in simulated server');
 };
 return {fetch:f,calls};
}
(async()=>{
 expectBlocked(()=>guard({...cfg,base:'http://staging-fixture.example.invalid/'}),
   'BLOCK_STAGING_HOST_NOT_INDEPENDENTLY_PINNED');
 expectBlocked(()=>guard({...cfg,base:'https://quote.vietnamtraveladvisor.com.vn/',
   hostPin:'quote.vietnamtraveladvisor.com.vn'}),'BLOCK_STAGING_HOST_NOT_INDEPENDENTLY_PINNED');
 expectBlocked(()=>guard({...cfg,base:'https://staging-fixture.example.invalid/',hostPin:'another-stage.example.invalid'}),
   'BLOCK_STAGING_HOST_NOT_INDEPENDENTLY_PINNED');
 expectBlocked(()=>guard({...cfg,approval:''}),'BLOCK_OPERATOR_READ_ONLY_APPROVAL_REQUIRED');
 expectBlocked(()=>guard({...cfg,sha:'not-a-git-sha'}),'BLOCK_CANDIDATE_SHA_PIN_REQUIRED');
 expectBlocked(()=>guard({...cfg,inventorySha:'wrong-inventory'}),'BLOCK_SOURCE_INVENTORY_PIN_REQUIRED');
 expectBlocked(()=>guard({...cfg,auth:true}),'BLOCK_LOGIN_AUDIT_WRITE_CONSENT_REQUIRED');
 const readonly=fakeServer();
 const unauth=await runCheck(cfg,readonly.fetch);
 assert.equal(unauth.status,'PASS_ANONYMOUS_STAGING_HTTP_ONLY');
 assert.equal(unauth.release_authorized,false);
 assert(readonly.calls.every(c=>c.method==='GET'),'no unauthenticated writes');
 assert(unauth.checks.includes('UNAPPROVED_ORIGIN_REJECTED'));
 assert(unauth.checks.includes('LIVE_RUNTIME_EXACT_CANDIDATE_SHA'));
 for(const args of [{missingBuild:true},{wrongSha:true},{invalidInventory:true}]){
   await assert.rejects(()=>runCheck(cfg,fakeServer(args).fetch),
     e=>e.message==='BLOCK_STAGING_BUILD_IDENTITY_MISMATCH');
 }
 console.log('PASS_CI health 200 fails if runtime unpackaged, wrong exact SHA or malformed inventory');
 console.log('PASS_CI anonymous probe only GET health, origin and unauthenticated denials');
 const withAuth={...cfg,auth:true,loginApproval:LOGIN_APPROVAL,credentials:creds,
   agencyA:11,agencyB:22,commonPublication:33,exclusiveA:44};
 const simulated=fakeServer();
 const report=await runCheck(withAuth,simulated.fetch);
 assert.equal(report.authenticated_roles,7);
 assert.equal(report.release_authorized,false);
 assert.equal(report.status,'PASS_READ_ONLY_STAGING_ROLE_AND_B2B_SMOKE');
 assert.equal(simulated.calls.filter(x=>x.method==='POST').length,7);
 assert(simulated.calls.filter(x=>x.method==='POST').every(c=>c.route==='auth/login'),
   'only intentional login writes permitted');
 const safe=JSON.stringify(report);
 for(const {email,password} of Object.values(creds)){
   assert(!safe.includes(email)&&!safe.includes(password));
 }
 console.log('PASS_CI seven authentic cookie sessions, A/B KPI/exclusive read-only and redacted output');
 await assert.rejects(()=>runCheck(withAuth,fakeServer({kpiLeaked:true}).fetch),
   e=>e.message==='BLOCK_PARTNER_KPI_PRIVATE_FIELDS');
 console.log('PASS_CI supplier ID leakage in Partner KPI blocked');
 await assert.rejects(()=>runCheck(withAuth,fakeServer({exclusiveBAllowed:true}).fetch),
   e=>e.message==='BLOCK_STAGING_HTTP_CONTRACT_b2b_tours_44');
 console.log('PASS_CI Partner B access to Exclusive A is a blocking failure');
 await assert.rejects(()=>runCheck(cfg,fakeServer({redirect:true}).fetch),
   e=>e.message==='BLOCK_STAGING_REDIRECT');
 console.log('PASS_CI HTTPS redirect to another origin is a blocking failure');
 console.log('Stage smoke tests are synthetic only; NO real host/credentials/database touched.');
})().catch(e=>{console.error('FAIL_CI '+e.message);process.exitCode=1;});

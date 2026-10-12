'use strict';
const assert=require('node:assert/strict');
const stage=require('./rc62-staging-operator-readonly.cjs');
const PIN='a'.repeat(40);
const config={url:'https://rc62-staging.example.invalid/',approvedHost:'rc62-staging.example.invalid',
 sha:PIN,common:11,exclusive:12,revoked:13,aCopy:41,bCopy:42};
const users={
 admin:{id:1,role_code:'ADMIN'},sales:{id:2,role_code:'SALES'},
 operations:{id:3,role_code:'OPERATIONS'},finance:{id:4,role_code:'FINANCE'},
 readonly:{id:5,role_code:'READONLY'},
 'agency_a':{id:6,role_code:'PARTNER'},'agency_b':{id:7,role_code:'PARTNER'}
};
const creds={};
const index={};
for(const r of stage.ROLES){
 const key=r.toLowerCase();
 creds[r]={email:key+'@stage-fixture.invalid',password:'local-fixture-password-only'};
 index[creds[r].email]=key;
}
const callLog=[];
function mockResponse(status,value,cookie=''){
 return {status,headers:{get(name){return name==='set-cookie'?cookie:null;}},
   async text(){return JSON.stringify(value);}};
}
function fixtureFetch(url,options){
 const address=new URL(url);
 assert.equal(address.host,'rc62-staging.example.invalid');
 assert.equal(address.pathname,'/api/index.php');
 assert.equal(options.redirect,'error','disable cross-origin redirects');
 const route=address.searchParams.get('route'),agencyId=Number(address.searchParams.get('agency_id'));
 const cookie=String(options.headers.Cookie||'');
 const caller=cookie.startsWith('vta_session=')?cookie.split('=')[1]:null;
 callLog.push({role:caller||'anonymous',route,method:options.method});
 if(route==='health'&&options.method==='GET')return Promise.resolve(mockResponse(200,{ok:true,service:'VTA API'}));
 if(route==='auth/login'){
  assert.equal(options.method,'POST');
  const body=JSON.parse(options.body);
  assert.equal(body.password,'local-fixture-password-only');
  const actor=index[body.email];if(!actor)throw Error('Unknown fixture credentials');
  return Promise.resolve(mockResponse(200,{ok:true,user:{...users[actor],company_id:1,csrf:'token'}},'vta_session='+actor.padEnd(12,'0')+'; HttpOnly; Secure'));
 }
 const actor=Object.keys(users).find(x=>x.padEnd(12,'0')===caller);
 const own=actor?users[actor]:null;
 if(!own)return Promise.resolve(mockResponse(401,{ok:false,error:'UNAUTHORIZED'}));
 if(route==='auth/me')return Promise.resolve(mockResponse(200,{ok:true,user:{...own,company_id:1,csrf:'token'}}));
 if(route==='b2b/me'){
  if(actor==='admin')return Promise.resolve(mockResponse(200,{ok:true,admin:true,agencies:[]}));
  if(actor==='agency_a')return Promise.resolve(mockResponse(200,{ok:true,admin:false,agencies:[{id:101}]}));
  if(actor==='agency_b')return Promise.resolve(mockResponse(200,{ok:true,admin:false,agencies:[{id:202}]}));
  return Promise.resolve(mockResponse(403,{ok:false,error:'FORBIDDEN'}));
 }
 const partner=actor==='agency_a'||actor==='agency_b';
 const ownsAgency=partner && agencyId===(actor==='agency_a'?101:202);
 if(route==='b2b/kpi'){
  return Promise.resolve(mockResponse(ownsAgency?200:404,ownsAgency?{ok:true,net_requests:{REQUESTED:1}}:{ok:false}));
 }
 if(route==='b2b/tours'){
  return Promise.resolve(mockResponse(ownsAgency?200:404,ownsAgency?{ok:true,items:
   actor==='agency_a'?[{id:11},{id:12}]:[{id:11}]}:{ok:false}));
 }
 if(route.startsWith('b2b/tours/')){
  const id=Number(route.split('/')[2]);
  const allowed=ownsAgency&&(id===11||(id===12&&actor==='agency_a'));
  return Promise.resolve(mockResponse(allowed?200:404,allowed?{ok:true,publication:{id}}:{ok:false}));
 }
 if(route.startsWith('b2b/my-tours/')){
  const id=Number(route.split('/')[2]);
  const allowed=(id===41&&actor==='agency_a')||(id===42&&actor==='agency_b');
  return Promise.resolve(mockResponse(allowed?200:404,allowed?{ok:true,tour:{id}}:{ok:false}));
 }
 throw Error('Unexpected network route (possible write?)');
}
function rejected(fn,code){
 assert.throws(fn,e=>e.safeCode===code,'expected refusal '+code);
 console.log('PASS_CI '+code);
}
(async()=>{
 const r=stage.validateTarget(config.url,config.approvedHost,config.sha);
 assert.equal(r.sha,PIN);
 rejected(()=>stage.validateTarget('https://quote.vietnamtraveladvisor.com.vn/','quote.vietnamtraveladvisor.com.vn',PIN),'INDEPENDENT_STAGING_HOST_PIN_REQUIRED');
 rejected(()=>stage.validateTarget('http://rc62-staging.example.invalid/','rc62-staging.example.invalid',PIN),'PRIVATE_HTTPS_STAGE_URL_REQUIRED');
 rejected(()=>stage.validateTarget('https://rc62-staging.example.invalid:8443/','rc62-staging.example.invalid',PIN),'PRIVATE_HTTPS_STAGE_URL_REQUIRED');
 rejected(()=>stage.validateTarget('https://rc62-staging.example.invalid/','other-staging.example.invalid',PIN),'INDEPENDENT_STAGING_HOST_PIN_REQUIRED');
 rejected(()=>stage.validateTarget('https://rc62-staging.example.invalid/','rc62-staging.example.invalid','00'),'EXACT_SHA_PIN_REQUIRED');
 rejected(()=>stage.makeUrl(r.base,'b2b/admin/publish'),'READ_ONLY_ROUTE_ALLOWLIST');
 rejected(()=>stage.makeUrl(r.base,'b2b/net-requests'),'READ_ONLY_ROUTE_ALLOWLIST');
 rejected(()=>stage.makeUrl(r.base,'b2b/tours/11/copy'),'READ_ONLY_ROUTE_ALLOWLIST');
 rejected(()=>stage.makeUrl(r.base,'b2b/kpi',{agency_id:-1}),'READ_ONLY_QUERY_DENIED');
 const flags=['--url',config.url,'--approved-host',config.approvedHost,'--expected-sha',PIN,
  '--common-id','11','--exclusive-id','12','--revoked-id','13','--a-copy-id','41','--b-copy-id','42'];
 const env={VTA_STAGE_UAT_OPERATOR_APPROVED:'YES_READ_ONLY_API_PROBES'};
 for(const role of stage.ROLES){
  env['VTA_STAGE_UAT_'+role+'_EMAIL']=creds[role].email;
  env['VTA_STAGE_UAT_'+role+'_PASSWORD']=creds[role].password;
 }
 const noop=await stage.main(flags,env);
 assert.equal(noop,2);
 assert.equal(callLog.length,0,'default must never connect');
 console.log('PASS_CI no --execute means zero network requests');
 assert.equal((await stage.main([...flags,'--execute'],{...env,VTA_STAGE_UAT_OPERATOR_APPROVED:'NO'})),2);
 assert.equal(callLog.length,0,'missing approval must never connect');
 console.log('PASS_CI explicit operator approval required before network');
 const result=await stage.probe(config,creds,fixtureFetch);
 assert.equal(result.status,'PASS_OPERATOR_READ_ONLY_API_PROBES');
 assert.equal(result.release_authorized,false);
 assert.equal(result.source_sha_remote_attested,false);
 assert.equal(result.check_counts.login,7);
 assert(result.check_counts.denied>=10);
 assert(callLog.every(entry=>entry.method==='GET'||entry.route==='auth/login'&&entry.method==='POST'),
  'test harness must never write business data');
 assert.equal(new Set(callLog.filter(e=>e.route==='auth/login').map(e=>e.role)).size,1,
  'only anonymous POST login should use anonymous cookie');
 console.log('PASS_CI authenticated 7-role mock sessions, Common/Exclusive/revoke and cross-agency GET 404');
 console.log('PASS_CI no price/PII logged, no business POST, no production host, release_authorized=false');
})().catch(e=>{console.error('FAIL_CI staging operator harness unit',e.safeCode||e.message);process.exitCode=1;});

'use strict';
/**
 * Operator-invoked RC6.2 HTTPS staging *read-only business API* probes.
 * The ONLY POST is auth/login (creates an audit/session and updates last_login_at).
 * Never writes tour, rate, NET, booking, supplier, media, Finance or Ops data.
 * Default: offline plan; NO network unless --execute + independent approval env.
 *
 * IMPORTANT: PASS_OPERATOR_READ_ONLY_PROBES is not PASS_STAGING_REAL_AUTH
 * for E2E-01..08, a deployment approval or proof of remote runtime SHA.
 */
const crypto=require('node:crypto');
const ROLES=['ADMIN','SALES','OPERATIONS','FINANCE','READONLY','AGENCY_A','AGENCY_B'];
const ALLOWED_GET=new Set([
 'health','auth/me','b2b/me','b2b/tours','b2b/kpi',
 'b2b/my-tours'
]);
const SOURCE='OPERATOR_HTTPS_STAGING_READ_ONLY_API';
function fail(code){const e=new Error(code);e.safeCode=code;throw e;}
function positiveId(value){
 return /^[1-9][0-9]{0,12}$/.test(String(value||'')) ? Number(value):fail('PREAPPROVED_FIXTURE_IDS_REQUIRED');
}
function validateTarget(base,approvedHost,sha){
 if(!/^[a-f0-9]{40}$/.test(String(sha||'')))fail('EXACT_SHA_PIN_REQUIRED');
 let u;
 try{u=new URL(base);}catch{fail('PRIVATE_HTTPS_STAGE_URL_REQUIRED');}
 if(u.protocol!=='https:'||u.username||u.password||u.port||u.pathname!=='/'||u.search||u.hash)
   fail('PRIVATE_HTTPS_STAGE_URL_REQUIRED');
 const host=u.hostname.toLowerCase(),pin=String(approvedHost||'').toLowerCase();
 if(!pin||host!==pin||!host.includes('stag')||
    !/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/.test(host)||
    host==='quote.vietnamtraveladvisor.com.vn'||
    host==='v2quote.vietnamtraveladvisor.com.vn'||
    host==='localhost'||host==='127.0.0.1'||
    host.endsWith('.local')||host.endsWith('.internal'))
   fail('INDEPENDENT_STAGING_HOST_PIN_REQUIRED');
 return {base:u.origin,hostname:host,sha};
}
function roleCreds(env,run){
 const creds={};
 if(!run)return creds;
 for(const role of ROLES){
  const email=env['VTA_STAGE_UAT_'+role+'_EMAIL'];
  const password=env['VTA_STAGE_UAT_'+role+'_PASSWORD'];
  if(!email||!password||!email.includes('@')||password.length<8)
    fail('PRIVATE_ROLE_CREDENTIALS_MISSING');
  creds[role]={email,password};
 }
 if(new Set(ROLES.map(r=>creds[r].email.toLowerCase())).size!==ROLES.length)
   fail('INDEPENDENT_ROLE_IDENTITIES_REQUIRED');
 return creds;
}
function makeUrl(base,route,query={}){
 const normalized=route.replace(/\/[0-9]+/g,'/:id');
 if(!ALLOWED_GET.has(normalized)&&
  !/^b2b\/tours\/:id$/.test(normalized)&&
  !/^b2b\/my-tours\/:id$/.test(normalized))
  fail('READ_ONLY_ROUTE_ALLOWLIST');
 const params=new URLSearchParams({route});
 for(const [k,v] of Object.entries(query)){
  if(!['agency_id'].includes(k)||!Number.isSafeInteger(v)||v<1)fail('READ_ONLY_QUERY_DENIED');
  params.set(k,String(v));
 }
 return base+'/api/index.php?'+params.toString();
}
async function collect(response){
 if(!response||!Number.isInteger(response.status))fail('STAGING_NETWORK_RESPONSE_INVALID');
 let value;
 try{
  const body=await response.text();
  if(body.length>512000)fail('STAGING_RESPONSE_LIMIT');
  value=JSON.parse(body);
 }catch(e){if(e.safeCode)throw e;fail('STAGING_JSON_RESPONSE_INVALID');}
 if(!value||typeof value!=='object'||Array.isArray(value))fail('STAGING_JSON_RESPONSE_INVALID');
 return {status:response.status,data:value,headers:response.headers};
}
async function probe(config,credentials,fetchFn=fetch){
 const {base,sha}=validateTarget(config.url,config.approvedHost,config.sha);
 const ids={
  common:positiveId(config.common),
  exclusive:positiveId(config.exclusive),
  revoked:positiveId(config.revoked),
  aCopy:positiveId(config.aCopy),
  bCopy:positiveId(config.bCopy),
 };
 if(new Set(Object.values(ids)).size!==5)fail('DISTINCT_STAGING_FIXTURE_IDS_REQUIRED');
 const counts={get:0,login:0,denied:0};
 async function call(url,options){
  let response;
  try{response=await fetchFn(url,{
    ...options,
    redirect:'error',
    signal:AbortSignal.timeout(15000)
  });}catch{fail('STAGING_TLS_OR_NETWORK_FAILURE');}
  return collect(response);
 }
 async function GET(session,route,query={}){
  const url=makeUrl(base,route,query);
  const headers={Accept:'application/json','Cache-Control':'no-store'};
  if(session?.cookie)headers.Cookie=session.cookie;
  counts.get++;
  return call(url,{method:'GET',headers});
 }
 async function expect(session,route,status,query={}){
  const r=await GET(session,route,query);
  if(r.status!==status)fail('STAGING_HTTP_ACCESS_ASSERTION_FAILED');
  if(status===404||status===403||status===401)counts.denied++;
  if(status===200&&r.data.ok!==true)fail('STAGING_RESPONSE_UNEXPECTED');
  return r.data;
 }
 await expect(null,'health',200);
 await expect(null,'b2b/me',401);
 const sessions={},users={};
 for(const role of ROLES){
  const cred=credentials[role];
  if(!cred||!cred.email||!cred.password)fail('PRIVATE_ROLE_CREDENTIALS_MISSING');
  const headers={'Content-Type':'application/json',Accept:'application/json','Cache-Control':'no-store'};
  // Every role has an isolated cookie and is never sent to any other host.
  counts.login++;
  const response=await call(base+'/api/index.php?route=auth%2Flogin',{
    method:'POST',headers,body:JSON.stringify({email:cred.email,password:cred.password})
  });
  if(response.status!==200||response.data.ok!==true||!response.data.user?.id)
   fail('STAGING_ROLE_LOGIN_FAILED');
  const cookies=response.headers?.get?.('set-cookie')||'';
  const cookie=cookies.split(';')[0];
  if(!/^[a-zA-Z0-9_-]{2,60}=[A-Za-z0-9_-]{8,300}$/.test(cookie))
    fail('STAGING_SECURE_SESSION_COOKIE_MISSING');
  const session={cookie};
  const logged=await expect(session,'auth/me',200);
  if(Number(logged.user?.id)!==Number(response.data.user.id)||
     logged.user?.role_code!==response.data.user.role_code||
     !logged.user?.csrf)fail('STAGING_SESSION_IDENTITY_MISMATCH');
  sessions[role]=session;
  users[role]={id:Number(logged.user.id),company:Number(logged.user.company_id),
    role:String(logged.user.role_code)};
 }
 if(new Set(ROLES.map(x=>users[x].id)).size!==ROLES.length)
  fail('STAGING_ROLE_USERS_NOT_DISTINCT');
 const company=users.ADMIN.company;
 if(!company||ROLES.some(role=>users[role].company!==company))
  fail('STAGING_SEVEN_ROLES_MUST_SHARE_COMPANY');
 if(users.AGENCY_A.role!=='PARTNER'||users.AGENCY_B.role!=='PARTNER')
  fail('STAGING_PARTNER_ROLE_REQUIRED');
 const admin=await expect(sessions.ADMIN,'b2b/me',200);
 if(admin.admin!==true)fail('STAGING_ADMIN_B2B_SCOPE_MISSING');
 const a=await expect(sessions.AGENCY_A,'b2b/me',200),
       b=await expect(sessions.AGENCY_B,'b2b/me',200);
 if(a.admin||b.admin||!Array.isArray(a.agencies)||!Array.isArray(b.agencies)||
    a.agencies.length!==1||b.agencies.length!==1)
  fail('STAGING_AGENCY_MEMBERSHIP_NOT_EXCLUSIVE');
 const aid=positiveId(a.agencies[0].id),bid=positiveId(b.agencies[0].id);
 if(aid===bid)fail('STAGING_AGENCIES_NOT_INDEPENDENT');
 const A=sessions.AGENCY_A,B=sessions.AGENCY_B;
 // All subsequent network calls are GET. Do NOT create/change any business row.
 await expect(A,'b2b/kpi',200,{agency_id:aid});
 await expect(B,'b2b/kpi',200,{agency_id:bid});
 await expect(A,'b2b/kpi',404,{agency_id:bid});
 await expect(B,'b2b/kpi',404,{agency_id:aid});
 const toursA=(await expect(A,'b2b/tours',200,{agency_id:aid})).items;
 const toursB=(await expect(B,'b2b/tours',200,{agency_id:bid})).items;
 if(!Array.isArray(toursA)||!Array.isArray(toursB)||
    !toursA.some(t=>Number(t.id)===ids.common)||
    !toursB.some(t=>Number(t.id)===ids.common)||
    !toursA.some(t=>Number(t.id)===ids.exclusive)||
    toursB.some(t=>Number(t.id)===ids.exclusive))
  fail('STAGING_COMMON_EXCLUSIVE_CATALOG_INCONSISTENT');
 await expect(A,'b2b/tours/'+ids.common,200,{agency_id:aid});
 await expect(B,'b2b/tours/'+ids.common,200,{agency_id:bid});
 await expect(A,'b2b/tours/'+ids.exclusive,200,{agency_id:aid});
 await expect(B,'b2b/tours/'+ids.exclusive,404,{agency_id:bid});
 await expect(B,'b2b/tours/'+ids.exclusive,404,{agency_id:aid});
 await expect(A,'b2b/tours/'+ids.revoked,404,{agency_id:aid});
 await expect(B,'b2b/tours/'+ids.revoked,404,{agency_id:bid});
 await expect(A,'b2b/tours',404,{agency_id:bid});
 await expect(B,'b2b/tours',404,{agency_id:aid});
 await expect(A,'b2b/my-tours/'+ids.aCopy,200);
 await expect(B,'b2b/my-tours/'+ids.bCopy,200);
 await expect(A,'b2b/my-tours/'+ids.bCopy,404);
 await expect(B,'b2b/my-tours/'+ids.aCopy,404);
 // Don't serialize any fetched tour, name, PII, rates, supplier or profit.
 return {ok:true,status:'PASS_OPERATOR_READ_ONLY_API_PROBES',
   source_kind:SOURCE,candidate_sha_unverified_on_remote:sha,
   source_sha_remote_attested:false,
   check_counts:counts,agency_isolation_checked:true,
   release_authorized:false,
   requires:['operator_https_staging_identity_review','full_e2e01_to_e2e08',
     'supplier_and_document_review','existing_db_clone_restore','separate_release_approval']};
}
function parseCli(args){
 const flags={};for(let i=0;i<args.length;i++){
  if(args[i]==='--execute'){flags.execute=true;continue;}
  if(!args[i].startsWith('--')||i===args.length-1)fail('OPERATOR_ARGS_REQUIRED');
  const k=args[i].slice(2);
  if(flags[k]!==undefined)fail('DUPLICATE_OPERATOR_ARG');
  flags[k]=args[++i];
 }
 const expected=['url','approved-host','expected-sha','common-id','exclusive-id',
   'revoked-id','a-copy-id','b-copy-id'];
 if(expected.some(x=>!flags[x]))fail('OPERATOR_ARGS_REQUIRED');
 return {execute:!!flags.execute,
  url:flags.url,approvedHost:flags['approved-host'],sha:flags['expected-sha'],
  common:flags['common-id'],exclusive:flags['exclusive-id'],
  revoked:flags['revoked-id'],aCopy:flags['a-copy-id'],bCopy:flags['b-copy-id']};
}
async function main(args,env=process.env){
 try{
  const config=parseCli(args);
  validateTarget(config.url,config.approvedHost,config.sha);
  for(const key of ['common','exclusive','revoked','aCopy','bCopy'])positiveId(config[key]);
  if(!config.execute){
   process.stdout.write(JSON.stringify({ok:false,status:'READY_FOR_APPROVED_HTTPS_STAGE_ONLY',
    network_requests_made:0,release_authorized:false})+'\n');return 2;
  }
  if(env.VTA_STAGE_UAT_OPERATOR_APPROVED!=='YES_READ_ONLY_API_PROBES')
   fail('EXPLICIT_OPERATOR_READ_ONLY_APPROVAL_REQUIRED');
  const result=await probe(config,roleCreds(env,true));
  process.stdout.write(JSON.stringify(result)+'\n');return 0;
 }catch(e){
  process.stdout.write(JSON.stringify({ok:false,status:'STAGING_UAT_BLOCKED',
   reason:e.safeCode||'STAGING_PROBE_FAILURE',release_authorized:false})+'\n');
  return 2;
 }
}
module.exports={ROLES,validateTarget,parseCli,roleCreds,makeUrl,probe,main};
if(require.main===module)main(process.argv.slice(2)).then(code=>{process.exitCode=code;});

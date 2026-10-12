#!/usr/bin/env node
'use strict';
/*
 * RC6.2 operator-run HTTPS staging smoke. NOT a deployment or release approval.
 * Anonymous mode: GET only. --auth: seven explicit authorized fixture/operator
 * logins (POST auth/login updates last_login_at and creates a login audit event),
 * then GET-only cross-agency checks. Never writes tours/prices/bookings/suppliers.
 * Credentials and session cookies are never logged or written to disk.
 */
const {strict:assert}=require('node:assert');
const APPROVAL='I_APPROVE_RC62_STAGING_READ_ONLY_UAT';
const LOGIN_APPROVAL='I_APPROVE_RC62_STAGING_LOGIN_AUDIT_WRITES';
const ROLE_KEYS=['ADMIN','SALES','OPERATIONS','FINANCE','READ_ONLY','AGENCY_A','AGENCY_B'];
const EXPECTED_PIN=/^[a-f0-9]{40}$/;
function guard(config){
  const base=String(config.base||''),hostPin=String(config.hostPin||'');
  let u;
  try{u=new URL(base);}catch{throw Error('BLOCK_INVALID_STAGING_URL');}
  if(u.protocol!=='https:'||u.username||u.password||u.port||u.search||u.hash
      ||(u.pathname!==''&&u.pathname!=='/')||!u.hostname
      ||u.hostname!==hostPin||!/(?:^|[.-])stag(?:e|ing)(?:[.-]|$)/i.test(u.hostname)
      ||/(?:^|[.-])(?:prod|production|live)(?:[.-]|$)/i.test(u.hostname)
      ||['quote.vietnamtraveladvisor.com.vn','v2quote.vietnamtraveladvisor.com.vn','localhost'].includes(u.hostname)
      ||/^\d+\.\d+\.\d+\.\d+$/.test(u.hostname))
    throw Error('BLOCK_STAGING_HOST_NOT_INDEPENDENTLY_PINNED');
  if(!EXPECTED_PIN.test(String(config.sha||'')))throw Error('BLOCK_CANDIDATE_SHA_PIN_REQUIRED');
  if(config.approval!==APPROVAL)throw Error('BLOCK_OPERATOR_READ_ONLY_APPROVAL_REQUIRED');
  if(config.auth&&config.loginApproval!==LOGIN_APPROVAL)
    throw Error('BLOCK_LOGIN_AUDIT_WRITE_CONSENT_REQUIRED');
  return u.origin;
}
function assertResponse(condition,code){if(!condition)throw Error(code);}
function routeUrl(base,route){
  const parts=route.split('?'),u=new URL('/api/index.php',base);
  u.searchParams.set('route',parts[0]);
  if(parts.length>1)new URLSearchParams(parts.slice(1).join('?')).forEach((val,key)=>u.searchParams.set(key,val));
  return u.href;
}
async function runCheck(config,fetchImpl=globalThis.fetch){
  const base=guard(config),results=[];
  function pass(label){results.push(label);}
  async function send(route,{method='GET',body,cookie,origin}={}){
    assertResponse(method==='GET'||(method==='POST'&&route==='auth/login'&&config.auth
        &&config.loginApproval===LOGIN_APPROVAL),'BLOCK_MUTATING_HTTP_METHOD');
    const headers={'Accept':'application/json'};
    if(method==='POST')headers['Content-Type']='application/json';
    if(cookie)headers.Cookie=cookie;
    if(origin)headers.Origin=origin;
    const requestUrl=routeUrl(base,route);
    const response=await fetchImpl(requestUrl,{
      method,headers,body:body===undefined?undefined:JSON.stringify(body),
      redirect:'manual',cache:'no-store',signal:AbortSignal.timeout(12000)
    });
    assertResponse(response.status<300||response.status>=400,'BLOCK_STAGING_REDIRECT');
    const length=Number(response.headers.get('content-length')||0);
    assertResponse(!length||length<=512000,'BLOCK_STAGING_BODY_TOO_LARGE');
    const raw=await response.text();
    assertResponse(raw.length<=512000,'BLOCK_STAGING_BODY_TOO_LARGE');
    let value=null;try{value=JSON.parse(raw);}catch{}
    return {status:response.status,data:value,headers:response.headers};
  }
  async function code(route,expected,opts){
    const r=await send(route,opts);
    assertResponse(r.status===expected,'BLOCK_STAGING_HTTP_CONTRACT_'+route.split('?')[0].replaceAll('/','_'));
    return r;
  }
  const health=await code('health',200);
  assertResponse(health.data?.ok===true&&health.data.service==='VTA API','BLOCK_STAGING_HEALTH');
  // A health 200 on the right DNS can still be an old/incorrect PHP tree.
  // Fail closed until the running API proves it holds the exact candidate
  // source SHA and inventory hash of the ZIP independently approved offline.
  const build=health.data?.build;
  assertResponse(build?.status==='PINNED_PACKAGE'
    &&build.source_commit===config.sha
    &&/^[a-f0-9]{64}$/.test(String(build.source_inventory_sha256||''))
    &&Number.isInteger(build.source_file_count)
    &&build.source_file_count>=75,'BLOCK_STAGING_BUILD_IDENTITY_MISMATCH');
  pass('LIVE_RUNTIME_EXACT_CANDIDATE_SHA');
  assertResponse(/no-store/i.test(health.headers.get('cache-control')||''),
    'BLOCK_STAGING_API_CACHE_POLICY');
  pass('STAGING_HTTPS_HEALTH_NO_STORE');
  for(const endpoint of ['auth/me','b2b/me','b2b/admin/agencies'])
    await code(endpoint,401);
  pass('ANONYMOUS_ADMIN_PARTNER_DENIAL');
  const originDenied=await code('health',403,{origin:'https://blocked-origin.example.invalid'});
  assertResponse(originDenied.data?.error==='ORIGIN_NOT_ALLOWED','BLOCK_CROSS_ORIGIN_POLICY');
  pass('UNAPPROVED_ORIGIN_REJECTED');
  if(!config.auth)return {status:'PASS_ANONYMOUS_STAGING_HTTP_ONLY',source_commit:config.sha,
    authenticated_roles:0,checks:results,release_authorized:false,
    requires:['operator_verified_real_roles','full_golden_path','independent_release_approval']};
  // No fallback accounts, no shared passwords: operator provisions dedicated,
  // authorized real staging identities; each login changes last_login_at/audit.
  const seen=new Set(),sessions={};
  for(const role of ROLE_KEYS){
    const cred=config.credentials?.[role];
    assertResponse(typeof cred?.email==='string'&&cred.email.includes('@')
        &&typeof cred.password==='string'&&cred.password.length>=12,
        'BLOCK_STAGING_ROLE_CREDENTIAL_REQUIRED');
    const login=await code('auth/login',200,{method:'POST',
      body:{email:cred.email,password:cred.password}});
    const cookie=(login.headers.get('set-cookie')||'').split(';')[0];
    assertResponse(/^[A-Za-z0-9_-]{3,70}=[A-Za-z0-9_-]{15,}$/.test(cookie)
        &&/httponly/i.test(login.headers.get('set-cookie')||'')
        &&/secure/i.test(login.headers.get('set-cookie')||'')
        &&/samesite=strict/i.test(login.headers.get('set-cookie')||''),
        'BLOCK_STAGING_SECURE_SESSION_COOKIE');
    const me=(await code('auth/me',200,{cookie})).data?.user;
    const userId=Number(me?.id),companyId=Number(me?.company_id);
    assertResponse(me&&userId>0&&companyId>0&&me.status==='ACTIVE'
       &&typeof me.role_code==='string'&&Array.isArray(me.permissions)
       &&!seen.has(userId),'BLOCK_STAGING_ROLE_SESSION_IDENTITY');
    if(role.startsWith('AGENCY_'))assertResponse(me.role_code==='PARTNER',
        'BLOCK_STAGING_PARTNER_ROLE_MISMATCH');
    else assertResponse(me.role_code!=='PARTNER','BLOCK_STAGING_STAFF_ROLE_MISMATCH');
    seen.add(userId);
    sessions[role]={cookie,userId,companyId,permissions:me.permissions};
  }
  const company=sessions.ADMIN.companyId;
  for(const role of ROLE_KEYS)assertResponse(sessions[role].companyId===company,
    'BLOCK_STAGING_CROSS_COMPANY_MISMATCH');
  pass('SEVEN_REAL_AUTH_SEPARATE_STAGING_SESSIONS');
  const aid=Number(config.agencyA),bid=Number(config.agencyB);
  assertResponse(Number.isSafeInteger(aid)&&aid>0&&Number.isSafeInteger(bid)&&bid>0&&aid!==bid,
    'BLOCK_TWO_INDEPENDENT_AGENCY_PINS_REQUIRED');
  const A=sessions.AGENCY_A,B=sessions.AGENCY_B;
  const am=(await code('b2b/me',200,{cookie:A.cookie})).data;
  const bm=(await code('b2b/me',200,{cookie:B.cookie})).data;
  assertResponse(Array.isArray(am?.agencies)&&Array.isArray(bm?.agencies)
    &&am.agencies.some(a=>Number(a.id)===aid)&&bm.agencies.some(b=>Number(b.id)===bid)
    &&!am.agencies.some(a=>Number(a.id)===bid)
    &&!bm.agencies.some(b=>Number(b.id)===aid)
    &&!am.admin&&!bm.admin,'BLOCK_TWO_AGENCY_MEMBERSHIP_ISOLATION');
  await code('b2b/admin/agencies',403,{cookie:A.cookie});
  await code('b2b/admin/agencies',403,{cookie:B.cookie});
  await code('b2b/kpi?agency_id='+bid,404,{cookie:A.cookie});
  await code('b2b/kpi?agency_id='+aid,404,{cookie:B.cookie});
  const kA=(await code('b2b/kpi?agency_id='+aid,200,{cookie:A.cookie})).data;
  const kB=(await code('b2b/kpi?agency_id='+bid,200,{cookie:B.cookie})).data;
  const safeKpi=(k,id)=>k?.agency?.id===id&&k?.net_requests
    &&k?.booking_requests&&Number.isInteger(k.published_tours)
    &&Number.isInteger(k.active_copies)
    &&!['guest_name','guest_email','supplier_id','net_per_pax','profit',
         'markup','password_hash'].some(word=>JSON.stringify(k).includes(word));
  assertResponse(safeKpi(kA,aid)&&safeKpi(kB,bid),'BLOCK_PARTNER_KPI_PRIVATE_FIELDS');
  pass('PARTNER_A_B_MEMBERSHIP_FORGED_ID_KPI_ISOLATION');
  const common=Number(config.commonPublication),exclusive=Number(config.exclusiveA);
  assertResponse(Number.isSafeInteger(common)&&common>0&&Number.isSafeInteger(exclusive)
    &&exclusive>0&&common!==exclusive,'BLOCK_APPROVED_COMMON_EXCLUSIVE_PINS_REQUIRED');
  const detail=(id,agency)=>'b2b/tours/'+id+'?agency_id='+agency;
  await code(detail(common,aid),200,{cookie:A.cookie});
  await code(detail(common,bid),200,{cookie:B.cookie});
  await code(detail(exclusive,aid),200,{cookie:A.cookie});
  await code(detail(exclusive,bid),404,{cookie:B.cookie});
  await code(detail(exclusive,aid),404,{cookie:B.cookie});
  pass('COMMON_VISIBLE_BOTH_EXCLUSIVE_A_DENIED_B');
  return {status:'PASS_READ_ONLY_STAGING_ROLE_AND_B2B_SMOKE',source_commit:config.sha,
    authenticated_roles:7,checks:results,release_authorized:false,
    requires:['supplier_rates_6_plus_1_foc','50_real_docx_pdf',
      'restore_rehearsal','golden_path_real_device','operator_approval']};
}
function configFromEnv(env=process.env,auth=process.argv.includes('--auth')){
  const credentials={};
  for(const role of ROLE_KEYS)credentials[role]={
    email:env['VTA_STAGE_'+role+'_EMAIL'],
    password:env['VTA_STAGE_'+role+'_PASSWORD']};
  return {base:env.VTA_STAGE_URL,hostPin:env.VTA_STAGE_APPROVED_HOST,
    sha:env.VTA_STAGE_APPROVED_SHA,approval:env.VTA_STAGE_READONLY_APPROVAL,
    loginApproval:env.VTA_STAGE_LOGIN_APPROVAL,auth,credentials,
    agencyA:env.VTA_STAGE_AGENCY_A_ID,agencyB:env.VTA_STAGE_AGENCY_B_ID,
    commonPublication:env.VTA_STAGE_COMMON_PUBLICATION_ID,
    exclusiveA:env.VTA_STAGE_EXCLUSIVE_A_PUBLICATION_ID};
}
if(require.main===module)runCheck(configFromEnv()).then(result=>{
  process.stdout.write(JSON.stringify(result)+'\n');
}).catch(e=>{
  // Do not print HTTP response bodies, usernames, cookies, target URLs or IDs.
  const safe=/^BLOCK_[A-Z0-9_]+$/.test(e.message)?e.message:'BLOCK_STAGING_SMOKE_ERROR';
  process.stdout.write(JSON.stringify({status:'BLOCKED',reason:safe,
    release_authorized:false})+'\n');
  process.exitCode=2;
});
module.exports={guard,routeUrl,runCheck,configFromEnv,APPROVAL,LOGIN_APPROVAL,ROLE_KEYS};

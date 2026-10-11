'use strict';
/**
 * CI-only REAL HTTP contract: PHP 8.3 index.php + session cookie + CSRF +
 * TourLibrary::handle + MariaDB. The PHP server MUST be on 127.0.0.1:18869.
 * Does not connect to CyberPanel, WordPress, supplier endpoints or production.
 */
const assert=require('node:assert/strict');
const origin=process.env.VTA_V5_HTTP_URL;
const password=process.env.VTA_V5_TEST_PASSWORD;
assert.equal(origin,'http://127.0.0.1:18869','Only local CI HTTP server is permitted');
assert(password && password.length>=12,'Synthetic test password must be provided');

const endpoint=route=>origin+'/api/index.php?route='+encodeURIComponent(route);
const check=(ok,msg)=>{assert(ok,msg);console.log('PASS V5 HTTP '+msg)};
async function send(route,method='GET',body,session,csrf=undefined){
  const headers={'Accept':'application/json'};
  if(body!==undefined)headers['Content-Type']='application/json';
  if(session?.cookie)headers.Cookie=session.cookie;
  if(csrf!==undefined)headers['X-VTA-CSRF']=csrf;
  const r=await fetch(endpoint(route),{
    method,headers,redirect:'error',signal:AbortSignal.timeout(10000),
    ...(body===undefined?{}:{body:JSON.stringify(body)})
  });
  const text=await r.text();
  let data;
  try{data=JSON.parse(text)}catch{throw Error('Expected JSON for '+method+' '+route+' HTTP '+r.status)}
  return {status:r.status,data,headers:r.headers};
}
async function login(email){
  const r=await send('auth/login','POST',{email,password});
  assert.equal(r.status,200,'Login failed '+email+' HTTP '+r.status);
  assert(r.data.user?.csrf && r.data.user?.company_id, 'Login response lacks session metadata');
  const cookie=r.headers.get('set-cookie')?.split(';')[0];
  assert(cookie?.startsWith('vta_v5_http_fixture='),'Authenticated cookie missing');
  return {cookie,csrf:r.data.user.csrf,companyId:Number(r.data.user.company_id)};
}
async function expected(route,method,body,session,status,csrf=session?.csrf){
  const result=await send(route,method,body,session,csrf);
  assert.equal(result.status,status,method+' '+route+' unexpected HTTP '+result.status+' / '+result.data.error);
  return result.data;
}
(async()=>{
  const unauth=await send('tour-library');
  check(unauth.status===401 && unauth.data.error==='AUTH_REQUIRED',
      'unauthenticated library request returns 401');
  const wrong=await send('auth/login','POST',{email:'tester@example.invalid',password:'invalid-fixture-password'});
  check(wrong.status===401 && wrong.data.error==='INVALID_CREDENTIALS',
      'incorrect credentials are rejected');
  const owner=await login('tester@example.invalid');
  check(owner.companyId===1,'company 1 owner receives an authenticated session');

  const original={
    title:'V5 HTTP Hanoi and Ha Long',destination:'Hanoi, Ha Long',language:'en',
    tags:['Culture','Cruise'],status:'ACTIVE',
    days:[
      {day:1,date:'',title:'Hanoi arrival',description:'Old Quarter',
        meals:'L/D',overnight:'Hanoi',notes:'Internal guide note'},
      {day:2,date:'',title:'Ha Long',description:'Bay cruising',
        meals:'B/L/D',overnight:'Ha Long',notes:''}
    ],
    included_text:'Private guide',excluded_text:'Flights',
    terms_text:'Reviewed payment terms',source_name:'source-itinerary.txt',
    source_type:'TEXT',source_text:'Day 1 Hanoi original text',
    proposal:{
      tour_code:'V5-HTTP-001',tour_type:'PRIVATE',
      overview:'Source proposal',highlights:['Hanoi culture'],
      group_prices:[{hotel:'3',price:'350',single:'80'}],
      private_prices:[{min:2,max:2,three:'550',four:'650',five:'750'},
                      {min:3,max:4,three:'500',four:'600',five:'700'}],
      hotels:[],policies:{children:'',payment:'Deposit',cancellation:'Review',notes:''}
    }
  };
  const badCsrf=await send('tour-library','POST',original,owner);
  check(badCsrf.status===419 && badCsrf.data.error==='CSRF_FAILED',
      'POST without CSRF rejected at HTTP boundary');
  const saved=await expected('tour-library','POST',original,owner,201);
  const sourceId=Number(saved.id);
  assert(sourceId>0,'Source library save needs a numeric ID');
  const source=(await expected('tour-library/'+sourceId,'GET',undefined,owner,200)).program;
  check(source.id===sourceId && source.source_type==='TEXT' &&
        source.source_text.includes('Hanoi original text'),
      'real HTTP POST→GET retains original source provenance');

  // Exactly the V5 UI Save-as-Template payload contract.
  const clone={...source,title:source.title+' (Template)',
    tags:[...new Set([...(source.tags||[]),'VTA_TEMPLATE'])],
    status:'DRAFT',source_text:'',source_name:'',source_type:'MANUAL',source_url:''};
  delete clone.id;delete clone.creation_key;delete clone.import_token;delete clone.has_source;
  clone.creation_key='v5-http-fixture-creation-key';
  const made=await expected('tour-library','POST',clone,owner,201);
  const templateId=Number(made.id);
  check(templateId>0 && templateId!==sourceId,
      'Save-as-Template creates an independent program through HTTP POST');
  const replay=await expected('tour-library','POST',clone,owner,201);
  check(Number(replay.id)===templateId && replay.replayed===true,
      'retrying template POST with same creation key replays the same program ID');
  const ownerList=await expected('tour-library','GET',undefined,owner,200);
  check(ownerList.items.filter(p=>p.title===clone.title).length===1,
      'template POST retry creates no duplicate library row');
  const template=(await expected('tour-library/'+templateId,'GET',undefined,owner,200)).program;
  check(template.status==='DRAFT' && template.tags.includes('VTA_TEMPLATE') &&
        template.source_name==='' && template.source_text==='' &&
        template.source_type==='MANUAL' && template.days.length===2,
      'HTTP GET reopens DRAFT template with scrubbed provenance and itinerary');
  check(JSON.stringify(template.proposal)===JSON.stringify(source.proposal) &&
        template.included_text===source.included_text,
      'reopened template retains approved proposal, prices and public terms');

  const edit={...template,title:'V5 HTTP Independently edited template',
      days:template.days.map((d,i)=>i?d:{...d,description:'Changed clone only'})};
  const changed=await expected('tour-library/'+templateId,'PUT',edit,owner,200);
  check(Number(changed.id)===templateId,'HTTP PUT updates only existing template ID');
  const updated=(await expected('tour-library/'+templateId,'GET',undefined,owner,200)).program;
  const originalAgain=(await expected('tour-library/'+sourceId,'GET',undefined,owner,200)).program;
  check(updated.days[0].description==='Changed clone only' &&
        originalAgain.days[0].description===source.days[0].description &&
        originalAgain.source_text===source.source_text,
      'HTTP PUT→GET preserves independent edit and original imported text');

  const invalid={...updated,proposal:{...updated.proposal,
    private_prices:updated.proposal.private_prices.map((r,i)=>i?{...r,min:2}:r)}};
  const invalidResult=await expected('tour-library/'+templateId,'PUT',invalid,owner,422);
  check(invalidResult.error==='VALIDATION','overlapping passenger bands rejected by HTTP API');
  const postValidation=(await expected('tour-library/'+templateId,'GET',undefined,owner,200)).program;
  check(postValidation.days[0].description==='Changed clone only' &&
        postValidation.proposal.private_prices[1].min===3,
      'validation failure does not overwrite persisted template');

  const viewer=await login('viewer@example.invalid');
  check(viewer.companyId===1 && (await expected('tour-library/'+templateId,'GET',undefined,viewer,200)).program.id===templateId,
      'read-only role can read same-company template');
  const denied=await expected('tour-library','POST',clone,viewer,403);
  check(denied.error==='FORBIDDEN','read-only role cannot create templates');
  check((await expected('tour-library/'+templateId,'PUT',edit,viewer,403)).error==='FORBIDDEN',
      'read-only role cannot update template');

  const foreign=await login('other@example.invalid');
  check(foreign.companyId===2 &&
        (await expected('tour-library/'+templateId,'GET',undefined,foreign,404)).error==='NOT_FOUND',
      'other company cannot read template even with admin role');
  check((await expected('tour-library/'+templateId,'PUT',edit,foreign,404)).error==='NOT_FOUND',
      'other company cannot update template even with admin role');
  const foreignList=await expected('tour-library','GET',undefined,foreign,200);
  check(!foreignList.items.some(p=>p.id===templateId||p.id===sourceId),
      'other company list does not disclose owner library programs');

  const postDenial=(await expected('tour-library/'+templateId,'GET',undefined,owner,200)).program;
  check(postDenial.title==='V5 HTTP Independently edited template' &&
        postDenial.source_text==='' &&
        postDenial.days[0].description==='Changed clone only',
      'CSRF/ACL negative attempts preserve program state');
  console.log('RC6.2 V5 authenticated HTTP/API/MariaDB Save→Reopen acceptance PASS (disposable CI ONLY).');
})().catch(err=>{console.error('FAIL V5 HTTP:',err.message);process.exitCode=1;});

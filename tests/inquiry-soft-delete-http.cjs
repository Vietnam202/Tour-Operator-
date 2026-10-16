'use strict';
// Actual PHP bootstrap, sessions/CSRF and synthetic disposable MariaDB. No live host.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const {spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),php=process.env.VTA_TEST_PHP||'php';
if(!process.env.VTA_TEST_DB_PASSWORD)throw Error('Disposable MariaDB credentials required');
const privateDir=fs.mkdtempSync(path.join(os.tmpdir(),'vta-inquiry-test-'));
const env={...process.env,VTA_INQUIRY_TEST_PASSWORD:'synthetic-inquiry-only-2026',VTA_CONFIG_FILE:path.join(privateDir,'config.php')};
let database,servers=[],passed=0;
function fixture(mode,args=[],input){const r=spawnSync(php,[path.join(__dirname,'inquiry-soft-delete-fixture.php'),mode,...args],{env,encoding:'utf8',input:input&&JSON.stringify(input),timeout:120000});if(r.status!==0)throw Error(r.stderr||r.stdout||String(r.error));return JSON.parse(r.stdout)}
function sql(q,args=[]){return fixture('sql',[database],{sql:q,args})}
function check(ok,label){assert(ok,label);passed++;console.log('PASS '+label)}
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function request(session,route,method='GET',body,token=session?.csrf){
 const port=session?.port||18872,headers={'Accept':'application/json'};
 if(session?.cookie)headers.Cookie=session.cookie;
 if(token)headers['X-VTA-CSRF']=token;
 if(body!==undefined)headers['Content-Type']='application/json';
 const [r,q]=route.split('?');
 const res=await fetch(`http://127.0.0.1:${port}/api/index.php?route=${encodeURIComponent(r)}${q?'&'+q:''}`,{method,headers,signal:AbortSignal.timeout(15000),...(body===undefined?{}:{body:JSON.stringify(body)})});
 const raw=await res.text();let data;try{data=JSON.parse(raw)}catch{throw Error('Non-JSON '+res.status+' '+raw.slice(0,300))}
 return {status:res.status,data,cookie:res.headers.get('set-cookie')?.split(';')[0]};
}
async function expect(s,route,status,method='GET',body,token){const r=await request(s,route,method,body,token===undefined?s?.csrf:token);assert.equal(r.status,status,`${method} ${route}: ${JSON.stringify(r.data)}`);return r.data}
async function login(email,port=18872){const r=await request({port},'auth/login','POST',{email,password:env.VTA_INQUIRY_TEST_PASSWORD});assert.equal(r.status,200,JSON.stringify(r.data));return{port,cookie:r.cookie,csrf:r.data.user.csrf}}
async function row(s,id,archived=false){return(await expect(s,`inquiries?archived=${archived?1:0}&id=${id}`,200)).items[0]}
async function newInquiry(s,label){return expect(s,'inquiries',201,'POST',{title:label,lead_contact_name:'Synthetic contact',adults:6,foc:1,total_guests:7,paying_pax:6,start_date:'2027-01-02',end_date:'2027-01-05',next_action_due:'2026-01-01 09:00:00'})}
async function change(s,id,action,version,status=200){return expect(s,`inquiries/${id}/${action}`,status,'POST',{expected_version_no:version})}
(async()=>{
 try{
  const seed=fixture('seed',[privateDir]);database=seed.database;console.log('MariaDB '+seed.server+'; '+seed.migrations+' migrations; disposable '+database);
  for(const port of [18872,18873]){const child=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{env,stdio:['ignore','ignore','pipe'],windowsHide:true});child.stderr.on('data',()=>{});servers.push(child)}
  let ready=false;for(let n=0;n<40;n++){try{ready=(await request(null,'health')).status===200;if(ready)break}catch{}await delay(150)}
  assert(ready,'Local PHP fixture startup');
  const [a,b,viewer,a2]=await Promise.all([login('a@example.invalid'),login('b@example.invalid'),login('viewer@example.invalid'),login('a@example.invalid',18873)]);
  const created=await newInquiry(a,'VIE15 draft preservation'),id=created.id;
  await expect(null,`inquiries/${id}/archive`,401,'POST',{expected_version_no:1});
  await expect(a,`inquiries/${id}/archive`,419,'POST',{expected_version_no:1},'invalid');
  await change(viewer,id,'archive',1,403);await expect(viewer,'inquiries?archived=1',403);
  await change(b,id,'archive',1,404);await change(a,9999999,'archive',1,404);
  await expect(a,'inquiries?archived=all',422);
  check((await row(a,id)).deleted_at===null,'401/419/403/404 and tenant protection preserve inquiry');
  const quote=await expect(a,`inquiries/${id}/create-quote`,201,'POST',{});
  const qv=sql('SELECT id FROM quote_versions WHERE quote_id=?',[quote.id])[0].id;
  const preserved=()=>Object.fromEntries(['trips','quotes','quote_versions','bookings','leads'].map(t=>[t,sql(`SELECT * FROM ${t} ORDER BY id`)]));
  const before=preserved(),active=await row(a,id),version=Number(active.version_no);
  await expect(a,`inquiries/${id}/archive`,422,'POST',{});
  await change(a,id,'archive',version+1,409);
  await change(a,id,'archive',version);
  check(!(await row(a,id)) && (await row(a,id,true)).deleted_by===1,'Archive hides active row and records actor in trash');
  assert.deepEqual(preserved(),before);check(true,'Archive leaves trip, draft quote/version, bookings and leads byte-for-byte unchanged');
  await change(a,id,'archive',version,409);
  await expect(a,`inquiries/${id}`,409,'PUT',{status:'WORKING'});
  await expect(a,`inquiries/${id}/create-quote`,409,'POST',{});
  for(const action of ['approve','send','confirm','create-booking']){
   const denial=await expect(a,`quotes/${quote.id}/${action}`,409,'POST',{});
   check(denial.error==='INQUIRY_ARCHIVED'||String(denial.message||'').includes('INQUIRY_ARCHIVED'),`Archived inquiry blocks quote ${action}`);
  }
  for(const [route,method,body] of [
   [`quote-versions/${qv}`,'PUT',{tour_name:'Should not change'}],
   [`quote-versions/${qv}/cost-items`,'POST',{category:'OTHER',service_name:'Should not change'}],
   [`quote-versions/${qv}/options`,'POST',{label:'Should not change'}],
  ]){
   const denial=await expect(a,route,409,method,body);
   check(String(denial.message||'').includes('INQUIRY_ARCHIVED')||denial.error==='INQUIRY_ARCHIVED',`Archived inquiry blocks ${method} ${route}`);
  }
  assert.deepEqual(preserved(),before);
  const events=sql("SELECT action_code FROM audit_logs WHERE entity_type='inquiry' AND entity_id=? AND action_code IN ('INQUIRY_ARCHIVED','INQUIRY_RESTORED')",[id]);
  check(events.length===1,'Duplicate archive rejected without duplicate audit');
  const trash=await row(a,id,true);await change(a,id,'restore',Number(trash.version_no));
  check(!!await row(a,id) && !(await row(a,id,true)),'Restore returns same inquiry to active list');
  await change(a,id,'archive',version,409);
  check(!!await row(a,id),'Old archive request cannot re-delete a restored inquiry');
  assert.deepEqual(preserved(),before);check(true,'Restore preserves linked draft data');
  const foreign=await newInquiry(b,'Tenant B');
  await change(b,foreign.id,'archive',Number((await row(b,foreign.id)).version_no));
  check(!(await expect(a,'inquiries?archived=1',200)).items.some(x=>Number(x.id)===Number(foreign.id)),'Trash listing remains tenant scoped');
  for(const status of ['APPROVED','SENT','CONFIRMED','SUPERSEDED']){
   sql('UPDATE quote_versions SET version_status=? WHERE id=?',[status,qv]);
   await change(a,id,'archive',Number((await row(a,id)).version_no),409);
  }
  sql("UPDATE quote_versions SET version_status='DRAFT' WHERE id=?",[qv]);
  sql("UPDATE quotes SET status='SENT' WHERE id=?",[quote.id]);await change(a,id,'archive',Number((await row(a,id)).version_no),409);
  sql("UPDATE quotes SET status='DRAFT' WHERE id=?",[quote.id]);
  check(true,'Approved/SENT/confirmed/historical version and quote header block archive');
  const plain=await newInquiry(a,'Followup lifecycle');
  const dashBefore=await expect(a,'dashboard',200),homeBefore=await expect(a,'workspace/home',200);
  await change(a,plain.id,'archive',Number((await row(a,plain.id)).version_no));
  const dashAfter=await expect(a,'dashboard',200),homeAfter=await expect(a,'workspace/home',200);
  check(dashAfter.stats.inquiries_open===dashBefore.stats.inquiries_open-1,'Dashboard open KPI excludes trash');
  check(homeAfter.metrics.sales_followups_due===homeBefore.metrics.sales_followups_due-1,'Workspace follow-up KPI excludes trash');
  check(sql("SELECT * FROM tasks WHERE entity_type='inquiry' AND entity_id=? AND status IN ('OPEN','SNOOZED')",[plain.id]).length===0,'Archive closes automatic follow-up');
  await change(a,plain.id,'restore',Number((await row(a,plain.id,true)).version_no));
  check(sql("SELECT * FROM tasks WHERE entity_type='inquiry' AND entity_id=? AND status IN ('OPEN','SNOOZED')",[plain.id]).length===1,'Restore recreates one follow-up');
  const race=await newInquiry(a,'Concurrent archive');const rv=Number((await row(a,race.id)).version_no);
  const raceResults=await Promise.all([request(a,`inquiries/${race.id}/archive`,'POST',{expected_version_no:rv}),request(a2,`inquiries/${race.id}/archive`,'POST',{expected_version_no:rv})]);
  assert.deepEqual(raceResults.map(r=>r.status).sort(),[200,409]);
  check(sql("SELECT * FROM audit_logs WHERE entity_type='inquiry' AND entity_id=? AND action_code='INQUIRY_ARCHIVED'",[race.id]).length===1,'Two PHP processes: simultaneous archive has one winner and one audit');
  // Failure after row/task updates must roll back the entire transaction.
  const atomic=await newInquiry(a,'Audit failure atomicity');const old=sql('SELECT * FROM inquiries WHERE id=?',[atomic.id]);
  sql("CREATE TRIGGER v15_fail_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action_code='INQUIRY_ARCHIVED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");
  await change(a,atomic.id,'archive',Number((await row(a,atomic.id)).version_no),500);
  sql('DROP TRIGGER v15_fail_audit');assert.deepEqual(sql('SELECT * FROM inquiries WHERE id=?',[atomic.id]),old);
  check(sql("SELECT * FROM tasks WHERE entity_type='inquiry' AND entity_id=? AND status='OPEN'",[atomic.id]).length===1,'Audit failure rolls back inquiry and task changes');
  console.log(`VIE15 HTTP/MariaDB: ${passed} checks PASS. Synthetic fixture only; no CyberPanel UAT claimed.`);
 }finally{for(const s of servers)s.kill();if(database&&!process.env.VTA_KEEP_TEST_DB)fixture('drop',[database]);fs.rmSync(privateDir,{recursive:true,force:true})}
})().catch(e=>{console.error(e.stack);process.exitCode=1});

'use strict';
const assert=require('node:assert/strict');
const base=(process.env.VTA_V5_HTTP_URL||'http://127.0.0.1:18869')+'/api/index.php';
if(base!=='http://127.0.0.1:18869/api/index.php'||!process.env.VTA_V5_TEST_PASSWORD)throw Error('Disposable HTTP fixture required');
async function session(email){let cookie='',csrf='';return async(route,method='GET',body)=>{const headers={};if(cookie)headers.Cookie=cookie;if(csrf)headers['X-VTA-CSRF']=csrf;if(body)headers['Content-Type']='application/json';const r=await fetch(base+'?route='+encodeURIComponent(route),{method,headers,body:body?JSON.stringify(body):undefined});const raw=r.headers.get('set-cookie');if(raw)cookie=raw.split(';')[0];const b=await r.json();if(b.user?.csrf)csrf=b.user.csrf;return {status:r.status,body:b};};}
(async()=>{
 const admin=await session(),other=await session(),viewer=await session();
 for(const [call,email] of [[admin,'tester@example.invalid'],[other,'other@example.invalid'],[viewer,'viewer@example.invalid']])assert.equal((await call('auth/login','POST',{email,password:process.env.VTA_V5_TEST_PASSWORD})).status,200);
 const masters=await admin('tour-library');const master=masters.body.items.find(p=>p.status!=='ARCHIVED');assert(master);
 const create={master_program_id:master.id,creation_key:'b2c-http-create-fixture-0001'};
 const a=await admin('b2c','POST',create);assert.equal(a.status,201);const id=a.body.program.id;
 assert.equal((await admin('b2c','POST',create)).body.program.id,id);
 assert.equal((await other('b2c/'+id+'/document')).status,404);
 assert.equal((await viewer('b2c/'+id+'/document')).status,200);
 assert.equal((await viewer('b2c','POST',create)).status,403);
 const doc=await admin('b2c/'+id+'/document');assert.equal(doc.status,200);
 const body={expected_revision:doc.body.costing_revision,settings:doc.body.settings};
 assert.equal((await viewer('b2c/'+id+'/document','PUT',body)).status,403);
 assert.equal((await admin('b2c/'+id+'/document','PUT',{...body,expected_revision:0})).status,409);
 const saved=await admin('b2c/'+id+'/document','PUT',body);assert.equal(saved.status,200);assert.equal(saved.body.program.revision,2);
 assert.equal((await admin('b2c/'+id+'/publish','POST',{expected_revision:2})).status,409);
 console.log('PASS B2C authenticated HTTP: create/retry/read/reopen, stale revision, readonly and cross-company denial, unapproved publish blocked');
})().catch(e=>{console.error(e);process.exitCode=1;});

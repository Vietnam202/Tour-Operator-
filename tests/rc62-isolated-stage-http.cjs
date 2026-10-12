'use strict';
/** Disposable CI only: real PHP bootstrap/MariaDB, simulated TLS flag.
 * This does NOT test live HTTPS, CyberPanel or an existing-data database.
 */
const assert=require('node:assert/strict');
const http=require('node:http');
const path=require('node:path');
const fs=require('node:fs');
const {spawn}=require('node:child_process');
const host='staging-fixture.example.invalid';
const fixtureUrl='http://127.0.0.1:18869';
const env=process.env;
const configSource=env.VTA_V5_HTTP_CONFIG||'';
if(env.GITHUB_ACTIONS!=='true'||env.VTA_V5_HTTP_URL!==fixtureUrl
 ||env.VTA_V5_HTTP_PORT!=='18869'||!env.VTA_TEST_DB_PASSWORD
 ||!env.VTA_V5_TEST_PASSWORD||env.VTA_V5_TEST_PASSWORD.length<12
 ||!configSource.endsWith('/config.php')||!fs.existsSync(configSource))
 throw Error('BLOCK_STAGE_HTTP_DISPOSABLE_CI_ONLY');
const root=path.resolve(__dirname,'..');
const privateDir=path.dirname(configSource),privateConfig=path.join(privateDir,'stage-config.php');
const router=path.join(privateDir,'stage-router.php'),mediaDir=path.join(privateDir,'staging-documents');
assert(fs.existsSync(privateConfig)&&fs.existsSync(router)&&fs.existsSync(mediaDir));
assert(!privateDir.startsWith(root+path.sep),'fixture config must be outside checkout');
assert(env.VTA_RC62_STAGING_HOST_PIN===host
 &&/^vta_test_[a-f0-9]{10}$/.test(env.VTA_RC62_STAGING_DB_PIN||'')
 &&env.VTA_RC62_STAGING_STORAGE_PIN===fs.realpathSync(mediaDir),'all synthetic fixture pins required');
const servers=[];
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function start(port,pins){
 const proc=spawn('php',['-S','127.0.0.1:'+port,'-t',root,router],{
   cwd:root,env:{...env,VTA_CONFIG_FILE:privateConfig,...pins},
   stdio:['ignore','ignore','ignore']});
 servers.push(proc);
 return proc;
}
function query(port,route,opts={}){
 return new Promise((resolve,reject)=>{
   const body=opts.body===undefined?null:JSON.stringify(opts.body);
   const headers={
     Host:opts.host||host,
     Accept:'application/json',
     ...(body===null?{}:{'Content-Type':'application/json','Content-Length':Buffer.byteLength(body)}),
     ...(opts.cookie?{Cookie:opts.cookie}:{}),
     ...(opts.origin?{Origin:opts.origin}:{}),
     ...(opts.forwarded?{'X-Forwarded-Host':opts.forwarded}:{}),
     ...(opts.scheme?{'X-Forwarded-Proto':opts.scheme}:{})
   };
   const req=http.request({hostname:'127.0.0.1',port,
     path:'/api/index.php?route='+encodeURIComponent(route.split('?')[0])+
        (route.includes('?')?'&'+route.split('?').slice(1).join('?'):''),
     method:body===null?'GET':'POST',headers,timeout:5000},res=>{
     const parts=[];let bytes=0;
     res.on('data',part=>{bytes+=part.length;
       if(bytes>512000){req.destroy(Error('FIXTURE_HTTP_RESPONSE_TOO_LARGE'));return;}
       parts.push(part);
     });
     res.on('end',()=>{
       const raw=Buffer.concat(parts).toString('utf8');
       let value=null;try{value=JSON.parse(raw)}catch{}
       resolve({status:res.statusCode,data:value,headers:res.headers});
     });
   });
   req.on('timeout',()=>req.destroy(Error('FIXTURE_HTTP_TIMEOUT')));
   req.on('error',reject);
   if(body!==null)req.write(body);
   req.end();
 });
}
async function ready(port){
 for(let i=0;i<45;i++){
   try{
     const r=await query(port,'health');
     if([200,503].includes(r.status))return r;
   }catch{}
   await sleep(120);
 }
 throw Error('STAGE_BOOTSTRAP_FIXTURE_NOT_READY');
}
function code(r,status,label){
 assert.equal(r.status,status,label+' HTTP='+r.status);
 if(status===503)assert.equal(r.data?.error,'UNSAFE_STAGING_CONFIGURATION',label);
 console.log('PASS STAGE HTTP '+label);
}
(async()=>{
 const port=18870;
 start(port,{});
 await ready(port);
 const accepted=await query(port,'health');
 code(accepted,200,'real PHP bootstrap accepts three-pinned isolated host with fixture MariaDB');
 assert.equal(accepted.data?.ok,true);
 code(await query(port,'health',{host:'quote.vietnamtraveladvisor.com.vn'}),503,
   'production Host cannot route a pinned isolated staging configuration');
 code(await query(port,'health',{host:host+':443'}),503,
   'noncanonical Host with port denied');
 code(await query(port,'health',{origin:'https://untrusted.example.invalid'}),403,
   'origin rejected after RuntimeGuard bootstrap');
 const spoof=await query(port,'health',{forwarded:'quote.vietnamtraveladvisor.com.vn',scheme:'http'});
 code(spoof,200,'X-Forwarded Host/Proto never override pinned Host/HTTPS server context');
 code(await query(port,'auth/me'),401,'anonymous users denied private auth/me');
 code(await query(port,'b2b/me'),401,'anonymous users denied B2B portal');
 const noPinPort=18871;
 start(noPinPort,{VTA_RC62_STAGING_DB_PIN:''});
 await ready(noPinPort);
 code(await query(noPinPort,'health'),503,
   'missing independently pinned clone DB fails before real DB/health route');
 const noStoragePort=18872;
 start(noStoragePort,{VTA_RC62_STAGING_STORAGE_PIN:''});
 await ready(noStoragePort);
 code(await query(noStoragePort,'health'),503,
   'missing independent private media pin fails before real DB/health route');

 async function login(email){
   const r=await query(port,'auth/login',{body:{email,password:env.VTA_V5_TEST_PASSWORD}});
   code(r,200,'fixture real PHP login session');
   const cookie=r.headers['set-cookie']?.[0]?.split(';')[0];
   assert(cookie&&r.data?.user?.id,'real login must set a session cookie');
   return cookie;
 }
 // Login has actual audit + last_login_at writes, but ONLY in disposable vta_test_*.
 const a=await login('partner-a@example.invalid'),b=await login('partner-b@example.invalid');
 const meA=await query(port,'b2b/me',{cookie:a}),meB=await query(port,'b2b/me',{cookie:b});
 code(meA,200,'Partner A authenticated on isolated pinned application bootstrap');
 code(meB,200,'Partner B authenticated on isolated pinned application bootstrap');
 const aid=Number(meA.data.agencies?.[0]?.id),bid=Number(meB.data.agencies?.[0]?.id);
 assert(aid>0&&bid>0&&aid!==bid,'two active synthetic agencies required');
 code(await query(port,'b2b/kpi?agency_id='+bid,{cookie:a}),404,
   'Partner A cannot read Partner B KPI using forged ID');
 code(await query(port,'b2b/kpi?agency_id='+aid,{cookie:b}),404,
   'Partner B cannot read Partner A KPI using forged ID');
 code(await query(port,'b2b/kpi?agency_id='+aid,{cookie:a}),200,
   'Partner A KPI allowed for own agency');
 console.log('PASS STAGE HTTP disposable MariaDB+PHP isolated bootstrap and Partner A/B contract');
 console.log('PASS_CI_FIXTURE_SIMULATED_HTTPS_ONLY; release_authorized=false');
})().catch(e=>{console.error('FAIL_CI_STAGE_HTTP '+String(e.message||e).slice(0,200));process.exitCode=1})
.finally(async()=>{for(const proc of servers)proc.kill('SIGTERM');await sleep(100)});

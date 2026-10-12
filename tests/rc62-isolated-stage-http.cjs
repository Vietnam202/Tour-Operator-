'use strict';
/** Disposable CI only: verified TLS (ephemeral CA) -> strict local proxy ->
 * real PHP bootstrap/MariaDB. NOT live CyberPanel, public CA, or a customer DB.
 */
const assert=require('node:assert/strict');
const http=require('node:http');
const https=require('node:https');
const {randomBytes}=require('node:crypto');
const path=require('node:path');
const fs=require('node:fs');
const {spawn,spawnSync}=require('node:child_process');
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
const servers=[],proxies=[];
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const proxyToken=randomBytes(32).toString('hex');
const certFile=path.join(privateDir,'ci-stage-tls.pem');
const keyFile=path.join(privateDir,'ci-stage-tls-key.pem');
const generated=spawnSync('openssl',['req','-x509','-newkey','rsa:2048','-sha256',
  '-nodes','-days','1','-subj','/CN='+host,
  '-addext','subjectAltName=DNS:'+host,
  '-keyout',keyFile,'-out',certFile],{stdio:'ignore',timeout:15000});
assert.equal(generated.status,0,'ephemeral TLS certificate generation failed');
fs.chmodSync(keyFile,0o600);
const localCert=fs.readFileSync(certFile);
const localKey=fs.readFileSync(keyFile);
function start(port,pins){
 const proc=spawn('php',['-S','127.0.0.1:'+port,'-t',root,router],{
   cwd:root,env:{...env,VTA_CONFIG_FILE:privateConfig,
     VTA_STAGE_CI_PROXY_TOKEN:proxyToken,...pins},
   stdio:['ignore','ignore','ignore']});
 servers.push(proc);
 // Terminate actual client TLS here, then forward to loopback PHP. Strip ALL
 // user-supplied forwarding headers. Inject an unpredictable private header
 // only at this trusted boundary. PHP's CI-only router will refuse direct HTTP.
 const front=https.createServer({key:localKey,cert:localCert,minVersion:'TLSv1.2'},
   (incoming,outgoing)=>{
     const headers={...incoming.headers};
     for(const name of ['x-vta-ci-tls-token','x-forwarded-host','x-forwarded-proto',
       'forwarded','x-forwarded-for','x-forwarded-port'])
       delete headers[name];
     headers['x-vta-ci-tls-token']=proxyToken;
     const upstream=http.request({hostname:'127.0.0.1',port,
       method:incoming.method,path:incoming.url,headers},up=>{
         outgoing.writeHead(up.statusCode,up.headers);up.pipe(outgoing);
       });
     upstream.on('error',()=>{
       if(!outgoing.headersSent)outgoing.writeHead(502,{'Content-Type':'application/json'});
       outgoing.end('{"ok":false,"error":"CI_PROXY_UPSTREAM_UNAVAILABLE"}');
     });
     incoming.pipe(upstream);
   });
 front.on('tlsClientError',()=>{}); // Expected for plaintext probes; never log cert/private data.
 front.listen(port+3,'127.0.0.1');
 proxies.push(front);
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
   const req=https.request({hostname:'127.0.0.1',port:port+3,
     servername:opts.servername||host,
     ca:opts.ca===undefined?localCert:opts.ca,
     rejectUnauthorized:true,
     minVersion:'TLSv1.2',
     path:'/api/index.php?route='+encodeURIComponent(route.split('?')[0])+
        (route.includes('?')?'&'+route.split('?').slice(1).join('?'):''),
     method:body===null?'GET':'POST',headers,timeout:5000},res=>{
     const tlsVerified=res.socket?.authorized===true;
     const negotiatedProtocol=res.socket?.getProtocol?.();
     const parts=[];let bytes=0;
     res.on('data',part=>{bytes+=part.length;
       if(bytes>512000){req.destroy(Error('FIXTURE_HTTP_RESPONSE_TOO_LARGE'));return;}
       parts.push(part);
     });
     res.on('end',()=>{
       const raw=Buffer.concat(parts).toString('utf8');
       let value=null;try{value=JSON.parse(raw)}catch{}
       resolve({status:res.statusCode,data:value,headers:res.headers,
         tlsVerified,negotiatedProtocol});
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
 code(accepted,200,'verified self-signed TLS -> proxy -> real PHP bootstrap with three pins');
 assert.equal(accepted.tlsVerified,true,'client must validate certificate chain and SAN');
 assert(['TLSv1.2','TLSv1.3'].includes(accepted.negotiatedProtocol));
 console.log('PASS STAGE TLS verified TLSv1.2+ and exact fixture DNS SAN, no insecure override');
 // Untrusted certificate and wrong DNS SAN MUST fail before even contacting PHP.
 await assert.rejects(query(port,'health',{ca:Buffer.from('not-an-authorized-CA')}),
   /.+/,'untrusted fixture CA unexpectedly accepted');
 await assert.rejects(query(port,'health',{servername:'wrong-stage.example.invalid'}),
   /.+/,'wrong TLS server identity unexpectedly accepted');
 console.log('PASS STAGE TLS untrusted CA and wrong DNS certificate identity refused');
 // Direct cleartext HTTP MUST fail at the router (even with forged forwarded
 // headers), and plaintext at the TLS listener MUST NOT produce health 200.
 const direct=await new Promise((resolve,reject)=>{
   http.get({host:'127.0.0.1',port,path:'/api/index.php?route=health',headers:{
     Host:host,'X-Forwarded-Proto':'https','X-VTA-CI-TLS-Token':'forged-by-client'}},
    response=>{
      let raw='';response.on('data',x=>raw+=x.toString());
      response.on('end',()=>resolve({status:response.statusCode,data:JSON.parse(raw)}));
    }).on('error',reject);
 });
 code(direct,503,'direct HTTP cannot assert a TLS security context to PHP router');
 await assert.rejects(new Promise((resolve,reject)=>{
   const req=http.get({host:'127.0.0.1',port:port+3,path:'/api/index.php?route=health',
     timeout:1500},response=>{
       response.resume();resolve(response.statusCode);
     });
   req.on('timeout',()=>req.destroy(Error('plaintext TLS probe timed out')));
   req.on('error',reject);
 }),/./,'plaintext HTTP unexpectedly handled as TLS');
 console.log('PASS STAGE TLS unencrypted HTTP cannot enter HTTPS listener');
 assert.equal(accepted.data?.ok,true);
 code(await query(port,'health',{host:'quote.vietnamtraveladvisor.com.vn'}),503,
   'production Host cannot route a pinned isolated staging configuration');
 code(await query(port,'health',{host:host+':443'}),503,
   'noncanonical Host with port denied');
 code(await query(port,'health',{origin:'https://untrusted.example.invalid'}),403,
   'origin rejected after RuntimeGuard bootstrap');
 const spoof=await query(port,'health',{forwarded:'quote.vietnamtraveladvisor.com.vn',scheme:'http'});
 code(spoof,200,'proxy strips forged X-Forwarded Host/Proto at TLS termination');
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
 console.log('PASS_CI_VERIFIED_EPHEMERAL_TLS_PROXY_FIXTURE_ONLY; release_authorized=false');
})().catch(e=>{console.error('FAIL_CI_STAGE_HTTP '+String(e.message||e).slice(0,200));process.exitCode=1})
.finally(async()=>{
 for(const front of proxies){front.closeAllConnections();front.close();}
 for(const proc of servers)proc.kill('SIGTERM');
 await sleep(100);
});

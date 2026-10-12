'use strict';
/**
 * Disposable CI only: actual TLS, nginx, PHP-FPM and MariaDB.
 * Never connects to real DNS or CyberPanel. 127.0.0.1 is hard-coded.
 * Ephemeral test certificate is verified using a private CI trust anchor.
 */
const assert=require('node:assert/strict');
const https=require('node:https');
const fs=require('node:fs');
const host='staging-fixture.example.invalid';
const env=process.env;
if(env.GITHUB_ACTIONS!=='true'||env.VTA_RC62_TLS_TARGET!==host||
   env.VTA_V5_HTTP_URL!=='http://127.0.0.1:18869'||
   !env.VTA_V5_TEST_PASSWORD||env.VTA_V5_TEST_PASSWORD.length<12||
   !env.VTA_RC62_TLS_CA||!fs.existsSync(env.VTA_RC62_TLS_CA)||
   !/^vta_test_[a-f0-9]{10}$/.test(env.VTA_RC62_STAGING_DB_PIN||''))
  throw Error('BLOCK_TLS_FPM_DISPOSABLE_ONLY');
const ca=fs.readFileSync(env.VTA_RC62_TLS_CA);
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
function request(port,route,{method='GET',body,hostHeader=host,
  origin,forwarded,servername=host,trustAnchor=ca,cookie}={}){
  return new Promise((resolve,reject)=>{
    let verified=false;
    const payload=body===undefined?null:JSON.stringify(body);
    const query=route.split('?');
    const path='/api/index.php?route='+encodeURIComponent(query[0])+
      (query.length>1?'&'+query.slice(1).join('?'):'');
    const headers={Host:hostHeader,Accept:'application/json',
      ...(payload===null?{}:{'Content-Type':'application/json',
        'Content-Length':Buffer.byteLength(payload)}),
      ...(cookie?{Cookie:cookie}:{}),
      ...(origin?{Origin:origin}:{}),
      ...(forwarded?{'X-Forwarded-Host':'quote.vietnamtraveladvisor.com.vn',
        'X-Forwarded-Proto':'http'}:{})};
    const req=https.request({hostname:'127.0.0.1',port,servername,
      rejectUnauthorized:true,ca:trustAnchor,method,headers,path,timeout:8000},
    res=>{
      verified=!!res.socket?.authorized;
      const negotiatedProtocol=res.socket?.getProtocol()||null;
      const chunks=[];let total=0;
      res.on('data',b=>{total+=b.length;
        if(total>512000){req.destroy(Error('BLOCK_TLS_BODY_TOO_LARGE'));return;}
        chunks.push(b);
      });
      res.on('end',()=>{
        let data=null;
        try{data=JSON.parse(Buffer.concat(chunks).toString('utf8'))}catch{}
        resolve({status:res.statusCode,data,headers:res.headers,verified,
          protocol:negotiatedProtocol});
      });
    });
    req.on('error',reject);
    req.on('timeout',()=>req.destroy(Error('BLOCK_TLS_REQUEST_TIMEOUT')));
    if(payload!==null)req.write(payload);
    req.end();
  });
}
function match(r,expected,message){
  assert.equal(r.status,expected,message+' status');
  assert.equal(r.verified,true,message+' strict TLS');
  assert(['TLSv1.2','TLSv1.3'].includes(r.protocol),message+' protocol');
  if(expected===503)assert.equal(r.data?.error,'UNSAFE_STAGING_CONFIGURATION');
  console.log('PASS TLS/FPM '+message);
}
(async()=>{
  let health;
  for(let n=0;n<45;n++){
    try{health=await request(18443,'health');if(health.status===200)break;}catch{}
    await sleep(150);
  }
  assert(health && health.status===200,'TLS nginx->FPM backend must be ready');
  match(health,200,'verified certificate SAN/SNI and real nginx->PHP-FPM health');
  assert(health.data?.ok===true && health.data?.service==='VTA API');
  assert(/no-store/i.test(health.headers['cache-control']||''));
  await assert.rejects(()=>request(18443,'health',{servername:'wrong-staging.example.invalid'}),
    /ERR_TLS_CERT_ALTNAME_INVALID|Hostname\/IP does not match certificate/);
  console.log('PASS TLS/FPM wrong SNI/certificate hostname rejected');
  await assert.rejects(()=>request(18443,'health',{trustAnchor:Buffer.from('')}),/certificate|self.signed|verify/i);
  console.log('PASS TLS/FPM no trusted test CA certificate rejected');
  match(await request(18443,'health',{hostHeader:'quote.vietnamtraveladvisor.com.vn'}),
    503,'TLS with forbidden production HTTP Host blocked by RuntimeGuard');
  match(await request(18443,'health',{hostHeader:host+':18443'}),
    503,'TLS with noncanonical Host port blocked');
  match(await request(18443,'health',{origin:'https://attacker.example.invalid'}),
    403,'cross-origin request blocked after real PHP bootstrap');
  match(await request(18443,'health',{forwarded:true}),200,
    'spoofed X-Forwarded-Host/Proto not trusted by nginx->PHP-FPM');
  match(await request(18443,'auth/me'),401,'anonymous staff user rejected');
  match(await request(18443,'b2b/me'),401,'anonymous partner rejected');
  match(await request(18444,'health'),503,'HTTPS connection without DB pin rejected');
  async function login(email){
    const r=await request(18443,'auth/login',
      {method:'POST',body:{email,password:env.VTA_V5_TEST_PASSWORD}});
    match(r,200,'actual PHP-FPM session login on disposable MariaDB');
    const raw=r.headers['set-cookie']?.[0]||'';
    assert(/;\s*Secure\b/i.test(raw),'Secure cookie must be set');
    assert(/;\s*HttpOnly\b/i.test(raw),'HttpOnly cookie must be set');
    assert(/;\s*SameSite=Strict\b/i.test(raw),'SameSite=Strict cookie must be set');
    const cookie=raw.split(';')[0];
    assert(cookie.includes('='),'auth session cookie required');
    return cookie;
  }
  const a=await login('partner-a@example.invalid');
  const b=await login('partner-b@example.invalid');
  const meA=await request(18443,'b2b/me',{cookie:a});
  const meB=await request(18443,'b2b/me',{cookie:b});
  match(meA,200,'actual TLS Partner A session');
  match(meB,200,'actual TLS Partner B session');
  const aid=Number(meA.data?.agencies?.[0]?.id);
  const bid=Number(meB.data?.agencies?.[0]?.id);
  assert(aid>0&&bid>0&&aid!==bid,'fixture agencies must be distinct');
  match(await request(18443,'b2b/kpi?agency_id='+bid,{cookie:a}),404,
    'A denied B private KPI over validated TLS');
  match(await request(18443,'b2b/kpi?agency_id='+aid,{cookie:b}),404,
    'B denied A private KPI over validated TLS');
  match(await request(18443,'b2b/kpi?agency_id='+aid,{cookie:a}),200,
    'A permitted own KPI over TLS');
  console.log('PASS_CI_GENUINE_TLS_NGINX_PHP_FPM_DISPOSABLE_MARIADB; release_authorized=false');
})().catch(e=>{
  console.error('FAIL_CI_TLS_FPM '+String(e?.code||e?.message||'UNKNOWN').slice(0,160));
  process.exitCode=1;
});

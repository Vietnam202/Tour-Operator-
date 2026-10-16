'use strict';
// All requests below are handled by a local fake fetch. NEVER contacts staging.
const assert=require('node:assert/strict');
const {checkStage,validate}=require('./rc62-stage-b2c-readonly.cjs');
const {APPROVAL}=require('./rc62-staging-readonly-smoke.cjs');
const host='staging-fixture.example.invalid';
const origin='https://'+host;
const cfg={base:origin+'/',hostPin:host,sha:'a'.repeat(40),
  inventorySha:'b'.repeat(64),approval:APPROVAL,
  approvedPublication:'101',withdrawnPublication:'102'};
function reply(status,content,headers={}){
  return new Response(typeof content==='string'?content:JSON.stringify(content),{
    status,headers:{'cache-control':'no-store',...headers}});
}
function server(mode='normal'){
  const calls=[];
  const fake=async (uri,options)=>{
    const u=new URL(uri),route=u.searchParams.get('route')||'';
    calls.push({origin:u.origin,pathname:u.pathname,route,method:options.method});
    assert.equal(u.origin,origin,'never contact any other host');
    assert.equal(options.method,'GET','no writes permitted by this smoke');
    assert.equal(options.redirect,'manual');
    if(u.pathname==='/api/index.php'&&route==='health'){
      if(options.headers.Origin)return reply(403,{error:'ORIGIN_NOT_ALLOWED'});
      return reply(200,{ok:true,service:'VTA API',
        build:{status:'PINNED_PACKAGE',
          source_commit:mode==='wrong_sha'?'c'.repeat(40):cfg.sha,
          source_inventory_sha256:cfg.inventorySha,source_file_count:255}});
    }
    if(['auth/me','b2b/me','b2b/admin/agencies'].includes(route))
      return reply(401,{error:'AUTH_REQUIRED'});
    if(u.pathname==='/journeys.php'&&u.searchParams.has('utm_source')){
      const html='<link rel="canonical" href="'+origin+'/journeys.php">'+
        '<meta property="og:url" content="'+origin+'/journeys.php">'+
        '<article class="journey-card"><h2><a href="journeys.php?publication=101&amp;utm_source=rc62-stage-smoke">Tour</a></h2></article>';
      return reply(200,mode==='list_leak'?html+' net_per_pax ':html,
        {'content-type':'text/html; charset=utf-8'});
    }
    if(u.pathname==='/journeys.php'&&u.searchParams.get('publication')==='101'){
      let html='<link rel="canonical" href="'+origin+'/journeys.php?publication=101">'+
        '<article itemscope itemtype="https://schema.org/Service">'+
        '<img src="api/index.php?route=b2c-public-image/101/55">'+
        '<form data-vta-b2c-inquiry data-api="api/index.php?route=b2c-storefront/101/inquiry" '+
        'data-csrf="'+('d'.repeat(64))+'">'+
        '<input name="consent" type="checkbox" required>'+
        '<input name="travel_date_end"><input name="total_guests"></form></article>';
      if(mode==='no_consent')html=html.replace('name="consent"','name="ignored"');
      return reply(200,html,{'content-type':'text/html; charset=utf-8'});
    }
    if(u.pathname==='/journeys.php'&&u.searchParams.get('publication')==='102')
      return reply(mode==='still_live'?200:404,
        mode==='still_live'?'not withdrawn':'unavailable',
        {'content-type':'text/html'});
    if(route==='b2c-public-image/101/55')
      return reply(mode==='missing_image'?404:200,'synthetic-png-image-bytes',
        {'content-type':'image/png'});
    if(route==='b2c-public-image/102/55')return reply(404,'gone');
    if(route==='b2c-storefront/101/inquiry')return reply(405,'method blocked');
    throw Error('Unexpected fake request: '+u.pathname+' '+route);
  };
  return {fake,calls};
}
async function blocked(config,mode,reason){
  const {fake}=server(mode);
  await assert.rejects(()=>checkStage(config,fake),e=>e.message===reason,
    'expected '+reason);
  process.stdout.write('PASS_CI '+reason+'\n');
}
(async()=>{
  assert.throws(()=>validate({...cfg,base:'https://v2quote.vietnamtraveladvisor.com.vn/',
    hostPin:'v2quote.vietnamtraveladvisor.com.vn'}),
    e=>e.message==='BLOCK_STAGING_HOST_NOT_INDEPENDENTLY_PINNED');
  assert.throws(()=>validate({...cfg,withdrawnPublication:'101'}),
    e=>e.message==='BLOCK_B2C_INDEPENDENT_PUBLICATION_PINS_REQUIRED');
  assert.throws(()=>validate({...cfg,approvedPublication:'not-an-id'}),
    e=>e.message==='BLOCK_B2C_APPROVED_PUBLICATION_PIN_REQUIRED');
  const {fake,calls}=server();
  const report=await checkStage(cfg,fake);
  assert.equal(report.status,'PASS_STAGING_B2C_GET_SMOKE');
  assert.equal(report.release_authorized,false);
  assert.equal(report.source_commit,cfg.sha);
  assert.equal(report.checks.length,6);
  assert(calls.every(c=>c.method==='GET'&&c.origin===origin));
  assert(calls.some(c=>c.route==='b2c-storefront/101/inquiry'));
  assert(!JSON.stringify(report).includes(host));
  process.stdout.write('PASS_CI B2C GET-only exact-SHA/UTM/SEO/consent/image/withdrawal contract (fixture)\n');
  await blocked(cfg,'wrong_sha','BLOCK_STAGING_BUILD_IDENTITY_MISMATCH');
  await blocked(cfg,'list_leak','BLOCK_B2C_PRIVATE_FIELD_EXPOSURE');
  await blocked(cfg,'no_consent','BLOCK_B2C_CONSENT_FORM');
  await blocked(cfg,'still_live','BLOCK_B2C_HTTP_CONTRACT');
  await blocked(cfg,'missing_image','BLOCK_B2C_HTTP_CONTRACT');
  process.stdout.write('NO real HTTPS host, MariaDB, inquiry or production touched; fixture only.\n');
})().catch(e=>{process.stderr.write('FAIL_CI '+e.message+'\n');process.exitCode=1;});

#!/usr/bin/env node
'use strict';
/*
 * VIE-5 / VIE-11: operator-initiated native B2C staging GET smoke.
 * Run ONLY against independently pinned, isolated HTTPS staging.
 * No publication, inquiry, login, migration, quote or booking POSTs.
 * GET journeys.php creates a PHP session/CSRF nonce on the server;
 * it is not a zero-side-effect database/storage forensic read.
 * Synthetic CI mocks are NOT real staging acceptance.
 */
const {guard,runCheck,APPROVAL}=require('./rc62-staging-readonly-smoke.cjs');
const MAX_BYTES=512000;
function requirePin(value,label){
  const n=Number(value);
  if(!Number.isSafeInteger(n)||n<1||String(n)!==String(value))
    throw Error('BLOCK_B2C_'+label+'_PIN_REQUIRED');
  return n;
}
function validate(config){
  const origin=guard({...config,auth:false});
  const approved=requirePin(config.approvedPublication,'APPROVED_PUBLICATION');
  const withdrawn=requirePin(config.withdrawnPublication,'WITHDRAWN_PUBLICATION');
  if(approved===withdrawn)throw Error('BLOCK_B2C_INDEPENDENT_PUBLICATION_PINS_REQUIRED');
  return {origin,approved,withdrawn};
}
function check(ok,reason){if(!ok)throw Error(reason);}
async function bounded(response){
  const header=Number(response.headers.get('content-length')||0);
  check(Number.isFinite(header)&&header>=0&&header<=MAX_BYTES,'BLOCK_B2C_RESPONSE_TOO_LARGE');
  const reader=response.body?.getReader();
  if(!reader){
    const raw=await response.arrayBuffer();
    check(raw.byteLength<=MAX_BYTES,'BLOCK_B2C_RESPONSE_TOO_LARGE');
    return Buffer.from(raw);
  }
  const chunks=[];let total=0;
  try{
    for(;;){
      const part=await reader.read();if(part.done)break;
      total+=part.value.byteLength;
      check(total<=MAX_BYTES,'BLOCK_B2C_RESPONSE_TOO_LARGE');
      chunks.push(Buffer.from(part.value));
    }
  }finally{reader.releaseLock();}
  return Buffer.concat(chunks,total);
}
async function checkStage(config,fetchImpl=globalThis.fetch){
  const pins=validate(config);
  // Reuse the existing exact-SHA, independently pinned source inventory,
  // anonymous API/CORS/HTTPS checks before touching public B2C pages.
  await runCheck({...config,auth:false},fetchImpl);
  const origin=pins.origin,checks=['EXACT_SHA_HEALTH_GUARD'];
  const url=path=>new URL(path,origin+'/');
  async function get(path,status,type='text/html'){
    const target=url(path);
    check(target.origin===origin,'BLOCK_B2C_CROSS_ORIGIN_REQUEST');
    const res=await fetchImpl(target.href,{method:'GET',redirect:'manual',
      cache:'no-store',headers:{Accept:type==='image/'?'image/*':'text/html'},
      signal:AbortSignal.timeout(12000)});
    check(res.status<300||res.status>=400,'BLOCK_B2C_REDIRECT');
    check(res.status===status,'BLOCK_B2C_HTTP_CONTRACT');
    if(status===200){
      const mime=res.headers.get('content-type')||'';
      check(type==='image/'?/^image\/(png|jpeg|webp|gif)\b/i.test(mime)
        :/^text\/html\b/i.test(mime),'BLOCK_B2C_CONTENT_TYPE');
      check(/no-store/i.test(res.headers.get('cache-control')||''),
        'BLOCK_B2C_CACHE_CONTROL');
    }
    const bytes=await bounded(res);
    return bytes.toString('utf8');
  }
  const list=await get('/journeys.php?utm_source=rc62-stage-smoke',200);
  check(list.includes('class="journey-card"')&&
    list.includes('utm_source=rc62-stage-smoke')&&
    list.includes('publication='),'BLOCK_B2C_CATALOGUE_OR_UTM');
  check(list.includes('rel="canonical" href="'+origin+'/journeys.php"')&&
    list.includes('property="og:url"'),'BLOCK_B2C_CATALOGUE_SEO');
  check(!/\b(?:net_per_pax|supplier_id|profit_margin|markup_rate|password_hash|api_key)\b/i.test(list),
    'BLOCK_B2C_PRIVATE_FIELD_EXPOSURE');
  checks.push('CATALOGUE_UTM_SEO_PRIVATE_FIELD_DENIAL');
  const detail=await get('/journeys.php?publication='+pins.approved,200);
  check(detail.includes('rel="canonical" href="'+origin+
    '/journeys.php?publication='+pins.approved+'"')&&
    detail.includes('itemscope')&&detail.includes('https://schema.org/Service'),
    'BLOCK_B2C_DETAIL_SEO');
  check(detail.includes('data-vta-b2c-inquiry')&&
    detail.includes('b2c-storefront/'+pins.approved+'/inquiry')&&
    /data-csrf="[a-f0-9]{64}"/.test(detail)&&
    detail.includes('name="consent" type="checkbox" required')&&
    detail.includes('name="travel_date_end"')&&
    detail.includes('name="total_guests"'),
    'BLOCK_B2C_CONSENT_FORM');
  check(!/\b(?:net_per_pax|supplier_id|profit_margin|markup_rate|password_hash|api_key)\b/i.test(detail),
    'BLOCK_B2C_PRIVATE_FIELD_EXPOSURE');
  checks.push('APPROVED_DETAIL_SEO_CONSENT_NO_INTERNAL_FIELDS');
  const image=detail.match(/src="api\/index\.php\?route=b2c-public-image\/(\d+)\/(\d+)"/);
  check(image&&Number(image[1])===pins.approved&&Number(image[2])>0,
    'BLOCK_B2C_APPROVED_IMAGE_REQUIRED');
  await get('/api/index.php?route=b2c-public-image/'+pins.approved+'/'+image[2],200,'image/');
  checks.push('APPROVED_PUBLIC_IMAGE');
  await get('/journeys.php?publication='+pins.withdrawn,404);
  await get('/api/index.php?route=b2c-public-image/'+pins.withdrawn+'/'+image[2],404);
  checks.push('WITHDRAWN_DETAIL_AND_IMAGE_DENIED');
  // GET must not accept an inquiry. Never submit a real lead in this smoke.
  await get('/api/index.php?route=b2c-storefront/'+pins.approved+'/inquiry',405);
  checks.push('GET_INQUIRY_METHOD_DENIED_NO_LEAD_CREATED');
  return {status:'PASS_STAGING_B2C_GET_SMOKE',source_commit:config.sha,
    checks,release_authorized:false,
    requires:['VIE-6 existing-data backup/clone/rollback',
      'real-consent POST/Lead Hub UAT separately approved',
      'supplier-approved cost/FOC and B2B ACL',
      '50 real DOCX/PDF, Android PWA, owner signoff']};
}
function configFromEnv(env=process.env){
  return {base:env.VTA_STAGE_URL,hostPin:env.VTA_STAGE_APPROVED_HOST,
    sha:env.VTA_STAGE_APPROVED_SHA,
    inventorySha:env.VTA_STAGE_APPROVED_SOURCE_INVENTORY_SHA256,
    approval:env.VTA_STAGE_READONLY_APPROVAL,
    approvedPublication:env.VTA_STAGE_B2C_APPROVED_PUBLICATION_ID,
    withdrawnPublication:env.VTA_STAGE_B2C_WITHDRAWN_PUBLICATION_ID};
}
if(require.main===module){
  checkStage(configFromEnv()).then(report=>process.stdout.write(JSON.stringify(report)+'\n'),
    error=>{const code=String(error?.message||'BLOCK_B2C_UNEXPECTED');
      process.stderr.write((/^BLOCK_[A-Z0-9_]+$/.test(code)?code:'BLOCK_B2C_UNEXPECTED')+'\n');
      process.exitCode=2;});
}
module.exports={validate,checkStage,configFromEnv};

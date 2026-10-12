'use strict';
// RC6.2 PWA cache-upgrade behavioral regression. No browser, network, auth or staging dependencies.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../service-worker.js'),'utf8');
const SCOPE='https://v2quote.vietnamtraveladvisor.com.vn/';
const CACHE='vta-RC62-COST-SAVE-2-PWA';
const OLD='vta-RC62-SMART-P1-1';
const asset=new URL('./tour-proposal-studio.js?v=V5-4-RECOVERY',SCOPE).href;
const offlinePage=new URL('./offline.html',SCOPE).href;
const costAsset=new URL('./cost-sheet.js?v=COST-SAVE-1',SCOPE).href;
const stores=new Map([
 [OLD,new Map([[asset,'STALE-V5-OLD'],[costAsset,'STALE-COST'],[offlinePage,'STALE-OFFLINE']])],
 ['foreign-app-cache',new Map([[asset,'FOREIGN-UNREVIEWED'],[costAsset,'FOREIGN-COST'],[offlinePage,'FOREIGN-OFFLINE']])]
]);
const events={};
let claimCount=0,globalMatchCount=0,skipWaitingCount=0,networkCalls=0,offline=false;
const caches={
 open:async key=>{
  if(!stores.has(key))stores.set(key,new Map());
  const store=stores.get(key);
  return {
   addAll:async urls=>{for(const item of urls)store.set(new URL(item,SCOPE).href,'FRESH:'+new URL(item,SCOPE).href);},
   match:async request=>store.get(typeof request==='string'?request:request.url)
  };
 },
 keys:async()=>[...stores.keys()],
 delete:async key=>stores.delete(key),
 match:async()=>{globalMatchCount++;throw Error('Cross-cache read forbidden');}
};
const self={
 registration:{scope:SCOPE},location:{origin:new URL(SCOPE).origin},
 clients:{claim:async()=>{claimCount++;}},
 skipWaiting(){skipWaitingCount++;},
 addEventListener(name,listener){events[name]=listener;}
};
const fetch=async(request,opts)=>{
 networkCalls++;
 if(offline)throw Error('network unavailable');
 if(opts?.cache==='no-store')return 'NETWORK-NO-STORE';
 return 'NETWORK-UNMANAGED';
};
vm.runInNewContext(source,{self,caches,fetch,URL,Response,console},{filename:'service-worker.js'});
assert.deepEqual(Object.keys(events).sort(),['activate','fetch','install','message']);
async function lifecycle(type) {
 let promise;
 events[type]({waitUntil(p){promise=p;}});
 assert(promise,'missing waitUntil for '+type);
 await promise;
}
function request(url,{method='GET',mode='cors'}={}) {
 let answered;
 events.fetch({request:{url,method,mode},respondWith(p){answered=p;}});
 return answered;
}
(async()=>{
 assert.match(source,/const CACHE='vta-RC62-COST-SAVE-2-PWA'/);
 assert(source.includes("'./cost-sheet.js?v=COST-SAVE-1'"),'current Cost editor must be precached');
 for(const page of ['index.html','preview.html']) {
  const html=fs.readFileSync(path.join(__dirname,'..',page),'utf8');
  assert(html.includes('cost-sheet.js?v=COST-SAVE-1'),page+' must request the updated Cost editor');
 }
 await lifecycle('install');
 assert(stores.has(CACHE),'new version must have dedicated cache');
 assert.equal(stores.get(CACHE).get(asset),'FRESH:'+asset);
 assert.equal(stores.get(CACHE).get(costAsset),'FRESH:'+costAsset);
 // Old and unrelated caches exist; a scoped lookup must never return them.
 assert.equal(await request(asset),'FRESH:'+asset,'current assets must come only from current-version cache');
 assert.equal(await request(costAsset),'FRESH:'+costAsset,'cost editor must not be served from old/foreign cache');
 assert.equal(globalMatchCount,0,'never globally scan caches for allowlisted assets');
 await lifecycle('activate');
 assert.equal(stores.has(OLD),false,'activation removes stale VTA cache');
 assert.equal(stores.has('foreign-app-cache'),true,'activation must not delete unrelated application cache');
 assert.equal(claimCount,1,'new worker claims controlled clients');
 assert.equal(await request(asset),'FRESH:'+asset);
 // Navigation is network-first; offline fallback is strictly scoped to current cache.
 offline=true;
 assert.equal(await request(SCOPE+'index.html',{mode:'navigate'}),'FRESH:'+offlinePage);
 assert.equal(globalMatchCount,0,'offline fallback must not use global caches');
 // Missing offline shell yields a safe 503 instead of an undefined Response.
 stores.get(CACHE).delete(offlinePage);
 const fallback=await request(SCOPE+'index.html',{mode:'navigate'});
 assert.equal(fallback.status,503);
 assert.equal(fallback.headers.get('content-type'),'text/plain; charset=utf-8');
 // Private resources, API, exports, non-GET, other origins never get intercepted.
 const noIntercept=[
  [SCOPE+'api/index.php?route=tour-library',{}],
  [SCOPE+'api/index.php?route=public-proposals/example',{}],
  [SCOPE+'uploads/private.jpg',{}],
  [SCOPE+'other.js',{}],
  [SCOPE+'cost-sheet.js?v=3.4.0-VS23-UX',{}],
  [asset,{method:'POST'}],
  ['https://external.invalid/service.js',{}]
 ];
 for(const [url,options] of noIntercept)assert.equal(request(url,options),undefined,'unsafe interception: '+url);
 assert.equal(globalMatchCount,0);
 events.message({data:{type:'ACTIVATE_UPDATE'}});
 assert.equal(skipWaitingCount,1);
 assert(networkCalls>=2,'network-first navigations exercised');
 console.log('PASS PWA V5 cache rotation, scope isolation, offline fallback, privacy and API bypass');
})().catch(error=>{console.error(error);process.exitCode=1;});

const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const base=path.join(__dirname,'..'),scope='https://localhost/vta/',events={},deleted=[];let precached=[],fetches=0,offline=false;
const priorCache='vta-os-'+fs.readFileSync(path.join(base,'VERSION'),'utf8').trim();
const releasedCache='vta-os-3.4.0-RC6.1-VS0';
const ctx={URL,self:{registration:{scope},location:{origin:'https://localhost'},addEventListener:(k,f)=>events[k]=f,clients:{claim:async()=>{}},skipWaiting(){}},caches:{open:async()=>({addAll:async urls=>precached=urls}),keys:async()=>['unrelated-app','vta-os-old',releasedCache,priorCache,priorCache+'-VS1',priorCache+'-VS1.1'],delete:async k=>deleted.push(k),match:async r=>typeof r==='string'&&r.endsWith('offline.html')?'OFFLINE':undefined},fetch:async()=>{fetches++;if(offline)throw Error('offline');return 'NETWORK';}};
vm.runInNewContext(fs.readFileSync(path.join(base,'service-worker.js'),'utf8'),ctx);
function event(name,data={}){let result;events[name]({...data,waitUntil:p=>result=p,respondWith:p=>result=p});return result;}
(async()=>{
 await event('install');for(const p of precached)assert(fs.existsSync(path.join(base,p.split('?')[0])));assert(!precached.some(x=>/api\/|index.html|preview.html/.test(x)));console.log('PASS all precache assets exist; app HTML and API absent');
 for(const [url,method,mode] of [['https://localhost/vta/api/?route=ai/threads','GET','cors'],['https://localhost/vta/api/?route=ai/threads','POST','cors'],['https://other.test/data','GET','cors'],[scope+'customer-export.csv','GET','cors']])assert.equal(event('fetch',{request:{url,method,mode}}),undefined);console.log('PASS API, writes, cross-origin and arbitrary exports are not intercepted');
 assert.equal(await event('fetch',{request:{url:scope,method:'GET',mode:'navigate'}}),'NETWORK');offline=true;assert.equal(await event('fetch',{request:{url:scope,method:'GET',mode:'navigate'}}),'OFFLINE');console.log('PASS navigation always uses network with generic offline fallback');
 await event('activate');assert.deepEqual(deleted,['vta-os-old',releasedCache,priorCache,priorCache+'-VS1']);console.log('PASS VS1.1 cache activation removes released VS0 and draft VS1 assets and preserves current/unrelated apps');
 const manifest=JSON.parse(fs.readFileSync(path.join(base,'manifest.json')));assert.equal(manifest.display,'standalone');for(const icon of manifest.icons){const png=fs.readFileSync(path.join(base,icon.src));const size=Number(icon.sizes.split('x')[0]);assert.equal(png.readUInt32BE(16),size);assert.equal(png.readUInt32BE(20),size);}console.log('PASS install manifest and PNG icon dimensions');
 console.log('NOTE: service worker simulation; browser installation requires device testing.');
})().catch(e=>{console.error(e);process.exitCode=1;});

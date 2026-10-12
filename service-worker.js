'use strict';
const CACHE='vta-RC62-V5-B2C-WORD-1-PWA';
const CORE=['./styles.css?v=3.4.0-RC6.2','./app-platform.css?v=3.4.0-RC6.2','./social-inbox.css?v=RC62-INBOX-P1','./operations-center.css?v=3.4.0-RC6.2','./tour-library.css?v=3.4.0-RC6.2','./tour-proposal-studio.css?v=V5-4-RECOVERY','./workspace-centers.css?v=3.4.0-RC6.2','./rc6-shell.css?v=3.4.0-RC6.2','./ai-drawer.css?v=3.4.0-RC6.2','./smart-costing.css?v=3.4.0-VS23-UX','./vta-cost-editor.css?v=DEMO-PARITY-1','./visual-proposal.css?v=3.4.0-VS23-UX','./document-editor.css?v=RC2-WORD-1','./commercial-matrix.css?v=RC6-GROUP-1','./vta-ui-refresh.css?v=RC62-UI2','./vta-approved-ui.css?v=APPROVED1','./vta-approved-library.css?v=RC62-LIB3','./vta-approved-workspaces.css?v=RC62-WS1','./unified-workspace.css?v=UNIFIED-1','./smart-enhancements.css?v=P1-1','./i18n.js?v=PROGRAM-1','./ai-chat.js?v=3.4.0-RC6.2','./platform.js?v=3.4.0-RC6.2','./social-inbox.js?v=RC62-MARKETING-UI-1','./operations-demo.js?v=3.4.0-RC6.2','./operations-center.js?v=3.4.0-VS23-CONFIRM','./tour-library-demo.js?v=PROGRAM-1','./tour-library.js?v=DEMO-PARITY-1','./tour-proposal-studio.js?v=V5-4-RECOVERY','./workspace-demo.js?v=3.4.0-RC6.2','./workspace-centers.js?v=3.4.0-RC6.2','./cost-sheet.js?v=COST-SAVE-1','./vta-cost-editor.js?v=COST-SAVE-2','./smart-costing.js?v=3.4.0-VS23-CONFIRM','./visual-proposal.js?v=3.4.0-VS23-UX-P0.2','./assets/document-engine.js?v=PROGRAM-1','./document-editor.js?v=WORD-SOURCE-1','./tour-program-workspace.js?v=PROGRAM-1','./tour-program-workspace.css?v=PROGRAM-1','./commercial-matrix.js?v=RC6-GROUP-1','./handover.js?v=3.4.0-VS23-CONFIRM','./quote-confirmation.js?v=3.4.0-VS24.1','./booking-operations.js?v=3.4.0-VS24.1','./unified-workspace.js?v=UNIFIED-1','./unified-demo.js?v=PROGRAM-1','./smart-enhancements.js?v=P1-1','./app.js?v=PROGRAM-1','./assets/document-pdf.js?v=RC2-WORD-1','./assets/document-pdf-worker.js?v=RC2-WORD-1','./offline.html','./assets/brand-logo.png','./assets/icon-192.png','./assets/icon-512.png','./vta-plus-assist.js?v=PLUS1','./vta-plus-assist.css?v=PLUS1','./b2c-studio.js?v=B2C2','./b2c-demo.js?v=B2C1'];
const ALLOWED=new Set(CORE.map(x=>new URL(x,self.registration.scope).href));
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(CORE))));
self.addEventListener('message',e=>{if(e.data?.type==='ACTIVATE_UPDATE')self.skipWaiting();});
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('vta-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 const u=new URL(e.request.url);
 if(e.request.method!=='GET'||u.origin!==self.location.origin)return;
 // API, customer exports, arbitrary URLs and private records must never enter the cache.
 if(u.pathname.includes('/api/')||u.pathname.endsWith('/journeys.php'))return;
 if(e.request.mode==='navigate'){
  e.respondWith(fetch(e.request,{cache:'no-store'}).catch(async()=>{
   const cache=await caches.open(CACHE);
   return (await cache.match(new URL('./offline.html',self.registration.scope).href))
    || new Response('Offline',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8'}});
  }));return;
 }
 // Never read unrelated caches: an old/foreign response might share the same URL.
 if(ALLOWED.has(u.href))e.respondWith(caches.open(CACHE).then(cache=>cache.match(e.request)).then(cached=>cached||fetch(e.request)));
});

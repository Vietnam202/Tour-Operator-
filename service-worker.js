'use strict';
const CACHE='vta-RC62-WS1';
const CORE=['./vta-approved-library.css?v=RC62-LIB3','./vta-approved-workspaces.css?v=RC62-WS1','./vta-ui-refresh.css?v=RC62-UI2','./tour-proposal-studio.js?v=V5-2-TPL','./tour-proposal-studio.css?v=V5-1','./vta-cost-editor.js?v=RC6-COST-1','./vta-cost-editor.css?v=RC6-COST-1','./assets/document-pdf.js?v=RC2-WORD-1','./assets/document-pdf-worker.js?v=RC2-WORD-1','./assets/document-engine.js?v=RC2-WORD-1','./document-editor.js?v=RC2-WORD-1','./document-editor.css?v=RC2-WORD-1','./booking-operations.js?v=3.4.0-VS24.1','./quote-confirmation.js?v=3.4.0-VS24.1','./cost-sheet.js?v=3.4.0-VS23-UX','./commercial-matrix.js?v=3.4.0-VS2.3','./commercial-matrix.css?v=3.4.0-VS2.3','./handover.js?v=3.4.0-VS23-CONFIRM','./visual-proposal.js?v=3.4.0-VS23-UX-P0.2','./visual-proposal.css?v=3.4.0-VS23-UX','./smart-costing.js?v=3.4.0-VS23-CONFIRM','./smart-costing.css?v=3.4.0-VS23-UX','./ai-drawer.css?v=3.4.0-RC6.2','./workspace-demo.js?v=3.4.0-RC6.2','./workspace-centers.js?v=3.4.0-RC6.2','./workspace-centers.css?v=3.4.0-RC6.2','./rc6-shell.css?v=3.4.0-RC6.2','./tour-library-demo.js?v=3.4.0-RC6.2','./tour-library.js?v=RC62-LIB3','./tour-library.css?v=3.4.0-RC6.2','./operations-center.js?v=3.4.0-VS23-CONFIRM','./operations-demo.js?v=3.4.0-RC6.2','./operations-center.css?v=3.4.0-RC6.2','./landing-extensions.js?v=3.4.0-RC6.2','./landing-model.js?v=3.4.0-RC6.2','./landing-builder.js?v=3.4.0-RC6.2','./landing-builder.css?v=3.4.0-RC6.2','./marketing-model.js?v=3.4.0-RC6.2','./marketing-demo.js?v=3.4.0-RC6.2','./marketing-studio.js?v=3.4.0-RC6.2','./marketing-studio.css?v=3.4.0-RC6.2','./offline.html','./styles.css?v=3.4.0-RC6.2','./app-platform.css?v=3.4.0-RC6.2','./app.js?v=3.4.0-RC6.2-VS24.1-OPERATIONS','./ai-chat.js?v=3.4.0-RC6.2','./platform.js?v=3.4.0-RC6.2','./marketing.js?v=3.4.0-RC6.2','./i18n.js?v=3.4.0-RC6.2','./assets/brand-logo.png','./assets/icon-192.png','./assets/icon-512.png'];
const ALLOWED=new Set(CORE.map(x=>new URL(x,self.registration.scope).href));
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(CORE))));
self.addEventListener('message',e=>{if(e.data?.type==='ACTIVATE_UPDATE')self.skipWaiting();});
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('vta-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 const u=new URL(e.request.url);
 if(e.request.method!=='GET'||u.origin!==self.location.origin)return;
 // API, customer exports, arbitrary URLs and private records must never enter the cache.
 if(u.pathname.includes('/api/'))return;
 if(e.request.mode==='navigate'){
  e.respondWith(fetch(e.request,{cache:'no-store'}).catch(()=>caches.match(new URL('./offline.html',self.registration.scope).href)));return;
 }
 if(ALLOWED.has(u.href))e.respondWith(caches.match(e.request).then(cached=>cached||fetch(e.request)));
});

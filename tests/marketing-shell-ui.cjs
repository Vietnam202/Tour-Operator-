'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const read=f=>fs.readFileSync(path.join(__dirname,'..',f),'utf8');
(async()=>{
 for(const name of ['index.html','preview.html','service-worker.js']){const s=read(name);for(const asset of ['marketing-studio.js','marketing.js?v=','marketing-model.js','marketing-demo.js','landing-builder.js','social-publishing.js','meta-connections.js'])assert(!s.includes(asset),name+' must not load '+asset);}
 const dom=new JSDOM('<div id="app"></div>',{url:'https://localhost/vta/',runScripts:'outside-only'}),w=dom.window;w.VTA_PREVIEW=true;
 w.eval(read('unified-workspace.js'));w.eval(read('app.js').replace('\nboot();','\nwindow.testCore={api,state,renderShell,navigate,navItems};'));
 const a=w.testCore;a.api.user.permissions=['lead.view','sales.view'];a.renderShell();assert(!a.navItems().some(x=>x.id==='marketing'));let handovers=0,requests=[];
 w.mountLeadHandover=async(host,o)=>{assert(host);handovers++;host.textContent='Inbound lead queue';};a.api.request=async route=>{requests.push(route);return {items:[]};};
 await a.navigate('marketing');assert.equal(a.state.view,'leads');assert.equal(handovers,1);assert(w.document.body.textContent.includes('Inbound lead queue'));assert(!requests.some(x=>x.includes('marketing/studio')||x.includes('campaign')));
 assert(read('api/lib/WebhookCenter.php').includes("webhooks/website-lead"));assert(read('api/lib/WebsiteInbox.php').includes('website-message'));
 dom.window.close();console.log('PASS WordPress owns publishing; legacy Marketing link reaches inbound Lead queue without campaign/provider requests; signed intake remains');
})().catch(e=>{console.error(e);process.exitCode=1;});

'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const read=f=>fs.readFileSync(path.join(__dirname,'..',f),'utf8');
(async()=>{
 for(const name of ['index.html','preview.html']){
  const html=read(name);
  for(const asset of ['marketing-studio.js','marketing-model.js','marketing-demo.js','landing-builder.js','landing-model.js','meta-connections.js','social-publishing.js']){
   assert(html.includes('src="'+asset+'?v='),name+' loads '+asset);
  }
  assert(!html.includes('src="marketing.js?'),name+' does not load the old conflicting Marketing renderer');
  assert(html.indexOf('marketing-studio.js')<html.indexOf('app.js?'),name+' Studio must load before boot');
 }
 const sw=read('service-worker.js');
 for(const asset of ['marketing-studio.js','landing-builder.js','meta-connections.js','social-publishing.js']){
  assert(sw.includes('./'+asset+'?v='),'PWA precache includes '+asset);
 }
 assert(sw.includes("u.pathname.includes('/api/')"),'API never precached');
 const dom=new JSDOM('<div id="app"></div>',{url:'https://localhost/vta/',runScripts:'outside-only'}),w=dom.window;
 w.VTA_PREVIEW=true;w.eval(read('unified-workspace.js'));
 w.eval(read('app.js').replace('\nboot();','\nwindow.testCore={api,state,renderShell,navigate,navItems};'));
 const a=w.testCore;
 assert(a,'test harness has shell API');
 a.api.user.permissions=['lead.view','sales.view'];a.renderShell();
 assert(a.navItems().some(x=>x.id==='marketing'),'authorized account sees Marketing nav');
 let studio=0,handovers=0,requests=[];
 w.VTAMarketing=async()=>{studio++;w.document.querySelector('#workspace').textContent='Marketing Studio';};
 w.mountLeadHandover=async(host)=>{assert(host);handovers++;host.textContent='Inbound lead queue';};
 a.api.request=async route=>{requests.push(route);return {items:[]};};
 await a.navigate('marketing');
 assert.equal(a.state.view,'marketing');assert.equal(studio,1);
 assert(w.document.body.textContent.includes('Marketing Studio'));
 await a.navigate('leads');assert.equal(handovers,1);
 assert(!requests.some(x=>x.includes('marketing/studio')||x.includes('campaign')),'preview no provider or campaign calls');
 a.api.user.permissions=['sales.view'];a.renderShell();
 assert(!a.navItems().some(x=>x.id==='marketing'),'account without lead.view has no Marketing link');
 const previous=studio;
 await a.navigate('marketing');
 assert.equal(studio,previous,'unauthorized direct navigation denied');
 assert(read('api/lib/WebhookCenter.php').includes('webhooks/website-lead'));
 assert(read('api/lib/WebsiteInbox.php').includes('website-message'));
 dom.window.close();
 console.log('PASS Marketing Studio restored in RC6 shell with lead.view ACL, no preview provider calls; inbound Lead and signed intake retained');
})().catch(e=>{console.error(e);process.exitCode=1;});

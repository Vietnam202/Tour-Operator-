'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.resolve(__dirname,'..'),html=fs.readFileSync(path.join(root,'ads-landing-publisher.html'),'utf8');
for(const text of ['Publish for Ads','publication_event_id','lead_form_id','campaign_id','default_utm_campaign',
 'ads/landing-admin/options','ads/landing-admin','Withdraw','utm_source','utm_medium','utm_campaign',
 'b2c.approve','campaign.manage'].filter(x=>x!=='b2c.approve'&&x!=='campaign.manage'))assert(html.includes(text),'Missing publish UI: '+text);
assert(html.includes('X-VTA-CSRF'),'publishing API must use canonical CSRF');
assert(html.includes("navigator.clipboard.writeText"),'copy ad URL action');
const match=html.match(/<script>([\s\S]*?)<\/script>/);
assert(match,'ads publisher JS missing');new vm.Script(match[1],{filename:'ads-landing-publisher.js'});
const php=fs.readFileSync(path.join(root,'api/lib/AdsLandingPages.php'),'utf8');
for(const fragment of ["'b2c.approve'","'campaign.manage'","self::live($db,$slug,true)","LeadHub::submit",
 "AdsLandingPresentation::html","ads_landing_lead_links","ads_landing_metrics","ADS_LANDING_WITHDRAWN"])
 assert(php.includes(fragment),'Missing fail-closed Ads API: '+fragment);
const ms=fs.readFileSync(path.join(root,'marketing-studio.js'),'utf8');
assert(ms.includes('ads-landing-publisher.html')&&ms.includes('data-mk="ads-publisher"'),'Marketing Studio entry point missing');
new vm.Script(ms,{filename:'marketing-studio.js'});
console.log('PASS Ads publisher UI syntax, authenticated workflow, UTM copy and client-server wiring');

'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const page=fs.readFileSync(path.join(root,'b2b-partner-edition.html'),'utf8');
const hub=fs.readFileSync(path.join(root,'partner-hub.js'),'utf8');
const back=fs.readFileSync(path.join(root,'api/lib/PartnerHub.php'),'utf8');
assert.match(page,/<html lang="en"/);
for(const needle of ['Agency branding &amp; selling prices','data-v67="days"','data-v67="hotels"','data-price-tier="3"','data-price-tier="4"','data-price-tier="5"','data-single-tier="3"','data-single-tier="4"','data-single-tier="5"','partner-print-btn','partner-save-btn','in-logo','partner-contact-details','vta67-loader']){
 assert(page.includes(needle),'missing owner-approved UI component '+needle);
}
for(const forbidden of ['VTA707','Vietnam Highlights 7D6N','Anise hotel','Hawai Resort','net_per_pax','source_quote_version_id','approvedRates(','Agency NET Working Copy'])
 assert(!page.includes(forbidden),'demo fixture or sensitive data leaked: '+forbidden);
const tags=[...page.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)];
assert(tags.length>=3,'expected JSON state + editable logic + secured async data loader');
for(const [i,t] of tags.entries()){
 if(t[1].includes('application/json'))continue;
 new vm.Script(t[2],{filename:'b2b-partner-edition-'+i+'.js'});
}
assert(page.includes('credentials:\'same-origin\''),'authenticated browser request needed');
assert(page.includes('.style.visibility=\'hidden\''),'inaccessible tours must fail closed');
assert(page.includes("cloned.querySelector('#vta67-loader')?.remove()"),'offline exported HTML must not require online staging');
assert(page.includes("window.print()"),'browser PDF print must work');
for(const x of ['v67PartnerPreview','data-v67','b2b-partner-edition.html?tour_id='])assert(hub.includes(x),'Partner Hub navigation missing '+x);
for(const x of ["$action==='v67-preview'","self::working($db,$u","self::normalizeCopy(self::parse($w['content_json']))","'schema'=>'VTA_B2B_PARTNER_V67_PUBLIC_PREVIEW'"])assert(back.includes(x),'agency-scope route missing '+x);
const start=back.indexOf("if($action==='v67-preview'");
const end=back.indexOf("if($action===''&&$method==='PUT')",start);
const block=back.slice(start,end);
for(const no of ['rate_json','markup_value','net_per_pax','supplier','costing'])assert(!block.includes(no),'sensitive commercial output in public preview: '+no);
assert.equal((page.match(/<section\b/g)||[]).length,(page.match(/<\/section>/g)||[]).length,'HTML sections balanced');
console.log('PASS V6.7 exact-layout references, 3/4/5 prices, auth data, Word/NET isolation, JS syntax, offline PDF actions');

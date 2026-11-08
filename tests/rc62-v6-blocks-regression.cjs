'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.join(__dirname,'..');
const source=fs.readFileSync(path.join(root,'document-editor.js'),'utf8');
const css=fs.readFileSync(path.join(root,'vta-v6-approval.css'),'utf8');
const html=fs.readFileSync(path.join(root,'index.html'),'utf8');
const pwa=fs.readFileSync(path.join(root,'service-worker.js'),'utf8');

// Registration-only IIFE; no editor, network, account or database is mounted.
const sandbox={window:{}};
vm.runInNewContext(source,sandbox,{timeout:2500,filename:'document-editor.js'});
const next=sandbox.window.VtaDocumentTools?.v6NextUninsertedDay;
assert.equal(typeof next,'function','Day selection helper must remain testable');
const days=[{title:'Hanoi',description:'Source data'},{title:'Ninh Binh'},{title:'Ha Long'}];
assert.equal(next(days,[])?.index,0,'Empty document starts from source day 1');
assert.equal(next(days,['Day 1 · Hanoi'])?.index,1,'Do not duplicate an existing day');
assert.equal(next(days,['Day 1 · Hanoi','Day 2 · Ninh Binh','Day 3 · Ha Long']),null,'All source days present');
assert.equal(next([],['Day 1 · Legacy']),null,'No fictional source day');
assert.equal(next(undefined,[]),null,'Missing source is safe');
assert.equal(next(days,['Day 1 · Hanoi','Other section'])?.day.title,'Ninh Binh','Keep original source identity');

for(const fake of ['Day [Number]','[Destination]','Điểm nổi bật 1','Meals: [B/L/D]']){
 assert(!source.includes(fake),'Editor may not insert fake itinerary placeholder: '+fake);
}
for(const snippet of ['ctx.program','v6Find','v6Go','source.included_text','source.excluded_text','source.terms_text','data-v6-toggle-focus','data-v6-toggle-outline','data-v6-panels="tools"']){
 assert(source.includes(snippet),'Missing source binding/duplicate protection/focus control: '+snippet);
}
assert(css.includes('flex-wrap:wrap;overflow-x:visible'),'Word toolbar must wrap rather than overflow');
assert(css.includes('data-v6-focus="true"'),'Focus mode must hide side panels');
assert(css.includes('data-v6-panels="all"'),'Outline must be optional');
for(const part of ['vta-v6-approval.css?v=V6-APPROVAL-2','document-editor.js?v=V6-STUDIO-2']){
 assert(html.includes(part),'HTML pin mismatch: '+part);
 assert(pwa.includes(part),'PWA precache pin mismatch: '+part);
}
assert(pwa.includes("V6-MASTER-EDITOR-2-PWA"),'PWA cache must rotate for changed editor runtime');
console.log('PASS: V6 day selection, no fake blocks, dedup guards, focus layout and cache pins (static/unit)');

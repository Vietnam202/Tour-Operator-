'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const win={};win.window=win;win.globalThis=win;vm.createContext(win);
for(const name of ['sample-data.js','design-templates.js']){
  const source=fs.readFileSync(path.join(root,name),'utf8');
  new (require('node:vm').Script)(source,{filename:name}).runInContext(win);
}
const api=win.VTATemplateDesignV6,sample=win.VTA_TEMPLATE_SAMPLE;
assert(api&&Array.isArray(api.templates));
assert.deepEqual([...api.templates.map(t=>t.id)],['detailed','brochure','quick','b2b']);
for(const style of api.templates){
  const html=api.render(sample,style.id);
  assert(html.includes('theme-'+style.id),style.id+' missing distinct wrapper');
  assert.match(html,/Vietnam Travel Advisor/);
  assert.match(html,/Vietnam Discovery/);
  assert.doesNotMatch(html,/Day \[Number\]|\[Destination\]|\bundefined\b/);
  const count=(html.match(/data-day="|class="vta-brochure-day vta-print-section"|class="vta-quick-detail vta-print-section"|class="vta-b2b-day vta-print-section"/g)||[]).length;
  assert.equal(count,8,'all eight sample days required for '+style.id);
  assert.doesNotMatch(html,/NET PRICE|markup|profit|\$\d+/gi,'sample may not expose pricing');
  process.stdout.write('PASS '+style.id+' 8-day preview\n');
}
const injected={...sample,name:'<img src=x onerror=alert(1)>',intro:'<script>alert(1)</script>',cover:'javascript:alert(1)',days:[{n:1,title:'<svg onload=alert(1)>',description:'& <unsafe>',route:'Hanoi',meals:'B',overnight:'Hanoi'}]};
for(const style of api.templates){
 const html=api.render(injected,style.id);
 assert.doesNotMatch(html,/<script>|<svg onload|<img src=x|javascript:/i);
 assert.match(html,/&lt;img src=x onerror=alert\(1\)&gt;/);
}
assert.throws(()=>api.render(sample,'unexpected'),/Unsupported layout/);
assert.throws(()=>api.render({days:Array(61).fill({})},'quick'),/60 days/);
const css=fs.readFileSync(path.join(root,'design-templates.css'),'utf8');
for(const selector of ['.vta-detailed-cover','.vta-brochure-cover','.vta-quick-top','.vta-b2b-top'])assert(css.includes(selector),'Missing design selector '+selector);
const html=fs.readFileSync(path.join(root,'preview.html'),'utf8');
for(const file of ['sample-data.js','design-templates.js','design-templates.css'])assert(html.includes(file));
console.log('PASS renderer escape, media allowlist, 4 distinct designs, size guard, UI entrypoints');

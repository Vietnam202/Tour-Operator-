'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const js=read('vta-cost-editor.js'),css=read('vta-approved-ui.css');
const html=read('index.html'),preview=read('preview.html'),sw=read('service-worker.js');
for(const [name,source] of [['index',html],['preview',preview]]) {
  assert(source.includes('vta-approved-ui.css?v=APPROVED1'),name+' includes approved style');
  assert(source.indexOf('vta-approved-ui.css')>source.indexOf('vta-ui-refresh.css'),name+' loads approved style last');
}
assert(html.includes('vta-cost-editor.js?v=RC6-APPROVED-1'),'uses versioned real costing editor');
assert(sw.includes("const CACHE='vta-RC62-UNIFIED-DEMO-1'"),'PWA cache version changed');
assert(sw.includes("'./vta-approved-ui.css?v=APPROVED1'"),'PWA precaches approved stylesheet');
assert(sw.includes("'./vta-cost-editor.js?v=RC6-APPROVED-1'"),'PWA precaches new costing code');
for(const feature of ['data-vta-option-stay','data-option-rate','data-option-property','data-mix-hotel','data-mix-cruise','vta-advanced-stays','data-vta-summary','data-add','data-remove','data-undo','data-vta-status'])assert(js.includes(feature),'preserve '+feature);
assert(js.includes("base+'/price-matrix'"),'pax groups use server pricing matrix');
assert(js.includes('data-private-groups'),'private tour groups always visible');
assert(js.includes('Chưa có giá xác nhận'),'unknown private group costs must remain missing');
assert(js.includes('cost_per_paying_pax_vnd'),'server cost per paying pax is displayed');
assert(js.includes('supplier_id:Number(supplier)'),'supplier identity required');
assert(js.includes('manual_contract:{evidence:reason,tax_basis:'),'supplier evidence required');
assert(js.includes('expected_revision:revision'),'protect concurrent edits');
assert(!js.includes('localStorage'),'rates never persisted in localStorage');
assert(css.includes('.vta-approved-cost'),'screen styling is scoped');
assert(css.includes('.vta-group-row'),'private group responsive matrix present');
assert(css.includes('.vtps .vtps-paper'),'itinerary paper styling');
assert(css.includes('@media screen and (max-width:430px)'),'phone breakpoint');
assert(!css.includes('@media print'),'does not alter printed exports');
assert(!css.includes('.marketing-studio'),'Marketing unaffected');
assert(!css.includes('.ai-page'),'AI Chat unaffected');
let blocks=0;for(const c of css){if(c==='{')blocks++;if(c==='}')blocks--;assert(blocks>=0,'valid CSS nesting');}
assert.equal(blocks,0,'balanced CSS');
new (require('node:vm').Script)(js);
console.log('PASS approved RC6 interface wiring, syntax, PWA, security invariants and responsive CSS');

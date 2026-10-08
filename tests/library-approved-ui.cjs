'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const read = p => fs.readFileSync(path.join(__dirname, '..', p), 'utf8');
const css = read('vta-approved-library.css');
const js = read('tour-library.js');
const workspaceCss = read('vta-approved-workspaces.css');
assert(workspaceCss.includes('@media screen {') && !workspaceCss.includes('@media print'), 'workspace polish never affects printed documents');
for (const scope of ['.vtps .vtps-paper', '.vtps .vtps-top', '.vta-direct-cost .vta-cost-section', '.vta-direct-cost .vta-cost-summary']) assert(workspaceCss.includes(scope), 'missing approved workspace selector '+scope);
assert(css.includes('@media screen {'), 'approved library CSS is screen-only');
assert(!css.includes('@media print'), 'never change print/export');
for (const value of ['.tl-cover--bay', '.tl-cover--island', '.tl-card-more', '.tl-grid', 'max-width: 650px', 'focus-visible']) {
  assert(css.includes(value), 'approved responsive layout missing ' + value);
}
for (const p of ['index.html', 'preview.html']) {
  const page = read(p);
  assert.equal((page.match(/vta-approved-library\.css\?v=RC62-LIB3/g)||[]).length, 1, p + ': one approved stylesheet');
  assert(page.indexOf('vta-approved-library.css') > page.indexOf('vta-ui-refresh.css'), p + ': latest screen layer loads last');
  assert(page.includes('tour-library.js?v=RC62-LIB3'), p + ': new library JS cache-busted');
  assert(page.includes('vta-approved-workspaces.css?v=RC62-WS1'), p + ': approved workspaces layer installed');
  assert(page.indexOf('vta-approved-workspaces.css') > page.indexOf('vta-approved-library.css'), p + ': workspaces polish loads last');
}
const sw = read('service-worker.js');
assert(sw.includes("const CACHE='vta-RC62-WS1'"), 'new PWA cache');
assert(sw.includes("'./vta-approved-library.css?v=RC62-LIB3'"), 'approved CSS precached');
assert(sw.includes("'./vta-approved-workspaces.css?v=RC62-WS1'"), 'approved workspace CSS precached');
assert(sw.includes("'./tour-library.js?v=RC62-LIB3'"), 'gallery JS precached');
const esc = value => String(value ?? '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const rows = [
  {id:1,title:'Halong Bay Cruise',destination:'Halong Bay',day_count:3,status:'ACTIVE',language:'en',tags:['Private'],source_type:'PC'},
  {id:2,title:'Phu Quoc Escape',destination:'Phu Quoc',day_count:5,status:'DRAFT',language:'en',tags:['Family'],source_type:'TEXT'},
  {id:3,title:'Archived tour',destination:'Hanoi',day_count:2,status:'ARCHIVED',language:'vi',tags:[]},
  {id:4,title:'<img src=x onerror=alert(1)>',destination:'Sapa',day_count:4,status:'ACTIVE',language:'vi',tags:['A']}
];
async function settle(){for(let i=0;i<6;i++)await new Promise(r=>setImmediate(r));}
async function boot(perms){
  const dom = new JSDOM('<main id="workspace" data-view="product"></main>', {url:'https://localhost/', runScripts:'outside-only'});
  const w = dom.window, d = w.document, calls = [];
  w.VTA_PREVIEW = true;
  w.eval(js);
  const deps = {
    api:{request:async route=>{calls.push(route);if(route==='tour-library')return {items:rows};throw Error('unhandled '+route);}},
    esc,can:x=>perms.includes(x),navigate:()=>{},modal:()=>{},toast:()=>{}
  };
  await w.VTATourLibrary(deps);
  return {dom,d,w,calls};
}
(async()=>{
  let h=await boot(['tour_library.view','tour_library.manage','quote.edit']);
  try{
    assert.equal(h.d.querySelectorAll('[data-tl-card]').length,3,'archived hidden');
    assert.equal(h.d.querySelectorAll('.tl-cover').length,3,'each tour has decorative cover');
    assert(h.d.querySelector('.tl-cover--bay'),'Halong cover');
    assert(h.d.querySelector('.tl-cover--island'),'Phu Quoc cover');
    assert(!h.d.querySelector('img[onerror]'),'malicious program title remains escaped');
    assert(h.d.querySelector('[data-tl-edit="1"]'),'edit available to manager');
    assert(h.d.querySelector('[data-tl-copy="1"]'),'quote reuse action remains available');
    assert(h.d.querySelector('[data-tl-archive="1"]'),'archive action remains available');
    assert(h.d.querySelector('[data-tl-quick-import]'),'quick import visible to manager');
    h.d.querySelector('[data-tl-search]').value='phu quoc';
    h.d.querySelector('[data-tl-search]').dispatchEvent(new h.w.Event('input'));
    assert.deepEqual([...h.d.querySelectorAll('[data-tl-card]')].filter(x=>!x.hidden).map(x=>x.dataset.tlCard),['2']);
    h.d.querySelector('[data-tl-quick-import]').click();
    await settle();
    assert(h.d.querySelector('[data-tl-text]'),'quick import opens existing real text import workflow');
    console.log('PASS: approved library cards, sanitized labels, search and import shortcut');
  }finally{h.dom.window.close();}
  h=await boot(['tour_library.view']);
  try{
    assert(!h.d.querySelector('[data-tl-new]'),'view-only cannot create');
    assert(!h.d.querySelector('[data-tl-quick-import]'),'view-only cannot import');
    assert(!h.d.querySelector('[data-tl-edit]'),'view-only cannot edit');
    assert(!h.d.querySelector('[data-tl-copy]'),'view-only cannot copy to quotation');
    assert(!h.d.querySelector('[data-tl-archive]'),'view-only cannot archive');
    assert(h.d.querySelector('[data-tl-open]'),'view-only may open');
    console.log('PASS: no privilege escalation from redesigned library');
  }finally{h.dom.window.close();}
  console.log('PASS: screen-only gallery styling and PWA wiring');
})().catch(e=>{console.error(e);process.exitCode=1;});

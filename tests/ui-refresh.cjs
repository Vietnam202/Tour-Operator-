'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const css=read('vta-ui-refresh.css');
const pages=['index.html','preview.html'];
for(const page of pages){
  const html=read(page);
  const references=[...html.matchAll(/vta-ui-refresh\.css\?v=RC62-UI2/g)];
  assert.equal(references.length,1,page+' loads UI layer exactly once');
  assert(html.lastIndexOf('vta-ui-refresh.css')<html.indexOf('</head>'),page+' loads UI layer in head');
  assert(html.lastIndexOf('vta-ui-refresh.css')>html.indexOf('document-editor.css'),page+' loads UI layer after legacy styles');
}
const sw=read('service-worker.js');
assert(sw.includes("'./vta-ui-refresh.css?v=RC62-UI2'"),'PWA includes versioned CSS offline');
assert(sw.includes("const CACHE='vta-RC62-APPROVED1'"),'PWA installs fresh cache');
assert(css.includes('@media screen {'),'screen-specific UI overrides');
assert(!css.includes('@media print'),'leave existing exported/printed document layout alone');
for(const selector of ['.vtps .vtps-paper','.wd-page','.vta-direct-cost .vta-cost-line','.tour-library .tl-card','.app-shell .sidebar']){
  assert(css.includes(selector),'style existing component '+selector);
}
for(const forbidden of ['.marketing-studio','.ai-page','.ai-conversation','.landing-builder']){
  assert(!css.includes(forbidden),'do not restyle protected modules '+forbidden);
}
let depth=0;
for(const ch of css){if(ch==='{')depth++;else if(ch==='}')depth--;assert(depth>=0,'CSS blocks never close early');}
assert.equal(depth,0,'CSS block braces balanced');
console.log('PASS: RC6.2 screen-only theme wired to main/preview/PWA, original modules and print retained');

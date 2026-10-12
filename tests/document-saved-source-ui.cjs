'use strict';
// Browser-free Word Canvas contract for an existing saved library DOCX.
// This checks the real mounted editor routing, confirmation, asset upload and save.
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const script=fs.readFileSync(path.join(__dirname,'../document-editor.js'),'utf8');
const clone=x=>JSON.parse(JSON.stringify(x));
const tick=async()=>{for(let i=0;i<25;i++)await new Promise(resolve=>setImmediate(resolve));};
async function setup({allowed=true,confirm=true,source=true}={}){
 const dom=new JSDOM('<main id="workspace"></main>',{url:'https://fixture.local',runScripts:'outside-only'});
 const w=dom.window,host=w.document.querySelector('main'),calls=[],confirmations=[],updates=[];
 let engineOptions;
 let doc={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Previously edited text'}]}]};
 const model={schema:'VTA_DOC_2',title:'Saved original tour',content:clone(doc)};
 let ctx={settings:{document:model},links:[],days:[],costing_revision:'baseline-v1',immutable:false};
 const editor={
  isActive:()=>false,isEmpty:false,can:()=>({undo:()=>false,redo:()=>false}),getAttributes:()=>({}),
  getText:()=>'',getJSON:()=>clone(doc),destroy:()=>{},
  commands:{setContent:value=>{
   updates.push(value);
   if(typeof value==='string'){
    assert(value.includes('Hanoi 7D6N'),'server-parsed DOCX heading appears in Word canvas');
    assert(value.includes('<table'),'server-parsed DOCX table appears in Word canvas');
    assert(value.includes('data-asset-id="42"'),'image references uploaded private Media Library asset');
    doc={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Hanoi 7D6N'}]},{type:'image',attrs:{assetId:42,alt:'Image'}}]};
   }else doc=clone(value);
   engineOptions.onUpdate();
  }}
 };
 w.VtaDocumentEngine={version:'unit',create:(_,o)=>{engineOptions=o;return editor;}};
 w.VTATourProgramWorkspace={read:()=>true,manage:()=>true};
 w.confirm=message=>{confirmations.push(message);return confirm;};
 const png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jLx8AAAAASUVORK5CYII=';
 const parsed={text:'Hanoi 7D6N',note:'Review source',warnings:['Tables and source prices need review'],blocks:[
  {type:'heading',level:2,runs:[{text:'Hanoi 7D6N',bold:true}]},
  {type:'table',rows:[[[{text:'Hotel 3'}],[{text:'Cruise 5'}]]]},
  {type:'image_candidate',image_index:0}
 ],images:[{index:0,name:'landscape.png',mime:'image/png',base64:png}]};
 const api={request:async(route,options={})=>{
  calls.push({route,options});
  if(route==='tour-library/7/document/document-import-source')return {ok:true,...parsed};
  if(route==='media/upload')return {ok:true,asset:{id:42,title:'landscape.png',width:600}};
  if(route==='tour-library/7/document'){
   if(options.method==='PUT'){ctx={...ctx,costing_revision:'baseline-v2',settings:clone(options.body.settings),links:clone(options.body.links)};}
   return clone(ctx);
  }
  throw Error('Unexpected request '+route);
 }};
 w.eval(script);
 const mounted=await w.mountVisualProposal(host,{api,quoteData:{quote:{id:0},version:{id:7,version_status:'DRAFT'}},can:perm=>allowed||perm!=='media.view',
  libraryProgram:{id:7,has_source:source,status:'DRAFT'},integrated:true,toast:()=>{},onBack:()=>{}});
 return {w,dom,host,mounted,confirmations,calls,updates,close:()=>{mounted.dispose();w.close();}};
}
(async()=>{
 let h=await setup({confirm:false});
 try{
  const button=h.host.querySelector('[data-wd="import-saved"]');
  assert(button,'Saved source import button visible');
  button.click();await tick();
  assert.equal(h.confirmations.length,1);
  assert.equal(h.calls.filter(x=>x.route.endsWith('/document-import-source')).length,0,'cancel never requests original bytes');
  assert.equal(h.calls.filter(x=>x.options.method==='PUT').length,0,'cancel never touches saved Word document');
  console.log('PASS cancel saved-source import preserves current Word without network writes');
 }finally{h.close();}
 h=await setup();
 try{
  h.host.querySelector('[data-wd="import-saved"]').click();await tick();
  assert.equal(h.calls.filter(x=>x.route.endsWith('/document-import-source')).length,1,'source reopens through scoped API once');
  assert.equal(h.calls.filter(x=>x.route==='media/upload').length,1,'DOCX embedded image uploaded once to private media');
  assert.equal(h.updates.length,1,'Word document replaced once after server parse, not appended twice');
  const writes=h.calls.filter(x=>x.route==='tour-library/7/document'&&x.options.method==='PUT');
  assert.equal(writes.length,1,'reviewed Word canvas persists via existing revision-guarded PUT');
  assert.equal(writes[0].options.body.expected_revision,'baseline-v1');
  assert.deepEqual(Array.from(writes[0].options.body.links,x=>Number(x.asset_id)),[42],'saved Word references uploaded private image');
  assert(h.host.querySelector('[data-wd-notice]').textContent.includes('Tables and source prices need review'));
  console.log('PASS private DOCX reopened with heading/table/image and saved in existing Word revision');
 }finally{h.close();}
 h=await setup({allowed:false});
 try{assert.equal(h.host.querySelector('[data-wd="import-saved"]'),null,'no media-view permission hides server-side source action');}
 finally{h.close();}
 h=await setup({source:false});
 try{assert.equal(h.host.querySelector('[data-wd="import-saved"]'),null,'manual-only document has no preserved original import action');}
 finally{h.close();}
 console.log('Word preserved-source UI contract complete');
})().catch(e=>{console.error(e);process.exitCode=1;});

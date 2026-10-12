'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{JSDOM}=require('jsdom');
const copy=x=>JSON.parse(JSON.stringify(x));
const tick=async()=>{for(let i=0;i<8;i++)await new Promise(r=>setImmediate(r));};
async function setup(){
 const dom=new JSDOM('<main id="workspace"></main>',{url:'https://fixture.local',runScripts:'outside-only'}),w=dom.window;
 const model={schema:'VTA_DOC_2',title:'Original',content:{type:'doc',content:[{type:'paragraph'}]}};
 let ctx={settings:{document:model},links:[],days:[],costing_revision:1},options,engineOptions,content=copy(model.content);
 const calls=[],confirmations=[];let failSave=false;
 const editor={isActive:()=>false,can:()=>({undo:()=>false,redo:()=>false}),getAttributes:()=>({}),getText:()=>'',getJSON:()=>copy(content),destroy:()=>{},commands:{setContent:value=>{content=copy(value);}}};
 w.VtaDocumentEngine={version:'fixture',create:(host,o)=>{engineOptions=o;return editor;}};
 w.VTATourProgramWorkspace={read:()=>true,manage:()=>true,picker:async o=>{options=o;await o.beforeApply();const previous=copy(o.getCurrent());o.adopt({...copy(previous),settings:{document:{...model,title:'Template'}},costing_revision:2},previous);}};
 w.confirm=message=>{confirmations.push(message);return false;};
 w.eval(fs.readFileSync(path.join(__dirname,'../document-editor.js'),'utf8'));
 const api={request:async(route,opt={})=>{calls.push({route,opt});if(opt.method==='PUT'){if(failSave)throw Error('SAVE_UNAVAILABLE');ctx={...ctx,settings:copy(opt.body.settings),links:copy(opt.body.links),costing_revision:ctx.costing_revision+1};}return copy(ctx);}};
 const mounted=await w.mountVisualProposal(w.document.querySelector('main'),{api,quoteData:{quote:{id:1},version:{id:1,version_status:'DRAFT'}},can:()=>true,integrated:true,toast:()=>{},onBack:()=>{}});
 return {w,calls,confirmations,mounted,q:s=>w.document.querySelector(s),change:()=>engineOptions.onUpdate(),failSave:value=>{failSave=value;},close:()=>{mounted.dispose();w.close();}};
}
(async()=>{
 let h=await setup();try{
  assert.equal(h.q('[data-wd-status]').textContent,'Saved');
  assert.equal(h.calls.filter(c=>c.opt.method==='PUT').length,0,'Opening a saved document is read-only');
  h.q('[data-wd=library]').click();await tick();
  h.q('[data-wd-title]').value='Edited after template';h.change();await h.mounted.save();
  const writes=h.calls.filter(c=>c.opt.method==='PUT').length;
  h.q('[data-wd=open]').click();await tick();
  assert.equal(h.q('[data-wd-title]').value,'Edited after template');
  h.q('[data-wd=program-undo]').click();await tick();
  assert.equal(h.confirmations.length,1,'Even saved edits require confirmation');
  assert.equal(h.calls.filter(c=>c.opt.method==='PUT').length,writes,'Cancellation does not write');
  assert.equal(h.q('[data-wd-title]').value,'Edited after template');
  h.w.confirm=()=>true;h.q('[data-wd=program-undo]').click();await tick();
  assert.equal(h.q('[data-wd-title]').value,'Original');
  assert(h.q('[data-wd=program-undo]').hidden);
  console.log('PASS saved open has no writes; undo cancellation preserves saved edits; confirmation restores original');
 }finally{h.close();}
 h=await setup();try{
  h.q('[data-wd=library]').click();await tick();h.q('[data-wd=program-undo]').click();await tick();
  assert.equal(h.confirmations.length,0,'No warning when template has not been edited');
  assert.equal(h.q('[data-wd-title]').value,'Original');
  console.log('PASS unchanged template undo restores original without unnecessary confirmation');
 }finally{h.close();}
 h=await setup();try{
  h.q('[data-wd-title]').value='Keep my draft';h.change();h.failSave(true);
  h.q('[data-wd=save]').click();await tick();
  assert.equal(h.q('[data-wd-status]').textContent,'Save failed · retry');
  assert.equal(h.q('[data-wd-title]').value,'Keep my draft');
  const leave=new h.w.Event('beforeunload',{cancelable:true});h.w.dispatchEvent(leave);assert(leave.defaultPrevented);
  const requests=h.calls.length;h.q('[data-wd=open]').click();await tick();
  assert.equal(h.calls.length,requests,'Cancelled reopen never requests or replaces saved content');
  assert.equal(h.q('[data-wd-title]').value,'Keep my draft');
  h.failSave(false);await h.mounted.save();assert.equal(h.q('[data-wd-status]').textContent,'Saved');
  h.q('[data-wd=open]').click();await tick();assert.equal(h.q('[data-wd-title]').value,'Keep my draft');
  const cleanLeave=new h.w.Event('beforeunload',{cancelable:true});h.w.dispatchEvent(cleanLeave);assert(!cleanLeave.defaultPrevented);
  console.log('PASS failed save retains draft and leave protection; retry/reopen clears dirty state');
 }finally{h.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

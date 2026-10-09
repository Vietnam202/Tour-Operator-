'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(path.join(__dirname, '..', 'vta-plus-assist.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '..', 'vta-plus-assist.css'), 'utf8');
const nextTick = () => new Promise(resolve => setImmediate(resolve));

async function run() {
 const dom = new JSDOM('<!doctype html><html><body><button id="launch">Launch</button></body></html>', {
   url:'https://vta.example.invalid/', runScripts:'outside-only'
 });
 const w=dom.window,doc=w.document,opened=[],copied=[];
 Object.defineProperty(w.navigator, 'clipboard', {configurable:true, value:{writeText:async s=>{copied.push(s);}}});
 w.open=(...args)=>{opened.push(args);return {};};
 w.confirm=()=>true;
 w.eval(script);
 const assistant=w.VTAPlusAssist;
 const launch=doc.getElementById('launch');
 launch.focus();
 assert.equal(doc.activeElement,launch);
 assert.equal(assistant.open({mode:'sales'}),true);
 let dialog=doc.querySelector('[data-vta-plus-dialog]');
 assert(dialog);
 assert.equal(doc.querySelectorAll('[data-vta-plus-dialog]').length,1);
 assert.match(dialog.querySelector('#vta-plus-prompt').value,/B2B/);
 assert(!dialog.querySelector('[data-plus-apply]'),'Sales must not silently modify CRM');
 assert(!/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i.test(dialog.querySelector('#vta-plus-prompt').value),'No customer email in prompt');
 dialog.querySelector('#vta-plus-prompt').value='Sanitized custom request only';
 dialog.querySelector('[data-plus-copy]').click();
 await nextTick();
 assert.deepEqual(copied,['Sanitized custom request only']);
 dialog.querySelector('[data-plus-open]').click();
 assert.equal(opened.length,1);
 assert.equal(opened[0][0],'https://chatgpt.com/');
 assert.equal(opened[0][1],'_blank');
 assert.match(opened[0][2],/noopener/);
 assert(!opened[0][0].includes('?'),'No customer information is passed via URL');
 dialog.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
 assert(!doc.querySelector('[data-vta-plus-dialog]'));
 assert.equal(doc.activeElement,launch,'Keyboard close should restore focus');

 // Read-only documents cannot apply AI output.
 assistant.open({mode:'document'});
 dialog=doc.querySelector('[data-vta-plus-dialog]');
 assert(!dialog.querySelector('[data-plus-apply]'));
 dialog.querySelector('[data-plus-close]').click();
 assert(!doc.querySelector('[data-vta-plus-dialog]'));

 // Editable documents are the only flow that receives data back.
 let applied=null, approvals=0;
 w.confirm=()=>{approvals++;return approvals>1;};
 assistant.open({mode:'document',onApply:async txt=>{applied=txt;}});
 dialog=doc.querySelector('[data-vta-plus-dialog]');
 const output=dialog.querySelector('#vta-plus-result'),apply=dialog.querySelector('[data-plus-apply]');
 assert(output&&apply,'Apply controls exist only for editor-provided callback');
 apply.click();
 assert.match(dialog.querySelector('.vta-plus-status').textContent,/Dán kết quả/);
 output.value='Reviewed itinerary\nDay 1: Hanoi <script>alert("x")</script>';
 apply.click();
 assert.equal(applied,null,'Cancelled confirmation must not mutate editor');
 assert(dialog.isConnected);
 apply.click();
 await nextTick();
 assert.equal(applied,output.value);
 assert.equal(approvals,2);
 assert(!doc.querySelector('[data-vta-plus-dialog]'),'Applied dialog closes after the callback');
 
 // Failure leaves the user's draft available for correction/retry.
 assistant.open({mode:'document',onApply:()=>{throw Error('Editor unavailable');}});
 dialog=doc.querySelector('[data-vta-plus-dialog]');
 dialog.querySelector('#vta-plus-result').value='Safe itinerary draft';
 dialog.querySelector('[data-plus-apply]').click();
 await nextTick();
 assert(dialog.isConnected);
 assert.equal(dialog.querySelector('#vta-plus-result').value,'Safe itinerary draft');
 assert.match(dialog.querySelector('.vta-plus-status').textContent,/Editor unavailable/);
 assert.equal(dialog.querySelector('[data-plus-apply]').disabled,false);
 dialog.querySelector('[data-plus-close]').click();

 // No automatic network/persistence integration exists.
 assert(!/\bfetch\s*\(|XMLHttpRequest|localStorage|sessionStorage|api\.request\s*\(/.test(script));
 assert(/@media\s*\(max-width:600px\)/.test(css),'mobile layout present');
 dom.window.close();
 console.log('PASS Plus companion DOM: clipboard, launch URL, permissions, focus, confirmation, apply and recovery');
}
run().catch(error=>{console.error(error);process.exitCode=1;});

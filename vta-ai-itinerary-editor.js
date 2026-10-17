(function(){
'use strict';
/* VIE-16: document-first human-reviewed JSON bridge; no live LLM claim. */
const tiers=['3star','4star','5star'];
const groupSizes=[4,6,8,10,12];
const layouts={detailed:'Professional Detailed',brochure:'Photo-rich Brochure',quick:'Quick Itinerary',b2b:'Tour Proposal'};
const copy=x=>JSON.parse(JSON.stringify(x));
function baseDraft(ctx,editor){
 const p=ctx.program||{};
 let rows=(p.proposal&&p.proposal.private_prices)||[];
 if(rows.length===5)rows=rows.map(r=>({pax:Number(r.min),prices:{'3star':r.three||null,'4star':r.four||null,'5star':r.five||null}}));
 else rows=groupSizes.map(pax=>({pax,prices:{'3star':null,'4star':null,'5star':null}}));
 return {schema:'VTA_MASTER_ITINERARY_V1',source_program_id:Number(p.id),source_revision:ctx.costing_revision,
  title:p.title||'Untitled',document:{schema:'VTA_DOC_2',title:p.title||'Untitled',content:copy(editor.getJSON())},
  days:copy(p.days||[]),private_groups:rows,currency:'USD',agent:'manual',selling_prices_reviewed:false};
}
function css(){
 if(document.getElementById('vta-ai-editor-css'))return;
 const e=document.createElement('style');e.id='vta-ai-editor-css';
 e.textContent='.vta-ai-mask{position:fixed;inset:0;background:#122735bd;z-index:22000;display:flex;align-items:center;justify-content:center;padding:12px}.vta-ai-panel{width:min(1120px,100%);max-height:96vh;overflow:auto;background:white;color:#173c52;border-radius:12px;box-shadow:0 12px 80px #1117;font:14px/1.5 system-ui}.vta-ai-head{background:#16455b;color:white;padding:16px 22px;display:flex;justify-content:space-between;gap:12px;align-items:center;position:sticky;top:0;z-index:2}.vta-ai-head h2{margin:0;color:white;font-size:21px}.vta-ai-content{padding:18px 22px}.vta-ai-columns{display:grid;grid-template-columns:minmax(270px,420px) minmax(0,1fr);gap:22px}.vta-ai-content label{font-weight:650;display:block;margin:11px 0 4px}.vta-ai-content input:not([type=checkbox]),.vta-ai-content textarea,.vta-ai-content select{width:100%;box-sizing:border-box;border:1px solid #c2d4da;padding:9px;border-radius:7px;font:inherit}.vta-ai-content textarea{height:210px;font:12px/1.45 ui-monospace,monospace}.vta-ai-action{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.vta-ai-action button,.vta-ai-head button{border:0;border-radius:8px;background:#0a777f;color:white;font:inherit;padding:9px 12px;cursor:pointer}.vta-ai-action button.light{border:1px solid #acc9d1;background:white;color:#185271}.vta-ai-action button:disabled{opacity:.4;cursor:not-allowed}.vta-ai-warning{padding:12px;background:#fff8e9;border-left:4px solid #cda556}.vta-ai-table{border-collapse:collapse;width:100%;font-size:12px}.vta-ai-table th,.vta-ai-table td{border:1px solid #ccdce2;padding:6px}.vta-ai-table th{background:#ecf4f5}.vta-ai-table input{width:86px!important;padding:7px!important}.vta-ai-viewport{width:100%;height:550px;border:1px solid #c1d4dc;border-radius:8px;background:white}.vta-ai-check{display:flex!important;gap:8px;align-items:start;font-weight:400!important}.vta-ai-small{font-size:12px;color:#5b6d78}.vta-ai-prices{overflow-x:auto}@media(max-width:800px){.vta-ai-columns{grid-template-columns:1fr}.vta-ai-content{padding:12px}.vta-ai-viewport{height:420px}}';
 document.head.appendChild(e);
}
function element(tag,attrs,text){
 const e=document.createElement(tag);
 Object.entries(attrs||{}).forEach(([k,v])=>{if(k==='class')e.className=v;else e.setAttribute(k,v)});
 if(text!=null)e.textContent=text;
 return e;
}
async function open(input){
 const {api,editor,getContext,libraryProgram,editable,toast}=input;
 if(!editable||!libraryProgram||!Number(libraryProgram.id))throw Error('Only editable Master Tours support AI edit.');
 if(document.querySelector('.vta-ai-mask'))return;
 const current=getContext();
 if(!current||!current.program||!current.costing_revision)throw Error('Source tour is not ready. Reopen Word editor.');
 css();
 const mask=element('div',{class:'vta-ai-mask',role:'dialog','aria-modal':'true','aria-label':'AI Edit Itinerary'});
 mask.innerHTML='<section class="vta-ai-panel"><header class="vta-ai-head"><div><strong>VIETNAM TRAVEL ADVISOR</strong><h2>AI Edit Itinerary · New Master Draft</h2></div><button type="button" data-ai-close>Close ×</button></header>'+
 '<div class="vta-ai-content"><p class="vta-ai-warning">Review-only mode. ChatGPT/Hermes APIs are not connected. Paste an externally generated, verified JSON proposal; original Master Tour remains untouched.</p>'+
 '<div class="vta-ai-columns"><div><label for="ai-instr">AI instruction</label><input id="ai-instr" maxlength="1200" placeholder="Change 7D6N to 8D7N, add Ninh Binh Day 5">'+
 '<div class="vta-ai-action"><button type="button" class="light" data-ai-prompt>Copy ChatGPT / Hermes prompt</button></div>'+
 '<label for="ai-json">VTA_MASTER_ITINERARY_V1 JSON</label><textarea id="ai-json" spellcheck="false"></textarea>'+
 '<p class="vta-ai-small">Paste the complete JSON with VTA_DOC_2 rich text, original asset IDs and structured tour days. No supplier NET, cost, margin or guest data.</p>'+
 '<h3>Private Group · USD / person</h3><div class="vta-ai-prices"><table class="vta-ai-table"><thead><tr><th>Group</th><th>3★</th><th>4★</th><th>5★</th></tr></thead><tbody data-ai-groups></tbody></table></div>'+
 '<label class="vta-ai-check"><input type="checkbox" data-ai-price-approval> Selling prices entered are explicitly approved for customer-facing use (never NET).</label>'+
 '<div class="vta-ai-action"><button type="button" data-ai-preview>Validate & Preview</button><button class="light" type="button" data-ai-reset>Reset JSON</button></div>'+
 '<p data-ai-status role="status"></p><div data-ai-diff class="vta-ai-small"></div></div>'+
 '<div><label for="ai-layout">Preview layout</label><select id="ai-layout" data-ai-layout>'+Object.entries(layouts).map(x=>'<option value="'+x[0]+'">'+x[1]+'</option>').join('')+'</select>'+
 '<iframe title="Draft preview" class="vta-ai-viewport" data-ai-frame sandbox=""></iframe>'+
 '<label class="vta-ai-check"><input type="checkbox" data-ai-ack> I reviewed the changes, transport, meals, accommodation, supplier confirmations and pricing.</label>'+
 '<div class="vta-ai-action"><button data-ai-commit type="button" disabled>Create NEW Draft, keep source</button></div><p data-ai-result role="status"></p></div></div></div></section>';
 document.body.appendChild(mask);
 const q=s=>mask.querySelector(s),txt=q('#ai-json'),frame=q('[data-ai-frame]');
 let data=baseDraft(current,editor),lastPreview=null,busy=false,creationKey='vta-ai-'+crypto.randomUUID();
 const setStatus=(message,isError)=>{const el=q('[data-ai-status]');el.textContent=message;el.style.color=isError?'#a62a24':'#15616a'};
 const invalidate=()=>{lastPreview=null;frame.srcdoc='';q('[data-ai-commit]').disabled=true;q('[data-ai-diff]').replaceChildren();q('[data-ai-ack]').checked=false};
 const putJson=()=>{txt.value=JSON.stringify(data,null,2);invalidate()};
 const loadJson=()=>{const parsed=JSON.parse(txt.value);if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))throw Error('Paste a JSON object');return parsed};
 const rowChange=(index,key,value)=>{
  try{data=loadJson();if(key==='pax')data.private_groups[index].pax=Number(value);
   else data.private_groups[index].prices[key]=value===''?null:value;
   data.selling_prices_reviewed=q('[data-ai-price-approval]').checked;putJson();
  }catch(e){setStatus('Invalid JSON. Fix it before changing group rows.',true)}
 };
 const draw=()=>{
  const tbody=q('[data-ai-groups]');tbody.replaceChildren();
  (data.private_groups||[]).forEach((r,i)=>{
   const tr=element('tr'),cell=element('td'),n=element('input',{type:'number',min:'1',max:'1000',step:'1','aria-label':'Pax group '+(i+1)});
   n.value=r.pax??'';n.onchange=()=>rowChange(i,'pax',n.value);cell.appendChild(n);tr.appendChild(cell);
   for(const tier of tiers){const td=element('td'),x=element('input',{type:'number',step:'0.01',min:'0',placeholder:'On request','aria-label':tier+' group '+(i+1)});
    x.value=(r.prices||{})[tier]??'';x.onchange=()=>rowChange(i,tier,x.value);td.appendChild(x);tr.appendChild(td)}
   tbody.appendChild(tr);
  });
 };
 putJson();draw();
 const close=()=>mask.remove();q('[data-ai-close]').onclick=close;
 mask.addEventListener('click',e=>{if(e.target===mask)close()});
 txt.oninput=()=>{invalidate();setStatus('JSON changed. Validate again.')};
 q('[data-ai-price-approval]').onchange=()=>{try{data=loadJson();data.selling_prices_reviewed=q('[data-ai-price-approval]').checked;putJson()}catch(e){setStatus(e.message,true)}};
 q('[data-ai-reset]').onclick=()=>{data=baseDraft(getContext(),editor);q('[data-ai-price-approval]').checked=false;draw();putJson();setStatus('Restored the editor source; no save occurred.')};
 q('[data-ai-prompt]').onclick=async()=>{
  const prompt='You are the VTA Senior Tour Designer. Edit the SOURCE JSON according to: '+(q('#ai-instr').value||'(Enter your requested change)')+
  '\nReturn a complete JSON object ONLY. Preserve schema VTA_MASTER_ITINERARY_V1, source_program_id, source_revision, VTA_DOC_2 content and media asset IDs. Keep exactly five ascending private_groups with 3star/4star/5star prices; null means On request. Never invent supplier prices, hotel confirmations, meals, transfers, bookings, NET or margin. Flag anything uncertain for human review. SOURCE JSON:\n'+txt.value;
  try{await navigator.clipboard.writeText(prompt);setStatus('Copied. Paste into ChatGPT or Hermes, then paste returned JSON into this editor.')}
  catch(e){setStatus('Clipboard unavailable. Please copy JSON and instruction manually.',true)}
 };
 async function preview(){
  if(busy)return;busy=true;q('[data-ai-preview]').disabled=true;
  try{
   data=loadJson();data.layout=q('[data-ai-layout]').value;
   const result=await api.request('tour-library/'+libraryProgram.id+'/ai-edit/preview',{method:'POST',body:data});
   lastPreview=result;frame.srcdoc=result.preview_html;
   const section=q('[data-ai-diff]');section.replaceChildren();
   const flags=result.changed||{};
   section.appendChild(element('strong',{},'Changes: Word '+(flags.document?'yes':'no')+' · Days '+(flags.days?'yes':'no')+' · Title '+(flags.title?'yes':'no')));
   const list=element('ul');for(const w of result.warnings||[])list.appendChild(element('li',{},w));section.appendChild(list);
   draw();q('[data-ai-price-approval]').checked=data.selling_prices_reviewed===true;
   q('[data-ai-commit]').disabled=false;setStatus('Preview validated. Review all four layouts and warnings.');
  }catch(e){invalidate();setStatus(e.message||'Validation failed',true)}
  finally{busy=false;q('[data-ai-preview]').disabled=false}
 }
 q('[data-ai-preview]').onclick=preview;
 q('[data-ai-layout]').onchange=()=>{if(lastPreview)preview()};
 q('[data-ai-commit]').onclick=async()=>{
  if(busy||!lastPreview||!q('[data-ai-ack]').checked){setStatus('Human review acknowledgement is required.',true);return}
  if(getContext().costing_revision!==data.source_revision){setStatus('Master Tour changed; reopen the editor.',true);return}
  busy=true;q('[data-ai-commit]').disabled=true;
  try{
   const payload={...data,review_acknowledged:true,creation_key:creationKey};delete payload.layout;
   const saved=await api.request('tour-library/'+libraryProgram.id+'/ai-edit/commit',{method:'POST',body:payload});
   q('[data-ai-result]').textContent='Created Master Tour DRAFT #'+saved.id+'. Original #'+saved.source_program_id+' remains unchanged. Open it in Tour Library.';
   setStatus('Saved new Draft. Nothing was published to B2C or Ads.');
   if(toast)toast('New Master Tour Draft #'+saved.id);
  }catch(e){setStatus(e.message||'Draft creation failed',true);q('[data-ai-commit]').disabled=false}
  finally{busy=false}
 };
 q('[data-ai-close]').focus();
}
window.VTAItineraryAI={open,baseDraft};
})();
(()=>{'use strict';
const app=document.getElementById('hub'), account=document.getElementById('account'),logout=document.getElementById('logout');
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const clone=x=>JSON.parse(JSON.stringify(x));
let user=null,portal=null,tab=['library','my','agency','admin','requests'].includes(location.hash.slice(1))?location.hash.slice(1):'library',view=null,tours=[],my=[],selectedAgency=0,draft=null,activeQuote=null,quotation=null;
const status=(msg,error=false)=>{const el=document.querySelector('[data-hub-status]');if(el){el.textContent=msg;el.className='hub-status '+(error?'error':'success');}else if(error)alert(msg);};
async function request(route,opt={}){
 const method=opt.method||'GET',headers={'Accept':'application/json'};let body;
 if(opt.form)body=opt.form;else if(opt.body!==undefined){headers['Content-Type']='application/json';body=JSON.stringify(opt.body);}
 if(method!=='GET'&&method!=='HEAD'&&user?.csrf)headers['X-VTA-CSRF']=user.csrf;
 const parts=route.split('?'),q=new URLSearchParams(parts[1]||'');q.set('route',parts[0]);
 const res=await fetch('api/?'+q,{method,credentials:'same-origin',headers,body,cache:'no-store'});
 const data=await res.json().catch(()=>({error:'Invalid response'}));
 if(!res.ok)throw Error(data.message||data.error||'Request failed');
 return data;
}
function shell(title,description,content){
 const options=[['library','Tour Library'],['my','My Tours'],['agency','My Agency'],...(portal?.admin?[['admin','VTA Publishing'],['requests','Booking Requests']]:[])];
 app.innerHTML='<header class="hub-intro"><div><h1>'+esc(title)+'</h1><p>'+esc(description)+'</p></div></header><nav class="hub-tabs" aria-label="Partner workspace">'+options.map(([k,n])=>'<button data-tab="'+k+'" class="'+(tab===k?'current':'')+'">'+esc(n)+'</button>').join('')+'</nav>'+content+'<p data-hub-status class="hub-status" role="status"></p>';
 app.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{tab=b.dataset.tab;draft=null;activeQuote=null;render();});
}
function optionsForAgency(){return (portal?.agencies||[]).map(a=>'<option value="'+Number(a.id)+'" '+(a.id===selectedAgency?'selected':'')+'>'+esc(a.name)+'</option>').join('');}
function agencyFilter(){return '<label class="hub-label">Agency<select id="hubAgency">'+optionsForAgency()+'</select></label>';}
function agencyName(){return portal?.agencies?.find(x=>x.id===selectedAgency)?.brand_name||'Your Agency';}
function noAgency(){return '<div class="hub-empty">No agency is assigned to this login. Contact VTA Admin for PARTNER role and agency membership.</div>';}
async function boot(){
 try{const x=await request('auth/me');user=x.user;account.textContent=user.full_name;logout.hidden=false;portal=await request('b2b/me');selectedAgency=portal.agencies[0]?.id||0;logout.onclick=async()=>{try{await request('auth/logout',{method:'POST',body:{}});}finally{user=null;portal=null;loginScreen();}};
 await render();
 }catch(e){user=null;portal=null;account.textContent='';logout.hidden=true;loginScreen();}
}
function loginScreen(){
 app.innerHTML='<div class="hub-box hub-login"><h1>Welcome to VTA Partner Hub</h1><p>Sign in with your approved Vietnam Travel Advisor agency account.</p><form id="hubLogin" class="hub-form"><label>Email<input required type="email" name="email" autocomplete="username"></label><label>Password<input required type="password" name="password" autocomplete="current-password"></label><button class="hub-btn primary">Sign in</button><p class="hub-status" role="alert"></p></form></div>';
 app.querySelector('form').onsubmit=async e=>{e.preventDefault();const f=e.target;f.querySelector('button').disabled=true;try{await request('auth/login',{method:'POST',body:{email:f.elements.email.value,password:f.elements.password.value}});await boot();}catch(err){f.querySelector('[role="alert"]').textContent=err.message;f.querySelector('button').disabled=false;}};
}
async function render(){
 if(!user)return loginScreen();
 try{
 if(tab==='library')return await library();
 if(tab==='my')return await myTours();
 if(tab==='agency')return await agencyScreen();
 if(tab==='admin'&&portal.admin)return await adminScreen();
 if(tab==='requests'&&portal.admin)return await requestsScreen();
 tab='library';await library();
 }catch(e){shell('Workspace unavailable','Please check your connection','<div class="hub-box"><p class="hub-status error">'+esc(e.message)+'</p><button class="hub-btn" id="hubRetry">Retry</button></div>');app.querySelector('#hubRetry').onclick=render;}
}
async function library(){
 if(!selectedAgency&&!portal.admin){shell('Tour Library','Published VTA itineraries',noAgency());return;}
 if(view?.type==='library'){const detail=await request('b2b/tours/'+view.id);return tourDetail(detail.publication);}
 const r=await request('b2b/tours');tours=r.items||[];
 shell('Tour Library','Browse approved Master Tours published from VTA Template Builder V5',
 '<div class="hub-toolbar"><label class="hub-label">Search<input id="tourSearch" type="search" placeholder="Tour name or destination"></label>'+agencyFilter()+'</div><div class="hub-grid" id="tourCards"></div>');
 const draw=()=>{const q=(app.querySelector('#tourSearch')?.value||'').toLocaleLowerCase();app.querySelector('#tourCards').innerHTML=tours.filter(x=>(x.title+' '+x.destination).toLocaleLowerCase().includes(q)).map(t=>'<article class="hub-card"><div class="visual">VIETNAM TOUR</div><h3>'+esc(t.title)+'</h3><p>'+esc(t.destination)+' · '+t.days+' days</p><div><span class="hub-pill">'+esc(t.tour_type)+'</span> <span class="hub-pill '+(t.has_approved_net?'ok':'warn')+'">'+(t.has_approved_net?'Approved NET':'NET on request')+'</span></div><div class="actions"><button class="hub-btn primary" data-open="'+t.id+'">View & Customize</button></div></article>').join('')||'<div class="hub-empty">No published tour matches your search.</div>';app.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>{view={type:'library',id:Number(b.dataset.open)};render();});};
 app.querySelector('#tourSearch').oninput=draw;app.querySelector('#hubAgency').onchange=e=>{selectedAgency=Number(e.target.value);};draw();
}
function dayMarkup(day,i,editable){
 return '<section class="day" data-day="'+i+'"><h2>Day '+(i+1)+' – <span class="hub-editable" data-day-title contenteditable="'+editable+'">'+esc(day.title)+'</span></h2><p class="hub-editable" data-day-description contenteditable="'+editable+'">'+esc(day.description)+'</p><small>Meals: <span class="hub-editable" data-day-meals contenteditable="'+editable+'">'+esc(day.meals)+'</span> · Overnight: <span class="hub-editable" data-day-overnight contenteditable="'+editable+'">'+esc(day.overnight)+'</span></small>'+(editable?'<div><button class="hub-btn" data-remove-day="'+i+'">Remove day</button></div>':'')+'</section>';
}
function contentMarkup(data,editable,agency){
 return '<div class="hub-paper" data-paper>'+(agency?'<img class="hub-logo" src="api/?route=b2b/agencies/'+selectedAgency+'/logo" onerror="this.hidden=true" alt="Agency logo">':'')+
 '<div class="hub-badge">'+esc(agency||'VIETNAM TRAVEL ADVISOR')+' · WHITE LABEL TOUR PROGRAM</div>'+
 '<h1 class="hub-editable" data-title contenteditable="'+editable+'">'+esc(data.title)+'</h1>'+
 '<div class="hub-editable hub-badge" data-destination contenteditable="'+editable+'">'+esc(data.destination)+'</div>'+
 '<h2>Tour Overview</h2><div class="hub-editable" data-overview contenteditable="'+editable+'">'+esc(data.overview||'Tour overview pending review.')+'</div>'+
 '<h2>Detailed Itinerary</h2><div id="hubDays">'+(data.days||[]).map((d,i)=>dayMarkup(d,i,editable)).join('')+'</div>'+
 (editable?'<button class="hub-btn" id="addDay">+ Add Day</button>':'')+
 '<h2>Included</h2><div class="hub-editable" data-included contenteditable="'+editable+'">'+esc(data.included_text||'')+'</div>'+
 '<h2>Excluded</h2><div class="hub-editable" data-excluded contenteditable="'+editable+'">'+esc(data.excluded_text||'')+'</div>'+
 '<h2>Terms & Conditions</h2><div class="hub-editable" data-terms contenteditable="'+editable+'">'+esc(data.terms_text||'')+'</div></div>';
}
async function tourDetail(pub){
 shell('Tour Detail','Master V5 · Published version '+pub.version,
 '<div class="hub-toolbar"><button class="hub-btn" id="backLibrary">← Back</button>'+agencyFilter()+'<button class="hub-btn primary" id="copyTour">Customize / Make a Copy</button></div><div class="hub-detail"><div>'+contentMarkup(pub.content,false,'')+'</div><aside class="hub-box hub-sidebar"><h2>'+esc(pub.content.title)+'</h2><p>'+esc(pub.content.tour_type)+' · '+pub.content.days.length+' days</p><p class="hub-note">Net prices from imported Word files are never activated automatically. An approved VTA quotation is required for client pricing.</p><p>'+ (pub.has_rates?'Approved rate source available.':'Pricing currently on request.')+'</p><button class="hub-btn primary" id="copyAside">Customize</button></aside></div>');
 app.querySelector('#backLibrary').onclick=()=>{view=null;render();};
 app.querySelector('#hubAgency').onchange=e=>selectedAgency=Number(e.target.value);
 const copyTour=async()=>{if(!selectedAgency)return status('Select your agency first',true);try{const r=await request('b2b/tours/'+pub.id+'/copy',{method:'POST',body:{agency_id:selectedAgency}});view={type:'draft',id:r.id};tab='my';await openDraft(r.id);}catch(e){status(e.message,true);}};
 app.querySelector('#copyTour').onclick=copyTour;app.querySelector('#copyAside').onclick=copyTour;
}
async function myTours(){
 if(view?.type==='draft')return openDraft(view.id);
 const r=await request('b2b/my-tours');my=r.items||[];
 shell('My Tours','Independent agency copies · Your edits never overwrite VTA Master',
 '<div class="hub-toolbar">'+agencyFilter()+'</div><div class="hub-grid" id="myGrid">'+(my.length?my.map(t=>'<article class="hub-card" data-agency="'+t.agency_id+'"><h3>'+esc(t.title)+'</h3><p>'+esc(t.agency)+' · Rev '+t.revision+'</p><div class="hub-pill '+(t.publication_status==='PUBLISHED'?'ok':'warn')+'">'+esc(t.publication_status)+'</div><div class="actions"><button class="hub-btn primary" data-edit="'+t.id+'">Edit / Quote / Download</button></div></article>').join(''):'<div class="hub-empty">No working copies yet. Select Customize from Tour Library.</div>')+'</div>');
 app.querySelector('#hubAgency').onchange=e=>{selectedAgency=Number(e.target.value);app.querySelectorAll('[data-agency]').forEach(c=>c.hidden=Number(c.dataset.agency)!==selectedAgency);};
 app.querySelector('#hubAgency').dispatchEvent(new Event('change'));
 app.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>{view={type:'draft',id:Number(b.dataset.edit)};openDraft(view.id);});
}
function readDraft(){
 if(!draft)return;
 const text=sel=>(app.querySelector(sel)?.innerText||'').trim();
 draft.content.title=text('[data-title]');draft.content.destination=text('[data-destination]');draft.content.overview=text('[data-overview]');
 draft.content.included_text=text('[data-included]');draft.content.excluded_text=text('[data-excluded]');draft.content.terms_text=text('[data-terms]');
 draft.content.days=[...app.querySelectorAll('[data-day]')].map((d,i)=>({day:i+1,date:'',title:d.querySelector('[data-day-title]').innerText.trim(),
 description:d.querySelector('[data-day-description]').innerText.trim(),meals:d.querySelector('[data-day-meals]').innerText.trim(),overnight:d.querySelector('[data-day-overnight]').innerText.trim(),notes:''}));
}
function quoteParams(){
 const form=app.querySelector('#quoteForm');
 return {pax:Number(form.elements.pax.value),mode:form.elements.mode.value,hotel:form.elements.hotel.value,cruise:form.elements.cruise.value};
}
function queryString(o){return new URLSearchParams(o).toString();}
async function saveWorking(){
 readDraft();const form=app.querySelector('#markupForm');let type=form.elements.markup_type.value,value=Number(form.elements.markup_value.value);
 const r=await request('b2b/my-tours/'+draft.id,{method:'PUT',body:{content:draft.content,revision:draft.revision,markup_type:type,markup_value:value}});
 draft.revision=r.revision;draft.markup_type=type;draft.markup_value=value;status('Saved. Revision '+r.revision);return r;
}
function exportLink(audience,format){
 const params=quoteParams(),query=queryString({...params,audience});
 return 'api/?route='+encodeURIComponent('b2b/my-tours/'+draft.id+'/export/'+format)+'&'+query;
}
function quoteResult(q){
 const el=app.querySelector('#quoteResult');quotation=q;
 if(q.status!=='QUOTABLE'){el.innerHTML='<div class="hub-note">'+esc(q.message||'Request updated VTA NET price')+'</div>';return;}
 const m=(Number(q.selling_per_pax)||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
 el.innerHTML='<div class="hub-quote"><small>Selling price / paying guest</small><div class="hub-price">'+esc(q.currency)+' '+m+'</div><p>Total selling: '+esc(q.currency)+' '+Number(q.selling_total).toFixed(2)+'</p><small>NET '+q.net_per_pax+' · Valid until '+esc(q.valid_until)+'</small></div>';
}
async function calculate(){
 try{await saveWorking();const q=await request('b2b/my-tours/'+draft.id+'/quote?'+queryString(quoteParams()));quoteResult(q.quote);}catch(e){status(e.message,true);}
}
async function openDraft(id){
 const r=await request('b2b/my-tours/'+id);draft=r.tour;view={type:'draft',id};selectedAgency=draft.agency.id;quotation=null;
 shell('Edit Tour & Quote','White-label · '+draft.agency.brand_name+' · Revision '+draft.revision,
 '<div class="hub-toolbar"><button class="hub-btn" id="backMy">← My Tours</button><button class="hub-btn primary" id="saveTour">Save Tour</button><button class="hub-btn" id="printTour">Print Preview</button></div>'+
 '<div class="hub-detail"><div>'+contentMarkup(draft.content,true,draft.agency.brand_name)+'</div>'+
 '<aside class="hub-sidebar"><div class="hub-box hub-section"><h2>Agency Markup</h2><form id="markupForm" class="hub-form"><label>Markup method<select name="markup_type"><option value="PERCENT" '+(draft.markup_type==='PERCENT'?'selected':'')+'>Percentage (%)</option><option value="FIXED" '+(draft.markup_type==='FIXED'?'selected':'')+'>Fixed amount / pax</option></select></label><label>Value<input type="number" name="markup_value" min="0" max="100000" step="0.01" value="'+draft.markup_value+'"></label></form></div>'+
 '<div class="hub-box hub-section"><h2>Quote & Export</h2><form id="quoteForm" class="hub-form"><div class="row"><label>Paying PAX<input name="pax" type="number" min="1" max="1000" value="2" required></label><label>Tour<select name="mode"><option value="PRIVATE">Private</option><option value="SIC">SIC / Group</option></select></label></div><div class="row"><label>Hotel<select name="hotel"><option value="3">3★</option><option value="4" selected>4★</option><option value="5">5★</option></select></label><label>Cruise<select name="cruise"><option value="">Not included</option><option value="4">4★</option><option value="5">5★</option></select></label></div><button type="button" class="hub-btn primary" id="calculateQuote">Check NET & Selling Price</button></form><div id="quoteResult" role="status"><p class="hub-note">Select a reviewed configuration and calculate before issuing a client proposal.</p></div>'+
 '<h3>Download</h3><div class="hub-form"><button class="hub-btn" data-export="itinerary:docx">Editable Word · Itinerary</button><button class="hub-btn" data-export="itinerary:pdf">PDF · Itinerary</button><button class="hub-btn" data-export="agency:docx">Agency NET Word</button><button class="hub-btn primary" data-export="client:pdf">Client Price PDF</button><button class="hub-btn" data-export="client:docx">Client Price Word</button></div></div>'+
 '<div class="hub-box"><h2>Booking Request</h2><form id="bookingForm" class="hub-form"><label>Departure<input name="departure_date" required type="date"></label><label>Lead guest<input name="guest_name" maxlength="190"></label><label>Guest email<input name="guest_email" type="email"></label><label>Notes<textarea name="notes"></textarea></label><button type="submit" class="hub-btn primary">Request Booking</button></form><p class="hub-badge">Request only · VTA confirms separately</p></div></aside></div>');
 app.querySelector('#backMy').onclick=()=>{view=null;draft=null;render();};
 app.querySelector('#saveTour').onclick=async()=>{try{await saveWorking();}catch(e){status(e.message,true);}};
 app.querySelector('#printTour').onclick=()=>window.print();
 app.querySelector('#addDay').onclick=()=>{readDraft();draft.content.days.push({day:draft.content.days.length+1,title:'New Day',description:'',meals:'',overnight:'',notes:''}); const list=app.querySelector('#hubDays');list.insertAdjacentHTML('beforeend',dayMarkup(draft.content.days.at(-1),draft.content.days.length-1,true));wireRemove();};
 function wireRemove(){app.querySelectorAll('[data-remove-day]').forEach(b=>b.onclick=()=>{readDraft();if(draft.content.days.length<=1)return status('A tour needs at least one day',true);b.closest('[data-day]').remove();readDraft();});}wireRemove();
 app.querySelector('#calculateQuote').onclick=calculate;
 app.querySelectorAll('[data-export]').forEach(btn=>btn.onclick=async()=>{
  const [audience,format]=btn.dataset.export.split(':');
  try{await saveWorking();if(audience!=='itinerary'){const q=await request('b2b/my-tours/'+draft.id+'/quote?'+queryString(quoteParams()));quoteResult(q.quote);if(q.quote.status!=='QUOTABLE')return status('VTA approval required before a priced export',true);}
    window.open(exportLink(audience,format),'_blank','noopener');}catch(e){status(e.message,true);}
 });
 app.querySelector('#bookingForm').onsubmit=async e=>{e.preventDefault();try{
  await saveWorking();const q=await request('b2b/my-tours/'+draft.id+'/quote?'+queryString(quoteParams()));if(q.quote.status!=='QUOTABLE')throw Error('Request an approved NET rate before booking');
  const b=Object.fromEntries(new FormData(e.target).entries());const result=await request('b2b/my-tours/'+draft.id+'/booking-request',{method:'POST',body:{...b,...quoteParams()}});
  status('Booking request #'+result.id+' submitted to VTA for review.');}catch(e){status(e.message,true);}};
}
async function agencyScreen(){
 if(!selectedAgency){shell('My Agency','Brand settings',noAgency());return;}
 shell('My Agency','Set the agency name, client contact and logo once; export uses your branding',
 '<div class="hub-toolbar">'+agencyFilter()+'</div><div class="hub-grid-2"><div class="hub-box"><h2>White-label Profile</h2><form id="agencyForm" class="hub-form"><label>Brand name<input required name="brand_name"></label><label>Brand color<input class="hub-brand-color" type="color" name="brand_color"></label><label>Contact email<input type="email" name="email"></label><label>WhatsApp<input name="whatsapp"></label><button class="hub-btn primary">Save Brand Settings</button></form></div><div class="hub-box"><h2>Agency Logo</h2><img class="hub-logo" id="agencyLogo" src="api/?route=b2b/agencies/'+selectedAgency+'/logo" onerror="this.hidden=true" alt="Agency logo"><p>PNG or JPEG, maximum 2 MB. Private storage.</p><form class="hub-form" id="logoForm"><input type="file" name="file" accept="image/png,image/jpeg" required><button class="hub-btn">Upload Logo</button></form></div></div>');
 const fill=()=>{let a=portal.agencies.find(x=>x.id===selectedAgency);if(!a)return;for(let k of ['brand_name','brand_color','email','whatsapp'])app.querySelector('#agencyForm').elements[k].value=a[k]||'';app.querySelector('#agencyLogo').src='api/?route=b2b/agencies/'+selectedAgency+'/logo&v='+Date.now();};
 app.querySelector('#hubAgency').onchange=e=>{selectedAgency=Number(e.target.value);fill();};fill();
 app.querySelector('#agencyForm').onsubmit=async e=>{e.preventDefault();try{const b=Object.fromEntries(new FormData(e.target).entries());await request('b2b/agencies/'+selectedAgency+'/branding',{method:'PUT',body:b});const a=portal.agencies.find(x=>x.id===selectedAgency);Object.assign(a,b);status('Agency branding saved');}catch(e){status(e.message,true);}};
 app.querySelector('#logoForm').onsubmit=async e=>{e.preventDefault();try{const b=await request('b2b/agencies/'+selectedAgency+'/logo',{method:'POST',form:new FormData(e.target)});app.querySelector('#agencyLogo').hidden=false;app.querySelector('#agencyLogo').src='api/?route=b2b/agencies/'+selectedAgency+'/logo&v='+Date.now();status('Logo uploaded');}catch(e){status(e.message,true);}};
}
async function adminScreen(){
 const [ag,pub,programs]=await Promise.all([request('b2b/admin/agencies'),request('b2b/admin/publications'),request('tour-library?limit=100&status=ACTIVE')]);
 shell('VTA Publishing Center','Select an ACTIVE Master Tour from Template Builder V5; review and publish a version',
 '<div class="hub-grid-2"><div class="hub-box"><h2>Publish Master Tour</h2><form class="hub-form" id="publishForm"><label>Active Master<select required name="program_id">'+(programs.items||[]).map(p=>'<option value="'+p.id+'">'+esc(p.title)+' (#'+p.id+')</option>').join('')+'</select></label><label>SENT / CONFIRMED Quote Version ID (optional)<input name="source_quote_version_id" type="number" min="1" placeholder="Leave empty for itinerary-only"></label><p class="hub-note">Historical Word prices are never accepted as approved NET. Choose a valid locked VTA quotation to publish rates.</p><button class="hub-btn primary">Publish new version</button></form></div><div class="hub-box"><h2>Create Agency</h2><form class="hub-form" id="agencyCreate"><label>Agency name<input name="agency_name" required></label><button class="hub-btn primary">Create Agency</button></form><h3>Assign existing PARTNER user</h3><form class="hub-form" id="memberForm"><label>Agency<select name="agency_id">'+(ag.items||[]).map(a=>'<option value="'+a.id+'">'+esc(a.agency_name)+'</option>').join('')+'</select></label><label>User ID<input name="user_id" type="number" min="1" required></label><button class="hub-btn">Grant Agency Access</button></form><p class="hub-note">Create a least-privilege PARTNER user in VTA Settings first. Never grant supplier, finance or costing permissions.</p></div></div>'+
 '<section class="hub-box hub-section" style="margin-top:20px"><h2>Publication History</h2><div style="overflow:auto"><table class="hub-table"><thead><tr><th>ID</th><th>Master Tour</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>'+(pub.items||[]).map(p=>'<tr><td>'+p.id+'</td><td>'+esc(p.title)+'</td><td>V'+p.version_no+'</td><td>'+esc(p.status)+'</td><td>'+(p.status==='PUBLISHED'?'<button class="hub-btn danger" data-revoke="'+p.id+'">Revoke</button>':'')+'</td></tr>').join('')+'</tbody></table></div></section><div class="hub-box"><h2>Existing 50 Word Programs</h2><p>Use Template Builder V5 → Tour Library → Upload / Nhập chương trình to review and save multiple DOCX files. Publish only after checking each itinerary.</p><a class="hub-btn" href="./index.html" target="_blank" rel="noopener">Open VTA Tour Library</a></div>');
 app.querySelector('#publishForm').onsubmit=async e=>{e.preventDefault();try{const b=Object.fromEntries(new FormData(e.target).entries());if(!b.source_quote_version_id)delete b.source_quote_version_id;const r=await request('b2b/admin/publish',{method:'POST',body:b});status('Published version '+r.version+' · #'+r.id);setTimeout(render,700);}catch(e){status(e.message,true);}};
 app.querySelector('#agencyCreate').onsubmit=async e=>{e.preventDefault();try{await request('b2b/admin/agencies',{method:'POST',body:Object.fromEntries(new FormData(e.target).entries())});portal=await request('b2b/me');status('Agency created');setTimeout(render,700);}catch(e){status(e.message,true);}};
 app.querySelector('#memberForm').onsubmit=async e=>{e.preventDefault();try{const b=Object.fromEntries(new FormData(e.target).entries());await request('b2b/admin/agencies/'+Number(b.agency_id)+'/members',{method:'POST',body:{user_id:Number(b.user_id)}});status('Agency member assigned');}catch(e){status(e.message,true);}};
 app.querySelectorAll('[data-revoke]').forEach(b=>b.onclick=async()=>{if(!confirm('Revoke this published version? Existing agency drafts retain their historical snapshots but cannot quote or book.'))return;try{await request('b2b/admin/publications/'+Number(b.dataset.revoke)+'/revoke',{method:'POST',body:{}});render();}catch(e){status(e.message,true);}});
}
async function requestsScreen(){
 const r=await request('b2b/admin/requests');
 shell('Booking Requests','Requests from agencies require VTA Operations verification',
 '<div class="hub-box"><div style="overflow:auto"><table class="hub-table"><thead><tr><th>Request</th><th>Agency</th><th>Departure</th><th>PAX</th><th>Lead guest</th><th>Status</th></tr></thead><tbody>'+(r.items||[]).map(t=>'<tr><td>#'+t.id+'</td><td>'+esc(t.agency_name)+'</td><td>'+esc(t.departure_date)+'</td><td>'+t.paying_pax+'</td><td>'+esc(t.guest_name)+'</td><td>'+esc(t.status)+'</td></tr>').join('')+'</tbody></table></div></div>');
}
boot();
})();

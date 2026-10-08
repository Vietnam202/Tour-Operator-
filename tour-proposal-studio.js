(function(){
'use strict';
const initial = () => ({
  tour_code:'', tour_type:'PRIVATE', overview:'', highlights:[],
  group_prices:[{hotel:'3',price:'',single:''},{hotel:'4',price:'',single:''},{hotel:'5',price:'',single:''}],
  private_prices:[{min:2,max:2,three:'',four:'',five:''},{min:3,max:4,three:'',four:'',five:''},{min:5,max:9,three:'',four:'',five:''},{min:10,max:14,three:'',four:'',five:''},{min:15,max:20,three:'',four:'',five:''}],
  hotels:[], policies:{children:'',payment:'',cancellation:'',notes:''}
});
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const clean=s=>String(s??'').trim();
const copy=x=>JSON.parse(JSON.stringify(x));
const text=(name,value='',attributes='')=>'<label>'+name+'<input '+attributes+' value="'+esc(value)+'"></label>';
const cell=(value,field,placeholder='—')=>'<input data-field="'+field+'" value="'+esc(value??'')+'" placeholder="'+placeholder+'" aria-label="'+field+'">';
const paragraph=(value,key,rows=4)=>'<textarea rows="'+rows+'" data-proposal="'+key+'" aria-label="'+key+'">'+esc(value)+'</textarea>';
const dayHTML=(d,i)=>'<section class="vtps-day" data-day-index="'+i+'" id="vtps-day-'+i+'"><div class="vtps-day-head"><b>DAY '+String(i+1).padStart(2,'0')+'</b><div class="vtps-day-actions"><button data-day-action="up" data-i="'+i+'" title="Lên">↑</button><button data-day-action="down" data-i="'+i+'" title="Xuống">↓</button><button data-day-action="duplicate" data-i="'+i+'">Nhân bản</button><button data-day-action="delete" data-i="'+i+'">Xóa</button></div></div><div class="vtps-day-title" contenteditable="true" data-day-field="title" role="textbox" aria-label="Tiêu đề ngày '+(i+1)+'">'+esc(d.title||'Tên hành trình ngày '+(i+1))+'</div><div class="vtps-day-meta">'+text('Ngày đi',d.date||'','data-day-field="date" placeholder="YYYY-MM-DD"')+text('Bữa ăn',d.meals||'','data-day-field="meals" placeholder="B/L/D"')+text('Nghỉ đêm',d.overnight||'','data-day-field="overnight"')+'</div><div class="vtps-text-area" data-day-field="description" contenteditable="true" role="textbox" aria-label="Nội dung ngày '+(i+1)+'" data-placeholder="Nhập hoặc dán lịch trình từ ChatGPT...">'+esc(d.description||'')+'</div><details><summary>Ghi chú nội bộ (không xuất khách)</summary><textarea data-day-field="notes" rows="2">'+esc(d.notes||'')+'</textarea></details></section>';
function validatePrices(rows){
  const nonempty=rows.filter(r=>[r.three,r.four,r.five].some(x=>clean(x)!==''));
  const ordered=rows.map(r=>({min:Number(r.min),max:Number(r.max)})).filter(x=>Number.isFinite(x.min)&&Number.isFinite(x.max)).sort((a,b)=>a.min-b.min);
  const messages=[];
  if(ordered.some(r=>!Number.isInteger(r.min)||!Number.isInteger(r.max)||r.min<1||r.max<r.min))messages.push('Khoảng số khách không hợp lệ.');
  for(let i=1;i<ordered.length;i++)if(ordered[i].min<=ordered[i-1].max)messages.push('Khoảng khách Private bị trùng; hãy sửa trước khi phát hành.');
  if(nonempty.length)messages.push('Giá Private là giá nhập thủ công, phải được Pricing Engine/Sales kiểm tra trước khi gửi khách.');
  return messages;
}
window.VTATourProposalStudio={
 mount:function(container,options){
  const {program,api,onBack,onSaved,onImport,toast,canEdit=true}=options;
  if(container.__vtpsAbort)container.__vtpsAbort.abort();
  const controller=new AbortController();
  container.__vtpsAbort=controller;
  let current=copy(program),pending=false,preview=false,displayMode='FULL';
  const base=initial(), p={...base,...(current.proposal||{}),policies:{...base.policies,...(current.proposal?.policies||{})}};
  current.proposal=p;
  current.days=Array.isArray(current.days)?current.days:[];
  const title=()=>clean(current.title)||'Chương trình tour mới';
  const daysNav=()=>current.days.map((_,i)=>'<button data-scroll="vtps-day-'+i+'">Day '+(i+1)+'</button>').join('');
  function screen(){
   container.innerHTML='<section class="vtps" aria-label="VTA Proposal Studio"><header class="vtps-top"><div><small>VIETNAM TRAVEL ADVISOR / TOUR PROGRAM LIBRARY</small><h1>Itinerary & Proposal Studio</h1></div><div class="vtps-top-actions"><span data-vtps-state>Chưa lưu thay đổi</span><button data-action="back">← Kho chương trình</button><button data-action="import">Import Word / PDF</button><button data-action="paste">Dán ChatGPT</button><button data-action="preview">Xem trước</button><button data-action="save-template">Lưu thành mẫu</button><button class="vtps-save" data-action="save">Lưu vào Library</button></div></header><div class="vtps-main"><aside class="vtps-sidebar"><p>DOCUMENT OUTLINE</p>'+[['overview','Tour Overview'],['summary','Summary Schedule'],['prices','Best Price Offer'],['hotels','Hotels & Cruise'],['itinerary','Detailed Itinerary'],['included','Included / Excluded'],['policies','Policies & Terms']].map(([id,name])=>'<button data-scroll="vtps-'+id+'">'+name+'</button>').join('')+'<div data-day-outline>'+daysNav()+'</div></aside><div class="vtps-paper"><div class="vtps-brand">VIETNAM TRAVEL ADVISOR</div><div class="vtps-modes" aria-label="Chế độ xem thử"><button data-mode="FULL" class="'+(displayMode==="FULL"?"selected":"")+'">Full Proposal</button><button data-mode="QUICK" class="'+(displayMode==="QUICK"?"selected":"")+'">Quick Quotation</button><button data-mode="B2B" class="'+(displayMode==="B2B"?"selected":"")+'">B2B Partner</button></div><h2 class="vtps-paper-title" id="vtps-overview">TOUR PROGRAM & QUOTATION</h2><div class="vtps-meta">'+text('Tên chương trình',current.title,'data-meta="title" maxlength="190"')+text('Điểm đến',current.destination,'data-meta="destination" maxlength="190"')+text('Mã tour',p.tour_code,'data-proposal="tour_code" maxlength="50"')+'<label>Tour Type<select data-proposal="tour_type"><option value="PRIVATE" '+(p.tour_type==='PRIVATE'?'selected':'')+'>Private</option><option value="SIC" '+(p.tour_type==='SIC'?'selected':'')+'>Group / SIC</option><option value="BOTH" '+(p.tour_type==='BOTH'?'selected':'')+'>Private + Group</option></select></label><label>Ngôn ngữ<select data-meta="language"><option value="en" '+(current.language==='en'?'selected':'')+'>English</option><option value="vi" '+(current.language==='vi'?'selected':'')+'>Tiếng Việt</option></select></label><label>Trạng thái<select data-meta="status"><option value="DRAFT" '+(current.status==='DRAFT'?'selected':'')+'>Draft</option><option value="ACTIVE" '+(current.status==='ACTIVE'?'selected':'')+'>Active</option></select></label></div><h3>Tour Overview</h3>'+paragraph(p.overview,'overview',4)+'<h3>Tour Highlights</h3>'+paragraph((p.highlights||[]).join('\n'),'highlights',4)+'<section id="vtps-summary"><h3>Summary Itinerary</h3><p class="vtps-help">Tự động lấy từ Detailed Itinerary. Không cần nhập hai lần.</p><table><thead><tr><th>Day</th><th>Schedule</th><th>Meals</th><th>Overnight</th></tr></thead><tbody>'+current.days.map((d,i)=>'<tr><td>Day '+(i+1)+'</td><td>'+esc(d.title)+'</td><td>'+esc(d.meals)+'</td><td>'+esc(d.overnight)+'</td></tr>').join('')+'</tbody></table></section><section id="vtps-prices"><h3>Best Price Offer · Group / SIC</h3><p class="vtps-help">Đơn giá bán USD/khách, chưa được phê duyệt khi nhập mới.</p><table data-table="group"><thead><tr><th>Hotel</th><th>Twin / Double · USD/Pax</th><th>Single supplement</th></tr></thead><tbody>'+p.group_prices.map((r,i)=>'<tr data-group-row="'+i+'"><th>'+esc(r.hotel)+'★</th><td>'+cell(r.price,'price')+'</td><td>'+cell(r.single,'single')+'</td></tr>').join('')+'</tbody></table><h3>Private Tour · Price per person in USD</h3><table data-table="private"><thead><tr><th>Group Size</th><th>3★ Hotel</th><th>4★ Hotel</th><th>5★ Hotel</th><th></th></tr></thead><tbody>'+p.private_prices.map((r,i)=>'<tr data-private-row="'+i+'"><td><div class="vtps-range">'+cell(r.min,'min')+' – '+cell(r.max,'max')+'</div></td>'+['three','four','five'].map(k=>'<td>'+cell(r[k],k)+'</td>').join('')+'<td><button data-remove-private="'+i+'" aria-label="Xóa dòng giá">×</button></td></tr>').join('')+'</tbody></table><button data-action="add-private" class="vtps-subaction">+ Thêm nhóm khách</button><p class="vtps-warning" data-pricing-warnings></p></section><section id="vtps-hotels"><h3>Hotels & Cruise</h3><table data-table="hotels"><thead><tr><th>Destination</th><th>3★</th><th>4★</th><th>5★</th><th></th></tr></thead><tbody>'+p.hotels.map((r,i)=>'<tr data-hotel-row="'+i+'">'+['destination','three','four','five'].map(k=>'<td>'+cell(r[k],k)+'</td>').join('')+'<td><button data-remove-hotel="'+i+' aria-label="Xóa dòng khách sạn">×</button></td></tr>').join('')+'</tbody></table><button class="vtps-subaction" data-action="add-hotel">+ Thêm khách sạn / cruise</button></section><section id="vtps-itinerary"><div class="vtps-section-bar"><h3>Detailed Itinerary</h3><button class="vtps-subaction" data-action="add-day">+ Add Day</button></div><div data-day-list>'+current.days.map(dayHTML).join('')+'</div></section><section id="vtps-included"><h3>Included</h3><textarea rows="5" data-meta="included_text">'+esc(current.included_text)+'</textarea><h3>Excluded</h3><textarea rows="5" data-meta="excluded_text">'+esc(current.excluded_text)+'</textarea></section><section id="vtps-policies"><h3>Children Policy</h3>'+paragraph(p.policies.children,'children',3)+'<h3>Payment Terms</h3>'+paragraph(p.policies.payment,'payment',3)+'<h3>Cancellation Policy</h3>'+paragraph(p.policies.cancellation,'cancellation',3)+'<h3>Important Notes</h3>'+paragraph(p.policies.notes,'notes',3)+'<h3>Other Terms & Conditions</h3><textarea rows="5" data-meta="terms_text">'+esc(current.terms_text)+'</textarea></section><p class="vtps-footer">VIETNAM TRAVEL ADVISOR · For draft review only. Rates and services subject to confirmation.</p></div></div></section>';
   container.classList.add('vtps-host');
   container.querySelector('[data-pricing-warnings]').textContent=validatePrices(current.proposal.private_prices).join(' ');
   container.classList.toggle('vtps-preview',preview);container.classList.toggle('vtps-quick-preview',preview&&displayMode==='QUICK');
   if(!canEdit||preview){container.querySelectorAll('input,textarea,select').forEach(el=>el.disabled=true);container.querySelectorAll('[contenteditable]').forEach(el=>el.setAttribute('contenteditable','false'));}
   if(!canEdit){container.querySelectorAll('button[data-action="import"],button[data-action="paste"],button[data-action="save-template"],button[data-action="save"],button[data-action="add-day"],button[data-action="add-private"],button[data-action="add-hotel"],button[data-day-action],button[data-remove-private],button[data-remove-hotel]').forEach(el=>{el.disabled=true;el.hidden=true;});container.querySelector('[data-vtps-state]').textContent='Chỉ xem · Không có quyền sửa';}
  }
  function read(){
   const one=s=>container.querySelector(s);
   container.querySelectorAll('[data-meta]').forEach(el=>current[el.dataset.meta]=el.value);
   p.tour_code=one('[data-proposal="tour_code"]').value;
   p.tour_type=one('[data-proposal="tour_type"]').value;
   p.overview=one('[data-proposal="overview"]').value;
   p.highlights=one('[data-proposal="highlights"]').value.split(/\r?\n/).map(clean).filter(Boolean);
   p.group_prices=[...container.querySelectorAll('[data-group-row]')].map(row=>({hotel:p.group_prices[Number(row.dataset.groupRow)].hotel,price:row.querySelector('[data-field="price"]').value,single:row.querySelector('[data-field="single"]').value}));
   p.private_prices=[...container.querySelectorAll('[data-private-row]')].map(row=>Object.fromEntries(['min','max','three','four','five'].map(k=>[k,k==='min'||k==='max'?Number(row.querySelector('[data-field="'+k+'"]').value):row.querySelector('[data-field="'+k+'"]').value])));
   p.hotels=[...container.querySelectorAll('[data-hotel-row]')].map(row=>Object.fromEntries(['destination','three','four','five'].map(k=>[k,row.querySelector('[data-field="'+k+'"]').value])));
   ['children','payment','cancellation','notes'].forEach(k=>p.policies[k]=one('[data-proposal="'+k+'"]').value);
   current.days=[...container.querySelectorAll('[data-day-index]')].map((node,i)=>{
     const d=current.days[Number(node.dataset.dayIndex)]||{};
     const val=k=>{const el=node.querySelector('[data-day-field="'+k+'"]');return el?.hasAttribute('contenteditable')?(el.innerText??el.textContent??''):el?.value||'';};
     return {...d,day:i+1,...Object.fromEntries(['date','title','description','meals','overnight','notes'].map(k=>[k,val(k)]))};
   });
   current.proposal=p;
  }
  async function saveAsTemplate(){
   if(!canEdit||pending)return;
   read();
   if(!clean(current.title)){toast('Đặt tên tour trước khi lưu mẫu.',true);return;}
   const warnings=validatePrices(p.private_prices);
   if(warnings.some(w=>w.includes('không hợp lệ')||w.includes('bị trùng'))){toast(warnings.join(' '),true);return;}
   pending=true;
   try{
    const draft={...current,title:current.title+' (Template)',tags:[...new Set([...(current.tags||[]),'VTA_TEMPLATE'])],status:'DRAFT',source_text:'',source_name:'',source_type:'MANUAL',source_url:''};
    delete draft.id;delete draft.creation_key;delete draft.import_token;delete draft.has_source;
    const response=await api.request('tour-library',{method:'POST',body:draft});
    if(!response?.id)throw Error('API chưa xác nhận lưu template.');
    toast('Đã lưu bản mẫu độc lập vào Template Center.');
   }catch(e){toast(e?.message||'Không lưu được mẫu.',true);}finally{pending=false;}
  }
  function pasteFromChatGPT(){
   if(!canEdit)return;
   const content=window.prompt('Dán lịch trình từ ChatGPT (Day 1 / Ngày 1 ...) để thêm vào bản nháp.');
   if(!content||!content.trim())return;
   read();
   const source=content.replace(/\r\n?/g,'\n');
   const matches=[...source.matchAll(/^(?:day|ngày)\s*0*\d{1,3}\s*[:.\-–—]?\s*([^\n]*)/gim)];
   if(matches.length){
    if(current.days.length+matches.length>90){toast('Vượt giới hạn 90 ngày tour.',true);return;}
    for(let i=0;i<matches.length;i++){
     const m=matches[i],start=m.index+m[0].length,end=i+1<matches.length?matches[i+1].index:source.length;
     current.days.push({day:current.days.length+1,date:'',title:m[1].trim()||'Tour Day',description:source.slice(start,end).trim(),meals:'',overnight:'',notes:''});
    }
   }else{p.overview=[p.overview,source.trim()].filter(Boolean).join('\n\n');}
   screen();toast(matches.length?'Đã thêm '+matches.length+' ngày; hãy kiểm tra lại nội dung.':'Đã thêm văn bản vào Tour Overview.');
  }
  function addDay(){
   if(current.days.length>=90){toast('Tối đa 90 ngày tour.',true);return;}
   current.days.push({day:current.days.length+1,date:'',title:'New Day',description:'',meals:'',overnight:'',notes:''});screen();
  }
  async function save(){
   if(pending||!canEdit)return;
   read();
   if(!clean(current.title)){toast('Nhập tên chương trình.',true);return;}
   if(current.status==='ACTIVE'&&!current.days.length){toast('Chương trình ACTIVE phải có ngày tour.',true);return;}
   const warnings=validatePrices(p.private_prices);
   if(warnings.some(w=>w.includes('không hợp lệ')||w.includes('bị trùng'))){toast(warnings.join(' '),true);return;}
   pending=true;const button=container.querySelector('[data-action="save"]');button.disabled=true;
   try{
    const route=current.id?'tour-library/'+Number(current.id):'tour-library';
    const r=await api.request(route,{method:current.id?'PUT':'POST',body:current});
    if(!r.id)throw Error('Không có xác nhận lưu từ API.');
    current.id=r.id;container.querySelector('[data-vtps-state]').textContent='Đã lưu vào Tour Program Library';
    toast('Đã lưu chương trình.');
    controller.abort();container.classList.remove('vtps-host');
    if(onSaved)await onSaved(r);
   }catch(e){toast(e?.message||'Không lưu được chương trình.',true);button.disabled=false;}
   finally{pending=false;}
  }
  screen();
  container.addEventListener('input',e=>{if(container.querySelector('[data-vtps-state]'))container.querySelector('[data-vtps-state]').textContent='Chưa lưu thay đổi';},{signal:controller.signal});
  container.addEventListener('click',async e=>{
   const scroll=e.target.closest('[data-scroll]');if(scroll){container.querySelector('#'+scroll.dataset.scroll)?.scrollIntoView({behavior:'smooth',block:'start'});return;}
   const b=e.target.closest('button');if(!b||!container.contains(b)||b.disabled)return;
   if((!canEdit||preview)&&!['back','preview'].includes(b.dataset.action||'')&&!b.dataset.mode)return;
   if(b.dataset.action==='save'){await save();return;}
   if(b.dataset.action==='save-template'){await saveAsTemplate();return;}
   if(b.dataset.action==='import'){if(!confirm('Chuyển sang nhập Word/PDF? Các thay đổi chưa lưu sẽ mất.'))return;controller.abort();container.classList.remove('vtps-host');if(onImport)await onImport();return;}
   if(b.dataset.action==='paste'){pasteFromChatGPT();return;}
   if(b.dataset.action==='back'){if(!confirm('Quay lại Kho chương trình? Những thay đổi chưa lưu sẽ mất.'))return;controller.abort();container.classList.remove('vtps-host');await onBack();return;}
   if(b.dataset.action==='preview'){read();preview=!preview;screen();return;}
   if(b.dataset.mode){if(!preview)read();displayMode=b.dataset.mode;screen();return;}
   if(b.dataset.action==='add-day'){read();addDay();return;}
   if(b.dataset.action==='add-private'){read();p.private_prices.push({min:21,max:30,three:'',four:'',five:''});screen();return;}
   if(b.dataset.action==='add-hotel'){read();p.hotels.push({destination:'',three:'',four:'',five:''});screen();return;}
   if(b.dataset.removePrivate!==undefined){read();p.private_prices.splice(Number(b.dataset.removePrivate),1);screen();return;}
   if(b.dataset.removeHotel!==undefined){read();p.hotels.splice(Number(b.dataset.removeHotel),1);screen();return;}
   if(b.dataset.dayAction){read();const i=Number(b.dataset.i),action=b.dataset.dayAction;
    if(action==='delete'){if(!confirm('Xóa ngày này?'))return;current.days.splice(i,1);}
    if(action==='duplicate'){if(current.days.length>=90){toast('Tối đa 90 ngày tour.',true);return;}current.days.splice(i+1,0,copy(current.days[i]));}
    if(action==='up'&&i>0)[current.days[i-1],current.days[i]]=[current.days[i],current.days[i-1]];
    if(action==='down'&&i<current.days.length-1)[current.days[i+1],current.days[i]]=[current.days[i],current.days[i+1]];
    current.days.forEach((d,j)=>d.day=j+1);screen();
   }
  },{signal:controller.signal});
 }
};
})();
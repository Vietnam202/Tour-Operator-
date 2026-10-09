(function(){
 'use strict';
 const copy=x=>JSON.parse(JSON.stringify(x)),esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const fold=x=>String(x??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d').toLowerCase();
 const read=can=>['tour_library.view','product.view','supplier.view'].some(can);
 const manage=can=>['tour_library.manage','product.manage','document.upload'].some(can);
 const text=n=>n.type==='text'?n.text:(n.content||[]).map(text).join(['doc','table','tableRow'].includes(n.type)?'\n':'');
 async function picker(o){
  const {api,modal,editable,beforeApply,getCurrent,adopt}=o;
  const m=modal('Kho chương trình','<div class="tpg-dialog" data-no-translate><p>Chọn một mẫu để tạo bản sao trong tour này. Thông tin khách, ngày đi và tính giá được giữ nguyên. Giá trong mẫu cần được kiểm tra tại tab Tính giá.</p>'+(!editable?'<p class="tpg-note">Phiên bản này chỉ đọc. Tạo phiên bản mới trong Hồ sơ tour để dùng mẫu.</p>':'')+'<div class="tpg-filters"><label>Tìm chương trình<input type="search" data-tpg-search placeholder="Tên, mã tour, điểm đến"></label><label>Trạng thái<select data-tpg-status><option value="">Tất cả</option><option value="ACTIVE">Đang dùng</option><option value="DRAFT">Bản nháp</option></select></label><label>Loại tour<select data-tpg-type><option value="">Tất cả</option><option value="PRIVATE">Private</option><option value="SIC">SIC</option><option value="BOTH">Private & SIC</option></select></label></div><div class="tpg-layout"><div class="tpg-list" data-tpg-list role="list">Đang tải…</div><div class="tpg-preview"><h3 data-tpg-title>Chọn chương trình</h3><p data-tpg-meta></p><pre data-tpg-preview></pre><button class="btn primary" data-tpg-apply disabled>Dùng cho tour này</button><p>Thao tác sẽ thay nội dung tài liệu hiện tại. Bạn có thể hoàn tác lần chọn này trong tab Chương trình.</p></div></div><p role="alert" data-tpg-error></p></div>','',true);
  const q=s=>m.querySelector(s),fail=e=>{if(m.isConnected)q('[data-tpg-error]').textContent=e.message||String(e);};
  let items=[],selected=null,ticket=0,busy=false;
  function render(){
   const query=fold(q('[data-tpg-search]').value),status=q('[data-tpg-status]').value,type=q('[data-tpg-type]').value;
   const visible=items.filter(p=>p.status!=='ARCHIVED'&&(!status||p.status===status)&&(!type||(p.tour_type||'PRIVATE')===type)&&fold([p.title,p.tour_code,p.destination].join(' ')).includes(query));
   q('[data-tpg-list]').innerHTML=visible.map(p=>'<button class="tpg-card" data-tpg-id="'+Number(p.id)+'" aria-pressed="'+String(Number(selected?.program?.id)===Number(p.id))+'"><strong>'+esc(p.title)+'</strong><span>'+esc([p.tour_code,p.destination].filter(Boolean).join(' · '))+'</span><small>'+esc((p.tour_type||'PRIVATE')+' · '+(p.status==='ACTIVE'?'Đang dùng':'Bản nháp'))+'</small></button>').join('')||'<p>Chưa có chương trình phù hợp.</p>';
   q('[data-tpg-list]').querySelectorAll('[data-tpg-id]').forEach(b=>{b.disabled=busy;b.onclick=()=>select(Number(b.dataset.tpgId));});
  }
  async function select(id){
   const at=++ticket;selected=null;q('[data-tpg-apply]').disabled=true;q('[data-tpg-preview]').textContent='Đang tải nội dung…';q('[data-tpg-error]').textContent='';
   try{const next=await api.request('tour-library/'+id+'/document');if(!m.isConnected||at!==ticket)return;selected=next;q('[data-tpg-title]').textContent=next.program.title;q('[data-tpg-meta]').textContent=[next.program.destination,next.program.tour_code,next.program.tour_type].filter(Boolean).join(' · ');q('[data-tpg-preview]').textContent=text(next.settings.document.content);q('[data-tpg-apply]').disabled=!editable||next.immutable;render();}catch(e){if(at===ticket)fail(e);}
  }
  for(const name of ['search','status','type'])q('[data-tpg-'+name+']').addEventListener(name==='search'?'input':'change',render);
  q('[data-tpg-apply]').onclick=async()=>{
   if(!editable||!selected||busy)return;busy=true;q('[data-tpg-apply]').disabled=true;render();
   try{await beforeApply();if(!m.isConnected)return;const previous=copy(getCurrent()),choice=selected;
    const next=await api.request('tour-library/'+Number(choice.program.id)+'/apply-to-document',{method:'POST',body:{version_id:o.versionId,expected_revision:previous.costing_revision,program_revision:choice.costing_revision}});
    adopt(next,previous);m.remove();o.toast?.('Đã dùng mẫu cho tour này.');
   }catch(e){fail(e);}finally{busy=false;if(m.isConnected){render();q('[data-tpg-apply]').disabled=!editable||!selected||selected.immutable;}}
  };
  try{let offset=0,total=0;do{const r=await api.request('tour-library?limit=100&offset='+offset+'&status=ALL');if(!m.isConnected)return;if(!Array.isArray(r.items))throw Error('Chưa tải được Kho chương trình.');items.push(...r.items);offset+=r.items.length;total=Number(r.total)||0;if(!r.items.length)break;}while(offset<total&&offset<10000);render();}catch(e){q('[data-tpg-list]').textContent='Không tải được danh sách.';fail(e);}
  return m;
 }
 async function saveAs(o){
  const m=o.modal('Lưu vào Kho chương trình','<form class="tpg-dialog" data-no-translate><p>Lưu thành mẫu mới để tái sử dụng. Các bảng giá được đánh dấu sẽ được bỏ khỏi mẫu; hãy kiểm tra văn bản trước khi lưu.</p><div class="tpg-filters"><label>Tên chương trình<input name="title" required maxlength="190" value="'+esc(o.title)+'"></label><label>Mã chương trình<input name="tour_code" maxlength="50"></label><label>Điểm đến<input name="destination" maxlength="190"></label><label>Loại tour<select name="tour_type"><option value="PRIVATE">Private</option><option value="SIC">SIC</option><option value="BOTH">Private & SIC</option></select></label><label>Ngôn ngữ<select name="language"><option value="vi">Tiếng Việt</option><option value="en">English</option></select></label></div><label class="tpg-review"><input name="reviewed" type="checkbox" required>Tôi đã kiểm tra và bỏ thông tin riêng của khách, giá chưa đánh dấu và dữ liệu nội bộ khỏi nội dung mẫu.</label><p>Ảnh riêng sẽ được tạo bản sao có thể tái sử dụng, giữ nguyên quyền riêng tư. Ảnh dùng chung cần được duyệt trong Kho ảnh.</p><button class="btn primary" type="submit">Lưu mẫu mới</button><p role="alert"></p></form>');
  const form=m.querySelector('form'),button=form.querySelector('[type=submit]');let key='',signature='',busy=false;
  form.onsubmit=async e=>{e.preventDefault();if(busy||!form.reportValidity())return;busy=true;button.disabled=true;
   try{await o.beforeApply();if(!m.isConnected)return;const data=Object.fromEntries(new FormData(form));data.reviewed=form.elements.reviewed.checked;data.version_id=o.versionId;data.expected_revision=o.getCurrent().costing_revision;
    const current=JSON.stringify(data);if(signature!==current){signature=current;key=crypto.randomUUID();}data.creation_key=key;
    const r=await o.api.request('tour-library/from-quote',{method:'POST',body:data});if(!r.id)throw Error('Chưa nhận được xác nhận lưu mẫu.');m.remove();o.toast?.('Đã lưu mẫu mới vào Kho chương trình.');
   }catch(error){if(m.isConnected)form.querySelector('[role=alert]').textContent=error.message;}finally{busy=false;if(m.isConnected)button.disabled=false;}
  };return m;
 }
 async function metadata(o){
  await o.beforeApply();const current=o.getCurrent(),p=current.program;
  const m=o.modal('Thông tin chương trình','<form class="tpg-dialog" data-no-translate><div class="tpg-filters"><label>Tên chương trình<input name="title" maxlength="190" required value="'+esc(p.title)+'"></label><label>Mã chương trình<input name="tour_code" maxlength="50" value="'+esc(p.tour_code)+'"></label><label>Điểm đến<input name="destination" maxlength="190" value="'+esc(p.destination)+'"></label><label>Ngôn ngữ<input name="language" maxlength="16" required value="'+esc(p.language)+'"></label><label>Loại tour<select name="tour_type">'+['PRIVATE','SIC','BOTH'].map(x=>'<option '+(x===p.tour_type?'selected':'')+'>'+x+'</option>').join('')+'</select></label><label>Trạng thái<select name="status">'+['DRAFT','ACTIVE'].map(x=>'<option value="'+x+'" '+(x===p.status?'selected':'')+'>'+(x==='DRAFT'?'Bản nháp':'Đang dùng')+'</option>').join('')+'</select></label></div><button class="btn primary" type="submit">Lưu thông tin</button><p role="alert"></p></form>');
  const f=m.querySelector('form');f.onsubmit=async e=>{e.preventDefault();f.querySelector('button').disabled=true;try{await o.beforeApply();if(!m.isConnected)return;const ctx=o.getCurrent();const next=await o.api.request('tour-library/'+p.id+'/document',{method:'PUT',body:{expected_revision:ctx.costing_revision,settings:ctx.settings,metadata:Object.fromEntries(new FormData(f))}});o.adopt(next);m.remove();o.toast?.('Đã lưu thông tin chương trình.');}catch(error){if(m.isConnected){f.querySelector('[role=alert]').textContent=error.message;f.querySelector('button').disabled=false;}}};return m;
 }
 async function mount(host,o){
  const p=o.program;
  return window.mountVisualProposal(host,{...o,integrated:true,libraryProgram:p,quoteData:{quote:{id:0},version:{id:p.id,version_status:p.status==='ARCHIVED'?'SUPERSEDED':'DRAFT'}},can:o.can||(()=>false)});
 }
 window.VTATourProgramWorkspace={picker,saveAs,metadata,mount,read,manage};
})();

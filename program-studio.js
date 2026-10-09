(function () {
  'use strict';
  const copy = value => JSON.parse(JSON.stringify(value));
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const text = (en, vi) => window.VTA_I18N?.language === 'en' ? en : vi;
  const blank = () => ({type:'doc',content:[{type:'paragraph'}]});
  const paragraph = value => value ? {type:'paragraph',content:[{type:'text',text:String(value)}]} : {type:'paragraph'};
  const heading = value => ({type:'heading',attrs:{level:2},content:[{type:'text',text:String(value)}]});
  function fromProgram(program) {
    if (program.document?.schema === 'VTA_DOC_2') return copy(program.document);
    const nodes = [];
    for (const day of program.days || []) {
      nodes.push(heading((day.title ? 'Day '+day.day+' · '+day.title : 'Day '+day.day)));
      String(day.description || '').split(/\r?\n/).forEach(line => nodes.push(paragraph(line)));
      if (day.meals || day.overnight) nodes.push(paragraph([day.meals,day.overnight].filter(Boolean).join(' · ')));
    }
    for (const [key, label] of [['included_text','Included'],['excluded_text','Excluded'],['terms_text','Terms']]) {
      if (program[key]) {nodes.push(heading(label));String(program[key]).split(/\r?\n/).forEach(line => nodes.push(paragraph(line)));}
    }
    if (!nodes.length && program.source_text) String(program.source_text).split(/\r?\n/).forEach(line => nodes.push(paragraph(line)));
    return {schema:'VTA_DOC_2',title:program.title || '',content:nodes.length ? {type:'doc',content:nodes} : blank()};
  }
  function linksFor(document) {
    const ids = new Set();
    const walk = node => {if(node.type === 'image')ids.add(Number(node.attrs.assetId));(node.content || []).forEach(walk);};
    walk(document.content);
    return [...ids].map((id, i) => ({asset_id:id,role:'SERVICE',day_key:'',reference_key:'FREEFORM',caption:'',sort_order:i}));
  }
  function picker({api, modal, closeModal, onChoose}) {
    const m = modal(text('Program library','Kho chương trình'),'<div class="studio-picker" data-no-translate><p>'+text('Use a copy; the source template stays independent.','Dùng bản sao riêng cho hồ sơ tour; mẫu gốc giữ nguyên.')+'</p><label>'+text('Search programs','Tìm chương trình')+'<input type="search" data-studio-search placeholder="Hanoi, Halong…"></label><div data-studio-results role="status"></div></div>','',true);
    const results = m.querySelector('[data-studio-results]');let sequence = 0, timer;
    async function search() {
      const ticket = ++sequence;results.textContent = text('Loading…','Đang tải…');
      try {
        const query = m.querySelector('[data-studio-search]').value.trim();
        const response = await api.request('tour-library?limit=50&q='+encodeURIComponent(query));
        if(!m.isConnected || ticket !== sequence)return;
        if(!Array.isArray(response.items))throw Error(text('Incomplete library response.','Dữ liệu kho chưa đầy đủ.'));
        const items = response.items.filter(p => p.status !== 'ARCHIVED');
        results.innerHTML = items.map(p => '<article class="studio-pick-row"><div><strong>'+esc(p.title)+'</strong><small>'+esc(p.destination || '')+' · '+esc(p.language || '')+(p.day_count?' · '+Number(p.day_count)+' '+text('days','ngày'):'')+'</small></div><button class="btn" data-studio-template="'+Number(p.id)+'">'+text('Use copy','Dùng bản sao')+'</button></article>').join('') || '<p>'+text('No matching programs.','Chưa có chương trình phù hợp.')+'</p>';
        if(Number(response.total)>50)results.insertAdjacentHTML('beforeend','<p>'+text('Showing the first 50 results. Refine your search.','Hiển thị 50 kết quả đầu. Nhập tên hoặc điểm đến để tìm tiếp.')+'</p>');
        results.querySelectorAll('[data-studio-template]').forEach(button => button.onclick = async () => {
          button.disabled = true;
          try {
            const result = await api.request('tour-library/'+button.dataset.studioTemplate);
            if(!m.isConnected)return;
            if(!result.program || result.program.status === 'ARCHIVED')throw Error(text('Program is unavailable.','Chương trình không còn khả dụng.'));
            await onChoose(result.program,m,closeModal);
          } catch(error) {if(m.isConnected){let note=m.querySelector('[data-studio-picker-error]');if(!note){note=document.createElement('p');note.dataset.studioPickerError='';note.setAttribute('role','alert');results.prepend(note);}note.textContent=error.message;button.disabled=false;}}
        });
      } catch(error) {if(m.isConnected && ticket===sequence)results.textContent=error.message;}
    }
    m.querySelector('[data-studio-search]').oninput = () => {clearTimeout(timer);timer=setTimeout(search,220);};
    search();
  }
  function saveTemplate({api, modal, closeModal, toast, document, sourceText, days}) {
    const m=modal(text('Save a program copy','Lưu bản sao vào kho'),'<form class="studio-template-form" data-no-translate><p>'+text('Create an independent draft template.','Tạo mẫu nháp độc lập để tái sử dụng.')+'</p><label>'+text('Program name','Tên chương trình')+'<input name="title" required maxlength="190" value="'+esc(document.title)+'"></label><label>'+text('Destinations','Điểm đến')+'<input name="destination" maxlength="190"></label><label>'+text('Language','Ngôn ngữ')+'<select name="language"><option value="en">English</option><option value="vi">Tiếng Việt</option></select></label><button class="btn primary" type="submit">'+text('Save to library','Lưu vào kho')+'</button><p role="alert"></p></form>');
    let creationKey=crypto.randomUUID(),signature='',preparedDocument=null;
    m.querySelector('form').onsubmit=async event=>{
      event.preventDefault();const form=event.target,button=form.querySelector('button');button.disabled=true;
      try {
        if(!preparedDocument){
          const prepared=await api.request('tour-library/prepare-document',{method:'POST',body:{document:copy(document)}});
          preparedDocument=prepared.document;
        }
        const prepared={document:copy(preparedDocument)};
        if(!prepared.document)throw Error(text('Template document was not prepared.','Chưa chuẩn bị được nội dung mẫu.'));
        prepared.document.title=form.elements.title.value.trim();
        const body={title:prepared.document.title,destination:form.elements.destination.value.trim(),language:form.elements.language.value,tags:[],status:'DRAFT',days:(days||[]).map((d,i)=>({day:i+1,date:'',title:d.title||'',description:d.description||'',meals:d.meals||'',overnight:d.overnight||'',notes:''})),included_text:'',excluded_text:'',terms_text:'',source_type:'MANUAL',source_text:sourceText,document:prepared.document};
        const next=JSON.stringify(body);if(signature && signature!==next)creationKey=crypto.randomUUID();signature=next;
        const saved=await api.request('tour-library',{method:'POST',body:{...body,creation_key:creationKey}});
        if(!saved.id)throw Error(text('Save was not confirmed.','Chưa nhận được xác nhận lưu.'));
        closeModal();toast(text('Independent program copy saved.','Đã lưu bản sao chương trình vào kho.'));
      } catch(error) {if(m.isConnected){form.querySelector('[role=alert]').textContent=error.message;button.disabled=false;}}
    };
  }
  window.VtaProgramTools={fromProgram,linksFor,picker,saveTemplate};
  window.VTATourProposalStudio={mount:async function(host,{program,api,can,modal,closeModal,toast,canEdit,onBack}) {
    let current=copy(program),revision=1;
    const permission=can || (p=>api.user?.permissions?.includes(p));
    if(!current.id){
      const created=await api.request('tour-library',{method:'POST',body:{...current,document:fromProgram(current),creation_key:crypto.randomUUID()}});
      if(!created.id)throw Error(text('New program was not saved.','Chưa lưu được chương trình mới.'));
      current=created.program;
    }
    if(!host.isConnected || host.dataset.view!=='product')return;
    const base='tour-library/'+current.id;
    const editable=canEdit && current.status!=='ARCHIVED';
    const context=()=>({settings:{document:fromProgram(current)},links:linksFor(fromProgram(current)),days:[],selling_options:[],costing_revision:revision,immutable:!editable});
    const studioApi={request:async(route,opt={})=>{
      if(route===base){
        if(opt.method==='PUT'){
          const meta=host.querySelector('[data-program-meta]');
          const body={...current,title:opt.body.settings.document.title,document:opt.body.settings.document,expected_content_hash:current.content_hash};
          if(meta){for(const key of ['destination','language','status'])body[key]=meta.elements[key].value;body.tags=meta.elements.tags.value.split(',').map(s=>s.trim()).filter(Boolean);}
          const saved=await api.request(base,{method:'PUT',body});if(!saved.program)throw Error(text('Save was not confirmed.','Chưa nhận được xác nhận lưu.'));current=saved.program;revision++;
        }else{const saved=await api.request(base);current=saved.program;}
        return context();
      }
      if(route===base+'/document-import')return api.request('tour-library/document-import',opt);
      if(route==='media/upload'){opt.form.delete('quote_id');opt.form.set('visibility','COMPANY');}
      return api.request(route,opt);
    }};
    const result=await window.mountVisualProposal(host,{api:studioApi,documentBase:base,programMode:true,integrated:true,quoteData:{quote:{id:0,quote_ref:'PROGRAM-'+current.id},version:{id:current.id,version_no:1,version_status:editable?'DRAFT':'SENT',tour_name:current.title}},can:p=>['quote.edit','proposal.edit'].includes(p)?editable:permission(p),modal,closeModal,toast,onBack});
    const meta=document.createElement('details');meta.className='studio-details';
    meta.innerHTML='<summary>'+text('Program information','Thông tin chương trình')+'</summary><form data-program-meta class="studio-meta-grid" data-no-translate><label>'+text('Destinations','Điểm đến')+'<input name="destination" maxlength="190" value="'+esc(current.destination)+'"></label><label>'+text('Language','Ngôn ngữ')+'<select name="language">'+['en','vi'].map(l=>'<option '+(current.language===l?'selected ':'')+'value="'+l+'">'+l.toUpperCase()+'</option>').join('')+'</select></label><label>Tags<input name="tags" value="'+esc((current.tags||[]).join(', '))+'"></label><label>'+text('Status','Trạng thái')+'<select name="status"><option value="DRAFT" '+(current.status==='DRAFT'?'selected':'')+'>'+text('Draft','Bản nháp')+'</option><option value="ACTIVE" '+(current.status==='ACTIVE'?'selected':'')+'>'+text('Active','Đang dùng')+'</option></select></label></form>';
    host.querySelector('.studio-actions')?.after(meta);
    meta.querySelector('form').onsubmit=e=>e.preventDefault();
    meta.querySelectorAll('input,select').forEach(field=>{field.disabled=!editable;field.onchange=()=>result.markDirty();});
    return result;
  }};
})();

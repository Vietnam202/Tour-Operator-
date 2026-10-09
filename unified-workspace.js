/* Unified navigation over the existing modules. No client-side pricing or permission expansion. */
(function () {
  'use strict';
  const safe = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const t = (en, vi) => window.VTA_I18N?.language === 'en' ? en : vi;
  const any = (can, permissions) => permissions.some(can);
  const numberID = value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
  const salesViews = ['sales', 'sales-list', 'crm', 'customer-360', 'leads', 'b2b'];
  const tourViews = ['tours', 'quote', 'quote-confirmations', 'booking', 'operations'];
  let observer = null;

  function navItems(can) {
    return [
      {id:'home', label:'Dashboard', icon:'◫', group:'WORKSPACES'},
      ...(any(can, ['sales.view','lead.view','booking.view','b2b.admin','b2b.portal']) ? [{id:'sales',label:'Sales & B2B',icon:'◎',group:'WORKSPACES'}] : []),
      ...(any(can, ['sales.view','quote.confirm','booking.view','operations.view','task.view']) ? [{id:'tours',label:'Tour Workspace',icon:'▣',group:'WORKSPACES'}] : []),
      ...(any(can, ['tour_library.view','product.view','supplier.view']) ? [{id:'product',label:'Tour Library',icon:'▤',group:'WORKSPACES'}] : []),
      ...(any(can, ['supplier.view','rate.view','document.view']) ? [{id:'suppliers',label:'Suppliers',icon:'◇',group:'WORKSPACES'}] : []),
      ...(can('lead.view') ? [{id:'marketing',label:'Marketing',icon:'◈',group:'WORKSPACES'}] : []),
      ...(any(can, ['report.view','finance.view','customer_ar.view','supplier_ap.view','profit.view']) ? [{id:'reports',label:'Reports',icon:'▥',group:'MANAGEMENT'}] : []),
      {id:'settings',label:'Settings',icon:'⚙',group:'MANAGEMENT'}
    ];
  }

  function activeNav(view) {
    if (salesViews.includes(view)) return 'sales';
    if (tourViews.includes(view)) return 'tours';
    if (view === 'inventory') return 'product';
    if (view === 'documents') return 'tours';
    if (view === 'finance') return 'reports';
    if (['modules','settings-admin'].includes(view)) return 'settings';
    if (view === 'control') return 'home';
    return view;
  }

  function tabsHTML(tabs, active, label) {
    return '<nav class="uw-tabs" aria-label="'+safe(label)+'">'+tabs.map(tab =>
      '<button type="button" data-uw-tab="'+safe(tab.id)+'" class="'+(tab.id===active?'active':'')+'" aria-current="'+(tab.id===active?'page':'false')+'" '+(tab.disabled?'disabled title="'+safe(tab.reason||t('Access required','Cần quyền truy cập'))+'"':'')+'>'+safe(tab.label)+'</button>'
    ).join('')+'</nav>';
  }

  function salesTabs(can) {
    return [
      {id:'pipeline',label:'Pipeline',route:'sales',disabled:!any(can,['lead.view','sales.view','booking.view'])},
      {id:'inquiries',label:'Inquiry',route:can('sales.view')?'sales-list':'leads',params:{salesTab:'inquiries'},disabled:!any(can,['sales.view','lead.view'])},
      {id:'partners',label:'B2B Partners',route:'b2b',params:{b2bTab:'partners'}},
      {id:'rates',label:'Rate & Contract',route:'b2b',params:{b2bTab:'rates'}}
    ];
  }

  function stop() { observer?.disconnect(); observer = null; }

  function attach(root, options) {
    stop();
    const {state,can,navigate,api,isCurrent} = options;
    const view = state.view;
    const alive = () => root.isConnected && root.dataset.view===view && (!isCurrent || isCurrent());
    const group = salesViews.includes(view)?'sales':tourViews.includes(view)?'tour':view==='finance'||view==='reports'?'reports':view==='settings-admin'||view==='modules'?'settings':view==='documents'?'documents':'';
    root.dataset.unified = group;
    const decorate = () => {
      if (!alive() || !group || root.querySelector(':scope > [data-unified-context]')) return;
      let title='',subtitle='',kicker='',active='',tabs=[],actions=[];
      if (group==='sales') {
        title='Sales & B2B'; kicker=t('COMMERCIAL WORKSPACE','KHÔNG GIAN KINH DOANH');
        subtitle=t('Pipeline, inquiries, customers and agency partners in one place.','Pipeline, inquiry, khách hàng và đối tác đại lý trong cùng một nơi.');
        active=view==='b2b'?(state.b2bTab==='rates'?'rates':'partners'):['sales-list','crm','customer-360','leads'].includes(view)?'inquiries':'pipeline';
        tabs=salesTabs(can);
        if(can('sales.view')) actions.push({label:t('Customers / CRM','Khách hàng / CRM'),route:'crm',params:{crmTab:'customers'}});
        if(can('inquiry.manage')) actions.push({label:t('+ New inquiry','+ Inquiry mới'),callback:options.newInquiry,primary:true});
      } else if (group==='tour' && ['quote','booking'].includes(view)) {
        const quote=state.quoteData,booking=state.bookingData;
        const record=view==='quote'?quote?.version:booking?.booking;
        if(!record) return;
        title=view==='quote'?(record.tour_name||quote.quote.quote_ref):(booking.commercial?.tour_name||booking.trip?.title||record.booking_ref);
        kicker=t('TOUR WORKSPACE','HỒ SƠ TOUR');
        const ref=view==='quote'?quote.quote.quote_ref:record.booking_ref;
        subtitle=[ref,view==='quote'?'V'+record.version_no:'Booking',record.start_date,record.end_date?('→ '+record.end_date):'',record.total_guests!=null?record.total_guests+' '+t('guests','khách'):''].filter(Boolean).join(' · ');
        active=view==='quote'?(state.quoteTool==='profile'?'profile':state.quoteTool==='visual'?'program':root.dataset.sheetStep==='price'||['price','send'].includes(state.quoteStep)&&record.costing_engine!=='VS2_1'?'proposal':state.quoteStep==='info'&&record.costing_engine!=='VS2_1'?'profile':'cost'):state.bookingTab==='services'||state.bookingTab==='suppliers'?'operations':['documents','activity','finance','handover'].includes(state.bookingTab)?'profile':'booking';
        if(view==='quote'&&!can('sales.view')) active='proposal';
        const quoteAccess=can('sales.view')||can('quote.confirm');
        const sourceID=view==='quote'?Number(quote.quote.id):numberID(record.quote_id||booking.commercial?.quote_id);
        const switchQuote = (tool, step) => {
          if(!sourceID) return options.toast(t('No source quote is linked to this booking.','Booking chưa có báo giá nguồn.'),true);
          root.dataset.sheetStep=step||'';
          // Display state must never carry a selected variant into another quote.
          if(view==='booking') { delete root.dataset.vs21Version; delete root.dataset.vs21Variant; delete root.dataset.vtaCostVariants; }
          navigate('quote',{quoteId:sourceID,quoteTool:tool,quoteStep:step==='price'?'price':'cost'});
        };
        const openBooking = async tab => {
          if(view==='booking') return navigate('booking',{bookingId:Number(record.id),bookingTab:tab});
          const button=root.querySelector('[data-uw-tab="'+(tab==='services'?'operations':'booking')+'"]');
          if(button)button.disabled=true;
          try {
            const response=await api.request('bookings');
            if(!alive()) return;
            if(!Array.isArray(response.items))throw Error(t('Incomplete booking response.','Dữ liệu booking chưa đầy đủ.'));
            const target=response.items.find(row=>Number(row.quote_id)===sourceID);
            if(target) return navigate('booking',{bookingId:Number(target.id),bookingTab:tab});
            options.toast(t('No booking yet. Confirm the issued quote and use Create / Open Booking below.','Chưa có Booking. Xác nhận báo giá đã phát hành, sau đó chọn Tạo / Mở Booking ở bên dưới.'));
            root.querySelector('[data-quote-confirmation]')?.scrollIntoView?.({block:'center'});
          } catch(error) { if(alive())options.toast(error.message,true); }
          finally { if(button?.isConnected)button.disabled=false; }
        };
        tabs=[
          {id:'program',label:t('Program','Chương trình'),disabled:!can('sales.view')||!sourceID,callback:()=>switchQuote('visual','')},
          {id:'cost',label:t('Costing','Tính giá'),disabled:!can('sales.view')||!can('quote.view_cost')||!sourceID,callback:()=>switchQuote('','')},
          {id:'proposal',label:t('Quotation','Báo giá'),disabled:!quoteAccess||!sourceID,callback:()=>switchQuote('','price')},
          {id:'booking',label:'Booking',disabled:!can('booking.view'),callback:()=>openBooking('overview')},
          {id:'operations',label:t('Operations','Điều hành'),disabled:!can('booking.view'),callback:()=>openBooking('services')},
          {id:'profile',label:t('Files & history','Hồ sơ'),callback:()=>view==='quote'?switchQuote('profile',''):navigate('booking',{bookingId:Number(record.id),bookingTab:'documents'})}
        ];
        actions.push({label:t('← All tours','← Danh sách tour'),route:'tours'});
        if(view==='quote'&&root.querySelector('[data-info]'))actions.push({label:t('Guest information','Thông tin khách'),callback:()=>root.querySelector('[data-info]')?.click()});
        if(view==='booking'&&can('travel_document.view'))actions.push({label:t('Travel documents','Chứng từ tour'),callback:()=>options.openTravelDocuments(Number(record.id))});
      } else if (group==='tour') {
        title=t('Tour Workspace','Hồ sơ tour'); kicker=t('DELIVERY WORKSPACE','HỒ SƠ & ĐIỀU HÀNH');
        subtitle=t('Program → Costing → Quotation → Booking → Operations → Files.','Chương trình → Tính giá → Báo giá → Booking → Điều hành → Hồ sơ.');
        tabs=[{id:'records',label:t('All tour files','Danh sách hồ sơ'),route:'tours'},...(any(can,['operations.view','booking.view','task.view'])?[{id:'operations',label:t('Operations center','Trung tâm điều hành'),route:'operations'}]:[]),...(can('quote.confirm')?[{id:'confirmations',label:t('Quote confirmations','Xác nhận báo giá'),route:'quote-confirmations'}]:[])];
        active=view==='operations'?'operations':view==='quote-confirmations'?'confirmations':'records';
      } else if (group==='reports') {
        title=t('Reports & Finance','Báo cáo & Tài chính');kicker=t('BUSINESS CONTROL','QUẢN TRỊ KINH DOANH');
        subtitle=t('Performance, receivables, payables and reconciliation remain permission-scoped.','Hiệu quả, công nợ phải thu/phải trả và đối soát theo phân quyền.');
        tabs=[...(can('report.view')?[{id:'summary',label:t('Reports','Báo cáo'),route:'reports'}]:[]),...(any(can,['finance.view','customer_ar.view','supplier_ap.view','profit.view'])?[{id:'finance',label:t('Finance','Tài chính'),route:'finance'}]:[])];
        active=view==='finance'?'finance':'summary';
      } else if (group==='settings') {
        title=t('Settings','Cài đặt');kicker='VTA WORKSPACE';subtitle=t('Account, integrations and administration.','Tài khoản, kết nối và quản trị.');
        tabs=[{id:'settings',label:t('Overview','Tổng quan'),route:'settings'},{id:'modules',label:t('Apps & connections','Ứng dụng & kết nối'),route:'modules'},...(any(can,['settings.manage','user.manage'])?[{id:'admin',label:t('Business / Users','Doanh nghiệp / Người dùng'),route:'settings-admin'}]:[])];
        active=view==='settings-admin'?'admin':'modules';
      } else {
        title=t('Documents','Tài liệu dùng chung');subtitle=t('Source documents stay under their original access rules.','Chứng từ gốc giữ nguyên quy tắc truy cập.');kicker='VTA WORKSPACE';
        actions=[{label:t('Tour files','Hồ sơ tour'),route:'tours'}];
      }
      const bar=document.createElement('section');bar.dataset.unifiedContext='';bar.className='uw-context';bar.setAttribute('data-no-translate','');
      bar.innerHTML='<header class="uw-heading"><div><span class="uw-kicker">'+safe(kicker)+'</span><h1>'+safe(title)+'</h1><p>'+safe(subtitle)+'</p></div><div class="uw-actions">'+actions.map((a,i)=>'<button class="btn '+(a.primary?'primary':'')+'" type="button" data-uw-action="'+i+'">'+safe(a.label)+'</button>').join('')+'</div></header>'+tabsHTML(tabs,active,title);
      root.prepend(bar);
      if(window.VTA_PREVIEW&&view==='quote')root.querySelectorAll('[data-wd="docx"],[data-wd="pdf"],[data-wd="preview"]').forEach(button=>{button.disabled=true;button.title=t('Word/PDF export requires the tested backend, not sample preview data.','Xuất Word/PDF cần backend đã kiểm thử, không dùng dữ liệu preview mẫu.');});
      bar.querySelectorAll('[data-uw-action]').forEach(b=>b.onclick=()=>{const a=actions[Number(b.dataset.uwAction)];if(alive())a.callback?a.callback():navigate(a.route,a.params||{});});
      bar.querySelectorAll('[data-uw-tab]').forEach(b=>b.onclick=()=>{const tab=tabs.find(a=>a.id===b.dataset.uwTab);if(alive()&&!tab.disabled)tab.callback?tab.callback():navigate(tab.route,tab.params||{});});
    };
    decorate();
    // Modules may redraw themselves after a save/refresh. Keep the shared context,
    // without observing editable document text or changing its event handlers.
    observer=new MutationObserver(()=>{if(alive())decorate();});
    observer.observe(root,{childList:true});
  }

  async function renderTours(root, {api,can,navigate,isCurrent}) {
    const alive=()=>root.isConnected&&root.dataset.view==='tours'&&(!isCurrent||isCurrent());
    const parts=await Promise.allSettled([
      can('sales.view')?api.request('quotes'):Promise.resolve(null),
      can('booking.view')?api.request('bookings'):Promise.resolve(null)
    ]);
    if(!alive())return;
    const rows=[],warnings=[];
    parts.forEach((part,index)=>{
      if(part.status==='rejected'){warnings.push((index?t('Bookings','Booking'):t('Quotes','Báo giá'))+': '+part.reason.message);return;}
      if(part.value===null)return;
      if(!Array.isArray(part.value.items)){warnings.push(t('Incomplete record response.','Dữ liệu hồ sơ chưa đầy đủ.'));return;}
      part.value.items.forEach(row=>{if(numberID(row.id))rows.push({kind:index?'booking':'quote',id:Number(row.id),ref:index?row.booking_ref:row.quote_ref,title:row.tour_name||row.title||row.lead_guest_name||row.quote_ref,contact:row.lead_guest_name||row.lead_contact_name||'',start:row.start_date,end:row.end_date,pax:row.total_guests,status:row.version_status||row.operations_status||row.status||'DRAFT'});});
    });
    root.innerHTML='<div class="page uw-records" data-no-translate>'+previewNotice()+warnings.map(message=>'<div class="uw-notice" role="alert">'+safe(message)+'</div>').join('')+
      '<div class="uw-stats">'+metric(rows.filter(r=>r.kind==='quote').length,t('Listed quotes','Báo giá đang hiển thị'))+metric(rows.filter(r=>r.kind==='booking').length,t('Listed bookings','Booking đang hiển thị'))+metric(rows.filter(r=>['DRAFT','NEEDS_REVIEW','PART_CONFIRMED'].includes(r.status)).length,t('Listed records to review','Hồ sơ hiển thị cần rà soát'))+'</div>'+
      '<div class="uw-filter"><label>'+t('Find tour / guest / reference','Tìm tour / tên khách / mã hồ sơ')+'<input type="search" data-uw-search placeholder="'+t('Search current list…','Tìm trong danh sách hiện tại…')+'"></label><label>'+t('Record type','Loại hồ sơ')+'<select data-uw-kind><option value="">'+t('All records','Tất cả hồ sơ')+'</option><option value="quote">'+t('Quotation','Báo giá')+'</option><option value="booking">Booking</option></select></label></div>'+
      '<section class="panel"><div class="uw-table-wrap"><table class="table uw-record-table"><thead><tr>'+[t('Tour file','Hồ sơ tour'),t('Contact','Khách / liên hệ'),t('Travel dates','Ngày đi'),t('Guests','Số khách'),t('Status','Trạng thái'),''].map(x=>'<th>'+safe(x)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(row=>'<tr data-uw-record="'+row.kind+':'+row.id+'"><td><small class="uw-ref">'+safe(row.ref)+'</small><strong>'+safe(row.title)+'</strong><span class="uw-type">'+(row.kind==='quote'?t('Quotation','Báo giá'):'Booking')+'</span></td><td>'+safe(row.contact||'—')+'</td><td>'+safe(row.start||'—')+(row.end?'<small>→ '+safe(row.end)+'</small>':'')+'</td><td>'+safe(row.pax??'—')+'</td><td><span class="uw-status">'+safe(row.status.replaceAll('_',' '))+'</span></td><td><button class="btn small" data-uw-open="'+row.kind+':'+row.id+'">'+t('Open →','Mở →')+'</button></td></tr>').join('')+'</tbody></table><div class="empty" data-uw-empty hidden>'+t('No matching tour files.','Chưa có hồ sơ phù hợp.')+'</div></div></section>'+
      '<p class="uw-footnote">'+t('Quotes and bookings are separate stages of the same trip; they are not added into a business KPI. Lists show up to 300 records per source.','Báo giá và booking là hai giai đoạn của cùng chuyến đi, không cộng thành KPI kinh doanh. Danh sách hiển thị tối đa 300 bản ghi mỗi nguồn.')+'</p></div>';
    const filter=()=>{const query=root.querySelector('[data-uw-search]').value.trim().toLocaleLowerCase('vi'),kind=root.querySelector('[data-uw-kind]').value;let visible=0;root.querySelectorAll('[data-uw-record]').forEach(tr=>{const row=rows.find(r=>r.kind+':'+r.id===tr.dataset.uwRecord);tr.hidden=!!(kind&&kind!==row.kind)||![row.ref,row.title,row.contact].join(' ').toLocaleLowerCase('vi').includes(query);if(!tr.hidden)visible++;});root.querySelector('[data-uw-empty]').hidden=visible!==0;};
    root.querySelector('[data-uw-search]').oninput=filter;root.querySelector('[data-uw-kind]').onchange=filter;
    root.querySelectorAll('[data-uw-open]').forEach(button=>button.onclick=()=>{if(!alive())return;const [kind,id]=button.dataset.uwOpen.split(':');navigate(kind,kind==='quote'?{quoteId:Number(id),quoteTool:'visual'}:{bookingId:Number(id),bookingTab:'overview'});});
    filter();
  }

  const metric=(value,label)=>'<article class="uw-stat"><small>'+safe(label)+'</small><strong>'+safe(value)+'</strong></article>';
  const previewNotice=()=>window.VTA_PREVIEW?'<div class="uw-preview" role="status">'+t('PREVIEW · Sample data only. No live publication, payment or booking is sent.','PREVIEW · Dữ liệu mẫu. Không phát hành, thanh toán hoặc gửi booking thật.')+'</div>':'';

  async function renderB2B(root, {api,can,state,navigate,modal,closeModal,toast,isCurrent}) {
    const tab=state.b2bTab==='rates'?'rates':'partners',admin=can('b2b.admin'),portal=can('b2b.portal');
    const alive=()=>root.isConnected&&root.dataset.view==='b2b'&&(state.b2bTab==='rates'?'rates':'partners')===tab&&(!isCurrent||isCurrent());
    if(!admin&&!portal){root.innerHTML='<div class="page" data-no-translate><section class="panel"><div class="panel-body"><h2>'+t('B2B Partner Hub','B2B Partner Hub')+'</h2><p>'+t('The B2B module is integrated. Your account needs b2b.admin or b2b.portal to access agencies and approved NET publications. Ask an authorized administrator to assign access.','Module B2B đã tích hợp. Tài khoản cần quyền b2b.admin hoặc b2b.portal để truy cập đại lý và bảng NET đã duyệt. Hãy nhờ quản trị viên được phép cấp quyền.')+'</p>'+(can('sales.view')?'<button class="btn" data-uw-agents>'+t('Open existing agent CRM','Mở CRM đại lý hiện có')+'</button>':'')+'</div></section></div>';root.querySelector('[data-uw-agents]')?.addEventListener('click',()=>navigate('crm',{crmTab:'agents'}));return;}
    const request=(route,options)=>window.VTA_PREVIEW&&window.VTAUnifiedDemo?window.VTAUnifiedDemo.request(route,options):api.request(route,options);
    const me=await request('b2b/me');
    if(!alive())return;
    if(!Array.isArray(me.agencies))throw Error(t('Incomplete B2B response.','Dữ liệu B2B chưa đầy đủ.'));
    // Effective permissions and server response must both agree before using admin routes.
    const isAdmin=admin&&me.admin===true;
    let agencies=me.agencies,publications=[],requests=[];
    if(isAdmin){const results=await Promise.all([request(tab==='rates'?'b2b/admin/publications':'b2b/admin/agencies'),tab==='partners'?request('b2b/admin/requests'):Promise.resolve({items:[]})]);if(!alive())return;if(results.some(r=>!Array.isArray(r.items)))throw Error(t('Incomplete B2B response.','Dữ liệu B2B chưa đầy đủ.'));if(tab==='rates')publications=results[0].items;else{agencies=results[0].items;requests=results[1].items;}}
    else if(tab==='rates'){const r=await request('b2b/tours');if(!alive())return;if(!Array.isArray(r.items))throw Error('Incomplete B2B tours');publications=r.items;}
    const link='partner.html#'+(tab==='rates'&&isAdmin?'admin':'library');
    root.innerHTML='<div class="page uw-b2b" data-no-translate>'+previewNotice()+'<div class="uw-stats">'+metric(agencies.length,t('Accessible agencies','Đại lý được phép xem'))+metric(tab==='rates'?publications.length:requests.length,tab==='rates'?t('Listed publications','Bản phát hành hiển thị'):t('Booking requests','Yêu cầu booking'))+metric(isAdmin?t('Admin','Quản trị'):t('Partner','Đối tác'),t('Effective B2B access','Quyền B2B hiện tại'))+'</div>'+
      '<section class="panel"><div class="panel-head"><div><h2>'+t(tab==='rates'?'Approved NET & publications':'Agency partners',tab==='rates'?'Bảng NET & phiên bản phát hành':'Đối tác đại lý')+'</h2><p class="muted">'+t(tab==='rates'?'Prices come from locked quote snapshots, not imported Word price tables.':'B2B agency accounts are isolated from internal customer / supplier data.',tab==='rates'?'Giá lấy từ báo giá đã khóa, không lấy tự động từ bảng giá trong Word.':'Tài khoản đại lý B2B tách biệt với dữ liệu khách và nhà cung cấp nội bộ.')+'</p></div><div class="actions">'+(tab==='partners'&&isAdmin?'<button class="btn primary" data-uw-new-agency>'+t('+ Add agency','+ Thêm đại lý')+'</button>':'')+(!window.VTA_PREVIEW?'<a class="btn" href="'+link+'" target="_blank" rel="noopener">'+t('Open Partner Hub ↗','Mở Partner Hub ↗')+'</a>':'')+'</div></div><div class="uw-table-wrap">'+(tab==='partners'?agencyTable(agencies):publicationTable(publications))+'</div></section>'+
      (tab==='partners'&&isAdmin?'<section class="panel"><div class="panel-head"><h2>'+t('Partner booking requests','Yêu cầu booking từ đối tác')+'</h2></div><div class="uw-table-wrap">'+requestTable(requests)+'</div></section>':'')+
      '<div class="uw-notice">'+t(tab==='rates'?'Rate & Contract groups publication and approved NET access. No signed-contract workflow is claimed; supplier source contracts remain in Supplier Vault and are never exposed to partners.':'A partner booking request is not a confirmed booking. VTA reviews and confirms through the existing Sales → Booking workflow.',tab==='rates'?'Rate & Contract gom phiên bản phát hành và truy cập NET đã duyệt. Chưa có quy trình ký hợp đồng riêng; hợp đồng nguồn nhà cung cấp vẫn ở Supplier Vault và không hiển thị cho đối tác.':'Yêu cầu booking từ đại lý chưa phải booking đã xác nhận. VTA rà soát và xác nhận theo luồng Sales → Booking hiện có.')+'</div></div>';
    root.querySelector('[data-uw-new-agency]')?.addEventListener('click',()=>{
      if(!alive()||!can('b2b.admin'))return;
      const m=modal(t('Add B2B agency','Thêm đại lý B2B'),'<form data-uw-agency-form><label>'+t('Agency name','Tên đại lý')+'<input name="agency_name" required maxlength="190"></label><p>'+t('Membership and branding are configured separately in Partner Hub.','Phân quyền thành viên và thương hiệu cấu hình riêng trong Partner Hub.')+'</p><button class="btn primary">'+t('Create agency','Tạo đại lý')+'</button></form>');
      m.querySelector('form').onsubmit=async event=>{event.preventDefault();if(!alive()||!can('b2b.admin'))return;const form=event.target,button=form.querySelector('button');button.disabled=true;try{await request('b2b/admin/agencies',{method:'POST',body:{agency_name:form.elements.agency_name.value.trim()}});closeModal();if(alive()){toast(t('Agency created','Đã tạo đại lý'));await navigate('b2b',{b2bTab:'partners'});}}catch(error){toast(error.message,true);}finally{if(button.isConnected)button.disabled=false;}};
    });
  }

  const table=(heads,rows)=>'<table class="table"><thead><tr>'+heads.map(h=>'<th>'+safe(h)+'</th>').join('')+'</tr></thead><tbody>'+rows.join('')+'</tbody></table>'+(rows.length?'':'<div class="empty">'+t('No records yet.','Chưa có dữ liệu.')+'</div>');
  function agencyTable(rows){return table([t('Agency / brand','Đại lý / thương hiệu'),'Email','WhatsApp',t('Status','Trạng thái')],rows.map(r=>'<tr><td><strong>'+safe(r.agency_name||r.name)+'</strong><small>'+safe(r.brand_name)+'</small></td><td>'+safe(r.email||'—')+'</td><td>'+safe(r.whatsapp||'—')+'</td><td><span class="uw-status">'+safe(r.status||'ACTIVE')+'</span></td></tr>'));}
  function publicationTable(rows){return table([t('Published program','Chương trình phát hành'),t('Version','Phiên bản'),t('Status','Trạng thái')],rows.map(r=>'<tr><td><strong>'+safe(r.title)+'</strong><small>'+safe(r.destination||('#'+r.program_id))+'</small></td><td>V'+safe(r.version_no??r.version)+'</td><td><span class="uw-status">'+safe(r.status|| (r.has_approved_net?t('Approved NET','Có NET đã duyệt'):t('NET on request','NET theo yêu cầu')))+'</span></td></tr>'));}
  function requestTable(rows){return table([t('Agency','Đại lý'),t('Departure','Ngày khởi hành'),t('Paying guests','Khách trả tiền'),t('Status','Trạng thái')],rows.map(r=>'<tr><td>'+safe(r.agency_name)+'</td><td>'+safe(r.departure_date)+'</td><td>'+safe(r.paying_pax)+'</td><td><span class="uw-status">'+safe(r.status)+'</span></td></tr>'));}

  function renderSettingsHub(root,{can,navigate}) {
    const cards=[{title:t('Apps & connections','Ứng dụng & kết nối'),description:t('Install PWA, integrations and sign out.','Cài PWA, kết nối dùng chung và đăng xuất.'),route:'modules',icon:'▦'},...(any(can,['settings.manage','user.manage'])?[{title:t('Company & permissions','Doanh nghiệp & phân quyền'),description:t('Business defaults and authorized user management.','Thiết lập doanh nghiệp và quản lý người dùng được cấp quyền.'),route:'settings-admin',icon:'⚙'}]:[]),...(can('lead.view')?[{title:t('Marketing connections','Kết nối Marketing'),description:t('Social accounts, verified publishing and inbox.','Tài khoản mạng xã hội, phát hành đã kiểm tra và inbox.'),route:'marketing',params:{marketingTab:'channels'},icon:'◈'}]:[])];
    root.innerHTML='<div class="page uw-settings" data-no-translate><header class="uw-heading"><div><span class="uw-kicker">VTA WORKSPACE</span><h1>'+t('Settings','Cài đặt')+'</h1><p>'+t('Only authorized settings are available to your account.','Chỉ hiển thị thiết lập tài khoản của bạn được phép sử dụng.')+'</p></div></header><div class="uw-card-grid">'+cards.map((c,i)=>'<button class="uw-link-card" data-uw-settings="'+i+'"><span class="uw-card-icon">'+c.icon+'</span><strong>'+safe(c.title)+'</strong><small>'+safe(c.description)+'</small><span class="uw-card-arrow">→</span></button>').join('')+'</div></div>';
    root.querySelectorAll('[data-uw-settings]').forEach(b=>b.onclick=()=>{const c=cards[Number(b.dataset.uwSettings)];navigate(c.route,c.params||{});});
  }

  function renderQuoteProfile(root,{quoteData,can,navigate}) {
    const {quote,version,trip={},versions=[]}=quoteData;
    root.innerHTML='<div class="page uw-profile" data-no-translate><section class="panel"><div class="panel-head"><h2>'+t('Tour record','Thông tin hồ sơ')+'</h2></div><div class="panel-body uw-detail-grid">'+[[t('Quote reference','Mã báo giá'),quote.quote_ref],[t('Version / status','Phiên bản / trạng thái'),'V'+version.version_no+' · '+version.version_status],[t('Trip reference','Mã chuyến đi'),trip.trip_ref||'—'],[t('Travel dates','Ngày đi'),[version.start_date,version.end_date].filter(Boolean).join(' → ')||'—'],[t('Total guests','Tổng khách'),version.total_guests??'—'],[t('Paying guests / FOC','Khách trả tiền / FOC'),(version.paying_pax??'—')+' / '+(version.foc??'—')]].map(([label,value])=>'<div><small>'+safe(label)+'</small><strong>'+safe(value)+'</strong></div>').join('')+'</div></section><section class="panel"><div class="panel-head"><h2>'+t('Quote versions','Lịch sử phiên bản báo giá')+'</h2></div><div class="uw-table-wrap">'+table([t('Version','Phiên bản'),t('Status','Trạng thái'),t('Issued at','Thời điểm phát hành')],versions.map(v=>'<tr><td>V'+safe(v.version_no)+'</td><td>'+safe(v.version_status)+'</td><td>'+safe(v.sent_at||'—')+'</td></tr>'))+'</div></section><div class="uw-notice">'+t('Issued quotes remain immutable. Booking guest lists, finance and travel documents become available in the linked booking, according to permissions.','Báo giá đã phát hành được giữ bất biến. Danh sách khách, tài chính và chứng từ tour nằm trong booking liên kết, theo phân quyền.')+'</div>'+(can('sales.view')?'<button class="btn" data-uw-source>'+t('Open program editor','Mở trình soạn chương trình')+'</button>':'')+'</div>';
    root.querySelector('[data-uw-source]')?.addEventListener('click',()=>navigate('quote',{quoteId:Number(quote.id),quoteTool:'visual'}));
  }

  window.VTAUnified={navItems,activeNav,attach,stop,renderTours,renderB2B,renderSettingsHub,renderQuoteProfile};
})();

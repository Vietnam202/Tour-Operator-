(function () {
 'use strict';
 const instances = new WeakMap();
 const salesCards = [
  ['new_leads', 'New Leads', 'Lead mới hôm nay', 'NEW', 'blue', 'lead.view'],
  ['need_qualification', 'Need Qualification', 'Cần đánh giá nhu cầu', 'QLF', 'purple', 'lead.view'],
  ['followup_today', 'Follow-up Today', 'Theo dõi hôm nay', 'TOD', 'blue', 'sales.view'],
  ['overdue_followup', 'Overdue Follow-up', 'Theo dõi quá hạn', '!', 'amber', 'sales.view'],
  ['draft_quotes', 'Draft Quotes', 'Báo giá đang soạn', 'DFT', 'blue', 'sales.view'],
  ['sent_quotes', 'Sent Quotes', 'Báo giá đã gửi', 'SENT', 'green', 'sales.view'],
  ['waiting_client', 'Waiting Client', 'Chờ khách phản hồi', 'WAIT', 'purple', 'sales.view'],
  ['confirmed_today', 'Confirmed Today', 'Booking tạo hôm nay', 'BKG', 'green', 'booking.view'],
  ['lost', 'Lost Opportunities', 'Cơ hội đã mất', 'LOST', 'olive', 'sales.view']
 ];
 const definitionVI = {
  new_leads: 'LeadRequest NEW được tạo trong ngày; đồng thời nằm trong Cần đánh giá nhu cầu.',
  need_qualification: 'Tất cả LeadRequest NEW, chưa được đánh giá; không giới hạn ngày tạo.',
  followup_today: 'Inquiry đang xử lý có next_action_due trong ngày, gồm cả giờ đã qua hôm nay.',
  overdue_followup: 'Inquiry đang xử lý có next_action_due trước ngày hôm nay.',
  draft_quotes: 'Báo giá có phiên bản hiện tại DRAFT, READY hoặc APPROVED.',
  sent_quotes: 'Báo giá SENT và phiên bản hiện tại SENT.',
  waiting_client: 'Báo giá FOLLOW_UP và phiên bản hiện tại SENT.',
  confirmed_today: 'Booking không bị hủy được tạo trong ngày; chưa có mốc khách xác nhận riêng.',
  lost: 'Tất cả Inquiry LOST, không giới hạn ngày tạo.'
 };
 const validCount = n => n === null || Number.isSafeInteger(n) && n >= 0;
 const object = o => o !== null && typeof o === 'object' && !Array.isArray(o);
 const list = o => Array.isArray(o) ? o : [];
 const libraryAccess = can => ['tour_library.view', 'product.view', 'supplier.view'].some(can);
 function context(options, view) {
  const root = document.querySelector('#workspace');
  if (!root || root.dataset.view !== view) return null;
  const marker = {}, esc = options.esc || (v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])));
  instances.set(root, marker);
  const vi = window.VTA_I18N?.language === 'vi', t = (en, vn) => vi ? vn : en;
  const can = p => options.can?.(p) === true;
  const alive = () => instances.get(root) === marker && root.dataset.view === view && root.isConnected;
  const empty = text => '<div class="wsc-empty">' + esc(text || t('No records need attention.', 'Chưa có bản ghi cần xử lý.')) + '</div>';
  const snapshot = data => '<p class="wsc-snapshot">' + esc(t('Snapshot: ', 'Thời điểm dữ liệu: ') + data.as_of + ' · ' + data.timezone + ' · ' + t('Today: ', 'Hôm nay: ') + data.date_range.today) + '</p>';
  const preview = data => '<span class="wsc-mode">' + esc(data.preview_only || window.VTA_PREVIEW ? t('PREVIEW · Counts from sample records in this session', 'XEM THỬ · Số liệu tính từ bản ghi mẫu trong phiên') : t('SYSTEM DATA · Counts follow the definitions below', 'DỮ LIỆU HỆ THỐNG · Số lượng theo định nghĩa bên dưới')) + '</span>';
  function validateSnapshot(data) {
   if (!object(data) || typeof data.as_of !== 'string' || typeof data.timezone !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(data.date_range?.today || '')) throw Error(t('Workspace response is incomplete. Check the API and retry.', 'Dữ liệu workspace chưa đầy đủ. Kiểm tra API rồi thử lại.'));
  }
  function rowPermission(row) {
   return ({lead_request:'lead.view', inquiry:'sales.view', quote:'sales.view', booking:'booking.view', task:'task.view'})[row.entity_type];
  }
  function openRow(row) {
   const id = Number(row.id), permission = rowPermission(row);
   if (!alive() || !permission || !can(permission) || !Number.isSafeInteger(id) || id <= 0) return;
   if (row.entity_type === 'quote') options.navigate('quote', {quoteId:id});
   else if (row.entity_type === 'booking') options.navigate('booking', {bookingId:id});
   else if (row.entity_type === 'inquiry') options.navigate('sales-list', {salesTab:'inquiries', inquiryId:id});
   else if (row.entity_type === 'lead_request') options.navigate('leads', {requestId:id});
   else if (row.entity_type === 'task') options.navigate('operations', {opsModule:'tasks', taskId:id});
  }
  function rows(items) {
   const data = list(items);
   return data.map((row, i) => {
    const available = rowPermission(row) && can(rowPermission(row)) && Number.isSafeInteger(Number(row.id)) && Number(row.id) > 0;
    return '<article class="wsc-record"><div><strong>' + esc(row.ref || row.title || '—') + '</strong>' + (row.ref && row.title ? '<h3>' + esc(row.title) + '</h3>' : '') + '<p>' + esc([row.contact, row.owner_name].filter(Boolean).join(' · ')) + '</p><p>' + esc(row.due_at ? t('Due: ', 'Hạn: ') + row.due_at : row.event_date || '') + '</p><span class="wsc-status">' + esc(row.status || '—') + '</span></div>' + (available ? '<button class="btn" type="button" data-wsc-record="' + i + '">' + t('Open', 'Mở') + ' →</button>' : '') + '</article>';
   }).join('') || empty();
  }
  function bindRows(container, items) { container.querySelectorAll('[data-wsc-record]').forEach(b => b.onclick = () => openRow(list(items)[Number(b.dataset.wscRecord)])); }
  function shortcut(label, route, params = {}, permission = true, callback = null) { return {label, route, params, permission, callback}; }
  function shortcutHTML(shortcuts) { return shortcuts.filter(s => s.permission).map((s, i) => '<button class="btn" type="button" data-wsc-shortcut="' + i + '">' + esc(s.label) + '</button>').join(''); }
  function bindShortcuts(shortcuts) { const visible = shortcuts.filter(s => s.permission); root.querySelectorAll('[data-wsc-shortcut]').forEach(b => b.onclick = () => { if (!alive()) return; const s = visible[Number(b.dataset.wscShortcut)]; if (s.callback) s.callback(); else options.navigate(s.route, s.params); }); }
  function shortcuts() {
   return [shortcut(t('+ New Inquiry', '+ Yêu cầu mới'), '', {}, can('inquiry.manage') && typeof options.createInquiry === 'function', options.createInquiry), shortcut(t('Inquiries', 'Yêu cầu tour'), 'sales-list', {salesTab:'inquiries'}, can('sales.view')), shortcut(t('Quotes', 'Báo giá'), 'sales-list', {salesTab:'quotes'}, can('sales.view')), shortcut(t('CRM / Customers', 'CRM / Khách hàng'), 'crm', {}, can('sales.view')), shortcut(t('Tour Program Library', 'Kho chương trình tour'), 'product', {}, libraryAccess(can)), shortcut(t('Suppliers & Rates', 'Nhà cung cấp & bảng giá'), 'suppliers', {}, ['supplier.view','rate.view'].some(can))];
  }
  function frame(title, description, contents, data, buttons = []) {
   root.innerHTML = '<div class="page wsc-center" data-no-translate><header class="wsc-heading"><div><h1>' + esc(title) + '</h1><p>' + esc(description) + '</p></div><button class="btn" type="button" data-wsc-refresh>' + t('Refresh', 'Làm mới') + '</button></header>' + (data ? '<div class="wsc-context">' + preview(data) + snapshot(data) + '</div>' : '') + (buttons.some(s => s.permission) ? '<div class="wsc-shortcuts">' + shortcutHTML(buttons) + '</div>' : '') + '<div data-wsc-content>' + contents + '</div></div>';
   bindShortcuts(buttons);
  }
  function errorHTML(error) { return '<div class="wsc-error" role="alert"><strong>' + t('Could not load this workspace', 'Chưa tải được workspace') + '</strong><p>' + esc(error.message || error) + '</p><button class="btn" type="button" data-wsc-retry>' + t('Try again', 'Thử lại') + '</button></div>'; }
  return {root,options,can,t,vi,esc,alive,empty,snapshot,preview,validateSnapshot,rows,bindRows,shortcut,shortcuts,frame,errorHTML};
 }
 window.VTAWorkspaceHome = async function (options) {
  const c = context(options, 'home'); if (!c) return;
  const {root,can,t,esc,alive} = c;
  let ticket = 0;
  const title = t('VTA Home Dashboard', 'VTA · Tổng quan'), desc = t('Shared records, clear ownership and today’s attention', 'Dữ liệu chung, người phụ trách và việc cần xử lý hôm nay');
  const allow = ['lead.view','sales.view','operations.view','booking.view','task.view'].some(can);
  const metricCards = [
   ['marketing_pending', t('Marketing approvals', 'Marketing chờ duyệt'), t('All content in PENDING status.', 'Tất cả nội dung PENDING.'), 'marketing', {}, 'marketing', 'MKT', 'purple'],
   ['new_leads', t('New leads today', 'Lead mới hôm nay'), t('NEW LeadRequests created today.', 'LeadRequest NEW được tạo hôm nay.'), 'sales', {salesQueue:'new_leads'}, 'sales', 'NEW', 'blue'],
   ['sales_followups_due', t('Follow-ups due', 'Theo dõi đến hạn'), t('Active inquiries due before tomorrow, including overdue.', 'Inquiry đang xử lý đến hạn trước ngày mai, gồm quá hạn.'), 'sales', {}, 'sales', 'TOD', 'amber'],
   ['departures_today', t('Departures today', 'Tour khởi hành hôm nay'), t('Active bookings starting today.', 'Booking còn hoạt động khởi hành hôm nay.'), 'operations', {opsModule:'bookings'}, 'operations', 'BKG', 'green'],
   ['bookings_at_risk', t('Bookings at risk', 'Booking có rủi ro'), t('Active bookings with HIGH/CRITICAL risk.', 'Booking còn hoạt động có risk HIGH/CRITICAL.'), 'operations', {opsModule:'bookings'}, 'operations', '!', 'amber'],
   ['my_tasks_due', t('My tasks due', 'Việc của tôi đến hạn'), t('My OPEN/SNOOZED tasks due before tomorrow.', 'Việc OPEN/SNOOZED của tôi đến hạn trước ngày mai.'), 'operations', {opsModule:'tasks'}, 'tasks', '✓', 'blue']
  ];
  async function load() {
   const n = ++ticket;
   c.frame(title, desc, c.empty(t('Loading…', 'Đang tải…'))); root.querySelector('[data-wsc-refresh]').disabled = true;
   if (!allow) { c.frame(title, desc, c.empty(t('No workspace data permissions are enabled for this account.', 'Tài khoản chưa có quyền xem dữ liệu workspace.'))); root.querySelector('[data-wsc-refresh]').onclick = load; return; }
   try {
    const data = await options.api.request('workspace/home'); if (!alive() || n !== ticket) return;
    c.validateSnapshot(data);
    if (!object(data.access) || !object(data.metrics) || !object(data.attention) || metricCards.some(m => !validCount(data.metrics[m[0]]))) throw Error(t('Home dashboard response is incomplete.', 'Dữ liệu tổng quan chưa đầy đủ.'));
    ['sales','operations','tasks'].forEach(key => { const q=data.attention[key]; if (!object(q) || !Array.isArray(q.items) || typeof q.available!=='boolean' || !validCount(q.total) || (q.available&&q.total===null) || (!q.available&&q.items.length)) throw Error(t('Attention queue response is incomplete.', 'Danh sách cần xử lý chưa đầy đủ.')); });
    const localAccess={marketing:can('lead.view'),sales:['lead.view','sales.view','booking.view'].some(can),operations:can('operations.view'),tasks:can('task.view')};
    Object.keys(localAccess).forEach(key=>{data.access[key]=data.access[key]===true&&localAccess[key];});
    const metricPermissions={marketing_pending:'lead.view',new_leads:'lead.view',sales_followups_due:'sales.view',departures_today:'operations.view',bookings_at_risk:'operations.view',my_tasks_due:'task.view'};
    const workspaces = [['marketing',t('Marketing Workspace','Workspace Marketing'),t('Content, approvals and campaigns','Nội dung, phê duyệt và chiến dịch'),'MKT','purple'],['sales',t('Sales Command Center','Trung tâm Kinh doanh'),t('Leads, follow-ups and quotes','Lead, theo dõi và báo giá'),'SLS','blue'],['operations',t('Operations Control Center','Trung tâm Điều hành'),t('Bookings, services and daily delivery','Booking, dịch vụ và điều hành tour'),'OPS','green']].filter(w=>data.access[w[0]] === true);
    let html = '<section class="wsc-section"><h2>' + t('Your workspaces','Workspace của bạn') + '</h2><div class="wsc-workspaces">' + workspaces.map(([route,name,subtitle,icon,tone])=>'<button class="wsc-workspace tone-'+tone+'" type="button" data-wsc-workspace="'+route+'"><span class="wsc-icon" aria-hidden="true">'+icon+'</span><strong>'+esc(name)+'</strong><span>'+esc(subtitle)+'</span><span class="wsc-enter">'+t('Open workspace','Mở workspace')+' →</span></button>').join('') + '</div></section>';
    html += '<section class="wsc-section"><h2>' + t('Needs attention', 'Cần xử lý') + '</h2><div class="wsc-metrics">' + metricCards.filter(m => data.metrics[m[0]] !== null && data.access[m[5]] === true && can(metricPermissions[m[0]])).map(([key,name,definition,route,params,access,icon,tone]) => '<button type="button" class="wsc-metric tone-'+tone+'" data-wsc-metric="'+key+'"><span class="wsc-icon" aria-hidden="true">'+icon+'</span><strong class="wsc-number">'+data.metrics[key]+'</strong><span class="wsc-card-title">'+esc(name)+'</span><span class="wsc-definition">'+esc(definition)+'</span></button>').join('') + '</div></section>';
    html += '<div class="wsc-attention">' + [['sales',t('Sales follow-ups','Theo dõi Kinh doanh')],['operations',t('Departures today / at-risk bookings','Tour khởi hành hôm nay / booking rủi ro')],['tasks',t('My unfinished tasks due','Việc của tôi đến hạn')]].filter(([key])=>data.access[key]===true&&(key!=='sales'||can('sales.view'))).map(([key,name])=>'<section class="wsc-panel" data-wsc-panel="'+key+'"><h2>'+esc(name)+' <span class="wsc-total">'+data.attention[key].total+'</span></h2>'+c.rows(data.attention[key].items)+(data.attention[key].total>data.attention[key].items.length?'<p class="wsc-note">'+esc(t('Showing '+data.attention[key].items.length+' of '+data.attention[key].total+'. Open the workspace for more.','Hiển thị '+data.attention[key].items.length+'/'+data.attention[key].total+'. Mở workspace để xem thêm.'))+'</p>':'')+'</section>').join('') + '</div>';
    c.frame(title,desc,html,data,c.shortcuts());
    root.querySelectorAll('[data-wsc-workspace]').forEach(b=>b.onclick=()=>{if(alive())options.navigate(b.dataset.wscWorkspace);});
    root.querySelectorAll('[data-wsc-metric]').forEach(b=>b.onclick=()=>{if(!alive())return;const m=metricCards.find(m=>m[0]===b.dataset.wscMetric);options.navigate(m[3],m[3]==='operations'&&m[4].opsModule==='bookings'&&!can('booking.view')?{opsModule:'home'}:m[4]);});
    root.querySelectorAll('[data-wsc-panel]').forEach(p=>c.bindRows(p,data.attention[p.dataset.wscPanel].items));
   } catch (error) { if (!alive() || n!==ticket) return; c.frame(title,desc,c.errorHTML(error)); root.querySelector('[data-wsc-retry]').onclick=load; }
   if(alive())root.querySelector('[data-wsc-refresh]').onclick=load;
  }
  return load();
 };
 window.VTAWorkspaceSales = async function (options) {
  const c = context(options,'sales'); if(!c)return;
  const {root,can,t,esc,alive}=c;
  let ticket=0,data=null,selected='',offset=0,queueData=null;
  const title=t('Sales Command Center','Trung tâm Kinh doanh'),desc=t('Lead → Qualification → Program → Quote → Follow-up → Booking','Lead → Đánh giá → Chương trình → Báo giá → Theo dõi → Booking');
  const allow=['lead.view','sales.view','booking.view'].some(can);
  function validate(data,queue='') {
   c.validateSnapshot(data);
   if(!object(data.metrics)||!object(data.definitions)||!object(data.queues)||salesCards.some(card=>!validCount(data.metrics[card[0]])))throw Error(t('Sales workspace response is incomplete.','Dữ liệu Kinh doanh chưa đầy đủ.'));
   const keys=queue?[queue]:salesCards.map(card=>card[0]);
   keys.forEach(key=>{const q=data.queues[key];if(typeof data.definitions[key]!=='string'||!object(q)||typeof q.available!=='boolean'||!Array.isArray(q.items)||!validCount(q.total)||(q.available&&q.total===null)||(!q.available&&q.items.length)||!Number.isSafeInteger(q.limit)||q.limit<1||!Number.isSafeInteger(q.offset)||q.offset<0)throw Error(t('Sales queue response is incomplete.','Danh sách Kinh doanh chưa đầy đủ.'));});
  }
  function render(content=null) {
   const cards='<div class="wsc-sales-grid">'+salesCards.map(([key,en,vn,icon,tone,permission])=>{
    const allowed=can(permission)&&data.metrics[key]!==null&&data.queues[key]?.available!==false;
    return '<button class="wsc-metric tone-'+tone+(selected===key?' active':'')+'" type="button" data-wsc-queue="'+key+'" '+(!allowed?'disabled':'')+' aria-pressed="'+(selected===key)+'"><span class="wsc-icon" aria-hidden="true">'+icon+'</span><strong class="wsc-number">'+(allowed?data.metrics[key]:'—')+'</strong><span class="wsc-card-title">'+esc(t(en,vn))+'</span><span class="wsc-definition">'+esc(c.vi?definitionVI[key]:data.definitions[key]||'')+'</span>'+(!allowed?'<span class="wsc-access">'+t('Access required','Cần quyền truy cập')+'</span>':'')+'</button>';
   }).join('')+'</div><p class="wsc-note">'+t('Queues may overlap. Counts include all matching records; each list is paginated.','Các nhóm có thể trùng bản ghi. Số lượng tính toàn bộ bản ghi phù hợp; danh sách có phân trang.')+'</p>';
   c.frame(title,desc,cards+'<section class="wsc-panel wsc-selected-queue" data-wsc-queue-panel>'+(content===null?queueHTML():content)+'</section>'+(options.handover?'<section class="wsc-selected-queue" data-vs1-handover-host></section>':''),data,c.shortcuts());
   if(options.handover&&window.mountLeadHandover)window.mountLeadHandover(root.querySelector('[data-vs1-handover-host]'),{...options,mode:'sales'});
   root.querySelector('[data-wsc-refresh]').onclick=()=>load(selected,offset);
   root.querySelectorAll('[data-wsc-queue]').forEach(b=>b.onclick=()=>{if(!b.disabled)load(b.dataset.wscQueue,0);});
   const panel=root.querySelector('[data-wsc-queue-panel]');
   if(content===null&&queueData) {
    c.bindRows(panel,queueData.items);
    panel.querySelector('[data-wsc-prev]')?.addEventListener('click',()=>load(selected,Math.max(0,offset-queueData.limit)));
    panel.querySelector('[data-wsc-next]')?.addEventListener('click',()=>load(selected,offset+queueData.limit));
   }
   panel.querySelector('[data-wsc-retry]')?.addEventListener('click',()=>load(selected,offset));
  }
  function queueHTML() {
   if(!selected||!queueData)return c.empty(t('Select a card to view the matching records.','Chọn một thẻ để xem các bản ghi tương ứng.'));
   const card=salesCards.find(card=>card[0]===selected),start=queueData.total?queueData.offset+1:0,end=Math.min(queueData.total,queueData.offset+queueData.items.length);
   return '<div class="wsc-queue-heading"><h2>'+esc(t(card[1],card[2]))+'</h2><span>'+esc(t('Showing '+start+'–'+end+' of '+queueData.total,'Hiển thị '+start+'–'+end+'/'+queueData.total))+'</span></div>'+c.rows(queueData.items)+'<div class="wsc-pagination"><button class="btn" type="button" data-wsc-prev '+(offset===0?'disabled':'')+'>← '+t('Previous','Trước')+'</button><button class="btn" type="button" data-wsc-next '+(offset+queueData.items.length>=queueData.total?'disabled':'')+'>'+t('Next','Tiếp')+' →</button></div>';
  }
  async function load(key='',nextOffset=0) {
   if(!alive())return;
   if(key&&!salesCards.some(card=>card[0]===key&&can(card[5])))return;
   const n=++ticket; selected=key;offset=nextOffset;
   if(data)render(c.empty(t('Loading matching records…','Đang tải danh sách…')));else{c.frame(title,desc,c.empty(t('Loading…','Đang tải…')));root.querySelector('[data-wsc-refresh]').disabled=true;}
   if(!allow){c.frame(title,desc,c.empty(t('Sales workspace access is not enabled for this account.','Tài khoản chưa có quyền truy cập Kinh doanh.')));root.querySelector('[data-wsc-refresh]').onclick=()=>load();return;}
   try{
    const route='workspace/sales'+(key?'?queue='+encodeURIComponent(key)+'&limit=30&offset='+nextOffset:'');
    const response=await options.api.request(route);if(!alive()||n!==ticket)return;validate(response,key);
    if(key){data={...response,queues:{...data?.queues,...response.queues}};queueData=response.queues[key];if(!queueData.available){selected='';queueData=null;}}else{data=response;queueData=null;}
    render();
   }catch(error){if(!alive()||n!==ticket)return;if(data)render(c.errorHTML(error));else{c.frame(title,desc,c.errorHTML(error));root.querySelector('[data-wsc-retry]').onclick=()=>load();root.querySelector('[data-wsc-refresh]').onclick=()=>load();}}
  }
  return load(options.initialQueue || '',0);
 };
})();

/* VS1 uses shared lead/customer records. Each mount owns its asynchronous lifecycle. */
(() => {
 'use strict';
 const mounts=new WeakMap(),array=v=>Array.isArray(v)?v:[],object=v=>v!==null&&typeof v==='object'&&!Array.isArray(v);
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const id=v=>Number.isSafeInteger(Number(v))&&Number(v)>0?Number(v):0;
 function mounted(host,options){
  if(!host)return null;const marker={},workspace=host.closest('#workspace'),view=workspace?.dataset.view;
  mounts.set(host,marker);const vi=window.VTA_I18N?.language==='vi',t=(en,vn)=>vi?vn:en,can=p=>options.can?.(p)===true;
  const alive=()=>mounts.get(host)===marker&&host.isConnected&&(!workspace||workspace.dataset.view===view);
  const badge=preview=>'<span class="wsc-mode">'+t(preview?'PREVIEW · Sample records in this session':'SYSTEM DATA',preview?'XEM THỬ · Bản ghi mẫu trong phiên':'DỮ LIỆU HỆ THỐNG')+'</span>';
  const empty=text=>'<p class="wsc-empty">'+esc(text||t('No matching records.','Chưa có bản ghi phù hợp.'))+'</p>';
  const error=e=>'<div class="wsc-error" role="alert">'+esc(e.message||e)+'</div>';
  const dateInput=v=>String(v||'').replace(' ','T').slice(0,16),dateBody=v=>v?String(v).replace('T',' ')+':00':'';
  const actionKey=()=>window.crypto?.randomUUID?.()||('vs1-'+Date.now()+'-'+Math.random().toString(36).slice(2));
  return {host,options,t,can,alive,badge,empty,error,dateInput,dateBody,actionKey};
 }
 window.mountLeadHandover=async function(host,options={}){
  const c=mounted(host,options);if(!c)return;const {t,can,alive}=c,marketing=options.mode==='marketing';
  const allowed=can('lead.view'),queues=marketing?['NEW','PENDING','RETURNED','ACCEPTED']:['PENDING','ACCEPTED','RETURNED','CONVERTED'];
  const names={NEW:t('Need qualification','Cần đánh giá'),PENDING:t('Waiting for Sales','Chờ Sales nhận'),ACCEPTED:t('Sales accepted','Sales đã nhận'),RETURNED:t('Returned to Marketing','Trả về Marketing'),CONVERTED:t('Converted opportunities','Đã tạo cơ hội')};
  let selected=queues.includes(options.initialStatus)?options.initialStatus:queues[0],offset=0,ticket=0,data=null,counts={},command=null;
  const title=marketing?t('Marketing → Sales handover','Bàn giao Marketing → Sales'):t('Sales handover queue','Hàng chờ bàn giao Sales');
  function rowHTML(row,index){
   const state=selected==='NEW'?'NEW':row.handover_status,version=selected==='NEW'?row.version_no:row.handover_version;
   const valid=id(row.id)&&id(version),actions=[];
   if(selected==='NEW'&&valid&&can('lead.manage'))actions.push(['qualify',t('Qualify & hand over','Đánh giá & bàn giao')]);
   if(state==='RETURNED'&&valid&&can('lead.manage'))actions.push(['resubmit',t('Review & resubmit','Xem lại & bàn giao lại')]);
   if(state==='PENDING'&&valid&&can('lead.sales_accept'))actions.push(['accept',t('Accept','Nhận')]);
   if(['PENDING','ACCEPTED'].includes(state)&&valid&&can('lead.sales_accept'))actions.push(['return',t('Return with reason','Trả về có lý do')]);
   if(state==='ACCEPTED'&&valid&&['lead.manage','inquiry.manage','sales.view'].every(can))actions.push(['convert',t('Review customer & create inquiry','Review khách & tạo inquiry')]);
   if(id(row.inquiry_id)&&can('sales.view'))actions.push(['inquiry',t('Open inquiry','Mở inquiry')]);
   if(id(row.customer_id)&&can('sales.view'))actions.push(['customer',t('Customer 360','Customer 360')]);
   const campaign=row.campaign_name||(id(row.campaign_id)?'#'+row.campaign_id:'');
   const owner=state==='ACCEPTED'||state==='CONVERTED'?(row.sales_owner_name||(id(row.sales_owner_user_id)?'#'+row.sales_owner_user_id:'')):(row.owner_name||(id(row.owner_user_id)?'#'+row.owner_user_id:''));
   const qualification=row.qualification_note?'<details class="wsc-note"><summary>'+t('Qualification review','Nội dung đánh giá')+'</summary><p>'+esc(row.qualification_note)+'</p></details>':'';
   const history=array(row.history).length?'<details class="wsc-note"><summary>'+t('Handover history','Lịch sử bàn giao')+'</summary>'+array(row.history).map(item=>'<p>'+esc([names[item.to_status]||item.to_status,item.actor_name,item.created_at,item.reason].filter(Boolean).join(' · '))+'</p>').join('')+'</details>':'';
   return '<article class="wsc-record wsc-handover-record"><div><strong>'+esc(row.request_ref||row.lead_ref||'#'+row.id)+'</strong><h3>'+esc(row.contact_name||row.full_name||'—')+'</h3><p>'+esc([row.source,campaign].filter(Boolean).join(' · '))+'</p><p>'+esc([row.email,row.phone].filter(Boolean).join(' · '))+'</p><p>'+esc([row.destination,row.travel_date].filter(Boolean).join(' · '))+'</p><p>'+esc(t('Owner: ','Phụ trách: ')+(owner||t('Unassigned','Chưa phân công')))+' · '+esc(t('SLA / next action: ','SLA / việc tiếp theo: ')+(row.next_action_due||t('Needs scheduling','Cần đặt lịch')) )+'</p>'+(row.return_reason?'<p class="wsc-return-reason">'+esc(t('Return reason: ','Lý do trả: ')+row.return_reason)+'</p>':'')+qualification+history+'<span class="wsc-status">'+esc(names[state]||state||'—')+'</span></div><div class="wsc-record-actions">'+actions.map(([action,label])=>'<button class="btn" type="button" data-vs1-action="'+action+'" data-vs1-index="'+index+'">'+esc(label)+'</button>').join('')+'</div></article>';
  }
  function render(){
   const total=Number.isSafeInteger(data?.total)&&data.total>=0?data.total:null,items=array(data?.items);
   host.innerHTML='<div class="wsc-panel wsc-center" data-no-translate><header class="wsc-heading"><div><h2>'+title+'</h2><p>'+t('Qualification → Sales decision → reviewed customer identity → inquiry','Đánh giá → Sales nhận/trả → review danh tính khách → inquiry')+'</p></div><button class="btn" type="button" data-vs1-refresh>'+t('Refresh','Làm mới')+'</button></header>'+c.badge(data?.preview_only||window.VTA_PREVIEW)+'<div class="wsc-handover-grid">'+queues.map(key=>'<button type="button" class="wsc-handover-card '+(selected===key?'active':'')+'" data-vs1-status="'+key+'" aria-pressed="'+(selected===key)+'"><strong>'+esc(names[key])+'</strong><span>'+esc(Number.isSafeInteger(counts[key])?counts[key]:'—')+'</span></button>').join('')+'</div><p class="wsc-note">'+t('Sales must accept a lead before conversion. Customer identity always requires review.','Sales cần nhận lead trước khi chuyển đổi. Danh tính khách luôn cần được review.')+'</p><div data-vs1-list>'+(data?items.map(rowHTML).join('')||c.empty():c.empty(t('Loading…','Đang tải…')))+'</div>'+(total!==null?'<p class="wsc-note">'+esc(t('Showing '+items.length+' of '+total,'Hiển thị '+items.length+'/'+total))+'</p><div class="wsc-pagination"><button class="btn" data-vs1-prev '+(offset===0?'disabled':'')+'>← '+t('Previous','Trước')+'</button><button class="btn" data-vs1-next '+(offset+items.length>=total?'disabled':'')+'>'+t('Next','Tiếp')+' →</button></div>':'')+'<div data-vs1-command></div></div>';
   host.querySelector('[data-vs1-refresh]').onclick=()=>load(selected,offset);
   host.querySelectorAll('[data-vs1-status]').forEach(button=>button.onclick=()=>load(button.dataset.vs1Status,0));
   host.querySelector('[data-vs1-prev]')?.addEventListener('click',()=>load(selected,Math.max(0,offset-30)));
   host.querySelector('[data-vs1-next]')?.addEventListener('click',()=>load(selected,offset+30));
   host.querySelectorAll('[data-vs1-action]').forEach(button=>button.onclick=()=>openCommand(button.dataset.vs1Action,items[Number(button.dataset.vs1Index)]));
  }
  async function load(status=selected,nextOffset=0){
   if(!alive()||!queues.includes(status))return;selected=status;offset=nextOffset;command=null;const n=++ticket;data=null;render();
   if(!allowed){host.querySelector('[data-vs1-list]').innerHTML=c.empty(t('Lead read permission is required.','Cần quyền xem lead.'));return;}
   try{
    const focus=options.requestId?'&request_id='+id(options.requestId):'';
    const route=selected==='NEW'?'lead-hub/requests?status=NEW&limit=30&offset='+offset+focus:'lead-hub/leads?handover_status='+selected+'&limit=30&offset='+offset+focus;
    const [result,summary]=await Promise.all([options.api.request(route),selected==='NEW'?options.api.request('lead-hub/leads?limit=1&offset=0'+focus):Promise.resolve(null)]);if(!alive()||n!==ticket)return;
    if(!object(result)||!Array.isArray(result.items))throw Error(t('Handover response is incomplete.','Dữ liệu bàn giao chưa đầy đủ.'));
    data=result;counts={...counts,...(object(summary?.counts)?summary.counts:{}),...(object(result.counts)?result.counts:{})};if(selected==='NEW'&&Number.isSafeInteger(result.total))counts.NEW=result.total;
    render();
   }catch(e){if(alive()&&n===ticket)host.querySelector('[data-vs1-list]').innerHTML=c.error(e);}
  }
  function formHTML(action,row,owners=[]){
   const isReturn=action==='return',isQualify=['qualify','resubmit'].includes(action),owner=action==='accept'?(row.sales_owner_user_id||options.api.user?.id||''):(row.owner_user_id||options.api.user?.id||'');
   const defaultDue=new Date(Date.now()+31*3600000).toISOString().slice(0,10)+'T09:00';
   return '<section class="wsc-command" aria-label="'+esc(names[selected])+'"><h3>'+esc(t(action==='accept'?'Accept lead':isReturn?'Return lead':isQualify?'Review qualification':'Review customer identity',action==='accept'?'Nhận lead':isReturn?'Trả lead':isQualify?'Review đánh giá':'Review danh tính khách'))+'</h3><p>'+esc(row.contact_name||'')+'</p><form data-vs1-form>'+((isReturn||isQualify)?'<label>'+t(isReturn?'Return reason':'Qualification notes',isReturn?'Lý do trả':'Ghi chú đánh giá')+'<textarea name="'+(isReturn?'reason':'qualification_note')+'" required maxlength="8000"></textarea></label>':'')+(!isReturn?'<div class="wsc-form-grid"><label>'+t('Owner','Người phụ trách')+'<select name="'+(action==='accept'?'sales_owner_user_id':'owner_user_id')+'" required><option value="">'+t('Choose a person','Chọn người phụ trách')+'</option>'+owners.filter(person=>id(person.id)).map(person=>'<option value="'+id(person.id)+'" '+(Number(owner)===id(person.id)?'selected':'')+'>'+esc(person.full_name)+'</option>').join('')+'</select></label><label>'+t('SLA / next action due','SLA / hạn việc tiếp theo')+'<input name="next_action_due" type="datetime-local" required value="'+esc(c.dateInput(row.next_action_due)||defaultDue)+'"></label></div>':'')+'<div data-vs1-form-error></div><div class="wsc-record-actions"><button class="btn primary" type="submit">'+t('Apply','Áp dụng')+'</button><button class="btn" type="button" data-vs1-cancel>'+t('Cancel','Hủy')+'</button></div></form></section>';
  }
  async function openCommand(action,row){
   if(!alive()||!row||!id(row.id))return;
   if(action==='inquiry'){if(can('sales.view'))options.navigate?.('sales-list',{salesTab:'inquiries',inquiryId:id(row.inquiry_id)});return;}
   if(action==='customer'){if(can('sales.view'))options.navigate?.('customer-360',{customerId:id(row.customer_id)});return;}
   if(!can('lead.view')||(['qualify','resubmit'].includes(action)&&!can('lead.manage'))||(['accept','return'].includes(action)&&!can('lead.sales_accept'))||(action==='convert'&&!['lead.manage','inquiry.manage','sales.view'].every(can)))return;
   const section=host.querySelector('[data-vs1-command]'),marker={};command=marker;const fresh=()=>alive()&&command===marker&&section.isConnected;
   if(action==='convert'){
    section.innerHTML=c.empty(t('Loading customer match suggestions…','Đang tải gợi ý khách trùng…'));
    try{const review=await options.api.request('lead-hub/leads/'+id(row.id)+'/customer-candidates');if(!fresh())return;
     if(!object(review)||!Array.isArray(review.items)||typeof review.identity_review_key!=='string'||Number(review.handover_version)!==Number(row.handover_version))throw Error(t('Lead changed. Refresh and review again.','Lead đã thay đổi. Làm mới và review lại.'));
     identityForm(section,row,review,fresh);
    }catch(e){if(fresh())section.innerHTML=c.error(e);}return;
   }
   let owners=[];if(action!=='return'){section.innerHTML=c.empty(t('Loading owners…','Đang tải người phụ trách…'));try{const result=await options.api.request('lead-hub/owners');if(!fresh())return;if(!Array.isArray(result?.items))throw Error(t('Owner list is incomplete.','Danh sách người phụ trách chưa đầy đủ.'));owners=result.items;}catch(e){if(fresh())section.innerHTML=c.error(e);return;}}
   section.innerHTML=formHTML(action,row,owners);const form=section.querySelector('form');
   section.querySelector('[data-vs1-cancel]').onclick=()=>{command=null;section.innerHTML='';};
   const key=c.actionKey();form.onsubmit=async e=>{e.preventDefault();if(!fresh()||!can('lead.view')||(['qualify','resubmit'].includes(action)&&!can('lead.manage'))||(['accept','return'].includes(action)&&!can('lead.sales_accept'))||!form.reportValidity())return;
    const values=Object.fromEntries(new FormData(form));if(values.next_action_due)values.next_action_due=c.dateBody(values.next_action_due);
    const route=action==='qualify'?'lead-hub/requests/'+id(row.id)+'/qualify':'lead-hub/leads/'+id(row.id)+'/'+action;
    const body={...values,expected_version:Number(action==='qualify'?row.version_no:row.handover_version),action_key:key};
    await submit(form,route,body,fresh,()=>load(selected,offset));
   };
  }
  async function submit(form,route,body,fresh,done){
   form.querySelectorAll('button').forEach(button=>button.disabled=true);form.querySelector('[data-vs1-form-error]').innerHTML='';
   try{await options.api.request(route,{method:'POST',body});if(!fresh())return;options.notify?.(t('Handover updated.','Đã cập nhật bàn giao.'));await done();}
   catch(e){if(fresh())form.querySelector('[data-vs1-form-error]').innerHTML=c.error(e);}
   finally{if(fresh())form.querySelectorAll('button').forEach(button=>button.disabled=false);}
  }
  function identityForm(section,row,review,fresh){
   const candidates=array(review.items).filter(item=>id(item.id)),create=can('customer.manage');
   section.innerHTML='<section class="wsc-command"><h3>'+t('Review customer identity','Review danh tính khách')+'</h3><p class="wsc-note">'+t('Contact matches are suggestions. Select an existing record or deliberately create a new customer. No record is merged automatically.','Liên hệ trùng chỉ là gợi ý. Chọn khách hiện có hoặc chủ động tạo khách mới. Hệ thống không tự gộp hồ sơ.')+'</p><form data-vs1-form><fieldset><legend>'+t('Identity decision','Quyết định danh tính')+'</legend><label><input type="radio" name="identity_mode" value="LINK_EXISTING" required '+(!candidates.length?'disabled':'')+'> '+t('Link existing customer','Liên kết khách hiện có')+'</label><div class="wsc-candidates">'+(candidates.map(item=>'<article><strong>'+esc(item.full_name)+'</strong><p>'+esc([item.email,item.whatsapp].filter(Boolean).join(' · '))+'</p><p>'+esc(array(item.match_reasons).join(' · '))+'</p></article>').join('')||c.empty(t('No contact matches found.','Không tìm thấy liên hệ trùng.')))+'</div><label>'+t('Existing customer','Khách hiện có')+'<select name="customer_id"><option value="">'+t('Choose after review','Chọn sau khi review')+'</option>'+candidates.map(item=>'<option value="'+id(item.id)+'">'+esc(item.full_name)+' (#'+id(item.id)+')</option>').join('')+'</select></label>'+(create?'<label><input type="radio" name="identity_mode" value="CREATE_NEW" required> '+t('Create a new customer','Tạo khách mới')+'</label><div class="wsc-form-grid"><label>'+t('Full name','Họ tên')+'<input name="full_name" maxlength="190" value="'+esc(row.contact_name||'')+'"></label><label>Email<input name="email" type="email" maxlength="190" value="'+esc(row.email||'')+'"></label><label>WhatsApp<input name="whatsapp" maxlength="64" value="'+esc(row.phone||'')+'"></label><label>'+t('Market','Thị trường')+'<input name="market" maxlength="120" value="'+esc(row.market||'')+'"></label></div>':'<p class="wsc-note">'+t('Creating a new customer requires customer management permission.','Tạo khách mới cần quyền quản lý khách hàng.')+'</p>')+'</fieldset><label class="wsc-reviewed"><input type="checkbox" name="identity_reviewed" required> '+t('I reviewed the contact identity and this explicit choice.','Tôi đã review danh tính liên hệ và lựa chọn này.')+'</label><div data-vs1-form-error></div><div class="wsc-record-actions"><button class="btn primary" type="submit" '+(!candidates.length&&!create?'disabled':'')+'>'+t('Create inquiry','Tạo inquiry')+'</button><button class="btn" type="button" data-vs1-cancel>'+t('Cancel','Hủy')+'</button></div></form></section>';
   const form=section.querySelector('form'),key=c.actionKey();section.querySelector('[data-vs1-cancel]').onclick=()=>{command=null;section.innerHTML='';};
   if(create){form.querySelector('fieldset').insertAdjacentHTML('beforeend','<label>'+t('Reason for a distinct identity if contacts already exist','Lý do tạo danh tính riêng nếu liên hệ đã tồn tại')+'<textarea name="duplicate_reason" minlength="10" maxlength="1000" disabled></textarea></label>');form.querySelectorAll('[name="identity_mode"]').forEach(input=>input.onchange=()=>{const reason=form.querySelector('[name="duplicate_reason"]'),newIdentity=form.querySelector('[name="identity_mode"]:checked')?.value==='CREATE_NEW';reason.disabled=!newIdentity;reason.required=newIdentity&&candidates.length>0;});}
   form.onsubmit=async e=>{e.preventDefault();if(!fresh()||!['lead.view','lead.manage','inquiry.manage','sales.view'].every(can)||!form.reportValidity())return;const values=Object.fromEntries(new FormData(form)),mode=values.identity_mode;
    if((mode==='LINK_EXISTING'&&!candidates.some(item=>id(item.id)===id(values.customer_id)))||(mode==='CREATE_NEW'&&(!can('customer.manage')||!values.full_name.trim()||(!values.email.trim()&&!values.whatsapp.trim())||(candidates.length&&String(values.duplicate_reason||'').trim().length<10)))){form.querySelector('[data-vs1-form-error]').innerHTML=c.error(Error(t('Choose a reviewed customer or enter a name, contact and required identity reason for a new customer.','Chọn khách đã review hoặc nhập tên, liên hệ và lý do danh tính cần thiết cho khách mới.')));return;}
    const body={expected_version:Number(review.handover_version),action_key:key,identity_mode:mode,identity_reviewed:true,identity_review_key:review.identity_review_key};
    if(mode==='LINK_EXISTING')body.customer_id=id(values.customer_id);else{body.new_customer={full_name:values.full_name,email:values.email,whatsapp:values.whatsapp,market:values.market};if(values.duplicate_reason)body.duplicate_reason=values.duplicate_reason;}
    await submit(form,'lead-hub/leads/'+id(row.id)+'/convert',body,fresh,()=>load(selected,offset));
   };
  }
  return load();
 };
 window.mountCustomer360=async function(host,options={}){
  const c=mounted(host,options);if(!c)return;const {t,can,alive}=c;let ticket=0;
  const definitions=[['leads',t('Marketing & lead source','Marketing & nguồn lead'),'lead.view'],['inquiries',t('Inquiries / opportunities','Inquiry / cơ hội'),'sales.view'],['quotes',t('Quote history','Lịch sử báo giá'),'sales.view'],['bookings',t('Booking history','Lịch sử booking'),'booking.view'],['tasks',t('Shared next actions','Việc tiếp theo'),'task.view'],['documents',t('Travel document metadata','Thông tin tài liệu tour'),'travel_document.view'],['finance',t('Issued invoice balances by currency','Số dư hóa đơn phát hành theo tiền tệ'),'finance.view'],['activity',t('Activity timeline','Dòng thời gian hoạt động'),null]];
  function itemHTML(item,key,index){
   if(key==='finance')return '<article class="wsc-record"><div><strong>'+esc(item.currency||'—')+'</strong><p>'+esc(t('Invoiced: ','Đã lập hóa đơn: ')+(item.total??item.invoice_total??'—'))+' · '+esc(t('Paid: ','Đã trả: ')+(item.paid??item.paid_total??'—'))+' · '+esc(t('Balance: ','Còn lại: ')+(item.balance??item.balance_total??'—'))+'</p></div></article>';
   const eventNames={LEAD_QUALIFIED:t('Lead qualified','Lead đã đánh giá'),INQUIRY_CREATED:t('Inquiry created','Đã tạo inquiry'),QUOTE_CREATED:t('Quote created','Đã tạo báo giá'),BOOKING_CREATED:t('Booking created','Đã tạo booking')};
   const reference=key==='activity'?(eventNames[item.event]||item.event||item.title||'—'):(item.ref||item.request_ref||item.inquiry_ref||item.quote_ref||item.booking_ref||item.title||'#'+(item.id||index+1)),name=item.title||item.contact_name||item.filename||item.document_type||item.name||'';
   const details=[item.source,item.campaign_name||(item.campaign_id?'#'+item.campaign_id:''),item.owner_name,item.next_action,item.next_action_due||item.due_at,item.created_at||item.occurred_at].filter(Boolean);
   const open=['leads','inquiries','quotes','bookings','tasks'].includes(key)&&id(item.id);
   return '<article class="wsc-record"><div><strong>'+esc(reference)+'</strong>'+(name?'<h3>'+esc(name)+'</h3>':'')+'<p>'+esc(details.join(' · '))+'</p>'+(item.status?'<span class="wsc-status">'+esc(item.status)+'</span>':'')+'</div>'+(open?'<button class="btn" data-vs1-360-open="'+key+'" data-vs1-index="'+index+'">'+t('Open','Mở')+' →</button>':'')+'</article>';
  }
  async function load(){
   if(!alive())return;const n=++ticket;host.innerHTML='<div class="wsc-center wsc-panel" data-no-translate>'+c.empty(t('Loading Customer 360…','Đang tải Customer 360…'))+'</div>';
   if(!can('sales.view')||!id(options.customerId)){host.innerHTML=c.empty(t('Customer read permission and a valid customer are required.','Cần quyền xem khách và khách hàng hợp lệ.'));return;}
   try{const data=await options.api.request('customers/'+id(options.customerId)+'/360');if(!alive()||n!==ticket)return;
    if(!object(data)||!object(data.customer)||!object(data.sections))throw Error(t('Customer 360 response is incomplete.','Dữ liệu Customer 360 chưa đầy đủ.'));
    const customer=data.customer;
    const html=definitions.map(([key,name,permission])=>{const section=data.sections[key],available=object(section)&&section.available===true&&(!permission||can(permission))&&(key!=='finance'||can('customer_ar.view'));
     if(!available)return '<section class="wsc-panel"><h2>'+esc(name)+'</h2>'+c.empty(t('Access is not enabled for this section.','Chưa có quyền xem phần này.'))+'</section>';
     if(!Array.isArray(section.items))throw Error(t('Customer section is incomplete.','Phần dữ liệu khách chưa đầy đủ.'));
     return '<section class="wsc-panel"><h2>'+esc(name)+(Number.isSafeInteger(section.total)?' <span class="wsc-total">'+section.total+'</span>':'')+'</h2>'+section.items.map((item,index)=>itemHTML(item,key,index)).join('')+(section.items.length?'':c.empty())+(section.has_more?'<p class="wsc-note">'+t('Recent records shown. Open the shared workspace for the full history.','Hiển thị bản ghi gần đây. Mở workspace dùng chung để xem lịch sử đầy đủ.')+'</p>':'')+'</section>';
    }).join('');
    host.innerHTML='<div class="wsc-center" data-no-translate><header class="wsc-heading"><div><h1>Customer 360 · '+esc(customer.full_name||customer.customer_ref||'—')+'</h1><p>'+esc([customer.customer_ref,customer.market,customer.country,customer.email,customer.whatsapp].filter(Boolean).join(' · '))+'</p></div><div class="wsc-record-actions"><button class="btn" data-vs1-360-back>← CRM</button><button class="btn" data-vs1-360-refresh>'+t('Refresh','Làm mới')+'</button></div></header><div class="wsc-context">'+c.badge(data.preview_only||window.VTA_PREVIEW)+'<p class="wsc-note">'+t('A shared customer identity across marketing, sales and operations.','Danh tính khách dùng chung từ Marketing, Sales đến Điều hành.')+'</p></div><div class="wsc-360-grid">'+html+'</div></div>';
    host.querySelector('[data-vs1-360-refresh]').onclick=load;
    host.querySelector('[data-vs1-360-back]').onclick=()=>{if(alive())options.navigate?.('crm',{crmTab:'customers'});};
    host.querySelectorAll('[data-vs1-360-open]').forEach(button=>button.onclick=()=>{if(!alive())return;const key=button.getAttribute('data-vs1-360-open'),index=Number(button.dataset.vs1Index),row=data.sections[key].items[index];if(!id(row.id))return;
     const routes={leads:['leads',{requestId:id(row.request_id||row.id)}],inquiries:['sales-list',{salesTab:'inquiries',inquiryId:id(row.id)}],quotes:['quote',{quoteId:id(row.id)}],bookings:['booking',{bookingId:id(row.id)}],tasks:['operations',{opsModule:'tasks',taskId:id(row.id)}]};options.navigate?.(...routes[key]);});
   }catch(e){if(alive()&&n===ticket){host.innerHTML=c.error(e)+'<button class="btn" data-vs1-360-retry>'+t('Try again','Thử lại')+'</button>';host.querySelector('[data-vs1-360-retry]').onclick=load;}}
  }
  return load();
 };
})();

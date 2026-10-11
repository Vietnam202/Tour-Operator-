/* VTA RC6.2 Smart Workflow P1 — isolated enhancements, no price calculations. */
(function () {
  'use strict';
  const safe = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  })[ch]);
  const positive = value => {
    const n=Number(value);
    return Number.isSafeInteger(n) && n>0 ? n : null;
  };
  const STATUS = new Set(['SENT','CONFIRMED','SUPERSEDED']);
  let teardown = () => {};

  function mount(ctx) {
    teardown();
    const {api,can,navigate,modal,toast,preview}=ctx;
    const input=document.getElementById('globalSearch');
    const results=document.getElementById('searchResults');
    const bell=document.getElementById('notifyBtn');
    if (!input || !results || !bell || !api?.user) return;
    let alive=true, timer=null, serial=0, active=-1, rows=[];
    const partnerOnly=Boolean(can('b2b.portal') && !can('b2b.admin'));
    const allowed=permission=>!partnerOnly && Boolean(can(permission));
    const button=document.createElement('button');
    button.type='button';button.id='vtaQuickSend';
    button.className='btn vta-smart-send';
    button.textContent='↗ Quick Send';
    button.title='Tạo link báo giá đã phát hành và chuẩn bị nội dung gửi khách';
    button.hidden=!allowed('sales.view');
    bell.parentNode.insertBefore(button,bell);
    results.setAttribute('role','listbox');
    results.setAttribute('aria-label','Kết quả tìm kiếm RC6.2');
    input.setAttribute('role','combobox');
    input.setAttribute('aria-autocomplete','list');
    input.setAttribute('aria-controls','searchResults');
    input.setAttribute('aria-expanded','false');
    input.placeholder='Tìm Tour, Quote, Booking, Customer, Supplier…';

    function clear() {
      serial++;clearTimeout(timer);rows=[];active=-1;
      results.classList.remove('open');results.replaceChildren();
      input.setAttribute('aria-expanded','false');
      input.removeAttribute('aria-activedescendant');
    }

    async function searchPreview(q) {
      const groups={quotes:[],bookings:[],customers:[],suppliers:[]};
      const sources=[
        ['quotes','sales.view','quotes',r=>[r.quote_ref,r.title,r.tour_name,r.lead_contact_name]],
        ['bookings','booking.view','bookings',r=>[r.booking_ref,r.title,r.lead_guest_name]],
        ['customers','sales.view','customers',r=>[r.customer_ref,r.full_name,r.company_name,r.email,r.whatsapp]],
        ['suppliers','supplier.view','suppliers',r=>[r.supplier_ref,r.name,r.city]]
      ];
      const query=q.toLocaleLowerCase('vi');
      for(const [group,permission,route,parts] of sources){
        if(!allowed(permission))continue;
        const response=await api.request(route);
        groups[group]=(response.items||[]).filter(r=>parts(r).join(' ').toLocaleLowerCase('vi').includes(query)).slice(0,8).map(r=>({
          id:r.id,ref:r.quote_ref||r.booking_ref||r.customer_ref||r.supplier_ref||'',
          title:r.title||r.tour_name||r.full_name||r.name||'',
          detail:r.lead_contact_name||r.lead_guest_name||r.company_name||r.city||'',
          status:r.version_status||r.operations_status||r.status||''
        }));
      }
      return {groups};
    }

    function openResult(record) {
      clear();
      if(!positive(record.id))return;
      if(record.kind==='quotes')navigate('quote',{quoteId:positive(record.id),quoteTool:'visual'});
      if(record.kind==='bookings')navigate('booking',{bookingId:positive(record.id),bookingTab:'overview'});
      if(record.kind==='customers')navigate('crm',{crmTab:'customers'});
      if(record.kind==='suppliers')navigate('suppliers',{productTab:'suppliers'});
      input.value='';
    }

    function renderSearch(groups) {
      rows=[];
      results.replaceChildren();
      const labels={quotes:'Tour & báo giá',bookings:'Booking',customers:'Khách hàng',suppliers:'Nhà cung cấp'};
      for(const kind of Object.keys(labels)) {
        const found=Array.isArray(groups?.[kind])?groups[kind]:[];
        if(!found.length)continue;
        const section=document.createElement('div');
        section.className='search-group';
        const title=document.createElement('h4');title.textContent=labels[kind];section.append(title);
        for(const r of found) {
          if(!positive(r.id))continue;
          const record={kind,id:r.id};
          const action=document.createElement('button');
          action.type='button';action.className='search-item vta-smart-result';
          action.setAttribute('role','option');action.setAttribute('aria-selected','false');
          action.id='smart-result-'+rows.length;
          const name=document.createElement('strong');
          name.textContent=[r.ref,r.title].filter(Boolean).join(' · ')||'Hồ sơ';
          const info=document.createElement('small');info.className='muted';
          info.textContent=[r.detail,r.status].filter(Boolean).join(' · ');
          action.append(name,document.createElement('br'),info);
          action.addEventListener('click',()=>openResult(record));
          section.append(action);rows.push({element:action,record});
        }
        results.append(section);
      }
      if(!rows.length){const empty=document.createElement('div');empty.className='empty';empty.textContent='Không tìm thấy dữ liệu phù hợp hoặc chưa có quyền xem.';results.append(empty);}
      results.classList.add('open');input.setAttribute('aria-expanded','true');
    }

    const onInput=()=> {
      clear();
      const q=input.value.trim();
      if(q.length<2)return;
      const current=++serial;
      timer=setTimeout(async()=>{
        try{
          const data=preview?await searchPreview(q):await api.request('smart/search?q='+encodeURIComponent(q));
          if(alive && serial===current && input.value.trim()===q)renderSearch(data.groups);
        }catch(err){
          if(alive && serial===current) {
            renderSearch({});toast('Không tải được kết quả tìm kiếm: '+err.message,true);
          }
        }
      },280);
    };
    const onKey=e=>{
      if(e.key==='Escape'){clear();return;}
      if(!results.classList.contains('open')||!rows.length)return;
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        e.preventDefault();
        active=(active+(e.key==='ArrowDown'?1:-1)+rows.length)%rows.length;
        rows.forEach((r,i)=>r.element.setAttribute('aria-selected',String(i===active)));
        input.setAttribute('aria-activedescendant',rows[active].element.id);
        rows[active].element.scrollIntoView?.({block:'nearest'});
      }
      if(e.key==='Enter' && active>=0){e.preventDefault();openResult(rows[active].record);}
    };
    const preserve=e=>e.preventDefault();
    input.addEventListener('input',onInput);
    input.addEventListener('keydown',onKey);
    results.addEventListener('pointerdown',preserve);

    function alertTarget(a) {
      if(a.kind==='booking' && positive(a.id))navigate('booking',{bookingId:positive(a.id),bookingTab:'overview'});
      else if(a.kind==='rate')navigate('suppliers',{productTab:'rates'});
      else navigate('operations',{opsModule:'tasks'});
    }

    async function previewAlerts(){
      const items=[];
      if(allowed('task.view')){
        const r=await api.request('tasks');
        for(const t of r.items||[]){
          if(!t.due_at)continue;
          const ms=new Date(t.due_at.replace(' ','T')).getTime()-Date.now();
          if(Number.isFinite(ms)&&ms<=7*86400000)
            items.push({kind:'task',id:t.id,title:t.title,detail:t.due_at,severity:ms<0?'critical':'warning'});
        }
      }
      if(allowed('operations.view')){
        const r=await api.request('bookings');
        for(const b of r.items||[])if(['HIGH','CRITICAL'].includes(b.risk_level)&&!['COMPLETED','CANCELLED'].includes(b.operations_status))
          items.push({kind:'booking',id:b.id,title:(b.booking_ref||'Booking'),detail:b.start_date,severity:b.risk_level==='CRITICAL'?'critical':'warning'});
      }
      if(allowed('rate.view')){
        const r=await api.request('rates');
        for(const x of r.items||[]){
          const days=(new Date((x.valid_to||'2000-01-01')+'T00:00:00').getTime()-Date.now())/86400000;
          if(days>=-1&&days<=30&&x.approval_status==='APPROVED')
            items.push({kind:'rate',id:x.id,title:x.rate_ref+' · '+x.product_name,detail:x.valid_to,severity:'warning'});
        }
      }
      return {items};
    }
    const getAlerts=()=>preview?previewAlerts():api.request('smart/alerts');
    let lastAlerts=[];
    async function refreshAlerts(){
      if(![ 'task.view','operations.view','rate.view' ].some(allowed))return [];
      const data=await getAlerts();
      if(!alive)return [];
      lastAlerts=Array.isArray(data.items)?data.items:[];
      bell.querySelector('.vta-smart-count')?.remove();
      if(lastAlerts.length){
        const count=document.createElement('span');count.className='vta-smart-count';
        count.textContent=String(lastAlerts.length);
        bell.append(count);
      }
      bell.setAttribute('aria-label','Cảnh báo: '+lastAlerts.length);
      return lastAlerts;
    }
    async function openAlerts(){
      let alerts;
      try{alerts=await refreshAlerts();}catch(err){toast('Không tải được cảnh báo: '+err.message,true);return;}
      const rowsHtml=alerts.length?alerts.map((a,i)=>
        '<article class="vta-smart-alert '+(a.severity==='critical'?'urgent':'')+'">'+
        '<span class="vta-smart-alert-dot" aria-hidden="true"></span><div><strong>'+safe(a.title)+'</strong><small>'+safe(a.detail||'')+'</small></div>'+
        '<button class="btn small" type="button" data-smart-alert="'+i+'">Mở</button></article>'
      ).join(''):'<p class="empty">Hiện không có cảnh báo trong phạm vi quyền của bạn.</p>';
      const dialog=modal('Smart Alerts · '+alerts.length, '<div class="vta-smart-alert-list">'+rowsHtml+'</div><p class="muted">Dữ liệu đọc trực tiếp từ Tasks, Booking Risk và Rate Expiry. Không tự xác nhận dịch vụ.</p>');
      dialog.querySelectorAll('[data-smart-alert]').forEach(btn=>btn.addEventListener('click',()=>{
        const a=alerts[Number(btn.dataset.smartAlert)];
        document.querySelector('.modal-backdrop')?.remove();
        if(a)alertTarget(a);
      }));
    }

    async function copyText(value) {
      if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(value);
      else {
        const field=document.createElement('textarea');field.value=value;
        field.style.position='fixed';field.style.opacity='0';
        document.body.append(field);field.select();
        const ok=document.execCommand('copy');field.remove();
        if(!ok)throw Error('Trình duyệt không cho phép sao chép');
      }
    }

    async function openQuickSend(){
      if(!allowed('sales.view'))return toast('Không có quyền xem báo giá.',true);
      if(!allowed('quote.send')||(!preview&&!allowed('proposal.send'))){
        return toast('Cần quyền quote.send và proposal.send để tạo link Proposal.',true);
      }
      let records;
      try {records=(await api.request('quotes')).items||[];}catch(err){toast(err.message,true);return;}
      const sent=records.filter(x=>STATUS.has(String(x.version_status||'').toUpperCase())&&positive(x.id));
      const choices=sent.map(x=>'<option value="'+positive(x.id)+'">'+safe(x.quote_ref||('Quote #'+x.id))+' · '+safe(x.title||x.tour_name||'')+'</option>').join('');
      const note=preview?'<p class="vta-smart-preview">Preview: không tạo link thật, không gửi tin nhắn.</p>':'';
      const body='<form id="vtaSmartShareForm"><label>Chọn báo giá đã phát hành<select id="vtaSmartQuote" required>'+choices+'</select></label>'+
        '<label>Thời hạn link (ngày)<select id="vtaSmartDays"><option value="7">7 ngày</option><option value="14" selected>14 ngày</option><option value="30">30 ngày</option></select></label>'+
        '<label class="vta-smart-check"><input type="checkbox" id="vtaSmartReview" required> Tôi đã kiểm tra đúng khách, phiên bản được duyệt và nội dung công khai.</label>'+
        note+'<div id="vtaSmartShareResult" aria-live="polite"></div></form>';
      const dialog=modal('Quick Send · Customer Proposal',body,
        '<button class="btn" data-close>Đóng</button><button class="btn primary" type="button" id="vtaSmartGenerate" '+(!sent.length||preview?'disabled':'')+'>Tạo link đã duyệt</button>');
      if(!sent.length)dialog.querySelector('#vtaSmartShareResult').textContent='Chưa có báo giá SENT / CONFIRMED để chia sẻ.';
      if(preview)dialog.querySelector('#vtaSmartShareResult').textContent='Bản demo không gọi API phát hành hoặc gửi tin.';
      const submit=dialog.querySelector('#vtaSmartGenerate');
      submit?.addEventListener('click',async()=>{
        if(!dialog.querySelector('#vtaSmartShareForm').reportValidity())return;
        const id=positive(dialog.querySelector('#vtaSmartQuote').value);
        const days=Number(dialog.querySelector('#vtaSmartDays').value);
        if(!id || ![7,14,30].includes(days))return;
        submit.disabled=true;
        try{
          const full=await api.request('quotes/'+id);
          const version=full.version;
          if(!positive(version?.id) || !STATUS.has(String(version.version_status).toUpperCase()))
            throw Error('Phiên bản hiện tại chưa được phát hành; không tạo link.');
          const created=await api.request('quote-versions/'+positive(version.id)+'/proposal/links',{method:'POST',body:{days}});
          if(!created.url || !/^https:\/\//i.test(created.url))throw Error('Đường dẫn không an toàn; liên hệ quản trị.');
          const url=created.url;
          const message='Vietnam Travel Advisor – Your approved travel proposal: '+url+'\nThis link may expire. Please contact your travel consultant for confirmation.';
          const result=dialog.querySelector('#vtaSmartShareResult');
          result.replaceChildren();
          const text=document.createElement('p');
          text.textContent='Đã tạo link Proposal; chưa gửi cho khách. Hết hạn: '+(created.expires_at||'');
          const field=document.createElement('input');field.value=url;field.readOnly=true;
          field.setAttribute('aria-label','Đường dẫn proposal đã duyệt');
          const actions=document.createElement('div');actions.className='vta-smart-share-actions';
          const copy=document.createElement('button');copy.type='button';copy.className='btn';copy.textContent='Sao chép nội dung';
          copy.onclick=async()=>{try{await copyText(message);toast('Đã sao chép nội dung gửi khách.');}catch(e){toast(e.message,true)}};
          const email=document.createElement('a');email.className='btn';email.textContent='Soạn Email';
          email.href='mailto:?subject='+encodeURIComponent('Your Vietnam travel proposal')+'&body='+encodeURIComponent(message);
          const whatsapp=document.createElement('a');whatsapp.className='btn';whatsapp.textContent='Mở WhatsApp';
          whatsapp.target='_blank';whatsapp.rel='noopener noreferrer';
          whatsapp.href='https://wa.me/?text='+encodeURIComponent(message);
          actions.append(copy,email,whatsapp);result.append(text,field,actions);
          toast('Link được tạo. Cần nhân viên tự chọn kênh gửi.');
        }catch(err){toast('Quick Send: '+err.message,true);}
        finally{if(submit.isConnected)submit.disabled=false;}
      });
    }

    // Replace the old passive bell; keep the same Tasks workspace accessible via navigation.
    bell.onclick=openAlerts;
    button.onclick=openQuickSend;
    refreshAlerts().catch(()=>{});
    teardown=()=>{
      alive=false;serial++;clearTimeout(timer);
      input.removeEventListener('input',onInput);
      input.removeEventListener('keydown',onKey);
      results.removeEventListener('pointerdown',preserve);
      button.remove();bell.querySelector('.vta-smart-count')?.remove();
    };
  }
  window.VTASmartEnhancements={mount};
})();

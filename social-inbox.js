(()=>{'use strict';
/** Native VTA website inbox UI. P2 manual replies queue for authenticated website backend polling. */
window.VTAWebsiteInbox=function(host,opt){
 const {api,esc,toast,can,t,preview}=opt;
 if(!host||!host.isConnected)return;
 if(preview){
  host.innerHTML='<section class="mk-panel"><h2>'+t('Unified Inbox','Hộp thư chung')+'</h2><p class="mk-note">'+t('Preview contains no real customer messages.','Chế độ xem thử không có tin nhắn khách thật.')+'</p></section>';
  return;
 }
 let items=[],selected=null,detail=null,busy=false,pendingReply='',pendingReplyKey=null,pendingTourDraft=null,metaAccounts=[];
 const label=s=>({NEW:t('New','Mới'),FOLLOW_UP:t('Follow-up','Đang xử lý'),CLOSED:t('Closed','Đã đóng')}[s]||s);
 const safe=x=>esc(String(x??''));
 const short=x=>String(x||'').slice(0,140);
 const isMeta=x=>/^meta_(fb|ig)_/.test(String(x?.source_code||''));
 const channel=x=>String(x?.source_code||'').startsWith('meta_fb_')?'Messenger':String(x?.source_code||'').startsWith('meta_ig_')?'Instagram':'Website';
 const inbound=m=>'<article class="vta-inbox-message"><p>'+safe(m.body)+'</p><small>'+safe(m.received_at)+'</small></article>';
 const row=x=>'<button type="button" class="vta-inbox-contact '+(selected===Number(x.id)?'selected':'')+'" data-vta-conv="'+Number(x.id)+'"><strong>'+safe(isMeta(x)&&x.contact_name==='Website visitor'?channel(x)+' visitor':(x.contact_name||t('Website visitor','Khách website')))+'</strong><small>'+safe(channel(x))+' · '+safe(label(x.status))+'</small><span>'+safe(short(x.last_message))+'</span></button>';
 function draw(){
  if(!host.isConnected)return;
  const c=detail?.conversation||null, msgs=detail?.messages||[],outs=detail?.outbound||[],allowed=can('lead.manage'),meta=isMeta(c),
  metaReplies=detail?.metaReplies||[],messenger=detail?.metaStatus||null;
  const outbound=m=>'<article class="vta-inbox-message vta-inbox-outgoing"><p>'+safe(m.body)+'</p><small>'+safe(m.status==='BLOCKED'?t('Blocked: tour approval changed','Đã chặn: tour bị thay đổi hoặc mất phê duyệt'):m.status==='RELAYED'?t('Collected by website relay; not necessarily read','Website đã nhận từ hệ thống; khách chưa chắc đã đọc'):t('Queued for website','Đang chờ website lấy tin'))+' · '+safe(m.created_at)+'</small></article>';
  const metaOutbound=m=>'<article class="vta-inbox-message vta-inbox-outgoing"><p>'+safe(m.message_text)+'</p><small>'+
    safe(({QUEUED:t('Queued, not delivered','Đang chờ, chưa gửi'),SENDING:t('Sending to Meta','Đang gửi tới Meta'),SENT:t('Meta confirmed sending, not reading','Meta đã xác nhận gửi, không phải khách đã đọc'),UNCERTAIN:t('Unknown outcome — verify with Meta','Không rõ kết quả — cần đối soát Meta'),BLOCKED:t('Blocked by policy','Đã chặn do điều kiện gửi')}[m.status]||m.status))+
    ' · '+safe(m.created_at)+'</small></article>';
  const lead=c&&c.lead_request_id?'<p class="mk-note">Lead Hub #'+Number(c.lead_request_id)+'</p>':'';
  host.innerHTML='<section class="mk-panel"><div class="mk-section-head"><div><h2>'+t('Unified Inbox · Website & Meta','Hộp thư chung · Website & Meta')+'</h2><p class="mk-note">'+t('Website supports two-way chat. Meta supports inbound text, internal drafts and Lead Hub only.','Website chat hai chiều. Meta mới nhận tin chữ, lưu nháp và chuyển Lead Hub.')+'</p></div><span class="mk-badge">'+items.length+' '+t('conversations','hội thoại')+'</span></div>'+
   '<p class="mk-note">'+t('Meta sources configured, not live verified: ','Nguồn Meta đã cấu hình, chưa xác minh live: ')+metaAccounts.map(a=>safe(a.platform)).join(', ')+'</p>'+ '<div class="vta-inbox-layout"><aside class="vta-inbox-list"><label>'+t('Find conversation','Tìm hội thoại')+'<input type="search" class="vta-inbox-search" placeholder="'+t('Guest / message…','Tên khách / nội dung…')+'"></label><div class="vta-inbox-contacts">'+(items.map(row).join('')||'<p class="mk-note">'+t('No verified messages yet.','Chưa có tin nhắn đã xác thực.')+'</p>')+'</div></aside>'+
   '<div class="vta-inbox-thread"><h3>'+safe(c&&isMeta(c)&&c.contact_name==='Website visitor'?channel(c)+' visitor':(c?.contact_name||t('Select a conversation','Chọn hội thoại')))+'</h3><div class="vta-inbox-history">'+(msgs.map(inbound).join('')||'<p class="mk-note">'+t('No messages','Chưa có tin nhắn')+'</p>')+(outs.length?'<h4>'+t('Website replies','Phản hồi website')+'</h4>'+outs.map(outbound).join(''):'')+
  (metaReplies.length?'<h4>'+t('Messenger replies','Phản hồi Messenger')+'</h4>'+metaReplies.map(metaOutbound).join(''):'')+'</div>'+
  (!meta&&c?'<div id="vtaTourAdvisorPanel"></div>':'')+
  (!meta&&c?'<div id="vtaTourSharePanel"></div>':'')+
  (meta&&c?'<p class="mk-note">'+(messenger?.can_send?
     t('Messenger standard reply window is open. Staff reply only, no automated sending.','Messenger đang trong thời hạn trả lời tiêu chuẩn. Chỉ nhân viên gửi, không tự động.'):
     t('Meta send unavailable: ','Không thể gửi Meta: ')+safe(messenger?.reason||'INSTAGRAM_DRAFT_ONLY'))+'</p>':'')+
  (meta&&allowed&&c&&messenger?.can_send?'<form data-vta-form="meta-send" class="mk-form vta-inbox-send"><label>'+
    t('Reply on Messenger','Trả lời trên Messenger')+'<textarea name="body" required rows="3" maxlength="1000">'+safe(pendingReply)+'</textarea></label>'+
    '<button type="submit" class="btn primary" '+(busy?'disabled':'')+'>'+t('Queue Messenger response','Xếp hàng gửi Messenger')+'</button>'+
    '<p class="mk-note">'+t('A queued response is not sent until an authorized CLI worker runs.','Đưa vào hàng chờ chưa phải đã gửi, worker có quyền mới xử lý.')+'</p></form>':'')+
  (!meta&&allowed&&c&&c.status!=='CLOSED'?'<form data-vta-form="send" class="mk-form vta-inbox-send"><label>'+t('Send to visitor via website relay','Gửi đến khách qua website')+'<textarea name="body" required rows="3" maxlength="8000">'+safe(pendingReply)+'</textarea></label><button type="submit" class="btn primary" '+(busy?'disabled':'')+'>'+t('Queue reply','Xếp hàng gửi')+'</button><p class="mk-note">'+t('Queued means not yet delivered. Relay confirmation does not prove visitor read.','Đưa vào hàng chờ chưa có nghĩa đã gửi. Website đã nhận cũng chưa có nghĩa khách đã đọc.')+'</p></form>':'')+'</div>'+
   '<aside class="vta-inbox-details">'+(c?'<h3>'+t('Customer & Sales','Khách hàng & Sales')+'</h3><p class="mk-note">'+safe(c.source_code)+' · '+safe(label(c.status))+'</p><p class="mk-note">'+safe(c.email||c.phone||t('Contact details not supplied','Chưa có thông tin liên hệ'))+'</p>'+lead+
   (allowed?'<form data-vta-form="update" class="mk-form"><label>'+t('Status','Trạng thái')+'<select name="status">'+['NEW','FOLLOW_UP','CLOSED'].map(s=>'<option value="'+s+'"'+(s===c.status?' selected':'')+'>'+safe(label(s))+'</option>').join('')+'</select></label><label>'+t('Reply draft (not sent)','Nháp trả lời (chưa gửi)')+'<textarea name="reply_draft" maxlength="8000" rows="5">'+safe(c.reply_draft||'')+'</textarea></label><button type="submit" class="btn" '+(busy?'disabled':'')+'>'+t('Save note','Lưu nháp')+'</button></form>':'')+
   (allowed&&!c.lead_request_id?'<details class="vta-inbox-lead"><summary>'+t('Create Lead Hub request','Tạo yêu cầu Lead Hub')+'</summary><form data-vta-form="handoff" class="mk-form"><label>'+t('Contact name','Tên khách')+'<input name="contact_name" required maxlength="190" value="'+safe(c.contact_name||'')+'"></label><label>Email<input name="email" type="email" maxlength="190" value="'+safe(c.email||'')+'"></label><label>'+t('Phone / WhatsApp','Số điện thoại / WhatsApp')+'<input name="phone" maxlength="64" value="'+safe(c.phone||'')+'"></label><label>'+t('Total guests','Tổng khách')+'<input name="total_guests" type="number" min="1" max="10000" required></label><label>'+t('Paying guests','Khách trả tiền')+'<input name="paying_pax" type="number" min="1" max="10000" required></label><label>FOC<input name="foc" type="number" min="0" max="10000" value="0" required></label><label>'+t('Travel date','Ngày đi')+'<input name="travel_date" type="date"></label><label>'+t('Destination','Điểm đến')+'<input name="destination" maxlength="500"></label><label>'+t('Hotel category','Hạng khách sạn')+'<input name="hotel_level" maxlength="32"></label><p class="mk-note">'+t('A contact channel and verified guest counts are required. Sales must qualify and accept before conversion.','Cần email/điện thoại và số khách được kiểm tra. Sales phải tiếp nhận trước khi chuyển đổi.')+'</p><button type="submit" class="btn primary" '+(busy?'disabled':'')+'>'+t('Create lead','Tạo lead')+'</button></form></details>':''):'<p class="mk-note">'+t('Select a conversation to view details.','Chọn hội thoại để xem chi tiết.')+'</p>')+'</aside></div></section>';
  if(c&&window.VTATourAdvisorPanel){const anchor=host.querySelector('#vtaTourAdvisorPanel');if(anchor)window.VTATourAdvisorPanel.mount(anchor,{api,esc,toast,can,t,conversationId:Number(c.id),onUse:reply=>{pendingReply=reply.text;pendingTourDraft=reply;pendingReplyKey=null;draw();}});}
  if(c&&window.VTATourSharePanel){const anchor=host.querySelector('#vtaTourSharePanel');if(anchor)window.VTATourSharePanel.mount(anchor,{api,esc,toast,can,t,conversationId:Number(c.id),onUse:reply=>{pendingReply=reply.text;pendingTourDraft=null;pendingReplyKey=null;draw();}});}
 }
 async function choose(id){
  selected=id;detail=null;pendingReply='';pendingReplyKey=null;pendingTourDraft=null;draw();
  try{const d=await api.request('marketing/social-inbox/'+id);
   const o=isMeta(d.conversation)?{messages:[]}:await api.request('marketing/website-chat/outbound?conversation_id='+id);
   const [metaStatus,metaHistory]=isMeta(d.conversation)?await Promise.all([
     api.request('marketing/meta-replies/status?conversation_id='+id),
     api.request('marketing/meta-replies/list?conversation_id='+id)
   ]):[null,{items:[]}];
   if(host.isConnected&&selected===id){detail={...d,outbound:o.messages||[],metaStatus,metaReplies:metaHistory.items||[]};draw();}
  }catch(e){if(host.isConnected)toast(e.message,true);}
 }
 async function refresh(){
  try{
   const [r,meta]=await Promise.all([api.request('marketing/social-inbox'),api.request('marketing/meta-inbox').catch(()=>({accounts:[]}))]);
   metaAccounts=meta.accounts||[];
   if(!host.isConnected)return;
   items=r.items||[];const current=items.some(x=>Number(x.id)===selected)?selected:Number(items[0]?.id||0);
   if(current)await choose(current);else{selected=null;detail=null;draw();}
  }catch(e){
   if(host.isConnected){host.innerHTML='<section class="mk-panel"><h2>Unified Inbox</h2><p class="mk-note">'+t('Could not load inbox. Check migration 024 and lead.view permission.','Không tải được hộp thư. Kiểm tra migration 024 và quyền lead.view.')+'</p></section>';toast(e.message,true);}
  }
 }
 host.addEventListener('click',e=>{
  const btn=e.target.closest('[data-vta-conv]');
  if(btn&&host.contains(btn))choose(Number(btn.dataset.vtaConv));
 });
 host.addEventListener('input',e=>{
  if(!e.target.matches('.vta-inbox-search'))return;
  const needle=e.target.value.trim().toLowerCase();
  const rows=host.querySelectorAll('.vta-inbox-contact');
  rows.forEach(btn=>{
   const item=items.find(x=>Number(x.id)===Number(btn.dataset.vtaConv));
   btn.hidden=!!needle&&!([item?.contact_name,item?.last_message,item?.source_code].join(' ').toLowerCase().includes(needle));
  });
 });
 host.addEventListener('submit',async e=>{
  const form=e.target.closest('[data-vta-form]');if(!form||!detail||busy)return;
  e.preventDefault();busy=true;const c=detail.conversation;const body=Object.fromEntries(new FormData(form));
  try{
   if(form.dataset.vtaForm==='meta-send'){
    if(!isMeta(c)||!detail.metaStatus?.can_send)throw Error(t('Messenger response no longer eligible','Không còn đủ điều kiện trả lời Messenger'));
    const msg=String(body.body||'').trim();if(!msg||msg.length>1000)throw Error(t('Invalid message','Nội dung không hợp lệ'));
    if(msg!==pendingReply||!pendingReplyKey)pendingReplyKey=crypto.randomUUID();
    pendingReply=msg;
    const result=await api.request('marketing/meta-replies/send',{method:'POST',body:{
       conversation_id:Number(c.id),request_key:pendingReplyKey,body:msg}});
    toast(t('Messenger response queued #','Đã xếp hàng phản hồi Messenger #')+result.id);
    pendingReply='';pendingReplyKey=null;
   }else if(form.dataset.vtaForm==='send'){
    if(isMeta(c))throw Error(t('Meta outbound messaging is not connected. Save an internal draft instead.','Chưa kết nối gửi tin Meta. Vui lòng lưu nháp nội bộ.'));
    const text=String(body.body||'').trim();
    if(!text)throw Error(t('Reply cannot be empty.','Nội dung không được trống.'));
    if(text!==pendingReply||!pendingReplyKey)pendingReplyKey=crypto.randomUUID();
    pendingReply=text;
    const draftId=pendingTourDraft&&pendingTourDraft.text===text?Number(pendingTourDraft.id):null;
    const result=await api.request('marketing/website-chat/send',{method:'POST',body:{conversation_id:Number(c.id),request_key:pendingReplyKey,body:text,...(draftId?{tour_advisor_draft_id:draftId}:{})}});
    toast(t('Reply queued #','Đã xếp hàng tin #')+result.id);
    pendingReply='';pendingReplyKey=null;pendingTourDraft=null;
   }else if(form.dataset.vtaForm==='update'){
    await api.request('marketing/social-inbox/update',{method:'POST',body:{id:Number(c.id),version_no:Number(c.version_no),status:body.status,reply_draft:body.reply_draft}});
    toast(t('Internal draft saved; nothing was sent.','Đã lưu nháp nội bộ; không gửi tin ra ngoài.'));
   }else{
    if(!body.email&&!body.phone)throw Error(t('Email or phone is required.','Cần email hoặc số điện thoại.'));
    body.id=Number(c.id);for(const f of ['total_guests','paying_pax','foc'])body[f]=Number(body[f]);
    if(body.total_guests<1||body.paying_pax<1||body.paying_pax+body.foc>body.total_guests)throw Error(t('Check guest counts.','Kiểm tra số khách.'));
    const result=await api.request('marketing/social-inbox/handoff',{method:'POST',body});
    toast(t('Lead Hub request created #','Đã tạo yêu cầu Lead Hub #')+result.request_id);
   }
   await refresh();
  }catch(err){toast(err.message,true);busy=false;draw();return;}
  busy=false;
 });
 host.innerHTML='<section class="mk-panel"><p class="mk-note">'+t('Loading website and social conversations…','Đang tải hội thoại website và mạng xã hội…')+'</p></section>';
 refresh();
};
})();

(()=>{'use strict';
/** P4: staff-only public-outline link creation. One-time token shown only on creation. */
window.VTATourSharePanel={
 mount(host,{api,esc,toast,t,can,conversationId,onUse}){
  if(!host||!Number.isSafeInteger(Number(conversationId))||!conversationId)return;
  const writable=can('lead.manage');
  let shares=[],approvedTours=[],selectedTour='',created=null,busy=false;
  const safe=x=>esc(String(x??''));
  const state=x=>x.revoked_at?t('Revoked','Đã thu hồi'):Date.parse(String(x.expires_at).replace(' ','T'))<Date.now()?t('Expired','Hết hạn'):t('Active until','Hiệu lực đến')+' '+String(x.expires_at||'');
  function render(){
   if(!host.isConnected)return;
   host.innerHTML='<section class="vta-tour-share"><h3>'+t('Tour Share Center','Trung tâm chia sẻ chương trình')+'</h3>'+
    '<p class="mk-note">'+t('Public outline links only: title, destination and day headings. No private pricing or documents.','Chỉ chia sẻ bản tóm tắt công khai: tên tour, điểm đến và các ngày. Không có giá nội bộ hoặc tài liệu gốc.')+'</p>'+
    (writable?'<form class="vta-share-create" data-share-form="create">'+
    '<label>'+t('Approved tour','Chương trình được duyệt')+'<select name="program_id" required>'+approvedTours.map(p=>'<option value="'+Number(p.id)+'"'+(String(p.id)===selectedTour?' selected':'')+'>'+safe(p.title)+' · '+Number(p.day_count)+' '+t('days','ngày')+'</option>').join('')+'</select></label>'+
    '<label>'+t('Expires after','Hết hạn sau')+'<select name="expires_in_days"><option value="1">1 '+t('day','ngày')+'</option><option value="3">3 '+t('days','ngày')+'</option><option value="7" selected>7 '+t('days','ngày')+'</option><option value="14">14 '+t('days','ngày')+'</option></select></label>'+
    '<button type="submit" class="btn" '+(busy||!approvedTours.length?'disabled':'')+'>'+t('Create private share link','Tạo liên kết chia sẻ')+'</button></form>':'')+
    (created?'<div class="vta-share-created"><strong>'+t('New link — visible once','Liên kết mới — chỉ hiển thị một lần')+'</strong>'+
     '<input type="text" readonly aria-label="Tour share link" value="'+safe(created.url)+'">'+
     '<div class="vta-share-actions"><button type="button" data-share-action="copy" class="btn">'+t('Copy link','Sao chép')+'</button>'+
     '<button type="button" data-share-action="use" class="btn primary">'+t('Use in reply','Chèn vào ô trả lời')+'</button></div></div>':'')+
    '<h4>'+t('Recent links','Liên kết gần đây')+'</h4>'+
    (shares.length?'<div class="vta-share-history">'+shares.map(x=>'<div class="vta-share-record"><span>#'+Number(x.id)+' · Tour #'+Number(x.program_id)+'<small>'+safe(state(x))+' · '+Number(x.view_count||0)+' '+t('page opens (not reads)','lượt mở trang (không phải đã đọc)')+'</small></span>'+
     (writable&&!x.revoked_at?'<button type="button" class="btn" data-share-action="revoke" data-share-id="'+Number(x.id)+'" '+(busy?'disabled':'')+'>'+t('Revoke','Thu hồi')+'</button>':'')+'</div>').join('')+'</div>':
      '<p class="mk-note">'+t('No links created yet.','Chưa tạo liên kết.')+'</p>')+'</section>';
  }
  async function load(){
   try{
    const [r,tours]=await Promise.all([
      api.request('marketing/tour-share/list?conversation_id='+Number(conversationId)),
      api.request('marketing/tour-advisor/programs?conversation_id='+Number(conversationId))
    ]);
    shares=r.items||[];approvedTours=(tours.items||[]).filter(p=>p.approved);
    if(!approvedTours.some(p=>String(p.id)===selectedTour))selectedTour=approvedTours.length?String(approvedTours[0].id):'';
   }catch(e){toast(e.message,true);}
   render();
  }
  host.addEventListener('submit',async e=>{
   const form=e.target.closest('[data-share-form="create"]');
   if(!form||busy||!writable)return;
   e.preventDefault();busy=true;
   const data=Object.fromEntries(new FormData(form));
   selectedTour=String(data.program_id||'');
   if(!approvedTours.some(p=>String(p.id)===selectedTour)){busy=false;toast(t('Select an approved tour','Chọn tour đã được duyệt'),true);render();return;}
   render();
   try{
    const r=await api.request('marketing/tour-share/create',{
      method:'POST',
      body:{conversation_id:Number(conversationId),program_id:Number(data.program_id),
        expires_in_days:Number(data.expires_in_days),request_key:'share_'+crypto.randomUUID().replace(/-/g,'')}
    });
    if(typeof r.token==='string'&&/^[a-f0-9]{64}$/.test(r.token)){
      const uri=new URL('api/index.php',document.baseURI);
      uri.searchParams.set('route','marketing/tour-share/view');
      uri.searchParams.set('token',r.token);
      created={id:r.id,url:uri.href};
      toast(t('Link created. Staff approval still required to send it.','Đã tạo liên kết. Nhân viên cần xác nhận trước khi gửi.'));
    }else toast(t('Link already created; create another to obtain a new URL.','Liên kết đã được tạo trước đó; cần tạo liên kết mới để nhận URL.'),true);
   }catch(err){toast(err.message,true);}
   finally{busy=false;await load();}
  });
  host.addEventListener('click',async e=>{
   const b=e.target.closest('button[data-share-action]');
   if(!b||busy)return;
   if(b.dataset.shareAction==='use'&&created){
      onUse({text:t('Here is a sample tour outline for your reference: ','Đây là chương trình tour tham khảo: ')+created.url});
      return;
   }
   if(b.dataset.shareAction==='copy'&&created) {
      try {await navigator.clipboard.writeText(created.url);toast(t('Link copied','Đã sao chép liên kết'));}
      catch(err){toast(t('Copy unavailable. Select the URL manually.','Không sao chép được. Hãy chọn URL thủ công.'),true);}
      return;
   }
   if(b.dataset.shareAction==='revoke'&&writable) {
      const id=Number(b.dataset.shareId);
      if(!Number.isSafeInteger(id)||!window.confirm(t('Revoke this share link immediately?','Thu hồi ngay liên kết này?')))return;
      busy=true;render();
      try{await api.request('marketing/tour-share/revoke',{method:'POST',body:{id}});if(created&&created.id===id)created=null;toast(t('Link revoked','Đã thu hồi liên kết'));}
      catch(err){toast(err.message,true);}
      finally{busy=false;await load();}
   }
  });
  render();load();
 }
};
})();
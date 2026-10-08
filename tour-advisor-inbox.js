(()=>{'use strict';
/**
 * P3 native VTA tour suggestion panel, never an automatic sending interface.
 * All model/marketing approval checks are performed again by PHP.
 */
window.VTATourAdvisorPanel={
 mount(host,{api,esc,toast,t,can,conversationId,onUse}){
  if(!host||!Number.isSafeInteger(Number(conversationId))||!conversationId)return;
  let items=[],generated=null,activeSearch='',busy=false,selectedLanguage='en';
  const safe=x=>esc(String(x??''));
  const hint=t('Customer-facing metadata only. No rates, availability or documents are sent automatically.','Chỉ sử dụng nội dung giới thiệu đã duyệt. Không tự gửi giá, tình trạng dịch vụ hay tài liệu.');
  const canApprove=can('tour_library.manage'),canDraft=can('lead.manage');
  function render(){
   if(!host.isConnected)return;
   host.innerHTML='<section class="vta-tour-advisor"><h3>'+t('VTA Tour Advisor','VTA Trợ lý gợi ý tour')+'</h3>'+
    '<p class="mk-note">'+hint+'</p>'+
    '<label class="vta-tour-search">'+t('Search tour','Tìm chương trình')+
    '<input type="search" maxlength="190" name="tour_query" value="'+safe(activeSearch)+'" placeholder="'+t('Destination or program name','Điểm đến hoặc tên tour')+'"></label>'+
    '<label class="vta-tour-lang">'+t('Reply language','Ngôn ngữ trả lời')+'<select name="tour_language"><option value="en"'+(selectedLanguage==='en'?' selected':'')+'>English</option><option value="vi"'+(selectedLanguage==='vi'?' selected':'')+'>Tiếng Việt</option></select></label>'+ 
    '<button type="button" class="btn" data-tour-action="search" '+(busy?'disabled':'')+'>'+t('Find tours','Tìm tour')+'</button>'+
    '<div class="vta-tour-results">'+(items.map(p=>'<div class="vta-tour-card" data-program-id="'+Number(p.id)+'">'+
       '<strong>'+safe(p.title)+'</strong><small>'+safe(p.destination)+' · '+Number(p.day_count)+' '+t('days','ngày')+'</small>'+
       '<small>'+safe((p.day_titles||[]).slice(0,5).map((s,i)=>(i+1)+'. '+s).join(' · '))+'</small>'+
       (p.approved?'<span class="vta-tour-approved">'+t('Approved for sharing','Đã duyệt chia sẻ')+'</span>':
        '<span class="vta-tour-unapproved">'+t('Not approved for sharing','Chưa duyệt chia sẻ')+'</span>')+
       '<div class="vta-tour-actions">'+
       (canApprove&&!p.approved?'<button type="button" class="btn" data-tour-action="approve" data-program-id="'+Number(p.id)+'" '+(busy?'disabled':'')+'>'+t('Approve public outline','Duyệt nội dung giới thiệu')+'</button>':'')+
       (canDraft&&p.approved?'<button type="button" class="btn" data-tour-action="draft" data-program-id="'+Number(p.id)+'" '+(busy?'disabled':'')+'>'+t('Prepare draft','Soạn nháp')+'</button>':'')+'</div></div>').join('')||
       '<p class="mk-note">'+t('No matching share-approved programs. Marketing manager can approve eligible ACTIVE programs.','Không tìm thấy tour được phép chia sẻ. Quản lý có thể duyệt chương trình ACTIVE phù hợp.')+'</p>')+'</div>'+
    (generated?'<div class="vta-tour-draft"><strong>'+t('Draft only — review before sending','Chỉ là nháp — cần kiểm tra trước khi gửi')+'</strong>'+
    '<pre>'+safe(generated.text)+'</pre><button type="button" class="btn primary" data-tour-action="use">'+t('Use in reply editor','Đưa vào ô trả lời')+'</button></div>':'')+
    '</section>';
  }
  async function load(){
    if(busy)return;busy=true;render();
    try{
      const r=await api.request('marketing/tour-advisor/programs?conversation_id='+Number(conversationId)+'&q='+encodeURIComponent(activeSearch));
      items=r.items||[];
    }catch(e){toast(e.message,true);}
    finally{busy=false;render();}
  }
  host.addEventListener('click',async e=>{
    const b=e.target.closest('button[data-tour-action]');if(!b||busy)return;
    const action=b.dataset.tourAction;
    const p=items.find(x=>Number(x.id)===Number(b.dataset.programId));
    if(action==='search') {activeSearch=host.querySelector('[name=tour_query]')?.value.trim()||'';generated=null;await load();return;}
    if(action==='use'&&generated){onUse({id:generated.id,text:generated.text});return;}
    if(!p)return;
    if(action==='approve'){
      if(!canApprove||!window.confirm(t('Confirm this ACTIVE itinerary metadata is suitable for customers? No supplier rates or private notes may be shared.','Xác nhận tên, điểm đến và tiêu đề từng ngày của tour này được phép chia sẻ cho khách? Không được chứa giá net hay ghi chú nội bộ.')))return;
      busy=true;render();
      try{await api.request('marketing/tour-advisor/approve',{method:'POST',body:{program_id:p.id,review_hash:p.review_hash}});toast(t('Marketing sharing approved','Đã duyệt nội dung chia sẻ'));}catch(err){toast(err.message,true);}
      finally{busy=false;await load();}
    }
    if(action==='draft'){
      if(!canDraft)return;
      selectedLanguage=host.querySelector('[name=tour_language]')?.value==='vi'?'vi':'en';
      busy=true;render();
      try{
        const language=selectedLanguage;
        const key='tour_draft_'+crypto.randomUUID().replace(/-/g,'');
        const r=await api.request('marketing/tour-advisor/draft',{method:'POST',body:{conversation_id:Number(conversationId),program_id:p.id,language,request_key:key}});
        generated={id:r.id,text:r.text,program_id:r.program_id};
        toast(t('Customer-safe reply draft prepared','Đã tạo nháp trả lời, chưa gửi cho khách.'));
      }catch(err){toast(err.message,true);}
      finally{busy=false;render();}
    }
  });
  host.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.matches('[name=tour_query]')){e.preventDefault();activeSearch=e.target.value.trim();generated=null;load();}});
  render();load();
 }
};
})();
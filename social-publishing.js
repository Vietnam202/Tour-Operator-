(()=>{'use strict';
/** P5 social scheduler. Posting never occurs from a browser API call. */
window.VTASocialPublisher=function(host,{api,esc,toast,t,can,preview,contents,user}){
 if(!host||!host.isConnected)return;
 if(preview){host.innerHTML='<section class="mk-panel"><h2>Social Publishing</h2><p class="mk-note">'+t('Preview only — no real account or publishing worker.','Chỉ xem trước — không kết nối tài khoản hay xuất bản thật.')+'</p></section>';return;}
 let accounts=[],jobs=[],busy=false;
 const approved=(contents||[]).filter(x=>x.status==='APPROVED'&&x.channel==='Facebook'&&x.content_format==='POST'&&!x.asset_url);
 const safe=x=>esc(String(x??''));
 const statusLabel={DRAFT:t('Draft approval','Chờ duyệt lịch'),APPROVED:t('Scheduled','Đã lên lịch'),SENDING:t('Provider request in progress','Đang xử lý'),PUBLISHED:t('Published · confirmed','Đã đăng · xác nhận'),UNCERTAIN:t('Unknown · reconcile manually','Chưa rõ · cần đối soát'),BLOCKED:t('Blocked','Đã chặn'),CANCELLED:t('Cancelled','Đã hủy')};
 function render(){
  if(!host.isConnected)return;
  const enabled=accounts.filter(a=>a.ready),canApprove=can('marketing.approve');
  host.innerHTML='<section class="mk-panel"><div class="mk-section-head"><div><h2>'+t('Social Publishing','Đăng bài mạng xã hội')+'</h2><p class="mk-note">'+t('Facebook Page text posts only in P5. Account credentials remain on the private server.','P5 chỉ hỗ trợ bài viết chữ cho Facebook Page. Thông tin kết nối chỉ lưu ở máy chủ riêng.')+'</p></div></div>'+
  '<h3>'+t('Connected destinations','Tài khoản phát hành')+'</h3>'+
  (accounts.map(a=>'<div class="vta-social-account"><strong>'+safe(a.name)+'</strong><small>'+safe(a.provider)+' · '+safe(a.alias)+'</small><span class="'+(a.ready?'vta-social-ready':'vta-social-off')+'">'+(a.ready?t('Permissioned and enabled','Đã cấu hình quyền'):t('Unavailable / approval needed','Chưa thể xuất bản / thiếu quyền'))+'</span></div>').join('')||'<p class="mk-note">'+t('No verified Facebook Page configured. Ask the administrator to use private server settings.','Chưa cấu hình Facebook Page được cấp quyền. Quản trị viên cần khai báo trên máy chủ riêng.')+'</p>')+
  '<div class="vta-social-account"><strong>Instagram</strong><span class="vta-social-off">'+t('Not implemented in P5 (requires image container publishing)','Chưa phát triển P5 (cần quy trình đăng ảnh riêng)')+'</span></div>'+
  '<div class="vta-social-account"><strong>WhatsApp</strong><span class="vta-social-off">'+t('Business messaging, not a feed publishing destination','Kênh nhắn tin doanh nghiệp, không phải bảng tin đăng bài')+'</span></div>'+
  '<h3>'+t('Schedule approved content','Lên lịch nội dung đã duyệt')+'</h3>'+
  (can('campaign.manage')?'<form class="vta-social-form" data-social-form="new"><label>'+t('Approved Facebook content','Nội dung Facebook đã duyệt')+'<select name="content_id" required>'+approved.map(x=>'<option value="'+Number(x.id)+'">#'+Number(x.id)+' · '+safe(String(x.body).slice(0,88))+'</option>').join('')+'</select></label>'+
  '<label>'+t('Facebook Page','Trang Facebook')+'<select name="account_alias" required>'+enabled.map(x=>'<option value="'+safe(x.alias)+'">'+safe(x.name)+'</option>').join('')+'</select></label>'+
  '<label>'+t('Vietnam local publish time','Ngày giờ đăng (giờ Việt Nam)')+'<input type="datetime-local" name="scheduled_at" required></label>'+
  '<button type="submit" class="btn primary" '+(busy||!approved.length||!enabled.length?'disabled':'')+'>'+t('Create approval request','Tạo lịch chờ duyệt')+'</button></form>':'')+
  '<h3>'+t('Publish queue','Hàng chờ đăng bài')+'</h3>'+
  (jobs.length?'<div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>'+t('Page / Content','Trang / Nội dung')+'</th><th>'+t('Scheduled UTC','Lịch UTC')+'</th><th>'+t('Status','Trạng thái')+'</th><th></th></tr></thead><tbody>'+jobs.map(j=>'<tr><td>#'+Number(j.id)+'</td><td>'+safe(j.account_alias)+' · #'+Number(j.content_id)+'</td><td>'+safe(j.scheduled_at)+'</td><td>'+safe(statusLabel[j.status]||j.status)+(j.status_detail?'<small> · '+safe(j.status_detail)+'</small>':'')+'</td><td>'+((j.status==='DRAFT'&&canApprove&&String(j.creator)!==String(user?.full_name))?'<button type="button" class="btn" data-social-action="approve" data-id="'+Number(j.id)+'">'+t('Approve','Duyệt')+'</button>':'')+
    (['DRAFT','APPROVED'].includes(j.status)&&can('campaign.manage')?'<button type="button" class="btn" data-social-action="cancel" data-id="'+Number(j.id)+'">'+t('Cancel','Hủy')+'</button>':'')+'</td></tr>').join('')+'</tbody></table></div>':
  '<p class="mk-note">'+t('No post jobs yet.','Chưa có lịch đăng bài nào.')+'</p>')+
  '<p class="mk-note">'+t('Live provider posting requires an explicitly enabled server worker. Scheduled does not mean published. Unknown outcomes require manual verification before another attempt.','Đăng thật chỉ chạy khi worker được bật riêng trên máy chủ. Đã lên lịch không có nghĩa đã đăng. Khi kết quả không rõ phải đối soát thủ công trước khi gửi lại.')+'</p></section>';
 }
 async function refresh(){
  try{const r=await api.request('marketing/social-publishing');accounts=r.accounts||[];jobs=r.jobs||[];}
  catch(e){toast(e.message,true);}
  render();
 }
 host.addEventListener('submit',async e=>{
  const f=e.target.closest('[data-social-form="new"]');if(!f||busy)return;
  e.preventDefault();const v=Object.fromEntries(new FormData(f));
  const local=new Date(v.scheduled_at);
  if(!Number.isFinite(local.getTime())){toast(t('Choose a valid publish date','Chọn ngày đăng hợp lệ'),true);return;}
  busy=true;
  try{
   const out=await api.request('marketing/social-publishing/create',{method:'POST',body:{
    content_id:Number(v.content_id),account_alias:v.account_alias,scheduled_at:local.toISOString().replace(/\.\d{3}Z$/,'Z'),
    request_key:'post_'+crypto.randomUUID().replace(/-/g,'')
   }});
   toast(t('Draft publishing job #','Đã tạo lịch chờ duyệt #')+out.id);
  }catch(err){toast(err.message,true);}finally{busy=false;await refresh();}
 });
 host.addEventListener('click',async e=>{
  const b=e.target.closest('button[data-social-action]');if(!b||busy)return;
  const action=b.dataset.socialAction,id=Number(b.dataset.id);
  if(action==='approve'&&!window.confirm(t('Approve sending this content publicly to the configured Facebook Page?','Duyệt xuất bản công khai nội dung này lên Facebook Page đã chọn?')))return;
  if(action==='cancel'&&!window.confirm(t('Cancel this job?','Hủy lịch đăng này?')))return;
  busy=true;
  try{await api.request('marketing/social-publishing/'+action,{method:'POST',body:{id}});
    toast(action==='approve'?t('Approved for scheduled dispatch','Đã duyệt lịch đăng'):t('Publishing job cancelled','Đã hủy lịch đăng'));}
  catch(err){toast(err.message,true);}finally{busy=false;await refresh();}
 });
 host.innerHTML='<section class="mk-panel"><p class="mk-note">'+t('Loading publishing queue…','Đang tải lịch đăng…')+'</p></section>';
 refresh();
};
})();
(()=>{'use strict';
/** VTA P6 scheduler. Posting is always a guarded server-side CLI action, never a browser call. */
window.VTASocialPublisher=function(host,{api,esc,toast,t,can,preview,contents,user}){
 if(!host||!host.isConnected)return;
 if(preview){
  host.innerHTML='<section class="mk-panel"><h2>'+t('Social Publishing','Đăng bài mạng xã hội')+'</h2><p class="mk-note">'+t('Demo mode. No real accounts or published posts.','Chế độ demo. Không kết nối hoặc đăng bài thật.')+'</p></section>';
  return;
 }
 let accounts=[],jobs=[],busy=false,loading=false,loadError=false,selectedAlias='';
 const safe=x=>esc(String(x??''));
 const eligible=(contents||[]).filter(x=>x.status==='APPROVED'&&x.content_format==='POST'
  &&((x.channel==='Facebook'&&!x.asset_url)
    ||(x.channel==='Instagram'&&/\.jpe?g$/i.test(String(x.asset_url||''))&&String(x.rights_note||'').trim())));
 const statusLabel={DRAFT:t('Awaiting second approval','Chờ người thứ hai duyệt'),APPROVED:t('Scheduled','Đã lên lịch'),SENDING:t('Provider request in progress','Đang xử lý'),PUBLISHED:t('Published · provider confirmed','Đã đăng · API xác nhận'),UNCERTAIN:t('Outcome unknown · reconcile','Chưa rõ · phải đối soát'),BLOCKED:t('Blocked','Đã chặn'),CANCELLED:t('Cancelled','Đã hủy')};
 function render(){
  if(!host.isConnected)return;
  if(loading||loadError){host.innerHTML='<section class="mk-panel"><h2>'+t('Social Publishing','Đăng bài mạng xã hội')+'</h2><p class="mk-note" role="status">'+(loading?t('Loading accounts and publishing queue…','Đang tải tài khoản và hàng chờ đăng…'):t('Could not load publishing status. Try again or contact your administrator.','Không tải được trạng thái đăng bài. Thử lại hoặc liên hệ quản trị viên.'))+'</p>'+(!loading?'<button type="button" class="btn" data-social-action="refresh">'+t('Try again','Thử lại')+'</button>':'')+'</section>';return;}
  const enabled=accounts.filter(a=>a.ready);
  const chosen=enabled.find(a=>a.alias===selectedAlias)||enabled[0]||null;
  const approved=eligible.filter(x=>chosen?.provider==='FACEBOOK_PAGE'
      ?x.channel==='Facebook':chosen?.provider==='INSTAGRAM_BUSINESS'&&x.channel==='Instagram');
  host.innerHTML='<section class="mk-panel"><div class="mk-section-head"><div><h2>'+t('Social Publishing','Đăng bài mạng xã hội')+'</h2>'+
   '<p class="mk-note">'+t('Facebook text and Instagram single-JPEG only. API permissions and tokens are server configured; no login/OAuth flow yet.','Chỉ Facebook bài chữ và Instagram một ảnh JPEG. Quyền API và token cấu hình trên máy chủ; chưa có giao diện OAuth.')+'</p></div><button type="button" class="btn" data-social-action="refresh" '+(busy?'disabled':'')+'>'+t('Refresh status','Làm mới')+'</button></div>'+
   '<h3>'+t('Configured publishing accounts','Tài khoản đăng bài đã cấu hình')+'</h3>'+
   (accounts.map(a=>'<div class="vta-social-account"><strong>'+safe(a.name)+'</strong><small>'+safe(a.provider)+' · '+safe(a.alias)+'</small><span class="'+(a.ready?'vta-social-ready':'vta-social-off')+'">'+
   (a.ready?t('Config ready · API connection not verified','Đủ cấu hình · chưa xác minh API thật'):t('Disabled or missing permissions','Chưa bật hoặc thiếu quyền'))+'</span></div>').join('')||
   '<p class="mk-note">'+t('No Meta publishing account configured yet. Configure an authorized Page token privately on the server.','Chưa cấu hình tài khoản Meta. Cần khai báo token có quyền trên máy chủ riêng.')+'</p>')+
   '<div class="vta-social-account"><strong>WhatsApp</strong><span class="vta-social-off">'+t('Business messaging — not a publishing feed','Nhắn tin doanh nghiệp — không phải kênh đăng bài')+'</span></div>'+
   '<h3>'+t('Schedule approved content','Lên lịch nội dung đã duyệt')+'</h3>'+
   (can('campaign.manage')?'<form class="vta-social-form" data-social-form="create">'+
   '<label>'+t('Publishing account','Tài khoản đăng')+'<select name="account_alias" required>'+enabled.map(a=>'<option value="'+safe(a.alias)+'"'+(chosen?.alias===a.alias?' selected':'')+'>'+safe(a.name)+' · '+safe(a.provider)+'</option>').join('')+'</select></label>'+
   '<label>'+t('Approved channel content','Nội dung đúng kênh đã duyệt')+'<select name="content_id" required>'+approved.map(c=>'<option value="'+Number(c.id)+'">#'+Number(c.id)+' · '+safe(String(c.body).slice(0,85))+'</option>').join('')+'</select></label>'+
   '<label>'+t('Publishing time in Vietnam (UTC+7)','Giờ đăng Việt Nam (UTC+7)')+'<input name="scheduled_at" type="datetime-local" required></label>'+
   '<button type="submit" class="btn primary" '+(busy||!chosen||!approved.length?'disabled':'')+'>'+t('Create post for review','Tạo lịch chờ duyệt')+'</button></form>':'')+
   '<h3>'+t('Publishing queue','Hàng chờ đăng')+'</h3>'+
   (jobs.length?'<div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>'+t('Provider / Content','Kênh / Bài viết')+'</th><th>'+t('UTC schedule','Lịch UTC')+'</th><th>'+t('State','Trạng thái')+'</th><th></th></tr></thead><tbody>'+
   jobs.map(j=>'<tr><td>#'+Number(j.id)+'</td><td>'+safe(j.provider)+' · #'+Number(j.content_id)+'<small> · '+safe(j.account_alias)+'</small></td><td>'+safe(j.scheduled_at)+'</td><td>'+safe(statusLabel[j.status]||j.status)+(j.status_detail?' · '+safe(j.status_detail):'')+'</td><td>'+
    (j.status==='DRAFT'&&can('campaign.manage')&&can('marketing.approve')&&Number(j.created_by)!==Number(user?.id)?'<button type="button" class="btn" data-social-action="approve" data-id="'+Number(j.id)+'">'+t('Approve','Duyệt')+'</button>':'')+
    (can('campaign.manage')&&['DRAFT','APPROVED'].includes(j.status)?'<button type="button" class="btn" data-social-action="cancel" data-id="'+Number(j.id)+'">'+t('Cancel','Hủy')+'</button>':'')+'</td></tr>').join('')+'</tbody></table></div>':
    '<p class="mk-note">'+t('No scheduled posts yet.','Chưa có bài lên lịch.')+'</p>')+
   '<p class="mk-note">'+t('Publishing requires a separate, guarded PHP CLI worker. A scheduled post has NOT been sent. Unknown outcomes are never retried automatically.','Việc đăng thật cần worker PHP được bật riêng. Bài đã lên lịch CHƯA được đăng. Kết quả chưa rõ không tự gửi lại.')+'</p></section>';
 }
 async function refresh(){
  if(loading)return;loading=true;loadError=false;render();
  try{
   const r=await api.request('marketing/social-publishing');
   accounts=r.accounts||[];jobs=r.jobs||[];
   if(!accounts.some(a=>a.alias===selectedAlias&&a.ready))selectedAlias=accounts.find(a=>a.ready)?.alias||'';
  }catch(e){loadError=true;if(host.isConnected)toast(t('Could not load publishing status.','Không tải được trạng thái đăng bài.'),true);}
  finally{loading=false;render();}
 }
 host.addEventListener('change',e=>{
   if(e.target.matches('select[name="account_alias"]')){selectedAlias=e.target.value;render();}
 });
 host.addEventListener('submit',async e=>{
  const form=e.target.closest('[data-social-form="create"]');if(!form)return;
  if(busy||loading||loadError){e.preventDefault();return;}
  e.preventDefault();
  const data=Object.fromEntries(new FormData(form));
  if(!/^\d{4}-\d\d-\d\dT\d\d:\d\d$/.test(data.scheduled_at||'')){toast(t('Choose a valid time','Chọn thời gian hợp lệ'),true);return;}
  const utc=new Date(data.scheduled_at+':00+07:00');
  if(!Number.isFinite(utc.getTime())){toast(t('Invalid date','Ngày không hợp lệ'),true);return;}
  const current=accounts.find(a=>a.alias===data.account_alias&&a.ready);
  const matched=eligible.find(c=>Number(c.id)===Number(data.content_id)&&c.channel===(current?.provider==='FACEBOOK_PAGE'?'Facebook':'Instagram'));
  if(!current||!matched){toast(t('Selected post or account is not eligible','Bài hoặc tài khoản không hợp lệ'),true);return;}
  busy=true;
  try{
   const r=await api.request('marketing/social-publishing/create',{method:'POST',body:{
    content_id:Number(data.content_id),account_alias:data.account_alias,
    scheduled_at:utc.toISOString().replace(/\.\d{3}Z$/,'Z'),
    request_key:'post_'+crypto.randomUUID().replace(/-/g,'')
   }});
   toast(t('Draft publishing job #','Đã tạo lịch chờ duyệt #')+r.id);
  }catch(err){toast(err.message,true);}
  finally{busy=false;await refresh();}
 });
 host.addEventListener('click',async e=>{
  const button=e.target.closest('button[data-social-action]');if(!button||busy||loading)return;
  if(button.dataset.socialAction==='refresh'){await refresh();return;}
  if(loadError)return;
  const action=button.dataset.socialAction,id=Number(button.dataset.id);
  if(!['approve','cancel'].includes(action)||!Number.isSafeInteger(id))return;
  if(!window.confirm(action==='approve'
    ?t('Approve PUBLIC publishing to this Meta account?','Duyệt ĐĂNG CÔNG KHAI lên tài khoản Meta này?')
    :t('Cancel this publishing job?','Hủy lịch đăng này?')))return;
  busy=true;
  try{
   await api.request('marketing/social-publishing/'+action,{method:'POST',body:{id}});
   toast(action==='approve'?t('Scheduled job approved','Đã duyệt lịch đăng'):t('Job cancelled','Đã hủy lịch'));
  }catch(err){toast(err.message,true);}
  finally{busy=false;await refresh();}
 });
 host.innerHTML='<section class="mk-panel">'+t('Loading accounts and publishing queue…','Đang tải tài khoản và hàng chờ đăng…')+'</section>';
 refresh();
};
})();
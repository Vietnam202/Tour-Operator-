(()=>{'use strict';
/** P9 read-only Meta Connection Center; never collects tokens or claims OAuth support. */
window.VTAMetaConnectionCenter=function(host,{api,esc,t,toast,preview}){
 if(!host||!host.isConnected)return;
 if(preview){
  host.innerHTML='<section class="mk-panel"><h2>'+t('Meta Connections','Kết nối Meta')+'</h2><p class="mk-note">'+t('Preview only. Accounts and tokens are not connected.','Chỉ xem thử. Không kết nối tài khoản hay token.')+'</p></section>';
  return;
 }
 let accounts=[],busy=false,loadError=false;
 const safe=x=>esc(String(x??''));
 const title=p=>({MESSENGER:'Facebook Messenger',INSTAGRAM_DM:'Instagram DM',FACEBOOK_PAGE:'Facebook Page',INSTAGRAM_PUBLISH:'Instagram Publishing'}[p]||p);
 const status=s=>({
   VERIFIED:t('Verified via read-only API','Đã kiểm tra API chỉ đọc'),
   NEVER_CHECKED:t('Not yet checked','Chưa kiểm tra'),
   NOT_VERIFIED:t('Check inconclusive','Không xác minh được'),
   MISSING_PERMISSIONS:t('Missing API scopes','Thiếu quyền API'),
   EXPIRED:t('Token expired/near expiry','Token hết hạn/sắp hết hạn'),
   INVALID:t('Invalid token/identity','Token hoặc tài khoản không hợp lệ')
 }[s]||s);
 function render(){
  if(!host.isConnected)return;
  if(busy||loadError){host.innerHTML='<section class="mk-panel"><h2>'+t('Meta Connection Center','Trung tâm kết nối Meta')+'</h2><p class="mk-note" role="status">'+(busy?t('Loading account status…','Đang tải trạng thái tài khoản…'):t('Could not load Meta connection status. Try again or contact your administrator.','Không tải được trạng thái kết nối Meta. Thử lại hoặc liên hệ quản trị viên.'))+'</p>'+(!busy?'<button type="button" class="btn" data-meta-action="refresh">'+t('Try again','Thử lại')+'</button>':'')+'</section>';return;}
  const healthy=accounts.filter(a=>a.recently_verified).length;
  host.innerHTML='<section class="mk-panel vta-meta-connection"><div class="mk-section-head"><div>'+
   '<h2>'+t('Meta Connection Center','Trung tâm kết nối Meta')+'</h2>'+
   '<p class="mk-note">'+t('Safe, read-only status for configured Facebook/Instagram accounts. Tokens are never shown.','Kiểm tra chỉ đọc các tài khoản Facebook/Instagram đã cấu hình. Không hiển thị token.')+'</p></div>'+
   '<button type="button" class="btn" data-meta-action="refresh" '+(busy?'disabled':'')+'>'+t('Refresh status','Làm mới')+'</button></div>'+
   '<div class="vta-meta-summary"><strong>'+healthy+' / '+accounts.length+'</strong> '+
   t('recent API checks passed; this does not prove Meta app review or publishing authorization.','tài khoản có kiểm tra API gần đây đạt; không đồng nghĩa đã duyệt Meta App hoặc cấp quyền đăng/gửi tin.')+'</div>'+
   (accounts.length?'<div class="vta-meta-connections">'+accounts.map(a=>'<article class="vta-meta-connection-card">'+
    '<div class="vta-meta-head"><strong>'+safe(title(a.product))+'</strong><span>'+safe(status(a.health_status))+'</span></div>'+
    '<p>'+safe(a.alias)+' · ID '+safe(a.entity_id)+'</p>'+
    '<p>'+t('Configuration enabled','Cấu hình bật')+': '+(a.enabled?t('Yes','Có'):t('No','Không'))+
    ' · '+t('App review recorded','Đã ghi nhận duyệt app')+': '+(a.app_review_confirmed?t('Yes','Có'):t('No','Không'))+'</p>'+
    '<p>'+t('Last check','Kiểm tra gần nhất')+': '+safe(a.last_checked_at||'—')+
    ' · '+t('Token expiry','Token hết hạn')+': '+safe(a.expires_at||t('Unknown / not provided','Chưa rõ / không có'))+'</p>'+
    (a.error_code?'<p class="vta-meta-warning">'+safe(a.error_code)+'</p>':'')+
    '<p class="mk-note">'+t('Required scopes','Quyền cần có')+': '+safe((a.required_scopes||[]).join(', '))+'</p></article>').join('')+'</div>':
    '<p class="mk-note">'+t('No accounts configured. A qualified administrator must provision Meta App and authorized Page/IG credentials on the private server.','Chưa có tài khoản. Quản trị viên cần thiết lập Meta App và token Facebook/Instagram được cấp phép trên máy chủ riêng.')+'</p>')+
   '<p class="mk-note">'+t('Automatic OAuth setup is not available. Administrators configure authorized accounts privately and verify them before enabling delivery.','Chưa hỗ trợ tự kết nối OAuth. Quản trị viên cấu hình tài khoản được cấp quyền trên máy chủ riêng và xác minh trước khi bật gửi/đăng.')+'</p>'+
   '<p class="mk-note">'+t('A successful account check does not authorize publishing or messaging. Each delivery has separate permission checks and processing.','Kiểm tra tài khoản đạt không đồng nghĩa được phép đăng bài/gửi tin. Mỗi lần phát hành có điều kiện cấp quyền và xử lý riêng.')+'</p></section>';
 }
 async function refresh(){
  if(busy)return;
  busy=true;loadError=false;render();
  try{
   const r=await api.request('marketing/meta-connections');
   accounts=Array.isArray(r.accounts)?r.accounts:[];
  }catch(err){loadError=true;if(host.isConnected)toast(t('Could not load Meta connection status.','Không tải được trạng thái kết nối Meta.'),true);}
  finally{busy=false;render();}
 }
 host.addEventListener('click',e=>{
   if(e.target.closest('[data-meta-action="refresh"]'))refresh();
 });
 refresh();
};
})();
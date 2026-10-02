(()=>{'use strict';
 let installEvent=null,registration=null;
 const text=(en,vi)=>window.VTA_I18N?.language==='vi'?vi:en;
 const showUpdate=()=>{const banner=document.querySelector('#platformUpdate');if(banner)banner.hidden=false;};
 window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installEvent=e;});
 window.addEventListener('appinstalled',()=>{installEvent=null;});
 window.VTAPlatform={
  async install(modal){
   if(installEvent){const e=installEvent;installEvent=null;await e.prompt();return;}
   modal(text('Install VTA OS','Cài VTA OS'),`<div class="platform-install" data-no-translate><p>${text('iPhone / iPad: open this site in Safari, choose Share → Add to Home Screen.','iPhone / iPad: mở trang bằng Safari, chọn Chia sẻ → Thêm vào Màn hình chính.')}</p><p>${text('Android / desktop Chrome or Edge: open the browser menu and choose Install app / Install this site as an app when available.','Android / Chrome hoặc Edge trên máy tính: mở menu trình duyệt, chọn Cài ứng dụng / Cài trang này dưới dạng ứng dụng khi có.')}</p><p>${text('An internet connection is required for business records and AI.','Cần kết nối mạng để làm việc với dữ liệu và AI.')}</p></div>`);
  }
 };
 async function init(){
  if(window.VTA_PREVIEW)return;
  const status=document.createElement('div');status.className='platform-status';status.id='platformOffline';status.setAttribute('role','status');document.body.prepend(status);
  const network=()=>{status.textContent=text('Offline. Reconnect before saving or sending to AI.','Đang mất mạng. Kết nối lại trước khi lưu hoặc gửi AI.');status.hidden=navigator.onLine;};network();window.addEventListener('online',network);window.addEventListener('offline',network);
  const banner=document.createElement('div');banner.className='platform-banner';banner.id='platformUpdate';banner.hidden=true;banner.innerHTML=`<span>${text('Update available. Save your work before reloading.','Có bản cập nhật. Lưu công việc trước khi tải lại.')}</span><button>${text('Reload','Tải lại')}</button>`;document.body.append(banner);
  let requested=false;
  banner.querySelector('button').onclick=()=>{if(!registration?.waiting){location.reload();return;}requested=true;registration.waiting.postMessage({type:'ACTIVATE_UPDATE'});};
  if('serviceWorker' in navigator){
   navigator.serviceWorker.addEventListener('controllerchange',()=>{if(requested)location.reload();else showUpdate();});
   try{registration=await navigator.serviceWorker.register('./service-worker.js',{updateViaCache:'none'});if(registration.waiting)showUpdate();registration.addEventListener('updatefound',()=>{const worker=registration.installing;worker?.addEventListener('statechange',()=>{if(worker.state==='installed'&&navigator.serviceWorker.controller)showUpdate();});});}catch{console.warn('PWA unavailable; web mode remains available.');}
  }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();

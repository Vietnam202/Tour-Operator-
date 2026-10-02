(()=>{
 'use strict';
 const script=document.currentScript;
 const token=script?.dataset.form||'';
 if(!/^[a-f0-9]{64}$/.test(token))return;
 const url=new URL('request.html',script.src),parentQuery=new URLSearchParams(location.search);
 url.searchParams.set('form',token);
 for(const key of ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id']){
   const value=parentQuery.get(key);if(value)url.searchParams.set(key,value.slice(0,1000));
 }
 // Store the landing-page location without forwarding unrelated query parameters or fragments.
 url.searchParams.set('landing_url',location.origin+location.pathname);
 const frame=document.createElement('iframe');frame.src=url.href;frame.title='Travel request';
 frame.style.width='100%';frame.style.height='1150px';frame.style.border='0';frame.loading='lazy';
 script.insertAdjacentElement('afterend',frame);
})();

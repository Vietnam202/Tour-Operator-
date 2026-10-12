(function(){'use strict';document.querySelectorAll('[data-vta-b2c-inquiry]').forEach(form=>{
 let request='',signature='',busy=false;
 const attribution=()=>{const p=new URL(location.href).searchParams,a={landing_url:location.href.slice(0,1000),referrer:document.referrer.slice(0,1000)};for(const key of ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id'])a[key]=(p.get(key)||'').slice(0,1000);return a;};
 form.onsubmit=async e=>{e.preventDefault();if(busy||!form.reportValidity())return;
  const input=Object.fromEntries(new FormData(form));input.total_guests=Number(input.total_guests);input.consent=form.querySelector('[name=consent]').checked;input.attribution=attribution();
  const current=JSON.stringify(input);if(current!==signature||!request){signature=current;request=crypto.randomUUID();}input.request_id=request;
  const button=form.querySelector('button'),status=form.querySelector('[role=status]');busy=true;button.disabled=true;status.textContent='Sending request…';
  try{const response=await fetch(form.dataset.api,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-VTA-B2C-CSRF':form.dataset.csrf},body:JSON.stringify(input)});const result=await response.json();if(!response.ok||result.accepted!==true)throw Error(response.status===404?'This programme is no longer available. Please choose another journey.':'Your request could not be accepted. Please check the details and retry.');status.textContent='Request accepted. Our team will review your dates and send a quotation.';request='';form.reset();}
  catch(error){status.textContent=error.message;}finally{busy=false;button.disabled=false;}
 };
});})();

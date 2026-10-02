'use strict';
const requestForm=document.getElementById('request'), statusLine=document.getElementById('status');
const query=new URLSearchParams(location.search);
let submissionKey=crypto.randomUUID(), previousPayload=null;
requestForm.addEventListener('submit',async event=>{
  event.preventDefault();
  const body=Object.fromEntries(new FormData(requestForm));
  if(!body.email&&!body.phone){statusLine.textContent='Please provide an email or phone number.';return;}
  for(const key of ['total_guests','paying_pax','foc']) body[key]=Number(body[key]);
  for(const key of ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id']) body[key]=query.get(key)||'';
  body.referrer=document.referrer.split('?')[0].split('#')[0];
  body.landing_url=query.get('landing_url')||(location.origin+location.pathname);
  const current=JSON.stringify(body);
  if(previousPayload!==null && previousPayload!==current) submissionKey=crypto.randomUUID();
  previousPayload=current;body.submission_key=submissionKey;
  const button=requestForm.querySelector('button');button.disabled=true;statusLine.textContent='Sending…';
  try {
    const result=await fetch('api/lead-submit.php?form='+encodeURIComponent(query.get('form')||''),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const data=await result.json();if(!result.ok) throw new Error(data.message||'Could not send. Please try again later.');
    statusLine.textContent='Thank you. Your request has been received.';
  }catch(error){statusLine.textContent=error.message;button.disabled=false;}
});

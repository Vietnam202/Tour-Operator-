(()=>{'use strict';
/**
 * Website side widget. Uses only same-origin chat-relay.php; no VTA secrets or cross-site API.
 * Install with script src and same-directory PHP backend after staging QA.
 */
const script=document.currentScript;
const relay=script?.dataset?.relay||'chat-relay.php';
if(!/^([A-Za-z0-9_./-]+\.php)$/.test(relay)||relay.includes('..'))return;
const api=new URL(relay,script.src).href;
const root=document.createElement('section');root.className='vta-chat-widget';root.setAttribute('aria-label','Vietnam Travel Advisor live chat');
const opener=document.createElement('button');opener.type='button';opener.className='vta-chat-launch';opener.textContent='Chat with VTA';opener.setAttribute('aria-expanded','false');
const panel=document.createElement('div');panel.className='vta-chat-panel';panel.hidden=true;
panel.innerHTML='<header><strong>Vietnam Travel Advisor</strong><button type="button" class="vta-chat-close" aria-label="Close chat">×</button></header><p class="vta-chat-notice">Our travel team can reply here. Please avoid sending sensitive documents.</p><div class="vta-chat-history" aria-live="polite"></div><form class="vta-chat-form"><label>Your name (optional)<input name="contact_name" maxlength="190" autocomplete="name"></label><label>Email (optional)<input name="email" type="email" maxlength="190" autocomplete="email"></label><label>Message<textarea name="text" required rows="3" maxlength="8000" placeholder="Where would you like to visit in Vietnam?"></textarea></label><button type="submit">Send message</button></form><p class="vta-chat-status" role="status"></p>';
root.append(opener,panel);document.body.append(root);
const close=panel.querySelector('.vta-chat-close'),history=panel.querySelector('.vta-chat-history'),status=panel.querySelector('.vta-chat-status'),form=panel.querySelector('form'),send=form.querySelector('button[type="submit"]');
let csrf='',opened=false,fetching=false,sending=false,timer=null,pendingId=null,pendingText=null;
function appendMessages(messages){
 history.replaceChildren();
 for(const m of (Array.isArray(messages)?messages:[])){
   if(!m||typeof m.text!=='string')continue;
   const node=document.createElement('div');
   node.className='vta-chat-bubble '+(m.direction==='outgoing'?'vta-chat-mine':'vta-chat-staff');
   node.textContent=m.text;history.append(node);
 }
 history.scrollTop=history.scrollHeight;
}
async function request(action,body=null){
 const options=body===null?{credentials:'same-origin',cache:'no-store'}:{
   method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)
 };
 const res=await fetch(api+'?action='+action,options);
 const data=await res.json();
 if(!res.ok||data.ok!==true)throw new Error(data.error||'Unable to connect');
 return data;
}
async function initialize(){
 if(!csrf){const data=await request('init');csrf=data.csrf;appendMessages(data.messages);}
}
async function poll(){
 if(!opened||fetching||sending||document.hidden)return;
 fetching=true;
 try{const data=await request('poll');appendMessages(data.messages);status.textContent='';}
 catch{status.textContent='Connection unavailable. We will retry.';}
 finally{fetching=false;}
}
function toggle(force){
 opened=typeof force==='boolean'?force:!opened;
 panel.hidden=!opened;opener.setAttribute('aria-expanded',String(opened));
 if(opened){
  initialize().then(poll).catch(()=>{status.textContent='Chat unavailable. Please try again.';});
  if(!timer)timer=setInterval(poll,12000);
 }else if(timer){clearInterval(timer);timer=null;}
}
opener.addEventListener('click',()=>toggle());close.addEventListener('click',()=>toggle(false));
document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
form.addEventListener('submit',async e=>{
 e.preventDefault();if(sending)return;
 const body=Object.fromEntries(new FormData(form)),text=String(body.text||'').trim();
 if(!text)return;
 if(pendingText!==text){pendingId=crypto.randomUUID();pendingText=text;}
 sending=true;send.disabled=true;status.textContent='Sending…';
 const tracking=new URLSearchParams(location.search),attrib={};
 for(const name of ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id']) {
  const val=tracking.get(name);if(val)attrib[name]=val.slice(0,1000);
 }
 attrib.landing_url=(location.origin+location.pathname).slice(0,1000);
 try{
  await initialize();
  const data=await request('send',{...body,csrf,message_id:pendingId,attribution:attrib});
  appendMessages(data.messages);form.elements.text.value='';pendingId=null;pendingText=null;status.textContent='Message received. Our team will respond here.';
  await poll();
 }catch(err){status.textContent='Could not send. Please retry; your message remains here.';}
 finally{sending=false;send.disabled=false;}
});
})();
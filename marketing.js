(()=>{'use strict';
let demo=null;
const seed=()=>({campaigns:[
 {id:1,name:'DEMO · Vietnam Family Discovery',market:'India',budget:0,currency:'USD',offer:'Vietnam 7-day family itinerary — draft concept, dates and rates to confirm',audience:'Indian families and private groups'},
 {id:2,name:'DEMO · Vietnam Couple Escape',market:'India',budget:0,currency:'USD',offer:'Vietnam private couple itinerary — draft concept',audience:'Couples planning a Vietnam holiday'}
],items:[
 {id:1,campaign_id:1,campaign_name:'DEMO · Vietnam Family Discovery',channel:'Facebook',status:'DRAFT',body:'Planning a family trip to Vietnam? Tell Vietnam Travel Advisor your travel dates, group size and interests. We will prepare a tailored itinerary for review. Prices and availability require final confirmation.'},
 {id:2,campaign_id:1,campaign_name:'DEMO · Vietnam Family Discovery',channel:'YouTube Shorts',status:'PENDING',body:'SAMPLE SCRIPT — 30 seconds\n0–5s: Vietnam, together as a family.\n5–20s: Show Hanoi, Ninh Binh and Ha Long Bay using licensed footage.\n20–30s: Ask us for a tailored Vietnam itinerary.\nDraft route only; services and dates need confirmation.'},
 {id:3,campaign_id:2,campaign_name:'DEMO · Vietnam Couple Escape',channel:'Google Ads',status:'APPROVED',body:'SAMPLE COPY — approval demo only\nHeadline: Plan Your Vietnam Escape\nDescription: Share your dates and preferences. Request a private itinerary from Vietnam Travel Advisor. No price or availability confirmed.'}
]});
const previewApi={async request(route,opt={}){
 if(!demo)demo=seed();const b=opt.body||{};
 if(route==='marketing/workspace')return JSON.parse(JSON.stringify(demo));
 if(opt.method!=='POST')throw Error('Unsupported demo action');
 if(route==='marketing/campaigns'){
  if(!String(b.name||'').trim()||!String(b.offer||'').trim())throw Error('Name and offer are required');
  const budget=Number(b.budget);if(!Number.isFinite(budget)||budget<0||!['USD','INR','VND'].includes(b.currency))throw Error('Invalid budget or currency');
  const id=Math.max(0,...demo.campaigns.map(c=>c.id))+1;demo.campaigns.unshift({...b,id,budget});return {ok:true,id};
 }
 if(route==='marketing/content'){
  const campaign=demo.campaigns.find(c=>c.id===Number(b.campaign_id));
  if(!campaign||!String(b.body||'').trim()||!['Facebook','Instagram','Google Ads','TikTok','YouTube Shorts','WhatsApp','Gmail'].includes(b.channel))throw Error('Invalid content draft');
  const id=Math.max(0,...demo.items.map(x=>x.id))+1;demo.items.unshift({id,campaign_id:campaign.id,campaign_name:campaign.name,channel:b.channel,body:b.body,status:'DRAFT'});return {ok:true,id};
 }
 const item=demo.items.find(x=>x.id===Number(b.id));if(!item)throw Error('Demo content not found');
 if(route==='marketing/submit'&&item.status==='DRAFT'){item.status='PENDING';return {ok:true};}
 if(route==='marketing/decision'&&item.status==='PENDING'&&['APPROVED','REJECTED'].includes(b.decision)){item.status=b.decision;return {ok:true};}
 throw Error('Action unavailable for this demo content status');
}};
/* CampaignPilot is a native workspace: no iframe, separate login or customer copy. */
window.VTAMarketing=async function({api,esc,modal,closeModal,toast,navigate,can}){
 const vi=window.VTA_I18N?.language==='vi',t=(en,vn)=>vi?vn:en;
 const root=document.querySelector('#workspace');
 const preview=!!window.VTA_PREVIEW;if(preview)api=previewApi;
 const data=await api.request('marketing/workspace');
 const manage=can('campaign.manage'),approve=can('marketing.approve');
 root.innerHTML=`<div class="page">${preview?`<section class="panel" data-no-translate style="border:2px solid #168aad"><div class="panel-body"><div class="actions" style="justify-content:space-between"><strong>DEMO · MARKETING PREVIEW 1</strong><button class="btn" id="marketingReset">${t('Reset demo','Đặt lại demo')}</button></div><p>${t('Try creating a campaign, composing copy and reviewing drafts. Sample data stays in this tab only and resets on reload. No server, AI or publishing calls are made. Demo campaigns are not synced to Lead Hub.','Anh có thể tạo chiến dịch, soạn nội dung và thử duyệt. Dữ liệu mẫu chỉ giữ trong tab này, tải lại trang sẽ đặt lại. Không gọi máy chủ, AI hoặc đăng bài. Chiến dịch demo không đồng bộ sang Lead Hub.')}</p></div></section>`:''}<div class="page-head"><div><h1>MARKETING · CampaignPilot</h1><p>${t('One campaign identity from content to lead, inquiry and booking.','Một mã chiến dịch xuyên suốt nội dung, lead, inquiry và booking.')}</p></div><div class="actions"><button class="btn" id="marketingLeads">Lead Hub →</button>${manage?`<button class="btn primary" id="marketingNew">+ ${t('Campaign','Chiến dịch')}</button>`:''}</div></div><section class="panel"><div class="panel-body"><strong>${t('Prepare → Review → Hold for release','Chuẩn bị → Phê duyệt → Chờ phát hành')}</strong><p>${t('Approval records your decision only. Composio publishing is not connected in this build. No ads, posts or messages are sent.','Duyệt chỉ ghi nhận quyết định. Bản này chưa kết nối phát hành qua Composio; chưa gửi bài, quảng cáo hoặc tin nhắn.')}</p></div></section><div class="grid2"><section class="panel"><div class="panel-head"><h2>${t('Campaigns','Chiến dịch')}</h2></div><div class="panel-body">${data.campaigns.map(c=>`<div class="attention"><div style="flex:1"><strong>${esc(c.name)}</strong><p>${esc(c.market||'')} · ${esc(c.budget||0)} ${esc(c.currency||'')}</p><p>${esc(c.offer||'')}</p></div>${manage?`<button class="btn" data-compose="${c.id}">${t('Compose','Soạn nội dung')}</button>`:''}</div>`).join('')||t('Create your first campaign.','Tạo chiến dịch đầu tiên.')}</div></section><section class="panel"><div class="panel-head"><h2>${t('Content & approvals','Nội dung & phê duyệt')}</h2></div><div class="panel-body">${data.items.map(x=>`<article class="attention"><div style="flex:1"><strong>${esc(x.channel)} · ${esc(x.campaign_name)}</strong><p><span class="badge">${esc(x.status)}</span></p><p style="white-space:pre-wrap">${esc(x.body)}</p><div class="actions"><button class="btn" data-copy="${x.id}">${t('Copy','Sao chép')}</button>${manage?`<button class="btn" data-revise="${x.id}">${t('New revision','Tạo bản sửa')}</button>`:''}${x.status==='DRAFT'&&manage?`<button class="btn primary" data-submit="${x.id}">${t('Submit for review','Trình duyệt')}</button>`:''}${x.status==='PENDING'&&approve?`<button class="btn primary" data-approve="${x.id}">${t('Approve','Duyệt')}</button><button class="btn" data-reject="${x.id}">${t('Reject','Từ chối')}</button>`:''}</div></div></article>`).join('')||t('No content yet.','Chưa có nội dung.')}</div></section></div></div>`;
 if(preview)root.querySelector('#marketingReset').onclick=async()=>{demo=seed();await navigate('marketing');};
 root.querySelector('#marketingLeads').onclick=()=>navigate('leads');
 const perform=async(route,body)=>{try{await api.request(route,{method:'POST',body});closeModal();await navigate('marketing');}catch(e){toast(e.message,true);}};
 const field=(label,name,value='',area=false)=>`<label>${label}${area?`<textarea name="${name}" required maxlength="8000">${esc(value)}</textarea>`:`<input name="${name}" value="${esc(value)}" required maxlength="190">`}</label>`;
 if(manage)root.querySelector('#marketingNew').onclick=()=>{
  const m=modal(t('New campaign','Chiến dịch mới'),`<form id="marketingForm">${field(t('Name','Tên'),'name')}${field(t('Market','Thị trường'),'market','India')}${field(t('Tour / offer','Tour / ưu đãi'),'offer','',true)}${field(t('Audience','Khách mục tiêu'),'audience','Indian families and private groups',true)}<label>${t('Planned budget','Ngân sách dự kiến')}<input name="budget" type="number" min="0" max="999999999999" step="0.01" value="0" required></label><label>${t('Currency','Tiền tệ')}<select name="currency"><option>INR</option><option>USD</option><option>VND</option></select></label><button class="btn primary">${t('Save','Lưu')}</button></form>`);
  m.querySelector('form').onsubmit=e=>{e.preventDefault();return perform('marketing/campaigns',Object.fromEntries(new FormData(e.target)));};
 };
 function compose(id,previous){
  const c=data.campaigns.find(c=>Number(c.id)===Number(id));
  const m=modal(t('Content draft','Soạn nội dung'),`<form><label>${t('Channel','Kênh')}<select name="channel">${['Facebook','Instagram','Google Ads','TikTok','YouTube Shorts','WhatsApp','Gmail'].map(v=>`<option ${v===previous?.channel?'selected':''}>${v}</option>`).join('')}</select></label><p>${t('Template-based drafting; review all claims, dates and prices.','Soạn theo mẫu; kiểm tra các thông tin, ngày và giá trước khi duyệt.')}</p>${field(t('Content','Nội dung'),'body',previous?.body||`${c.offer||c.name}\n\nPlan your Vietnam journey with Vietnam Travel Advisor.\nContact us for a tailored itinerary.\nAvailability and prices are subject to final confirmation.`,true)}<button class="btn primary">${t('Save draft','Lưu bản nháp')}</button></form>`);
  m.querySelector('form').onsubmit=e=>{e.preventDefault();return perform('marketing/content',{...Object.fromEntries(new FormData(e.target)),campaign_id:Number(id)});};
 }
 root.querySelectorAll('[data-compose]').forEach(b=>b.onclick=()=>compose(b.dataset.compose));
 root.querySelectorAll('[data-revise]').forEach(b=>b.onclick=()=>{const item=data.items.find(x=>Number(x.id)===Number(b.dataset.revise));compose(item.campaign_id,item);});
 root.querySelectorAll('[data-copy]').forEach(b=>b.onclick=async()=>{try{await navigator.clipboard.writeText(data.items.find(x=>Number(x.id)===Number(b.dataset.copy)).body);toast(t('Copied','Đã sao chép'));}catch{toast(t('Copy failed','Không sao chép được'),true);}});
 root.querySelectorAll('[data-submit]').forEach(b=>b.onclick=()=>perform('marketing/submit',{id:Number(b.dataset.submit)}));
 root.querySelectorAll('[data-approve]').forEach(b=>b.onclick=()=>perform('marketing/decision',{id:Number(b.dataset.approve),decision:'APPROVED'}));
 root.querySelectorAll('[data-reject]').forEach(b=>b.onclick=()=>perform('marketing/decision',{id:Number(b.dataset.reject),decision:'REJECTED'}));
};

})();

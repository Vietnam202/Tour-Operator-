(()=>{'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 window.mountQuoteConfirmation=async function(host,{api,quoteData,can,navigate,toast,refresh,standalone=false,isCurrent=()=>true}){
  const v=quoteData.version,q=quoteData.quote;if(!can('quote.confirm')&&!can('booking.manage'))return;
  const data=await api.request('quote-versions/'+v.id+'/confirmation');if(!host.isConnected||!isCurrent())return;
  const sent=data.version_status==='SENT',confirmed=data.version_status==='CONFIRMED';
  const root=document.createElement('section');root.className='panel';root.dataset.quoteConfirmation='';
  const choices=data.choices||[];
  root.innerHTML='<div class="panel-head"><h2>Quotation · '+esc(data.version_status)+'</h2></div><div class="panel-body"><p>'+esc(data.quote_ref)+' V'+data.version_no+' · '+esc(data.tour_name)+'</p>'+
   (sent&&can('quote.confirm')&&choices.length?'<label>Customer-confirmed option<select data-confirm-choice>'+choices.map((o,i)=>'<option value="'+i+'">'+esc(o.label||('Hotel '+o.hotel_level+' / Cruise '+(o.cruise_level||'—')))+' · '+esc(o.currency)+' '+esc(o.selling_per_pax)+' / pax'+(o.pax_min?' · '+o.pax_min+'–'+o.pax_max+' paying pax':'')+'</option>').join('')+'</select></label>':'')+
   (sent&&choices.some(o=>o.cell_id)?'<label>Confirmed paying pax<input data-confirm-pax type="number" min="1" max="10000" value="'+data.paying_pax+'"></label>':'')+
   '<div class="actions">'+(sent&&can('quote.confirm')?'<button class="btn primary" data-confirm-quote>Confirm quotation</button>':'')+
   (confirmed&&can('booking.manage')?'<button class="btn primary" data-convert-booking>'+(data.booking?'Open Booking':'Create Booking')+'</button>':'')+
   (data.booking&&can('booking.view')&&!can('booking.manage')?'<button class="btn" data-open-existing>Open Booking</button>':'')+
   (standalone?'<button class="btn" data-quote-preview>Preview quotation</button><button class="btn" data-queue-back>Back</button>':'')+'</div><p role="alert"></p></div>';
  if(standalone){host.replaceChildren(root);root.querySelector('.panel-body').insertAdjacentHTML('beforeend',data.schedule.map(d=>'<details><summary>Day '+esc(d.day)+' · '+esc(d.date)+' · '+esc(d.title)+'</summary><p class="codeish">'+esc(d.description)+'</p></details>').join(''));}else host.prepend(root);
  let busy=false;async function run(fn){if(busy)return;busy=true;const buttons=[...root.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);try{await fn();}catch(e){root.querySelector('[role=alert]').textContent=e.message;toast(e.message,true);}finally{busy=false;buttons.filter(b=>b.isConnected).forEach(b=>b.disabled=false);}}
  root.querySelector('[data-confirm-quote]')?.addEventListener('click',()=>run(async()=>{
   const option=choices[Number(root.querySelector('[data-confirm-choice]')?.value||0)];
   if(choices.length&&!option)throw Error('Choose the customer-confirmed option.');
   const body={expected_revision:data.costing_revision};if(option){body.option_id=Number(option.option_id??option.id);body.variant_id=Number(option.variant_id||0);body.sent_content_hash=data.sent_content_hash;}
   if(option?.cell_id){body.cell_id=Number(option.cell_id);body.paying_pax=Number(root.querySelector('[data-confirm-pax]').value);if(!Number.isInteger(body.paying_pax)||body.paying_pax<option.pax_min||body.paying_pax>option.pax_max)throw Error('Confirmed pax must be within the selected offered band.');}
   await api.request('quotes/'+q.id+'/confirm',{method:'POST',body});toast('Quotation confirmed.');await refresh();
  }));
  root.querySelector('[data-convert-booking]')?.addEventListener('click',()=>run(async()=>{const b=data.booking||await api.request('quotes/'+q.id+'/create-booking',{method:'POST',body:{expected_revision:data.costing_revision}});await navigate('booking',{bookingId:Number(b.id)});}));
  root.querySelector('[data-open-existing]')?.addEventListener('click',()=>navigate('booking',{bookingId:Number(data.booking.id)}));
  root.querySelector('[data-queue-back]')?.addEventListener('click',()=>navigate('quote-confirmations'));
  root.querySelector('[data-quote-preview]')?.addEventListener('click',()=>window.open('api/index.php?route=quote-versions/'+v.id+'/proposal/html','_blank','noopener'));
 };
 window.mountQuoteConfirmationQueue=async function(host,{api,navigate}){
  const data=await api.request('quote-confirmations');host.innerHTML='<div class="page"><h1>Quotation confirmation</h1><div class="panel"><div class="panel-body">'+data.items.map(q=>'<article class="ops-row"><div><strong>'+esc(q.quote_ref)+' · '+esc(q.tour_name)+'</strong><p>'+esc(q.start_date)+' → '+esc(q.end_date)+' · '+q.total_guests+' guests · '+esc(q.version_status)+'</p></div><button class="btn" data-open-quote="'+q.id+'">Open quotation</button></article>').join('')+(data.items.length?'':'<p>No sent quotations awaiting confirmation.</p>')+'</div></div></div>';
  host.querySelectorAll('[data-open-quote]').forEach(b=>b.onclick=()=>navigate('quote',{quoteId:Number(b.dataset.openQuote)}));
 };
})();

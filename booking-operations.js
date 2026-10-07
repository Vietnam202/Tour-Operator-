(()=>{'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const label=s=>({PLANNED:'Pending',NOT_REQUESTED:'Pending',REQUESTED:'Requested',CONFIRMED:'Confirmed',CANCELLED:'Cancelled'}[s]||s);
 const groups=[['HOTEL','Hotel'],['TRANSPORT','Transport'],['GUIDE','Guide'],['MEAL','Restaurant / Meals'],['CRUISE','Cruise'],['ATTRACTION','Attractions'],['TOUR','Tours'],['FLIGHT_SERVICE','Flights / Trains'],['VISA','Visa'],['OTHER','Other services']];
 const input=(key,title,value,type='text')=>'<div class="field"><label>'+esc(title)+'<input name="'+key+'" type="'+type+'" value="'+esc(value)+'"'+(type==='number'?' step="0.01" min="0" max="10000"':'')+'></label></div>';
 const textarea=(key,title,value)=>'<div class="field span12"><label>'+esc(title)+'<textarea name="'+key+'">'+esc(value)+'</textarea></label></div>';
 window.editOperationalService=async function(id,{api,can,modal,closeModal,toast,refresh}){
  const data=await api.request('services/'+id+'/operations'),s=data.service,d=data.details,op=data.operation;
  const suppliers=can('supplier.view')?(await api.request('suppliers')).items:[];
  const list=[...(suppliers||[])];if(s.supplier_id&&!list.some(p=>Number(p.id)===Number(s.supplier_id)))list.push({id:s.supplier_id,name:s.supplier_name||'Current supplier'});
  const statuses=[...new Set([s.booking_status,'PLANNED','REQUESTED','CONFIRMED','CANCELLED'])];
  let fields=input('service_name','Service / hotel / restaurant',s.service_name)+input('service_date','Service date',s.service_date,'date')+
   '<div class="field"><label>Supplier<select name="supplier_id"><option value="">Unassigned</option>'+list.map(p=>'<option value="'+p.id+'"'+(Number(p.id)===Number(s.supplier_id)?' selected':'')+'>'+esc(p.name)+'</option>').join('')+'</select></label></div>'+
   '<div class="field"><label>Status<select name="booking_status">'+statuses.map(x=>'<option'+(x===s.booking_status?' selected':'')+' value="'+x+'">'+esc(label(x))+'</option>').join('')+'</select></label></div>'+input('confirmation_no','Confirmation / booking reference',s.confirmation_no)+input('pax','Service pax',s.pax,'number')+input('qty','Quantity',s.qty,'number');
  if(s.category==='HOTEL')fields+=input('check_in','Check-in',d.check_in,'date')+input('check_out','Check-out',d.check_out,'date')+input('room_type','Room / category',d.room_type)+input('address','Hotel address',d.address);
  if(s.category==='TRANSPORT'||s.category==='FLIGHT_SERVICE')fields+=input('start_time','Start / pickup time',s.start_time,'time')+input('end_time','End time',s.end_time,'time')+input('pickup_location','From / pickup',s.pickup_location)+input('dropoff_location','To / drop-off',s.dropoff_location)+input('vehicle_type','Vehicle / flight / train',s.vehicle_type)+input('vehicle_plate','Vehicle / service reference',s.vehicle_plate)+input('driver_name','Driver',s.driver_name)+input('driver_mobile','Driver contact',s.driver_mobile);
  if(s.category==='GUIDE')fields+=input('language','Guide language',op.language)+input('guide_name','Guide',s.guide_name)+input('guide_mobile','Guide contact',s.guide_mobile)+input('start_time','Start time',s.start_time,'time');
  if(s.category==='MEAL')fields+=input('meal_plan','Meal / menu',d.meal_plan)+input('start_time','Meal time',s.start_time,'time');
  if(s.category==='CRUISE')fields+=input('cabin_type','Cabin / category',d.cabin_type)+input('meeting_point','Meeting point',d.meeting_point);
  fields+=textarea('instructions','Operations notes',op.instructions)+textarea('guest_instructions','Guest instructions for vouchers',d.guest_instructions);
  const m=modal('Edit service · '+s.service_name,'<form data-operation-edit><div class="form-grid">'+fields+'</div><p role="alert"></p><button class="btn primary" type="submit">Save</button></form>','',true),form=m.querySelector('form');let busy=false;
  form.onsubmit=async e=>{e.preventDefault();if(busy)return;busy=true;const button=form.querySelector('button[type=submit]');button.disabled=true;
   try{const values=Object.fromEntries(new FormData(form)),body={expected_service_hash:data.service_hash,details:{},operation:{...op}};
    for(const [key,value] of Object.entries(values)){if(['check_in','check_out','room_type','address','meal_plan','cabin_type','meeting_point','guest_instructions'].includes(key))body.details[key]=value;else if(['language','instructions'].includes(key))body.operation[key]=value;else body[key]=value;}
    body.supplier_id=body.supplier_id?Number(body.supplier_id):null;
    await api.request('services/'+id+'/operations',{method:'PUT',body});closeModal();toast('Service saved.');await refresh();
   }catch(error){form.querySelector('[role=alert]').textContent=error.message;button.disabled=false;}finally{busy=false;}
  };
 };
 window.mountBookingOperations=function(host,{data,api,can,modal,closeModal,toast,refresh,openVoucher,addService}){
  const b=data.booking,c=data.commercial,readonly=!!b.finance_closed_at||['COMPLETED','CANCELLED'].includes(b.operations_status),items=data.services||[];
  host.innerHTML='<div class="toolbar">'+(can('service.manage')&&!readonly?'<button class="btn" data-add-operation>+ Service</button>':'')+'</div>'+(c?'<section class="panel"><div class="panel-body"><strong>'+esc(c.tour_name)+'</strong><p>'+esc(c.customer_name)+(c.agency_name?' · '+esc(c.agency_name):'')+' · '+esc(b.start_date)+' → '+esc(b.end_date)+' · '+b.total_guests+' guests</p><details><summary>Confirmed itinerary</summary>'+c.schedule.map(day=>'<h3>Day '+esc(day.day)+' · '+esc(day.title)+'</h3><p class="codeish">'+esc(day.description)+'</p>').join('')+'</details></div></section>':'')+
   groups.map(([category,title])=>{const services=items.filter(s=>s.category===category);return services.length?'<section class="panel" data-service-group="'+category+'"><div class="panel-head"><h2>'+title+'</h2></div><div class="panel-body">'+services.map(s=>'<article class="ops-row"><div><strong>'+esc(s.service_name)+'</strong><p>'+esc(s.service_date)+' · '+esc(s.start_time||'')+' · '+esc(s.supplier_name||'')+'</p><p>'+esc(label(s.booking_status))+' · '+esc(s.confirmation_no||'')+' · '+esc(s.pax)+' pax</p></div><div class="actions">'+(can('service.manage')&&!readonly?'<button class="btn" data-edit-operation="'+s.id+'">Edit</button>':'')+'<button class="btn" data-operation-voucher="'+s.id+'">Voucher</button></div></article>').join('')+'</div></section>':'';}).join('')+(items.length?'':'<p>No services defined for this booking.</p>');
  host.querySelectorAll('[data-edit-operation]').forEach(button=>button.onclick=async()=>{try{await window.editOperationalService(Number(button.dataset.editOperation),{api,can,modal,closeModal,toast,refresh});}catch(e){toast(e.message,true);}});
  host.querySelectorAll('[data-operation-voucher]').forEach(button=>button.onclick=()=>openVoucher?.(items.find(s=>Number(s.id)===Number(button.dataset.operationVoucher))));
  host.querySelector('[data-add-operation]')?.addEventListener('click',()=>addService?.());
 };
})();

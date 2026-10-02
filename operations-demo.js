/* Operations preview contract. All changes live only in this page's memory. */
(()=>{'use strict';
  const states=new WeakMap();
  const array=value=>Array.isArray(value)?value:[];
  const sameId=(a,b)=>Number(a)===Number(b);
  const today=()=>new Date(Date.now()+7*60*60*1000).toISOString().slice(0,10);
  const localNow=()=>new Date(Date.now()+7*60*60*1000).toISOString().slice(0,19).replace('T',' ');
  const copy=value=>JSON.parse(JSON.stringify(value));
  const byteLength=value=>{let bytes=0;for(const char of value){const point=char.codePointAt(0);bytes+=point<128?1:point<2048?2:point<65536?3:4}return bytes};
  const fail=(status,error,message)=>{throw Object.assign(new Error(message),{status,data:{ok:false,error,message}})};
  const requiredText=(value,max=190)=>{
    if(typeof value!=='string'||!value.trim()||byteLength(value)>max)fail(422,'VALIDATION','Required text missing or too long');
    return value.trim();
  };
  function cents(value){
    if(!['string','number'].includes(typeof value)||!/^\d{1,12}(?:\.\d{1,2})?$/.test(String(value)))fail(422,'VALIDATION','Amount must be nonnegative with at most two decimal places');
    const [whole,fraction='']=String(value).split('.');return Number(whole)*100+Number(fraction.padEnd(2,'0'));
  }
  const travelFields={address:500,room_type:190,rooming:2000,check_in:10,check_out:10,meal_plan:190,meeting_point:500,emergency_phone:64,cabin_type:190,inclusions:4000,guest_instructions:4000};
  function cleanTravelDetails(body){
    const out={};
    Object.entries(travelFields).forEach(([field,max])=>{const value=body[field]??'';if(typeof value!=='string'||byteLength(value)>max)fail(422,'VALIDATION','Invalid '+field);out[field]=value.trim()});
    ['check_in','check_out'].forEach(field=>{if(out[field])date(out[field])});
    if(out.check_in&&out.check_out&&out.check_out<=out.check_in)fail(422,'VALIDATION','Check-out must follow check-in');return out;
  }
  function date(value){
    if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value))fail(422,'VALIDATION','Invalid date');
    const parsed=new Date(value+'T00:00:00Z');
    if(!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,10)!==value)fail(422,'VALIDATION','Invalid date');
    return value;
  }
  function time(value){
    if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value))fail(422,'VALIDATION','Use local time YYYY-MM-DD HH:MM:SS');
    date(value.slice(0,10));
    const parsed=new Date(value.replace(' ','T')+'Z');
    if(!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,19).replace('T',' ')!==value)fail(422,'VALIDATION','Use local time YYYY-MM-DD HH:MM:SS');
    return value;
  }
  function addDays(value,days){const d=new Date(value+'T00:00:00Z');d.setUTCDate(d.getUTCDate()+days);return d.toISOString().slice(0,10)}
  function init(api){
    if(states.has(api))return states.get(api);
    const resources=[
      {id:1,kind:'GUIDE',name:'[Preview] English-speaking guide',phone:'',capacity:null,status:'ACTIVE'},
      {id:2,kind:'DRIVER',name:'[Preview] Hanoi driver',phone:'',capacity:null,status:'ACTIVE'},
      {id:3,kind:'VEHICLE',name:'[Preview] 16-seat van',phone:'',capacity:16,status:'ACTIVE'}
    ];
    const booking=array(api.bookings)[0],issues=[];
    if(booking)issues.push({id:1,booking_id:booking.id,category:'TRANSPORT',severity:'HIGH',title:'[Preview] Confirm airport pickup meeting point',description:'Sample incident for preview. Confirm the meeting point with the transport supplier before arrival.',status:'OPEN',owner_user_id:api.user?.id||1,owner_name:api.user?.full_name||'Preview administrator',created_by:api.user?.id||1,created_at:localNow(),root_cause:null,resolution:null,lessons_learned:null,financial_impact:0,currency:'USD'});
    const state={resources,assignments:[],issues,travelDetails:new Map(),paymentKeys:new Map()};states.set(api,state);return state;
  }
  function nextId(items){return items.reduce((max,item)=>Math.max(max,Number(item.id)||0),0)+1}
  function bookingFor(api,id){const booking=array(api.bookings).find(b=>sameId(b.id,id));if(!booking)fail(404,'NOT_FOUND','Booking not found');return booking}
  function serviceFor(api,id){const service=array(api.services).find(s=>sameId(s.id,id));if(!service)fail(404,'NOT_FOUND','Service not found');bookingFor(api,service.booking_id);return service}
  function assignedFor(state,service){return state.assignments.filter(a=>sameId(a.service_id,service.id)&&a.status==='ASSIGNED'&&a.starts_at.slice(0,10)===service.service_date).map(a=>{const resource=state.resources.find(r=>sameId(r.id,a.resource_id)&&r.status==='ACTIVE');return resource?{id:a.id,kind:resource.kind,name:resource.name,capacity:resource.capacity,starts_at:a.starts_at,ends_at:a.ends_at}:null}).filter(Boolean)}
  function readiness(api,state,id){
    const b=bookingFor(api,id),services=array(api.services).filter(s=>sameId(s.booking_id,id)&&s.booking_status!=='CANCELLED');
    const details=services.map(s=>{
      const assigned=assignedFor(state,s),counts={};assigned.forEach(a=>{counts[a.kind]=(counts[a.kind]||0)+1});
      const capacity=assigned.filter(a=>a.kind==='VEHICLE').reduce((total,a)=>total+(Number(a.capacity)||0),0);
      let resourcesReady=true;
      if(s.category==='TRANSPORT')resourcesReady=(counts.DRIVER||0)>=Math.max(1,counts.VEHICLE||0)&&capacity>=Number(b.total_guests||0);
      if(s.category==='GUIDE')resourcesReady=(counts.GUIDE||0)>0;
      return {service_id:s.id,service_name:s.service_name,confirmed:['CONFIRMED','COMPLETED'].includes(s.booking_status),resources_ready:resourcesReady,assigned};
    });
    const guests=array(api.guests).filter(g=>sameId(g.booking_id,id)).length;
    const issueCount=state.issues.filter(i=>sameId(i.booking_id,id)&&['HIGH','CRITICAL'].includes(i.severity)&&['OPEN','INVESTIGATING'].includes(i.status)).length;
    // Preview cannot issue or verify the content hash of a current travel pack.
    const checks={supplier_confirmation:details.length>0&&details.every(s=>s.confirmed),resource_assignment:details.length>0&&details.every(s=>s.resources_ready),guest_list:Number(b.total_guests)>0&&guests>=Number(b.total_guests),no_critical_issues:issueCount===0,current_travel_pack:false};
    const pct=Math.round(Object.values(checks).filter(Boolean).length/5*100);
    return {booking_id:b.id,booking_ref:b.booking_ref,readiness_pct:pct,ready:pct===100,checks,services:details,open_high_critical_issues:issueCount,guest_count:guests};
  }
  function updateContact(api,state,assignment){
    const service=serviceFor(api,assignment.service_id),resource=state.resources.find(r=>sameId(r.id,assignment.resource_id));
    if(!resource)return;
    const fields=resource.kind==='GUIDE'?['guide_name','guide_mobile']:resource.kind==='DRIVER'?['driver_name','driver_mobile']:['vehicle_type',null];
    if(assignment.status==='ASSIGNED'){
      if(service[fields[0]]==null)service[fields[0]]=resource.name;
      if(fields[1]&&service[fields[1]]==null)service[fields[1]]=resource.phone||null;
    }else if(service[fields[0]]===resource.name){
      const next=state.assignments.filter(a=>sameId(a.service_id,service.id)&&a.status==='ASSIGNED').map(a=>state.resources.find(r=>sameId(r.id,a.resource_id)&&r.kind===resource.kind&&r.status==='ACTIVE')).find(Boolean);
      service[fields[0]]=next?.name||null;if(fields[1])service[fields[1]]=next?.phone||null;
    }
  }
  function handle(api,route,opt={}){
    if(!api||typeof route!=='string')return undefined;
    const split=route.search(/[?&]/),path=(split<0?route:route.slice(0,split)).replace(/^\/+|\/+$/g,''),query=new URLSearchParams(split<0?'':route.slice(split+1));
    const method=String(opt.method||'GET').toUpperCase(),body=opt.body||{};let match;
    const permission=p=>api.user?.role_code==='ADMIN'||array(api.user?.permissions).includes(p);
    if(method==='GET'&&path==='v3/control-center'){const state=init(api);return {ok:true,tasks:permission('task.view')?copy(array(api.tasks).filter(t=>t.status!=='DONE'&&sameId(t.owner_user_id,api.user?.id))):[],quote_reviews:[],supplier_reviews:[],alerts:permission('operations.view')?copy(state.issues.filter(i=>['OPEN','INVESTIGATING'].includes(i.status))):[],preview_only:true};}
    if(method==='POST'&&path==='tasks'){if(!permission('task.view'))fail(403,'FORBIDDEN','Task access required');const title=requiredText(body.title),priority=body.priority||'NORMAL';if(!['LOW','NORMAL','HIGH','CRITICAL'].includes(priority))fail(422,'VALIDATION','Invalid task priority');const due=body.due_at||null;if(due&&!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(due))fail(422,'VALIDATION','Invalid task deadline');const id=Math.max(0,...array(api.tasks).map(t=>Number(t.id)))+1;const task={id,title,priority,due_at:due,status:'OPEN',owner_user_id:api.user?.id,owner_name:api.user?.full_name||'',entity_type:'manual',entity_id:0};api.tasks||=[];api.tasks.push(task);return {ok:true,id,preview_only:true};}
    const supported=(method==='GET'&&['v3/operations','v3/resources','operations/departures','operations/timeline','finance/ap'].includes(path))||(method==='POST'&&(path==='v3/resources'||/^services\/\d+\/assign$/.test(path)||/^(?:resource-assignments|assignments)\/\d+\/cancel$/.test(path)||/^bookings\/\d+\/issues$/.test(path)||/^issues\/\d+\/resolve$/.test(path)||/^payables\/\d+\/payments$/.test(path)))||(method==='GET'&&/^bookings\/\d+\/(?:readiness|issues)$/.test(path))||(['GET','PUT'].includes(method)&&/^services\/\d+\/travel-details$/.test(path));
    if(!supported)return undefined;
    const state=init(api);
    if(method==='GET'&&path==='v3/resources')return {ok:true,items:copy([...state.resources].sort((a,b)=>a.kind.localeCompare(b.kind)||a.name.localeCompare(b.name)))};
    if(method==='POST'&&path==='v3/resources'){
      const kind=body.kind;if(!['GUIDE','DRIVER','VEHICLE'].includes(kind))fail(422,'VALIDATION','Invalid resource type');
      const name=requiredText(body.name),phone=body.phone==null?'':String(body.phone);if(byteLength(phone)>64)fail(422,'VALIDATION','Phone too long');
      const capacity=kind==='VEHICLE'?Number(body.capacity):null;if(kind==='VEHICLE'&&(!Number.isInteger(capacity)||capacity<1||capacity>1000))fail(422,'VALIDATION','Vehicle capacity required');
      const id=nextId(state.resources);state.resources.push({id,kind,name,phone:phone||null,capacity,status:'ACTIVE'});return {ok:true,id,preview_only:true};
    }
    if(method==='GET'&&path==='v3/operations'){
      const selected=date(query.has('date')?query.get('date'):today()),movement=[];
      array(api.services).filter(s=>s.service_date===selected&&s.booking_status!=='CANCELLED').forEach(s=>{
        const b=array(api.bookings).find(b=>sameId(b.id,s.booking_id));if(!b)return;
        movement.push({id:s.id,booking_id:s.booking_id,booking_ref:b.booking_ref,lead_guest_name:b.lead_guest_name||'',category:s.category,service_name:s.service_name,service_date:s.service_date,start_time:s.start_time||null,end_time:s.end_time||null,booking_status:s.booking_status,pickup_location:s.pickup_location||null,dropoff_location:s.dropoff_location||null});
      });
      movement.sort((a,b)=>String(a.start_time||'').localeCompare(String(b.start_time||''))||Number(a.id)-Number(b.id));
      const ids=[...new Set(movement.map(s=>s.booking_id))],severities=['CRITICAL','HIGH','MEDIUM','LOW'];
      const issues=state.issues.filter(i=>['OPEN','INVESTIGATING'].includes(i.status)).map(i=>({id:i.id,booking_id:i.booking_id,booking_ref:bookingFor(api,i.booking_id).booking_ref,title:i.title,severity:i.severity,status:i.status})).sort((a,b)=>severities.indexOf(a.severity)-severities.indexOf(b.severity));
      return {ok:true,date:selected,movement,readiness:ids.map(id=>readiness(api,state,id)),issues};
    }
    if(method==='GET'&&path==='operations/departures'){
      const parsedDays=parseInt(query.get('days')||'14',10),days=Math.max(1,Math.min(90,Number.isFinite(parsedDays)?parsedDays:14)),from=today(),until=addDays(from,days);
      return {ok:true,items:array(api.bookings).filter(b=>b.start_date>=from&&b.start_date<=until&&b.operations_status!=='CANCELLED').sort((a,b)=>a.start_date.localeCompare(b.start_date)).map(b=>{const services=array(api.services).filter(s=>sameId(s.booking_id,b.id));return {...copy(b),service_count:services.length,confirmed_services:services.filter(s=>['CONFIRMED','COMPLETED'].includes(s.booking_status)).length}})};
    }
    if(method==='GET'&&path==='operations/timeline'){
      const selected=date(query.has('date')?query.get('date'):today());
      const items=array(api.services).filter(s=>s.service_date===selected).map(s=>{const b=array(api.bookings).find(b=>sameId(b.id,s.booking_id));if(!b||b.operations_status==='CANCELLED')return null;const supplier=array(api.suppliers).find(p=>sameId(p.id,s.supplier_id)),flight=array(api.flights).find(f=>sameId(f.id,s.linked_flight_id));return {...copy(s),booking_ref:b.booking_ref,lead_guest_name:b.lead_guest_name||'',supplier_name:supplier?.name||s.supplier_name||null,flight_number:flight?.flight_number||null,flight_arrival:flight?.arrival_time||null}}).filter(Boolean).sort((a,b)=>String(a.start_time||'23:59:59').localeCompare(String(b.start_time||'23:59:59'))||a.booking_ref.localeCompare(b.booking_ref));
      return {ok:true,date:selected,items};
    }
    if(method==='GET'&&path==='finance/ap')return {ok:true,items:array(api.payables).filter(p=>p.status!=='CANCELLED').map(p=>{const b=array(api.bookings).find(b=>sameId(b.id,p.booking_id)),supplier=array(api.suppliers).find(s=>sameId(s.id,p.supplier_id));return {...copy(p),booking_ref:b?.booking_ref||'',lead_guest_name:b?.lead_guest_name||'',supplier_name:supplier?.name||p.supplier_name||''}}).sort((a,b)=>['UNPAID','PART_PAID','PAID'].indexOf(a.status)-['UNPAID','PART_PAID','PAID'].indexOf(b.status)||String(a.due_date||'2999-12-31').localeCompare(String(b.due_date||'2999-12-31')))};
    if(['GET','PUT'].includes(method)&&(match=path.match(/^services\/(\d+)\/travel-details$/))){
      const service=serviceFor(api,Number(match[1])),old=state.travelDetails.get(Number(service.id))||cleanTravelDetails({});
      const details=method==='PUT'?cleanTravelDetails({...old,...body}):old;
      if(method==='PUT')state.travelDetails.set(Number(service.id),details);
      return {ok:true,details:copy(details),...(method==='PUT'?{preview_only:true}:{})};
    }
    if(method==='POST'&&(match=path.match(/^payables\/(\d+)\/payments$/))){
      const payable=array(api.payables).find(p=>sameId(p.id,match[1]));if(!payable)fail(404,'NOT_FOUND','Payable not found');
      const key=requiredText(body.idempotency_key,80),amount=cents(body.amount),paymentDate=date(body.payment_date??today()),reference=requiredText(body.transaction_reference),currency=body.currency??payable.currency;
      if(currency!==payable.currency||amount<=0)fail(422,'VALIDATION','Positive same-currency supplier payment required');
      const payload=JSON.stringify([Number(payable.id),amount,paymentDate,currency,reference]),existing=state.paymentKeys.get(key);
      if(existing){if(existing.payload!==payload)fail(409,'CONFLICT','Payment key already used');return copy(existing.result)}
      if(payable.status==='CANCELLED'||amount>cents(payable.balance))fail(409,'CONFLICT','Payment exceeds payable balance');
      const paid=cents(payable.paid_amount)+amount,balance=cents(payable.total_amount)-paid;if(balance<0)fail(409,'CONFLICT','Payment exceeds payable balance');
      const payments=array(api.supplierPayments),id=nextId(payments),paymentRef='SPAY-PREVIEW-'+String(id).padStart(6,'0');
      const payment={id,payment_ref:paymentRef,payable_id:payable.id,booking_id:payable.booking_id,supplier_id:payable.supplier_id,payment_date:paymentDate,amount:amount/100,currency,transaction_reference:reference,recorded_by:api.user?.id||1,preview_only:true};
      const result={ok:true,id,payment_ref:paymentRef,preview_only:true};
      Object.assign(payable,{paid_amount:paid/100,balance:balance/100,status:balance===0?'PAID':'PART_PAID'});if(!Array.isArray(api.supplierPayments))api.supplierPayments=[];api.supplierPayments.unshift(payment);state.paymentKeys.set(key,{payload,result});
      return copy(result);
    }
    if(method==='GET'&&(match=path.match(/^bookings\/(\d+)\/(readiness|issues)$/))){
      const id=Number(match[1]);bookingFor(api,id);
      return match[2]==='readiness'?{ok:true,...readiness(api,state,id)}:{ok:true,items:copy(state.issues.filter(i=>sameId(i.booking_id,id)).sort((a,b)=>b.created_at.localeCompare(a.created_at)||Number(b.id)-Number(a.id)))};
    }
    if(method==='POST'&&(match=path.match(/^services\/(\d+)\/assign$/))){
      const service=serviceFor(api,Number(match[1])),start=time(body.starts_at),end=time(body.ends_at),resource=state.resources.find(r=>sameId(r.id,body.resource_id)&&r.status==='ACTIVE');
      if(end<=start)fail(422,'VALIDATION','End must follow start');if(!resource)fail(404,'NOT_FOUND','Resource not found');
      if(service.booking_status==='CANCELLED')fail(409,'CONFLICT','Cannot assign a cancelled service');
      if(!service.service_date||start.slice(0,10)!==service.service_date)fail(409,'CONFLICT','Assignment must start on the service date');
      const serviceStart=service.start_time?service.service_date+' '+(service.start_time.length===5?service.start_time+':00':service.start_time):null;
      if(serviceStart&&start>serviceStart)fail(409,'CONFLICT','Assignment must cover the service start');
      if(service.end_time){const endTime=service.end_time.length===5?service.end_time+':00':service.end_time,startTime=service.start_time&&service.start_time.length===5?service.start_time+':00':service.start_time;let requiredEnd=service.service_date+' '+endTime;if(startTime&&endTime<startTime)requiredEnd=addDays(service.service_date,1)+' '+endTime;if(end<requiredEnd)fail(409,'CONFLICT','Assignment must cover the service end')}
      const conflict=state.assignments.find(a=>sameId(a.resource_id,resource.id)&&a.status==='ASSIGNED'&&a.starts_at<end&&a.ends_at>start);
      if(conflict){if(sameId(conflict.service_id,service.id)&&conflict.starts_at===start&&conflict.ends_at===end)return {ok:true,id:conflict.id,preview_only:true};fail(409,'CONFLICT','Resource already assigned during this time interval')}
      const id=nextId(state.assignments),assignment={id,resource_id:resource.id,service_id:service.id,starts_at:start,ends_at:end,status:'ASSIGNED',assigned_by:api.user?.id||1};state.assignments.push(assignment);updateContact(api,state,assignment);return {ok:true,id,preview_only:true};
    }
    if(method==='POST'&&(match=path.match(/^(?:resource-assignments|assignments)\/(\d+)\/cancel$/))){
      requiredText(body.reason,1000);const assignment=state.assignments.find(a=>sameId(a.id,match[1]));if(!assignment)fail(404,'NOT_FOUND','Assignment not found');
      assignment.status='CANCELLED';updateContact(api,state,assignment);return {ok:true,id:assignment.id,preview_only:true};
    }
    if(method==='POST'&&(match=path.match(/^bookings\/(\d+)\/issues$/))){
      const booking=bookingFor(api,Number(match[1])),severity=body.severity||'MEDIUM';if(!['LOW','MEDIUM','HIGH','CRITICAL'].includes(severity))fail(422,'VALIDATION','Invalid severity');
      const owner=Number(body.owner_user_id||api.user?.id||1),activeUsers=array(api.users).length?api.users:[api.user].filter(Boolean),user=activeUsers.find(u=>sameId(u.id,owner)&&(u.status==null||u.status==='ACTIVE')&&(u.company_id==null||sameId(u.company_id,api.user?.company_id)));
      if(!user)fail(422,'VALIDATION','Active same-company issue owner required');
      const category=requiredText(body.category||'OTHER',64),title=requiredText(body.title),description=requiredText(body.description,16000),id=nextId(state.issues);
      state.issues.push({id,booking_id:booking.id,category,severity,title,description,owner_user_id:owner,owner_name:user.full_name||'',created_by:api.user?.id||1,created_at:localNow(),status:'OPEN',root_cause:null,resolution:null,lessons_learned:null,financial_impact:0,currency:'USD'});return {ok:true,id,preview_only:true};
    }
    if(method==='POST'&&(match=path.match(/^issues\/(\d+)\/resolve$/))){
      const issue=state.issues.find(i=>sameId(i.id,match[1]));if(!issue)fail(404,'NOT_FOUND','Issue not found');if(['RESOLVED','CLOSED'].includes(issue.status))fail(409,'CONFLICT','Issue already resolved');
      const root=requiredText(body.root_cause,8000),resolution=requiredText(body.resolution,8000),lessons=requiredText(body.lessons_learned,8000),currency=body.currency||'USD',amount=String(body.financial_impact??'0');
      if(!['USD','VND'].includes(currency))fail(422,'VALIDATION','Invalid impact currency');if(!/^\d{1,12}(?:\.\d{1,2})?$/.test(amount)||!Number.isFinite(Number(amount)))fail(422,'VALIDATION','Amount must be nonnegative with at most two decimal places');
      Object.assign(issue,{status:'RESOLVED',root_cause:root,resolution,lessons_learned:lessons,financial_impact:Number(amount),currency,resolved_by:api.user?.id||1,resolved_at:localNow()});return {ok:true,id:issue.id,status:'RESOLVED',preview_only:true};
    }
    return undefined;
  }
  window.VTAOperationsDemo={handle};
})();

/* RC6 Phase 1 preview projection. Reads existing DemoApi records; no invented live metrics. */
(() => {
 'use strict';
 const array = v => Array.isArray(v) ? v : [];
 const copy = v => JSON.parse(JSON.stringify(v));
 const keys = ['new_leads','need_qualification','followup_today','overdue_followup','draft_quotes','sent_quotes','waiting_client','confirmed_today','lost'];
 const permissions = {new_leads:'lead.view',need_qualification:'lead.view',followup_today:'sales.view',overdue_followup:'sales.view',draft_quotes:'sales.view',sent_quotes:'sales.view',waiting_client:'sales.view',confirmed_today:'booking.view',lost:'sales.view'};
 const definitions = {
  new_leads:'NEW lead requests created today; also included in Need Qualification.',
  need_qualification:'All NEW lead requests, across all creation dates.',
  followup_today:'Active inquiries with next_action_due today, including elapsed times today.',
  overdue_followup:'Active inquiries with next_action_due before today.',
  draft_quotes:'Current quote and current version are DRAFT, READY or APPROVED.',
  sent_quotes:'Quote status SENT and current version status SENT.',
  waiting_client:'Quote status FOLLOW_UP and current version status SENT.',
  confirmed_today:'Non-cancelled bookings created today; historical client confirmation time is not recorded separately.',
  lost:'All LOST inquiries, across all creation dates.'
 };
 const fail=(status,message)=>{throw Object.assign(Error(message),{status,data:{ok:false,error:status===403?'FORBIDDEN':status===409?'CONFLICT':status===404?'NOT_FOUND':'VALIDATION',message}});};
 const validDate = value => typeof value==='string' && /^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/.test(value);
 const local = value => typeof value==='string' ? value.replace('T',' ').slice(0,19) : '';
 const rowId = row => Number(row.id)||0;
 const order = (a,b,field) => String(a[field]||'').localeCompare(String(b[field]||'')) || rowId(a)-rowId(b);
 const unfinishedInquiry = row => !['CONFIRMED','LOST','CANCELLED'].includes(row.status);
 const activeBooking = row => !['OPERATION_COMPLETED','COMPLETED','CANCELLED'].includes(row.operations_status);
 function clock() {
  const now=new Date(Date.now()+7*3600000),today=now.toISOString().slice(0,10),start=today+' 00:00:00';
  const tomorrow=new Date(now);tomorrow.setUTCDate(tomorrow.getUTCDate()+1);
  const nextSeven=new Date(now);nextSeven.setUTCDate(nextSeven.getUTCDate()+7);
  return {as_of:now.toISOString().slice(0,19)+'+07:00',timezone:'Asia/Ho_Chi_Minh',date_range:{today,start,end:tomorrow.toISOString().slice(0,10)+' 00:00:00',next_7_end:nextSeven.toISOString().slice(0,10)+' 00:00:00'}};
 }
 function scoped(api,rows) {
  const company=Number(api.user?.company_id);
  return array(rows).filter(row=>row.company_id===undefined||Number(row.company_id)===company);
 }
 const handoverStates=['PENDING','ACCEPTED','RETURNED','CONVERTED'];
 const contactEmail=v=>String(v||'').trim().toLowerCase(),contactPhone=v=>String(v||'').replace(/\D/g,'');
 const newId=rows=>array(rows).reduce((max,row)=>Math.max(max,Number(row.id)||0),0)+1;
 function requests(api){return scoped(api,api.leadRequests||api.lead_requests||api.requests);}
 function leads(api){return scoped(api,api.leads).filter(lead=>requests(api).some(row=>Number(row.id)===Number(lead.request_id))).map(lead=>{
  const request=requests(api).find(row=>Number(row.id)===Number(lead.request_id))||{},owner=scoped(api,api.users).find(row=>Number(row.id)===Number(lead.owner_user_id)),salesOwner=scoped(api,api.users).find(row=>Number(row.id)===Number(lead.sales_owner_user_id));
  return {...lead,contact_name:request.contact_name||lead.contact_name||'',email:request.email||lead.email||'',phone:request.phone||lead.phone||'',source:request.source||lead.source||'',campaign_id:request.campaign_id||lead.campaign_id||null,attribution_json:request.attribution_json||lead.attribution_json||null,destination:request.destination||lead.destination||'',travel_date:request.travel_date||lead.travel_date||null,handover_status:lead.handover_status||(lead.inquiry_id||lead.status==='CONVERTED'?'CONVERTED':'PENDING'),handover_version:Number(lead.handover_version)||1,owner_name:owner?.full_name||lead.owner_name||(Number(lead.owner_user_id)===Number(api.user?.id)?api.user?.full_name||'Demo user':''),sales_owner_name:salesOwner?.full_name||lead.sales_owner_name||'',history:copy(array(lead.history))};
 });}
 function candidates(api,lead){return scoped(api,api.customers).filter(row=>row.status===undefined||row.status==='ACTIVE').map(row=>{
  const reasons=[];if(contactEmail(lead.email)&&contactEmail(row.email)===contactEmail(lead.email))reasons.push('EMAIL');if(contactPhone(lead.phone)&&contactPhone(row.whatsapp)===contactPhone(lead.phone))reasons.push('PHONE');
  return {id:Number(row.id),full_name:row.full_name||'',email:row.email||'',whatsapp:row.whatsapp||'',match_reasons:reasons};
 }).filter(row=>row.match_reasons.length).sort((a,b)=>a.id-b.id);}
 function requiredOwner(api,value){const owner=Number(value);if(!Number.isSafeInteger(owner)||owner<1)fail(422,'An active same-company owner is required.');const users=scoped(api,api.users);if(users.length?!users.some(row=>Number(row.id)===owner&&(row.status===undefined||row.status==='ACTIVE')):owner!==Number(api.user?.id))fail(422,'An active same-company owner is required.');return owner;}
 function due(value){if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)||Number.isNaN(Date.parse(value.replace(' ','T')+'+07:00')))fail(422,'A valid next action due is required.');return value;}
 function permission(can,keys){if(!keys.every(can))fail(403,'This action is not permitted.');}
 function commandKey(api,route,body){const raw=body.action_key;if(typeof raw!=='string'||!/^[a-zA-Z0-9_-]{16,80}$/.test(raw))fail(422,'Invalid action_key.');const key=api.user.company_id+':'+api.user.id+':'+raw,hash=JSON.stringify(body),old=api.leadActions?.[key];if(old&&(old.route!==route||old.hash!==hash))fail(409,'Action key already used for different content.');return {key,hash,old};}
 function remember(api,route,command,result){api.leadActions||={};api.leadActions[command.key]={route,hash:command.hash,result:copy(result)};return {...result,preview_only:true};}
 function closeLeadTasks(api,lead){scoped(api,api.tasks).filter(row=>row.entity_type==='lead'&&Number(row.entity_id)===Number(lead.id)&&row.source==='AUTOMATION'&&['LEAD_SALES_REVIEW','LEAD_SALES_NEXT_ACTION','LEAD_REQUALIFY'].includes(row.rule_code)&&['OPEN','SNOOZED'].includes(row.status)).forEach(row=>row.status='DONE');}
 function updateTask(api,lead,owner,deadline){api.tasks||=[];const execution='lead-'+lead.id+'-version-'+lead.handover_version;if(scoped(api,api.tasks).some(row=>row.execution_key===execution))return;closeLeadTasks(api,lead);api.tasks.push({id:newId(api.tasks),company_id:Number(api.user.company_id),entity_type:'lead',entity_id:lead.id,priority:'NORMAL',source:'AUTOMATION',rule_code:lead.handover_status==='PENDING'?'LEAD_SALES_REVIEW':lead.handover_status==='RETURNED'?'LEAD_REQUALIFY':'LEAD_SALES_NEXT_ACTION',execution_key:execution,title:lead.handover_status==='PENDING'?'Review marketing handover':lead.handover_status==='RETURNED'?'Review returned lead':'Follow up accepted lead',owner_user_id:owner,due_at:deadline,status:'OPEN'});}
 function stage(api,lead,status,reason,owner,deadline){const before=lead.handover_status||'PENDING',stamp=clock().as_of;lead.handover_status=status;lead.handover_version=(Number(lead.handover_version)||1)+1;lead.history||=[];lead.history.push({from_status:before,to_status:status,reason:reason||null,actor_user_id:Number(api.user.id),created_at:stamp});if(owner)lead.sales_owner_user_id=status==='ACCEPTED'?owner:lead.sales_owner_user_id;if(deadline)lead.next_action_due=deadline;api.leadEvents||=[];api.leadEvents.push({id:newId(api.leadEvents),company_id:Number(api.user.company_id),entity_type:'lead',entity_id:lead.id,event:status==='ACCEPTED'?'lead.sales_accepted':status==='PENDING'?'lead.qualified':status==='RETURNED'?'lead.sales_returned':'lead.converted',created_at:stamp});if(status==='CONVERTED')closeLeadTasks(api,lead);}
 async function handover(api,path,method,query,body,can){
  let match;
  if(method==='GET'&&(match=/^lead-hub\/leads\/(\d+)\/customer-candidates$/.exec(path))){permission(can,['lead.view','sales.view']);const lead=leads(api).find(row=>Number(row.id)===Number(match[1]));if(!lead)fail(404,'Lead not found.');if(lead.handover_status!=='ACCEPTED')fail(409,'Sales must accept the lead before identity review.');const items=candidates(api,lead),key='preview-review-'+Date.now()+'-'+Math.random().toString(36).slice(2);api.leadIdentityReviews||={};api.leadIdentityReviews[key]={company:Number(api.user.company_id),lead_id:lead.id,actor:Number(api.user.id),version:lead.handover_version,hash:JSON.stringify(items),expires:Date.now()+15*60000};return {ok:true,items,identity_review_key:key,expires_at:new Date(Date.now()+15*60000).toISOString(),handover_version:lead.handover_version,preview_only:true};}
  if(method!=='POST'||!(match=/^lead-hub\/(requests|leads)\/(\d+)\/(qualify|accept|return|resubmit|convert)$/.exec(path)))return undefined;
  const [,kind,rawId,action]=match,recordId=Number(rawId);permission(can,['lead.view',...(action==='accept'||action==='return'?['lead.sales_accept']:['lead.manage']),...(action==='convert'?['inquiry.manage','sales.view']:[])]);
  if((action==='qualify')!==(kind==='requests'))fail(404,'Action not found.');const cmd=commandKey(api,path,body);if(cmd.old)return {ok:true,...copy(cmd.old.result),preview_only:true};
  if(action==='qualify'){
   const row=requests(api).find(row=>Number(row.id)===recordId);if(!row)fail(404,'Request not found.');if(Number(body.expected_version)!==(Number(row.version_no)||1))fail(409,'Request changed. Refresh and retry.');if(row.status!=='NEW'||leads(api).some(lead=>Number(lead.request_id)===recordId))fail(409,'Request already qualified.');if(typeof body.qualification_note!=='string'||!body.qualification_note.trim()||body.qualification_note.length>8000)fail(422,'Qualification notes are required.');const owner=requiredOwner(api,body.owner_user_id??api.user.id),deadline=due(body.next_action_due);
   api.leads||=[];const lead={id:newId(api.leads),company_id:Number(api.user.company_id),request_id:recordId,owner_user_id:owner,qualification_note:body.qualification_note.trim(),qualified_by:Number(api.user.id),handover_status:'PENDING',handover_version:0,status:'QUALIFIED',next_action_due:deadline,created_at:clock().as_of};stage(api,lead,'PENDING',body.qualification_note,owner,deadline);api.leads.push(lead);row.status='QUALIFIED';row.version_no=(Number(row.version_no)||1)+1;updateTask(api,lead,owner,deadline);return remember(api,path,cmd,{ok:true,id:lead.id,handover_status:lead.handover_status,handover_version:lead.handover_version});
  }
  const enriched=leads(api).find(row=>Number(row.id)===recordId),lead=scoped(api,api.leads).find(row=>Number(row.id)===recordId);if(!enriched||!lead)fail(404,'Lead not found.');
  if(action==='convert'&&lead.inquiry_id)return remember(api,path,cmd,{ok:true,id:recordId,inquiry_id:lead.inquiry_id,trip_id:lead.trip_id,customer_id:lead.customer_id,handover_version:enriched.handover_version});
  if(Number(body.expected_version)!==enriched.handover_version)fail(409,'Lead changed. Refresh and retry.');
  if(action==='accept'){
   if(enriched.handover_status!=='PENDING')fail(409,'Only pending handovers may be accepted.');const owner=requiredOwner(api,body.sales_owner_user_id??api.user.id),deadline=due(body.next_action_due);stage(api,lead,'ACCEPTED','',owner,deadline);lead.accepted_at=clock().as_of;lead.accepted_by=Number(api.user.id);updateTask(api,lead,owner,deadline);
  }else if(action==='return'){
   if(!['PENDING','ACCEPTED'].includes(enriched.handover_status))fail(409,'Only pending or accepted handovers may be returned.');if(typeof body.reason!=='string'||!body.reason.trim()||body.reason.length>8000)fail(422,'A return reason is required.');stage(api,lead,'RETURNED',body.reason);lead.next_action_due=new Date(Date.now()+31*3600000).toISOString().slice(0,19).replace('T',' ');lead.return_reason=body.reason.trim();updateTask(api,lead,lead.owner_user_id,lead.next_action_due);
  }else if(action==='resubmit'){
   if(enriched.handover_status!=='RETURNED')fail(409,'Only returned leads may be resubmitted.');if(typeof body.qualification_note!=='string'||!body.qualification_note.trim()||body.qualification_note.length>8000)fail(422,'Qualification notes are required.');const owner=requiredOwner(api,body.owner_user_id??api.user.id),deadline=due(body.next_action_due);lead.owner_user_id=owner;lead.qualification_note=body.qualification_note.trim();lead.return_reason=null;lead.accepted_at=null;lead.accepted_by=null;lead.sales_owner_user_id=null;stage(api,lead,'PENDING',body.qualification_note,owner,deadline);api.leadEvents.at(-1).event='lead.resubmitted';updateTask(api,lead,owner,deadline);
  }else{
   if(enriched.handover_status!=='ACCEPTED')fail(409,'Sales must accept the lead before conversion.');if(body.identity_reviewed!==true||!['LINK_EXISTING','CREATE_NEW'].includes(body.identity_mode))fail(422,'Explicit customer identity review is required.');const review=api.leadIdentityReviews?.[body.identity_review_key],items=candidates(api,enriched);if(!review||review.company!==Number(api.user.company_id)||review.lead_id!==recordId||review.actor!==Number(api.user.id)||review.version!==enriched.handover_version||review.expires<Date.now()||review.hash!==JSON.stringify(items))fail(409,'Customer suggestions changed or expired. Review again.');
   let customer=null,newCustomer=null;if(body.identity_mode==='LINK_EXISTING'){customer=scoped(api,api.customers).find(row=>Number(row.id)===Number(body.customer_id)&&(row.status===undefined||row.status==='ACTIVE'));if(!customer||!items.some(row=>row.id===Number(customer.id)))fail(422,'Select an active reviewed contact match.');}
   else{permission(can,['customer.manage']);const profile=body.new_customer;if(!profile||typeof profile.full_name!=='string'||!profile.full_name.trim()||profile.full_name.length>190||(!contactEmail(profile.email)&&!contactPhone(profile.whatsapp))||(profile.email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(profile.email)))fail(422,'New customer name and contact are required.');const newMatches=candidates(api,{email:profile.email,phone:profile.whatsapp});if((items.length||newMatches.length)&&(typeof body.duplicate_reason!=='string'||body.duplicate_reason.trim().length<10||body.duplicate_reason.length>1000))fail(422,'A reason is required for a distinct identity with matching contact.');newCustomer={id:newId(api.customers),company_id:Number(api.user.company_id),customer_ref:'CUS-PREVIEW-'+newId(api.customers),status:'ACTIVE',full_name:profile.full_name.trim(),email:profile.email||'',whatsapp:profile.whatsapp||'',market:profile.market||''};customer=newCustomer;}
   let agent=null;if(body.agent_id){agent=scoped(api,api.agents).find(row=>Number(row.id)===Number(body.agent_id)&&row.status==='ACTIVE');if(!agent)fail(422,'Active same-company agent required.');}
   const request=requests(api).find(row=>Number(row.id)===Number(lead.request_id));if(!request)fail(409,'Lead source request is missing.');api.trips||=[];api.inquiries||=[];if(newCustomer){api.customers||=[];api.customers.push(newCustomer);}const tripId=newId(array(api.trips).concat(api.trip?[api.trip]:[]));const trip={id:tripId,company_id:Number(api.user.company_id),trip_ref:'TRIP-PREVIEW-'+tripId,customer_id:Number(customer.id),agent_id:agent?Number(agent.id):null,market:body.market||customer.market||agent?.market||'',title:(request.destination||'Trip')+' - '+request.contact_name,lead_contact_name:request.contact_name,lead_email:request.email||'',lead_whatsapp:request.phone||'',total_guests:request.total_guests,paying_pax:request.paying_pax,foc:request.foc,start_date:request.travel_date,sales_owner_id:lead.sales_owner_user_id||lead.owner_user_id};const inquiry={id:newId(api.inquiries),company_id:Number(api.user.company_id),inquiry_ref:'INQ-PREVIEW-'+newId(api.inquiries),trip_id:trip.id,status:'NEW',source:request.source,request_text:request.message||'',destination_text:request.destination||'',next_action:'Prepare quotation',next_action_due:lead.next_action_due,created_at:clock().as_of};api.trips.push(trip);api.inquiries.push(inquiry);lead.customer_id=customer.id;lead.trip_id=trip.id;lead.inquiry_id=inquiry.id;lead.status='CONVERTED';stage(api,lead,'CONVERTED','');api.tasks||=[];if(!scoped(api,api.tasks).some(row=>row.entity_type==='inquiry'&&Number(row.entity_id)===inquiry.id&&row.rule_code==='INQUIRY_NEXT_ACTION'))api.tasks.push({id:newId(api.tasks),company_id:Number(api.user.company_id),entity_type:'inquiry',entity_id:inquiry.id,title:'Prepare quotation',owner_user_id:trip.sales_owner_id,due_at:lead.next_action_due,priority:'NORMAL',status:'OPEN',source:'AUTOMATION',rule_code:'INQUIRY_NEXT_ACTION'});const task=scoped(api,api.tasks).find(row=>row.entity_type==='lead'&&Number(row.entity_id)===lead.id&&row.rule_code==='LEAD_HANDOVER_NEXT_ACTION');if(task)task.status='DONE';return remember(api,path,cmd,{ok:true,id:recordId,inquiry_id:inquiry.id,trip_id:trip.id,customer_id:customer.id,handover_version:lead.handover_version});
  }
  return remember(api,path,cmd,{ok:true,id:recordId,handover_status:lead.handover_status,handover_version:lead.handover_version});
 }
 function common(type,row,api) {
  const trip=api.trip&&Number(api.trip.id)===Number(row.trip_id)?api.trip:array(api.trips).find(t=>Number(t.id)===Number(row.trip_id))||{};
  const names={lead_request:row.contact_name||row.full_name||'',inquiry:row.lead_contact_name||trip.lead_contact_name||'',quote:row.contact||row.lead_contact_name||trip.lead_contact_name||'',booking:row.lead_guest_name||'',task:''};
  const routes={lead_request:['leads',{requestId:Number(row.id)}],inquiry:['sales-list',{salesTab:'inquiries',inquiryId:Number(row.id)}],quote:['quote',{quoteId:Number(row.id)}],booking:['booking',{bookingId:Number(row.id)}],task:['operations',{opsModule:'tasks',taskId:Number(row.id)}]};
  return {entity_type:type,id:Number(row.id),ref:row.request_ref||row.inquiry_ref||row.quote_ref||row.booking_ref||(['lead_request','task'].includes(type)?String(row.id):''),title:row.destination||row.title||row.tour_name||trip.title||row.next_action||'',contact:names[type]||'',status:row.status||row.operations_status||'',owner_name:row.owner_name||row.sales_owner||'',due_at:row.next_action_due||row.due_at||null,event_date:type==='quote'?row.sent_at||null:row.created_at||null,route:routes[type][0],params:routes[type][1]};
 }
 function dataFor(api,stamp) {
  const requests=scoped(api,api.leadRequests||api.lead_requests||api.requests),inquiries=scoped(api,api.inquiries),bookings=scoped(api,api.bookings),tasks=scoped(api,api.tasks);
  const quotes=scoped(api,Array.isArray(api.quotes)?api.quotes:api.quote?[api.quote]:[]);
  const versions=array(api.quoteVersions||api.quote_versions||api.versions).concat(api.qv?[api.qv]:[]);
  const quoteRows=quotes.map(q=>{
   const v=versions.find(v=>Number(v.quote_id)===Number(q.id)&&Number(v.version_no)===Number(q.current_version_no));
   return v?{...q,tour_name:v.tour_name||q.tour_name,version_status:v.version_status,sent_at:v.sent_at}:null;
  }).filter(Boolean);
  const inToday=value=>validDate(value)&&local(value)>=stamp.date_range.start&&local(value)<stamp.date_range.end;
  const todayRows=inquiries.filter(i=>unfinishedInquiry(i)&&inToday(i.next_action_due)).sort((a,b)=>order(a,b,'next_action_due'));
  const overdueRows=inquiries.filter(i=>unfinishedInquiry(i)&&validDate(i.next_action_due)&&local(i.next_action_due)<stamp.date_range.start).sort((a,b)=>order(a,b,'next_action_due'));
  const newRows=requests.filter(r=>r.status==='NEW'&&inToday(r.created_at)).sort((a,b)=>-order(a,b,'created_at'));
  const needRows=requests.filter(r=>r.status==='NEW').sort((a,b)=>-order(a,b,'created_at'));
  const draftRows=quoteRows.filter(q=>['DRAFT','READY','APPROVED'].includes(q.status)&&['DRAFT','READY','APPROVED'].includes(q.version_status)).sort((a,b)=>-order(a,b,'updated_at'));
  const sentRows=quoteRows.filter(q=>q.status==='SENT'&&q.version_status==='SENT').sort((a,b)=>-order(a,b,'updated_at'));
  const waitingRows=quoteRows.filter(q=>q.status==='FOLLOW_UP'&&q.version_status==='SENT').sort((a,b)=>-order(a,b,'updated_at'));
  const confirmedRows=bookings.filter(b=>b.operations_status!=='CANCELLED'&&inToday(b.created_at)).sort((a,b)=>-order(a,b,'created_at'));
  const lostRows=inquiries.filter(i=>i.status==='LOST').sort((a,b)=>order(a,b,'next_action_due'));
  const raw={new_leads:newRows,need_qualification:needRows,followup_today:todayRows,overdue_followup:overdueRows,draft_quotes:draftRows,sent_quotes:sentRows,waiting_client:waitingRows,confirmed_today:confirmedRows,lost:lostRows};
  const types={new_leads:'lead_request',need_qualification:'lead_request',followup_today:'inquiry',overdue_followup:'inquiry',draft_quotes:'quote',sent_quotes:'quote',waiting_client:'quote',confirmed_today:'booking',lost:'inquiry'};
  const sales={};keys.forEach(key=>sales[key]=raw[key].map(row=>common(types[key],row,api)));
  const departed=bookings.filter(b=>activeBooking(b)&&b.start_date===stamp.date_range.today);
  const risk=bookings.filter(b=>activeBooking(b)&&['HIGH','CRITICAL'].includes(b.risk_level));
  const operations=bookings.filter(b=>activeBooking(b)&&(b.start_date===stamp.date_range.today||['HIGH','CRITICAL'].includes(b.risk_level))).sort((a,b)=>order(a,b,'start_date')).map(row=>({...common('booking',row,api),due_at:row.start_date,event_date:row.start_date}));
  const priority=value=>value==='CRITICAL'?0:value==='HIGH'?1:2;
  const mine=tasks.filter(task=>Number(task.owner_user_id)===Number(api.user?.id)&&['OPEN','SNOOZED'].includes(task.status)&&validDate(task.due_at)&&local(task.due_at)<stamp.date_range.end).sort((a,b)=>priority(a.priority)-priority(b.priority)||order(a,b,'due_at')).map(row=>common('task',row,api));
  const due=inquiries.filter(i=>unfinishedInquiry(i)&&validDate(i.next_action_due)&&local(i.next_action_due)<stamp.date_range.end).sort((a,b)=>order(a,b,'next_action_due')).map(row=>common('inquiry',row,api));
  return {sales,departed,risk,operations,mine,due};
 }
 function customer360(api,customerId,can){
  permission(can,['sales.view']);const customer=scoped(api,api.customers).find(row=>Number(row.id)===customerId);if(!customer)fail(404,'Customer not found.');
  const profile={};['id','customer_ref','full_name','market','country','email','whatsapp','status'].forEach(key=>{if(customer[key]!==undefined)profile[key]=customer[key];});
  const allTrips=scoped(api,array(api.trips).concat(api.trip?[api.trip]:[])),tripIds=new Set(allTrips.filter(row=>Number(row.customer_id)===customerId).map(row=>Number(row.id)));
  const inqs=scoped(api,api.inquiries).filter(row=>tripIds.has(Number(row.trip_id))),inquiryIds=new Set(inqs.map(row=>Number(row.id))),quoteRows=scoped(api,Array.isArray(api.quotes)?api.quotes:api.quote?[api.quote]:[]).filter(row=>tripIds.has(Number(row.trip_id))),quoteIds=new Set(quoteRows.map(row=>Number(row.id))),bookingRows=scoped(api,api.bookings).filter(row=>tripIds.has(Number(row.trip_id))),bookingIds=new Set(bookingRows.map(row=>Number(row.id)));
  const leadRows=leads(api).filter(row=>Number(row.customer_id)===customerId||inquiryIds.has(Number(row.inquiry_id))),leadIds=new Set(leadRows.map(row=>Number(row.id)));
  const safe=(row,type)=>({id:Number(row.id),ref:row.request_ref||row.inquiry_ref||row.quote_ref||row.booking_ref||row.document_ref||'',title:row.title||row.contact_name||row.tour_name||row.original_filename||row.filename||'',status:row.handover_status||row.status||row.review_status||row.operations_status||'',created_at:row.created_at||null,source:type==='lead'?row.source||'':undefined,campaign_id:type==='lead'?row.campaign_id||null:undefined,request_id:type==='lead'?row.request_id:undefined,owner_name:row.sales_owner_name||row.owner_name||'',next_action_due:row.next_action_due||row.due_at||null});
  const relevant=(type,recordId)=>({customer:new Set([customerId]),lead:leadIds,lead_request:new Set(leadRows.map(row=>Number(row.request_id))),inquiry:inquiryIds,quote:quoteIds,booking:bookingIds})[type]?.has(Number(recordId))===true;
  const taskRows=scoped(api,api.tasks).filter(row=>relevant(row.entity_type,row.entity_id)&&(can('approval.manage')||Number(row.owner_user_id)===Number(api.user.id))),documents=scoped(api,api.travelDocuments||api.docs).filter(row=>bookingIds.has(Number(row.booking_id)));
  const section=(available,rows)=>({available,items:available?copy(rows.slice(0,30)):[],total:available?rows.length:null,has_more:available&&rows.length>30});
  const financeAvailable=can('finance.view')&&can('customer_ar.view'),currencyGroups={};
  if(financeAvailable)scoped(api,api.invoices).filter(row=>bookingIds.has(Number(row.booking_id))&&['ISSUED','SENT','PART_PAID','PAID'].includes(row.status)&&!['RECEIPT','CREDIT_NOTE'].includes(row.invoice_type)).forEach(row=>{if(!/^[A-Z]{3}$/.test(row.currency||'')||![row.total,row.paid_amount??row.paid??0,row.balance].every(value=>Number.isFinite(Number(value))))return;const g=currencyGroups[row.currency]||={currency:row.currency,total:0,paid:0,balance:0,invoice_count:0};g.total+=Number(row.total);g.paid+=Number(row.paid_amount??row.paid??0);g.balance+=Number(row.balance);g.invoice_count++;});
  const finance=Object.values(currencyGroups).sort((a,b)=>a.currency.localeCompare(b.currency)).map(row=>({...row,total:Math.round(row.total*100)/100,paid:Math.round(row.paid*100)/100,balance:Math.round(row.balance*100)/100}));
  const sections={leads:section(can('lead.view'),leadRows.map(row=>safe(row,'lead'))),inquiries:section(can('sales.view'),inqs.map(row=>safe(row,'inquiry'))),quotes:section(can('sales.view'),quoteRows.map(row=>safe(row,'quote'))),bookings:section(can('booking.view'),bookingRows.map(row=>safe(row,'booking'))),tasks:section(can('task.view'),taskRows.map(row=>safe(row,'task'))),documents:section(can('travel_document.view'),documents.map(row=>safe(row,'document'))),finance:section(financeAvailable,finance)};
  const activity=[];Object.entries(sections).filter(([key,value])=>value.available&&key!=='finance').forEach(([key,value])=>value.items.forEach(row=>{if(row.created_at)activity.push({id:activity.length+1,title:key+' record',ref:row.ref,status:row.status,created_at:row.created_at});}));activity.sort((a,b)=>String(b.created_at).localeCompare(String(a.created_at)));sections.activity=section(true,activity);
  return {ok:true,customer:profile,sections,preview_only:true};
 }
 async function handle(api,route,opt={}) {
  if(!api||typeof route!=='string')return undefined;
  const [rawPath,search='']=route.split('?'),path=rawPath.replace(/^\/+|\/+$/g,''),method=String(opt.method||'GET').toUpperCase();
  const can=p=>array(api.user?.permissions).includes(p),query=new URLSearchParams(search);
  const stageResult=await handover(api,path,method,query,opt.body||{},can);if(stageResult!==undefined)return stageResult;
  if(method!=='GET')return undefined;
  const customerMatch=/^customers\/(\d+)\/360$/.exec(path);if(customerMatch)return customer360(api,Number(customerMatch[1]),can);
  if(path==='lead-hub/owners'){permission(can,['lead.view']);return {ok:true,items:scoped(api,api.users||[api.user]).filter(row=>row.status===undefined||row.status==='ACTIVE').map(row=>({id:Number(row.id),full_name:row.full_name||'Demo user'})),preview_only:true};}
  if(path==='inquiries'&&query.has('id')) {
   if(!can('sales.view'))fail(403,'Sales access is required.');
   if(!/^[1-9]\d*$/.test(query.get('id'))||!Number.isSafeInteger(Number(query.get('id'))))fail(422,'Invalid inquiry id.');
   return {ok:true,items:copy(scoped(api,api.inquiries).filter(row=>Number(row.id)===Number(query.get('id')))),preview_only:true};
  }
  if(['lead-hub/requests','lead-hub/leads'].includes(path)) {
   if(!can('lead.view'))fail(403,'Lead access is required.');
   const param='request_id';
   if(query.has(param)&&(!/^[1-9]\d*$/.test(query.get(param))||!Number.isSafeInteger(Number(query.get(param)))))fail(422,'Invalid lead record id.');
   const isRequest=path==='lead-hub/requests';let rows=isRequest?requests(api).map(row=>({...row,version_no:Number(row.version_no)||1})):leads(api);
   if(query.has(param))rows=rows.filter(row=>Number(isRequest?row.id:row.request_id)===Number(query.get(param)));
   if(query.has('lead_id')){if(!/^[1-9]\d*$/.test(query.get('lead_id')))fail(422,'Invalid lead_id.');rows=rows.filter(row=>Number(row.id)===Number(query.get('lead_id')));}
   const counts=Object.fromEntries(handoverStates.map(status=>[status,isRequest?0:rows.filter(row=>row.handover_status===status).length]));
   if(isRequest&&query.has('status')){if(!['NEW','QUALIFIED','REJECTED'].includes(query.get('status')))fail(422,'Invalid request status.');rows=rows.filter(row=>row.status===query.get('status'));}
   if(!isRequest&&query.has('handover_status')){if(!handoverStates.includes(query.get('handover_status')))fail(422,'Invalid handover status.');rows=rows.filter(row=>row.handover_status===query.get('handover_status'));}
   const page=(key,fallback,max)=>{if(!query.has(key))return fallback;const value=query.get(key);if(!/^\d+$/.test(value)||!Number.isSafeInteger(Number(value))||Number(value)>(max)||Number(value)<(key==='limit'?1:0))fail(422,'Invalid '+key+'.');return Number(value);},limit=page('limit',100,100),offset=page('offset',0,100000);rows.sort((a,b)=>Number(b.id)-Number(a.id));
   return {ok:true,items:copy(rows.slice(offset,offset+limit)),total:rows.length,counts:isRequest?undefined:counts,limit,offset,preview_only:true};
  }
  if(!['workspace/home','workspace/sales'].includes(path))return undefined;
  const stamp=clock(),records=dataFor(api,stamp);
  if(path==='workspace/sales') {
   if(!['lead.view','sales.view','booking.view'].some(can))fail(403,'Sales workspace access is required.');
   const selected=query.get('queue')||'';
   if(selected&&!keys.includes(selected))fail(422,'Unknown sales queue.');
   const number=(name,fallback,min,max)=>{const value=query.get(name);if(value===null)return fallback;if(!/^\d+$/.test(value)||!Number.isSafeInteger(Number(value))||Number(value)<min||Number(value)>max)fail(422,'Invalid '+name+'.');return Number(value);};
   const limit=number('limit',selected?30:6,1,100),offset=number('offset',0,0,10000000),metrics={},queues={};
   keys.forEach(key=>metrics[key]=can(permissions[key])?records.sales[key].length:null);
   (selected?[selected]:keys).forEach(key=>{const available=can(permissions[key]),all=available?records.sales[key]:[];queues[key]={available,total:available?all.length:null,items:copy(all.slice(offset,offset+limit)),limit,offset};});
   return {ok:true,...stamp,preview_only:true,metrics,definitions:copy(definitions),queues};
  }
  const access={marketing:can('lead.view'),sales:['lead.view','sales.view','booking.view'].some(can),operations:can('operations.view'),tasks:can('task.view')};
  let pending=null;
  if(access.marketing) {
   if(Array.isArray(api.marketingContent))pending=scoped(api,api.marketingContent).filter(row=>row.status==='PENDING').length;
   else if(window.VTAMarketingDemo?.request) {
    const studio=await window.VTAMarketingDemo.request('marketing/studio');pending=array(studio?.items).filter(row=>row.status==='PENDING').length;
   } else pending=null;
  }
  const section=(available,rows)=>({available,total:available?rows.length:null,items:available?copy(rows.slice(0,8)):[],limit:8,offset:0});
  return {ok:true,...stamp,preview_only:true,access,metrics:{marketing_pending:pending,new_leads:can('lead.view')?records.sales.new_leads.length:null,sales_followups_due:can('sales.view')?records.due.length:null,departures_today:access.operations?records.departed.length:null,bookings_at_risk:access.operations?records.risk.length:null,my_tasks_due:access.tasks?records.mine.length:null},attention:{sales:section(can('sales.view'),records.due),operations:section(access.operations,records.operations),tasks:section(access.tasks,records.mine)},definitions:{marketing_pending:'Pending marketing content, all dates.',new_leads:definitions.new_leads,sales_followups_due:'Active inquiry next actions due before the end of today (today and overdue).',departures_today:'Bookings starting today that are not operation-completed, completed or cancelled.',bookings_at_risk:'Operationally active bookings with persisted HIGH or CRITICAL risk; does not recalculate readiness.',my_tasks_due:'Your OPEN/SNOOZED tasks due before the end of today.'}};
 }
 window.VTAWorkspaceDemo={handle};
})();

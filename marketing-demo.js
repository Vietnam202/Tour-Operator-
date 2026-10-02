(()=>{'use strict';
const M=window.VTAMarketingModel,copy=x=>JSON.parse(JSON.stringify(x));let data,batches=new Map();
function seed(){
 const today=M.today();const scheduled=(n)=>new Date(Date.parse(M.utc(today+'T10:00'))+n*86400000).toISOString().replace('.000Z','Z');
 return {campaigns:[{id:1,name:'DEMO · Vietnam Family Discovery',market:'India',offer:'Vietnam family tour concept. Dates and supplier rates to confirm.',audience:'Indian families and private groups',budget:0,currency:'USD',request_count:3,qualified_count:1,converted_count:1,status:'ACTIVE'},{id:2,name:'DEMO · Vietnam Couple Escape',market:'India',offer:'A private Vietnam journey for two, tailored after qualification.',audience:'Couples',budget:0,currency:'USD',request_count:1,qualified_count:0,converted_count:0,status:'ACTIVE'}],
 items:[{id:1,campaign_id:1,campaign_name:'DEMO · Vietnam Family Discovery',channel:'Facebook',body:'Planning Vietnam with your family? Share your dates, group size and interests for a tailored itinerary. Services and prices require final confirmation.',status:'DRAFT',topic:'Vietnam Packages',content_format:'POST',tags:'India, family',asset_url:'',rights_note:'',planned_at:scheduled(0)},
 {id:2,campaign_id:1,campaign_name:'DEMO · Vietnam Family Discovery',channel:'YouTube Shorts',body:'SAMPLE SCRIPT · 0–5s: Vietnam together. 5–20s: Hanoi, Ninh Binh and Ha Long Bay using licensed footage. 20–30s: Ask VTA for a tailored family itinerary. No prices or availability confirmed.',status:'PENDING',topic:'Vietnam Destination',content_format:'REEL_SCRIPT',tags:'family, video',asset_url:'',rights_note:'',planned_at:scheduled(1)},
 {id:3,campaign_id:2,campaign_name:'DEMO · Vietnam Couple Escape',channel:'Instagram',body:'A Vietnam escape, planned around you. Tell us your dates and interests. Draft idea only; all services subject to final confirmation.',status:'APPROVED',topic:'Promotion / CTA',content_format:'POST',tags:'couple',asset_url:'',rights_note:'',planned_at:scheduled(3)}],
 library:[{id:1,title:'India family · qualification CTA',topic:'India → Vietnam',body:'Planning a family trip to Vietnam? Tell us your travel dates, number of adults and children, duration and preferred hotel category. Our local team will prepare a tailored itinerary for review.',tags:'India, family, qualification',asset_url:'',rights_note:''},{id:2,title:'Destination · Ninh Binh video outline',topic:'Vietnam Destination',body:'SAMPLE VIDEO OUTLINE: landscape opening → experience highlights using licensed footage → invitation to ask VTA for an itinerary. Verify current operating details and access before publication.',tags:'Ninh Binh, video',asset_url:'',rights_note:''},{id:3,title:'Review · request permission first',topic:'Customer Reviews',body:'TEMPLATE: [Insert a real review only after permission and source verification]. Never invent a customer quote or rating.',tags:'review, permission',asset_url:'',rights_note:''}],
 inbox:[{id:1,campaign_id:1,campaign_name:'DEMO · Vietnam Family Discovery',channel:'Facebook',contact_name:'Sample family inquiry',email:'sample@example.invalid',phone:'',message:'Price for 6 people in December? We are interested in Danang.',reply_draft:'Thank you! Could you share your exact dates, number of adults/children and preferred hotel category?',status:'NEW',owner_user_id:1,owner_name:'Demo administrator',version_no:1,lead_request_id:null}],users:[{id:1,full_name:'Demo administrator'},{id:2,full_name:'Demo sales'}],publishing_enabled:false,inbox_sync_enabled:false,timezone:'Asia/Ho_Chi_Minh',limits:{campaigns:300,items:500,library:300,inbox:200}};
}
function reset(){data=seed();batches=new Map();}
function next(rows){return Math.max(0,...rows.map(x=>Number(x.id)))+1;}
function campaign(id){const c=data.campaigns.find(x=>Number(x.id)===Number(id));if(!c)throw Error('Campaign not found');return c;}
function create(row){const c=campaign(row.campaign_id),id=next(data.items);data.items.unshift({...copy(row),id,campaign_name:c.name,status:'DRAFT',creator_name:'Demo administrator'});return id;}
window.VTAMarketingDemo={reset,async request(route,opt={}){
 if(!data)reset();const b=opt.body||{};
 if(route==='marketing/studio'){data.counts=['DRAFT','PENDING','APPROVED','REJECTED'].map(status=>({status,total:data.items.filter(x=>x.status===status).length}));return copy(data);}
 if(opt.method!=='POST')throw Error('Demo route unavailable');
 if(route==='marketing/campaigns'){const id=next(data.campaigns);data.campaigns.unshift({...b,id,status:'ACTIVE',request_count:0,qualified_count:0,converted_count:0});return {id};}
 if(route==='marketing/content')return {id:create(b)};
 if(route==='marketing/batch'){
  if(!Array.isArray(b.entries)||!b.entries.length||b.entries.length>30)throw Error('Use 1–30 drafts');const hash=JSON.stringify(b.entries);
  if(batches.has(b.request_key)){const old=batches.get(b.request_key);if(old.hash!==hash)throw Error('Request key conflict');return {ids:old.ids,replayed:true};}
  b.entries.forEach(row=>campaign(row.campaign_id));const ids=b.entries.map(create);batches.set(b.request_key,{hash,ids});return {ids,replayed:false};
 }
 if(route==='marketing/library'){const id=next(data.library);data.library.unshift({...b,id});return {id};}
 if(['marketing/submit','marketing/decision'].includes(route)){
  const x=data.items.find(x=>Number(x.id)===Number(b.id));if(!x)throw Error('Content not found');
  if(route==='marketing/submit'&&x.status==='DRAFT')x.status='PENDING';else if(route==='marketing/decision'&&x.status==='PENDING'&&['APPROVED','REJECTED'].includes(b.decision))x.status=b.decision;else throw Error('Content status changed');return {id:x.id};
 }
 if(route==='marketing/inbox'){const c=campaign(b.campaign_id),id=next(data.inbox);data.inbox.unshift({...b,id,campaign_name:c.name,reply_draft:'',status:'NEW',version_no:1,lead_request_id:null,owner_name:data.users.find(x=>Number(x.id)===Number(b.owner_user_id))?.full_name||''});return {id};}
 const x=data.inbox.find(x=>Number(x.id)===Number(b.id));if(!x)throw Error('Conversation not found');
 if(route==='marketing/inbox-update'){if(x.version_no!==Number(b.version_no))throw Error('Conversation changed');Object.assign(x,b,{version_no:x.version_no+1,owner_name:data.users.find(u=>Number(u.id)===Number(b.owner_user_id))?.full_name||''});return {id:x.id};}
 if(route==='marketing/inbox-lead'){
  if(x.lead_request_id)return {id:x.id,request_id:x.lead_request_id,replayed:true};
  if((!x.email&&!x.phone)||Number(b.total_guests)<1||Number(b.paying_pax)<1||Number(b.paying_pax)+Number(b.foc)>Number(b.total_guests))throw Error('Valid contact and guest counts are required');
  x.lead_request_id=1000+x.id;x.status='FOLLOW_UP';x.version_no++;campaign(x.campaign_id).request_count++;return {id:x.id,request_id:x.lead_request_id};
 }
 throw Error('Demo action unavailable');
}};
})();

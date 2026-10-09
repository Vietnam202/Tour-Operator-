/* Explicitly labelled preview fixtures. This module never handles live requests. */
(function(){
  'use strict';
  if(!window.VTA_PREVIEW)return;
  const clone=value=>JSON.parse(JSON.stringify(value));
  const agencies=[{id:1,agency_name:'Demo Travel Agency',name:'Demo Travel Agency',brand_name:'Demo Travel',email:'sales@example.invalid',whatsapp:'',status:'ACTIVE'}];
  const publications=[{id:1,program_id:1,title:'Vietnam Discovery · Chương trình mẫu',version_no:1,version:1,status:'PUBLISHED',destination:'Hanoi · Halong',has_approved_net:false}];
  const requests=[{id:1,agency_id:1,agency_name:'Demo Travel Agency',departure_date:'2027-01-15',paying_pax:6,status:'NEW'}];
  window.VTAUnifiedDemo={handle:async function(api,route,options={}){
    if(!window.VTA_PREVIEW)return;
    const match=route.match(/^quote-versions\/(\d+)\/proposal$/);
    if(!match||Number(match[1])!==Number(api.qv.id))return;
    const locked=['SENT','CONFIRMED','SUPERSEDED'].includes(api.qv.version_status);
    const text=value=>({type:'paragraph',content:value?[{type:'text',text:String(value)}]:[]});
    const days=JSON.parse(api.qv.schedule_json||'[]').map((day,index)=>({...day,day_key:'demo-day-'+(index+1)}));
    if(!api.unifiedProposalSettings)api.unifiedProposalSettings={document:{schema:'VTA_DOC_2',title:api.qv.tour_name,content:{type:'doc',content:[{type:'heading',attrs:{level:1},content:[{type:'text',text:api.qv.tour_name}]},text('PREVIEW · Chương trình minh họa, không phải bản phát hành thật.'),...days.flatMap((day,index)=>[{type:'heading',attrs:{level:2},content:[{type:'text',text:'Day '+(index+1)+' · '+day.title}]},text(day.description),text('Meals: '+day.meals+' · Overnight: '+day.overnight)]),text(api.qv.included_text),text(api.qv.excluded_text),text(api.qv.terms_text)]}}};
    if(options.method==='PUT'){if(locked)throw Error('Issued preview quote is read-only.');if(Number(options.body.expected_revision)!==(api.unifiedProposalRevision||0))throw Error('STALE_REVISION');api.unifiedProposalSettings=clone(options.body.settings);api.unifiedProposalRevision=(api.unifiedProposalRevision||0)+1;}
    return{ok:true,settings:clone(api.unifiedProposalSettings),days,links:[],orphan_links:[],variant_choices:[],selling_options:[],immutable:locked,enabled:true,templates:[],costing_revision:api.unifiedProposalRevision||0,document_check:{errors:[],warnings:[]},requirements:[]};
  },request:async function(route,options={}){
    if(!window.VTA_PREVIEW)throw Error('Preview fixtures are unavailable in live mode');
    if(route==='b2b/me')return{ok:true,admin:true,agencies:clone(agencies)};
    if(route==='b2b/admin/agencies'&&options.method==='POST'){const name=String(options.body?.agency_name||'').trim();if(!name||name.length>190)throw Error('Tên đại lý chưa hợp lệ');const id=agencies.length+1;agencies.push({id,agency_name:name,name,brand_name:name,status:'ACTIVE'});return{ok:true,id};}
    if(route==='b2b/admin/agencies')return{ok:true,items:clone(agencies)};
    if(['b2b/admin/publications','b2b/tours'].includes(route))return{ok:true,items:clone(publications)};
    if(route==='b2b/admin/requests')return{ok:true,items:clone(requests)};
    throw Error('Unsupported preview route: '+route);
  }};
})();

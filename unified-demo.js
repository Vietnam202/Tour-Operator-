/* Explicitly labelled preview fixtures. This module never handles live requests. */
(function(){
  'use strict';
  if(!window.VTA_PREVIEW)return;
  const clone=value=>JSON.parse(JSON.stringify(value));
  window.VTAUnifiedDemo={handle:async function(api,route,options={}){
    if(!window.VTA_PREVIEW)return;
    const match=route.match(/^quote-versions\/(\d+)\/proposal$/);
    if(!match||Number(match[1])!==Number(api.qv.id))return;
    const locked=['SENT','CONFIRMED','SUPERSEDED'].includes(api.qv.version_status);
    const text=value=>({type:'paragraph',content:value?[{type:'text',text:String(value)}]:[]});
    const days=JSON.parse(api.qv.schedule_json||'[]').map((day,index)=>({...day,day_key:'demo-day-'+(index+1)}));
    if(!api.unifiedProposalSettings)api.unifiedProposalSettings={document:{schema:'VTA_DOC_2',title:api.qv.tour_name,content:{type:'doc',content:[{type:'heading',attrs:{level:1},content:[{type:'text',text:api.qv.tour_name}]},text('PREVIEW · Chương trình minh họa, không phải bản phát hành thật.'),...days.flatMap((day,index)=>[{type:'heading',attrs:{level:2},content:[{type:'text',text:'Day '+(index+1)+' · '+day.title}]},text(day.description),text('Meals: '+day.meals+' · Overnight: '+day.overnight)]),text(api.qv.included_text),text(api.qv.excluded_text),text(api.qv.terms_text)]}}};
    if(options.method==='PUT'){if(locked)throw Error('Issued preview quote is read-only.');api.unifiedProposalSettings=clone(options.body.settings);}
    return{ok:true,settings:clone(api.unifiedProposalSettings),days,links:[],orphan_links:[],variant_choices:[],selling_options:[],immutable:locked,enabled:true,templates:[],costing_revision:0,document_check:{errors:[],warnings:[]},requirements:[]};
  }};
})();

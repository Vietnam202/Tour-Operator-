(function(){'use strict';const stores=new WeakMap(),copy=x=>JSON.parse(JSON.stringify(x));
 window.VTAB2CDemo={async handle(api,route,opt={}){
  if(!window.VTA_PREVIEW)throw Error('B2C demo chỉ dùng trong preview.');
  const [path]=route.split('?');if(path!=='b2c'&&!path.startsWith('b2c/'))return api.request(route,opt);
  const can=p=>api.user?.permissions?.includes(p);if(!can('b2c.view'))throw Error('Không có quyền xem B2C.');
  if(!stores.has(api))stores.set(api,{rows:[],keys:new Map()});const s=stores.get(api),method=opt.method||'GET',b=opt.body||{};
  if(path==='b2c'&&method==='GET')return {ok:true,items:copy(s.rows.map(({document,...p})=>p))};
  if(path==='b2c'&&method==='POST'){
   if(!can('b2c.edit'))throw Error('Không có quyền sửa B2C.');const old=s.keys.get(b.creation_key);if(old){if(old.master_program_id!==b.master_program_id)throw Error('Creation key reused');return {ok:true,program:copy(old)};}
   const ctx=await api.request('tour-library/'+b.master_program_id+'/document');const row={id:s.rows.length+1,master_program_id:b.master_program_id,title:ctx.program.title,destination:ctx.program.destination,language:ctx.program.language,revision:1,status:'DRAFT',approved_revision:null,document:copy(ctx.settings.document)};s.rows.push(row);s.keys.set(b.creation_key,row);return {ok:true,program:copy(row)};
  }
  const m=path.match(/^b2c\/(\d+)(?:\/(document|review|approve|publish|withdraw|apply-to-document))?$/);const p=m&&s.rows.find(x=>x.id===Number(m[1]));if(!p)throw Error('Không tìm thấy B2C demo.');
  const ctx=()=>({ok:true,program:copy(p),settings:{document:copy(p.document)},links:[],days:[],selling_options:[],immutable:false,costing_revision:p.revision});
  if(method==='GET')return ctx();if(m[2]==='apply-to-document'){if(!can('quote.edit')||!can('proposal.edit')||b.program_revision!==p.revision)throw Error('B2C revision/permission mismatch');const q=await api.request('quote-versions/'+b.version_id+'/proposal');const settings={...q.settings,document:copy(p.document),template:'VTA_EXPLORER_B2C',channel:'B2C',brand_mode:'VTA'};return api.request('quote-versions/'+b.version_id+'/proposal',{method:'PUT',body:{expected_revision:b.expected_revision,settings,links:q.links}});}if(b.expected_revision!==p.revision)throw Error('STALE_REVISION');
  if(m[2]==='document'&&method==='PUT'){if(!can('b2c.edit'))throw Error('Không có quyền sửa B2C.');if(b.metadata){p.destination=b.metadata.destination;p.language=b.metadata.language;}p.document=copy(b.settings.document);p.title=p.document.title;p.revision++;p.status='DRAFT';p.approved_revision=null;return ctx();}
  if(m[2]==='review'){if(!can('b2c.edit')||!['DRAFT','REVIEW'].includes(p.status))throw Error('Không thể gửi duyệt.');p.status='REVIEW';}
  else if(['approve','publish','withdraw'].includes(m[2])){if(!can('b2c.approve'))throw Error('Không có quyền duyệt B2C.');if(m[2]==='approve'){if(!['REVIEW','APPROVED'].includes(p.status)||!b.public_content_reviewed)throw Error('Cần rà soát nội dung công khai.');p.status='APPROVED';p.approved_revision=p.revision;}if(m[2]==='publish'){if(!['APPROVED','PUBLISHED'].includes(p.status)||p.approved_revision!==p.revision)throw Error('Revision chưa duyệt.');p.status='PUBLISHED';}if(m[2]==='withdraw'){p.status='WITHDRAWN';p.approved_revision=null;}}
  else throw Error('Unsupported demo action');return {ok:true,program:copy(p)};
 }};
})();

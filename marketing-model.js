(()=>{'use strict';
const channels=['Facebook','Instagram','TikTok','YouTube Shorts','Google Ads','WhatsApp','Gmail','Threads','Google Business','WordPress','X','Pinterest'];
const topics=['Vietnam Destination','Vietnam Travel Tips','India → Vietnam','Vietnam Packages','Customer Reviews','Promotion / CTA','General'];
const formats=['POST','REEL_SCRIPT','VIDEO','AD_COPY','EMAIL','STORY'];
function localStamp(value){if(!value)return '';let s=String(value).replace(' ','T');if(!/(Z|[+-]\d\d:\d\d)$/i.test(s))s+='Z';const n=Date.parse(s);return Number.isFinite(n)?new Date(n+7*3600000).toISOString().slice(0,16):'';}
function utc(local){if(!local)return null;if(!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local))throw Error('Invalid date/time');const d=new Date(local+'+07:00');if(!Number.isFinite(d.getTime())||localStamp(d.toISOString())!==local)throw Error('Invalid date/time');return d.toISOString().replace('.000Z','Z');}
function url(value){if(!value)return '';try{const u=new URL(value);return u.protocol==='https:'&&!u.username&&!u.password?u.href:'';}catch{return '';}}
function plan({campaign_id,channels:chosen,body,topic='General',content_format='POST',tags='',asset_url='',rights_note='',start='',repeat=1,interval=7,parent_content_id=null}){
 chosen=[...new Set(chosen||[])];repeat=Number(repeat);interval=Number(interval);
 if(!Number.isInteger(Number(campaign_id))||Number(campaign_id)<1||!chosen.length||chosen.some(c=>!channels.includes(c)))throw Error('Choose a campaign and channels');
 if(!Number.isInteger(repeat)||repeat<1||repeat>8||chosen.length*repeat>30||!Number.isInteger(interval)||interval<1||interval>90)throw Error('Use 1–8 occurrences, 1–90 days apart, at most 30 drafts');
 if(!String(body).trim()||!topics.includes(topic)||!formats.includes(content_format))throw Error('Complete the content, topic and format');
 if(asset_url&&(!url(asset_url)||!rights_note.trim()))throw Error('Use an HTTPS asset link and describe usage permission');
 if(repeat>1&&!start)throw Error('Choose a start date for repeating drafts');
 const first=utc(start);const rows=[];
 for(let i=0;i<repeat;i++)for(const channel of chosen)rows.push({campaign_id:Number(campaign_id),channel,body,topic,content_format,tags,asset_url,rights_note,parent_content_id,planned_at:first?new Date(Date.parse(first)+i*interval*86400000).toISOString().replace('.000Z','Z'):null});
 return rows;
}
function key(){if(globalThis.crypto?.randomUUID)return crypto.randomUUID();const a=new Uint8Array(16);crypto.getRandomValues(a);return Array.from(a,x=>x.toString(16).padStart(2,'0')).join('');}
window.VTAMarketingModel={channels,topics,formats,localStamp,utc,url,plan,key,today:()=>localStamp(new Date().toISOString()).slice(0,10)};
})();

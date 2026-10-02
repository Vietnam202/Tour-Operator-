const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const path=require('node:path'),root=path.join(__dirname,'..');
for(const file of ['app.js','request.js','embed.js','service-worker.js','ai-chat.js','platform.js','marketing.js','marketing-model.js','marketing-demo.js','marketing-studio.js','landing-model.js','landing-extensions.js','landing-builder.js']){new vm.Script(fs.readFileSync(path.join(root,file),'utf8'));console.log('PASS JavaScript syntax '+file);}
const code=fs.readFileSync(path.join(root,'app.js'),'utf8'),start=code.indexOf('class ApiClient{'),end=code.indexOf('class DemoApi');
const calls=[];const context={window:{},URLSearchParams,fetch:async(url,options)=>{calls.push({url,options});return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({ok:true})}}};
vm.createContext(context);vm.runInContext(code.slice(start,end)+';this.Client=ApiClient;',context);
(async()=>{const client=new context.Client();client.user={csrf:'synthetic-token'};
 for(const route of ['v3/operations?date=2027-01-02','v3/operations&date=2027-01-02']){await client.request(route);const parsed=new URL(calls.at(-1).url,'http://localhost/');assert.equal(parsed.searchParams.get('route'),'v3/operations');assert.equal(parsed.searchParams.get('date'),'2027-01-02');assert.equal(calls.at(-1).options.credentials,'same-origin');}
 console.log('PASS query parameters preserved separately from API route');
 await client.request('invoices/1/commercial',{method:'POST',body:{issue_date:'2026-09-22'}});assert.equal(calls.at(-1).options.headers['X-VTA-CSRF'],'synthetic-token');assert.equal(JSON.parse(calls.at(-1).options.body).issue_date,'2026-09-22');console.log('PASS mutation sends CSRF token and JSON body');
 context.fetch=async()=>({ok:false,status:409,headers:{get:()=> 'application/json'},json:async()=>({message:'Immutable document'})});await assert.rejects(()=>client.request('invoices/1/commercial',{method:'POST',body:{}}),/Immutable document/);console.log('PASS API conflict is surfaced to UI');
})().catch(e=>{console.error(e);process.exitCode=1});

'use strict';
// Disposable PHP/MariaDB + browser-context HTTP fixture. Never targets a live URL.
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
const origin='http://127.0.0.1:18869';
if(process.env.VTA_V5_HTTP_URL!==origin||!process.env.VTA_V5_TEST_PASSWORD)
 throw Error('Refusing non-disposable staging URL or missing fixture credentials');
const zipScript=[
 'import io,sys,zipfile',
 'n=int(sys.argv[1]); mode=sys.argv[2]',
 'buf=io.BytesIO()',
 'with zipfile.ZipFile(buf,"w",compression=zipfile.ZIP_STORED) as z:',
 ' for i in range(n):',
 '  body=f"Day 1: Vietnam itinerary {i+1}\\nTransfer and hotel briefing {i+1}\\n" if mode!="duplicate" else "Day 1: Same Vietnam tour\\nSame text in two documents\\n"',
 '  name="../invalid.txt" if mode=="traversal" and i==n-1 else f"tour-{i+1:03d}.txt"',
 '  z.writestr(name,body)',
 'sys.stdout.buffer.write(buf.getvalue())'
].join('\n');
function zip(n,mode='normal'){return execFileSync('python3',['-c',zipScript,String(n),mode],{maxBuffer:2097152});}
async function asJSON(resp){const text=await resp.text();try{return JSON.parse(text);}catch{throw Error('HTTP '+resp.status()+' unexpected response '+text.slice(0,500));}}
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
  const context=await browser.newContext();
  const route=name=>origin+'/api/index.php?route='+encodeURIComponent(name);
  const login=await context.request.post(route('auth/login'),{data:{email:'tester@example.invalid',password:process.env.VTA_V5_TEST_PASSWORD}});
  assert.equal(login.status(),200,'Fixture Admin login required');
  const session=await asJSON(login),csrf=session.user.csrf;
  assert(csrf,'CSRF must be issued by authenticated server');
  const upload=buffer=>context.request.post(route('tour-library/preview-zip'),{
    headers:{'X-VTA-CSRF':csrf},
    multipart:{file:{name:'review-batch.zip',mimeType:'application/zip',buffer}}
  });
  const tooMany=await upload(zip(51));
  assert.equal(tooMany.status(),422,'51 source documents must be refused before staging');
  assert((await asJSON(tooMany)).message.includes('more than 50'));
  const traversal=await upload(zip(2,'traversal'));
  assert.equal(traversal.status(),422,'Unsafe archive path must fail before staging');
  const full=await upload(zip(50));
  const result=await asJSON(full);
  assert.equal(full.status(),200,JSON.stringify(result).slice(0,500));
  assert.equal(result.total,50);
  assert.equal(result.ready,50,'Whole 50-file batch stages after refused 51/unsafe ZIP (no leaked pending slots)');
  assert(result.requires_review);
  assert.equal(new Set(result.items.map(x=>x.preview?.import_token)).size,50,'50 independent private import tokens');
  console.log('PASS 51/unsafe preflight is atomic; exactly 50 reviewed TXT originals staged with private tokens');
  const ids=new Set();
  for(let i=0;i<result.items.length;i++){
    const p=result.items[i].preview;
    assert.equal(result.items[i].status,'READY');
    const body={...p,status:'DRAFT',creation_key:'synthetic-zip50-import-'+String(i).padStart(4,'0')};
    const saved=await context.request.post(route('tour-library'),{headers:{'X-VTA-CSRF':csrf},data:body});
    const record=await asJSON(saved);
    assert.equal(saved.status(),201,'Save #'+i+': '+JSON.stringify(record).slice(0,400));
    ids.add(Number(record.id));
    if(i===0){
      const replay=await context.request.post(route('tour-library'),{headers:{'X-VTA-CSRF':csrf},data:body});
      const repeated=await asJSON(replay);
      assert.equal(Number(repeated.id),Number(record.id));
      assert.equal(repeated.replayed,true,'Lost response retry must not create a second Master');
    }
  }
  assert.equal(ids.size,50,'50 independent Master program IDs saved');
  const first=[...ids][0];
  const loaded=await context.request.get(route('tour-library/'+first));
  const program=(await asJSON(loaded)).program;
  assert.equal(loaded.status(),200);assert(program.has_source);assert.equal(program.source_name,'tour-001.txt');
  const original=await context.request.get(route('tour-library/'+first+'/source'));
  assert.equal(original.status(),200);assert((await original.text()).includes('Vietnam itinerary 1'),'Original byte stream retained after save/reopen');
  const document=await context.request.post(route('tour-library/'+first+'/document/document-import-source'),{headers:{'X-VTA-CSRF':csrf},data:{}});
  assert.equal(document.status(),200,'Previously saved source can be reopened directly by Word document importer');
  assert((await asJSON(document)).text.includes('Vietnam itinerary 1'));
  console.log('PASS 50 independent draft Masters save/reopen; same-key retry, original download and Word source import');
  const duplicate=await upload(zip(2,'duplicate'));
  const dup=await asJSON(duplicate);
  assert.equal(duplicate.status(),200);
  assert.equal(dup.ready,1);
  assert.equal(dup.items[1].status,'ERROR');
  assert(dup.items[1].message.includes('Duplicate content'));
  console.log('PASS different filenames with identical binary do not silently create duplicate Master programs');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

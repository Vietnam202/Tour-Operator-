'use strict';
// EXACT localhost-only CI: real multipart ZIP → PHP TourLibrary → disposable
// MariaDB 50 saves/reopens + original binary checksums + duplicate-file refusal.
const assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),path=require('node:path');
const crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
const origin=process.env.VTA_V5_HTTP_URL;
if(origin!=='http://127.0.0.1:18869'||!process.env.VTA_V5_TEST_PASSWORD)throw Error('DISPOSABLE_HTTP_FIXTURE_REQUIRED');
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vta-zip50-'));
const endpoint=route=>origin+'/api/index.php?route='+encodeURIComponent(route);
const digest=data=>crypto.createHash('sha256').update(data).digest('hex');
const generate=String.raw`
$out=$argv[1];$mode=$argv[2];$dir=$argv[3];
$archive=new ZipArchive();if($archive->open($out,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP fixture unavailable');
$count=$mode==='50'?50:2;$original=null;
for($i=1;$i<=$count;$i++){
    $id=$mode==='duplicate'?1:$i;
    $xml='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.
      '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Vietnam Tour '.sprintf('%02d',$id).'</w:t></w:r></w:p>'.
      '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Best effort Word content</w:t></w:r></w:p>'.
      '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Day 1</w:t></w:r></w:p></w:tc>'.
      '<w:tc><w:p><w:r><w:t>Hanoi</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'.
      '</w:body></w:document>';
    if($mode==='duplicate'&&$original!==null){$bytes=$original;}
    else{$name=$dir.'/source-'.sprintf('%02d',$id).'.docx';$doc=new ZipArchive();
      if($doc->open($name,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('DOCX fixture unavailable');
      $doc->addFromString('word/document.xml',$xml);$doc->close();
      $bytes=file_get_contents($name);$original=$bytes;
    }
    $archive->addFromString('Tour-'.sprintf('%02d',$i).'.docx',$bytes);
}
$archive->close();
`;
async function json(route,{method='GET',cookie,csrf,body}={}){
 const headers={Accept:'application/json'};
 if(cookie)headers.Cookie=cookie;
 if(csrf)headers['X-VTA-CSRF']=csrf;
 if(body!==undefined)headers['Content-Type']='application/json';
 const r=await fetch(endpoint(route),{method,headers,redirect:'error',signal:AbortSignal.timeout(30000),body:body===undefined?undefined:JSON.stringify(body)});
 const data=await r.json();return {code:r.status,data,headers:r.headers};
}
async function upload(zip,cookie,csrf){
 const form=new FormData();
 form.append('file',new Blob([fs.readFileSync(zip)],{type:'application/zip'}),'synthetic-50.zip');
 const r=await fetch(endpoint('tour-library/preview-zip'),{method:'POST',
   headers:{Cookie:cookie,'X-VTA-CSRF':csrf},body:form,signal:AbortSignal.timeout(30000),redirect:'error'});
 return {code:r.status,data:await r.json()};
}
(async()=>{
 const logged=await json('auth/login',{method:'POST',body:{email:'tester@example.invalid',password:process.env.VTA_V5_TEST_PASSWORD}});
 assert.equal(logged.code,200);
 const cookie=logged.headers.get('set-cookie')?.split(';')[0],csrf=logged.data.user?.csrf;
 assert(cookie&&csrf,'Synthetic login cookie/CSRF required');
 const zip=path.join(tmp,'batch50.zip');
 execFileSync('php',['-r',generate,zip,'50',tmp],{timeout:15000});
 const batch=await upload(zip,cookie,csrf);
 assert.equal(batch.code,200,'50-file upload failed: '+JSON.stringify(batch.data).slice(0,600));
 assert.equal(batch.data.total,50);assert.equal(batch.data.ready,50);
 assert.equal(batch.data.items.length,50);
 console.log('PASS 50 distinct DOCX files previewed from a real authenticated multipart ZIP');
 const ids=[];
 for(let i=0;i<batch.data.items.length;i++){
    const row=batch.data.items[i],p=row.preview;
    assert.equal(row.status,'READY');
    assert.match(p.import_token,/^[a-f0-9]{64}$/);
    assert.equal(p.status,'DRAFT');
    assert.equal(p.has_source,true);
    assert.equal(p.proposal.document.schema,'VTA_DOC_2','Missing typed Word canvas at '+i);
    assert(p.proposal.document.content.content.some(x=>x.type==='table'),'Word table omitted at '+i);
    assert(!JSON.stringify(p).includes('base64'),'Never expose embedded media bytes in preview');
    const key='bulk50-xml-fixture-'+String(i+1).padStart(4,'0');
    const saved=await json('tour-library',{method:'POST',cookie,csrf,body:{...p,creation_key:key}});
    assert.equal(saved.code,201,'Save failed '+i+': '+JSON.stringify(saved.data).slice(0,300));
    const id=Number(saved.data.id);assert(id>0&&!ids.includes(id),'Duplicate program id '+i);ids.push(id);
    const reopened=await json('tour-library/'+id,{cookie});
    assert.equal(reopened.code,200);
    assert.equal(reopened.data.program.status,'DRAFT');
    assert.equal(reopened.data.program.source_name,row.name);
    assert.equal(reopened.data.program.has_source,true);
    assert.deepEqual(reopened.data.program.proposal.document,p.proposal.document,'Word content changed on Save/Reopen '+i);
    // Exact original Word binary is stored separately, not replaced by text extraction.
    if(i===0||i===49){
      const download=await fetch(endpoint('tour-library/'+id+'/source'),{headers:{Cookie:cookie},signal:AbortSignal.timeout(10000)});
      assert.equal(download.status,200);
      const bytes=Buffer.from(await download.arrayBuffer());
      assert.equal(digest(bytes),digest(fs.readFileSync(path.join(tmp,'source-'+String(i+1).padStart(2,'0')+'.docx'))));
    }
 }
 assert.equal(ids.length,50);
 console.log('PASS 50 DRAFT programs saved/reopened on real disposable MariaDB; first/last DOCX original checksum identical');
 const duplicate=path.join(tmp,'renamed-duplicates.zip');
 execFileSync('php',['-r',generate,duplicate,'duplicate',tmp],{timeout:15000});
 const conflicts=await upload(duplicate,cookie,csrf);
 assert.equal(conflicts.code,200);
 assert.equal(conflicts.data.total,2);
 assert.equal(conflicts.data.ready,1);
 assert(conflicts.data.items.some(x=>x.status==='ERROR'&&x.message.includes('Duplicate document bytes')),
   'Renamed same-content DOCX must not create a second staging token');
 console.log('PASS ZIP renamed duplicate bytes detected; one staged original, second rejected');
 const foreign=await json('auth/login',{method:'POST',body:{email:'other@example.invalid',password:process.env.VTA_V5_TEST_PASSWORD}});
 assert.equal(foreign.code,200);
 const foreignCookie=foreign.headers.get('set-cookie')?.split(';')[0];
 assert.equal((await json('tour-library/'+ids[0],{cookie:foreignCookie})).code,404);
 console.log('PASS second company cannot fetch imported Word program');
})().catch(e=>{console.error('FAIL ZIP50 HTTP fixture:',e);process.exitCode=1;})
.finally(()=>{for(const f of fs.readdirSync(tmp))fs.unlinkSync(path.join(tmp,f));fs.rmdirSync(tmp);});

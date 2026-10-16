'use strict';
const fs=require('node:fs'),http=require('node:http'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const html=fs.readFileSync(path.resolve(__dirname,'../b2b-partner-edition.html'));
const DATA={ok:true,schema:'VTA_B2B_PARTNER_V67_PUBLIC_PREVIEW',
 tour:{id:19,publication_version:2,content:{title:'Approved Vietnam Journey for B2B',destination:'Hanoi → Ha Long → Da Nang',tour_code:'TEST-B2B-19',tour_type:'SIC',
 overview:'Approved itinerary description',days:Array.from({length:7},(_,i)=>({title:'Approved day '+(i+1),description:'Detailed agency itinerary '+(i+1),meals:'B/L',overnight:'Hanoi'})),
 hotels:[{destination:'Hanoi',three:'Three Star Verified',four:'Four Star Verified',five:'Five Star Verified'}],
 included_text:'Airport transfer\nGuide',excluded_text:'Flights',terms_text:'Availability pending',policies:{payment:'Terms pending'}}},
 agency:{id:8,name:'Approved Partner',brand_name:'Approved Partner Ltd',email:'test@example.invalid',whatsapp:'+84000000000',has_logo:false}};
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://127.0.0.1');
 if(u.pathname==='/b2b-partner-edition.html'){res.writeHead(200,{'content-type':'text/html; charset=utf-8'});return res.end(html);}
 if(u.pathname==='/api/index.php'){
  if(u.searchParams.get('route')==='b2b/my-tours/19/v67-preview'){res.writeHead(200,{'content-type':'application/json','cache-control':'no-store'});return res.end(JSON.stringify(DATA));}
  res.writeHead(403,{'content-type':'application/json'});return res.end(JSON.stringify({ok:false,error:'FORBIDDEN'}));
 }
 res.writeHead(404);res.end('missing');
});
(async()=>{
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const base='http://127.0.0.1:'+server.address().port, browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
  const modes=['detailed','brochure','quick','b2b'];
  for(const width of [1366,390]){
   const page=await browser.newPage({viewport:{width,height:875},acceptDownloads:true});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base+'/b2b-partner-edition.html?tour_id=19');
   await page.locator('.partner-editor-grid').waitFor({state:'visible',timeout:20000});
   assert.equal(await page.locator('#in-layout').inputValue(),'b2b','owner-approved B2B template is default');
   await page.locator('#in-price3').fill('799');
   await page.locator('#in-price4').fill('999');
   await page.locator('#in-price5').fill('1399');
   await page.locator('#in-whatsapp').fill('+8412345678');
   assert.equal(await page.locator('#in-photo-day option').count(),8,'day photo picker + initial option');
   for(const mode of modes){
    await page.locator('#in-layout').selectOption(mode);
    const doc=page.locator('#partner-print-area .vta-document');
    assert.equal(await doc.getAttribute('data-active-template'),mode);
    assert.equal(await doc.locator('[data-v67=days] .vta-b2b-day').count(),7,'all seven days in '+mode);
    assert.match(await doc.locator('[data-v67=hotels]').innerText(),/Four Star Verified/,'hotels always retained');
    assert.match(await doc.locator('[data-price-tier="3"]').innerText(),/799 USD/,'selling price always retained');
    assert.match(await doc.locator('[data-price-tier="5"]').innerText(),/1,399 USD/,'fifth tier retained');
    assert.match(await doc.locator('#partner-contact-details').innerText(),/\+8412345678/);
    assert.equal(await page.locator('#in-price4').inputValue(),'999','switch did not reset price');
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+5);
    assert(!overflow,'layout '+mode+' overflow at '+width);
    await page.screenshot({path:'vta-four-'+mode+'-'+width+'.png',fullPage:true});
    await page.emulateMedia({media:'print'});
    assert.equal(await page.locator('.partner-editor').evaluate(x=>getComputedStyle(x).display),'none','print must hide editor');
    if(width===1366){
      const pdf=await page.pdf({format:'A4',printBackground:true,preferCSSPageSize:true});
      assert.equal(pdf.subarray(0,5).toString(),'%PDF-');
      assert(pdf.length>12000,'real A4 PDF must not be empty for '+mode);
      if(mode==='brochure')fs.writeFileSync('vta-four-brochure-reference.pdf',pdf);
    }
    await page.emulateMedia({media:'screen'});
   }
   // Brochure author can use only photos for which usage rights were acknowledged.
   await page.locator('#in-layout').selectOption('brochure');
   const photo=await page.evaluate(()=>{
      const canvas=document.createElement('canvas');canvas.width=160;canvas.height=100;let c=canvas.getContext('2d');
      c.fillStyle='#517c94';c.fillRect(0,0,160,100);c.fillStyle='white';c.fillRect(20,20,50,50);
      return canvas.toDataURL('image/png').split(',')[1];
   });
   const image={name:'licensed-test.png',mimeType:'image/png',buffer:Buffer.from(photo,'base64')};
   await page.locator('#in-cover').setInputFiles(image);
   await page.locator('#partner-status').waitFor({state:'visible'});
   assert.match(await page.locator('#partner-status').innerText(),/permission|confirm image usage rights/i);
   await page.locator('#in-media-rights').check();
   await page.locator('#in-cover').setInputFiles(image);
   await page.waitForFunction(()=>getComputedStyle(document.querySelector('.vta-b2b-title')).backgroundImage.includes('data:image'));
   await page.locator('#in-photo-day').selectOption('1');
   await page.locator('#in-day-photo').setInputFiles(image);
   await page.locator('[data-v67=days] .vta-day-photo').first().waitFor({state:'visible'});
   assert.match(await page.locator('[data-v67=days] .vta-day-photo').first().getAttribute('src'),/^data:image\/jpeg;base64,/);
   const [download]=await Promise.all([page.waitForEvent('download'),page.locator('#partner-save-btn').click()]);
   const file=path.join(os.tmpdir(),'four-template-'+width+'-'+Date.now()+'.html');await download.saveAs(file);
   const saved=fs.readFileSync(file,'utf8');
   assert(!saved.includes('id="vta67-loader"'),'must work offline without auth loader');
   assert(saved.includes('dayPhotos'),'day image map should be persisted in edited HTML');
   const offline=await browser.newPage({viewport:{width,height:875}});
   await offline.goto('file://'+file);
   assert.equal(await offline.locator('#in-layout').inputValue(),'brochure','saved selected template');
   assert.equal(await offline.locator('#in-price4').inputValue(),'999','saved pricing');
   assert.match(await offline.locator('.vta-b2b-title').evaluate(x=>getComputedStyle(x).backgroundImage),/data:image/,'saved cover');
   await offline.locator('#in-layout').selectOption('quick');
   assert.equal(await offline.locator('[data-v67=days] .vta-b2b-day').count(),7,'offline switch retains itinerary');
   await offline.close();fs.unlinkSync(file);
   assert.deepEqual(errors,[],'no runtime JavaScript errors');
   await page.close();
   console.log('PASS '+width+' · four layouts, hotels, all prices, responsive, export A4, cover/day photography rights and saved offline HTML');
  }
  const forbidden=await browser.newPage();
  await forbidden.goto(base+'/b2b-partner-edition.html?tour_id=998');
  await forbidden.locator('.partner-help[role=alert]').waitFor();
  assert.equal(await forbidden.locator('.partner-editor-grid').evaluate(x=>getComputedStyle(x).visibility),'hidden');
  assert(!await forbidden.locator('body').innerText().then(t=>t.includes('Approved Vietnam Journey for B2B')));
  await forbidden.close();
  console.log('PASS unauthorized working-copy ID fail-closed');
 }finally{await browser.close();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);process.exitCode=1;server.close();});

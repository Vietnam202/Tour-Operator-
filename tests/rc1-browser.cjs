(function testMain(){
const fs=require('fs'),assert=require('node:assert/strict'),{chromium}=require('playwright'),{PDFDocument}=require('pdf-lib'),JSZip=require('jszip');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.VTA_TEST_CHROME||undefined});const errors=[];
 try{
 const page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8873');await page.locator('input[type=email]').fill('admin@example.invalid');await page.locator('input[type=password]').fill('Local-Http-Test-2026');await page.getByRole('button',{name:'Sign In',exact:true}).click();await page.waitForSelector('#workspace');
 const api=page.context().request,base='http://127.0.0.1:8873/api/?route=';
 const me=await (await api.get(base+'auth/me')).json(),token=me.user.csrf;
 async function call(route,method='GET',data){const res=await api.fetch(base+encodeURIComponent(route),{method,data,headers:{'X-VTA-CSRF':token}});const json=await res.json();assert(res.ok(),route+' '+res.status()+' '+JSON.stringify(json));return json}
 const pass=s=>console.log('PASS '+s);
 const inquiry=await call('inquiries','POST',{lead_contact_name:'RC1 Browser Source',lead_email:'source@example.invalid',title:'RC1 browser test',adults:5,total_guests:6,paying_pax:5,foc:1,start_date:'2027-01-02',end_date:'2027-01-05'});
 const target=await call('inquiries','POST',{lead_contact_name:'RC1 Browser Target',lead_email:'target@example.invalid',title:'RC1 target',adults:3,total_guests:4,paying_pax:3,foc:1,start_date:'2027-01-12',end_date:'2027-01-15'});
 const quote=await call('inquiries/'+inquiry.id+'/create-quote','POST',{});let data=await call('quotes/'+quote.id),vid=data.version.id;
 await page.locator('[data-nav=sales]').first().click();await page.locator('[data-sales-tab="quotes"]').click();await page.locator('[data-open-quote="'+quote.id+'"]').click();await page.waitForSelector('#quoteInfo');
 await page.locator('[name=document_language]').selectOption('vi');await page.locator('#saveQuoteInfo').click();await page.waitForSelector('#daysWrap');
 await page.locator('#addDay').click();await page.locator('[data-day-index="0"] [data-key=title]').fill('Unsaved Hanoi');await page.locator('[data-day-index="0"] [data-key=description]').fill('Full first paragraph.\nFull second paragraph.');
 await page.locator('#vtaLanguage').selectOption('vi');await page.waitForFunction(()=>document.documentElement.lang==='vi');assert.equal(await page.locator('[data-key=title]').inputValue(),'Unsaved Hanoi');assert.match(await page.locator('#addDay').innerText(),/Thêm ngày/);
 await page.locator('#vtaLanguage').selectOption('en');await page.waitForFunction(()=>document.documentElement.lang==='en');assert.equal(await page.locator('#addDay').innerText(),'Add Day');pass('EN/VI toggle preserves unsaved input and returns to English');
 await page.locator('[data-op=duplicate]').click();assert.equal(await page.locator('[data-day-index="1"] [data-key=title]').inputValue(),'Unsaved Hanoi');
 await page.locator('[data-day-index="1"] [data-key=title]').fill('Second day');await page.locator('[data-op=up][data-index="1"]').click();assert.equal(await page.locator('[data-day-index="0"] [data-key=title]').inputValue(),'Second day');
 await page.locator('[data-op=delete][data-index="0"]').click();assert.equal(await page.locator('[data-key=description]').inputValue(),'Full first paragraph.\nFull second paragraph.');pass('duplicate reorder delete retain current form edits');
 await page.locator('#pasteSchedule').click();await page.locator('#schedulePaste').fill('Ngày 1: Hà Nội\nĐón khách.\nTham quan phố cổ.\nBữa ăn: B/L\nNghỉ đêm: Hà Nội\nDay 2: Ha Long\nCruise excursion.\nMeals: B/L/D\nOvernight: Cruise');
 await page.locator('#extractDays').click();await page.locator('#applyPreview').waitFor({state:'visible'});await page.waitForFunction(()=>!document.querySelector('#applyPreview').disabled);
 assert.equal(JSON.parse((await call('quotes/'+quote.id)).version.schedule_json).length,0);pass('import preview has no database side effect');
 await page.locator('#importMode').selectOption('replace');await page.locator('#applyPreview').click();await page.locator('#saveSchedule').click();await page.waitForSelector('#addCost');
 data=await call('quotes/'+quote.id);assert.equal(JSON.parse(data.version.schedule_json)[0].overnight,'Hà Nội');assert.match(JSON.parse(data.version.schedule_json)[0].description,/Tham quan phố cổ/);pass('paste preview applies full description meals overnight and saves');
 await page.locator('#addCost').click();assert.equal(await page.locator('#costForm [name=pax]').inputValue(),'5');
 await page.locator('#costForm [name=service_name]').fill('Lunch');await page.locator('#costForm [name=unit_price]').fill('10');await page.locator('#costForm [name=currency]').selectOption('USD');await page.locator('#costForm [name=notes]').fill('Reviewed supplier price');
 await page.locator('#saveCost').click();await page.waitForSelector('[data-cost]');let row=page.locator('[data-cost]').first();await row.locator('[data-key=qty]').fill('2');await row.locator('[data-save-cost]').click();await page.waitForResponse(r=>r.url().includes('route=quotes%2F')&&r.request().method()==='GET');
 data=await call('quotes/'+quote.id);assert.equal(Number(data.cost_items[0].total),100);pass('cost default paying pax and inline Qty edit persist and reprice');
 await page.locator('[data-duplicate-cost]').first().click();await page.waitForFunction(()=>document.querySelectorAll('[data-cost]').length===2);
 data=await call('quotes/'+quote.id);assert.equal(Number(data.cost_items[1].review_required),1);pass('quick duplicate marks cost review-required');
 await page.locator('#pasteCost').click();await page.locator('#costPaste').fill('Snack\t\t1\t2\tUSD\tMEAL\t2027-01-02\tReviewed snack\nWater\t3\t2\t1\tUSD\tMEAL\t2027-01-02\tReviewed water');
 await page.locator('#previewCosts').click();await page.locator('#saveCosts').click();await page.waitForFunction(()=>document.querySelectorAll('[data-cost]').length===4);data=await call('quotes/'+quote.id);assert.equal(Number(data.cost_items[2].pax),5);pass('Excel paste previews and atomically saves multiple rows');
 // Upload all three formats through the actual multipart endpoint.
 const txt=Buffer.from('Day 1: TXT arrival\nDetailed TXT paragraph.\nMeals: B\nOvernight: Hanoi');
 const zip=new JSZip();zip.file('word/document.xml','<w:document xmlns:w="urn:test"><w:body><w:p><w:r><w:t>Day 1: DOCX arrival</w:t></w:r></w:p><w:p><w:r><w:t>Detailed DOCX paragraph.</w:t></w:r></w:p><w:p><w:r><w:t>Overnight: Hanoi</w:t></w:r></w:p></w:body></w:document>');
 const docx=await zip.generateAsync({type:'nodebuffer'});
 const pdf=await PDFDocument.create(),p=pdf.addPage();p.drawText('Day 1: PDF arrival\nDetailed PDF paragraph.\nMeals: B/L\nOvernight: Hanoi',{x:40,y:700,size:12});const pdfbytes=Buffer.from(await pdf.save());
 for(const [name,mime,buffer] of [['program.txt','text/plain',txt],['program.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',docx],['program.pdf','application/pdf',pdfbytes]]){
  const res=await api.post(base+'schedule%2Fpreview',{headers:{'X-VTA-CSRF':token},multipart:{start_date:'2027-01-02',file:{name,mimeType:mime,buffer}}});const out=await res.json();assert(res.ok(),name+' '+JSON.stringify(out));assert.equal(out.status,'DRAFT');assert.match(out.days[0].description,/Detailed/);assert.equal(out.days[0].overnight,'Hanoi');pass(name+' multipart upload extracts complete preview');
 }
 await page.locator('[data-qstep=schedule]').click();await page.locator('#uploadSchedule').click();await page.locator('#scheduleFile').setInputFiles({name:'program.docx',mimeType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document',buffer:docx});await page.locator('#extractDays').click();await page.waitForFunction(()=>!document.querySelector('#applyPreview').disabled);
 await page.locator('#importMode').selectOption('replace');await page.locator('#applyPreview').click();await page.locator('#saveSchedule').click();await page.waitForSelector('#addCost');pass('upload button uses DRAFT preview before applying and saving');
 // Reject malformed input without changing the saved quote.
 const bad=await api.post(base+'schedule%2Fpreview',{headers:{'X-VTA-CSRF':token},data:{text:'not an itinerary'}});assert.equal(bad.status(),422);
 const savedBefore=await call('quotes/'+quote.id);
 const approval=await api.post(base+'quotes%2F'+quote.id+'%2Fapprove',{headers:{'X-VTA-CSRF':token},data:{}});assert.equal(approval.status(),409);pass('approval blocked while copied rates still need review');
 await page.getByRole('button',{name:'Clone & Reuse',exact:true}).click();await page.locator('#reuseInquiry').selectOption(String(target.id));await page.locator('#reuseMode').selectOption('FULL_DRAFT');
 const cloneResponse=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('%2Freuse'));await page.locator('#reuseSave').click();const clone=await (await cloneResponse).json();assert(clone.ok);await page.waitForSelector('#quoteInfo');const cloned=await call('quotes/'+clone.id);assert.equal(cloned.version.version_status,'DRAFT');assert.equal(cloned.trip.lead_email,'target@example.invalid');assert(cloned.cost_items.every(c=>Number(c.review_required)===1&&!c.rate_version_id));pass('Full Quote Draft UI creates safe target clone with mandatory rate review');
 for(const mode of ['PROGRAM_ONLY','PROGRAM_COST']){
  const c=await call('quote-versions/'+vid+'/reuse','POST',{inquiry_id:target.id,mode});const q=await call('quotes/'+c.id);assert.equal(q.version.version_status,'DRAFT');if(mode==='PROGRAM_ONLY')assert.equal(q.cost_items.length,0);else assert(q.cost_items.every(x=>Number(x.unit_price)===0&&Number(x.review_required)===1));pass(mode+' API behavior');
 }
 assert.deepEqual((await call('quotes/'+quote.id)).version,savedBefore.version);pass('reuse leaves source content unchanged');
 await page.getByRole('button',{name:'3* / 4* / 5* Options',exact:true}).click();await page.locator('#newQuoteOption').click();await page.locator('#optionAddManual').click();await page.waitForSelector('#manualOptionLine');
 assert.equal(await page.locator('#manualOptionLine [name=pax]').inputValue(),'3');await page.locator('#manualOptionLine [name=charge_basis]').selectOption('PER_VEHICLE');assert.equal(await page.locator('#manualOptionLine [name=pax]').inputValue(),'1');await page.locator('#manualOptionLine [name=charge_basis]').selectOption('PAYING_PAX');
 await page.locator('#manualOptionLine [name=service_name]').fill('Option lunch');await page.locator('#manualOptionLine [name=unit_price]').fill('5');await page.locator('#manualOptionLine [name=reason]').fill('Reviewed current-date supplier cost');await page.locator('#manualOptionLine button').click();
 await page.locator('[data-option-row="0"] [data-key=qty]').fill('2');await page.locator('[data-copy-line]').click();await page.locator('#reviewOptionCopied').check();await page.locator('#reviewOptionReason').fill('Both copied lines checked for target dates');await page.locator('#optionForm button[type=submit], #optionForm button:not([type])').last().click();
 await page.waitForSelector('#newQuoteOption');const opt=await call('quote-versions/'+clone.version_id+'/options');assert.equal(opt.items[0].snapshot.lines.length,2);assert.equal(Number(opt.items[0].snapshot.lines[0].pax),3);assert.equal(Number(opt.items[0].snapshot.pricing.cost_total),60);pass('option editor defaults pax by basis, edits and duplicates rows, saves reviewed prices');
 assert.deepEqual(errors,[]);pass('no browser JavaScript exceptions');
 await page.screenshot({path:'work/rc1-browser.png',fullPage:true});
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
})();


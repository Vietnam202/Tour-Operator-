<?php
declare(strict_types=1);
require __DIR__.'/lead-hub.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/QuoteOptions.php';
require_once __DIR__.'/../api/lib/QuoteReuse.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
require_once __DIR__.'/../api/lib/QuoteExport.php';
$text="Vietnam program\nNgày 1: Hà Nội (B/L)\nĐón khách tại sân bay.\nTham quan phố cổ.\nBữa ăn: B/L\nNghỉ đêm: Hà Nội\n\nDay 2 — Ha Long\nCruise and cave visit.\nMeals: B/L/D\nOvernight: Cruise";
$preview=ScheduleImport::parse($text,'2027-01-02');
check($preview['status']==='DRAFT'&&count($preview['days'])===2,'bilingual headings produce DRAFT preview');
check(str_contains($preview['days'][0]['description'],'Tham quan phố cổ.')&&$preview['days'][0]['overnight']==='Hà Nội'&&$preview['days'][1]['meals']==='B/L/D','full description meals overnight retained');
check($preview['days'][1]['date']==='2027-01-03'&&$preview['source_text']===$text,'source retained and dates timezone independent');
rejects(fn()=>ScheduleImport::parse('No headings'),InvalidArgumentException::class,'unrecognized input cannot silently replace schedule');
rejects(fn()=>ScheduleImport::normalize([['date'=>'2027-02-31']]),InvalidArgumentException::class,'invalid dates rejected');
$tmp=tempnam(sys_get_temp_dir(),'vta');file_put_contents($tmp,$text);$plain=DocumentParser::extract($tmp,'txt');check(ScheduleImport::parse($plain['text'])['days'][0]['title']==='Hà Nội (B/L)','TXT extraction');
if(!class_exists('ZipArchive'))throw new RuntimeException('Enable PHP zip for DOCX smoke test');
$zip=new ZipArchive();$zip->open($tmp,ZipArchive::OVERWRITE);$zip->addFromString('word/document.xml','<w:document xmlns:w="urn:test"><w:body><w:p><w:r><w:t>Day 1: Arrival</w:t></w:r></w:p><w:p><w:r><w:t>Full description.</w:t></w:r></w:p><w:p><w:r><w:t>Meals: B/L</w:t></w:r></w:p></w:body></w:document>');$zip->close();
$docx=DocumentParser::extract($tmp,'docx');check(ScheduleImport::parse($docx['text'])['days'][0]['description']==="Full description.\nMeals: B/L",'DOCX full paragraphs preserved');unlink($tmp);
$db->exec("UPDATE quote_versions SET version_status='DRAFT',start_date='2027-01-02',end_date='2027-01-03',paying_pax=10,total_guests=11,foc=1,fx_rate=25000 WHERE id=$qvid");
$base=['service_name'=>'Lunch','category'=>'MEAL','qty'=>2,'unit_price'=>12,'currency'=>'USD','notes'=>'Supplier reviewed for these dates','supplier_id'=>1];
$item=QuoteCostItems::write($db,$user,$qvid,$base);$id=$item['id'];$row=$db->query("SELECT * FROM quote_cost_items WHERE id=$id")->fetch();
check((float)$row['pax']===10.0&&(float)$row['total']===240.0,'new cost defaults to paying pax and Pax x Qty x Unit');
$edit=QuoteCostItems::write($db,$user,$qvid,['id'=>$id,'qty'=>3]);$row=$db->query("SELECT * FROM quote_cost_items WHERE id=$id")->fetch();
check((float)$row['pax']===10.0&&(float)$row['total']===360.0&&$row['service_name']==='Lunch','partial inline edit preserves omitted fields and reprices');
$group=QuoteCostItems::write($db,$user,$qvid,$base+['charge_basis'=>'PER_VEHICLE']);check((float)$db->query('SELECT pax FROM quote_cost_items WHERE id='.$group['id'])->fetchColumn()===1.0,'vehicle basis defaults to one');
$dup=QuoteCostItems::duplicate($db,$user,$qvid,$id);check((int)$db->query('SELECT review_required FROM quote_cost_items WHERE id='.$dup['id'])->fetchColumn()===1,'duplicate requires rate review');
rejects(fn()=>QuoteCostItems::assertReviewed($db,$qvid),DomainException::class,'unreviewed copy blocks approval');
QuoteCostItems::write($db,$user,$qvid,['id'=>$dup['id'],'reviewed'=>true,'notes'=>'Supplier reconfirmed current dates']);QuoteCostItems::assertReviewed($db,$qvid);
$before=(int)$db->query('SELECT COUNT(*) FROM quote_cost_items')->fetchColumn();
rejects(fn()=>QuoteCostItems::bulk($db,$user,$qvid,[$base,['service_name'=>'Bad','pax'=>-1]]),InvalidArgumentException::class,'bulk invalid row rejected');
check((int)$db->query('SELECT COUNT(*) FROM quote_cost_items')->fetchColumn()===$before,'bulk failure rolls back all rows');
QuoteCostItems::bulk($db,$user,$qvid,[$base,$base]);check((int)$db->query('SELECT COUNT(*) FROM quote_cost_items')->fetchColumn()===$before+2,'bulk rows committed together');
// Exercise the actual Info/Schedule save service inside a transaction, then read back.
$save=new ReflectionMethod(CoreOS::class,'saveQuotePatch');
$db->beginTransaction();$saved=$save->invoke(null,$db,['id'=>$qvid],['schedule'=>$preview['days'],'document_language'=>'vi'],1);$db->commit();
check(json_decode($saved['schedule_json'],true)[0]['description']===$preview['days'][0]['description']&&$saved['document_language']==='vi','save and refresh preserve description and document language');
// New target contains only its own customer identity.
$targetBody=$body;$targetBody['submission_key']='rc1-new-target-0001';$targetBody['contact_name']='New Customer';$targetBody['email']='new@example.invalid';$targetBody['travel_date']='2027-01-10';$targetBody['total_guests']=6;$targetBody['paying_pax']=5;$targetBody['foc']=1;
$db->exec("UPDATE lead_forms SET status='ACTIVE' WHERE id=1");LeadHub::submit($db,$token,$targetBody);
$requestId=(int)$db->query("SELECT id FROM lead_requests WHERE submission_key='rc1-new-target-0001'")->fetchColumn();
$lead=fixtureQualifyLead($db,$user,$requestId,'New target confirmed');$newTarget=fixtureConvertLead($db,$user,$lead['id']);
$db->prepare("UPDATE trips SET end_date='2027-01-11' WHERE id=?")->execute([$newTarget['trip_id']]);
$db->exec("UPDATE quote_versions SET version_status='SENT',sent_snapshot_json='{\"immutable\":true}' WHERE id=$qvid");
$sourceBefore=$db->query("SELECT * FROM quote_versions WHERE id=$qvid")->fetch();
$forbidden=['bookings','supplier_orders','guests','customer_invoices','customer_payments'];$counts=[];
foreach($forbidden as $table)$counts[$table]=(int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
foreach(['PROGRAM_ONLY','PROGRAM_COST','FULL_DRAFT'] as $mode){
 $clone=QuoteReuse::clone($db,$user,$qvid,$newTarget['inquiry_id'],$mode);$cv=QuoteOptions::version($db,1,$clone['version_id']);
 check($cv['version_status']==='DRAFT'&&$cv['sent_snapshot_json']===null&&$cv['approved_by']===null,'clone '.$mode.' resets status and issuance');
 check((int)$cv['paying_pax']===5&&$cv['lead_email']==='new@example.invalid'&&$cv['proposal_json']==='{}','clone '.$mode.' uses target identity/pax without old proposal');
 $days=json_decode($cv['schedule_json'],true);check($days[0]['date']==='2027-01-10'&&!isset($days[0]['notes']),'clone '.$mode.' rebases dates and drops private notes');
 $costs=$db->query('SELECT * FROM quote_cost_items WHERE quote_version_id='.$cv['id'])->fetchAll();
 if($mode==='PROGRAM_ONLY')check(!$costs,'program-only has no costs');
 else{check(count($costs)>0&&count(array_filter($costs,fn($c)=>(int)$c['review_required']===1&&$c['rate_version_id']===null))===count($costs),'copied costs are never approved rates');check((float)$costs[0]['unit_price']===($mode==='FULL_DRAFT'?12.0:0.0),'cost structure clears amounts; full draft retains reviewable estimates');}
}
foreach($counts as $table=>$count)check((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()===$count,'clone does not copy '.$table);
check($db->query("SELECT * FROM quote_versions WHERE id=$qvid")->fetch()===$sourceBefore,'clone leaves sent source byte-for-byte unchanged');
rejects(fn()=>QuoteReuse::clone($db,['company_id'=>2,'id'=>2],$qvid,$newTarget['inquiry_id'],'FULL_DRAFT'),OutOfBoundsException::class,'cross-company reuse blocked');
rejects(fn()=>QuoteCostItems::write($db,$user,$qvid,['id'=>$id,'qty'=>99]),DomainException::class,'issued cost inline edit blocked');
// Approved rate refresh is revalidated against travel-date matching.
$db->exec("UPDATE quote_versions SET version_status='DRAFT' WHERE id=$qvid");$db->exec("UPDATE rates SET category='MEAL' WHERE id=1");$db->exec("UPDATE rate_versions SET rate_basis='PER_PAX',tax_basis='NET',approval_status='APPROVED' WHERE id=1");
QuoteCostItems::write($db,$user,$qvid,['id'=>$id,'rate_version_id'=>1,'notes'=>'Paying pax override reviewed']);
$r=$db->query("SELECT * FROM quote_cost_items WHERE id=$id")->fetch();check((float)$r['unit_price']===110.0&&$r['source_type']==='APPROVED_RATE'&&(int)$r['review_required']===0,'refresh reads approved current-date server rate');
rejects(fn()=>QuoteCostItems::write($db,$user,$qvid,['id'=>$id,'unit_price'=>1]),DomainException::class,'inline price cannot spoof approved rate');
$public=['document_language'=>'vi','tour_name'=>'Vietnam','start_date'=>'2027-01-10','end_date'=>'2027-01-11','total_guests'=>6,'paying_pax'=>5,'foc'=>1,'schedule'=>$preview['days']];
$html=QuoteExport::html($public);check(str_contains($html,'lang="vi"')&&str_contains($html,'Lịch trình')&&!str_contains($html,'notes'),'Vietnamese customer document hooks keep private fields out');
echo "RC1 targeted checks complete.\n";



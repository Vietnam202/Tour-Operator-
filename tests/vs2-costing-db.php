<?php
declare(strict_types=1);
require __DIR__.'/lead-hub.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/QuoteSmartCosting.php';
$db->exec("UPDATE quote_versions SET adults=10,children=0,infants=0,foc=1,total_guests=11,paying_pax=10,start_date='2027-01-02',end_date='2027-01-06',fx_rate=25000,schedule_json='[{\"date\":\"2027-01-02\",\"title\":\"Hanoi\"},{\"date\":\"2027-01-03\",\"title\":\"Halong\"}]' WHERE id=$qvid");
$v=QuoteOptions::version($db,1,$qvid);$requirements=SmartCosting::defaults('PRIVATE',$v);$requirements[]=array_replace($requirements[6],['key'=>'second_ticket','service_name'=>'Second attraction']);$requirements[]=array_replace($requirements[6],['key'=>'sic_tour','category'=>'TOUR','service_name'=>'SIC tour','mode'=>'SIC']);
foreach($requirements as &$r)$r['mode']=$r['category']==='TOUR'?'SIC':'BOTH';unset($r);
$c=QuoteSmartCosting::writeContext($db,$user,$qvid,['requirements'=>$requirements]);check(count($c['requirements'])===9,'requirements persist once per quote version');
$lines=SmartCosting::template('PRIVATE',$requirements);foreach($lines as &$l){$l['unit_price']=100000;$l['supplier_id']=1;$l['manual_reason']='Supplier confirmed final VND price';$l['star_level']='4*';$l['capacity']=16;$l['route_scope_hash']=$c['route_scope_hash'];}unset($l);
$b=['variant_key'=>'private4','label'=>'Private recommended','costing_mode'=>'PRIVATE','hotel_level'=>'4*','cruise_level'=>'4*','lines'=>$lines,'pricing_mode'=>'MARKUP','pricing_value'=>20];
$o=QuoteSmartCosting::save($db,$user,$qvid,$b);check($o['snapshot']['validation']['ready'],'server manual supplier costing validates');
$again=QuoteSmartCosting::save($db,$user,$qvid,$b);check($again['id']===$o['id'],'same variant key updates draft');
$mix=$b;$mix['variant_key']='private_mix';$mix['cruise_level']='5*';foreach($mix['lines'] as &$l)if($l['category']==='CRUISE')$l['star_level']='5*';unset($l);$mo=QuoteSmartCosting::save($db,$user,$qvid,$mix);check($mo['snapshot']['validation']['ready'],'custom hotel4/cruise5 mix validates');
$sic=$b;$sic['variant_key']='sic4';$sic['costing_mode']='SIC';$sic['label']='SIC recommended';$sl=$sic['lines'];$sl[]=array_replace($sl[0],['requirement_key'=>'sic_tour','category'=>'TOUR','service_name'=>'SIC Hanoi','quantity_source'=>'TOTAL_GUESTS','included_keys'=>['guide','meal','attraction','second_ticket']]);$sic['lines']=$sl;$so=QuoteSmartCosting::save($db,$user,$qvid,$sic);check($so['snapshot']['validation']['ready'],'SIC suppresses included guide/meals/tickets');
foreach(['3*','5*'] as $star){$preset=$b;$preset['variant_key']='preset'.$star[0];$preset['hotel_level']=$preset['cruise_level']=$star;foreach($preset['lines'] as &$l)if(in_array($l['category'],['HOTEL','CRUISE'],true))$l['star_level']=$star;unset($l);check(QuoteSmartCosting::save($db,$user,$qvid,$preset)['snapshot']['validation']['ready'],'preset '.$star.' reuses the same itinerary');}
$hybrid=$sic;$hybrid['variant_key']='hybrid4';$hybrid['costing_mode']='HYBRID';check(QuoteSmartCosting::save($db,$user,$qvid,$hybrid)['snapshot']['validation']['ready'],'hybrid service costing validates');
check((int)$db->query('SELECT COUNT(*) FROM quote_options')->fetchColumn()===6,'PRIVATE/SIC and custom mix coexist at same hotel star');
check((int)$db->query('SELECT COUNT(*) FROM quote_versions')->fetchColumn()===1,'variants do not duplicate itinerary');
rejects(fn()=>QuoteSmartCosting::save($db,['id'=>2,'company_id'=>2],$qvid,$b),OutOfBoundsException::class,'cross-company quote blocked');
$bad=$b;$bad['lines'][0]['source_type']='AI';rejects(fn()=>QuoteSmartCosting::save($db,$user,$qvid,$bad),InvalidArgumentException::class,'AI-labelled supplier cost rejected');
$bad=$b;$bad['lines'][0]['supplier_id']=2;rejects(fn()=>QuoteSmartCosting::save($db,$user,$qvid,$bad),InvalidArgumentException::class,'foreign supplier rejected');
$bad=$b;$bad['lines'][0]['rate_version_id']=999999;rejects(fn()=>QuoteSmartCosting::save($db,$user,$qvid,$bad),InvalidArgumentException::class,'foreign or absent rate rejected');
// Test approved rate server override, compatibility and expiration without trusting request totals.
$db->exec("UPDATE rates SET category='MEAL',option_name='Meal',status='ACTIVE' WHERE id=1");$db->exec("UPDATE rate_versions SET amount=200000,currency='VND',rate_basis='PER_PAX',tax_basis='NET',approval_status='APPROVED' WHERE id=1");
$rate=$b;$rate['variant_key']='approved_rate';$rate['lines'][5]['rate_version_id']=1;$rate['lines'][5]['unit_price']=1;$rate['lines'][5]['_rate_status']='APPROVED RATE';$ro=QuoteSmartCosting::save($db,$user,$qvid,$rate);check($ro['snapshot']['lines'][5]['unit_price']===220000.0,'approved seasonal amount resolved server-side, caller unit ignored');
$db->exec("UPDATE rate_versions SET approval_status='UNREVIEWED' WHERE id=1");rejects(fn()=>QuoteOptions::approve($db,$user,$qvid,'Reviewed'),DomainException::class,'newly unapproved rate blocks finalization');$db->exec("UPDATE rate_versions SET approval_status='APPROVED' WHERE id=1");
$db->exec("UPDATE rate_versions SET valid_to='2026-01-01' WHERE id=1");$expired=QuoteSmartCosting::calculate($db,QuoteOptions::version($db,1,$qvid),$rate);check(in_array('EXPIRED RATE: line 6',$expired['validation']['errors'],true),'expired rate stored as explicit validation error');$db->exec("UPDATE rate_versions SET valid_to='2028-01-01' WHERE id=1");
$db->exec('DELETE FROM quote_options WHERE id='.(int)$ro['id']);
// Change only quote-level guest fields through existing save service: recalculate followers, preserve custom overrides.
$manual=$b;$manual['variant_key']='manualqty';$manual['lines'][4]['quantity_source']='CUSTOM_QTY';$manual['lines'][4]['custom_qty']=8;$manual['lines'][4]['quantity_context']=$c['guests'];$manualOption=QuoteSmartCosting::save($db,$user,$qvid,$manual);
$save=new ReflectionMethod(CoreOS::class,'saveQuotePatch');$db->beginTransaction();$save->invoke(null,$db,['id'=>$qvid],['adults'=>12,'total_guests'=>13,'paying_pax'=>12],1);$db->commit();
$ms=json_decode($db->query('SELECT snapshot_json FROM quote_options WHERE id='.$manualOption['id'])->fetchColumn(),true);check($ms['lines'][4]['pax']===8&&in_array('NEEDS REVIEW: line 5',$ms['validation']['errors'],true),'guest edit leaves manual quantity stable and blocks finalization');
$ps=json_decode($db->query('SELECT snapshot_json FROM quote_options WHERE id='.$o['id'])->fetchColumn(),true);check($ps['lines'][2]['pax']===13&&$ps['lines'][0]['pax']===1&&$ps['total_guests']===13,'legacy info update recalculates VS2 rule followers automatically');
$db->exec('DELETE FROM quote_options WHERE id='.(int)$manualOption['id']);
$db->exec("UPDATE quote_options SET snapshot_json=JSON_SET(snapshot_json,'$.review_required',true) WHERE id=".(int)$o['id']);
$copied=QuoteSmartCosting::save($db,$user,$qvid,$ps+['variant_key'=>'private4','label'=>'Private recommended']);check(!empty($copied['snapshot']['review_required']),'copied gate persists if client omits review markers');rejects(fn()=>QuoteOptions::approve($db,$user,$qvid,'Copied not reviewed'),DomainException::class,'copied VS2 costs cannot silently finalize');
QuoteSmartCosting::save($db,$user,$qvid,$ps+['variant_key'=>'private4','label'=>'Private recommended','review_copied'=>true,'review_reason'=>'Current supplier inputs explicitly reconfirmed']);
QuoteOptions::approve($db,$user,$qvid,'Supplier inputs and final validation reviewed');
$sent=QuoteOptions::send($db,$user,$qvid);$public=json_encode($sent,JSON_THROW_ON_ERROR);check(!str_contains($public,'supplier')&&!str_contains($public,'margin')&&!str_contains($public,'unit_price')&&!str_contains($public,'rate_snapshot'),'customer snapshot excludes internal financial data');
check(count(array_filter($sent['customer_safe_snapshot']['options'],fn($o)=>$o['cruise_level']==='4*'))>=1,'customer option preserves cruise category');
$frozen=$db->query('SELECT internal_snapshot_json FROM quote_sent_bundles WHERE quote_version_id='.$qvid)->fetchColumn();
rejects(fn()=>QuoteSmartCosting::save($db,$user,$qvid,$b),DomainException::class,'sent cost variant immutable');rejects(fn()=>QuoteSmartCosting::writeContext($db,$user,$qvid,['requirements'=>[]]),DomainException::class,'sent service/guest context immutable');
check(QuoteOptions::send($db,$user,$qvid)===$sent,'send retry returns same frozen snapshot');
QuoteOptions::confirm($db,$user,$qvid,$so['id']);$booking=QuoteOptions::booking($db,$user,$qvid);$services=$db->query('SELECT * FROM booking_services WHERE booking_id='.$booking['id'])->fetchAll();check(count($services)===5,'booking excludes included standalone SIC services');
$db->exec('UPDATE rate_versions SET amount=999 WHERE id=1');check($db->query('SELECT internal_snapshot_json FROM quote_sent_bundles WHERE quote_version_id='.$qvid)->fetchColumn()===$frozen,'subsequent rate change cannot rewrite issued snapshot');
// Context copy is additive and preserves the source, ready for the existing revision endpoint.
$db->exec("INSERT INTO quote_versions(quote_id,version_no,version_status,tour_name,created_by) VALUES(1,2,'DRAFT','Revision',1)");$new=(int)$db->lastInsertId();QuoteSmartCosting::copyContext($db,$qvid,$new,1);check((int)$db->query('SELECT COUNT(*) FROM quote_service_requirements WHERE quote_version_id='.$new)->fetchColumn()===9,'revision copies structured requirements');
check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_code LIKE 'VS2_%'")->fetchColumn()>=5,'VS2 edits auditable');
echo "VS2.1 MariaDB suite complete.\n";

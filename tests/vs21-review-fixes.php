<?php
declare(strict_types=1);
require __DIR__.'/vs21-native.php';

// Start from the existing copied VS2 revision, with its original PRIVATE rates.
$qvid=$new;
$graph=QuoteVs2Repository::graph($db,v2v());
$variant=(int)array_values(array_filter($graph['variants'],fn($row)=>$row['variant_key']==='private-4-4'))[0]['id'];
$tourRate=v2rate('TOUR','900000','SIC_PAX');
$tour=v2line($variant,'TOUR');
v2edit($variant,(int)$tour['id'],['rate_version_id'=>$tourRate]);
v2command([],fn($v)=>['id'=>Vs2Variants::save($db,$user,$v,['variant_id'=>$variant,'costing_mode'=>'HYBRID','is_offered'=>true])]);
v2command([],function($v)use($db){SmartCosting::refresh($db,$v,[],true);Vs2Inclusions::apply($db,$v);return [];});
foreach(v2q("SELECT id FROM quote_variant_cost_lines WHERE variant_id=? AND coverage_state IN ('PRICED','INCLUDED')",[$variant])->fetchAll() as $row)v2review($variant,(int)$row['id']);
check(QuoteVs2Validator::validate($db,v2v())['valid'],'L28 complete PRIVATE plus SIC Hybrid validates');

// A count of three cannot turn one real hotel night into three hotel nights.
$hotel=v2line($variant,'HOTEL');$hotelReq=v2q('SELECT * FROM quote_service_requirements WHERE id=?',[$hotel['requirement_id']])->fetch();$originalScope=QuoteVs2Repository::decode($hotelReq['scope_json']);
v2context(['requirements'=>[['id'=>(int)$hotelReq['id'],'scope'=>array_replace($originalScope,['dates'=>['2027-01-02','2027-01-02','2027-01-02']])]]]);
check(in_array('HOTEL_NIGHTS_MISMATCH',v2codes(),true),'L43 duplicate hotel nights block central validation');
try{QuoteOptions::send($db,$user,$qvid);throw new RuntimeException('Send accepted duplicate nights');}catch(DomainException $e){check(str_contains($e->getMessage(),'HOTEL_NIGHTS_MISMATCH'),'L43 duplicate hotel nights block Send');}
v2context(['requirements'=>[['id'=>(int)$hotelReq['id'],'scope'=>$originalScope]]]);v2review($variant,(int)$hotel['id']);
check(QuoteVs2Validator::validate($db,v2v())['valid'],'L43 restored distinct hotel nights validate');
$outside=$originalScope;$outside['dates']=['2027-01-02','2027-01-03','2027-01-06'];v2context(['requirements'=>[['id'=>(int)$hotelReq['id'],'scope'=>$outside]]]);check(in_array('HOTEL_NIGHTS_MISMATCH',v2codes(),true),'L43 outside-itinerary hotel night blocks');v2context(['requirements'=>[['id'=>(int)$hotelReq['id'],'scope'=>$originalScope]]]);v2review($variant,(int)$hotel['id']);
$days=QuoteVs2Repository::decode(v2v()['schedule_json']);$days[2]['overnight_type']='CRUISE';v2context(['schedule'=>$days]);check(in_array('HOTEL_NIGHTS_MISMATCH',v2codes(),true),'L43 cruise overnight blocks hotel night');$days[2]['overnight_type']='HOTEL';v2context(['schedule'=>$days]);
$guide=v2line($variant,'GUIDE');$guideReq=v2q('SELECT * FROM quote_service_requirements WHERE id=?',[$guide['requirement_id']])->fetch();$guideScope=QuoteVs2Repository::decode($guideReq['scope_json']);$duplicateGuide=$guideScope;$duplicateGuide['dates']=['2027-01-02','2027-01-02','2027-01-04','2027-01-05'];v2context(['requirements'=>[['id'=>(int)$guideReq['id'],'scope'=>$duplicateGuide]]]);check(in_array('GUIDE_DAYS_MISMATCH',v2codes(),true),'L43 duplicate guide day blocks');v2context(['requirements'=>[['id'=>(int)$guideReq['id'],'scope'=>$guideScope]]]);v2review($variant,(int)$guide['id']);
check(QuoteVs2Validator::validate($db,v2v())['valid'],'L43 restored hotel and guide itinerary dates validate');

// This package has no included dependents, so the real line editor permits removal.
$tour=v2line($variant,'TOUR');v2command([],fn($v)=>Vs2LineEditor::write($db,$user,$v,$variant,[],'delete',(int)$tour['id']));
check(in_array('MISSING_REQUIRED_SERVICE',v2codes(),true),'L28 missing required SIC tour detected in Hybrid');
try{QuoteOptions::send($db,$user,$qvid);throw new RuntimeException('Send accepted missing SIC tour');}catch(DomainException $e){check(str_contains($e->getMessage(),'MISSING_REQUIRED_SERVICE'),'L42 missing SIC tour blocks Send');}
echo "VS2.1 review blocker checks complete.\n";

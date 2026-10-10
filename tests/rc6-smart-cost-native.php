<?php
declare(strict_types=1);
// End-to-end RC6 costing integration: disposable MariaDB fixture. Never touches a deployed database.
require __DIR__.'/vs21-native.php';
$version=fn()=>QuoteOptions::version($db,1,(int)$new);
$graph=QuoteVs2Repository::graph($db,$version());
$private=[];foreach($graph['variants'] as $p)if(in_array($p['variant_key'],['private-3-3','private-4-4','private-5-5'],true))$private[(int)$p['hotel_level']] = (int)$p['id'];
ksort($private);
check(count($private)===3,'RC6 three baseline variants available on disposable copied draft');
$ids=array_values($private);
$command=fn(array $body)=>QuoteVs2::mutate($db,$user,(int)$new,['expected_revision'=>(int)$version()['costing_revision']],
    fn($v)=>QuoteVs2::sheet($db,$user,$v,['variant_ids'=>$ids]+$body),'RC6_COST_ACCEPTANCE');
$sapa=$command(['action'=>'row','requirement'=>[
    'category'=>'HOTEL','service_name'=>'Sapa Hotel','service_date'=>'2027-01-04',
    'service_units'=>1,'default_quantity_source'=>'HOTEL_PAX','service_mode'=>'BOTH',
    'requirement_state'=>'REQUIRED','scope'=>['destination'=>'Sapa','dates'=>['2027-01-04']]
],'line'=>[]]);
$sapaId=(int)$sapa['requirement_id'];
$hanoi=QuoteVs2Repository::q($db,
    "SELECT id,scope_json FROM quote_service_requirements WHERE quote_version_id=? AND category='HOTEL' AND id<>? LIMIT 1",
    [$new,$sapaId])->fetch();
check((int)$hanoi['id']!==$sapaId,'RC6 Hanoi hotel remains independent of Sapa hotel');
$have=QuoteVs2Repository::q($db,
    'SELECT COUNT(*) FROM quote_variant_cost_lines WHERE requirement_id=? AND variant_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',
    [$sapaId,...$ids])->fetchColumn();
check((int)$have===3,'RC6 Sapa hotel has separate lines for three options');
$mix=$command(['action'=>'mix','mode'=>'PRIVATE','hotel_level'=>3,'cruise_level'=>5]);
$mixId=(int)$mix['variant_id'];
$target=QuoteVs2Repository::variant($db,(int)$new,$mixId);
check($target['hotel_level']==='3*'&&(int)$target['cruise_level']===5,'RC6 Hotel 3-star + Cruise 5-star persisted');
check((int)$target['is_offered']===0,'RC6 new mix is not silently offered or sent');
$all=QuoteVs2Repository::q($db,"SELECT requirement_id FROM quote_variant_cost_lines WHERE variant_id=? AND line_kind='SERVICE'",[$mixId])->fetchAll();
check(in_array($sapaId,array_map('intval',array_column($all,'requirement_id')),true),'RC6 mixed option retains Sapa hotel row');
$reused=$command(['action'=>'mix','mode'=>'PRIVATE','hotel_level'=>3,'cruise_level'=>5]);
check((int)$reused['variant_id']===$mixId&&!empty($reused['reused']),'RC6 same Hotel/Cruise mix is idempotent');
$command(['action'=>'remove','requirement_id'=>$sapaId,'reason'=>'Client changed hotel itinerary']);
check(QuoteVs2Repository::q($db,'SELECT requirement_state FROM quote_service_requirements WHERE id=?',[$sapaId])->fetchColumn()==='NOT_APPLICABLE',
    'RC6 remove does not physically delete costing requirement');
$command(['action'=>'row','requirement'=>['id'=>$sapaId,'requirement_state'=>'REQUIRED'],'line'=>[]]);
check(QuoteVs2Repository::q($db,'SELECT requirement_state FROM quote_service_requirements WHERE id=?',[$sapaId])->fetchColumn()==='REQUIRED',
    'RC6 Undo restores removed hotel without losing its destination or rates');
echo "RC6 Smart Cost native acceptance checks complete.\n";

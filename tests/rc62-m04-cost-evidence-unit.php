<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/VtaQuoteCostEvidenceAudit.php';
function must(bool $condition,string $text):void {if (!$condition) throw new RuntimeException('FAIL '.$text);}
function refusal(callable $fn,string $text):void {try{$fn();}catch(DomainException|InvalidArgumentException $e){return;}throw new RuntimeException('FAIL expected refusal '.$text);}
$actor=['company_id'=>14];
$quote=['company_id'=>14,'quote_version_id'=>210,'total_guests'=>7,'paying_pax'=>6,'foc'=>1,'tour_mode'=>'PRIVATE','hotel_star'=>3,'cruise_star'=>5,'status'=>'DRAFT'];
$line=[
    'company_id'=>14,'service_key'=>'hotel_hanoi_day1','rate_version_id'=>910,'source_document_ref'=>'DOC-001',
    'approval_status'=>'APPROVED','currency'=>'VND','unit_vnd'=>'1200000.00','units'=>1,
    'guest_basis'=>'TOTAL_PAX','valid_from'=>'2027-01-01','valid_to'=>'2027-04-30','service_date'=>'2027-03-01'
];
$guide=array_merge($line,['service_key'=>'guide_day1','rate_version_id'=>911,'unit_vnd'=>'950000.00','guest_basis'=>'PER_UNIT']);
$meal=array_merge($line,['service_key'=>'meal_day1','rate_version_id'=>912,'unit_vnd'=>'200000.00','guest_basis'=>'PAYING_PAX']);
$source=[$line,$guide,$meal];
$hash=hash('sha256',serialize([$actor,$quote,$source]));
$r=VtaQuoteCostEvidenceAudit::review($actor,$quote,$source);
must($r['verdict']==='EXPLAINED_COST_NOT_A_QUOTE','valid evidence only explains cost');
must($r['cost_total_vnd']==='10550000.00','7*1.2m + 950k + 6*200k = 10.55m');
must($r['foc_cost_included_vnd']==='1200000.00','FOC portion explained, not added twice');
must($r['pax']===['total'=>7,'paying'=>6,'foc'=>1],'correct 7=6+1');
must($r['hotel_star']===3 && $r['cruise_star']===5,'hotel3 cruise5 independent choice');
must($r['can_sell']===false && $r['can_commit']===false && $r['fx_applied']===false,'preview never sells/commits/assumes FX');
must($r['supplier_availability_confirmed']===false,'rate does not prove availability');
must(hash('sha256',serialize([$actor,$quote,$source]))===$hash,'original snapshots unchanged');
must($r['lines'][2]['cost_vnd']==='1200000.00' && $r['lines'][2]['foc_included_vnd']==='0.00','paying-only meals');

$old=array_merge($quote,['total_guests'=>8]);
$bad=VtaQuoteCostEvidenceAudit::review($actor,$old,$source);
must(in_array('PAX_FOC_CONFLICT',$bad['errors'],true),'8/6/1 conflict blocked');
must($bad['cost_total_vnd']===null && $bad['lines'][0]['cost_vnd']===null,'no computed cost on inconsistent pax');
$unapproved=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['approval_status'=>'REVIEWED'])]);
must(in_array('RATE_NOT_APPROVED',$unapproved['errors'],true) && $unapproved['cost_total_vnd']===null,'unapproved rate never costed');
$zero=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['unit_vnd'=>'0'])]);
must(in_array('UNIT_AMOUNT_INVALID',$zero['errors'],true),'zero is unpriced not free');
$usd=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['currency'=>'USD'])]);
must(in_array('SOURCE_VND_REQUIRED',$usd['errors'],true),'supplier currency must be VND');
$expired=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['valid_to'=>'2027-02-28'])]);
must(in_array('SERVICE_OUTSIDE_RATE_VALIDITY',$expired['errors'],true),'expired source blocked');
$dupe=VtaQuoteCostEvidenceAudit::review($actor,$quote,[$line,$line]);
must(in_array('SERVICE_KEY_DUPLICATE',$dupe['errors'],true),'cost line id duplicate');
$overflow=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['unit_vnd'=>'99999999999999.00','units'=>10000])]);
must(in_array('ARITHMETIC_OVERFLOW',$overflow['errors'],true),'checked arithmetic overflow');
$unknown=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['valid_from'=>''])]);
must(in_array('RATE_VALIDITY_UNVERIFIED',$unknown['errors'],true),'missing validity cannot be used');
refusal(fn()=>VtaQuoteCostEvidenceAudit::review($actor,array_merge($quote,['company_id'=>2]),$source),'other company quote');
$other=VtaQuoteCostEvidenceAudit::review($actor,$quote,[array_merge($line,['company_id'=>2])]);
must(in_array('RATE_TENANT_MISMATCH',$other['errors'],true),'other company rate blocked');
refusal(fn()=>VtaQuoteCostEvidenceAudit::review(['company_id'=>'14'],$quote,$source),'untrusted actor');

$scenarios=[];
foreach([4,6,8,10,12] as $pax) {
    foreach([3,4,5] as $star) {
        $variant=array_merge($quote,['quote_version_id'=>200+$star*100+$pax,'paying_pax'=>$pax,'total_guests'=>$pax+1,'hotel_star'=>$star]);
        $scenarios[]=['id'=>'PAX'.$pax.'_H'.$star,'quote'=>$variant,'lines'=>$source];
    }
}
$matrix=VtaQuoteCostEvidenceAudit::compare($actor,$scenarios);
must(count($matrix['items'])===15,'15 separate PRIVATE star/pax scenarios');
must($matrix['all_verified']===true && $matrix['can_sell']===false,'all evidence checked; not sellable');
$scenarios[0]['quote']['tour_mode']='SIC';
$privateVsSic=VtaQuoteCostEvidenceAudit::compare($actor,$scenarios);
must($privateVsSic['items'][0]['audit']['tour_mode']==='SIC','Private vs SIC independent, no shared price');
refusal(fn()=>VtaQuoteCostEvidenceAudit::compare($actor,[$scenarios[0],$scenarios[0]]),'duplicate scenarios');
refusal(fn()=>VtaQuoteCostEvidenceAudit::compare($actor,array_fill(0,21,$scenarios[0])),'scenario bound');
must(!str_contains(file_get_contents(__DIR__.'/../api/lib/VtaQuoteCostEvidenceAudit.php'),'INSERT INTO'),'no DB write added');
echo "PASS M04 evidence: exact VND arithmetic, 6+1 FOC, 3/4/5 star, Private/SIC, 15 bands, tenant, approved rate, expiry, no sell/commit\n";

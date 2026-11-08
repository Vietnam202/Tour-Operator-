<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/VtaSupplierRateIntakeReview.php';
function ok(bool $condition, string $label): void {if (!$condition) throw new RuntimeException('FAIL '.$label);}
function issue(array $row,string $code): bool {return in_array($code,$row['issues'],true);}
function fails(callable $fn,string $label):void {try{$fn();}catch(InvalidArgumentException|DomainException $e){return;}throw new RuntimeException('FAIL negative path '.$label);}

$head='supplier_ref,category,product_name,rate_basis,amount,currency,valid_from,valid_to,source_document_ref,contract_ref,source_version,option_name,booking_valid_from,booking_valid_to,min_pax,max_pax';
$valid='SUP-01,HOTEL,Hanoi Verified Hotel,PER_ROOM_NIGHT,1450000,VND,2027-01-01,2027-03-31,DOC-123,CONT-09,V2,Twin,2026-11-01,2027-02-28,1,8';
$csv=$head."\n".$valid."\n";
$company=['company_id'=>42];
$before=hash('sha256',serialize([$company,$csv]));
$preview=VtaSupplierRateIntakeReview::previewCsv($csv,$company);
ok($preview['schema']==='VTA_SUPPLIER_RATE_INTAKE_PREVIEW_1','schema stable');
ok($preview['mode']==='READ_ONLY_PREVIEW','mode read-only');
ok($preview['summary']['total']===1 && $preview['summary']['blocked']===0,'valid source line accepted for review');
ok($preview['rows'][0]['state']==='REVIEW_REQUIRED','unknown blackout/capacity cannot be assumed');
ok(issue($preview['rows'][0],'CAPACITY_BLACKOUT_UNVERIFIED'),'blackout warning');
ok($preview['may_price_quote']===false && $preview['may_publish']===false,'never auto price/publish');
ok(hash('sha256',serialize([$company,$csv]))===$before,'input immutable');

$bom=VtaSupplierRateIntakeReview::previewCsv("\xEF\xBB\xBF".$csv,$company);
ok($bom['rows'][0]['fingerprint']===$preview['rows'][0]['fingerprint'],'BOM handled');
ok(VtaSupplierRateIntakeReview::previewCsv($csv,$company)['source_digest']===$preview['source_digest'],'idempotent digest');

$dup=VtaSupplierRateIntakeReview::previewCsv($head."\n".$valid."\n".$valid."\n",$company);
ok(issue($dup['rows'][1],'DUPLICATE_SOURCE_ROW') && $dup['rows'][1]['state']==='BLOCKED','duplicate import rejected');
ok(issue($dup['rows'][1],'SEASON_OR_VERSION_OVERLAP'),'same supplier season conflict');

$alt=str_replace('2027-01-01,2027-03-31','2027-03-15,2027-05-31',$valid);
$overlap=VtaSupplierRateIntakeReview::previewCsv($head."\n".$valid."\n".$alt."\n",$company);
ok(issue($overlap['rows'][1],'SEASON_OR_VERSION_OVERLAP'),'partial seasonal overlap flagged');
$separate=str_replace('2027-01-01,2027-03-31','2027-04-01,2027-06-30',$valid);
$clear=VtaSupplierRateIntakeReview::previewCsv($head."\n".$valid."\n".$separate."\n",$company);
ok(!issue($clear['rows'][1],'SEASON_OR_VERSION_OVERLAP'),'adjacent intervals not overlap');

$approved=[[
    'company_id'=>42,'approval_status'=>'APPROVED','supplier_ref'=>'SUP-01','category'=>'HOTEL',
    'product_name'=>'Hanoi Verified Hotel','option_name'=>'Twin','rate_basis'=>'PER_ROOM_NIGHT',
    'currency'=>'VND','valid_from'=>'2027-03-01','valid_to'=>'2027-05-15'
]];
$against=VtaSupplierRateIntakeReview::previewCsv($csv,$company,$approved);
ok(issue($against['rows'][0],'SEASON_OR_VERSION_OVERLAP'),'approved source overlap flagged');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv($csv,$company,[array_merge($approved[0],['company_id'=>9])]),'cross company source rejected');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv($csv,['company_id'=>'42']),'client-controlled company string rejected');

$usd=str_replace(',VND,',',USD,',$valid);
$usdPreview=VtaSupplierRateIntakeReview::previewCsv($head."\n".$usd."\n",$company);
ok(issue($usdPreview['rows'][0],'VND_SOURCE_REQUIRED') && $usdPreview['rows'][0]['state']==='BLOCKED','mixed supplier currency blocked');
$zero=str_replace(',1450000,',',0,',$valid);
ok(issue(VtaSupplierRateIntakeReview::previewCsv($head."\n".$zero."\n",$company)['rows'][0],'AMOUNT_INVALID'),'zero is not free');
$formula=str_replace('Hanoi Verified Hotel','=HYPERLINK("http://evil")',$valid);
$fp=VtaSupplierRateIntakeReview::previewRows([array_combine(explode(',',$head),explode(',',$formula))],$company);
ok(issue($fp['rows'][0],'FORMULA_LIKE_FIELD'),'formula-like source blocked');
ok(!str_contains(json_encode($fp,JSON_THROW_ON_ERROR),'evil'),'untrusted raw cell never echoed');

$badDate=str_replace('2027-03-31','2027-02-30',$valid);
ok(issue(VtaSupplierRateIntakeReview::previewCsv($head."\n".$badDate."\n",$company)['rows'][0],'DATE_INVALID'),'reject nonexistent dates');
$reverse=str_replace('2027-03-31','2026-03-31',$valid);
ok(issue(VtaSupplierRateIntakeReview::previewCsv($head."\n".$reverse."\n",$company)['rows'][0],'VALIDITY_REVERSED'),'reject reversed seasons');
$noDoc=str_replace('DOC-123','',$valid);
ok(issue(VtaSupplierRateIntakeReview::previewCsv($head."\n".$noDoc."\n",$company)['rows'][0],'REQUIRED_FIELD_MISSING'),'source doc required');
$missingProvenance=str_replace(',CONT-09,V2,',',,,',$valid);
ok(issue(VtaSupplierRateIntakeReview::previewCsv($head."\n".$missingProvenance."\n",$company)['rows'][0],'CONTRACT_PROVENANCE_UNVERIFIED'),'contract version provenance mandatory for review');

fails(fn()=>VtaSupplierRateIntakeReview::previewCsv("supplier_ref,currency\nSUP,VND\n",$company),'header required fields');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv($head."\n".$valid.",EXTRA\n",$company),'CSV column mismatch');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv(str_repeat('a',524289),$company),'CSV max size');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv($csv."\0",$company),'NUL rejected');
fails(fn()=>VtaSupplierRateIntakeReview::previewCsv($head."\n".str_repeat($valid."\n",251),$company),'row limit');

ok(!str_contains(file_get_contents(__DIR__.'/../api/lib/VtaSupplierRateIntakeReview.php'),'INSERT INTO'),'no DB write embedded');
print "PASS M03 preview: VND, contract provenance, seasons, approved overlap, CSV injection, tenant, idempotence, limits, original immutability\n";

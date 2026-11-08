<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/VtaRateIntakeReview.php';

function must(bool $good,string $label):void {if(!$good)throw new RuntimeException($label);}
function hasCode(array $row,string $code):bool {return in_array($code,array_column($row['issues'],'code'),true);}
function csv(array $rows):string {
    $f=fopen('php://temp','w+');
    foreach($rows as $row)fputcsv($f,$row,',','"','');
    rewind($f);$s=stream_get_contents($f);fclose($f);return $s;
}
function row(string $amount='1000000',string $from='2027-01-01',string $to='2027-02-01',string $currency='VND',string $product='Lake Hotel',string $supplier='19'):array {
    return [$supplier,'HOTEL',$product,'CONTRACT','PER_ROOM_NIGHT',$currency,$amount,$from,$to,'500','NET','2','8','INDIA','Double','','','','','agreement paragraph 2',''];
}
$h=['supplier_id','category','product_name','rate_type','rate_basis','currency','amount_vnd','valid_from','valid_to','source_document_id','tax_basis','min_pax','max_pax','market','option_name','season_from','season_to','blackout_from','blackout_to','source_locator','linked_trip_ref'];
$scope=['company_id'=>55,'supplier_id'=>19];
$input=csv([$h,row(),row('1100000','2027-01-15','2027-03-01'),row('1200000','2027-04-01','2027-05-01')]);
$before=hash('sha256',$input);
$r=VtaRateIntakeReview::previewCsv($input,$scope);
must($r['source_sha256']===$before,'original bytes must be fingerprinted exactly');
must($r['schema']==='VTA_RATE_INTAKE_REVIEW_1' && $r['status']==='PREVIEW_ONLY_NO_DB_WRITE','no approval / persistence');
must($r['total_rows']===3 && $r['blocked_rows']===0 && $r['review_rows']===3,'three candidates for human review');
must(hasCode($r['rows'][0],'CANDIDATE_RATE_OVERLAP') && hasCode($r['rows'][1],'CANDIDATE_RATE_OVERLAP'),'overlapping VND contract same supplier/product/pax');
must(!hasCode($r['rows'][2],'CANDIDATE_RATE_OVERLAP'),'non-overlapping season not conflated');
must($r['rows'][0]['candidate']['amount']==='1000000','keep money as exact decimal strings');
must($r['rows'][0]['candidate']['tax_basis']==='NET' && $r['rows'][0]['candidate']['source_locator']==='agreement paragraph 2','retain reviewable contract tax and source location');
must($r['rows'][0]['candidate']['company_id']===55,'explicit company context retained');
must(hasCode($r['rows'][0],'SUPPLIER_OWNERSHIP_AND_EXISTING_RATES_NOT_CHECKED'),'do not claim ownership or matching DB');

$duplicate=VtaRateIntakeReview::previewCsv(csv([$h,row(),row()]),$scope);
must($duplicate['rows'][0]['fingerprint']===$duplicate['rows'][1]['fingerprint'],'stable row fingerprint');
must(hasCode($duplicate['rows'][0],'DUPLICATE_IMPORT_ROW') && hasCode($duplicate['rows'][1],'DUPLICATE_IMPORT_ROW'),'idempotent duplicate recognition');

$denied=VtaRateIntakeReview::previewCsv(csv([$h,row('100','2027-01-01','2027-02-01','USD'),row('500','2027-01-01','2027-02-01','VND','=HYPERLINK("bad")'),row('400','2027-01-01','2027-02-01','VND','Lake Hotel','888'),row('0')]),$scope);
must($denied['blocked_rows']===4,'wrong currency, formula, foreign supplier and free/zero must fail');
must(hasCode($denied['rows'][0],'SUPPLIER_VND_REQUIRED'),'supplier input stays VND');
must(hasCode($denied['rows'][1],'UNSAFE_SPREADSHEET_CELL'),'reject spreadsheet formula injection');
must($denied['rows'][1]['candidate']===null,'do not echo unsafe cells in candidate data');
must(hasCode($denied['rows'][2],'SUPPLIER_SCOPE_MISMATCH'),'no cross supplier input');
must(hasCode($denied['rows'][3],'POSITIVE_VND_AMOUNT_REQUIRED'),'0 is not free');

$invalid=VtaRateIntakeReview::previewCsv(csv([$h,row('100','2027-02-30','2027-01-01'),row('100','2027-12-01','2027-01-01')]),$scope);
must($invalid['blocked_rows']===2,'impossible/reversed dates refused');
$special=row();$special[3]='SPECIAL_QUOTE';
must(hasCode(VtaRateIntakeReview::previewCsv(csv([$h,$special]),$scope)['rows'][0],'SPECIAL_QUOTE_TRIP_REQUIRED'),'trip-specific rate cannot silently become generic');
$special[20]='TR-2027-001';
$reviewedSpecial=VtaRateIntakeReview::previewCsv(csv([$h,$special]),$scope);
must(!hasCode($reviewedSpecial['rows'][0],'SPECIAL_QUOTE_TRIP_REQUIRED') && $reviewedSpecial['rows'][0]['candidate']['linked_trip_ref']==='TR-2027-001','trip-restricted source is preserved, not published');
$overPax=row();$overPax[12]='10001';
must(hasCode(VtaRateIntakeReview::previewCsv(csv([$h,$overPax]),$scope)['rows'][0],'PAX_RANGE_INVALID'),'supplier PAX cannot exceed existing RateEngine maximum');
$season=row();$season[15]='2027-01-01';$season[16]='2027-02-01';
must(hasCode(VtaRateIntakeReview::previewCsv(csv([$h,$season]),$scope)['rows'][0],'DATE_PERIOD_REQUIRES_RATE_RULES_REVIEW'),'seasons must enter existing RateRules after review');

try {VtaRateIntakeReview::previewCsv(csv([['unknown'],['value']]),$scope);throw new RuntimeException('unknown headers were accepted');}
catch(InvalidArgumentException $e){must(str_contains($e->getMessage(),'header')||str_contains($e->getMessage(),'column'),'header validation');}
try {VtaRateIntakeReview::previewCsv(csv([$h,row()]),['company_id'=>55,'supplier_id'=>0]);throw new RuntimeException('unscoped preview accepted');}
catch(InvalidArgumentException $e){must(str_contains($e->getMessage(),'company and supplier'),'explicit scoped preview');}
try {VtaRateIntakeReview::previewCsv("\xFF\xFE",$scope);throw new RuntimeException('invalid UTF8 accepted');}
catch(InvalidArgumentException $e){must(str_contains($e->getMessage(),'UTF-8'),'reject malformed UTF8');}
try {VtaRateIntakeReview::previewCsv(str_repeat('X',VtaRateIntakeReview::MAX_BYTES+1),$scope);throw new RuntimeException('oversized content accepted');}
catch(InvalidArgumentException $e){must(str_contains($e->getMessage(),'UTF-8'),'reject oversized content');}
must(!method_exists(VtaRateIntakeReview::class,'commit'),'read-only helper must have no persistence entrypoint');
echo "PASS M03 supplier VND preview: exact source hash, contract overlap/duplication, blocked USD/formula/foreign supplier/zero/invalid dates, no DB writes\n";

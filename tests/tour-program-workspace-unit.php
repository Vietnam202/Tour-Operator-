<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/ScheduleImport.php';
require_once __DIR__.'/../api/lib/TourLibrary.php';
function checkProgram(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejectProgram(callable $fn,string $name):void {try{$fn();}catch(InvalidArgumentException $e){checkProgram(true,$name);return;}throw new RuntimeException($name);}
$paragraph=fn($s)=>['type'=>'paragraph','content'=>[['type'=>'text','text'=>$s]]];
$cell=fn($s)=>['type'=>'tableCell','content'=>[$paragraph($s)]];
$document=['schema'=>'VTA_DOC_2','title'=>'Reusable Word','content'=>['type'=>'doc','content'=>[
 $paragraph('Keep program prose'),['type'=>'table','content'=>[['type'=>'tableRow','content'=>[$cell('09:00'),$cell('Visit Hanoi')]]]],
 ['type'=>'table','attrs'=>['vtaSection'=>'pricing'],'content'=>[['type'=>'tableRow','content'=>[$cell('STALE PRICE CANARY')]]]],
 ['type'=>'image','attrs'=>['assetId'=>12,'width'=>320]],['type'=>'pageBreak']]]];
$clean=TourProgramWorkspace::reusableDocument($document);
checkProgram(!str_contains(json_encode($clean),'STALE PRICE CANARY'),'marked price blocks never enter a reusable program');
checkProgram(str_contains(json_encode($clean),'Visit Hanoi')&&count($clean['content']['content'])===4,'ordinary tables, prose, uploaded image and page break survive');
checkProgram(FreeformDocument::imageIds($clean)===[12],'reusable images remain owned asset IDs');
$base=['id'=>1,'title'=>'Northern trip','status'=>'ACTIVE','language'=>'en','destination'=>'Hanoi','tags_json'=>'[]','days_json'=>'[{"title":"Arrival","description":"Old quarter","notes":"INTERNAL NOTES CANARY","date":"2027-01-01"}]','included_text'=>'Guide','proposal_json'=>json_encode(['tour_code'=>'VTA-01','tour_type'=>'SIC','group_prices'=>[['hotel'=>'3','price'=>'999']],'private_prices'=>[['min'=>2,'max'=>3,'three'=>'999']]])];
$doc=TourProgramWorkspace::document($base);$json=json_encode($doc);
checkProgram(str_contains($json,'Old quarter')&&!str_contains($json,'INTERNAL NOTES CANARY')&&!str_contains($json,'999')&&!str_contains($json,'2027-01-01'),'legacy programs convert without prices, internal notes or template travel dates');
$summary=TourLibrary::publicProgram($base,false);
checkProgram($summary['tour_code']==='VTA-01'&&$summary['tour_type']==='SIC'&&!isset($summary['proposal']),'picker summaries expose code/type without the full document');
$changed=$base;$changed['proposal_json']=json_encode(['tour_code'=>'Changed']);
checkProgram(TourProgramWorkspace::revision($base)!==TourProgramWorkspace::revision($changed),'master revision covers content changes rather than timestamp precision');
$normalized=TourLibrary::normalize(['title'=>'Word master','language'=>'vi','status'=>'ACTIVE','days'=>[],'proposal'=>['document'=>$clean]]);
checkProgram($normalized['proposal']['document']===$clean,'native Word master needs no forced day cards');
rejectProgram(fn()=>TourLibrary::normalize(['title'=>'Invalid','proposal'=>['document'=>['schema'=>'VTA_DOC_2','content'=>['type'=>'doc','content'=>[['type'=>'script']]]]]]),'script nodes rejected before library persistence');
$bad=$document;$bad['content']['content'][0]['attrs']=['vtaSection'=>'unsafe'];
rejectProgram(fn()=>TourProgramWorkspace::reusableDocument($bad),'unsupported public section attributes rejected');
rejectProgram(fn()=>TourLibrary::normalize(['title'=>'Empty','status'=>'ACTIVE','days'=>[],'proposal'=>['document'=>['schema'=>'VTA_DOC_2','title'=>'Empty','content'=>['type'=>'doc','content'=>[['type'=>'paragraph']]]]]]),'empty Word programs cannot be activated');
$base['proposal_json']=json_encode(['hotels'=>[['destination'=>'Hanoi','three'=>'Hotel three','four'=>'Hotel four','five'=>'Hotel five']],'private_prices'=>[['min'=>1,'max'=>2,'three'=>'999']]]);
checkProgram(str_contains(json_encode(TourProgramWorkspace::document($base)),'Hotel four')&&!str_contains(json_encode(TourProgramWorkspace::document($base)),'999'),'legacy hotel names become editable tables without copying prices');
echo "Tour program workspace domain checks complete.\n";

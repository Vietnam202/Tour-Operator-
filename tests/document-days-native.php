<?php
declare(strict_types=1);
// Keep the complete 163-check baseline; use only a disposable native database.
require __DIR__.'/document-cost-native.php';
$payloadPath=getenv('VS2_DAY_UI_OUTPUT')?:throw new RuntimeException('Run document-days-ui.cjs with VS2_DAY_UI_OUTPUT first');
$payload=json_decode(file_get_contents($payloadPath),true,512,JSON_THROW_ON_ERROR);
$sourceId=$qvid;$source=v2v();$sentBefore=v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();$bookingsBefore=v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll();
$reuse=QuoteReuse::clone($db,$user,$sourceId,(int)$source['inquiry_id'],'FULL_DRAFT');$qvid=(int)$reuse['version_id'];
check(v2v()['version_status']==='DRAFT'&&$qvid!==$sourceId,'P0 creates an editable quote using the existing reuse/inquiry model');
v2context(['start_date'=>'2027-01-02','end_date'=>'2027-01-08']);
$costRows=fn()=>v2q('SELECT l.id,l.variant_id,l.unit_amount_original,l.original_currency,l.total_vnd,l.resolved_quantity,l.resolved_units FROM quote_variant_cost_lines l JOIN quote_option_variants c ON c.id=l.variant_id JOIN quote_options o ON o.id=c.quote_option_id WHERE o.quote_version_id=? ORDER BY l.id',[$qvid])->fetchAll();$costBefore=$costRows();
$dayImage=imagecreatetruecolor(800,500);$dayColor=imagecolorallocate($dayImage,32,110,90);imagefill($dayImage,0,0,$dayColor);imagestring($dayImage,5,40,40,'P0 INDEPENDENT DAY MEDIA',imagecolorallocate($dayImage,255,255,255));ob_start();imagejpeg($dayImage,null,90);$dayBytes=ob_get_clean();imagedestroy($dayImage);
$companyAsset=(int)MediaLibrary::ingest($db,$cfg,$user,$dayBytes,['title'=>'P0 day media','visibility'=>'COMPANY','filename'=>'p0-day.jpg'])['asset']['id'];
foreach($payload['links'] as &$link)$link['asset_id']=(int)$companyAsset;unset($link);
// Dates are operator-reviewed inputs, not inferred by duplicate/reorder actions.
foreach($payload['days'] as $i=>&$day)$day['date']='2027-01-'.str_pad((string)($i+2),2,'0',STR_PAD_LEFT);unset($day);
$saved=QuoteProposal::save($db,$user,$qvid,['expected_revision'=>(int)v2v()['costing_revision'],'settings'=>$payload['settings'],'days'=>$payload['days'],'links'=>$payload['links']]);
check(count($saved['days'])===7&&count(array_unique(array_column($saved['days'],'day_key')))===7,'P0 saved edited seven-day programme retains unique day identity');
check(array_column($saved['days'],'day')===range(1,7),'P0 persisted day order is numbered consecutively after duplicate/delete/reorder');
$doc=$saved['settings']['document'];$sourceKey=$payload['source_key'];$duplicateKey=$payload['duplicate_key'];
check($doc['days']===QuoteProposal::document($payload['settings']['document'])['days'],'P0 typed paragraphs/runs/lists/tables persist exactly through the canonical save API');
check($doc['days'][$sourceKey]!==$doc['days'][$duplicateKey]&&str_contains(QuoteProposal::blockText($doc['days'][$sourceKey]),'Visit Hanoi')&&str_contains(QuoteProposal::blockText($doc['days'][$duplicateKey]),'Edited copy only'),'P0 edited duplicate remains independent of the source in the database');
check($costRows()===$costBefore,'P0 itinerary actions preserve stored quantities, supplier costs and all variant arithmetic');
$reopened=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$database.';charset=utf8mb4','root',getenv('VTA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$rv=QuoteOptions::version($reopened,1,$qvid);$loaded=QuoteProposal::context($reopened,$user,$rv);
check($loaded['days']===$saved['days']&&$loaded['settings']['document']===$doc,'P0 independent database reload/reopen preserves the complete formatted itinerary');
check(count(array_filter($loaded['links'],fn($l)=>$l['day_key']===$sourceKey))===1&&count(array_filter($loaded['links'],fn($l)=>$l['day_key']===$duplicateKey))===1,'P0 copied media assignments belong independently to the two stable day keys');
$public=QuoteProposal::publicQuote($reopened,$rv);$html=ProposalOutput::html($public,fn($a)=>'api/image');file_put_contents($output.'/p0-days.html',$html);
check(str_contains($html,'Retry copied title')&&str_contains($html,'<strong>Edited copy only</strong>')&&str_contains($html,'<em>Evening visit</em>')&&str_contains($html,'<strong>Visit Hanoi'),'P0 real HTML preview renders both source and independent formatted duplicate');
$image=MediaLibrary::asset($db,$user,(int)$companyAsset);$imagePath=fn($a)=>$image['storage_path'];
file_put_contents($output.'/p0-days.docx',ProposalOutput::docx($public,$imagePath));$word=DocumentParser::proposal($output.'/p0-days.docx','docx');
check(str_contains($word['text'],'Edited copy only')&&str_contains($word['text'],'Visit Hanoi')&&count($word['blocks'])>0,'P0 real DOCX export preserves both original and edited duplicate prose');
$formatted=false;foreach($word['blocks'] as $b)foreach($b['runs']??[] as $r)if(str_contains($r['text'],'Edited copy only')&&$r['bold'])$formatted=true;check($formatted,'P0 exported DOCX retains duplicated bold formatting');
file_put_contents($output.'/p0-days.pdf',ProposalOutput::pdf($public,$cfg,$imagePath,true));$pdf=DocumentParser::proposal($output.'/p0-days.pdf','pdf');
check($pdf['quality']==='MEDIUM'&&str_contains($pdf['text'],'Edited copy only')&&str_contains($pdf['text'],'Visit Hanoi'),'P0 real searchable PDF export preserves both days after reopen');
check(v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll()===$sentBefore&&v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll()===$bookingsBefore,'P0 old Sent/accepted/booking snapshots stay byte-identical');
file_put_contents($output.'/p0-days-fixture.json',MediaLibrary::json(['database'=>$database,'quote_id'=>$reuse['id'],'version_id'=>$qvid,'quote_ref'=>$reuse['quote_ref'],'result'=>'PASS']));
echo "P0 document day persistence/preview/export acceptance complete.\n";

<?php
declare(strict_types=1);

/**
 * VIE-16 native acceptance.
 * Runs ONLY on a freshly created disposable MariaDB fixture inherited from
 * tour-program-workspace-native.php. NEVER run on an existing staging DB.
 */
require __DIR__.'/tour-program-workspace-native.php';
require_once __DIR__.'/../api/lib/VtaAiItinerary.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

$sourceBefore=TourProgramWorkspace::program($db,$user,$programId);
$sourceCtx=TourProgramWorkspace::context($db,$user,$sourceBefore);
$sourceDoc=TourProgramWorkspace::document($sourceBefore);
$origBusinessRows=v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll();
$origIssuedRows=v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll();
$masterRowCount=(int)v2q('SELECT COUNT(*) FROM tour_library_programs WHERE company_id=?',[(int)$user['company_id']])->fetchColumn();

$candidate=$sourceDoc;
$candidate['title']='RC6.2 AI edited Hanoi itinerary 8D7N';
$candidate['content']['content'][]=['type'=>'heading','attrs'=>['level'=>2],
 'content'=>[['type'=>'text','text'=>'Day 8 — Extended Hanoi departure']]];
$candidate['content']['content'][]=['type'=>'paragraph',
 'content'=>[['type'=>'text','text'=>'Airport pickup/drop-off time subject to flight review; no supplier confirmed.']]];

$grid=VtaAiItinerary::defaultGrid();
$grid[0]['prices']['3star']='250.50';
$grid[2]['prices']['4star']='390.00';
$payload=[
 'schema'=>VtaAiItinerary::SCHEMA,
 'source_program_id'=>$programId,
 'source_revision'=>TourProgramWorkspace::revision($sourceBefore),
 'title'=>$candidate['title'],
 'document'=>$candidate,
 'days'=>TourLibrary::publicProgram($sourceBefore)['days'],
 'private_groups'=>$grid,
 'currency'=>'USD',
 'agent'=>'chatgpt',
 'selling_prices_reviewed'=>true,
 'creation_key'=>'vta-ai-native-'.bin2hex(random_bytes(12)),
 'review_acknowledged'=>true
];
$preview=VtaAiItinerary::preview($db,$user,$programId,$payload+['layout'=>'brochure']);
$payload['review_token']=$preview['review_token'];
check(($preview['changed']['document']??false)===true&&str_contains($preview['preview_html'],'mode-brochure'),
 'AI native preview displays altered rich text in brochure layout');
check(str_contains($preview['preview_html'],'USD 250.50')&&str_contains($preview['preview_html'],'On request'),
 'AI native preview displays only reviewed USD selling and On request');
check(TourProgramWorkspace::program($db,$user,$programId)===$sourceBefore,
 'AI native preview never mutates the source Master');
$sourceOther=$user;
$sourceOther['company_id']=(int)$user['company_id']+999;
rejects(fn()=>VtaAiItinerary::preview($db,$sourceOther,$programId,$payload),OutOfBoundsException::class,
 'AI native cross-tenant source inspection fails closed');

$created=VtaAiItinerary::commit($db,$user,$programId,$payload);
$newId=(int)$created['id'];
check($newId!==$programId&&!($created['replayed']??false),
 'AI native commit creates an independent new Master id');
$replayed=VtaAiItinerary::commit($db,$user,$programId,$payload);
check((int)$replayed['id']===$newId&&($replayed['replayed']??false),
 'AI native repeated commit returns original draft by idempotency key');
check((int)v2q('SELECT COUNT(*) FROM tour_library_programs WHERE company_id=?',[(int)$user['company_id']])->fetchColumn()===$masterRowCount+1,
 'AI native retries never create a second draft');
check(TourProgramWorkspace::program($db,$user,$programId)===$sourceBefore,
 'AI native commit preserves source row, status, document and revision exactly');

$newRow=TourProgramWorkspace::program($db,$user,$newId);
$newData=TourLibrary::publicProgram($newRow);
check($newData['status']==='DRAFT'&&$newData['title']===$candidate['title']
 &&$newData['proposal']['private_prices_reviewed']===true,
 'AI native new Master is DRAFT and selling approval metadata persists');
check(count($newData['proposal']['private_prices'])===5
 &&$newData['proposal']['private_prices'][0]['min']===4
 &&$newData['proposal']['private_prices'][4]['max']===12,
 'AI native 4/6/8/10/12 private groups survive database roundtrip');
check($newData['proposal']['private_prices'][0]['three']==='250.50',
 'AI native 3-star USD selling amount survives persistence');
check($newData['proposal']['private_prices'][2]['four']==='390.00',
 'AI native 4-star USD selling amount survives persistence');
check(!str_contains(json_encode($newData,JSON_UNESCAPED_UNICODE),'PRIVATE_CANARY'),
 'AI native public-derived Master does not leak internal quote canary');

$ctx=TourProgramWorkspace::context($db,$user,$newRow);
$document=TourProgramWorkspace::document($newRow);
$exportDoc=VtaAiItinerary::exportDocument($document,$grid,true);
$media=TourProgramWorkspace::images($db,$user,$document);
$public=['quote_ref'=>'PROGRAM-'.$newId,'tour_name'=>$newData['title'],
 'presentation'=>['settings'=>['document'=>$exportDoc],'media'=>$media]];
$path=fn($id)=>MediaLibrary::path($cfg,MediaLibrary::asset($db,$user,(int)$id));
$word=ProposalOutput::docx($public,$path);
file_put_contents($output.'/vta-ai-itinerary-price.docx',$word);
check(strlen($word)>2000&&substr($word,0,2)==='PK',
 'AI native real DOCX bytes generated through existing exporter');
$reimport=DocumentParser::proposal($output.'/vta-ai-itinerary-price.docx','docx');
check(str_contains($reimport['text'],'250.50')&&str_contains($reimport['text'],'390.00')
 &&str_contains($reimport['text'],'On request'),
 'AI native DOCX reimport includes approved prices and unfilled cells');
$pdf=ProposalOutput::pdf($public,$cfg,$path);
file_put_contents($output.'/vta-ai-itinerary-price.pdf',$pdf);
check(strlen($pdf)>500&&str_starts_with($pdf,'%PDF-'),
 'AI native real PDF bytes generated through existing exporter');
$publicHtml=ProposalOutput::html($public,static fn($id)=>'image/test');
check(str_contains($publicHtml,'USD 250.50')&&str_contains($publicHtml,'Private Group Selling Price'),
 'AI native HTML export contains same five-row table as DOCX/PDF pipeline');
check(v2q('SELECT * FROM quote_sent_bundles ORDER BY quote_version_id')->fetchAll()===$origIssuedRows
 &&v2q('SELECT * FROM booking_quote_snapshots ORDER BY booking_id')->fetchAll()===$origBusinessRows,
 'AI native preview and commit preserve issued/booking historical snapshots');

$modified=$payload;$modified['source_revision']=str_repeat('0',64);
rejects(fn()=>VtaAiItinerary::commit($db,$user,$programId,$modified),DomainException::class,
 'AI native commit refuses stale source hash without writing');
$sourceNow=TourProgramWorkspace::program($db,$user,$programId);
check($sourceNow===$sourceBefore,'AI native rejected writes never change source Master');

echo "AI Itinerary isolated MariaDB + real document export acceptance complete.\n";

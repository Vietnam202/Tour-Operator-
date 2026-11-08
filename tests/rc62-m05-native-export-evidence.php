<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
require_once __DIR__.'/../api/lib/MediaLibrary.php';
require_once __DIR__.'/../api/lib/ProposalOutput.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
require_once __DIR__.'/../api/lib/VtaProposalExportEvidence.php';

function verify(bool $pass,string $label):void
{
    if(!$pass)throw new RuntimeException('FAIL M05 '.$label);
}
function command(string $binary,array $args):string
{
    $cmd=escapeshellcmd($binary);
    foreach($args as $arg)$cmd.=' '.escapeshellarg($arg);
    exec($cmd.' 2>&1',$lines,$status);
    if($status!==0)throw new RuntimeException('FAIL M05 external proof '.$binary.': '.implode("\n",$lines));
    return implode("\n",$lines);
}
function hasCode(array $report,string $code):bool
{
    return in_array($code,array_column($report['alerts'],'code'),true);
}
function exportAndProof(array $snapshot,string $prefix,string $dir,string $photo):array
{
    $source=hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
    $imagePath=static function(int $id) use ($photo):string {
        if($id!==77)throw new OutOfBoundsException('Synthetic approved asset not in fixture.');
        return $photo;
    };
    $docx=ProposalOutput::docx($snapshot,$imagePath,false);
    $pdf=ProposalOutput::pdf($snapshot,['storage'=>['local_path'=>$dir.'/storage']],$imagePath,false);
    $html=ProposalOutput::html($snapshot,fn($id)=>'/approved-image-'.$id.'.jpg',false);
    verify(str_starts_with($pdf,'%PDF-') && str_starts_with($docx,'PK'),'genuine PDF/DOCX bytes');
    $docxPath=$dir.'/'.$prefix.'.docx';$pdfPath=$dir.'/'.$prefix.'.pdf';
    file_put_contents($docxPath,$docx);file_put_contents($pdfPath,$pdf);
    $pdfInfo=command('pdfinfo',[$pdfPath]);
    verify(preg_match('/^Pages:\s*(\d+)$/m',$pdfInfo,$m)===1,'pdfinfo confirms page count');
    $pages=(int)$m[1];
    $a4=(bool)preg_match('/^Page size:\s*595(?:\.\d+)? x 841(?:\.\d+)? pts \(A4\)$/m',$pdfInfo);
    $textPath=$dir.'/'.$prefix.'.txt';
    command('pdftotext',['-layout','-enc','UTF-8',$pdfPath,$textPath]);
    $pdfText=file_get_contents($textPath);
    verify(is_string($pdfText)&&strlen($pdfText)>100,'actual PDF contains extractable searchable text');
    command('pdftoppm',['-f','1','-l','1','-scale-to','850','-png','-singlefile',$pdfPath,$dir.'/'.$prefix.'-page1']);
    verify(filesize($dir.'/'.$prefix.'-page1.png')>5000,'actual PDF first page rasterized for visual review');
    verify($source===hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),'source snapshot unchanged by renderer');
    return [$docxPath,$pdfPath,$html,['pdf_text'=>$pdfText,'pdf_pages'=>$pages,'pdf_a4'=>$a4]];
}

$out=getenv('VTA_M05_OUTPUT_DIR');
if(!is_string($out)||!preg_match('~^/[^[:cntrl:]]{4,240}$~D',$out))throw new RuntimeException('Private absolute VTA_M05_OUTPUT_DIR required.');
if(!is_dir($out)&&!mkdir($out,0700,true))throw new RuntimeException('Cannot make private test output.');
RuntimeGuard::privatePath($out);
if(!extension_loaded('zip')||!class_exists(DOMDocument::class)||!function_exists('imagecreatetruecolor')){
    throw new RuntimeException('Native ZIP + XML + GD required for real DOCX/PDF evidence.');
}
$im=imagecreatetruecolor(800,450);
imagefill($im,0,0,imagecolorallocate($im,90,150,195));
imagejpeg($im,$out.'/approved-fixture.jpg',85);imagedestroy($im);
$photo=$out.'/approved-fixture.jpg';
$blocks=[
    ['type'=>'brand','text'=>'SYNTHETIC VIETNAM TRAVEL ADVISOR'],
    ['type'=>'title','text'=>'SYNTHETIC Vietnam 4D3N'],
    ['type'=>'text','text'=>'QUOTE-TEST-007 · 6 paying plus 1 FOC'],
    ['type'=>'heading','text'=>'Detailed Itinerary'],
];
for($d=1;$d<=4;$d++){
    $blocks[]=['type'=>'heading','text'=>'Day '.$d.' - Verified Demo Day '.$d];
    $blocks[]=['type'=>'text','text'=>'Fixture only. The tour content is synthetic and has no supplier guarantee.'];
}
$blocks[]=['type'=>'image','asset_id'=>77,'width'=>800,'height'=>450,'width_pct'=>75,'caption'=>'Approved Fixture Photo'];
$rows=[];
for($i=1;$i<=18;$i++)$rows[]=[(string)$i,'Hotel 3-star','Cruise 5-star','On request','No supplier quote activated'];
$blocks[]=['type'=>'table','headers'=>['Group','Hotel','Cruise','Price','Basis'],'rows'=>$rows];
$blocks[]=['type'=>'pageBreak'];
$blocks[]=['type'=>'heading','text'=>'Cancellation Policy'];
$blocks[]=['type'=>'text','text'=>'SYNTHETIC cancellation terms require supplier confirmation.'];
$blocks[]=['type'=>'text','text'=>'END OF QUOTATION'];
$must=['SYNTHETIC Vietnam 4D3N','QUOTE-TEST-007','Day 1 - Verified Demo Day 1',
    'Day 4 - Verified Demo Day 4','Approved Fixture Photo','END OF QUOTATION'];
$forbidden=['INTERNAL_SUPPLIER_COST_CANARY_ABC123','PRIVATE_GUEST_ACCOUNT_CANARY_DEF456'];
$expected=['public_markers'=>$must,'forbidden_markers'=>$forbidden,
    'minimum_pdf_pages'=>2,'minimum_images'=>1,'minimum_tables'=>1,'minimum_docx_page_breaks'=>1];
$evidence=[];
foreach(VtaProposalExportEvidence::TEMPLATES as $i=>$template){
    $settings=['template'=>$template,'brand_mode'=>$template==='VTA_B2B_WHITE_LABEL'?'WHITE_LABEL':'VTA'];
    if($template==='VTA_B2B_WHITE_LABEL'){
        $settings['brand_name']='SYNTHETIC PARTNER A';$settings['brand_color']='#163b60';
    }
    $snapshot=['quote_ref'=>'QUOTE-TEST-007','tour_name'=>'SYNTHETIC Vietnam 4D3N',
        'presentation'=>['settings'=>$settings,'blocks'=>$blocks]];
    $serial=json_encode($snapshot,JSON_THROW_ON_ERROR);
    [$docxPath,$pdfPath,$html,$proof]=exportAndProof($snapshot,'m05-'.($i+1).'-'.$template,$out,$photo);
    $r=VtaProposalExportEvidence::inspect($snapshot,$docxPath,$pdfPath,$html,$proof,$expected);
    verify($r['verdict']==='STRUCTURE_AND_MARKERS_PASS_VISUAL_REVIEW_PENDING',
        'four genuine DOCX/PDF template artifacts accepted: '.$template.' '.json_encode($r['alerts']));
    verify($r['docx_tables']>=1&&$r['docx_embedded_images']>=1&&$r['docx_explicit_page_breaks']>=1,'document keeps table/photo/page break');
    verify($r['pdf_pages_externally_verified']>=2,'PDF is multi-page with correct text');
    verify($r['docx_sha256']===hash_file('sha256',$docxPath)&&$r['pdf_sha256']===hash_file('sha256',$pdfPath),'binary SHA256 audit matches real bytes');
    verify($r['source_sha256']===hash('sha256',$serial),'source immutable fingerprint recorded');
    $reopened=DocumentParser::proposal($docxPath,'docx');
    verify(str_contains($reopened['text'],'Day 4 - Verified Demo Day 4')&&count($reopened['images'])===1,
        'real DOCX reimports Day 4 text and embedded photo');
    $evidence[]=$r;
}

// A fifth fixture proves the current Word/Tiptap VTA_DOC_2 canvas remains editable.
$text=static fn(string $value):array=>['type'=>'text','text'=>$value];
$freeform=['schema'=>'VTA_DOC_2','title'=>'SYNTHETIC Owner Word',
    'content'=>['type'=>'doc','content'=>[
        ['type'=>'heading','attrs'=>['level'=>2],'content'=>[$text('SYNTHETIC Owner Word')]],
        ['type'=>'paragraph','content'=>[$text('Day 1 - Preserved Editable Word Text')]],
        ['type'=>'pageBreak'],
        ['type'=>'paragraph','content'=>[$text('END OF WORD DOCUMENT')]],
    ]]];
$wordSnapshot=['quote_ref'=>'WORD-TEST-007','tour_name'=>'SYNTHETIC Owner Word',
    'presentation'=>['settings'=>['template'=>'VTA_STANDARD_B2B','document'=>$freeform]]];
[$docxWord,$pdfWord,$htmlWord,$proofWord]=exportAndProof($wordSnapshot,'m05-5-saved-word',$out,$photo);
$wordExpected=['public_markers'=>['SYNTHETIC Owner Word','Day 1 - Preserved Editable Word Text','END OF WORD DOCUMENT'],
    'forbidden_markers'=>$forbidden,'minimum_pdf_pages'=>2,'minimum_docx_page_breaks'=>1];
$wordReport=VtaProposalExportEvidence::inspect($wordSnapshot,$docxWord,$pdfWord,$htmlWord,$proofWord,$wordExpected);
verify($wordReport['verdict']==='STRUCTURE_AND_MARKERS_PASS_VISUAL_REVIEW_PENDING','saved Word export reopens and passes structural parity');
verify(str_contains(DocumentParser::proposal($docxWord,'docx')['text'],'Preserved Editable Word Text'),'real Word document remains editable/re-importable');
verify($wordSnapshot['presentation']['settings']['document']===$freeform,'saved Word source unchanged by outputs');

// Negative: a synthetic internal supplier-cost canary injected in real rendered
// exports must be detected in BOTH DOCX + pdftotext output + HTML preview.
$leaked=$blocks;
$leaked[]=['type'=>'text','text'=>'INTERNAL_SUPPLIER_COST_CANARY_ABC123'];
$dirty=['quote_ref'=>'QUOTE-TEST-007','tour_name'=>'SYNTHETIC Vietnam 4D3N',
    'presentation'=>['settings'=>['template'=>'VTA_STANDARD_B2B'],'blocks'=>$leaked]];
[$dDirty,$pDirty,$hDirty,$eDirty]=exportAndProof($dirty,'m05-negative-leak',$out,$photo);
$check=VtaProposalExportEvidence::inspect($dirty,$dDirty,$pDirty,$hDirty,$eDirty,$expected);
verify($check['verdict']==='BLOCKED_REVIEW'
    && hasCode($check,'CONFIDENTIAL_MARKER_IN_DOCX')
    && hasCode($check,'CONFIDENTIAL_MARKER_IN_PDF')
    && hasCode($check,'CONFIDENTIAL_MARKER_IN_HTML'),'real exported supplier canary blocked in all 3 formats');
$badField=$dirty;$badField['rate_sources']=[['INTERNAL_RATE_METADATA'=>true]];
$badFieldReport=VtaProposalExportEvidence::inspect($badField,$dDirty,$pDirty,$hDirty,$eDirty,$expected);
verify(hasCode($badFieldReport,'PRIVATE_FIELD_IN_PUBLIC_SNAPSHOT'),'supplier rate metadata must not be present in public snapshot');
$noPdfProof=VtaProposalExportEvidence::inspect($dirty,$dDirty,$pDirty,$hDirty,[],$expected);
verify(hasCode($noPdfProof,'TRUSTED_PDF_TEXT_PROOF_MISSING') && hasCode($noPdfProof,'TRUSTED_PDF_PAGE_COUNT_MISSING'),
    'cannot infer PDF verification from MIME bytes alone');
try {
    VtaProposalExportEvidence::inspect($dirty,$dDirty,$pDirty,$hDirty,$eDirty,
        ['public_markers'=>['Word Test'],'forbidden_markers'=>[]]);
    throw new RuntimeException('Empty forbidden scope accepted');
} catch (InvalidArgumentException $e) {
    verify(str_contains($e->getMessage(),'forbidden'),'explicit confidentiality scope is mandatory');
}
file_put_contents($out.'/m05-evidence-summary.json',
    json_encode(['status'=>'SYNTHETIC_FIXTURE_ONLY','real_templates'=>count($evidence),
        'source_immutability'=>true,'native_export'=>true,'visual_acceptance'=>'NOT_PROVEN',
        'templates'=>$evidence,'saved_word'=>$wordReport],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS M05: four native template DOCX/PDF pairs, one saved editable Word pair, real Poppler PDF proof, image/table/break, strict canary and immutable source; PIXEL VISUAL REVIEW PENDING\n";

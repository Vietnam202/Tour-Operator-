<?php
declare(strict_types=1);

/**
 * M07 independent native Word/PDF parity evidence from PartnerHub::output.
 * Synthetic records only; 100% offline and no production quote/provider.
 * Real binary export, real DOCX XML re-open, real Poppler PDF text extraction.
 */
require_once __DIR__.'/../api/lib/ScheduleImport.php';
require_once __DIR__.'/../api/lib/PartnerHub.php';
require_once __DIR__.'/../api/lib/ProposalOutput.php';

function assertM07(bool $pass,string $label):void
{
    if(!$pass)throw new RuntimeException('M07 native export FAIL: '.$label);
}
function normalizeM07(string $text):string
{
    return preg_replace('/\s+/u',' ',trim($text))??trim($text);
}
function runM07(string $name,array $args):string
{
    $command=escapeshellcmd($name);
    foreach($args as $v)$command.=' '.escapeshellarg((string)$v);
    exec($command.' 2>&1',$log,$status);
    if($status!==0)throw new RuntimeException('M07 external tool failed: '.$name.' '.implode("\n",$log));
    return implode("\n",$log);
}
function docxTextM07(string $filename):string
{
    $zip=new ZipArchive();
    assertM07($zip->open($filename)===true,'DOCX is a genuine ZIP document');
    $xml=$zip->getFromName('word/document.xml');
    $zip->close();
    assertM07(is_string($xml)&&strlen($xml)>400,'real Word document XML present');
    $doc=new DOMDocument();
    assertM07($doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING),
        'native DOCX WordprocessingML reopens safely');
    $xp=new DOMXPath($doc);
    $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $texts=[];
    foreach($xp->query('//w:t') as $node)$texts[]=$node->textContent;
    assertM07($xp->query('//w:tbl')->length>0,'Word export has actual editable table');
    return normalizeM07(implode(' ',$texts));
}

$dir=getenv('VTA_M07_OUTPUT_DIR');
if(!is_string($dir)||!str_starts_with($dir,'/')||strlen($dir)>220)
    throw new RuntimeException('Absolute isolated output directory required');
if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create test output directory');
assertM07(class_exists(ZipArchive::class)&&class_exists(DOMDocument::class),
    'native PHP zip/dom required');

$content=PartnerHub::normalizeCopy([
    'title'=>'SYNTHETIC VIETNAM 3D2N','destination'=>'Hanoi, Halong','language'=>'en',
    'tour_code'=>'SYNTHETIC-001','tour_type'=>'PRIVATE',
    'overview'=>'Private three day sightseeing, subject to operator confirmation.',
    'days'=>[
        ['title'=>'Hanoi Arrival','description'=>'Synthetic arrival transfer','meals'=>'','overnight'=>'Hanoi'],
        ['title'=>'Halong Bay','description'=>'Synthetic sightseeing cruise','meals'=>'B','overnight'=>'Halong'],
        ['title'=>'Return Hanoi','description'=>'Synthetic return to Hanoi','meals'=>'B','overnight'=>''],
    ],
    'highlights'=>['SYNTHETIC Golden Bridge','SYNTHETIC Halong'],
    'included_text'=>'Included services are not independently confirmed',
    'excluded_text'=>'Flights excluded',
    'terms_text'=>'Subject to availability and written confirmation',
    'policies'=>['children'=>'','payment'=>'','cancellation'=>'','notes'=>''],
    'supplier_costs'=>['SECRET_SUPPLIER_COST_123456'],
    'internal_notes'=>'SECRET_INTERNAL_NOTE_123456',
]);
$work=['id'=>711,'content_json'=>json_encode($content,JSON_THROW_ON_ERROR),
       'markup_type'=>'PERCENT','markup_value'=>'15.00'];
$rate=json_encode(['valid_until'=>'2027-12-31','cells'=>[
    ['mode'=>'PRIVATE','hotel'=>'3','cruise'=>'5','pax_min'=>6,'pax_max'=>8,
     'currency'=>'USD','net_per_pax'=>9876.54],
]],JSON_THROW_ON_ERROR);
$pub=['status'=>'PUBLISHED','rate_json'=>$rate,'version_no'=>2];
$agency=['brand_name'=>'SYNTHETIC PARTNER ALPHA','brand_color'=>'#163b60',
    'email'=>'agency@example.invalid','whatsapp'=>'','logo_path'=>null];
$params=['pax'=>6,'mode'=>'PRIVATE','hotel'=>'3','cruise'=>'5'];
$method=new ReflectionMethod(PartnerHub::class,'output');
$sourceHash=hash('sha256',json_encode([$work,$pub,$agency,$params],JSON_THROW_ON_ERROR));
$evidence=[];
foreach(['client','agency','itinerary'] as $audience){
    $snap=$method->invoke(null,$work,$agency,$pub,$params,$audience);
    $html=ProposalOutput::html($snap,static fn(int $id):string=>'no-image-expected',false);
    $docx=ProposalOutput::docx($snap,static function(int $id):string{
        throw new RuntimeException('No media in synthetic fixture');
    },false);
    $pdf=ProposalOutput::pdf($snap,['storage'=>['local_path'=>$dir.'/storage']],static function(int $id):string{
        throw new RuntimeException('No media in synthetic fixture');
    },false);
    assertM07(str_starts_with($docx,'PK')&&str_starts_with($pdf,'%PDF-'),'real native DOCX/PDF for '.$audience);
    $docPath=$dir.'/m07-'. $audience.'.docx';
    $pdfPath=$dir.'/m07-'. $audience.'.pdf';
    file_put_contents($docPath,$docx);file_put_contents($pdfPath,$pdf);
    $docText=docxTextM07($docPath);
    $info=runM07('pdfinfo',[$pdfPath]);
    assertM07(preg_match('/^Pages:\s*(\d+)/m',$info,$p)===1&&(int)$p[1]>=1,
        'actual PDF has pages: '.$audience);
    $textPath=$dir.'/m07-'.$audience.'.txt';
    runM07('pdftotext',['-layout','-enc','UTF-8',$pdfPath,$textPath]);
    $pdfText=normalizeM07((string)file_get_contents($textPath));
    $htmlText=normalizeM07(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    foreach([$docText,$pdfText,$htmlText] as $text){
        assertM07(str_contains($text,'SYNTHETIC PARTNER ALPHA'),'agency white label exists: '.$audience);
        assertM07(str_contains($text,'SYNTHETIC VIETNAM 3D2N'),'verified public itinerary title: '.$audience);
        assertM07(!str_contains($text,'SECRET_SUPPLIER_COST_123456')
            &&!str_contains($text,'SECRET_INTERNAL_NOTE_123456'),'no hidden cost/notes: '.$audience);
        assertM07(!str_contains($text,'Vietnam Travel Advisor'),
            'Partner white-label does not inject VTA corporate brand: '.$audience);
        if($audience==='client'||$audience==='itinerary'){
            assertM07(!str_contains($text,'VTA NET / pax')&&!str_contains($text,'9876.54'),
                'client/itinerary documents contain no agency NET: '.$audience);
            assertM07(!str_contains($text,'markup_value')&&!str_contains($text,'margin_amount'),
                'no commercial internals in client export: '.$audience);
        }elseif($audience==='agency'){
            assertM07(str_contains($text,'VTA NET / pax')&&str_contains($text,'9876.54'),
                'only agency working copy includes authorized B2B NET');
        }
    }
    if($audience==='itinerary'){
        foreach([$docText,$pdfText,$htmlText] as $text)
            assertM07(!str_contains($text,'Client Price Offer')&&!str_contains($text,'11358.02'),
                'itinerary-only export does not invent or disclose price');
    }
    runM07('pdftoppm',['-f','1','-l','1','-scale-to','850','-png','-singlefile',
        $pdfPath,$dir.'/m07-'.$audience.'-page1']);
    assertM07(filesize($dir.'/m07-'.$audience.'-page1.png')>4000,
        'real PDF first-page PNG for manual review');
    $evidence[]=[
        'audience'=>$audience,
        'docx_sha256'=>hash_file('sha256',$docPath),
        'pdf_sha256'=>hash_file('sha256',$pdfPath),
        'pdf_pages'=>(int)$p[1],
        'word_editable_table'=>true,
        'partner_white_label'=>true,
        'client_net_leak'=>false,
        'owner_visual_acceptance'=>'PENDING',
    ];
}
assertM07($sourceHash===hash('sha256',json_encode([$work,$pub,$agency,$params],JSON_THROW_ON_ERROR)),
    'source, partner rates and markup remain immutable by native exporter');
file_put_contents($dir.'/m07-native-b2b-evidence.json',json_encode([
    'status'=>'SYNTHETIC_REAL_BINARY_ONLY','templates'=>$evidence,
    'source_unchanged'=>true,
    'deployment'=>'NOT_PERFORMED',
    'owner_visual_acceptance'=>'PENDING',
],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS M07 real native B2B Client/Agency/Itinerary DOCX+PDF+HTML export: genuine files, text parity, agency-only NET, no supplier secret, white-label metadata and immutable source\n";

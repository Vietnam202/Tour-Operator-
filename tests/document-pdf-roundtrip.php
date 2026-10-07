<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
require_once __DIR__.'/../api/lib/MediaLibrary.php';
require_once __DIR__.'/../api/lib/ProposalOutput.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
require_once __DIR__.'/../api/lib/ScheduleImport.php';
function pdfCheck(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name."\n";}
$output=getenv('VS22_OUTPUT_DIR')?:throw new RuntimeException('Set VS22_OUTPUT_DIR to the native output fixture');
$f=json_decode(file_get_contents($output.'/ux-fixture.json'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$f['database']))throw new RuntimeException('Local native fixture required');
// No database connection: exercise real output and extraction from synthetic public content.
$blocks=[['type'=>'title','text'=>'Vietnam 7D6N'],['type'=>'text','text'=>'Duration: 7D6N'],['type'=>'heading','text'=>'Detailed Itinerary']];
for($i=1;$i<=7;$i++){$blocks[]=['type'=>'heading','text'=>'Day '.$i.' - Journey '.$i.' (B/L/D)'];$blocks[]=['type'=>'text','text'=>"Morning visit.\nOvernight: Hanoi"];}
$blocks[]=['type'=>'heading','text'=>'Included'];$blocks[]=['type'=>'text','text'=>'Reviewed accommodation'];
$path=$output.'/ux-searchable-seven-days.pdf';
file_put_contents($path,ProposalOutput::pdf(['tour_name'=>'Vietnam 7D6N','quote_ref'=>'LOCAL-PDF-ONLY','presentation'=>['blocks'=>$blocks]],$f['config'],fn($a)=>'',true));
$extracted=DocumentParser::proposal($path,'pdf');
pdfCheck($extracted['quality']==='MEDIUM'&&str_contains($extracted['text'],'Morning visit.'),'UX real searchable PDF extraction returns editable itinerary text');
pdfCheck(!str_contains($extracted['text'],"\f")&&str_contains($extracted['text'],'Day 7 - Journey 7'),'P0 PDF page breaks are normalized without dropping next-page day headings');
$parsed=ScheduleImport::documentPreview($extracted['text'],$extracted);
pdfCheck(count($parsed['days'])===7&&$parsed['source_numbers']===range(1,7),'UX searchable PDF preview identifies all seven detailed days');
pdfCheck($parsed['days'][0]['meals']==='B/L/D'&&isset($parsed['sections']['included']),'UX searchable PDF preview maps meals and public policy');
$rich=DocumentParser::proposal($output.'/ux-rich.pdf','pdf');
pdfCheck($rich['quality']==='MEDIUM'&&str_contains($rich['text'],'visit'),'UX saved rich proposal PDF exports searchable prose');
$docx=DocumentParser::proposal($output.'/proposal.docx','docx');
pdfCheck(count($docx['images'])===3&&count($docx['blocks'])>0,'UX DOCX image candidates and ordered blocks survive real export');
echo "Document PDF/DOCX roundtrip acceptance complete.\n";

<?php
declare(strict_types=1);
// Isolated filesystem/parser contract. Never reads existing production files.
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
require_once __DIR__.'/../api/lib/TourLibrary.php';

function checkStored(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
function denyStored(callable $fn,string $label):void {
    try{$fn();}catch(OutOfBoundsException|DomainException|InvalidArgumentException $e){checkStored(true,$label);return;}
    throw new RuntimeException('FAIL '.$label);
}
if(!class_exists('ZipArchive')||!class_exists('DOMDocument'))throw new RuntimeException('DOCX requires zip + DOM test extensions');
$root=sys_get_temp_dir().'/vta-library-source-'.bin2hex(random_bytes(6));
$library=$root.'/tour-library';
if(!mkdir($library,0700,true))throw new RuntimeException('Cannot initialize temporary fixture');
$filename=$library.'/rich.docx';
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jLx8AAAAASUVORK5CYII=',true);
$xml='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
 .'<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>Hanoi 7D6N rich itinerary</w:t></w:r><w:r><w:drawing><a:blip r:embed="rIdPic1"/></w:drawing></w:r></w:p>'
 .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Hotel 3 stars</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Cruise 5 stars</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
 .'</w:body></w:document>';
$rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdPic1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/></Relationships>';
try {
    $zip=new ZipArchive();if($zip->open($filename,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not make fixture DOCX');
    $zip->addFromString('word/document.xml',$xml);
    $zip->addFromString('word/_rels/document.xml.rels',$rels);
    $zip->addFromString('word/media/image1.png',$png);
    $zip->close();
    $cfg=['storage'=>['local_path'=>$root]];
    $p=['source_storage_path'=>$filename,'source_name'=>'Vietnam rich.docx','source_mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','source_sha256'=>hash_file('sha256',$filename)];
    $verified=TourLibrary::verifiedSource($cfg,$p);
    checkStored($verified['path']===realpath($filename)&&$verified['extension']==='docx','verified original Word source resolves inside private Tour Library only');
    $doc=DocumentParser::proposal($verified['path'],$verified['extension'],true);
    checkStored(str_contains($doc['text'],'Hanoi 7D6N')&&str_contains($doc['text'],'Cruise 5 stars'),'saved source retains Unicode itinerary and independent hotel/cruise table text');
    checkStored(count(array_filter($doc['blocks'],fn($b)=>$b['type']==='heading'))===1
        &&count(array_filter($doc['blocks'],fn($b)=>$b['type']==='table'))===1,'DOCX preserved headings and table as editable typed blocks');
    checkStored(count($doc['images'])===1&&count(array_filter($doc['blocks'],fn($b)=>$b['type']==='image_candidate'))===1,
        'DOCX embedded image is exposed for separate scoped Media Library upload');
    denyStored(fn()=>TourLibrary::verifiedSource($cfg,array_replace($p,['source_sha256'=>str_repeat('0',64)])),
        'checksum mismatch fails closed before Word parser');
    denyStored(fn()=>TourLibrary::verifiedSource($cfg,array_replace($p,['source_name'=>'wrong.pdf'])),
        'forged source extension cannot reclassify private DOCX');
    $outside=$root.'/outside.docx';copy($filename,$outside);
    denyStored(fn()=>TourLibrary::verifiedSource($cfg,array_replace($p,['source_storage_path'=>$outside])),
        'private path outside tour-library folder refused');
    denyStored(fn()=>TourLibrary::verifiedSource($cfg,array_replace($p,['source_storage_path'=>$root.'/missing.docx'])),
        'missing preserved source refuses silent fallback');
    echo 'Saved DOCX source Word round-trip contract PASS (synthetic only).'.PHP_EOL;
} finally {
    foreach([$filename,$root.'/outside.docx'] as $file)if(is_file($file))unlink($file);
    if(is_dir($library))rmdir($library);
    if(is_dir($root))rmdir($root);
}

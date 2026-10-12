<?php
declare(strict_types=1);
// Pure synthetic Word fixture. No private/customer file, no DB, no external service.
require_once __DIR__.'/../api/lib/QuoteOptions.php';
require_once __DIR__.'/../api/lib/TourLibrary.php';
require_once __DIR__.'/../api/lib/DocumentParser.php';
function richCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('FAIL '.$why);echo 'PASS '.$why.PHP_EOL;}
if(!class_exists(ZipArchive::class)||!class_exists(DOMDocument::class))throw new RuntimeException('Requires ZipArchive and DOM');
$dir=sys_get_temp_dir().'/vta-rich-library-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700))throw new RuntimeException('Temporary directory unavailable');
try{
    // Actual Office XML, not a mocked parser. 50 independently named DOCX files.
    $xmlHead='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
    $xmlTail='</w:body></w:document>';
    $docs=[];
    for($n=1;$n<=50;$n++){
        $name=sprintf('Vietnam-Trip-%02d.docx',$n);
        $file=$dir.'/'.$name;$zip=new ZipArchive();
        if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create synthetic DOCX');
        $xml=$xmlHead.
            '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Trip '.sprintf('%02d',$n).' Hà Nội</w:t></w:r></w:p>'.
            '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/><w:i/><w:color w:val="CC3300"/><w:sz w:val="36"/></w:rPr><w:t>Bold itinerary</w:t></w:r></w:p>'.
            '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Day</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Destination</w:t></w:r></w:p></w:tc></w:tr>'.
            '<w:tr><w:tc><w:p><w:r><w:t>1</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Ha Long</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'.
            $xmlTail;
        $zip->addFromString('word/document.xml',$xml);$zip->close();
        TourLibrary::validateFile($file,'docx');
        $raw=DocumentParser::proposal($file,'docx',true);
        $draft=TourLibrary::richDocxDraft($raw,'Trip '.sprintf('%02d',$n));
        richCheck(isset($draft['proposal']['document']),'DOCX '.$n.' creates editable Word canvas');
        $document=$draft['proposal']['document'];
        $nodes=$document['content']['content'];
        richCheck(($nodes[0]['type']??'')==='heading'
            &&($nodes[1]['attrs']['textAlign']??'')==='center'
            &&($nodes[1]['content'][0]['marks'][0]['type']??'')==='bold'
            &&($nodes[2]['type']??'')==='table','DOCX '.$n.' preserves heading, text formatting and rectangular table');
        $stored=TourLibrary::normalize(['title'=>'Trip '.sprintf('%02d',$n),'language'=>'vi','status'=>'DRAFT',
            'source_name'=>$name,'source_type'=>'PC','proposal'=>$draft['proposal'],'days'=>[]]);
        $reopened=json_decode(json_encode($stored,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true,512,JSON_THROW_ON_ERROR);
        richCheck($reopened['proposal']['document']===$document,'DOCX '.$n.' typed document roundtrips through library JSON storage');
        $docs[]=hash_file('sha256',$file);
    }
    richCheck(count(array_unique($docs))===50,'50 synthetic sources have independent checksums');
    $withImage=TourLibrary::richDocxDraft(['blocks'=>[
        ['type'=>'paragraph','runs'=>[['text'=>'Before image','bold'=>true]]],
        ['type'=>'image_candidate','image_index'=>0]
    ],'images'=>[['name'=>'unapproved.jpg','base64'=>'untrusted']]],
        'Tour with original photo');
    richCheck(count($withImage['proposal']['document']['content']['content'])===1
        &&!str_contains(json_encode($withImage,JSON_THROW_ON_ERROR),'untrusted')
        &&str_contains(implode(' ', $withImage['warnings']),'private original'),
        'Unapproved embedded image bytes are never exposed or assigned fake media IDs');
    richCheck(!isset(TourLibrary::richDocxDraft(['blocks'=>[], 'images'=>[]],'Empty')['proposal']),
        'Unreadable DOCX remains reviewable source, never invents Word blocks');
    echo "PASS 50 DOCX rich-import and save/reopen contract fixtures; no MariaDB or production data".PHP_EOL;
}finally{foreach(glob($dir.'/*')?:[] as $p)@unlink($p);@rmdir($dir);}

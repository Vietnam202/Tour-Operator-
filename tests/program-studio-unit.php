<?php
declare(strict_types=1);
require __DIR__.'/tour-library.php';
function studioCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$cell=fn(string $s)=>['type'=>'tableCell','content'=>[['type'=>'paragraph','content'=>[['type'=>'text','text'=>$s]]]]];
$document=['schema'=>'VTA_DOC_2','title'=>'Hà Nội – Hạ Long','content'=>['type'=>'doc','content'=>[
 ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Ngày 2 · Chỉnh sửa riêng']]],
 ['type'=>'paragraph','content'=>[['type'=>'text','text'=>'  Nội dung giữ nguyên khoảng trắng  ','marks'=>[['type'=>'bold']]]]],
 ['type'=>'table','content'=>[['type'=>'tableRow','content'=>[$cell('Giờ'),$cell('Hoạt động')]],['type'=>'tableRow','content'=>[$cell('09:00'),$cell('Chương trình đã sửa')]]]],
 ['type'=>'image','attrs'=>['assetId'=>99,'width'=>320]],['type'=>'pageBreak']
]]];
$canonical=FreeformDocument::validate($document);$original="Nguồn Word nguyên bản\nIncluded: Hotel";
$encoded=TourLibrary::documentSource($original,$canonical);$reopened=TourLibrary::readDocumentSource($encoded);
studioCheck($reopened['source_text']===$original,'Studio preserves original source independently of edited document');
studioCheck($reopened['document']===$canonical,'Library storage/reopen preserves Unicode, formatting, tables, image IDs and page breaks');
studioCheck(TourLibrary::readDocumentSource($original)===['source_text'=>$original],'Existing plain-text programs need no migration');
$richRow=$row;$richRow['source_text']=$encoded;
$public=TourLibrary::publicProgram($richRow);studioCheck($public['document']===$canonical&&$public['source_text']===$original,'Detail API exposes canonical content and unwrapped source text');
$metadata=TourLibrary::publicProgram($richRow,false);studioCheck($metadata['has_document']&&!isset($metadata['document'],$metadata['source_text']),'Gallery response excludes heavy document/source content');
studioCheck(!isset($public['source_storage_path'],$public['source_sha256']),'Studio adds no private filesystem or checksum disclosure');
$hash=$public['content_hash'];$changed=$richRow;$changed['source_text']=TourLibrary::documentSource($original,array_replace($canonical,['title'=>'Changed']));
studioCheck(TourLibrary::contentHash($changed)!==$hash,'Conflict token changes with rich document, not only timestamps');
$plain=TourLibrary::normalize(array_replace($base,['days'=>[],'status'=>'DRAFT','document'=>$canonical]));studioCheck($plain['document']===$canonical&&$plain['days']===[],'Freeform program has no forced day-card reconstruction');
rejects(fn()=>TourLibrary::normalize(array_replace($base,['document'=>['schema'=>'VTA_DOC_2','content'=>['type'=>'doc','content'=>[['type'=>'script']]]]])),'Executable document node cannot enter library storage');
rejects(fn()=>TourLibrary::normalize(array_replace($base,['source_text'=>$encoded])),'Client cannot forge a storage envelope through raw source text');
class StudioScopePDO extends ScopePDO {
 public array $stored;
 public function rowFor(string $sql,array $params):mixed{if(str_contains($sql,'FROM tour_library_programs')&&$params===[7,101])return $this->stored;return false;}
}
$db=new StudioScopePDO();$db->stored=$richRow;
rejects(fn()=>TourLibrary::save($db,$u,array_replace($base,['expected_content_hash'=>str_repeat('0',64)]),101),'Stale Studio save rejects before any write',DomainException::class);
studioCheck($db->writes===0&&$db->rolledBack,'Conflict preserves the stored program and rolls back');
echo "Studio storage/guard contract passed; no live MariaDB used.\n";

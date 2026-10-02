<?php
declare(strict_types=1);
final class ScheduleImport {
 public static function date(string $s): DateTimeImmutable {$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);if(!$d||$d->format('Y-m-d')!==$s)throw new InvalidArgumentException('Valid travel date required');return $d;}
 public static function normalize($days): array {
  if(!is_array($days)||!array_is_list($days)||count($days)>90)throw new InvalidArgumentException('Maximum 90 itinerary days');
  $out=[];foreach($days as $i=>$day){
   if(!is_array($day))throw new InvalidArgumentException('Invalid itinerary day');
   $c=['day'=>$i+1];foreach(['date','title','description','meals','overnight','notes'] as $k){
    $v=$day[$k]??'';if(!is_string($v)||strlen($v)>($k==='description'?60000:($k==='notes'?4000:1000)))throw new InvalidArgumentException('Invalid itinerary text');
    $c[$k]=trim($v);
   }if($c['date']!=='')self::date($c['date']);$out[]=$c;
  }return $out;
 }
 public static function parse(string $text,string $start=''): array {
  if(strlen($text)>1000000||!preg_match('//u',$text))throw new InvalidArgumentException('Import requires UTF-8 text under 1 MB');
  $text=trim(str_replace(["\r\n","\r","\xEF\xBB\xBF"],["\n","\n",''],$text));
  if($text==='')throw new InvalidArgumentException('No text extracted; paste or enter the itinerary manually');
  $date=$start!==''?self::date($start):null;$days=[];$preamble=[];$current=null;$numbers=[];
  $sections=['included_text'=>'','excluded_text'=>'','terms_text'=>''];$found=array_fill_keys(array_keys($sections),false);$active=null;
  $headings=[
   'included_text'=>'Included|Includes|Inclusions|Price Includes|Price Included|Services Included|Bao gồm|Giá bao gồm|Dịch vụ bao gồm',
   'excluded_text'=>'Excluded|Excludes|Exclusions|Price Excludes|Price Excluded|Services Excluded|Not Included|Không bao gồm|Giá không bao gồm|Dịch vụ không bao gồm',
   'terms_text'=>'Terms|Terms and Conditions|Terms & Conditions|Booking Terms|Điều khoản|Điều kiện|Điều kiện và điều khoản|Điều kiện đặt tour|Payment Terms|Cancellation Policy|Điều kiện thanh toán|Điều kiện hủy'
  ];
  foreach(explode("\n",$text) as $line){
   $heading=false;
   foreach($headings as $key=>$aliases){
    if(preg_match('/^\s*('.$aliases.')\s*(?:[:：]\s*(.*))?\s*$/iu',$line,$section)){
     $active=$key;$found[$key]=true;$heading=true;
     $label=trim($section[1]);$content=trim($section[2]??'');
     if($key==='terms_text'&&preg_match('/^(?:Payment Terms|Cancellation Policy|Điều kiện thanh toán|Điều kiện hủy)$/iu',$label))$content=$label.($content!==''?"\n".$content:'');
     if($content!=='')$sections[$key].=($sections[$key]!==''?"\n\n":'').$content;
     break;
    }
   }
   if($heading)continue;
   if(preg_match('/^\s*(?:Day|Ngày)\s*(\d{1,3})(?=\s|[:.\-–—]|$)\s*[:.\-–—]?\s*(.*)$/iu',$line,$m)){
    $active=null;
    if($current!==null)$days[]=$current;$numbers[]=(int)$m[1];$i=count($days);
    $current=['day'=>$i+1,'date'=>$date?$date->modify("+$i days")->format('Y-m-d'):'','title'=>trim($m[2]),'description'=>'','meals'=>'','overnight'=>''];
    if(preg_match('/\((B(?:\s*[\/,]\s*[LD]){0,2}|L(?:\s*[\/,]\s*D)?|D)\)\s*$/i',$current['title'],$meal))$current['meals']=$meal[1];
   }elseif($active!==null){$sections[$active].=($sections[$active]===''?'':"\n").$line;
   }elseif($current===null){$preamble[]=$line;}else{
    $current['description'].=($current['description']===''?'':"\n").$line;
    if(preg_match('/^\s*(?:Meals?|Bữa ăn|Ăn)\s*[:：]\s*(.+)$/iu',$line,$m))$current['meals']=trim($m[1]);
    if(preg_match('/^\s*(?:Overnight(?:\s+in)?|Nghỉ đêm(?:\s+tại)?|Lưu trú)\s*[:：]\s*(.+)$/iu',$line,$m))$current['overnight']=trim($m[1]);
   }
  }if($current!==null)$days[]=$current;
  if(!$days)throw new InvalidArgumentException('No Day / Ngày headings found; use manual entry');
  $warnings=['Review all descriptions, meals, overnight locations and dates before applying.'];
  if(trim(implode("\n",$preamble))!=='')$warnings[]='Text before the first day remains in the source preview.';
  if($numbers!==range(1,count($numbers)))$warnings[]='Source day numbers are not sequential; preview has been renumbered.';
  foreach($sections as $key=>$value){$sections[$key]=trim($value);if($found[$key]&&$sections[$key]==='')$warnings[]='Empty '.$key.' section detected; review before replacing existing content.';}
  return ['status'=>'DRAFT','days'=>self::normalize($days),'source_text'=>$text,'warnings'=>$warnings,'sections_found'=>$found]+$sections;
 }
 public static function upload(array $file,string $start=''): array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Upload failed');
  if(($file['size']??0)>10485760)throw new InvalidArgumentException('File must be under 10 MB');
  $ext=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));
  if(!in_array($ext,['docx','pdf','txt'],true))throw new InvalidArgumentException('Use DOCX, PDF or TXT');
  if($ext==='docx'&&!class_exists('ZipArchive'))throw new InvalidArgumentException('DOCX import requires PHP ZipArchive; paste text instead');
  $r=DocumentParser::extract($file['tmp_name'],$ext);if(strlen($r['text'])>=250000)throw new InvalidArgumentException('Extracted text is too long; split the document before importing');$out=self::parse($r['text'],$start);
  $out['extraction_quality']=$r['quality'];$out['warnings'][]=$r['note'];return $out;
 }
 public static function handle(string $route,string $method,PDO $db,array $u): void {
  if($route!=='schedule/preview'||$method!=='POST')return;Auth::requirePermission($db,$u,'quote.edit');
  try{$b=isset($_FILES['file'])?$_POST:Http::body();$out=isset($_FILES['file'])?self::upload($_FILES['file'],(string)($b['start_date']??'')):self::parse((string)($b['text']??''),(string)($b['start_date']??''));Http::json(['ok'=>true]+$out);}
  catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
 }
}


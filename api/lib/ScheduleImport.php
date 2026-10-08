<?php
declare(strict_types=1);
final class ScheduleImport {
 /** Preview only. Candidates never become rates, prices or guest quantities. */
 public static function documentPreview(string $text,array $extracted=[]):array {
  if(strlen($text)>250000||!preg_match('//u',$text))throw new InvalidArgumentException('Use UTF-8 proposal text under 250 KB');
  $text=trim(str_replace(["\r\n","\r","\xEF\xBB\xBF"],["\n","\n",''],$text));$blocks=$extracted['blocks']??[];
  if(!$blocks)foreach(explode("\n",$text) as $line){$line=trim($line);if($line==='')continue;$level=preg_match('/^(#{1,6})\s+(.+)$/u',$line,$h)?strlen($h[1]):0;if($level)$line=$h[2];
   if(preg_match('/^\|(.+)\|$/u',$line,$t)){$cells=array_map('trim',explode('|',$t[1]));if(!array_filter($cells,fn($c)=>!preg_match('/^:?-+:?$/D',$c)))continue;$row=array_map(fn($c)=>[['text'=>$c,'bold'=>false,'italic'=>false]],$cells);if(($blocks[array_key_last($blocks)]['type']??'')==='table')$blocks[array_key_last($blocks)]['rows'][]=$row;else $blocks[]=['type'=>'table','rows'=>[$row]];continue;}
   $list=preg_match('/^(?:([-•*])\s+|(\d+)[.)]\s+)(.+)$/u',$line,$l);$raw=$list?$l[3]:$line;$runs=[];foreach(preg_split('/(\*\*[^*]+\*\*|\*[^*]+\*)/u',$raw,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY) as $part)$runs[]=['text'=>trim($part,'*'),'bold'=>str_starts_with($part,'**'),'italic'=>str_starts_with($part,'*')&&!str_starts_with($part,'**')];
   $blocks[]=$list?['type'=>'list','ordered'=>!empty($l[2]),'items'=>[$runs]]:['type'=>$level?'heading':'paragraph','level'=>$level?:2,'runs'=>$runs];
  }
  $aliases=[
   'briefing'=>'Briefing|Overview|Tour Overview|Tổng quan', 'highlights'=>'Highlights|Điểm nổi bật',
   'schedule'=>'Schedule|Schedule Summary|Short Schedule|Lịch trình tóm tắt|Lịch trình tổng quan',
   'price'=>'Best Price Offer|Price|Price Table|Package Rate|Pricing|Giá tour|Bảng giá',
   'services'=>'Services|Accommodation|Services / Accommodation|Hotel(?:s)?(?: / Cruise)?|Hotel and Cruise|Cruise|Dịch vụ|Lưu trú',
   'itinerary'=>'Detailed Itinerary|Itinerary|Chi tiết lịch trình|Lịch trình chi tiết',
   'included'=>'Included|Includes|Inclusions|Price Includes|Price Included|Services Included|Bao gồm|Giá bao gồm|Dịch vụ bao gồm',
   'excluded'=>'Excluded|Excludes|Exclusions|Not Included|Price Excludes|Price Excluded|Services Excluded|Không bao gồm|Giá không bao gồm',
   'children'=>'Children Policy|Child Policy|Chính sách trẻ em', 'payment'=>'Payment Terms|Payment Policy|Booking / Payment Terms|Booking Terms|Điều kiện thanh toán|Chính sách thanh toán',
   'cancellation'=>'Cancellation Policy|Cancellation Terms|Điều kiện hủy|Chính sách hủy',
   'terms'=>'Terms|Terms and Conditions|Terms & Conditions|General Terms|Điều khoản|Điều kiện và điều khoản', 'notes'=>'Important Notes|Notes|Lưu ý'
  ];
  $out=['status'=>'PREVIEW','title'=>'','days'=>[],'sections'=>[],'candidates'=>[],'source_schedule'=>[],'source_numbers'=>[],'warnings'=>$extracted['warnings']??[],'images'=>$extracted['images']??[],'image_positions'=>[],'extraction_quality'=>$extracted['quality']??'HIGH','source_text'=>$text];$active='briefing';$current=null;
  foreach($blocks as $b){
   if($b['type']==='image_candidate'){$out['image_positions'][]=['image_index'=>$b['image_index'],'day_index'=>$current];continue;}
   $plain=isset($b['runs'])?implode('',array_column($b['runs'],'text')):($b['type']==='list'?implode('',array_column($b['items'][0]??[],'text')):implode(" | ",array_map(fn($r)=>implode('',array_column($r,'text')),$b['rows'][0]??[])));
   $plain=trim($plain);$heading=false;
   if($b['type']==='table')$out['warnings'][]='Table classification requires review; source cell order retained.';
   if($b['type']==='heading'&&preg_match('/^(?:Internal|Supplier Cost|Profit|Margin|Costing Notes|Nội bộ|Chi phí nhà cung cấp)\b/iu',$plain)){$active='private';$current=null;$out['warnings'][]='Internal / supplier-cost section excluded from public document. Review the source.';continue;}
   if($active==='private'&&$b['type']!=='heading')continue;
   foreach($aliases as $key=>$regex)if(preg_match('~^(?:'.$regex.')\s*(?:[:：]\s*(.*))?$~iu',$plain,$h)){$active=$key;$current=null;$heading=true;$out['sections'][$key]??=[];if(trim($h[1]??'')!=='')$out['sections'][$key][]=['type'=>'paragraph','runs'=>[['text'=>trim($h[1]),'bold'=>false,'italic'=>false]]];break;}
   if($heading)continue;
   if(preg_match('/^(?:Day|Ngày)\s*(\d{1,3})\s*[:.\-–—]?\s*(.*)$/iu',$plain,$day)){
    $title=trim($day[2]);$meals='';if(preg_match('/\((B(?:\s*[\/,]\s*[LD]){0,2}|L(?:\s*[\/,]\s*D)?|D)\)\s*$/i',$title,$m)){$meals=str_replace([' ',','],['','/'],$m[1]);$title=trim(substr($title,0,-strlen($m[0])));}
    if($active==='schedule'){$out['source_schedule'][]=['number'=>(int)$day[1],'title'=>$title];continue;}
    $active='itinerary';$current=count($out['days']);$out['source_numbers'][]=(int)$day[1];$out['days'][]=['source_day'=>(int)$day[1],'title'=>$title,'date'=>'','meals'=>$meals,'overnight'=>'','blocks'=>[]];continue;
   }
   if($active==='itinerary'&&$current!==null){
    if(preg_match('/^(?:Meals?|Bữa ăn|Ăn)\s*[:：]\s*(.+)$/iu',$plain,$m)){$out['days'][$current]['meals']=trim($m[1]);continue;}
    if(preg_match('/^(?:Overnight(?: in)?|Nghỉ đêm(?: tại)?|Lưu trú)\s*[:：]\s*(.+)$/iu',$plain,$m)){$out['days'][$current]['overnight']=trim($m[1]);continue;}
    $out['days'][$current]['blocks'][]=$b;continue;
   }
   if($out['title']===''&&$active==='briefing'&&!preg_match('/^(?:Pax|Guests?|FOC|Tour type|Duration|Hotel|Cruise|Meals?)\s*:/iu',$plain)){$out['title']=$plain;continue;}
   $out['sections'][$active]??=[];$out['sections'][$active][]=$b;
  }
  if(preg_match('/\b(\d{1,3})\s*D\s*(\d{1,3})\s*N\b/i',$text,$m))$out['candidates']['duration_days']=(int)$m[1];
  elseif(preg_match('/(?:Duration|Thời lượng)\s*[:：]\s*(\d+)\s*(?:days?|ngày)/iu',$text,$m))$out['candidates']['duration_days']=(int)$m[1];
  foreach(['tour_type'=>'Tour type|Loại tour','guests'=>'Pax|Guests?|Adults?|Số khách','foc'=>'FOC','hotel_cruise'=>'Hotel(?: / Cruise)?|Cruise|Khách sạn'] as $key=>$labels)if(preg_match('~^(?:'.$labels.')\s*[:：]\s*(.+)$~imu',$text,$m))$out['candidates'][$key]=trim($m[1]);
  if(!$out['days'])$out['warnings'][]='Needs Review: no detailed Day headings found. Source remains editable; add or identify Day headings before saving.';
  if($out['source_numbers']&&$out['source_numbers']!==range(1,count($out['source_numbers'])))$out['warnings'][]='Day numbering conflict: duplicate, missing or out-of-order Day. No source numbering was silently corrected.';
  if(isset($out['candidates']['duration_days'])&&$out['candidates']['duration_days']!==count($out['days']))$out['warnings'][]='Duration differs from detected detailed days.';
  if($out['source_schedule']){foreach($out['source_schedule'] as $s){$match=array_values(array_filter($out['days'],fn($d)=>$d['source_day']===$s['number']));if(count($match)!==1||$match[0]['title']!==$s['title']){$out['warnings'][]='Schedule title/number differs from Detailed Day '.$s['number'].'.';break;}}}
  if(!empty($out['sections']['price']))$out['warnings'][]='Imported price text/table is a review reference only. Reviewed Smart Costing prices remain authoritative.';
  if(!empty($out['candidates']))$out['warnings'][]='Detected guest/FOC/duration/accommodation values are candidates only; existing guests, rates, prices and service requirements are unchanged.';
  foreach($out['days'] as &$d){$d['blocks']=QuoteProposal::richBlocks($d['blocks']);$d['description']=trim(QuoteProposal::blockText($d['blocks']));}unset($d);
  foreach($out['sections'] as &$s)$s=QuoteProposal::richBlocks($s);unset($s);
  if(($out['extraction_quality']??'')==='LOW'||$text==='')$out['warnings'][]='Needs Review: PDF may be scanned or extraction unavailable. Paste searchable text; no content was invented.';
  if(count($out['days'])>90)throw new InvalidArgumentException('Maximum 90 itinerary days');$out['warnings']=array_values(array_unique($out['warnings']));return $out;
 }
 public static function documentHtmlPreview(string $html):array {
  $parsed=DocumentParser::richHtmlBlocks($html);$text=implode("\n",array_map(fn($block)=>QuoteProposal::blockText([$block]),$parsed['blocks']));
  return self::documentPreview($text,['blocks'=>$parsed['blocks'],'warnings'=>$parsed['warnings'],'quality'=>'HIGH','note'=>'Pasted formatting was converted to editable text, headings, lists, tables and inline styles. Review the result before replacing the current draft.']);
 }
 public static function documentUpload(array $file):array {
  if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>10485760)throw new InvalidArgumentException('Upload DOCX/PDF/TXT under 10 MB');
  $ext=strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION));if(!in_array($ext,['docx','pdf','txt'],true))throw new InvalidArgumentException('Use DOCX, PDF or TXT');$r=DocumentParser::proposal($file['tmp_name'],$ext);$out=self::documentPreview($r['text'],$r);$out['warnings'][]=$r['note'];return $out;
 }
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


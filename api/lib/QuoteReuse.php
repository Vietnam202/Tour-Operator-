<?php
declare(strict_types=1);
final class QuoteReuse {
 private static function q(PDO $db,string $s,array $a=[]): PDOStatement {$q=$db->prepare($s);$q->execute($a);return $q;}
 private static function insert(PDO $db,string $table,array $data): int {self::q($db,"INSERT INTO $table(".implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));return (int)$db->lastInsertId();}
 public static function copyLine(array $line,array $target,bool $full,int $offset=0): array {
  $basis=$line['charge_basis']??$line['rate_snapshot']['rate_basis']??'PAYING_PAX';
  $pax=QuoteCostItems::defaultPax($target,$basis);
  if(!in_array($basis,['PAYING_PAX','PER_PAX','TOTAL_GUESTS'],true))$pax=(float)($line['pax']??1);
  $unit=$full?(float)($line['unit_price']??0):0.0;$qty=(float)($line['qty']??1);
  return ['category'=>$line['category']??'OTHER','service_name'=>(string)($line['service_name']??'Service'),'service_date'=>ScheduleImport::date($target['start_date'])->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d'),'pax'=>$pax,'qty'=>$qty,'unit_price'=>$unit,'currency'=>$line['currency']??'VND','total'=>round($pax*$qty*$unit,2),'supplier_id'=>$line['supplier_id']??null,'source_type'=>'LEGACY','rate_version_id'=>null,'rate_snapshot'=>null,'charge_basis'=>$basis,'review_required'=>true,'manual_reason'=>'Copied cost; refresh current travel-date rates or explicitly review this manual cost.'];
 }
 public static function clone(PDO $db,array $u,int $source,int $inquiry,string $mode): array {
  if(!in_array($mode,['PROGRAM_ONLY','PROGRAM_COST','FULL_DRAFT'],true))throw new InvalidArgumentException('Invalid reuse mode');
  $db->beginTransaction();try{
   $old=QuoteOptions::version($db,(int)$u['company_id'],$source,true);
   $target=self::q($db,'SELECT t.*,i.id target_inquiry FROM inquiries i JOIN trips t ON t.id=i.trip_id WHERE i.company_id=? AND t.company_id=? AND i.id=? FOR UPDATE',[$u['company_id'],$u['company_id'],$inquiry])->fetch();
   if(!$target)throw new OutOfBoundsException('Target inquiry not found');
   ScheduleImport::date((string)$target['start_date']);ScheduleImport::date((string)$target['end_date']);
   if($target['paying_pax']<1||$target['total_guests']<$target['paying_pax']+$target['foc'])throw new InvalidArgumentException('Correct target inquiry dates and paying pax first');
   $scrub=function($text)use($old){$s=(string)$text;foreach(['lead_contact_name','lead_email','lead_whatsapp'] as $k){$v=trim((string)($old[$k]??''));if(strlen($v)>2)$s=str_ireplace($v,'',$s);}return trim($s);};
   $schedule=ScheduleImport::normalize(json_decode($old['schedule_json']??'[]',true)?:[]);
   foreach($schedule as $i=>&$day){unset($day['notes']);$day['date']=ScheduleImport::date($target['start_date'])->modify("+$i days")->format('Y-m-d');foreach(['title','description','meals','overnight'] as $k)$day[$k]=$scrub($day[$k]);}unset($day);
   $quote=self::insert($db,'quotes',['company_id'=>$u['company_id'],'quote_ref'=>'PENDING-'.bin2hex(random_bytes(8)),'trip_id'=>$target['id'],'inquiry_id'=>$inquiry,'current_version_no'=>1,'status'=>'DRAFT','created_by'=>$u['id'],'updated_by'=>$u['id']]);
   $ref=sprintf('QT-%s-%06d',date('Y'),$quote);self::q($db,'UPDATE quotes SET quote_ref=? WHERE id=?',[$ref,$quote]);
   $data=['quote_id'=>$quote,'version_no'=>1,'version_status'=>'DRAFT','tour_name'=>$scrub($old['tour_name']),'cost_currency'=>'VND','selling_currency'=>'USD','fx_rate'=>$old['fx_rate'],'pricing_mode'=>'MARKUP','pricing_value'=>15,'rounding_step'=>5,'schedule_json'=>json_encode($schedule,JSON_THROW_ON_ERROR),'proposal_json'=>'{}','document_language'=>$old['document_language']??'en','created_by'=>$u['id']];
   foreach(['start_date','end_date','adults','children','infants','foc','total_guests','paying_pax'] as $k)$data[$k]=$target[$k];
   foreach(['hotel_level','tour_type','guide_language','meals'] as $k)$data[$k]=$scrub($old[$k]??'');
   if($mode==='FULL_DRAFT'){foreach(['included_text','excluded_text','terms_text'] as $k)$data[$k]=$scrub($old[$k]??'');foreach(['pricing_mode','pricing_value','rounding_step','selling_per_pax'] as $k)$data[$k]=$old[$k];}
   $version=self::insert($db,'quote_versions',$data);
   $offset=function($date)use($old):int {if(!$date||!$old['start_date'])return 0;return (int)ScheduleImport::date($old['start_date'])->diff(ScheduleImport::date($date))->format('%r%a');};
   if($mode!=='PROGRAM_ONLY'){
    $options=self::q($db,'SELECT * FROM quote_options WHERE quote_version_id=? ORDER BY hotel_level',[$source])->fetchAll();
    if($options){foreach($options as $option){
     $snapshot=json_decode($option['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$lines=[];$cost=0;
     foreach($snapshot['lines'] as $line){
      $c=self::copyLine($line,$target,$mode==='FULL_DRAFT',$offset($line['service_date']??null));$c['service_name']=$scrub($c['service_name']);$c['cost_usd']=$c['currency']==='USD'?$c['total']:round($c['total']/(float)$data['fx_rate'],2);$cost+=$c['cost_usd'];$lines[]=$c;
     }
     $p=QuoteOptions::pricing($cost,(int)$target['paying_pax'],$data['pricing_mode'],(float)$data['pricing_value'],(float)$data['rounding_step']);
     $safe=['total_guests'=>(int)$target['total_guests'],'paying_pax'=>(int)$target['paying_pax'],'foc'=>(int)$target['foc'],'fx_rate'=>(float)$data['fx_rate'],'selling_currency'=>'USD','lines'=>$lines,'pricing'=>$p,'manual_review_required'=>true,'review_required'=>true];
     self::insert($db,'quote_options',['quote_version_id'=>$version,'label'=>$option['hotel_level'],'hotel_level'=>$option['hotel_level'],'snapshot_json'=>json_encode($safe,JSON_THROW_ON_ERROR),'created_by'=>$u['id']]);
    }}else{
     foreach(self::q($db,'SELECT * FROM quote_cost_items WHERE quote_version_id=? ORDER BY sort_order,id',[$source])->fetchAll() as $line){
      $c=self::copyLine($line,$target,$mode==='FULL_DRAFT',$offset($line['service_date']??null));$c['service_name']=$scrub($c['service_name']);$c['notes']=$c['manual_reason'];$c['_copied']=true;QuoteCostItems::write($db,$u,$version,$c);
     }
    }
   }
   $p=CoreOS::quotePrice($db,$version);self::q($db,'UPDATE quote_versions SET total_cost=?,cost_per_paying_pax=?,selling_per_pax=?,total_selling=?,profit_amount=?,margin_pct=?,markup_pct=? WHERE id=?',[$p['total_cost'],$p['cost_per_paying_pax'],$p['selling_per_pax'],$p['total_selling'],$p['profit_amount'],$p['margin_pct'],$p['markup_pct'],$version]);
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],'QUOTE_REUSED','quote_version',$version,null,['source_version'=>$source,'mode'=>$mode,'target_inquiry'=>$inquiry]);
   $db->commit();return ['id'=>$quote,'version_id'=>$version,'quote_ref'=>$ref,'status'=>'DRAFT','review_required'=>$mode!=='PROGRAM_ONLY','message'=>'Review program text and refresh rates for the new travel dates before approval.'];
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function handle(string $route,string $method,PDO $db,array $u): void {
  if($method!=='POST'||!preg_match('#^quote-versions/(\d+)/reuse$#',$route,$m))return;
  Auth::requirePermission($db,$u,'quote.create');Auth::requirePermission($db,$u,'quote.edit');Auth::requirePermission($db,$u,'sales.view');
  try{$b=Http::body();if(($b['mode']??'')!=='PROGRAM_ONLY')Auth::requirePermission($db,$u,'quote.view_cost');Http::json(['ok'=>true]+self::clone($db,$u,(int)$m[1],(int)($b['inquiry_id']??0),(string)($b['mode']??'')),201);}
  catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
 }
}


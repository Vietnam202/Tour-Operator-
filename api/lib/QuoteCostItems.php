<?php
declare(strict_types=1);
require_once __DIR__.'/ScheduleImport.php';
final class QuoteCostItems {
 private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
 public static function defaultPax(array $v,string $basis='PAYING_PAX'): float {
  if(!in_array($basis,['PAYING_PAX','TOTAL_GUESTS','PER_PAX','PER_ROOM_NIGHT','PER_VEHICLE','PER_TRANSFER','PER_DAY','PER_GUIDE_DAY','PER_CABIN','PER_GROUP','PER_SERVICE'],true))throw new InvalidArgumentException('Invalid charge basis');
  return in_array($basis,['PAYING_PAX','PER_PAX'],true)?(float)$v['paying_pax']:($basis==='TOTAL_GUESTS'?(float)$v['total_guests']:1.0);
 }
 public static function write(PDO $db,array $u,int $version,array $body,?int $delete=null): array {
  $own=!$db->inTransaction();if($own)$db->beginTransaction();try{
   $v=QuoteOptions::version($db,(int)$u['company_id'],$version,true);
   if(in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true))throw new DomainException('Issued version is immutable');
   if(self::q($db,'SELECT 1 FROM quote_options WHERE quote_version_id=? LIMIT 1',[$version])->fetchColumn())throw new DomainException('Edit the selected 3*/4*/5* option costs for this quote');
   $id=(int)($body['id']??$delete??0);$old=null;
   if($id){$old=self::q($db,'SELECT * FROM quote_cost_items WHERE id=? AND quote_version_id=?',[$id,$version])->fetch();if(!$old)throw new OutOfBoundsException('Cost item not found');}
   if($delete){self::q($db,'DELETE FROM quote_cost_items WHERE id=?',[$id]);}
   else{
    $patch=$body;$body=array_replace($old??[],$body);
    $rateVersion=(int)($body['rate_version_id']??0);$basis=$body['charge_basis']??'PAYING_PAX';$rate=null;
    if($rateVersion){
     $r=self::q($db,'SELECT r.category,r.destination,rv.rate_basis FROM rates r JOIN rate_versions rv ON rv.rate_id=r.id WHERE r.company_id=? AND rv.id=?',[$u['company_id'],$rateVersion])->fetch();
     if(!$r)throw new InvalidArgumentException('Rate not found');$basis=$r['rate_basis'];
    }
    foreach(['pax','qty','unit_price'] as $k){$val=$body[$k]??($k==='pax'?self::defaultPax($v,$basis):($k==='qty'?1:0));if(!is_numeric($val)||!is_finite((float)$val)||(float)$val<0||(float)$val>100000000)throw new InvalidArgumentException('Invalid '.$k);$body[$k]=(float)$val;}
    self::defaultPax($v,$basis);
    if($body['pax']<=0||$body['qty']<=0)throw new InvalidArgumentException('Positive pax and quantity required');
    $date=$body['service_date']??$v['start_date'];ScheduleImport::date((string)$date);
    $rateId=null;$document=null;$source='MANUAL';$review=(int)($old['review_required']??0);
    if($rateVersion){
     foreach(RateEngine::match($db,(int)$u['company_id'],['category'=>$r['category'],'destination'=>$r['destination']??'','travel_date'=>$date,'market'=>$v['market']??'','pax'=>(int)$v['paying_pax'],'trip_ref'=>$v['trip_ref']]) as $candidate)if((int)$candidate['rate_version_id']===$rateVersion)$rate=$candidate;
     if(!$rate||$rate['conflict']||!in_array($rate['tax_basis'],['NET','TAX_INCLUDED'],true))throw new DomainException('Approved final rate not available for this context');
     if($rate['category']==='TRANSPORT')throw new DomainException('Use Smart Quote options for transport capacity and supplement costing');
     if($old&&((isset($patch['unit_price'])&&(float)$patch['unit_price']!==(float)$rate['effective_amount'])||(isset($patch['service_name'])&&$patch['service_name']!==$rate['product_name'])))throw new DomainException('Convert to manual with a review reason before changing an approved rate');
     $body['unit_price']=$rate['effective_amount'];$body['currency']=$rate['currency'];$body['supplier_id']=$rate['supplier_id'];$body['category']=$rate['category'];$body['service_name']=$rate['product_name'];$rateId=$rate['rate_id'];$document=$rate['source_document_id'];$source='APPROVED_RATE';$review=0;
     if($basis==='PER_PAX'&&$body['pax']!==(float)$v['total_guests']&&trim((string)($body['notes']??''))==='')throw new InvalidArgumentException('Explain costing pax override');
    }elseif(trim((string)($body['notes']??''))==='')throw new InvalidArgumentException('Manual cost requires a review reason in Notes');
    if(!empty($patch['reviewed'])){if(trim((string)($patch['notes']??''))==='')throw new InvalidArgumentException('Review reason required');$review=0;}
    // Only trusted internal callers can set this marker. HTTP bodies are filtered in handle().
    if(!empty($body['_copied'])){$review=1;$rateVersion=0;$rateId=null;$document=null;$source='LEGACY';}
    if($review)$source='LEGACY';
    $currency=$body['currency']??'VND';$category=$body['category']??'OTHER';$supplier=(int)($body['supplier_id']??0);$name=trim((string)($body['service_name']??''));
    if(!in_array($currency,['USD','VND'],true)||!in_array($category,['TRANSPORT','GUIDE','HOTEL','MEAL','ATTRACTION','TOUR','CRUISE','VISA','OTHER'],true)||$name===''||strlen($name)>255)throw new InvalidArgumentException('Valid currency, category and service name required');
    if($supplier&&!self::q($db,"SELECT id FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'",[$u['company_id'],$supplier])->fetchColumn())throw new InvalidArgumentException('Supplier must be active in this company');
    $data=['sort_order'=>(int)($body['sort_order']??0),'category'=>$category,'service_name'=>$name,'service_date'=>$date,'pax'=>$body['pax'],'qty'=>$body['qty'],'unit_price'=>$body['unit_price'],'currency'=>$currency,'total'=>round($body['pax']*$body['qty']*$body['unit_price'],2),'supplier_id'=>$supplier?:null,'rate_id'=>$rateId,'rate_version_id'=>$rateVersion?:null,'source_document_id'=>$document,'source_type'=>$source,'manual_pax'=>!empty($body['manual_pax'])?1:0,'notes'=>substr((string)($body['notes']??''),0,1000),'charge_basis'=>$basis,'review_required'=>$review];
    if($id){self::q($db,'UPDATE quote_cost_items SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($data))).' WHERE id=?',[...array_values($data),$id]);}
    else{$data=['quote_version_id'=>$version]+$data;self::q($db,'INSERT INTO quote_cost_items('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));$id=(int)$db->lastInsertId();}
   }
   $p=CoreOS::quotePrice($db,$version);self::q($db,"UPDATE quote_versions SET version_status='DRAFT',approved_by=NULL,approved_at=NULL,total_cost=?,cost_per_paying_pax=?,selling_per_pax=?,total_selling=?,profit_amount=?,margin_pct=?,markup_pct=? WHERE id=?",[$p['total_cost'],$p['cost_per_paying_pax'],$p['selling_per_pax'],$p['total_selling'],$p['profit_amount'],$p['margin_pct'],$p['markup_pct'],$version]);self::q($db,"UPDATE quotes SET status='DRAFT',updated_by=? WHERE id=?",[$u['id'],$v['quote_id']]);
   Audit::log($db,(int)$u['company_id'],(int)$u['id'],$delete?'QUOTE_COST_REMOVED':($old?'QUOTE_COST_UPDATED':'QUOTE_COST_ADDED'),'quote_cost_item',$id,null,['version_id'=>$version]);if($own)$db->commit();return ['id'=>$id,'pricing'=>$p];
  }catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function bulk(PDO $db,array $u,int $version,array $items): array {
  if(!array_is_list($items)||!$items||count($items)>200)throw new InvalidArgumentException('One to 200 cost rows required');
  $db->beginTransaction();try{$ids=[];foreach($items as $item){if(!is_array($item))throw new InvalidArgumentException('Invalid cost row');unset($item['_copied']);$out=self::write($db,$u,$version,$item);$ids[]=$out['id'];}$db->commit();return ['ids'=>$ids,'pricing'=>$out['pricing']];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function duplicate(PDO $db,array $u,int $version,int $id): array {
  $db->beginTransaction();try{$v=QuoteOptions::version($db,(int)$u['company_id'],$version,true);$row=self::q($db,'SELECT * FROM quote_cost_items WHERE id=? AND quote_version_id=?',[$id,$version])->fetch();if(!$row)throw new OutOfBoundsException('Cost item not found');
   unset($row['id']);$row['rate_version_id']=null;$row['_copied']=true;$row['notes']='Copied cost; review current travel dates and rates.';$out=self::write($db,$u,$version,$row);$db->commit();return $out;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 public static function assertReviewed(PDO $db,int $version): void {
  if(self::q($db,'SELECT 1 FROM quote_cost_items WHERE quote_version_id=? AND review_required=1 LIMIT 1',[$version])->fetchColumn())throw new DomainException('Review copied costs and refresh rates before approval');
 }
 public static function handle(string $route,string $method,PDO $db,array $u): void {
  try{
   if($method==='POST'&&preg_match('#^quote-versions/(\d+)/cost-items(?:/(bulk))?$#',$route,$m)){Auth::requirePermission($db,$u,'quote.edit');$b=Http::body();unset($b['_copied'],$b['id']);Http::json(['ok'=>true]+(!empty($m[2])?self::bulk($db,$u,(int)$m[1],$b['items']??[]):self::write($db,$u,(int)$m[1],$b)),201);}
   if(preg_match('#^cost-items/(\d+)(?:/(duplicate))?$#',$route,$m)&&in_array($method,['PUT','PATCH','DELETE','POST'],true)){
    Auth::requirePermission($db,$u,'quote.edit');$version=self::q($db,'SELECT c.quote_version_id FROM quote_cost_items c JOIN quote_versions v ON v.id=c.quote_version_id JOIN quotes q ON q.id=v.quote_id WHERE q.company_id=? AND c.id=?',[$u['company_id'],(int)$m[1]])->fetchColumn();if(!$version)throw new OutOfBoundsException('Cost item not found');
    if(!empty($m[2])&&$method==='POST')Http::json(['ok'=>true]+self::duplicate($db,$u,(int)$version,(int)$m[1]),201);
    if(empty($m[2])&&$method!=='POST'){$b=Http::body();unset($b['_copied']);$b['id']=(int)$m[1];Http::json(['ok'=>true]+self::write($db,$u,(int)$version,$b,$method==='DELETE'?(int)$m[1]:null));}
   }
  }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
 }
}


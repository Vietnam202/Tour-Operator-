<?php
declare(strict_types=1);
require_once __DIR__.'/SmartCosting.php';
require_once __DIR__.'/QuoteOptions.php';
require_once __DIR__.'/RateEngine.php';
require_once __DIR__.'/ScheduleImport.php';

final class QuoteSmartCosting {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
    private static function json($v): string {return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private static function tx(PDO $db,callable $fn): array {$own=!$db->inTransaction();if($own)$db->beginTransaction();try{$r=$fn();if($own)$db->commit();return $r;}catch(Throwable $e){if($own&&$db->inTransaction())$db->rollBack();throw $e;}}
    private static function mutable(PDO $db,array $v): void {
        if(in_array($v['version_status'],['SENT','CONFIRMED','SUPERSEDED'],true)||self::q($db,'SELECT 1 FROM quote_sent_bundles WHERE quote_version_id=?',[$v['id']])->fetchColumn())throw new DomainException('Issued quote is immutable; create a revision');
    }
    public static function context(PDO $db,array $v): array {
        $p=self::q($db,'SELECT visa_pax,meal_pax,hotel_pax,ticket_pax FROM quote_guest_profiles WHERE quote_version_id=?',[$v['id']])->fetch()?:[];
        $r=self::q($db,'SELECT * FROM quote_service_requirements WHERE quote_version_id=? ORDER BY sort_order,id',[$v['id']])->fetchAll();$requirements=[];
        foreach($r as $x)$requirements[]=json_decode($x['metadata_json'],true,512,JSON_THROW_ON_ERROR)+['key'=>$x['requirement_key'],'category'=>$x['category'],'service_name'=>$x['service_name'],'service_date'=>$x['service_date'],'quantity'=>(int)$x['quantity'],'mode'=>$x['service_mode'],'scope'=>$x['scope']];
        return ['guest_profile'=>$p,'guests'=>SmartCosting::guests($v,$p),'requirements'=>$requirements,'route_scope_hash'=>SmartCosting::scopeHash($v)];
    }
    private static function resolve(PDO $db,array $v,array $g,array $lines): array {
        $out=[];
        foreach($lines as $l){
            if(in_array(strtoupper((string)($l['source_type']??$l['origin']??'')),['AI','AI_ESTIMATE','LLM'],true))throw new InvalidArgumentException('AI supplier prices cannot enter costing');
            // Projection prevents callers from supplying trusted price/rate-status/supplier snapshots.
            $allowed=['requirement_key','category','service_name','service_date','quantity_source','custom_qty','qty','cost_basis','unit_price','supplier_id','rate_version_id','scope','capacity','route_scope_hash','star_level','manual_reason','reason','quantity_context','included_keys','no_cost','edited','basis_review_reason'];
            $l=array_intersect_key($l,array_flip($allowed));$rv=SmartCosting::integer($l['rate_version_id']??0,PHP_INT_MAX);$l['_rate_status']='RATE NEEDED';
            if(!empty($l['no_cost'])){$l['supplier_id']=null;$l['rate_version_id']=null;}
            elseif($rv){
                $identity=self::q($db,'SELECT r.category,r.destination,rv.valid_from,rv.valid_to,rv.currency,rv.approval_status FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id WHERE r.company_id=? AND rv.id=?',[$v['company_id'],$rv])->fetch();
                if(!$identity)throw new InvalidArgumentException('Rate must belong to this company');
                if($identity['category']!==($l['category']??''))throw new InvalidArgumentException('Rate category must match cost category');
                $date=$l['service_date']??$v['start_date'];$rate=null;
                $dates=[$date];if(in_array($identity['category'],['HOTEL','GUIDE'],true))for($j=1;$j<(int)($l['qty']??1);$j++)$dates[]=ScheduleImport::date($date)->modify("+$j days")->format('Y-m-d');
                $valid=true;
                foreach($dates as $d){$found=null;foreach(RateEngine::match($db,(int)$v['company_id'],['category'=>$identity['category'],'destination'=>$identity['destination']??'','travel_date'=>$d,'pax'=>$g['paying_pax'],'market'=>$v['market']??'','trip_ref'=>$v['trip_ref']]) as $candidate)if((int)$candidate['rate_version_id']===$rv)$found=$candidate;
                    if(!$found||$found['conflict']||!in_array($found['tax_basis'],['NET','TAX_INCLUDED'],true)){$valid=false;break;}if($rate&&(float)$rate['effective_amount']!==(float)$found['effective_amount']){$valid=false;break;}$rate=$found;
                }
                $l['unit_price']=0;
                if(!$valid){$l['_rate_status']=($identity['valid_to']&&$identity['valid_to']<$date)?'EXPIRED RATE':'RATE NEEDED';}
                elseif($rate['currency']!=='VND'){$l['_rate_status']='NEEDS REVIEW';}
                else {
                    $basis=$rate['rate_basis'];$compatible=match($identity['category']){'TRANSPORT'=>in_array($basis,['PER_SERVICE','PER_TRANSFER','PER_VEHICLE'],true),'GUIDE'=>in_array($basis,['PER_DAY','PER_GUIDE_DAY'],true),'HOTEL','CRUISE','VISA','MEAL','ATTRACTION','TOUR'=>$basis==='PER_PAX',default=>$basis==='PER_SERVICE'};
                    if(!$compatible||($identity['category']==='TRANSPORT'&&trim((string)($l['basis_review_reason']??''))===''))$l['_rate_status']='NEEDS REVIEW';
                    else {$l['unit_price']=$rate['effective_amount'];$l['supplier_id']=(int)$rate['supplier_id'];$l['service_name']=$rate['product_name'];$l['_rate_status']=$rate['rate_type']==='CONTRACT'?'CONTRACT RATE':'APPROVED RATE';$l['rate_snapshot']=$rate;}
                    if($identity['category']==='TRANSPORT'){$rules=self::q($db,'SELECT capacity FROM transport_rate_rules WHERE rate_version_id=?',[$rv])->fetch();$l['_capacity']=$rules['capacity']??0;}
                    // A documented supplier star confirmation is required when the master rate has no star metadata.
                    if(in_array($identity['category'],['HOTEL','CRUISE'],true)){
                        if(preg_match('/([345])\s*(?:\*|★|star)/i',(string)($rate['option_name']??''),$m))$l['_star_level']=$m[1].'*';
                        elseif(trim((string)($l['basis_review_reason']??''))==='')$l['_star_level']='';
                    }
                }
            }elseif(array_key_exists('unit_price',$l)&&$l['unit_price']!==null&&$l['unit_price']!==''){
                $sid=SmartCosting::integer($l['supplier_id']??0,PHP_INT_MAX);
                if(!self::q($db,"SELECT 1 FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'",[$v['company_id'],$sid])->fetchColumn())throw new InvalidArgumentException('Manual supplier must be active in this company');
                if(trim((string)($l['manual_reason']??$l['reason']??''))==='')throw new InvalidArgumentException('Manual price requires reviewed supplier input reason');
                $l['unit_price']=SmartCosting::money($l['unit_price']);$l['_rate_status']='MANUAL COST';
            }
            $out[]=$l;
        }
        return $out;
    }
    public static function calculate(PDO $db,array $v,array $body): array {
        $c=self::context($db,$v);$lines=$body['lines']??[];if(!is_array($lines)||!array_is_list($lines)||count($lines)>500)throw new InvalidArgumentException('Maximum 500 cost lines');
        foreach($lines as &$line){if(!is_array($line))throw new InvalidArgumentException('Invalid cost line');$key=$line['requirement_key']??'';foreach($c['requirements'] as $r)if($r['key']===$key){$line+=['service_date'=>$r['service_date'],'qty'=>in_array($r['category'],['HOTEL','GUIDE','MEAL'],true)?$r['quantity']:1];}}
        unset($line);$fx=(float)($body['fx_rate']??$v['fx_rate']);if(!is_finite($fx)||$fx<=0||$fx>1000000)throw new InvalidArgumentException('Valid VND/USD FX snapshot required');
        return SmartCosting::calculate(self::resolve($db,$v,$c['guests'],$lines),$c['guests'],$v,$c['requirements'],$body['costing_mode']??'PRIVATE',$body['hotel_level']??'4*',$body['cruise_level']??'4*',$fx,$body['pricing']??$body);
    }
    private static function invalidate(PDO $db,array $u,array $v): void {
        self::q($db,'DELETE FROM quote_bundle_approvals WHERE quote_version_id=?',[$v['id']]);self::q($db,"UPDATE quote_versions SET version_status='DRAFT',approved_by=NULL,approved_at=NULL WHERE id=?",[$v['id']]);self::q($db,"UPDATE quotes SET status='DRAFT',updated_by=? WHERE id=?",[$u['id'],$v['quote_id']]);
        $p=CoreOS::quotePrice($db,(int)$v['id']);self::q($db,'UPDATE quote_versions SET total_cost=?,cost_per_paying_pax=?,selling_per_pax=?,total_selling=?,profit_amount=?,margin_pct=?,markup_pct=? WHERE id=?',[$p['total_cost'],$p['cost_per_paying_pax'],$p['selling_per_pax'],$p['total_selling'],$p['profit_amount'],$p['margin_pct'],$p['markup_pct'],$v['id']]);
    }
    public static function save(PDO $db,array $u,int $version,array $body): array {
        return self::tx($db,function()use($db,$u,$version,$body){$v=QuoteOptions::version($db,(int)$u['company_id'],$version,true);self::mutable($db,$v);
            $key=$body['variant_key']??'';if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$key))throw new InvalidArgumentException('Variant key required');
            $label=trim((string)($body['label']??$key));if($label===''||strlen($label)>190)throw new InvalidArgumentException('Variant label required');
            $snap=self::calculate($db,$v,$body);$prior=self::q($db,'SELECT snapshot_json FROM quote_options WHERE quote_version_id=? AND variant_key=?',[$version,$key])->fetchColumn();$prior=$prior?json_decode($prior,true):[];if(!empty($prior['review_required'])){if(!empty($body['review_copied'])&&trim((string)($body['review_reason']??''))!==''){$snap['review_required']=false;$snap['review_reason']=substr(trim($body['review_reason']),0,1000);}else $snap['review_required']=true;}$id=self::q($db,'SELECT id FROM quote_options WHERE quote_version_id=? AND variant_key=?',[$version,$key])->fetchColumn();
            // Keys are unique within a quote across hotel changes, including edit/reorder/replace requests.
            if($id)self::q($db,'UPDATE quote_options SET label=?,hotel_level=?,cruise_level=?,costing_mode=?,snapshot_json=? WHERE id=?',[$label,$snap['hotel_level'],$snap['cruise_level'],$snap['costing_mode'],self::json($snap),$id]);
            else {self::q($db,'INSERT INTO quote_options(quote_version_id,label,hotel_level,variant_key,cruise_level,costing_mode,snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?)',[$version,$label,$snap['hotel_level'],$key,$snap['cruise_level'],$snap['costing_mode'],self::json($snap),$u['id']]);$id=(int)$db->lastInsertId();}
            self::invalidate($db,$u,$v);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'VS2_COST_VARIANT_SAVED','quote_option',(int)$id,null,['variant_key'=>$key,'snapshot'=>$snap]);return ['id'=>(int)$id,'snapshot'=>$snap];
        });
    }
    public static function refresh(PDO $db,array $u,array $v): void {
        foreach(self::q($db,"SELECT * FROM quote_options WHERE quote_version_id=? AND variant_key<>''",[$v['id']])->fetchAll() as $o){$s=json_decode($o['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$review=array_intersect_key($s,array_flip(['review_required','review_reason']));$s=self::calculate($db,$v,$s)+$review;self::q($db,'UPDATE quote_options SET snapshot_json=? WHERE id=?',[self::json($s),$o['id']]);}
    }
    public static function writeContext(PDO $db,array $u,int $version,array $b): array {
        return self::tx($db,function()use($db,$u,$version,$b){$v=QuoteOptions::version($db,(int)$u['company_id'],$version,true);self::mutable($db,$v);$old=self::context($db,$v);$profile=array_replace($old['guest_profile'],$b['guest_profile']??[]);
            $g=SmartCosting::guests($v,$profile);$requirements=SmartCosting::requirements($b['requirements']??$old['requirements'],$v);
            $values=[];foreach(['visa_pax','meal_pax','hotel_pax','ticket_pax'] as $k)$values[]=isset($profile[$k])?SmartCosting::integer($profile[$k]):null;
            self::q($db,'INSERT INTO quote_guest_profiles(quote_version_id,visa_pax,meal_pax,hotel_pax,ticket_pax,updated_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE visa_pax=VALUES(visa_pax),meal_pax=VALUES(meal_pax),hotel_pax=VALUES(hotel_pax),ticket_pax=VALUES(ticket_pax),updated_by=VALUES(updated_by)',[$version,...$values,$u['id']]);
            self::q($db,'DELETE FROM quote_service_requirements WHERE quote_version_id=?',[$version]);foreach($requirements as $i=>$r)self::q($db,'INSERT INTO quote_service_requirements(quote_version_id,requirement_key,sort_order,category,service_name,service_date,quantity,service_mode,scope,metadata_json) VALUES(?,?,?,?,?,?,?,?,?,?)',[$version,$r['key'],$i,$r['category'],$r['service_name'],$r['service_date'],$r['quantity'],$r['mode'],$r['scope'],self::json(['meal_type'=>$r['meal_type'],'dietary'=>$r['dietary']])]);
            self::refresh($db,$u,$v);self::invalidate($db,$u,$v);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'VS2_SERVICE_REQUIREMENTS_UPDATED','quote_version',$version,$old,['guests'=>$g,'requirements'=>$requirements]);return self::context($db,$v);
        });
    }
    public static function validateFinal(PDO $db,array $v,array $bundle): void {
        foreach($bundle['options'] as $o){$s=$o['snapshot'];if(($s['engine']??'')!=='VS2.1')continue;
            if(!empty($s['review_required']))throw new DomainException('Review copied variant supplier costs before finalization');
            $fresh=self::calculate($db,$v,$s);unset($s['review_required'],$s['review_reason']);
            if(!$fresh['validation']['ready'])throw new DomainException('VS2_FINAL_VALIDATION: '.implode('; ',$fresh['validation']['errors']));
            if($fresh!=$s)throw new DomainException('VS2_COST_CHANGED: recalculate and review supplier rates before finalization');
        }
    }
    public static function copyContext(PDO $db,int $old,int $new,int $uid): void {
        self::q($db,'INSERT INTO quote_guest_profiles(quote_version_id,visa_pax,meal_pax,hotel_pax,ticket_pax,updated_by) SELECT ?,visa_pax,meal_pax,hotel_pax,ticket_pax,? FROM quote_guest_profiles WHERE quote_version_id=?',[$new,$uid,$old]);
        self::q($db,'INSERT INTO quote_service_requirements(quote_version_id,requirement_key,sort_order,category,service_name,service_date,quantity,service_mode,scope,metadata_json) SELECT ?,requirement_key,sort_order,category,service_name,service_date,quantity,service_mode,scope,metadata_json FROM quote_service_requirements WHERE quote_version_id=?',[$new,$old]);
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if(!preg_match('#^quote-versions/(\d+)/smart-costing(?:/(variants|preview|template)(?:/(\d+))?)?$#',$route,$m))return;
        try {
            Auth::requirePermission($db,$u,'quote.view_cost');if($method!=='GET')Auth::requirePermission($db,$u,'quote.edit');
            $v=QuoteOptions::version($db,(int)$u['company_id'],(int)$m[1]);$action=$m[2]??'';
            if($method==='GET'&&$action===''){$c=self::context($db,$v);$items=self::q($db,"SELECT * FROM quote_options WHERE quote_version_id=? AND variant_key<>'' ORDER BY costing_mode,hotel_level,cruise_level,id",[$v['id']])->fetchAll();foreach($items as &$o){$o['snapshot']=json_decode($o['snapshot_json'],true);unset($o['snapshot_json']);if(!Auth::can($db,(int)$u['id'],'quote.view_profit'))unset($o['snapshot']['pricing']['profit'],$o['snapshot']['pricing']['margin_pct'],$o['snapshot']['pricing']['markup_pct']);}unset($o);$suppliers=self::q($db,"SELECT id,name FROM suppliers WHERE company_id=? AND status='ACTIVE' ORDER BY name LIMIT 500",[$u['company_id']])->fetchAll();$rates=Auth::can($db,(int)$u['id'],'rate.view')?self::q($db,"SELECT rv.id,r.category,r.product_name,r.option_name,s.name supplier_name,rv.rate_basis FROM rate_versions rv JOIN rates r ON r.id=rv.rate_id JOIN suppliers s ON s.id=r.supplier_id AND s.company_id=r.company_id WHERE r.company_id=? AND r.status='ACTIVE' AND s.status='ACTIVE' AND rv.approval_status='APPROVED' AND rv.currency='VND' ORDER BY r.product_name,rv.version_no DESC LIMIT 500",[$u['company_id']])->fetchAll():[];Http::json(['ok'=>true]+$c+['items'=>$items,'suppliers'=>$suppliers,'rates'=>$rates]);}
            if($method==='PUT'&&$action==='')Http::json(['ok'=>true]+self::writeContext($db,$u,(int)$v['id'],Http::body()));
            if($method==='POST'&&$action==='variants'){$saved=self::save($db,$u,(int)$v['id'],Http::body());if(!Auth::can($db,(int)$u['id'],'quote.view_profit'))unset($saved['snapshot']['pricing']['profit'],$saved['snapshot']['pricing']['margin_pct'],$saved['snapshot']['pricing']['markup_pct']);Http::json(['ok'=>true]+$saved,201);}
            if($method==='POST'&&$action==='preview'){self::mutable($db,$v);$s=self::calculate($db,$v,Http::body());if(!Auth::can($db,(int)$u['id'],'quote.view_profit'))unset($s['pricing']['profit'],$s['pricing']['margin_pct'],$s['pricing']['markup_pct']);Http::json(['ok'=>true,'snapshot'=>$s]);}
            if($method==='POST'&&$action==='template'){self::mutable($db,$v);$c=self::context($db,$v);$b=Http::body();$lines=SmartCosting::template($b['costing_mode']??'PRIVATE',$c['requirements']);foreach($lines as &$l)if($l['category']==='TRANSPORT')$l['route_scope_hash']=$c['route_scope_hash'];unset($l);Http::json(['ok'=>true,'lines'=>$lines]);}
            if($method==='DELETE'&&$action==='variants'&&isset($m[3]))Http::json(['ok'=>true]+self::tx($db,function()use($db,$u,$v,$m){$v=QuoteOptions::version($db,(int)$u['company_id'],(int)$v['id'],true);self::mutable($db,$v);$s=self::q($db,"DELETE FROM quote_options WHERE quote_version_id=? AND id=? AND variant_key<>''",[$v['id'],$m[3]]);if(!$s->rowCount())throw new OutOfBoundsException('Variant not found');self::invalidate($db,$u,$v);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'VS2_VARIANT_REMOVED','quote_option',(int)$m[3]);return [];}));
            Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
    }
}

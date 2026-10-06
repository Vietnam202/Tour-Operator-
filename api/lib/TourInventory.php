<?php
declare(strict_types=1);
require_once __DIR__.'/QuoteVs2Repository.php';

final class TourInventory {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
    private static function text($value,int $limit=190,bool $required=true): string {
        if(!is_string($value)||strlen($value)>$limit||($required&&trim($value)==='')) throw new InvalidArgumentException('Invalid text field');
        return trim($value);
    }
    private static function atomic(PDO $db,callable $fn): array {
        $db->beginTransaction();try{$out=$fn();$db->commit();return $out;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function version(PDO $db,int $company,int $id,bool $lock=false): array {
        $v=self::q($db,'SELECT v.*,p.company_id,p.code FROM tour_program_versions v JOIN tour_programs p ON p.id=v.program_id WHERE p.company_id=? AND v.id=?'.($lock?' FOR UPDATE':''),[$company,$id])->fetch();
        if(!$v)throw new OutOfBoundsException('Program version not found');return $v;
    }
    public static function snapshot(PDO $db,int $company,int $id): array {
        $v=self::version($db,$company,$id);
        $days=self::q($db,'SELECT * FROM tour_days WHERE program_version_id=? ORDER BY day_no',[$id])->fetchAll();
        foreach($days as &$day)$day['services']=self::q($db,'SELECT * FROM tour_service_blueprints WHERE tour_day_id=? ORDER BY id',[$day['id']])->fetchAll();unset($day);
        return ['version'=>$v,'days'=>$days,'variants'=>self::q($db,'SELECT * FROM tour_variants WHERE program_version_id=? ORDER BY id',[$id])->fetchAll()];
    }
    public static function createVersion(PDO $db,array $user,int $program,array $body): array {
        $title=self::text($body['title']??'');$days=$body['days']??[];$variants=$body['variants']??[];
        if(!is_array($days)||count($days)<1||count($days)>90||!array_is_list($days)||!is_array($variants)||count($variants)>30)throw new InvalidArgumentException('One to 90 itinerary days required');
        return self::atomic($db,function() use($db,$user,$program,$title,$days,$variants){
            $cid=(int)$user['company_id'];$uid=(int)$user['id'];
            if(!self::q($db,'SELECT id FROM tour_programs WHERE company_id=? AND id=? FOR UPDATE',[$cid,$program])->fetchColumn())throw new OutOfBoundsException('Program not found');
            $number=(int)self::q($db,'SELECT COALESCE(MAX(version_no),0)+1 FROM tour_program_versions WHERE program_id=?',[$program])->fetchColumn();
            self::q($db,'INSERT INTO tour_program_versions(program_id,version_no,title,created_by) VALUES(?,?,?,?)',[$program,$number,$title,$uid]);$id=(int)$db->lastInsertId();
            foreach($days as $index=>$day){
                if(!is_array($day))throw new InvalidArgumentException('Invalid day');
                self::q($db,'INSERT INTO tour_days(program_version_id,day_no,title,itinerary,overnight,meals) VALUES(?,?,?,?,?,?)',[$id,$index+1,self::text($day['title']??''),self::text($day['itinerary']??'',30000),self::text($day['overnight']??'',190,false),self::text($day['meals']??'',190,false)]);$dayId=(int)$db->lastInsertId();
                $services=$day['services']??[];if(!is_array($services)||count($services)>100)throw new InvalidArgumentException('Invalid services');
                foreach($services as $service){
                    $component=self::q($db,'SELECT id,name,category,destination,customer_description FROM tour_components WHERE company_id=? AND id=?',[$cid,(int)($service['component_id']??0)])->fetch();
                    if(!$component)throw new InvalidArgumentException('Component is not in this company');
                    $qty=$service['quantity']??1;$basis=$service['pax_basis']??'TOTAL_GUESTS';
                    if(!is_numeric($qty)||(float)$qty<=0||(float)$qty>10000||!in_array($basis,['TOTAL_GUESTS','PAYING_PAX','ONE'],true))throw new InvalidArgumentException('Invalid service quantity or pax basis');
                    self::q($db,'INSERT INTO tour_service_blueprints(tour_day_id,component_id,component_snapshot_json,quantity,pax_basis) VALUES(?,?,?,?,?)',[$dayId,$component['id'],json_encode($component,JSON_THROW_ON_ERROR),$qty,$basis]);
                }
            }
            foreach($variants as $variant){
                $agent=(int)($variant['agent_id']??0);if($agent&&!self::q($db,'SELECT id FROM agents WHERE company_id=? AND id=?',[$cid,$agent])->fetchColumn())throw new InvalidArgumentException('Agent is not in this company');
                $hotel=$variant['hotel_level']??'';if(!in_array($hotel,['3*','4*','5*'],true))throw new InvalidArgumentException('Invalid hotel level');
                self::q($db,'INSERT INTO tour_variants(program_version_id,name,market,agent_id,hotel_level,meals,payment_terms) VALUES(?,?,?,?,?,?,?)',[$id,self::text($variant['name']??''),self::text($variant['market']??'',120,false),$agent?:null,$hotel,self::text($variant['meals']??'',190,false),self::text($variant['payment_terms']??'',4000,false)]);
            }
            Audit::log($db,$cid,$uid,'TOUR_VERSION_CREATED','tour_program_version',$id,null,['program_id'=>$program,'version_no'=>$number]);return ['id'=>$id,'version_no'=>$number];
        });
    }
    public static function publish(PDO $db,array $user,int $version): array {
        return self::atomic($db,function()use($db,$user,$version){
            $v=self::version($db,(int)$user['company_id'],$version,true);if($v['status']==='PUBLISHED')return ['id'=>$version,'status'=>'PUBLISHED'];
            $missing=(int)self::q($db,'SELECT COUNT(*) FROM tour_days d WHERE d.program_version_id=? AND NOT EXISTS(SELECT 1 FROM tour_service_blueprints s WHERE s.tour_day_id=d.id)',[$version])->fetchColumn();
            if($missing)throw new DomainException('Every day needs a service blueprint before publishing');
            self::q($db,"UPDATE tour_program_versions SET status='PUBLISHED',published_at=NOW() WHERE id=?",[$version]);
            Audit::log($db,(int)$user['company_id'],(int)$user['id'],'TOUR_PUBLISHED','tour_program_version',$version);return ['id'=>$version,'status'=>'PUBLISHED'];
        });
    }
    public static function useProgram(PDO $db,array $user,int $version,int $quoteVersion,?int $variant): array {
        return self::atomic($db,function()use($db,$user,$version,$quoteVersion,$variant){
            $cid=(int)$user['company_id'];$v=self::version($db,$cid,$version,true);
            if($v['status']!=='PUBLISHED')throw new DomainException('Publish the tour version first');
            $q=self::q($db,'SELECT v.*,q.id parent_quote_id,t.agent_id,t.market FROM quote_versions v JOIN quotes q ON q.id=v.quote_id JOIN trips t ON t.id=q.trip_id WHERE q.company_id=? AND v.id=? FOR UPDATE',[$cid,$quoteVersion])->fetch();
            if(!$q)throw new OutOfBoundsException('Quote not found');
            QuoteVs2Repository::legacy($q);
            if($q['version_status']!=='DRAFT')throw new DomainException('Only draft quotes can use a program');
            if(self::q($db,'SELECT 1 FROM quote_program_sources WHERE quote_version_id=?',[$quoteVersion])->fetchColumn())throw new DomainException('Program already copied; create another quote revision');
            if(json_decode($q['schedule_json']??'[]',true))throw new DomainException('Existing itinerary must not be overwritten');
            $snapshot=self::snapshot($db,$cid,$version);$chosen=null;
            if($variant){
                foreach($snapshot['variants'] as $candidate)if((int)$candidate['id']===$variant)$chosen=$candidate;
                if(!$chosen)throw new InvalidArgumentException('Variant does not belong to version');
                if($chosen['agent_id'] && (int)$chosen['agent_id']!==(int)$q['agent_id'])throw new DomainException('Agent variant does not match this trip');
                if($chosen['market'] && strcasecmp($chosen['market'],(string)$q['market'])!==0)throw new DomainException('Market variant does not match this trip');
            }
            // Explicit public fields: component/service internals stay in the private source snapshot.
            $schedule=array_map(fn($d)=>['day_no'=>(int)$d['day_no'],'title'=>$d['title'],'description'=>$d['itinerary'],'overnight'=>$d['overnight'],'meals'=>$d['meals']],$snapshot['days']);
            self::q($db,'INSERT INTO quote_program_sources(quote_version_id,program_version_id,variant_id,snapshot_json,created_by) VALUES(?,?,?,?,?)',[$quoteVersion,$version,$variant,json_encode($snapshot,JSON_THROW_ON_ERROR),$user['id']]);
            self::q($db,'UPDATE quote_versions SET tour_name=?,schedule_json=?,hotel_level=COALESCE(?,hotel_level),meals=COALESCE(?,meals),terms_text=COALESCE(?,terms_text) WHERE id=?',[$v['title'],json_encode($schedule,JSON_THROW_ON_ERROR),$chosen['hotel_level']??null,$chosen['meals']??null,$chosen['payment_terms']??null,$quoteVersion]);
            Audit::log($db,$cid,(int)$user['id'],'PROGRAM_COPIED_TO_QUOTE','quote_version',$quoteVersion,null,['program_version_id'=>$version,'variant_id'=>$variant]);return ['quote_version_id'=>$quoteVersion,'schedule'=>$schedule];
        });
    }
    public static function handle(string $route,string $method,PDO $db,array $user): void {
        if(!str_starts_with($route,'inventory/'))return;
        try {
            $copy=preg_match('#^inventory/versions/(\d+)/use$#',$route,$use);
            Auth::requirePermission($db,$user,$copy?'quote.edit':($method==='GET'?'product.view':'product.manage'));
            $cid=(int)$user['company_id'];$uid=(int)$user['id'];
            if($method==='GET' && preg_match('#^inventory/versions/(\d+)/health$#',$route,$m)){
                Auth::requirePermission($db,$user,'rate.view');
                $snapshot=self::snapshot($db,$cid,(int)$m[1]);$items=[];
                $start=$_GET['travel_date']??'';$date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$start);
                if(!$date||$date->format('Y-m-d')!==$start)throw new InvalidArgumentException('Travel date required');
                foreach($snapshot['days'] as $day)foreach($day['services'] as $service){
                    $component=json_decode($service['component_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                    $criteria=['category'=>$component['category'],'product_name'=>$component['name'],'destination'=>$component['destination'],'travel_date'=>$date->modify('+'.((int)$day['day_no']-1).' days')->format('Y-m-d'),'pax'=>$_GET['pax']??0,'market'=>$_GET['market']??''];
                    $rates=RateEngine::match($db,$cid,$criteria);
                    $items[]=['day_no'=>$day['day_no'],'component'=>$component['name'],'status'=>!$rates?'MISSING_RATE':(count($rates)>1||$rates[0]['conflict']?'REVIEW_REQUIRED':'MATCHED'),'candidates'=>$rates];
                }
                Http::json(['ok'=>true,'items'=>$items,'ready'=>count($items)>0&&count(array_filter($items,fn($i)=>$i['status']!=='MATCHED'))===0]);
            }
            if($method==='GET' && in_array($route,['inventory/programs','inventory/components'],true)){
                $table=$route==='inventory/programs'?'tour_programs':'tour_components';Http::json(['ok'=>true,'items'=>self::q($db,"SELECT * FROM $table WHERE company_id=? ORDER BY id DESC LIMIT 300",[$cid])->fetchAll()]);
            }
            if($method==='GET'&&$route==='inventory/agents')Http::json(['ok'=>true,'items'=>self::q($db,"SELECT id,company_name,market FROM agents WHERE company_id=? AND status='ACTIVE' ORDER BY company_name",[$cid])->fetchAll()]);
            if($method==='POST' && in_array($route,['inventory/programs','inventory/components'],true)){
                $b=Http::body();$name=self::text($b['name']??'');
                $result=self::atomic($db,function()use($db,$cid,$uid,$route,$b,$name){
                    if($route==='inventory/programs'){self::q($db,'INSERT INTO tour_programs(company_id,code,name,created_by) VALUES(?,?,?,?)',[$cid,self::text($b['code']??'',64),$name,$uid]);$entity='tour_program';}
                    else {
                        $cat=$b['category']??'';if(!in_array($cat,['HOTEL','TRANSPORT','GUIDE','CRUISE','ATTRACTION','MEAL','TOUR','VISA','OTHER'],true))throw new InvalidArgumentException('Invalid category');
                        self::q($db,'INSERT INTO tour_components(company_id,name,category,destination,customer_description,created_by) VALUES(?,?,?,?,?,?)',[$cid,$name,$cat,self::text($b['destination']??'',160),self::text($b['customer_description']??'',30000),$uid]);$entity='tour_component';
                    }
                    $id=(int)$db->lastInsertId();Audit::log($db,$cid,$uid,'INVENTORY_CREATED',$entity,$id);return ['id'=>$id];
                });Http::json(['ok'=>true]+$result,201);
            }
            if(preg_match('#^inventory/programs/(\d+)/versions$#',$route,$m)){
                if($method==='POST')Http::json(['ok'=>true]+self::createVersion($db,$user,(int)$m[1],Http::body()),201);
                if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT v.* FROM tour_program_versions v JOIN tour_programs p ON p.id=v.program_id WHERE p.company_id=? AND p.id=? ORDER BY v.version_no DESC',[$cid,(int)$m[1]])->fetchAll()]);
            }
            if($method==='GET' && preg_match('#^inventory/versions/(\d+)$#',$route,$m))Http::json(['ok'=>true]+self::snapshot($db,$cid,(int)$m[1]));
            if($method==='POST' && preg_match('#^inventory/versions/(\d+)/publish$#',$route,$m))Http::json(['ok'=>true]+self::publish($db,$user,(int)$m[1]));
            if($method==='POST' && $copy){Auth::requirePermission($db,$user,'product.view');$b=Http::body();Http::json(['ok'=>true]+self::useProgram($db,$user,(int)$use[1],(int)($b['quote_version_id']??0),!empty($b['variant_id'])?(int)$b['variant_id']:null));}
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
require_once __DIR__.'/BookingReadiness.php';

final class OperationsControl {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return BookingIntegrity::query($db,$sql,$args);}
    private static function tx(PDO $db,callable $fn): array {BookingIntegrity::begin($db);try{$r=$fn();$db->commit();return $r;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    private static function text($v,int $max=190): string {if(!is_string($v)||trim($v)===''||strlen($v)>$max)throw new InvalidArgumentException('Required text missing or too long');return trim($v);}
    private static function time($v): string {$d=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v):false;if(!$d||$d->format('Y-m-d H:i:s')!==$v)throw new InvalidArgumentException('Use local time YYYY-MM-DD HH:MM:SS');return $v;}
    public static function assign(PDO $db,array $u,int $service,array $body): array {
        $start=self::time($body['starts_at']??'');$end=self::time($body['ends_at']??'');if($end<=$start)throw new InvalidArgumentException('End must follow start');$rid=(int)($body['resource_id']??0);
        return self::tx($db,function()use($db,$u,$service,$start,$end,$rid){
            $booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'service',$service);
            $resource=self::q($db,"SELECT * FROM operation_resources WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$u['company_id'],$rid])->fetch();if(!$resource)throw new OutOfBoundsException('Resource not found');
            $s=self::q($db,'SELECT s.* FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND s.id=? FOR UPDATE',[$u['company_id'],$service])->fetch();if(!$s)throw new OutOfBoundsException('Service not found');
            if($s['booking_status']==='CANCELLED')throw new DomainException('Cannot assign a cancelled service');
            if(!$s['service_date'] || substr($start,0,10)!==$s['service_date'])throw new DomainException('Assignment must start on the service date');
            if($s['start_time'] && $start>$s['service_date'].' '.$s['start_time'])throw new DomainException('Assignment must cover the service start');
            if($s['end_time']){$requiredEnd=$s['service_date'].' '.$s['end_time'];if($s['start_time']&&$s['end_time']<$s['start_time'])$requiredEnd=(new DateTimeImmutable($requiredEnd))->modify('+1 day')->format('Y-m-d H:i:s');if($end<$requiredEnd)throw new DomainException('Assignment must cover the service end');}
            $conflict=self::q($db,"SELECT id,service_id,starts_at,ends_at FROM resource_assignments WHERE resource_id=? AND status='ASSIGNED' AND starts_at<? AND ends_at>?",[$rid,$end,$start])->fetch();
            if($conflict){if((int)$conflict['service_id']===$service&&$conflict['starts_at']===$start&&$conflict['ends_at']===$end)return ['id'=>(int)$conflict['id']];throw new DomainException('Resource already assigned during this time interval');}
            self::q($db,'INSERT INTO resource_assignments(resource_id,service_id,starts_at,ends_at,assigned_by) VALUES(?,?,?,?,?)',[$rid,$service,$start,$end,$u['id']]);$id=(int)$db->lastInsertId();
            // Existing service-facing documents retain the first contact. The scheduler keeps all assigned resources.
            if($resource['kind']==='GUIDE')self::q($db,'UPDATE booking_services SET guide_name=COALESCE(guide_name,?),guide_mobile=COALESCE(guide_mobile,?) WHERE id=?',[$resource['name'],$resource['phone'],$service]);
            if($resource['kind']==='DRIVER')self::q($db,'UPDATE booking_services SET driver_name=COALESCE(driver_name,?),driver_mobile=COALESCE(driver_mobile,?) WHERE id=?',[$resource['name'],$resource['phone'],$service]);
            if($resource['kind']==='VEHICLE')self::q($db,'UPDATE booking_services SET vehicle_type=COALESCE(vehicle_type,?) WHERE id=?',[$resource['name'],$service]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'RESOURCE_ASSIGNED','resource_assignment',$id,null,['resource_id'=>$rid,'service_id'=>$service,'starts_at'=>$start,'ends_at'=>$end]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);return ['id'=>$id];
        });
    }
    public static function readiness(PDO $db,int $cid,int $booking): array {
        return BookingReadiness::evaluate($db,$cid,$booking);
    }
    public static function createIssue(PDO $db,array $u,int $booking,array $body): array {
        return self::tx($db,function()use($db,$u,$booking,$body){
            BookingIntegrity::lock($db,(int)$u['company_id'],$booking);$severity=$body['severity']??'MEDIUM';if(!in_array($severity,['LOW','MEDIUM','HIGH','CRITICAL'],true))throw new InvalidArgumentException('Invalid severity');$owner=(int)($body['owner_user_id']??$u['id']);
            if(!self::q($db,"SELECT id FROM users WHERE company_id=? AND id=? AND status='ACTIVE'",[$u['company_id'],$owner])->fetchColumn())throw new InvalidArgumentException('Active same-company issue owner required');
            self::q($db,'INSERT INTO operational_issues(company_id,booking_id,category,severity,title,description,owner_user_id,created_by) VALUES(?,?,?,?,?,?,?,?)',[$u['company_id'],$booking,self::text($body['category']??'OTHER',64),$severity,self::text($body['title']??''),self::text($body['description']??'',16000),$owner,$u['id']]);$id=(int)$db->lastInsertId();Audit::log($db,(int)$u['company_id'],(int)$u['id'],'ISSUE_OPENED','operational_issue',$id,null,['severity'=>$severity,'booking_id'=>$booking]);BookingReadiness::persist($db,(int)$u['company_id'],$booking);return ['id'=>$id];
        });
    }
    public static function resolveIssue(PDO $db,array $u,int $id,array $body): array {
        return self::tx($db,function()use($db,$u,$id,$body){$booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'issue',$id);$i=self::q($db,'SELECT * FROM operational_issues WHERE company_id=? AND id=? FOR UPDATE',[$u['company_id'],$id])->fetch();if(!$i)throw new OutOfBoundsException('Issue not found');if(in_array($i['status'],['RESOLVED','CLOSED'],true))throw new DomainException('Issue already resolved');
            $root=self::text($body['root_cause']??'',8000);$resolution=self::text($body['resolution']??'',8000);$lessons=self::text($body['lessons_learned']??'',8000);$impact=FinanceLedger::cents($body['financial_impact']??'0');$currency=$body['currency']??'USD';if(!in_array($currency,['USD','VND'],true))throw new InvalidArgumentException('Invalid impact currency');
            self::q($db,"UPDATE operational_issues SET status='RESOLVED',root_cause=?,resolution=?,lessons_learned=?,financial_impact=?,currency=?,resolved_by=?,resolved_at=NOW() WHERE id=?",[$root,$resolution,$lessons,$impact/100,$currency,$u['id'],$id]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'ISSUE_RESOLVED','operational_issue',$id,null,['financial_impact'=>$impact/100,'currency'=>$currency]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);return ['id'=>$id,'status'=>'RESOLVED'];
        });
    }
    public static function cancelAssignment(PDO $db,array $u,int $id,array $body): array {
        $reason=self::text($body['reason']??'',1000);
        return self::tx($db,function()use($db,$u,$id,$reason){
            $booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'assignment',$id);
            $a=self::q($db,'SELECT a.*,r.kind,r.name FROM resource_assignments a JOIN operation_resources r ON r.id=a.resource_id JOIN booking_services s ON s.id=a.service_id JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND r.company_id=? AND a.id=? FOR UPDATE',[$u['company_id'],$u['company_id'],$id])->fetch();if(!$a)throw new OutOfBoundsException('Assignment not found');if($a['status']==='CANCELLED')return ['id'=>(int)$a['id']];
            self::q($db,"UPDATE resource_assignments SET status='CANCELLED' WHERE id=?",[$a['id']]);$next=self::q($db,"SELECT r.name,r.phone FROM resource_assignments a JOIN operation_resources r ON r.id=a.resource_id WHERE a.service_id=? AND r.company_id=? AND r.kind=? AND a.status='ASSIGNED' AND r.status='ACTIVE' ORDER BY a.id LIMIT 1",[$a['service_id'],$u['company_id'],$a['kind']])->fetch();
            [$name,$phone]=match($a['kind']){'DRIVER'=>['driver_name','driver_mobile'],'GUIDE'=>['guide_name','guide_mobile'],'VEHICLE'=>['vehicle_type',null]};
            $sql='UPDATE booking_services SET '.$name.'=?'.($phone?','.$phone.'=?':'').' WHERE id=? AND '.$name.'=?';$args=[$next['name']??null];if($phone)$args[]=$next['phone']??null;$args[]=$a['service_id'];$args[]=$a['name'];self::q($db,$sql,$args);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'RESOURCE_ASSIGNMENT_CANCELLED','resource_assignment',(int)$a['id'],null,['reason'=>$reason]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);return ['id'=>(int)$a['id']];
        });
    }
    public static function updateService(PDO $db,array $u,int $id,array $body): array {
        return self::tx($db,function()use($db,$u,$id,$body){
            $booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'service',$id);
            $s=self::q($db,'SELECT s.* FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND s.id=? FOR UPDATE',[$u['company_id'],$id])->fetch();if(!$s)throw new OutOfBoundsException('Service not found');
            foreach(['confirmed_cost','actual_cost'] as $field)if(array_key_exists($field,$body)&&(($body[$field]===null)!==($s[$field]===null)||(float)$body[$field]!=(float)$s[$field]))throw new DomainException('Use supplier confirmation or invoice reconciliation to change costs');
            $status=$body['booking_status']??$s['booking_status'];if($status!==$s['booking_status']&&!($s['booking_status']==='CONFIRMED'&&$status==='COMPLETED'))throw new DomainException('Service status changes require the procurement workflow');
            if(array_key_exists('supplier_id',$body)&&(int)$body['supplier_id']!==(int)$s['supplier_id'])throw new DomainException('Supplier replacement requires an order amendment');
            $set=['booking_status=?'];$args=[$status];foreach(['driver_name'=>190,'driver_mobile'=>64,'vehicle_type'=>120,'vehicle_plate'=>64,'guide_name'=>190,'guide_mobile'=>64,'notes'=>16000] as $field=>$max){if(!array_key_exists($field,$body))continue;$value=$body[$field];if($value!==null&&(!is_string($value)||strlen($value)>$max))throw new InvalidArgumentException('Invalid '.$field);$set[]=$field.'=?';$args[]=$value;}
            $args[]=$s['id'];self::q($db,'UPDATE booking_services SET '.implode(',',$set).' WHERE id=?',$args);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SERVICE_OPERATION_UPDATED','service',(int)$s['id'],null,['status'=>$status]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);return ['id'=>(int)$s['id']];
        });
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        try{
            if($method==='POST'&&preg_match('#^resource-assignments/(\d+)/cancel$#',$route,$m)){
                Auth::requirePermission($db,$u,'service.manage');Http::json(['ok'=>true]+self::cancelAssignment($db,$u,(int)$m[1],Http::body()));
            }
            if($method==='PUT'&&preg_match('#^services/(\d+)$#',$route,$m)){
                Auth::requirePermission($db,$u,'service.manage');Http::json(['ok'=>true]+self::updateService($db,$u,(int)$m[1],Http::body()));
            }
            if($route==='v3/resources'){
                Auth::requirePermission($db,$u,$method==='GET'?'operations.view':'service.manage');
                if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT id,kind,name,phone,capacity,status FROM operation_resources WHERE company_id=? ORDER BY kind,name',[$u['company_id']])->fetchAll()]);
                if($method==='POST'){$b=Http::body();$kind=$b['kind']??'';if(!in_array($kind,['GUIDE','DRIVER','VEHICLE'],true))throw new InvalidArgumentException('Invalid resource type');$name=self::text($b['name']??'');$phone=(string)($b['phone']??'');if(strlen($phone)>64)throw new InvalidArgumentException('Phone too long');$capacity=$kind==='VEHICLE'?filter_var($b['capacity']??0,FILTER_VALIDATE_INT):null;if($kind==='VEHICLE'&&(!$capacity||$capacity<1||$capacity>1000))throw new InvalidArgumentException('Vehicle capacity required');
                    $result=self::tx($db,function()use($db,$u,$kind,$name,$phone,$capacity){self::q($db,'INSERT INTO operation_resources(company_id,kind,name,phone,capacity,created_by) VALUES(?,?,?,?,?,?)',[$u['company_id'],$kind,$name,$phone?:null,$capacity,$u['id']]);$id=(int)$db->lastInsertId();Audit::log($db,(int)$u['company_id'],(int)$u['id'],'RESOURCE_CREATED','operation_resource',$id);return ['id'=>$id];});Http::json(['ok'=>true]+$result,201);
                }
            }
            if($method==='POST'&&preg_match('#^services/(\d+)/assign$#',$route,$m)){Auth::requirePermission($db,$u,'service.manage');Http::json(['ok'=>true]+self::assign($db,$u,(int)$m[1],Http::body()),201);}
            if(preg_match('#^bookings/(\d+)/(readiness|issues)$#',$route,$m)){
                Auth::requirePermission($db,$u,$method==='GET'?'operations.view':'service.manage');
                if($method==='GET'&&$m[2]==='readiness')Http::json(['ok'=>true]+self::readiness($db,(int)$u['company_id'],(int)$m[1]));
                if($method==='GET'&&$m[2]==='issues')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT i.*,u.full_name owner_name FROM operational_issues i JOIN users u ON u.id=i.owner_user_id WHERE i.company_id=? AND i.booking_id=? ORDER BY i.created_at DESC',[$u['company_id'],(int)$m[1]])->fetchAll()]);
                if($method==='POST'&&$m[2]==='issues')Http::json(['ok'=>true]+self::createIssue($db,$u,(int)$m[1],Http::body()),201);
            }
            if($method==='POST'&&preg_match('#^issues/(\d+)/resolve$#',$route,$m)){Auth::requirePermission($db,$u,'service.manage');Http::json(['ok'=>true]+self::resolveIssue($db,$u,(int)$m[1],Http::body()));}
            if($method==='GET'&&$route==='v3/operations'){
                Auth::requirePermission($db,$u,'operations.view');$date=$_GET['date']??date('Y-m-d');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Invalid date');
                $rows=self::q($db,"SELECT s.id,s.booking_id,b.booking_ref,b.lead_guest_name,s.category,s.service_name,s.service_date,s.start_time,s.end_time,s.booking_status,s.pickup_location,s.dropoff_location FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND s.service_date=? AND s.booking_status<>'CANCELLED' ORDER BY s.start_time,s.id",[$u['company_id'],$date])->fetchAll();$ids=array_unique(array_column($rows,'booking_id'));$readiness=[];foreach($ids as $id)$readiness[]=self::readiness($db,(int)$u['company_id'],(int)$id);
                $issues=self::q($db,"SELECT i.id,i.booking_id,b.booking_ref,i.title,i.severity,i.status FROM operational_issues i JOIN bookings b ON b.id=i.booking_id WHERE i.company_id=? AND i.status IN ('OPEN','INVESTIGATING') ORDER BY FIELD(i.severity,'CRITICAL','HIGH','MEDIUM','LOW'),i.created_at",[$u['company_id']])->fetchAll();
                Http::json(['ok'=>true,'date'=>$date,'movement'=>$rows,'readiness'=>$readiness,'issues'=>$issues]);
            }
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

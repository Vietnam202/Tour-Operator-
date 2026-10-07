<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
final class ServiceTravelDetails {
    public const FIELDS=['address'=>500,'room_type'=>190,'rooming'=>2000,'check_in'=>10,'check_out'=>10,'meal_plan'=>190,'meeting_point'=>500,'emergency_phone'=>64,'cabin_type'=>190,'inclusions'=>4000,'guest_instructions'=>4000];
    public const OP_FIELDS=['service_name'=>255,'service_date'=>10,'start_time'=>8,'end_time'=>8,'pickup_location'=>255,'dropoff_location'=>255,'confirmation_no'=>120,'driver_name'=>190,'driver_mobile'=>64,'vehicle_type'=>120,'vehicle_plate'=>64,'guide_name'=>190,'guide_mobile'=>64];
    private const STATUSES=['PLANNED','NOT_REQUESTED','REQUESTED','AVAILABLE','CONFIRMED','PART_CONFIRMED','ON_REQUEST','WAITLIST','REJECTED','CANCELLED','COMPLETED'];
    private static function stored(PDO $db,int $id): array {
        $s=$db->prepare('SELECT customer_details_json FROM service_travel_details WHERE service_id=?');$s->execute([$id]);$json=$s->fetchColumn();return $json?json_decode($json,true,512,JSON_THROW_ON_ERROR):[];
    }
    public static function operations(PDO $db,int $company,int $id): array {
        $s=$db->prepare('SELECT s.*,p.name supplier_name FROM booking_services s JOIN bookings b ON b.id=s.booking_id LEFT JOIN suppliers p ON p.id=s.supplier_id AND p.company_id=b.company_id WHERE b.company_id=? AND s.id=?');$s->execute([$company,$id]);$row=$s->fetch(PDO::FETCH_ASSOC)?:throw new OutOfBoundsException('Service not found');
        $safe=array_intersect_key($row,array_flip(['id','booking_id','category','pax','qty','supplier_id','supplier_name','booking_status',...array_keys(self::OP_FIELDS)]));
        $stored=self::stored($db,$id);$details=self::clean($stored);$operation=$stored['operations']??[];
        $operation=['language'=>(string)($operation['language']??''),'instructions'=>(string)($operation['instructions']??'')];
        return ['service'=>$safe,'details'=>$details,'operation'=>$operation,'service_hash'=>hash('sha256',json_encode([$safe,$details,$operation],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))];
    }
    public static function saveOperations(PDO $db,array $u,int $id,array $body): array {
        BookingIntegrity::begin($db);try{
            $booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'service',$id);
            if(in_array($booking['operations_status'],['COMPLETED','CANCELLED'],true))throw new DomainException('Completed or cancelled booking is read-only');
            $current=self::operations($db,(int)$u['company_id'],$id);
            if(!isset($body['expected_service_hash'])||!is_string($body['expected_service_hash'])||!hash_equals($current['service_hash'],$body['expected_service_hash']))throw new DomainException('STALE_SERVICE: Reload this service before saving');
            if(array_diff(array_keys($body),['expected_service_hash','details','operation','supplier_id','booking_status','pax','qty',...array_keys(self::OP_FIELDS)]))throw new InvalidArgumentException('Only operational fields can be changed here');
            $row=$current['service'];$patch=[];
            foreach(self::OP_FIELDS as $key=>$max){$value=$body[$key]??$row[$key]??'';if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('Invalid '.$key);$patch[$key]=trim($value);}
            if($patch['service_name']==='')throw new InvalidArgumentException('Service name required');
            if($patch['service_date']!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$patch['service_date']);if(!$date||$date->format('Y-m-d')!==$patch['service_date'])throw new InvalidArgumentException('Invalid service date');if(($booking['start_date']&&$patch['service_date']<$booking['start_date'])||($booking['end_date']&&$patch['service_date']>$booking['end_date']))throw new InvalidArgumentException('Service date must be within booking travel dates');}
            foreach(['start_time','end_time'] as $key)if($patch[$key]!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D',$patch[$key]))throw new InvalidArgumentException('Invalid '.$key);
            if($patch['start_time']!==''&&$patch['end_time']!==''&&$patch['end_time']<$patch['start_time'])throw new InvalidArgumentException('End time must follow start time');
            $status=$body['booking_status']??$row['booking_status'];if(!is_string($status)||!in_array($status,self::STATUSES,true))throw new InvalidArgumentException('Invalid service status');
            if($status!==$row['booking_status']&&in_array($status,['CONFIRMED','PART_CONFIRMED','CANCELLED','COMPLETED'],true)&&!Auth::can($db,(int)$u['id'],'supplier_order.confirm'))throw new DomainException('Supplier confirmation permission required');
            $supplier=array_key_exists('supplier_id',$body)?$body['supplier_id']:$row['supplier_id'];
            if($supplier===null||$supplier==='')$supplier=null;else{if(!is_scalar($supplier)||!preg_match('/^[1-9][0-9]*$/D',(string)$supplier))throw new InvalidArgumentException('Invalid supplier');$s=$db->prepare("SELECT id FROM suppliers WHERE company_id=? AND id=? AND status='ACTIVE'");$s->execute([$u['company_id'],$supplier]);if(!$s->fetchColumn())throw new InvalidArgumentException('Select an active supplier from this company');$supplier=(int)$supplier;}
            if($status==='CONFIRMED'&&(!$supplier||$patch['confirmation_no']===''))throw new InvalidArgumentException('Supplier and confirmation reference required');
            $links=$db->prepare("SELECT o.status FROM supplier_order_services x JOIN supplier_orders o ON o.id=x.order_id WHERE x.service_id=? AND o.company_id=? AND o.status<>'CANCELLED'");$links->execute([$id,$u['company_id']]);
            $ordered=$links->fetchAll(PDO::FETCH_COLUMN);
            $payable=$db->prepare("SELECT id FROM supplier_payables WHERE company_id=? AND service_id=? AND status<>'CANCELLED'");$payable->execute([$u['company_id'],$id]);$committed=$ordered||$payable->fetchColumn();
            if($committed&&($supplier!==($row['supplier_id']===null?null:(int)$row['supplier_id'])||$status!==$row['booking_status']||($body['pax']??$row['pax'])!=$row['pax']||($body['qty']??$row['qty'])!=$row['qty']))throw new DomainException('Use the existing supplier order workflow to change an ordered service');
            foreach(['pax','qty'] as $key){$value=$body[$key]??$row[$key];if(!is_scalar($value)||!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D',(string)$value)||(float)$value>10000)throw new InvalidArgumentException('Invalid '.$key);$patch[$key]=$value;}
            $stored=self::stored($db,$id);$detailInput=$body['details']??[];$operation=$body['operation']??$current['operation'];
            if(!is_array($detailInput)||array_diff(array_keys($detailInput),array_keys(self::FIELDS))||!is_array($operation)||array_diff(array_keys($operation),['language','instructions']))throw new InvalidArgumentException('Invalid service details');
            $details=self::clean(array_replace(self::clean($stored),$detailInput));$ops=[];foreach(['language'=>120,'instructions'=>4000] as $key=>$max){$value=$operation[$key]??'';if(!is_string($value)||strlen($value)>$max)throw new InvalidArgumentException('Invalid '.$key);$ops[$key]=trim($value);}
            $patch['supplier_id']=$supplier;$patch['booking_status']=$status;
            $s=$db->prepare('UPDATE booking_services SET '.implode(',',array_map(fn($key)=>$key.'=?',array_keys($patch))).' WHERE id=? AND booking_id=?');$s->execute([...array_values(array_map(fn($value)=>$value===''?null:$value,$patch)),$id,$booking['id']]);
            $s=$db->prepare('INSERT INTO service_travel_details(service_id,customer_details_json,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE customer_details_json=VALUES(customer_details_json),updated_by=VALUES(updated_by)');$s->execute([$id,json_encode($details+['operations'=>$ops],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$u['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'OPERATIONAL_SERVICE_SAVED','service',$id,null,['booking_id'=>$booking['id'],'status'=>$status]);
            require_once __DIR__.'/BookingReadiness.php';BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);
            $out=self::operations($db,(int)$u['company_id'],$id);$db->commit();return $out;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function clean(array $body):array {
        $out=[];foreach(self::FIELDS as $key=>$max){$v=$body[$key]??'';if(!is_string($v)||strlen($v)>$max)throw new InvalidArgumentException('Invalid '.$key);$out[$key]=trim($v);}
        foreach(['check_in','check_out'] as $key)if($out[$key]!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$out[$key]);if(!$d||$d->format('Y-m-d')!==$out[$key])throw new InvalidArgumentException('Invalid '.$key);}
        if($out['check_in']!==''&&$out['check_out']!==''&&$out['check_out']<=$out['check_in'])throw new InvalidArgumentException('Check-out must follow check-in');return $out;
    }
    public static function read(PDO $db,int $company,int $id):array {
        $q=$db->prepare('SELECT s.id FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND s.id=?');$q->execute([$company,$id]);if(!$q->fetchColumn())throw new OutOfBoundsException('Service not found');
        $q=$db->prepare('SELECT customer_details_json FROM service_travel_details WHERE service_id=?');$q->execute([$id]);$old=$q->fetchColumn();return $old?self::clean(json_decode($old,true,512,JSON_THROW_ON_ERROR)):self::clean([]);
    }
    public static function put(PDO $db,array $u,int $id,array $body):array {
        BookingIntegrity::begin($db);try{
            $booking=BookingIntegrity::financialFor($db,(int)$u['company_id'],'service',$id);$details=self::clean(array_replace(self::read($db,(int)$u['company_id'],$id),$body));
            $stored=self::stored($db,$id);$save=$details;if(isset($stored['operations']))$save['operations']=$stored['operations'];
            $q=$db->prepare('INSERT INTO service_travel_details(service_id,customer_details_json,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE customer_details_json=VALUES(customer_details_json),updated_by=VALUES(updated_by)');$q->execute([$id,json_encode($save,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$u['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'CUSTOMER_TRAVEL_DETAILS_UPDATED','service',$id);
            require_once __DIR__.'/BookingReadiness.php';BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);$db->commit();return $details;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function handle(string $route,string $method,PDO $db,array $u):void {
        if(preg_match('#^services/(\d+)/operations$#',$route,$op)&&in_array($method,['GET','PUT'],true)){
            Auth::requirePermission($db,$u,$method==='GET'?'operations.view':'service.manage');
            try{Http::json(['ok'=>true]+($method==='GET'?self::operations($db,(int)$u['company_id'],(int)$op[1]):self::saveOperations($db,$u,(int)$op[1],Http::body())));
            }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}return;
        }
        if(!preg_match('#^services/(\d+)/travel-details$#',$route,$m)||!in_array($method,['GET','PUT'],true))return;
        Auth::requirePermission($db,$u,$method==='GET'?'operations.view':'service.manage');
        try{Http::json(['ok'=>true,'details'=>$method==='GET'?self::read($db,(int)$u['company_id'],(int)$m[1]):self::put($db,$u,(int)$m[1],Http::body())]);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
    }
}

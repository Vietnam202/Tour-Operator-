<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
final class ServiceTravelDetails {
    public const FIELDS=['address'=>500,'room_type'=>190,'rooming'=>2000,'check_in'=>10,'check_out'=>10,'meal_plan'=>190,'meeting_point'=>500,'emergency_phone'=>64,'cabin_type'=>190,'inclusions'=>4000,'guest_instructions'=>4000];
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
            $q=$db->prepare('INSERT INTO service_travel_details(service_id,customer_details_json,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE customer_details_json=VALUES(customer_details_json),updated_by=VALUES(updated_by)');$q->execute([$id,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$u['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'CUSTOMER_TRAVEL_DETAILS_UPDATED','service',$id);
            require_once __DIR__.'/BookingReadiness.php';BookingReadiness::persist($db,(int)$u['company_id'],(int)$booking['id']);$db->commit();return $details;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function handle(string $route,string $method,PDO $db,array $u):void {
        if(!preg_match('#^services/(\d+)/travel-details$#',$route,$m)||!in_array($method,['GET','PUT'],true))return;
        Auth::requirePermission($db,$u,$method==='GET'?'operations.view':'service.manage');
        try{Http::json(['ok'=>true,'details'=>$method==='GET'?self::read($db,(int)$u['company_id'],(int)$m[1]):self::put($db,$u,(int)$m[1],Http::body())]);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
    }
}

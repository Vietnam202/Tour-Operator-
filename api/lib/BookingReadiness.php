<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
require_once __DIR__.'/TravelDocuments.php';
require_once __DIR__.'/ServiceTravelDetails.php';

/** The same five checks serve the Ops API, persisted booking projection and departure automation. */
final class BookingReadiness {
    public static function evaluate(PDO $db,int $company,int $booking): array {
        $b=BookingIntegrity::query($db,'SELECT * FROM bookings WHERE company_id=? AND id=?',[$company,$booking])->fetch(PDO::FETCH_ASSOC);
        if(!$b)throw new OutOfBoundsException('Booking not found');
        $services=BookingIntegrity::query($db,"SELECT * FROM booking_services WHERE booking_id=? AND booking_status<>'CANCELLED' ORDER BY id",[$booking])->fetchAll(PDO::FETCH_ASSOC);
        $confirmed=count($services)>0;$resources=true;$details=[];$confirmedCount=0;
        foreach($services as $s){
            $isConfirmed=in_array($s['booking_status'],['CONFIRMED','COMPLETED'],true);if($isConfirmed)$confirmedCount++;else $confirmed=false;
            $assigned=BookingIntegrity::query($db,"SELECT a.id,r.kind,r.name,r.capacity,a.starts_at,a.ends_at FROM resource_assignments a JOIN operation_resources r ON r.id=a.resource_id WHERE a.service_id=? AND a.status='ASSIGNED' AND r.company_id=? AND r.status='ACTIVE' ORDER BY a.id",[$s['id'],$company])->fetchAll(PDO::FETCH_ASSOC);
            $assigned=array_values(array_filter($assigned,fn($a)=>self::covers($s,$a)));
            $counts=array_count_values(array_column($assigned,'kind'));$capacity=array_sum(array_map(fn($r)=>$r['kind']==='VEHICLE'?(int)$r['capacity']:0,$assigned));$resourceReady=true;
            if($s['category']==='TRANSPORT')$resourceReady=($counts['DRIVER']??0)>=max(1,$counts['VEHICLE']??0)&&$capacity>=(int)$b['total_guests'];
            if($s['category']==='GUIDE')$resourceReady=($counts['GUIDE']??0)>0;
            if(!$resourceReady)$resources=false;
            $details[]=['service_id'=>$s['id'],'service_name'=>$s['service_name'],'confirmed'=>$isConfirmed,'resources_ready'=>$resourceReady,'assigned'=>$assigned];
        }
        $guests=(int)BookingIntegrity::query($db,'SELECT COUNT(*) FROM guests WHERE booking_id=?',[$booking])->fetchColumn();
        $issueRows=BookingIntegrity::query($db,"SELECT severity FROM operational_issues WHERE company_id=? AND booking_id=? AND severity IN ('HIGH','CRITICAL') AND status IN ('OPEN','INVESTIGATING')",[$company,$booking])->fetchAll(PDO::FETCH_COLUMN);
        $pack=BookingIntegrity::query($db,"SELECT v.* FROM travel_document_versions v JOIN travel_documents d ON d.id=v.document_id WHERE d.company_id=? AND d.booking_id=? AND d.kind='TRAVEL_PACK' AND v.status IN ('ISSUED','SENT') ORDER BY v.version_no DESC,v.id DESC LIMIT 1",[$company,$booking])->fetch(PDO::FETCH_ASSOC);$currentPack=false;
        if($pack){try{$current=TravelDocuments::snapshot($db,$company,$booking,'TRAVEL_PACK',$pack['visibility']);$current['document_version']=(int)$pack['version_no'];$currentPack=hash_equals($pack['content_hash'],hash('sha256',json_encode($current,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));}catch(DomainException $e){$currentPack=false;}}
        $checks=['supplier_confirmation'=>$confirmed,'resource_assignment'=>$resources&&count($services)>0,'guest_list'=>$guests>=(int)$b['total_guests']&&(int)$b['total_guests']>0,'no_critical_issues'=>count($issueRows)===0,'current_travel_pack'=>$currentPack];
        $pct=(int)round(count(array_filter($checks))/count($checks)*100);$ready=$pct===100&&$b['operations_status']!=='CANCELLED';$risk='LOW';
        if(in_array('CRITICAL',$issueRows,true))$risk='CRITICAL';elseif($issueRows)$risk='HIGH';elseif(!$ready){
            $risk=$pct<70?'HIGH':'MEDIUM';
            if(!empty($b['start_date'])&&!in_array($b['operations_status'],['ON_TOUR','OPERATION_COMPLETED','COMPLETED','CANCELLED'],true)&&$b['start_date']<=(new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'))$risk='CRITICAL';
        }
        return ['booking_id'=>$booking,'booking_ref'=>$b['booking_ref'],'readiness_pct'=>$pct,'ready'=>$ready,'risk_level'=>$risk,'checks'=>$checks,'services'=>$details,'open_high_critical_issues'=>count($issueRows),'guest_count'=>$guests,'service_count'=>count($services),'confirmed_services'=>$confirmedCount];
    }
    private static function covers(array $service,array $assignment): bool {
        $date=$service['service_date']??null;$start=$assignment['starts_at'];$end=$assignment['ends_at'];
        if(!$date||substr($start,0,10)!==$date||$end<=$start)return false;
        if(!empty($service['start_time'])&&$start>$date.' '.$service['start_time'])return false;
        if(!empty($service['end_time'])){$required=$date.' '.$service['end_time'];if(!empty($service['start_time'])&&$service['end_time']<$service['start_time'])$required=(new DateTimeImmutable($required))->modify('+1 day')->format('Y-m-d H:i:s');if($end<$required)return false;}
        return true;
    }
    public static function persist(PDO $db,int $company,int $booking): array {
        $owns=!$db->inTransaction();if($owns)BookingIntegrity::begin($db);
        try{
            $b=BookingIntegrity::lock($db,$company,$booking);$result=self::evaluate($db,$company,$booking);$status=$b['operations_status'];
            if(empty($b['finance_closed_at'])&&!in_array($status,['ON_TOUR','OPERATION_COMPLETED','COMPLETED','CANCELLED'],true)){
                if($result['ready'])$status='READY';elseif($result['service_count']>0&&$result['confirmed_services']===$result['service_count'])$status='ALL_CONFIRMED';elseif($result['confirmed_services']>0)$status='PART_CONFIRMED';else{$requested=BookingIntegrity::query($db,"SELECT 1 FROM booking_services WHERE booking_id=? AND booking_status='REQUESTED' LIMIT 1",[$booking])->fetchColumn();$status=$requested?'ON_REQUEST':'NEW_BOOKING';}
            }
            BookingIntegrity::query($db,'UPDATE bookings SET readiness_pct=?,risk_level=?,operations_status=? WHERE company_id=? AND id=?',[$result['readiness_pct'],$result['risk_level'],$status,$company,$booking]);$result['operations_status']=$status;
            if($owns)$db->commit();return $result;
        }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function needsDepartureCheck(array $booking,array $readiness,string $today,int $warningDays): bool {
        if(in_array($booking['operations_status'],['ON_TOUR','OPERATION_COMPLETED','COMPLETED','CANCELLED'],true)||empty($booking['start_date']))return false;
        $end=(new DateTimeImmutable($today))->modify('+'.max(1,$warningDays).' days')->format('Y-m-d');
        return $booking['start_date']>=$today&&$booking['start_date']<=$end&&!$readiness['ready'];
    }
}

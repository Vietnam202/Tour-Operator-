<?php
declare(strict_types=1);

final class TravelDocuments {
    private const KINDS=['FULL_ITINERARY','HOTEL_VOUCHER','TRANSFER_VOUCHER','ACTIVITY_VOUCHER','CRUISE_VOUCHER','TRAVEL_PACK'];
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
    private static function tx(PDO $db,callable $fn): array {$db->beginTransaction();try{$out=$fn();$db->commit();return $out;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    public static function snapshot(PDO $db,int $company,int $booking,string $kind,string $visibility): array {
        if(!in_array($kind,self::KINDS,true)||!in_array($visibility,['HIDE_PRICE','PACKAGE_PRICE'],true))throw new InvalidArgumentException('Unsupported document or visibility');
        $b=self::q($db,'SELECT * FROM bookings WHERE company_id=? AND id=?',[$company,$booking])->fetch();if(!$b)throw new OutOfBoundsException('Booking not found');
        $raw=self::q($db,'SELECT public_snapshot_json FROM booking_quote_snapshots WHERE booking_id=?',[$booking])->fetchColumn();
        if(!$raw)$raw=self::q($db,'SELECT sent_snapshot_json FROM quote_versions WHERE quote_id=? AND version_no=?',[$b['quote_id'],$b['confirmed_quote_version_no']])->fetchColumn();
        if(!$raw)throw new DomainException('Confirmed issued quote snapshot required');$quote=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $days=[];foreach(($quote['schedule']??[]) as $i=>$day){$clean=['day'=>$i+1];foreach(['date','title','description','meals','overnight'] as $key)$clean[$key]=is_scalar($day[$key]??'')?(string)($day[$key]??''):'';$days[]=$clean;}
        if(!$days)throw new DomainException('Full itinerary missing');
        $categories=match($kind){'HOTEL_VOUCHER'=>['HOTEL'],'TRANSFER_VOUCHER'=>['TRANSPORT'],'CRUISE_VOUCHER'=>['CRUISE'],'ACTIVITY_VOUCHER'=>['ATTRACTION','TOUR'],'FULL_ITINERARY'=>[],default=>['HOTEL','TRANSPORT','CRUISE','ATTRACTION','TOUR','MEAL','GUIDE','VISA','OTHER']};
        $services=self::q($db,'SELECT id,category,service_name,service_date,start_time,end_time,pickup_location,dropoff_location,pax,qty,booking_status,confirmation_no,driver_name,driver_mobile,vehicle_type,vehicle_plate,guide_name,guide_mobile FROM booking_services WHERE booking_id=? ORDER BY service_date,start_time,id',[$booking])->fetchAll();$vouchers=[];
        foreach($services as $s){if(!in_array($s['category'],$categories,true)||$s['booking_status']==='CANCELLED')continue;
            if(!in_array($s['booking_status'],['CONFIRMED','COMPLETED'],true))throw new DomainException('All included voucher services must be confirmed');
            $safe=[];foreach(['category','service_name','service_date','start_time','end_time','pickup_location','dropoff_location','pax','qty','confirmation_no','driver_name','driver_mobile','vehicle_type','vehicle_plate','guide_name','guide_mobile'] as $key)$safe[$key]=$s[$key];
            $detail=self::q($db,'SELECT customer_details_json FROM service_travel_details WHERE service_id=?',[$s['id']])->fetchColumn();if($detail)$safe['travel_details']=ServiceTravelDetails::clean(json_decode($detail,true));$vouchers[]=$safe;
        }
        if($categories&&!$vouchers)throw new DomainException('No confirmed services for this document type');
        $guests=self::q($db,'SELECT full_name FROM guests WHERE booking_id=? ORDER BY id',[$booking])->fetchAll();
        $out=['document_type'=>$kind,'booking_ref'=>$b['booking_ref'],'tour_name'=>$quote['tour_name']??'Travel itinerary','lead_guest_name'=>$b['lead_guest_name'],'total_guests'=>(int)$b['total_guests'],'start_date'=>$b['start_date'],'end_date'=>$b['end_date'],'guests'=>array_column($guests,'full_name'),'schedule'=>in_array($kind,['TRAVEL_PACK','FULL_ITINERARY'],true)?$days:[],'vouchers'=>$vouchers,'visibility'=>$visibility];
        if(array_key_exists('document_language',$quote))$out['document_language']=$quote['document_language']==='vi'?'vi':'en';
        if($visibility==='PACKAGE_PRICE')$out['package_price']=['amount'=>$b['confirmed_selling'],'currency'=>$b['selling_currency']];
        if(in_array($kind,['TRAVEL_PACK','FULL_ITINERARY'],true))$out['flights']=self::q($db,"SELECT airline,flight_number,flight_date,origin_code,destination_code,departure_time,arrival_time,terminal FROM flights WHERE booking_id=? AND status<>'CANCELLED' ORDER BY flight_date,departure_time",[$booking])->fetchAll();
        return $out;
    }
    public static function create(PDO $db,array $u,int $booking,array $body): array {
        return self::tx($db,function()use($db,$u,$booking,$body){
            if(!self::q($db,'SELECT id FROM bookings WHERE company_id=? AND id=? FOR UPDATE',[$u['company_id'],$booking])->fetchColumn())throw new OutOfBoundsException('Booking not found');
            $kind=$body['kind']??'TRAVEL_PACK';$visibility=$body['visibility']??'HIDE_PRICE';$snapshot=self::snapshot($db,(int)$u['company_id'],$booking,$kind,$visibility);
            self::q($db,'INSERT IGNORE INTO travel_documents(company_id,booking_id,kind,created_by) VALUES(?,?,?,?)',[$u['company_id'],$booking,$kind,$u['id']]);$document=(int)self::q($db,'SELECT id FROM travel_documents WHERE booking_id=? AND kind=?',[$booking,$kind])->fetchColumn();
            $version=(int)self::q($db,'SELECT COALESCE(MAX(version_no),0)+1 FROM travel_document_versions WHERE document_id=?',[$document])->fetchColumn();$snapshot['document_version']=$version;
            $json=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);
            self::q($db,'INSERT INTO travel_document_versions(document_id,version_no,visibility,public_snapshot_json,content_hash,created_by) VALUES(?,?,?,?,?,?)',[$document,$version,$visibility,$json,$hash,$u['id']]);$id=(int)$db->lastInsertId();
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'TRAVEL_DOCUMENT_DRAFT','travel_document_version',$id,null,['hash'=>$hash]);return ['id'=>$id,'version_no'=>$version,'status'=>'DRAFT'];
        });
    }
    public static function transition(PDO $db,array $u,int $id,string $action): array {
        return self::tx($db,function()use($db,$u,$id,$action){
            $v=self::q($db,'SELECT v.*,d.company_id,d.booking_id,d.kind FROM travel_document_versions v JOIN travel_documents d ON d.id=v.document_id WHERE d.company_id=? AND v.id=? FOR UPDATE',[$u['company_id'],$id])->fetch();if(!$v)throw new OutOfBoundsException('Document not found');
            $map=['review'=>['DRAFT','REVIEW'],'ready'=>['REVIEW','READY'],'issue'=>['READY','ISSUED'],'sent'=>['ISSUED','SENT']];if(!isset($map[$action]))throw new InvalidArgumentException('Invalid document action');[$from,$to]=$map[$action];
            if($v['status']===$to)return ['id'=>$id,'status'=>$to];if($v['status']!==$from)throw new DomainException('Document transition is not valid');
            if($action==='issue'){
                $current=self::snapshot($db,(int)$u['company_id'],(int)$v['booking_id'],$v['kind'],$v['visibility']);$current['document_version']=(int)$v['version_no'];
                $hash=hash('sha256',json_encode($current,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));if(!hash_equals($v['content_hash'],$hash))throw new DomainException('Booking details changed after document preparation; create and review a new version');
                self::q($db,"UPDATE travel_document_versions SET status='SUPERSEDED' WHERE document_id=? AND id<>? AND status IN ('ISSUED','SENT')",[$v['document_id'],$id]);
                self::q($db,"UPDATE travel_document_versions SET status='ISSUED',issued_by=?,issued_at=NOW() WHERE id=?",[$u['id'],$id]);
            }elseif($action==='ready')self::q($db,"UPDATE travel_document_versions SET status='READY',reviewed_by=? WHERE id=?",[$u['id'],$id]);
            elseif($action==='sent')self::q($db,"UPDATE travel_document_versions SET status='SENT',sent_at=NOW() WHERE id=?",[$id]);
            else self::q($db,'UPDATE travel_document_versions SET status=? WHERE id=?',[$to,$id]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'TRAVEL_DOCUMENT_'.strtoupper($action),'travel_document_version',$id,null,['hash'=>$v['content_hash']]);return ['id'=>$id,'status'=>$to];
        });
    }
    public static function html(array $v): string {
        $s=json_decode($v['public_snapshot_json'],true,512,JSON_THROW_ON_ERROR);$e=fn($value)=>htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($s['booking_ref']).' · '.$e($s['document_type']).'</title><style>body{font:16px/1.6 system-ui;max-width:900px;margin:40px auto;padding:20px;color:#17362f}h1,h2{line-height:1.2}.voucher{border:1px solid #bbb;padding:20px;margin:20px 0;break-inside:avoid}.muted{color:#555}@media print{body{margin:0}.noprint{display:none}}</style><h1>Vietnam Travel Advisor</h1><h2>'.$e(str_replace('_',' ',$s['document_type'])).'</h2><p>'.$e($s['booking_ref']).' · Version '.$e($s['document_version']).' · '.$e($v['status']).'</p><h2>'.$e($s['tour_name']).'</h2><p>'.$e($s['lead_guest_name']).' · '.$e($s['total_guests']).' guests · '.$e($s['start_date']).' – '.$e($s['end_date']).'</p>';
        if(!empty($s['guests']))$html.='<p>Guests: '.$e(implode(', ',$s['guests'])).'</p>';
        if(isset($s['package_price']))$html.='<p>Package price: '.$e($s['package_price']['currency']).' '.$e($s['package_price']['amount']).'</p>';
        if(!empty($s['flights'])){$html.='<h2>Flights</h2>';foreach($s['flights'] as $flight)$html.='<p>'.$e($flight['flight_date']).' · '.$e($flight['airline']).' '.$e($flight['flight_number']).' · '.$e($flight['origin_code']).' → '.$e($flight['destination_code']).' · '.$e($flight['departure_time']).' / '.$e($flight['arrival_time']).' · Terminal '.$e($flight['terminal']).'</p>';}
        foreach($s['schedule'] as $day)$html.='<section><h2>Day '.$e($day['day']).': '.$e($day['title']).'</h2><p>'.nl2br($e($day['description'])).'</p><p>Meals: '.$e($day['meals']).' · Overnight: '.$e($day['overnight']).'</p></section>';
        foreach($s['vouchers'] as $service){$html.='<section class="voucher"><h2>'.$e($service['category']).' · '.$e($service['service_name']).'</h2>';
            foreach(($service['travel_details']??[]) as $key=>$value)if($value!=='')$html.='<p><strong>'.$e(ucwords(str_replace('_',' ',$key))).':</strong> '.nl2br($e($value)).'</p>';
            foreach(['service_date'=>'Date','start_time'=>'Start','end_time'=>'End','pickup_location'=>'Pick-up','dropoff_location'=>'Drop-off','pax'=>'Guests / units','qty'=>'Quantity','confirmation_no'=>'Confirmation','driver_name'=>'Driver','driver_mobile'=>'Driver phone','vehicle_type'=>'Vehicle','vehicle_plate'=>'Vehicle plate','guide_name'=>'Guide','guide_mobile'=>'Guide phone'] as $key=>$label)if($service[$key]!==null&&$service[$key]!=='')$html.='<p><strong>'.$label.':</strong> '.$e($service[$key]).'</p>';$html.='</section>';
        }
        return $html.'<p class="muted">Document verification: '.$e($v['content_hash']).'</p></html>';
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        try{
            if(preg_match('#^bookings/(\d+)/travel-documents$#',$route,$m)){
                Auth::requirePermission($db,$u,$method==='GET'?'travel_document.view':'travel_document.manage');
                if($method==='POST')Http::json(['ok'=>true]+self::create($db,$u,(int)$m[1],Http::body()),201);
                if($method==='GET')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT v.id,d.kind,v.version_no,v.status,v.visibility,v.content_hash,v.created_at,v.issued_at FROM travel_documents d JOIN travel_document_versions v ON v.document_id=d.id WHERE d.company_id=? AND d.booking_id=? ORDER BY v.id DESC',[$u['company_id'],(int)$m[1]])->fetchAll()]);
            }
            if(preg_match('#^travel-documents/(\d+)/(review|ready|issue|sent|html)$#',$route,$m)){
                if($m[2]==='html'&&$method==='GET'){
                    Auth::requirePermission($db,$u,'travel_document.view');$v=self::q($db,'SELECT v.* FROM travel_document_versions v JOIN travel_documents d ON d.id=v.document_id WHERE d.company_id=? AND v.id=?',[$u['company_id'],(int)$m[1]])->fetch();if(!$v)throw new OutOfBoundsException('Document not found');
                    header('Content-Type: text/html; charset=utf-8');header('Cache-Control: private, no-store');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'");echo self::html($v);exit;
                }
                if($method==='POST'){Auth::requirePermission($db,$u,'travel_document.issue');Http::json(['ok'=>true]+self::transition($db,$u,(int)$m[1],$m[2]));}
            }
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

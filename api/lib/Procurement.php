<?php
declare(strict_types=1);
require_once __DIR__.'/BookingIntegrity.php';
require_once __DIR__.'/BookingReadiness.php';

final class Procurement {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {return BookingIntegrity::query($db,$sql,$args);}
    private static function tx(PDO $db,callable $fn): array {BookingIntegrity::begin($db);try{$x=$fn();$db->commit();return $x;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}}
    private static function order(PDO $db,int $cid,int $id): array {BookingIntegrity::financialFor($db,$cid,'order',$id);$o=self::q($db,'SELECT * FROM supplier_orders WHERE company_id=? AND id=? FOR UPDATE',[$cid,$id])->fetch();if(!$o)throw new OutOfBoundsException('Order not found');return $o;}
    private static function services(PDO $db,int $id): array {return self::q($db,'SELECT s.* FROM supplier_order_services os JOIN booking_services s ON s.id=os.service_id WHERE os.order_id=? ORDER BY s.id',[$id])->fetchAll();}
    public static function generate(PDO $db,array $u,int $booking): array {
        return self::tx($db,function()use($db,$u,$booking){
            $b=BookingIntegrity::financial($db,(int)$u['company_id'],$booking);
            $rows=self::q($db,"SELECT s.* FROM booking_services s JOIN suppliers p ON p.id=s.supplier_id AND p.company_id=? AND p.status='ACTIVE' WHERE s.booking_id=? AND s.booking_status IN ('PLANNED','NOT_REQUESTED') AND NOT EXISTS(SELECT 1 FROM supplier_order_services os JOIN supplier_orders o ON o.id=os.order_id WHERE os.service_id=s.id AND o.status<>'CANCELLED') ORDER BY s.id",[$u['company_id'],$booking])->fetchAll();$groups=[];
            foreach($rows as $row)$groups[$row['supplier_id'].'-'.$row['cost_currency']][]=$row;
            foreach($groups as $services){
                $supplier=$services[0]['supplier_id'];$ref='SBO-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
                self::q($db,"INSERT INTO supplier_orders(company_id,order_ref,booking_id,supplier_id,status,current_revision,created_by,updated_by) VALUES(?,?,?,?,'DRAFT',0,?,?)",[$u['company_id'],$ref,$booking,$supplier,$u['id'],$u['id']]);$id=(int)$db->lastInsertId();
                foreach($services as $s)self::q($db,"INSERT INTO supplier_order_services(order_id,service_id,service_status) VALUES(?,?,'PLANNED')",[$id,$s['id']]);
                self::q($db,"INSERT INTO supplier_order_revisions(order_id,revision_no,revision_type,status,snapshot_json,created_by) VALUES(?,0,'ORIGINAL','DRAFT',?,?)",[$id,json_encode(['booking_ref'=>$b['booking_ref'],'services'=>$services],JSON_THROW_ON_ERROR),$u['id']]);
                Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_ORDER_DRAFT','supplier_order',$id);
            }
            BookingReadiness::persist($db,(int)$u['company_id'],$booking);
            return ['items'=>self::q($db,"SELECT id,order_ref,status FROM supplier_orders WHERE company_id=? AND booking_id=? AND status='DRAFT' ORDER BY id",[$u['company_id'],$booking])->fetchAll()];
        });
    }
    public static function send(PDO $db,array $u,int $id): array {
        return self::tx($db,function()use($db,$u,$id){$o=self::order($db,(int)$u['company_id'],$id);
            if($o['status']==='REQUESTED')return ['id'=>$id,'status'=>'REQUESTED'];
            if($o['status']!=='DRAFT')throw new DomainException('Only draft supplier orders may be sent');
            $services=self::services($db,$id);if(!$services)throw new DomainException('Order has no services');
            foreach($services as $s)if((int)$s['supplier_id']!==(int)$o['supplier_id']||(int)$s['booking_id']!==(int)$o['booking_id'])throw new DomainException('Service ownership changed');
            self::q($db,"UPDATE supplier_order_revisions SET snapshot_json=?,status='SENT',sent_at=NOW() WHERE order_id=? AND revision_no=? AND status='DRAFT'",[json_encode(['order_ref'=>$o['order_ref'],'services'=>$services],JSON_THROW_ON_ERROR),$id,$o['current_revision']]);
            self::q($db,"UPDATE supplier_orders SET status='REQUESTED',last_sent_at=NOW(),followup_due_at=DATE_ADD(NOW(),INTERVAL 1 DAY),updated_by=? WHERE id=?",[$u['id'],$id]);
            self::q($db,"UPDATE supplier_order_services SET service_status='REQUESTED' WHERE order_id=?",[$id]);
            foreach($services as $s)self::q($db,"UPDATE booking_services SET booking_status='REQUESTED' WHERE id=?",[$s['id']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_ORDER_SENT','supplier_order',$id,null,['revision'=>$o['current_revision']]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$o['booking_id']);return ['id'=>$id,'status'=>'REQUESTED'];
        });
    }
    public static function confirm(PDO $db,array $u,int $id,array $body): array {
        return self::tx($db,function()use($db,$u,$id,$body){$o=self::order($db,(int)$u['company_id'],$id);
            if(!in_array($o['status'],['REQUESTED','AVAILABLE','PART_CONFIRMED','ON_REQUEST','WAITLIST','CONFIRMED'],true))throw new DomainException('Explicitly send the draft order before confirmation');
            $status=$body['status']??'CONFIRMED';if(!in_array($status,['CONFIRMED','PART_CONFIRMED','AVAILABLE','ON_REQUEST','WAITLIST','REJECTED'],true))throw new InvalidArgumentException('Unsupported confirmation status');
            if(!in_array($status,['CONFIRMED','PART_CONFIRMED'],true)){
                if($o['status']==='CONFIRMED')throw new DomainException('Confirmed orders need a reviewed amendment');
                self::q($db,'UPDATE supplier_orders SET status=?,updated_by=? WHERE id=?',[$status,$u['id'],$id]);Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_ORDER_RESPONSE','supplier_order',$id,null,['status'=>$status]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$o['booking_id']);return ['status'=>$status];
            }
            $rows=self::services($db,$id);$map=[];foreach($rows as $s){if((int)$s['booking_id']!==(int)$o['booking_id']||(int)$s['supplier_id']!==(int)$o['supplier_id'])throw new DomainException('Service ownership changed');$map[(int)$s['id']]=$s;}
            $ids=$body['service_ids']??($status==='CONFIRMED'?array_keys($map):[]);if(!is_array($ids)||!$ids)throw new InvalidArgumentException('Select confirmed services');$ids=array_map('intval',$ids);if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Duplicate service IDs');
            $currency=$body['currency']??'';$number=$body['confirmation_no']??'';$source=$body['confirmation_source']??'OTHER';
            if(!is_string($number)||trim($number)===''||strlen($number)>120||!in_array($source,['EMAIL','WHATSAPP','PDF','PHONE','OTHER'],true))throw new InvalidArgumentException('Confirmation reference and source required');
            $costs=$body['service_costs']??[];if(count($ids)===1&&isset($body['confirmed_total'])&&!isset($costs[$ids[0]]))$costs[$ids[0]]=$body['confirmed_total'];$sum=0;$unchanged=true;
            foreach($ids as $sid){
                if(!isset($map[$sid]))throw new InvalidArgumentException('Service is not part of this supplier order');$s=$map[$sid];
                if($s['cost_currency']!==$currency)throw new InvalidArgumentException('Confirmation currency must match every selected service');
                $cost=$costs[$sid]??null;if(!is_numeric($cost)||!is_finite((float)$cost)||(float)$cost<0||(float)$cost>100000000)throw new InvalidArgumentException('Explicit confirmed cost required for each selected service');$cost=round((float)$cost,2);$sum+=$cost;
                $ap=self::q($db,"SELECT * FROM supplier_payables WHERE supplier_order_id=? AND service_id=? AND status<>'CANCELLED' FOR UPDATE",[$id,$sid])->fetch();
                if($ap){if(abs((float)$ap['total_amount']-$cost)>0.001)throw new DomainException('Confirmed payable amount changed; reconcile an amendment before updating');}
                else{$unchanged=false;$ref='AP-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6)));self::q($db,"INSERT INTO supplier_payables(company_id,payable_ref,booking_id,supplier_id,supplier_order_id,service_id,label,currency,total_amount,balance,status) VALUES(?,?,?,?,?,?,?,?,?,?,'UNPAID')",[$u['company_id'],$ref,$o['booking_id'],$o['supplier_id'],$id,$sid,$s['service_name'],$currency,$cost,$cost]);}
                self::q($db,"UPDATE booking_services SET booking_status='CONFIRMED',confirmed_cost=?,confirmation_no=?,confirmation_source=? WHERE id=?",[$cost,$number,$source,$sid]);self::q($db,"UPDATE supplier_order_services SET service_status='CONFIRMED' WHERE order_id=? AND service_id=?",[$id,$sid]);
            }
            if(isset($body['confirmed_total'])&&(!is_numeric($body['confirmed_total'])||abs(round((float)$body['confirmed_total'],2)-round($sum,2))>0.001))throw new InvalidArgumentException('Confirmation total must equal selected service costs');
            if(!$unchanged)self::q($db,'INSERT INTO supplier_confirmations(order_id,confirmation_no,confirmation_source,confirmed_at,currency,confirmed_total,recorded_by) VALUES(?,?,?,NOW(),?,?,?)',[$id,$number,$source,$currency,round($sum,2),$u['id']]);
            $pending=(int)self::q($db,"SELECT COUNT(*) FROM supplier_order_services WHERE order_id=? AND service_status<>'CONFIRMED'",[$id])->fetchColumn();$final=$pending?'PART_CONFIRMED':'CONFIRMED';
            self::q($db,'UPDATE supplier_orders SET status=?,updated_by=? WHERE id=?',[$final,$u['id'],$id]);if(!$pending)self::q($db,"UPDATE supplier_order_revisions SET status='CONFIRMED' WHERE order_id=? AND revision_no=?",[$id,$o['current_revision']]);
            Audit::log($db,(int)$u['company_id'],(int)$u['id'],'SUPPLIER_COST_CONFIRMED','supplier_order',$id,null,['status'=>$final,'service_ids'=>$ids,'total'=>round($sum,2),'currency'=>$currency]);BookingReadiness::persist($db,(int)$u['company_id'],(int)$o['booking_id']);return ['status'=>$final];
        });
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if($method!=='POST')return;
        try{
            if(preg_match('#^bookings/(\d+)/generate-orders$#',$route,$m)){Auth::requirePermission($db,$u,'supplier_order.create');Http::json(['ok'=>true]+self::generate($db,$u,(int)$m[1]),201);}
            if(preg_match('#^supplier-orders/(\d+)/(send|confirm)$#',$route,$m)){Auth::requirePermission($db,$u,'supplier_order.'.$m[2]);$result=$m[2]==='send'?self::send($db,$u,(int)$m[1]):self::confirm($db,$u,(int)$m[1],Http::body());Http::json(['ok'=>true]+$result);}
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

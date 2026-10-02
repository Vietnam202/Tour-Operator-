<?php
declare(strict_types=1);

/** Read projection over existing shared identities. No private snapshots or raw audit payloads. */
final class Customer360 {
    private static function rows(PDO $db,string $sql,array $args): array {
        $s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function section(PDO $db,bool $allowed,string $sql,array $args): array {
        if(!$allowed)return ['available'=>false,'items'=>[],'total'=>null,'has_more'=>false];
        $rows=self::rows($db,$sql,$args);$more=count($rows)>100;
        return ['available'=>true,'items'=>array_slice($rows,0,100),'total'=>$more?null:count($rows),'has_more'=>$more];
    }
    public static function read(PDO $db,array $user,int $id): array {
        if(!Auth::can($db,(int)$user['id'],'sales.view'))throw new DomainException('FORBIDDEN');
        $cid=(int)$user['company_id'];$args=[$cid,$id];
        $profile=self::rows($db,'SELECT c.id,c.customer_ref,c.customer_type,c.full_name,c.company_name,c.country,c.market,c.whatsapp,c.email,c.preferred_language,c.source,c.sales_owner_id,c.status,c.created_at,c.updated_at,u.full_name owner_name FROM customers c LEFT JOIN users u ON u.id=c.sales_owner_id AND u.company_id=c.company_id WHERE c.company_id=? AND c.id=?',$args);
        if(!$profile)throw new OutOfBoundsException('Customer not found');
        $can=fn(string $p):bool=>Auth::can($db,(int)$user['id'],$p);
        $s=[];
        $s['leads']=self::section($db,$can('lead.view'),
            'SELECT l.id,l.request_id,l.inquiry_id,l.status,l.handover_status,l.handover_version,l.qualified_at created_at,l.next_action_due due_at,r.contact_name title,r.source,r.campaign_id,r.attribution_json,c.name campaign_name,u.full_name owner_name FROM leads l JOIN lead_requests r ON r.id=l.request_id AND r.company_id=l.company_id JOIN inquiries i ON i.id=l.inquiry_id AND i.company_id=l.company_id JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id LEFT JOIN campaigns c ON c.id=r.campaign_id AND c.company_id=r.company_id LEFT JOIN users u ON u.id=COALESCE(l.sales_owner_user_id,l.owner_user_id) AND u.company_id=l.company_id WHERE t.company_id=? AND t.customer_id=? ORDER BY l.qualified_at DESC,l.id DESC LIMIT 101',$args);
        $s['inquiries']=self::section($db,true,
            'SELECT i.id,i.inquiry_ref ref,i.trip_id,i.status,i.next_action,i.next_action_due,i.next_action_due due_at,i.created_at,t.title,t.start_date,t.end_date,t.market,t.total_guests,t.paying_pax,u.full_name owner_name FROM inquiries i JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id LEFT JOIN users u ON u.id=t.sales_owner_id AND u.company_id=t.company_id WHERE t.company_id=? AND t.customer_id=? ORDER BY i.created_at DESC,i.id DESC LIMIT 101',$args);
        $s['quotes']=self::section($db,true,
            'SELECT q.id,q.quote_ref ref,q.trip_id,q.inquiry_id,q.status,q.current_version_no,q.created_at,v.tour_name title,v.start_date,v.end_date,v.selling_currency currency,v.total_selling,v.sent_at FROM quotes q JOIN trips t ON t.id=q.trip_id AND t.company_id=q.company_id JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=q.current_version_no WHERE t.company_id=? AND t.customer_id=? ORDER BY q.created_at DESC,q.id DESC LIMIT 101',$args);
        $s['bookings']=self::section($db,$can('booking.view'),
            'SELECT b.id,b.booking_ref ref,b.booking_ref title,b.trip_id,b.operations_status status,b.start_date,b.end_date,b.total_guests,b.created_at,b.operation_completed_at,u.full_name owner_name FROM bookings b JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id LEFT JOIN users u ON u.id=b.operations_owner_id AND u.company_id=b.company_id WHERE t.company_id=? AND t.customer_id=? ORDER BY b.created_at DESC,b.id DESC LIMIT 101',$args);
        // Select task metadata only; no notes or unrestricted child payloads. Link every parent in company.
        $s['tasks']=self::section($db,$can('task.view'),
            "SELECT k.id,k.title,k.status,k.priority,k.entity_type,k.entity_id,k.due_at,k.created_at,u.full_name owner_name FROM tasks k LEFT JOIN users u ON u.id=k.owner_user_id AND u.company_id=k.company_id WHERE k.company_id=? AND (k.entity_type='customer' AND k.entity_id=? OR k.entity_type='trip' AND EXISTS(SELECT 1 FROM trips t WHERE t.id=k.entity_id AND t.company_id=k.company_id AND t.customer_id=?) OR k.entity_type='inquiry' AND EXISTS(SELECT 1 FROM inquiries i JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id WHERE i.id=k.entity_id AND i.company_id=k.company_id AND t.customer_id=?) OR k.entity_type='quote' AND EXISTS(SELECT 1 FROM quotes q JOIN trips t ON t.id=q.trip_id AND t.company_id=q.company_id WHERE q.id=k.entity_id AND q.company_id=k.company_id AND t.customer_id=?) OR k.entity_type='booking' AND EXISTS(SELECT 1 FROM bookings b JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id WHERE b.id=k.entity_id AND b.company_id=k.company_id AND t.customer_id=?) OR k.entity_type='lead' AND EXISTS(SELECT 1 FROM leads l JOIN inquiries i ON i.id=l.inquiry_id AND i.company_id=l.company_id JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id WHERE l.id=k.entity_id AND l.company_id=k.company_id AND t.customer_id=?)) AND (?=1 OR k.owner_user_id=?) ORDER BY k.due_at,k.id LIMIT 101",[$cid,$id,$id,$id,$id,$id,$id,$can('approval.manage')?1:0,(int)$user['id']]);
        $s['documents']=self::section($db,$can('travel_document.view'),
            'SELECT d.id,d.booking_id,d.kind title,b.booking_ref ref,v.version_no,v.status,v.created_at,v.issued_at FROM travel_documents d JOIN bookings b ON b.id=d.booking_id AND b.company_id=d.company_id JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id JOIN travel_document_versions v ON v.document_id=d.id WHERE t.company_id=? AND t.customer_id=? AND v.version_no=(SELECT MAX(v2.version_no) FROM travel_document_versions v2 WHERE v2.document_id=d.id) ORDER BY v.created_at DESC,d.id DESC LIMIT 101',$args);
        $s['finance']=self::section($db,$can('finance.view')&&$can('customer_ar.view'),
            "SELECT f.currency,COUNT(*) invoice_count,SUM(f.total) total,SUM(f.paid_amount) paid,SUM(f.balance) balance FROM customer_invoices f JOIN bookings b ON b.id=f.booking_id AND b.company_id=f.company_id JOIN trips t ON t.id=b.trip_id AND t.company_id=b.company_id WHERE t.company_id=? AND t.customer_id=? AND f.status IN ('ISSUED','SENT','PART_PAID','PAID') AND f.invoice_type NOT IN ('RECEIPT','CREDIT_NOTE') GROUP BY f.currency ORDER BY f.currency LIMIT 101",$args);
        $s['finance']['definition']='Issued invoice balances by currency; excludes draft/cancelled invoices, receipt and credit-note documents. Not a complete customer statement.';
        $events=[];
        foreach(['leads'=>'LEAD_QUALIFIED','inquiries'=>'INQUIRY_CREATED','quotes'=>'QUOTE_CREATED','bookings'=>'BOOKING_CREATED'] as $key=>$event){
            if(!$s[$key]['available'])continue;
            foreach($s[$key]['items'] as $r)$events[]=['id'=>$r['id'],'entity_type'=>['leads'=>'lead','inquiries'=>'inquiry','quotes'=>'quote','bookings'=>'booking'][$key],'event'=>$event,'title'=>$r['ref']??$r['title']??('Lead #'.$r['id']),'created_at'=>$r['created_at'],'status'=>$r['status']??null];
        }
        usort($events,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
        $s['activity']=['available'=>true,'items'=>array_slice($events,0,100),'total'=>count($events),'has_more'=>count($events)>100||array_reduce($s,fn($more,$part)=>$more||$part['has_more'],false)];
        return ['ok'=>true,'customer'=>$profile[0],'sections'=>$s];
    }
    public static function handle(string $route,string $method,PDO $db,array $user): void {
        if(!preg_match('#^customers/(\d+)/360$#',$route,$m))return;
        if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        try {Http::json(self::read($db,$user,(int)$m[1]));}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);}
    }
}

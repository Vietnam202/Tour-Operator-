<?php
declare(strict_types=1);

final class ControlCenter {
    private static function rows(PDO $db,string $sql,array $args): array {$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll();}
    public static function overview(PDO $db,array $u): array {
        $cid=(int)$u['company_id'];$uid=(int)$u['id'];$out=['tasks'=>[],'quote_reviews'=>[],'supplier_reviews'=>[],'alerts'=>[]];
        if(Auth::can($db,$uid,'task.view'))$out['tasks']=self::rows($db,"SELECT id,title,entity_type,entity_id,due_at,priority,status FROM tasks WHERE company_id=? AND owner_user_id=? AND status IN ('OPEN','SNOOZED') ORDER BY due_at,id LIMIT 200",[$cid,$uid]);
        if(Auth::can($db,$uid,'quote.approve'))$out['quote_reviews']=self::rows($db,"SELECT q.id quote_id,q.quote_ref,v.id version_id,v.version_no,v.tour_name,COUNT(o.id) option_count FROM quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=q.current_version_no JOIN quote_options o ON o.quote_version_id=v.id WHERE q.company_id=? AND v.version_status='DRAFT' GROUP BY q.id,q.quote_ref,v.id,v.version_no,v.tour_name ORDER BY q.id DESC LIMIT 100",[$cid]);
        if(Auth::can($db,$uid,'finance.close')&&Auth::can($db,$uid,'supplier_ap.view'))$out['supplier_reviews']=self::rows($db,"SELECT r.id,r.supplier_invoice_no,r.confirmed_amount,r.invoice_amount,r.variance,r.currency,r.reason,p.booking_id,b.booking_ref,s.name supplier_name FROM supplier_invoice_reconciliations r JOIN supplier_payables p ON p.id=r.payable_id JOIN bookings b ON b.id=p.booking_id JOIN suppliers s ON s.id=p.supplier_id WHERE p.company_id=? AND r.status='REVIEW_REQUIRED' ORDER BY r.id LIMIT 100",[$cid]);
        if(Auth::can($db,$uid,'operations.view'))$out['alerts']=self::rows($db,"SELECT i.id,i.booking_id,b.booking_ref,i.severity,i.title,i.status FROM operational_issues i JOIN bookings b ON b.id=i.booking_id WHERE i.company_id=? AND i.status IN ('OPEN','INVESTIGATING') ORDER BY FIELD(i.severity,'CRITICAL','HIGH','MEDIUM','LOW'),i.id LIMIT 100",[$cid]);
        return $out;
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if($route==='v3/control-center'&&$method==='GET'){
            $allowed=false;foreach(['task.view','quote.approve','finance.close','operations.view'] as $p)$allowed=$allowed||Auth::can($db,(int)$u['id'],$p);if(!$allowed)Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);
            Http::json(['ok'=>true]+self::overview($db,$u));
        }
        if($route==='v3/reports/attribution'&&$method==='GET'){
            Auth::requirePermission($db,$u,'report.view');Auth::requirePermission($db,$u,'lead.view');
            $rows=self::rows($db,"SELECT c.id campaign_id,c.name campaign,c.source,COUNT(DISTINCT r.id) requests,COUNT(DISTINCT l.id) qualified_leads,COUNT(DISTINCT l.inquiry_id) inquiries,COUNT(DISTINCT q.id) quotes,COUNT(DISTINCT b.id) bookings FROM campaigns c LEFT JOIN lead_requests r ON r.campaign_id=c.id AND r.company_id=c.company_id LEFT JOIN leads l ON l.request_id=r.id AND l.company_id=c.company_id LEFT JOIN inquiries i ON i.id=l.inquiry_id LEFT JOIN quotes q ON q.inquiry_id=i.id LEFT JOIN bookings b ON b.quote_id=q.id WHERE c.company_id=? GROUP BY c.id,c.name,c.source ORDER BY c.id DESC",[$u['company_id']]);
            Http::json(['ok'=>true,'items'=>$rows,'definition'=>'All-time distinct records linked to the original request campaign; one request counted once regardless of quote revisions.']);
        }
        if($route==='tasks'&&$method==='POST'){
            Auth::requirePermission($db,$u,'task.view');$b=Http::body();$owner=(int)($b['owner_user_id']??$u['id']);
            if(!self::rows($db,"SELECT id FROM users WHERE company_id=? AND id=? AND status='ACTIVE'",[$u['company_id'],$owner]))Http::json(['ok'=>false,'error'=>'INVALID_OWNER'],422);
            // Validate entity links before the existing task writer handles the mutation.
            $kind=$b['entity_type']??'manual';$id=(int)($b['entity_id']??0);$table=['booking'=>'bookings','quote'=>'quotes','inquiry'=>'inquiries','supplier_order'=>'supplier_orders'][$kind]??null;
            if($kind!=='manual'&&(!$table||!self::rows($db,"SELECT id FROM $table WHERE company_id=? AND id=?",[$u['company_id'],$id])))Http::json(['ok'=>false,'error'=>'INVALID_TASK_ENTITY'],422);
            if(!in_array($b['priority']??'NORMAL',['NORMAL','HIGH','CRITICAL'],true))Http::json(['ok'=>false,'error'=>'INVALID_PRIORITY'],422);
        }
    }
}

<?php
declare(strict_types=1);

/** Read-only RC6 dashboards. Counts and paged queues use the same scoped predicates. */
final class WorkspaceCenter {
    public const SALES_QUEUES=['new_leads','need_qualification','followup_today','overdue_followup','draft_quotes','sent_quotes','waiting_client','confirmed_today','lost'];

    public static function clock(?DateTimeImmutable $now=null): array {
        $now=$now??new DateTimeImmutable('now');
        $start=$now->setTime(0,0,0);$end=$start->modify('+1 day');
        return ['as_of'=>$now->format(DATE_ATOM),'timezone'=>$now->getTimezone()->getName(),'date_range'=>[
            'today'=>$start->format('Y-m-d'),'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s'),
            'next_7_end'=>$start->modify('+7 days')->format('Y-m-d H:i:s')]];
    }
    private static function permissions(PDO $db,array $u): array {
        $out=[];foreach(['lead.view','sales.view','booking.view','campaign.manage','operations.view','task.view'] as $p)$out[$p]=Auth::can($db,(int)$u['id'],$p);
        return $out;
    }
    private static function rows(PDO $db,string $sql,array $args): array {$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
    private static function count(PDO $db,string $from,string $where,array $args): int {$s=$db->prepare("SELECT COUNT(*) FROM $from WHERE $where");$s->execute($args);return (int)$s->fetchColumn();}
    public static function paging(array $query,int $default=6): array {
        $out=[];foreach(['limit'=>$default,'offset'=>0] as $key=>$fallback){
            $value=$query[$key]??$fallback;
            if(!is_scalar($value)||!preg_match('/^\d+$/D',(string)$value)||strlen((string)$value)>9)throw new InvalidArgumentException('Invalid '.$key);
            $out[$key]=(int)$value;
        }
        if($out['limit']<1||$out['limit']>100||$out['offset']>10000000)throw new InvalidArgumentException('Invalid pagination range');
        return [$out['limit'],$out['offset']];
    }
    /** Only registered aliases may become row navigation destinations. */
    public static function row(array $r,string $kind): array {
        $id=(int)$r['id'];$out=['entity_type'=>$kind,'id'=>$id,'ref'=>(string)($r['ref']??''),'title'=>(string)($r['title']??''),
            'contact'=>(string)($r['contact']??''),'status'=>(string)($r['status']??''),'owner_name'=>(string)($r['owner_name']??''),
            'due_at'=>$r['due_at']??null,'event_date'=>$r['event_date']??null];
        [$out['route'],$out['params']]=match($kind){
            'lead_request'=>['leads',['requestId'=>$id]],'inquiry'=>['sales-list',['salesTab'=>'inquiries','inquiryId'=>$id]],
            'quote'=>['quote',['quoteId'=>$id]],'booking'=>['booking',['bookingId'=>$id]],'task'=>['operations',['opsModule'=>'tasks']],
            default=>throw new InvalidArgumentException('Invalid queue entity')};
        return $out;
    }
    private static function specs(int $cid,array $clock): array {
        $d=$clock['date_range'];$active="i.status NOT IN ('CONFIRMED','LOST','CANCELLED')";
        $request=['permission'=>'lead.view','kind'=>'lead_request','from'=>'lead_requests r',
            'select'=>"r.id,r.id ref,r.destination title,r.contact_name contact,r.status,NULL owner_name,NULL due_at,r.created_at event_date",
            'base'=>'r.company_id=?','args'=>[$cid],'order'=>'r.created_at DESC,r.id DESC'];
        $inquiry=['permission'=>'sales.view','kind'=>'inquiry','from'=>'inquiries i JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id LEFT JOIN users u ON u.id=t.sales_owner_id AND u.company_id=i.company_id',
            'select'=>'i.id,i.inquiry_ref ref,t.title,t.lead_contact_name contact,i.status,u.full_name owner_name,i.next_action_due due_at,i.created_at event_date',
            'base'=>'i.company_id=?','args'=>[$cid],'order'=>'i.next_action_due ASC,i.id ASC'];
        $quote=['permission'=>'sales.view','kind'=>'quote','from'=>'quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=q.current_version_no JOIN trips t ON t.id=q.trip_id AND t.company_id=q.company_id LEFT JOIN users u ON u.id=t.sales_owner_id AND u.company_id=q.company_id',
            'select'=>'q.id,q.quote_ref ref,v.tour_name title,t.lead_contact_name contact,q.status,u.full_name owner_name,NULL due_at,v.sent_at event_date',
            'base'=>'q.company_id=?','args'=>[$cid],'order'=>'q.updated_at DESC,q.id DESC'];
        $booking=['permission'=>'booking.view','kind'=>'booking','from'=>'bookings b LEFT JOIN users u ON u.id=b.sales_owner_id AND u.company_id=b.company_id',
            'select'=>'b.id,b.booking_ref ref,b.booking_ref title,b.lead_guest_name contact,b.operations_status status,u.full_name owner_name,NULL due_at,b.created_at event_date',
            'base'=>'b.company_id=?','args'=>[$cid],'order'=>'b.created_at DESC,b.id DESC'];
        $make=static function(array $base,string $predicate,array $args,string $definition): array {
            $base['where']=$base['base'].' AND '.$predicate;$base['args']=array_merge($base['args'],$args);$base['definition']=$definition;return $base;
        };
        return [
            'new_leads'=>$make($request,"r.status='NEW' AND r.created_at>=? AND r.created_at<?",[$d['start'],$d['end']],'New, unqualified lead requests received during today in the configured application timezone. Also included in Need qualification.'),
            'need_qualification'=>$make($request,"r.status='NEW'",[],'All unqualified lead requests (NEW); no date restriction.'),
            'followup_today'=>$make($inquiry,"$active AND i.next_action_due>=? AND i.next_action_due<?",[$d['start'],$d['end']],'Active inquiries with a next action due today, including elapsed times today. Excludes confirmed, lost and cancelled.'),
            'overdue_followup'=>$make($inquiry,"$active AND i.next_action_due<?",[$d['start']],'Active inquiries whose next action was due before the start of today; excludes today to avoid double-counting follow-up queues.'),
            'draft_quotes'=>$make($quote,"q.status IN ('DRAFT','READY','APPROVED') AND v.version_status IN ('DRAFT','READY','APPROVED')",[],'Quotes whose current version and master status are draft, ready or approved; counted once per quote.'),
            'sent_quotes'=>$make($quote,"q.status='SENT' AND v.version_status='SENT'",[],'Quotes in SENT state with a sent current version. FOLLOW_UP quotes appear separately in Waiting client.'),
            'waiting_client'=>$make($quote,"q.status='FOLLOW_UP' AND v.version_status='SENT'",[],'Quotes explicitly moved to FOLLOW_UP whose current version remains SENT.'),
            'confirmed_today'=>$make($booking,"b.operations_status<>'CANCELLED' AND b.created_at>=? AND b.created_at<?",[$d['start'],$d['end']],'Non-cancelled bookings created today. RC5 has no immutable acceptance timestamp; this is booking creation, not a historical acceptance-date report.'),
            'lost'=>$make($inquiry,"i.status='LOST'",[],'All lost inquiries; no date restriction. Lost quotes are not counted again.')
        ];
    }
    private static function queue(PDO $db,array $s,bool $available,int $limit,int $offset): array {
        if(!$available)return ['available'=>false,'total'=>null,'items'=>[],'limit'=>$limit,'offset'=>$offset];
        $total=self::count($db,$s['from'],$s['where'],$s['args']);
        $rows=self::rows($db,"SELECT {$s['select']} FROM {$s['from']} WHERE {$s['where']} ORDER BY {$s['order']} LIMIT $limit OFFSET $offset",$s['args']);
        return ['available'=>true,'total'=>$total,'items'=>array_map(fn($r)=>self::row($r,$s['kind']),$rows),'limit'=>$limit,'offset'=>$offset];
    }
    public static function sales(PDO $db,array $u,array $query=[],?DateTimeImmutable $now=null): array {
        $p=self::permissions($db,$u);if(!$p['lead.view']&&!$p['sales.view']&&!$p['booking.view'])throw new DomainException('FORBIDDEN');
        $clock=self::clock($now);$specs=self::specs((int)$u['company_id'],$clock);$selected=$query['queue']??null;
        if($selected!==null&&(!is_string($selected)||!isset($specs[$selected])))throw new InvalidArgumentException('Invalid sales queue');
        [$limit,$offset]=self::paging($query,$selected===null?6:30);$out=['ok'=>true]+$clock+['metrics'=>[],'definitions'=>[],'queues'=>[]];
        foreach($specs as $key=>$s){
            $allowed=$p[$s['permission']];$out['definitions'][$key]=$s['definition'];
            if($selected===null||$selected===$key){$queue=self::queue($db,$s,$allowed,$limit,$offset);$out['queues'][$key]=$queue;$out['metrics'][$key]=$queue['total'];}
            else $out['metrics'][$key]=$allowed?self::count($db,$s['from'],$s['where'],$s['args']):null;
        }
        return $out;
    }
    public static function home(PDO $db,array $u,?DateTimeImmutable $now=null): array {
        $p=self::permissions($db,$u);$clock=self::clock($now);$d=$clock['date_range'];$cid=(int)$u['company_id'];$uid=(int)$u['id'];
        $access=['marketing'=>$p['lead.view'],'sales'=>$p['lead.view']||$p['sales.view']||$p['booking.view'],'operations'=>$p['operations.view'],'tasks'=>$p['task.view']];
        $out=['ok'=>true]+$clock+['access'=>$access,'metrics'=>array_fill_keys(['marketing_pending','new_leads','sales_followups_due','departures_today','bookings_at_risk','my_tasks_due'],null),'attention'=>[]];
        if($p['lead.view'])$out['metrics']['marketing_pending']=self::count($db,'marketing_content m',"m.company_id=? AND m.status='PENDING'",[$cid]);
        $specs=self::specs($cid,$clock);
        if($p['lead.view'])$out['metrics']['new_leads']=self::count($db,$specs['new_leads']['from'],$specs['new_leads']['where'],$specs['new_leads']['args']);
        $sales=$specs['followup_today'];$sales['where']="i.company_id=? AND i.status NOT IN ('CONFIRMED','LOST','CANCELLED') AND i.next_action_due<?";$sales['args']=[$cid,$d['end']];
        $out['attention']['sales']=self::queue($db,$sales,$p['sales.view'],8,0);$out['metrics']['sales_followups_due']=$out['attention']['sales']['total'];
        $opsActive="b.operations_status NOT IN ('OPERATION_COMPLETED','COMPLETED','CANCELLED')";
        if($p['operations.view']){
            $out['metrics']['departures_today']=self::count($db,'bookings b',"b.company_id=? AND $opsActive AND b.start_date=?",[$cid,$d['today']]);
            $out['metrics']['bookings_at_risk']=self::count($db,'bookings b',"b.company_id=? AND $opsActive AND b.risk_level IN ('HIGH','CRITICAL')",[$cid]);
        }
        $ops=['kind'=>'booking','from'=>'bookings b LEFT JOIN users u ON u.id=b.operations_owner_id AND u.company_id=b.company_id',
            'select'=>'b.id,b.booking_ref ref,b.booking_ref title,b.lead_guest_name contact,b.operations_status status,u.full_name owner_name,b.start_date due_at,b.start_date event_date',
            'where'=>"b.company_id=? AND $opsActive AND (b.start_date=? OR b.risk_level IN ('HIGH','CRITICAL'))",'args'=>[$cid,$d['today']],
            'order'=>'b.start_date ASC,b.id ASC'];
        $out['attention']['operations']=self::queue($db,$ops,$p['operations.view'],8,0);
        $tasks=['kind'=>'task','from'=>'tasks t LEFT JOIN users u ON u.id=t.owner_user_id AND u.company_id=t.company_id',
            'select'=>"t.id,t.id ref,t.title,NULL contact,t.status,u.full_name owner_name,t.due_at,t.created_at event_date",
            'where'=>"t.company_id=? AND t.owner_user_id=? AND t.status IN ('OPEN','SNOOZED') AND t.due_at<?",'args'=>[$cid,$uid,$d['end']],
            'order'=>"CASE t.priority WHEN 'CRITICAL' THEN 0 WHEN 'HIGH' THEN 1 ELSE 2 END,t.due_at,t.id" ];
        $out['attention']['tasks']=self::queue($db,$tasks,$p['task.view'],8,0);$out['metrics']['my_tasks_due']=$out['attention']['tasks']['total'];
        $out['definitions']=['marketing_pending'=>'Pending marketing content, all dates.','new_leads'=>$specs['new_leads']['definition'],
            'sales_followups_due'=>'Active inquiry next actions due before the end of today (today and overdue).',
            'departures_today'=>'Bookings starting today that are not operation-completed, completed or cancelled.',
            'bookings_at_risk'=>'Operationally active bookings with persisted HIGH or CRITICAL risk; does not recalculate RC5 readiness.',
            'my_tasks_due'=>'Your OPEN/SNOOZED tasks due before the end of today. Undated, other-owner, done and cancelled tasks are excluded.'];
        return $out;
    }
    public static function handle(string $route,string $method,PDO $db,array $u): void {
        if(!in_array($route,['workspace/home','workspace/sales'],true)||$method!=='GET')return;
        try {Http::json($route==='workspace/home'?self::home($db,$u):self::sales($db,$u,$_GET));}
        catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);}
    }
}

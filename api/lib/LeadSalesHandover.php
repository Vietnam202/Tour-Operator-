<?php
declare(strict_types=1);

final class LeadSalesPermissionException extends RuntimeException {}

/** One lead/request identity, explicit Sales decisions, reviewed Customer identity and atomic task effects. */
final class LeadSalesHandover {
    private static function query(PDO $db,string $sql,array $args=[]): PDOStatement {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    private static function permissions(PDO $db,array $user,array $permissions): void {
        foreach($permissions as $permission)if(!Auth::can($db,(int)$user['id'],$permission))throw new LeadSalesPermissionException('Missing permission: '.$permission);
    }
    private static function text(array $b,string $key,int $limit,bool $required=false): string {
        $v=$b[$key]??'';if(!is_string($v)||strlen($v)>$limit||($required&&trim($v)===''))throw new InvalidArgumentException('Invalid '.$key);return trim($v);
    }
    private static function id(array $b,string $key,int $default=0,bool $required=false): int {
        $v=$b[$key]??$default;if(filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<($required?1:0)||(int)$v>PHP_INT_MAX)throw new InvalidArgumentException('Invalid '.$key);return (int)$v;
    }
    private static function due(array $b): string {
        $value=self::text($b,'next_action_due',19,true);$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if(!$date||$date->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('next_action_due must use YYYY-MM-DD HH:MM:SS in the company timezone');return $value;
    }
    private static function action(array $b): string {
        $key=self::text($b,'action_key',80,true);if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Invalid action_key');return $key;
    }
    private static function transaction(PDO $db,callable $fn): array {
        if($db->inTransaction())throw new LogicException('Lead commands require their own transaction');
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();try{$result=$fn();$db->commit();return $result;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function activeUser(PDO $db,int $company,int $id): void {
        if(!self::query($db,"SELECT id FROM users WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$company,$id])->fetchColumn())throw new InvalidArgumentException('Owner must be an active user in this company');
    }
    private static function lock(PDO $db,int $company,int $leadId): array {
        $request=self::query($db,'SELECT request_id FROM leads WHERE company_id=? AND id=?',[$company,$leadId])->fetchColumn();
        if(!$request)throw new OutOfBoundsException('Lead not found');
        $r=self::query($db,'SELECT * FROM lead_requests WHERE company_id=? AND id=? FOR UPDATE',[$company,$request])->fetch(PDO::FETCH_ASSOC);
        $lead=self::query($db,'SELECT * FROM leads WHERE company_id=? AND id=? AND request_id=? FOR UPDATE',[$company,$leadId,$request])->fetch(PDO::FETCH_ASSOC);
        if(!$r||!$lead)throw new OutOfBoundsException('Lead request not found');return [$lead,$r];
    }
    private static function version(array $row,array $b,string $column): void {
        if(self::id($b,'expected_version',0,true)!==(int)$row[$column])throw new DomainException('VERSION_CONFLICT: refresh the lead before applying this change');
    }
    private static function summary(array $lead): array {
        return ['id'=>(int)$lead['id'],'request_id'=>(int)$lead['request_id'],'handover_status'=>$lead['handover_status'],'handover_version'=>(int)$lead['handover_version'],'owner_user_id'=>(int)$lead['owner_user_id'],'sales_owner_user_id'=>$lead['sales_owner_user_id']===null?null:(int)$lead['sales_owner_user_id'],'next_action_due'=>$lead['next_action_due']];
    }
    private static function history(PDO $db,array $user,array $lead,?string $from,string $action,?string $reason): void {
        $owner=(int)($lead['sales_owner_user_id']?:$lead['owner_user_id']);
        if(in_array($lead['handover_status'],['RETURNED','PENDING'],true))$owner=(int)$lead['owner_user_id'];
        self::query($db,'INSERT INTO lead_sales_history(company_id,lead_id,handover_version,action_code,from_status,to_status,actor_user_id,owner_user_id,next_action_due,reason) VALUES(?,?,?,?,?,?,?,?,?,?)',[$user['company_id'],$lead['id'],$lead['handover_version'],$action,$from,$lead['handover_status'],$user['id'],$owner,$lead['next_action_due'],$reason]);
    }
    private static function closeLeadTasks(PDO $db,int $company,int $lead): void {
        self::query($db,"UPDATE tasks SET status='DONE',completed_at=CURRENT_TIMESTAMP WHERE company_id=? AND entity_type='lead' AND entity_id=? AND source='AUTOMATION' AND rule_code IN ('LEAD_SALES_REVIEW','LEAD_SALES_NEXT_ACTION','LEAD_REQUALIFY') AND status IN ('OPEN','SNOOZED')",[$company,$lead]);
    }
    private static function task(array $lead,string $title,string $rule,?int $owner=null): array {
        return ['title'=>$title,'entity_type'=>'lead','entity_id'=>(int)$lead['id'],'owner_user_id'=>$owner??(int)$lead['owner_user_id'],'due_at'=>$lead['next_action_due'],'rule_code'=>$rule];
    }
    public static function qualify(PDO $db,array $user,int $requestId,array $b): array {
        self::permissions($db,$user,['lead.view','lead.manage']);$key=self::action($b);$hash=DomainOutbox::hash(['action'=>'qualify','request_id'=>$requestId,'body'=>$b]);
        return self::transaction($db,function() use($db,$user,$requestId,$b,$key,$hash){
            $cid=(int)$user['company_id'];$r=self::query($db,'SELECT * FROM lead_requests WHERE company_id=? AND id=? FOR UPDATE',[$cid,$requestId])->fetch(PDO::FETCH_ASSOC);
            if(!$r)throw new OutOfBoundsException('Request not found');if($old=DomainOutbox::commandReplay($db,$user,$key,$hash))return $old;
            self::version($r,$b,'version_no');if($r['status']!=='NEW')throw new DomainException('REQUEST_ALREADY_QUALIFIED: use its existing lead');
            $note=self::text($b,'qualification_note',8000,true);$owner=self::id($b,'owner_user_id',(int)$user['id'],true);$due=self::due($b);self::activeUser($db,$cid,$owner);
            self::query($db,'INSERT INTO leads(company_id,request_id,owner_user_id,qualification_note,qualified_by,next_action_due) VALUES(?,?,?,?,?,?)',[$cid,$requestId,$owner,$note,$user['id'],$due]);$id=(int)$db->lastInsertId();
            self::query($db,"UPDATE lead_requests SET status='QUALIFIED',version_no=version_no+1 WHERE company_id=? AND id=?",[$cid,$requestId]);
            $lead=self::query($db,'SELECT * FROM leads WHERE company_id=? AND id=?',[$cid,$id])->fetch(PDO::FETCH_ASSOC);self::history($db,$user,$lead,null,'QUALIFIED',$note);
            $result=self::summary($lead);$result['request_version']=(int)$r['version_no']+1;
            DomainOutbox::event($db,$user,'lead.qualified','lead',$id,1,['request_id'=>$requestId,'campaign_id'=>$r['campaign_id'],'source'=>$r['source'],'owner_user_id'=>$owner,'department'=>'SALES'],self::task($lead,'Review qualified lead','LEAD_SALES_REVIEW'));
            Audit::log($db,$cid,(int)$user['id'],'LEAD_QUALIFIED','lead',$id,null,$result+['qualification_note'=>$note]);DomainOutbox::commandResult($db,$user,$key,$hash,'lead',$id,$result);return $result;
        });
    }
    public static function accept(PDO $db,array $user,int $id,array $b): array { return self::decision($db,$user,$id,$b,'accept'); }
    public static function returnLead(PDO $db,array $user,int $id,array $b): array { return self::decision($db,$user,$id,$b,'return'); }
    public static function resubmit(PDO $db,array $user,int $id,array $b): array { return self::decision($db,$user,$id,$b,'resubmit'); }
    private static function decision(PDO $db,array $user,int $id,array $b,string $action): array {
        self::permissions($db,$user,['lead.view',$action==='resubmit'?'lead.manage':'lead.sales_accept']);$key=self::action($b);$hash=DomainOutbox::hash(['action'=>$action,'lead_id'=>$id,'body'=>$b]);
        return self::transaction($db,function() use($db,$user,$id,$b,$action,$key,$hash){
            $cid=(int)$user['company_id'];[$lead,$request]=self::lock($db,$cid,$id);if($old=DomainOutbox::commandReplay($db,$user,$key,$hash))return $old;
            self::version($lead,$b,'handover_version');$from=$lead['handover_status'];$reason=null;
            if($lead['inquiry_id']||$from==='CONVERTED')throw new DomainException('Lead is already converted');
            if($action==='accept'){
                if($from!=='PENDING')throw new DomainException('Only a pending handover can be accepted');
                $owner=self::id($b,'sales_owner_user_id',(int)$user['id'],true);self::activeUser($db,$cid,$owner);
                $due=self::due($b);self::query($db,"UPDATE leads SET handover_status='ACCEPTED',handover_version=handover_version+1,sales_owner_user_id=?,next_action_due=?,accepted_by=?,accepted_at=CURRENT_TIMESTAMP,return_reason=NULL WHERE company_id=? AND id=?",[$owner,$due,$user['id'],$cid,$id]);
                $event='lead.sales_accepted';$audit='LEAD_SALES_ACCEPTED';
            }elseif($action==='return'){
                if(!in_array($from,['PENDING','ACCEPTED'],true))throw new DomainException('Only a pending or accepted handover can be returned');
                $reason=self::text($b,'reason',8000,true);$due=(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');self::activeUser($db,$cid,(int)$lead['owner_user_id']);
                self::query($db,"UPDATE leads SET handover_status='RETURNED',handover_version=handover_version+1,next_action_due=?,returned_by=?,returned_at=CURRENT_TIMESTAMP,return_reason=? WHERE company_id=? AND id=?",[$due,$user['id'],$reason,$cid,$id]);$event='lead.sales_returned';$audit='LEAD_SALES_RETURNED';
            }else{
                if($from!=='RETURNED')throw new DomainException('Only a returned lead can be resubmitted');
                $reason=self::text($b,'qualification_note',8000,true);$owner=self::id($b,'owner_user_id',(int)$lead['owner_user_id'],true);self::activeUser($db,$cid,$owner);$due=self::due($b);
                self::query($db,"UPDATE leads SET handover_status='PENDING',handover_version=handover_version+1,owner_user_id=?,qualification_note=?,next_action_due=?,sales_owner_user_id=NULL,accepted_by=NULL,accepted_at=NULL,return_reason=NULL WHERE company_id=? AND id=?",[$owner,$reason,$due,$cid,$id]);$event='lead.resubmitted';$audit='LEAD_RESUBMITTED';
            }
            $changed=self::query($db,'SELECT * FROM leads WHERE company_id=? AND id=?',[$cid,$id])->fetch(PDO::FETCH_ASSOC);self::closeLeadTasks($db,$cid,$id);self::history($db,$user,$changed,$from,strtoupper($action),$reason);
            $task=match($action){'accept'=>self::task($changed,'Prepare Sales next action','LEAD_SALES_NEXT_ACTION',(int)$changed['sales_owner_user_id']),'return'=>self::task($changed,'Review returned lead','LEAD_REQUALIFY'),default=>self::task($changed,'Review resubmitted lead','LEAD_SALES_REVIEW')};
            $result=self::summary($changed);DomainOutbox::event($db,$user,$event,'lead',$id,(int)$changed['handover_version'],['request_id'=>$request['id'],'from_status'=>$from,'to_status'=>$changed['handover_status'],'reason'=>$reason,'department'=>$action==='return'?'MARKETING':'SALES'],$task);
            Audit::log($db,$cid,(int)$user['id'],$audit,'lead',$id,self::summary($lead),$result+['reason'=>$reason]);DomainOutbox::commandResult($db,$user,$key,$hash,'lead',$id,$result);return $result;
        });
    }
    private static function candidates(PDO $db,int $company,array $request): array {
        $email=strtolower(trim((string)($request['email']??'')));$phone=preg_replace('/\D/','',(string)($request['phone']??''));$items=[];
        // Contact matching is deliberately conservative: no name-only inference and no identity merge.
        $rows=self::query($db,"SELECT id,customer_ref,full_name,email,whatsapp,market,version_no FROM customers WHERE company_id=? AND status='ACTIVE' AND (email IS NOT NULL OR whatsapp IS NOT NULL) ORDER BY id",[$company])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){$reasons=[];if($email!==''&&$email===strtolower(trim((string)$row['email'])))$reasons[]='EMAIL_EXACT';$other=preg_replace('/\D/','',(string)$row['whatsapp']);if(strlen($phone)>=7&&$phone===$other)$reasons[]='PHONE_EXACT';if($reasons){$row['id']=(int)$row['id'];$row['version_no']=(int)$row['version_no'];$row['match_reasons']=$reasons;$items[]=$row;}}return $items;
    }
    public static function customerCandidates(PDO $db,array $user,int $id): array {
        self::permissions($db,$user,['lead.view','sales.view']);
        return self::transaction($db,function() use($db,$user,$id){
            [$lead,$request]=self::lock($db,(int)$user['company_id'],$id);if($lead['handover_status']!=='ACCEPTED'||$lead['inquiry_id'])throw new DomainException('Customer identity is reviewed after Sales accepts this lead');
            $items=self::candidates($db,(int)$user['company_id'],$request);$key=bin2hex(random_bytes(24));$expiry=(new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
            self::query($db,'DELETE FROM lead_identity_reviews WHERE company_id=? AND lead_id=? AND actor_user_id=? AND expires_at<CURRENT_TIMESTAMP AND used_at IS NULL',[$user['company_id'],$id,$user['id']]);
            self::query($db,'INSERT INTO lead_identity_reviews(company_id,lead_id,actor_user_id,handover_version,review_key,candidate_hash,expires_at) VALUES(?,?,?,?,?,?,?)',[$user['company_id'],$id,$user['id'],$lead['handover_version'],$key,DomainOutbox::hash($items),$expiry]);
            return ['items'=>$items,'identity_review_key'=>$key,'expires_at'=>$expiry,'handover_version'=>(int)$lead['handover_version'],'contact'=>['full_name'=>$request['contact_name'],'email'=>$request['email'],'whatsapp'=>$request['phone']]];
        });
    }
    public static function convert(PDO $db,array $user,int $id,array $b=[]): array {
        self::permissions($db,$user,['lead.view','lead.manage','inquiry.manage']);
        return self::transaction($db,function() use($db,$user,$id,$b){
            $cid=(int)$user['company_id'];$uid=(int)$user['id'];[$lead,$request]=self::lock($db,$cid,$id);
            // Historical conversion is a read-only replay, never a new identity or task.
            if($lead['inquiry_id']){
                $row=self::query($db,'SELECT i.id inquiry_id,i.trip_id,t.customer_id FROM inquiries i JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id WHERE i.company_id=? AND i.id=?',[$cid,$lead['inquiry_id']])->fetch(PDO::FETCH_ASSOC);
                if(!$row)throw new DomainException('Existing conversion link requires review');
                if(isset($b['action_key'])){$key=self::action($b);$hash=DomainOutbox::hash(['action'=>'convert','lead_id'=>$id,'body'=>$b]);if($old=DomainOutbox::commandReplay($db,$user,$key,$hash))return $old;}
                return ['inquiry_id'=>(int)$row['inquiry_id'],'trip_id'=>(int)$row['trip_id'],'customer_id'=>$row['customer_id']===null?null:(int)$row['customer_id'],'handover_version'=>(int)$lead['handover_version']];
            }
            self::permissions($db,$user,['sales.view']);$key=self::action($b);$hash=DomainOutbox::hash(['action'=>'convert','lead_id'=>$id,'body'=>$b]);if($old=DomainOutbox::commandReplay($db,$user,$key,$hash))return $old;
            self::version($lead,$b,'handover_version');if($lead['handover_status']!=='ACCEPTED')throw new DomainException('SALES_ACCEPT_REQUIRED: Sales must explicitly accept this lead before conversion');
            $mode=self::text($b,'identity_mode',24,true);if(!in_array($mode,['LINK_EXISTING','CREATE_NEW'],true)||($b['identity_reviewed']??false)!==true)throw new InvalidArgumentException('Review customer suggestions and explicitly choose LINK_EXISTING or CREATE_NEW');
            $identityReason=self::text($b,'identity_reason',1000);$duplicateReason=self::text($b,'duplicate_reason',1000);
            $reviewKey=self::text($b,'identity_review_key',48,true);
            $review=self::query($db,'SELECT * FROM lead_identity_reviews WHERE company_id=? AND lead_id=? AND actor_user_id=? AND review_key=? FOR UPDATE',[$cid,$id,$uid,$reviewKey])->fetch(PDO::FETCH_ASSOC);
            if(!$review||(int)$review['handover_version']!==(int)$lead['handover_version']||$review['used_at']!==null||$review['expires_at']<date('Y-m-d H:i:s'))throw new DomainException('IDENTITY_REVIEW_EXPIRED: refresh customer suggestions');
            // Serialize VS1 identity choices within the tenant, then recheck suggestions under READ COMMITTED.
            self::query($db,'SELECT id FROM companies WHERE id=? FOR UPDATE',[$cid])->fetchColumn();$candidates=self::candidates($db,$cid,$request);
            if(!hash_equals($review['candidate_hash'],DomainOutbox::hash($candidates)))throw new DomainException('IDENTITY_CANDIDATES_CHANGED: refresh customer suggestions');
            $owner=(int)$lead['sales_owner_user_id'];self::activeUser($db,$cid,$owner);$market=self::text($b,'market',120);$agent=self::id($b,'agent_id');
            if($agent){$a=self::query($db,"SELECT id,market FROM agents WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$cid,$agent])->fetch(PDO::FETCH_ASSOC);if(!$a)throw new InvalidArgumentException('Agent must be active in this company');if($market==='')$market=(string)($a['market']??'');}
            if($mode==='LINK_EXISTING'){
                $customer=self::id($b,'customer_id',0,true);$chosen=self::query($db,"SELECT id,market FROM customers WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$cid,$customer])->fetch(PDO::FETCH_ASSOC);if(!$chosen)throw new InvalidArgumentException('Customer must be active in this company');
                if(!in_array($customer,array_column($candidates,'id'),true)&&$identityReason==='')throw new InvalidArgumentException('Explain the deliberate link to a customer outside the contact suggestions');if($market==='')$market=(string)($chosen['market']??'');
            }else{
                self::permissions($db,$user,['customer.manage']);if($candidates&&strlen($duplicateReason)<10)throw new InvalidArgumentException('Explain why a new identity is needed despite matching contact suggestions');
                $new=$b['new_customer']??null;if(!is_array($new))throw new InvalidArgumentException('Invalid new_customer');$name=self::text($new,'full_name',190,true);$email=self::text($new,'email',190);$phone=self::text($new,'whatsapp',64);
                if(($email===''&&$phone==='')||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('A valid customer email or phone is required');if($market==='')$market=self::text($new,'market',120);
                // Corrections to new-customer contact fields cannot silently bypass an existing contact match.
                $newMatches=self::candidates($db,$cid,['email'=>$email,'phone'=>$phone]);if($newMatches&&strlen($duplicateReason)<10)throw new InvalidArgumentException('The new customer contact already exists; review the existing identity or explain the distinct new identity');
                self::query($db,'INSERT INTO customers(company_id,customer_ref,full_name,email,whatsapp,market,source,sales_owner_id,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$cid,'CUS-'.bin2hex(random_bytes(12)),$name,$email?:null,$phone?:null,$market?:null,$request['source'],$owner,$uid,$uid]);$customer=(int)$db->lastInsertId();
                Audit::log($db,$cid,$uid,'CUSTOMER_CREATED_FROM_LEAD','customer',$customer,null,['lead_id'=>$id,'identity_review_key'=>$reviewKey,'duplicate_reason'=>$duplicateReason?:null]);
            }
            $due=(string)$lead['next_action_due'];if($due==='')throw new DomainException('Accepted handover requires a next action due date');
            $title=($request['destination']?:'Trip').' - '.$request['contact_name'];$title=function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);
            self::query($db,'INSERT INTO trips(company_id,trip_ref,title,customer_id,agent_id,lead_contact_name,lead_whatsapp,lead_email,market,start_date,total_guests,paying_pax,foc,sales_owner_id,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$cid,'TRIP-'.bin2hex(random_bytes(12)),$title,$customer,$agent?:null,$request['contact_name'],$request['phone'],$request['email'],$market?:null,$request['travel_date'],$request['total_guests'],$request['paying_pax'],$request['foc'],$owner,$uid,$uid]);$trip=(int)$db->lastInsertId();
            $source=strtoupper((string)$request['source']);if(!in_array($source,['WHATSAPP','EMAIL','WEBSITE','FACEBOOK','LINKEDIN','REFERRAL','EXISTING_AGENT','REPEAT_CUSTOMER','WALK_IN'],true))$source='OTHER';
            self::query($db,'INSERT INTO inquiries(company_id,inquiry_ref,trip_id,request_text,destination_text,hotel_level,source,next_action,next_action_due,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$cid,'INQ-'.bin2hex(random_bytes(12)),$trip,$request['message'],$request['destination'],$request['hotel_level'],$source,'Prepare quotation',$due,$uid,$uid]);$inquiry=(int)$db->lastInsertId();
            self::query($db,"UPDATE leads SET inquiry_id=?,status='CONVERTED',handover_status='CONVERTED',handover_version=handover_version+1,converted_at=CURRENT_TIMESTAMP WHERE company_id=? AND id=?",[$inquiry,$cid,$id]);self::query($db,'UPDATE lead_identity_reviews SET used_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=?',[$review['id'],$cid]);
            self::closeLeadTasks($db,$cid,$id);$changed=self::query($db,'SELECT * FROM leads WHERE company_id=? AND id=?',[$cid,$id])->fetch(PDO::FETCH_ASSOC);self::history($db,$user,$changed,'ACCEPTED','CONVERT',$identityReason?:($duplicateReason?:null));
            $result=['inquiry_id'=>$inquiry,'trip_id'=>$trip,'customer_id'=>$customer,'handover_version'=>(int)$changed['handover_version']];
            DomainOutbox::event($db,$user,'lead.converted','lead',$id,(int)$changed['handover_version'],['request_id'=>$request['id'],'campaign_id'=>$request['campaign_id'],'source'=>$request['source'],'inquiry_id'=>$inquiry,'trip_id'=>$trip,'customer_id'=>$customer,'identity_mode'=>$mode,'department'=>'SALES'],['title'=>'Prepare quotation','entity_type'=>'inquiry','entity_id'=>$inquiry,'owner_user_id'=>$owner,'due_at'=>$due,'rule_code'=>'INQUIRY_NEXT_ACTION']);
            Audit::log($db,$cid,$uid,'LEAD_CONVERTED','lead',$id,self::summary($lead),$result+['identity_mode'=>$mode,'identity_review_key'=>$reviewKey]);DomainOutbox::commandResult($db,$user,$key,$hash,'lead',$id,$result);return $result;
        });
    }
    public static function leads(PDO $db,array $user,array $filters=[]): array {
        self::permissions($db,$user,['lead.view']);$cid=(int)$user['company_id'];$where='l.company_id=?';$args=[$cid];
        foreach(['lead_id'=>'l.id','request_id'=>'l.request_id'] as $key=>$column)if(isset($filters[$key])){$where.=' AND '.$column.'=?';$args[]=self::id($filters,$key,0,true);}
        $status=self::text($filters,'handover_status',24);if($status!==''){if(!in_array($status,['PENDING','ACCEPTED','RETURNED','CONVERTED'],true))throw new InvalidArgumentException('Invalid handover_status');$where.=' AND l.handover_status=?';$args[]=$status;}
        $limit=self::id($filters,'limit',100,true);$offset=self::id($filters,'offset');if($limit>100||$offset>100000)throw new InvalidArgumentException('Invalid pagination');
        $total=(int)self::query($db,'SELECT COUNT(*) FROM leads l WHERE '.$where,$args)->fetchColumn();
        $rows=self::query($db,"SELECT l.*,r.contact_name,r.email,r.phone,r.source,r.campaign_id,r.attribution_json,r.destination,r.travel_date,r.total_guests,r.paying_pax,r.foc,r.message,r.hotel_level,u.full_name owner_name,s.full_name sales_owner_name FROM leads l JOIN lead_requests r ON r.id=l.request_id AND r.company_id=l.company_id LEFT JOIN users u ON u.id=l.owner_user_id AND u.company_id=l.company_id LEFT JOIN users s ON s.id=l.sales_owner_user_id AND s.company_id=l.company_id WHERE $where ORDER BY l.id DESC LIMIT $limit OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row)$row['history']=self::query($db,'SELECT h.*,u.full_name actor_name FROM lead_sales_history h LEFT JOIN users u ON u.id=h.actor_user_id AND u.company_id=h.company_id WHERE h.company_id=? AND h.lead_id=? ORDER BY h.handover_version',[$cid,$row['id']])->fetchAll(PDO::FETCH_ASSOC);unset($row);
        $counts=['PENDING'=>0,'ACCEPTED'=>0,'RETURNED'=>0,'CONVERTED'=>0];foreach(self::query($db,'SELECT handover_status,COUNT(*) total FROM leads WHERE company_id=? GROUP BY handover_status',[$cid])->fetchAll(PDO::FETCH_ASSOC) as $r)$counts[$r['handover_status']]=(int)$r['total'];
        return ['items'=>$rows,'total'=>$total,'counts'=>$counts,'limit'=>$limit,'offset'=>$offset];
    }
}

<?php
declare(strict_types=1);
require_once __DIR__.'/DomainOutbox.php';
require_once __DIR__.'/LeadSalesHandover.php';

/** Request attribution is append-only; conversion links to existing v2.4 inquiries. */
final class LeadHub {
    private static function query(PDO $db, string $sql, array $args=[]): PDOStatement {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=preg_replace('/\s+FOR UPDATE\b/i','',$sql);
        $s=$db->prepare($sql); $s->execute($args); return $s;
    }
    private static function text(array $b, string $key, int $limit, bool $required=false): string {
        $v=$b[$key]??'';
        if(!is_string($v) || strlen($v)>$limit || ($required && trim($v)==='')) {
            throw new InvalidArgumentException('Invalid '.$key);
        }
        return trim($v);
    }
    private static function integer(array $b, string $key, int $default): int {
        $v=$b[$key]??$default;
        if(filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<0 || (int)$v>10000) {
            throw new InvalidArgumentException('Invalid '.$key);
        }
        return (int)$v;
    }
    public static function validateRequest(array $b): array {
        $name=self::text($b,'contact_name',190,true);
        $email=self::text($b,'email',190); $phone=self::text($b,'phone',64);
        if(($email==='' && $phone==='') || ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('A valid email or phone is required');
        }
        $total=self::integer($b,'total_guests',0); $pay=self::integer($b,'paying_pax',0); $foc=self::integer($b,'foc',0);
        if($total<1 || $pay<1 || $pay+$foc>$total) throw new InvalidArgumentException('Invalid guest counts');
        $date=self::text($b,'travel_date',10);
        if($date!=='') {
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if(!$d || $d->format('Y-m-d')!==$date) throw new InvalidArgumentException('Invalid travel_date');
        }
        $attribution=[];
        foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id','referrer','landing_url'] as $key) {
            $attribution[$key]=self::text($b,$key,1000);
        }
        return ['contact_name'=>$name,'email'=>$email,'phone'=>$phone,'travel_date'=>$date,
            'total_guests'=>$total,'paying_pax'=>$pay,'foc'=>$foc,
            'destination'=>self::text($b,'destination',500),'hotel_level'=>self::text($b,'hotel_level',32),
            'message'=>self::text($b,'message',8000),'attribution'=>$attribution];
    }
    private static function transaction(PDO $db, callable $fn): array {
        $db->beginTransaction();
        try { $result=$fn(); $db->commit(); return $result; }
        catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    public static function submit(PDO $db, string $token, array $b): array {
        $payload=self::validateRequest($b);
        $key=self::text($b,'submission_key',80,true);
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)) throw new InvalidArgumentException('Invalid submission_key');
        if(self::text($b,'website',255)!=='') throw new InvalidArgumentException('Submission rejected');
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        return self::transaction($db,function() use($db,$token,$payload,$key,$hash) {
            // Lock form to serialize retries and the rate-limit counter without trusting client IP headers.
            $form=self::query($db,"SELECT f.*,c.source FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id WHERE f.public_token=? AND f.status='ACTIVE' AND c.status='ACTIVE' FOR UPDATE",[$token])->fetch();
            if(!$form) throw new OutOfBoundsException('Form not found');
            $old=self::query($db,'SELECT id,form_id,payload_hash FROM lead_requests WHERE company_id=? AND submission_key=?',[$form['company_id'],$key])->fetch();
            if($old) {
                if((int)$old['form_id']!==(int)$form['id'] || !hash_equals($old['payload_hash'],$hash)) throw new DomainException('Submission key already used for different content');
                return ['accepted'=>true];
            }
            $window=date('Y-m-d H:i:00');
            self::query($db,'INSERT INTO lead_submission_windows(form_id,window_start,submissions) VALUES(?,?,1) ON DUPLICATE KEY UPDATE submissions=submissions+1',[$form['id'],$window]);
            $count=(int)self::query($db,'SELECT submissions FROM lead_submission_windows WHERE form_id=? AND window_start=?',[$form['id'],$window])->fetchColumn();
            if($count>30) throw new OverflowException('Too many submissions; retry later');
            self::query($db,'DELETE FROM lead_submission_windows WHERE form_id=? AND window_start < DATE_SUB(NOW(),INTERVAL 1 DAY)',[$form['id']]);
            self::query($db,'INSERT INTO lead_requests(company_id,form_id,campaign_id,source,submission_key,payload_hash,contact_name,email,phone,travel_date,total_guests,paying_pax,foc,destination,hotel_level,message,attribution_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $form['company_id'],$form['id'],$form['campaign_id'],$form['source'],$key,$hash,$payload['contact_name'],$payload['email']?:null,$payload['phone']?:null,$payload['travel_date']?:null,
                $payload['total_guests'],$payload['paying_pax'],$payload['foc'],$payload['destination'],$payload['hotel_level'],$payload['message'],json_encode($payload['attribution'],JSON_THROW_ON_ERROR)]);
            $id=(int)$db->lastInsertId();
            Audit::log($db,(int)$form['company_id'],null,'LEAD_REQUEST_RECEIVED','lead_request',$id,null,['form_id'=>$form['id'],'campaign_id'=>$form['campaign_id']]);
            return ['accepted'=>true];
        });
    }
    public static function qualify(PDO $db, array $user, int $id, array $b): array {
        return LeadSalesHandover::qualify($db,$user,$id,$b);
    }
    public static function convert(PDO $db, array $user, int $id,array $body=[]): array {
        return LeadSalesHandover::convert($db,$user,$id,$body);
    }
    public static function handle(string $route,string $method,PDO $db,array $user): void {
        if(!str_starts_with($route,'lead-hub/')) return;
        try {
            $cid=(int)$user['company_id']; $uid=(int)$user['id'];
            if($method==='GET')Auth::requirePermission($db,$user,'lead.view');
            if($method!=='GET'&&in_array($route,['lead-hub/campaigns','lead-hub/forms'],true))Auth::requirePermission($db,$user,'campaign.manage');
            if($method==='GET' && $route==='lead-hub/owners') {
                Http::json(['ok'=>true,'items'=>self::query($db,"SELECT id,full_name FROM users WHERE company_id=? AND status='ACTIVE' ORDER BY full_name,id",[$cid])->fetchAll()]);
            }
            if($method==='GET' && preg_match('#^lead-hub/leads/(\d+)/customer-candidates$#',$route,$m)) Http::json(['ok'=>true]+LeadSalesHandover::customerCandidates($db,$user,(int)$m[1]));
            if($method==='GET' && in_array($route,['lead-hub/requests','lead-hub/campaigns','lead-hub/forms'],true)) {
                $table=['lead-hub/requests'=>'lead_requests','lead-hub/campaigns'=>'campaigns','lead-hub/forms'=>'lead_forms'][$route];
                $where='company_id=?';$args=[$cid];
                if($route==='lead-hub/requests'&&isset($_GET['request_id'])){
                    $id=filter_var($_GET['request_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if($id===false)throw new InvalidArgumentException('Invalid request_id');
                    $where.=' AND id=?';$args[]=$id;
                }
                if($route==='lead-hub/requests'){
                    if(isset($_GET['status'])){$status=self::text($_GET,'status',24,true);if(!in_array($status,['NEW','QUALIFIED','REJECTED'],true))throw new InvalidArgumentException('Invalid status');$where.=' AND status=?';$args[]=$status;}
                    $limit=filter_var($_GET['limit']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);$offset=filter_var($_GET['offset']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);if($limit===false||$offset===false)throw new InvalidArgumentException('Invalid pagination');
                    $total=(int)self::query($db,"SELECT COUNT(*) FROM $table WHERE $where",$args)->fetchColumn();Http::json(['ok'=>true,'items'=>self::query($db,"SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset",$args)->fetchAll(),'total'=>$total,'limit'=>$limit,'offset'=>$offset]);
                }
                Http::json(['ok'=>true,'items'=>self::query($db,"SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT 300",$args)->fetchAll()]);
            }
            if($method==='GET' && $route==='lead-hub/leads') {
                Http::json(['ok'=>true]+LeadSalesHandover::leads($db,$user,$_GET));
            }
            if($method==='POST' && $route==='lead-hub/campaigns') {
                $b=Http::body(); $name=self::text($b,'name',190,true);$source=self::text($b,'source',80,true);
                $result=self::transaction($db,function() use($db,$cid,$uid,$name,$source) {
                    self::query($db,'INSERT INTO campaigns(company_id,name,source,created_by) VALUES(?,?,?,?)',[$cid,$name,$source,$uid]);$id=(int)$db->lastInsertId();
                    Audit::log($db,$cid,$uid,'CAMPAIGN_CREATED','campaign',$id,null,['name'=>$name,'source'=>$source]);return ['id'=>$id];
                }); Http::json(['ok'=>true]+$result,201);
            }
            if($method==='POST' && $route==='lead-hub/forms') {
                $b=Http::body();$name=self::text($b,'name',190,true);$campaign=self::integer($b,'campaign_id',0);$url=self::text($b,'landing_url',1000);
                if($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true))) throw new InvalidArgumentException('Invalid landing_url');
                $result=self::transaction($db,function() use($db,$cid,$uid,$name,$campaign,$url) {
                    if(!self::query($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE' FOR UPDATE",[$cid,$campaign])->fetchColumn()) throw new OutOfBoundsException('Campaign not found');
                    $token=bin2hex(random_bytes(32));
                    self::query($db,'INSERT INTO lead_forms(company_id,campaign_id,name,public_token,landing_url,created_by) VALUES(?,?,?,?,?,?)',[$cid,$campaign,$name,$token,$url?:null,$uid]);$id=(int)$db->lastInsertId();
                    Audit::log($db,$cid,$uid,'LEAD_FORM_CREATED','lead_form',$id,null,['campaign_id'=>$campaign]);return ['id'=>$id,'public_token'=>$token];
                });Http::json(['ok'=>true]+$result,201);
            }
            if($method==='POST' && preg_match('#^lead-hub/requests/(\d+)/qualify$#',$route,$m)) Http::json(['ok'=>true]+self::qualify($db,$user,(int)$m[1],Http::body()));
            if($method==='POST' && preg_match('#^lead-hub/leads/(\d+)/(accept|return|resubmit)$#',$route,$m)){
                $body=Http::body();$result=match($m[2]){'accept'=>LeadSalesHandover::accept($db,$user,(int)$m[1],$body),'return'=>LeadSalesHandover::returnLead($db,$user,(int)$m[1],$body),default=>LeadSalesHandover::resubmit($db,$user,(int)$m[1],$body)};Http::json(['ok'=>true]+$result);
            }
            if($method==='POST' && preg_match('#^lead-hub/leads/(\d+)/convert$#',$route,$m)) {
                Http::json(['ok'=>true]+self::convert($db,$user,(int)$m[1],Http::body()));
            }
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        } catch(LeadSalesPermissionException $e) { Http::json(['ok'=>false,'error'=>'FORBIDDEN','message'=>'You do not have permission for this action.'],403); }
        catch(InvalidArgumentException $e) { Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422); }
        catch(OutOfBoundsException $e) { Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404); }
        catch(DomainException $e) { Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409); }
        catch(PDOException $e) { if(in_array((string)$e->getCode(),['23000','40001'],true))Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>'A concurrent change conflicted with this action. Refresh and retry.'],409);throw $e; }
    }
}

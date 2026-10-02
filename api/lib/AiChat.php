<?php
declare(strict_types=1);
require_once __DIR__.'/AiProvider.php';

final class AiChat {
    private static function q(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
    public static function validateMessage(array $b): array {
        $message=$b['message']??null;$key=$b['request_key']??null;
        if(!is_string($message)||trim($message)===''||strlen($message)>10000)throw new InvalidArgumentException('Message must contain 1–10000 bytes.');
        if(!is_string($key)||!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('Invalid request key.');
        return ['message'=>trim($message),'request_key'=>$key];
    }
    private static function thread(PDO $db,array $u,int $id): array {
        $r=self::q($db,'SELECT * FROM ai_threads WHERE id=? AND company_id=? AND user_id=?',[$id,$u['company_id'],$u['id']])->fetch();
        if(!$r)throw new OutOfBoundsException('Conversation not found.');return $r;
    }
    private static function turns(PDO $db,int $id): array {return self::q($db,'SELECT id,prompt,reply,model,created_at FROM ai_turns WHERE thread_id=? ORDER BY id LIMIT 60',[$id])->fetchAll();}
    private static function campaign(PDO $db,array $u,int $id): array {
        if(!Auth::can($db,(int)$u['id'],'lead.view'))throw new OutOfBoundsException('Campaign not available.');
        $r=self::q($db,'SELECT c.name,b.market,b.offer,b.audience FROM campaigns c LEFT JOIN marketing_briefs b ON b.company_id=c.company_id AND b.campaign_id=c.id WHERE c.company_id=? AND c.id=?',[$u['company_id'],$id])->fetch();
        if(!$r)throw new OutOfBoundsException('Campaign not available.');return $r;
    }
    public static function validateContextRef($ref): array {
        if(!is_array($ref)||array_diff(array_keys($ref),['workspace','type','id']))throw new InvalidArgumentException('Invalid context reference.');
        $workspace=$ref['workspace']??null;$type=$ref['type']??null;$id=$ref['id']??null;
        $types=['marketing'=>['campaign','content'],'sales'=>['quote','inquiry','customer'],'operations'=>['booking','service']];
        if(!is_string($workspace)||!isset($types[$workspace]))throw new InvalidArgumentException('Invalid workspace context.');
        if($type===null&&$id===null)return ['workspace'=>$workspace];
        if(!is_string($type)||!in_array($type,$types[$workspace],true)||!is_int($id)||$id<1||$id>2147483647)throw new InvalidArgumentException('Invalid entity context.');
        return ['workspace'=>$workspace,'type'=>$type,'id'=>$id];
    }
    private static function contextFields(array $row,array $fields): array {
        $out=[];foreach($fields as $field){$value=$row[$field]??null;if($value===null||is_int($value)||is_float($value)||is_bool($value))$out[$field]=$value;elseif(is_string($value))$out[$field]=function_exists('mb_substr')?mb_substr($value,0,3000,'UTF-8'):substr($value,0,3000);}
        return $out;
    }
    public static function resolveContext(PDO $db,array $u,$ref): array {
        $ref=self::validateContextRef($ref);
        if(!Auth::can($db,(int)$u['id'],'ai.chat'))throw new OutOfBoundsException('Context not available.');
        $workspace=$ref['workspace'];$type=$ref['type']??null;$data=[];$label=ucfirst($workspace).' workspace';
        if($type!==null){
            $permission=match($type){'campaign','content'=>'lead.view','quote','inquiry','customer'=>'sales.view','booking'=>'booking.view','service'=>'operations.view'};
            if(!Auth::can($db,(int)$u['id'],$permission))throw new OutOfBoundsException('Context not available.');
            $cid=(int)$u['company_id'];$id=$ref['id'];
            $row=match($type){
                'campaign'=>self::campaign($db,$u,$id),
                'content'=>self::q($db,'SELECT m.topic,m.channel,m.content_format,m.body,m.status,c.name campaign FROM marketing_content m JOIN campaigns c ON c.id=m.campaign_id AND c.company_id=m.company_id WHERE m.company_id=? AND m.id=?',[$cid,$id])->fetch(),
                'quote'=>self::q($db,'SELECT q.quote_ref,q.status,v.version_no,v.tour_name,v.start_date,v.end_date,v.total_guests,v.foc,v.hotel_level,v.guide_language,v.meals,v.schedule_json FROM quotes q JOIN quote_versions v ON v.quote_id=q.id AND v.version_no=q.current_version_no WHERE q.company_id=? AND q.id=?',[$cid,$id])->fetch(),
                'inquiry'=>self::q($db,'SELECT i.inquiry_ref,i.status,i.destination_text,i.hotel_level,i.meals,i.transport_type,i.guide_language,t.title,t.market,t.start_date,t.end_date,t.total_guests FROM inquiries i JOIN trips t ON t.id=i.trip_id AND t.company_id=i.company_id WHERE i.company_id=? AND i.id=?',[$cid,$id])->fetch(),
                'customer'=>self::q($db,'SELECT customer_ref,full_name,company_name,country,market,preferred_language FROM customers WHERE company_id=? AND id=?',[$cid,$id])->fetch(),
                'booking'=>self::q($db,'SELECT booking_ref,start_date,end_date,total_guests,operations_status FROM bookings WHERE company_id=? AND id=?',[$cid,$id])->fetch(),
                'service'=>self::q($db,'SELECT b.booking_ref,s.category,s.service_name,s.service_date,s.start_time,s.end_time,s.pickup_location,s.dropoff_location,s.pax,s.qty,s.booking_status,s.driver_name,s.vehicle_type,s.guide_name FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND s.id=?',[$cid,$id])->fetch()
            };
            if(!$row)throw new OutOfBoundsException('Context not available.');
            $fields=match($type){
                'campaign'=>['name','market','offer','audience'],
                'content'=>['topic','channel','content_format','body','status','campaign'],
                'quote'=>['quote_ref','status','version_no','tour_name','start_date','end_date','total_guests','foc','hotel_level','guide_language','meals'],
                'inquiry'=>['inquiry_ref','status','destination_text','hotel_level','meals','transport_type','guide_language','title','market','start_date','end_date','total_guests'],
                'customer'=>['customer_ref','full_name','company_name','country','market','preferred_language'],
                'booking'=>['booking_ref','start_date','end_date','total_guests','operations_status'],
                'service'=>['booking_ref','category','service_name','service_date','start_time','end_time','pickup_location','dropoff_location','pax','qty','booking_status','driver_name','vehicle_type','guide_name']
            };
            $data=self::contextFields($row,$fields);
            if($type==='quote'){
                $schedule=json_decode((string)($row['schedule_json']??'[]'),true);$data['schedule']=[];
                if(is_array($schedule))foreach(array_slice($schedule,0,30) as $day)if(is_array($day))$data['schedule'][]=self::contextFields($day,['day','date','title','description','meals','overnight']);
                $data['schedule_truncated']=is_array($schedule)&&count($schedule)>30;
            }
            if($type==='booking'){
                $services=self::q($db,"SELECT s.category,s.service_name,s.service_date,s.start_time,s.end_time,s.pickup_location,s.dropoff_location,s.booking_status,s.driver_name,s.vehicle_type,s.guide_name FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=? AND b.id=? AND s.booking_status<>'CANCELLED' ORDER BY s.service_date,s.id LIMIT 61",[$cid,$id])->fetchAll();
                $data['services']=array_map(fn($service)=>self::contextFields($service,['category','service_name','service_date','start_time','end_time','pickup_location','dropoff_location','booking_status','driver_name','vehicle_type','guide_name']),array_slice($services,0,60));$data['services_truncated']=count($services)>60;
            }
            $label=ucfirst($workspace).' · '.ucfirst($type).' · '.($row['quote_ref']??$row['inquiry_ref']??$row['customer_ref']??$row['booking_ref']??$row['name']??$row['topic']??$id);
        }
        $context=['reference'=>$ref,'label'=>$label,'frozen_at'=>gmdate('c'),'data'=>$data,'limits'=>['field_chars'=>3000,'schedule_days'=>30,'booking_services'=>60]];
        if(strlen(json_encode($context,JSON_THROW_ON_ERROR))>60000)throw new LengthException('Context is too large; select a smaller service or inquiry reference.');
        return $context;
    }
    public static function createThread(PDO $db,array $u,array $body): array {
        if(!Auth::can($db,(int)$u['id'],'ai.chat'))throw new OutOfBoundsException('Conversation not available.');
        $profile=$body['profile']??'sales';if(!is_string($profile))throw new InvalidArgumentException('Invalid profile');AiProvider::instructions($profile);
        $title=$body['title']??'New conversation';if(!is_string($title)||trim($title)===''||strlen($title)>190)throw new InvalidArgumentException('Invalid title');
        $campaign=$body['campaign_id']??null;if($campaign!==null&&(!is_int($campaign)||$campaign<1))throw new InvalidArgumentException('Invalid campaign');
        if(array_key_exists('context_ref',$body)&&$body['context_ref']!==null){if($campaign!==null)throw new InvalidArgumentException('Choose one context reference.');$context=self::resolveContext($db,$u,$body['context_ref']);}
        else $context=$campaign?self::campaign($db,$u,$campaign):[];
        self::q($db,'INSERT INTO ai_threads(company_id,user_id,title,profile,context_json) VALUES(?,?,?,?,?)',[$u['company_id'],$u['id'],trim($title),$profile,json_encode($context,JSON_THROW_ON_ERROR)]);
        return ['id'=>(int)$db->lastInsertId()];
    }
    public static function send(PDO $db,array $u,array $config,int $id,array $b): array {
        $v=self::validateMessage($b);$thread=self::thread($db,$u,$id);$s=AiProvider::settings($config);
        if(!AiProvider::ready($s))throw new RuntimeException('AI is not configured on this server.');
        $lock='vta-ai-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn().':'.$id),0,45);
        if((int)self::q($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)throw new DomainException('This conversation is generating a reply. Please wait.');
        try {
            $hash=hash('sha256',$v['message']);
            $old=self::q($db,'SELECT id,reply,prompt_hash FROM ai_turns WHERE thread_id=? AND request_key=?',[$id,$v['request_key']])->fetch();
            if($old){if(!hash_equals($old['prompt_hash'],$hash))throw new DomainException('Request key was already used for different content.');return ['id'=>$old['id'],'reply'=>$old['reply'],'replayed'=>true];}
            $turns=self::turns($db,$id);if(count($turns)>=60)throw new LengthException('Start a new conversation after 60 replies.');
            $context=json_decode($thread['context_json'],true)?:[];
            if(isset($context['reference']))self::resolveContext($db,$u,$context['reference']); // Recheck access; keep the original frozen data.
            $payload=AiProvider::payload($s,$thread['profile'],$context,$turns,$v['message']);
            // Reserve one attempt atomically; failed provider calls also consume the daily allowance.
            self::q($db,'INSERT IGNORE INTO ai_daily_usage(company_id,user_id,usage_date,attempts) VALUES(?,?,CURDATE(),0)',[$u['company_id'],$u['id']]);
            $quota=self::q($db,'UPDATE ai_daily_usage SET attempts=attempts+1 WHERE company_id=? AND user_id=? AND usage_date=CURDATE() AND attempts<?',[$u['company_id'],$u['id'],$s['daily_limit']]);
            if($quota->rowCount()!==1)throw new OverflowException('Daily AI allowance reached.');
            $result=AiProvider::generate($s,$payload);
            $db->beginTransaction();
            try{
                self::q($db,'INSERT INTO ai_turns(thread_id,request_key,prompt_hash,prompt,reply,model,input_tokens,output_tokens) VALUES(?,?,?,?,?,?,?,?)',[$id,$v['request_key'],$hash,$v['message'],$result['reply'],$s['model'],$result['input_tokens'],$result['output_tokens']]);$turnId=(int)$db->lastInsertId();
                self::q($db,'UPDATE ai_threads SET updated_at=NOW() WHERE id=?',[$id]);
                Audit::log($db,(int)$u['company_id'],(int)$u['id'],'AI_DRAFT_CREATED','ai_thread',$id,null,['turn_id'=>$turnId]);
                $db->commit();return ['id'=>$turnId,'reply'=>$result['reply'],'replayed'=>false];
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }finally{self::q($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public static function handle(string $route,string $method,PDO $db,array $config,array $u): void {
        if(!str_starts_with($route,'ai/'))return;
        Auth::requirePermission($db,$u,'ai.chat');
        try {
            $s=AiProvider::settings($config);
            if($method==='GET'&&$route==='ai/status')Http::json(['ok'=>true,'ready'=>AiProvider::ready($s),'daily_limit'=>$s['daily_limit'],'provider'=>'OpenAI','version'=>'3.4.0-RC6.1']);
            if($method==='GET'&&$route==='ai/context'){
                $ref=['workspace'=>$_GET['workspace']??null];if(isset($_GET['type'])||isset($_GET['id'])){$id=$_GET['id']??null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))throw new InvalidArgumentException('Invalid context ID.');$ref['type']=$_GET['type']??null;$ref['id']=(int)$id;}
                Http::json(['ok'=>true,'context'=>self::resolveContext($db,$u,$ref)]);
            }
            if($method==='GET'&&$route==='ai/campaigns'){
                $items=Auth::can($db,(int)$u['id'],'lead.view')?self::q($db,'SELECT c.id,c.name,b.market,b.offer,b.audience FROM campaigns c LEFT JOIN marketing_briefs b ON b.company_id=c.company_id AND b.campaign_id=c.id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 100',[$u['company_id']])->fetchAll():[];
                Http::json(['ok'=>true,'items'=>$items]);
            }
            if($method==='GET'&&$route==='ai/threads')Http::json(['ok'=>true,'items'=>self::q($db,'SELECT id,title,profile,updated_at FROM ai_threads WHERE company_id=? AND user_id=? ORDER BY updated_at DESC,id DESC LIMIT 100',[$u['company_id'],$u['id']])->fetchAll()]);
            if($method==='GET'&&preg_match('#^ai/threads/(\d+)$#',$route,$m)){$t=self::thread($db,$u,(int)$m[1]);Http::json(['ok'=>true,'thread'=>['id'=>$t['id'],'title'=>$t['title'],'profile'=>$t['profile'],'context'=>json_decode($t['context_json'],true)],'items'=>self::turns($db,(int)$t['id'])]);}
            if($method==='POST'&&$route==='ai/threads'){
                Http::json(['ok'=>true]+self::createThread($db,$u,Http::body()),201);
            }
            if($method==='POST'&&preg_match('#^ai/threads/(\d+)/messages$#',$route,$m))Http::json(['ok'=>true]+self::send($db,$u,$config,(int)$m[1],Http::body()));
            Http::json(['ok'=>false,'message'=>'Unknown AI route'],404);
        }catch(Throwable $e){
            $status=$e instanceof OutOfBoundsException?404:($e instanceof OverflowException?429:($e instanceof DomainException?409:($e instanceof InvalidArgumentException||$e instanceof LengthException?422:503)));
            $message=$e instanceof PDOException?'AI storage is not ready. Ask the administrator to run the RC3 migration.':$e->getMessage();
            Http::json(['ok'=>false,'message'=>$message],$status);
        }
    }
}

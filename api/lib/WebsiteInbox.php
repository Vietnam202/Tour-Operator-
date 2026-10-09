<?php
declare(strict_types=1);

/**
 * Inbound-first VTA website unified inbox.
 * Signed backends only; NO Meta/WhatsApp adapter and NO customer-facing reply sender.
 */
final class WebsiteInbox {
    private const MAX_BYTES=65536;

    private static function val(array $v,string $key,int $max,bool $required=false):string {
        $x=$v[$key]??'';
        if(!is_string($x)||strlen($x)>$max||($required&&trim($x)===''))
            throw new InvalidArgumentException('Invalid '.$key);
        return trim($x);
    }
    private static function id(array $v,string $key,int $min=8,int $max=128):string {
        $x=$v[$key]??null;
        if(!is_string($x)||strlen($x)<$min||strlen($x)>$max||!preg_match('/^[A-Za-z0-9_-]+$/D',$x))
            throw new InvalidArgumentException('Invalid '.$key);
        return $x;
    }
    public static function parseMessage(string $raw):array {
        if($raw===''||strlen($raw)>self::MAX_BYTES)throw new InvalidArgumentException('Invalid payload size');
        $v=json_decode($raw,true);
        if(!is_array($v)||array_is_list($v)||($v['event_type']??'')!=='conversation.message')
            throw new InvalidArgumentException('Unsupported event');
        $event=self::id($v,'event_id',16,80);
        $c=$v['conversation']??null;$m=$v['message']??null;
        if(!is_array($c)||array_is_list($c)||!is_array($m)||array_is_list($m))
            throw new InvalidArgumentException('Invalid conversation or message');
        $external=self::id($c,'external_id');
        $msgId=self::id($m,'message_id');
        $text=self::val($m,'text',8000,true);
        $name=self::val($c,'contact_name',190);
        $email=self::val($c,'email',190);
        $phone=self::val($c,'phone',64);
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))
            throw new InvalidArgumentException('Invalid email');
        $attr=[];
        if(isset($v['attribution'])) {
            if(!is_array($v['attribution'])||array_is_list($v['attribution']))
                throw new InvalidArgumentException('Invalid attribution');
            foreach(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_id','adset_id','referrer','landing_url'] as $key)
                $attr[$key]=self::val($v['attribution'],$key,1000);
        }
        return ['event_id'=>$event,'external_id'=>$external,'message_id'=>$msgId,'text'=>$text,
            'contact_name'=>$name,'email'=>$email,'phone'=>$phone,'attribution'=>$attr];
    }
    private static function q(PDO $db,string $sql,array $values=[]):PDOStatement {
        $st=$db->prepare($sql);$st->execute($values);return $st;
    }
    private static function replay(PDO $db,int $company,string $source,string $key,string $hash):array {
        $old=self::q($db,'SELECT payload_hash,conversation_id FROM social_webhook_events WHERE company_id=? AND source_code=? AND event_key=?',[$company,$source,$key])->fetch();
        if(!$old)throw new RuntimeException('Concurrent event is not committed');
        if(!hash_equals((string)$old['payload_hash'],$hash))throw new DomainException('Event ID reused for different content');
        return ['accepted'=>true,'replayed'=>true,'conversation_id'=>(int)$old['conversation_id']];
    }
    private static function throttle(PDO $db,int $company,string $source,string $thread):void {
        $window=date('Y-m-d H:i:00');
        foreach([['SOURCE',hash('sha256',$source),300],['THREAD',hash('sha256',$thread),30]] as $limit) {
            self::q($db,'INSERT INTO social_webhook_rate_windows(company_id,source_code,scope_type,scope_key,window_start,accepted_count) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE accepted_count=accepted_count+1',[
                $company,$source,$limit[0],$limit[1],$window]);
            $count=(int)self::q($db,'SELECT accepted_count FROM social_webhook_rate_windows WHERE company_id=? AND source_code=? AND scope_type=? AND scope_key=? AND window_start=?',[
                $company,$source,$limit[0],$limit[1],$window])->fetchColumn();
            if($count>$limit[2])throw new OverflowException('Website message rate limit');
        }
    }
    public static function ingest(PDO $db,int $company,int $campaign,string $source,array $event,string $hash):array {
        $key=WebhookCenter::eventKey($source,$event['event_id']);
        $db->beginTransaction();
        try {
            self::q($db,'INSERT INTO social_webhook_events(company_id,source_code,event_key,payload_hash) VALUES(?,?,?,?)',[$company,$source,$key,$hash]);
            self::throttle($db,$company,$source,$event['external_id']);
            self::q($db,"INSERT IGNORE INTO social_conversations(company_id,campaign_id,source_code,external_conversation_id,contact_name,email,phone,attribution_json,reply_draft) VALUES(?,?,?,?,?,?,?,?,'')",[
                $company,$campaign,$source,$event['external_id'],$event['contact_name']?:'Website visitor',
                $event['email'],$event['phone'],$event['attribution']?json_encode($event['attribution'],JSON_THROW_ON_ERROR):null]);
            $conv=self::q($db,'SELECT id,contact_name,email,phone FROM social_conversations WHERE company_id=? AND source_code=? AND external_conversation_id=? FOR UPDATE',[$company,$source,$event['external_id']])->fetch();
            if(!$conv)throw new RuntimeException('Conversation missing');
            $cid=(int)$conv['id'];
            $msg=self::q($db,"INSERT IGNORE INTO social_messages(company_id,conversation_id,external_message_id,body) VALUES(?,?,?,?)",[
                $company,$cid,$event['message_id'],$event['text']]);
            $inserted=$msg->rowCount()>0;
            if(!$inserted){
                $old=self::q($db,'SELECT body FROM social_messages WHERE company_id=? AND conversation_id=? AND external_message_id=?',[$company,$cid,$event['message_id']])->fetchColumn();
                if($old===false||!hash_equals((string)$old,$event['text']))
                    throw new DomainException('Message ID reused for different content');
            } else {
                self::q($db,"UPDATE social_conversations SET contact_name=?,email=?,phone=?,last_message_at=NOW(),updated_at=NOW(),version_no=version_no+1,status=IF(status='CLOSED','NEW',status) WHERE company_id=? AND id=?",[
                    $event['contact_name']?:$conv['contact_name'],$event['email']?:$conv['email'],$event['phone']?:$conv['phone'],$company,$cid]);
            }
            self::q($db,'UPDATE social_webhook_events SET conversation_id=? WHERE company_id=? AND source_code=? AND event_key=?',[$cid,$company,$source,$key]);
            $db->commit();
            return ['accepted'=>true,'replayed'=>!$inserted,'conversation_id'=>$cid];
        } catch(PDOException $e) {
            if($db->inTransaction())$db->rollBack();
            if((string)$e->getCode()==='23000')return self::replay($db,$company,$source,$key,$hash);
            throw $e;
        } catch(Throwable $e) {
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $config):void {
        if($route!=='webhooks/website-message')return;
        if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        $source=(string)($_SERVER['HTTP_X_VTA_WEBHOOK_SOURCE']??'');
        $cfg=$config['integrations']['website_webhooks']??[];
        $spec=(is_array($cfg)&&preg_match('/^[a-z0-9_-]{3,64}$/D',$source))?($cfg[$source]??null):null;
        if(!is_array($spec))Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
        $secret=$spec['secret']??null;$token=$spec['form_token']??null;
        if(!is_string($secret)||strlen($secret)<32||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))
            Http::json(['ok'=>false,'error'=>'SOURCE_NOT_READY'],503);
        $raw=file_get_contents('php://input');
        if(!is_string($raw)||strlen($raw)>self::MAX_BYTES)
            Http::json(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
        if(!WebhookCenter::validSignature($raw,$secret,(string)($_SERVER['HTTP_X_VTA_SIGNATURE_256']??'')))
            Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
        try {
            $event=self::parseMessage($raw);
            $form=self::q($db,"SELECT f.company_id,f.campaign_id FROM lead_forms f JOIN campaigns c ON c.company_id=f.company_id AND c.id=f.campaign_id WHERE f.public_token=? AND f.status='ACTIVE' AND c.status='ACTIVE'",[$token])->fetch();
            if(!$form)Http::json(['ok'=>false,'error'=>'SOURCE_NOT_READY'],503);
            $result=self::ingest($db,(int)$form['company_id'],(int)$form['campaign_id'],$source,$event,hash('sha256',$raw));
            Http::json(['ok'=>true]+$result,202);
        }catch(OverflowException $e){header('Retry-After: 60');Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429);}
         catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);}
         catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT'],409);}
         catch(Throwable $e){error_log('VTA website inbox: '.get_class($e));Http::json(['ok'=>false,'error'=>'PROCESSING_FAILED'],503);}
    }
    public static function handoff(PDO $db,array $user,array $body):array {
        $company=(int)$user['company_id'];$id=filter_var($body['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('Invalid conversation ID');
        $db->beginTransaction();
        try {
            $conv=self::q($db,'SELECT * FROM social_conversations WHERE company_id=? AND id=? FOR UPDATE',[$company,$id])->fetch();
            if(!$conv)throw new OutOfBoundsException('Conversation not found');
            if($conv['lead_request_id']){
                $db->commit();
                return ['request_id'=>(int)$conv['lead_request_id'],'replayed'=>true];
            }
            if(!self::q($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE'",[$company,$conv['campaign_id']])->fetchColumn())
                throw new DomainException('Source campaign is archived');
            $last=self::q($db,'SELECT body FROM social_messages WHERE company_id=? AND conversation_id=? ORDER BY id DESC LIMIT 1',[$company,$id])->fetchColumn();
            $normalized=LeadHub::validateRequest(array_merge($body,[
                'contact_name'=>$body['contact_name']??$conv['contact_name'],
                'email'=>$body['email']??$conv['email'],
                'phone'=>$body['phone']??$conv['phone'],
                'message'=>$last?:'Website chat request',
            ],json_decode($conv['attribution_json']??'{}',true)?:[]));
            $social=str_starts_with((string)$conv['source_code'],'meta_fb_')||str_starts_with((string)$conv['source_code'],'meta_ig_');
            $source=$social?'SOCIAL_DM':'WEB_CHAT';
            $attrib=$normalized['attribution']+['source_type'=>$social?'VERIFIED_META_DM':'SIGNED_WEBSITE_CHAT','source_code'=>$conv['source_code'],'conversation_id'=>(int)$id];
            $key='website-chat-'.$id;
            $hash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
            self::q($db,'INSERT INTO lead_requests(company_id,campaign_id,source,submission_key,payload_hash,contact_name,email,phone,travel_date,total_guests,paying_pax,foc,destination,hotel_level,message,attribution_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                $company,$conv['campaign_id'],$source,$key,$hash,
                $normalized['contact_name'],$normalized['email']?:null,$normalized['phone']?:null,
                $normalized['travel_date']?:null,$normalized['total_guests'],$normalized['paying_pax'],$normalized['foc'],
                $normalized['destination'],$normalized['hotel_level'],$normalized['message'],json_encode($attrib,JSON_THROW_ON_ERROR)]);
            $leadId=(int)$db->lastInsertId();
            self::q($db,"UPDATE social_conversations SET lead_request_id=?,status='FOLLOW_UP',version_no=version_no+1,updated_at=NOW() WHERE company_id=? AND id=?",[$leadId,$company,$id]);
            Audit::log($db,$company,(int)$user['id'],'WEB_CHAT_LEAD_CREATED','lead_request',$leadId,null,['conversation_id'=>$id]);
            $db->commit();
            return ['request_id'=>$leadId,'replayed'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $user):void {
        if(!str_starts_with($route,'marketing/social-inbox'))return;
        $company=(int)$user['company_id'];
        if($method==='GET')Auth::requirePermission($db,$user,'lead.view');
        else Auth::requirePermission($db,$user,'lead.manage');
        try {
            if($route==='marketing/social-inbox'&&$method==='GET') {
                $rows=self::q($db,"SELECT c.id,c.source_code,c.contact_name,c.email,c.phone,c.status,c.reply_draft,c.owner_user_id,c.lead_request_id,c.last_message_at,c.version_no,
                    (SELECT m.body FROM social_messages m WHERE m.company_id=c.company_id AND m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_message
                    FROM social_conversations c WHERE c.company_id=? ORDER BY c.last_message_at DESC,c.id DESC LIMIT 100",[$company])->fetchAll();
                Http::json(['ok'=>true,'items'=>$rows,'channel'=>'WEB_CHAT','outbound_enabled'=>false]);
            }
            if($method==='GET'&&preg_match('#^marketing/social-inbox/([1-9][0-9]*)$#D',$route,$m)){
                $id=(int)$m[1];
                $conv=self::q($db,'SELECT id,source_code,contact_name,email,phone,status,reply_draft,owner_user_id,lead_request_id,version_no,last_message_at FROM social_conversations WHERE company_id=? AND id=?',[$company,$id])->fetch();
                if(!$conv)throw new OutOfBoundsException('Conversation not found');
                $messages=self::q($db,'SELECT id,direction,body,received_at FROM social_messages WHERE company_id=? AND conversation_id=? ORDER BY id DESC LIMIT 100',[$company,$id])->fetchAll();
                Http::json(['ok'=>true,'conversation'=>$conv,'messages'=>array_reverse($messages),'outbound_enabled'=>false]);
            }
            if($method==='POST'&&$route==='marketing/social-inbox/update'){
                $body=Http::body();
                $id=filter_var($body['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $version=filter_var($body['version_no']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $status=self::val($body,'status',24,true);
                $reply=self::val($body,'reply_draft',8000);
                if(!$id||!$version||!in_array($status,['NEW','FOLLOW_UP','CLOSED'],true))throw new InvalidArgumentException('Invalid update');
                $db->beginTransaction();
                try {
                    $conv=self::q($db,'SELECT version_no FROM social_conversations WHERE company_id=? AND id=? FOR UPDATE',[$company,$id])->fetch();
                    if(!$conv)throw new OutOfBoundsException('Conversation not found');
                    if((int)$conv['version_no']!==$version)throw new DomainException('Conversation changed; refresh');
                    self::q($db,'UPDATE social_conversations SET status=?,reply_draft=?,version_no=version_no+1,updated_at=NOW() WHERE company_id=? AND id=?',[$status,$reply,$company,$id]);
                    Audit::log($db,$company,(int)$user['id'],'WEB_CHAT_NOTE_SAVED','social_conversation',$id,null,['status'=>$status]);
                    $db->commit();
                    Http::json(['ok'=>true,'id'=>$id]);
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            }
            if($method==='POST'&&$route==='marketing/social-inbox/handoff')
                Http::json(['ok'=>true]+self::handoff($db,$user,Http::body()));
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422);}
         catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
         catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

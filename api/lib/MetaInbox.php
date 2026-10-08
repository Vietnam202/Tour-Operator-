<?php
declare(strict_types=1);

/**
 * P7: Verified Meta Messenger + Instagram DM text-event ingestion.
 * No OAuth, outbound Graph calls, automated replies or social publishing changes.
 */
final class MetaInbox {
    private const MAX_BYTES=262144;
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement{
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    public static function settings(array $config):array{
        $raw=$config['integrations']['meta_inbox']??[];
        return is_array($raw)?$raw:[];
    }
    public static function challenge(array $query,string $verifyToken):?string{
        if(strlen($verifyToken)<32||!is_string($query['hub.verify_token']??null)
           ||($query['hub.mode']??'')!=='subscribe'
           ||!hash_equals($verifyToken,$query['hub.verify_token']))return null;
        $v=$query['hub.challenge']??null;
        return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,256}$/D',$v)?$v:null;
    }
    public static function verify(string $raw,string $secret,string $header):bool{
        return strlen($secret)>=32
            &&preg_match('/^sha256=[0-9a-f]{64}$/D',$header)===1
            &&hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),$header);
    }
    public static function accounts(array $config):array{
        $cfg=self::settings($config);
        if(($cfg['enabled']??false)!==true)return [];
        $all=$cfg['accounts']??[];
        if(!is_array($all))return [];
        $result=[];
        foreach($all as $alias=>$v) {
            if(!is_string($alias)||!preg_match('/^[a-z0-9_-]{3,44}$/D',$alias)||!is_array($v))continue;
            $obj=$v['object']??'';
            if(!in_array($obj,['page','instagram'],true))continue;
            $entity=$v['entity_id']??null;
            if(!is_string($entity)||!preg_match('/^[0-9]{8,40}$/D',$entity)
                ||!is_int($v['company_id']??null)||$v['company_id']<1
                ||!is_int($v['campaign_id']??null)||$v['campaign_id']<1
                ||($v['enabled']??false)!==true||($v['app_review_confirmed']??false)!==true)continue;
            $key=$obj.':'.$entity;
            // Fail closed if two tenant accounts try to claim one Meta entity.
            if(isset($result[$key]))throw new DomainException('Meta entity mapped to multiple accounts');
            $result[$key]=[
                'company_id'=>$v['company_id'],'campaign_id'=>$v['campaign_id'],
                'source_code'=>'meta_'.($obj==='page'?'fb_':'ig_').$alias,
                'alias'=>$alias,'object'=>$obj,'entity_id'=>$entity,
                'platform'=>$obj==='page'?'FACEBOOK_MESSENGER':'INSTAGRAM_DM'
            ];
        }
        return $result;
    }
    public static function configuredAccounts(array $config,int $company):array {
        $list=[];
        foreach(self::accounts($config) as $v)if($v['company_id']===$company)
            $list[]=['alias'=>$v['alias'],'platform'=>$v['platform'],
                'source_code'=>$v['source_code'],'status'=>'CONFIGURED_NOT_LIVE_VERIFIED'];
        return $list;
    }
    public static function extract(string $raw,array $accounts):array{
        if($raw===''||strlen($raw)>self::MAX_BYTES)throw new InvalidArgumentException('Invalid request length');
        // Decode once as native objects to distinguish JSON [] from JSON {}.
        // Associative json_decode cannot distinguish an empty object from an empty list.
        $native=json_decode($raw,false,256);
        if(!($native instanceof stdClass))throw new InvalidArgumentException('Invalid JSON object');
        if(isset($native->entry)&&!is_array($native->entry))
            throw new InvalidArgumentException('Webhook entry must be a JSON array');
        foreach(($native->entry??[]) as $entryObj){
            if(!($entryObj instanceof stdClass))continue;
            if(isset($entryObj->messaging)&&!is_array($entryObj->messaging))
                throw new InvalidArgumentException('Messaging must be a JSON array');
        }
        $data=json_decode($raw,true,256);
        if(!is_array($data))throw new InvalidArgumentException('Invalid JSON object');
        $object=$data['object']??null;
        if(!in_array($object,['page','instagram'],true))return [];
        $entries=$data['entry']??null;
        if(!is_array($entries)||!array_is_list($entries)||count($entries)>200)
            throw new InvalidArgumentException('Invalid webhook entries');
        $out=[];
        foreach($entries as $entry){
            if(!is_array($entry)||array_is_list($entry))continue;
            $entity=$entry['id']??null;
            if(!is_string($entity)||!preg_match('/^[0-9]{8,40}$/D',$entity))continue;
            $a=$accounts[$object.':'.$entity]??null;
            if(!$a)continue;
            $messages=$entry['messaging']??[];
            if(!is_array($messages)||!array_is_list($messages)||count($messages)>200)
                throw new InvalidArgumentException('Invalid messaging batch');
            foreach($messages as $m){
                if(!is_array($m)||array_is_list($m))continue;
                $sender=$m['sender']['id']??null;
                $recipient=$m['recipient']['id']??null;
                $message=$m['message']??null;
                if(!is_array($message)||($message['is_echo']??false)===true)continue;
                if(!is_string($sender)||!preg_match('/^[0-9]{8,40}$/D',$sender)||$sender===$entity
                   ||!is_string($recipient)||!hash_equals($entity,$recipient))continue;
                $mid=$message['mid']??null;$text=$message['text']??null;
                if(!is_string($mid)||strlen($mid)>128||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$mid)
                   ||!is_string($text)||trim($text)===''||strlen($text)>8000
                   ||!preg_match('//u',$text))continue;
                $event=hash('sha256',$object.':'.$entity.':'.$mid);
                $msg='meta_'.hash('sha256',$mid);
                $source=$a['source_code'];
                $out[]=[
                    'company_id'=>$a['company_id'],'campaign_id'=>$a['campaign_id'],
                    'source_code'=>$source,'platform'=>$a['platform'],
                    'event_key'=>$event,'sender_id'=>$sender,'message_id'=>$msg,
                    'message_text'=>trim($text),
                    'payload_hash'=>hash('sha256',json_encode([$sender,$recipient,$mid,trim($text)],JSON_THROW_ON_ERROR))
                ];
                if(count($out)>500)throw new InvalidArgumentException('Too many text messages');
            }
        }
        return $out;
    }
    public static function enqueue(PDO $db,array $events):array {
        $db->beginTransaction();
        $inserted=0;$replayed=0;
        try{
            $check=self::q($db,"SELECT 1");
            foreach($events as $v){
                $active=self::q($db,"SELECT id FROM campaigns WHERE company_id=? AND id=? AND status='ACTIVE'",[
                    $v['company_id'],$v['campaign_id']])->fetchColumn();
                if(!$active)throw new DomainException('Mapped Meta campaign is inactive');
                $result=self::q($db,"INSERT IGNORE INTO social_meta_inbound_jobs(
                    company_id,campaign_id,source_code,platform,event_key,payload_hash,sender_id,message_id,message_text
                    ) VALUES(?,?,?,?,?,?,?,?,?)",[
                    $v['company_id'],$v['campaign_id'],$v['source_code'],$v['platform'],
                    $v['event_key'],$v['payload_hash'],$v['sender_id'],$v['message_id'],$v['message_text']]);
                if($result->rowCount()>0){$inserted++;continue;}
                $old=self::q($db,"SELECT payload_hash FROM social_meta_inbound_jobs WHERE company_id=? AND source_code=? AND event_key=?",[
                    $v['company_id'],$v['source_code'],$v['event_key']])->fetchColumn();
                if($old===false||!hash_equals((string)$old,$v['payload_hash']))
                    throw new DomainException('Meta event ID collision');
                $replayed++;
            }
            $db->commit();return ['queued'=>$inserted,'replayed'=>$replayed];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function processOne(PDO $db):?array{
        $db->beginTransaction();
        try{
            $job=self::q($db,"SELECT * FROM social_meta_inbound_jobs WHERE status='PENDING' ORDER BY id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if(!$job){$db->commit();return null;}
            self::q($db,"UPDATE social_meta_inbound_jobs SET status='PROCESSING',claimed_at=UTC_TIMESTAMP(),attempt_count=attempt_count+1 WHERE id=?",[$job['id']]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $id=(int)$job['id'];
        try{
            $event=[
                'event_id'=>$job['event_key'],'external_id'=>$job['sender_id'],
                'message_id'=>$job['message_id'],'text'=>$job['message_text'],
                'contact_name'=>'', // Meta sender IDs do not provide verified display names; preserve staff-enriched contact names.
                'email'=>'','phone'=>'',
                'attribution'=>['utm_source'=>$job['platform']==='FACEBOOK_MESSENGER'?'facebook_messenger':'instagram_dm',
                    'utm_medium'=>'social_dm']
            ];
            $hash=hash('sha256',json_encode($event,JSON_THROW_ON_ERROR));
            $result=WebsiteInbox::ingest($db,(int)$job['company_id'],(int)$job['campaign_id'],(string)$job['source_code'],$event,$hash);
            self::q($db,"UPDATE social_meta_inbound_jobs SET status='DONE',processed_at=UTC_TIMESTAMP(),conversation_id=?,last_error=NULL WHERE id=? AND status='PROCESSING'",[
                (int)$result['conversation_id'],$id]);
            return ['id'=>$id,'status'=>'DONE','conversation_id'=>(int)$result['conversation_id']];
        }catch(Throwable $e){
            $hard=$e instanceof DomainException||$e instanceof InvalidArgumentException||$e instanceof OverflowException;
            $failed=$hard||(int)$job['attempt_count']>=2;
            self::q($db,"UPDATE social_meta_inbound_jobs SET status=?,last_error=? WHERE id=? AND status='PROCESSING'",[
                $failed?'FAILED':'PENDING',$hard?'VALIDATION_OR_RATE_LIMIT':'TEMPORARY_INGEST_FAILURE',$id]);
            return ['id'=>$id,'status'=>$failed?'FAILED':'PENDING'];
        }
    }
    public static function recover(PDO $db):int{
        return self::q($db,"UPDATE social_meta_inbound_jobs SET status=IF(attempt_count>=2,'FAILED','PENDING'),last_error='ABANDONED_WORKER' WHERE status='PROCESSING' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)")->rowCount();
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $config):void{
        if($route!=='webhooks/meta-messaging')return;
        $cfg=self::settings($config);
        if(($cfg['enabled']??false)!==true)Http::json(['ok'=>false,'error'=>'NOT_CONNECTED'],503);
        if($method==='GET'){
            $token=$cfg['verify_token']??null;
            $result=is_string($token)?self::challenge($_GET,$token):null;
            if($result===null)Http::json(['ok'=>false,'error'=>'VERIFICATION_DENIED'],403);
            header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo $result;exit;
        }
        if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        $secret=$cfg['app_secret']??null;
        $raw=file_get_contents('php://input',false,null,0,self::MAX_BYTES+1);
        if(!is_string($raw)||strlen($raw)>self::MAX_BYTES)Http::json(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
        if(!is_string($secret)||!self::verify($raw,$secret,(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'')))
            Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
        try{
            $events=self::extract($raw,self::accounts($config));
            $result=self::enqueue($db,$events);
            Http::json(['ok'=>true,'acknowledged'=>true,'queued'=>$result['queued']]);
        }catch(InvalidArgumentException|DomainException $e){Http::json(['ok'=>false,'error'=>'INVALID_EVENT'],422);}
        catch(Throwable $e){error_log('VTA Meta ingress failed: '.get_class($e));Http::json(['ok'=>false,'error'=>'TEMPORARY_ERROR'],503);}
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $config,array $user):void{
        if($route!=='marketing/meta-inbox')return;
        if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        Auth::requirePermission($db,$user,'lead.view');
        $company=(int)$user['company_id'];
        $stats=self::q($db,"SELECT platform,status,COUNT(*) total FROM social_meta_inbound_jobs WHERE company_id=? GROUP BY platform,status",[$company])->fetchAll(PDO::FETCH_ASSOC);
        Http::json(['ok'=>true,'accounts'=>self::configuredAccounts($config,$company),
            'queue_stats'=>$stats,'outbound_enabled'=>false,
            'note'=>'Meta inbound text messages only. Page access/OAuth and outbound Messenger/IG replies not connected.']);
    }
}

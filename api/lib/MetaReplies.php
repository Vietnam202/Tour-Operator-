<?php
declare(strict_types=1);

/**
 * P8 manual Facebook Messenger Page text reply queue.
 * Instagram DMs remain draft-only: Facebook Login and Instagram Login
 * have distinct access-token and messaging endpoint requirements.
 */
final class MetaReplies {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement{
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    private static function id(mixed $v):int {
        $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('Invalid record ID');
        return (int)$id;
    }
    public static function source(array $cfg,int $company,string $source):?array {
        foreach(MetaInbox::accounts($cfg) as $mapping) {
            if($mapping['company_id']!==$company||$mapping['source_code']!==$source)continue;
            if($mapping['platform']!=='FACEBOOK_MESSENGER')return null;
            $a=MetaInbox::settings($cfg)['accounts'][$mapping['alias']]??null;
            if(!is_array($a))return null;
            $page=(string)($a['entity_id']??'');
            $scope=$a['permissions']??[];
            $ready=($a['messaging_enabled']??false)===true
                &&($a['messaging_permission_approved']??false)===true
                &&($a['message_task_confirmed']??false)===true
                &&is_array($scope)&&in_array('pages_messaging',$scope,true)
                &&preg_match('/^[0-9]{8,40}$/D',$page)===1
                &&is_string($a['page_access_token']??null)&&strlen($a['page_access_token'])>=30;
            return [
                'alias'=>$mapping['alias'],'source_code'=>$source,
                'page_id'=>$page,'ready'=>$ready,'config'=>$a
            ];
        }
        return null;
    }
    public static function thread(PDO $db,array $cfg,int $company,int $conversation):array {
        $row=self::q($db,'SELECT id,source_code,status,external_conversation_id,last_meta_inbound_at FROM social_conversations WHERE company_id=? AND id=?',[
            $company,$conversation])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new OutOfBoundsException('Conversation not found');
        $account=self::source($cfg,$company,$row['source_code']);
        if(!$account)return ['conversation_id'=>$conversation,'provider'=>'NOT_SUPPORTED','can_send'=>false,'reason'=>'MESSENGER_ONLY'];
        $last=$row['last_meta_inbound_at'];
        // Keep a ten-minute buffer before the 24-hour standard messaging window closes.
        $clockOk=$last!==null&&strtotime($last.' UTC')>time()-(23*3600+50*60)
            &&strtotime($last.' UTC')<=time()+60;
        $open=$row['status']!=='CLOSED';
        $sender=(string)$row['external_conversation_id'];
        $recipientOk=preg_match('/^[0-9]{8,40}$/D',$sender)===1&&$sender!==$account['page_id'];
        return ['conversation_id'=>$conversation,'provider'=>'FACEBOOK_MESSENGER',
            'can_send'=>$account['ready']&&$clockOk&&$open&&$recipientOk,
            'reason'=>!$account['ready']?'ACCOUNT_NOT_AUTHORIZED':(!$clockOk?'OUTSIDE_24H_WINDOW':(!$open?'CLOSED':'INVALID_RECIPIENT')),
            'last_inbound_at'=>$last,
            'source_code'=>$row['source_code']];
    }
    public static function enqueue(PDO $db,array $cfg,array $user,array $input):array {
        $company=(int)$user['company_id'];
        $id=self::id($input['conversation_id']??null);
        $body=$input['body']??null;
        if(!is_string($body)||trim($body)===''||strlen($body)>1000||!preg_match('//u',$body))
            throw new InvalidArgumentException('Invalid Messenger text, maximum 1000 bytes');
        $body=trim($body);
        $key=$input['request_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))
            throw new InvalidArgumentException('Invalid request_key');
        $hash=hash('sha256',json_encode([$id,$body],JSON_THROW_ON_ERROR));
        $db->beginTransaction();
        try{
            $old=self::q($db,'SELECT id,payload_hash,status FROM marketing_meta_outbound WHERE company_id=? AND request_key=? FOR UPDATE',[$company,$key])->fetch(PDO::FETCH_ASSOC);
            if($old) {
                if(!hash_equals((string)$old['payload_hash'],$hash))throw new DomainException('Request ID reused for different message');
                $db->commit();return ['id'=>(int)$old['id'],'status'=>$old['status'],'replayed'=>true];
            }
            $check=self::thread($db,$cfg,$company,$id);
            if(!$check['can_send'])throw new DomainException('Messenger unavailable: '.$check['reason']);
            self::q($db,"INSERT INTO marketing_meta_outbound(company_id,conversation_id,source_code,request_key,payload_hash,message_text,created_by) VALUES(?,?,?,?,?,?,?)",[
                $company,$id,$check['source_code'],$key,$hash,$body,(int)$user['id']]);
            $outId=(int)$db->lastInsertId();
            Audit::log($db,$company,(int)$user['id'],'META_REPLY_QUEUED','marketing_meta_outbound',$outId,null,['conversation_id'=>$id]);
            $db->commit();
            return ['id'=>$outId,'status'=>'QUEUED','replayed'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function claim(PDO $db,array $cfg):?array {
        $db->beginTransaction();
        try{
            $row=self::q($db,"SELECT * FROM marketing_meta_outbound WHERE status='QUEUED' ORDER BY id ASC LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if(!$row){$db->commit();return null;}
            $t=self::thread($db,$cfg,(int)$row['company_id'],(int)$row['conversation_id']);
            if(!$t['can_send']||$t['source_code']!==$row['source_code']){
                self::q($db,"UPDATE marketing_meta_outbound SET status='BLOCKED',status_detail=? WHERE id=?",[
                    substr((string)$t['reason'],0,160),$row['id']]);
                $db->commit();return ['id'=>(int)$row['id'],'status'=>'BLOCKED'];
            }
            $account=self::source($cfg,(int)$row['company_id'],$row['source_code']);
            $conv=self::q($db,"SELECT external_conversation_id FROM social_conversations WHERE company_id=? AND id=?",[
                $row['company_id'],$row['conversation_id']])->fetch(PDO::FETCH_ASSOC);
            self::q($db,"UPDATE marketing_meta_outbound SET status='SENDING',claimed_at=UTC_TIMESTAMP() WHERE id=?",[$row['id']]);
            $db->commit();
            return ['id'=>(int)$row['id'],'status'=>'SENDING','page_id'=>$account['page_id'],
                'recipient_id'=>$conv['external_conversation_id'],'body'=>$row['message_text'],'account'=>$account['config']];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function complete(PDO $db,int $id,?string $messageId):void {
        if($messageId!==null&&!preg_match('/^[A-Za-z0-9._:-]{4,256}$/D',$messageId))
            throw new InvalidArgumentException('Invalid Meta message ID');
        self::q($db,"UPDATE marketing_meta_outbound SET status=?,provider_message_id=?,sent_at=IF(?='SENT',UTC_TIMESTAMP(),NULL),status_detail=IF(?='UNCERTAIN','Provider response unconfirmed; verify on Meta before resending',NULL)
            WHERE id=? AND status='SENDING'",[
                $messageId!==null?'SENT':'UNCERTAIN',$messageId,
                $messageId!==null?'SENT':'UNCERTAIN',$messageId!==null?'SENT':'UNCERTAIN',$id]);
    }
    public static function recover(PDO $db):int{
        return self::q($db,"UPDATE marketing_meta_outbound SET status='UNCERTAIN',status_detail='Worker abandoned; reconcile with Meta' WHERE status='SENDING' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)")->rowCount();
    }
    public static function list(PDO $db,array $user,int $conversation):array{
        $cid=(int)$user['company_id'];
        if(!self::q($db,'SELECT id FROM social_conversations WHERE company_id=? AND id=?',[$cid,$conversation])->fetchColumn())
            throw new OutOfBoundsException('Conversation not found');
        return self::q($db,'SELECT id,message_text,status,created_at,sent_at,status_detail FROM marketing_meta_outbound WHERE company_id=? AND conversation_id=? ORDER BY id DESC LIMIT 100',[
            $cid,$conversation])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $cfg,array $user):void{
        if(!str_starts_with($route,'marketing/meta-replies/'))return;
        Auth::requirePermission($db,$user,$method==='GET'?'lead.view':'lead.manage');
        try{
            if($route==='marketing/meta-replies/status'&&$method==='GET'){
                Http::json(['ok'=>true]+self::thread($db,$cfg,(int)$user['company_id'],self::id($_GET['conversation_id']??null)));
            }
            if($route==='marketing/meta-replies/list'&&$method==='GET'){
                $id=self::id($_GET['conversation_id']??null);
                Http::json(['ok'=>true,'items'=>self::list($db,$user,$id)]);
            }
            if($route==='marketing/meta-replies/send'&&$method==='POST'){
                Http::json(['ok'=>true]+self::enqueue($db,$cfg,$user,Http::body()),201);
            }
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

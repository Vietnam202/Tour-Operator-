<?php
declare(strict_types=1);

/**
 * VTA-first party chat delivery P2.
 * The browser NEVER calls these relay routes directly with provider secrets.
 * RELAYED means collected by a trusted website backend, not read by a visitor.
 */
final class WebsiteChatDelivery {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement {
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    private static function positive($v,string $field):int {
        $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false||$id===null)throw new InvalidArgumentException('Invalid '.$field);
        return (int)$id;
    }
    private static function text(array $data,string $name,int $max,bool $required=false):string {
        $s=$data[$name]??'';
        if(!is_string($s)||strlen($s)>$max||($required&&trim($s)===''))
            throw new InvalidArgumentException('Invalid '.$name);
        return trim($s);
    }
    public static function send(PDO $db,array $user,array $input):array {
        $company=(int)$user['company_id'];
        $id=self::positive($input['conversation_id']??null,'conversation_id');
        $key=self::text($input,'request_key',80,true);
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))
            throw new InvalidArgumentException('Invalid request_key');
        $body=self::text($input,'body',8000,true);
        $hash=hash('sha256',json_encode([$id,$body],JSON_THROW_ON_ERROR));
        $db->beginTransaction();
        try {
            $previous=self::q($db,'SELECT id,conversation_id,payload_hash,status FROM website_chat_outbound WHERE company_id=? AND request_key=? FOR UPDATE',[$company,$key])->fetch();
            if($previous) {
                if(!hash_equals((string)$previous['payload_hash'],$hash))
                    throw new DomainException('Request key was used for other content');
                $db->commit();
                return ['id'=>(int)$previous['id'],'status'=>$previous['status'],'replayed'=>true];
            }
            $conv=self::q($db,"SELECT id,status FROM social_conversations WHERE company_id=? AND id=? FOR UPDATE",[$company,$id])->fetch();
            if(!$conv)throw new OutOfBoundsException('Conversation not found');
            if($conv['status']==='CLOSED')throw new DomainException('Closed conversation cannot receive replies');
            if(array_key_exists('tour_advisor_draft_id',$input)&&$input['tour_advisor_draft_id']!==null) {
                $draftId=self::positive($input['tour_advisor_draft_id'],'tour_advisor_draft_id');
                MarketingTourAdvisor::validateDraftForSend($db,$company,$id,$draftId,$body);
            }
            self::q($db,"INSERT INTO website_chat_outbound(company_id,conversation_id,request_key,payload_hash,body,created_by) VALUES(?,?,?,?,?,?)",[
                $company,$id,$key,$hash,$body,(int)$user['id']]);
            $outId=(int)$db->lastInsertId();
            Audit::log($db,$company,(int)$user['id'],'WEBSITE_CHAT_REPLY_QUEUED','website_chat_outbound',$outId,null,['conversation_id'=>$id]);
            $db->commit();
            return ['id'=>$outId,'status'=>'QUEUED','replayed'=>false];
        }catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function validateRelayEvent(string $raw,int $now):array {
        if($raw===''||strlen($raw)>32768)throw new InvalidArgumentException('Invalid request size');
        $data=json_decode($raw,true);
        if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('Invalid request JSON');
        $action=self::text($data,'action',16,true);
        if(!in_array($action,['pull','ack'],true))throw new InvalidArgumentException('Invalid action');
        $time=$data['timestamp']??null;
        if(!is_int($time)||abs($now-$time)>300)throw new DomainException('Expired relay request');
        $external=self::text($data,'external_conversation_id',128,true);
        if(strlen($external)<8||!preg_match('/^[A-Za-z0-9_-]+$/D',$external))
            throw new InvalidArgumentException('Invalid external conversation ID');
        $last=0;$ids=[];
        if($action==='pull') {
            $value=$data['after_id']??0;
            if(!is_int($value)||$value<0)throw new InvalidArgumentException('Invalid cursor');
            $last=$value;
        }else{
            $ids=$data['message_ids']??null;
            if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>50)
                throw new InvalidArgumentException('Invalid acknowledgement');
            foreach($ids as $id) {
                if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid outbound message ID');
            }
            $ids=array_values(array_unique($ids));
        }
        return ['action'=>$action,'external_conversation_id'=>$external,'after_id'=>$last,'message_ids'=>$ids];
    }
    private static function sourceForm(PDO $db,array $config,string $source):array {
        $sites=$config['integrations']['website_webhooks']??[];
        $site=is_array($sites)?($sites[$source]??null):null;
        if(!is_array($site)||!is_string($site['form_token']??null)||strlen($site['form_token'])!==64
            ||!is_string($site['secret']??null)||strlen($site['secret'])<32)
            throw new OutOfBoundsException('Unconfigured source');
        $token=$site['form_token'];
        $form=self::q($db,"SELECT f.company_id,f.campaign_id FROM lead_forms f JOIN campaigns c ON c.id=f.campaign_id AND c.company_id=f.company_id WHERE f.public_token=? AND f.status='ACTIVE' AND c.status='ACTIVE' LIMIT 1",[$token])->fetch();
        if(!$form)throw new OutOfBoundsException('Inactive website form');
        return ['company_id'=>(int)$form['company_id'],'secret'=>$site['secret']];
    }
    public static function relay(PDO $db,int $company,string $source,array $req):array {
        $conv=self::q($db,'SELECT id FROM social_conversations WHERE company_id=? AND source_code=? AND external_conversation_id=? LIMIT 1',[
            $company,$source,$req['external_conversation_id']])->fetch();
        if(!$conv)return ['ok'=>true,'messages'=>[],'last_id'=>$req['after_id']];
        $id=(int)$conv['id'];
        if($req['action']==='pull'){
            $rows=self::q($db,'SELECT id,body,created_at FROM website_chat_outbound WHERE company_id=? AND conversation_id=? AND id>? ORDER BY id ASC LIMIT 50',[
                $company,$id,$req['after_id']])->fetchAll(PDO::FETCH_ASSOC);
            $msgs=array_map(fn($m)=>['id'=>(int)$m['id'],'text'=>$m['body'],'created_at'=>$m['created_at']],$rows);
            return ['ok'=>true,'messages'=>$msgs,'last_id'=>$msgs?(int)end($msgs)['id']:$req['after_id']];
        }
        if($req['action']==='ack'){
            $n=0;$db->beginTransaction();
            try{
                foreach($req['message_ids'] as $outId){
                    $st=self::q($db,"UPDATE website_chat_outbound SET status='RELAYED',relayed_at=COALESCE(relayed_at,NOW()) WHERE company_id=? AND conversation_id=? AND id=? AND status='QUEUED'",[
                        $company,$id,$outId]);
                    $n+=$st->rowCount();
                }
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            return ['ok'=>true,'acknowledged'=>$n,'relay_status'=>'RELAYED_NOT_READ'];
        }
        throw new InvalidArgumentException('Invalid action');
    }
    public static function publicHandle(string $route,string $method,PDO $db,array $config):void {
        if($route!=='webhooks/website-chat-relay')return;
        if($method!=='POST')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        $source=(string)($_SERVER['HTTP_X_VTA_WEBHOOK_SOURCE']??'');
        if(!preg_match('/^[a-z0-9_-]{3,64}$/D',$source))
            Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
        try{$auth=self::sourceForm($db,$config,$source);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);}
        $raw=file_get_contents('php://input');
        if(!is_string($raw)||strlen($raw)>32768)
            Http::json(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
        if(!WebhookCenter::validSignature($raw,$auth['secret'],(string)($_SERVER['HTTP_X_VTA_SIGNATURE_256']??'')))
            Http::json(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
        try{
            $request=self::validateRelayEvent($raw,time());
            Http::json(self::relay($db,$auth['company_id'],$source,$request));
        }catch(DomainException $e){Http::json(['ok'=>false,'error'=>'STALE_RELAY_REQUEST'],401);}
         catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);}
         catch(Throwable $e){error_log('VTA website relay: '.get_class($e));Http::json(['ok'=>false,'error'=>'RELAY_FAILED'],503);}
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $config,array $user):void {
        if(!str_starts_with($route,'marketing/website-chat/'))return;
        $company=(int)$user['company_id'];
        if($route==='marketing/website-chat/send'&&$method==='POST') {
            Auth::requirePermission($db,$user,'lead.manage');
            try{
                $input=Http::body();
                $conversationId=self::positive($input['conversation_id']??null,'conversation_id');
                $conv=self::q($db,'SELECT source_code FROM social_conversations WHERE company_id=? AND id=?',[$company,$conversationId])->fetch();
                if(!$conv)throw new OutOfBoundsException('Conversation not found');
                $site=self::sourceForm($db,$config,(string)$conv['source_code']);
                if((int)$site['company_id']!==$company)throw new OutOfBoundsException('Source is not ready for this company');
                Http::json(['ok'=>true]+self::send($db,$user,$input),201);
            }
            catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
            catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
            catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
        }
        if($route==='marketing/website-chat/outbound'&&$method==='GET') {
            Auth::requirePermission($db,$user,'lead.view');
            try{$id=self::positive($_GET['conversation_id']??null,'conversation_id');}
            catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
            $exists=self::q($db,'SELECT id FROM social_conversations WHERE company_id=? AND id=?',[$company,$id])->fetchColumn();
            if(!$exists)Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
            $messages=self::q($db,'SELECT id,body,status,created_at,relayed_at FROM website_chat_outbound WHERE company_id=? AND conversation_id=? ORDER BY id DESC LIMIT 100',[$company,$id])->fetchAll(PDO::FETCH_ASSOC);
            Http::json(['ok'=>true,'messages'=>array_reverse($messages),'relay_status_only'=>true]);
        }
        Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
    }
}

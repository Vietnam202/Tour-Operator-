<?php
declare(strict_types=1);

/**
 * P5: First-party publishing jobs. ONLY approved Facebook Page text posts.
 * No OAuth UI / IG/WhatsApp publishing / provider traffic from HTTP handlers.
 * Accounts and secrets are provisioned exclusively in private server config.
 */
final class SocialPublishing {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement{
        $q=$db->prepare($sql);$q->execute($args);return $q;
    }
    private static function id(mixed $v,string $key):int {
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($n===false||$n===null)throw new InvalidArgumentException("Invalid $key");
        return (int)$n;
    }
    public static function key(mixed $v):string{
        if(!is_string($v)||preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$v)!==1)throw new InvalidArgumentException('Invalid request_key');
        return $v;
    }
    public static function account(array $config,int $company,string $alias):?array {
        if(!preg_match('/^[a-z0-9_-]{3,64}$/D',$alias))return null;
        $v=$config['integrations']['social_publishing']['accounts'][$alias]??null;
        if(!is_array($v)||(int)($v['company_id']??0)!==$company)return null;
        $provider=(string)($v['provider']??'');
        if($provider!=='FACEBOOK_PAGE')return null;
        $page=(string)($v['page_id']??'');
        $scope=$v['permissions']??[];
        $ready=($config['integrations']['social_publishing']['enabled']??false)===true
            &&($v['enabled']??false)===true&&preg_match('/^[0-9]{5,30}$/D',$page)===1
            &&is_string($v['access_token']??null)&&strlen($v['access_token'])>=30
            &&is_array($scope)&&in_array('pages_manage_posts',$scope,true)
            &&($v['app_review_confirmed']??false)===true;
        return ['alias'=>$alias,'name'=>substr((string)($v['name']??$alias),0,150),
            'provider'=>$provider,'ready'=>$ready,'page_id'=>$page,'config'=>$v];
    }
    public static function accounts(array $config,int $company):array {
        $raw=$config['integrations']['social_publishing']['accounts']??[];
        if(!is_array($raw))return [];
        $result=[];
        foreach($raw as $alias=>$unused){
            if(!is_string($alias))continue;
            $a=self::account($config,$company,$alias);
            if($a)$result[]=['alias'=>$a['alias'],'name'=>$a['name'],'provider'=>$a['provider'],
                'ready'=>$a['ready'],'page_id'=>$a['page_id']];
        }
        return $result;
    }
    private static function content(PDO $db,int $company,int $id,bool $lock=false):array{
        $q=self::q($db,'SELECT id,company_id,channel,content_format,status,body,asset_url FROM marketing_content WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''),[$company,$id]);
        $v=$q->fetch(PDO::FETCH_ASSOC);if(!$v)throw new OutOfBoundsException('Content not found');
        if($v['status']!=='APPROVED'||$v['channel']!=='Facebook'||$v['content_format']!=='POST'||$v['asset_url']!=='')
            throw new DomainException('Only approved Facebook text posts without media can be scheduled');
        if(!is_string($v['body'])||trim($v['body'])===''||strlen($v['body'])>5000)
            throw new DomainException('Facebook post text must be between 1 and 5000 bytes');
        return $v;
    }
    private static function hash(array $c):string {
        return hash('sha256',json_encode([$c['id'],$c['status'],$c['channel'],$c['content_format'],$c['asset_url'],$c['body']],JSON_THROW_ON_ERROR));
    }
    public static function create(PDO $db,array $config,array $user,array $input):array {
        $company=(int)$user['company_id'];
        $contentId=self::id($input['content_id']??null,'content_id');
        $alias=$input['account_alias']??null;
        if(!is_string($alias)||!self::account($config,$company,$alias))throw new InvalidArgumentException('Unknown account');
        $key=self::key($input['request_key']??null);
        $raw=$input['scheduled_at']??null;
        if(!is_string($raw)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$raw))
            throw new InvalidArgumentException('scheduled_at must be UTC ISO-8601');
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));
        if(!$time||$time->format('Y-m-d\TH:i:s\Z')!==$raw
            ||$time->getTimestamp()<time()-90||$time->getTimestamp()>time()+90*86400)
            throw new InvalidArgumentException('Publish time must be now to 90 days from now');
        $schedule=$time->format('Y-m-d H:i:s');
        $inputHash=hash('sha256',json_encode([$contentId,$alias,$schedule],JSON_THROW_ON_ERROR));
        $db->beginTransaction();
        try{
            $old=self::q($db,'SELECT id,request_hash FROM marketing_publish_jobs WHERE company_id=? AND request_key=? FOR UPDATE',[$company,$key])->fetch(PDO::FETCH_ASSOC);
            if($old){
                if(!hash_equals((string)$old['request_hash'],$inputHash))throw new DomainException('Request key reused for different post');
                $db->commit();return ['id'=>(int)$old['id'],'replayed'=>true];
            }
            $c=self::content($db,$company,$contentId,true);
            self::q($db,"INSERT INTO marketing_publish_jobs(company_id,content_id,account_alias,request_key,request_hash,content_hash,message_snapshot,scheduled_at,created_by) VALUES(?,?,?,?,?,?,?,?,?)",[
                $company,$contentId,$alias,$key,$inputHash,self::hash($c),$c['body'],$schedule,(int)$user['id']]);
            $id=(int)$db->lastInsertId();
            $db->commit();return ['id'=>$id,'status'=>'DRAFT','replayed'=>false];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function approve(PDO $db,array $config,array $user,int $jobId):array {
        $company=(int)$user['company_id'];$db->beginTransaction();
        try{
            $j=self::q($db,'SELECT * FROM marketing_publish_jobs WHERE company_id=? AND id=? FOR UPDATE',[$company,$jobId])->fetch(PDO::FETCH_ASSOC);
            if(!$j)throw new OutOfBoundsException('Job not found');
            if($j['status']!=='DRAFT')throw new DomainException('Only draft can be approved');
            if((int)$j['created_by']===(int)$user['id'])throw new DomainException('Publisher cannot approve their own job');
            $account=self::account($config,$company,$j['account_alias']);
            if(!$account||!$account['ready'])throw new DomainException('Page permission or publishing feature not ready');
            $c=self::content($db,$company,(int)$j['content_id'],true);
            if(!hash_equals((string)$j['content_hash'],self::hash($c)))throw new DomainException('Content changed since drafting');
            self::q($db,"UPDATE marketing_publish_jobs SET status='APPROVED',approved_by=?,approved_at=NOW(),updated_at=NOW() WHERE id=? AND company_id=?",[
                (int)$user['id'],$jobId,$company]);
            $db->commit();return ['id'=>$jobId,'status'=>'APPROVED'];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function cancel(PDO $db,array $user,int $jobId):array{
        $company=(int)$user['company_id'];
        $row=self::q($db,"UPDATE marketing_publish_jobs SET status='CANCELLED',updated_at=NOW() WHERE company_id=? AND id=? AND status IN ('DRAFT','APPROVED')",[$company,$jobId]);
        if($row->rowCount()!==1)throw new DomainException('Job is no longer cancellable');
        return ['id'=>$jobId,'status'=>'CANCELLED'];
    }
    public static function claim(PDO $db,array $config):?array{
        $db->beginTransaction();
        try{
            $job=self::q($db,"SELECT * FROM marketing_publish_jobs WHERE status='APPROVED' AND scheduled_at<=UTC_TIMESTAMP() ORDER BY scheduled_at,id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if(!$job){$db->commit();return null;}
            $company=(int)$job['company_id'];
            $a=self::account($config,$company,$job['account_alias']);
            try{$c=self::content($db,$company,(int)$job['content_id'],true);}
            catch(DomainException|OutOfBoundsException $e){$c=null;}
            if(!$a||!$a['ready']||!$c||!hash_equals((string)$job['content_hash'],self::hash($c))) {
                self::q($db,"UPDATE marketing_publish_jobs SET status='BLOCKED',status_detail='Account or approved content changed',updated_at=UTC_TIMESTAMP() WHERE id=?",[$job['id']]);
                $db->commit();return ['blocked'=>true,'id'=>(int)$job['id']];
            }
            self::q($db,"UPDATE marketing_publish_jobs SET status='SENDING',claimed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?",[$job['id']]);
            $db->commit();
            return ['id'=>(int)$job['id'],'company_id'=>$company,'message'=>$job['message_snapshot'],'account'=>$a];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function complete(PDO $db,int $id,?string $providerId,string $detail=''):void{
        $status=$providerId!==null?'PUBLISHED':'UNCERTAIN';
        self::q($db,"UPDATE marketing_publish_jobs SET status=?,provider_post_id=?,status_detail=?,published_at=IF(?='PUBLISHED',UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='SENDING'",[
            $status,$providerId,substr($detail,0,200),$status,$id]);
    }
    public static function markAbandoned(PDO $db):int{
        $q=self::q($db,"UPDATE marketing_publish_jobs SET status='UNCERTAIN',status_detail='Worker interrupted; reconcile manually',updated_at=UTC_TIMESTAMP() WHERE status='SENDING' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
        return $q->rowCount();
    }
    public static function jobs(PDO $db,array $user):array {
        return self::q($db,"SELECT j.id,j.content_id,j.account_alias,j.scheduled_at,j.status,j.status_detail,j.provider_post_id,j.created_at,u.full_name creator
            FROM marketing_publish_jobs j JOIN users u ON u.id=j.created_by AND u.company_id=j.company_id
            WHERE j.company_id=? ORDER BY j.id DESC LIMIT 70",[(int)$user['company_id']])->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $config,array $user):void{
        if(!str_starts_with($route,'marketing/social-publishing'))return;
        Auth::requirePermission($db,$user,'campaign.manage');
        try{
            if($route==='marketing/social-publishing'&&$method==='GET')
                Http::json(['ok'=>true,'accounts'=>self::accounts($config,(int)$user['company_id']),
                    'jobs'=>self::jobs($db,$user),'worker_enabled'=>false,
                    'hint'=>'Manual server configuration and explicit approval required before live posting']);
            if($route==='marketing/social-publishing/create'&&$method==='POST')
                Http::json(['ok'=>true]+self::create($db,$config,$user,Http::body()),201);
            if($route==='marketing/social-publishing/approve'&&$method==='POST'){
                Auth::requirePermission($db,$user,'marketing.approve');
                $body=Http::body();Http::json(['ok'=>true]+self::approve($db,$config,$user,self::id($body['id']??null,'id')));
            }
            if($route==='marketing/social-publishing/cancel'&&$method==='POST'){
                $body=Http::body();Http::json(['ok'=>true]+self::cancel($db,$user,self::id($body['id']??null,'id')));
            }
            Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
        }catch(InvalidArgumentException $e){Http::json(['ok'=>false,'error'=>'VALIDATION'],422);}
        catch(OutOfBoundsException $e){Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);}
        catch(DomainException $e){Http::json(['ok'=>false,'error'=>'CONFLICT','message'=>$e->getMessage()],409);}
    }
}

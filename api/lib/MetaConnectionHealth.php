<?php
declare(strict_types=1);

/**
 * P9: Meta connection diagnostics, NOT OAuth authorization / account onboarding.
 * Tokens remain in private VTA config; this service never stores/returns them.
 * A VERIFIED health result confirms read-only Graph identity and token scopes,
 * NOT Meta app review, permissions to send messages or active subscriptions.
 */
final class MetaConnectionHealth {
    private static function q(PDO $db,string $sql,array $args=[]):PDOStatement{
        $s=$db->prepare($sql);$s->execute($args);return $s;
    }
    public static function inventory(array $cfg,int $company):array {
        $accounts=[];
        $inbound=$cfg['integrations']['meta_inbox']['accounts']??[];
        if(is_array($inbound))foreach($inbound as $alias=>$item){
            if(!is_string($alias)||!preg_match('/^[a-z0-9_-]{3,44}$/D',$alias)
               ||!is_array($item)||(int)($item['company_id']??0)!==$company)continue;
            $obj=$item['object']??'';
            if(!in_array($obj,['page','instagram'],true))continue;
            $id=(string)($item['entity_id']??'');
            if(!preg_match('/^[0-9]{8,40}$/D',$id))continue;
            $fb=$obj==='page';
            $accounts[]=[
                'alias'=>$alias,'product'=>$fb?'MESSENGER':'INSTAGRAM_DM',
                'entity_id'=>$id,'enabled'=>($item['enabled']??false)===true,
                'app_review_confirmed'=>($item['app_review_confirmed']??false)===true,
                'required_scopes'=>$fb?['pages_messaging']:['instagram_basic','instagram_manage_messages'],
                'token_key'=>$fb?'page_access_token':'page_access_token',
                'cfg'=>$item
            ];
        }
        $publishing=$cfg['integrations']['social_publishing']['accounts']??[];
        if(is_array($publishing))foreach($publishing as $alias=>$item){
            if(!is_string($alias)||!preg_match('/^[a-z0-9_-]{3,64}$/D',$alias)
               ||!is_array($item)||(int)($item['company_id']??0)!==$company)continue;
            $p=$item['provider']??null;
            if(!in_array($p,['FACEBOOK_PAGE','INSTAGRAM_BUSINESS'],true))continue;
            $fb=$p==='FACEBOOK_PAGE';
            $id=(string)($item[$fb?'page_id':'ig_user_id']??'');
            if(!preg_match('/^[0-9]{5,40}$/D',$id))continue;
            $accounts[]=[
                'alias'=>$alias,'product'=>$fb?'FACEBOOK_PAGE':'INSTAGRAM_PUBLISH',
                'entity_id'=>$id,'enabled'=>($item['enabled']??false)===true,
                'app_review_confirmed'=>($item['app_review_confirmed']??false)===true,
                'required_scopes'=>$fb?['pages_manage_posts']:['instagram_basic','instagram_content_publish','pages_read_engagement'],
                'token_key'=>'access_token','cfg'=>$item
            ];
        }
        return $accounts;
    }
    private static function config(array $cfg):array{
        $v=$cfg['integrations']['meta_connection_check']??[];
        if(!is_array($v))return [];
        return $v;
    }
    public static function version(array $cfg):string{
        $v=(string)(self::config($cfg)['graph_version']??'v26.0');
        if(!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$v))throw new InvalidArgumentException('Invalid Meta Graph version');
        return $v;
    }
    private static function url(string $path,array $params,array $cfg):string{
        if(!preg_match('/^(?:debug_token|[0-9]{5,40})$/D',$path))
            throw new InvalidArgumentException('Invalid Graph path');
        return 'https://graph.facebook.com/'.self::version($cfg).'/'.$path.
            ($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');
    }
    public static function get(string $url,string $authorization):array{
        if(!preg_match('#^https://graph\.facebook\.com/v[0-9]{1,2}\.[0-9]/(?:debug_token|[0-9]{5,40})(?:\?.{1,5000})?$#D',$url)
           ||strlen($authorization)<30)throw new InvalidArgumentException('Unapproved Graph request');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP curl extension unavailable');
        $h=curl_init($url);
        if($h===false)throw new RuntimeException('Graph HTTP transport unavailable');
        try {
            curl_setopt_array($h,[
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>15,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$authorization,'Accept: application/json'],
                CURLOPT_USERAGENT=>'VTA-Meta-Connection-Check/1.0'
            ]);
            $raw=curl_exec($h);$http=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);
            if(!is_string($raw)||strlen($raw)>32768||$http<200||$http>=300)
                throw new RuntimeException('Meta verification not confirmed');
            $out=json_decode($raw,true);
            if(!is_array($out)||array_is_list($out))
                throw new RuntimeException('Invalid Graph response');
            return $out;
        }finally{curl_close($h);}
    }
    public static function probe(array $cfg,array $entry,?callable $transport=null,?int $now=null):array{
        $now??=time();
        $app=self::config($cfg);
        $appId=(string)($app['app_id']??'');
        $secret=$app['app_secret']??null;
        $token=$entry['cfg'][$entry['token_key']]??null;
        if(!preg_match('/^[0-9]{5,30}$/D',$appId)||!is_string($secret)||strlen($secret)<32
           ||!is_string($token)||strlen($token)<30)
            return ['status'=>'NOT_VERIFIED','scopes'=>[],'expires_at'=>null,'error_code'=>'MISSING_PRIVATE_CREDENTIALS'];
        $get=$transport??[self::class,'get'];
        $appToken=$appId.'|'.$secret;
        try {
            // Provider-documented token introspection; URL remains server-side and
            // must be excluded from proxy/HTTP tracing and application logs.
            $debug=$get(self::url('debug_token',['input_token'=>$token],$cfg),$appToken);
            $info=$debug['data']??null;
            if(!is_array($info))throw new RuntimeException('Malformed token debugger response');
            $scopes=$info['scopes']??[];
            if(!is_array($scopes))$scopes=[];
            $scopes=array_values(array_unique(array_filter($scopes,static fn($v)=>is_string($v)&&preg_match('/^[a-z0-9_]{3,80}$/D',$v)===1)));
            sort($scopes);
            $expires=is_int($info['expires_at']??null)?$info['expires_at']:null;
            $issuedApp=(string)($info['app_id']??'');
            if(($info['is_valid']??false)!==true||!hash_equals($appId,$issuedApp))
                return ['status'=>'INVALID','scopes'=>$scopes,'expires_at'=>$expires,'error_code'=>'INVALID_TOKEN_OR_APP'];
            if($expires!==null&&$expires!==0&&$expires<=$now+86400)
                return ['status'=>'EXPIRED','scopes'=>$scopes,'expires_at'=>$expires,'error_code'=>'TOKEN_EXPIRED_OR_IMMINENT'];
            $missing=array_diff($entry['required_scopes'],$scopes);
            if($missing)return ['status'=>'MISSING_PERMISSIONS','scopes'=>$scopes,'expires_at'=>$expires,'error_code'=>'REQUIRED_SCOPE_NOT_GRANTED'];
            // Check the precise configured Page or IG account identity.
            $entity=(string)$entry['entity_id'];
            $identity=$get(self::url($entity,['fields'=>'id'],$cfg),$token);
            if((string)($identity['id']??'')!==$entity)
                return ['status'=>'INVALID','scopes'=>$scopes,'expires_at'=>$expires,'error_code'=>'ACCOUNT_IDENTITY_MISMATCH'];
            return ['status'=>'VERIFIED','scopes'=>$scopes,'expires_at'=>$expires,'error_code'=>null];
        }catch(Throwable $e){
            return ['status'=>'NOT_VERIFIED','scopes'=>[],'expires_at'=>null,'error_code'=>'PROVIDER_CHECK_FAILED'];
        }
    }
    public static function store(PDO $db,int $company,array $entry,array $result):void{
        $status=$result['status'];
        if(!in_array($status,['VERIFIED','INVALID','MISSING_PERMISSIONS','EXPIRED','NOT_VERIFIED'],true))
            throw new InvalidArgumentException('Invalid health status');
        $expires=$result['expires_at']??null;
        $utc=$expires!==null&&$expires>0?gmdate('Y-m-d H:i:s',(int)$expires):null;
        self::q($db,"INSERT INTO marketing_meta_connection_checks(
            company_id,account_alias,product,entity_id,status,checked_at,expires_at,permissions_json,error_code
        ) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),?,?,?)
        ON DUPLICATE KEY UPDATE entity_id=VALUES(entity_id),status=VALUES(status),checked_at=VALUES(checked_at),
            expires_at=VALUES(expires_at),permissions_json=VALUES(permissions_json),error_code=VALUES(error_code)",[
            $company,$entry['alias'],$entry['product'],$entry['entity_id'],$status,$utc,
            json_encode($result['scopes']??[],JSON_THROW_ON_ERROR),$result['error_code']??null]);
    }
    public static function isRecentVerified(PDO $db,int $company,string $alias,string $product,string $entity,int $maxAgeHours=12):bool{
        if($maxAgeHours<1||$maxAgeHours>72)return false;
        $row=self::q($db,"SELECT status,checked_at,expires_at,entity_id FROM marketing_meta_connection_checks
          WHERE company_id=? AND account_alias=? AND product=? LIMIT 1",[$company,$alias,$product])->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='VERIFIED'||!hash_equals((string)$row['entity_id'],$entity))return false;
        $checked=strtotime($row['checked_at'].' UTC');
        return $checked!==false&&$checked>=time()-$maxAgeHours*3600
            &&($row['expires_at']===null||strtotime($row['expires_at'].' UTC')>time()+3600);
    }
    public static function listing(PDO $db,array $cfg,int $company):array{
        $items=[];
        foreach(self::inventory($cfg,$company) as $account){
            $r=self::q($db,"SELECT status,checked_at,expires_at,error_code,entity_id,permissions_json
                FROM marketing_meta_connection_checks
                WHERE company_id=? AND account_alias=? AND product=? LIMIT 1",[
                    $company,$account['alias'],$account['product']])->fetch(PDO::FETCH_ASSOC);
            $match=$r&&hash_equals((string)$r['entity_id'],(string)$account['entity_id']);
            $status=$match?$r['status']:'NEVER_CHECKED';
            $fresh=$match&&self::isRecentVerified($db,$company,$account['alias'],$account['product'],$account['entity_id']);
            $items[]=[
                'alias'=>$account['alias'],'product'=>$account['product'],
                'entity_id'=>$account['entity_id'],
                'enabled'=>$account['enabled'],'app_review_confirmed'=>$account['app_review_confirmed'],
                'health_status'=>$status,'last_checked_at'=>$match?$r['checked_at']:null,
                'expires_at'=>$match?$r['expires_at']:null,
                'error_code'=>$match?$r['error_code']:null,
                'recently_verified'=>$fresh,
                // Configured/verified is NOT approval to publish or reply.
                'oauth_connected'=>false,
                'required_scopes'=>$account['required_scopes']
            ];
        }
        return $items;
    }
    public static function adminHandle(string $route,string $method,PDO $db,array $cfg,array $user):void{
        if($route!=='marketing/meta-connections')return;
        if($method!=='GET')Http::json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
        if(!Auth::can($db,(int)$user['id'],'lead.view')&&!Auth::can($db,(int)$user['id'],'campaign.manage'))
            Auth::requirePermission($db,$user,'lead.view');
        Http::json(['ok'=>true,'accounts'=>self::listing($db,$cfg,(int)$user['company_id']),
            'oauth_supported'=>false,'test_mode_only'=>true,
            'verification_note'=>'Read-only checks require an explicit authorized staging CLI; no live Meta OAuth connection or outbound permission is claimed']);
    }
}

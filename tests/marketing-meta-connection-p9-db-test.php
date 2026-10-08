<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MetaConnectionHealth.php';
require __DIR__.'/marketing-meta-replies-p8-db-test.php';

echo "\nP9 Meta Connection Center diagnostics tests\n";
function p9check(bool $ok,string $desc):void {
 if(!$ok)throw new RuntimeException('FAIL '.$desc);
 echo "PASS ".$desc."\n";
}
$schema=file_get_contents(__DIR__.'/../api/migrations/043_meta_connection_health.sql');
foreach(explode(';',$schema) as $stmt)if(trim($stmt)!=='')$db->exec($stmt);

$appId='123456789012345';
$appSecret=str_repeat('s',48);
$pageToken=str_repeat('P',64);
$igToken=str_repeat('I',64);
$healthCfg=[
 'integrations'=>[
  'meta_connection_check'=>['graph_version'=>'v26.0','app_id'=>$appId,'app_secret'=>$appSecret],
  'meta_inbox'=>[
   'enabled'=>true,
   'accounts'=>[
    'vta_page'=>[
      'company_id'=>1,'campaign_id'=>1,'object'=>'page',
      'entity_id'=>'11223344556677','enabled'=>true,'app_review_confirmed'=>true,
      'page_access_token'=>$pageToken
    ],
    'other_ig'=>[
      'company_id'=>2,'campaign_id'=>2,'object'=>'instagram',
      'entity_id'=>'17841400999000111','enabled'=>true,'app_review_confirmed'=>true,
      'page_access_token'=>$igToken
    ]
   ]
  ],
  'social_publishing'=>[
   'enabled'=>false,
   'accounts'=>[
    'mainfb'=>[
      'company_id'=>1,'provider'=>'FACEBOOK_PAGE','page_id'=>'11223344556677',
      'access_token'=>$pageToken,'enabled'=>false,'app_review_confirmed'=>false
    ],
    'igpub'=>[
      'company_id'=>1,'provider'=>'INSTAGRAM_BUSINESS','ig_user_id'=>'17841400999000111',
      'access_token'=>$igToken,'enabled'=>false,'app_review_confirmed'=>false
    ]
   ]
  ]
 ]
];
$ours=MetaConnectionHealth::inventory($healthCfg,1);
p9check(count($ours)===3,'private config discovery sees Messenger, Page and IG publisher for company 1');
$theirs=MetaConnectionHealth::inventory($healthCfg,2);
p9check(count($theirs)===1&&$theirs[0]['product']==='INSTAGRAM_DM','company 2 only sees its own Instagram DM');
p9check(!str_contains(json_encode(array_map(static fn($a)=>[$a['alias'],$a['product'],$a['entity_id']],$ours)),$appSecret),'public account metadata omits Meta app secret');
$entry=array_values(array_filter($ours,fn($a)=>$a['product']==='MESSENGER'))[0];
$future=time()+14*86400;
$calls=[];
$fake=static function(string $url,string $auth) use(&$calls,$future,$appId):array {
 $calls[]=['url'=>$url,'auth'=>$auth];
 if(str_contains($url,'/debug_token?')){
   return ['data'=>[
     'is_valid'=>true,'app_id'=>$appId,
     'expires_at'=>$future,'scopes'=>['pages_messaging','pages_manage_metadata']
   ]];
 }
 return ['id'=>'11223344556677'];
};
$valid=MetaConnectionHealth::probe($healthCfg,$entry,$fake);
p9check($valid['status']==='VERIFIED','read-only token debugger and exact Page ID can be verified');
p9check(count($calls)===2,'one debug_token and one identity check, no publishing API');
p9check(str_starts_with($calls[0]['url'],'https://graph.facebook.com/v26.0/debug_token?input_token='),
    'token debugging uses fixed Meta Graph host');
p9check($calls[0]['auth']===$appId.'|'.$appSecret&&$calls[1]['auth']===$pageToken,
    'app token and Page bearer token sent only to their respective verifier endpoints');
MetaConnectionHealth::store($db,1,$entry,$valid);
p9check(MetaConnectionHealth::isRecentVerified($db,1,'vta_page','MESSENGER','11223344556677'),
    'fresh health record is accepted only for exact company and entity');
p9check(!MetaConnectionHealth::isRecentVerified($db,2,'vta_page','MESSENGER','11223344556677'),
    'company 2 cannot reuse company 1 verified health');
p9check(!MetaConnectionHealth::isRecentVerified($db,1,'vta_page','MESSENGER','99999999999999'),
    'wrong Page ID invalidates readiness');

$listing=MetaConnectionHealth::listing($db,$healthCfg,1);
p9check(count($listing)===3,'all company 1 account cards are returned');
$listed=json_encode($listing,JSON_THROW_ON_ERROR);
p9check(!str_contains($listed,$pageToken)&&!str_contains($listed,$appSecret)&&!str_contains($listed,$igToken),
    'UI API does not return tokens or app secrets');
p9check($listing[0]['oauth_connected']===false,'status never falsely claims OAuth connected');
p9check(count(array_filter($listing,fn($a)=>$a['recently_verified']))===1,
    'only actually checked account appears recently verified');

$deny=static fn(string $url,string $auth):array=>['data'=>[
 'is_valid'=>true,'app_id'=>$appId,'expires_at'=>time()+172800,'scopes'=>['pages_read_engagement']
]];
$missing=MetaConnectionHealth::probe($healthCfg,$entry,$deny);
p9check($missing['status']==='MISSING_PERMISSIONS'&&$missing['error_code']==='REQUIRED_SCOPE_NOT_GRANTED',
    'Page token without pages_messaging permission marked insufficient');
MetaConnectionHealth::store($db,1,$entry,$missing);
p9check(!MetaConnectionHealth::isRecentVerified($db,1,'vta_page','MESSENGER','11223344556677'),
    'permission removal invalidates previously verified entry');
$expired=MetaConnectionHealth::probe($healthCfg,$entry,
 static fn(string $url,string $auth):array=>['data'=>[
 'is_valid'=>true,'app_id'=>'123456789012345','expires_at'=>time()+600,'scopes'=>['pages_messaging']
 ]]);
p9check($expired['status']==='EXPIRED','near-term expiry is flagged before credential use');
$wrongApp=MetaConnectionHealth::probe($healthCfg,$entry,
 static fn(string $url,string $auth):array=>['data'=>[
 'is_valid'=>true,'app_id'=>'9999999999999','expires_at'=>time()+864000,'scopes'=>['pages_messaging']
 ]]);
p9check($wrongApp['status']==='INVALID','token from another Meta application rejected');
$wrongIdentity=MetaConnectionHealth::probe($healthCfg,$entry,
 static fn(string $url,string $auth):array=>str_contains($url,'debug_token')?[
 'data'=>['is_valid'=>true,'app_id'=>'123456789012345','expires_at'=>time()+864000,
    'scopes'=>['pages_messaging']]
 ]:['id'=>'00000000000000']);
p9check($wrongIdentity['status']==='INVALID'&&$wrongIdentity['error_code']==='ACCOUNT_IDENTITY_MISMATCH',
    'cross-Page token cannot verify a different Page account');
$network=MetaConnectionHealth::probe($healthCfg,$entry,
 static function():array{throw new RuntimeException('Provider unavailable');});
p9check($network['status']==='NOT_VERIFIED'&&$network['error_code']==='PROVIDER_CHECK_FAILED',
    'provider timeout does not imply connected status');
$notReady=$healthCfg;
unset($notReady['integrations']['meta_connection_check']['app_secret']);
p9check(MetaConnectionHealth::probe($notReady,$entry)['status']==='NOT_VERIFIED',
    'no verification without private Meta app secret');
$badHost=false;
try{MetaConnectionHealth::get('https://unsafe.example/debug_token',str_repeat('x',64));}
catch(InvalidArgumentException $e){$badHost=true;}
p9check($badHost,'network SSRF host injection rejected without making a request');
$db->exec("UPDATE marketing_meta_connection_checks SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE company_id=1");
p9check(!MetaConnectionHealth::isRecentVerified($db,1,'vta_page','MESSENGER','11223344556677'),
    'old health results cannot be treated as fresh');
p9check(!MetaConnectionHealth::isRecentVerified($db,1,'vta_page','MESSENGER','11223344556677',73),
    'invalid health time threshold rejected');

$guarded=$cfg;
$guarded['integrations']['meta_connection_check']=['enforce_messenger_verified'=>true];
$db->prepare("UPDATE social_conversations SET status='NEW',last_meta_inbound_at=UTC_TIMESTAMP() WHERE company_id=1 AND id=?")->execute([$cid]);
$blocked=MetaReplies::thread($db,$guarded,1,$cid);
p9check(!$blocked['can_send']&&$blocked['reason']==='CONNECTION_CHECK_REQUIRED',
    'Messenger can fail closed when recent connection verification is required');
MetaConnectionHealth::store($db,1,$entry,$valid);
$ready=MetaReplies::thread($db,$guarded,1,$cid);
p9check($ready['can_send']===true,'Messenger may queue a staff reply after fresh valid Meta health check');
$db->exec("UPDATE marketing_meta_connection_checks SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE company_id=1");
$stale=MetaReplies::thread($db,$guarded,1,$cid);
p9check(!$stale['can_send']&&$stale['reason']==='CONNECTION_CHECK_REQUIRED',
    'Messenger reply rejected if required verification record becomes stale');
echo "P9 Connection Center MariaDB integration complete.\n";

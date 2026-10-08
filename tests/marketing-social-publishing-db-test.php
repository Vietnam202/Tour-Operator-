<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/SocialPublishing.php';
require_once __DIR__.'/../api/lib/FacebookPagePoster.php';
function vtaAssert(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
$db=new PDO(getenv('VTA_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=vta_ci;charset=utf8mb4',
    getenv('VTA_TEST_USER')?:'vta',getenv('VTA_TEST_PASSWORD')?:'vta_ci_pw',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY)');
$db->exec('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,full_name VARCHAR(190) NOT NULL)');
$db->exec("CREATE TABLE campaigns(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,UNIQUE KEY uq_compaign_tenant(company_id,id))");
$db->exec("CREATE TABLE marketing_content (
 id BIGINT UNSIGNED PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 campaign_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(40) NOT NULL,
 content_format VARCHAR(32) NOT NULL DEFAULT 'POST',
 status ENUM('DRAFT','PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'DRAFT',
 body TEXT NOT NULL,
 asset_url VARCHAR(1000) NOT NULL DEFAULT '',
 KEY idx_marketing_queue(company_id,status)
)");
$contents=[
 [100,1,'Facebook','APPROVED','Discover Vietnam with our local team.','POST',''],
 [101,1,'Facebook','DRAFT','Unreviewed campaign copy.','POST',''],
 [102,1,'Instagram','APPROVED','IG post.','POST',''],
 [103,1,'Facebook','APPROVED','Image-based post.','POST','https://example.test/photo.jpg'],
 [200,2,'Facebook','APPROVED','Other tenant copy.','POST',''],
];
$stmt=$db->prepare("INSERT INTO marketing_content(id,company_id,channel,status,body,content_format,asset_url,campaign_id) VALUES(?,?,?,?,?,?,?,1)");
$db->exec("INSERT INTO companies VALUES(1),(2)");
$db->exec("INSERT INTO users VALUES(1,1,'Writer'),(2,1,'Reviewer'),(3,2,'Another Tenant')");
$db->exec("INSERT INTO campaigns VALUES(1,1),(2,2)");
foreach($contents as $a)$stmt->execute($a);
$sql=file_get_contents(__DIR__.'/../api/migrations/039_social_publishing_core.sql');
foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);

$config=['integrations'=>['social_publishing'=>[
 'enabled'=>true,
 'accounts'=>[
  'vta-fb-main'=>[
    'company_id'=>1,'provider'=>'FACEBOOK_PAGE','page_id'=>'1234567891234',
    'name'=>'VTA Main Facebook','access_token'=>str_repeat('x',60),
    'permissions'=>['pages_manage_posts'],'enabled'=>true,'app_review_confirmed'=>true
  ],
  'foreign-page'=>[
    'company_id'=>2,'provider'=>'FACEBOOK_PAGE','page_id'=>'1234567891235',
    'access_token'=>str_repeat('y',60),'permissions'=>['pages_manage_posts'],
    'enabled'=>true,'app_review_confirmed'=>true
  ],
  'no-permission'=>[
    'company_id'=>1,'provider'=>'FACEBOOK_PAGE','page_id'=>'1234567891236',
    'access_token'=>str_repeat('z',60),'permissions'=>['pages_read_engagement'],
    'enabled'=>true,'app_review_confirmed'=>true
  ]
]]]];
$user=['company_id'=>1,'id'=>1];$reviewer=['company_id'=>1,'id'=>2];
vtaAssert(count(SocialPublishing::accounts($config,1))===2,'only company-specific accounts returned');
vtaAssert(SocialPublishing::account($config,1,'foreign-page')===null,'foreign provider account hidden');
vtaAssert(SocialPublishing::account($config,1,'no-permission')['ready']===false,'unapproved posting capability blocked');
$shown=json_encode(SocialPublishing::accounts($config,1));
vtaAssert(!str_contains($shown,str_repeat('x',60)),'account listing does not leak access token');
vtaAssert(FacebookPagePoster::endpoint('1234567891234','v26.0')==='https://graph.facebook.com/v26.0/1234567891234/feed','Meta Page feed URL is fixed and HTTPS');
$invalid=false;
try{FacebookPagePoster::endpoint('https://evil.example/?token=x');}catch(InvalidArgumentException $e){$invalid=true;}
vtaAssert($invalid,'arbitrary network destination rejected');
$when=gmdate('Y-m-d\TH:i:s\Z',time()-20);
$create=['content_id'=>100,'account_alias'=>'vta-fb-main','scheduled_at'=>$when,'request_key'=>'social_draft_request_00001'];
$r=SocialPublishing::create($db,$config,$user,$create);
vtaAssert($r['id']>0&&$r['status']==='DRAFT','approved Marketing copy creates unpublished job');
$replayed=SocialPublishing::create($db,$config,$user,$create);
vtaAssert($replayed['replayed']&&$replayed['id']===$r['id'],'duplicate scheduling request deduplicated');
$conflict=false;
try{SocialPublishing::create($db,$config,$user,array_replace($create,['content_id'=>101]));}
catch(DomainException $e){$conflict=true;}
vtaAssert($conflict,'idempotency key reused with altered content rejected');
$self=false;
try{SocialPublishing::approve($db,$config,$user,$r['id']);}catch(DomainException $e){$self=true;}
vtaAssert($self,'no self approval');
$approved=SocialPublishing::approve($db,$config,$reviewer,$r['id']);
vtaAssert($approved['status']==='APPROVED','separate staff member approves publishing');
$j=SocialPublishing::claim($db,$config);
vtaAssert($j['id']===$r['id']&&$j['message']==='Discover Vietnam with our local team.','worker safely claims approved due job');
vtaAssert(SocialPublishing::claim($db,$config)===null,'claimed job is not selected again');
SocialPublishing::complete($db,$r['id'],'1234567891234_987654321');
$status=$db->query('SELECT status,provider_post_id FROM marketing_publish_jobs WHERE id='.$r['id'])->fetch();
vtaAssert($status['status']==='PUBLISHED'&&$status['provider_post_id']!=='','provider confirmation can mark job published');
$doubleApprove=false;try{SocialPublishing::approve($db,$config,$reviewer,$r['id']);}catch(DomainException $e){$doubleApprove=true;}
vtaAssert($doubleApprove,'published job cannot be approved again');
$badDraft=false;
try{SocialPublishing::create($db,$config,$user,array_replace($create,['content_id'=>101,'request_key'=>'social_draft_request_00002']));}
catch(DomainException $e){$badDraft=true;}
vtaAssert($badDraft,'unapproved Marketing copy cannot be scheduled');
$badIg=false;
try{SocialPublishing::create($db,$config,$user,array_replace($create,['content_id'=>102,'request_key'=>'social_draft_request_00003']));}
catch(DomainException $e){$badIg=true;}
vtaAssert($badIg,'Instagram draft cannot be sent through Facebook-only adapter');
$badAsset=false;
try{SocialPublishing::create($db,$config,$user,array_replace($create,['content_id'=>103,'request_key'=>'social_draft_request_00004']));}
catch(DomainException $e){$badAsset=true;}
vtaAssert($badAsset,'image post cannot masquerade as supported text-only post');
$future=['content_id'=>100,'account_alias'=>'vta-fb-main','scheduled_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+1800),'request_key'=>'social_draft_request_00005'];
$later=SocialPublishing::create($db,$config,$user,$future);
SocialPublishing::approve($db,$config,$reviewer,$later['id']);
vtaAssert(SocialPublishing::claim($db,$config)===null,'future scheduled job is not prematurely published');
$cancel=SocialPublishing::cancel($db,$user,$later['id']);
vtaAssert($cancel['status']==='CANCELLED','scheduled but unsent post can be cancelled');
$stale=SocialPublishing::create($db,$config,$user,array_replace($create,['request_key'=>'social_draft_request_00006']));
$db->exec("UPDATE marketing_content SET body='Edited without new review' WHERE id=100");
$staleBlocked=false;
try{SocialPublishing::approve($db,$config,$reviewer,$stale['id']);}catch(DomainException $e){$staleBlocked=true;}
vtaAssert($staleBlocked,'changed Marketing content blocked at approval');
$db->exec("UPDATE marketing_content SET body='Discover Vietnam with our local team.' WHERE id=100");
$uncertain=SocialPublishing::create($db,$config,$user,array_replace($create,['request_key'=>'social_draft_request_00007']));
SocialPublishing::approve($db,$config,$reviewer,$uncertain['id']);
$j=SocialPublishing::claim($db,$config);vtaAssert($j['id']===$uncertain['id'],'revised job claimed once');
SocialPublishing::complete($db,$uncertain['id'],null,'provider delivery unknown');
vtaAssert($db->query("SELECT status FROM marketing_publish_jobs WHERE id=".$uncertain['id'])->fetchColumn()==='UNCERTAIN','unknown provider result is not retried automatically');
vtaAssert(SocialPublishing::claim($db,$config)===null,'unknown outcome cannot be dispatched twice');
$pending=SocialPublishing::create($db,$config,$user,array_replace($create,['request_key'=>'social_draft_request_00008']));
SocialPublishing::approve($db,$config,$reviewer,$pending['id']);
$db->exec("UPDATE marketing_content SET body='Changed after approval' WHERE id=100");
$block=SocialPublishing::claim($db,$config);
vtaAssert(!empty($block['blocked'])&&$block['id']===$pending['id'],'worker blocks altered copy immediately before provider call');
$state=$db->query("SELECT status FROM marketing_publish_jobs WHERE id=".$pending['id'])->fetchColumn();
vtaAssert($state==='BLOCKED','changed-content job is marked BLOCKED, never published');
$db->exec("UPDATE marketing_content SET body='Discover Vietnam with our local team.' WHERE id=100");
$unready=SocialPublishing::create($db,$config,$user,array_replace($create,['account_alias'=>'no-permission','request_key'=>'social_draft_request_00009']));
$denied=false;
try{SocialPublishing::approve($db,$config,$reviewer,$unready['id']);}
catch(DomainException $e){$denied=true;}
vtaAssert($denied,'account missing pages_manage_posts cannot be approved for live publishing');
echo "P5 Social Publishing MariaDB checks completed.\n";

<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MetaGraphTransport.php';
require_once __DIR__.'/../api/lib/InstagramImagePoster.php';
require __DIR__.'/marketing-social-publishing-db-test.php';

echo "\nP6 Instagram image publishing integration:\n";
$db->prepare("INSERT INTO marketing_content(id,company_id,campaign_id,channel,content_format,status,body,asset_url,rights_note)
 VALUES(104,1,1,'Instagram','POST','APPROVED',?,?,?)")->execute([
 'Vietnam Travel Advisor - explore Da Nang!',
 'https://media.vietnamtraveladvisor.com.vn/photos/danang.jpg',
 'Licensed VTA photography'
]);
$config['integrations']['social_publishing']['accounts']['vta-ig-main']=[
 'company_id'=>1,'provider'=>'INSTAGRAM_BUSINESS','name'=>'VTA Instagram',
 'ig_user_id'=>'17841400999000111','page_id'=>'1234567891234',
 'login_type'=>'FACEBOOK','access_token'=>str_repeat('i',60),
 'permissions'=>['instagram_basic','instagram_content_publish','pages_read_engagement','pages_show_list'],
 'allowed_media_hosts'=>['media.vietnamtraveladvisor.com.vn'],
 'enabled'=>true,'app_review_confirmed'=>true
];
$ig=SocialPublishing::account($config,1,'vta-ig-main');
vtaAssert($ig!==null&&$ig['ready']===true&&$ig['provider']==='INSTAGRAM_BUSINESS','IG professional Facebook Login account accepted with explicit scopes');
$accounts=SocialPublishing::accounts($config,1);
vtaAssert(count($accounts)===3,'FB and IG accounts share tenant-scoped readiness list');
vtaAssert(!str_contains(json_encode($accounts),str_repeat('i',60)),'IG token never appears in staff API account list');
vtaAssert(InstagramImagePoster::endpoint('17841400999000111','media')==='https://graph.facebook.com/v26.0/17841400999000111/media','correct IG container endpoint');
vtaAssert(InstagramImagePoster::endpoint('17841400999000111','media_publish')==='https://graph.facebook.com/v26.0/17841400999000111/media_publish','correct IG media_publish endpoint');
foreach([
 'http://media.vietnamtraveladvisor.com.vn/a.jpg',
 'https://127.0.0.1/a.jpg',
 'https://foreign.example/a.jpg',
 'https://media.vietnamtraveladvisor.com.vn/a.png',
 'https://media.vietnamtraveladvisor.com.vn/a.jpg?token=secret',
 'https://user:password@media.vietnamtraveladvisor.com.vn/a.jpg'
] as $bad){
    $denied=false;
    try{InstagramImagePoster::imageUrl($bad,['media.vietnamtraveladvisor.com.vn']);}
    catch(DomainException $e){$denied=true;}
    vtaAssert($denied,'disallow unsigned/unknown/non-JPEG media source: '.substr($bad,0,38));
}
$badEndpoint=false;try{MetaGraphTransport::request('GET','https://evil.example.net/resource',[],str_repeat('i',60));}
catch(InvalidArgumentException $e){$badEndpoint=true;}
vtaAssert($badEndpoint,'Meta transport prevents arbitrary external API URLs');
$created=SocialPublishing::create($db,$config,$user,[
 'content_id'=>104,'account_alias'=>'vta-ig-main','scheduled_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-20),'request_key'=>'ig_post_approved_00000001'
]);
vtaAssert($created['status']==='DRAFT','approved IG photo creates pending job');
SocialPublishing::approve($db,$config,$reviewer,(int)$created['id']);
$claimed=SocialPublishing::claim($db,$config);
vtaAssert($claimed['id']===$created['id']&&$claimed['provider']==='INSTAGRAM_BUSINESS','worker claims IG rather than FB publisher');
vtaAssert($claimed['media_url']==='https://media.vietnamtraveladvisor.com.vn/photos/danang.jpg','immutable photo URL passed to worker');
$apiCalls=[];$reads=0;$captured='';
$fakeTransport=static function(string $method,string $url,array $fields,string $token)use(&$apiCalls,&$reads):array {
    $apiCalls[]=[$method,$url,$fields];
    if($method==='POST'&&str_ends_with($url,'/media'))return ['id'=>'18000000000000001'];
    if($method==='GET')return ['status_code'=>(++$reads===1?'IN_PROGRESS':'FINISHED')];
    if($method==='POST'&&str_ends_with($url,'/media_publish'))return ['id'=>'18000000000000002'];
    throw new RuntimeException('Unknown mocked Meta request');
};
$postId=InstagramImagePoster::publish($claimed['account'],$claimed['message'],$claimed['media_url'],
 static function(string $id)use($db,$claimed,&$captured):void{
    $captured=$id;SocialPublishing::rememberContainer($db,(int)$claimed['id'],$id);
 },$fakeTransport,static function():void{});
vtaAssert($postId==='18000000000000002','simulated IG provider confirms published media ID');
vtaAssert($captured==='18000000000000001','media container recorded before publish stage');
vtaAssert(count($apiCalls)===4&&$apiCalls[0][0]==='POST'&&$apiCalls[1][0]==='GET'
    &&$apiCalls[2][0]==='GET'&&$apiCalls[3][0]==='POST','provider calls container, status, status, publish in order');
vtaAssert($apiCalls[0][2]['image_url']===$claimed['media_url'],'media creation uses vetted photo URL');
SocialPublishing::complete($db,(int)$claimed['id'],$postId);
$p=$db->query("SELECT status,media_container_id,provider_post_id FROM marketing_publish_jobs WHERE id=".$created['id'])->fetch();
vtaAssert($p['status']==='PUBLISHED'&&$p['media_container_id']==='18000000000000001'&&$p['provider_post_id']===$postId,
    'published job tracks container and provider media ID without token');
vtaAssert(SocialPublishing::claim($db,$config)===null,'published IG post not claimed a second time');

$changed=SocialPublishing::create($db,$config,$user,[
 'content_id'=>104,'account_alias'=>'vta-ig-main','scheduled_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-20),'request_key'=>'ig_post_changed_00000001'
]);
SocialPublishing::approve($db,$config,$reviewer,(int)$changed['id']);
$db->exec("UPDATE marketing_content SET asset_url='https://media.vietnamtraveladvisor.com.vn/photos/another.jpg' WHERE id=104");
$blocked=SocialPublishing::claim($db,$config);
vtaAssert(!empty($blocked['blocked'])&&$blocked['id']===$changed['id'],'changed IG image rejected immediately before publish');
$db->exec("UPDATE marketing_content SET asset_url='https://media.vietnamtraveladvisor.com.vn/photos/danang.jpg' WHERE id=104");

$config['integrations']['social_publishing']['accounts']['vta-ig-main']['permissions']=['instagram_basic'];
vtaAssert(SocialPublishing::account($config,1,'vta-ig-main')['ready']===false,'missing instagram_content_publish blocks account readiness');
$config['integrations']['social_publishing']['accounts']['vta-ig-main']['permissions']=['instagram_basic','instagram_content_publish','pages_read_engagement'];
$noRights=SocialPublishing::create($db,$config,$user,[
 'content_id'=>104,'account_alias'=>'vta-ig-main','scheduled_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-20),'request_key'=>'ig_post_norights_00001'
]);
$db->exec("UPDATE marketing_content SET rights_note='' WHERE id=104");
$rightsBlocked=false;try{SocialPublishing::approve($db,$config,$reviewer,(int)$noRights['id']);}
catch(DomainException $e){$rightsBlocked=true;}
vtaAssert($rightsBlocked,'Instagram media rights must remain present at approval');
$db->exec("UPDATE marketing_content SET rights_note='Licensed VTA photography' WHERE id=104");
$uncertain=SocialPublishing::create($db,$config,$user,[
 'content_id'=>104,'account_alias'=>'vta-ig-main','scheduled_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-20),'request_key'=>'ig_post_uncertain_00001'
]);
SocialPublishing::approve($db,$config,$reviewer,(int)$uncertain['id']);
$waiting=SocialPublishing::claim($db,$config);
$failed=false;
try{
    InstagramImagePoster::publish($waiting['account'],$waiting['message'],$waiting['media_url'],
        static function(string $id)use($db,$waiting):void{SocialPublishing::rememberContainer($db,(int)$waiting['id'],$id);},
        static function(string $method,string $url,array $fields,string $token):array{
            if(str_ends_with($url,'/media_publish'))throw new RuntimeException('Simulated network uncertainty');
            if($method==='GET')return ['status_code'=>'FINISHED'];
            return ['id'=>'18000000000000003'];
        },static function():void{});
}catch(RuntimeException $e){$failed=true;}
vtaAssert($failed,'media_publish network failure explicitly propagated as unknown');
SocialPublishing::complete($db,(int)$waiting['id'],null,'Manual reconcile required');
vtaAssert($db->query("SELECT status FROM marketing_publish_jobs WHERE id=".$waiting['id'])->fetchColumn()==='UNCERTAIN',
    'unconfirmed Instagram publish never gets automatic retry');
vtaAssert(SocialPublishing::claim($db,$config)===null,'all published/blocked/uncertain IG jobs excluded from dispatch');
echo "P6 Instagram MariaDB tests completed.\n";

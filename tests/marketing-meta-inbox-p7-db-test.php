<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MetaInbox.php';
require __DIR__.'/website-chat-delivery-db-test.php';

function metaCheck(bool $ok,string $description):void{
 if(!$ok)throw new RuntimeException('FAIL '.$description);
 echo "PASS ".$description."\n";
}
$schema=file_get_contents(__DIR__.'/../api/migrations/041_meta_unified_inbox.sql');
foreach(explode(';',$schema) as $statement)if(trim($statement)!=='')$db->exec($statement);

$secret=str_repeat('a',32);$verify=str_repeat('v',48);
$config=['integrations'=>['meta_inbox'=>[
 'enabled'=>true,'app_secret'=>$secret,'verify_token'=>$verify,
 'accounts'=>[
    'vta_page'=>[
        'company_id'=>1,'campaign_id'=>1,'object'=>'page',
        'entity_id'=>'11223344556677','enabled'=>true,'app_review_confirmed'=>true],
    'vta_instagram'=>[
        'company_id'=>2,'campaign_id'=>2,'object'=>'instagram',
        'entity_id'=>'17841400999000111','enabled'=>true,'app_review_confirmed'=>true]
 ]]]];
$map=MetaInbox::accounts($config);
metaCheck(count($map)===2,'two tenant-scoped Meta endpoints configured');
metaCheck(count(MetaInbox::configuredAccounts($config,1))===1,'staff connection status scoped to correct company');
metaCheck(MetaInbox::challenge(['hub.mode'=>'subscribe','hub.verify_token'=>$verify,'hub.challenge'=>'abc9876'],$verify)==='abc9876','Meta verification handshake accepts correct configured token');
metaCheck(MetaInbox::challenge(['hub.mode'=>'subscribe','hub.verify_token'=>'wrong','hub.challenge'=>'abc9876'],$verify)===null,'unknown verify token rejected');
metaCheck(MetaInbox::challenge(['hub.mode'=>'subscribe','hub.verify_token'=>$verify,'hub.challenge'=>'<script>'],$verify)===null,'non-literal verify challenge rejected');
$fb=[
 'object'=>'page',
 'entry'=>[['id'=>'11223344556677','messaging'=>[
    ['sender'=>['id'=>'66554433221100'],'recipient'=>['id'=>'11223344556677'],
     'timestamp'=>1700000000000,'message'=>['mid'=>'m_abc00123456789','text'=>'Hi! Need Hanoi family tour.']],
    ['sender'=>['id'=>'11223344556677'],'recipient'=>['id'=>'66554433221100'],
     'message'=>['mid'=>'m_echo00123456','text'=>'Staff response','is_echo'=>true]],
    ['sender'=>['id'=>'66554433221100'],'recipient'=>['id'=>'11223344556677'],
     'delivery'=>['mids'=>['m_some_delivery_id']]],
    ['sender'=>['id'=>'66554433221100'],'recipient'=>['id'=>'99999999998888'],
     'message'=>['mid'=>'m_wrongrecipient123','text'=>'Forged recipient']],
 ]]]
];
$raw=json_encode($fb,JSON_THROW_ON_ERROR);
$sig='sha256='.hash_hmac('sha256',$raw,$secret);
metaCheck(MetaInbox::verify($raw,$secret,$sig),'valid app secret signs exact request bytes');
metaCheck(!MetaInbox::verify($raw.' ',$secret,$sig),'webhook altered after signing rejected');
metaCheck(!MetaInbox::verify($raw,'short',$sig),'short app secret rejected');
$parsed=MetaInbox::extract($raw,$map);
metaCheck(count($parsed)===1,'incoming text accepted but echo, delivery and mismatched recipient ignored');
metaCheck($parsed[0]['company_id']===1&&$parsed[0]['platform']==='FACEBOOK_MESSENGER','Page message assigned only to configured tenant');
metaCheck($parsed[0]['sender_id']==='66554433221100','Page sender ID retained as opaque conversation reference');
$first=MetaInbox::enqueue($db,$parsed);
metaCheck($first['queued']===1&&$first['replayed']===0,'inbound Meta event written durably');
$duplicate=MetaInbox::enqueue($db,$parsed);
metaCheck($duplicate['queued']===0&&$duplicate['replayed']===1,'identical retry is idempotent');
$altered=$parsed;$altered[0]['payload_hash']=str_repeat('f',64);
$conflict=false;
try{MetaInbox::enqueue($db,$altered);}catch(DomainException $e){$conflict=true;}
metaCheck($conflict,'changed content with reused Meta message ID blocked');
$worked=MetaInbox::processOne($db);
metaCheck($worked!==null&&$worked['status']==='DONE','queued webhook enters Unified Inbox asynchronously');
$none=MetaInbox::processOne($db);
metaCheck($none===null,'no duplicate processing after completed job');
$stored=$db->query("SELECT c.company_id,c.source_code,c.external_conversation_id,m.body FROM social_messages m JOIN social_conversations c ON c.id=m.conversation_id WHERE c.source_code LIKE 'meta_fb_%'")->fetchAll();
metaCheck(count($stored)===1&&$stored[0]['company_id']==1&&$stored[0]['body']==='Hi! Need Hanoi family tour.','original customer text stored once and tenant-scoped');

$ig=['object'=>'instagram','entry'=>[['id'=>'17841400999000111','messaging'=>[
 ['sender'=>['id'=>'99887766554433'],'recipient'=>['id'=>'17841400999000111'],
 'message'=>['mid'=>'m_insta00123456789','text'=>'Da Nang private tour?']]
]]]];
$igParsed=MetaInbox::extract(json_encode($ig,JSON_THROW_ON_ERROR),$map);
metaCheck(count($igParsed)===1&&$igParsed[0]['company_id']===2&&$igParsed[0]['platform']==='INSTAGRAM_DM','Instagram text accepted for separate tenant');
MetaInbox::enqueue($db,$igParsed);
$igJob=MetaInbox::processOne($db);
metaCheck($igJob['status']==='DONE','Instagram event persisted to shared conversation inbox');
$cross=$db->query("SELECT COUNT(*) FROM social_conversations WHERE company_id=2 AND source_code LIKE 'meta_ig_%'")->fetchColumn();
metaCheck((int)$cross===1,'Instagram conversation isolated from company 1');
$unknown=$fb;$unknown['entry'][0]['id']='33334444555566';
metaCheck(MetaInbox::extract(json_encode($unknown,JSON_THROW_ON_ERROR),$map)===[],'webhook for unknown Page creates no conversation');
$oversize=false;
try{MetaInbox::extract(str_repeat('a',262145),$map);}
catch(InvalidArgumentException $e){$oversize=true;}
metaCheck($oversize,'oversized untrusted delivery rejected');
$malformed=false;
try{MetaInbox::extract('{"object":"page","entry":{}}',$map);}
catch(InvalidArgumentException $e){$malformed=true;}
metaCheck($malformed,'invalid Meta event batch shape rejected');
$duplicateEntity=$config;
$duplicateEntity['integrations']['meta_inbox']['accounts']['other_tenant']=[
    'company_id'=>2,'campaign_id'=>2,'object'=>'page','entity_id'=>'11223344556677',
    'enabled'=>true,'app_review_confirmed'=>true];
$ambiguous=false;
try{MetaInbox::accounts($duplicateEntity);}catch(DomainException $e){$ambiguous=true;}
metaCheck($ambiguous,'duplicate Page mapping across tenants fails closed');

$fbConversation=$db->query("SELECT id FROM social_conversations WHERE company_id=1 AND source_code LIKE 'meta_fb_%'")->fetchColumn();
$lead=WebsiteInbox::handoff($db,['id'=>1,'company_id'=>1],[
  'id'=>(int)$fbConversation,'contact_name'=>'Verified guest',
  'email'=>'verified@example.test','total_guests'=>2,'paying_pax'=>2,
  'foc'=>0,'destination'=>'Hanoi'
]);
$leadRow=$db->query("SELECT source,attribution_json FROM lead_requests WHERE id=".$lead['request_id'])->fetch();
$attribution=json_decode($leadRow['attribution_json'],true);
metaCheck($leadRow['source']==='SOCIAL_DM'&&$attribution['source_type']==='VERIFIED_META_DM','Sales handoff preserves SOCIAL_DM attribution');
metaCheck(MetaInbox::recover($db)===0,'no orphan worker jobs found');

echo "P7 Meta-to-Unified-Inbox MariaDB integration checks complete.\n";

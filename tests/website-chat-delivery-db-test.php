<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/WebhookCenter.php';
require_once __DIR__.'/../api/lib/WebsiteInbox.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/WebsiteChatDelivery.php';

function checked(bool $ok,string $title):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$title);
    echo 'PASS: '.$title.PHP_EOL;
}
$db=new PDO(getenv('VTA_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=vta_ci;charset=utf8mb4',
    getenv('VTA_TEST_USER')?:'vta',getenv('VTA_TEST_PASSWORD')?:'vta_ci_pw',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema=[
    'CREATE TABLE companies (id BIGINT UNSIGNED PRIMARY KEY)',
    'CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL)',
    "CREATE TABLE campaigns (id BIGINT UNSIGNED PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE', UNIQUE KEY uq_campaign(company_id,id))",
    "CREATE TABLE lead_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NULL,source VARCHAR(80) NOT NULL,
        submission_key VARCHAR(80) NOT NULL,payload_hash CHAR(64) NOT NULL,contact_name VARCHAR(190) NOT NULL,
        email VARCHAR(190) NULL,phone VARCHAR(64) NULL,travel_date DATE NULL,total_guests INT UNSIGNED NOT NULL,
        paying_pax INT UNSIGNED NOT NULL,foc INT UNSIGNED NOT NULL,destination VARCHAR(500) NULL,
        hotel_level VARCHAR(32) NULL,message TEXT NULL,attribution_json JSON NOT NULL,
        UNIQUE KEY uq_submission(company_id,submission_key),UNIQUE KEY uq_request_tenant(company_id,id))",
    'CREATE TABLE audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,action_code VARCHAR(80) NOT NULL,entity_type VARCHAR(80) NULL,
        entity_id BIGINT UNSIGNED NULL,before_json JSON NULL,after_json JSON NULL,
        request_id VARCHAR(48) NOT NULL,ip_address VARCHAR(100) NULL)'
];
foreach($schema as $sql)$db->exec($sql);
$path=__DIR__.'/../api/migrations/024_website_unified_inbox.sql';
$raw=file_get_contents($path);
foreach(explode(';',$raw) as $sql)if(trim($sql)!=='')$db->exec($sql);
$extra=file_get_contents(__DIR__.'/../api/migrations/025_website_chat_outbound.sql');
foreach(explode(';',$extra) as $sql)if(trim($sql)!=='')$db->exec($sql);
// P3 upgraded RC6 schema needs marketing-approved tour metadata before P2 sends.
$db->exec("CREATE TABLE tour_library_programs (
 id BIGINT UNSIGNED PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 status ENUM('DRAFT','ACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
 title VARCHAR(190) NOT NULL,
 destination VARCHAR(190) NOT NULL,
 language VARCHAR(16) NOT NULL DEFAULT 'en',
 tags_json JSON NOT NULL,
 days_json JSON NOT NULL,
 UNIQUE KEY uq_lib_tenant(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$upgrade=file_get_contents(__DIR__.'/../api/migrations/037_marketing_tour_advisor_p3.sql');
foreach(explode(';',$upgrade) as $statement)if(trim($statement)!=='')$db->exec($statement);

$db->exec('INSERT INTO companies(id) VALUES(1),(2)');
$db->exec('INSERT INTO campaigns(id,company_id) VALUES(1,1),(2,2)');
$db->exec('INSERT INTO users(id,company_id) VALUES(1,1),(2,2)');

function evt(string $event='event_site_00000001',string $text='Hello'):array {
    return WebsiteInbox::parseMessage(json_encode([
        'event_type'=>'conversation.message','event_id'=>$event,
        'conversation'=>['external_id'=>'visitor_site_000001','contact_name'=>'Test Guest','email'=>'guest@example.test'],
        'message'=>['message_id'=>'message_'.$event,'text'=>$text],
        'attribution'=>['utm_source'=>'website','utm_campaign'=>'northern-vietnam']
    ],JSON_THROW_ON_ERROR));
}
$a=evt();$hash=hash('sha256',json_encode($a,JSON_THROW_ON_ERROR));
$first=WebsiteInbox::ingest($db,1,1,'site-main',$a,$hash);
checked($first['accepted']===true&&!$first['replayed'],'first event accepted');
$second=WebsiteInbox::ingest($db,1,1,'site-main',$a,$hash);
checked($second['replayed']===true&&$first['conversation_id']===$second['conversation_id'],'event replay is idempotent');
checked((int)$db->query('SELECT COUNT(*) FROM social_messages')->fetchColumn()===1,'one inbound message');
$conflict=false;
try{WebsiteInbox::ingest($db,1,1,'site-main',$a,hash('sha256','different'));}
catch(DomainException $e){$conflict=true;}
checked($conflict,'changed event payload rejected');

$b=evt('event_site_00000002','Second message');
WebsiteInbox::ingest($db,1,1,'site-main',$b,hash('sha256','second'));
checked((int)$db->query('SELECT COUNT(*) FROM social_messages')->fetchColumn()===2,'second message appended to same conversation');
$db->exec('UPDATE social_conversations SET status="CLOSED" WHERE company_id=1');
$c=evt('event_site_00000003','Reopen conversation');
WebsiteInbox::ingest($db,1,1,'site-main',$c,hash('sha256','third'));
checked($db->query('SELECT status FROM social_conversations WHERE company_id=1 LIMIT 1')->fetchColumn()==='NEW','new inbound message reopens closed thread');

$cross=WebsiteInbox::ingest($db,2,2,'site-main',evt('event_site_00000001'),$hash);
checked($cross['conversation_id']!==$first['conversation_id'],'company tenant isolation');
$other=WebsiteInbox::ingest($db,1,1,'site-landing',evt('event_site_00000001'),$hash);
checked($other['conversation_id']!==$first['conversation_id'],'separate website source isolation');

$conflictMessage=false;
try {
  $reused=evt('event_site_00000004','Different text under existing message id');
  $reused['message_id']=$a['message_id'];
  WebsiteInbox::ingest($db,1,1,'site-main',$reused,hash('sha256','other-msg'));
}catch(DomainException $e){$conflictMessage=true;}
checked($conflictMessage,'message ID tampering rejected');

$handoff=WebsiteInbox::handoff($db,['company_id'=>1,'id'=>1],[
 'id'=>$first['conversation_id'],'contact_name'=>'Test Guest','email'=>'guest@example.test',
 'total_guests'=>4,'paying_pax'=>4,'foc'=>0,'destination'=>'Ha Long']);
checked($handoff['request_id']>0&&!$handoff['replayed'],'website conversation converted to lead request');
$again=WebsiteInbox::handoff($db,['company_id'=>1,'id'=>1],['id'=>$first['conversation_id']]);
checked($again['replayed']===true&&$again['request_id']===$handoff['request_id'],'handoff replays without duplicate lead');
$lead=$db->query('SELECT source,attribution_json FROM lead_requests WHERE company_id=1')->fetch();
checked($lead['source']==='WEB_CHAT','source attribution uses WEB_CHAT');
$attribution=json_decode($lead['attribution_json'],true);
checked($attribution['utm_source']==='website'&&$attribution['conversation_id']===$first['conversation_id'],'campaign attribution retained');

$throttled=false;
for($i=5;$i<40;$i++){
 $v=evt(sprintf('event_site_%08d',$i),'rate test');
 try{WebsiteInbox::ingest($db,1,1,'site-main',$v,hash('sha256',(string)$i));}
 catch(OverflowException $e){$throttled=true;break;}
}
checked($throttled,'thread rate limit prevents excessive inbound messages');
echo "Website Inbox DB integration checks completed.\n";


echo "\nWebsite two-way chat delivery checks:\n";
$staff=['company_id'=>1,'id'=>1];
$key='reply_key_0000000001';
$draft=['conversation_id'=>$first['conversation_id'],'request_key'=>$key,'body'=>'Here is your Hanoi and Ha Long itinerary.'];
$sent=WebsiteChatDelivery::send($db,$staff,$draft);
checked($sent['status']==='QUEUED'&&!$sent['replayed'],'staff reply safely queued');
$retry=WebsiteChatDelivery::send($db,$staff,$draft);
checked($retry['replayed']&&$retry['id']===$sent['id'],'outbound send deduplicated by request key');
$changed=false;
try{WebsiteChatDelivery::send($db,$staff,array_replace($draft,['body'=>'Unrelated reply']));}
catch(DomainException $e){$changed=true;}
checked($changed,'outbound key reused with altered content rejected');
$valid=['action'=>'pull','timestamp'=>time(),'external_conversation_id'=>'visitor_site_000001','after_id'=>0];
$parsed=WebsiteChatDelivery::validateRelayEvent(json_encode($valid,JSON_THROW_ON_ERROR),time());
$pulled=WebsiteChatDelivery::relay($db,1,'site-main',$parsed);
checked(count($pulled['messages'])===1&&$pulled['messages'][0]['id']===$sent['id'],'trusted relay retrieves queued staff message');
$cursor=WebsiteChatDelivery::relay($db,1,'site-main',array_replace($parsed,['after_id'=>$sent['id']]));
checked(count($cursor['messages'])===0,'cursor avoids repeat delivery');
$wrongTenant=WebsiteChatDelivery::relay($db,2,'site-main',$parsed);
checked(count($wrongTenant['messages'])===0,'tenant cannot pull another tenant reply');
$wrongSource=WebsiteChatDelivery::relay($db,1,'site-landing',$parsed);
checked(count($wrongSource['messages'])===0,'website source cannot pull another website reply');
$ackRequest=['action'=>'ack','timestamp'=>time(),'external_conversation_id'=>'visitor_site_000001','message_ids'=>[$sent['id']]];
$ack=WebsiteChatDelivery::relay($db,1,'site-main',WebsiteChatDelivery::validateRelayEvent(json_encode($ackRequest,JSON_THROW_ON_ERROR),time()));
checked($ack['acknowledged']===1,'website backend acknowledges staff message receipt');
$ack2=WebsiteChatDelivery::relay($db,1,'site-main',WebsiteChatDelivery::validateRelayEvent(json_encode($ackRequest,JSON_THROW_ON_ERROR),time()));
checked($ack2['acknowledged']===0,'relay acknowledgement is idempotent');
checked($db->query('SELECT status FROM website_chat_outbound WHERE company_id=1')->fetchColumn()==='RELAYED','database status is RELAYED, not read');
$badAck=false;
try{
 $notValid=array_replace($ackRequest,['timestamp'=>time()-900]);
 WebsiteChatDelivery::validateRelayEvent(json_encode($notValid,JSON_THROW_ON_ERROR),time());
}catch(DomainException $e){$badAck=true;}
checked($badAck,'stale signed relay request rejected');
$invalidTimestamp=false;
try{
 $notValid=array_replace($valid,['timestamp'=>'not-an-integer']);
 WebsiteChatDelivery::validateRelayEvent(json_encode($notValid,JSON_THROW_ON_ERROR),time());
}catch(DomainException $e){$invalidTimestamp=true;}
checked($invalidTimestamp,'invalid relay timestamp rejected');
$db->prepare("UPDATE social_conversations SET status='CLOSED' WHERE company_id=1 AND id=?")->execute([$first['conversation_id']]);
$blocked=false;
try{WebsiteChatDelivery::send($db,$staff,array_replace($draft,['request_key'=>'reply_key_0000000002']));}
catch(DomainException $e){$blocked=true;}
checked($blocked,'closed conversation blocks new staff send');
echo "P2 two-way website relay database checks completed.\n";

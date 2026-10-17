<?php
declare(strict_types=1);
/** REAL CI-ONLY MariaDB integration: never stage/prod. */
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/MediaLibrary.php';
require_once __DIR__.'/../api/lib/B2CStudio.php';
require_once __DIR__.'/../api/lib/B2CStorefront.php';
require_once __DIR__.'/../api/lib/FreeformDocument.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/AdsLandingPresentation.php';
require_once __DIR__.'/../api/lib/AdsLandingPages.php';
$host=getenv('TEST_DB_HOST');$database=getenv('TEST_DB_DATABASE');
if($host!=='127.0.0.1'||$database!=='vta_ads_ci')throw new RuntimeException('FAIL_CLOSED: isolated ads CI DB only');
$db=new PDO('mysql:host='.$host.';port=3306;dbname='.$database.';charset=utf8mb4',
 (string)getenv('TEST_DB_USER'),(string)getenv('TEST_DB_PASSWORD'),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function ok(bool $yes,string $label):void{if(!$yes)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$create=[
"CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY)",
"CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL)",
"CREATE TABLE campaigns(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,source VARCHAR(80) NOT NULL,status VARCHAR(16) NOT NULL)",
"CREATE TABLE lead_forms(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,public_token CHAR(64) NOT NULL UNIQUE,status VARCHAR(16) NOT NULL)",
"CREATE TABLE b2c_programs(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,revision INT NOT NULL,approved_revision INT,status VARCHAR(16) NOT NULL)",
"CREATE TABLE b2c_publication_events(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,program_id BIGINT UNSIGNED NOT NULL,revision INT NOT NULL,status VARCHAR(16) NOT NULL,snapshot_json LONGTEXT NOT NULL,snapshot_hash CHAR(64) NOT NULL)",
"CREATE TABLE lead_submission_windows(form_id BIGINT UNSIGNED,window_start DATETIME NOT NULL,submissions INT NOT NULL,PRIMARY KEY(form_id,window_start))",
"CREATE TABLE lead_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,form_id BIGINT UNSIGNED NOT NULL,campaign_id BIGINT UNSIGNED NOT NULL,source VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'NEW',submission_key VARCHAR(80) NOT NULL,payload_hash CHAR(64) NOT NULL,contact_name VARCHAR(190) NOT NULL,email VARCHAR(190),phone VARCHAR(64),travel_date DATE,total_guests INT,paying_pax INT,foc INT,destination VARCHAR(500),hotel_level VARCHAR(32),message TEXT,attribution_json JSON,UNIQUE KEY company_idempotency(company_id,submission_key))",
"CREATE TABLE audit_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED,user_id BIGINT UNSIGNED NULL,action_code VARCHAR(80),entity_type VARCHAR(80),entity_id BIGINT UNSIGNED,before_json JSON NULL,after_json JSON NULL,request_id VARCHAR(64),ip_address VARCHAR(64))"
];
foreach($create as $sql)$db->exec($sql);
$sql=file_get_contents(__DIR__.'/../api/migrations/090_ads_landing_publish.sql');
if(!is_string($sql)||strlen($sql)<900)throw new RuntimeException('migration unavailable');
foreach(preg_split('/;\s*(?:\n|$)/',$sql) as $stmt)if(trim($stmt)!=='')$db->exec($stmt);
ok((int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vta_ads_ci' AND table_name LIKE 'ads_landing_%'")->fetchColumn()===3,'actual migration creates 3 ads tables');
$db->exec("INSERT INTO companies VALUES(7),(8)");
$db->exec("INSERT INTO users VALUES(5,7),(6,8)");
$db->exec("INSERT INTO campaigns VALUES(21,7,'Facebook campaign','ACTIVE'),(22,8,'Other','ACTIVE')");
$token=str_repeat('b',64);
$st=$db->prepare("INSERT INTO lead_forms VALUES(?,?,?,?,?)");$st->execute([31,7,21,$token,'ACTIVE']);$st->execute([32,8,22,str_repeat('c',64),'ACTIVE']);
$db->exec("INSERT INTO b2c_programs VALUES(41,7,2,2,'PUBLISHED'),(42,8,1,1,'PUBLISHED')");
$document=['schema'=>'VTA_DOC_2','title'=>'Reviewed Tour','content'=>['type'=>'doc','content'=>[
 ['type'=>'heading','attrs'=>['level'=>2],'content'=>[['type'=>'text','text'=>'Day 1 Hanoi']]],
 ['type'=>'paragraph','content'=>[['type'=>'text','text'=>'Approved Hanoi city tour']]]
]]];
$snapshot=['schema'=>'VTA_B2C_PUBLICATION_V1','title'=>'Vietnam 7 Days','destination'=>'Hanoi / Ha Long / Da Nang','language'=>'en','document'=>$document,'pricing'=>'REQUEST_QUOTE'];
$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$e=$db->prepare('INSERT INTO b2c_publication_events VALUES(?,?,?,?,?,?,?)');
$e->execute([52,7,41,2,'PUBLISHED',$json,hash('sha256',$json)]);
$e->execute([53,8,42,1,'PUBLISHED',$json,hash('sha256',$json)]);
$cfg=['app'=>['base_url'=>'https://rc62-staging.vietnamtraveladvisor.com.vn']];
$actor=['company_id'=>7,'id'=>5];
$landing=AdsLandingPages::create($db,$cfg,$actor,[
 'slug'=>'vietnam-7d6n-facebook','publication_event_id'=>52,'campaign_id'=>21,'lead_form_id'=>31,
 'layout'=>'brochure','public_content_reviewed'=>true,'default_utm_campaign'=>'vietnam_discovery']);
ok($landing['status']==='PUBLISHED'&&$landing['slug']==='vietnam-7d6n-facebook','publish stable slug for B2C reviewed content');
try{AdsLandingPages::create($db,$cfg,$actor,[
 'slug'=>'vietnam-7d6n-facebook','publication_event_id'=>52,'campaign_id'=>21,'lead_form_id'=>31,
 'layout'=>'brochure','public_content_reviewed'=>true]);throw new RuntimeException('duplicate slug accepted');}
catch(DomainException $er){ok(true,'globally unique slug enforced');}
try{AdsLandingPages::create($db,$cfg,$actor,[
 'slug'=>'vietnam-7d6n-other','publication_event_id'=>53,'campaign_id'=>21,'lead_form_id'=>31,
 'layout'=>'quick','public_content_reviewed'=>true]);throw new RuntimeException('cross tenant publication accepted');}
catch(OutOfBoundsException $er){ok(true,'cross-company publication rejected');}
$active=AdsLandingPages::live($db,'vietnam-7d6n-facebook');
ok((int)$active['company_id']===7&&(int)$active['publication_event_id']===52,'live GET binds company and current publication');
$utm=AdsLandingPages::attribution(['utm_source'=>'facebook','utm_medium'=>'paid_social','utm_campaign'=>'oct_2026','ad_id'=>'video_03'],$active);
AdsLandingPages::metric($db,(int)$active['id'],'VIEW',$utm);
AdsLandingPages::metric($db,(int)$active['id'],'VIEW',$utm);
AdsLandingPages::metric($db,(int)$active['id'],'CTA',$utm);
ok((int)$db->query("SELECT count_total FROM ads_landing_metrics WHERE event_type='VIEW'")->fetchColumn()===2,'aggregated UTM views counted');
$input=['consent'=>true,'request_id'=>'qa_ads_request_000001','contact_name'=>'STAGING QA Lead',
 'email'=>'qa-ads@example.invalid','phone'=>'','travel_date'=>'2026-12-01','total_guests'=>2,
 'hotel_level'=>'4-star','message'=>'Staging QA integration request','website'=>'','attribution'=>$utm];
$lead=B2CStorefront::lead($input);
$lead['destination']=$snapshot['destination'];$lead['hotel_level']='4-star';
$lead['submission_key']='ads_'.hash('sha256','7:'.$active['id'].':'.$input['request_id']);
$callback=static function(int $company,int $leadId)use($db,$active,$utm):void{
 AdsLandingPages::recordLead($db,'vietnam-7d6n-facebook',$company,$leadId,7,(int)$active['id'],52,$utm);
};
$result=LeadHub::submit($db,$token,$lead,$callback);
ok(($result['accepted']??false)===true,'real LeadHub submit accepted');
$row=$db->query('SELECT * FROM lead_requests WHERE company_id=7')->fetch();
ok(is_array($row)&&$row['status']==='NEW','real CRM Lead status NEW');
ok($row['hotel_level']==='4-star'&&(int)$row['total_guests']===2,'hotel category and guest count stored');
$attr=json_decode($row['attribution_json'],true);
ok($attr['utm_source']==='facebook'&&$attr['utm_campaign']==='oct_2026'&&$attr['ad_id']==='video_03','UTM campaign + ad attribution persisted');
ok((int)$db->query('SELECT COUNT(*) FROM ads_landing_lead_links')->fetchColumn()===1,'exact landing→lead mapping persisted');
ok((int)$db->query("SELECT count_total FROM ads_landing_metrics WHERE event_type='LEAD'")->fetchColumn()===1,'one conversion counted');
LeadHub::submit($db,$token,$lead,$callback);
ok((int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===1 &&
   (int)$db->query("SELECT count_total FROM ads_landing_metrics WHERE event_type='LEAD'")->fetchColumn()===1,'retry does not duplicate Lead or conversion');
$changed=$lead;$changed['paying_pax']=3;$changed['total_guests']=3;
try{LeadHub::submit($db,$token,$changed,$callback);throw new RuntimeException('mismatched payload retried');}
catch(DomainException $er){ok(true,'reused submission key with different content rejected');}
try{AdsLandingPages::recordLead($db,'vietnam-7d6n-facebook',8,(int)$row['id'],7,(int)$active['id'],52,$utm);throw new RuntimeException('cross-company attribution allowed');}
catch(OutOfBoundsException $er){ok(true,'cross-company Lead linkage rejected');}
$db->exec("UPDATE b2c_programs SET status='WITHDRAWN' WHERE id=41");
try{AdsLandingPages::live($db,'vietnam-7d6n-facebook');throw new RuntimeException('stale B2C pub still serves');}
catch(OutOfBoundsException $er){ok(true,'B2C withdrawn publication blocks advertising');}
$db->exec("UPDATE b2c_programs SET status='PUBLISHED' WHERE id=41");
AdsLandingPages::withdraw($db,$actor,(int)$active['id']);
try{AdsLandingPages::live($db,'vietnam-7d6n-facebook');throw new RuntimeException('withdrawn Ads URL serves');}
catch(OutOfBoundsException $er){ok(true,'withdraw Ads link revokes website and form');}
ok((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_code IN ('ADS_LANDING_PUBLISHED','ADS_LANDING_WITHDRAWN','LEAD_REQUEST_RECEIVED')")->fetchColumn()===3,'publish, lead, withdraw audit persisted');
echo "PASS ADS PUBLICATION + REAL CRM LEAD MARIADB CI (NOT STAGING)\n";

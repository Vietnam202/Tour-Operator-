<?php
declare(strict_types=1);
/**
 * REAL INSERT integration test, MariaDB/MySQL CI CONTAINER ONLY.
 * Never run against staging or production. TEST_DB_DATABASE must be vta_lead_ci.
 */
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/B2CStorefront.php';
$host=getenv('TEST_DB_HOST');$name=getenv('TEST_DB_DATABASE');
if($host!=='127.0.0.1'||$name!=='vta_lead_ci')throw new RuntimeException('FAIL_CLOSED: CI database only, never staging or production');
$dsn='mysql:host='.$host.';port=3306;dbname='.$name.';charset=utf8mb4';
$db=new PDO($dsn,(string)getenv('TEST_DB_USER'),(string)getenv('TEST_DB_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
function test(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAILED: '.$label);echo "PASS $label\n";}
$db->exec("CREATE TABLE campaigns(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT NOT NULL,source VARCHAR(80) NOT NULL,status VARCHAR(16) NOT NULL)");
$db->exec("CREATE TABLE lead_forms(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT NOT NULL,campaign_id INT NOT NULL,public_token CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,UNIQUE KEY token_unique(public_token))");
$db->exec("CREATE TABLE lead_submission_windows(form_id INT NOT NULL,window_start DATETIME NOT NULL,submissions INT NOT NULL,PRIMARY KEY(form_id,window_start))");
$db->exec("CREATE TABLE lead_requests(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT NOT NULL,form_id INT NOT NULL,campaign_id INT NOT NULL,source VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'NEW',
 submission_key VARCHAR(80) NOT NULL,payload_hash CHAR(64) NOT NULL,contact_name VARCHAR(190) NOT NULL,email VARCHAR(190),phone VARCHAR(64),travel_date DATE,total_guests INT,paying_pax INT,foc INT,destination VARCHAR(500),hotel_level VARCHAR(32),message TEXT,attribution_json JSON,UNIQUE KEY company_idempotency(company_id,submission_key))");
$db->exec("CREATE TABLE audit_logs(id INT AUTO_INCREMENT PRIMARY KEY,company_id INT,user_id INT NULL,action_code VARCHAR(80),entity_type VARCHAR(80),entity_id INT,before_json JSON NULL,after_json JSON NULL,request_id VARCHAR(64),ip_address VARCHAR(64))");
$db->exec("INSERT INTO campaigns(company_id,source,status) VALUES(7,'VTA Tour Share','ACTIVE'),(8,'Other Tenant','ACTIVE')");
$token=str_repeat('b',64);
$st=$db->prepare("INSERT INTO lead_forms(company_id,campaign_id,public_token,status) VALUES(7,1,?,'ACTIVE')");$st->execute([$token]);
$input=[
 'consent'=>true,'request_id'=>'qa_demo_20261017_001','contact_name'=>'STAGING QA Synthetic',
 'email'=>'qa-example@example.invalid','phone'=>'','travel_date'=>'2026-12-01','total_guests'=>2,
 'hotel_level'=>'4-star','message'=>'Test ONLY | source share 42 | master tour VTA707',
 'website'=>'','attribution'=>['utm_source'=>'vta_tour_share','utm_medium'=>'customer_link','utm_campaign'=>'tour_program_77','utm_content'=>'share_42']
];
$body=B2CStorefront::lead($input);$body['destination']='Da Nang – Ha Long – Phu Quoc';
$body['hotel_level']='4-star';$body['submission_key']='vta_share_'.hash('sha256','7:42:'.$input['request_id']);
$r=LeadHub::submit($db,$token,$body);
test(($r['accepted']??false)===true,'LeadHub accepted real MariaDB INSERT');
$row=$db->query("SELECT * FROM lead_requests WHERE company_id=7 LIMIT 1")->fetch();
test(is_array($row)&&$row['status']==='NEW','persisted Lead has NEW status');
test((int)$row['total_guests']===2&&$row['hotel_level']==='4-star','guest and hotel preference preserved');
$attr=json_decode($row['attribution_json'],true);
test($attr['utm_source']==='vta_tour_share'&&$attr['utm_content']==='share_42','share provenance stored');
test((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action_code='LEAD_REQUEST_RECEIVED'")->fetchColumn()===1,'audit event persisted');
$retry=LeadHub::submit($db,$token,$body);
test(($retry['accepted']??false)===true&&((int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn())===1,'same request retry idempotent');
$changed=$body;$changed['total_guests']=3;$changed['paying_pax']=3;
try{LeadHub::submit($db,$token,$changed);throw new RuntimeException('changed request reused key');}
catch(DomainException $e){test(true,'changed payload with same key rejected');}
try{B2CStorefront::lead([...$input,'consent'=>false]);throw new RuntimeException('missing consent accepted');}
catch(InvalidArgumentException $e){test(true,'no consent rejected');}
$db->exec("UPDATE lead_forms SET status='INACTIVE' WHERE company_id=7");
$second=[...$body,'submission_key'=>'vta_share_'.hash('sha256','7:42:new_random_222')];
try{LeadHub::submit($db,$token,$second);throw new RuntimeException('inactive form accepted');}
catch(OutOfBoundsException $e){test(true,'inactive lead form fails closed');}
test((int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===1,'no extra lead after negative tests');
echo "PASS MariaDB CI REAL INSERT + CRM NEW readback; staging NOT TESTED\n";

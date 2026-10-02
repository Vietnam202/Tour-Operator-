<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/MarketingStudio.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
function mkCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function mkReject(callable $fn,string $type):bool{try{$fn();}catch(Throwable $e){return $e instanceof $type;}return false;}
$base=['campaign_id'=>1,'channel'=>'Facebook','body'=>'Draft'];
$v=MarketingRules::content($base);mkCheck($v['planned_at']===null&&$v['topic']==='General','legacy AI handoff receives metadata defaults');
mkCheck(MarketingRules::planned('2026-12-31T16:30:00Z')==='2026-12-31 16:30:00','UTC planning is stored without server timezone conversion');
foreach(['2026-02-30T12:00:00Z','2026-01-01 12:00:00','2026-01-01T12:00:00+07:00'] as $bad)mkCheck(mkReject(fn()=>MarketingRules::planned($bad),InvalidArgumentException::class),'ambiguous or invalid planned date rejected');
foreach(['javascript:alert(1)','http://example.com/file','https://u:p@example.com/file'] as $bad)mkCheck(mkReject(fn()=>MarketingRules::metadata(['asset_url'=>$bad,'rights_note'=>'Owned']),InvalidArgumentException::class),'unsafe media reference rejected');
mkCheck(mkReject(fn()=>MarketingRules::metadata(['asset_url'=>'https://example.com/file']),InvalidArgumentException::class),'media reference requires rights note');
mkCheck(mkReject(fn()=>MarketingRules::content($base+['content_format'=>'BAD']),InvalidArgumentException::class),'unknown format rejected');
mkCheck(mkReject(fn()=>MarketingRules::batch(['request_key'=>'short','entries'=>[$base]]),InvalidArgumentException::class),'invalid idempotency key rejected');
mkCheck(mkReject(fn()=>MarketingRules::batch(['request_key'=>str_repeat('a',20),'entries'=>array_fill(0,31,$base)]),InvalidArgumentException::class),'oversize batch rejected');
mkCheck(MarketingRules::transition('DRAFT','submit')==='PENDING'&&MarketingRules::transition('PENDING','decision','APPROVED')==='APPROVED','valid review transitions');
mkCheck(mkReject(fn()=>MarketingRules::transition('DRAFT','decision','APPROVED'),DomainException::class),'unsubmitted content cannot be approved');

// Real PDO SQLite storage; translate only dialect syntax for sequential mutation checks.
class MkFixturePDO extends PDO {
 public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(['INSERT IGNORE',' FOR UPDATE','NOW()'],['INSERT OR IGNORE','','CURRENT_TIMESTAMP'],$query),$options);}
}
$db=new MkFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE campaigns(id INTEGER,company_id INTEGER,status TEXT); INSERT INTO campaigns VALUES(1,10,'ACTIVE'),(2,20,'ACTIVE'),(3,10,'ARCHIVED'); CREATE TABLE marketing_content(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,campaign_id INTEGER,channel TEXT,body TEXT,created_by INTEGER,topic TEXT,content_format TEXT,tags TEXT,asset_url TEXT,rights_note TEXT,planned_at TEXT,parent_content_id INTEGER,status TEXT DEFAULT 'DRAFT'); CREATE TABLE marketing_batches(company_id INTEGER,request_key TEXT,payload_hash TEXT,result_json TEXT,PRIMARY KEY(company_id,request_key));");
$u=['company_id'=>10,'id'=>7];$db->beginTransaction();$id=MarketingStudio::createContent($db,$u,$v);$db->commit();
mkCheck((int)$db->query('SELECT COUNT(*) FROM marketing_content')->fetchColumn()===1,'content inserted as real stored draft');
foreach([2,3] as $bad)mkCheck(mkReject(fn()=>MarketingStudio::createContent($db,$u,MarketingRules::content(array_merge($base,['campaign_id'=>$bad]))),OutOfBoundsException::class),'foreign or archived campaign cannot receive drafts');
mkCheck(mkReject(fn()=>MarketingStudio::createContent($db,$u,MarketingRules::content($base+['parent_content_id'=>999])),OutOfBoundsException::class),'missing revision parent rejected');
$batch=['request_key'=>'unique-demo-key-0001','entries'=>[$base,array_merge($base,['channel'=>'Instagram'])]];
$db->beginTransaction();$first=MarketingStudio::createBatch($db,$u,$batch);$db->commit();$db->beginTransaction();$again=MarketingStudio::createBatch($db,$u,$batch);$db->commit();
mkCheck($first['ids']===$again['ids']&&$again['replayed']&&(int)$db->query('SELECT COUNT(*) FROM marketing_content')->fetchColumn()===3,'batch replay returns same records without duplicates');
$conflict=$batch;$conflict['entries'][0]['body']='Different';$db->beginTransaction();$blocked=mkReject(fn()=>MarketingStudio::createBatch($db,$u,$conflict),DomainException::class);$db->rollBack();mkCheck($blocked,'same batch key with changed payload rejected');
$bad=$batch;$bad['request_key']='unique-demo-key-0002';$bad['entries'][1]['campaign_id']=2;$db->beginTransaction();$blocked=mkReject(fn()=>MarketingStudio::createBatch($db,$u,$bad),OutOfBoundsException::class);$db->rollBack();mkCheck($blocked&&(int)$db->query('SELECT COUNT(*) FROM marketing_content')->fetchColumn()===3,'failed multichannel batch rolls back all new drafts');
$db->exec("CREATE TABLE marketing_inbox(id INTEGER PRIMARY KEY,company_id INTEGER,campaign_id INTEGER,channel TEXT,contact_name TEXT,email TEXT,phone TEXT,message TEXT,lead_request_id INTEGER,status TEXT,version_no INTEGER DEFAULT 1,updated_at TEXT); INSERT INTO marketing_inbox(id,company_id,campaign_id,channel,contact_name,email,phone,message) VALUES(1,10,1,'Facebook','Sample customer','sample@example.invalid','','Original message'),(2,20,2,'Facebook','Other company','other@example.invalid','','Other message'); CREATE TABLE lead_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,campaign_id INTEGER,source TEXT,submission_key TEXT,payload_hash TEXT,contact_name TEXT,email TEXT,phone TEXT,travel_date TEXT,total_guests INTEGER,paying_pax INTEGER,foc INTEGER,destination TEXT,hotel_level TEXT,message TEXT,attribution_json TEXT,UNIQUE(company_id,submission_key));");
mkCheck(mkReject(fn()=>MarketingStudio::handoff($db,$u,['id'=>2]),OutOfBoundsException::class),'other company conversation cannot be handed off');
mkCheck(mkReject(fn()=>MarketingStudio::handoff($db,$u,['id'=>1,'total_guests'=>0]),InvalidArgumentException::class),'handoff requires confirmed positive guest counts');
$b=['id'=>1,'total_guests'=>6,'paying_pax'=>6,'foc'=>0,'contact_name'=>'Spoofed','email'=>'fake@example.invalid','message'=>'Changed'];
$db->beginTransaction();$r=MarketingStudio::handoff($db,$u,$b);$db->commit();$stored=$db->query('SELECT * FROM lead_requests')->fetch();
mkCheck($stored['contact_name']==='Sample customer'&&$stored['message']==='Original message'&&(int)$stored['campaign_id']===1,'handoff preserves stored contact, message and campaign attribution');
mkCheck(json_decode($stored['attribution_json'],true)['source_type']==='MANUAL_SOCIAL','manual social source clearly identified');
$db->beginTransaction();$r2=MarketingStudio::handoff($db,$u,$b);$db->commit();mkCheck($r2['request_id']===$r['request_id']&&$r2['replayed']&&(int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===1,'handoff replay creates no duplicate request');
echo "NOTE: SQLite sequential storage checks do not validate MariaDB DDL, locks, concurrency, HTTP sessions or publishing.\n";

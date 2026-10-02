<?php
declare(strict_types=1);
// Execute the production SQL against SQLite fixture tables. This is not MariaDB migration/E2E validation.
require_once __DIR__.'/../api/lib/WorkspaceCenter.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/CoreOS.php';
require_once __DIR__.'/../api/lib/ControlCenter.php';
final class Response extends RuntimeException {public function __construct(public array $body,public int $status){parent::__construct('HTTP '.$status);}}
final class Http {
    public static array $input=[];
    public static function json(array $body,int $status=200): never {throw new Response($body,$status);}
    public static function body(): array {return self::$input;}
}
final class Auth {
    public static array $permissions=[];
    public static function can(PDO $db,int $uid,string $p): bool {return in_array($p,self::$permissions,true);}
    public static function requirePermission(PDO $db,array $u,string $p): void {if(!self::can($db,(int)$u['id'],$p))Http::json(['ok'=>false,'error'=>'FORBIDDEN'],403);}
}
final class TracedPDO extends PDO {
    public array $sql=[];
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->sql[]=$query;return parent::prepare($query,$options);}
}
$checks=0;
function same(mixed $a,mixed $b,string $name): void {global $checks;if($a!==$b)throw new RuntimeException('FAIL '.$name.' expected '.var_export($b,true).' got '.var_export($a,true));$checks++;echo "PASS $name\n";}
function rejects(callable $f,string $class,string $name): void {try{$f();}catch(Throwable $e){same($e instanceof $class,true,$name);return;}throw new RuntimeException('FAIL accepted '.$name);}
function response(callable $f): Response {try{$f();}catch(Response $r){return $r;}throw new RuntimeException('No HTTP response');}
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO SQLite is required for this suite; no success is reported without executing SQL.');
$db=new TracedPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// CoreOS probes its baseline table through MySQL metadata; provide equivalent fixture metadata only.
$db->exec("ATTACH DATABASE ':memory:' AS information_schema;CREATE TABLE information_schema.tables(table_schema TEXT,table_name TEXT);INSERT INTO information_schema.tables VALUES('fixture','trips')");
$db->sqliteCreateFunction('DATABASE',fn()=>'fixture',0);
foreach([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,company_id INTEGER,full_name TEXT,status TEXT)',
 'CREATE TABLE lead_requests(id INTEGER PRIMARY KEY,company_id INTEGER,source TEXT,contact_name TEXT,destination TEXT,status TEXT,created_at TEXT,campaign_id INTEGER,attribution_json TEXT)',
 'CREATE TABLE leads(id INTEGER PRIMARY KEY,company_id INTEGER,request_id INTEGER)',
 'CREATE TABLE trips(id INTEGER PRIMARY KEY,company_id INTEGER,title TEXT,lead_contact_name TEXT,sales_owner_id INTEGER,trip_ref TEXT,lead_whatsapp TEXT,market TEXT,start_date TEXT,end_date TEXT,total_guests INTEGER,paying_pax INTEGER,foc INTEGER)',
 'CREATE TABLE inquiries(id INTEGER PRIMARY KEY,company_id INTEGER,trip_id INTEGER,inquiry_ref TEXT,status TEXT,next_action_due TEXT,created_at TEXT,updated_at TEXT)',
 'CREATE TABLE quotes(id INTEGER PRIMARY KEY,company_id INTEGER,trip_id INTEGER,quote_ref TEXT,status TEXT,current_version_no INTEGER,updated_at TEXT)',
 'CREATE TABLE quote_versions(id INTEGER PRIMARY KEY,quote_id INTEGER,version_no INTEGER,version_status TEXT,tour_name TEXT,sent_at TEXT)',
 'CREATE TABLE bookings(id INTEGER PRIMARY KEY,company_id INTEGER,booking_ref TEXT,lead_guest_name TEXT,operations_status TEXT,sales_owner_id INTEGER,operations_owner_id INTEGER,created_at TEXT,start_date TEXT,risk_level TEXT)',
 'CREATE TABLE tasks(id INTEGER PRIMARY KEY,company_id INTEGER,title TEXT,owner_user_id INTEGER,due_at TEXT,status TEXT,priority TEXT,created_at TEXT,entity_type TEXT,entity_id INTEGER)',
 'CREATE TABLE marketing_content(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT)'
] as $sql)$db->exec($sql);
$db->exec("INSERT INTO users VALUES(10,1,'Sales owner','ACTIVE'),(20,2,'Foreign owner','ACTIVE'),(30,1,'Colleague','ACTIVE')");
$insert=$db->prepare('INSERT INTO lead_requests VALUES(?,1,\'WEBSITE\',\'Guest\',\'Hanoi\',\'NEW\',?,NULL,\'{}\')');
for($i=1;$i<=305;$i++)$insert->execute([$i,'2026-10-01 09:00:00']);
$insert->execute([306,'2026-09-30 23:59:59']);$insert->execute([307,'2026-10-02 00:00:00']);
$db->exec("INSERT INTO lead_requests VALUES(308,1,'WEB','Qualified','Hanoi','QUALIFIED','2026-10-01 10:00:00',NULL,'{}'),(999,2,'WEB','Foreign','Hanoi','NEW','2026-10-01 10:00:00',NULL,'{}')");
$db->exec("INSERT INTO leads VALUES(1,1,1),(2,2,999)");
// Add the VS1 projection columns after legacy positional seed inserts; no production SQL is stubbed.
foreach(['email TEXT','phone TEXT','travel_date TEXT','total_guests INTEGER','paying_pax INTEGER','foc INTEGER','message TEXT','hotel_level TEXT','version_no INTEGER DEFAULT 1'] as $column)$db->exec('ALTER TABLE lead_requests ADD COLUMN '.$column);
foreach(['owner_user_id INTEGER','sales_owner_user_id INTEGER',"handover_status TEXT DEFAULT 'PENDING'",'handover_version INTEGER DEFAULT 1'] as $column)$db->exec('ALTER TABLE leads ADD COLUMN '.$column);
$db->exec('CREATE TABLE lead_sales_history(id INTEGER,company_id INTEGER,lead_id INTEGER,actor_user_id INTEGER,handover_version INTEGER)');
$db->exec("INSERT INTO trips(id,company_id,title,lead_contact_name,sales_owner_id) VALUES(1,1,'Hanoi tour','Guest',10),(2,2,'Private foreign tour','Foreign',20)");
$inq=$db->prepare('INSERT INTO inquiries VALUES(?,1,1,?, ?, ?,\'2026-09-01 08:00:00\',\'2026-10-01 09:00:00\')');
foreach([[1,'FOLLOW_UP','2026-10-01 12:00:00'],[2,'WORKING','2026-10-01 00:00:00'],[3,'SENT','2026-09-30 23:59:59'],[4,'CONFIRMED','2026-09-30 12:00:00'],[5,'LOST','2026-09-29 12:00:00'],[6,'CANCELLED','2026-09-29 12:00:00'],[7,'NEW','2026-10-02 00:00:00'],[8,'NEW',null],[9,'QUOTED','2026-10-01 23:59:59']] as [$id,$status,$due])$inq->execute([$id,'INQ-'.$id,$status,$due]);
$db->exec("INSERT INTO inquiries VALUES(999,2,2,'FOREIGN','FOLLOW_UP','2026-10-01 10:00:00','2026-09-01','2026-10-01'),(998,1,2,'BAD-LINK','FOLLOW_UP','2026-10-01 10:00:00','2026-09-01','2026-10-01')");
$q=$db->prepare("INSERT INTO quotes VALUES(?,1,1,?, ?,1,'2026-10-01 09:00:00')");$v=$db->prepare("INSERT INTO quote_versions VALUES(?,?,1,?,'Hanoi tour','2026-09-30 10:00:00')");
foreach([[10,'DRAFT','DRAFT'],[11,'SENT','SENT'],[12,'FOLLOW_UP','SENT'],[13,'LOST','SENT'],[14,'DRAFT','SENT'],[15,'APPROVED','APPROVED']] as [$id,$status,$vs]){$q->execute([$id,'QT-'.$id,$status]);$v->execute([$id,$id,$vs]);}
$db->exec("INSERT INTO quote_versions VALUES(115,15,2,'DRAFT','Historical noncurrent version',NULL);INSERT INTO quotes VALUES(999,2,2,'QT-FOREIGN','DRAFT',1,'2026-10-01');INSERT INTO quote_versions VALUES(999,999,1,'DRAFT','Foreign',NULL)");
$b=$db->prepare("INSERT INTO bookings VALUES(?,1,?,'Guest',?,10,10,?,?,?)");
foreach([[1,'READY','2026-10-01 09:00:00','2026-10-01','LOW'],[2,'CANCELLED','2026-10-01 10:00:00','2026-10-01','CRITICAL'],[3,'NEW_BOOKING','2026-09-30 10:00:00','2026-09-30','HIGH'],[4,'OPERATION_COMPLETED','2026-09-29 10:00:00','2026-10-01','HIGH'],[5,'ON_REQUEST','2026-09-28 10:00:00','2026-10-09','CRITICAL'],[6,'COMPLETED','2026-09-27 10:00:00','2026-10-01','HIGH'],[7,'READY','2026-10-02 00:00:00','2026-10-02','LOW']] as [$id,$status,$created,$date,$risk])$b->execute([$id,'BKG-'.$id,$status,$created,$date,$risk]);
$db->exec("INSERT INTO bookings VALUES(999,2,'FOREIGN','Foreign','READY',20,20,'2026-10-01','2026-10-01','CRITICAL')");
$task=$db->prepare("INSERT INTO tasks VALUES(?,1,?, ?, ?, ?, 'HIGH','2026-09-01','manual',0)");
foreach([[1,10,'OPEN','2026-10-01 23:59:59'],[2,10,'SNOOZED','2026-09-30 23:59:59'],[3,10,'DONE','2026-09-30'],[4,30,'OPEN','2026-09-30'],[5,10,'OPEN',null],[6,10,'OPEN','2026-10-02 00:00:00'],[7,10,'CANCELLED','2026-09-30']] as [$id,$owner,$status,$due])$task->execute([$id,'Task '.$id,$owner,$due,$status]);
$db->exec("INSERT INTO tasks VALUES(999,2,'Foreign task',10,'2026-09-30','OPEN','HIGH','2026-09-01','manual',0);INSERT INTO marketing_content VALUES(1,1,'PENDING'),(2,1,'PENDING'),(3,1,'APPROVED'),(999,2,'PENDING')");
$u=['id'=>10,'company_id'=>1];$now=new DateTimeImmutable('2026-10-01 15:00:00',new DateTimeZone('Asia/Ho_Chi_Minh'));
$db->sql=[];
Auth::$permissions=['lead.view','sales.view','booking.view','campaign.manage','operations.view','task.view'];
$s=WorkspaceCenter::sales($db,$u,[],$now);
same($s['metrics'],['new_leads'=>305,'need_qualification'=>307,'followup_today'=>3,'overdue_followup'=>1,'draft_quotes'=>2,'sent_quotes'=>1,'waiting_client'=>1,'confirmed_today'=>1,'lost'=>1],'nine counts follow real schema and exclude foreign records');
same($s['timezone'],'Asia/Ho_Chi_Minh','configured timezone returned');same($s['date_range']['end'],'2026-10-02 00:00:00','half-open day range excludes next midnight');
same(count($s['queues']['new_leads']['items']),6,'preview bounded to six rows while count exceeds legacy 300');
foreach(WorkspaceCenter::SALES_QUEUES as $key){$paged=WorkspaceCenter::sales($db,$u,['queue'=>$key,'limit'=>100,'offset'=>0],$now);same($paged['queues'][$key]['total'],$s['metrics'][$key],$key.' count matches filtered queue');same(count($paged['queues']),1,$key.' selected queue only');}
$tail=WorkspaceCenter::sales($db,$u,['queue'=>'new_leads','limit'=>10,'offset'=>300],$now);
same(count($tail['queues']['new_leads']['items']),5,'pagination reaches records beyond legacy limit');
same($tail['queues']['new_leads']['items'][4]['id'],1,'stable timestamp and id order keeps oldest record reachable');
same($tail['queues']['new_leads']['items'][4]['params'],['requestId'=>1],'lead request links use stable record id');
same($s['queues']['followup_today']['items'][0]['params'],['salesTab'=>'inquiries','inquiryId'=>2],'inquiry links target exact inquiry');
same($s['queues']['sent_quotes']['items'][0]['params'],['quoteId'=>11],'quote links target quote id');
same($s['queues']['confirmed_today']['items'][0]['params'],['bookingId'=>1],'confirmation links target actual booking');
same(str_contains($s['definitions']['confirmed_today'],'no immutable acceptance timestamp'),true,'confirmation proxy is explicitly described');
$home=WorkspaceCenter::home($db,$u,$now);
same($home['metrics'],['marketing_pending'=>2,'new_leads'=>305,'sales_followups_due'=>4,'departures_today'=>1,'bookings_at_risk'=>2,'my_tasks_due'=>2],'home metrics reflect scoped actionable data with no monetary totals');
same($home['attention']['operations']['total'],3,'operations attention uses union without duplicate bookings');
same(array_column($home['attention']['tasks']['items'],'id'),[2,1],'tasks owned by current user exclude done cancelled undated future and foreign');
same($home['attention']['sales']['total'],4,'today and earlier inquiry next actions included');
$writes=array_filter($db->sql,fn($sql)=>preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE)\b/i',$sql));same(count($writes),0,'dashboard production queries are read-only');
Auth::$permissions=['lead.view'];$start=count($db->sql);$restricted=WorkspaceCenter::sales($db,$u,[],$now);
same($restricted['metrics']['draft_quotes'],null,'denied quote count is unavailable not zero');same($restricted['queues']['draft_quotes']['items'],[],'denied quote rows omitted');same($restricted['queues']['draft_quotes']['available'],false,'denied queue explicitly unavailable');
same(count(array_filter(array_slice($db->sql,$start),fn($sql)=>str_contains($sql,'quotes q')||str_contains($sql,'bookings b')||str_contains($sql,'inquiries i'))),0,'denied sales and booking domains are never queried');
$restrictedHome=WorkspaceCenter::home($db,$u,$now);same($restrictedHome['metrics']['my_tasks_due'],null,'restricted home hides task count');same($restrictedHome['attention']['operations']['items'],[],'restricted home hides operations rows');
same($restrictedHome['access']['marketing'],true,'lead-view role can open the read-only Marketing workspace');same($restrictedHome['metrics']['marketing_pending'],2,'Marketing count follows existing lead-view GET permission');
Auth::$permissions=['campaign.manage'];$writeOnlyHome=WorkspaceCenter::home($db,$u,$now);
same($writeOnlyHome['access']['marketing'],false,'campaign-manage alone cannot open read-only Marketing API');same($writeOnlyHome['metrics']['marketing_pending'],null,'write permission does not imply Marketing read access');
Auth::$permissions=[];rejects(fn()=>WorkspaceCenter::sales($db,$u,[],$now),DomainException::class,'sales API rejects user without domain permission');
Auth::$permissions=['sales.view'];same(WorkspaceCenter::sales($db,$u,[],$now)['metrics']['new_leads'],null,'sales permission does not grant lead visibility');
Auth::$permissions=['booking.view'];same(WorkspaceCenter::sales($db,$u,[],$now)['metrics']['confirmed_today'],1,'booking-only role sees its one permitted sales queue');
Auth::$permissions=['lead.view','sales.view','booking.view'];
foreach([['queue'=>'anything'],['queue'=>['lost']],['limit'=>'0'],['limit'=>101],['offset'=>-1],['offset'=>['0']],['limit'=>'1 OR 1=1']] as $i=>$bad)rejects(fn()=>WorkspaceCenter::sales($db,$u,$bad,$now),InvalidArgumentException::class,'invalid queue/pagination rejected '.$i);
same(WorkspaceCenter::clock(new DateTimeImmutable('2026-10-01 23:59:59',new DateTimeZone('UTC')))['date_range']['today'],'2026-10-01','explicit clock keeps supplied timezone');
// The exact-id extensions open older records without increasing legacy list limits.
$_GET=['request_id'=>'1'];$r=response(fn()=>LeadHub::handle('lead-hub/requests','GET',$db,$u));same(array_column($r->body['items'],'id'),[1],'request id fetch returns oldest request beyond300');
$_GET=['request_id'=>'999'];$r=response(fn()=>LeadHub::handle('lead-hub/requests','GET',$db,$u));same($r->body['items'],[],'exact request lookup remains tenant scoped');
$_GET=['request_id'=>'1'];$r=response(fn()=>LeadHub::handle('lead-hub/leads','GET',$db,$u));same(array_column($r->body['items'],'request_id'),[1],'lead lookup follows exact request link');
$_GET=['request_id'=>'999'];$r=response(fn()=>LeadHub::handle('lead-hub/leads','GET',$db,$u));same($r->body['items'],[],'exact lead lookup remains tenant scoped');
$_GET=['request_id'=>['1']];same(response(fn()=>LeadHub::handle('lead-hub/requests','GET',$db,$u))->status,422,'request array id rejected');
$_GET=['id'=>'1'];$r=response(fn()=>CoreOS::handle('inquiries','GET',$db,[],$u));same(array_column($r->body['items'],'id'),[1],'inquiry id fetch returns exact inquiry');
$_GET=['id'=>'999'];$r=response(fn()=>CoreOS::handle('inquiries','GET',$db,[],$u));same($r->body['items'],[],'inquiry id lookup remains tenant scoped');
$_GET=['id'=>['1']];same(response(fn()=>CoreOS::handle('inquiries','GET',$db,[],$u))->status,422,'inquiry array id rejected');
Auth::$permissions=['task.view'];Http::$input=['title'=>'Invalid priority','priority'=>'LOW'];$_GET=[];
same(response(fn()=>ControlCenter::handle('tasks','POST',$db,$u))->body['error'],'INVALID_PRIORITY','LOW rejected because RC5 schema only NORMAL HIGH CRITICAL');
$control=ControlCenter::overview($db,$u);same(count($control['tasks']),4,'control tasks use existing OPEN SNOOZED enum states');
same(str_contains(end($db->sql),'IN_PROGRESS'),false,'control query does not invent IN_PROGRESS state');
echo "Workspace Center: $checks checks passed using actual SQLite SQL and permission doubles. MariaDB migrations and staging E2E were not run.\n";

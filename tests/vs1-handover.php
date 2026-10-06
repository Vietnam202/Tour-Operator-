<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI fixture only');
require_once __DIR__.'/../api/lib/Migrations.php';
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Auth.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/Customer360.php';

$count=0;
function v1check(bool $ok,string $name):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$name);$count++;echo "PASS $name\n";}
function v1reject(callable $fn,string $class,string $name):Throwable{try{$fn();}catch(Throwable $e){v1check($e instanceof $class,$name.' ('.get_class($e).')');return $e;}throw new RuntimeException('FAIL accepted '.$name);}
function v1query(PDO $db,string $sql,array $args=[]):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;}
function v1insert(PDO $db,string $table,array $row):int{$fields=array_keys($row);v1query($db,'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($row));return (int)$db->lastInsertId();}
function v1connect(string $dsn):PDO{
    if(!preg_match('/^mysql:host=127\.0\.0\.1;port=33317;dbname=vta_vs1_[a-f0-9]{8,32};charset=utf8mb4$/D',$dsn))throw new RuntimeException('VS1 requires an explicitly named isolated local vta_vs1_<random hex> database');
    $db=new PDO($dsn,getenv('VS1_TEST_MYSQL_USER')?:'root',getenv('VS1_TEST_MYSQL_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+07:00'");return $db;
}
function v1empty(PDO $db):void{if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fixture database must be empty; existing tables are never replaced');}
function v1key():string{return 'native-vs1-'.bin2hex(random_bytes(12));}
function v1fingerprint(PDO $db):string{$state=[];foreach(['lead_requests','leads','customers','trips','inquiries','tasks','lead_sales_history','domain_outbox','domain_execution_keys','audit_logs'] as $table)$state[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll();return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));}
function v1request(PDO $db,string $name,string $email='match@example.invalid',int $company=1):int{return v1insert($db,'lead_requests',['company_id'=>$company,'campaign_id'=>$company===1?1:null,'form_id'=>$company===1?1:null,'source'=>'FACEBOOK','submission_key'=>v1key(),'payload_hash'=>str_repeat('a',64),'contact_name'=>$name,'email'=>$email,'phone'=>'+84123456789','travel_date'=>'2027-02-10','total_guests'=>4,'paying_pax'=>3,'foc'=>1,'destination'=>'Hanoi','message'=>'Private conversation must not enter 360','attribution_json'=>'{"utm_campaign":"native-vs1","ad_id":"vs1-ad"}']);}
function v1qbody(int $owner=1):array{return ['qualification_note'=>'Dates and scope reviewed','owner_user_id'=>$owner,'next_action_due'=>'2027-02-01 12:00:00','expected_version'=>1,'action_key'=>v1key()];}
function v1abody(array $lead,int $owner=1):array{return ['expected_version'=>$lead['handover_version'],'action_key'=>v1key(),'sales_owner_user_id'=>$owner,'next_action_due'=>'2027-02-02 12:00:00'];}
function v1cbody(array $lead,array $review,int $customer=1):array{return ['expected_version'=>$lead['handover_version'],'action_key'=>v1key(),'identity_mode'=>'LINK_EXISTING','identity_reviewed'=>true,'identity_review_key'=>$review['identity_review_key'],'customer_id'=>$customer];}
function v1effect(PDO $db,int $lead,int $version,string $event):void{
    $e=v1query($db,"SELECT * FROM domain_outbox WHERE company_id=1 AND entity_type='lead' AND entity_id=? AND entity_version=? AND event_name=?",[$lead,$version,$event])->fetchAll();
    v1check(count($e)===1&&$e[0]['status']==='APPLIED','one committed internal '.$event.' outbox record');
    $effects=v1query($db,"SELECT k.task_id,k.execution_kind,t.id FROM domain_execution_keys k JOIN tasks t ON t.id=k.task_id AND t.company_id=k.company_id WHERE k.company_id=1 AND k.event_id=? AND k.execution_kind='TASK'",[$e[0]['id']])->fetchAll();
    v1check(count($effects)===1,'one shared task effect for '.$event);
}

$dsn=getenv('VS1_TEST_MYSQL_DSN')?:'';
if($dsn==='')throw new RuntimeException('Set VS1_TEST_MYSQL_DSN to a newly created empty local vta_vs1_<random hex> database, plus VS1_TEST_MYSQL_PASSWORD');
date_default_timezone_set('Asia/Ho_Chi_Minh');$db=v1connect($dsn);v1empty($db);
$migrationDir=__DIR__.'/../api/migrations';$baselineDir=getenv('VS1_TEST_BASELINE_DIR')?:__DIR__.'/../../VTA_Unified_OS_RC5_3/api/migrations';
$files=glob($migrationDir.'/*.sql')?:[];sort($files,SORT_STRING);$manifest=array_map(fn($p)=>basename($p,'.sql'),$files);
v1check(in_array('022_lead_sales_handover',$manifest,true),'current migration manifest includes additive VS1 handover');
$baseline=glob($baselineDir.'/*.sql')?:[];sort($baseline,SORT_STRING);
v1check(count($baseline)===21,'released baseline has all 21 migrations');
foreach($baseline as $file)v1check(is_file($migrationDir.'/'.basename($file))&&hash_file('sha256',$file)===hash_file('sha256',$migrationDir.'/'.basename($file)),'released migration bytes preserved: '.basename($file));
v1check(Migrations::run($db,$migrationDir)===$manifest,'fresh MariaDB installs complete current migration manifest');
v1check(Migrations::run($db,$migrationDir)===[],'fresh migration retry is a no-op');
$db->exec("INSERT INTO companies(code,name) VALUES('VS1','VS1 test'),('OTHER','Other tenant')");
$db->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'ADMIN','VS1 Admin'),(2,'ADMIN','Foreign'),(1,'READER','Reader')");
$db->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT 1,id FROM permissions');
$db->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT 2,id FROM permissions');
$db->exec("INSERT INTO role_permissions(role_id,permission_id) SELECT 3,id FROM permissions WHERE code='sales.view'");
$db->exec("INSERT INTO users(company_id,role_id,full_name,email,password_hash,status) VALUES(1,1,'Tester','vs1@example.invalid','unused','ACTIVE'),(2,2,'Foreign','foreign@example.invalid','unused','ACTIVE'),(1,1,'Inactive','inactive@example.invalid','unused','INACTIVE'),(1,3,'Read only','reader@example.invalid','unused','ACTIVE'),(1,1,'Other actor','other-actor@example.invalid','unused','ACTIVE')");
$db->exec("INSERT INTO campaigns(company_id,name,source,created_by) VALUES(1,'Native VS1 campaign','FACEBOOK',1)");
$db->exec("INSERT INTO lead_forms(company_id,campaign_id,name,public_token,created_by) VALUES(1,1,'Native VS1 form',REPEAT('d',64),1)");
$customerBase=['company_id'=>1,'customer_ref'=>'V1-MATCH','full_name'=>'Name shared by unrelated travelers','email'=>'match@example.invalid','whatsapp'=>'+84123456789','market'=>'VN','sales_owner_id'=>1,'created_by'=>1,'updated_by'=>1];
v1insert($db,'customers',$customerBase);
$sameName=v1insert($db,'customers',array_replace($customerBase,['customer_ref'=>'V1-SAME-NAME','email'=>'unrelated@example.invalid','whatsapp'=>'+84999999999']));
$foreignCustomer=v1insert($db,'customers',array_replace($customerBase,['company_id'=>2,'customer_ref'=>'V1-FOREIGN','sales_owner_id'=>2,'created_by'=>2,'updated_by'=>2]));
$inactiveCustomer=v1insert($db,'customers',array_replace($customerBase,['customer_ref'=>'V1-INACTIVE','status'=>'INACTIVE']));
$foreignAgent=v1insert($db,'agents',['company_id'=>2,'agent_ref'=>'V1-FOREIGN-A','company_name'=>'Foreign agent','created_by'=>2,'updated_by'=>2]);
$inactiveAgent=v1insert($db,'agents',['company_id'=>1,'agent_ref'=>'V1-INACTIVE-A','company_name'=>'Inactive agent','status'=>'INACTIVE','created_by'=>1,'updated_by'=>1]);
$user=['id'=>1,'company_id'=>1];$other=['id'=>5,'company_id'=>1];$foreign=['id'=>2,'company_id'=>2];$reader=['id'=>4,'company_id'=>1];
$rid=v1request($db,$customerBase['full_name']);$requestBefore=v1query($db,'SELECT source,campaign_id,form_id,payload_hash,attribution_json,message,email,phone FROM lead_requests WHERE id=?',[$rid])->fetch();
$before=v1fingerprint($db);
foreach([2,3] as $owner)v1reject(fn()=>LeadHub::qualify($db,$user,$rid,v1qbody($owner)),InvalidArgumentException::class,'foreign/inactive qualification owner rejected');
v1reject(fn()=>LeadHub::qualify($db,$foreign,$rid,v1qbody(2)),OutOfBoundsException::class,'foreign company cannot qualify request');
v1reject(fn()=>LeadHub::qualify($db,$reader,$rid,v1qbody()),LeadSalesPermissionException::class,'sales.view alone cannot qualify');
v1check(v1fingerprint($db)===$before,'failed qualification preflight leaves no partial records/effects');
$qualifyWait=v1connect($dsn);$qualifyWait->exec('SET innodb_lock_wait_timeout=1');$db->beginTransaction();v1query($db,'SELECT id FROM lead_requests WHERE id=? FOR UPDATE',[$rid]);
$e=v1reject(fn()=>LeadHub::qualify($qualifyWait,$user,$rid,v1qbody()),PDOException::class,'concurrent qualification waits on request lock');
v1check(($e->errorInfo[1]??null)===1205,'native InnoDB lock timeout proves qualification serialization');$db->rollBack();v1check(!$qualifyWait->inTransaction()&&v1fingerprint($db)===$before,'timed-out qualification rolls back without partial lead/effects');
$db->exec("CREATE TRIGGER vs1_fail_task BEFORE INSERT ON tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional VS1 qualification task failure'");
v1reject(fn()=>LeadHub::qualify($db,$user,$rid,v1qbody()),PDOException::class,'task failure aborts qualification transaction');$db->exec('DROP TRIGGER vs1_fail_task');v1check(v1fingerprint($db)===$before,'failed qualification rolls back lead/request revision/history/outbox/task/audit');
$qb=v1qbody();$lead=LeadHub::qualify($db,$user,$rid,$qb);v1check($lead['handover_status']==='PENDING'&&(int)$lead['request_version']===2,'qualification persists pending handover and request revision');v1effect($db,$lead['id'],1,'lead.qualified');
$before=v1fingerprint($db);v1check(LeadHub::qualify($db,$user,$rid,$qb)===$lead,'exact qualification retry returns same receipt');v1check(v1fingerprint($db)===$before,'qualification retry creates no duplicated task/event/history');
v1reject(fn()=>LeadHub::qualify($db,$other,$rid,$qb),DomainException::class,'command receipt cannot be reused by another actor');
v1reject(fn()=>LeadHub::qualify($db,$user,$rid,array_replace($qb,['qualification_note'=>'Changed payload'])),DomainException::class,'same action key with changed content conflicts');
v1reject(fn()=>LeadHub::qualify($db,$user,$rid,array_replace($qb,['action_key'=>v1key()])),DomainException::class,'stale new qualification key cannot create another lead');
v1reject(fn()=>LeadHub::convert($db,$user,$lead['id'],['expected_version'=>1,'action_key'=>v1key()]),DomainException::class,'unaccepted handover cannot convert');
v1reject(fn()=>LeadSalesHandover::customerCandidates($db,$user,$lead['id']),DomainException::class,'identity review cannot precede Sales acceptance');
$ab=v1abody($lead);v1reject(fn()=>LeadSalesHandover::accept($db,$user,$lead['id'],array_replace($ab,['sales_owner_user_id'=>2])),InvalidArgumentException::class,'accept rejects foreign owner');
$lead=LeadSalesHandover::accept($db,$user,$lead['id'],$ab);v1check($lead['handover_status']==='ACCEPTED'&&$lead['handover_version']===2,'acceptance advances handover once');v1effect($db,$lead['id'],2,'lead.sales_accepted');
$before=v1fingerprint($db);v1check(LeadSalesHandover::accept($db,$user,$lead['id'],$ab)===$lead,'acceptance retry returns same receipt');v1check(v1fingerprint($db)===$before,'acceptance replay adds no effects');
v1reject(fn()=>LeadSalesHandover::returnLead($db,$user,$lead['id'],['expected_version'=>1,'action_key'=>v1key(),'reason'=>'Stale decision']),DomainException::class,'stale handover version rejects a new decision');
$review=LeadSalesHandover::customerCandidates($db,$user,$lead['id']);$ids=array_column($review['items'],'id');v1check($ids===[1],'exact contacts suggest one same-company active identity; name alone never matches');
$cb=v1cbody($lead,$review);$before=v1fingerprint($db);
foreach([['identity_reviewed'=>false],['identity_review_key'=>'invalid'],['customer_id'=>$foreignCustomer],['customer_id'=>$inactiveCustomer],['agent_id'=>$foreignAgent],['agent_id'=>$inactiveAgent]] as $patch)v1reject(fn()=>LeadHub::convert($db,$user,$lead['id'],array_replace($cb,$patch,['action_key'=>v1key()])),($patch['identity_review_key']??'')==='invalid'?DomainException::class:InvalidArgumentException::class,'reviewed identity and tenant link preflight rejected');
v1reject(fn()=>LeadHub::convert($db,$other,$lead['id'],array_replace($cb,['action_key'=>v1key()])),DomainException::class,'identity review nonce cannot transfer to another actor');
v1check(v1fingerprint($db)===$before,'invalid identity/agent selections create no partial customer/trip/inquiry/effect');

// A second native connection really waits on the business parent, then times out and retries.
$second=v1connect($dsn);$second->exec('SET innodb_lock_wait_timeout=1');$isolation=$second->query('SELECT @@session.tx_isolation')->fetchColumn();
$db->beginTransaction();v1query($db,'SELECT id FROM lead_requests WHERE id=? FOR UPDATE',[$rid]);
$e=v1reject(fn()=>LeadHub::convert($second,$user,$lead['id'],$cb),PDOException::class,'concurrent conversion is serialized by request parent lock');
v1check(($e->errorInfo[1]??null)===1205,'native InnoDB lock wait timeout proves second conversion waited');$db->rollBack();
v1check(!$second->inTransaction(),'timed-out conversion rolls back its transaction');v1check(v1fingerprint($db)===$before,'lock timeout leaves no downstream/effect rows');
v1check($second->query('SELECT @@session.tx_isolation')->fetchColumn()===$isolation,'handover transaction isolation does not alter connection default');

// Failure injected after customer/trip/inquiry inserts must roll the whole command back.
$db->exec("CREATE TRIGGER vs1_fail_task BEFORE INSERT ON tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional VS1 task failure'");
v1reject(fn()=>LeadHub::convert($db,$user,$lead['id'],$cb),PDOException::class,'injected task persistence failure aborts conversion');
$db->exec('DROP TRIGGER vs1_fail_task');v1check(v1fingerprint($db)===$before,'failed conversion rolls back trip/inquiry/stage/outbox/audit/command effects');
v1check(v1query($db,'SELECT used_at FROM lead_identity_reviews WHERE review_key=?',[$review['identity_review_key']])->fetchColumn()===null,'rollback leaves identity review nonce reusable');
$converted=LeadHub::convert($second,$user,$lead['id'],$cb);
v1check((int)$converted['customer_id']===1&&(int)$converted['trip_id']>0&&(int)$converted['inquiry_id']>0,'same command succeeds after lock/failure rollback with existing customer');
v1effect($db,$lead['id'],3,'lead.converted');
$before=v1fingerprint($db);v1check(LeadHub::convert($db,$user,$lead['id'],$cb)===$converted,'conversion retry uses exact original command result');
$old=LeadHub::convert($db,$user,$lead['id']);v1check($old['trip_id']===$converted['trip_id']&&$old['inquiry_id']===$converted['inquiry_id'],'historical no-body conversion replay preserves existing identities');v1check(v1fingerprint($db)===$before,'conversion retries never create new customer/trip/inquiry/tasks');
v1check((int)$db->query('SELECT COUNT(*) FROM trips')->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM inquiries')->fetchColumn()===1,'one opportunity identity downstream');
v1check(v1query($db,'SELECT source,campaign_id,form_id,payload_hash,attribution_json,message,email,phone FROM lead_requests WHERE id=?',[$rid])->fetch()===$requestBefore,'request source attribution and original contacts remain byte-preserved');
$trip=v1query($db,'SELECT * FROM trips WHERE id=?',[$converted['trip_id']])->fetch();v1check((int)$trip['total_guests']===4&&(int)$trip['paying_pax']===3&&(int)$trip['foc']===1,'shared trip retains upstream guest counts');
v1check((int)v1query($db,"SELECT COUNT(*) FROM tasks WHERE entity_type='inquiry' AND entity_id=? AND rule_code='INQUIRY_NEXT_ACTION'",[$converted['inquiry_id']])->fetchColumn()===1,'conversion creates exactly one next-action task for shared inquiry');

$rid2=v1request($db,'Returned lead');$l2=LeadHub::qualify($db,$user,$rid2,v1qbody());$return=['expected_version'=>$l2['handover_version'],'action_key'=>v1key(),'reason'=>'Travel dates need human verification'];
$before=v1fingerprint($db);v1reject(fn()=>LeadSalesHandover::returnLead($db,$user,$l2['id'],array_replace($return,['reason'=>''])),InvalidArgumentException::class,'return requires recorded reason');v1check(v1fingerprint($db)===$before,'invalid return has no effect');
$l2=LeadSalesHandover::returnLead($db,$user,$l2['id'],$return);v1check($l2['handover_status']==='RETURNED','Sales can return pending MQL');v1effect($db,$l2['id'],2,'lead.sales_returned');
$before=v1fingerprint($db);v1check(LeadSalesHandover::returnLead($db,$user,$l2['id'],$return)===$l2,'return command replay returns receipt');v1check(v1fingerprint($db)===$before,'return retry does not duplicate effects');
v1reject(fn()=>LeadSalesHandover::accept($db,$user,$l2['id'],v1abody($l2)),DomainException::class,'returned lead requires resubmission before Sales acceptance');
$resubmit=['expected_version'=>$l2['handover_version'],'action_key'=>v1key(),'qualification_note'=>'Verified revised dates','owner_user_id'=>1,'next_action_due'=>'2027-02-03 10:00:00'];
$l2=LeadSalesHandover::resubmit($db,$user,$l2['id'],$resubmit);v1check($l2['handover_status']==='PENDING','Marketing resubmits same lead as pending');v1effect($db,$l2['id'],3,'lead.resubmitted');
$before=v1fingerprint($db);v1check(LeadSalesHandover::resubmit($db,$user,$l2['id'],$resubmit)===$l2,'resubmit retry returns receipt');v1check(v1fingerprint($db)===$before,'resubmit retry does not duplicate effects');
$l2=LeadSalesHandover::accept($db,$user,$l2['id'],v1abody($l2));$review2=LeadSalesHandover::customerCandidates($db,$user,$l2['id']);
v1query($db,"UPDATE lead_identity_reviews SET expires_at='2000-01-01 00:00:00' WHERE review_key=?",[$review2['identity_review_key']]);$before=v1fingerprint($db);v1reject(fn()=>LeadHub::convert($db,$user,$l2['id'],v1cbody($l2,$review2)),DomainException::class,'expired review nonce cannot convert');v1check(v1fingerprint($db)===$before,'expired identity review leaves business state untouched');
$review2=LeadSalesHandover::customerCandidates($db,$user,$l2['id']);v1query($db,'UPDATE customers SET full_name=?,version_no=version_no+1 WHERE id=1',['Reviewed contact changed']);$before=v1fingerprint($db);v1reject(fn()=>LeadHub::convert($db,$user,$l2['id'],v1cbody($l2,$review2)),DomainException::class,'identity candidate changes require refreshed human review');v1check(v1fingerprint($db)===$before,'candidate conflict inserts no partial opportunity');
$review2=LeadSalesHandover::customerCandidates($db,$user,$l2['id']);$new=['expected_version'=>$l2['handover_version'],'action_key'=>v1key(),'identity_mode'=>'CREATE_NEW','identity_reviewed'=>true,'identity_review_key'=>$review2['identity_review_key'],'new_customer'=>['full_name'=>'Distinct traveler using shared family contact','email'=>'new@example.invalid']];
v1reject(fn()=>LeadHub::convert($db,$user,$l2['id'],$new),InvalidArgumentException::class,'new identity despite match needs explicit duplicate reason');$new['duplicate_reason']='Reviewed separate traveler sharing family contact';
$before=v1fingerprint($db);$db->exec("CREATE TRIGGER vs1_fail_task BEFORE INSERT ON tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional VS1 new-customer task failure'");
v1reject(fn()=>LeadHub::convert($db,$user,$l2['id'],$new),PDOException::class,'late task failure aborts new-customer conversion');$db->exec('DROP TRIGGER vs1_fail_task');v1check(v1fingerprint($db)===$before,'failed CREATE_NEW rolls back new customer and all downstream effects');
$customerCount=(int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();$newConversion=LeadHub::convert($db,$user,$l2['id'],$new);v1check((int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn()===$customerCount+1,'reviewed new customer uses shared customer master once');v1check(LeadHub::convert($db,$user,$l2['id'],$new)===$newConversion,'CREATE_NEW retry reuses one identity');

$rid3=v1request($db,'Same name only','nameonly@example.invalid');v1query($db,'UPDATE lead_requests SET phone=? WHERE id=?',['+84000077777',$rid3]);$l3=LeadHub::qualify($db,$user,$rid3,v1qbody());$l3=LeadSalesHandover::accept($db,$user,$l3['id'],v1abody($l3));$review3=LeadSalesHandover::customerCandidates($db,$user,$l3['id']);v1check($review3['items']===[],'unrelated same name never creates a customer merge candidate');
$noReview=v1cbody($l3,$review3,$sameName);v1reject(fn()=>LeadHub::convert($db,$user,$l3['id'],$noReview),InvalidArgumentException::class,'nonmatching existing identity requires recorded identity reason');
$noReview['identity_reason']='Reviewed existing customer ID against offline evidence';$sameNameConversion=LeadHub::convert($db,$user,$l3['id'],$noReview);v1check($sameNameConversion['customer_id']===$sameName,'explicit reason can link an existing identity without automatic name merge');

$projection=Customer360::read($db,$reader,1);v1check($projection['sections']['inquiries']['available']&&$projection['sections']['quotes']['available'],'Customer360 reuses shared Sales projection');
foreach(['leads','bookings','tasks','documents','finance'] as $name)v1check($projection['sections'][$name]['available']===false&&$projection['sections'][$name]['items']===[]&&$projection['sections'][$name]['total']===null,'Customer360 redacts '.$name.' for sales.view-only role');
v1reject(fn()=>Customer360::read($db,$user,$foreignCustomer),OutOfBoundsException::class,'Customer360 scoped foreign ID returns no data');
v1query($db,"INSERT INTO user_permissions(user_id,permission_id,effect) SELECT 1,id,'DENY' FROM permissions WHERE code='lead.sales_accept'");
$rid4=v1request($db,'Denied admin');$l4=LeadHub::qualify($db,$user,$rid4,v1qbody());$before=v1fingerprint($db);v1reject(fn()=>LeadSalesHandover::accept($db,$user,$l4['id'],v1abody($l4)),LeadSalesPermissionException::class,'ADMIN label cannot bypass effective Sales acceptance DENY');v1check(v1fingerprint($db)===$before,'explicit permission denial has no business side effects');

// Upgrade fixture uses another explicitly supplied empty local database; no destructive reset.
$upgradeDsn=getenv('VS1_UPGRADE_MYSQL_DSN')?:'';
if($upgradeDsn!==''){
    $up=v1connect($upgradeDsn);v1empty($up);Migrations::run($up,$baselineDir);
    $up->exec("INSERT INTO companies(code,name) VALUES('OLD','Existing company')");$up->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'SALES','Existing Sales')");$up->exec("INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,1,'Existing sales','legacy@example.invalid','preserve-this-password-hash')");
    $up->exec("INSERT INTO role_permissions(role_id,permission_id) SELECT 1,id FROM permissions WHERE code IN ('lead.view','lead.manage','inquiry.manage')");
    $up->exec("INSERT INTO trips(company_id,trip_ref,title,created_by,updated_by) VALUES(1,'OLD-TRIP','Historical trip',1,1)");$up->exec("INSERT INTO inquiries(company_id,inquiry_ref,trip_id,created_by,updated_by) VALUES(1,'OLD-INQ',1,1,1)");
    $oldReq=v1insert($up,'lead_requests',['company_id'=>1,'source'=>'FACEBOOK','submission_key'=>v1key(),'payload_hash'=>str_repeat('a',64),'contact_name'=>'Historical converted lead','email'=>'legacy@example.invalid','total_guests'=>4,'paying_pax'=>3,'foc'=>1,'attribution_json'=>'{"utm_campaign":"preserved"}','status'=>'QUALIFIED']);
    $up->exec("INSERT INTO leads(company_id,request_id,owner_user_id,qualification_note,inquiry_id,status,qualified_by,converted_at) VALUES(1,$oldReq,1,'Historical qualification',1,'CONVERTED',1,'2020-01-01 00:00:00')");
    $beforeUser=$up->query('SELECT * FROM users')->fetchAll();$beforeRequest=$up->query('SELECT * FROM lead_requests')->fetchAll();$beforeTrip=$up->query('SELECT * FROM trips')->fetchAll();$beforeInquiry=$up->query('SELECT * FROM inquiries')->fetchAll();
    v1check(Migrations::run($up,$migrationDir)===array_values(array_filter($manifest,fn($version)=>(int)$version>=22)),'RC5.3-to-current upgrade applies the exact additive manifest from 022');
    v1check($up->query('SELECT * FROM users')->fetchAll()===$beforeUser&&$up->query('SELECT * FROM trips')->fetchAll()===$beforeTrip&&$up->query('SELECT * FROM inquiries')->fetchAll()===$beforeInquiry,'upgrade preserves users/passwords and historic downstream identities');
    $newRequest=$up->query('SELECT * FROM lead_requests')->fetchAll();foreach($newRequest as &$row)unset($row['version_no']);unset($row);v1check($newRequest===$beforeRequest,'upgrade preserves request attribution fields');
    v1check($up->query("SELECT handover_status FROM leads WHERE inquiry_id=1")->fetchColumn()==='CONVERTED','upgrade marks only existing converted links without inventing acceptance history');
    v1check((int)$up->query('SELECT COUNT(*) FROM lead_sales_history')->fetchColumn()===0&&(int)$up->query('SELECT COUNT(*) FROM domain_outbox')->fetchColumn()===0&&(int)$up->query('SELECT COUNT(*) FROM tasks')->fetchColumn()===0,'upgrade does not invent history/events/tasks for old conversions');
    v1check(Migrations::run($up,$migrationDir)===[],'upgraded migration retry is a no-op');
    $retry=LeadHub::convert($up,['id'=>1,'company_id'=>1],1);v1check($retry['inquiry_id']===1&&$retry['trip_id']===1,'legacy conversion retry remains readable after additive upgrade');
}else echo "SKIP dedicated RC5.3 upgrade fixture: set VS1_UPGRADE_MYSQL_DSN to another empty local fixture database.\n";
echo "VS1 native handover checks complete: $count passed assertions.\n";

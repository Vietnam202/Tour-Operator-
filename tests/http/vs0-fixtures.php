<?php
declare(strict_types=1);
// Disposable local HTTP test data only; never permits production or remote database configuration.
$configPath=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/runtime/http-config.php';
if(!is_file($configPath))throw new RuntimeException('Prepare the disposable HTTP database first.');
$config=require $configPath;$c=$config['db'];
if(($config['app']['env']??'')!=='testing'||$c['host']!=='127.0.0.1'||(int)$c['port']!==33317||!str_starts_with($c['database'],'vta_http_'))throw new RuntimeException('VS0 fixtures require the private disposable local vta_http_ database.');
date_default_timezone_set($config['app']['timezone']);
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+07:00'");
$manifestPath=dirname($configPath).'/vs0-http-fixtures.json';
function vfQuery(PDO $db,string $sql,array $args=[]): PDOStatement {$s=$db->prepare($sql);$s->execute($args);return $s;}
function vfInsert(PDO $db,string $table,array $row): int {$fields=array_keys($row);vfQuery($db,'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($row));return (int)$db->lastInsertId();}
function vfFingerprint(PDO $db): string {
    $out=[];
    foreach(['customer_invoices','customer_receipts','supplier_payables','supplier_payments','supplier_orders'] as $table)$out[$table]=vfQuery($db,"SELECT * FROM $table WHERE company_id=1 ORDER BY id")->fetchAll();
    $out['allocations']=vfQuery($db,'SELECT a.* FROM payment_allocations a JOIN customer_invoices i ON i.id=a.invoice_id WHERE i.company_id=1 ORDER BY a.id')->fetchAll();
    $out['services']=vfQuery($db,'SELECT s.id,s.booking_id,s.supplier_id,s.planned_cost,s.confirmed_cost,s.actual_cost,s.cost_currency,s.booking_status FROM booking_services s JOIN bookings b ON b.id=s.booking_id WHERE b.company_id=1 ORDER BY s.id')->fetchAll();
    $out['sent']=vfQuery($db,'SELECT s.* FROM quote_sent_bundles s JOIN quotes q ON q.id=(SELECT quote_id FROM quote_versions WHERE id=s.quote_version_id) WHERE q.company_id=1 ORDER BY s.quote_version_id')->fetchAll();
    return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
}
if(($argv[1]??'')==='verify'){
    $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    $count=(int)vfQuery($db,'SELECT COUNT(*) FROM customers WHERE company_id=1 AND full_name LIKE ?',[$manifest['invalid_prefix'].'%'])->fetchColumn();
    $count+=(int)vfQuery($db,'SELECT COUNT(*) FROM trips WHERE company_id=1 AND lead_contact_name LIKE ?',[$manifest['invalid_prefix'].'%'])->fetchColumn();
    if($count!==0)throw new RuntimeException('Invalid CRM links inserted partial customer/trip records.');
    if(!hash_equals($manifest['financial_fingerprint'],vfFingerprint($db)))throw new RuntimeException('Closed writer attempts changed financial rows, service costs or immutable sent bundles.');
    echo "PASS database verification: rejected CRM requests inserted no partial records; closed financial writers left ledger and sent snapshots unchanged.\n";exit;
}
$suffix=bin2hex(random_bytes(4));$users=[];$hash=password_hash('Local-Http-Test-2026',PASSWORD_DEFAULT);
$admin=(int)vfQuery($db,"SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.company_id=1 AND r.code='ADMIN' AND u.email='admin@example.invalid'")->fetchColumn();$adminRole=(int)vfQuery($db,'SELECT role_id FROM users WHERE id=?',[$admin])->fetchColumn();
if(!$admin)throw new RuntimeException('Run setup.php and the full HTTP chain first.');
$db->beginTransaction();
foreach(['lead-only'=>['lead.view'],'campaign-only'=>['campaign.manage'],'task-only'=>['task.view'],'booking-only'=>['booking.view'],'sales-only'=>['sales.view'],'report-only'=>['report.view']] as $name=>$permissions){
    $role=vfInsert($db,'roles',['company_id'=>1,'code'=>'VS0_'.$suffix.'_'.str_replace('-','_',$name),'name'=>'VS0 '.$name]);
    foreach($permissions as $permission){$p=(int)vfQuery($db,'SELECT id FROM permissions WHERE code=?',[$permission])->fetchColumn();if(!$p)throw new RuntimeException('Missing permission '.$permission);vfInsert($db,'role_permissions',['role_id'=>$role,'permission_id'=>$p]);}
    $email=$name.'-'.$suffix.'@example.invalid';$uid=vfInsert($db,'users',['company_id'=>1,'role_id'=>$role,'full_name'=>'VS0 '.$name,'email'=>$email,'password_hash'=>$hash]);$users[$name]=['id'=>$uid,'email'=>$email];
}
$email='admin-deny-'.$suffix.'@example.invalid';$denyId=vfInsert($db,'users',['company_id'=>1,'role_id'=>$adminRole,'full_name'=>'VS0 ADMIN with explicit denies','email'=>$email,'password_hash'=>$hash]);$users['admin-deny']=['id'=>$denyId,'email'=>$email];
foreach(['lead.view','sales.view','booking.view','campaign.manage','operations.view','task.view','profit.view'] as $permission)vfQuery($db,"INSERT INTO user_permissions(user_id,permission_id,effect) SELECT ?,id,'DENY' FROM permissions WHERE code=?",[$denyId,$permission]);
$foreignCompany=vfInsert($db,'companies',['code'=>'VS0_FOREIGN_'.$suffix,'name'=>'VS0 foreign tenant']);$foreignRole=vfInsert($db,'roles',['company_id'=>$foreignCompany,'code'=>'ADMIN','name'=>'Foreign Admin']);$foreignUser=vfInsert($db,'users',['company_id'=>$foreignCompany,'role_id'=>$foreignRole,'full_name'=>'Foreign active owner','email'=>'foreign-'.$suffix.'@example.invalid','password_hash'=>$hash]);
$inactiveUser=vfInsert($db,'users',['company_id'=>1,'role_id'=>$adminRole,'full_name'=>'Inactive owner','email'=>'inactive-'.$suffix.'@example.invalid','password_hash'=>$hash,'status'=>'INACTIVE']);
$foreignCustomer=vfInsert($db,'customers',['company_id'=>$foreignCompany,'customer_ref'=>'VS0-CUS-'.$suffix,'full_name'=>'Foreign active customer','sales_owner_id'=>$foreignUser,'created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$inactiveCustomer=vfInsert($db,'customers',['company_id'=>1,'customer_ref'=>'VS0-INACTIVE-CUS-'.$suffix,'full_name'=>'Inactive customer','status'=>'INACTIVE','sales_owner_id'=>$admin,'created_by'=>$admin,'updated_by'=>$admin]);
$foreignAgent=vfInsert($db,'agents',['company_id'=>$foreignCompany,'agent_ref'=>'VS0-AGT-'.$suffix,'company_name'=>'Foreign active agent','created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$inactiveAgent=vfInsert($db,'agents',['company_id'=>1,'agent_ref'=>'VS0-INACTIVE-AGT-'.$suffix,'company_name'=>'Inactive agent','status'=>'INACTIVE','created_by'=>$admin,'updated_by'=>$admin]);
$trip=vfInsert($db,'trips',['company_id'=>1,'trip_ref'=>'VS0-TRIP-'.$suffix,'title'=>'Focused old inquiry','lead_contact_name'=>'Focused old inquiry','sales_owner_id'=>$admin,'created_by'=>$admin,'updated_by'=>$admin]);
$foreignTrip=vfInsert($db,'trips',['company_id'=>$foreignCompany,'trip_ref'=>'VS0-FOREIGN-'.$suffix,'title'=>'Private foreign inquiry','lead_contact_name'=>'Private foreign inquiry','sales_owner_id'=>$foreignUser,'created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$today=new DateTimeImmutable('today');$start=$today->format('Y-m-d 00:00:00');$end=$today->modify('+1 day')->format('Y-m-d 00:00:00');
$oldInquiry=vfInsert($db,'inquiries',['company_id'=>1,'inquiry_ref'=>'VS0-OLD-INQ-'.$suffix,'trip_id'=>$trip,'status'=>'WORKING','next_action_due'=>$end,'created_by'=>$admin,'updated_by'=>$admin]);
for($i=1;$i<=301;$i++)vfInsert($db,'inquiries',['company_id'=>1,'inquiry_ref'=>'VS0-INQ-'.$suffix.'-'.$i,'trip_id'=>$trip,'status'=>'WORKING','next_action_due'=>$start,'created_by'=>$admin,'updated_by'=>$admin]);
$foreignInquiry=vfInsert($db,'inquiries',['company_id'=>$foreignCompany,'inquiry_ref'=>'VS0-FOREIGN-INQ-'.$suffix,'trip_id'=>$foreignTrip,'status'=>'WORKING','next_action_due'=>$start,'created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$requestBase=['company_id'=>1,'source'=>'WEBSITE','payload_hash'=>str_repeat('a',64),'contact_name'=>'VS0 lead','total_guests'=>1,'paying_pax'=>1,'foc'=>0,'destination'=>'Hanoi','attribution_json'=>'{}'];
$oldRequest=vfInsert($db,'lead_requests',$requestBase+['submission_key'=>'VS0-OLD-'.$suffix,'status'=>'QUALIFIED','created_at'=>$today->modify('-1 day')->format('Y-m-d 00:00:00')]);
$oldLead=vfInsert($db,'leads',['company_id'=>1,'request_id'=>$oldRequest,'owner_user_id'=>$users['lead-only']['id'],'qualification_note'=>'Explicitly qualified fixture','qualified_by'=>$admin]);
for($i=1;$i<=305;$i++)vfInsert($db,'lead_requests',$requestBase+['submission_key'=>'VS0-'.$suffix.'-'.$i,'status'=>'NEW','created_at'=>$today->modify('+'.$i.' seconds')->format('Y-m-d H:i:s')]);
vfInsert($db,'lead_requests',$requestBase+['submission_key'=>'VS0-NEXT-DAY-'.$suffix,'status'=>'NEW','created_at'=>$end]);
$foreignRequest=vfInsert($db,'lead_requests',array_replace($requestBase,['company_id'=>$foreignCompany,'contact_name'=>'Private foreign lead','submission_key'=>'VS0-FOREIGN-'.$suffix,'status'=>'NEW','created_at'=>$start]));
vfInsert($db,'leads',['company_id'=>$foreignCompany,'request_id'=>$foreignRequest,'owner_user_id'=>$foreignUser,'qualification_note'=>'Foreign qualified fixture','qualified_by'=>$foreignUser]);
$campaign=vfInsert($db,'campaigns',['company_id'=>1,'name'=>'VS0 pending marketing','source'=>'WEBSITE','created_by'=>$admin]);$foreignCampaign=vfInsert($db,'campaigns',['company_id'=>$foreignCompany,'name'=>'Foreign pending marketing','source'=>'WEBSITE','created_by'=>$foreignUser]);
for($i=1;$i<=2;$i++)vfInsert($db,'marketing_content',['company_id'=>1,'campaign_id'=>$campaign,'channel'=>'FACEBOOK','body'=>'VS0 pending item','status'=>'PENDING','created_by'=>$admin]);
for($i=1;$i<=3;$i++)vfInsert($db,'marketing_content',['company_id'=>$foreignCompany,'campaign_id'=>$foreignCampaign,'channel'=>'FACEBOOK','body'=>'Private foreign item','status'=>'PENDING','created_by'=>$foreignUser]);
$taskBase=['company_id'=>1,'title'=>'VS0 task','entity_type'=>'manual','entity_id'=>0,'owner_user_id'=>$users['task-only']['id'],'due_at'=>$start,'priority'=>'NORMAL','status'=>'OPEN','source'=>'MANUAL'];
$ownedTasks=[];foreach([[],['status'=>'SNOOZED'],['status'=>'DONE'],['due_at'=>null],['due_at'=>$end],['owner_user_id'=>$admin],['company_id'=>$foreignCompany]] as $i=>$patch){$id=vfInsert($db,'tasks',array_replace($taskBase,$patch,['title'=>'VS0 task '.$i]));if($i<2)$ownedTasks[]=$id;}
$foreignBooking=vfInsert($db,'bookings',['company_id'=>$foreignCompany,'booking_ref'=>'VS0-FOREIGN-BKG-'.$suffix,'trip_id'=>$foreignTrip,'lead_guest_name'=>'Private foreign booking','start_date'=>$today->format('Y-m-d'),'risk_level'=>'HIGH','created_by'=>$foreignUser,'updated_by'=>$foreignUser,'sales_owner_id'=>$foreignUser,'operations_owner_id'=>$foreignUser]);
$db->commit();
$manifest=['users'=>$users,'foreign_user'=>$foreignUser,'inactive_user'=>$inactiveUser,'foreign_customer'=>$foreignCustomer,'inactive_customer'=>$inactiveCustomer,'foreign_agent'=>$foreignAgent,'inactive_agent'=>$inactiveAgent,'old_request_id'=>$oldRequest,'old_lead_id'=>$oldLead,'foreign_request_id'=>$foreignRequest,'old_inquiry_id'=>$oldInquiry,'foreign_inquiry_id'=>$foreignInquiry,'foreign_booking_id'=>$foreignBooking,'task_due_ids'=>$ownedTasks,'invalid_prefix'=>'VS0_INVALID_'.$suffix,
 'expected_new_leads'=>(int)vfQuery($db,"SELECT COUNT(*) FROM lead_requests WHERE company_id=1 AND status='NEW' AND created_at>=? AND created_at<?",[$start,$end])->fetchColumn(),
 'expected_need_qualification'=>(int)vfQuery($db,"SELECT COUNT(*) FROM lead_requests WHERE company_id=1 AND status='NEW'")->fetchColumn(),
 'expected_confirmed_today'=>(int)vfQuery($db,"SELECT COUNT(*) FROM bookings WHERE company_id=1 AND operations_status<>'CANCELLED' AND created_at>=? AND created_at<?",[$start,$end])->fetchColumn(),
 'financial_fingerprint'=>vfFingerprint($db)];
file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "VS0 HTTP fixtures prepared: scoped roles, explicit ADMIN denies, foreign/inactive CRM links, more than 300 lead requests and inquiry records.\n";

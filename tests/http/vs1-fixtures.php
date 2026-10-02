<?php
declare(strict_types=1);
// CLI-only fixtures are confined to the task's disposable local HTTP database.
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI fixture only');
$configPath=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/runtime/http-config.php';
if(!is_file($configPath))throw new RuntimeException('Prepare the disposable HTTP database first');
$config=require $configPath;$c=$config['db'];
if(($config['app']['env']??'')!=='testing'||$c['host']!=='127.0.0.1'||(int)$c['port']!==33317||!preg_match('/^vta_http_[a-f0-9]{8,32}$/D',$c['database']))throw new RuntimeException('VS1 fixtures require the private disposable local vta_http_ database');
date_default_timezone_set($config['app']['timezone']);
$db=new PDO('mysql:host=127.0.0.1;port=33317;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("SET time_zone='+07:00'");
$manifestPath=dirname($configPath).'/vs1-http-fixtures.json';
function v1q(PDO $db,string $sql,array $args=[]):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;}
function v1i(PDO $db,string $table,array $row):int{$fields=array_keys($row);v1q($db,'INSERT INTO '.$table.'('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($row));return (int)$db->lastInsertId();}
if(($argv[1]??'')==='counts'){
    $data=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);$counts=[];
    foreach(['customers','trips','inquiries','tasks','lead_sales_history','domain_outbox','domain_execution_keys'] as $table)$counts[$table]=(int)v1q($db,"SELECT COUNT(*) FROM $table WHERE company_id=1")->fetchColumn();
    $counts['leads']=(int)v1q($db,'SELECT COUNT(*) FROM leads WHERE company_id=1')->fetchColumn();
    echo json_encode($counts,JSON_THROW_ON_ERROR);exit;
}
$suffix=bin2hex(random_bytes(4));$hash=password_hash('Local-Http-Test-2026',PASSWORD_DEFAULT);
$admin=(int)v1q($db,"SELECT id FROM users WHERE company_id=1 AND email='admin@example.invalid'")->fetchColumn();
if(!$admin)throw new RuntimeException('Run the local HTTP setup first');
$adminRole=(int)v1q($db,'SELECT role_id FROM users WHERE id=?',[$admin])->fetchColumn();
$db->beginTransaction();$users=[];
foreach([
    'lead-manager'=>['lead.view','lead.manage'],
    'sales-accept'=>['lead.view','lead.sales_accept'],
    'sales-read'=>['sales.view'],
    'crm-reader'=>['sales.view','lead.view','booking.view','task.view','travel_document.view'],
    'finance-one'=>['sales.view','finance.view'],
    'finance-full'=>['sales.view','finance.view','customer_ar.view'],
    'convert-no-create'=>['lead.view','lead.manage','inquiry.manage','sales.view'],
    'lead-read'=>['lead.view']
] as $name=>$perms){
    $role=v1i($db,'roles',['company_id'=>1,'code'=>'V1_'.$suffix.'_'.str_replace('-','_',$name),'name'=>'VS1 '.$name]);
    foreach($perms as $code){$pid=(int)v1q($db,'SELECT id FROM permissions WHERE code=?',[$code])->fetchColumn();if(!$pid)throw new RuntimeException('Missing VS1 permission '.$code);v1i($db,'role_permissions',['role_id'=>$role,'permission_id'=>$pid]);}
    $email='vs1-'.$name.'-'.$suffix.'@example.invalid';$id=v1i($db,'users',['company_id'=>1,'role_id'=>$role,'full_name'=>'VS1 '.$name,'email'=>$email,'password_hash'=>$hash]);$users[$name]=['id'=>$id,'email'=>$email];
}
$denyEmail='vs1-admin-deny-'.$suffix.'@example.invalid';$deny=v1i($db,'users',['company_id'=>1,'role_id'=>$adminRole,'full_name'=>'VS1 ADMIN explicit deny','email'=>$denyEmail,'password_hash'=>$hash]);$users['admin-deny']=['id'=>$deny,'email'=>$denyEmail];
foreach(['lead.sales_accept','sales.view','booking.view','task.view','travel_document.view','finance.view','customer_ar.view'] as $code)v1q($db,"INSERT INTO user_permissions(user_id,permission_id,effect) SELECT ?,id,'DENY' FROM permissions WHERE code=?",[$deny,$code]);
$foreignCompany=v1i($db,'companies',['code'=>'VS1_OTHER_'.$suffix,'name'=>'VS1 foreign company']);
$foreignRole=v1i($db,'roles',['company_id'=>$foreignCompany,'code'=>'ADMIN','name'=>'VS1 foreign Admin']);
$foreignUser=v1i($db,'users',['company_id'=>$foreignCompany,'role_id'=>$foreignRole,'full_name'=>'Foreign owner','email'=>'vs1-foreign-'.$suffix.'@example.invalid','password_hash'=>$hash]);
$inactiveUser=v1i($db,'users',['company_id'=>1,'role_id'=>$adminRole,'full_name'=>'Inactive owner','email'=>'vs1-inactive-'.$suffix.'@example.invalid','password_hash'=>$hash,'status'=>'INACTIVE']);
$customer=v1i($db,'customers',['company_id'=>1,'customer_ref'=>'V1-C-'.$suffix,'full_name'=>'Same name needs review','email'=>'vs1-match-'.$suffix.'@example.invalid','whatsapp'=>'+840099'.$suffix,'market'=>'VN','notes'=>'VS1_PRIVATE_PROFILE_NOTES','sales_owner_id'=>$admin,'created_by'=>$admin,'updated_by'=>$admin]);
$sameName=v1i($db,'customers',['company_id'=>1,'customer_ref'=>'V1-NAME-'.$suffix,'full_name'=>'Same name needs review','email'=>'other-'.$suffix.'@example.invalid','whatsapp'=>'+840088'.$suffix,'sales_owner_id'=>$admin,'created_by'=>$admin,'updated_by'=>$admin]);
$foreignCustomer=v1i($db,'customers',['company_id'=>$foreignCompany,'customer_ref'=>'V1-FOREIGN-'.$suffix,'full_name'=>'Foreign private customer','email'=>'vs1-match-'.$suffix.'@example.invalid','whatsapp'=>'+840099'.$suffix,'created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$inactiveCustomer=v1i($db,'customers',['company_id'=>1,'customer_ref'=>'V1-INACTIVE-'.$suffix,'full_name'=>'Inactive customer','email'=>'vs1-match-'.$suffix.'@example.invalid','status'=>'INACTIVE','created_by'=>$admin,'updated_by'=>$admin]);
$agent=v1i($db,'agents',['company_id'=>1,'agent_ref'=>'V1-A-'.$suffix,'company_name'=>'VS1 active agent','created_by'=>$admin,'updated_by'=>$admin]);
$foreignAgent=v1i($db,'agents',['company_id'=>$foreignCompany,'agent_ref'=>'V1-FOREIGN-A-'.$suffix,'company_name'=>'VS1 foreign agent','created_by'=>$foreignUser,'updated_by'=>$foreignUser]);
$inactiveAgent=v1i($db,'agents',['company_id'=>1,'agent_ref'=>'V1-INACTIVE-A-'.$suffix,'company_name'=>'VS1 inactive agent','status'=>'INACTIVE','created_by'=>$admin,'updated_by'=>$admin]);
$campaign=v1i($db,'campaigns',['company_id'=>1,'name'=>'VS1 attributed campaign','source'=>'FACEBOOK','created_by'=>$admin]);
$formToken=bin2hex(random_bytes(32));$form=v1i($db,'lead_forms',['company_id'=>1,'campaign_id'=>$campaign,'name'=>'VS1 public form','public_token'=>$formToken,'created_by'=>$admin]);
$requestBase=['company_id'=>1,'campaign_id'=>$campaign,'form_id'=>$form,'source'=>'FACEBOOK','payload_hash'=>str_repeat('a',64),'contact_name'=>'Same name needs review','email'=>'vs1-match-'.$suffix.'@example.invalid','phone'=>'+840099'.$suffix,'total_guests'=>4,'paying_pax'=>3,'foc'=>1,'travel_date'=>'2027-02-10','destination'=>'Hanoi','message'=>'VS1_PRIVATE_CONVERSATION','attribution_json'=>'{"utm_campaign":"VS1_ATTRIBUTION","ad_id":"VS1_AD"}'];
$requests=[];foreach(['main','return','new-customer','same-name','invalid','rollback','nonce'] as $name){$row=$requestBase+['submission_key'=>'V1-'.$name.'-'.$suffix];if($name==='same-name'){$row['email']='nameonly-'.$suffix.'@example.invalid';$row['phone']='+840077'.$suffix;}$requests[$name]=v1i($db,'lead_requests',$row);}
$foreignRequest=v1i($db,'lead_requests',array_replace($requestBase,['company_id'=>$foreignCompany,'campaign_id'=>null,'form_id'=>null,'submission_key'=>'V1-foreign-'.$suffix,'contact_name'=>'Private foreign lead']));
// Coherent shared customer records for independent projection privacy checks.
$trip=v1i($db,'trips',['company_id'=>1,'trip_ref'=>'V1-360-'.$suffix,'title'=>'VS1 permitted journey','customer_id'=>$customer,'sales_owner_id'=>$admin,'created_by'=>$admin,'updated_by'=>$admin]);
$inquiry=v1i($db,'inquiries',['company_id'=>1,'inquiry_ref'=>'V1-360-INQ-'.$suffix,'trip_id'=>$trip,'request_text'=>'VS1_PRIVATE_INQUIRY_TEXT','next_action'=>'Prepare proposal','next_action_due'=>'2027-02-01 10:00:00','created_by'=>$admin,'updated_by'=>$admin]);
$quote=v1i($db,'quotes',['company_id'=>1,'quote_ref'=>'V1-360-QT-'.$suffix,'trip_id'=>$trip,'inquiry_id'=>$inquiry,'created_by'=>$admin,'updated_by'=>$admin]);
v1i($db,'quote_versions',['quote_id'=>$quote,'version_no'=>1,'tour_name'=>'VS1 visible quote','cost_json'=>'{"secret":"VS1_PRIVATE_QUOTE_NOTES"}','created_by'=>$admin]);
$booking=v1i($db,'bookings',['company_id'=>1,'booking_ref'=>'V1-360-BK-'.$suffix,'trip_id'=>$trip,'quote_id'=>$quote,'lead_guest_name'=>'VS1 visible traveler','confirmed_selling'=>'100.00','special_requests'=>'VS1_PRIVATE_BOOKING_NOTES','created_by'=>$admin,'updated_by'=>$admin]);
v1i($db,'guests',['booking_id'=>$booking,'full_name'=>'VS1 private passenger','passport_number'=>'VS1_SECRET_PASSPORT','notes'=>'VS1_PRIVATE_GUEST_NOTES']);
$doc=v1i($db,'travel_documents',['company_id'=>1,'booking_id'=>$booking,'kind'=>'TRAVEL_PACK','created_by'=>$admin]);
v1i($db,'travel_document_versions',['document_id'=>$doc,'version_no'=>1,'status'=>'ISSUED','public_snapshot_json'=>'{"passport":"VS1_SECRET_DOCUMENT_SNAPSHOT"}','content_hash'=>str_repeat('b',64),'created_by'=>$admin,'issued_by'=>$admin,'issued_at'=>'2026-10-01 10:00:00']);
v1i($db,'tasks',['company_id'=>1,'title'=>'VS1 visible next action','entity_type'=>'inquiry','entity_id'=>$inquiry,'owner_user_id'=>$users['crm-reader']['id'],'due_at'=>'2027-02-01 10:00:00','source'=>'MANUAL']);
v1i($db,'tasks',['company_id'=>1,'title'=>'VS1_OTHER_OWNER_TASK','entity_type'=>'inquiry','entity_id'=>$inquiry,'owner_user_id'=>$admin,'due_at'=>'2027-02-01 10:00:00','source'=>'MANUAL']);
foreach([
    ['currency'=>'USD','total'=>'100.00','paid_amount'=>'25.00','balance'=>'75.00','status'=>'PART_PAID','invoice_type'=>'PROFORMA'],
    ['currency'=>'VND','total'=>'500000.00','paid_amount'=>'100000.00','balance'=>'400000.00','status'=>'PART_PAID','invoice_type'=>'CUSTOMER_INVOICE'],
    ['currency'=>'USD','total'=>'800.00','paid_amount'=>'0.00','balance'=>'800.00','status'=>'DRAFT','invoice_type'=>'PROFORMA'],
    ['currency'=>'USD','total'=>'900.00','paid_amount'=>'0.00','balance'=>'900.00','status'=>'CANCELLED','invoice_type'=>'PROFORMA'],
    ['currency'=>'USD','total'=>'250.00','paid_amount'=>'250.00','balance'=>'0.00','status'=>'PAID','invoice_type'=>'RECEIPT'],
    ['currency'=>'USD','total'=>'60.00','paid_amount'=>'0.00','balance'=>'60.00','status'=>'ISSUED','invoice_type'=>'CREDIT_NOTE']
] as $i=>$row)v1i($db,'customer_invoices',$row+['company_id'=>1,'invoice_ref'=>'V1-360-INV-'.$suffix.'-'.$i,'booking_id'=>$booking,'notes'=>'VS1_PRIVATE_PAYMENT_NOTES','bank_details'=>'VS1_SECRET_BANK','created_by'=>$admin]);
v1i($db,'customer_receipts',['company_id'=>1,'party_kind'=>'CUSTOMER','party_id'=>$customer,'receipt_ref'=>'V1-RCPT-'.$suffix,'payment_date'=>'2026-10-01','amount'=>'25.00','currency'=>'USD','transaction_reference'=>'VS1_SECRET_BANK_TRANSACTION','idempotency_key'=>'V1-RCPT-'.$suffix,'payload_hash'=>str_repeat('c',64),'recorded_by'=>$admin]);
v1i($db,'audit_logs',['company_id'=>1,'user_id'=>$admin,'action_code'=>'VS1_PRIVATE_AUDIT','entity_type'=>'customer','entity_id'=>$customer,'after_json'=>'{"secret":"VS1_SECRET_AUDIT_JSON"}']);
$db->commit();
$manifest=['suffix'=>$suffix,'users'=>$users,'requests'=>$requests,'customer'=>$customer,'same_name_customer'=>$sameName,'foreign_customer'=>$foreignCustomer,'inactive_customer'=>$inactiveCustomer,'agent'=>$agent,'foreign_agent'=>$foreignAgent,'inactive_agent'=>$inactiveAgent,'foreign_user'=>$foreignUser,'inactive_user'=>$inactiveUser,'foreign_request'=>$foreignRequest,'campaign'=>$campaign,'form_token'=>$formToken,'email'=>$requestBase['email'],'phone'=>$requestBase['phone'],'trip'=>$trip,'inquiry'=>$inquiry,'quote'=>$quote,'booking'=>$booking,'document'=>$doc];
file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "VS1 local HTTP fixtures prepared: scoped roles, explicit deny, tenant links, reviewed identity candidates and permission-projection canaries.\n";

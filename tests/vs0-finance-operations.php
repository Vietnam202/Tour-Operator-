<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/FinanceLedger.php';
require_once __DIR__.'/../api/lib/InvoiceCommercial.php';
require_once __DIR__.'/../api/lib/Procurement.php';
require_once __DIR__.'/../api/lib/OperationsControl.php';

$count=0;
function vs0Check(bool $ok,string $label): void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
function vs0Reject(callable $fn,string $class,string $label): void {try{$fn();}catch(Throwable $e){vs0Check($e instanceof $class,$label.' ('.get_class($e).')');return;}throw new RuntimeException('FAIL '.$label.' accepted');}
$dsn=getenv('VS0_TEST_MYSQL_DSN')?:'sqlite::memory:';$mysql=str_starts_with($dsn,'mysql:');
if($mysql&&!preg_match('/^mysql:host=(?:127\.0\.0\.1|localhost);port=\d{2,5};dbname=vta_vs0_[a-f0-9]{8,32};charset=utf8mb4$/D',$dsn))throw new RuntimeException('MariaDB fixture requires an explicitly named empty local vta_vs0_<random hex> database');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$db=new PDO($dsn,$mysql?(getenv('VS0_TEST_MYSQL_USER')?:'root'):null,$mysql?(getenv('VS0_TEST_MYSQL_PASSWORD')?:''):null,$options);
if($mysql){if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fixture database must be empty; existing tables are never replaced');}
else $db->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
$identity=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
$schemas=[
 'trips'=>"id $identity,company_id INTEGER,customer_id INTEGER,agent_id INTEGER",
 'users'=>"id $identity,company_id INTEGER,status VARCHAR(20),full_name VARCHAR(100)",
 'bookings'=>"id $identity,company_id INTEGER,booking_ref VARCHAR(64),trip_id INTEGER,quote_id INTEGER,confirmed_quote_version_no INTEGER,lead_guest_name VARCHAR(100),lead_whatsapp VARCHAR(64),lead_email VARCHAR(100),start_date VARCHAR(10),end_date VARCHAR(10),total_guests INTEGER,paying_pax INTEGER,foc INTEGER DEFAULT 0,selling_currency VARCHAR(3) DEFAULT 'USD',confirmed_selling VARCHAR(40) DEFAULT '100.00',operations_status VARCHAR(30),readiness_pct INTEGER DEFAULT 0,risk_level VARCHAR(10) DEFAULT 'LOW',operations_owner_id INTEGER DEFAULT 1,finance_closed_at VARCHAR(30),operation_completed_at VARCHAR(30),customer_payment_status VARCHAR(30),supplier_payment_status VARCHAR(30)",
 'booking_services'=>"id $identity,booking_id INTEGER,service_ref VARCHAR(40),category VARCHAR(20),service_name VARCHAR(100),service_date VARCHAR(10),start_time VARCHAR(8),end_time VARCHAR(8),pickup_location VARCHAR(100),dropoff_location VARCHAR(100),pax INTEGER DEFAULT 2,qty INTEGER DEFAULT 1,supplier_id INTEGER DEFAULT 1,booking_status VARCHAR(20),planned_cost VARCHAR(40) DEFAULT '10.00',confirmed_cost VARCHAR(40) DEFAULT '10.00',actual_cost VARCHAR(40),cost_currency VARCHAR(3) DEFAULT 'USD',confirmation_no VARCHAR(100),confirmation_source VARCHAR(20),driver_name VARCHAR(100),driver_mobile VARCHAR(30),vehicle_type VARCHAR(100),vehicle_plate VARCHAR(30),guide_name VARCHAR(100),guide_mobile VARCHAR(30),notes TEXT,source_type VARCHAR(30),source_ref VARCHAR(100)",
 'guests'=>"id $identity,booking_id INTEGER,full_name VARCHAR(100)",
 'flights'=>"id $identity,booking_id INTEGER,airline VARCHAR(30),flight_number VARCHAR(30),flight_date VARCHAR(10),origin_code VARCHAR(10),destination_code VARCHAR(10),departure_time VARCHAR(8),arrival_time VARCHAR(8),terminal VARCHAR(30),status VARCHAR(20)",
 'booking_quote_snapshots'=>"id $identity,booking_id INTEGER,public_snapshot_json TEXT,internal_snapshot_json TEXT",
 'service_travel_details'=>'service_id INTEGER PRIMARY KEY,customer_details_json TEXT,updated_by INTEGER',
 'travel_documents'=>"id $identity,company_id INTEGER,booking_id INTEGER,kind VARCHAR(30)",
 'travel_document_versions'=>"id $identity,document_id INTEGER,version_no INTEGER,status VARCHAR(20),visibility VARCHAR(20),public_snapshot_json TEXT,content_hash VARCHAR(64)",
 'operation_resources'=>"id $identity,company_id INTEGER,kind VARCHAR(20),name VARCHAR(100),phone VARCHAR(30),capacity INTEGER,status VARCHAR(20)",
 'resource_assignments'=>"id $identity,resource_id INTEGER,service_id INTEGER,starts_at VARCHAR(30),ends_at VARCHAR(30),status VARCHAR(20) DEFAULT 'ASSIGNED',assigned_by INTEGER",
 'operational_issues'=>"id $identity,company_id INTEGER,booking_id INTEGER,category VARCHAR(30),severity VARCHAR(20),title VARCHAR(100),description TEXT,owner_user_id INTEGER,created_by INTEGER,status VARCHAR(20) DEFAULT 'OPEN',root_cause TEXT,resolution TEXT,lessons_learned TEXT,financial_impact VARCHAR(40) DEFAULT '0.00',currency VARCHAR(3),resolved_by INTEGER,resolved_at VARCHAR(30)",
 'customer_invoices'=>"id $identity,company_id INTEGER,booking_id INTEGER,invoice_ref VARCHAR(40),invoice_type VARCHAR(30) DEFAULT 'CUSTOMER_INVOICE',status VARCHAR(20),issue_date VARCHAR(10),due_date VARCHAR(10),currency VARCHAR(3) DEFAULT 'USD',subtotal VARCHAR(40),total VARCHAR(40),paid_amount VARCHAR(40) DEFAULT '0.00',balance VARCHAR(40),line_items_json TEXT,notes TEXT,created_by INTEGER,issued_by INTEGER,issued_at VARCHAR(30)",
 'invoice_issue_snapshots'=>"id $identity,invoice_id INTEGER,public_snapshot_json TEXT,content_hash VARCHAR(64),issued_by INTEGER",
 'invoice_commercial_documents'=>"id $identity,invoice_id INTEGER,document_ref VARCHAR(64),issue_date VARCHAR(10),public_snapshot_json TEXT,content_hash VARCHAR(64),issued_by INTEGER",
 'customer_receipts'=>"id $identity,company_id INTEGER,party_kind VARCHAR(20),party_id INTEGER,receipt_ref VARCHAR(40),payment_date VARCHAR(10),amount VARCHAR(40),currency VARCHAR(3),transaction_reference VARCHAR(100),idempotency_key VARCHAR(80),payload_hash VARCHAR(64),recorded_by INTEGER",
 'payment_allocations'=>"id $identity,receipt_id INTEGER,invoice_id INTEGER,amount VARCHAR(40),allocation_date VARCHAR(10),request_key VARCHAR(80),allocated_by INTEGER",
 'customer_payments'=>"id $identity,booking_id INTEGER,invoice_id INTEGER,amount VARCHAR(40),currency VARCHAR(3),payment_date VARCHAR(10)",
 'payment_schedules'=>"id $identity,booking_id INTEGER,direction VARCHAR(3),label VARCHAR(100),percentage VARCHAR(20),amount VARCHAR(40),currency VARCHAR(3),due_date VARCHAR(10),status VARCHAR(20)",
 'invoice_payment_schedules'=>"id $identity,invoice_id INTEGER,schedule_id INTEGER",
 'supplier_orders'=>"id $identity,company_id INTEGER,booking_id INTEGER,order_ref VARCHAR(40),supplier_id INTEGER,status VARCHAR(20),current_revision INTEGER",
 'supplier_order_services'=>"id $identity,order_id INTEGER,service_id INTEGER,service_status VARCHAR(20)",
 'supplier_payables'=>"id $identity,company_id INTEGER,booking_id INTEGER,service_id INTEGER,supplier_order_id INTEGER,supplier_id INTEGER,payable_ref VARCHAR(40),currency VARCHAR(3),total_amount VARCHAR(40),paid_amount VARCHAR(40),balance VARCHAR(40),status VARCHAR(20),supplier_invoice_no VARCHAR(100)",
 'supplier_invoice_reconciliations'=>"id $identity,payable_id INTEGER,status VARCHAR(20),invoice_amount VARCHAR(40),supplier_invoice_no VARCHAR(100)",
 'supplier_payments'=>"id $identity,company_id INTEGER,payment_ref VARCHAR(40),payable_id INTEGER,booking_id INTEGER,supplier_id INTEGER,payment_date VARCHAR(10),amount VARCHAR(40),currency VARCHAR(3),transaction_reference VARCHAR(100),recorded_by INTEGER",
 'supplier_payment_keys'=>"id $identity,company_id INTEGER,request_key VARCHAR(80),payment_id INTEGER,payload_hash VARCHAR(64)",
 'audit_logs'=>"id $identity,company_id INTEGER,user_id INTEGER,action_code VARCHAR(80),entity_type VARCHAR(40),entity_id INTEGER,before_json TEXT,after_json TEXT,request_id VARCHAR(80),ip_address VARCHAR(100)"
];
foreach($schemas as $table=>$fields)$db->exec('CREATE TABLE '.$table.' ('.$fields.')'.($mysql?' ENGINE=InnoDB':''));
$today=date('Y-m-d');$date=(new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');$closed=$today.' 01:00:00';
$db->exec("INSERT INTO trips(id,company_id) VALUES(1,1),(2,2); INSERT INTO users VALUES(1,1,'ACTIVE','Operator'),(2,2,'ACTIVE','Other company');");
$db->prepare("INSERT INTO bookings(id,company_id,booking_ref,trip_id,quote_id,confirmed_quote_version_no,lead_guest_name,start_date,end_date,total_guests,paying_pax,operations_status,finance_closed_at) VALUES(1,1,'B-VS0',1,1,1,'Guest A',?,?,2,2,'READY',?),(2,2,'B-OTHER',2,2,1,'Other',?,?,1,1,'NEW_BOOKING',NULL)")->execute([$date,$date,$closed,$date,$date]);
$db->prepare("INSERT INTO booking_services(id,booking_id,category,service_name,service_date,start_time,end_time,booking_status,confirmation_no) VALUES(1,1,'TRANSPORT','Airport transfer',?,'08:00:00','17:00:00','CONFIRMED','CONF-1')")->execute([$date]);
$db->exec("INSERT INTO guests VALUES(1,1,'Guest A'),(2,1,'Guest B'); INSERT INTO operation_resources VALUES(1,1,'DRIVER','Driver A','1',NULL,'ACTIVE'),(2,1,'VEHICLE','Van A','2',2,'ACTIVE'),(3,2,'DRIVER','Foreign driver','3',NULL,'ACTIVE');");
$db->prepare("INSERT INTO resource_assignments(id,resource_id,service_id,starts_at,ends_at,status,assigned_by) VALUES(1,1,1,?,?,'ASSIGNED',1),(2,2,1,?,?,'ASSIGNED',1)")->execute([$date.' 08:00:00',$date.' 17:00:00',$date.' 08:00:00',$date.' 17:00:00']);
$quote=['tour_name'=>'Vietnam transfer','schedule'=>[['day'=>1,'date'=>$date,'title'=>'Arrival','description'=>'Meet the driver','meals'=>'','overnight'=>'Hanoi']],'document_language'=>'en'];
$db->prepare('INSERT INTO booking_quote_snapshots(booking_id,public_snapshot_json,internal_snapshot_json) VALUES(1,?,?)')->execute([json_encode($quote),'HISTORICAL PRIVATE SNAPSHOT']);
$db->exec("INSERT INTO travel_documents VALUES(1,1,1,'TRAVEL_PACK')");
$pack=TravelDocuments::snapshot($db,1,1,'TRAVEL_PACK','HIDE_PRICE');$pack['document_version']=1;$packJson=json_encode($pack,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$db->prepare("INSERT INTO travel_document_versions VALUES(1,1,1,'ISSUED','HIDE_PRICE',?,?)")->execute([$packJson,hash('sha256',$packJson)]);
$db->prepare("INSERT INTO customer_invoices(id,company_id,booking_id,invoice_ref,status,issue_date,due_date,total,balance,line_items_json) VALUES(1,1,1,'INV-VS0','ISSUED',?,?,'100.00','100.00','[]')")->execute([$today,$date]);
$db->exec("INSERT INTO invoice_issue_snapshots VALUES(1,1,'HISTORICAL ISSUED INVOICE','immutable-hash',1); INSERT INTO supplier_orders VALUES(1,1,1,'SBO-VS0',1,'REQUESTED',0); INSERT INTO supplier_payables VALUES(1,1,1,1,1,1,'AP-VS0','USD','10.00','0.00','10.00','UNPAID',NULL); INSERT INTO supplier_invoice_reconciliations VALUES(1,1,'REVIEW_REQUIRED','10.00','SUP-1'); INSERT INTO operational_issues(id,company_id,booking_id,severity,title,status,owner_user_id) VALUES(1,1,1,'LOW','Historical issue','OPEN',1)");
$db->exec("INSERT INTO supplier_order_services VALUES(1,1,1,'REQUESTED')");
$db->prepare("INSERT INTO invoice_commercial_documents VALUES(1,1,'INV-VS0-C',?,'HISTORICAL COMMERCIAL SNAPSHOT','commercial-immutable-hash',1)")->execute([$today]);
$db->prepare("INSERT INTO customer_receipts(id,company_id,party_kind,party_id,receipt_ref,payment_date,amount,currency) VALUES(1,1,'BOOKING',1,'PAY-VS0',?,'100.00','USD')")->execute([$today]);
$u=['id'=>1,'company_id'=>1];
$fingerprint=function()use($db,$schemas):string{$rows=[];foreach(array_keys($schemas) as $table)$rows[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));};
$before=$fingerprint();
$writers=[
 'invoice draft'=>fn()=>FinanceLedger::createInvoice($db,$u,1,['subtotal'=>'1.00']),
 'invoice issue'=>fn()=>FinanceLedger::issueInvoice($db,$u,1),
 'commercial issue retry'=>fn()=>InvoiceCommercial::issue($db,$u,1,$today),
 'receipt'=>fn()=>FinanceLedger::receipt($db,$u,1,['amount'=>'1.00','payment_date'=>$today,'transaction_reference'=>'BANK-NEW','idempotency_key'=>'vs0-new-receipt']),
 'allocation'=>fn()=>FinanceLedger::allocate($db,$u,1,['request_key'=>'vs0-allocation','allocation_date'=>$today,'items'=>[['invoice_id'=>1,'amount'=>'1.00']]]),
 'payment schedule'=>fn()=>FinanceLedger::paymentSchedule($db,$u,1,['items'=>[['amount'=>'1.00','due_date'=>$date]]]),
 'supplier reconciliation'=>fn()=>FinanceLedger::reconcile($db,$u,1,['invoice_amount'=>'10.00','supplier_invoice_no'=>'SUP-NEW','reason'=>'Review']),
 'reconciliation approval'=>fn()=>FinanceLedger::approveReconciliation($db,$u,1),
 'supplier payment'=>fn()=>FinanceLedger::supplierPayment($db,$u,1,['amount'=>'1.00','payment_date'=>$today,'transaction_reference'=>'BANK-SUP','idempotency_key'=>'vs0-supplier']),
 'generate supplier orders'=>fn()=>Procurement::generate($db,$u,1),
 'send supplier order'=>fn()=>Procurement::send($db,$u,1),
 'confirm supplier order'=>fn()=>Procurement::confirm($db,$u,1,[]),
 'resource assignment'=>fn()=>OperationsControl::assign($db,$u,1,['resource_id'=>1,'starts_at'=>$date.' 08:00:00','ends_at'=>$date.' 17:00:00']),
 'cancel assignment'=>fn()=>OperationsControl::cancelAssignment($db,$u,1,['reason'=>'Change']),
 'service operations update'=>fn()=>OperationsControl::updateService($db,$u,1,['notes'=>'Changed after close']),
 'customer travel details'=>fn()=>ServiceTravelDetails::put($db,$u,1,['meeting_point'=>'Changed after close']),
 'issue financial resolution'=>fn()=>OperationsControl::resolveIssue($db,$u,1,['root_cause'=>'Cause','resolution'=>'Fix','lessons_learned'=>'Lesson','financial_impact'=>'1.00'])
];
foreach($writers as $label=>$writer){vs0Reject($writer,DomainException::class,'closed booking rejects '.$label);vs0Check(!$db->inTransaction()&&$fingerprint()===$before,'rejected '.$label.' rolls back without changing historical or ledger records');}
vs0Reject(fn()=>BookingIntegrity::financial($db,1,1),LogicException::class,'financial guard cannot run outside a transaction');
$db->beginTransaction();vs0Reject(fn()=>BookingIntegrity::financialFor($db,2,'invoice',1),OutOfBoundsException::class,'parent lookup does not disclose a foreign-company invoice');$db->rollBack();
$readiness=OperationsControl::readiness($db,1,1);vs0Check($readiness['ready']&&$readiness['readiness_pct']===100,'closed booking historical readiness remains readable');
$statement=FinanceLedger::statement($db,1,'BOOKING',1,$today,$today);vs0Check(isset($statement['currencies']['USD'])&&$fingerprint()===$before,'closed booking statements are read-only and historical snapshots remain intact');
vs0Check(ServiceTravelDetails::read($db,1,1)['meeting_point']===''&&$fingerprint()===$before,'closed booking customer travel details remain readable without locking or mutation');

// Reopen is tested at the integrity boundary; CoreOS HTTP fixtures cover permission and reason checks.
$db->beginTransaction();BookingIntegrity::lock($db,1,1);$db->exec('UPDATE bookings SET finance_closed_at=NULL WHERE id=1');$db->commit();
$receiptBody=['amount'=>'1.00','currency'=>'USD','payment_date'=>$today,'transaction_reference'=>'BANK-NEW','idempotency_key'=>'vs0-new-receipt'];
$receipt=FinanceLedger::receipt($db,$u,1,$receiptBody);vs0Check(FinanceLedger::receipt($db,$u,1,$receiptBody)===$receipt,'reopened booking accepts an idempotent receipt');
$db->prepare('UPDATE bookings SET finance_closed_at=? WHERE id=1')->execute([$closed]);$closedRetry=$fingerprint();vs0Reject(fn()=>FinanceLedger::receipt($db,$u,1,$receiptBody),DomainException::class,'closed marker also gates a mutating receipt retry');vs0Check($closedRetry===$fingerprint(),'blocked retry preserves the existing bank transaction');$db->exec('UPDATE bookings SET finance_closed_at=NULL WHERE id=1');
OperationsControl::updateService($db,$u,1,['notes'=>'Reviewed operation note']);vs0Check($db->query('SELECT notes FROM booking_services WHERE id=1')->fetchColumn()==='Reviewed operation note','reopened booking service updates use the real writer');
$db->exec('UPDATE booking_services SET booking_id=2 WHERE id=1');$badOwnership=$fingerprint();vs0Reject(fn()=>Procurement::confirm($db,$u,1,[]),DomainException::class,'supplier confirmation rejects a service attached from another booking');vs0Check($badOwnership===$fingerprint(),'invalid supplier service ownership produces no AP or service mutation');vs0Reject(fn()=>FinanceLedger::approveReconciliation($db,$u,1),DomainException::class,'supplier reconciliation cannot write actual cost to another booking service');vs0Check($badOwnership===$fingerprint(),'foreign service reconciliation leaves payable and service costs unchanged');$db->exec('UPDATE booking_services SET booking_id=1 WHERE id=1');
$result=BookingReadiness::persist($db,1,1);vs0Check($result['ready']&&$result['operations_status']==='READY'&&$result['checks']===OperationsControl::readiness($db,1,1)['checks'],'API and persisted readiness share all five canonical checks');
vs0Check(!BookingReadiness::needsDepartureCheck($db->query('SELECT * FROM bookings WHERE id=1')->fetch(),$result,$today,7),'ready canonical policy clears departure check');
$db->exec("UPDATE guests SET full_name='Guest B amended' WHERE id=2");$stale=OperationsControl::readiness($db,1,1);$stored=$db->query('SELECT * FROM bookings WHERE id=1')->fetch();
vs0Check(!$stale['checks']['current_travel_pack']&&BookingReadiness::needsDepartureCheck($stored,$stale,$today,7),'stale issued Travel Pack requires departure check even while stored phase is READY');
$result=BookingReadiness::persist($db,1,1);vs0Check(!$result['ready']&&$result['operations_status']==='ALL_CONFIRMED'&&$result['readiness_pct']===80,'stale document removes READY using the same policy');
$db->exec("UPDATE guests SET full_name='Guest B' WHERE id=2; UPDATE resource_assignments SET ends_at=starts_at WHERE id=2");
vs0Check(!OperationsControl::readiness($db,1,1)['checks']['resource_assignment'],'same-date assignment with insufficient interval cannot satisfy readiness');
$db->prepare('UPDATE resource_assignments SET ends_at=? WHERE id=2')->execute([$date.' 17:00:00']);$db->exec("UPDATE resource_assignments SET resource_id=3 WHERE id=1");
vs0Check(!OperationsControl::readiness($db,1,1)['checks']['resource_assignment'],'foreign-company active driver cannot satisfy readiness');
$db->exec('UPDATE resource_assignments SET resource_id=1 WHERE id=1');$db->exec("UPDATE operation_resources SET status='INACTIVE' WHERE id=2");
vs0Check(!OperationsControl::readiness($db,1,1)['checks']['resource_assignment'],'inactive vehicle cannot satisfy readiness');$db->exec("UPDATE operation_resources SET status='ACTIVE' WHERE id=2");
$db->prepare("INSERT INTO booking_services(id,booking_id,category,service_name,service_date,booking_status) VALUES(2,1,'HOTEL','Cancelled hotel',?,'CANCELLED')")->execute([$date]);$active=OperationsControl::readiness($db,1,1);vs0Check($active['ready']&&$active['service_count']===1&&$active['confirmed_services']===1,'cancelled service is excluded consistently from supplier and pack readiness');
$db->exec("UPDATE booking_services SET start_time='23:00:00',end_time='02:00:00' WHERE id=1");$db->prepare('UPDATE resource_assignments SET starts_at=?,ends_at=? WHERE service_id=1')->execute([$date.' 23:00:00',$date.' 23:59:00']);vs0Check(!OperationsControl::readiness($db,1,1)['checks']['resource_assignment'],'overnight service requires resource coverage after midnight');
$db->prepare('UPDATE resource_assignments SET ends_at=? WHERE service_id=1')->execute([(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d').' 02:00:00']);vs0Check(OperationsControl::readiness($db,1,1)['checks']['resource_assignment'],'full overnight coverage satisfies the same resource policy');
$db->exec("UPDATE booking_services SET start_time='08:00:00',end_time='17:00:00' WHERE id=1");$db->prepare('UPDATE resource_assignments SET starts_at=?,ends_at=? WHERE service_id=1')->execute([$date.' 08:00:00',$date.' 17:00:00']);
$issue=OperationsControl::createIssue($db,$u,1,['category'=>'TRANSPORT','severity'=>'HIGH','title'=>'Pickup risk','description'=>'Investigate']);
vs0Check(!$db->inTransaction()&&$db->query('SELECT operations_status FROM bookings WHERE id=1')->fetchColumn()==='ALL_CONFIRMED'&&!OperationsControl::readiness($db,1,1)['checks']['no_critical_issues'],'opening an incident immediately persists the readiness block');
OperationsControl::resolveIssue($db,$u,$issue['id'],['root_cause'=>'Cause','resolution'=>'Resolved','lessons_learned'=>'Lesson','financial_impact'=>'0.00','currency'=>'USD']);
vs0Check(OperationsControl::readiness($db,1,1)['ready']&&$db->query('SELECT operations_status FROM bookings WHERE id=1')->fetchColumn()==='READY','resolved incident immediately restores canonical readiness');
foreach(['ON_TOUR','OPERATION_COMPLETED','COMPLETED','CANCELLED'] as $phase){$db->prepare('UPDATE bookings SET operations_status=? WHERE id=1')->execute([$phase]);$result=BookingReadiness::persist($db,1,1);vs0Check($result['operations_status']===$phase&&!BookingReadiness::needsDepartureCheck($db->query('SELECT * FROM bookings WHERE id=1')->fetch(),$result,$today,7),'readiness refresh preserves '.$phase.' lifecycle and closes departure tasks');}
vs0Check($db->query('SELECT public_snapshot_json FROM travel_document_versions WHERE id=1')->fetchColumn()===$packJson&&$db->query('SELECT public_snapshot_json FROM invoice_issue_snapshots WHERE id=1')->fetchColumn()==='HISTORICAL ISSUED INVOICE','projection and operational mutations preserve issued document snapshots');

if($mysql){
    $db->exec("UPDATE bookings SET finance_closed_at=NULL,operations_status='READY' WHERE id=1");$other=new PDO($dsn,getenv('VS0_TEST_MYSQL_USER')?:'root',getenv('VS0_TEST_MYSQL_PASSWORD')?:'',$options);$other->exec('SET SESSION innodb_lock_wait_timeout=1');
    ServiceTravelDetails::put($db,$u,1,['meeting_point'=>'Reviewed meeting point']);vs0Check(ServiceTravelDetails::read($db,1,1)['meeting_point']==='Reviewed meeting point'&&!BookingReadiness::evaluate($db,1,1)['checks']['current_travel_pack']&&$db->query('SELECT operations_status FROM bookings WHERE id=1')->fetchColumn()==='ALL_CONFIRMED','MariaDB customer travel details writer immediately persists the stale Travel Pack block');
    $sessionIsolation=$db->query('SELECT @@session.tx_isolation')->fetchColumn();BookingIntegrity::begin($db);BookingIntegrity::parentId($db,1,'invoice',1);$other->exec("UPDATE customer_receipts SET amount='101.00' WHERE id=1");vs0Check(FinanceLedger::cents($db->query('SELECT amount FROM customer_receipts WHERE id=1')->fetchColumn())===10100,'MariaDB booking transaction reads fresh aggregate inputs after unlocked parent discovery');$db->rollBack();vs0Check($db->query('SELECT @@session.tx_isolation')->fetchColumn()===$sessionIsolation,'booking transaction isolation leaves the connection default unchanged');
    $db->beginTransaction();BookingIntegrity::lock($db,1,1);$db->prepare('UPDATE bookings SET finance_closed_at=? WHERE id=1')->execute([$closed]);
    $lockError=null;try{FinanceLedger::receipt($other,$u,1,array_replace($receiptBody,['idempotency_key'=>'vs0-race','transaction_reference'=>'RACE']));}catch(PDOException $e){$lockError=$e;}
    vs0Check($lockError!==null&&(int)($lockError->errorInfo[1]??0)===1205,'MariaDB writer receives the real InnoDB lock-wait timeout while finance close holds the booking');
    vs0Check(!$other->inTransaction(),'blocked MariaDB writer rolls back its transaction');$db->commit();
    vs0Reject(fn()=>FinanceLedger::receipt($other,$u,1,array_replace($receiptBody,['idempotency_key'=>'vs0-race','transaction_reference'=>'RACE'])),DomainException::class,'MariaDB writer sees the committed closed marker after lock release');
}else echo "LIMIT SQLite fixture verifies real mutation guards and readiness SQL; InnoDB row-lock concurrency and HTTP permission/session dispatch require separate MariaDB/HTTP verification.\n";
echo "VS0 finance/operations checks passed: $count (".($mysql?'MariaDB':'SQLite').").\n";

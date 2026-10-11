<?php
declare(strict_types=1);
/**
 * Real MariaDB isolation checks, using only a throwaway schema.
 * No production config, deployment secrets, or RC6 tables are touched.
 */
if (getenv('VTA_SMART_TEST_ONLY') !== '1' || getenv('VTA_SMART_TEST_DB_PASSWORD') !== 'vta-smart-fixture-only') {
    fwrite(STDERR, "Refusing to run outside dedicated Smart Workflow CI fixture.\n");
    exit(2);
}
final class SmartTestResponse extends Exception {
    public function __construct(public array $data, public int $httpCode=200) { parent::__construct('HTTP'); }
}
final class Auth {
    public static array $grants=[];
    public static function can(PDO $db,int $id,string $permission):bool {
        return in_array($permission,self::$grants[$id]??[],true);
    }
}
final class Http {
    public static function json(array $payload,int $code=200):never {
        throw new SmartTestResponse($payload,$code);
    }
}
require dirname(__DIR__).'/api/lib/SmartEnhancements.php';

function reply(PDO $db,array $user,string $route,string $q=''):array {
    $_GET=['q'=>$q];
    try {SmartEnhancements::handle($route,'GET',$db,$user);throw new RuntimeException('No response from SmartEnhancements');}
    catch(SmartTestResponse $r){if($r->httpCode!==200)throw new RuntimeException('Unexpected HTTP '.$r->httpCode);return $r->data;}
}
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
function records(PDO $db,string $sql):void {$db->exec($sql);}
$server=new PDO('mysql:host=127.0.0.1;port=33319;charset=utf8mb4','root',getenv('VTA_SMART_TEST_DB_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$dbName='vta_smart_p1_ci_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db=new PDO('mysql:host=127.0.0.1;port=33319;dbname='.$dbName.';charset=utf8mb4','root',getenv('VTA_SMART_TEST_DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    foreach([
        'CREATE TABLE tour_library_programs(id INT PRIMARY KEY,company_id INT,title VARCHAR(190),destination VARCHAR(190),status VARCHAR(30),updated_at DATETIME)',
        'CREATE TABLE trips(id INT PRIMARY KEY,company_id INT,title VARCHAR(190),lead_contact_name VARCHAR(190),trip_ref VARCHAR(90))',
        'CREATE TABLE quotes(id INT PRIMARY KEY,company_id INT,quote_ref VARCHAR(90),trip_id INT,current_version_no INT,updated_at DATETIME)',
        'CREATE TABLE quote_versions(id INT PRIMARY KEY,quote_id INT,version_no INT,version_status VARCHAR(30))',
        'CREATE TABLE customers(id INT PRIMARY KEY,company_id INT,customer_ref VARCHAR(90),full_name VARCHAR(190),company_name VARCHAR(190),email VARCHAR(190),whatsapp VARCHAR(80),status VARCHAR(30),updated_at DATETIME)',
        'CREATE TABLE bookings(id INT PRIMARY KEY,company_id INT,booking_ref VARCHAR(90),trip_id INT,lead_guest_name VARCHAR(190),operations_status VARCHAR(30),risk_level VARCHAR(30),start_date DATE)',
        'CREATE TABLE suppliers(id INT PRIMARY KEY,company_id INT,supplier_ref VARCHAR(90),name VARCHAR(190),city VARCHAR(190),status VARCHAR(30),preferred INT)',
        'CREATE TABLE tasks(id INT PRIMARY KEY,company_id INT,owner_user_id INT,title VARCHAR(190),due_at DATETIME,priority VARCHAR(30),status VARCHAR(30))',
        'CREATE TABLE rates(id INT PRIMARY KEY,company_id INT,rate_ref VARCHAR(90),product_name VARCHAR(190),status VARCHAR(30))',
        'CREATE TABLE rate_versions(id INT PRIMARY KEY,rate_id INT,version_no INT,approval_status VARCHAR(30),valid_to DATE)'
    ] as $sql)records($db,$sql);
    records($db,"INSERT INTO tour_library_programs VALUES (1,101,'Hanoi Luxury','Hanoi','ACTIVE',NOW()),(2,202,'Hanoi Foreign','Hanoi','ACTIVE',NOW()),(3,101,'Archived tour','Hanoi','ARCHIVED',NOW())");
    records($db,"INSERT INTO trips VALUES (1,101,'Hanoi Family','Alice','TRIP-A'),(2,202,'Hanoi Foreign','Secret Person','TRIP-B')");
    records($db,"INSERT INTO quotes VALUES (1,101,'QT-HANOI',1,1,NOW()),(2,202,'QT-FOREIGN',2,1,NOW())");
    records($db,"INSERT INTO quote_versions VALUES (1,1,1,'SENT'),(2,2,1,'SENT')");
    records($db,"INSERT INTO customers VALUES (1,101,'CUS-ALICE','Alice','Hanoi Clients','alice@example.test','111','ACTIVE',NOW()),(2,202,'CUS-FOREIGN','Secret Person','Hanoi Clients','foreign@example.test','222','ACTIVE',NOW())");
    records($db,"INSERT INTO bookings VALUES (1,101,'BK-HANOI',1,'Alice','READY','HIGH',DATE_ADD(CURDATE(),INTERVAL 5 DAY)),(2,202,'BK-FOREIGN',2,'Secret Person','READY','CRITICAL',DATE_ADD(CURDATE(),INTERVAL 5 DAY))");
    records($db,"INSERT INTO suppliers VALUES (1,101,'SUP-HANOI','Hanoi Hotel','Hanoi','ACTIVE',1),(2,202,'SUP-FOREIGN','Hanoi Secret','Hanoi','ACTIVE',1)");
    records($db,"INSERT INTO tasks VALUES (1,101,1,'Review Alice booking',DATE_SUB(NOW(),INTERVAL 2 DAY),'HIGH','OPEN'),(2,101,2,'Private owner task',NOW(),'HIGH','OPEN'),(3,202,3,'Foreign task',NOW(),'HIGH','OPEN')");
    records($db,"INSERT INTO rates VALUES (1,101,'RATE-A','Hanoi Hotel 4 star','ACTIVE'),(2,202,'RATE-B','Hanoi Foreign Rate','ACTIVE')");
    records($db,"INSERT INTO rate_versions VALUES (1,1,1,'APPROVED',DATE_ADD(CURDATE(),INTERVAL 9 DAY)),(2,2,1,'APPROVED',DATE_ADD(CURDATE(),INTERVAL 9 DAY))");
    $full=['tour_library.view','sales.view','booking.view','supplier.view','rate.view','task.view','operations.view'];
    Auth::$grants=[1=>$full,2=>['tour_library.view'],3=>array_merge($full,['b2b.portal'])];
    $owner=['id'=>1,'company_id'=>101];
    $search=reply($db,$owner,'smart/search','Hanoi');
    foreach(['tours','quotes','bookings','customers','suppliers'] as $k){
        check(count($search['groups'][$k])===1,'Tenant 101 has one scoped '.$k.' result');
        check((int)$search['groups'][$k][0]['id']===1,'Tenant 202 '.$k.' is not disclosed');
    }
    check(!str_contains(json_encode($search,JSON_THROW_ON_ERROR),'Secret Person'),'No foreign name in search payload');
    check(!str_contains(json_encode($search,JSON_THROW_ON_ERROR),'net_price'),'No NET fields in search payload');
    check(count(reply($db,$owner,'smart/search','zz')['groups']['quotes'])===0,'Unmatched query is empty');
    $libraryOnly=reply($db,['id'=>2,'company_id'=>101],'smart/search','Hanoi');
    check(count($libraryOnly['groups']['tours'])===1 && $libraryOnly['groups']['quotes']===[], 'Library-only role never reads Sales quote');
    $partner=reply($db,['id'=>3,'company_id'=>101],'smart/search','Hanoi');
    check(count(array_filter($partner['groups']))===0,'Partner portal-only role is fail-closed');
    $alerts=reply($db,$owner,'smart/alerts');
    check(count($alerts['items'])===3,'Owned task, at-risk booking and expiring rate present');
    foreach($alerts['items'] as $alert)check((int)$alert['id']===1,'Only tenant-owned alert records disclosed');
    check(reply($db,['id'=>3,'company_id'=>101],'smart/alerts')['items']===[],'B2B partner has no internal alerts');
    $n=(int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    reply($db,$owner,'smart/alerts');
    check((int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn()===$n,'GET endpoints do not mutate tasks');
    try {SmartEnhancements::handle('smart/search','POST',$db,$owner);throw new RuntimeException('POST must be rejected');}
    catch(SmartTestResponse $r){check($r->httpCode===405,'Unsupported write method rejected');}
    echo "Smart P1 MariaDB tenant/RBAC and alerts contract PASS\n";
} finally {
    $db=null;
    $server->exec('DROP DATABASE `'.$dbName.'`');
}

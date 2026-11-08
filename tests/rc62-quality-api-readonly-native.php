<?php
declare(strict_types=1);

/**
 * Isolated authenticated-route contract fixture. Run with PDO SQLite.
 * Auth/Http are deterministic test doubles; production uses the unchanged
 * RC6.2 Auth::requireUser / requirePermission / checkCsrf implementations.
 */
final class ResponseStop extends RuntimeException
{
    public function __construct(public array $payload,public int $httpStatus)
    {
        parent::__construct((string)($payload['error']??'SUCCESS'));
    }
}
final class Http
{
    public static array $request=[];
    public static function json(array $payload,int $status=200):never
    {
        throw new ResponseStop($payload,$status);
    }
    public static function body():array {return self::$request;}
}
final class Auth
{
    public static ?array $session=null;
    public static array $permissions=[];
    public static bool $csrf=true;
    public static function requireUser(PDO $db):array
    {
        if(self::$session===null)Http::json(['error'=>'AUTH_REQUIRED'],401);
        return self::$session;
    }
    public static function can(PDO $db,int $id,string $code):bool
    {
        return in_array($code,self::$permissions[$id]??[],true);
    }
    public static function requirePermission(PDO $db,array $u,string $code):void
    {
        if(!self::can($db,(int)$u['id'],$code))Http::json(['error'=>'FORBIDDEN'],403);
    }
    public static function checkCsrf():void
    {
        if($_SERVER['REQUEST_METHOD']==='POST'&&!self::$csrf)Http::json(['error'=>'CSRF_INVALID'],403);
    }
}
final class TourLibrary
{
    public static function publicProgram(array $r,bool $detail=true):array
    {
        return ['title'=>$r['title'],'day_count'=>(int)$r['day_count'],
            'days'=>json_decode($r['days_json'],true,32,JSON_THROW_ON_ERROR),
            'proposal'=>json_decode($r['proposal_json'],true,32,JSON_THROW_ON_ERROR)];
    }
}
final class PriceMatrix
{
    public static function row(PDO $db,array $v):?array
    {
        $q=$db->prepare('SELECT * FROM quote_price_matrices WHERE quote_version_id=? LIMIT 1');
        $q->execute([$v['id']]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public static function cells(PDO $db,array $m):array
    {
        $q=$db->prepare('SELECT * FROM quote_price_matrix_cells WHERE matrix_id=? AND generation=? ORDER BY id');
        $q->execute([$m['id'],$m['generation']]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
require_once __DIR__.'/../api/lib/VtaAdvancedQualityApi.php';
function check(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}
function hasIssue(array $issues,string $code):bool
{
    return in_array($code,array_column($issues,'code'),true);
}
function callApi(PDO $db,string $route,string $method,array $user,array $body=[]):ResponseStop
{
    $_SERVER['REQUEST_METHOD']=$method;Http::$request=$body;
    try {
        VtaAdvancedQualityApi::handle($route,$method,$db,$user);
    }catch(ResponseStop $e){return $e;}
    throw new RuntimeException('Unmatched route or silently returned: '.$route);
}
function csv(array $rows):string
{
    $fh=fopen('php://temp','w+');
    foreach($rows as $row)fputcsv($fh,$row,',','"','');
    rewind($fh);$text=stream_get_contents($fh);fclose($fh);return $text;
}

check(extension_loaded('pdo_sqlite'),'PDO SQLite is required for this isolated regression.');
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE tour_library_programs (
    id INTEGER PRIMARY KEY, company_id INTEGER, title TEXT, day_count INTEGER, days_json TEXT, proposal_json TEXT);
CREATE TABLE suppliers(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT);
CREATE TABLE documents(id INTEGER PRIMARY KEY,company_id INTEGER,supplier_id INTEGER,review_status TEXT);
CREATE TABLE rates(id INTEGER PRIMARY KEY,company_id INTEGER,supplier_id INTEGER,category TEXT,product_name TEXT,option_name TEXT,rate_type TEXT,status TEXT,min_pax INTEGER,max_pax INTEGER);
CREATE TABLE rate_versions(id INTEGER PRIMARY KEY,rate_id INTEGER,rate_basis TEXT,approval_status TEXT,valid_from TEXT,valid_to TEXT);
CREATE TABLE quotes(id INTEGER PRIMARY KEY,company_id INTEGER);
CREATE TABLE quote_versions(id INTEGER PRIMARY KEY,quote_id INTEGER,fx_rate TEXT,costing_revision INTEGER,total_guests INTEGER,paying_pax INTEGER,foc INTEGER,adults INTEGER,children INTEGER,infants INTEGER,version_status TEXT);
CREATE TABLE quote_price_matrices(id INTEGER PRIMARY KEY,quote_version_id INTEGER,generation INTEGER,status TEXT,dependency_hash TEXT,fx_snapshot_json TEXT,inputs_json TEXT);
CREATE TABLE quote_price_matrix_cells(id INTEGER PRIMARY KEY,matrix_id INTEGER,generation INTEGER,variant_id INTEGER,channel TEXT,is_selected INTEGER,min_pax INTEGER,max_pax INTEGER,status TEXT,selling_currency TEXT,selling_per_pax TEXT,result_json TEXT);");

$days=[['day'=>1,'title'=>'Existing Hanoi','meals'=>'B','overnight'=>'Hanoi'],['day'=>2,'title'=>'Existing Halong','meals'=>'B']];
$doc=['schema'=>'VTA_DOC_2','content'=>['type'=>'doc','content'=>[
    ['type'=>'heading','content'=>[['type'=>'text','text'=>'Day 1 - Hanoi']]],
    ['type'=>'heading','content'=>[['type'=>'text','text'=>'Day 2 - Halong']]]
]]];
$stmt=$db->prepare('INSERT INTO tour_library_programs VALUES(?,?,?,?,?,?)');
foreach([[1,10],[2,11]] as [$id,$company])$stmt->execute([$id,$company,'Vietnam 2D1N',2,json_encode($days),json_encode(['document'=>$doc])]);
$db->exec("INSERT INTO suppliers VALUES(9,10,'ACTIVE'),(10,11,'ACTIVE');
INSERT INTO documents VALUES(17,10,9,'APPROVED'),(18,11,10,'APPROVED');
INSERT INTO rates VALUES(71,10,9,'HOTEL','Approved Hotel','','CONTRACT','ACTIVE',2,15);
INSERT INTO rate_versions VALUES(72,71,'PER_ROOM_NIGHT','APPROVED','2027-01-01','2027-12-31');
INSERT INTO quotes VALUES(29,10),(30,11);
INSERT INTO quote_versions VALUES(35,29,'25400.000000',2,7,6,1,6,0,0,'DRAFT');
INSERT INTO quote_versions VALUES(36,30,'25400.000000',2,7,6,1,6,0,0,'DRAFT');");
$fx=json_encode(['currency_pair'=>'USD/VND','source'=>'MANUAL','value'=>'25400.000000'],JSON_THROW_ON_ERROR);
$snap=json_encode(['graph'=>['version'=>['costing_revision'=>2]]],JSON_THROW_ON_ERROR);
$stmt=$db->prepare('INSERT INTO quote_price_matrices VALUES(?,?,?,?,?,?,?)');
$stmt->execute([90,35,1,'LOCKED',str_repeat('b',64),$fx,$snap]);
$result=json_encode(['mode'=>'PRIVATE','hotel_level'=>3,'cruise_level'=>5,'scenarios'=>[
    ['pax'=>6,'cost'=>['cost_total_vnd'=>'1500000.00','rate_sources'=>[['rate_version_id'=>72]],'guests'=>['foc'=>1]],
    'pricing'=>['selling_per_pax'=>'150.00']]
]],JSON_THROW_ON_ERROR);
$stmt=$db->prepare('INSERT INTO quote_price_matrix_cells VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([91,90,1,1,'B2B',1,6,6,'VALID','USD','150.00',$result]);

$user=['id'=>100,'company_id'=>10];
Auth::$session=$user;
Auth::$permissions[100]=['tour_library.view','supplier.view','rate.create','quote.view_cost'];
putenv(VtaAdvancedQualityApi::FLAG); // default OFF before server opt-in
check(callApi($db,'quality/tour-programs/1','GET',$user)->httpStatus===404,'feature disabled by default');
putenv(VtaAdvancedQualityApi::FLAG.'=1');
$r=callApi($db,'quality/tour-programs/1','GET',$user);
check($r->httpStatus===200 && $r->payload['module']==='M01','authenticated M01 reachable on opt-in');
check($r->payload['quality']['report']['verdict']==='NO_WARNINGS','saved Word/source 2D1N agree');
check(callApi($db,'quality/tour-programs/2','GET',$user)->httpStatus===404,'M01 other company direct ID denied');
check(callApi($db,'quality/tour-programs/1','POST',$user)->httpStatus===405,'M01 cannot mutate with POST');
$other=$user;$other['company_id']=11;
check(callApi($db,'quality/tour-programs/1','GET',$other)->httpStatus===401,'forged caller company cannot bypass session');
Auth::$session=null;
check(callApi($db,'quality/tour-programs/1','GET',$user)->httpStatus===401,'anonymous request rejected');
Auth::$session=$user;
Auth::$permissions[100]=[];
check(callApi($db,'quality/tour-programs/1','GET',$user)->httpStatus===403,'M01 denies unpermitted user');
Auth::$permissions[100]=['tour_library.view','supplier.view','rate.create','quote.view_cost'];

$header=['supplier_id','category','product_name','rate_type','rate_basis','currency','amount_vnd','valid_from','valid_to','source_document_id','tax_basis'];
$good=['9','HOTEL','Approved Hotel','CONTRACT','PER_ROOM_NIGHT','VND','2500000','2027-03-01','2027-05-01','17','NET'];
$body=['supplier_id'=>9,'csv'=>csv([$header,$good])];
$totalsBefore=(int)$db->query('SELECT COUNT(*) FROM rate_versions')->fetchColumn();
Auth::$csrf=false;
check(callApi($db,'quality/supplier-rates/preview','POST',$user,$body)->httpStatus===403,'CSRF mandatory for preview');
Auth::$csrf=true;
check(callApi($db,'quality/supplier-rates/preview','GET',$user,$body)->httpStatus===405,'M03 never exposes CSV preview by GET');
$r=callApi($db,'quality/supplier-rates/preview','POST',$user,$body);
check($r->httpStatus===200 && $r->payload['module']==='M03','authorized supplier preview returned');
$q=$r->payload['quality'];
check($q['status']==='PREVIEW_ONLY_NO_DB_WRITE','never mark imported/approved');
check(hasIssue($q['rows'][0]['issues'],'EXISTING_APPROVED_RATE_OVERLAP'),'existing approved contract overlap detected');
check((int)$db->query('SELECT COUNT(*) FROM rate_versions')->fetchColumn()===$totalsBefore,'source rates never altered');
$foreign=$good;$foreign[9]='18';
$r=callApi($db,'quality/supplier-rates/preview','POST',$user,['supplier_id'=>9,'csv'=>csv([$header,$foreign])]);
check($r->payload['quality']['blocked_rows']===1
  && hasIssue($r->payload['quality']['rows'][0]['issues'],'SOURCE_DOCUMENT_OWNERSHIP_REJECTED'),
 'other company document cannot authorize a supplier rate');
check(callApi($db,'quality/supplier-rates/preview','POST',$user,['supplier_id'=>10,'csv'=>csv([$header,$good])])->httpStatus===404,'foreign supplier cannot be inspected');
Auth::$permissions[100]=['supplier.view','tour_library.view','quote.view_cost'];
check(callApi($db,'quality/supplier-rates/preview','POST',$user,$body)->httpStatus===403,'rate.create required for supplier preview');
Auth::$permissions[100]=['supplier.view','tour_library.view','rate.create','quote.view_cost'];

$r=callApi($db,'quality/quote-versions/35/matrix','GET',$user);
check($r->httpStatus===200 && $r->payload['module']==='M04','scoped M04 is available');
$report=$r->payload['quality'];
check($report['verdict']==='ADVISORY_PASS_NOT_OWNER_APPROVAL','same SHA snapshot diagnostic only');
check($report['safe_cells'][0]['hotel_level']===3 && $report['safe_cells'][0]['cruise_level']===5,'selected Hotel 3star + Cruise 5star retained');
check(!str_contains(json_encode($report),'1500000')&&!str_contains(json_encode($report),'150.00'),'no cost/price disclosure');
check(callApi($db,'quality/quote-versions/36/matrix','GET',$user)->httpStatus===404,'M04 other company direct ID denied');
Auth::$permissions[100]=['tour_library.view','supplier.view','rate.create'];
check(callApi($db,'quality/quote-versions/35/matrix','GET',$user)->httpStatus===403,'M04 price diagnostics require quote.view_cost');
Auth::$permissions[100]=['tour_library.view','supplier.view','rate.create','quote.view_cost'];
check(callApi($db,'quality/quote-versions/35/matrix','POST',$user)->httpStatus===405,'M04 diagnostics cannot write');

putenv(VtaAdvancedQualityApi::FLAG); // process cleanup
echo "PASS RC6.2 opt-in quality API: feature flag OFF, login/session + permissions/CSRF, scoped Tour/Quote/Supplier, approved contract overlap, no write or sensitive output\n";

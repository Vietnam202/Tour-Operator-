<?php
declare(strict_types=1);

/**
 * M07 security and price-provenance fixture. All records are SYNTHETIC.
 * Uses SQLite PRAGMA query_only=ON after setup, no supplier/contact/provider
 * activity, no B2B approval, no DOCX/production output or DB migration.
 */
final class StopB2B extends RuntimeException
{
    public function __construct(public array $payload,public int $status)
    {parent::__construct((string)($payload['error']??'OK'));}
}
final class Http
{
    public static function json(array $body,int $status=200):never
    {throw new StopB2B($body,$status);}
    public static function body():array{return [];}
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
    public static function can(PDO $db,int $id,string $permission):bool
    {
        return in_array($permission,self::$permissions[$id]??[],true);
    }
    public static function requirePermission(PDO $db,array $actor,string $permission):void
    {
        if(!self::can($db,(int)$actor['id'],$permission))
            Http::json(['error'=>'FORBIDDEN'],403);
    }
    public static function checkCsrf():void
    {
        if(!self::$csrf)Http::json(['error'=>'CSRF_REJECTED'],403);
    }
}
require_once __DIR__.'/../api/lib/PartnerHub.php';
require_once __DIR__.'/../api/lib/VtaB2BPartnerAudit.php';

function yes(bool $condition,string $why):void
{
    if(!$condition)throw new RuntimeException('M07 FAILED: '.$why);
}
function has(array $report,string $code):bool
{
    return in_array($code,array_column($report['warnings'],'code'),true);
}
function http(PDO $db,array $actor,string $route,string $method='GET',array $query=[]):StopB2B
{
    $_GET=$query;
    try {PartnerHub::handle($route,$method,$db,[],$actor);}
    catch(StopB2B $r){return $r;}
    throw new RuntimeException('B2B handler returned unexpectedly');
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE b2b_agencies (
    id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT,
    agency_name TEXT,brand_name TEXT,brand_color TEXT,logo_path TEXT,logo_sha256 TEXT);
CREATE TABLE b2b_agency_members(company_id INTEGER,agency_id INTEGER,user_id INTEGER);
CREATE TABLE b2b_publications(
    id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT,distribution_scope TEXT,
    exclusive_agency_id INTEGER,version_no INTEGER,rate_json TEXT,source_quote_version_id INTEGER);
CREATE TABLE b2b_partner_tours(
    id INTEGER PRIMARY KEY,company_id INTEGER,agency_id INTEGER,publication_id INTEGER,
    revision INTEGER,content_json TEXT,markup_type TEXT,markup_value TEXT);
CREATE TABLE b2b_net_requests(
    id INTEGER PRIMARY KEY,company_id INTEGER,agency_id INTEGER,
    partner_tour_id INTEGER,publication_id INTEGER,status TEXT,
    departure_date TEXT,paying_pax INTEGER,foc_pax INTEGER,mode TEXT,hotel_level TEXT,
    cruise_level TEXT,request_key_hash TEXT,request_payload_hash TEXT,
    source_quote_version_id INTEGER,assigned_to_user_id INTEGER,resolved_at TEXT);
CREATE TABLE users(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT);
CREATE TABLE quotes(id INTEGER PRIMARY KEY,company_id INTEGER);
CREATE TABLE quote_versions(
    id INTEGER PRIMARY KEY,quote_id INTEGER,version_status TEXT,
    start_date TEXT,paying_pax INTEGER,foc INTEGER);");
$db->exec("INSERT INTO b2b_agencies VALUES
    (10,1,'ACTIVE','Agency A','PARTNER_A_PRIVATE_BRAND','#163b60',NULL,NULL),
    (20,1,'ACTIVE','Agency B','PARTNER_B_PRIVATE_BRAND','#264b60',NULL,NULL),
    (30,1,'DISABLED','Agency C','DISABLED_AGENCY_BRAND','#163b60',NULL,NULL),
    (99,2,'ACTIVE','Agency Other','FOREIGN_AGENCY_BRAND','#123456',NULL,NULL);
INSERT INTO b2b_agency_members VALUES(1,10,101),(1,20,102),(1,30,103),(2,99,201);
INSERT INTO users VALUES(500,1,'ACTIVE'),(501,1,'INACTIVE'),(600,2,'ACTIVE');
INSERT INTO quotes VALUES(601,1),(602,2);
INSERT INTO quote_versions VALUES
    (701,601,'SENT','2027-02-02',6,1),
    (702,602,'SENT','2027-02-02',6,1),
    (703,601,'DRAFT','2027-02-02',6,1),
    (704,601,'SENT','2027-02-03',7,0);");
$publicContent=json_encode([
    'title'=>'SYNTHETIC 3D2N','days'=>[['title'=>'Demo Day 1'],['title'=>'Demo Day 2']],
    'included_text'=>'','terms_text'=>'Subject to written approval',
],JSON_THROW_ON_ERROR);
$badContent=json_encode([
    'title'=>'SYNTHETIC 3D2N','days'=>[],
    'supplier_costs'=>['DANGEROUS_PRIVATE_SUPPLIER_COST_99999'],
    'margin_amount'=>'PRIVATE_MARGIN_SECRET_12345',
],JSON_THROW_ON_ERROR);
$rateGood=json_encode(['valid_until'=>'2027-12-31','cells'=>[
    ['mode'=>'PRIVATE','hotel'=>'3','cruise'=>'5','pax_min'=>6,'pax_max'=>8,
     'net_per_pax'=>9876.54,'currency'=>'USD'],
]],JSON_THROW_ON_ERROR);
$rateExpired=json_encode(['valid_until'=>'2025-01-01','cells'=>[
    ['mode'=>'PRIVATE','hotel'=>'3','cruise'=>'5','pax_min'=>6,'pax_max'=>8,
     'net_per_pax'=>9999.98,'currency'=>'USD'],
]],JSON_THROW_ON_ERROR);
$p=$db->prepare('INSERT INTO b2b_publications VALUES(?,?,?,?,?,?,?,?)');
foreach([
 [1,1,'PUBLISHED','COMMON',null,1,$rateGood,701],
 [2,1,'PUBLISHED','EXCLUSIVE',10,2,$rateGood,701],
 [3,1,'REVOKED','COMMON',null,1,$rateGood,701],
 [4,1,'PUBLISHED','EXCLUSIVE',20,3,$rateExpired,703],
 [5,2,'PUBLISHED','EXCLUSIVE',99,1,$rateGood,702],
] as $row)$p->execute($row);
$w=$db->prepare('INSERT INTO b2b_partner_tours VALUES(?,?,?,?,?,?,?,?)');
foreach([
 [11,1,10,1,5,$publicContent,'PERCENT','20.00'],
 [12,1,20,1,2,$publicContent,'FIXED','999.99'],
 [13,1,10,2,6,$publicContent,'PERCENT','15.00'],
 [14,1,20,2,2,$publicContent,'PERCENT','10.00'],
 [15,1,20,3,1,$publicContent,'PERCENT','10.00'],
 [16,1,20,4,1,$publicContent,'PERCENT','10.00'],
 [17,2,99,5,1,$publicContent,'PERCENT','10.00'],
 [18,1,30,1,1,$publicContent,'PERCENT','10.00'],
 [19,1,10,1,1,$badContent,'PERCENT','10.00'],
] as $row)$w->execute($row);
$n=$db->prepare('INSERT INTO b2b_net_requests VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
function net(PDOStatement $n,int $id,int $agency,int $work,int $pub,
    string $status,?int $source,int $pax=6,int $foc=1,?int $owner=500,
    string $date='2027-02-02',string $key='a',string $payload='b',int $company=1):void
{
    $n->execute([$id,$company,$agency,$work,$pub,$status,$date,$pax,$foc,
        'PRIVATE','3','5',str_repeat($key,64),str_repeat($payload,64),$source,$owner,null]);
}
net($n,111,10,13,2,'REQUESTED',null);
net($n,112,10,13,2,'CLOSED',701);
net($n,113,20,16,4,'CLOSED',702);
net($n,114,10,13,2,'CLOSED',703);
net($n,115,10,13,2,'CLOSED',704);
net($n,116,10,13,2,'REQUESTED',null,6,1,null,'2027-02-02','!','b');
net($n,117,20,13,2,'REQUESTED',null);
net($n,118,99,17,5,'CLOSED',702,6,1,600,'2027-02-02','a','b',2);
net($n,119,10,13,2,'CLOSED',null);

$db->exec('PRAGMA query_only=ON');
$uA=['id'=>101,'company_id'=>1];
$uB=['id'=>102,'company_id'=>1];
$uForeign=['id'=>201,'company_id'=>2];
$uAdmin=['id'=>900,'company_id'=>1];
Auth::$permissions=[
  101=>['b2b.portal'],102=>['b2b.portal'],103=>['b2b.portal'],
  201=>['b2b.portal'],900=>['b2b.admin'],
];
$clock=new DateTimeImmutable('2026-11-08 12:00:00',new DateTimeZone('Asia/Bangkok'));
Auth::$session=$uA;
putenv(VtaB2BPartnerAudit::FLAG);
yes(http($db,$uA,'b2b/partner-tours/13/quality')->status===404,
    'feature is OFF by default');
putenv(VtaB2BPartnerAudit::FLAG.'=1');
$ok=http($db,$uA,'b2b/partner-tours/13/quality','GET',['expected_revision'=>'6']);
yes($ok->status===200&&($ok->payload['module']??'')==='M07',
    'existing PartnerHub serves default-off advisory only when flag ON');
$t=$ok->payload['quality'];
yes($t['agency_id']===10&&$t['publication_version_no']===2
    &&$t['expected_revision_matches']===true
    &&$t['distribution_scope']==='EXCLUSIVE'
    &&$t['branding']['mode']==='EXISTING_WHITE_LABEL'
    &&$t['branding']['brand_name_present']===true,
    'agency, published snapshot version and branding metadata preserved');
yes($t['net_publication']['expiry_status']==='FUTURE_VALIDITY_DATE'
    &&$t['publication_quote_source_status']==='SENT_OR_CONFIRMED_SOURCE',
    'frozen active publication + issued quote source are exposed only as safe status');
yes($t['branding']['logo_bytes_or_access_checked']===false,
    'logo path/bytes not inspected or exposed');
$stale=http($db,$uA,'b2b/partner-tours/13/quality','GET',['expected_revision'=>'5']);
yes(has($stale->payload['quality'],'WORKING_REVISION_CHANGED')
    &&$stale->payload['quality']['expected_revision_matches']===false,
    'revision drift review only, no hidden overwrite');
$leak=http($db,$uA,'b2b/partner-tours/19/quality');
yes(has($leak->payload['quality'],'PRIVATE_FIELD_IN_PARTNER_CONTENT'),
    'nested forbidden supplier/margin fields flagged BLOCK without disclosure');
$public=json_encode($ok->payload,JSON_THROW_ON_ERROR);
foreach(['9876.54','9999.98','999.99','PRIVATE_MARGIN_SECRET_12345','DANGEROUS_PRIVATE_SUPPLIER_COST_99999',
  'PARTNER_A_PRIVATE_BRAND','PARTNER_B_PRIVATE_BRAND','FOREIGN_AGENCY_BRAND'] as $canary){
    yes(!str_contains($public,$canary),'no commercial/branding secret in M07 report: '.$canary);
}
yes(http($db,$uA,'b2b/partner-tours/12/quality')->status===404,
    'Partner A cannot enumerate Partner B working ID');
yes(http($db,$uA,'b2b/partner-tours/14/quality')->status===404,
    'Partner A cannot enumerate wrong agency EXCLUSIVE copy');
yes(http($db,$uA,'b2b/partner-tours/15/quality')->status===404,
    'revoked publication cannot be audited by portal');
yes(http($db,$uA,'b2b/partner-tours/17/quality')->status===404,
    'foreign-company direct ID cannot be inspected');
yes(http($db,$uA,'b2b/partner-tours/18/quality')->status===404,
    'disabled agency inaccessible');
yes(http($db,$uA,'b2b/partner-tours/13/quality','POST')->status===405,
    'quality endpoints never publish/update tours');
yes(http($db,$uA,'b2b/partner-tours/13/quality','GET',['expected_revision'=>'0'])->status===422,
    'malformed expected revision rejected without any write');

$r=VtaB2BPartnerAudit::netRequest($db,$uA,112,$clock);
yes($r['request_status']==='CLOSED'
    &&$r['source_quote_status']==='SENT_OR_CONFIRMED_SOURCE'
    &&$r['source_travel_pax_consistency']==='MATCH'
    &&$r['idempotency_metadata_complete']
    &&has($r,'NET_IS_NOT_AN_OPERATIONS_BOOKING_CONFIRMATION'),
    'closed NET has issued same date/PAX source, but is NOT a booking or supplier confirmation');
yes($r['source_quote_version_id']===null,
    'partner sees issued Quote status but not the internal QuoteVersion ID');
$pending=VtaB2BPartnerAudit::netRequest($db,$uA,111,$clock);
yes(has($pending,'NET_REQUEST_PENDING_OPERATOR_RESOLUTION')
    &&$pending['state']==='READ_ONLY_REVIEW_NO_NET_APPROVAL',
    'NET request state can only be changed by original admin/quote approval flow');
$idempotent=VtaB2BPartnerAudit::netRequest($db,$uA,116,$clock);
yes(has($idempotent,'NET_REQUEST_IDEMPOTENCY_PROOF_MISSING'),
    'missing/invalid request retry token evidence is BLOCKED');
$draft=VtaB2BPartnerAudit::netRequest($db,$uA,114,$clock);
yes(has($draft,'CLOSED_NET_SOURCE_NOT_ISSUED'),
    'a DRAFT source is never treated as approved NET');
$pax=VtaB2BPartnerAudit::netRequest($db,$uA,115,$clock);
yes(has($pax,'ISSUED_SOURCE_TRAVEL_OR_PAX_MISMATCH')
    &&$pax['source_travel_pax_consistency']==='MISMATCH',
    'source 7 paying+0 FOC cannot justify request 6 paying+1 FOC');
$historical=VtaB2BPartnerAudit::netRequest($db,$uA,119,$clock);
yes(has($historical,'HISTORICAL_CLOSED_NET_HAS_NO_QUOTE_SOURCE'),
    'legacy CLOSED rows require provenance, never fabricated');
yes(http($db,$uA,'b2b/net-requests/113/quality')->status===404,
    'agency A cannot access agency B NET request');
yes(http($db,$uA,'b2b/net-requests/117/quality')->status===404,
    'corrupt agency/work/publication link fails closed');
yes(http($db,$uA,'b2b/net-requests/118/quality')->status===404,
    'company A cannot access company B NET request');
Auth::$session=$uB;
$expired=VtaB2BPartnerAudit::netRequest($db,$uB,113,$clock);
yes($expired['source_quote_status']==='QUOTE_NOT_FOUND_IN_COMPANY'
    &&$expired['rate_expiry_status']==='EXPIRED'
    &&has($expired,'PUBLICATION_NET_NOT_CURRENTLY_QUOTABLE'),
    'foreign quote reference and expired B2B NET are not approved');
Auth::$session=$uAdmin;
yes(http($db,$uAdmin,'b2b/partner-tours/12/quality')->status===200,
    'authorized same-company B2B admin can read permitted agency B status');
$adminSource=http($db,$uAdmin,'b2b/net-requests/112/quality');
yes($adminSource->status===200
    &&$adminSource->payload['quality']['source_quote_version_id']===701,
    'only authorized internal B2B admin may inspect exact source QuoteVersion ID');
Auth::$session=$uA;
$forged=['id'=>101,'company_id'=>2];
yes(http($db,$forged,'b2b/partner-tours/13/quality')->status===401,
    'cannot forge company context in direct URL');
Auth::$session=null;
yes(http($db,$uA,'b2b/net-requests/111/quality')->status===401,
    'anonymous request cannot inspect B2B NET status');
Auth::$session=$uA;
Auth::$permissions[101]=[];
yes(http($db,$uA,'b2b/partner-tours/13/quality')->status===403,
    'missing b2b.portal role is rejected');
Auth::$permissions[101]=['b2b.portal'];
Auth::$csrf=false;
yes(http($db,$uA,'b2b/partner-tours/13/quality')->status===403,
    'original CSRF requirement preserved in helper');
Auth::$csrf=true;
yes((int)$db->query('SELECT COUNT(*) FROM b2b_partner_tours')->fetchColumn()===9
    &&(int)$db->query('SELECT COUNT(*) FROM b2b_net_requests')->fetchColumn()===9
    &&$db->query('SELECT status FROM b2b_net_requests WHERE id=112')->fetchColumn()==='CLOSED',
    'query-only DB and historic NET workflow unchanged');
putenv(VtaB2BPartnerAudit::FLAG);
echo "PASS M07: agency isolation, revoked/EXCLUSIVE scopes, white-label metadata, source expiry/issued NET, FOC, source revisions, no financial output, idempotency and no writes\n";

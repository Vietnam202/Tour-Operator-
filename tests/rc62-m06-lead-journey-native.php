<?php
declare(strict_types=1);

/**
 * M06: disposable in-memory SQLite, with query_only after seed.
 * No actual CRM accounts, contact messages, DB migration, provider calls,
 * merge/qualify/convert or quote writes. Synthetic contact values only.
 */
final class StopResponse extends RuntimeException
{
    public function __construct(public array $payload, public int $status)
    {
        parent::__construct((string)($payload['error']??'OK'));
    }
}
final class Http
{
    public static function json(array $data,int $status=200):never
    {
        throw new StopResponse($data,$status);
    }
    public static function body():array {return [];}
}
final class Auth
{
    public static ?array $session=null;
    public static array $permissions=[];
    public static bool $csrfValid=true;
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
        if(!self::$csrfValid)Http::json(['error'=>'CSRF_REJECTED'],403);
    }
}
require_once __DIR__.'/../api/lib/LeadHub.php';
require_once __DIR__.'/../api/lib/VtaLeadJourneyReview.php';

function ok(bool $yes,string $message):void
{
    if(!$yes)throw new RuntimeException('M06 FAIL: '.$message);
}
function warning(array $report,string $code):bool
{
    return in_array($code,array_column($report['warnings'],'code'),true);
}
function getRoute(PDO $db,string $route,string $method,array $actor):StopResponse
{
    try{LeadHub::handle($route,$method,$db,$actor);}
    catch(StopResponse $r){return $r;}
    throw new RuntimeException('M06 route fell through');
}
function inspect(PDO $db,array $actor,int $request,DateTimeImmutable $clock):array
{
    return VtaLeadJourneyReview::review($db,$actor,$request,$clock);
}

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE lead_requests(
    id INTEGER PRIMARY KEY,company_id INTEGER,form_id INTEGER,campaign_id INTEGER,
    source TEXT,submission_key TEXT,payload_hash TEXT,
    contact_name TEXT,email TEXT,phone TEXT,travel_date TEXT,destination TEXT,
    status TEXT,created_at TEXT,attribution_json TEXT,version_no INTEGER,
    next_action_due TEXT,followup_owner_user_id INTEGER);
CREATE TABLE leads(id INTEGER PRIMARY KEY,company_id INTEGER,request_id INTEGER,
    status TEXT,handover_status TEXT,owner_user_id INTEGER,
    sales_owner_user_id INTEGER,next_action_due TEXT,inquiry_id INTEGER);
CREATE TABLE users(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT);
CREATE TABLE inquiries(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT,trip_id INTEGER);
CREATE TABLE trips(id INTEGER PRIMARY KEY,company_id INTEGER,customer_id INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,company_id INTEGER,status TEXT);
CREATE TABLE quotes(id INTEGER PRIMARY KEY,company_id INTEGER,inquiry_id INTEGER,status TEXT);");

$req=$db->prepare('INSERT INTO lead_requests VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
function putRequest(PDOStatement $req,int $id,int $company,string $name,?string $email,
    ?string $phone,?string $date,?string $dest,string $status='NEW',?string $due=null,
    ?int $followOwner=null,string $attribution='{}'):void
{
    $req->execute([$id,$company,4,3,'FACEBOOK','PRIVATE_SUBMISSION_KEY_'.$id,str_repeat('f',64),
        $name,$email,$phone,$date,$dest,$status,'2026-11-08 01:00:00',
        $attribution,1,$due,$followOwner]);
}
$sourceJson=json_encode([
    'utm_source'=>'facebook','utm_medium'=>'paid_social','utm_campaign'=>'SYNTHETIC_SECRET_CAMPAIGN',
    'ad_id'=>'SYNTHETIC_PRIVATE_AD_ID','landing_url'=>'https://example.invalid/private-utm-token',
    'consent_version'=>'CLAIMED_ONLY_NOT_VERIFIED',
],JSON_THROW_ON_ERROR);
putRequest($req,101,10,'Demo Alice','Alice@Example.COM','+84 912 345 678','2027-01-01',
    'Hanoi - Halong','QUALIFIED',null,null,$sourceJson);
putRequest($req,102,10,'Different Guest','alice@example.com','000 111 2222','2027-01-20',
    'Sapa'); // exact email
putRequest($req,103,10,'Another Guest','another@example.com','84912345678','2027-02-03',
    'Hue'); // normalized phone
putRequest($req,104,10,'  DEMO Alice  ','brand-new@example.net','099 988 8777','2027-01-01',
    ' Hanoi - Halong '); // same name/date/destination only
putRequest($req,105,11,'Demo Alice','Alice@example.com','84912345678','2027-01-01',
    'Hanoi - Halong'); // foreign company identity must never be shown
putRequest($req,106,10,'Unrelated','unrelated@example.net','012 345 6789','2027-01-02',
    'Another City');
putRequest($req,110,10,'Historic Entry',null,null,null,null,'NEW',null,null,'{"not-json":');
putRequest($req,111,10,'Broken Link','broken@example.org',null,'2027-01-03','Da Nang','QUALIFIED');
putRequest($req,112,10,'Converted Broken','nobody@example.org',null,'2027-01-04','Ha Giang','QUALIFIED');
putRequest($req,113,10,'Trip Foreign Customer','foreign-customer@example.org',null,'2027-01-08','Ninh Binh','QUALIFIED');
putRequest($req,114,10,'Trip Inactive Customer','inactive@example.org',null,'2027-01-09','Sapa','QUALIFIED');

$db->exec("INSERT INTO users VALUES(5,10,'ACTIVE'),(6,10,'INACTIVE'),(8,11,'ACTIVE');
INSERT INTO leads VALUES(201,10,101,'QUALIFIED','ACCEPTED',5,NULL,'2026-11-08 05:00:00',301);
INSERT INTO leads VALUES(211,10,111,'QUALIFIED','CONVERTED',6,NULL,NULL,399);
INSERT INTO leads VALUES(212,10,112,'CONVERTED','CONVERTED',5,NULL,NULL,NULL);
INSERT INTO leads VALUES(213,10,113,'CONVERTED','CONVERTED',5,NULL,NULL,302);
INSERT INTO leads VALUES(214,10,114,'CONVERTED','CONVERTED',5,NULL,NULL,303);
INSERT INTO inquiries VALUES(301,10,'WORKING',501);
INSERT INTO inquiries VALUES(302,10,'QUOTED',502);
INSERT INTO inquiries VALUES(303,10,'WORKING',503);
INSERT INTO inquiries VALUES(399,11,'SENT',599);
INSERT INTO trips VALUES(501,10,701),(502,10,799),(503,10,702),(599,11,799);
INSERT INTO customers VALUES(701,10,'ACTIVE'),(702,10,'INACTIVE'),(799,11,'ACTIVE');
INSERT INTO quotes VALUES(401,10,301,'READY'),(402,11,301,'APPROVED');");
$before=$db->query('SELECT COUNT(*) requests FROM lead_requests')->fetchColumn();
$beforeLead=$db->query('SELECT COUNT(*) FROM leads')->fetchColumn();
$beforeQuote=$db->query('SELECT COUNT(*) FROM quotes')->fetchColumn();
$db->exec('PRAGMA query_only=ON');

$actor=['id'=>501,'company_id'=>10];
Auth::$session=$actor;
Auth::$permissions[501]=['lead.view','sales.view'];
$clock=new DateTimeImmutable('2026-11-08 12:00:00',new DateTimeZone('Asia/Bangkok'));

putenv(VtaLeadJourneyReview::FLAG);
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$actor)->status===404,
    'M06 OFF means no new active route');
putenv(VtaLeadJourneyReview::FLAG.'=1');
$http=getRoute($db,'lead-hub/requests/101/quality','GET',$actor);
ok($http->status===200 && ($http->payload['module']??'')==='M06',
    'GET under authorized LeadHub module returns M06 report');
$r=inspect($db,$actor,101,$clock);
ok($r['state']==='READ_ONLY_ADVISORY_NO_MERGE_NO_CONTACT','review is only advisory');
ok($r['duplicate_review']['status']==='POSSIBLE_MATCHES_REQUIRE_HUMAN_REVIEW','suggestions never identity decisions');
ok($r['duplicate_review']['no_auto_merge']===true,'no auto merge');
$ids=array_column($r['duplicate_review']['candidates'],'request_id');
sort($ids);
ok($ids===[102,103,104],'email, phone, name/date/destination recommendations are same-company only');
ok(!in_array(105,$ids,true),'no cross-tenant lead candidates');
$levels=array_column($r['duplicate_review']['candidates'],'confidence','request_id');
ok($levels[102]==='CONTACT_MATCH_REVIEW'&&$levels[103]==='CONTACT_MATCH_REVIEW'
    &&$levels[104]==='TRAVEL_INTENT_ONLY',
    'name/date similarity is not customer identity or exact contact match');
ok(warning($r,'POTENTIAL_REQUEST_DUPLICATE_REQUIRES_HUMAN_REVIEW'),'human review flagged');
ok(warning($r,'CONSENT_VERSION_NOT_VERIFIED_IN_LEAD_REQUEST')
    &&$r['attribution']['consent_status']==='NOT_ESTABLISHED_FROM_EXISTING_SCHEMA',
    'untrusted consent_version inside attribution never becomes consent proof');
ok($r['attribution']['fields_present']['utm_source'] && $r['attribution']['fields_present']['utm_campaign']
    && !$r['attribution']['fields_present']['utm_term'],
    'only presence flags of real UTM are exposed');
ok($r['attribution']['first_touch_status']==='NOT_ESTABLISHED',
    'submission attribution must not be treated as verified first touch');
ok($r['follow_up']['age_minutes']===660
    &&$r['follow_up']['next_action_status']==='OVERDUE'
    &&$r['follow_up']['owner_verified_active']===true,
    'reproducible ageing, scheduled overdue and owner company/ACTIVE proof');
ok(warning($r,'NEXT_ACTION_OVERDUE'),'overdue task flagged, not sent');
ok($r['follow_up']['sla_status']==='NOT_MEASURABLE_WITHOUT_VERIFIED_POLICY',
    'no fabricated response time or SLA breach');
ok($r['journey']['lead_id']===201 && $r['journey']['inquiry_id']===301
    &&$r['journey']['inquiry_status']==='WORKING'
    &&$r['journey']['latest_quote_id']===401
    &&$r['journey']['latest_quote_status']==='READY'
    &&$r['journey']['quote_count_bounded']===1
    &&$r['journey']['customer360_link_status']==='ACTIVE_COMPANY_CUSTOMER_LINK',
    'LeadHub to Inquiry/Trip/Customer360 and Quote references scoped to original tenant');
$reportText=json_encode($r,JSON_THROW_ON_ERROR);
foreach(['alice@example.com','84912345678','SYNTHETIC_SECRET_CAMPAIGN',
    'SYNTHETIC_PRIVATE_AD_ID','PRIVATE_SUBMISSION_KEY_101','private-utm-token',
    'Demo Alice'] as $pii)ok(!str_contains(strtolower($reportText),strtolower($pii)),
    'sensitive PII, submission key, UTM contents absent from report: '.$pii);

$invalid=inspect($db,$actor,110,$clock);
ok(warning($invalid,'CONTACT_FIELDS_MISSING_OR_UNVERIFIED')
    &&warning($invalid,'ATTRIBUTION_SNAPSHOT_INVALID')
    &&warning($invalid,'INCOMPLETE_UTM_SNAPSHOT')
    &&warning($invalid,'TRAVEL_DATE_UNVERIFIED'),
    'incomplete source evidence remains unverified instead of synthesized');
$broken=inspect($db,$actor,111,$clock);
ok(warning($broken,'LINKED_INQUIRY_NOT_FOUND_IN_COMPANY')
    &&$broken['journey']['inquiry_status']==='BROKEN_OR_FOREIGN_LINK'
    &&warning($broken,'ASSIGNED_OWNER_NOT_ACTIVE'),
    'foreign Inquiry link and inactive owner flagged without cross-tenant read');
$noInquiry=inspect($db,$actor,112,$clock);
ok(warning($noInquiry,'CONVERTED_LEAD_HAS_NO_INQUIRY_LINK'),
    'converted record lacking inquiry link is BLOCKED review');
$foreignCustomer=inspect($db,$actor,113,$clock);
ok(warning($foreignCustomer,'CUSTOMER360_CROSS_TENANT_OR_BROKEN_LINK')
    &&$foreignCustomer['journey']['customer360_link_status']==='CUSTOMER_NOT_FOUND_IN_COMPANY',
    'cross-tenant customer reference cannot be accepted through a local trip');
$inactiveCustomer=inspect($db,$actor,114,$clock);
ok(warning($inactiveCustomer,'CUSTOMER360_CUSTOMER_NOT_ACTIVE')
    &&$inactiveCustomer['journey']['customer360_link_status']==='CUSTOMER_INACTIVE_REVIEW',
    'inactive Customer360 link requires human review, never auto-activates');

ok(getRoute($db,'lead-hub/requests/101/quality','POST',$actor)->status===405,
    'new advisory route rejects all mutating methods');
Auth::$permissions[501]=['lead.view'];
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$actor)->status===403,
    'sales.view required for linked Inquiry/Quote metadata');
Auth::$permissions[501]=['sales.view'];
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$actor)->status===403,
    'lead.view required for contacts');
Auth::$permissions[501]=['lead.view','sales.view'];
Auth::$csrfValid=false;
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$actor)->status===403,
    'session CSRF cookie control preserved as original API');
Auth::$csrfValid=true;
ok(getRoute($db,'lead-hub/requests/105/quality','GET',$actor)->status===404,
    'direct foreign company request ID denied');
$forged=['id'=>501,'company_id'=>11];
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$forged)->status===401,
    'caller may not forge current company_id');
Auth::$session=null;
ok(getRoute($db,'lead-hub/requests/101/quality','GET',$actor)->status===401,
    'anonymous not allowed');
Auth::$session=$actor;

ok((int)$db->query('SELECT COUNT(*) FROM lead_requests')->fetchColumn()===$before
    &&(int)$db->query('SELECT COUNT(*) FROM leads')->fetchColumn()===$beforeLead
    &&(int)$db->query('SELECT COUNT(*) FROM quotes')->fetchColumn()===$beforeQuote,
    'SQLite query_only database proves no contacts/leads/quotes wrote');
ok($db->query('SELECT status FROM lead_requests WHERE id=101')->fetchColumn()==='QUALIFIED',
    'original submission/consent and request status unchanged');
ok($db->query('SELECT handover_status FROM leads WHERE id=201')->fetchColumn()==='ACCEPTED',
    'original Sales handover/identity decision unchanged');
putenv(VtaLeadJourneyReview::FLAG);
echo "PASS RC6.2 M06: exact email/phone + weak travel-intent duplicate suggestions, no PII, UTM/consent unknown, explicit ageing/owner, company-scoped Inquiry/Quote, flag OFF, RBAC/CSRF/tenant, query-only DB\n";

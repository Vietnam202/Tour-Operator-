<?php
declare(strict_types=1);
// Pure disposable SQLite fixture: exercises the actual PartnerHub access rules,
// not production sessions, supplier rates or hosting credentials.
final class Auth {
    public static function can(PDO $db,int $id,string $permission):bool {
        return $id===900 && $permission==='b2b.admin';
    }
    public static function requirePermission(PDO $db,array $user,string $permission):void {
        if($permission!=='b2b.portal' || !in_array((int)$user['id'],[101,102,103,201],true))
            throw new DomainException('Permission denied');
    }
}
require_once __DIR__.'/../api/lib/PartnerHub.php';
function okB2B(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label.PHP_EOL;
}
function deniedB2B(callable $fn,string $label):void {
    try{$fn();}catch(OutOfBoundsException $e){okB2B(true,$label);return;}
    throw new RuntimeException('FAIL: '.$label);
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE b2b_agencies(id INTEGER,company_id INTEGER,status TEXT,brand_name TEXT);
CREATE TABLE b2b_agency_members(company_id INTEGER,agency_id INTEGER,user_id INTEGER);
CREATE TABLE b2b_publications(id INTEGER,company_id INTEGER,status TEXT,distribution_scope TEXT,exclusive_agency_id INTEGER,version_no INTEGER);
CREATE TABLE b2b_partner_tours(id INTEGER,company_id INTEGER,agency_id INTEGER,publication_id INTEGER,content_json TEXT,updated_at TEXT,revision INTEGER);");
$db->exec("INSERT INTO b2b_agencies VALUES(10,1,'ACTIVE','A'),(20,1,'ACTIVE','B'),(30,1,'DISABLED','C'),(99,2,'ACTIVE','Other');
INSERT INTO b2b_agency_members VALUES(1,10,101),(1,20,102),(1,30,103),(2,99,201);
INSERT INTO b2b_publications VALUES(1,1,'PUBLISHED','COMMON',NULL,1),(2,1,'PUBLISHED','EXCLUSIVE',10,1),(3,1,'REVOKED','COMMON',NULL,1),(4,1,'PUBLISHED','EXCLUSIVE',20,1),(5,2,'PUBLISHED','EXCLUSIVE',99,1);
INSERT INTO b2b_partner_tours VALUES
(11,1,10,1,'{}','2026-01-01',1),
(12,1,20,1,'{}','2026-01-01',1),
(13,1,10,2,'{}','2026-01-01',1),
(14,1,20,2,'{}','2026-01-01',1),
(15,1,20,3,'{}','2026-01-01',1),
(16,1,20,4,'{}','2026-01-01',1),
(17,2,99,5,'{}','2026-01-01',1),
(18,1,30,1,'{}','2026-01-01',1);");
$pub=new ReflectionMethod(PartnerHub::class,'publication');
$work=new ReflectionMethod(PartnerHub::class,'working');
$list=new ReflectionMethod(PartnerHub::class,'listWorking');
$a=['id'=>101,'company_id'=>1];$b=['id'=>102,'company_id'=>1];$other=['id'=>201,'company_id'=>2];
okB2B((int)$pub->invoke(null,$db,$a,2,10)['id']===2,'Exclusive A is available to assigned partner A');
deniedB2B(fn()=>$pub->invoke(null,$db,$b,2,20),'Exclusive A denied to partner B direct publication API');
deniedB2B(fn()=>$pub->invoke(null,$db,$b,2,10),'Partner B cannot spoof partner A agency ID');
okB2B((int)$pub->invoke(null,$db,$b,1,20)['id']===1,'Common tour available to partner B');
deniedB2B(fn()=>$pub->invoke(null,$db,$b,5,20),'Cross-company publication denied');
deniedB2B(fn()=>$work->invoke(null,$db,$b,14),'Old forbidden exclusive working copy denied to B');
deniedB2B(fn()=>$work->invoke(null,$db,$b,15),'Revoked publication working copy denied');
deniedB2B(fn()=>$work->invoke(null,$db,$b,13),'Cross-agency working copy denied');
$ids=static fn(array $u)=>array_map('intval',array_column($list->invoke(null,$db,$u)->fetchAll(PDO::FETCH_ASSOC),'id'));
okB2B($ids($a)===[11,13],'A lists only assigned common and exclusive copies');
okB2B($ids($b)===[12,16],'B list hides exclusive A, revoked copies and other agencies');
okB2B($ids($other)===[17],'Other company lists only its own active working copies');
$db->exec("UPDATE b2b_publications SET status='REVOKED' WHERE id=4");
okB2B($ids($b)===[12],'Revocation removes formerly accessible exclusive tour from list');
deniedB2B(fn()=>$work->invoke(null,$db,$b,16),'Revoked exclusive tour direct access denied');
echo 'B2B partner tenant/access isolation fixture complete.'.PHP_EOL;

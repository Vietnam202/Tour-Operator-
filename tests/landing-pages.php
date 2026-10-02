<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/LandingPages.php';
function check(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);echo "PASS $s\n";}
function rejects(callable $f,string $t):bool{try{$f();}catch(Throwable $e){return $e instanceof $t;}return false;}
$block=['id'=>'lp-12345678','type'=>'hero','title'=>'Vietnam','body'=>'Travel details','image'=>'','alt'=>'','rights'=>'','button'=>'Contact','url'=>'https://example.invalid/contact'];
$block+=['secondary'=>'','deadline'=>'','form_url'=>'','items'=>[]];
$doc=['schema'=>1,'title'=>'Vietnam tour','description'=>'Sample','brand'=>'VTA','accent'=>'#087f8c','language'=>'en','blocks'=>[$block]];
check(LandingPages::document($doc)===$doc,'structured document normalized');
foreach(['javascript:alert(1)','http://example.invalid','https://u:p@example.invalid','https:\\example.invalid'] as $url){$x=$doc;$x['blocks'][0]['url']=$url;check(rejects(fn()=>LandingPages::document($x),InvalidArgumentException::class),'unsafe link rejected');}
foreach(['type'=>'script','id'=>'bad','image'=>'https://example.invalid/image.jpg'] as $k=>$v){$x=$doc;$x['blocks'][0][$k]=$v;check(rejects(fn()=>LandingPages::document($x),InvalidArgumentException::class),'invalid block type/id/image metadata rejected');}
$x=$doc;$x['blocks'][]=$block;check(rejects(fn()=>LandingPages::document($x),InvalidArgumentException::class),'duplicate block ID rejected');
$x=$doc;$x['blocks']=array_fill(0,41,$block);check(rejects(fn()=>LandingPages::document($x),InvalidArgumentException::class),'oversize document rejected');
class LandingFixturePDO extends PDO {public function prepare(string $q,array $o=[]):PDOStatement|false{return parent::prepare(str_replace(['INSERT IGNORE',' FOR UPDATE','NOW()'],['INSERT OR IGNORE','','CURRENT_TIMESTAMP'],$q),$o);}}
$db=new LandingFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE campaigns(id INTEGER,company_id INTEGER,status TEXT); INSERT INTO campaigns VALUES(1,10,'ACTIVE'),(2,20,'ACTIVE'),(3,10,'ARCHIVED'); CREATE TABLE marketing_landing_pages(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,campaign_id INTEGER,client_key TEXT,title TEXT,document_json TEXT,version_no INTEGER DEFAULT 1,created_by INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(company_id,client_key));");
$u=['id'=>7,'company_id'=>10];$b=['client_key'=>'lp-create-test-12345','document'=>$doc,'campaign_id'=>1];$db->beginTransaction();$r=LandingPages::save($db,$u,$b);$db->commit();check($r['version_no']===1&&$r['document']===$doc,'draft save and load roundtrip');
$db->beginTransaction();$again=LandingPages::save($db,$u,$b);$db->commit();check($r['id']===$again['id']&&(int)$db->query('SELECT COUNT(*) FROM marketing_landing_pages')->fetchColumn()===1,'create retry idempotent');
$changed=$b;$changed['document']['title']='Changed';check(rejects(fn()=>LandingPages::save($db,$u,$changed),DomainException::class),'changed payload cannot reuse original creation key');
$changed['id']=$r['id'];$changed['version_no']=1;$db->beginTransaction();$r2=LandingPages::save($db,$u,$changed);$db->commit();check($r2['version_no']===2&&$r2['title']==='Changed','update increments version and saves content');
check(rejects(fn()=>LandingPages::save($db,$u,$changed),DomainException::class),'stale save rejected without overwriting newer content');
check(rejects(fn()=>LandingPages::get($db,20,$r['id']),OutOfBoundsException::class),'cross-company read rejected');
check(rejects(fn()=>LandingPages::save($db,['id'=>8,'company_id'=>20],array_merge($changed,['campaign_id'=>null])),OutOfBoundsException::class),'cross-company update rejected');
foreach([2,3] as $cid)check(rejects(fn()=>LandingPages::save($db,$u,array_merge($b,['campaign_id'=>$cid])),OutOfBoundsException::class),'foreign or archived campaign rejected');
check(LandingPages::get($db,10,$r['id'])['title']==='Changed','rejected operations preserve saved document');
$db->exec("CREATE TABLE lead_forms(id INTEGER,company_id INTEGER,campaign_id INTEGER,public_token TEXT,status TEXT)");
$token=str_repeat('a',64);$db->prepare('INSERT INTO lead_forms VALUES(1,10,1,?,?)')->execute([$token,'ACTIVE']);
$form=$doc;$form['blocks'][0]['type']='hero_form';$form['blocks'][0]['form_url']='https://vta.test/request.html?form='.$token;
$_SERVER['HTTP_HOST']='vta.test';$_SERVER['SCRIPT_NAME']='/api/index.php';
$db->beginTransaction();$savedForm=LandingPages::save($db,$u,['client_key'=>'lp-form-draft-12345','campaign_id'=>1,'document'=>$form]);$db->commit();check($savedForm['document']['blocks'][0]['form_url']===$form['blocks'][0]['form_url'],'same-company campaign form saved');
$bad=$form;$bad['blocks'][0]['form_url']='https://other.test/request.html?form='.$token;check(rejects(fn()=>LandingPages::save($db,$u,['client_key'=>'lp-form-other-12345','campaign_id'=>1,'document'=>$bad]),InvalidArgumentException::class),'foreign form host rejected');
$bad=$form;$bad['blocks'][0]['form_url']='https://vta.test/request.html?form='.str_repeat('b',64);check(rejects(fn()=>LandingPages::save($db,$u,['client_key'=>'lp-form-token-12345','campaign_id'=>1,'document'=>$bad]),OutOfBoundsException::class),'unowned form token rejected');
$bad=$form;$bad['blocks'][0]['deadline']='2026-02-30T12:00:00Z';check(rejects(fn()=>LandingPages::document($bad),InvalidArgumentException::class),'invalid countdown date rejected');
$bad['blocks'][0]['deadline']='2026-12-01T12:00:00Z';check(LandingPages::document($bad)['blocks'][0]['deadline']==='2026-12-01T12:00:00Z','valid fixed countdown date preserved');
$bad=$form;$bad['blocks'][0]['items']=[['title'=>'Gallery','image'=>'https://example.invalid/photo.jpg']];check(rejects(fn()=>LandingPages::document($bad),InvalidArgumentException::class),'gallery image without rights rejected');
$bad['blocks'][0]['items'][0]+=['alt'=>'Real photo','rights'=>'Owned'];check(count(LandingPages::document($bad)['blocks'][0]['items'])===1,'extended gallery item roundtrip');
// Authorization boundary test: fake Auth denial must occur before storage mutation.
class Auth {public static array $seen=[];public static function requirePermission(PDO $db,array $u,string $p):void{self::$seen[]=$p;throw new LogicException('Denied by fixture');}}
foreach(['GET'=>'lead.view','POST'=>'campaign.manage'] as $method=>$permission){check(rejects(fn()=>LandingPages::handle('marketing/landing-pages',$method,$db,$u),LogicException::class)&&end(Auth::$seen)===$permission,'handler requires '.$permission.' before data access');}
echo "NOTE: SQLite sequential fixture, not MariaDB DDL/concurrency or HTTP authentication testing.\n";

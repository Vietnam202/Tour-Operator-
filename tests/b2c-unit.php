<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/Http.php';
require_once __DIR__.'/../api/lib/Auth.php';
require_once __DIR__.'/../api/lib/Audit.php';
require_once __DIR__.'/../api/lib/QuoteVs2Repository.php';
require_once __DIR__.'/../api/lib/B2CStudio.php';
require_once __DIR__.'/../api/lib/B2CPublicationBridge.php';
require_once __DIR__.'/../integrations/wordpress/vta-connect/includes/class-vta-b2c-publications.php';
// SQLite covers domain behavior only. The native CI suite separately covers MariaDB SQL/locking/migrations.
final class B2CTestDatabase extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(preg_replace('/\s+FOR UPDATE\b/','',$query),$options);}
}
function ok(bool $condition,string $label): void {if(!$condition)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function denied(callable $f,string $class,string $label): void {try{$f();}catch(Throwable $e){ok($e instanceof $class,$label);return;}throw new RuntimeException('Unexpected acceptance: '.$label);}
$db=new B2CTestDatabase('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach([
 'CREATE TABLE companies(id INTEGER PRIMARY KEY)',
 'CREATE TABLE users(id INTEGER PRIMARY KEY,role_id INTEGER)',
 'CREATE TABLE permissions(id INTEGER PRIMARY KEY,code TEXT)',
 'CREATE TABLE role_permissions(role_id INTEGER,permission_id INTEGER)',
 'CREATE TABLE user_permissions(user_id INTEGER,permission_id INTEGER,effect TEXT)',
 'CREATE TABLE tour_library_programs(id INTEGER PRIMARY KEY,company_id INTEGER,title TEXT,destination TEXT,language TEXT,status TEXT,proposal_json TEXT,days_json TEXT)',
 "CREATE TABLE b2c_programs(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,master_program_id INTEGER,content_json TEXT,creation_key TEXT,created_by INTEGER,updated_by INTEGER,revision INTEGER DEFAULT 1,status TEXT DEFAULT 'DRAFT',approved_revision INTEGER,approved_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(company_id,creation_key))",
 'CREATE TABLE b2c_revisions(program_id INTEGER,revision INTEGER,content_json TEXT,actor_id INTEGER,PRIMARY KEY(program_id,revision))',
 'CREATE TABLE b2c_publication_events(id INTEGER PRIMARY KEY AUTOINCREMENT,company_id INTEGER,program_id INTEGER,revision INTEGER,status TEXT,snapshot_json TEXT,snapshot_hash TEXT)',
 'CREATE TABLE audit_logs(company_id INTEGER,user_id INTEGER,action_code TEXT,entity_type TEXT,entity_id INTEGER,before_json TEXT,after_json TEXT,request_id TEXT,ip_address TEXT)'
] as $sql)$db->exec($sql);
$db->exec('INSERT INTO companies VALUES(1),(2)');$db->exec('INSERT INTO users VALUES(1,1)');$i=0;foreach(['b2c.view','b2c.edit','b2c.approve','tour_library.view'] as $perm){$db->prepare('INSERT INTO permissions VALUES(?,?)')->execute([++$i,$perm]);$db->prepare('INSERT INTO role_permissions VALUES(1,?)')->execute([$i]);}
$u=['id'=>1,'company_id'=>1];$document=['schema'=>'VTA_DOC_2','title'=>'Master','content'=>['type'=>'doc','content'=>[['type'=>'paragraph','content'=>[['type'=>'text','text'=>'Public itinerary']]]]]];
$db->prepare("INSERT INTO tour_library_programs VALUES(1,1,'Master','Hanoi','en','ACTIVE',?,'[]')")->execute([json_encode(['document'=>$document])]);$master=$db->query('SELECT * FROM tour_library_programs')->fetch();
$request=['master_program_id'=>1,'creation_key'=>'unit-b2c-creation-0001'];$p=B2CStudio::create($db,$u,$request);$id=$p['id'];ok(B2CStudio::create($db,$u,$request)['id']===$id,'idempotent creation');
denied(fn()=>B2CStudio::row($db,['company_id'=>2],$id),OutOfBoundsException::class,'company isolation');
denied(fn()=>B2CStudio::transition($db,$u,$id,'publish',['expected_revision'=>1]),DomainException::class,'draft publish denied');
B2CStudio::transition($db,$u,$id,'review',['expected_revision'=>1]);denied(fn()=>B2CStudio::transition($db,$u,$id,'approve',['expected_revision'=>1]),DomainException::class,'approval review required');B2CStudio::transition($db,$u,$id,'approve',['expected_revision'=>1,'public_content_reviewed'=>true]);B2CStudio::transition($db,$u,$id,'publish',['expected_revision'=>1]);
$event=$db->query('SELECT * FROM b2c_publication_events')->fetch();B2CStudio::transition($db,$u,$id,'publish',['expected_revision'=>1]);ok((int)$db->query('SELECT COUNT(*) FROM b2c_publication_events')->fetchColumn()===1,'idempotent publication');
$cfg=['app'=>['base_url'=>'https://example.invalid'],'integrations'=>['b2c_publications'=>['unit_source'=>['enabled'=>true,'company_id'=>1,'secret'=>str_repeat('s',48)]]]];$pull=['timestamp'=>time(),'nonce'=>str_repeat('a',32),'after_id'=>0];$raw=json_encode($pull);$signed='sha256='.hash_hmac('sha256',$raw,str_repeat('s',48));$feed=B2CPublicationBridge::pull($db,$cfg,'unit_source',$raw,$signed);$wp=VTA_B2C_Publications::verify($feed['body'],$feed['signature'],$pull,['source'=>'unit_source','secret'=>str_repeat('s',48)]);ok(count($wp['events'])===1,'signed publication feed');
denied(fn()=>B2CPublicationBridge::pull($db,$cfg,'unit_source',$raw,'sha256='.str_repeat('0',64)),OutOfBoundsException::class,'invalid signature denied');denied(fn()=>VTA_B2C_Publications::verify($feed['body'].'x',$feed['signature'],$pull,['source'=>'unit_source','secret'=>str_repeat('s',48)]),RuntimeException::class,'response tamper denied');
$pull['nonce']=str_repeat('b',32);denied(fn()=>VTA_B2C_Publications::verify($feed['body'],$feed['signature'],$pull,['source'=>'unit_source','secret'=>str_repeat('s',48)]),RuntimeException::class,'response replay denied');
$ctx=B2CStudio::context($db,$u,B2CStudio::row($db,$u,$id));$body=['expected_revision'=>1,'settings'=>$ctx['settings']];$body['settings']['document']['title']='B2C edit';$saved=B2CStudio::save($db,$u,$id,$body);ok($saved['program']['revision']===2&&$saved['program']['status']==='DRAFT'&&$saved['program']['approved_revision']===null,'editing clears approval');
denied(fn()=>B2CStudio::publication($db,1,(int)$event['id']),OutOfBoundsException::class,'withdrawn publication denied');denied(fn()=>B2CStudio::save($db,$u,$id,$body),DomainException::class,'stale edit denied');ok($db->query('SELECT snapshot_json FROM b2c_publication_events WHERE id=1')->fetchColumn()===$event['snapshot_json'],'immutable prior snapshot');ok($db->query('SELECT * FROM tour_library_programs')->fetch()===$master,'master untouched');ok((int)$db->query('SELECT COUNT(*) FROM b2c_revisions')->fetchColumn()===2,'revision history retained');

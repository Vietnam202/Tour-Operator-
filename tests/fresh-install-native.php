<?php
declare(strict_types=1);
// Disposable localhost only. No existing database is selected or dropped.
$port=(int)(getenv('VTA_TEST_DB_PORT')?:13362);
$password=getenv('VTA_TEST_DB_PASSWORD')?:throw new RuntimeException('Test credentials required');
if($port<1024||$port>65535)throw new RuntimeException('Invalid local test port');
$db=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='vta_test_install_'.bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE `$database`");
$private=sys_get_temp_dir().'/'.$database;
mkdir($private,0700);
$config=['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1','timezone'=>'Asia/Ho_Chi_Minh'],
 'db'=>['host'=>'127.0.0.1','port'=>$port,'database'=>$database,'username'=>'root','password'=>$password],
 'storage'=>['driver'=>'local','local_path'=>$private.'/documents']];
file_put_contents($private.'/config.php',"<?php return ".var_export($config,true).";\n");
$env=getenv();$env['VTA_CONFIG_FILE']=$private.'/config.php';$env['VTA_ADMIN_PASSWORD']='Synthetic-Installer-Only-2026';
$run=static function()use($env):int{
 $process=proc_open([PHP_BINARY,__DIR__.'/../api/bin/install.php','--email=fixture@example.invalid','--name=Fixture Admin'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($process))throw new RuntimeException('Cannot start installer');
 $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 $code=proc_close($process);if($code!==0&&$code!==2)throw new RuntimeException('Installer failed: '.$error.$output);return $code;
};
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";};
$check($run()===0,'Actual CLI installs empty database');
$roles=$db->query('SELECT code FROM roles ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
$check($roles===['ADMIN','FINANCE','MARKETING','OPERATIONS','PARTNER','PRODUCT','SALES'],'Seven roles exist after fresh migrations');
$grants=static function(string $role)use($db):array{$q=$db->prepare('SELECT p.code FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.code=?');$q->execute([$role]);return $q->fetchAll(PDO::FETCH_COLUMN);};
$check(!array_diff(['lead.sales_accept','media.view','media.manage','proposal.edit','proposal.send','b2c.view','b2c.edit'],$grants('SALES')),'Fresh Sales receives lead acceptance, document media/proposal and B2C grants');
$check(!array_diff(['b2c.view','b2c.edit','b2c.approve'],$grants('PRODUCT')),'Fresh Product receives B2C approval grants');
$check($grants('PARTNER')===['b2b.portal'],'Partner has only scoped portal permission');
$check(!array_intersect(['quote.view_cost','finance.view','b2b.admin','lead.sales_accept'],$grants('MARKETING')),'Marketing cannot acquire financial, partner admin or Sales acceptance permissions');
$before=$db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
$check($run()===2&&$before===$db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC),'Repeat installer refuses existing users without resetting credentials');
$check((int)$db->query("SELECT COUNT(*) FROM migration_checksums WHERE status<>'APPLIED'")->fetchColumn()===0,'Migration ledger has no failed or partial migration');
echo "Fixture database retained: $database\n";
// Synthetic accounts for optional real-browser acceptance of this fresh install.
$insert=$db->prepare("INSERT INTO users(company_id,role_id,full_name,email,password_hash,status) SELECT company_id,id,name,?,?,'ACTIVE' FROM roles WHERE code=?");
foreach($roles as $code)$insert->execute([strtolower($code).'@fixture.invalid',password_hash($env['VTA_ADMIN_PASSWORD'],PASSWORD_DEFAULT),$code]);
if($target=getenv('VTA_FRESH_FIXTURE_CONFIG_POINTER'))file_put_contents($target,$private.'/config.php');

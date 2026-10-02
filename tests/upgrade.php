<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/Migrations.php';
function verify(bool $value,string $label): void {if(!$value)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";}
$password=getenv('VTA_TEST_DB_PASSWORD');if(!$password)throw new RuntimeException('VTA_TEST_DB_PASSWORD required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='vta_upgrade_'.bin2hex(random_bytes(4));$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
$files=glob(__DIR__.'/../api/migrations/00[1-6]*.sql');sort($files);
foreach($files as $file)foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($file)))) as $sql)$db->exec($sql);
$db->exec("INSERT INTO companies(code,name) VALUES('EXISTING','Existing company')");
$db->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'ADMIN','Admin'),(1,'SALES','Sales')");
$db->exec("INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,1,'Existing admin','existing@example.invalid','preserve-this-hash')");
$before=$db->query('SELECT * FROM users')->fetchAll();
$applied=Migrations::run($db,__DIR__.'/../api/migrations');
$migrationFiles=glob(__DIR__.'/../api/migrations/*.sql')?:[];sort($migrationFiles,SORT_STRING);
$manifest=array_map(fn($file)=>basename($file,'.sql'),$migrationFiles);$legacy=array_map(fn($file)=>basename($file,'.sql'),$files);
verify(in_array('021_tour_library',$manifest,true),'upgrade manifest includes the RC5.3 baseline');
verify($applied===array_values(array_diff($manifest,$legacy)),'upgrade applies every manifest migration after the legacy baseline');
verify($db->query('SELECT * FROM users')->fetchAll()===$before,'upgrade preserves existing users and password hashes');
verify((int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code='SALES' AND p.code IN ('lead.view','lead.manage','campaign.manage','product.view')")->fetchColumn()===4,'existing sales role gets additive permissions');
verify(Migrations::run($db,__DIR__.'/../api/migrations')===[],'upgraded schema rerun is a no-op');
verify($db->query("SELECT version FROM migration_checksums WHERE status='APPLIED' ORDER BY version")->fetchAll(PDO::FETCH_COLUMN)===$manifest,'every legacy and new migration checksum recorded by filename');
$db->exec("UPDATE migration_checksums SET status='FAILED' WHERE version='008_tour_inventory'");
try{Migrations::run($db,__DIR__.'/../api/migrations');throw new LogicException('Accepted failed migration');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'Partial migration'),'partial migration blocks automatic retry');}
echo "Upgrade fixture verified; deployed staging schema has not been inspected.\n";

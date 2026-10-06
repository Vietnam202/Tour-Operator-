<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/Migrations.php';
function check22(bool $value,string $label): void {if(!$value)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$pass=getenv('VTA_TEST_DB_PASSWORD');if(!$pass)throw new RuntimeException('Disposable local database password required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$name='vta_vs22_upgrade_'.bin2hex(random_bytes(5));$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
$baseline=sys_get_temp_dir().'/vs22-baseline-'.bin2hex(random_bytes(6));mkdir($baseline);$files=glob(__DIR__.'/../api/migrations/*.sql');$old=[];foreach($files as $f)if((int)basename($f)<29){copy($f,$baseline.'/'.basename($f));$old[basename($f)]=hash_file('sha256',$f);}
check22(count(Migrations::run($db,$baseline))===26,'VS21 baseline has exact 26 historical migration entries');
$db->exec("INSERT INTO companies(code,name) VALUES('KEEP','Existing tenant')");$db->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'ADMIN','Existing admin')");$db->exec("INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,1,'Existing owner','owner@example.invalid','preserved-hash')");
$db->exec("INSERT INTO trips(company_id,trip_ref,title,created_by,updated_by) VALUES(1,'TRIP-KEEP','Historical tour',1,1)");$db->exec("INSERT INTO quotes(company_id,quote_ref,trip_id,created_by,updated_by,status) VALUES(1,'QUOTE-KEEP',1,1,1,'SENT')");$db->exec("INSERT INTO quote_versions(quote_id,version_no,version_status,tour_name,schedule_json,proposal_json,sent_snapshot_json,created_by) VALUES(1,1,'SENT','Historical tour','[{\"day\":1,\"title\":\"Keep\"}]','{\"old\":true}','{\"immutable\":true}',1)");$db->exec("INSERT INTO quote_sent_bundles(quote_version_id,public_snapshot_json,internal_snapshot_json,content_hash,sent_by) VALUES(1,'{\"immutable\":true}','{\"private\":true}',REPEAT('a',64),1)");
$before=[];foreach(['users','quote_versions','quote_sent_bundles'] as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
check22(Migrations::run($db,__DIR__.'/../api/migrations')===['029_vs22_media_library','030_vs22_proposal_links'],'VS22 upgrade applies only 029 and 030');
foreach($before as $table=>$rows)check22($db->query('SELECT * FROM '.$table)->fetchAll()===$rows,'VS22 upgrade preserves '.$table.' bytes');
foreach($old as $file=>$hash)check22(hash_file('sha256',__DIR__.'/../api/migrations/'.$file)===$hash,'VS22 historical migration unchanged '.$file);
foreach(['media_assets','media_favourites','media_drive_folders','media_drive_links','media_assignments','proposal_public_links'] as $table)check22((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'VS22 additive table starts empty '.$table);
check22(Migrations::run($db,__DIR__.'/../api/migrations')===[],'VS22 migration rerun NO_OP');
check22((int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('media_assets','media_favourites','media_drive_folders','media_drive_links','media_assignments','proposal_public_links') AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn()===16,'VS22 16 foreign keys present');
try{$db->exec("INSERT INTO media_favourites(asset_id,user_id) VALUES(99999,1)");throw new LogicException('Missing FK validation');}catch(PDOException $e){check22($e->getCode()==='23000','VS22 foreign key rejects orphan asset');}
echo "VS2.2 additive upgrade complete; disposable database retained.\n";

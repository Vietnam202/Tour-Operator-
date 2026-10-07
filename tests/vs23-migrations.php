<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/Migrations.php';
function gate23(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";}
$pass=getenv('VTA_TEST_DB_PASSWORD')?:throw new RuntimeException('Disposable local database password required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$name='vta_vs23_upgrade_'.bin2hex(random_bytes(5));$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
$baseline=sys_get_temp_dir().'/vs23-baseline-'.bin2hex(random_bytes(5));mkdir($baseline);$old=[];foreach(glob(__DIR__.'/../api/migrations/*.sql') as $file)if((int)basename($file)<=30){copy($file,$baseline.'/'.basename($file));$old[basename($file)]=hash_file('sha256',$file);}
gate23(count(Migrations::run($db,$baseline))===28,'VS23 upgrade starts at exact VS22 28-entry baseline');
$db->exec("INSERT INTO companies(code,name) VALUES('KEEP','Existing company')");foreach(['ADMIN','SALES','OPERATIONS','FINANCE','PRODUCT'] as $role)$db->prepare('INSERT INTO roles(company_id,code,name) VALUES(1,?,?)')->execute([$role,$role]);
$db->exec("INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,1,'Existing owner','upgrade@example.invalid','unchanged')");
$db->exec("INSERT INTO trips(company_id,trip_ref,title,created_by,updated_by) VALUES(1,'T-KEEP','Existing itinerary',1,1)");$db->exec("INSERT INTO quotes(company_id,quote_ref,trip_id,created_by,updated_by,status) VALUES(1,'Q-KEEP',1,1,1,'SENT')");$db->exec("INSERT INTO quote_versions(quote_id,version_no,version_status,tour_name,schedule_json,proposal_json,sent_snapshot_json,created_by) VALUES(1,1,'SENT','Keep','[]','{}','{\"immutable\":true}',1)");$db->exec("INSERT INTO quote_sent_bundles(quote_version_id,public_snapshot_json,internal_snapshot_json,content_hash,sent_by) VALUES(1,'{\"public\":true}','{\"private\":true}',REPEAT('a',64),1)");
$before=[];foreach(['users','trips','quotes','quote_versions','quote_sent_bundles'] as $table)$before[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
gate23(Migrations::run($db,__DIR__.'/../api/migrations')===['031_vs23_commercial_policies','032_vs23_price_matrices','033_vs23_commercial_acceptance','034_vs23_sales_operations_handover','035_quote_confirmation_roles'],'VS23 applies four additive schema migrations and the approved confirmation grant');
foreach($before as $table=>$rows)gate23($db->query('SELECT * FROM '.$table)->fetchAll()===$rows,'VS23 preserves historical rows '.$table);
foreach($old as $file=>$hash)gate23(hash_file('sha256',__DIR__.'/../api/migrations/'.$file)===$hash,'VS23 historical migration unchanged '.$file);
foreach(['commercial_policies','quote_price_matrices','quote_price_matrix_cells','commercial_overrides','quote_commercial_acceptances','booking_handovers','booking_handover_services','booking_handover_events','booking_change_requests'] as $table)gate23((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'VS23 child table starts empty '.$table);
$grants=$db->query("SELECT r.code role,p.code permission FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE p.code LIKE 'commercial.%' OR p.code LIKE 'sales.handover_%' OR p.code LIKE 'operations.handover_%' OR p.code='booking.change_request_manage'")->fetchAll();gate23(count($grants)===8&&array_unique(array_column($grants,'role'))===['ADMIN'],'VS23 exact human-approved ADMIN-only grants');
try{$db->exec("INSERT INTO booking_handovers(booking_id,checklist_json,source_hash,sales_notes,created_by) VALUES(9999,'{}',REPEAT('a',64),'',1)");throw new LogicException('Orphan accepted');}catch(PDOException $e){gate23($e->getCode()==='23000','VS23 handover FK rejects booking without accepted snapshot');}
gate23(Migrations::run($db,__DIR__.'/../api/migrations')===[],'VS23 migration rerun NO_OP');
echo "VS2.3 additive upgrade complete.\n";

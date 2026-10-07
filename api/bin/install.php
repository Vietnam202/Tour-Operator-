<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$configPath=getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($configPath)){ fwrite(STDERR,"Missing config: $configPath\nSet VTA_CONFIG_FILE or create vta_private/config.php\n"); exit(1); }
$config=require $configPath; date_default_timezone_set($config['app']['timezone']??'Asia/Ho_Chi_Minh');
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
try { RuntimeGuard::config($configPath,$config); } catch(Throwable $e) { fwrite(STDERR,"Refused: unsafe staging configuration\n");exit(2); }
require_once dirname(__DIR__).'/lib/Database.php';require_once dirname(__DIR__).'/lib/Http.php';
$db=Database::connect($config['db']);
if($db->query("SHOW TABLES LIKE 'users'")->fetchColumn()&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()>0){fwrite(STDERR,"Existing users detected. Use migrate.php for upgrades; installer will not reset accounts.\n");exit(2);}
$args=[];foreach(array_slice($argv,1) as $a){if(str_starts_with($a,'--') && str_contains($a,'=')){[$k,$v]=explode('=',substr($a,2),2);$args[$k]=$v;}}
$email=strtolower(trim($args['email']??''));$name=trim($args['name']??'VTA Administrator');$password=$args['password']??(getenv('VTA_ADMIN_PASSWORD')?:'');
if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10){fwrite(STDERR,"Usage: VTA_ADMIN_PASSWORD=... php api/bin/install.php --email=admin@example.com --name=\"VTA Admin\"
For safer shell history: read -s VTA_ADMIN_PASSWORD; export VTA_ADMIN_PASSWORD; php api/bin/install.php --email=... --name=...\n");exit(2);} 
require_once dirname(__DIR__).'/lib/Migrations.php';
Migrations::run($db,dirname(__DIR__).'/migrations');
$db->beginTransaction();
$db->exec("INSERT INTO companies(code,name,timezone,base_currency) VALUES('VTA','Vietnam Travel Advisor','Asia/Ho_Chi_Minh','USD') ON DUPLICATE KEY UPDATE name=VALUES(name)");
$companyId=(int)$db->query("SELECT id FROM companies WHERE code='VTA'")->fetchColumn();
$roles=['ADMIN'=>'CEO / Admin','SALES'=>'Sales','PRODUCT'=>'Product / Contracting','OPERATIONS'=>'Operations','FINANCE'=>'Finance'];
$roleIds=[];$st=$db->prepare('INSERT INTO roles(company_id,code,name,is_system) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name)');
foreach($roles as $code=>$label){$st->execute([$companyId,$code,$label]);$q=$db->prepare('SELECT id FROM roles WHERE company_id=? AND code=?');$q->execute([$companyId,$code]);$roleIds[$code]=(int)$q->fetchColumn();}
$all=array_column($db->query('SELECT code FROM permissions')->fetchAll(),'code');
$map=[
 'ADMIN'=>$all,
 'PRODUCT'=>['ai.chat','tour_library.view','tour_library.manage','product.view','product.manage','supplier.view','supplier.create','supplier.edit','supplier.archive','document.view','document.upload','document.review','rate.view','rate.create','rate.edit','rate.approve','rate.archive','rate.reconcile','rate.compare','task.view','audit.view'],
 'SALES'=>['ai.chat','tour_library.view','travel_document.view','product.view','lead.view','lead.manage','campaign.manage','sales.view','customer.manage','inquiry.manage','quote.create','quote.edit','quote.send','quote.confirm','quote.view_cost','supplier.view','document.view','rate.view','booking.view','task.view'],
 'OPERATIONS'=>['quote.confirm','ai.chat','tour_library.view','travel_document.view','travel_document.manage','travel_document.issue','booking.view','booking.manage','guest.manage','operations.view','service.manage','supplier_order.create','supplier_order.send','supplier_order.confirm','document.booking_upload','booking.complete','supplier.view','document.view','rate.view','supplier_ap.view','task.view'],
 'FINANCE'=>['booking.view','finance.view','customer_ar.view','customer_payment.record','supplier_ap.view','supplier_payment.record','profit.view','finance.close','document.booking_upload','report.view','supplier.view','document.view','rate.view','task.view'],
];
$permId=[];foreach($db->query('SELECT id,code FROM permissions')->fetchAll() as $p)$permId[$p['code']]=(int)$p['id'];
$ins=$db->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)');foreach($map as $role=>$perms){foreach($perms as $p){if(isset($permId[$p]))$ins->execute([$roleIds[$role],$permId[$p]]);}}
$hash=password_hash($password,PASSWORD_DEFAULT);$find=$db->prepare('SELECT id FROM users WHERE company_id=? AND email=?');$find->execute([$companyId,$email]);$existing=$find->fetchColumn();
if($existing){$db->prepare("UPDATE users SET role_id=?,full_name=?,password_hash=?,status='ACTIVE' WHERE id=?")->execute([$roleIds['ADMIN'],$name,$hash,$existing]);$adminId=(int)$existing;}else{$db->prepare("INSERT INTO users(company_id,role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,?,'ACTIVE')")->execute([$companyId,$roleIds['ADMIN'],$name,$email,$hash]);$adminId=(int)$db->lastInsertId();}
$db->commit();
echo "VTA v3 staging installed successfully.\nCompany ID: $companyId\nAdmin ID: $adminId\nAdmin email: $email\nIMPORTANT: remove command history containing the password and keep private config outside public_html.\n";

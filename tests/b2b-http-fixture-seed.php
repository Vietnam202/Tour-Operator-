<?php
declare(strict_types=1);
/**
 * Create two real-auth B2B partner accounts on the already-created local HTTP
 * fixture schema. NEVER use this script against a live or staging database.
 */
$configPath=(string)getenv('VTA_V5_HTTP_CONFIG');
$password=(string)getenv('VTA_V5_TEST_PASSWORD');
$dbPassword=(string)getenv('VTA_TEST_DB_PASSWORD');
if(!$configPath || !$password || strlen($password)<12 || !$dbPassword
   ||(string)getenv('VTA_V5_HTTP_URL')!=='http://127.0.0.1:18869'
   ||(int)getenv('VTA_V5_HTTP_PORT')!==18869
   ||!is_file($configPath))
    throw new RuntimeException('B2B_LOCAL_HTTP_FIXTURE_REQUIRED');
$config=require $configPath;
$database=(string)($config['db']['database']??'');
$testPort=(int)(getenv('VTA_TEST_DB_PORT')?:33317);
if(!preg_match('/^vta_test_[0-9a-f]{10}$/D',$database)
   ||($config['app']['env']??'')!=='testing'
   ||($config['db']['host']??'')!=='127.0.0.1'
   ||$testPort<1024||$testPort>65535
   ||(int)($config['db']['port']??0)!==$testPort
   ||($config['app']['base_url']??'')!=='http://127.0.0.1:18869')
    throw new RuntimeException('Refusing non-disposable B2B database fixture');
$db=new PDO('mysql:host=127.0.0.1;port='.$testPort.';dbname='.$database.';charset=utf8mb4','root',$dbPassword,
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
   PDO::ATTR_EMULATE_PREPARES=>false]);
$role=$db->query("SELECT id FROM roles WHERE company_id=1 AND code='PARTNER'")->fetchColumn();
// The fixture inserts synthetic companies *after* all migrations, whereas
// production company-onboarding creates per-company roles at company creation.
// Create only the two synthetic users' necessary PARTNER role inside vta_test_*.
if(!$role){
    $db->exec("INSERT INTO roles(company_id,code,name,is_system) VALUES(1,'PARTNER','B2B Fixture Partner',1)");
    $role=(int)$db->lastInsertId();
}
$db->prepare("INSERT IGNORE INTO role_permissions(role_id,permission_id)
    SELECT ?,id FROM permissions WHERE code='b2b.portal'")->execute([(int)$role]);
$permission=$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=".(int)$role." AND p.code='b2b.portal'")->fetchColumn();
if((int)$permission!==1)throw new RuntimeException('Partner role missing scoped portal grant');
$secret=password_hash($password,PASSWORD_DEFAULT);
$insert=$db->prepare("INSERT INTO users(company_id,role_id,full_name,email,password_hash,status) VALUES(1,?,?,?,?,'ACTIVE')");
$partners=[['Partner A','partner-a@example.invalid','Agency A'],['Partner B','partner-b@example.invalid','Agency B']];
foreach($partners as [$person,$email,$agency]){
    $insert->execute([(int)$role,$person,$email,$secret]);
    $id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO b2b_agencies(company_id,agency_name,brand_name) VALUES(1,?,?)')->execute([$agency,$agency]);
    $agencyId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO b2b_agency_members(company_id,agency_id,user_id) VALUES(1,?,?)')->execute([$agencyId,$id]);
    if(!$id||!$agencyId)throw new RuntimeException('Fixture partner creation failed');
}
$check=$db->query("SELECT COUNT(*) FROM b2b_agency_members m JOIN users u ON u.id=m.user_id WHERE m.company_id=1 AND u.email IN ('partner-a@example.invalid','partner-b@example.invalid')")->fetchColumn();
if((int)$check!==2)throw new RuntimeException('Missing two independent partner sessions');
echo "PASS B2B two actual PARTNER-role accounts, two memberships on disposable ".$database." (no staging or supplier data)\n";

<?php
declare(strict_types=1);

/**
 * CI-only: the parent fixture provisions a disposable random vta_test_* schema.
 * This file prepares *synthetic* HTTP credentials/config outside the repo.
 * Never invoke it against staging/production; never reuse VTA live passwords.
 */
require __DIR__.'/rc62-v5-template-persistence-native.php';
require __DIR__.'/b2c-native-assertions.php';
require_once __DIR__.'/../api/lib/RuntimeGuard.php';

$port=(int)(getenv('VTA_V5_HTTP_PORT') ?: 18869);
$conf=(string)getenv('VTA_V5_HTTP_CONFIG');
$password=(string)getenv('VTA_V5_TEST_PASSWORD');
$dbPassword=(string)getenv('VTA_TEST_DB_PASSWORD');
$private=realpath(dirname($conf));
$repository=realpath(dirname(__DIR__));
if (!preg_match('/^vta_test_[a-f0-9]{10}$/D', $database)
    || (string)$db->query('SELECT DATABASE()')->fetchColumn()!==$database
    || $port!==18869 || !$conf || !$password || !$dbPassword
    || !$private || !$repository || RuntimeGuard::inside($private,$repository)
    || !str_ends_with($conf,'/config.php')
) throw new RuntimeException('V5_HTTP_FIXTURE_ISOLATION_REQUIRED');

$hash=password_hash($password,PASSWORD_DEFAULT);
$q=$db->prepare('UPDATE users SET password_hash=? WHERE email=? AND company_id=?');
foreach ([['tester@example.invalid',1],['other@example.invalid',2]] as [$email,$company]) {
    $q->execute([$hash,$email,$company]);
    check($q->rowCount()===1,'V5 HTTP fixture: synthetic login account exists for company '.$company);
}

// Read-only same-company account, granted only Master/B2C view permissions.
$db->prepare("INSERT INTO roles(company_id,code,name) VALUES(1,'V5_VIEWER','V5 Fixture Viewer')")->execute();
$viewerRole=(int)$db->lastInsertId();
$db->prepare("INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,?,'V5 Viewer','viewer@example.invalid',?)")->execute([$viewerRole,$hash]);
$db->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code IN ('tour_library.view','b2c.view')")->execute([$viewerRole]);
check($viewerRole>0
    && (int)$db->query("SELECT COUNT(*) FROM role_permissions WHERE role_id=".$viewerRole)->fetchColumn()===2,
    'V5 HTTP fixture: viewer has only Master/B2C view permissions');

@mkdir($private.'/documents',0700,true);
$config=[
    'app'=>[
        'name'=>'VTA CI V5 HTTP Fixture', 'env'=>'testing',
        'timezone'=>'Asia/Ho_Chi_Minh',
        'base_url'=>'http://127.0.0.1:18869',
        'session_name'=>'vta_v5_http_fixture',
        'session_lifetime'=>1800,
    ],
    'db'=>[
        'host'=>'127.0.0.1','port'=>(int)(getenv('VTA_TEST_DB_PORT')?:33317),
        'database'=>$database,'username'=>'root','password'=>$dbPassword,
        'charset'=>'utf8mb4'
    ],
    'storage'=>['driver'=>'local','local_path'=>$private.'/documents'],
    'security'=>['allowed_origin'=>'http://127.0.0.1:18869','max_upload_bytes'=>15728640]
];
file_put_contents($conf, "<?php\nreturn ".var_export($config,true).";\n");
chmod($conf,0600);
RuntimeGuard::config($conf, $config);
echo "PASS V5 HTTP fixture: isolated local host, config outside repo and disposable DB configured\n";

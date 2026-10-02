<?php
declare(strict_types=1);
require __DIR__.'/../../api/lib/Migrations.php';
if(!getenv('VTA_TEST_DB_PASSWORD'))throw new RuntimeException('Set VTA_TEST_DB_PASSWORD for the disposable local server');
$privateDir=dirname(__DIR__,3).'/runtime';if(!is_dir($privateDir))mkdir($privateDir,0700,true);
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root',getenv('VTA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='vta_http_'.bin2hex(random_bytes(4));$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
Migrations::run($db,__DIR__.'/../../api/migrations');
$db->exec("INSERT INTO companies(code,name) VALUES('HTTP','HTTP test')");
$db->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'ADMIN','Admin'),(1,'VIEWER','Viewer')");
$db->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT 1,id FROM permissions');
$hash=password_hash('Local-Http-Test-2026',PASSWORD_DEFAULT);
$st=$db->prepare('INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(1,?,?,?,?)');
$st->execute([1,'Admin','admin@example.invalid',$hash]);$st->execute([2,'Viewer','viewer@example.invalid',$hash]);
$cfg=['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:8873','timezone'=>'Asia/Ho_Chi_Minh','session_name'=>'vta_test_http'],
'db'=>['host'=>'127.0.0.1','port'=>33317,'database'=>$name,'username'=>'root','password'=>getenv('VTA_TEST_DB_PASSWORD'),'charset'=>'utf8mb4'],
'security'=>['allowed_origin'=>'http://127.0.0.1:8873','allowed_extensions'=>['pdf','docx','xlsx','csv','txt'],'max_upload_bytes'=>12582912],'storage'=>['driver'=>'local','local_path'=>dirname(__DIR__,3).'/runtime/private-files']];
file_put_contents(dirname(__DIR__,3).'/runtime/http-config.php',"<?php\nreturn ".var_export($cfg,true).";\n");
echo "Prepared $name\n";

<?php
declare(strict_types=1);
// Synthetic loopback-only fixture. Never accepts a production config or DB name.
require_once __DIR__.'/../api/lib/Migrations.php';
$port=(int)(getenv('VTA_TEST_DB_PORT')?:33317);
$password=getenv('VTA_TEST_DB_PASSWORD');
if(!$password || $port<1024)throw new RuntimeException('Disposable MariaDB required');
$db=new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4",'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$mode=$argv[1]??'';
if($mode==='seed'){
    $database='vta_test_'.bin2hex(random_bytes(5));
    $private=realpath($argv[2]??'');
    if(!$private || str_starts_with(str_replace('\\','/',$private),str_replace('\\','/',realpath(dirname(__DIR__)))))throw new RuntimeException('Private fixture directory must be outside docroot');
    $db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("USE `$database`");
    $versions=Migrations::run($db,__DIR__.'/../api/migrations');
    if(!in_array('049_inquiry_soft_delete',$versions,true) || Migrations::run($db,__DIR__.'/../api/migrations')!==[])throw new RuntimeException('Migration 049 or idempotency failed');
    $db->exec("INSERT INTO companies(code,name) VALUES('V15A','Synthetic A'),('V15B','Synthetic B')");
    $db->exec("INSERT INTO roles(company_id,code,name) VALUES(1,'ADMIN','Admin'),(2,'ADMIN','Admin'),(1,'VIEWER','Viewer')");
    $hash=password_hash(getenv('VTA_INQUIRY_TEST_PASSWORD'),PASSWORD_DEFAULT);
    $st=$db->prepare('INSERT INTO users(company_id,role_id,full_name,email,password_hash) VALUES(?,?,?,?,?)');
    foreach([[1,1,'A','a@example.invalid'],[2,2,'B','b@example.invalid'],[1,3,'Viewer','viewer@example.invalid']] as $u)$st->execute([...$u,$hash]);
    $db->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.id IN (1,2)');
    $db->exec("INSERT INTO role_permissions(role_id,permission_id) SELECT 3,id FROM permissions WHERE code IN ('sales.view','task.view')");
    @mkdir($private.'/media');
    $config=['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:18872','timezone'=>'Asia/Ho_Chi_Minh','session_name'=>'vta_inquiry_test'],'db'=>['host'=>'127.0.0.1','port'=>$port,'database'=>$database,'username'=>'root','password'=>$password,'charset'=>'utf8mb4'],'security'=>['allowed_origin'=>'http://127.0.0.1:18872'],'storage'=>['driver'=>'local','local_path'=>$private.'/media']];
    file_put_contents($private.'/config.php',"<?php\nreturn ".var_export($config,true).";\n");
    echo json_encode(['database'=>$database,'migrations'=>count($versions),'server'=>$db->query('SELECT VERSION()')->fetchColumn()]);
}else{
    $database=$argv[2]??'';
    if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$database))throw new RuntimeException('Disposable database name required');
    $db->exec("USE `$database`");
    if($mode==='sql'){
        $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
        $st=$db->prepare($input['sql']);$st->execute($input['args']??[]);
        echo json_encode($st->columnCount()?$st->fetchAll():['changed'=>$st->rowCount()]);
    }elseif($mode==='drop'){$db->exec("DROP DATABASE `$database`");echo '{}';}
    else throw new RuntimeException('Unknown fixture command');
}

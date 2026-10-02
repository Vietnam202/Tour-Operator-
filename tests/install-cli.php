<?php
declare(strict_types=1);
$password=getenv('VTA_TEST_DB_PASSWORD');if(!$password)throw new RuntimeException('VTA_TEST_DB_PASSWORD required');
$db=new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='vta_install_'.bin2hex(random_bytes(5));$db->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$db->exec("USE `$name`");
$dir=dirname(__DIR__,2).'/runtime';if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/install-test-'.bin2hex(random_bytes(5)).'.php';
$cfg=['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:8873'],'db'=>['host'=>'127.0.0.1','port'=>33317,'database'=>$name,'username'=>'root','password'=>$password,'charset'=>'utf8mb4']];
file_put_contents($path,"<?php return ".var_export($cfg,true).";\n");
$env=array_replace(getenv(),['VTA_CONFIG_FILE'=>$path,'VTA_ADMIN_PASSWORD'=>'Synthetic-Install-Test-2026']);
$run=function()use($env){$cmd=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql',__DIR__.'/../api/bin/install.php','--email=installer@example.invalid'];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);if(!is_resource($p))throw new RuntimeException('Cannot run installer');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];};
try{
 [$code,$out,$err]=$run();if($code!==0)throw new RuntimeException('Fresh install failed: '.$err);echo "PASS fresh CLI install\n";
 $migrationFiles=glob(__DIR__.'/../api/migrations/*.sql')?:[];sort($migrationFiles,SORT_STRING);$manifest=array_map(fn($file)=>basename($file,'.sql'),$migrationFiles);
 if(!in_array('021_tour_library',$manifest,true)||$db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)!==$manifest)throw new RuntimeException('Migration manifest mismatch');echo "PASS fresh installer applies complete migration filename manifest including RC5.3\n";
 $hash=$db->query('SELECT password_hash FROM users LIMIT 1')->fetchColumn();if(!password_verify($env['VTA_ADMIN_PASSWORD'],$hash))throw new RuntimeException('Admin password not initialized');echo "PASS fresh admin account initialized\n";
 [$code,$out,$err]=$run();if($code!==2||!str_contains($err,'Existing users detected'))throw new RuntimeException('Installer rerun not blocked');if($hash!==$db->query('SELECT password_hash FROM users LIMIT 1')->fetchColumn())throw new RuntimeException('Account changed');echo "PASS installer rerun refuses to reset existing users\n";
}finally{unlink($path);}

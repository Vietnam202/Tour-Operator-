<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"FAIL Private configuration missing\n");exit(1);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}catch(Throwable $e){fwrite(STDERR,"FAIL Unsafe staging configuration\n");exit(2);}
$checks=[];
try{
 $c=$config['db'];$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $checks['Migration 019 applied']=(bool)$db->query("SELECT 1 FROM migration_checksums WHERE version='019_marketing_studio' AND status='APPLIED'")->fetchColumn();
 $checks['Migration 020 applied']=(bool)$db->query("SELECT 1 FROM migration_checksums WHERE version='020_landing_pages' AND status='APPLIED'")->fetchColumn();
 foreach(['marketing_landing_pages','marketing_content','marketing_library','marketing_batches','marketing_inbox','lead_requests'] as $table)$checks[$table]=(bool)$db->query("SHOW TABLES LIKE '$table'")->fetchColumn();
 $checks['Planning metadata']=(bool)$db->query("SHOW COLUMNS FROM marketing_content LIKE 'planned_at'")->fetchColumn();
}catch(Throwable $e){$checks['Database and RC5 schema ready']=false;}
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)$fail++;}
echo "Publishing and social inbox sync remain disabled. No external request was made.\n";exit($fail?2:0);

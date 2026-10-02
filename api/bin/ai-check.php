<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/AiProvider.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"FAIL Private config missing\n");exit(1);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}catch(Throwable $e){fwrite(STDERR,"FAIL Unsafe staging configuration\n");exit(2);}
$s=AiProvider::settings($config);$checks=['AI enabled'=>$s['enabled'],'API key configured'=>$s['key']!=='','Model configured'=>$s['model']!=='','PHP cURL available'=>function_exists('curl_init')];
try{
 $c=$config['db'];$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$c['host'],$c['port']??3306,$c['database']),$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $checks['Migration 018 applied']=(bool)$db->query("SELECT 1 FROM migration_checksums WHERE version='018_ai_chat' AND status='APPLIED'")->fetchColumn();
 foreach(['ai_threads','ai_turns','ai_daily_usage'] as $table)$checks['Table '.$table]=(bool)$db->query("SHOW TABLES LIKE '$table'")->fetchColumn();
 $checks['AI permission exists']=(bool)$db->query("SELECT 1 FROM permissions WHERE code='ai.chat'")->fetchColumn();
}catch(Throwable $e){$checks['Database and AI schema available']=false;}
$fail=0;foreach($checks as $label=>$ok){echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$fail++;}
echo "No credentials printed. No provider request made. Live model access must be tested in AI Chat.\n";
exit($fail?2:0);

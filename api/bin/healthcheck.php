<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$configPath=getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__,3).'/vta_private/config.php';
$checks=[];$checks[]=['Config file',is_file($configPath),$configPath];
if(!is_file($configPath)){foreach($checks as $c)echo ($c[1]?'PASS':'FAIL')."  {$c[0]}  {$c[2]}\n";exit(1);} $config=require $configPath;
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
try{RuntimeGuard::config($configPath,$config);}catch(Throwable $e){fwrite(STDERR,"Refused: unsafe staging configuration\n");exit(2);}
require_once dirname(__DIR__).'/lib/Http.php';require_once dirname(__DIR__).'/lib/Database.php';
try{$db=Database::connect($config['db']);$db->query('SELECT 1');$checks[]=['Database connection',true,''];$checks[]=['Core schema',(bool)$db->query("SHOW TABLES LIKE 'suppliers'")->fetchColumn(),'suppliers table'];}catch(Throwable $e){$checks[]=['Database connection',false,$e->getMessage()];}
$driver=$config['storage']['driver']??'local';$checks[]=['Storage driver',in_array($driver,['google_drive','local'],true),$driver];
if($driver==='google_drive'){$oauth=!empty($config['storage']['google_oauth_refresh_token']);$sa=!empty($config['storage']['google_service_account_json'])&&is_file($config['storage']['google_service_account_json']);$folder=!empty($config['storage']['google_drive_folder_id'])&&$config['storage']['google_drive_folder_id']!=='CHANGE_ME';$checks[]=['Google authorization configured',$oauth||$sa,$oauth?'OAuth refresh token':'service account'];$checks[]=['Google Drive folder configured',$folder,$config['storage']['google_drive_folder_id']??''];}
$fail=0;foreach($checks as $c){echo ($c[1]?'PASS':'FAIL')."  {$c[0]}".($c[2]?'  '.$c[2]:'')."\n";if(!$c[1])$fail++;}exit($fail?2:0);

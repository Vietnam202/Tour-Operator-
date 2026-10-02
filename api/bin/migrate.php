<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(403);exit; }
require_once dirname(__DIR__).'/lib/Migrations.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)) { fwrite(STDERR,"Private config missing\n");exit(1); }
$c=require $path;
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
try { RuntimeGuard::config($path,$c); } catch(Throwable $e) { fwrite(STDERR,"Refused: unsafe staging configuration\n");exit(2); }
$host=parse_url($c['app']['base_url']??'',PHP_URL_HOST);
if(!in_array($c['app']['env']??'',['staging','testing'],true) || !in_array($host,['v2quote.vietnamtraveladvisor.com.vn','127.0.0.1','localhost'],true)) {
    fwrite(STDERR,"Refused: this upgrade is restricted to staging/local testing\n");exit(2);
}
try {
    $cfg=$c['db'];
    $db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],$cfg['port']??3306,$cfg['database']),$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach(Migrations::run($db,dirname(__DIR__).'/migrations') as $v) echo "Applied $v\n";
    echo "Migrations verified. User accounts were not modified.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}

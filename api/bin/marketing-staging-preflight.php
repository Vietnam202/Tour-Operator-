<?php
declare(strict_types=1);
/**
 * Print a redacted, read-only readiness report. Never migrates, deploys,
 * connects to a provider, modifies files or enables background workers.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/MarketingStagingGate.php';
$root=dirname(__DIR__,2);
if(in_array('--help',$argv,true)){
    echo "php api/bin/marketing-staging-preflight.php [--db]\nNo --db: source-only dry-run. --db: also inspect current staging migration ledger read-only.\n";exit(0);
}
$items=MarketingStagingGate::inspectSource($root);
if(in_array('--db',$argv,true)){
    $cfgPath=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
    if(!is_file($cfgPath)){fwrite(STDERR,"Private staging configuration not found\n");exit(3);}
    require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
    $cfg=require $cfgPath;
    try {RuntimeGuard::config($cfgPath,$cfg);}
    catch(Throwable $e){fwrite(STDERR,"Target failed strict staging guard\n");exit(3);}
    $items=array_merge($items,MarketingStagingGate::inspectRuntime(
        $cfg,'v2quote.vietnamtraveladvisor.com.vn'));
    try{
        $dbCfg=$cfg['db'];
        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbCfg['host'],$dbCfg['port']??3306,$dbCfg['database']);
        $db=new PDO($dsn,$dbCfg['username'],$dbCfg['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
        $items=array_merge($items,MarketingStagingGate::inspectLedger($db,$root));
    }catch(Throwable $e){
        $items[]=['id'=>'DB_CONNECTION','level'=>'BLOCK','description'=>'Staging database inaccessible for read-only preflight'];
    }
}
$report=MarketingStagingGate::summary($items);
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($report['safe_to_release']?0:2);

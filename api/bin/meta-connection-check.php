<?php
declare(strict_types=1);
/**
 * P9 read-only Meta account health CLI.
 * It performs no token exchange, OAuth login, Page subscription, publishing or DM send.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/MetaConnectionHealth.php';

if(!in_array('--check',$argv,true)){
    echo "NOOP — no API request. Use --check on staging with a specific --alias.\n";
    exit(0);
}
if(getenv('VTA_ALLOW_META_CONNECTION_CHECK')!=='STAGING_READ_ONLY'){
    fwrite(STDERR,"Refused: enable staging-only read-only check explicitly\n");exit(3);
}
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"Private configuration unavailable\n");exit(2);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}
catch(Throwable $e){fwrite(STDERR,"Staging-only RuntimeGuard denied this host\n");exit(2);}
$alias='';$company=0;$product='';
foreach($argv as $arg){
    if(str_starts_with($arg,'--alias='))$alias=substr($arg,8);
    if(str_starts_with($arg,'--company='))$company=(int)substr($arg,10);
    if(str_starts_with($arg,'--product='))$product=substr($arg,10);
}
if($company<1||!preg_match('/^[a-z0-9_-]{3,64}$/D',$alias)
   ||!in_array($product,['MESSENGER','INSTAGRAM_DM','FACEBOOK_PAGE','INSTAGRAM_PUBLISH'],true)){
    fwrite(STDERR,"Specify --company=ID --alias=CODE --product=PRODUCT\n");exit(3);
}
$matching=array_values(array_filter(MetaConnectionHealth::inventory($config,$company),
    static fn(array $a)=>$a['alias']===$alias&&$a['product']===$product));
if(count($matching)!==1){
    fwrite(STDERR,"No uniquely configured Meta account for this company and product\n");exit(3);
}
$db=Database::connect($config['db']);
$report=MetaConnectionHealth::probe($config,$matching[0]);
MetaConnectionHealth::store($db,$company,$matching[0],$report);
echo "Meta connection read-only verification: ".$report['status'].
    " (".$report['error_code']??'') .")\n";
if($report['status']!=='VERIFIED')exit(4);

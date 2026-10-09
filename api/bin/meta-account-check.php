<?php
declare(strict_types=1);
/**
 * Read-only Meta account check on reviewed staging/test host.
 * No token printed. No credentials persisted. No provider publishing requests.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/SocialPublishing.php';
require_once dirname(__DIR__).'/lib/MetaGraphTransport.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"Private config unavailable\n");exit(2);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}
catch(Throwable $e){fwrite(STDERR,"Forbidden runtime: staging/testing only\n");exit(2);}
if(!in_array('--verify',$argv,true)){
    echo "READ-ONLY NOOP. Use --verify on staging after provisioning Meta tokens.\n";
    exit(0);
}
$alias='';
foreach($argv as $arg)if(str_starts_with($arg,'--alias='))$alias=substr($arg,8);
$company=(int)(getenv('VTA_VERIFY_COMPANY_ID')?:0);
$account=SocialPublishing::account($config,$company,$alias);
if($company<1||!$account||!$account['ready']){
    fwrite(STDERR,"Account not enabled or required permissions not configured\n");exit(3);
}
try {
    $id=$account['account_id'];
    $ver=(string)($account['config']['graph_version']??'v26.0');
    if(!preg_match('/^v[0-9]{1,2}\.[0-9]$/D',$ver))throw new InvalidArgumentException('API version invalid');
    $url='https://graph.facebook.com/'.$ver.'/'.$id;
    $data=MetaGraphTransport::request('GET',$url,['fields'=>'id'],(string)$account['config']['access_token']);
    if((string)($data['id']??'')!==$id)throw new RuntimeException('Account identity mismatch');
    echo "Provider read-only account identity check: VERIFIED (no publish permission test)\n";
}catch(Throwable $e){
    fwrite(STDERR,"Provider identity not verified. Check token, linked Page, Meta app review and account permissions.\n");
    exit(4);
}

<?php
declare(strict_types=1);
/** P7 durable Meta text event worker. No outbound provider messages or network calls. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/WebhookCenter.php';
require_once dirname(__DIR__).'/lib/WebsiteInbox.php';
require_once dirname(__DIR__).'/lib/MetaInbox.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"Private config not found\n");exit(2);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}
catch(Throwable $e){fwrite(STDERR,"Forbidden runtime. Staging/testing only.\n");exit(2);}
if(!in_array('--process',$argv,true)){
    echo "NOOP. No events processed. Use --process with private inbound flag on reviewed staging.\n";exit(0);
}
if(($config['integrations']['meta_inbox']['enabled']??false)!==true
   ||getenv('VTA_ALLOW_META_INBOUND_PROCESSING')!=='I_APPROVE_STAGING_META_INBOX'){
    fwrite(STDERR,"Meta inbox processing disabled\n");exit(3);
}
$db=Database::connect($config['db']);
$recover=MetaInbox::recover($db);
if($recover)echo "Recovered $recover abandoned Meta jobs\n";
for($i=0;$i<100;$i++){
    try{$event=MetaInbox::processOne($db);}
    catch(Throwable $e){fwrite(STDERR,"Queue error; no user data printed\n");exit(4);}
    if($event===null)break;
    echo 'Event #'.$event['id'].' => '.$event['status']."\n";
}

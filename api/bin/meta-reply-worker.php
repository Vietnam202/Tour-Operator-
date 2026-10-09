<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/MetaInbox.php';
require_once dirname(__DIR__).'/lib/MetaReplies.php';
require_once dirname(__DIR__).'/lib/MessengerSender.php';
$path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($path)){fwrite(STDERR,"Private config missing\n");exit(2);}
$config=require $path;
try{RuntimeGuard::config($path,$config);}
catch(Throwable $e){fwrite(STDERR,"Denied: staging/testing only\n");exit(2);}
if(!in_array('--send',$argv,true)){
    echo "NOOP: provider messaging disabled. Require explicit --send and staging approval.\n";exit(0);
}
if(getenv('VTA_ALLOW_META_REPLIES')!=='I_APPROVE_REAL_MESSENGER_RESPONSES'
    ||(MetaInbox::settings($config)['enabled']??false)!==true){
    fwrite(STDERR,"Denied: sender explicitly disabled\n");exit(3);
}
$db=Database::connect($config['db']);
MetaReplies::recover($db);
for($i=0;$i<10;$i++){
    try{$job=MetaReplies::claim($db,$config);}
    catch(Throwable $e){fwrite(STDERR,"Unable to claim Messenger queue\n");exit(4);}
    if($job===null)break;
    if($job['status']==='BLOCKED'){echo "Job #".$job['id']." BLOCKED\n";continue;}
    try{
        $mid=MessengerSender::request((string)$job['page_id'],(string)$job['recipient_id'],(string)$job['body'],$job['account']);
        MetaReplies::complete($db,(int)$job['id'],$mid);
        echo "Job #".$job['id']." SENT (provider confirmed)\n";
    }catch(Throwable $e){
        MetaReplies::complete($db,(int)$job['id'],null);
        fwrite(STDERR,"Job #".$job['id']." UNCERTAIN. Verify provider before manually creating another response\n");
    }
}

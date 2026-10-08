<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/SocialPublishing.php';
require_once dirname(__DIR__).'/lib/FacebookPagePoster.php';
$configPath=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
if(!is_file($configPath)){fwrite(STDERR,"Missing private config\n");exit(2);}
$config=require $configPath;
try{RuntimeGuard::config($configPath,$config);}
catch(Throwable $e){fwrite(STDERR,"Unsafe runtime: staging/testing only\n");exit(2);}
$live=in_array('--send',$argv,true);
if(!$live){
    echo "DRY/NOOP. No network call, no claim. Explicit --send and private gate required.\n";
    exit(0);
}
if(getenv('VTA_ALLOW_PROVIDER_POSTING')!=='I_ACKNOWLEDGE_REAL_POSTS'
   ||($config['integrations']['social_publishing']['enabled']??false)!==true){
    fwrite(STDERR,"Refused: live provider dispatch is disabled\n");exit(3);
}
$db=Database::connect($config['db']);
$stale=SocialPublishing::markAbandoned($db);
if($stale)fwrite(STDERR,"Marked $stale uncertain dispatches: requires manual reconciliation\n");
$count=0;
for($i=0;$i<5;$i++){
    try{$job=SocialPublishing::claim($db,$config);}
    catch(Throwable $e){fwrite(STDERR,"Queue claim failed\n");exit(4);}
    if($job===null)break;
    if(!empty($job['blocked'])){echo "Blocked job #".$job['id']."\n";continue;}
    $count++;
    try {
        $postId=FacebookPagePoster::publish($job['account'],$job['message']);
        SocialPublishing::complete($db,$job['id'],$postId);
        echo "Provider confirmed job #".$job['id']."\n";
    } catch(Throwable $e) {
        SocialPublishing::complete($db,$job['id'],null,'Not confirmed; manual reconcile, no automatic resend');
        fwrite(STDERR,"Uncertain job #".$job['id'].", manual reconciliation required\n");
    }
}
echo "Dispatch attempts: $count\n";

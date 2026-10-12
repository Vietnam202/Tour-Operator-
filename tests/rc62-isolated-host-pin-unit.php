<?php
declare(strict_types=1);
/** Synthetic only. No network, credentials, migrations or real staging files. */
require dirname(__DIR__).'/api/lib/RuntimeGuard.php';
$base=sys_get_temp_dir().'/vta-isolated-host-fixture-'.bin2hex(random_bytes(5));
if(!mkdir($base,0700) || !mkdir($base.'/private-storage',0700))
    throw new RuntimeException('Cannot create CI-only runtime guard fixture');
$configPath=$base.'/config.php';
file_put_contents($configPath,"<?php return [];".PHP_EOL);
$host='staging-fixture.example.invalid';
$dbName='vta_rc62_clone_fixture';
$origin='https://'.$host;
$valid=[
    'app'=>['env'=>'staging','base_url'=>$origin,'session_name'=>'vta_staging_session'],
    'db'=>['host'=>'127.0.0.1','database'=>$dbName],
    'security'=>['approved_staging_host'=>$host,'allowed_origin'=>$origin],
    'storage'=>['driver'=>'local','local_path'=>$base.'/private-storage']
];
$originalPins=[
  getenv('VTA_RC62_STAGING_HOST_PIN'),getenv('VTA_RC62_STAGING_DB_PIN')
];
$origHost=$_SERVER['HTTP_HOST']??null;$origHttps=$_SERVER['HTTPS']??null;
$passed=0;
function yes(bool $ok,string $label):void{
    global $passed;
    if(!$ok)throw new RuntimeException($label);
    $passed++;
    echo 'PASS '.$label.PHP_EOL;
}
function refuse(callable $check,string $label):void{
    try{$check();}
    catch(RuntimeException $e){
        yes($e->getMessage()==='ISOLATED_STAGING_HOST_NOT_APPROVED',$label);
        return;
    }
    throw new RuntimeException('Unsafe isolated staging accepted: '.$label);
}
try{
    unset($_SERVER['HTTP_HOST'],$_SERVER['HTTPS']);
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'new stage is blocked by default without independent pins');
    putenv('VTA_RC62_STAGING_HOST_PIN='.$host);
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'a host pin alone cannot authorize staging DB');
    putenv('VTA_RC62_STAGING_DB_PIN='.$dbName);
    RuntimeGuard::config($configPath,$valid);
    yes(true,'exact host + clone db pins permit only isolated candidate from private config');
    $_SERVER['HTTP_HOST']=$host;$_SERVER['HTTPS']='on';
    RuntimeGuard::config($configPath,$valid);
    yes(true,'approved HTTPS Host header accepted without trusting X-Forwarded-Host');
    $_SERVER['HTTP_HOST']='quote.vietnamtraveladvisor.com.vn';
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'production Host header cannot route to isolated staged candidate');
    $_SERVER['HTTP_HOST']=$host.':443';
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'Host with port is not exact pin');
    $_SERVER['HTTP_HOST']=$host;$_SERVER['HTTPS']='off';
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'HTTP cannot impersonate approved HTTPS stage');
    $_SERVER['HTTPS']='on';
    $bad=$valid;$bad['app']['env']='production';
    try{RuntimeGuard::config($configPath,$bad);throw new RuntimeException('STAGING_PRODUCTION_ENV_ACCEPTED');}
    catch(RuntimeException $e){yes($e->getMessage()==='STAGING_ENV_REQUIRED','production env rejected before stage evaluation');}
    $bad=$valid;$bad['app']['env']='testing';
    refuse(fn()=>RuntimeGuard::config($configPath,$bad),'new remote stage cannot run in testing env');
    foreach([
        ['http://'.$host,'cleartext HTTP base'],
        ['https://'.$host.':443','noncanonical explicit port'],
        ['https://'.$host.'/portal','app root path'],
        ['https://'.$host.'?key=1','query in base URL'],
        ['https://admin:password@'.$host,'credentials embedded in origin'],
        ['https://'.$host.'#frag','fragment in origin']
    ] as [$baseUrl,$label]){
        $bad=$valid;$bad['app']['base_url']=$baseUrl;
        refuse(fn()=>RuntimeGuard::config($configPath,$bad),'reject '.$label);
    }
    $bad=$valid;$bad['security']['allowed_origin']='https://different-stage.example.invalid';
    refuse(fn()=>RuntimeGuard::config($configPath,$bad),'origin must match approved staging URL');
    $bad=$valid;unset($bad['security']['approved_staging_host']);
    refuse(fn()=>RuntimeGuard::config($configPath,$bad),'private config exact staging host required');
    putenv('VTA_RC62_STAGING_HOST_PIN=other-staging.example.invalid');
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'independent host pin mismatch');
    putenv('VTA_RC62_STAGING_HOST_PIN='.$host);
    putenv('VTA_RC62_STAGING_DB_PIN=vta_other_clone');
    refuse(fn()=>RuntimeGuard::config($configPath,$valid),'independent DB pin mismatch');
    putenv('VTA_RC62_STAGING_DB_PIN='.$dbName);
    foreach(['v2qu_v2qu_vtaos','vta_production','vta_rc62_live','stage-with-hyphen'] as $db){
        $bad=$valid;$bad['db']['database']=$db;
        putenv('VTA_RC62_STAGING_DB_PIN='.$db);
        refuse(fn()=>RuntimeGuard::config($configPath,$bad),'reject unsafe database '.$db);
    }
    putenv('VTA_RC62_STAGING_DB_PIN='.$dbName);
    $bad=$valid;$bad['db']['host']='db.example.invalid';
    refuse(fn()=>RuntimeGuard::config($configPath,$bad),'remote database endpoint cannot be implicitly accepted');
    $bad=$valid;$bad['storage']['local_path']=dirname(__DIR__).'/api';
    refuse(fn()=>RuntimeGuard::config($configPath,$bad),'staging local storage cannot be inside application directory');
    foreach([
        'notstaging.example.invalid','stage-prod.example.invalid',
        'production-stage.example.invalid','quote.vietnamtraveladvisor.com.vn',
        '192.168.0.1','staged.evil.example.invalid'
    ] as $candidate){
        $bad=$valid;$bad['app']['base_url']='https://'.$candidate;
        $bad['security']['approved_staging_host']=$candidate;
        $bad['security']['allowed_origin']='https://'.$candidate;
        putenv('VTA_RC62_STAGING_HOST_PIN='.$candidate);
        refuse(fn()=>RuntimeGuard::config($configPath,$bad),'unknown/unsafe stage hostname '.$candidate);
    }
    // stage with a valid exact, independently pinned name is opt-in only.
    putenv('VTA_RC62_STAGING_HOST_PIN='.$host);
    RuntimeGuard::config($configPath,$valid);
    yes(true,'fully pinned valid host restored after negative cases');
    $legacy=$valid;$legacy['app']['base_url']='http://127.0.0.1:18869';$legacy['app']['env']='testing';
    putenv('VTA_RC62_STAGING_HOST_PIN');
    putenv('VTA_RC62_STAGING_DB_PIN');
    unset($_SERVER['HTTP_HOST'],$_SERVER['HTTPS']);
    RuntimeGuard::config($configPath,$legacy);
    yes(true,'legacy local CI fixture remains compatible without staging host opt-in');
    echo "PASS ".$passed." isolated staging host pin checks, no production changes".PHP_EOL;
} finally {
    foreach(['VTA_RC62_STAGING_HOST_PIN','VTA_RC62_STAGING_DB_PIN'] as $i=>$key){
        if($originalPins[$i]===false)putenv($key); else putenv($key.'='.$originalPins[$i]);
    }
    if($origHost===null)unset($_SERVER['HTTP_HOST']);else $_SERVER['HTTP_HOST']=$origHost;
    if($origHttps===null)unset($_SERVER['HTTPS']);else $_SERVER['HTTPS']=$origHttps;
    @unlink($configPath);@rmdir($base.'/private-storage');@rmdir($base);
}

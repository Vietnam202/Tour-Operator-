<?php
declare(strict_types=1);
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
$passed=0;
function stageCheck(bool $ok,string $name):void{
    global $passed;
    if(!$ok)throw new RuntimeException('TEST_FAILED_'.$name);
    $passed++;echo "PASS_STAGE_GUARD $name\n";
}
function stageDeny(callable $fn,string $name):void{
    try{$fn();}catch(RuntimeException $error){stageCheck(str_starts_with($error->getMessage(),'UNSAFE_'),$name);return;}
    throw new RuntimeException('UNEXPECTED_STAGE_ACCEPT_'.$name);
}
$root=realpath(dirname(__DIR__));
if(!$root)throw new RuntimeException('MISSING_REPO_ROOT');
$host='stage-rc62.vietnamtraveladvisor.com.vn';
$db='vta_rc62_clone_acceptance';$sha=str_repeat('a',40);
$config=[
 'app'=>['env'=>'staging','base_url'=>'https://'.$host],
 'db'=>['host'=>'127.0.0.1','port'=>3306,'database'=>$db],
 'security'=>[
    'allowed_origin'=>'https://'.$host,
    'staging_guard'=>['host'=>$host,'database'=>$db,'source_sha'=>$sha,'docroot'=>$root]
 ]
];
$pins=['host'=>$host,'db'=>$db,'sha'=>$sha];
$server=['HTTP_HOST'=>$host,'HTTPS'=>'on','DOCUMENT_ROOT'=>$root];
RuntimeGuard::isolatedStaging($config,$server,$pins);
stageCheck(true,'independently pinned HTTPS staging host/clone/docroot/source accepted');
$bad=static function(array $changes, string $section)use($config):array {
 $copy=$config;
 foreach($changes as $k=>$v)$copy[$section][$k]=$v;
 return $copy;
};
foreach([
 ['url-http',$bad(['base_url'=>'http://'.$host],'app')],
 ['url-prod',$bad(['base_url'=>'https://quote.vietnamtraveladvisor.com.vn'],'app')],
 ['url-v2quote',$bad(['base_url'=>'https://v2quote.vietnamtraveladvisor.com.vn'],'app')],
 ['url-lookalike',$bad(['base_url'=>'https://staging.vietnamtraveladvisor.com.vn.evil.example'],'app')],
 ['url-public-host',$bad(['base_url'=>'https://stage-rc62.example.invalid'],'app')],
 ['url-credentials',$bad(['base_url'=>'https://user:password@'.$host],'app')],
 ['url-query',$bad(['base_url'=>'https://'.$host.'/?admin=true'],'app')],
 ['url-nonstandard-port',$bad(['base_url'=>'https://'.$host.':8443'],'app')],
 ['testing-env',$bad(['env'=>'testing'],'app')],
 ['production-env',$bad(['env'=>'production'],'app')],
 ['wrong-database',$bad(['database'=>'vta_production'],'db')],
 ['remote-database',$bad(['host'=>'production.db.example.invalid'],'db')],
 ['wrong-origin',$bad(['allowed_origin'=>'https://quote.vietnamtraveladvisor.com.vn'],'security')],
 ['missing-guard',$bad(['staging_guard'=>null],'security')],
 ['wrong-guard-host',$bad(['staging_guard'=>array_replace($config['security']['staging_guard'],['host'=>'other-staging.vietnamtraveladvisor.vn'])],'security')],
 ['wrong-guard-db',$bad(['staging_guard'=>array_replace($config['security']['staging_guard'],['database'=>'vta_other_clone'])],'security')],
 ['wrong-guard-sha',$bad(['staging_guard'=>array_replace($config['security']['staging_guard'],['source_sha'=>str_repeat('b',40)])],'security')],
 ['wrong-guard-docroot',$bad(['staging_guard'=>array_replace($config['security']['staging_guard'],['docroot'=>'/not-a-real-docroot'])],'security')]
] as [$label,$variant])stageDeny(fn()=>RuntimeGuard::isolatedStaging($variant,$server,$pins),$label);
foreach([
 ['missing-host-pin',['host'=>'']],
 ['wrong-db-pin',['db'=>'vta_rc62_other_clone']],
 ['wrong-sha-pin',['sha'=>str_repeat('b',40)]]
] as [$label,$changes])stageDeny(fn()=>RuntimeGuard::isolatedStaging($config,$server,array_replace($pins,$changes)),$label);
foreach([
 ['host-spoof',['HTTP_HOST'=>'quote.vietnamtraveladvisor.com.vn']],
 ['host-header-port',['HTTP_HOST'=>$host.':443']],
 ['plaintext',['HTTPS'=>'off']],
 ['forwarded-only',['HTTPS'=>'','HTTP_X_FORWARDED_PROTO'=>'https']],
 ['wrong-document-root',['DOCUMENT_ROOT'=>'/tmp']],
 ['missing-document-root',['DOCUMENT_ROOT'=>null]]
] as [$label,$changes])stageDeny(fn()=>RuntimeGuard::isolatedStaging($config,array_replace($server,$changes),$pins),$label);
// Exactly independent operator pins are also enforced by the actual bootstrap
// entrypoint, not only by the helper's unit-level synthetic inputs.
$tmp=tempnam(sys_get_temp_dir(),'vta-stage-guard-');
if($tmp===false)throw new RuntimeException('TEMP_REQUIRED');
$saved=[];
foreach(['VTA_STAGING_PIN_HOST','VTA_STAGING_PIN_DB','VTA_STAGING_PIN_SHA'] as $name)$saved[$name]=getenv($name);
$oldServer=$_SERVER;
try{
  putenv('VTA_STAGING_PIN_HOST='.$host);
  putenv('VTA_STAGING_PIN_DB='.$db);
  putenv('VTA_STAGING_PIN_SHA='.$sha);
  $_SERVER=array_replace($_SERVER,$server);
  RuntimeGuard::config($tmp,$config);
  stageCheck(true,'private config + real process pins accepted by bootstrap API guard');
  putenv('VTA_STAGING_PIN_DB=unexpected_production');
  stageDeny(fn()=>RuntimeGuard::config($tmp,$config),'bootstrap-forged-db-pin');
  putenv('VTA_STAGING_PIN_DB='.$db);
  unset($_SERVER['HTTPS']);
  stageDeny(fn()=>RuntimeGuard::config($tmp,$config),'bootstrap-no-tls-denied');
  $_SERVER=array_replace($oldServer,$server);
  stageDeny(fn()=>RuntimeGuard::config($tmp,[
     'app'=>['env'=>'staging','base_url'=>'https://v2quote.vietnamtraveladvisor.com.vn']
  ]),'legacy-base-url-wrong-http-host-bypass-blocked');
  $_SERVER=$oldServer;
  RuntimeGuard::config($tmp,['app'=>['env'=>'testing','base_url'=>'http://127.0.0.1:18869']]);
  stageCheck(true,'existing disposable local HTTP fixture unchanged');
  RuntimeGuard::config($tmp,['app'=>['env'=>'staging','base_url'=>'https://v2quote.vietnamtraveladvisor.com.vn']]);
  stageCheck(true,'historical staging host compatibility retained without controller change');
}finally{
 $_SERVER=$oldServer;@unlink($tmp);
 foreach($saved as $name=>$value) {
   if($value===false)putenv($name);
   else putenv($name.'='.$value);
 }
}
echo "PASS_STAGE_GUARD total=".$passed." (synthetic CI, no live host or DB accessed)\n";

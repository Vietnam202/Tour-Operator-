<?php
declare(strict_types=1);
/**
 * Disposable ONLY. Repurposes the existing local vta_test_* fixture as an
 * isolated *simulated* staged host. DO NOT run on CyberPanel or real data.
 */
require_once __DIR__.'/../api/lib/RuntimeGuard.php';
$source=(string)getenv('VTA_V5_HTTP_CONFIG');
$port=(string)getenv('VTA_V5_HTTP_PORT');
$fixtureUrl=(string)getenv('VTA_V5_HTTP_URL');
$rootPassword=(string)getenv('VTA_TEST_DB_PASSWORD');
if($source===''||$port!=='18869'||$fixtureUrl!=='http://127.0.0.1:18869'
    ||$rootPassword===''||!is_file($source))
    throw new RuntimeException('STAGE_SIMULATION_DISPOSABLE_FIXTURE_REQUIRED');
$sourceDir=realpath(dirname($source));$repo=realpath(dirname(__DIR__));
if(!$sourceDir||!$repo||RuntimeGuard::inside($sourceDir,$repo)
    ||!str_ends_with($source,'/config.php'))
    throw new RuntimeException('STAGE_SIMULATION_CONFIG_MUST_BE_PRIVATE');
$cfg=require $source;
$name=(string)($cfg['db']['database']??'');
if(!preg_match('/^vta_test_[a-f0-9]{10}$/D',$name)
    ||($cfg['app']['env']??'')!=='testing'
    ||($cfg['db']['host']??'')!=='127.0.0.1'
    ||(int)($cfg['db']['port']??0)!==33317
    ||($cfg['app']['base_url']??'')!=='http://127.0.0.1:18869'
    ||($cfg['db']['password']??'')!==$rootPassword)
    throw new RuntimeException('STAGE_SIMULATION_DB_NOT_DISPOSABLE');
$host='staging-fixture.example.invalid'; // Never a real owned/customer DNS name.
$origin='https://'.$host;
$private=$sourceDir.'/staging-documents';
if(!is_dir($private)&&!mkdir($private,0700))
    throw new RuntimeException('STAGE_SIMULATION_PRIVATE_STORAGE_REQUIRED');
$cfg['app']['env']='staging';
$cfg['app']['base_url']=$origin;
$cfg['security']['allowed_origin']=$origin;
$cfg['security']['approved_staging_host']=$host;
$cfg['storage']['driver']='local';
$cfg['storage']['local_path']=$private;
$configOut=$sourceDir.'/stage-config.php';
$routerOut=$sourceDir.'/stage-router.php';
file_put_contents($configOut,"<?php\nreturn ".var_export($cfg,true).";\n");
chmod($configOut,0600);
$api=realpath(dirname(__DIR__).'/api/index.php');
if(!$api||RuntimeGuard::inside($sourceDir,$repo))
    throw new RuntimeException('STAGE_SIMULATION_ROUTER_INVALID');
// Explicitly simulated SSL termination for PHP's built-in HTTP server.
// Real staging MUST use separately verified, actual end-to-end HTTPS.
file_put_contents($routerOut, "<?php\n".
    "if(str_starts_with(\$_SERVER['REQUEST_URI']??'','/api/index.php')) {\n".
    "  \$_SERVER['HTTPS']='on';\n".
    "  require ".var_export($api,true).";\n".
    "  return true;\n".
    "}\nreturn false;\n");
chmod($routerOut,0600);
echo "PASS disposable stage config: new HTTPS host+DB+media pins; no actual TLS or staging host\n";

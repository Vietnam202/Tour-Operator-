<?php
declare(strict_types=1);
// Public capture endpoint. Does not expose authenticated routes or accept company IDs.
$configPath=getenv('VTA_CONFIG_FILE') ?: dirname(__DIR__,2).'/vta_private/config.php';
require_once __DIR__.'/lib/Http.php';
require_once __DIR__.'/lib/Database.php';
require_once __DIR__.'/lib/Audit.php';
require_once __DIR__.'/lib/LeadHub.php';
require_once __DIR__.'/lib/RuntimeGuard.php';
Http::requireMethod('POST');
if(!is_file($configPath)) Http::json(['ok'=>false,'error'=>'CONFIG_MISSING'],503);
$config=require $configPath;
try { RuntimeGuard::config($configPath,$config); } catch(Throwable $e) { Http::json(['ok'=>false,'error'=>'UNSAFE_STAGING_CONFIGURATION'],503); }
date_default_timezone_set($config['app']['timezone']??'Asia/Ho_Chi_Minh');
header('X-Content-Type-Options: nosniff');
$raw=file_get_contents('php://input',false,null,0,20001);
if($raw===false || strlen($raw)>20000) Http::json(['ok'=>false,'error'=>'BODY_TOO_LARGE'],413);
$body=json_decode($raw,true);
if(!is_array($body)) Http::json(['ok'=>false,'error'=>'INVALID_JSON'],400);
$token=$_GET['form']??'';
if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404);
try {
    $db=Database::connect($config['db']);
    Http::json(['ok'=>true]+LeadHub::submit($db,$token,$body),202);
} catch(InvalidArgumentException $e) { Http::json(['ok'=>false,'error'=>'VALIDATION','message'=>$e->getMessage()],422); }
catch(OutOfBoundsException $e) { Http::json(['ok'=>false,'error'=>'NOT_FOUND'],404); }
catch(OverflowException $e) { header('Retry-After: 60'); Http::json(['ok'=>false,'error'=>'RATE_LIMITED'],429); }
catch(DomainException $e) { Http::json(['ok'=>false,'error'=>'CONFLICT'],409); }
catch(Throwable $e) { error_log('Lead submission failed: '.Http::requestId()); Http::json(['ok'=>false,'error'=>'SERVER_ERROR'],500); }

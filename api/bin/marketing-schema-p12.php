<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/lib/MarketingSchemaUpgrade.php';
require_once dirname(__DIR__).'/lib/RuntimeGuard.php';

$root=dirname(__DIR__,2);
$directory=$root.'/api/migrations';
$mode=$argv[1]??'--manifest';
if($mode==='--help'){
    echo "Usage:\n";
    echo " php api/bin/marketing-schema-p12.php --manifest\n";
    echo " php api/bin/marketing-schema-p12.php --inspect\n";
    echo " php api/bin/marketing-schema-p12.php --apply-clone --fingerprint=SHA256 --backup=/secure/file.sql.gz --backup-sha256=SHA256\n";
    echo "Never writes staging or production; --apply-clone requires a testing localhost disposable clone.\n";
    exit(0);
}
if(!in_array($mode,['--manifest','--inspect','--apply-clone'],true)){
    fwrite(STDERR,"Refused: unsupported command\n");exit(2);
}
try {
    $manifest=MarketingSchemaUpgrade::manifest($directory);
    if($mode==='--manifest') {
        echo json_encode([
            'mode'=>'SOURCE_ONLY','fingerprint'=>$manifest['fingerprint'],
            'versions'=>$manifest['ordered_versions'],'db_writes'=>false,
            'staging_applied'=>false
        ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit(0);
    }
    $path=getenv('VTA_CONFIG_FILE')?:dirname(__DIR__,3).'/vta_private/config.php';
    if(!is_file($path))throw new RuntimeException('Missing private configuration');
    $config=require $path;
    RuntimeGuard::config($path,$config);
    $settings=$config['db']??[];
    $dbHost=strtolower((string)($settings['host']??''));
    if(!in_array($dbHost,['localhost','127.0.0.1'],true))
        throw new RuntimeException('Remote database access refused');
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $dbHost,(int)($settings['port']??3306),(string)($settings['database']??''));
    $db=new PDO($dsn,(string)($settings['username']??''),(string)($settings['password']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    if($mode==='--inspect'){
        $plan=MarketingSchemaUpgrade::inspect($db,$manifest);
        echo json_encode(['mode'=>'READ_ONLY','db_writes'=>false,'result'=>$plan,
            'staging_approved'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        exit($plan['pending']===[]?0:2);
    }
    if(getenv('VTA_ALLOW_P12_CLONE_APPLY')!=='DISPOSABLE_CLONE_ONLY')
        throw new RuntimeException('Clone-only DDL is disabled by default');
    MarketingSchemaUpgrade::cloneOnly($db,$config);
    $options=[];
    foreach(array_slice($argv,2) as $arg){
        if(!preg_match('/^--(fingerprint|backup|backup-sha256)=(.+)$/D',$arg,$m))
            throw new InvalidArgumentException('Unknown or malformed flag');
        if(isset($options[$m[1]]))throw new InvalidArgumentException('Duplicate option');
        $options[$m[1]]=$m[2];
    }
    foreach(['fingerprint','backup','backup-sha256'] as $needed)
        if(!isset($options[$needed]))throw new RuntimeException('Required clone confirmation or backup proof missing');
    $backupPath=$options['backup'];
    $actual=realpath($backupPath);
    if($actual===false||is_link($backupPath)||!is_file($actual)||!is_readable($actual)
        ||filesize($actual)<64||preg_match('/^[a-f0-9]{64}$/D',$options['backup-sha256'])!==1)
        throw new RuntimeException('Backup artifact missing or cannot be validated');
    RuntimeGuard::privatePath($actual);
    if(!hash_equals((string)hash_file('sha256',$actual),$options['backup-sha256']))
        throw new RuntimeException('Backup SHA-256 mismatch');
    // File hash only proves backup bytes were preserved, NOT that restoration
    // of all customer data succeeded; clone restoration is separately reviewed.
    $done=MarketingSchemaUpgrade::applyClone($db,$directory,$config,$options['fingerprint']);
    echo json_encode(['mode'=>'DISPOSABLE_CLONE_APPLIED','clone_only'=>true,
        'backup_file_hash_verified'=>true,'restore_drill_verified'=>false,
        'applied'=>$done['applied'],'manifest'=>$done['after']['manifest'],
        'production_or_staging_changed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit(0);
}catch(Throwable $e){
    // Deliberately avoid raw exception content (e.g. PDO DSN, credentials,
    // private paths, SQL fragments, or personally identifiable records).
    fwrite(STDERR,"P12 REFUSED: safety check failed; no automatic retry\n");
    exit(3);
}

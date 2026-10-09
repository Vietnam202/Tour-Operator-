<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/MarketingStagingGate.php';
$root=dirname(__DIR__);
function qa(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
}
$checks=MarketingStagingGate::inspectSource($root);
$all=MarketingStagingGate::summary($checks);
$ids=array_column($checks,'id');
qa(!$all['safe_to_release'],'unsafe candidate is BLOCKED, never auto-deployed');
qa(in_array('STAGING_DEPLOY_MIGRATIONS',$ids,true),'new migrations require deploy controller review');
qa(in_array('STAGING_BRANCH_PIN',$ids,true),'pinned testing branch cannot accept stacked PR automatically');
qa(in_array('STAGING_ACCEPTANCE_NOT_SIGNED',$ids,true),'source-only CI cannot impersonate human staging signoff');
qa(in_array('MIGRATION_ORDER_RECONCILED',$ids,true),'legacy 025 guard preserved and Marketing 023/024 safely deferred until after VS2.1');
qa(!in_array('VS21_MIGRATION_GUARD',$ids,true),'core-first migration plan eliminates the original ordering conflict');
qa(!in_array('MISSING_SOURCE',$ids,true),'all source files for P0–P9 present');
qa(!in_array('MIGRATION_ORDER',$ids,true)&&!in_array('MIGRATION_SEQUENCE',$ids,true),
    'marketing schema dependencies ordered correctly as source files');

$runtime=['app'=>['env'=>'staging','base_url'=>'https://v2quote.vietnamtraveladvisor.com.vn'],
 'integrations'=>['social_publishing'=>['enabled'=>false],'meta_inbox'=>['enabled'=>false]]];
qa(MarketingStagingGate::inspectRuntime($runtime,'v2quote.vietnamtraveladvisor.com.vn')===[],
    'read-only staging with publishing disabled has no runtime blockers');
$live=$runtime;
$live['app']['env']='production';$live['integrations']['social_publishing']['enabled']=true;
$liveChecks=MarketingStagingGate::inspectRuntime($live,'v2quote.vietnamtraveladvisor.com.vn');
qa(count(array_filter($liveChecks,fn($v)=>$v['level']==='BLOCK'))===2,
    'production target and enabled publishing fail closed');

$db=new PDO(getenv('VTA_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=vta_ci;charset=utf8mb4',
    getenv('VTA_TEST_USER')?:'vta',getenv('VTA_TEST_PASSWORD')?:'vta_ci_pw',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec("CREATE TABLE schema_migrations(version VARCHAR(64) PRIMARY KEY)");
$db->exec("CREATE TABLE migration_checksums(version VARCHAR(64) PRIMARY KEY,sha256 CHAR(64) NOT NULL,status ENUM('RUNNING','APPLIED','FAILED') NOT NULL)");
$pending=MarketingStagingGate::inspectLedger($db,$root);
qa(count(array_filter($pending,fn($v)=>$v['id']==='PENDING_MIGRATION'))===count(MarketingStagingGate::STAGES),
    'unapplied Marketing SQL blocks staging acceptance');
$insertApplied=$db->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
$insertTracked=$db->prepare("INSERT INTO migration_checksums(version,sha256,status) VALUES(?,?,'APPLIED')");
foreach(MarketingStagingGate::STAGES as $filename){
    $name=basename($filename,'.sql');
    $hash=hash_file('sha256',$root.'/api/migrations/'.$filename);
    $insertApplied->execute([$name]);
    $insertTracked->execute([$name,$hash]);
}
qa(MarketingStagingGate::inspectLedger($db,$root)===[],
    'read-only ledger accepts matching exact marketing migration checksums');
$db->exec("UPDATE migration_checksums SET sha256=REPEAT('a',64) WHERE version='042_meta_messenger_replies'");
$drift=MarketingStagingGate::inspectLedger($db,$root);
qa(in_array('LEDGER_DRIFT',array_column($drift,'id'),true),
    'historical migration checksum drift blocks release');
$db->exec("UPDATE migration_checksums SET status='FAILED' WHERE version='041_meta_unified_inbox'");
qa(count(array_filter(MarketingStagingGate::inspectLedger($db,$root),fn($v)=>$v['id']==='LEDGER_DRIFT'))>=2,
    'FAILED migration ledger row blocks release');
$report=MarketingStagingGate::summary(array_merge($checks,$drift));
qa($report['blockers']>=3&&$report['safe_to_release']===false,
    'final gate never silently upgrades blocked candidate');
echo "Marketing P0–P9 staging preflight acceptance tests completed.\n";

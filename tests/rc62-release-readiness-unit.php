<?php
declare(strict_types=1);
require __DIR__.'/../api/bin/rc62-release-readiness.php';

function requirePass(bool $test, string $label): void {
    if (!$test) throw new RuntimeException('FAIL '.$label);
    echo "PASS $label\n";
}
function mustReject(callable $run, string $code): void {
    try { $run(); } catch(RuntimeException $error) {
        requirePass($error->getMessage() === $code, 'Reject '.$code);
        return;
    }
    throw new RuntimeException('Expected block '.$code);
}

foreach (['../config.php', '/etc/passwd', 'api//index.php', './app.js',
    'api/../config.php', "assets/\0secret", 'api\\lib\\A.php'] as $path) {
    requirePass(!RC62ReleaseReadiness::safeRelative($path), 'Reject invalid relative paths');
}
requirePass(RC62ReleaseReadiness::safeRelative('api/bin/healthcheck.php'), 'Allow safe nested file');

$manifest=[
    'schema'=>'bad.schema',
    'source_commit'=>str_repeat('a',40),
    'release_type'=>'STAGING_CANDIDATE_NOT_PRODUCTION',
    'file_count'=>0,
    'sha256'=>[],
    'migration_count'=>0
];
mustReject(fn()=>RC62ReleaseReadiness::files(__DIR__,$manifest),'INVALID_RELEASE_MANIFEST');
$manifest['schema']='vta.rc62.runtime.manifest.v1';
mustReject(fn()=>RC62ReleaseReadiness::files(__DIR__,$manifest),'INCOMPLETE_RELEASE_MANIFEST');

$expected=[];
for($i=1;$i<=32;$i++) $expected['migration_'.str_pad((string)$i,3,'0',STR_PAD_LEFT)] = hash('sha256',(string)$i);
$versions=array_keys($expected);
$ledger=[];
foreach($expected as $name=>$sha) $ledger[]=['version'=>$name,'sha256'=>$sha,'status'=>'APPLIED'];

$ok=RC62ReleaseReadiness::ledger($expected,$versions,$ledger);
requirePass($ok['expected']===32 && $ok['applied']===32 && $ok['pending']===[], 'Accept all exact reviewed migration checksums');

$pending=RC62ReleaseReadiness::ledger($expected,array_slice($versions,0,-1),array_slice($ledger,0,-1));
requirePass($pending['pending']===[$versions[31]], 'Return pending migrations without applying any SQL');
$drift=$ledger;$drift[0]['sha256']=hash('sha256','changed');
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,$versions,$drift),'MIGRATION_CHECKSUM_OR_STATUS_MISMATCH');
$failed=$ledger;$failed[0]['status']='FAILED';
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,$versions,$failed),'MIGRATION_CHECKSUM_OR_STATUS_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,array_merge($versions,['unexpected']),$ledger),'UNEXPECTED_SCHEMA_VERSION');
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,array_merge($versions,[$versions[0]]),$ledger),'DUPLICATE_SCHEMA_VERSION');
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,$versions,array_merge($ledger,[$ledger[0]])),'DUPLICATE_MIGRATION_LEDGER');
$foreign=$ledger; $foreign[]=['version'=>'unreviewed','sha256'=>str_repeat('f',64),'status'=>'APPLIED'];
mustReject(fn()=>RC62ReleaseReadiness::ledger($expected,$versions,$foreign),'UNEXPECTED_MIGRATION_LEDGER');
echo "PASS RC6.2 read-only release gate unit checks; no PDO or config loaded.\n";

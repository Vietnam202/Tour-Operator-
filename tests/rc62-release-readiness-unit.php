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

// Operator must provide the externally pinned candidate SHA, not merely trust
// a self-declared commit in the downloaded manifest.
$pin=str_repeat('a',40);
RC62ReleaseReadiness::assertPinnedCommit($pin,$pin);
requirePass(true,'Match independent exact-head release SHA');
mustReject(fn()=>RC62ReleaseReadiness::assertPinnedCommit($pin,str_repeat('b',40)),'RELEASE_SHA_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertPinnedCommit($pin,'not-a-sha'),'RELEASE_SHA_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::run(['readiness.php']),'CLI_MANIFEST_SHA_DB_PIN_REQUIRED');

// Database identity must be pinned by the operator separately from config and
// manifest; a production-like name, an accidental substring, or a different
// database returned by SELECT DATABASE() must fail closed.
RC62ReleaseReadiness::assertCloneDatabase('vta_rc62_clone', 'vta_rc62_clone', 'vta_rc62_clone');
requirePass(true,'Accept independently pinned disposable DB clone identity');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('vta_rc62_clone','other_rc62_clone'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('vta_rc62_clone','vta_rc62_clone','other_rc62_clone'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('vta_rc62_clone_prod','vta_rc62_clone_prod'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('v2qu_v2qu_vtaos_clone','v2qu_v2qu_vtaos_clone'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('contest_data','contest_data'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::assertCloneDatabase('vta_rc62_clone;DROP','vta_rc62_clone;DROP'),
    'CLONE_DATABASE_MISMATCH');
mustReject(fn()=>RC62ReleaseReadiness::run([
    'readiness.php','--manifest=/private/manifest.json','--expected-sha='.$pin
]),'CLI_MANIFEST_SHA_DB_PIN_REQUIRED');

// SHOW GRANTS must establish SELECT-only on the independently pinned
// clone schema. Checking only the configured username is insufficient.
$readOnly=[
    'GRANT USAGE ON *.* TO `vta_rc62_preflight_ci`@`%`',
    'GRANT SELECT ON `vta_rc62_restore_clone_ci`.* TO `vta_rc62_preflight_ci`@`%`'
];
RC62ReleaseReadiness::assertReadOnlyGrants($readOnly,'vta_rc62_restore_clone_ci');
requirePass(true,'Read-only clone user with only SELECT/USAGE is accepted');
$unsafe=[
    ['GRANT ALL PRIVILEGES ON *.* TO root@localhost'],
    ['GRANT SELECT, INSERT ON `vta_rc62_restore_clone_ci`.* TO `operator`@`%`'],
    ['GRANT SELECT ON *.* TO `global-reader`@`%`'],
    ['GRANT SELECT ON `vta_rc62_other_clone_ci`.* TO `user`@`%`'],
    ['GRANT SELECT ON `vta_rc62_restore_clone_ci`.users TO `user`@`%`'],
    ['GRANT SELECT ON `vta_rc62_restore_clone_ci`.* TO `user`@`%` WITH GRANT OPTION'],
    ['GRANT USAGE ON *.* TO `user`@`%`'],
    ['GRANT `dbAdminRole`@`%` TO `user`@`%`']
];
foreach($unsafe as $grants){
    mustReject(fn()=>RC62ReleaseReadiness::assertReadOnlyGrants($grants,'vta_rc62_restore_clone_ci'),
        'READ_ONLY_DB_ACCOUNT_REQUIRED');
}
mustReject(fn()=>RC62ReleaseReadiness::assertReadOnlyGrants(
    [...$readOnly, 'GRANT UPDATE ON `vta_rc62_restore_clone_ci`.* TO `user`@`%`'],
    'vta_rc62_restore_clone_ci'), 'READ_ONLY_DB_ACCOUNT_REQUIRED');
requirePass(RC62ReleaseReadiness::safeFailure(new RuntimeException('READ_ONLY_DB_ACCOUNT_REQUIRED'))===
    'READ_ONLY_DB_ACCOUNT_REQUIRED','Read-only denial reports only a safe reason code');

// Never show PDOException/RuntimeGuard/raw PHP messages to the operator log.
requirePass(
    RC62ReleaseReadiness::safeFailure(new PDOException('SQLSTATE leaked database=private password=secret'))==='PREFLIGHT_ERROR',
    'PDO exception messages never leak to readiness output'
);
requirePass(
    RC62ReleaseReadiness::safeFailure(new RuntimeException('private hostname and credentials'))==='PREFLIGHT_ERROR',
    'Unknown exception messages never leak to readiness output'
);
requirePass(
    RC62ReleaseReadiness::safeFailure(new RuntimeException('MIGRATION_CHECKSUM_OR_STATUS_MISMATCH'))
        ==='MIGRATION_CHECKSUM_OR_STATUS_MISMATCH',
    'Known safe failure reason remains actionable'
);

// Test the real file checker with a generated runtime containing >100 files,
// and ensure a symlinked parent directory can never escape runtime root.
$root=sys_get_temp_dir().'/vta-rc62-guard-'.bin2hex(random_bytes(6));
$outside=sys_get_temp_dir().'/vta-rc62-guard-out-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
mkdir($outside,0700,true);
try {
    $required=[
        'index.html','app.js','api/bootstrap.php','api/index.php',
        'api/lib/PartnerHub.php','api/lib/TourLibrary.php',
        'api/lib/SmartEnhancements.php','api/migrations/038_b2b_hybrid_distribution.sql'
    ];
    $fileNames=$required;
    for ($i=0;$i<30;$i++) {
        $fileNames[]='api/migrations/fixture_'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'.sql';
    }
    for ($i=0;$i<65;$i++) {
        $fileNames[]='assets/item-'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'.txt';
    }
    $sha=[];
    foreach ($fileNames as $name) {
        $file=$root.'/'.$name;
        if(!is_dir(dirname($file))) mkdir(dirname($file),0700,true);
        file_put_contents($file, 'safe file: '.$name);
        $sha[$name]=hash_file('sha256',$file);
    }
    $filesManifest=[
        'schema'=>'vta.rc62.runtime.manifest.v1',
        'source_commit'=>$pin,
        'release_type'=>'STAGING_CANDIDATE_NOT_PRODUCTION',
        'file_count'=>count($sha),
        'sha256'=>$sha,
        'migration_count'=>31
    ];
    $validated=RC62ReleaseReadiness::files($root,$filesManifest);
    requirePass($validated['count']===count($sha)&&$validated['migrations']===31,
        'Accept exact local runtime file hashes and migration count');

    file_put_contents($outside.'/outside.txt','EXTERNAL_PRIVATE_DATA');
    if (!symlink($outside,$root.'/assets/external'))
        throw new RuntimeException('Fixture cannot create symlink');
    unset($filesManifest['sha256']['assets/item-00.txt']);
    $filesManifest['sha256']['assets/external/outside.txt']=hash_file('sha256',$outside.'/outside.txt');
    mustReject(fn()=>RC62ReleaseReadiness::files($root,$filesManifest),'RUNTIME_HASH_MISMATCH');
} finally {
    $walk=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($walk as $item) {
        if ($item->isDir()&&!$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($root);
    unlink($outside.'/outside.txt');
    rmdir($outside);
}
echo "PASS RC6.2 fail-closed staging readiness unit checks; no PDO connection or config loaded.\n";

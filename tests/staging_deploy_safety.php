<?php
declare(strict_types=1);
require dirname(__DIR__).'/deploy/staging-release.php';

// Exercise real file cutover/rollback in a disposable fixture. No application database or server access.
$base = str_replace('\\','/',sys_get_temp_dir()).'/vta-fast-deploy-'.bin2hex(random_bytes(5));
mkdir($base,0700); mkdir($base.'/live',0700); mkdir($base.'/source',0700);
$root = $base.'/live'; $source = $base.'/source'; $passed = 0;
function check(bool $ok,string $label): void { global $passed; if(!$ok) throw new RuntimeException($label); $passed++; echo "PASS $label\n"; }
function reject(callable $run,string $prefix,string $label): void {
    try { $run(); } catch(RuntimeException $error) { check(str_starts_with($error->getMessage(),$prefix),$label); return; }
    throw new RuntimeException('Unexpected acceptance: '.$label);
}
function state(string $root,array $paths,string $commit): array {
    $files=[]; foreach($paths as $path) $files[$path]=['source'=>StagingRelease::digest($root.'/'.$path),'deployed'=>StagingRelease::digest($root.'/'.$path)];
    return ['commit'=>$commit,'files'=>$files];
}
try {
    foreach (['.env','.env.production','api/config.php','uploads/customer.pdf','storage/customer.pdf','runtime/a.php','logs/a.log','cache/a.php','backup/a.php','vta_private/config.php','secret.key','deploy/staging-release.php','docs/test.md'] as $path) check(!StagingRelease::managed($path),'exclude '.$path);
    foreach (['../app.js','api/../../config.php','/etc/passwd','api//lib/a.php','api/a.php;id','api/./a.php','api\a.php'] as $path) reject(fn()=>StagingRelease::managed($path),'UNSAFE_PATH','reject path '.$path);
    StagingRelease::atomic($root.'/.htaccess',"original access\n");
    StagingRelease::atomic($root.'/app.js',"old app\n");
    StagingRelease::atomic($root.'/removed.js',"old unused module\n");
    StagingRelease::atomic($root.'/api/lib/LineEnding.php',"unchanged\r\n");
    StagingRelease::atomic($root.'/api/migrations/035_quote_confirmation_roles.sql',"approved SQL bytes\n");
    foreach (['.env','uploads/customer.txt','storage/runtime.txt','logs/test.log'] as $path) StagingRelease::atomic($root.'/'.$path,"server-owned $path\n");
    StagingRelease::atomic($base.'/config.php',"synthetic private config\n",0600);
    $paths=['.htaccess','app.js','removed.js','api/lib/LineEnding.php','api/migrations/035_quote_confirmation_roles.sql'];
    $before=state($root,$paths,str_repeat('a',40));
    $before['files']['api/lib/LineEnding.php']['source']=hash('sha256',"unchanged\n");
    StagingRelease::atomic($source.'/.htaccess',"new access\n");
    StagingRelease::atomic($source.'/app.js',"new app\n");
    StagingRelease::atomic($source.'/new.js',"new module\n");
    StagingRelease::atomic($source.'/api/lib/LineEnding.php',"unchanged\n");
    StagingRelease::atomic($source.'/api/migrations/035_quote_confirmation_roles.sql',"approved SQL bytes\n");
    $after=state($source,['.htaccess','app.js','new.js','api/lib/LineEnding.php','api/migrations/035_quote_confirmation_roles.sql'],str_repeat('b',40));
    [$changes,$after]=StagingRelease::plan($root,$before,$after);
    check(count($changes)===4 && !isset($changes['api/lib/LineEnding.php']) && $after['files']['api/lib/LineEnding.php']['deployed']===$before['files']['api/lib/LineEnding.php']['deployed'],'preserve unchanged CRLF deployed bytes');
    check(!isset($changes['api/migrations/035_quote_confirmation_roles.sql']),'historical migration remains untouched');
    $bad=$after; $bad['files']['api/migrations/035_quote_confirmation_roles.sql']['source']=hash('sha256','rewritten');
    reject(fn()=>StagingRelease::plan($root,$before,$bad),'MIGRATION_REVIEW_REQUIRED','reject rewritten historical migration');
    $bad=$after; $bad['files']['api/migrations/036_unapproved.sql']=['source'=>hash('sha256','new'),'deployed'=>hash('sha256','new')];
    reject(fn()=>StagingRelease::plan($root,$before,$bad),'MIGRATION_REVIEW_REQUIRED','reject unapproved new migration');
    $bad=$after; unset($bad['files']['api/migrations/035_quote_confirmation_roles.sql']);
    reject(fn()=>StagingRelease::plan($root,$before,$bad),'MIGRATION_REVIEW_REQUIRED','reject migration deletion');
    StagingRelease::atomic($root.'/new.js',"customer-owned collision\n");
    reject(fn()=>StagingRelease::plan($root,$before,$after),'UNTRACKED_FILE_CONFLICT','preserve untracked file on name collision'); unlink($root.'/new.js');
    StagingRelease::atomic($root.'/app.js',"uncommitted host edit\n");
    reject(fn()=>StagingRelease::plan($root,$before,$after),'SERVER_DRIFT','block unexpected hosting source edit'); StagingRelease::atomic($root.'/app.js',"old app\n");
    $version='035_quote_confirmation_roles'; $hash=$before['files']['api/migrations/'.$version.'.sql']['source'];
    $rows=[['version'=>$version,'sha256'=>$hash,'status'=>'APPLIED']];
    StagingRelease::validateLedger($root,$before,$rows,[$version]); check(true,'read-only ledger accepts exact applied SQL checksum');
    reject(fn()=>StagingRelease::validateLedger($root,$before,[],[]),'MIGRATION_REVIEW_REQUIRED','pending ledger blocks deploy');
    $failed=$rows; $failed[0]['status']='FAILED';
    reject(fn()=>StagingRelease::validateLedger($root,$before,$failed,[$version]),'MIGRATION_CHECKSUM_MISMATCH','failed/partial migration blocks deploy');
    $changed=$rows; $changed[0]['sha256']=hash('sha256','other');
    reject(fn()=>StagingRelease::validateLedger($root,$before,$changed,[$version]),'MIGRATION_CHECKSUM_MISMATCH','ledger checksum mismatch blocks deploy');
    StagingRelease::atomic($root.'/api/migrations/'.$version.'.sql',"edited historical SQL\n");
    reject(fn()=>StagingRelease::validateLedger($root,$before,$rows,[$version]),'SERVER_MIGRATION_DRIFT','host migration byte change blocks deploy');
    StagingRelease::atomic($root.'/api/migrations/'.$version.'.sql',"approved SQL bytes\n");
    $backup=$base.'/backup'; $journal=StagingRelease::backup($backup,$root,$base.'/config.php',$before,$after,$changes);
    check(StagingRelease::digest($backup.'/config.php')===StagingRelease::digest($base.'/config.php'),'private configuration backup is verified');
    StagingRelease::maintenance($root); StagingRelease::installChanges($root,$source,$changes);
    StagingRelease::atomic($root.'/.htaccess',"new access\n"); StagingRelease::verify($root,$after);
    check(!is_file($root.'/removed.js') && file_get_contents($root.'/new.js')==="new module\n",'install delta and remove only tracked obsolete source');
    foreach (['.env','uploads/customer.txt','storage/runtime.txt','logs/test.log'] as $path) check(file_get_contents($root.'/'.$path)==="server-owned $path\n",'preserve live data '.$path);
    StagingRelease::rollback($root,$backup,$journal);
    check(!is_file($root.'/new.js') && file_get_contents($root.'/removed.js')==="old unused module\n" && file_get_contents($root.'/.htaccess')==="original access\n",'rollback created, removed and changed source plus access');
    check(file_get_contents($base.'/config.php')==="synthetic private config\n",'rollback never overwrites private configuration');
    // Interrupted deployment: only the first file was installed. The same journal restores it safely.
    StagingRelease::maintenance($root); StagingRelease::atomic($root.'/app.js',"new app\n");
    StagingRelease::rollback($root,$backup,$journal); StagingRelease::verify($root,$before);
    check(file_get_contents($root.'/app.js')==="old app\n",'recover partially installed cutover');
    StagingRelease::atomic($root.'/app.js',"unrelated post-deploy edit\n");
    reject(fn()=>StagingRelease::rollback($root,$backup,$journal),'ROLLBACK_SERVER_DRIFT','rollback refuses to overwrite unexpected server changes'); StagingRelease::atomic($root.'/app.js',"old app\n");
    StagingRelease::atomic($backup.'/source/app.js',"corrupt backup\n");
    reject(fn()=>StagingRelease::rollback($root,$backup,$journal),'ROLLBACK_BACKUP_DAMAGED','damaged backup stops rollback before source writes');
    check(file_get_contents($root.'/.htaccess')==="original access\n",'backup rejection preserves access file');
    StagingRelease::atomic($backup.'/source/app.js',"old app\n");
    // A race after preflight must stop before overwriting the changed preimage.
    StagingRelease::atomic($root.'/app.js',"concurrent hosting edit\n");
    reject(fn()=>StagingRelease::installChanges($root,$source,$changes),'CUTOVER_PREIMAGE_CHANGED','block preflight-to-cutover race'); StagingRelease::atomic($root.'/app.js',"old app\n");
    $script=file_get_contents(dirname(__DIR__).'/deploy/deploy-staging.sh');
    check(str_contains($script,'set -eu') && !preg_match('/^\s*git .*clean\b/m',$script) && !str_contains($script,'--hard'),'wrapper has strict mode and no destructive public reset or clean');
    check(str_contains($script,'refs/heads/$BRANCH:refs/remotes/origin/$BRANCH') && str_contains($script,'merge-base --is-ancestor'),'explicit ancestry check rejects branch history rewrites');
    check(str_contains($script,'flock -n 9') && str_contains($script,'--preflight') && str_contains($script,'HALTED'),'wrapper serializes deployments and honors failure halt');
    reject(fn()=>StagingRelease::run(['test']),'STAGING_PHP_REQUIRED','controller refuses this non-staging runtime before database access');
    echo "RESULT PASS $passed deployment safety checks; no application regression suite rerun\n";
} finally {
    // Delete only this random, disposable local test directory; never server/application paths.
    $resolved=str_replace('\\','/',(string)realpath($base));
    if($resolved===$base && str_starts_with($base,str_replace('\\','/',sys_get_temp_dir()).'/vta-fast-deploy-')) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file) { if($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($base);
    }
}

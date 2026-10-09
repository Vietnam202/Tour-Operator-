<?php
declare(strict_types=1);
require __DIR__.'/../api/lib/MarketingSchemaUpgrade.php';
function verifyP12(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
}
$real=dirname(__DIR__).'/api/migrations';
$manifest=MarketingSchemaUpgrade::manifest($real);
verifyP12(count($manifest['marketing'])===10,'ten reviewed Marketing versions are pinned to hashes');
verifyP12(array_search('025_vs21_shared_requirements',$manifest['ordered_versions'],true)
    <array_search('023_marketing_webhook_core',$manifest['ordered_versions'],true),
    'schema planner applies core 025 before Marketing 023');

$db=new PDO(getenv('VTA_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=vta_p12_clone_ci;charset=utf8mb4',
    getenv('VTA_TEST_USER')?:'vta',getenv('VTA_TEST_PASSWORD')?:'vta_ci_pw',[
       PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
       PDO::ATTR_EMULATE_PREPARES=>false
    ]);
$config=['app'=>['env'=>'testing','base_url'=>'http://localhost'],
 'db'=>['host'=>'127.0.0.1','database'=>'vta_p12_clone_ci'],
 'integrations'=>['social_publishing'=>['enabled'=>false],'meta_inbox'=>['enabled'=>false]]];
MarketingSchemaUpgrade::cloneOnly($db,$config);
$wrong=$config;$wrong['db']['database']='v2qu_v2qu_vtaos';
$refused=false;try{MarketingSchemaUpgrade::cloneOnly($db,$wrong);}
catch(RuntimeException $e){$refused=true;}
verifyP12($refused,'staging database identifier cannot be used for clone apply');
$wrong=$config;$wrong['app']['env']='staging';
$refused=false;try{MarketingSchemaUpgrade::cloneOnly($db,$wrong);}
catch(RuntimeException $e){$refused=true;}
verifyP12($refused,'staging runtime cannot write even to clone-named database');
$wrong=$config;$wrong['integrations']['social_publishing']['enabled']=true;
$refused=false;try{MarketingSchemaUpgrade::cloneOnly($db,$wrong);}
catch(RuntimeException $e){$refused=true;}
verifyP12($refused,'real social publishing prevents schema rehearsal');

$coreDir=sys_get_temp_dir().'/vta-p12-core-'.bin2hex(random_bytes(7));
if(!mkdir($coreDir,0700))throw new RuntimeException('Unable to create core fixture');
try {
    foreach($manifest['core'] as $version=>$hash){
        $source=$real.'/'.$version.'.sql';
        verifyP12(hash_file('sha256',$source)===$hash,'core source pinned: '.$version);
        if(!copy($source,$coreDir.'/'.$version.'.sql'))throw new RuntimeException('Core fixture copy failed');
    }
    // This executes actual repository core migrations 001–036, not stub SQL.
    // The database is disposable and named vta_p12_clone_ci by GitHub Actions.
    $core=Migrations::run($db,$coreDir);
    verifyP12(count($core)===count($manifest['core']),'all actual baseline core migrations applied to disposable MariaDB');
    $baseline=MarketingSchemaUpgrade::inspect($db,$manifest);
    verifyP12($baseline['pending']===array_keys($manifest['marketing']),
        'ledger inspect identifies exactly the ten unapplied Marketing migrations');
    $quoteBefore=$db->query('SHOW CREATE TABLE quote_options')->fetch(PDO::FETCH_NUM)[1];
    $notApproved=false;try{
        MarketingSchemaUpgrade::applyClone($db,$real,$config,str_repeat('b',64));
    }catch(RuntimeException $e){$notApproved=true;}
    verifyP12($notApproved,'wrong review fingerprint rejects schema DDL');

    $applied=MarketingSchemaUpgrade::applyClone($db,$real,$config,$manifest['fingerprint']);
    verifyP12($applied['applied']===array_keys($manifest['marketing']),
        'all ten real Marketing migrations run on disposable core schema');
    verifyP12($applied['after']['pending']===[],'all Marketing versions have exact checksum entries');
    $quoteAfter=$db->query('SHOW CREATE TABLE quote_options')->fetch(PDO::FETCH_NUM)[1];
    verifyP12($quoteBefore===$quoteAfter,'original quotation option schema is preserved byte-for-byte');
    verifyP12($db->query("SHOW TABLES LIKE 'marketing_meta_connection_checks'")->fetchColumn()!==false,
        'final connection diagnostics table exists on clone');
    verifyP12($db->query("SHOW TABLES LIKE 'social_meta_inbound_jobs'")->fetchColumn()!==false,
        'Meta inbox queue is installed on clone');
    verifyP12($db->query("SHOW TABLES LIKE 'marketing_meta_outbound'")->fetchColumn()!==false,
        'Messenger outbound queue is installed on clone');
    $again=MarketingSchemaUpgrade::applyClone($db,$real,$config,$manifest['fingerprint']);
    verifyP12($again['applied']===[],'forward-only rehearsal is idempotent on an already-upgraded clone');
    $db->exec("UPDATE migration_checksums SET sha256=REPEAT('c',64) WHERE version='042_meta_messenger_replies'");
    $blocked=false;try{MarketingSchemaUpgrade::inspect($db,$manifest);}
    catch(RuntimeException $e){$blocked=true;}
    verifyP12($blocked,'historical Marketing checksum tampering fails closed');
    require_once __DIR__.'/../api/lib/MarketingStagingGate.php';
    $sourceDecision=MarketingStagingGate::summary(MarketingStagingGate::inspectSource(dirname(__DIR__)));
    verifyP12($sourceDecision['safe_to_release']===false,
        'successful disposable clone rehearsal does not authorize real staging deployment');
    $pendingBlockers=array_column(array_filter($sourceDecision['checks'],
        static fn(array $x)=>($x['level']??'')==='BLOCK'),'id');
    verifyP12(in_array('STAGING_DEPLOY_MIGRATIONS',$pendingBlockers,true)
        &&in_array('STAGING_BRANCH_PIN',$pendingBlockers,true)
        &&in_array('STAGING_ACCEPTANCE_NOT_SIGNED',$pendingBlockers,true),
        'private controller, release branch and human acceptance remain blocked');
    echo "P12 full repository core + Marketing schema rehearsal checks complete.\n";
}finally{
    foreach(glob($coreDir.'/*.sql')?:[] as $tmp)unlink($tmp);
    rmdir($coreDir);
}
